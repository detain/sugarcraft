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
 * WEB-3b: the answer carries this root's trust prompt (`sources.trust`), and
 * the same prompt arrives live as a `permission.asked` with kind "trust" —
 * the approvals drawer deliberately does not hold those, this store does.
 * Verdicts ride `context.trust` and land back on the same record.
 *
 * C-SEC-4: the prompt now also arrives before any panel has measured — the
 * server mints it on `session.subscribe` — so it is shelved in `pendingTrust`,
 * independent of `sources`, and the StatusBar badge reads that shelf. The
 * answer carries `sessionId` because the gateway routes trust asks to the
 * workspace host that minted them, not to the primary process.
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
   * C-SEC-4: the open trust prompt, held WITHOUT `sources`. The server now
   * mints the prompt on `session.subscribe`, so it can arrive before any
   * panel has measured; the badge must still see it.
   */
  const pendingTrust = ref<TrustPrompt | null>(null)

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
      // C-SEC-4: the measurement re-states the truth about this root's prompt.
      // Adopt it; a settled or vanished prompt retires the badge.
      if (result.trust) pendingTrust.value = { ...result.trust, sessionId: id }
      else if (pendingTrust.value !== null && (pendingTrust.value.sessionId !== id || pendingTrust.value.status !== 'asked')) pendingTrust.value = null
    } catch (failure) {
      if (mine !== generation) return
      // Fail soft: the panel shows the reason and stays mounted for a reload.
      error.value = failure instanceof Error ? failure.message : String(failure)
    } finally {
      if (mine === generation) loading.value = false
    }
  }

  /**
   * Answer the open trust prompt. A settled-elsewhere refusal (`already_resolved`
   * or `ask_not_found`) clears the prompt — the truth is that it is gone — and
   * reports failure so the panel can say so; anything else re-stamps the record
   * with the server's verdict. `trust_write_failed` rethrows untouched: the
   * server left the record `asked` precisely so the answer can be repeated.
   */
  async function respondTrust(decision: TrustDecision, remember: TrustRemember): Promise<boolean> {
    const askId = sources.value?.trust?.askId ?? pendingTrust.value?.askId
    // C-SEC-4: the gateway routes `context.trust` by sessionId to the host
    // that minted the prompt — an answer without one is refused at the schema.
    const sid = sessionId.value ?? pendingTrust.value?.sessionId
    if (!askId || !sid) return false
    try {
      const result = await connection.request('context.trust', { sessionId: sid, askId, decision, remember })
      if (sources.value?.trust) sources.value = { ...sources.value, trust: { ...sources.value.trust, status: result.status, persisted: result.persisted } }
      if (pendingTrust.value?.askId === askId) pendingTrust.value = { ...pendingTrust.value, status: result.status, persisted: result.persisted }
      return true
    } catch (failure) {
      if (failure instanceof ProtocolError && (failure.kind === 'already_resolved' || failure.kind === 'ask_not_found')) {
        if (sources.value?.trust?.askId === askId) sources.value = { ...sources.value, trust: null }
        if (pendingTrust.value?.askId === askId) pendingTrust.value = null
        return false
      }
      throw failure
    }
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
    if (envelope.type !== 'permission.asked') return
    const data = envelope.data as unknown as Events['permission.asked']
    // Only trust prompts belong here; the approvals store owns the rest.
    if (!('kind' in data) || data.kind !== 'trust') return
    // C-SEC-4: the prompt may arrive before any panel measured — hold it on
    // the badge shelf regardless of `sources`, for this session only, and
    // never over a different prompt that still awaits its answer.
    if (sessionId.value !== null && data.sessionId !== sessionId.value) return
    const open = pendingTrust.value
    if (open !== null && open.askId !== data.askId && open.status === 'asked') return
    pendingTrust.value = data
    // A prompt is about the root a measurement ran for; show it on the session
    // that measured, never on an unrelated open panel, and never over an
    // already-settled record (the server's one answer per root is final).
    if (sources.value === null || sources.value.trust) return
    if (data.sessionId !== sessionId.value) return
    sources.value = { ...sources.value, trust: data }
  })

  return { sources, loading, loaded, error, sessionId, pendingTrust, load, respondTrust }
})
