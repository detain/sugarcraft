<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import Composer from '../components/Composer.vue'
import RunningAgentsLine from '../components/agents/RunningAgentsLine.vue'
import type { PastedDocument, PastedImage } from '../lib/paste'
import PermissionCard, { type Reply } from '../components/PermissionCard.vue'
import QueueStrip from '../components/QueueStrip.vue'
import ReasoningFold from '../components/ReasoningFold.vue'
import StatusBar from '../components/StatusBar.vue'
import Transcript from '../components/Transcript.vue'
import type { PermissionMode } from '../protocol/generated'
import { useConnectionStore } from '../stores/connection'
import { useLayoutStore } from '../stores/layout'
import type { ToolItem } from '../stores/reducer'
import { describe, useSessionStore, type Delivery, type SessionStore } from '../stores/session'
import { useSessionsStore } from '../stores/sessions'

const props = defineProps<{ id: string }>()

const connection = useConnectionStore()
const sessions = useSessionsStore()
const layout = useLayoutStore()
const error = ref<string | null>(null)
const sending = ref(false)

const session = computed<SessionStore>(() => useSessionStore(props.id))
const state = computed(() => session.value.state)
const summary = computed(() => sessions.byId[props.id])
const title = computed(() => summary.value?.name || summary.value?.preview?.slice(0, 80) || props.id)
const canAnswer = computed(() => connection.scopes.includes('approve'))
const liveText = computed(() => state.value.live?.text ?? '')
const liveReasoning = computed(() => state.value.reasoning?.text ?? '')
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
    if (item?.kind === 'assistant') return `Reply complete: ${item.content}`
  }
  return ''
})
const draft = computed({
  get: () => layout.draft(props.id),
  set: (value: string) => layout.setDraft(props.id, value),
})

watch(() => props.id, (id, previous) => {
  if (previous !== undefined) useSessionStore(previous).release()
  error.value = null
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

async function stop(): Promise<void> {
  try {
    await session.value.cancel('hard')
  } catch (failure) {
    error.value = describe(failure)
  }
}

async function answer(askId: string, reply: Reply, cascade: boolean, note: string, failed: () => void, save = false): Promise<void> {
  try {
    await session.value.respond(askId, reply, { ...(cascade ? { cascade: true } : {}), ...(note !== '' ? { note } : {}), ...(save ? { remember: 'user' } : {}) })
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

async function rename(): Promise<void> {
  const name = window.prompt('Rename the session', summary.value?.name ?? '')
  if (name === null || name.trim() === '') return
  try {
    await sessions.rename(props.id, name.trim())
  } catch (failure) {
    error.value = describe(failure)
  }
}
</script>

<template>
  <section class="session-view" data-testid="session-view" :data-session-id="id" :data-phase="session.phase">
    <header class="head">
      <h1 :title="id">{{ title }}</h1>
      <button type="button" class="link" @click="rename">rename</button>
    </header>
    <p v-if="session.error" class="error" role="alert">{{ session.error }}</p>
    <Transcript :items="state.items" :subagents="state.subagents" :tick="liveText.length + liveReasoning.length + state.asks.length" :session-id="id" @load-full="loadFull">
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
      <QueueStrip :queue="state.queue" @remove="dequeue" @refresh="refreshQueue" />
      <p v-if="error" class="error" role="alert" data-testid="session-error">{{ error }}</p>
      <Composer
        v-model="draft"
        :busy="session.busy"
        :disabled="sending || !connection.connected"
        :commands="session.commands"
        :default-delivery="connection.defaultDelivery"
        :search-files="searchFiles"
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
.dock .error {
  padding: 0;
}
</style>
