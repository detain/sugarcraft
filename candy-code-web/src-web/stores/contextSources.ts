import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { MethodResult } from '../protocol/generated'
import { useConnectionStore } from './connection'

export type ContextSourcesResult = MethodResult<'context.sources'>

/**
 * WEB-3a: the session's context sources over `context.sources` — the
 * structured twin of the `/context` text panel. The store owns the fetch and
 * the failure line so the panel keeps its template honest; the data is read
 * on demand and never pollutes (there is nothing to invalidate server-side:
 * a reload re-measures).
 */
export const useContextSourcesStore = defineStore('contextSources', () => {
  const sources = ref<ContextSourcesResult | null>(null)
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref<string | null>(null)
  /** The session $sources belong to, so another session cannot show a stale panel. */
  const sessionId = ref<string | null>(null)

  async function load(id: string): Promise<void> {
    const connection = useConnectionStore()
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

  return { sources, loading, loaded, error, sessionId, load }
})
