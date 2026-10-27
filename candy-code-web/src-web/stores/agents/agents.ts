import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { MethodResult } from '../../protocol/generated'
import { describe } from '../session'
import { useConnectionStore } from '../connection'

export type TranscriptEntry = MethodResult<'agents.transcript'>['items'][number]
export type ControlVerb = 'cancel' | 'pause' | 'resume' | 'background'
/** How a message rides to a running run: at its next step (`steer`, the wire
 * default) or skipping that step's unstarted sequential calls (`interrupt`). */
export type MessageMode = 'steer' | 'interrupt'

/** A message sent to a run that its transcript has not shown arriving yet. */
export interface PendingMessage {
  text: string
  msgId?: string
  status: 'sending' | 'queued' | 'resuming' | 'failed'
  error?: string
  /** The delivery mode the message was sent with; `interrupt` rows badge `⚡`. */
  mode?: MessageMode
}

/** One run's agent view: its own transcript as read so far, and what was sent to it. */
export interface RunTranscript {
  items: TranscriptEntry[]
  offset: number
  more: boolean
  finished: boolean
  loading: boolean
  error: string | null
  pending: PendingMessage[]
}

/** Pages one {@see useAgentsStore.loadTranscript} call reads at most. */
const MAX_PAGES_PER_LOAD = 16

/** Items a view keeps; older ones fall off the front. */
const MAX_ITEMS = 2000

function key(sessionId: string, agentId: string): string {
  return `${sessionId}/${agentId}`
}

function emptyTranscript(): RunTranscript {
  return { items: [], offset: 0, more: false, finished: false, loading: false, error: null, pending: [] }
}

/**
 * The sessions' delegated runs as the Agents panel and the agent view need
 * them (roadmap O-6c): `agents.subtree` — every run the session's log and the
 * server's live feed know, including runs that finished before this tab
 * followed the session — and, per run, its own transcript (`agents.transcript`,
 * read forward from a byte offset) and the messages sent to it
 * (`agents.message`, `agents.control`). The live beats themselves arrive on
 * the session's own events, which the session reducer keeps; the panel folds
 * the two ({@see import('./tree').foldRuns}).
 */
export const useAgentsStore = defineStore('agents', () => {
  const connection = useConnectionStore()
  const seeds = ref<Record<string, Record<string, unknown>[]>>({})
  const errors = ref<Record<string, string | null>>({})
  const transcripts = ref<Record<string, RunTranscript>>({})
  /**
   * The catalogue's `color:` names resolved by the server to `#rrggbb`
   * (P-AGENT.c: agents.list), keyed by agent name — the same hex the TUI
   * strip paints, already contrast-checked. Kept here (not in the tree fold,
   * which stays pure) so any run row can look one up by name.
   */
  const catalogue = ref<Record<string, string>>({})
  /** Each seed's own `color` hex, keyed by run id — the live preset colour. */
  const runColours = ref<Record<string, string>>({})
  let catalogueLoaded = false

  function seed(sessionId: string): Record<string, unknown>[] {
    return seeds.value[sessionId] ?? []
  }

  /** The server's contrast-checked hex for run $agentId of $name, if it has one. */
  function colourFor(agentId: string, name: string): string | null {
    return runColours.value[agentId] ?? catalogue.value[name] ?? null
  }

  /** Index the `color` hexes $items carry (a seed or a catalogue roster). */
  function indexColours(items: Record<string, unknown>[], byName: boolean): void {
    for (const item of items) {
      const color = typeof item.color === 'string' ? item.color : ''
      const id = typeof item.id === 'string' ? item.id : ''
      const name = typeof item.name === 'string' ? item.name : ''
      if (color === '' || (byName ? name === '' : id === '')) continue
      if (byName) catalogue.value = { ...catalogue.value, [name]: color }
      else runColours.value = { ...runColours.value, [id]: color }
    }
  }

  /** Read the delegation catalogue once; its colours name the runs' dots. */
  async function refreshCatalogue(): Promise<void> {
    if (catalogueLoaded) return
    try {
      const result = await connection.request('agents.list', {})
      indexColours(result.items as Record<string, unknown>[], true)
      catalogueLoaded = true
    } catch {
      // The dots keep their status colours; the next refresh tries again.
    }
  }

  async function refresh(sessionId: string): Promise<void> {
    void refreshCatalogue()
    try {
      const result = await connection.request('agents.subtree', { sessionId })
      seeds.value = { ...seeds.value, [sessionId]: result.items }
      indexColours(result.items, false)
      errors.value = { ...errors.value, [sessionId]: null }
    } catch (failure) {
      errors.value = { ...errors.value, [sessionId]: describe(failure) }
    }
  }

  function transcript(sessionId: string, agentId: string): RunTranscript {
    return transcripts.value[key(sessionId, agentId)] ?? emptyTranscript()
  }

  function put(sessionId: string, agentId: string, view: RunTranscript): void {
    transcripts.value = { ...transcripts.value, [key(sessionId, agentId)]: view }
  }

  /**
   * Read what run $agentId's log holds past what this view has (every page
   * there is, up to a bound); $fresh starts over from the first byte.
   */
  async function loadTranscript(sessionId: string, agentId: string, fresh = false): Promise<void> {
    const current = fresh ? { ...emptyTranscript(), pending: transcript(sessionId, agentId).pending } : transcript(sessionId, agentId)
    if (current.loading) return
    put(sessionId, agentId, { ...current, loading: true })
    let view: RunTranscript = { ...current, loading: true, error: null }
    try {
      for (let page = 0; page < MAX_PAGES_PER_LOAD; page++) {
        const result = await connection.request('agents.transcript', { sessionId, agentId, offset: view.offset })
        const items = [...view.items, ...result.items]
        view = {
          ...view,
          items: items.length > MAX_ITEMS ? items.slice(items.length - MAX_ITEMS) : items,
          offset: result.offset,
          more: result.more,
          finished: result.finished,
        }
        if (!result.more || result.items.length === 0) break
      }
      // What the run has read is no longer pending.
      const arrived = new Set(view.items.filter((item) => item.t === 'inbox' && typeof item.msgId === 'string').map((item) => item.msgId))
      view = { ...view, pending: view.pending.filter((message) => message.msgId === undefined || !arrived.has(message.msgId) || message.status === 'failed') }
    } catch (failure) {
      view = { ...view, error: describe(failure) }
    }
    put(sessionId, agentId, { ...view, loading: false })
  }

  function setPending(sessionId: string, agentId: string, update: (pending: PendingMessage[]) => PendingMessage[]): void {
    const view = transcript(sessionId, agentId)
    put(sessionId, agentId, { ...view, pending: update(view.pending) })
  }

  /**
   * Send $text to run $agentId: into its mailbox while it runs, as a
   * follow-up once it finished. $mode rides `agents.message` — `interrupt`
   * skips the current step's unstarted sequential calls, `steer` (or, on
   * the wire, nothing) delivers at the next step boundary. The message shows
   * as pending until the run's transcript shows it read.
   */
  async function message(sessionId: string, agentId: string, text: string, mode?: MessageMode): Promise<PendingMessage> {
    const entry: PendingMessage = { text, status: 'sending', ...(mode ? { mode } : {}) }
    setPending(sessionId, agentId, (pending) => [...pending, entry])
    let settled: PendingMessage
    try {
      const result = await connection.request('agents.message', { sessionId, agentId, text, ...(mode ? { mode } : {}) })
      settled = { text, status: result.status, ...(result.msgId ? { msgId: result.msgId } : {}), ...(mode ? { mode } : {}) }
    } catch (failure) {
      settled = { text, status: 'failed', error: describe(failure), ...(mode ? { mode } : {}) }
    }
    setPending(sessionId, agentId, (pending) => pending.map((p) => (p === entry || (p.text === text && p.status === 'sending') ? settled : p)))
    return settled
  }

  async function control(sessionId: string, agentId: string, verb: ControlVerb): Promise<void> {
    await connection.request('agents.control', { sessionId, agentId, verb })
  }

  function dismissPending(sessionId: string, agentId: string, index: number): void {
    setPending(sessionId, agentId, (pending) => pending.filter((_, i) => i !== index))
  }

  return { seeds, errors, transcripts, catalogue, runColours, seed, colourFor, refresh, transcript, loadTranscript, message, control, dismissPending }
})
