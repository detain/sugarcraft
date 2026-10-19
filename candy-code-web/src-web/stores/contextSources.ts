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
 */
export const useContextSourcesStore = defineStore('contextSources', () => {
  const connection = useConnectionStore()
  const sources = ref<ContextSourcesResult | null>(null)
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref<string | null>(null)
  /** The session $sources belong to, so another session cannot show a stale panel. */
  const sessionId = ref<string | null>(null)

  async function load(id: string): Promise<void> {
    loading.value = true
    try {
      sources.value = await connection.request('context.sources', { sessionId: id })
      sessionId.value = id
      loaded.value = true
      error.value = null
    } catch (failure) {
      // Fail soft: the panel shows the reason and stays mounted for a reload.
      error.value = failure instanceof Error ? failure.message : String(failure)
    } finally {
      loading.value = false
    }
  }

  /**
   * Answer the open trust prompt. A settled-elsewhere refusal (`already_resolved`
   * or `ask_not_found`) clears the prompt — the truth is that it is gone — and
   * reports failure so the panel can say so; anything else re-stamps the record
   * with the server's verdict.
   */
  async function respondTrust(decision: TrustDecision, remember: TrustRemember): Promise<boolean> {
    const askId = sources.value?.trust?.askId
    if (!askId) return false
    try {
      const result = await connection.request('context.trust', { askId, decision, remember })
      if (sources.value?.trust) sources.value = { ...sources.value, trust: { ...sources.value.trust, status: result.status, persisted: result.persisted } }
      return true
    } catch (failure) {
      if (failure instanceof ProtocolError && (failure.kind === 'already_resolved' || failure.kind === 'ask_not_found')) {
        if (sources.value?.trust?.askId === askId) sources.value = { ...sources.value, trust: null }
        return false
      }
      throw failure
    }
  }

  connection.onEvent((envelope) => {
    if (envelope.type !== 'permission.asked') return
    const data = envelope.data as unknown as Events['permission.asked']
    // Only trust prompts belong here; the approvals store owns the rest.
    if (!('kind' in data) || data.kind !== 'trust') return
    // A prompt is about the root a measurement ran for; show it on the session
    // that measured, never on an unrelated open panel, and never over an
    // already-settled record (the server's one answer per root is final).
    if (sources.value === null || sources.value.trust) return
    if (data.sessionId !== sessionId.value) return
    sources.value = { ...sources.value, trust: data }
  })

  return { sources, loading, loaded, error, sessionId, load, respondTrust }
})
