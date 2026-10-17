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

describe('server.overflow resubscribes (ISS-052)', () => {
  it('resubscribes the named session from its cursor', async () => {
    await connect()
    await followLive('s1')
    await followLive('s2')
    const before = sent('session.subscribe').length

    event(null, 'server.overflow', { dropped: 41, action: 'resubscribe', sessionId: 's1' })
    connectionFlush()

    const after = sent('session.subscribe').slice(before)
    expect(after).toEqual([expect.objectContaining({ params: { sessionId: 's1', afterSeq: 7 } })])
  })

  it('resubscribes every followed session when no session is named', async () => {
    await connect()
    await followLive('s1')
    await followLive('s2')
    const before = sent('session.subscribe').length

    event(null, 'server.overflow', { dropped: 2, action: 'resubscribe' })
    connectionFlush()

    const after = sent('session.subscribe').slice(before)
    expect(after.map((f) => f.params)).toEqual([
      { sessionId: 's1', afterSeq: 7 },
      { sessionId: 's2', afterSeq: 7 },
    ])
  })

  it('the resubscribed session lands its replay and stays live', async () => {
    await connect()
    await followLive('s1')

    event(null, 'server.overflow', { dropped: 1, action: 'resubscribe', sessionId: 's1' })
    connectionFlush()
    seq = 7
    answer('session.subscribe', { fromSeq: 8, throughSeq: 8, pendingAsks: [] })
    await flush()
    event('s1', 'message.created', { messageId: 'm8', role: 'user', kind: 'user', content: 'after the drop' }, true)
    connectionFlush()

    const session = useSessionStore('s1')
    expect(session.phase).toBe('live')
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
