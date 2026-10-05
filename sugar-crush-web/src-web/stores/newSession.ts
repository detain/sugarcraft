import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { MethodResult, SessionSummary } from '../protocol/generated'
import { useConnectionStore } from './connection'
import { useSessionsStore } from './sessions'

export type DirListing = MethodResult<'fs.listDirs'>

/**
 * "New session", wherever it is clicked (sidebar, dashboard, grid, palette).
 *
 * On a server started with `--allow-dir-browse` (`server.hello`'s
 * `features.dirBrowse.enabled`) it first opens the directory picker
 * (DirectoryPicker.vue, mounted once in App.vue) and resolves with the session
 * started in the picked directory — or null when the picker was cancelled.
 * Without the capability it starts a session on the server's own root at once,
 * as it always did.
 */
export const useNewSessionStore = defineStore('newSession', () => {
  const connection = useConnectionStore()
  const sessions = useSessionsStore()
  const pickerOpen = ref(false)
  const starting = ref(false)
  const error = ref<string | null>(null)
  let settle: ((summary: SessionSummary | null) => void) | null = null

  /** Whether the server lets a client pick the directory a session starts in. */
  const canBrowse = computed(() => connection.hello?.features.dirBrowse?.enabled === true)
  /** The directory picking is confined to. */
  const browseRoot = computed(() => connection.hello?.features.dirBrowse?.root ?? null)
  /** The server's own project root, where the picker opens. */
  const serverRoot = computed(() => connection.hello?.server.root ?? null)

  /** Start a session: through the picker when the server offers one. */
  function start(): Promise<SessionSummary | null> {
    if (!canBrowse.value) return sessions.create()
    settle?.(null)
    error.value = null
    pickerOpen.value = true
    return new Promise((resolve) => {
      settle = resolve
    })
  }

  /** List one directory under the browse root (`fs.listDirs`). */
  function list(path: string | null, showHidden: boolean): Promise<DirListing> {
    return connection.request('fs.listDirs', path === null ? { showHidden } : { path, showHidden })
  }

  /**
   * Start the session in $root: open its workspace (the server re-checks the
   * browse root), then create the session there. The server's own root is
   * its primary workspace, and the session simply the server's own.
   */
  async function startIn(root: string): Promise<SessionSummary> {
    starting.value = true
    error.value = null
    try {
      const opened = await connection.request('workspace.open', { root, browse: true })
      const summary = await sessions.create(opened.primary ? {} : { root: opened.root })
      finish(summary)
      return summary
    } catch (failure) {
      error.value = failure instanceof Error ? failure.message : String(failure)
      throw failure
    } finally {
      starting.value = false
    }
  }

  function cancel(): void {
    finish(null)
  }

  function finish(summary: SessionSummary | null): void {
    pickerOpen.value = false
    const resolve = settle
    settle = null
    resolve?.(summary)
  }

  return { pickerOpen, starting, error, canBrowse, browseRoot, serverRoot, start, list, startIn, cancel }
})
