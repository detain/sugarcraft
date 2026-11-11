import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { MethodResult } from '../../protocol/generated'
import { describe } from '../session'
import { useConnectionStore } from '../connection'
import type { AgentRun } from './tree'
import { isStalled, observeStall, stallSeconds, type StallTrack } from './stall'

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
   * strip paints under the default theme (the server resolves against
   * Theme::default(), so a themed TUI may paint word-colours differently),
   * already contrast-checked. Kept here (not in the tree fold,
   * which stays pure) so any run row can look one up by name.
   */
  const catalogue = ref<Record<string, string>>({})
  /**
   * C-AGT-4.c: each catalogue name's presenting `mode:` as the server read it
   * off the preset's frontmatter — `primary`, `subagent` or `all` (the
   * leniency answer for a built-in or an unreadable declaration; rows from a
   * server older than the field default to `all` too, never hiding an agent).
   * Kept beside {@see catalogue} for the same reason: the roster is launch
   * state of the server, not of any one run.
   */
  const roster = ref<Record<string, string>>({})
  /** Each seed's own `color` hex, keyed by run id — the live preset colour. */
  const runColours = ref<Record<string, string>>({})
  let catalogueLoaded = false

  /**
   * CWP-03 (PTV-P01 parity): the live stall detector's per-run threads, keyed
   * `${sessionId}/${agentId}` in the same shape transcripts use, and the badge
   * state derived from them — per session, the running agents whose output has
   * sat under the char-proxy floor for the whole window, mapped to their whole
   * stalled seconds. The arithmetic is the pure port in `./stall`; the Agents
   * panel re-observes its fold on every change and on its own tick, since
   * silence — unlike the server's per-tick pump — brings no arrival of its own.
   */
  const stallTracks = ref<Record<string, StallTrack>>({})
  const stalledRuns = ref<Record<string, Record<string, number>>>({})

  /**
   * Fold this instant's run rows of $sessionId into the stall detector at
   * $nowMs (the local wall clock; specs pass a synthetic one): a first-sighted
   * live run records a baseline, a newer beat resets or accrues its window, a
   * terminal or departed run loses its thread, and {@see stalledRuns} carries
   * the verdict for the badge.
   */
  function observeStalls(sessionId: string, runs: Record<string, AgentRun>, nowMs: number): void {
    const tracks: Record<string, StallTrack> = { ...stallTracks.value }
    const stalled: Record<string, number> = {}
    for (const [agentId, run] of Object.entries(runs)) {
      const trackKey = key(sessionId, agentId)
      const track = observeStall(tracks[trackKey], run, nowMs)
      if (track === undefined) {
        delete tracks[trackKey]
        continue
      }
      tracks[trackKey] = track
      if (isStalled(track, run, nowMs)) stalled[agentId] = Math.floor(stallSeconds(track, run, nowMs))
    }
    const prefix = `${sessionId}/`
    for (const trackKey of Object.keys(tracks)) {
      if (trackKey.startsWith(prefix) && runs[trackKey.slice(prefix.length)] === undefined) delete tracks[trackKey]
    }
    stallTracks.value = tracks
    const next: Record<string, Record<string, number>> = {}
    for (const [id, seconds] of Object.entries(stalledRuns.value)) {
      if (id !== sessionId) next[id] = seconds
    }
    if (Object.keys(stalled).length > 0) next[sessionId] = stalled
    stalledRuns.value = next
  }

  /**
   * C-AGT-4.c: the agents a browser may offer as the session's primary —
   * exactly what the TUI's /agent cycle presents: every roster name whose
   * declared mode is not `subagent`, sorted the way Chat's
   * presentedPrimaryAgents() sorts it.
   */
  const primaryRoster = computed(() =>
    Object.entries(roster.value)
      .filter(([, mode]) => mode !== 'subagent')
      .map(([name]) => name)
      .sort(),
  )

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
      const items = result.items as Record<string, unknown>[]
      indexColours(items, true)
      // C-AGT-4.c: index the same rows' modes beside their colours, one read
      // serving the Agents panel, the picker and the strip alike.
      const modes: Record<string, string> = { ...roster.value }
      for (const item of items) {
        const name = typeof item.name === 'string' ? item.name : ''
        if (name === '') continue
        modes[name] = typeof item.mode === 'string' ? item.mode : 'all'
      }
      roster.value = modes
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
   *
   * Pairing law: an answer settles exactly one pending row — this send's own
   * row while it is still listed, otherwise the OLDEST row still sending the
   * same text, never scanning backward. The wire carries no client-side id
   * (the server mints msgId only for the answer), so a send's identity is its
   * entry object first and text+FIFO by contract once a hot reload or reset
   * drops that object; concurrent same-text sends settle independently, each
   * against its own row.
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
    setPending(sessionId, agentId, (pending) => {
      const own = pending.indexOf(entry)
      const index = own !== -1 ? own : pending.findIndex((p) => p.text === text && p.status === 'sending')
      if (index === -1) return pending
      return pending.map((p, i) => (i === index ? settled : p))
    })
    return settled
  }

  async function control(sessionId: string, agentId: string, verb: ControlVerb): Promise<void> {
    await connection.request('agents.control', { sessionId, agentId, verb })
  }

  function dismissPending(sessionId: string, agentId: string, index: number): void {
    setPending(sessionId, agentId, (pending) => pending.filter((_, i) => i !== index))
  }

  return { seeds, errors, transcripts, catalogue, roster, primaryRoster, runColours, seed, colourFor, refresh, refreshCatalogue, transcript, loadTranscript, message, control, dismissPending, stallTracks, stalledRuns, observeStalls }
})
