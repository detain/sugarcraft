/**
 * Per-viewer conveniences in localStorage — drafts, layout, theme. Every
 * access is guarded: storage can be absent, full or blocked (private mode,
 * cleared site data), and the UI must work the same without it. Never a
 * credential: the session cookie is HttpOnly and the token is never kept.
 */
const PREFIX = 'sugar-crush-web:'

export function readJson<T>(key: string, fallback: T): T {
  try {
    const raw = globalThis.localStorage?.getItem(PREFIX + key)
    return raw === null || raw === undefined ? fallback : (JSON.parse(raw) as T)
  } catch {
    return fallback
  }
}

export function writeJson(key: string, value: unknown): void {
  try {
    globalThis.localStorage?.setItem(PREFIX + key, JSON.stringify(value))
  } catch {
    // storage full or blocked: a convenience lost, nothing more
  }
}
