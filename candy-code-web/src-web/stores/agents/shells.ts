import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { BackgroundShell, MethodParams } from '../../protocol/generated'
import { useConnectionStore } from '../connection'
import { describe } from '../session'

/** The named keys `shells.input` accepts — the wire enum, spelled once. */
export type ShellInputKey = NonNullable<MethodParams<'shells.input'>['key']>

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

/** One growing tail-pane buffer (C-TOOL-6.l): the appended view of an interactive shell. */
export interface ShellTail {
  stdout: string
  stderr: string
  /** The "out.err" token the NEXT `shells.output` read threads from. */
  cursor: string
  loading: boolean
  error: string | null
}

const FRESH_TAIL: ShellTail = { stdout: '', stderr: '', cursor: '0.0', loading: false, error: null }

/** Each tail stream keeps its newest 64 KiB — the pane budget the design sets. */
export const TAIL_CAP_BYTES = 65536

/** Clip to the newest $capBytes UTF-8 bytes, dropping a partial head fragment. */
function clipToTail(text: string, capBytes: number): string {
  const bytes = new TextEncoder().encode(text)
  if (bytes.length <= capBytes) return text

  return new TextDecoder('utf-8', { fatal: false })
    .decode(bytes.subarray(bytes.length - capBytes))
    .replace(/^\uFFFD+/, '')
}

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
 *
 * C-TOOL-6.l adds the typing door: `shells.input` sends literal bytes and/or
 * one named key as one human-origin send, and interactive rows grow a polling
 * tail pane (`pollTail`, cursor-threaded, newest 64 KiB per stream) beside it.
 * This is a line-oriented door for a polling client — not a PTY stream over
 * the socket; the browser is not a terminal emulator here.
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

  const tails = ref<Record<string, ShellTail>>({})

  function tail(shellId: string): ShellTail {
    return tails.value[shellId] ?? FRESH_TAIL
  }

  /**
   * One polling step of the terminal pane (C-TOOL-6.l): read `shells.output`
   * from the held cursor and APPEND what answers, per stream capped at the
   * newest 64 KiB. Unlike loadOutput's replace-the-window plain tail, nothing
   * already rendered is re-fetched — the cursor IS the progress. A failed step
   * keeps the held bytes and names its error; the next tick retries from the
   * same cursor, so an interruption costs a stall, never lost output.
   */
  async function pollTail(shellId: string, sessionId: string): Promise<void> {
    const current = tail(shellId)
    if (current.loading) return
    tails.value = { ...tails.value, [shellId]: { ...current, loading: true } }
    let next: ShellTail = { ...current, loading: false, error: null }
    try {
      const result = await connection.request('shells.output', { sessionId, shellId, cursor: current.cursor })
      next = {
        stdout: clipToTail(current.stdout + String(result.stdout ?? ''), TAIL_CAP_BYTES),
        stderr: clipToTail(current.stderr + String(result.stderr ?? ''), TAIL_CAP_BYTES),
        cursor: String(result.cursor),
        loading: false,
        error: null,
      }
    } catch (failure) {
      next = { ...next, error: describe(failure) }
    }
    tails.value = { ...tails.value, [shellId]: next }
  }

  /** Collapse forgets the buffer: re-expanding re-reads the tail from the head. */
  function resetTail(shellId: string): void {
    if (tails.value[shellId] !== undefined) {
      const rest = { ...tails.value }
      delete rest[shellId]
      tails.value = rest
    }
  }

  /**
   * Type into an interactive shell (C-TOOL-6.l): literal `text` and/or one
   * named `key`, composed server-side as ONE human send. The answer's cursor
   * is the stdout offset just before the keystroke landed, so a tail pane
   * threads reads from it — its stderr half is kept, because the send tells
   * us nothing about stderr growth. Refusals (unknown, foreign, plain shell,
   * settled, too long, NUL) reject with the wire error; the caller surfaces
   * them, and the held cursor stays put so the next poll misses nothing.
   */
  async function input(shellId: string, sessionId: string, text?: string, key?: ShellInputKey): Promise<{ accepted: boolean; cursor: number }> {
    const params: MethodParams<'shells.input'> = { sessionId, shellId }
    if (text !== undefined) params.text = text
    if (key !== undefined) params.key = key
    const result = await connection.request('shells.input', params)
    const held = tails.value[shellId]
    if (held !== undefined) {
      const parts = /^(\d+)\.(\d+)$/.exec(held.cursor)
      tails.value = { ...tails.value, [shellId]: { ...held, cursor: `${Number(result.cursor)}.${parts?.[2] ?? '0'}` } }
    }

    return { accepted: result.accepted === true, cursor: Number(result.cursor) }
  }

  return { items, outputs, error, load, output, loadOutput, stop, tails, tail, pollTail, resetTail, input }
})
