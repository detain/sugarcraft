import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { MemoryEntry, MethodResult } from '../../protocol/generated'
import { useConnectionStore } from '../connection'
import { describe } from '../session'

export const MEMORY_SCOPES = ['user', 'project', 'agent'] as const
export type MemoryScope = (typeof MEMORY_SCOPES)[number]

/** What `memory.add` did: where the note landed, and whether a project note had to fall back. */
export type MemoryAddResult = MethodResult<'memory.add'>

/** One `memory.log` row: a home-store commit `memory.undo` can restore. */
export type MemoryRevision = MethodResult<'memory.log'>['items'][number]

/** What `memory.undo` did: the revision restored, and the commit that recorded it. */
export type MemoryUndoResult = MethodResult<'memory.undo'>

/**
 * The notes `/memory` keeps (roadmap O-6c, `memory.*`): one scope's notes at
 * a time, a search across every scope, and add / edit / delete. Notes belong
 * to the workspace, not a session, so one list serves every session's panel.
 * Since ISS-020 the server routes through the same MemoryWriter `/memory`
 * uses: a `project` listing carries both the repository store (the repo-local
 * memory directory, shared through the checkout) and the home store, each row
 * badged with its `source`, and an add reports where its note actually landed.
 */
export const useMemoryStore = defineStore('memory', () => {
  const connection = useConnectionStore()
  const scope = ref<MemoryScope>('project')
  const entries = ref<MemoryEntry[]>([])
  const query = ref('')
  const loading = ref(false)
  const historyLoading = ref(false)
  const revisions = ref<MemoryRevision[]>([])
  const error = ref<string | null>(null)

  async function load(next: MemoryScope = scope.value): Promise<void> {
    scope.value = next
    query.value = ''
    loading.value = true
    try {
      entries.value = (await connection.request('memory.list', { scope: next })).items
      error.value = null
    } catch (failure) {
      error.value = describe(failure)
    } finally {
      loading.value = false
    }
  }

  async function search(text: string): Promise<void> {
    query.value = text.trim()
    if (query.value === '') return load()
    loading.value = true
    try {
      entries.value = (await connection.request('memory.search', { query: query.value, limit: 100 })).items
      error.value = null
    } catch (failure) {
      error.value = describe(failure)
    } finally {
      loading.value = false
    }
  }

  async function reload(): Promise<void> {
    await (query.value === '' ? load() : search(query.value))
  }

  async function add(content: string, tags: string[] = []): Promise<MemoryAddResult> {
    const result = await connection.request('memory.add', tags.length === 0 ? { content, scope: scope.value } : { content, scope: scope.value, tags })
    await reload()
    return result
  }

  async function edit(id: string, content: string): Promise<void> {
    await connection.request('memory.edit', { id, content })
    await reload()
  }

  async function remove(id: string): Promise<void> {
    await connection.request('memory.delete', { id })
    entries.value = entries.value.filter((entry) => entry.id !== id)
  }

  /**
   * The home store's commit history, newest first — the revisions `undo`
   * names. Like `/memory log`, `memory.log` commits pending changes first,
   * so the history ends at what is on disk now; that is also why the panel
   * opens this tab on demand rather than loading it on every mount.
   */
  async function log(limit?: number): Promise<void> {
    historyLoading.value = true
    try {
      revisions.value = (await connection.request('memory.log', limit === undefined ? {} : { limit })).items
      error.value = null
    } catch (failure) {
      error.value = describe(failure)
    } finally {
      historyLoading.value = false
    }
  }

  /**
   * Restore the home store to `revision` (omitted: the state before its
   * newest commit); the restore is itself recorded as a new commit. The
   * notes on disk changed with it, so the current scope list reloads.
   */
  async function undo(revision?: string): Promise<MemoryUndoResult> {
    const result = await connection.request('memory.undo', revision === undefined ? {} : { revision })
    await reload()
    return result
  }

  return { scope, entries, query, loading, error, revisions, historyLoading, load, search, add, edit, remove, log, undo }
})
