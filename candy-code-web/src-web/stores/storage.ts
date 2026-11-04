/**
 * Per-viewer conveniences in localStorage — drafts, layout, theme. Every
 * access is guarded: storage can be absent, full or blocked (private mode,
 * cleared site data), and the UI must work the same without it. The token is
 * never kept here; the one secret that is (the login secret, ISS-007, stored
 * by `protocol/auth.ts` under its own key) is origin-scoped, minted by this
 * very sign-in, and useless without the HttpOnly cookie beside it.
 */
const PREFIX = 'candy-code-web:'

/**
 * The versioned namespace (CW-DBA-16). Keys the helpers own will be re-read
 * under this prefix once every raw `candy-code-web:` literal in the tree is
 * either routed through `storageKey()` or added to its sweep list, so the
 * flip and the sweep land together rather than stranding a reader.
 */
const VERSIONED_PREFIX = 'candy-code-web:v1:'

/**
 * One-time migration sweep (CW-DBA-16): move each listed bare key from the
 * unversioned prefix to the versioned one. A destination that already exists
 * is never clobbered, and the unlisted keys stay put for their owners to claim
 * — a partial sweep is safe by construction. Blocked or absent storage skips
 * the whole sweep silently: boot does not depend on a convenience move.
 */
export function migrateUnversionedKeys(movable: readonly string[]): void {
  try {
    const store = globalThis.localStorage
    if (!store) return
    for (const key of movable) {
      const unversioned = PREFIX + key
      const raw = store.getItem(unversioned)
      if (raw === null) continue
      const versioned = VERSIONED_PREFIX + key
      if (store.getItem(versioned) === null) store.setItem(versioned, raw)
      store.removeItem(unversioned)
    }
  } catch {
    // storage blocked: every key simply stays where it is
  }
}

export function readJson<T>(key: string, fallback: T): T {
  try {
    const raw = globalThis.localStorage?.getItem(PREFIX + key)
    return raw === null || raw === undefined ? fallback : (JSON.parse(raw) as T)
  } catch {
    return fallback
  }
}

export function writeJson(key: string, value: unknown): boolean {
  try {
    globalThis.localStorage?.setItem(PREFIX + key, JSON.stringify(value))
    return true
  } catch {
    // storage full or blocked: a convenience lost, nothing more. The return
    // value lets the holders that persist user-typed text — the drafts map
    // and the prompt history ring (CW-DBA-4) — show that their memory and
    // the disk have diverged.
    return false
  }
}

/** The `localStorage` name for a wrapper key, exactly as the `storage` event reports it. */
export function storageKey(key: string): string {
  return PREFIX + key
}

/** Drop a stored key entirely — the way out for anything a viewer recorded. */
export function removeJson(key: string): void {
  try {
    globalThis.localStorage?.removeItem(PREFIX + key)
  } catch {
    // nothing to clear if storage is blocked
  }
}

/**
 * In-tab erasure bus (the `storage` event never fires in the tab that made
 * the change): `eraseKey` drops the stored copy AND tells this tab's live
 * holders, so a pending debounced writer cannot resurrect what sign-out
 * just deleted. Cross-tab removals arrive over the real `storage` event.
 */
const eraseWatchers = new Map<string, Set<() => void>>()

export function onKeyErased(key: string, listener: () => void): () => void {
  const set = eraseWatchers.get(key) ?? new Set<() => void>()
  set.add(listener)
  eraseWatchers.set(key, set)
  return () => {
    set.delete(listener)
  }
}

export function eraseKey(key: string): void {
  removeJson(key)
  for (const listener of [...(eraseWatchers.get(key) ?? [])]) listener()
}
