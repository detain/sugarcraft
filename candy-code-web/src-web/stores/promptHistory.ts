/**
 * WEB-5a-i: the composer's prompt history — the ring buffer the TUI keeps
 * behind ↑/↓, landed on the browser as the same per-viewer convenience the
 * draft and the theme already use (`stores/storage.ts`'s guarded wrapper).
 * Most-recent-first, capped, duplicates of the last entry skipped, and never
 * an empty prompt. A viewer whose storage is blocked simply has no history;
 * nothing else in the UI changes.
 */
import { ref } from 'vue'
import { onKeyErased, physicalKeyMatches, readJson, removeJson, writeJson } from './storage'

/** How far back the ring reaches; the oldest entry falls off the tail. */
export const PROMPT_HISTORY_CAP = 50

/**
 * CW-DBA-4: one entry's persisted length bound — the same 16 KiB character
 * law the drafts map keeps (layout.ts `DRAFT_MAX_PERSISTED_CHARS`). Counting
 * entries protects nothing against one pasted megabyte prompt; the mirror
 * is by comment, not import: the ring has no business depending on the
 * layout store.
 */
export const PROMPT_HISTORY_MAX_ENTRY_CHARS = 16 * 1024

/**
 * CW-DBA-4: a storage write the browser refused (quota, private-mode
 * lockdown) used to vanish into writeJson's return — the drafts map has
 * carried a degraded flag since then, and this is the ring's same flag.
 * Set by the next successful write; nothing else in the UI changes.
 */
export const storageDegraded = ref(false)

function clampEntry(text: string): string {
  return text.length > PROMPT_HISTORY_MAX_ENTRY_CHARS ? text.slice(0, PROMPT_HISTORY_MAX_ENTRY_CHARS) : text
}

const KEY = 'promptHistory'

/** The stored ring, sanitised: only non-empty strings, never more than the cap. */
export function loadPromptHistory(): string[] {
  const stored = readJson<unknown>(KEY, [])
  if (!Array.isArray(stored)) return []
  return stored
    .filter((entry): entry is string => typeof entry === 'string' && entry !== '')
    .slice(0, PROMPT_HISTORY_CAP)
}

/**
 * Record $prompt at the head of the ring and persist it.
 * Returns the ring untouched when it adds nothing: blank, or a repeat of the
 * entry already at the head (re-sending one prompt must not eat two slots).
 *
 * CW-DBA-3: the ring is READ-merge-WRITE like the drafts map in layout.ts —
 * storage is consulted fresh, so a prompt another tab pushed mid-flight
 * rides the result instead of being clobbered by this tab's stale memory.
 * The head-only dedupe law is kept on the stored view too: when the stored
 * head already IS the clamped form of $prompt, nothing new is written and the
 * caller adopts storage's ring.
 *
 * F-NIT-1: the head compares run on CLAMPED forms, before the store is
 * touched. Entries persist clamped (and legacy rows may predate the clamp),
 * so a raw >16 KiB text could never match the head it duplicates — every
 * re-send of one oversized prompt pasted a byte-identical entry at the head,
 * and at cap each one evicted a tail entry the ring should have kept.
 * Clamping the compare side first makes the dedupe see what the ring holds.
 * Two prompts that diverge only beyond 16 KiB are stored identically, so
 * comparing their stored forms is exactly the dedupe law the entries obey.
 */
export function pushPromptHistory(history: string[], prompt: string): string[] {
  const text = prompt.trim()
  const head = clampEntry(text)
  const prior = history[0]
  if (text === '' || (prior !== undefined && clampEntry(prior) === head)) return history
  const stored = loadPromptHistory()
  const storedHead = stored[0]
  if (storedHead !== undefined && clampEntry(storedHead) === head) return stored
  const next = [text, ...stored].map(clampEntry).slice(0, PROMPT_HISTORY_CAP)
  if (writeJson(KEY, next)) storageDegraded.value = false
  else storageDegraded.value = true
  return next
}

/**
 * The ring filtered the way the TUI's Ctrl+G search filters it
 * ({@see \SugarCraft\Code\Tui\HistorySearch::computeMatches()}): case-
 * insensitive containment, recency IS the rank — the ring is stored
 * newest-first and the filter preserves that order, so the freshest match is
 * always the first row. An empty query returns the whole ring.
 */
export function searchPromptHistory(history: string[], query: string): string[] {
  if (query === '') return history
  const needle = query.toLowerCase()
  return history.filter((entry) => entry.toLowerCase().includes(needle))
}

/** Listeners told when the stored ring is erased — here or in another tab. */
type ClearedListener = () => void
const clearedListeners = new Set<ClearedListener>()
let clearWatchAttached = false

function notifyCleared(): void {
  for (const listener of [...clearedListeners]) listener()
}

function onStorageEvent(event: StorageEvent): void {
  // A removal made in ANOTHER tab arrives as some name this key answers to,
  // with a null newValue. The event reports the name the WRITING tab resolved,
  // which is stale against an import-time snapshot the moment any tab rotates
  // the cache scope — so the match runs PER EVENT through `physicalKeyMatches`
  // (L-DBA MAJOR-1: a snapshotted name silently stopped firing). A whole-
  // storage `localStorage.clear()` fires once with key === null. Neither the
  // browser's DevTools erasure nor a sign-out in a second tab may leave this
  // tab's ring alive to be re-persisted by the next send.
  if (event.key === null) notifyCleared()
  else if (event.newValue === null && physicalKeyMatches(KEY, event.key)) notifyCleared()
}

/**
 * WEB-6-P1: subscribe to erasures of the stored ring, wherever they happen.
 * The `storage` event never fires in the tab that made the change, so
 * `clearPromptHistory()` below notifies in-tab listeners directly; cross-tab
 * removals arrive over the lazily-attached window listener (at most one).
 * MAJOR-1 adds the in-tab erase bus beside the window listener: `rotate-` and
 * `dropCacheScope()` fire the ring's logical key, so a sign-out or a re-login
 * in THIS tab reaches the ring's holders the same way the drafts watcher
 * reaches the map's — a scope swap in the owning tab must not leave an open
 * composer holding a ring whose storage name is already dead weight. The
 * logout path's `clearPromptHistory()` then notifies a second time; every
 * holder resets to empty, and the double is idempotent by construction.
 * Returns the unsubscribe.
 */
export function onPromptHistoryCleared(listener: ClearedListener): () => void {
  if (!clearWatchAttached) {
    if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
      window.addEventListener('storage', onStorageEvent)
    }
    onKeyErased(KEY, notifyCleared)
    clearWatchAttached = true
  }
  clearedListeners.add(listener)
  return () => {
    clearedListeners.delete(listener)
  }
}

/**
 * Erase the stored ring and tell every holder in this tab to drop its copy
 * (WEB-6-P1: the in-memory ring was once kept deliberately — which let an
 * open tab's next send rewrite the whole pre-sign-out history from memory).
 * Other tabs hear it over the `storage` event.
 * What the viewer typed stays on this browser — in the ring, under the
 * current login's cache scope (`stores/storage.ts`, CW-DBA-9) — until it is
 * cleared: the palette's “Clear prompt history” action and sign-out both
 * erase it, and it was never sent anywhere except the server that answered
 * those very prompts.
 */
export function clearPromptHistory(): void {
  removeJson(KEY)
  notifyCleared()
}
