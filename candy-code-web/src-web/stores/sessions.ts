import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { Events, MethodResult, PermissionMode, SessionSummary } from '../protocol/generated'
import { ProtocolError } from '../protocol/jsonrpc'
import { useConnectionStore } from './connection'

const PAGE = 200
const MAX_PAGES = 10
/** C-SES-2.c: keystrokes inside this window collapse into one server round trip. */
const SEARCH_DEBOUNCE_MS = 250
/** C-WEB-7.c: the biggest export page the server will cut (its own maximum). */
const EXPORT_PAGE_BYTES = 1_048_576

/** A `session.export` answer: the unpaged pair, or a page with its coordinates. */
type ExportPage = MethodResult<'session.export'> & {
  offset?: number
  nextOffset?: number
  total?: number
  more?: boolean
  revision?: string
}

/** One live share page, as `session.shares` lists it (C-WEB-3.e). */
export type SessionShareRow = MethodResult<'session.shares'>['items'][number]

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
  /**
   * C-WEB-3.e: the cross-session live-share listing, in memory only. The
   * server's `session.shares` is the record — the token is never written to
   * any storage, so a revoke works even for a link this browser never made.
   */
  const sharesBySession = ref<Record<string, SessionShareRow[]>>({})
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

  /** WEB-1a: fork on the server; the fresh summary joins the index like a created one. */
  async function fork(sessionId: string): Promise<SessionSummary> {
    const summary = await connection.request('session.fork', { sessionId })
    put(summary)
    return summary
  }

  /**
   * WEB-1a: close the session. The server keeps it listed with a closed
   * status (a `session.status` event would say the same; the local stamp is
   * what makes the row honest before the round trip comes back).
   */
  async function close(sessionId: string): Promise<void> {
    await connection.request('session.close', { sessionId })
    const current = byId.value[sessionId]
    if (current) put({ ...current, status: 'closed' })
  }

  /**
   * WEB-1a: the transcript rendered server-side, in the format asked for.
   *
   * C-WEB-7.c (WEB-1-P2): a long transcript no longer rides one frame. The
   * walk asks for `limit`-sized byte pages, advances by the server's
   * `nextOffset` (a client never byte-counts text JSON substitution may
   * rewrite in transit), and echoes the first page's `revision` from page 2
   * on so pages from two renders can never splice: a session that changed
   * mid-walk answers `export_changed`, and the whole walk restarts once from
   * offset 0. A second conflict rethrows — SessionSidebar's existing error
   * line is where it surfaces. An answer without `more` (any pre-paging
   * server, and every test double) joins as the single page it is, so the
   * `{ format, content }` seam this store always offered is unchanged.
   */
  async function exportTranscript(sessionId: string, format: 'markdown' | 'json' | 'text'): Promise<{ format: string; content: string }> {
    const parts: string[] = []
    let offset = 0
    let revision: string | undefined
    let restarted = false
    for (;;) {
      let page: ExportPage
      try {
        page = (await connection.request('session.export', {
          sessionId,
          format,
          offset,
          limit: EXPORT_PAGE_BYTES,
          ...(revision === undefined ? {} : { revision }),
        })) as ExportPage
      } catch (error) {
        if (error instanceof ProtocolError && error.kind === 'export_changed' && !restarted) {
          restarted = true
          parts.length = 0
          offset = 0
          revision = undefined
          continue
        }
        throw error
      }
      parts.push(page.content)
      if (!page.more) {
        return { format, content: parts.join('') }
      }
      const next = page.nextOffset ?? 0
      if (next <= offset) {
        throw new Error('session.export answered `more` without advancing its offset')
      }
      offset = next
      if (page.revision !== undefined) {
        revision = page.revision
      }
    }
  }

  /**
   * C-WEB-3.e: re-read the server's live share pages. Best effort: a refused
   * or unanswered listing keeps the previous map (a fresh connection has
   * nothing to hide); the menu only ever offers revoke for rows the server
   * listed, so it cannot get ahead of the truth on its own.
   */
  async function loadShares(): Promise<void> {
    try {
      const result = await connection.request('session.shares', {})
      const next: Record<string, SessionShareRow[]> = {}
      for (const row of result.items) {
        const rows = next[row.sessionId]
        if (rows) rows.push(row)
        else next[row.sessionId] = [row]
      }
      sharesBySession.value = next
    } catch {
      // Listing is a read: a server that refuses it simply shows no items.
    }
  }

  /**
   * C-WEB-3.e: publish the share snapshot. The full token arrives
   * only in this response and renders straight into the dialog — the listing
   * re-read rides behind it and nothing here touches web storage.
   */
  async function share(sessionId: string, days?: number): Promise<MethodResult<'session.share'>> {
    const result = await connection.request('session.share', days === undefined ? { sessionId } : { sessionId, days })
    void loadShares()
    return result
  }

  /** C-WEB-3.e: revoke one share page by its full token; false means it was already gone. */
  async function unshare(token: string): Promise<boolean> {
    const result = await connection.request('session.unshare', { token })
    void loadShares()
    return result.revoked
  }

  /**
   * C-WEB-3.d: stop a non-primary root's workspace host (`workspace.close`),
   * drop that root's rows from the index, and reload it. While one of the
   * host's turns runs the server refuses with `turn_running` — that throws
   * here before the drop, so a host that stays up keeps its rows; the
   * sidebar's second confirm retries with `force`.
   */
  async function closeRoot(root: string, force = false): Promise<void> {
    await connection.request('workspace.close', force ? { root, force: true } : { root })
    const next = { ...byId.value }
    for (const [id, summary] of Object.entries(next)) {
      if (summary.root === root) delete next[id]
    }
    byId.value = next
    await load()
  }

  function drop(sessionId: string): void {
    const next = { ...byId.value }
    delete next[sessionId]
    byId.value = next
  }

  /**
   * C-SES-2.c: the sidebar's filter is a SERVER search, not a client-side
   * sweep over whatever the last full page cached — typing routes through
   * `session.list {query}`, which ranks by FTS5 body match (with the
   * name/preview/id LIKE degrade) and carries each hit's snippet. Keystrokes
   * inside SEARCH_DEBOUNCE_MS collapse to one round trip, and a result that
   * loses the race to a newer query (or a clear) is dropped.
   *
   * `searchResults` is null while no query is active — the sidebar lists the
   * full index then; while one is active it lists exactly what the server
   * ranked.
   */
  const searchResults = ref<SessionSummary[] | null>(null)
  const searching = ref(false)
  let searchTimer: ReturnType<typeof setTimeout> | null = null
  let searchSeq = 0

  function search(query: string): void {
    if (searchTimer !== null) {
      clearTimeout(searchTimer)
      searchTimer = null
    }
    const term = query.trim()
    if (term === '') {
      searchSeq++
      searchResults.value = null
      searching.value = false
      return
    }
    searching.value = true
    searchSeq++
    const seq = searchSeq
    searchTimer = setTimeout(() => {
      searchTimer = null
      void (async () => {
        try {
          const result: { items: SessionSummary[]; nextCursor: string | null } = await connection.request(
            'session.list',
            { limit: PAGE, query: term },
          )
          if (seq !== searchSeq) return
          searchResults.value = result.items
        } catch {
          // A failed search leaves the previous results on screen; the index
          // itself is untouched either way.
        } finally {
          if (seq === searchSeq) searching.value = false
        }
      })()
    }, SEARCH_DEBOUNCE_MS)
  }

  connection.onHello(() => {
    void load()
    void loadShares()
  })

  connection.onEvent((envelope) => {
    switch (envelope.type) {
      case 'session.created':
      case 'session.updated':
        put(envelope.data as unknown as Events['session.created'])
        break
      case 'session.deleted':
        drop((envelope.data as unknown as Events['session.deleted']).id)
        // C-SES-3 voids a session's share pages when it is deleted; the
        // listing follows the server rather than guessing locally.
        void loadShares()
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

  return {
    byId,
    items,
    loading,
    loaded,
    error,
    searchResults,
    searching,
    sharesBySession,
    load,
    search,
    create,
    rename,
    remove,
    fork,
    close,
    exportTranscript,
    loadShares,
    share,
    unshare,
    closeRoot,
  }
})
