<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import Composer from '../components/Composer.vue'
import type { McpMention } from '../lib/mentions'
import RunningAgentsLine from '../components/agents/RunningAgentsLine.vue'
import { useAgentsStore } from '../stores/agents/agents'
import { t } from '../lib/i18n'
import type { PastedDocument, PastedImage } from '../lib/paste'
import PermissionCard, { type Reply } from '../components/PermissionCard.vue'
import QueueStrip from '../components/QueueStrip.vue'
import ReasoningFold from '../components/ReasoningFold.vue'
import StatusBar from '../components/StatusBar.vue'
import Transcript from '../components/Transcript.vue'
import type { PermissionMode } from '../protocol/generated'
import { useConnectionStore } from '../stores/connection'
import { useLayoutStore } from '../stores/layout'
import type { ToolItem, UserItem } from '../stores/reducer'
import { describe, useSessionStore, type Delivery, type SessionStore } from '../stores/session'
import { useSessionsStore } from '../stores/sessions'

const props = defineProps<{ id: string }>()

const connection = useConnectionStore()
const sessions = useSessionsStore()
const layout = useLayoutStore()
const error = ref<string | null>(null)
// The server's persistence verdict for the last answer attempted here.
const notice = ref<string | null>(null)
const sending = ref(false)

const session = computed<SessionStore>(() => useSessionStore(props.id))
const state = computed(() => session.value.state)
const summary = computed(() => sessions.byId[props.id])
const title = computed(() => summary.value?.name || summary.value?.preview?.slice(0, 80) || props.id)
const canAnswer = computed(() => connection.scopes.includes('approve'))
const liveText = computed(() => state.value.live?.text ?? '')
const liveReasoning = computed(() => state.value.reasoning?.text ?? '')
/**
 * CW-EN-EU02: the newest steer-notice key the transcript holds (null before
 * the first steer ever). QueueStrip keys its one-shot fade on this, so each
 * new steer remounts the flash; an old steer riding in from history replay
 * fades once on open — harmless, attention-grabbing, self-clearing.
 */
const lastSteerKey = computed<string | null>(() => {
  for (let index = state.value.items.length - 1; index >= 0; index = index - 1) {
    const item = state.value.items[index]
    if (item?.kind === 'notice' && item.key.startsWith('steered:')) return item.key
  }
  return null
})
/**
 * ISS-108: the streaming tail is plain pre-wrap text, not markdown re-parsed
 * per token, and nothing with `aria-live` watches it — a polite region fed
 * every delta spams screen readers. The announcement fires once, when the
 * reply has landed as a completed row.
 */
const finishedReply = computed(() => {
  if (state.value.status !== 'idle' || liveText.value !== '') return ''
  for (let index = state.value.items.length - 1; index >= 0; index = index - 1) {
    const item = state.value.items[index]
    if (item?.kind === 'assistant') return t('web.views.session.reply_complete', { content: item.content })
  }
  return ''
})
const draft = computed({
  get: () => layout.draft(props.id),
  set: (value: string) => layout.setDraft(props.id, value),
})

/**
 * C-MCP-2.c: cache slot for the `@mcp:` popup roster. Declared before the
 * session-id watch because that watch runs immediate and resets it; the
 * launch-frozen rows are fetched once per opened session by
 * `mcpResourceRoster()` below.
 */
let mcpRoster: Promise<McpMention[]> | null = null

watch(() => props.id, (id, previous) => {
  if (previous !== undefined) useSessionStore(previous).release()
  error.value = null
  mcpRoster = null // C-MCP-2.c: the roster is frozen per session root
  layout.lastSessionId = id
  useSessionStore(id).retain()
}, { immediate: true })

onBeforeUnmount(() => session.value.release())

// The first connect (or a sign-in) after this view opened.
watch(() => connection.connected, (connected) => {
  if (connected && session.value.phase === 'idle') void session.value.subscribe()
})

async function send(text: string, delivery: Delivery, images: PastedImage[] = [], documents: PastedDocument[] = []): Promise<void> {
  sending.value = true
  error.value = null
  try {
    await session.value.send(text, delivery, images, documents)
    draft.value = ''
  } catch (failure) {
    error.value = describe(failure)
  } finally {
    sending.value = false
  }
}

/** The composer's @ popup (WEB-5b): `fs.search` over this session's store. */
const searchFiles = (query: string): Promise<string[]> => session.value.searchFiles(query)

/**
 * C-MCP-2.c: the `@mcp:` popup roster. `mcp.resources` reports the
 * launch-frozen rows without starting any server, so the answer is fetched
 * once per opened session and cached here; the popup query filters
 * server/uri/name. A failed or `not-started` answer caches empty rows, and
 * the composer falls back to files.
 */
function mcpResourceRoster(): Promise<McpMention[]> {
  mcpRoster ??= connection
    .request('mcp.resources', { sessionId: props.id })
    .then((answer) =>
      answer.servers.flatMap((entry) =>
        entry.resources.map((resource) => ({
          server: entry.server,
          uri: resource.uri,
          name: resource.name,
        })),
      ),
    )
    .catch(() => [] as McpMention[])
  return mcpRoster
}

/**
 * C-AGT-4.d (client-only): the composer's `@agent:` popup reads the launch
 * roster straight off the agents store — the same `agents.list` catalogue the
 * StatusBar already fetches, never a second request. Names not in the roster
 * are refused by the server's scanner too, so the popup offers only what the
 * run can honour.
 */
const agentsStore = useAgentsStore()
const agentRosterNames = (): string[] => Object.keys(agentsStore.roster)

const searchMcpResources = (query: string): Promise<McpMention[]> => {
  const needle = query.toLowerCase()
  return mcpResourceRoster().then((rows) =>
    rows
      .filter(
        (row) =>
          row.server.toLowerCase().includes(needle) ||
          row.uri.toLowerCase().includes(needle) ||
          row.name.toLowerCase().includes(needle),
      )
      .slice(0, 8),
  )
}

async function stop(): Promise<void> {
  try {
    await session.value.cancel('hard')
  } catch (failure) {
    error.value = describe(failure)
  }
}

async function answer(askId: string, reply: Reply, cascade: boolean, note: string, failed: () => void, save = false): Promise<void> {
  notice.value = null
  try {
    notice.value = await session.value.respond(askId, reply, { ...(cascade ? { cascade: true } : {}), ...(note !== '' ? { note } : {}), ...(save ? { remember: 'user' } : {}) })
  } catch (failure) {
    error.value = describe(failure)
    // ISS-110: the question is still open — hand the card back to the user.
    failed()
  }
}

async function dequeue(queueId: string): Promise<void> {
  try {
    await session.value.dequeue(queueId)
  } catch (failure) {
    error.value = describe(failure)
  }
}

/** WEB-1a: manual reconcile of the queue against `session.queue` on the server. */
async function refreshQueue(): Promise<void> {
  try {
    await session.value.refreshQueue()
  } catch (failure) {
    error.value = describe(failure)
  }
}

/** WEB-2: status-bar commands (/compact, /model …) enter through the same slash router the composer uses. */
async function runCommand(text: string): Promise<void> {
  await send(text, 'queue')
}

async function setMode(mode: PermissionMode): Promise<void> {
  try {
    await session.value.setMode(mode)
  } catch (failure) {
    error.value = describe(failure)
  }
}

async function loadFull(item: ToolItem): Promise<void> {
  try {
    await session.value.loadFullOutput(item)
  } catch (failure) {
    error.value = describe(failure)
  }
}

/**
 * C-TUI-3.b: how many checkpoints back the given prompt is. `kind:'user'`
 * items are 1:1 with the host's checkpoints — one `saveCheckpoint` per
 * dispatched prompt, and command echo rows arrive `uiOnly`, which the
 * reducer maps to notices, never to user items — so the answer is one plus
 * the user rows still below this one. Matches how
 * `Checkpoints::rewindConversation` indexes its newest-first list; asking
 * past the oldest (the 100-per-session cap prunes) clamps there server-side,
 * never errors. Returns null when the row is gone (someone rewound first).
 */
function rewindSteps(item: UserItem): number | null {
  const items = state.value.items
  const at = items.findIndex((row) => row.kind === 'user' && row.key === item.key)
  if (at === -1) return null
  let steps = 1
  for (let index = at + 1; index < items.length; index = index + 1) {
    if (items[index]?.kind === 'user') steps = steps + 1
  }
  return steps
}

/**
 * Both row actions run `/rewind n --chat` on the server behind a confirm
 * (the design's guard); "Edit & resend" then seeds the composer with the
 * prompt's text — seeded only after the rewind lands, and never sent from
 * here, so the affordance cannot double-send: the one exec it makes is a
 * command, not a prompt. A plain rewind leaves the draft alone; replacing a
 * non-empty draft is named in its own confirm clause first. While a turn
 * runs the server refuses commands — the client says so on the spot instead
 * of exec'ing into a refused row.
 */
/**
 * Review F2: single-flight guard for the rewind path. Without it, two rapid
 * confirms (double dialog-ok, or a second row's button pressed while the
 * first exec is still in flight) fire TWO `command.exec rewind` calls whose
 * step counts were computed from the SAME pre-refetch `items` snapshot —
 * the second over-rewinds past what the user confirmed. Recoverable via
 * /redo, but silent; a destructive affordance must not interleave.
 */
let rewinding = false

async function rewindRow(item: UserItem, seedComposer: boolean): Promise<void> {
  if (rewinding) return
  error.value = null
  if (session.value.busy) {
    error.value = t('web.views.session.rewind_busy')
    return
  }
  const steps = rewindSteps(item)
  if (steps === null) {
    error.value = t('web.views.session.rewind_gone')
    return
  }
  const preview = item.content.replace(/\s+/g, ' ').trim().slice(0, 60)
  const replacesDraft = seedComposer && draft.value !== ''
  const question = t('web.views.session.rewind_confirm', { preview })
    + (replacesDraft ? t('web.views.session.rewind_confirm_draft') : '')
  if (typeof window !== 'undefined' && !window.confirm(question)) return
  // Everything from the exec to its full settle (the rows-answer refetch)
  // is the protected window; nothing between confirm() and this assignment
  // yields to the event loop, so no second confirm can slip inside it.
  rewinding = true
  try {
    await session.value.rewindTo(steps)
    if (seedComposer) draft.value = item.content
  } catch (failure) {
    error.value = describe(failure)
  } finally {
    rewinding = false
  }
}

const rewindHere = (item: UserItem): Promise<void> => rewindRow(item, false)
const editAndResend = (item: UserItem): Promise<void> => rewindRow(item, true)

async function rename(): Promise<void> {
  const name = window.prompt(t('web.views.session.rename_prompt'), summary.value?.name ?? '')
  if (name === null || name.trim() === '') return
  try {
    await sessions.rename(props.id, name.trim())
  } catch (failure) {
    error.value = describe(failure)
  }
}

/**
 * MA-29: the Transcript scroller drives infinite scroll-up; it asks the store for one
 * older page and reports back the store's own failure string (null = the page landed or
 * there was simply nothing to fetch). The component needs the outcome to re-anchor the
 * view or show the alert, and the store keeps the single source of truth for olderError.
 */
async function fetchOlderTranscript(): Promise<string | null> {
  await session.value.loadOlder()
  return session.value.olderError
}
</script>

<template>
  <section class="session-view" data-testid="session-view" :data-session-id="id" :data-phase="session.phase">
    <header class="head">
      <h1 :title="id">{{ title }}</h1>
      <button type="button" class="link" @click="rename">{{ t('web.views.session.rename') }}</button>
    </header>
    <p v-if="session.error" class="error" role="alert">{{ session.error }}</p>
    <!-- m38: the stale window painted on the pane — the drop the server
         declared, in fixed chrome above the frozen transcript (the audit row
         inside the scroller can be off-screen when the fall-behind happens). -->
    <p v-if="session.staleNotice" class="stale-notice" role="status" aria-live="polite" data-testid="session-stale">{{ session.staleNotice }}</p>
    <Transcript
      :items="state.items"
      :subagents="state.subagents"
      :tick="liveText.length + liveReasoning.length + state.asks.length"
      :session-id="id"
      :can-load-older="state.hasMoreOlder"
      :loading-older="session.loadingOlder"
      :fetch-older="fetchOlderTranscript"
      :subscribing="session.phase === 'subscribing'"
      :failed="session.error !== null"
      @load-full="loadFull"
      @rewind="rewindHere"
      @edit-resend="editAndResend"
    >
      <template #after>
        <ReasoningFold v-if="liveReasoning" :text="liveReasoning" live />
        <div v-if="liveText" class="live" data-testid="live-reply">
          <p v-if="state.live?.gap" class="gap">…</p>
          <pre class="live-text">{{ liveText }}</pre>
        </div>
        <PermissionCard
          v-for="ask in state.asks"
          :key="ask.askId"
          :ask="ask"
          :can-answer="canAnswer"
          @answer="(reply, cascade, note, failed, save) => answer(ask.askId, reply, cascade, note, failed, save)"
        />
        <RunningAgentsLine :session-id="id" />
      </template>
    </Transcript>
    <p class="sr-only" role="status" aria-live="polite" data-testid="reply-announcement">{{ finishedReply }}</p>
    <div class="dock">
      <QueueStrip :queue="state.queue" :flash-key="lastSteerKey" @remove="dequeue" @refresh="refreshQueue" />
      <p v-if="error" class="error" role="alert" data-testid="session-error">{{ error }}</p>
      <p v-if="notice" class="save-notice" role="status" data-testid="session-save-notice">{{ notice }}</p>
      <Composer
        v-model="draft"
        :busy="session.busy"
        :disabled="sending || !connection.connected"
        :commands="session.commands"
        :default-delivery="connection.defaultDelivery"
        :search-files="searchFiles"
        :search-mcp-resources="searchMcpResources"
        :agent-roster="agentRosterNames"
        @send="send"
        @stop="stop"
      />
      <StatusBar :state="state" :summary="summary" :context-pct="session.contextPct" :connection="connection.statusLabel" @set-mode="setMode" @run-command="runCommand" />
    </div>
  </section>
</template>

<style scoped>
.session-view {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}
.head {
  display: flex;
  align-items: baseline;
  gap: 0.75rem;
  padding: 0.5rem 1rem;
  border-bottom: 1px solid var(--line);
  min-width: 0;
}
h1 {
  margin: 0;
  font-size: 1rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.live {
  padding: 0.35rem 0;
}
/* ISS-108: the streaming tail is verbatim text, wrapped like the finished
   markdown will be — no per-token parse while it arrives. */
.live-text {
  margin: 0;
  font: inherit;
  line-height: 1.55;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.gap {
  margin: 0;
  color: var(--muted);
}
.dock {
  padding: 0.5rem 1rem 0.25rem;
  border-top: 1px solid var(--line);
  background: var(--bg);
}
.error {
  margin: 0 0 0.4rem;
  color: var(--error);
  font-size: 0.875rem;
  padding: 0 1rem;
}
.save-notice {
  margin: 0 0 0.4rem;
  color: var(--warn);
  font-size: 0.875rem;
  padding: 0 1rem;
}
/* m38: the stale-window notice rides the same warn chrome as the save
   notice — it sits outside the scroller so a frozen transcript is never
   mistaken for a live one. */
.stale-notice {
  margin: 0 0 0.4rem;
  color: var(--warn);
  font-size: 0.875rem;
  padding: 0 1rem;
}
.dock .error {
  padding: 0;
}
/* CW-UX-08: on short viewports the chrome stack (queue strip, error line, composer,
   status bar) is the loudest consumer — compact its paddings, cap the dock at a
   scrollable fraction of the viewport, and floor the transcript's flex area so the
   conversation keeps visible rows. happy-dom measures nothing, so the geometry is
   pinned by source scan in sessionViewShortViewport.spec.ts. The overlay files of
   this register row were capped by lane G5-UXB. */
@media (max-height: 600px) {
  .head {
    padding: 0.25rem 1rem;
  }
  .dock {
    padding: 0.25rem 0.75rem 0.15rem;
    max-height: 55vh;
    overflow-y: auto;
  }
  .scroller {
    min-height: 6rem;
  }
}
</style>
