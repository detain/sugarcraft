import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { FakeSocket, FakeTimers, flush, helloResult } from '../../__tests__/fakes'
import { Backoff } from '../../protocol/reconnect'
import { useConnectionStore } from '../connection'
import { useSessionStore } from '../session'

let sockets: FakeSocket[]
let timers: FakeTimers

function socket(): FakeSocket {
  const last = sockets[sockets.length - 1]
  if (!last) throw new Error('no socket')
  return last
}

function sent(method: string): Record<string, unknown>[] {
  return socket().sent.filter((frame) => frame.method === method)
}

async function connect(): Promise<ReturnType<typeof useConnectionStore>> {
  const connection = useConnectionStore()
  connection.configure(
    {
      connectUrl: async () => 'ws://x/ws?ticket=t',
      socket: (url, protocols) => {
        const created = new FakeSocket(url, protocols)
        sockets.push(created)
        return created
      },
      timers,
      backoff: new Backoff({ random: () => 0.5 }),
    },
    (fn) => fn(),
  )
  connection.start()
  await flush()
  socket().open()
  socket().answer(helloResult(), 'hello')
  return connection
}

function answer(method: string, result: unknown): void {
  const frame = sent(method).pop()
  if (!frame) throw new Error(`no ${method} sent`)
  socket().answer(result, frame.id)
}

const SNAPSHOT = {
  reset: true,
  throughSeq: 7,
  pendingAsks: [],
  snapshot: { sessionId: 'x', status: 'idle', lastSeq: 7, pendingAsks: [], queue: [], messages: [] },
} as const

let seq = 0
function event(sessionId: string | null, type: string, data: Record<string, unknown>, durable = false): void {
  socket().event({ sessionId, ...(durable ? { seq: ++seq } : {}), type, ts: 1, durable, data })
}

/** Subscribe a session to `throughSeq` and park it live. */
async function followLive(sessionId: string): Promise<void> {
  const session = useSessionStore(sessionId)
  const subscribed = session.subscribe()
  answer('session.subscribe', { ...SNAPSHOT, throughSeq: 7, snapshot: { ...SNAPSHOT.snapshot, sessionId } })
  await subscribed
}

beforeEach(() => {
  setActivePinia(createPinia())
  sockets = []
  timers = new FakeTimers()
  seq = 0
})

describe('server.overflow resubscribes (ISS-052, G4-C client contract)', () => {
  it('resubscribes the named session with a fresh full snapshot, scoped to it', async () => {
    await connect()
    await followLive('s1')
    await followLive('s2')
    const before = sent('session.subscribe').length

    event(null, 'server.overflow', { dropped: 41, action: 'resubscribe', sessionId: 's1' })
    connectionFlush()

    // G4-C contract: the only repair is a NEW subscription — the request
    // carries no afterSeq, so the server answers reset:true with a full
    // snapshot. A replay cannot heal the dropped ephemerals, and s2 (not
    // named) must stay untouched: the array is exactly one frame.
    const after = sent('session.subscribe').slice(before)
    expect(after).toHaveLength(1)
    expect(after[0]?.params).toEqual({ sessionId: 's1' })
  })

  it('resubscribes every followed session when no session is named', async () => {
    await connect()
    await followLive('s1')
    await followLive('s2')
    const before = sent('session.subscribe').length

    event(null, 'server.overflow', { dropped: 2, action: 'resubscribe' })
    connectionFlush()

    const after = sent('session.subscribe').slice(before)
    expect(after.map((f) => f.params)).toEqual([{ sessionId: 's1' }, { sessionId: 's2' }])
  })

  it('ignores an overflow for a session this client does not follow', async () => {
    await connect()
    await followLive('s1')
    const before = sent('session.subscribe').length

    event(null, 'server.overflow', { dropped: 9, action: 'resubscribe', sessionId: 'other' })
    connectionFlush()

    expect(sent('session.subscribe')).toHaveLength(before)
  })

  it('does nothing once the named session has unfollowed', async () => {
    await connect()
    await followLive('s1')
    const session = useSessionStore('s1')
    session.unsubscribe()
    const before = sent('session.subscribe').length

    event(null, 'server.overflow', { dropped: 4, action: 'resubscribe', sessionId: 's1' })
    connectionFlush()

    expect(sent('session.subscribe')).toHaveLength(before)
    expect(session.stale).toBe(false)
  })

  it('marks stale mid-subscribe: a server-side drop never leaves a dead pane', async () => {
    await connect()
    const session = useSessionStore('s1')
    const subscribed = session.subscribe()
    event(null, 'server.overflow', { dropped: 5, action: 'resubscribe', sessionId: 's1' })
    connectionFlush()

    expect(session.stale).toBe(true)
    const frames = sent('session.subscribe')
    expect(frames).toHaveLength(2)
    expect(frames[1]?.params).toEqual({ sessionId: 's1' })

    answer('session.subscribe', { ...SNAPSHOT, snapshot: { ...SNAPSHOT.snapshot, sessionId: 's1' } })
    await flush()
    expect(session.phase).toBe('live')
    expect(session.stale).toBe(false)
    // The superseded in-flight request finally answers too — the second
    // snapshot lands on an already-live session without injury.
    const first = sent('session.subscribe')[0]
    if (first) socket().answer({ ...SNAPSHOT, throughSeq: 8, snapshot: { ...SNAPSHOT.snapshot, sessionId: 's1', lastSeq: 8 } }, first.id)
    await subscribed
    expect(session.phase).toBe('live')
    expect(session.stale).toBe(false)
  })

  it('the fresh snapshot replaces the transcript, the drop notice survives it', async () => {
    await connect()
    await followLive('s1')
    const session = useSessionStore('s1')
    seq = 7
    event('s1', 'message.created', { messageId: 'm8', role: 'user', kind: 'user', content: 'held row' }, true)
    connectionFlush()
    expect(session.state.items.some((item) => item.kind === 'user' && item.content === 'held row')).toBe(true)

    event(null, 'server.overflow', { dropped: 3, action: 'resubscribe', sessionId: 's1' })
    connectionFlush()
    expect(session.state.items.some((item) => item.kind === 'notice' && item.level === 'warn' && String(item.content).includes('3'))).toBe(true)

    answer('session.subscribe', { ...SNAPSHOT, throughSeq: 9, snapshot: { ...SNAPSHOT.snapshot, sessionId: 's1', lastSeq: 9, messages: [] } })
    await flush()

    expect(session.phase).toBe('live')
    expect(session.stale).toBe(false)
    // Replaced: the held row is gone…
    expect(session.state.items.some((item) => item.kind === 'user')).toBe(false)
    // …but the audit line records what fell behind (re-appended after the swap).
    expect(session.state.items.some((item) => item.kind === 'notice' && item.level === 'warn' && String(item.content).includes('3'))).toBe(true)

    seq = 9
    event('s1', 'message.created', { messageId: 'm10', role: 'user', kind: 'user', content: 'after the drop' }, true)
    connectionFlush()
    expect(session.state.items.some((item) => item.kind === 'user' && item.content === 'after the drop')).toBe(true)
  })
})

describe('server.shutdown surfaces (ISS-052)', () => {
  it('exposes the reason and grace, and clears on the next handshake', async () => {
    const connection = await connect()
    expect(connection.shutdown).toBeNull()

    event(null, 'server.shutdown', { reason: 'restart', graceSeconds: 10 })
    connectionFlush()
    expect(connection.shutdown).toEqual({ reason: 'restart', graceSeconds: 10 })

    socket().drop()
    timers.advance(500)
    await flush()
    socket().open()
    socket().answer(helloResult(), 'hello')
    expect(connection.shutdown).toBeNull()
  })
})

/** Let the batched scheduler (identity in these tests) run the queued events. */
function connectionFlush(): void {
  useConnectionStore().flushNow()
}
