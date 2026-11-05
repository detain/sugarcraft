<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { ansiSegments } from '../../lib/ansi'
import { t } from '../../lib/i18n'
import type { BackgroundShell } from '../../protocol/generated'
import { useShellsStore } from '../../stores/agents/shells'
import type { ShellInputKey } from '../../stores/agents/shells'
import { useConnectionStore } from '../../stores/connection'
import { describe } from '../../stores/session'

/**
 * The launch's background shells (C-TOOL-3.e), mounted inside the Background
 * panel: each with its state, how it settled, its age, and its recent output
 * on demand — the web twin of the TUI's `/shells`.
 *
 * The table is OWNERSHIP-FILTERED at the server: every call names the
 * session on screen, so a row list is that session's shells plus the
 * untagged launch ones, and another session's shell simply is not there.
 * A running row carries a Stop button for write-scope callers; it rides the
 * registry's kill ladder and is idempotent, so a double click is harmless.
 *
 * POLLING IS THE PANEL'S LIFECYCLE, not the connection's: shells have no
 * events by design, so the section reads `shells.list` once on open and
 * every tick while it is mounted, and unmount stops the timer — an open
 * table costs one list read per interval, a closed panel costs nothing.
 * The interval is slow on purpose: the rows are age-stamps and settle
 * states, not a live byte counter (output is read only into an expanded
 * row, on demand).
 *
 * C-TOOL-6.l: an INTERACTIVE (pty) row expands into a typing pane instead of
 * the plain read-once tail. It is a POLLING, LINE-ORIENTED terminal, not a
 * PTY-over-WebSocket bridge and not a terminal emulator in the browser:
 * output arrives by threading a `shells.output` cursor every TAIL_POLL_MS
 * while the row is expanded and the panel open; keystrokes LEAVE as single
 * `shells.input` calls (the line plus its Enter in one send). Output is
 * painted through ansiSegments — SGR colours become classes, everything else
 * is dropped, and a lone CR/backspace rewrites the line the way the shell's
 * own progress display expects. Without write scope the input row and key
 * buttons are disabled, exactly like Stop. Non-interactive rows keep the
 * plain window tail and never see the typing controls.
 */
const props = defineProps<{ sessionId: string | null }>()

const shells = useShellsStore()
const connection = useConnectionStore()
const canWrite = computed(() => connection.scopes.includes('write'))
const expanded = ref<string | null>(null)
const error = ref<string | null>(null)
const draft = ref('')

/** Rows arrive typed against the pre-6.l generated union; the flag rides the row. */
function isInteractive(shell: BackgroundShell): boolean {
  return (shell as { interactive?: boolean }).interactive === true
}

/** Six of the wire enum's eleven keys get buttons: Enter rides the send
 *  line, and Backspace/Left/Right/C-z are sendable but unbuttoned. */
const KEY_BUTTONS = [
  { key: 'C-c', label: 'Ctrl-C' },
  { key: 'C-d', label: 'Ctrl-D' },
  { key: 'Tab', label: 'Tab' },
  { key: 'Esc', label: 'Esc' },
  { key: 'Up', label: '↑' },
  { key: 'Down', label: '↓' },
] as const

/** Sibling idiom (BackgroundPanel): a rejecting store call lands as an error line, never an uncaught rejection. */
async function act(action: () => Promise<unknown>): Promise<void> {
  error.value = null
  try {
    await action()
  } catch (failure) {
    error.value = describe(failure)
  }
}

/** How often an OPEN panel re-reads the table. A closed one never polls. */
const POLL_MS = 3000
/** How often an EXPANDED interactive row reads the next output slice. */
const TAIL_POLL_MS = 750
let timer: ReturnType<typeof setInterval> | null = null
let tailTimer: ReturnType<typeof setInterval> | null = null

function refresh(): void {
  if (props.sessionId !== null) void shells.load(props.sessionId)
}

function startPolling(): void {
  stopPolling()
  stopTail()
  if (expanded.value !== null) shells.resetTail(expanded.value)
  expanded.value = null
  draft.value = ''
  if (props.sessionId === null) return
  refresh()
  timer = setInterval(refresh, POLL_MS)
}

function stopPolling(): void {
  if (timer !== null) {
    clearInterval(timer)
    timer = null
  }
}

function stopTail(): void {
  if (tailTimer !== null) {
    clearInterval(tailTimer)
    tailTimer = null
  }
}

function startTail(shellId: string): void {
  stopTail()
  const id = props.sessionId
  if (id === null) return
  void shells.pollTail(shellId, id)
  tailTimer = setInterval(() => {
    if (props.sessionId !== null) void shells.pollTail(shellId, props.sessionId)
  }, TAIL_POLL_MS)
}

onMounted(startPolling)
onUnmounted(() => {
  stopPolling()
  stopTail()
})
watch(() => props.sessionId, startPolling)

function toggle(shell: BackgroundShell): void {
  if (expanded.value !== null) {
    stopTail()
    shells.resetTail(expanded.value)
  }
  expanded.value = expanded.value === shell.shellId ? null : shell.shellId
  const id = props.sessionId
  if (expanded.value === null || id === null) return
  if (isInteractive(shell)) startTail(shell.shellId)
  else void shells.loadOutput(shell.shellId, id)
}

async function stop(shellId: string): Promise<void> {
  const id = props.sessionId
  if (id === null) return
  await act(() => shells.stop(shellId, id))
}

/** Enter on the input line: one call carries the text AND its Enter, so the shell sees one complete line. */
async function sendLine(): Promise<void> {
  const id = props.sessionId
  const shellId = expanded.value
  if (id === null || shellId === null) return
  error.value = null
  try {
    if (draft.value === '') await shells.input(shellId, id, undefined, 'Enter')
    else await shells.input(shellId, id, draft.value, 'Enter')
    draft.value = ''
  } catch (failure) {
    error.value = describe(failure)
  }
}

function sendKey(key: ShellInputKey): void {
  const id = props.sessionId
  const shellId = expanded.value
  if (id === null || shellId === null) return
  void act(() => shells.input(shellId, id, undefined, key))
}

function outputText(shellId: string): string {
  const held = shells.output(shellId)
  const body = [held.stdout, held.stderr].filter((part: string) => part !== '').join('\n')
  if (body !== '') return (held.skippedBytes > 0 ? `… ${held.skippedBytes} older bytes skipped\n` : '') + body

  return held.loading ? 'Loading…' : held.error ?? 'No output yet.'
}

function tailSegments(shellId: string) {
  const held = shells.tail(shellId)

  return ansiSegments([held.stdout, held.stderr].filter((part: string) => part !== '').join('\n'))
}

/** What the row's settle column says: the code, else the note, else an honest dash. */
function settleText(shell: BackgroundShell): string {
  if (shell.state === 'running') return t('web.panels.proc_running')
  if (shell.exitCode !== null && shell.exitCode !== undefined) return t('web.panels.proc_exit', { code: shell.exitCode })

  return shell.exitNote ?? t('web.panels.proc_exit_unknown')
}

function ageText(seconds: number): string {
  const whole = Math.max(0, Math.round(seconds))
  if (whole < 60) return `${whole}s`
  if (whole < 3600) return `${Math.floor(whole / 60)}m ${whole % 60}s`

  return `${Math.floor(whole / 3600)}h ${Math.floor((whole % 3600) / 60)}m`
}
</script>

<template>
  <section class="processes" data-testid="processes-section">
    <h3>{{ t('web.panels.proc_title') }}</h3>
    <p v-if="error || shells.error" class="error" role="alert" data-testid="proc-error">{{ error || shells.error }}</p>
    <p v-if="sessionId === null" class="empty">{{ t('web.panels.proc_open_session') }}</p>
    <template v-else>
      <p v-if="shells.items.length === 0" class="empty" data-testid="proc-empty">{{ t('web.panels.proc_empty') }}</p>
      <ul v-else>
        <li v-for="shell in shells.items" :key="shell.shellId" :data-state="shell.state" data-testid="proc-item">
          <button type="button" class="head" :aria-expanded="expanded === shell.shellId" :data-testid="`proc-head-${shell.shellId}`" @click="toggle(shell)">
            <span class="status" :class="shell.state">{{ shell.state }}</span>
            <span class="name">{{ shell.label }}</span>
            <span class="meta">{{ settleText(shell) }}<template v-if="shell.expired"> {{ t('web.panels.proc_expired') }}</template> · {{ ageText(shell.ageSeconds) }}</span>
          </button>
          <div v-if="expanded === shell.shellId" class="body">
            <pre v-if="isInteractive(shell)" data-testid="proc-tail"><span v-for="(seg, i) in tailSegments(shell.shellId)" :key="i" :class="seg.classes">{{ seg.text }}</span></pre>
            <pre v-else data-testid="proc-output">{{ outputText(shell.shellId) }}</pre>
            <div v-if="isInteractive(shell)" class="type">
              <input
                type="text"
                data-testid="proc-input"
                v-model="draft"
                :placeholder="t('web.panels.proc_input_ph')"
                :aria-label="t('web.panels.proc_input_ph')"
                :disabled="!canWrite"
                @keydown.enter.prevent="sendLine"
              />
              <span class="keys" role="group" :aria-label="t('web.panels.proc_keys_aria')">
                <button v-for="k in KEY_BUTTONS" :key="k.key" type="button" :data-testid="`proc-key-${k.key}`" :disabled="!canWrite" @click="sendKey(k.key)">{{ k.label }}</button>
              </span>
            </div>
            <div v-if="canWrite && shell.state === 'running'" class="actions">
              <button type="button" class="danger" :data-testid="`proc-stop-${shell.shellId}`" @click="stop(shell.shellId)">{{ t('web.panels.stop') }}</button>
            </div>
          </div>
        </li>
      </ul>
    </template>
  </section>
</template>

<style scoped>
.processes {
  display: grid;
  gap: 0.4rem;
  border-top: 1px solid var(--line);
  padding-top: 0.5rem;
}
.processes h3 {
  margin: 0;
}
.empty {
  margin: 0;
  color: var(--muted);
  font-size: 0.875rem;
}
.error {
  margin: 0;
  color: var(--error);
  font-size: 0.875rem;
}
ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.35rem;
}
.head {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 0.1rem 0.5rem;
  width: 100%;
  text-align: left;
  background: var(--bg);
}
.status {
  font-size: 0.75rem;
  text-transform: uppercase;
  color: var(--accent);
}
.status.finished {
  color: var(--ok);
}
.status.unknown {
  color: var(--warn);
}
.name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}
.meta {
  grid-column: 1 / 4;
  font-size: 0.8125rem;
  color: var(--muted);
}
.body {
  display: grid;
  gap: 0.4rem;
  padding: 0.4rem 0.2rem;
}
pre {
  margin: 0;
  padding: 0.4rem;
  max-height: 16rem;
  overflow: auto;
  font-family: var(--mono);
  font-size: 0.8125rem;
  background: var(--bg);
  border-radius: var(--radius-2);
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.actions {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
}
.type {
  display: flex;
  gap: 0.4rem;
  align-items: center;
  flex-wrap: wrap;
}
.type input {
  flex: 1;
  min-width: 8rem;
  font-family: var(--mono);
  font-size: 0.8125rem;
}
.keys {
  display: flex;
  gap: 0.25rem;
}
/* The tail pane's SGR classes (the C-TOOL-6.l render model): colours named
   from the ANSI palette, looks mapped to their browser equivalents. */
.processes :deep(.ansi-bold) {
  font-weight: 700;
}
.processes :deep(.ansi-dim) {
  opacity: 0.65;
}
.processes :deep(.ansi-italic) {
  font-style: italic;
}
.processes :deep(.ansi-underline) {
  text-decoration: underline;
}
.processes :deep(.ansi-inverse) {
  filter: invert(1);
}
/* CW-UX-02: the palette follows the active theme through the --ansi-* tokens
   in tokens.css. fg-black rides --ansi-black-fg — the readable-foreground
   black convention terminals use, since pure black on a dark card vanishes. */
.processes :deep(.ansi-fg-black) { color: var(--ansi-black-fg); }
.processes :deep(.ansi-fg-red) { color: var(--ansi-1); }
.processes :deep(.ansi-fg-green) { color: var(--ansi-2); }
.processes :deep(.ansi-fg-yellow) { color: var(--ansi-3); }
.processes :deep(.ansi-fg-blue) { color: var(--ansi-4); }
.processes :deep(.ansi-fg-magenta) { color: var(--ansi-5); }
.processes :deep(.ansi-fg-cyan) { color: var(--ansi-6); }
.processes :deep(.ansi-fg-white) { color: var(--ansi-7); }
.processes :deep(.ansi-fg-bright-black) { color: var(--ansi-8); }
.processes :deep(.ansi-fg-bright-red) { color: var(--ansi-9); }
.processes :deep(.ansi-fg-bright-green) { color: var(--ansi-10); }
.processes :deep(.ansi-fg-bright-yellow) { color: var(--ansi-11); }
.processes :deep(.ansi-fg-bright-blue) { color: var(--ansi-12); }
.processes :deep(.ansi-fg-bright-magenta) { color: var(--ansi-13); }
.processes :deep(.ansi-fg-bright-cyan) { color: var(--ansi-14); }
.processes :deep(.ansi-fg-bright-white) { color: var(--ansi-15); }
.processes :deep(.ansi-bg-black) { background: var(--ansi-0); }
.processes :deep(.ansi-bg-red) { background: var(--ansi-1); }
.processes :deep(.ansi-bg-green) { background: var(--ansi-2); }
.processes :deep(.ansi-bg-yellow) { background: var(--ansi-3); }
.processes :deep(.ansi-bg-blue) { background: var(--ansi-4); }
.processes :deep(.ansi-bg-magenta) { background: var(--ansi-5); }
.processes :deep(.ansi-bg-cyan) { background: var(--ansi-6); }
.processes :deep(.ansi-bg-white) { background: var(--ansi-7); }
</style>
