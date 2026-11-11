import { describe, expect, it } from 'vitest'
import { FakeSocket, FakeTimers, flush, helloResult } from '../../__tests__/fakes'
import { AuthError } from '../auth'
import { CandyCodeClient } from '../client'
import type { EventEnvelope } from '../generated'
import { ProtocolError } from '../jsonrpc'
import { Backoff } from '../reconnect'

function harness(options: { resume?: () => Record<string, number>; connectUrl?: () => Promise<string> } = {}) {
  const sockets: FakeSocket[] = []
  const timers = new FakeTimers()
  const client = new CandyCodeClient({
    connectUrl: options.connectUrl ?? (async () => `ws://x/ws?ticket=t${sockets.length + 1}`),
    socket: (url, protocols) => {
      const socket = new FakeSocket(url, protocols)
      sockets.push(socket)
      return socket
    },
    timers,
    backoff: new Backoff({ random: () => 0.5 }),
    resume: options.resume,
  })
  const socket = (): FakeSocket => {
    const last = sockets[sockets.length - 1]
    if (!last) throw new Error('no socket')
    return last
  }
  /** Open the current socket and complete its handshake. */
  const handshake = (overrides: Record<string, unknown> = {}): void => {
    socket().open()
    socket().answer(helloResult(overrides), 'hello')
  }
  return { client, sockets, timers, socket, handshake }
}

describe('CandyCodeClient', () => {
  it('offers the candy-code.v1 subprotocol and opens with server.hello carrying the resume cursors', async () => {
    const { client, socket } = harness({ resume: () => ({ s1: 42 }) })
    client.start()
    await flush()
    expect(socket().protocols).toEqual(['candy-code.v1'])
    expect(socket().url).toBe('ws://x/ws?ticket=t1')
    socket().open()
    const hello = socket().last()
    expect(hello.method).toBe('server.hello')
    expect(hello.params).toMatchObject({ minProtocol: 1, maxProtocol: 1, resume: { s1: 42 } })
    expect(client.status).toBe('connecting')
    socket().answer(helloResult(), 'hello')
    expect(client.status).toBe('connected')
    expect(client.hello?.server.connectionId).toBe('c1')
  })

  it('correlates answers by id and rejects with the error kind', async () => {
    const { client, socket, handshake } = harness()
    client.start()
    await flush()
    handshake()

    const list = client.request('session.list', {})
    const listId = socket().last().id
    const get = client.request('session.get', { sessionId: 'nope' })
    const getId = socket().last().id
    expect(listId).not.toBe(getId)

    socket().fail(-32004, 'session_not_found', 'no such session', getId)
    socket().answer({ items: [], nextCursor: null }, listId)

    await expect(list).resolves.toEqual({ items: [], nextCursor: null })
    await expect(get).rejects.toMatchObject({ kind: 'session_not_found', code: -32004 })
  })

  it('adds an idempotencyKey to side-effecting methods only', async () => {
    const { client, socket, handshake } = harness()
    client.start()
    await flush()
    handshake()
    void client.request('session.send', { sessionId: 's', text: 'hi' })
    expect((socket().last().params as Record<string, unknown>).idempotencyKey).toMatch(/^[0-9a-f]{32}$/)
    void client.request('session.list', {})
    expect(socket().last().params).not.toHaveProperty('idempotencyKey')
  })

  it('fans out event notifications', async () => {
    const { client, socket, handshake } = harness()
    const seen: EventEnvelope[] = []
    client.onEvent((event) => seen.push(event))
    client.start()
    await flush()
    handshake()
    socket().event({ sessionId: 's', seq: 1, type: 'session.status', ts: 1, durable: true, data: { status: 'busy' } })
    expect(seen).toHaveLength(1)
    expect(seen[0]?.type).toBe('session.status')
  })

  it('reconnects on the backoff with a fresh ticket, refusing reads in flight and resending writes once with the same key', async () => {
    const { client, sockets, socket, timers, handshake } = harness()
    client.start()
    await flush()
    handshake()

    const read = client.request('session.list', {})
    const write = client.request('session.send', { sessionId: 's', text: 'hi' })
    const key = (socket().last().params as Record<string, unknown>).idempotencyKey

    socket().drop(1006)
    await expect(read).rejects.toBeInstanceOf(ProtocolError)
    expect(client.status).toBe('reconnecting')
    expect(client.retryAt).toBe(timers.now() + 500)

    timers.advance(499)
    expect(sockets).toHaveLength(1)
    timers.advance(1)
    await flush()
    expect(sockets).toHaveLength(2)
    expect(socket().url).toBe('ws://x/ws?ticket=t2')

    handshake()
    const resent = socket().last()
    expect(resent.method).toBe('session.send')
    expect((resent.params as Record<string, unknown>).idempotencyKey).toBe(key)
    socket().answer({ admitted: 'started', turnId: 't1' })
    await expect(write).resolves.toMatchObject({ admitted: 'started' })
  })

  it('gives up a resent write after a second drop', async () => {
    const { client, socket, timers, handshake } = harness()
    client.start()
    await flush()
    handshake()
    const write = client.request('session.send', { sessionId: 's', text: 'hi' })
    socket().drop()
    timers.advance(500)
    await flush()
    handshake()
    socket().drop()
    await expect(write).rejects.toMatchObject({ kind: 'disconnected' })
  })

  it('is signed out, without retrying, when the ticket is refused', async () => {
    const { client, timers } = harness({
      connectUrl: async () => {
        throw new AuthError(401, 'unauthorized', 'no session')
      },
    })
    client.start()
    await flush()
    expect(client.status).toBe('signed-out')
    expect(timers.pending).toBe(0)
  })

  it('is signed out when the server revokes the session (close 4001)', async () => {
    const { client, socket, timers, handshake } = harness()
    client.start()
    await flush()
    handshake()
    socket().drop(4001, 'signed out')
    expect(client.status).toBe('signed-out')
    expect(timers.pending).toBe(0)
  })

  it('closes a socket that stays silent for two tick intervals, then reconnects', async () => {
    const { client, socket, timers, handshake } = harness()
    client.start()
    await flush()
    handshake({ limits: { tickIntervalMs: 1000 } })
    timers.advance(1999)
    expect(socket().closedWith).toBeNull()
    socket().event({ sessionId: null, type: 'server.tick', ts: 1, durable: false, data: { now: 1, turnsRunning: 0 } })
    timers.advance(1999)
    expect(socket().closedWith).toBeNull()
    timers.advance(2)
    expect(socket().closedWith?.code).toBe(4000)
    expect(client.status).toBe('reconnecting')
  })

  it('notices a server that changed version across a reconnect', async () => {
    const { client, socket, timers, handshake } = harness()
    client.start()
    await flush()
    handshake()
    expect(client.serverChanged).toBe(false)
    socket().drop()
    timers.advance(500)
    await flush()
    handshake({ server: { version: '1.0.1', connectionId: 'c2' } })
    expect(client.serverChanged).toBe(true)
  })

  it('stops for good on an unsupported protocol', async () => {
    const { client, socket } = harness()
    client.start()
    await flush()
    socket().open()
    socket().fail(-32600, 'unsupported_protocol', 'protocol 2 only', 'hello')
    expect(client.status).toBe('stopped')
    expect(client.lastError).toBe('protocol 2 only')
  })

  it('spends the ladder, names the dead server, and stops retrying (ISS-052)', async () => {
    const { client, sockets, socket, timers, handshake } = harness()
    client.start()
    await flush()
    handshake()

    const write = client.request('session.send', { sessionId: 's', text: 'hi' })
    socket().drop()
    // Six waits go by — 0.5, 1, 2, 4, 8, 15 s — and every attempt dies before its handshake.
    for (const delay of [500, 1_000, 2_000, 4_000, 8_000, 15_000]) {
      timers.advance(delay)
      await flush()
      expect(client.status).toBe('reconnecting')
      socket().drop()
    }
    expect(client.status).toBe('stopped')
    expect(client.exhausted).toBe(true)
    expect(client.lastError).toBe('the server did not answer after 6 tries')
    expect(timers.pending).toBe(0)
    // A verdict, not a wait: nothing is held for a reconnect that will not come.
    await expect(write).rejects.toMatchObject({ kind: 'disconnected' })

    // The banner's way back spends a fresh ladder.
    client.start()
    await flush()
    expect(sockets).toHaveLength(8)
    expect(client.exhausted).toBe(false)
    expect(client.status).toBe('reconnecting')
    socket().drop()
    expect(client.retryAt).toBe(timers.now() + 500)
  })

  it('refuses a read while disconnected but holds a write for the connection', async () => {
    const { client, socket, handshake } = harness()
    await expect(client.request('session.list', {})).rejects.toMatchObject({ kind: 'disconnected' })
    client.start()
    const write = client.request('session.create', {})
    await flush()
    handshake()
    expect(socket().last().method).toBe('session.create')
    socket().answer({ id: 'new', open: true, status: 'idle' })
    await expect(write).resolves.toMatchObject({ id: 'new' })
  })
})

/**
 * CW-1 (reviews-1103): helloAnswered fired the hello listeners BEFORE
 * resending the waiting queue — one throwing listener stranded every
 * queued idempotent request until the next reconnect, and silenced the
 * later listeners too. The queue is drained first and each listener is
 * isolated.
 */
describe('hello listener isolation (CW-1)', () => {
  it('a throwing hello listener neither strands queued requests nor later listeners', async () => {
    const { client, socket } = harness()
    client.start()
    await flush()
    socket().open()
    // A queue-while-connecting request: issued after open, before the hello
    // answer. session.send is the idempotent side-effecting method, so the
    // client parks it in `waiting` (session.list is a read — it would be
    // refused outright while connecting).
    void client.request('session.send', { sessionId: 's1', text: 'hi' }).catch(() => {})
    expect(socket().sent.filter((frame) => frame.method === 'session.send')).toHaveLength(0)

    client.onHello(() => {
      throw new Error('listener exploded')
    })
    let later = 0
    client.onHello(() => {
      later += 1
    })

    socket().answer(helloResult(), 'hello')
    await flush()

    expect(later).toBe(1)
    expect(socket().sent.filter((frame) => frame.method === 'session.send')).toHaveLength(1)
  })
})

describe('malformed hello (CW-3)', () => {
  it('treats a malformed hello answer as a protocol error instead of a crash', async () => {
    const { client, socket } = harness()
    client.start()
    await flush()
    socket().open()
    // CW-3 at base: the hello answer was cast without a shape check, so a
    // server (or proxy) that answers with server: null derefences a TypeError
    // inside the response dispatch — right here — instead of being answered
    // as a protocol error.
    expect(() => socket().answer(helloResult({ server: null }), 'hello')).not.toThrow()
    await flush()
    expect(socket().closedWith?.code).toBe(4000)
    expect(socket().closedWith?.reason).toBe('hello malformed')
    // The synchronous onclose names the close as the visible error — the
    // point is the malformed hello never reached a deref.
    expect(client.lastError).toContain('hello malformed')
    expect(client.status).toBe('reconnecting')
    client.stop()
  })

  it('rejects a hello missing the principal scopes the connection store dereferences', async () => {
    const { client, socket } = harness()
    client.start()
    await flush()
    socket().open()
    expect(() => socket().answer(helloResult({ principal: { kind: 'owner' } }), 'hello')).not.toThrow()
    await flush()
    expect(socket().closedWith?.code).toBe(4000)
    expect(client.status).toBe('reconnecting')
    client.stop()
  })

  it('still accepts a well-formed hello', async () => {
    const { client, handshake } = harness()
    client.start()
    await flush()
    handshake()
    expect(client.status).toBe('connected')
    client.stop()
  })

  // M-19 agreement family: the guard's presence-only check on `resumed` is a
  // deliberate contract with the connection store (absent map = nothing resumed,
  // every follower heals via a fresh resubscribe — pinned end-to-end in
  // stores/__tests__/helloSpineIsolation.spec.ts), not an oversight. These two
  // pins lock both halves so the layers cannot silently disagree again.
  it('accepts a hello that omits the resumed map entirely (M-19)', async () => {
    const { client, socket } = harness()
    client.start()
    await flush()
    socket().open()
    let announced = 0
    client.onHello(() => {
      announced += 1
    })
    // JSON round-trip physically drops the key: a true wire-shape hello without it.
    const frame = JSON.parse(JSON.stringify({ ...helloResult(), resumed: undefined }))
    expect(() => socket().answer(frame, 'hello')).not.toThrow()
    await flush()
    expect(client.status).toBe('connected')
    expect(announced).toBe(1)
    expect(socket().closedWith).toBeNull()
    expect(client.lastError).toBeNull()
    client.stop()
  })

  it('closes a hello whose resumed map is present but not an object (M-19)', async () => {
    const { client, socket } = harness()
    client.start()
    await flush()
    socket().open()
    expect(() => socket().answer(helloResult({ resumed: null }), 'hello')).not.toThrow()
    await flush()
    expect(socket().closedWith?.code).toBe(4000)
    expect(socket().closedWith?.reason).toBe('hello malformed')
    expect(client.lastError).toContain('hello malformed')
    client.stop()
  })
})
