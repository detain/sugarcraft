<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { MethodResult } from '../protocol/generated'
import { t } from '../lib/i18n'
import { useDialog } from '../lib/useDialog'
import { useConnectionStore } from '../stores/connection'
import { describe } from '../stores/session'

/**
 * WEB-1c: what this client is talking to (`server.info`) and how that
 * server is doing right now (`server.health`) — the two read-scoped methods
 * that had no caller. Both go over the socket everything else uses, and
 * each settles on its own: a health failure never hides the inventory the
 * info answer brought.
 */
const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: [] }>()
const connection = useConnectionStore()

const info = ref<MethodResult<'server.info'> | null>(null)
const health = ref<MethodResult<'server.health'> | null>(null)
const error = ref<string | null>(null)
const loading = ref(false)
const stopping = ref(false)
const stopError = ref<string | null>(null)

/**
 * C-WEB-5.f (R13): the admin-scoped log tail. server.log lines can quote
 * prompts and paths, so this section renders ONLY behind Scope::Admin — the
 * same door the stop control stands behind — and the server flattens and
 * caps every answer ({@see LogTail::answer}). The fetch is load-driven, so
 * a non-admin never sends the request at all (a click-gated control could
 * not prove that). Refusals land loud on the logs row; the `no_log` machine
 * token — a foreground server that never wrote the file — renders as the
 * chrome note, never a blank void.
 */
const logs = ref<string[] | null>(null)
const logsReason = ref<string | null>(null)
const logsError = ref<string | null>(null)
const logsLoading = ref(false)

/**
 * C-WEB-5.g (R13): the browser's ONLY caller of the Scope::Admin
 * `server.shutdown` lives behind two doors. The control renders only for an
 * admin-scoped principal — SettingsView's house gate shape — and only once a
 * health answer is known: the confirm must name what it interrupts, and a
 * click-through with an unknown running-turn count would misrepresent the
 * blast radius. The standing sole-caller proof is the source scan in
 * `__tests__/shutdownDormant.spec.ts`.
 */
const canAdmin = computed(() => connection.scopes.includes('admin'))
const canStop = computed(() => canAdmin.value && health.value !== null)

/**
 * w4j/w2h review MINOR: the close→rapid-reopen race. load() clears the
 * feeds and awaits; a re-open starts a SECOND load while the first is
 * still in flight, and the two share one set of refs. The generation
 * latch is the write gate: every load stamps its own number, and a
 * settle that arrives after a newer load began belongs to a superseded
 * visit and is dropped — answers AND errors, because a refusal from the
 * closed visit is not news to the open one. Without it, the first
 * visit's response (or failure) can repopulate or blank the feeds the
 * second visit already settled.
 */
let generation = 0

async function load(): Promise<void> {
  const mine = ++generation
  loading.value = true
  error.value = null
  // w2h M3: a re-open never renders the previous visit's answers. Both
  // feeds start null every load, so a failed fetch shows the failure —
  // not last session's stale version, uptime, or health line.
  info.value = null
  health.value = null
  // C-WEB-5.g: a re-open never inherits the previous visit's stop state —
  // a stale refusal row or a stuck "Stopping…" latch belongs to a dead visit.
  stopping.value = false
  stopError.value = null
  // C-WEB-5.f: a re-open never inherits the previous visit's log rows.
  logs.value = null
  logsReason.value = null
  logsError.value = null
  const [first, second] = await Promise.allSettled([connection.request('server.info', {}), connection.request('server.health', {})])
  if (mine !== generation) {
    return
  }
  if (first.status === 'fulfilled') info.value = first.value
  else error.value = describe(first.reason)
  if (second.status === 'fulfilled') health.value = second.value
  if (canAdmin.value) void loadLogs()
  loading.value = false
}

async function loadLogs(): Promise<void> {
  if (!canAdmin.value) return
  const mine = generation
  logsLoading.value = true
  logsError.value = null
  try {
    const answer = await connection.request('server.logs', {})
    if (mine !== generation) return
    logs.value = answer.lines
    logsReason.value = answer.reason ?? null
  } catch (failure) {
    if (mine !== generation) return
    logsError.value = describe(failure)
  } finally {
    if (mine === generation) logsLoading.value = false
  }
}

watch(() => props.open, (open) => {
  if (open) void load()
}, { immediate: true })

const dialogEl = ref<HTMLElement | null>(null)
// CW-UX-07: Escape behavior is unchanged (it lives on the dialog keydown now);
// the spine adds focus-on-open, the Tab trap this modal never had, and the
// restore to whatever opened About.
const dialog = useDialog({ panel: dialogEl, open: () => props.open, onClose: () => emit('close') })

/** Seconds the server has been up, in the coarsest unit that still reads. */
function uptime(seconds: number): string {
  if (seconds < 60) return `${Math.round(seconds)} s`
  if (seconds < 3_600) return `${Math.floor(seconds / 60)} m ${seconds % 60} s`
  return `${Math.floor(seconds / 3_600)} h ${Math.floor((seconds % 3_600) / 60)} m`
}

/**
 * The confirmed stop (C-WEB-5.g / R13). The question names the blast radius
 * from the last health answer — running turns, open sessions, the fact that
 * every client drops and only the operator brings the server back — because
 * a click-through confirm that could not say what it interrupts would
 * misrepresent it. Cancel sends nothing. The `stopping` latch is the send
 * once-and-only-once door: a double-click never queues a second shutdown.
 * A refusal (the server enforces the admin scope; hello scopes are the
 * client's best guess, not an authority) lands loud on the control itself
 * and re-arms it. On success the About overlay closes and the draining
 * banner — the non-durable `server.shutdown` event the server broadcasts on
 * its way out — takes over (ConnectionBanner reads connection.shutdown).
 */
async function stopServer(): Promise<void> {
  const known = health.value
  if (!canStop.value || stopping.value || known === null) return
  const turns = known.turnsRunning === 1 ? t('web.chrome.about.stop_running_turns_one') : t('web.chrome.about.stop_running_turns_other')
  const sessions = known.sessionsOpen === 1 ? t('web.chrome.about.stop_open_sessions_one') : t('web.chrome.about.stop_open_sessions_other')
  const question =
    t('web.chrome.about.stop_confirm_head') +
    `${known.turnsRunning} ${turns}` +
    t('web.chrome.about.stop_confirm_mid') +
    `${known.sessionsOpen} ${sessions}` +
    t('web.chrome.about.stop_confirm_foot')
  if (!window.confirm(question)) return
  stopping.value = true
  stopError.value = null
  try {
    await connection.request('server.shutdown', {})
    emit('close')
  } catch (failure) {
    stopping.value = false
    stopError.value = describe(failure)
  }
}
</script>

<template>
  <div v-if="open" class="backdrop" data-testid="about-backdrop" @click.self="emit('close')">
    <div ref="dialogEl" class="dialog" role="dialog" aria-modal="true" :aria-label="t('web.chrome.about.title')" tabindex="-1" data-testid="about-modal" @keydown="dialog.onKeydown">
      <header>
        <h2>{{ t('web.chrome.about.title') }}</h2>
        <button type="button" class="link" :aria-label="t('web.chrome.about.close_aria')" data-testid="about-close" @click="emit('close')">✕</button>
      </header>
      <p v-if="error" class="error" role="alert" data-testid="about-error">{{ error }}</p>
      <p v-else-if="loading && info === null" class="empty">{{ t('web.chrome.about.loading') }}</p>
      <template v-else-if="info !== null">
        <dl class="grid" data-testid="about-info">
          <dt>{{ t('web.chrome.about.version') }}</dt>
          <dd>{{ info.version }}</dd>
          <dt>{{ t('web.chrome.about.protocol') }}</dt>
          <dd>{{ info.protocol }}</dd>
          <dt v-if="info.root">{{ t('web.chrome.about.root') }}</dt>
          <dd v-if="info.root">{{ info.root }}</dd>
          <dt v-if="info.providers?.length">{{ t('web.chrome.about.providers') }}</dt>
          <dd v-if="info.providers?.length">{{ info.providers.join(', ') }}</dd>
          <dt v-if="info.tools?.length">{{ t('web.chrome.about.tools') }}</dt>
          <dd v-if="info.tools?.length" data-testid="about-tools">{{ info.tools.length }}<span class="hint"> · {{ info.tools.join(', ') }}</span></dd>
          <dt v-if="info.commands?.length">{{ t('web.chrome.about.commands') }}</dt>
          <dd v-if="info.commands?.length" data-testid="about-commands">{{ info.commands.join(', ') }}</dd>
          <dt>{{ t('web.chrome.about.permissions') }}</dt>
          <dd data-testid="about-modes">{{ info.permissionModes.join(', ') }} {{ t('web.chrome.about.default_mode', { mode: info.defaultPermissionMode }) }}</dd>
        </dl>
        <template v-if="info.agents?.length">
          <h3>{{ t('web.chrome.about.agents') }}</h3>
          <ul data-testid="about-agents">
            <li v-for="agent in info.agents" :key="agent.name">
              <strong>{{ agent.name }}</strong><template v-if="agent.description"> — {{ agent.description }}</template>
            </li>
          </ul>
        </template>
      </template>
      <template v-if="health !== null">
        <h3>{{ t('web.chrome.about.health') }}</h3>
        <p class="health" data-testid="about-health">
          <span class="state" :class="health.ok ? 'ok' : 'bad'">{{ health.ok ? t('web.chrome.about.health_ok') : t('web.chrome.about.health_not_ok') }}</span>
          · {{ t('web.chrome.about.up') }} {{ uptime(health.uptimeS) }}
          · {{ t('web.chrome.about.sessions_open', { n: health.sessionsOpen }) }}
          · {{ t('web.chrome.about.turns_running', { n: health.turnsRunning }) }}
          <template v-if="health.draining"> · {{ t('web.chrome.about.draining') }}</template>
        </p>
      </template>
      <template v-if="canAdmin">
        <h3 class="logs-head">
          <span>{{ t('web.chrome.about.logs') }}</span>
          <button type="button" class="link" :disabled="logsLoading" :aria-label="t('web.chrome.about.logs_refresh')" data-testid="about-logs-refresh" @click="void loadLogs()">{{ t('web.chrome.about.logs_refresh') }}</button>
        </h3>
        <p v-if="logsLoading && logs === null && logsReason === null && logsError === null" class="empty" data-testid="about-logs-loading">{{ t('web.chrome.about.loading') }}</p>
        <p v-else-if="logsError" class="error" role="alert" data-testid="about-logs-error">{{ logsError }}</p>
        <p v-else-if="logsReason !== null" class="empty" data-testid="about-logs-reason">{{ logsReason === 'no_log' ? t('web.chrome.about.logs_no_log') : logsReason }}</p>
        <pre v-else-if="logs !== null" class="logs" data-testid="about-logs">{{ logs.length === 0 ? t('web.chrome.about.logs_empty') : logs.join('\n') }}</pre>
      </template>
      <p v-if="canStop" class="stop">
        <button type="button" class="danger" :disabled="stopping" data-testid="about-stop" @click="void stopServer()">
          {{ stopping ? t('web.chrome.about.stopping') : t('web.chrome.about.stop_btn') }}
        </button>
        <span v-if="stopError" class="error" role="alert" data-testid="about-stop-error">{{ stopError }}</span>
        <span v-else-if="stopping" class="hint" data-testid="about-stop-state">{{ t('web.chrome.about.stop_state') }}</span>
      </p>
    </div>
  </div>
</template>

<style scoped>
.backdrop {
  position: fixed;
  inset: 0;
  z-index: var(--z-dialog);
  background: rgb(0 0 0 / 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}
.dialog {
  width: min(36rem, 100%);
  max-height: min(80vh, 100%, calc(100dvh - 2rem));
  overflow-y: auto;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 0.75rem 1rem 1rem;
  box-shadow: 0 0.6rem 1.6rem rgb(0 0 0 / 0.35);
}
header {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
}
h2,
h3 {
  margin: 0;
}
h3 {
  margin: 0.75rem 0 0.25rem;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--muted);
}
header .link {
  margin-left: auto;
}
.grid {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.15rem 0.75rem;
  margin: 0.5rem 0 0;
  font-size: 0.8125rem;
}
.grid dt {
  color: var(--muted);
}
.grid dd {
  margin: 0;
  word-break: break-word;
}
.hint {
  color: var(--muted);
}
ul {
  margin: 0.2rem 0 0;
  padding-left: 1.1rem;
  font-size: 0.8125rem;
}
.health {
  margin: 0.2rem 0 0;
  font-size: 0.8125rem;
}
.state.ok {
  color: var(--accent);
}
.state.bad {
  color: var(--error);
}
.empty {
  color: var(--muted);
}
.error {
  color: var(--error);
}
.stop {
  margin: 0.9rem 0 0;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
  font-size: 0.8125rem;
}
.stop .danger {
  color: var(--error);
  background: none;
  border: 1px solid var(--error);
  border-radius: 6px;
  padding: 0.25rem 0.6rem;
  font: inherit;
  cursor: pointer;
}
.stop .danger:disabled {
  opacity: 0.6;
  cursor: default;
}
.logs-head {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
}
.logs-head .link {
  margin-left: auto;
}
.logs {
  margin: 0.3rem 0 0;
  padding: 0.5rem 0.6rem;
  border: 1px solid var(--line);
  border-radius: 6px;
  font-size: 0.72rem;
  line-height: 1.45;
  white-space: pre-wrap;
  word-break: break-word;
  max-height: 16rem;
  overflow-y: auto;
}
/* CW-UX-08: About is already a constant-padded centered sheet; the media block
   only tightens the cap further so its internal scroll never leaves the view.
   UX-12: short — height axis of the shared scale (App.vue header). */
@media (max-height: 480px) {
  .backdrop {
    padding: 0.5rem;
  }
  .dialog {
    max-height: calc(100dvh - 1rem);
  }
}
</style>
