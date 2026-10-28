import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { BackgroundShell } from '../../protocol/generated'
import { useConnectionStore } from '../connection'
import { describe } from '../session'

export interface ShellOutput {
  stdout: string
  stderr: string
  /** The "out.err" token of the last read — what the NEXT slice starts from. */
  cursor: string
  /** Bytes the fresh tail-wins window dropped off the head (why the pane may open mid-file). */
  skippedBytes: number
  loading: boolean
  error: string | null
}

const FRESH: ShellOutput = { stdout: '', stderr: '', cursor: '0.0', skippedBytes: 0, loading: false, error: null }

/**
 * The launch's background shells (C-TOOL-3.e), the web twin of the TUI's
 * `/shells`: `shells.list` is owner-filtered by the session the panel is
 * open on, so the store is per-session-keyed in its CALLS — the server
 * answers one session's table per request, and a foreign id reads as
 * unknown. There are NO shell events by design: the panel's own polling is
 * the refresh channel, started and stopped by ProcessesSection with the
 * panel's lifecycle, so an open table costs one list read per interval and a
 * closed one costs nothing. Output is read on demand — a fresh
 * `shells.output` window tail-wins, which is exactly the "recent output" the
 * row expands to. Stop rides the registry's idempotent kill ladder.
 */
export const useShellsStore = defineStore('shells', () => {
  const connection = useConnectionStore()
  const items = ref<BackgroundShell[]>([])
  const outputs = ref<Record<string, ShellOutput>>({})
  const error = ref<string | null>(null)

  async function load(sessionId: string): Promise<void> {
    try {
      const result = await connection.request('shells.list', { sessionId })
      items.value = result.items
      error.value = null
    } catch (failure) {
      error.value = describe(failure)
    }
  }

  function output(shellId: string): ShellOutput {
    return outputs.value[shellId] ?? FRESH
  }

  /** The most recent bytes of $shellId, replacing the held window (the tail IS the answer). */
  async function loadOutput(shellId: string, sessionId: string): Promise<void> {
    const current = output(shellId)
    if (current.loading) return
    outputs.value = { ...outputs.value, [shellId]: { ...current, loading: true } }
    let next: ShellOutput = { ...current, loading: false, error: null }
    try {
      const result = await connection.request('shells.output', { sessionId, shellId })
      next = { stdout: result.stdout, stderr: result.stderr, cursor: result.cursor, skippedBytes: result.skippedBytes, loading: false, error: null }
    } catch (failure) {
      next = { ...next, error: describe(failure) }
    }
    outputs.value = { ...outputs.value, [shellId]: next }
  }

  /** Stop one shell, then re-read the table so the row settles without waiting a poll. */
  async function stop(shellId: string, sessionId: string): Promise<void> {
    await connection.request('shells.stop', { sessionId, shellId })
    await load(sessionId)
  }

  return { items, outputs, error, load, output, loadOutput, stop }
})
