import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  AuthError,
  LOGIN_SECRET_HEADER,
  clearLoginSecret,
  login,
  logout,
  storedLoginSecret,
  ticket,
} from '../auth'

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

interface Call {
  url: string
  headers: Record<string, string>
  body: unknown
}

function responder(reply: (call: Call) => Response) {
  const calls: Call[] = []
  const fetchImpl = vi.fn(async (url: string, init?: RequestInit) => {
    const call: Call = {
      url,
      headers: (init?.headers ?? {}) as Record<string, string>,
      body: JSON.parse(String(init?.body ?? '{}')) as unknown,
    }
    calls.push(call)
    return reply(call)
  })
  return { calls, fetchImpl }
}

beforeEach(() => {
  clearLoginSecret()
})

describe('login secret (ISS-007 web half)', () => {
  it('stores the secret the login answer carries, in plain view of no other origin', async () => {
    const { fetchImpl } = responder(() => json(200, { ok: true, loginSecret: 'abc123' }))
    await login({ code: 'c0dec0dec0de' }, fetchImpl)
    expect(storedLoginSecret()).toBe('abc123')
  })

  it('sends the header on every cookie-authenticated POST once it is held', async () => {
    const { calls, fetchImpl } = responder((call) =>
      String(call.url).endsWith('/ticket') ? json(200, { ticket: 'tk', expiresInSeconds: 30 }) : json(200, { ok: true, loginSecret: 'abc123' }),
    )
    await login({ token: 't'.repeat(40) }, fetchImpl)
    expect(calls[0]?.headers[LOGIN_SECRET_HEADER]).toBeUndefined()

    expect(await ticket(fetchImpl)).toBe('tk')
    expect(calls[1]?.headers[LOGIN_SECRET_HEADER]).toBe('abc123')
  })

  it('clears the secret on a 401 so the retry is a clean sign-in', async () => {
    const stored = responder(() => json(200, { ok: true, loginSecret: 'abc123' }))
    await login({ code: 'c0dec0dec0de' }, stored.fetchImpl)
    expect(storedLoginSecret()).toBe('abc123')

    const refused = responder(() => json(401, { error: { kind: 'unauthorized', message: 'sign in' } }))
    await expect(ticket(refused.fetchImpl)).rejects.toSatisfy((f: unknown) => f instanceof AuthError && f.signedOut)
    expect(storedLoginSecret()).toBeNull()
  })

  it('keeps working when the server answers no secret (older server)', async () => {
    const { calls, fetchImpl } = responder((call) =>
      String(call.url).endsWith('/ticket') ? json(200, { ticket: 'tk', expiresInSeconds: 30 }) : json(200, { ok: true }),
    )
    await login({ code: 'c0dec0dec0de' }, fetchImpl)
    expect(await ticket(fetchImpl)).toBe('tk')
    expect(calls[1]?.headers[LOGIN_SECRET_HEADER]).toBeUndefined()
  })

  it('clears the secret on sign-out, even when the call itself fails', async () => {
    const stored = responder(() => json(200, { ok: true, loginSecret: 'abc123' }))
    await login({ code: 'c0dec0dec0de' }, stored.fetchImpl)

    const broken = responder(() => json(500, { error: { kind: 'boom', message: 'boom' } }))
    await expect(logout(broken.fetchImpl)).rejects.toBeInstanceOf(AuthError)
    expect(storedLoginSecret()).toBeNull()
  })
})
