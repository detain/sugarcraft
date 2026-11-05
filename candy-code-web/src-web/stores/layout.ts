import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { useConnectionStore } from './connection'
import { useSessionsStore } from './sessions'
import { eraseKey, onKeyErased, physicalKeyMatches, readJson, writeJson } from './storage'

const DRAFT_LIMIT = 50

/**
 * How long one persisted draft may be. A pasted megabyte stays fully
 * editable in memory, but only its first DRAFT_MAX_PERSISTED_CHARS reach
 * storage — a single oversized entry would push the other 49 out of the
 * ~5 MB per-origin quota, and the whole map would stop surviving reloads.
 */
export const DRAFT_MAX_PERSISTED_CHARS = 16 * 1024

/**
 * Trailing-edge debounce for draft persistence. `setDraft` rides every
 * keystroke; serialising up to 50 drafts per character is O(stored bytes)
 * per keystroke, and a synchronous `setItem` per character is the kind of
 * work the browser forgives once and not fifty times a second.
 */
const DRAFT_PERSIST_DEBOUNCE_MS = 300

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
  const sessions = useSessionsStore()

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

  // P-TABS.h: a session that finished (busy → idle) while it was not the
  // active one has news the viewer has not seen; the tab strip badges it, and
  // selecting the session (or closing its tab) settles the debt. Deliberately
  // not persisted — after a reload the badge would be stale at once.
  const unseen = ref<Record<string, boolean>>({})
  const lastStatus = new Map<string, string>()

  function clearUnseen(sessionId: string): void {
    if (unseen.value[sessionId] === undefined) return
    const next = { ...unseen.value }
    delete next[sessionId]
    unseen.value = next
  }

  watch(() => sessions.byId, (index) => {
    let marked: Record<string, boolean> | null = null
    for (const [id, summary] of Object.entries(index)) {
      const prev = lastStatus.get(id)
      if (prev === summary.status) continue
      lastStatus.set(id, summary.status)
      if (prev === 'busy' && summary.status === 'idle' && id !== focused.value) {
        marked ??= { ...unseen.value }
        marked[id] = true
      }
    }
    if (marked !== null) unseen.value = marked
  })

  function draft(sessionId: string): string {
    return drafts.value[sessionId] ?? ''
  }

  /**
   * True once a draft write failed (storage full or blocked): memory and
   * disk have silently diverged, and the one place a reader learns that is
   * this flag — the composer's copy still works, it just will not survive
   * reload. One-shot: recovery of the truth is "stop trusting storage",
   * which the wrapper cannot observe.
   */
  const storageDegraded = ref(false)

  let persistTimer: ReturnType<typeof setTimeout> | null = null

  /**
   * Ids emptied since the last write. The in-memory map deletes on empty,
   * so without a tombstone the read-modify-write below would treat "gone
   * from my map" as "never mine" and resurrect the stored copy.
   */
  const pendingErases = new Set<string>()

  /**
   * Write the draft map: read-modify-write over the FRESHEST stored copy, so
   * a second tab editing another session's draft is carried forward instead
   * of clobbered by this tab's snapshot (MA-14: the whole-map rewrite used
   * to re-persist the other tab's stale text and destroy its newer one).
   * This tab's map is authoritative for the keys it holds — and tombstones
   * carry the deletions it made since the last write.
   */
  function persistDrafts(): void {
    if (persistTimer !== null) {
      clearTimeout(persistTimer)
      persistTimer = null
    }
    const stored = readJson<Record<string, string>>('drafts', {})
    const merged: Record<string, string> = { ...stored }
    for (const [id, text] of Object.entries(drafts.value)) {
      // CW-DBA-5: the same touch-keeps-recency law across the storage
      // merge — an existing stored slot is re-opened so the memory order
      // (this tab's freshest edits) lands at the tail eviction drops from
      // the head.
      delete merged[id]
      merged[id] = text.length > DRAFT_MAX_PERSISTED_CHARS ? text.slice(0, DRAFT_MAX_PERSISTED_CHARS) : text
    }
    for (const id of pendingErases) delete merged[id]
    pendingErases.clear()
    // Bound the map: the oldest drafts go first (also against the merged
    // view — a tab that learned other tabs' keys must not persist past them).
    const keys = Object.keys(merged)
    for (const key of keys.slice(0, Math.max(0, keys.length - DRAFT_LIMIT))) delete merged[key]
    // Memory is deliberately NOT replaced by `merged`: the clamp trims what
    // is STORABLE, while the editor keeps the viewer's full text to fix,
    // copy, or send.
    if (!writeJson('drafts', merged)) storageDegraded.value = true
  }

  function setDraft(sessionId: string, text: string): void {
    const next = { ...drafts.value }
    if (text === '') {
      delete next[sessionId]
      pendingErases.add(sessionId)
    } else {
      // CW-DBA-5: delete-before-insert, so a touch MOVES the entry to the
      // recency tail; without it the draft being actively edited keeps its
      // ancient slot and is the first thing eviction drops.
      delete next[sessionId]
      next[sessionId] = text
      pendingErases.delete(sessionId)
    }
    // Bound the map: the oldest drafts go first.
    const keys = Object.keys(next)
    for (const key of keys.slice(0, Math.max(0, keys.length - DRAFT_LIMIT))) delete next[key]
    drafts.value = next
    // Persist on a trailing edge, not per keystroke; blur/pagehide flush.
    if (persistTimer !== null) clearTimeout(persistTimer)
    persistTimer = setTimeout(persistDrafts, DRAFT_PERSIST_DEBOUNCE_MS)
  }

  /**
   * Forget everything recorded about a session that is gone: its draft row,
   * and the layout pointers that named it. Wire this where a session leaves
   * the index (delete, `session.deleted`, workspace close) — an orphaned
   * draft of pasted paths and proprietary text outliving the session is the
   * disclosure the README had not made (fixed alongside it).
   */
  function forget(sessionId: string): void {
    if (drafts.value[sessionId] === undefined && lastSessionId.value !== sessionId && focused.value !== sessionId) return
    if (drafts.value[sessionId] !== undefined) {
      setDraft(sessionId, '') // debounced persist carries the deletion
    }
    if (lastSessionId.value === sessionId) lastSessionId.value = null
    if (focused.value === sessionId) focused.value = null
    closeTab(sessionId)
  }

  /** Erase every stored draft — what signing out now does (MA-12). */
  function clearAllDrafts(): void {
    eraseKey('drafts') // fires this tab's watcher below; disk removal inside
  }

  // Sign-out (here or in another tab) must empty the IN-MEMORY map too: a
  // pending debounced flush would otherwise re-persist the drafts that were
  // just erased, from this tab's snapshot.
  onKeyErased('drafts', () => {
    if (persistTimer !== null) {
      clearTimeout(persistTimer)
      persistTimer = null
    }
    pendingErases.clear()
    drafts.value = {}
  })

  // OD-28: sign-out sweeps the tab-restore state with the caches — the
  // in-memory strip and pointers must die with their disk copies, or this
  // tab's own watch-writers would re-persist session ids the sweep just
  // erased. (An empty re-write is fine and expected; a remembered id is not.)
  onKeyErased('tabs', () => {
    tabs.value = []
  })
  onKeyErased('lastSession', () => {
    lastSessionId.value = null
  })
  onKeyErased('focused', () => {
    focused.value = null
  })

  // A draft written in ANOTHER tab replaces this tab's copy of the whole map
  // (both tabs now RMW, so the arriving map carries this tab's latest keys);
  // a removal — sign-out elsewhere — empties it, so the next keystroke here
  // cannot resurrect it from memory (same law promptHistory already obeys).
  // MAJOR-1: the event key is matched PER EVENT, never against a snapshot of
  // `storageKey('drafts')` — this store mounts pre-login, and every rotate/
  // drop moves the drafts under a fresh physical name the mount-time snapshot
  // never again equals, which silently stopped the cross-tab clear from
  // emptying this tab (and let the pagehide flush below re-persist the dead
  // principal's map into whoever's live bucket the pointer names).
  if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
    window.addEventListener('storage', (event: StorageEvent) => {
      if (event.key !== null && !physicalKeyMatches('drafts', event.key)) return
      if (event.key === null || event.newValue === null) {
        if (persistTimer !== null) {
          clearTimeout(persistTimer)
          persistTimer = null
        }
        pendingErases.clear()
        drafts.value = {}
        return
      }
      try {
        const parsed = JSON.parse(event.newValue) as unknown
        if (parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)) {
          drafts.value = parsed as Record<string, string>
        }
      } catch {
        // unparseable neighbour write: keep this tab's memory, next flush wins
      }
    })
  }

  // The last chance to persist before the page can go away.
  if (typeof window !== 'undefined') {
    window.addEventListener('pagehide', () => persistDrafts())
    window.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') persistDrafts()
    })
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
    clearUnseen(sessionId)
    if (focused.value === sessionId) focused.value = next[Math.min(at, next.length - 1)] ?? null
    return focused.value
  }

  function focus(sessionId: string | null): void {
    if (sessionId !== null) {
      openTab(sessionId)
      clearUnseen(sessionId)
    }
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
    for (const id of Object.keys(unseen.value)) if (!next.includes(id)) clearUnseen(id)
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
    storageDegraded,
    draft,
    setDraft,
    persistDrafts,
    forget,
    clearAllDrafts,
    tabs,
    unseen,
    mode,
    focused,
    tiles,
    columns,
    showing,
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
