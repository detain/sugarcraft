import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { Events, PermissionMode, SessionSummary } from '../protocol/generated'
import { useConnectionStore } from './connection'

const PAGE = 200
const MAX_PAGES = 10

function stamp(summary: SessionSummary): string {
  return summary.updatedAt ?? summary.createdAt ?? ''
}

/**
 * The sessions index the sidebar lists: `session.list` on every handshake,
 * then kept current by the server-scope `session.created` / `updated` /
 * `deleted` events and by the status of the sessions this tab follows.
 */
export const useSessionsStore = defineStore('sessions', () => {
  const connection = useConnectionStore()
  const byId = ref<Record<string, SessionSummary>>({})
  const loading = ref(false)
  const error = ref<string | null>(null)
  const loaded = ref(false)

  const items = computed(() =>
    Object.values(byId.value).sort((a, b) => stamp(b).localeCompare(stamp(a)) || a.id.localeCompare(b.id)),
  )

  function put(summary: SessionSummary): void {
    byId.value = { ...byId.value, [summary.id]: { ...byId.value[summary.id], ...summary } }
  }

  async function load(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const next: Record<string, SessionSummary> = {}
      let cursor: string | null = null
      for (let page = 0; page < MAX_PAGES; page++) {
        const result: { items: SessionSummary[]; nextCursor: string | null } = await connection.request(
          'session.list',
          cursor === null ? { limit: PAGE } : { limit: PAGE, cursor },
        )
        for (const summary of result.items) next[summary.id] = summary
        cursor = result.nextCursor
        if (cursor === null) break
      }
      await loadOtherRoots(next)
      byId.value = next
      loaded.value = true
    } catch (failure) {
      error.value = failure instanceof Error ? failure.message : String(failure)
    } finally {
      loading.value = false
    }
  }

  /**
   * On a server that lets clients pick a session's directory, the sessions of
   * the other project roots it has open (its running workspace hosts) too —
   * so a session started in a picked directory is still listed after a
   * reload. Best effort: a root that cannot answer lists nothing.
   */
  async function loadOtherRoots(into: Record<string, SessionSummary>): Promise<void> {
    if (connection.hello?.features.dirBrowse?.enabled !== true) return
    try {
      const workspaces = await connection.request('workspace.list', {})
      for (const workspace of workspaces.items) {
        if (workspace.primary || !workspace.running) continue
        const result = await connection.request('session.list', { limit: PAGE, root: workspace.root })
        for (const summary of result.items) into[summary.id] = { ...summary, root: summary.root ?? workspace.root }
      }
    } catch {
      // The server's own sessions are listed either way.
    }
  }

  async function create(options: { name?: string; permissionMode?: PermissionMode; root?: string } = {}): Promise<SessionSummary> {
    const summary = await connection.request('session.create', options)
    put(summary)
    return summary
  }

  async function rename(sessionId: string, name: string): Promise<void> {
    await connection.request('session.rename', { sessionId, name })
    const current = byId.value[sessionId]
    if (current) put({ ...current, name })
  }

  async function remove(sessionId: string): Promise<void> {
    await connection.request('session.delete', { sessionId })
    drop(sessionId)
  }

  function drop(sessionId: string): void {
    const next = { ...byId.value }
    delete next[sessionId]
    byId.value = next
  }

  connection.onHello(() => {
    void load()
  })

  connection.onEvent((envelope) => {
    switch (envelope.type) {
      case 'session.created':
      case 'session.updated':
        put(envelope.data as unknown as Events['session.created'])
        break
      case 'session.deleted':
        drop((envelope.data as unknown as Events['session.deleted']).id)
        break
      case 'session.status': {
        const current = envelope.sessionId === null ? undefined : byId.value[envelope.sessionId]
        if (current) put({ ...current, status: (envelope.data as unknown as Events['session.status']).status })
        break
      }
      case 'message.created': {
        const current = envelope.sessionId === null ? undefined : byId.value[envelope.sessionId]
        const data = envelope.data as unknown as Events['message.created']
        if (current && data.role === 'user') {
          put({ ...current, preview: data.content.slice(0, 200), updatedAt: new Date(envelope.ts).toISOString().replace('T', ' ').slice(0, 19) })
        }
        break
      }
    }
  })

  return { byId, items, loading, loaded, error, load, create, rename, remove }
})
