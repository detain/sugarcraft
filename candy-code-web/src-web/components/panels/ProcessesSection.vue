<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import type { BackgroundShell } from '../../protocol/generated'
import { useShellsStore } from '../../stores/agents/shells'
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
 */
const props = defineProps<{ sessionId: string | null }>()

const shells = useShellsStore()
const connection = useConnectionStore()
const canWrite = computed(() => connection.scopes.includes('write'))
const expanded = ref<string | null>(null)
const error = ref<string | null>(null)

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
let timer: ReturnType<typeof setInterval> | null = null

function refresh(): void {
  if (props.sessionId !== null) void shells.load(props.sessionId)
}

function startPolling(): void {
  stopPolling()
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

onMounted(startPolling)
onUnmounted(stopPolling)
watch(() => props.sessionId, startPolling)

function toggle(shell: BackgroundShell): void {
  expanded.value = expanded.value === shell.shellId ? null : shell.shellId
  if (expanded.value !== null && props.sessionId !== null) void shells.loadOutput(shell.shellId, props.sessionId)
}

async function stop(shellId: string): Promise<void> {
  const id = props.sessionId
  if (id === null) return
  await act(() => shells.stop(shellId, id))
}

function outputText(shellId: string): string {
  const held = shells.output(shellId)
  const body = [held.stdout, held.stderr].filter((part: string) => part !== '').join('\n')
  if (body !== '') return (held.skippedBytes > 0 ? `… ${held.skippedBytes} older bytes skipped\n` : '') + body

  return held.loading ? 'Loading…' : held.error ?? 'No output yet.'
}

/** What the row's settle column says: the code, else the note, else an honest dash. */
function settleText(shell: BackgroundShell): string {
  if (shell.state === 'running') return 'running'
  if (shell.exitCode !== null && shell.exitCode !== undefined) return `exit ${shell.exitCode}`

  return shell.exitNote ?? 'exit ?'
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
    <h3>Processes</h3>
    <p v-if="error || shells.error" class="error" role="alert" data-testid="proc-error">{{ error || shells.error }}</p>
    <p v-if="sessionId === null" class="empty">Open a session to see its background shells.</p>
    <template v-else>
      <p v-if="shells.items.length === 0" class="empty" data-testid="proc-empty">No background shells.</p>
      <ul v-else>
        <li v-for="shell in shells.items" :key="shell.shellId" :data-state="shell.state" data-testid="proc-item">
          <button type="button" class="head" :aria-expanded="expanded === shell.shellId" :data-testid="`proc-head-${shell.shellId}`" @click="toggle(shell)">
            <span class="status" :class="shell.state">{{ shell.state }}</span>
            <span class="name">{{ shell.label }}</span>
            <span class="meta">{{ settleText(shell) }}<template v-if="shell.expired"> · expired</template> · {{ ageText(shell.ageSeconds) }}</span>
          </button>
          <div v-if="expanded === shell.shellId" class="body">
            <pre data-testid="proc-output">{{ outputText(shell.shellId) }}</pre>
            <div v-if="canWrite && shell.state === 'running'" class="actions">
              <button type="button" class="danger" :data-testid="`proc-stop-${shell.shellId}`" @click="stop(shell.shellId)">Stop</button>
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
  border-radius: 4px;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.actions {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
}
</style>
