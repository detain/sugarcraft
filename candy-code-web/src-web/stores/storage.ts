/**
 * Per-viewer conveniences in localStorage — drafts, layout, theme. Every
 * access is guarded: storage can be absent, full or blocked (private mode,
 * cleared site data), and the UI must work the same without it. The token is
 * never kept here; the one secret that is (the login secret, ISS-007, stored
 * by `protocol/auth.ts` under its own key) is origin-scoped, minted by this
 * very sign-in, and useless without the HttpOnly cookie beside it.
 *
 * CW-DBA-9 (OD-28) adds the principal scope. The transcript caches — the
 * drafts map and the prompt-history ring — persist under a per-login scope
 * segment (`candy-code-web:<scope>/drafts`), and `rotateCacheScope()` /
 * `dropCacheScope()` sweep everything one principal left when the next
 * sign-in rotates the scope or a sign-out drops it. The scope is minted
 * client-side because the browser cannot read the identity behind the
 * HttpOnly cookie, and the hello answer names no stable user — this is the
 * only handle the client has to key its own caches by. A page that starts
 * with no pointer reads a private ephemeral segment instead, so cold-booting
 * on a browser someone else left signed-in meets empty caches, never the
 * previous principal's — the isolation holds even before logout ever fires.
 */
const PREFIX = 'candy-code-web:'

/** The pointer naming whose bucket the transcript caches live in. */
const SCOPE_POINTER = PREFIX + 'cacheScope'

/** A minted scope must be path-safe inside a localStorage name. */
const SCOPE_PATTERN = /^[A-Za-z0-9_-]{1,64}$/

/** The logical keys holding text one principal typed; stored under the scope. */
const SCOPED_KEYS = new Set(['drafts', 'promptHistory'])

/** Everything one principal left behind that is not a preference. */
const PRINCIPAL_KEYS = ['drafts', 'promptHistory', 'tabs', 'lastSession', 'focused']

let ephemeralScope: string | null = null

function mintScope(): string {
  try {
    const uuid = globalThis.crypto?.randomUUID?.()
    if (typeof uuid === 'string' && SCOPE_PATTERN.test(uuid)) return uuid
  } catch {
    // no WebCrypto: the Math.random fallback below
  }
  let hex = ''
  while (hex.length < 32) hex += Math.floor(Math.random() * 0x10000).toString(16).padStart(4, '0')
  return hex
}

/**
 * The scope segment the cache keys resolve to RIGHT NOW: the persisted
 * pointer when one is set — read live, so two tabs of one login share the
 * same bucket and `storage` events keep matching — otherwise a per-page
 * ephemeral that was never persisted and is nobody else's to read.
 */
function currentScope(): string {
  try {
    const stored = globalThis.localStorage?.getItem(SCOPE_POINTER)
    if (typeof stored === 'string' && SCOPE_PATTERN.test(stored)) return stored
  } catch {
    // storage blocked: the ephemeral scope still isolates this page
  }
  if (ephemeralScope === null) ephemeralScope = mintScope()
  return ephemeralScope
}

/** The localStorage name a logical key resolves to under the current scope. */
function physical(key: string): string {
  return SCOPED_KEYS.has(key) ? `${PREFIX}${currentScope()}/${key}` : PREFIX + key
}

export function readJson<T>(key: string, fallback: T): T {
  try {
    const raw = globalThis.localStorage?.getItem(physical(key))
    return raw === null || raw === undefined ? fallback : (JSON.parse(raw) as T)
  } catch {
    return fallback
  }
}

export function writeJson(key: string, value: unknown): boolean {
  try {
    globalThis.localStorage?.setItem(physical(key), JSON.stringify(value))
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
  return physical(key)
}

/**
 * Whether a `storage` event's key names THIS logical key's stored copy, under
 * any scope the event could plausibly arrive from: the name resolving live,
 * any other principal's bucket (`candy-code-web:<scope>/key`), or the bare
 * pre-namespacing copy. Cross-tab watchers MUST compare per event,
 * never against a name captured at module or setup time — the scope rotates
 * under every login and sign-out, and a snapshot matcher goes silently stale
 * at the first transition (the sign-out in the other tab then no longer
 * empties this tab's memory, and this tab's next flush re-persists the dead
 * principal's text into the live bucket). Over-clearing is the safe direction
 * for the foreign-bucket leg: a sweep made in another tab deletes this tab's
 * OLD-bucket file, and by the time the event lands the pointer has usually
 * already moved, so live matching alone cannot see it.
 */
export function physicalKeyMatches(key: string, eventKey: string): boolean {
  if (eventKey === physical(key)) return true
  if (eventKey === PREFIX + key) return true
  if (!SCOPED_KEYS.has(key)) return false
  if (!eventKey.startsWith(PREFIX) || !eventKey.endsWith('/' + key)) return false
  const bucket = eventKey.slice(PREFIX.length, eventKey.length - key.length - 1)
  return bucket !== '' && !bucket.includes('/')
}

/** Drop a stored key entirely — the way out for anything a viewer recorded. */
export function removeJson(key: string): void {
  try {
    globalThis.localStorage?.removeItem(physical(key))
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
  fireErase(key)
}

/** Tell this tab's live holders that a key is gone (the disk sweep is theirs to skip). */
function fireErase(key: string): void {
  for (const listener of [...(eraseWatchers.get(key) ?? [])]) listener()
}

/**
 * Remove everything one principal left (CW-DBA-9): every scoped bucket
 * member — no inventory key carries a '/' unsuffixed, so a shape scan is an
 * exact membership test — plus the bare pre-namespacing copy of the principal
 * keys. Those names carried transcript text and tab state
 * since before any scope existed, so anything still under them belongs to a
 * previous principal by definition.
 */
function sweepPrincipalState(): void {
  try {
    const store = globalThis.localStorage
    if (!store) return
    for (let i = store.length - 1; i >= 0; i -= 1) {
      const name = store.key(i)
      if (name === null || !name.startsWith(PREFIX)) continue
      if (name.slice(PREFIX.length).includes('/')) store.removeItem(name)
    }
    for (const key of PRINCIPAL_KEYS) {
      store.removeItem(PREFIX + key)
    }
  } catch {
    // storage blocked: the scope is a memory-only luxury in that browser anyway
  }
}

/**
 * New principal (login): sweep the previous one's state, then mint a fresh
 * scope before this session's secret is even stored. The sweep is what makes
 * sign-out provably complete in the inverse direction — a sign-in never
 * inherits a bucket, even when the previous session ended without logout.
 */
export function rotateCacheScope(): void {
  sweepPrincipalState()
  try {
    globalThis.localStorage?.setItem(SCOPE_POINTER, mintScope())
  } catch {
    // storage blocked: stay on the page's ephemeral scope
  }
  ephemeralScope = null
  fireErase('drafts')
  fireErase('promptHistory')
  fireErase('tabs')
  fireErase('lastSession')
  fireErase('focused')
}

/**
 * Sign-out (OD-28): sweep the transcript caches and the tab-restore state,
 * then drop the pointer, so the next page load starts from nothing even
 * before any later login rotates a fresh scope. Preferences — theme,
 * notifications, diff style, layout mode, keymap, panel and transcript-view
 * settings, push consent — are untouched.
 */
export function dropCacheScope(): void {
  sweepPrincipalState()
  try {
    globalThis.localStorage?.removeItem(SCOPE_POINTER)
  } catch {
    // nothing to clear if storage is blocked
  }
  ephemeralScope = null
  fireErase('drafts')
  fireErase('promptHistory')
  fireErase('tabs')
  fireErase('lastSession')
  fireErase('focused')
}
