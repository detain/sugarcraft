/**
 * The browser half of the sign-in (SERVER.md "Authentication"): the one-time
 * code from the sign-in URL's fragment — or the owner token, pasted — is
 * traded at `POST /api/login` for an HttpOnly session cookie; before every
 * WebSocket connect, `POST /api/ticket` trades the cookie for a single-use
 * ticket valid 30 s. The page never holds the token after the login call and
 * never stores anything: the cookie is the only credential, and the browser
 * keeps it where script cannot read it.
 *
 * URLs resolve against the document's base, so the UI also works mounted
 * under a path behind a reverse proxy.
 */
export class AuthError extends Error {
  readonly status: number
  readonly kind: string

  constructor(status: number, kind: string, message: string) {
    super(message)
    this.name = 'AuthError'
    this.status = status
    this.kind = kind
  }

  /** The credential is missing, wrong or signed out: the user must sign in. */
  get signedOut(): boolean {
    return this.status === 401
  }
}

export type Fetch = (input: string, init?: RequestInit) => Promise<Response>

function base(): string {
  return typeof document !== 'undefined' ? document.baseURI : 'http://127.0.0.1/'
}

export function apiUrl(path: string, baseUrl: string = base()): string {
  return new URL(`api/${path}`, baseUrl).toString()
}

export function wsUrl(ticket: string, baseUrl: string = base()): string {
  const url = new URL('ws', baseUrl)
  url.protocol = url.protocol === 'https:' ? 'wss:' : 'ws:'
  url.search = `?ticket=${encodeURIComponent(ticket)}`
  url.hash = ''
  return url.toString()
}

async function post(fetchImpl: Fetch, path: string, body: unknown): Promise<Record<string, unknown>> {
  let response: Response
  try {
    response = await fetchImpl(apiUrl(path), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
  } catch {
    throw new AuthError(0, 'unreachable', 'the server cannot be reached')
  }

  let json: Record<string, unknown> = {}
  try {
    json = (await response.json()) as Record<string, unknown>
  } catch {
    // a non-JSON answer: judged on its status alone
  }
  if (!response.ok) {
    const error = (json.error ?? {}) as { kind?: string; message?: string }
    throw new AuthError(response.status, error.kind ?? 'http_error', error.message ?? `HTTP ${response.status}`)
  }
  return json
}

/** Trade a one-time code (or the owner token) for the session cookie. */
export async function login(credential: { code: string } | { token: string }, fetchImpl: Fetch = fetch): Promise<void> {
  await post(fetchImpl, 'login', credential)
}

/** A single-use WebSocket ticket for the cookie session. */
export async function ticket(fetchImpl: Fetch = fetch): Promise<string> {
  const json = await post(fetchImpl, 'ticket', {})
  if (typeof json.ticket !== 'string' || json.ticket === '') {
    throw new AuthError(500, 'bad_ticket', 'the server answered no ticket')
  }
  return json.ticket
}

export async function logout(fetchImpl: Fetch = fetch): Promise<void> {
  await post(fetchImpl, 'logout', {})
}

/**
 * The one-time code in a sign-in URL's fragment (`#code=<hex>`), or null.
 * Read before the hash router sees the fragment, which it would otherwise
 * take for a route.
 */
export function codeFromHash(hash: string): string | null {
  const match = /^#\/?code=([A-Za-z0-9_-]{8,256})$/.exec(hash)
  return match ? (match[1] ?? null) : null
}
