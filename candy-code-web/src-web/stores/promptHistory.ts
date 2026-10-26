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

/**
 * Erase the stored ring (the in-memory copy callers hold is untouched).
 * What the viewer typed stays on this browser in `candy-code-web:promptHistory`
 * until it is cleared — sign-out clears it, and it was never sent anywhere
 * except the server that answered those very prompts.
 */
export function clearPromptHistory(): void {
  removeJson(KEY)
}
