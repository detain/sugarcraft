/**
 * Local structural types for the viewer-token RPC family — the G5-G seam.
 *
 * SEAM: the token.grant / token.list / token.revoke defs are specced in
 * /tmp/opencode/reviews-1103/design/X-DESIGN1.md §D-2 and realized server-side
 * by TokenMethods (G4-G + addendum, rv1103/g4ga). This branch's
 * src-web/protocol/generated.ts predates that weld chain, so the token.*
 * names are absent from its generated maps here; the panel types the family
 * from this file and routes calls through tokenRpc(), which casts around the
 * generated MethodName union.
 *
 * Now that the G4-G chain (with its addendum) carries the realized handlers
 * and the regenerated generated.ts (weld order law: G4-E → G4-G/addendum →
 * G5-G), the swap is mechanical and the welder's option: delete tokenRpc()
 * and this file's Params/Result aliases, call
 * `connection.request('token.list', {})` directly — the generated maps name
 * the same shapes — and the panel needs no other change. If a shape here
 * ever disagrees with the server's TokenMethods answer, the server wins;
 * fix this file to match, never the server. Two realized-shape laws this
 * file previously drifted from and now pins (G4-G-ADDENDUM review MAJOR-1):
 *  - `createdAt`/`expiresAt` are ISO-8601 strings (`date(ATOM)` of the
 *    store's float), NOT epoch numbers;
 *  - a token.list row OMITS the `sessionIds` key entirely when the grant is
 *    unpinned — the wire never carries null there.
 *
 * Copy note: the panel's sentences are inline English rostered in
 * src-web/__tests__/rawCopyCensus.spec.ts, with the catalogue keys filed as a
 * LANG REQUEST (lane report ## LANG REQUEST) — the i18n window for
 * candy-code/lang/*.php is welded last per program law, and t() against a key
 * the generated catalogue does not carry yet would render the raw key. The
 * G5-B 'Import' rider (448f8dd1b) set exactly this pattern.
 */

/** The three Admin-scope token methods, exactly as §D-2 registers them. */
export type TokenRpcName = 'token.grant' | 'token.list' | 'token.revoke'

/**
 * One stored grant as token.list returns it. The hash and the raw token are
 * NEVER present in list rows — ViewerTokens::list() strips the hash at the
 * store boundary (G4-G), so nothing here can render a re-displayable secret.
 */
export interface TokenGrantRow {
  readonly id: string
  readonly label: string
  readonly scopes: string[]
  /** Absent = the grant covers every session (the server omits the key
   * rather than answering null); a present [] would cover none. */
  readonly sessionIds?: string[]
  /** ISO-8601 (`date(ATOM)` of the store's float) — the server's stamp(). */
  readonly createdAt: string
  /** ISO-8601 — absolute, never slid by use. */
  readonly expiresAt: string
}

export interface TokenListResult {
  readonly tokens: TokenGrantRow[]
}

export interface TokenGrantParams {
  readonly label: string
  /** Read-only v1 per the owner ruling (X-DESIGN1 §7 item 2): always ['read']. */
  readonly scopes: ['read']
  /** Lifetime in days, 1..365; the server defaults to 30 when omitted. */
  readonly days?: number
  readonly sessionIds?: string[]
}

/** The grant answer — the ONLY moment the raw `cv1_` token crosses the wire. */
export interface TokenGrantResult {
  readonly id: string
  readonly token: string
  readonly label: string
  readonly scopes: string[]
  /** ISO-8601 stamps — same `date(ATOM)` shape as the list rows. */
  readonly createdAt: string
  readonly expiresAt: string
}

export interface TokenRevokeParams {
  readonly id: string
}

export interface TokenRevokeResult {
  readonly revoked: true
}

type ParamsOf<N extends TokenRpcName> = N extends 'token.grant'
  ? TokenGrantParams
  : N extends 'token.list'
    ? Record<string, never>
    : TokenRevokeParams

/**
 * SEAM (G4-E): a cast-typed escape hatch around stores/connection's
 * `request(method)` — its method parameter is the generated MethodName union,
 * which does not carry token.* until the regen. The runtime path is the real
 * connection (same socket, same ProtocolError envelope, same idempotency
 * behaviour); only the types need loosening. Delete with this file's types
 * once the generated maps name the family.
 */
export interface TokenRpcCaller {
  request(...args: never[]): Promise<unknown>
}

export function tokenRpc<R>(
  connection: TokenRpcCaller,
  method: TokenRpcName,
  params: ParamsOf<TokenRpcName>,
): Promise<R> {
  const call = connection.request as unknown as (m: TokenRpcName, p: ParamsOf<TokenRpcName>) => Promise<R>
  return call(method, params)
}
