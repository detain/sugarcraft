import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { FakeSocket, FakeTimers, flush, helloResult } from '../../__tests__/fakes'
import { Backoff } from '../../protocol/reconnect'
import { useApprovalsStore } from '../approvals'
import { useConnectionStore } from '../connection'
import { useSessionStore } from '../session'
import { useSessionsStore } from '../sessions'

let sockets: FakeSocket[]
let timers: FakeTimers

function socket(): FakeSocket {
  const last = sockets[sockets.length - 1]
  if (!last) throw new Error('no socket')
  return last
}

/** The frames sent with $method, newest last. */
function sent(method: string): Record<string, unknown>[] {
  return socket().sent.filter((frame) => frame.method === method)
}

async function connect(): Promise<ReturnType<typeof useConnectionStore>> {
  const connection = useConnectionStore()
  connection.configure({
    connectUrl: async () => 'ws://x/ws?ticket=t',
    socket: (url, protocols) => {
      const created = new FakeSocket(url, protocols)
      sockets.push(created)
      return created
    },
    timers,
    backoff: new Backoff({ random: () => 0.5 }),
  }, (fn) => fn())
  useSessionsStore()
  useApprovalsStore()
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

let seq = 0
function event(type: string, data: Record<string, unknown>, durable = true): void {
  socket().event({ sessionId: 's1', ...(durable ? { seq: ++seq } : {}), type, ts: 1, durable, turnId: 't1', data })
}

beforeEach(() => {
  setActivePinia(createPinia())
  sockets = []
  timers = new FakeTimers()
  seq = 0
})

describe('session store', () => {
  it('subscribes from a snapshot, then applies the live events in seq order', async () => {
    const connection = await connect()
    expect(connection.status).toBe('connected')
    const session = useSessionStore('s1')
    const subscribed = session.subscribe()
    expect(sent('session.subscribe').pop()?.params).toEqual({ sessionId: 's1' })

    // An event that races ahead of the subscribe answer is held, then applied.
    seq = 3
    event('message.created', { messageId: 'm4', role: 'user', kind: 'user', content: 'late' })
    answer('session.subscribe', {
      reset: true,
      throughSeq: 3,
      pendingAsks: [],
      snapshot: { sessionId: 's1', status: 'idle', lastSeq: 3, pendingAsks: [], queue: [], messages: [{ role: 'user', content: 'first' }] },
    })
    await subscribed
    expect(session.phase).toBe('live')
    expect(session.state.items.map((i) => i.kind === 'user' && i.content)).toEqual(['first', 'late'])

    // A replayed duplicate is dropped by the cursor.
    seq = 3
    event('message.created', { messageId: 'm4', role: 'user', kind: 'user', content: 'late' })
    expect(session.state.items).toHaveLength(2)
  })

  it('resumes from its cursor after a reconnect', async () => {
    await connect()
    const session = useSessionStore('s1')
    const subscribed = session.subscribe()
    answer('session.subscribe', {
      reset: true, throughSeq: 7, pendingAsks: [],
      snapshot: { sessionId: 's1', status: 'idle', lastSeq: 7, pendingAsks: [], queue: [], messages: [] },
    })
    await subscribed

    socket().drop()
    timers.advance(500)
    await flush()
    socket().open()
    const hello = socket().last()
    expect((hello.params as Record<string, unknown>).resume).toEqual({ s1: 7 })
    socket().answer(helloResult({ resumed: { s1: { fromSeq: 8, throughSeq: 8, pendingAsks: [] } } }), 'hello')
    seq = 7
    event('message.created', { messageId: 'm8', role: 'user', kind: 'user', content: 'missed' })
    expect(session.state.items).toEqual([expect.objectContaining({ content: 'missed' })])
  })

  it('routes a server-side built-in through command.exec and refuses a terminal-only one', async () => {
    await connect()
    const session = useSessionStore('s1')
    session.commands = [
      { name: 'clear', source: 'builtin', runsIn: 'server' },
      { name: 'theme', source: 'builtin', runsIn: 'client' },
      { name: 'review', source: 'file', runsIn: 'server' },
    ]
    void session.send('/clear')
    expect(sent('command.exec').pop()?.params).toMatchObject({ sessionId: 's1', name: 'clear' })
    expect(sent('command.exec').pop()?.params).not.toHaveProperty('args')
    await expect(session.send('/theme dark')).rejects.toMatchObject({ kind: 'ui_only' })
    void session.send('/review src/')
    expect(sent('session.send').pop()?.params).toMatchObject({ text: '/review src/' })
    void session.send('hello')
    expect(sent('session.send').pop()?.params).toMatchObject({ sessionId: 's1', text: 'hello' })
  })

  it('sends with a delivery only while a turn runs', async () => {
    await connect()
    const session = useSessionStore('s1')
    session.state.status = 'busy'
    void session.send('more', 'steer')
    expect(sent('session.send').pop()?.params).toMatchObject({ text: 'more', delivery: 'steer' })
  })

  it('closes a question another client answered first', async () => {
    await connect()
    const session = useSessionStore('s1')
    session.state.asks = [{ askId: 'a1', toolCallId: 'c', tool: 'Bash', arguments: {}, options: ['once'] }]
    const answering = session.respond('a1', 'once')
    const frame = sent('permission.respond').pop()
    socket().fail(-32009, 'already_resolved', 'already answered', frame?.id)
    await answering
    expect(session.state.asks).toEqual([])
  })
})

describe('sessions and approvals stores', () => {
  it('loads the index on the handshake and follows server-scope events', async () => {
    await connect()
    const sessions = useSessionsStore()
    answer('session.list', { items: [{ id: 'a', open: false, status: 'closed', updatedAt: '2026-01-01 00:00:00' }], nextCursor: null })
    await flush()
    expect(sessions.items.map((s) => s.id)).toEqual(['a'])
    socket().event({ sessionId: null, type: 'session.created', ts: 1, durable: false, data: { id: 'b', open: true, status: 'idle', updatedAt: '2026-02-01 00:00:00' } })
    expect(sessions.items.map((s) => s.id)).toEqual(['b', 'a'])
    socket().event({ sessionId: null, type: 'session.deleted', ts: 1, durable: false, data: { id: 'a' } })
    expect(sessions.items.map((s) => s.id)).toEqual(['b'])
  })

  it('counts open questions across sessions', async () => {
    await connect()
    const approvals = useApprovalsStore()
    answer('permission.pending', { items: [{ askId: 'x', sessionId: 'other', toolCallId: 'c', tool: 'Bash', arguments: {}, options: ['once'] }] })
    await flush()
    expect(approvals.countFor('other')).toBe(1)
    socket().event({ sessionId: 's1', seq: 1, type: 'permission.requested', ts: 1, durable: true, data: { askId: 'y', toolCallId: 'c', tool: 'Write', arguments: {}, options: ['once'] } })
    expect(approvals.total).toBe(2)
    socket().event({ sessionId: 's1', seq: 2, type: 'permission.resolved', ts: 1, durable: true, data: { askId: 'y', reply: 'once' } })
    expect(approvals.total).toBe(1)
  })
})
