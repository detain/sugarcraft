import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  AuthError,
  LOGIN_SECRET_HEADER,
  canvasLink,
  clearLoginSecret,
  login,
  logout,
  storedLoginSecret,
  ticket,
} from '../auth'
import { loadPromptHistory } from '../../stores/promptHistory'
import { onKeyErased, readJson, storageKey, writeJson } from '../../stores/storage'

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

describe('canvasLink (C-TOOL-6.g web half)', () => {
  it('posts the pair to /api/canvas with the login secret and returns the minted url', async () => {
    const stored = responder(() => json(200, { ok: true, loginSecret: 'abc123' }))
    await login({ code: 'c0dec0dec0de' }, stored.fetchImpl)

    const { calls, fetchImpl } = responder((call) =>
      String(call.url).endsWith('/canvas') ? json(200, { url: '/canvas/' + 'f'.repeat(64), expiresInSeconds: 60 }) : json(200, { ok: true }),
    )
    expect(await canvasLink('s1', 'canvas-1', fetchImpl)).toBe('/canvas/' + 'f'.repeat(64))
    expect(calls[0]?.url).toMatch(/api\/canvas$/)
    expect(calls[0]?.headers[LOGIN_SECRET_HEADER]).toBe('abc123')
    expect(calls[0]?.body).toEqual({ sessionId: 's1', artifactId: 'canvas-1' })
  })

  it('maps the shared 404 refusal to an AuthError of the server kind', async () => {
    const { fetchImpl } = responder(() => json(404, { error: { kind: 'not_found', message: 'this canvas page does not exist, or its link has been used or has expired' } }))
    await expect(canvasLink('s1', 'gone', fetchImpl)).rejects.toSatisfy((f: unknown) => f instanceof AuthError && f.status === 404 && f.kind === 'not_found')
  })

  it('refuses an answer that carries no url', async () => {
    const { fetchImpl } = responder(() => json(200, { expiresInSeconds: 60 }))
    await expect(canvasLink('s1', 'a1', fetchImpl)).rejects.toSatisfy((f: unknown) => f instanceof AuthError && f.kind === 'bad_canvas_link')
  })
})

describe('sign-out sweeps the browser (WEB-6 C)', () => {
  // CW-DBA-9 (OD-28): the ring moved inside the per-login cache scope, so
  // these pins capture the name the wrapper resolves NOW rather than naming
  // it raw — the same physical key the logout sweep must delete.
  it('logout clears the prompt-history ring, even when the server call fails', async () => {
    writeJson('promptHistory', ['what the viewer typed'])
    const ringKey = storageKey('promptHistory')
    expect(localStorage.getItem(ringKey)).not.toBeNull()

    // The finally-branch is the whole point: an unreachable or angry server
    // must not leave the typed prompts behind either.
    const broken = responder(() => {
      throw new TypeError('unreachable')
    })
    await expect(logout(broken.fetchImpl)).rejects.toBeInstanceOf(AuthError)
    expect(localStorage.getItem(ringKey)).toBeNull()
  })

  it('a successful logout clears it too', async () => {
    writeJson('promptHistory', ['one more'])
    const ringKey = storageKey('promptHistory')
    const { fetchImpl } = responder(() => json(200, { ok: true }))
    await logout(fetchImpl)
    expect(localStorage.getItem(ringKey)).toBeNull()
  })

  it('logout clears the drafts map and the tab-restore state and keeps preferences (OD-28)', async () => {
    localStorage.clear()
    writeJson('drafts', { s1: 'typed into the composer' })
    writeJson('promptHistory', ['a sent prompt'])
    writeJson('tabs', ['s1'])
    writeJson('lastSession', 's1')
    writeJson('focused', 's1')
    writeJson('theme', 'dracula')
    writeJson('mode', 'grid')
    const draftsKey = storageKey('drafts')
    const ringKey = storageKey('promptHistory')
    const { fetchImpl } = responder(() => json(200, { ok: true }))
    await logout(fetchImpl)

    expect(localStorage.getItem(draftsKey)).toBeNull()
    expect(localStorage.getItem(ringKey)).toBeNull()
    // The sweep leaves no bucket member anywhere: no scoped name survives.
    for (let i = 0; i < localStorage.length; i += 1) {
      expect((localStorage.key(i) ?? '').slice('candy-code-web:'.length)).not.toContain('/')
    }
    // The bare tab-restore names carry no session id. An empty re-write from
    // a live watcher is tolerated; a remembered 's1' would be the leak.
    expect(localStorage.getItem('candy-code-web:tabs') ?? '[]').toBe('[]')
    expect(localStorage.getItem('candy-code-web:lastSession') ?? 'null').toBe('null')
    expect(localStorage.getItem('candy-code-web:focused') ?? 'null').toBe('null')
    // Preferences survive sign-out verbatim.
    expect(localStorage.getItem('candy-code-web:theme')).toBe('"dracula"')
    expect(localStorage.getItem('candy-code-web:mode')).toBe('"grid"')
  })

  it('the sweep reaches the bare pre-namespacing copies', async () => {
    localStorage.clear()
    localStorage.setItem('candy-code-web:drafts', '{"s1":"legacy typed"}')
    localStorage.setItem('candy-code-web:promptHistory', '["legacy sent"]')
    const { fetchImpl } = responder(() => json(200, { ok: true }))
    await logout(fetchImpl)
    for (const key of ['drafts', 'promptHistory']) {
      expect(localStorage.getItem(`candy-code-web:${key}`)).toBeNull()
    }
  })

  it('sign-out fires the in-tab erase bus for every swept holder key', async () => {
    localStorage.clear()
    const erased: string[] = []
    const offs = ['drafts', 'tabs', 'lastSession', 'focused'].map((key) =>
      onKeyErased(key, () => {
        erased.push(key)
      }),
    )
    const { fetchImpl } = responder(() => json(200, { ok: true }))
    await logout(fetchImpl)
    expect([...erased].sort()).toEqual(['drafts', 'focused', 'lastSession', 'tabs'])
    for (const off of offs) off()
  })

  it('logout closes every app card in the service workers notification inbox', async () => {
    // OWED-CLOSEOUT (ii) of L-DBA: queued OS notifications carry session
    // bodies and outlive the tab. The shapes below are exactly what sw.ts
    // mints (question/finished/generic, pinned by push.spec's worker half);
    // the 'mastodon:' card belongs to a different app sharing a
    // registration and must survive.
    localStorage.clear()
    const closed: string[] = []
    const card = (tag: string) => ({
      tag,
      close: async () => { closed.push(tag) },
    })
    const registrations = [
      { getNotifications: async () => [card('candy-code:question:s-42_x'), card('candy-code:generic'), card('mastodon:notify')] },
      { getNotifications: async () => [card('candy-code:finished')] },
    ]
    Object.defineProperty(navigator, 'serviceWorker', {
      configurable: true,
      value: { getRegistrations: async () => registrations },
    })
    try {
      const { fetchImpl } = responder(() => json(200, { ok: true }))
      await logout(fetchImpl)
      expect(closed.sort()).toEqual(['candy-code:finished', 'candy-code:generic', 'candy-code:question:s-42_x'])
    } finally {
      Reflect.deleteProperty(navigator, 'serviceWorker')
    }
  })

  it('a refusing getRegistrations never blocks the rest of sign-out', async () => {
    localStorage.clear()
    writeJson('promptHistory', ['typed before the push surface broke'])
    const ringKey = storageKey('promptHistory')
    const stored = responder(() => json(200, { ok: true, loginSecret: 'abc123' }))
    await login({ code: 'c0dec0dec0de' }, stored.fetchImpl)
    Object.defineProperty(navigator, 'serviceWorker', {
      configurable: true,
      value: { getRegistrations: async () => { throw new Error('push surface on fire') } },
    })
    try {
      const { fetchImpl } = responder(() => json(200, { ok: true }))
      await logout(fetchImpl) // resolves — the sweep is fail-soft, not a gate
      expect(storedLoginSecret()).toBeNull()
      expect(localStorage.getItem(ringKey)).toBeNull()
    } finally {
      Reflect.deleteProperty(navigator, 'serviceWorker')
    }
  })

  it('one broken registration cannot strand the sweep of another', async () => {
    // Per-registration fan-out (allSettled): a getNotifications that throws
    // in the first registration must not un-reach the second one's inbox.
    localStorage.clear()
    const closed: string[] = []
    Object.defineProperty(navigator, 'serviceWorker', {
      configurable: true,
      value: {
        getRegistrations: async () => [
          { getNotifications: async () => { throw new Error('this registration is a husk') } },
          { getNotifications: async () => [{ tag: 'candy-code:question:s-7', close: async () => { closed.push('survivor') } }] },
        ],
      },
    })
    try {
      const { fetchImpl } = responder(() => json(200, { ok: true }))
      await logout(fetchImpl)
      expect(closed).toEqual(['survivor'])
    } finally {
      Reflect.deleteProperty(navigator, 'serviceWorker')
    }
  })
})

/**
 * CW-DBA-9 (OD-28) namespacing: the transcript caches live under a scope
 * segment minted at login; the pointer itself lives inside storage.ts and is
 * named here only because spec files are outside the storage-inventory walk.
 */
describe('cache scope (CW-DBA-9 / OD-28 namespacing)', () => {
  const POINTER = 'candy-code-web:cacheScope'

  it('a cold boot with no pointer reads none of the leftover caches', () => {
    localStorage.clear()
    localStorage.setItem('candy-code-web:drafts', '{"s1":"waiting"}')
    localStorage.setItem('candy-code-web:promptHistory', '["waiting"]')
    expect(readJson<unknown>('drafts', null)).toBeNull()
    expect(loadPromptHistory()).toEqual([])
  })

  it('while a pointer is set, the transcript caches live under its bucket', () => {
    localStorage.clear()
    localStorage.setItem(POINTER, 'a0a0a0a0')
    writeJson('drafts', { s1: 'typed' })
    writeJson('promptHistory', ['sent'])
    expect(localStorage.getItem('candy-code-web:a0a0a0a0/drafts')).toBe('{"s1":"typed"}')
    expect(localStorage.getItem('candy-code-web:a0a0a0a0/promptHistory')).toBe('["sent"]')
    // Preferences keep the bare names — only the principal caches are scoped.
    expect(storageKey('theme')).toBe('candy-code-web:theme')
  })

  it('login sweeps the previous principal entirely and mints a fresh scope', async () => {
    localStorage.clear()
    localStorage.setItem(POINTER, 'a0a0a0a0')
    localStorage.setItem('candy-code-web:a0a0a0a0/drafts', '{"A":"typed"}')
    localStorage.setItem('candy-code-web:a0a0a0a0/promptHistory', '["sent"]')
    localStorage.setItem('candy-code-web:drafts', '{"Z":"legacy"}')
    localStorage.setItem('candy-code-web:tabs', '["A"]')
    localStorage.setItem('candy-code-web:lastSession', '"A"')
    localStorage.setItem('candy-code-web:focused', '"A"')
    localStorage.setItem('candy-code-web:theme', '"dracula"')
    localStorage.setItem('candy-code-web:keymap', '{"palette":42}')
    const { fetchImpl } = responder(() => json(200, { ok: true, loginSecret: 'sec' }))
    await login({ code: 'c0dec0dec0de' }, fetchImpl)

    expect(localStorage.getItem('candy-code-web:a0a0a0a0/drafts')).toBeNull()
    expect(localStorage.getItem('candy-code-web:a0a0a0a0/promptHistory')).toBeNull()
    expect(localStorage.getItem('candy-code-web:drafts')).toBeNull()
    expect(localStorage.getItem('candy-code-web:tabs') ?? '[]').toBe('[]')
    expect(localStorage.getItem('candy-code-web:lastSession') ?? 'null').toBe('null')
    expect(localStorage.getItem('candy-code-web:focused') ?? 'null').toBe('null')
    const pointer = localStorage.getItem(POINTER)
    expect(pointer).not.toBeNull()
    expect(pointer).not.toBe('a0a0a0a0')
    expect(localStorage.getItem('candy-code-web:theme')).toBe('"dracula"')
    expect(localStorage.getItem('candy-code-web:keymap')).toBe('{"palette":42}')
    // The fresh scope starts empty: the new principal inherits nothing.
    expect(readJson<unknown>('drafts', null)).toBeNull()
    expect(loadPromptHistory()).toEqual([])
  })

  it('logout drops the pointer so the next boot starts from nothing', async () => {
    localStorage.clear()
    const { fetchImpl } = responder(() => json(200, { ok: true, loginSecret: 'sec' }))
    await login({ code: 'c0dec0dec0de' }, fetchImpl)
    const pointer = localStorage.getItem(POINTER)
    expect(pointer).not.toBeNull()
    writeJson('drafts', { s1: 'typed' })
    expect(localStorage.getItem(`candy-code-web:${pointer}/drafts`)).toBe('{"s1":"typed"}')

    await logout(fetchImpl)
    expect(localStorage.getItem(POINTER)).toBeNull()
    expect(localStorage.getItem(`candy-code-web:${pointer}/drafts`)).toBeNull()
  })
})
