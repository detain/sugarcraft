import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { MethodParams, MethodResult } from '../protocol/generated'
import { useConnectionStore } from './connection'

export type GenerateSubmitParams = MethodParams<'generate.submit'>
export type GenerateStatusResult = MethodResult<'generate.status'>

/** States after which no further `generate.status` poll is worth sending. */
const TERMINAL_STATES: GenerateStatusResult['state'][] = ['done', 'failed', 'cancelled']

/** The job being watched, stamped with the session that owns it. */
export interface WatchedJob {
  sessionId: string
  jobId: string
  state: GenerateStatusResult['state']
  progress01: number
  etaSeconds?: number
  error?: string
  artifacts?: GenerateStatusResult['artifacts']
}

/**
 * WEB-1d: the client half of media generation. `generate.submit` (Write,
 * side-effecting) answers `{jobId}`; the job's life is then followed through
 * `generate.status` polls until a terminal state, and finished artifacts
 * render through MediaCard (the one sanctioned `media.fetch` surface). The
 * store owns the wire and the poll timer; the panel keeps its template honest.
 *
 * Session-scoped and fail-soft like its sibling `contextSources`: the watched
 * job names its session, a session switch drops the watch (the server job
 * outlives the view — nothing here cancels it), and a refused poll lands an
 * error line rather than throwing at the drawer. The submit request itself
 * carries only the fields the server accepts — `generate.submit` is a
 * two-kind method since C-WEB-3.f (`kind: image|video`, default image, is a
 * wire dial selecting the tool door; it also labels the returned MediaRef
 * rows), and each kind's key set is forwarded whole by the server.
 *
 * The generation gate is the AboutModal/contextSources latch once more: a
 * settle (or failure) from a superseded submit, poll or follow belongs to a
 * visit that already ended and is dropped whole.
 */
export const useGenerateStore = defineStore('generate', () => {
  const connection = useConnectionStore()

  const job = ref<WatchedJob | null>(null)
  /** The submit request is in flight. */
  const busy = ref(false)
  /** Last wire failure line (fail-soft; cleared by the next successful call). */
  const error = ref<string | null>(null)
  /** Poll spacing; a test seam as much as a dial (house pattern: writable ref). */
  const pollMs = ref(1000)

  let generation = 0
  let timer: ReturnType<typeof setTimeout> | null = null
  /** Job id of the in-flight poll — the guard is per job, so a straggler
   *  request of an abandoned watch never swallows a new session's first poll. */
  let pollInFlight: string | null = null

  function stopTimer(): void {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  function isTerminal(state: GenerateStatusResult['state']): boolean {
    return TERMINAL_STATES.includes(state)
  }

  function schedulePoll(mine: number): void {
    stopTimer()
    if (isTerminal(job.value?.state ?? 'done')) return
    timer = setTimeout(() => {
      timer = null
      if (mine === generation) void poll()
    }, pollMs.value)
  }

  /** One `generate.status` round; re-armed on arrival unless the job settled. */
  async function poll(): Promise<void> {
    const watched = job.value
    if (watched === null || isTerminal(watched.state) || pollInFlight === watched.jobId) return
    pollInFlight = watched.jobId
    const mine = generation
    try {
      const result = await connection.request('generate.status', { sessionId: watched.sessionId, jobId: watched.jobId })
      if (mine !== generation || job.value?.jobId !== watched.jobId) return
      job.value = { ...watched, ...result, artifacts: result.artifacts ?? watched.artifacts }
      if (isTerminal(result.state)) stopTimer()
      else schedulePoll(mine)
    } catch (failure) {
      if (mine !== generation) return
      // Fail soft, and stop hammering: the ledger refused this watch; the
      // panel's reload button re-arms deliberately.
      stopTimer()
      error.value = failure instanceof Error ? failure.message : String(failure)
    } finally {
      if (pollInFlight === watched.jobId) pollInFlight = null
    }
  }

  /** Submit a generation over `generate.submit` and start watching the job. */
  async function submit(params: GenerateSubmitParams): Promise<boolean> {
    if (busy.value) return false
    const mine = ++generation
    stopTimer()
    busy.value = true
    error.value = null
    try {
      const result = await connection.request('generate.submit', params)
      if (mine !== generation) return false
      job.value = { sessionId: params.sessionId, jobId: result.jobId, state: 'queued', progress01: 0 }
      schedulePoll(mine)
      return true
    } catch (failure) {
      if (mine !== generation) return false
      error.value = failure instanceof Error ? failure.message : String(failure)
      return false
    } finally {
      if (mine === generation) busy.value = false
    }
  }

  /** Re-arm a watch whose poll was refused (the panel's reload button). */
  function retry(): void {
    error.value = null
    void poll()
  }

  /**
   * Point the watch at the session on screen: another session's job is
   * dropped, timer included — a late `generate.status` answer must never
   * repaint a different session's panel (the stale-wins law).
   */
  function follow(sessionId: string | null): void {
    if (sessionId !== null && job.value?.sessionId === sessionId) return
    generation++
    stopTimer()
    job.value = null
    error.value = null
    busy.value = false
  }

  /** Clear the finished view (server job history stays where it is: the ledger). */
  function forget(): void {
    generation++
    stopTimer()
    job.value = null
    error.value = null
  }

  return { job, busy, error, pollMs, submit, poll, retry, follow, forget, isTerminal }
})
