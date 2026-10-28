import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { Events, MethodResult } from '../protocol/generated'
import { ProtocolError } from '../protocol/jsonrpc'
import { useConnectionStore } from './connection'

export type ContextSourcesResult = MethodResult<'context.sources'>
export type TrustPrompt = Extract<Events['permission.asked'], { kind: 'trust' }>
export type TrustDecision = 'grant' | 'reject'
export type TrustRemember = 'session' | 'user'

/**
 * WEB-3a: the session's context sources over `context.sources` — the
 * structured twin of the `/context` text panel. The store owns the fetch and
 * the failure line so the panel keeps its template honest; the data is read
 * on demand and never pollutes (there is nothing to invalidate server-side:
 * a reload re-measures).
 *
 * WEB-3b: the answer carries this root's trust prompts (`sources.trusts`),
 * and the same prompts arrive live as `permission.asked` with kind "trust" —
 * the approvals drawer deliberately does not hold those, this store does.
 * Verdicts ride `context.trust` and land back on the same record.
 *
 * C-SEC-4: the prompts now also arrive before any panel has measured — the
 * server mints them on `session.subscribe` — so they are shelved in
 * `pendingTrusts`, independent of `sources`, and the StatusBar badge counts
 * that shelf. The answer carries `sessionId` because the gateway routes trust
 * asks to the workspace host that minted them, not to the primary process.
 *
 * C-SEC-5.b: the shelf is a LIST. The launch census declares up to five grant
 * doors per root (mcp, mcp-pin, hooks, commands, settings), each pending door
 * mints its OWN row of `sources.trusts` — once per (root, target) — and
 * `respondTrust` answers one row by its `askId`; settling a row never touches
 * the others. The measurement re-states the whole truth, including settled
 * rows; a live prompt that arrives between measurements merges in by askId.
 */
export const useContextSourcesStore = defineStore('contextSources', () => {
  const connection = useConnectionStore()
  const sources = ref<ContextSourcesResult | null>(null)
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref<string | null>(null)
  /** The session $sources belong to, so another session cannot show a stale panel. */
  const sessionId = ref<string | null>(null)
  /**
   * C-SEC-4: the open trust prompts, held WITHOUT `sources`. The server mints
   * them on `session.subscribe`, so they can arrive before any panel has
   * measured; the badge must still see them. C-SEC-5.b: the shelf is a list —
   * one row per pending grant door, keyed by `askId`, never one prompt
   * shadowing another.
   */
  const pendingTrusts = ref<TrustPrompt[]>([])

  /**
   * WEB-3a review MINOR-2: the ordering latch, the AboutModal generation
   * gate in store form. Without it a late answer for session A overwrites
   * session B's fresh `sources`+`sessionId`; the panel's
   * `context.sessionId === props.sessionId` guard then blanks B's panel
   * until a manual reload (the candy-query stale-wins lesson). Every load
   * stamps its own number; a settle that arrives after a newer load began
   * belongs to a superseded session and is dropped — answers AND errors,
   * because a refusal for A is not news to B.
   */
  let generation = 0

  async function load(id: string): Promise<void> {
    const mine = ++generation
    loading.value = true
    try {
      const result = await connection.request('context.sources', { sessionId: id })
      if (mine !== generation) return
      sources.value = result
      sessionId.value = id
      loaded.value = true
      error.value = null
      // C-SEC-4/C-SEC-5.b: the measurement re-states the whole truth about
      // this root's prompts — pending rows and settled rows alike — so the
      // shelf is replaced by it, not merged into it. A prompt that vanishes
      // from the list has no server record and retires with it.
      pendingTrusts.value = (result.trusts ?? []).map((row) => ({ ...row, sessionId: id }))
    } catch (failure) {
      if (mine !== generation) return
      // Fail soft: the panel shows the reason and stays mounted for a reload.
      error.value = failure instanceof Error ? failure.message : String(failure)
    } finally {
      if (mine === generation) loading.value = false
    }
  }

  /**
   * Answer one trust prompt by its `askId`. A settled-elsewhere refusal
   * (`already_resolved` or `ask_not_found`) removes that row — the truth is
   * that it is gone — and reports failure so the panel can say so; the other
   * rows are untouched (settling one door never settles another). Anything
   * else re-stamps that row with the server's verdict. `trust_write_failed`
   * rethrows untouched: the server left the record `asked` precisely so the
   * answer can be repeated.
   */
  async function respondTrust(askId: string, decision: TrustDecision, remember: TrustRemember): Promise<boolean> {
    // C-SEC-4: the gateway routes `context.trust` by sessionId to the host
    // that minted the prompt — an answer without one is refused at the schema.
    const sid = sessionId.value ?? pendingTrusts.value.find((row) => row.askId === askId)?.sessionId
    if (!askId || !sid) return false
    try {
      const result = await connection.request('context.trust', { sessionId: sid, askId, decision, remember })
      if (sources.value?.trusts?.some((row) => row.askId === askId)) {
        sources.value = {
          ...sources.value,
          trusts: sources.value.trusts.map((row) => (row.askId === askId ? { ...row, status: result.status, persisted: result.persisted } : row)),
        }
      }
      pendingTrusts.value = pendingTrusts.value.map((row) => (row.askId === askId ? { ...row, status: result.status, persisted: result.persisted } : row))
      return true
    } catch (failure) {
      if (failure instanceof ProtocolError && (failure.kind === 'already_resolved' || failure.kind === 'ask_not_found')) {
        if (sources.value?.trusts?.some((row) => row.askId === askId)) {
          sources.value = { ...sources.value, trusts: sources.value.trusts.filter((row) => row.askId !== askId) }
        }
        pendingTrusts.value = pendingTrusts.value.filter((row) => row.askId !== askId)
        return false
      }
      throw failure
    }
  }

  // C-CTX-2.d: trailing debounce for the `context.changed` reload. Half a second
  // covers the burst a settle produces (settle + compaction + command reasons
  // land back-to-back), and a pending timer never re-arms, so a long run of
  // changes refetches at most once per half second. The reload itself obeys the
  // measured-only law below.
  let reloadTimer: ReturnType<typeof setTimeout> | null = null
  function scheduleReload(id: string): void {
    if (reloadTimer !== null) return
    reloadTimer = setTimeout(() => {
      reloadTimer = null
      void load(id)
    }, 500)
  }

  connection.onEvent((envelope) => {
    if (envelope.type === 'turn.completed') {
      // P-CTX.g: the turn that just settled changed the prompt — the category
      // map, the buffer and the messages row are all re-measured by the next
      // request. A session that has already been measured (the panel is or
      // was open on it) reloads itself; an unmeasured session is left alone,
      // so the store never dials `context.sources` nobody asked for. The
      // generation latch inside load() drops a late answer the same way a
      // session switch does.
      const sid = envelope.sessionId
      if (sid !== null && sid !== undefined && sources.value !== null && sessionId.value === sid) void load(sid)
      return
    }
    if (envelope.type === 'context.changed') {
      // C-CTX-2.d: what the context is built from moved — a settle, a compaction,
      // or a rules/memory/init command. The same measured-only law as the reload
      // above (an unmeasured session is never dialed for), debounced so a burst
      // costs one refetch; the generation latch inside load() drops stragglers.
      const sid = envelope.sessionId
      if (sid !== null && sid !== undefined && sources.value !== null && sessionId.value === sid) scheduleReload(sid)
      return
    }
    if (envelope.type !== 'permission.asked') return
    const data = envelope.data as unknown as Events['permission.asked']
    // Only trust prompts belong here; the approvals store owns the rest.
    if (!('kind' in data) || data.kind !== 'trust') return
    // C-SEC-4: a prompt may arrive before any panel measured — hold it on the
    // badge shelf regardless of `sources`, for this session only.
    if (sessionId.value !== null && data.sessionId !== sessionId.value) return
    // C-SEC-5.b: merge by askId — the census mints one row per pending door,
    // so a second prompt never overrides the first (the old single-prompt
    // refuse-over-open law retired with the shelf becoming a list).
    pendingTrusts.value = mergeTrustRow(pendingTrusts.value, data)
    // A prompt is about the root a measurement ran for; show it on the session
    // that measured, never on an unrelated open panel.
    if (sources.value === null || data.sessionId !== sessionId.value) return
    sources.value = { ...sources.value, trusts: mergeTrustRow(sources.value.trusts ?? [], data) }
  })

  /**
   * C-SEC-5.b: one row per `askId`. A late `asked` delivery never resurrects
   * a row this client already settled or was refused about — the server's one
   * answer per (root, target) is final.
   */
  function mergeTrustRow(rows: TrustPrompt[], row: TrustPrompt): TrustPrompt[] {
    const index = rows.findIndex((existing) => existing.askId === row.askId)
    if (index === -1) return [...rows, row]
    if (row.status === 'asked' && rows[index].status !== 'asked') return rows
    const next = rows.slice()
    next[index] = row
    return next
  }

  return { sources, loading, loaded, error, sessionId, pendingTrusts, load, respondTrust }
})
