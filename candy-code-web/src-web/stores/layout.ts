import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { useConnectionStore } from './connection'
import { readJson, writeJson } from './storage'

const DRAFT_LIMIT = 50

/** Sessions kept open as tabs; the oldest unfocused one goes first past it. */
export const MAX_TABS = 12

/** Appendix O §7.4: the grid shows up to 3×3 tiles. */
export const MAX_TILES = 9

export type LayoutMode = 'tabs' | 'grid'

/** What this tab shows, as `client.viewing` tells the server. */
export interface Showing {
  sessionIds: string[]
  foreground: string | null
  /** Narrate every followed session but the one in front (the grid's glanced-at tiles). */
  narrate: boolean
}

const NOTHING: Showing = { sessionIds: [], foreground: null, narrate: false }

function sameShowing(a: Showing, b: Showing): boolean {
  return a.foreground === b.foreground && a.narrate === b.narrate && a.sessionIds.join('\n') === b.sessionIds.join('\n')
}

function strings(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : []
}

/**
 * How this viewer arranged the page (Appendix O §7.4–§7.5): the sessions open
 * as tabs, whether they show one at a time or tiled in a grid, which one is in
 * front, the approvals drawer, the sidebar drawer (narrow screens), the last
 * session opened, and an unsent draft per session. Persisted per viewer in
 * localStorage — a convenience only, so every read falls back cleanly.
 *
 * It also tells the server what is on screen (`client.viewing`): in the grid,
 * every tile but the focused one is narrated — a tail every 2 s instead of a
 * delta stream — and focusing a tile hands it the reply so far at once.
 */
export const useLayoutStore = defineStore('layout', () => {
  const connection = useConnectionStore()

  const sidebarOpen = ref(false)
  const approvalsOpen = ref(false)
  const lastSessionId = ref<string | null>(readJson<string | null>('lastSession', null))
  const drafts = ref<Record<string, string>>(readJson<Record<string, string>>('drafts', {}))
  const tabs = ref<string[]>(strings(readJson<unknown>('tabs', [])).slice(0, MAX_TABS))
  const mode = ref<LayoutMode>(readJson<LayoutMode>('mode', 'tabs') === 'grid' ? 'grid' : 'tabs')
  const focused = ref<string | null>(readJson<string | null>('focused', null))
  const showing = ref<Showing>(NOTHING)

  const tiles = computed(() => tabs.value.slice(0, MAX_TILES))
  /** 1 tile → 1 column, up to 4 → 2×2, more → 3 columns. */
  const columns = computed(() => (tiles.value.length <= 1 ? 1 : tiles.value.length <= 4 ? 2 : 3))

  watch(lastSessionId, (id) => writeJson('lastSession', id))
  watch(tabs, (ids) => writeJson('tabs', ids))
  watch(mode, (value) => writeJson('mode', value))
  watch(focused, (id) => writeJson('focused', id))

  function draft(sessionId: string): string {
    return drafts.value[sessionId] ?? ''
  }

  function setDraft(sessionId: string, text: string): void {
    const next = { ...drafts.value }
    if (text === '') delete next[sessionId]
    else next[sessionId] = text
    // Bound the map: the oldest drafts go first.
    const keys = Object.keys(next)
    for (const key of keys.slice(0, Math.max(0, keys.length - DRAFT_LIMIT))) delete next[key]
    drafts.value = next
    writeJson('drafts', next)
  }

  function toggleSidebar(open?: boolean): void {
    sidebarOpen.value = open ?? !sidebarOpen.value
  }

  function toggleApprovals(open?: boolean): void {
    approvalsOpen.value = open ?? !approvalsOpen.value
  }

  /** Open $sessionId as a tab (appended once); past {@see MAX_TABS} the oldest unfocused tab closes. */
  function openTab(sessionId: string): void {
    if (tabs.value.includes(sessionId)) return
    const next = [...tabs.value, sessionId]
    while (next.length > MAX_TABS) {
      const drop = next.findIndex((id) => id !== focused.value && id !== sessionId)
      next.splice(drop >= 0 ? drop : 0, 1)
    }
    tabs.value = next
  }

  /**
   * Close $sessionId's tab (and its tile). When it was in front, its
   * neighbour comes forward; the answer is the session now in front.
   */
  function closeTab(sessionId: string): string | null {
    const at = tabs.value.indexOf(sessionId)
    if (at < 0) return focused.value
    const next = tabs.value.filter((id) => id !== sessionId)
    tabs.value = next
    if (focused.value === sessionId) focused.value = next[Math.min(at, next.length - 1)] ?? null
    return focused.value
  }

  function focus(sessionId: string | null): void {
    if (sessionId !== null) openTab(sessionId)
    focused.value = sessionId
  }

  function setMode(value: LayoutMode): void {
    mode.value = value
  }

  /** Move $sessionId's tab to $index (drag to reorder, or a keyboard move). */
  function moveTab(sessionId: string, index: number): void {
    const at = tabs.value.indexOf(sessionId)
    if (at < 0) return
    const next = tabs.value.filter((id) => id !== sessionId)
    next.splice(Math.max(0, Math.min(index, next.length)), 0, sessionId)
    tabs.value = next
  }

  /** Forget tabs whose sessions are gone (deleted, or another root's after a restart). */
  function pruneTabs(known: (sessionId: string) => boolean): void {
    const next = tabs.value.filter(known)
    if (next.length === tabs.value.length) return
    tabs.value = next
    if (focused.value !== null && !next.includes(focused.value)) focused.value = next[0] ?? null
  }

  // ── what is on screen, for the server ──────────────────────────────

  let sendPending = false

  async function sendViewing(): Promise<void> {
    if (!connection.connected) return
    const current = showing.value
    try {
      await connection.request('client.viewing', {
        sessionIds: current.sessionIds,
        foreground: current.foreground,
        narrate: current.narrate,
      })
    } catch {
      // Advisory only: without it the server streams everything in full.
    }
  }

  /** Say what is on screen; sent once per change, coalesced within a tick. */
  function show(next: Showing): void {
    const value = { ...next, sessionIds: next.sessionIds.slice(0, 50) }
    if (sameShowing(value, showing.value)) return
    showing.value = value
    if (sendPending) return
    sendPending = true
    queueMicrotask(() => {
      sendPending = false
      void sendViewing()
    })
  }

  // A new socket knows nothing of what this tab shows.
  connection.onHello(() => {
    if (showing.value.sessionIds.length > 0 || showing.value.foreground !== null) void sendViewing()
  })

  return {
    sidebarOpen,
    approvalsOpen,
    lastSessionId,
    drafts,
    tabs,
    mode,
    focused,
    tiles,
    columns,
    showing,
    draft,
    setDraft,
    toggleSidebar,
    toggleApprovals,
    openTab,
    closeTab,
    focus,
    setMode,
    moveTab,
    pruneTabs,
    show,
  }
})
