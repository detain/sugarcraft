/**
 * WEB-5a-i: the composer's prompt history — the ring buffer the TUI keeps
 * behind ↑/↓, landed on the browser as the same per-viewer convenience the
 * draft and the theme already use (`stores/storage.ts`'s guarded wrapper).
 * Most-recent-first, capped, duplicates of the last entry skipped, and never
 * an empty prompt. A viewer whose storage is blocked simply has no history;
 * nothing else in the UI changes.
 */
import { readJson, removeJson, writeJson } from './storage'

/** How far back the ring reaches; the oldest entry falls off the tail. */
export const PROMPT_HISTORY_CAP = 50

const KEY = 'promptHistory'

/**
 * WEB-6-P1: the fully-prefixed localStorage key as `stores/storage.ts` writes
 * it (`PREFIX + KEY`). The `storage` event reports the prefixed name, and the
 * cross-tab clear watch has to recognise exactly this one.
 */
const STORAGE_KEY = 'candy-code-web:promptHistory'

/** The stored ring, sanitised: only non-empty strings, never more than the cap. */
export function loadPromptHistory(): string[] {
  const stored = readJson<unknown>(KEY, [])
  if (!Array.isArray(stored)) return []
  return stored
    .filter((entry): entry is string => typeof entry === 'string' && entry !== '')
    .slice(0, PROMPT_HISTORY_CAP)
}

/**
 * Record $prompt at the head of $history and persist the ring.
 * Returns $history untouched when it adds nothing: blank, or a repeat of the
 * entry already at the head (re-sending one prompt must not eat two slots).
 */
export function pushPromptHistory(history: string[], prompt: string): string[] {
  const text = prompt.trim()
  if (text === '' || history[0] === text) return history
  const next = [text, ...history].slice(0, PROMPT_HISTORY_CAP)
  writeJson(KEY, next)
  return next
}

/** Listeners told when the stored ring is erased — here or in another tab. */
type ClearedListener = () => void
const clearedListeners = new Set<ClearedListener>()
let storageWatchAttached = false

function notifyCleared(): void {
  for (const listener of [...clearedListeners]) listener()
}

function onStorageEvent(event: StorageEvent): void {
  // A removal made in ANOTHER tab arrives as this key with a null newValue;
  // a whole-storage `localStorage.clear()` fires once with key === null.
  // Neither the browser's DevTools erasure nor a sign-out in a second tab may
  // leave this tab's ring alive to be re-persisted by the next send.
  if (event.key === STORAGE_KEY && event.newValue === null) notifyCleared()
  else if (event.key === null) notifyCleared()
}

/**
 * WEB-6-P1: subscribe to erasures of the stored ring, wherever they happen.
 * The `storage` event never fires in the tab that made the change, so
 * `clearPromptHistory()` below notifies in-tab listeners directly; cross-tab
 * removals arrive over the lazily-attached window listener (at most one).
 * Returns the unsubscribe.
 */
export function onPromptHistoryCleared(listener: ClearedListener): () => void {
  if (!storageWatchAttached && typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
    window.addEventListener('storage', onStorageEvent)
    storageWatchAttached = true
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
 * What the viewer typed stays on this browser in `candy-code-web:promptHistory`
 * until it is cleared — the palette's “Clear prompt history” action and
 * sign-out both clear it, and it was never sent anywhere except the server
 * that answered those very prompts.
 */
export function clearPromptHistory(): void {
  removeJson(KEY)
  notifyCleared()
}
