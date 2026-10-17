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

/**
 * ISS-058, the client paging consumer. The server lane has not landed its
 * schema yet, so every pin here is about the defensive shape: the client asks
 * nothing of a server that never mentions paging, adopts the cursor when it
 * does, dedupes a shifted boundary, refuses to loop, and turns a refusal into
 * `olderError` instead of a broken follow.
 */
describe('session paging (ISS-058)', () => {
  function pagedSnapshot(snapshotExtras: Record<string, unknown>, messages: unknown[] = [{ id: 'm1', role: 'user', content: 'one' }]): Record<string, unknown> {
    return {
      reset: true,
      throughSeq: 3,
      pendingAsks: [],
      snapshot: { sessionId: 's1', status: 'idle', lastSeq: 3, pendingAsks: [], queue: [], messages, ...snapshotExtras },
    }
  }

  async function subscribePaged(snapshotExtras: Record<string, unknown> = {}, messages?: unknown[]) {
    await connect()
    const session = useSessionStore('s1')
    const subscribed = session.subscribe()
    answer('session.subscribe', pagedSnapshot(snapshotExtras, messages))
    await subscribed
    return session
  }

  it('asks nothing of a server that never mentions paging', async () => {
    const session = await subscribePaged()
    expect(session.state.hasMoreOlder).toBe(false)
    expect(session.state.olderCursor).toBeNull()
    await expect(session.loadOlder()).resolves.toBe(false)
    expect(sent('session.history')).toHaveLength(0)
  })

  it('adopts the cursor a paged snapshot names', async () => {
    const session = await subscribePaged({ hasMoreOlder: true, olderCursor: 40 })
    expect(session.state.hasMoreOlder).toBe(true)
    expect(session.state.olderCursor).toBe(40)
  })

  it('reads the olderCursor spelling the server fix names as beforeIndex', async () => {
    const session = await subscribePaged({ hasMore: true, beforeIndex: 12 })
    expect(session.state.hasMoreOlder).toBe(true)
    expect(session.state.olderCursor).toBe(12)
  })

  it('sends the held cursor and prepends the page it answers', async () => {
    const session = await subscribePaged({ hasMoreOlder: true, olderCursor: 40 })
    const loading = session.loadOlder()
    expect(sent('session.history').pop()?.params).toEqual({ sessionId: 's1', beforeIndex: 40, limit: 200 })
    answer('session.history', {
      sessionId: 's1', status: 'idle', lastSeq: 3, pendingAsks: [], queue: [],
      messages: [{ id: 'm0', role: 'user', content: 'older' }],
      hasMoreOlder: true, olderCursor: 20,
    })
    await expect(loading).resolves.toBe(true)
    expect(session.state.items.map((item) => item.key)).toEqual(['m0', 'm1'])
    expect(session.state.olderCursor).toBe(20)
    expect(session.state.hasMoreOlder).toBe(true)
    expect(session.olderError).toBeNull()
  })

  it('skips a row the boundary shift already showed the client', async () => {
    const session = await subscribePaged({ hasMoreOlder: true, olderCursor: 40 })
    const loading = session.loadOlder()
    answer('session.history', {
      sessionId: 's1', status: 'idle', lastSeq: 3, pendingAsks: [], queue: [],
      messages: [{ id: 'm0', role: 'user', content: 'older' }, { id: 'm1', role: 'user', content: 'one' }],
    })
    await loading
    expect(session.state.items.map((item) => item.key)).toEqual(['m0', 'm1'])
    // A page that names no further cursor ends the walk.
    expect(session.state.hasMoreOlder).toBe(false)
    expect(session.state.olderCursor).toBeNull()
  })

  it('refuses to loop on a stuck page that adds nothing', async () => {
    const session = await subscribePaged({ hasMoreOlder: true, olderCursor: 40 })
    const loading = session.loadOlder()
    answer('session.history', {
      sessionId: 's1', status: 'idle', lastSeq: 3, pendingAsks: [], queue: [],
      messages: [{ id: 'm1', role: 'user', content: 'one' }],
      hasMoreOlder: true, olderCursor: 40,
    })
    await loading
    expect(session.state.hasMoreOlder).toBe(false)
    await expect(session.loadOlder()).resolves.toBe(false)
    expect(sent('session.history')).toHaveLength(1)
  })

  it('lands a refusal in olderError and leaves the walk where it was', async () => {
    const session = await subscribePaged({ hasMoreOlder: true, olderCursor: 40 })
    const loading = session.loadOlder()
    const frame = sent('session.history').pop()
    socket().fail(-32601, 'method_not_found', 'unknown method', frame?.id)
    await expect(loading).resolves.toBe(false)
    expect(session.olderError).toBe('unknown method')
    expect(session.state.hasMoreOlder).toBe(true)
    expect(session.state.olderCursor).toBe(40)
    expect(session.state.items.map((item) => item.key)).toEqual(['m1'])
  })
})
