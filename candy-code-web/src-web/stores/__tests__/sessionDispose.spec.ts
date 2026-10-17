import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { FakeSocket, FakeTimers, flush, helloResult } from '../../__tests__/fakes'
import { Backoff } from '../../protocol/reconnect'
import { useConnectionStore } from '../connection'
import { useLayoutStore } from '../layout'
import { RELEASE_GRACE_MS, liveSessionStoreCount, useSessionStore } from '../session'

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

async function connect(): Promise<void> {
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
  snapshot: { sessionId: 's1', status: 'idle', lastSeq: 7, pendingAsks: [], queue: [], messages: [{ id: 'm1', role: 'user', content: 'remember me' }] },
} as const

/**
 * The release grace uses the ambient `setTimeout` (a store-internal alarm,
 * not the connection's injected clock), so the test arms a manual queue for
 * exactly the calls under test and fires them by hand.
 */
function manualAlarms(): { fire: () => void; count: () => number } {
  const originals = { set: globalThis.setTimeout, clear: globalThis.clearTimeout }
  const armed = new Map<number, () => void>()
  let next = 1
  globalThis.setTimeout = ((fn: () => void) => {
    const id = next++
    armed.set(id, fn)
    return id
  }) as unknown as typeof setTimeout
  globalThis.clearTimeout = ((id: number) => {
    armed.delete(id)
  }) as unknown as typeof clearTimeout
  return {
    fire() {
      const fns = [...armed.values()]
      armed.clear()
      globalThis.setTimeout = originals.set
      globalThis.clearTimeout = originals.clear
      for (const fn of fns) fn()
    },
    count: () => armed.size,
  }
}

async function visit(): Promise<ReturnType<typeof useSessionStore>> {
  const session = useSessionStore('s1')
  session.retain()
  await flush()
  answer('session.subscribe', SNAPSHOT)
  await flush()
  return session
}

describe('released session stores are disposed (ISS-112)', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    localStorage.clear()
    sockets = []
    timers = new FakeTimers()
    await connect()
  })
  it('the grace alarm with no open tab unfollows AND frees the store', async () => {
    const session = await visit()
    expect(session.state.items.length).toBeGreaterThan(0)
    expect(liveSessionStoreCount()).toBe(1)

    const alarms = manualAlarms()
    session.release()
    expect(alarms.count()).toBe(1)
    expect(RELEASE_GRACE_MS).toBeGreaterThan(0)
    alarms.fire()
    await flush()

    expect(sent('session.unsubscribe').length).toBe(1)
    expect(liveSessionStoreCount()).toBe(0)
  })

  it('a session still open as a tab keeps its store when the grace fires', async () => {
    useLayoutStore().openTab('s1')
    const session = await visit()
    const alarms = manualAlarms()
    session.release()
    alarms.fire()
    await flush()

    expect(sent('session.unsubscribe').length).toBe(1)
    expect(liveSessionStoreCount()).toBe(1)
    expect(useSessionStore('s1').state.items.length).toBeGreaterThan(0)
  })

  it('a second visit after disposal is a fresh follow, not a zombie cache', async () => {
    const first = await visit()
    const alarms = manualAlarms()
    first.release()
    alarms.fire()
    await flush()

    const again = useSessionStore('s1')
    expect(again).not.toBe(first)
    expect(again.state.items.length).toBe(0)
    expect(liveSessionStoreCount()).toBe(1)

    const pending = again.subscribe()
    // A brand-new store has no cursor, so the server answers with a full
    // snapshot — the reset-carrying shape, not a replay.
    answer('session.subscribe', { ...SNAPSHOT, throughSeq: 9, snapshot: { ...SNAPSHOT.snapshot, lastSeq: 9 } })
    await pending
    await flush()
    expect(again.state.items.length).toBeGreaterThan(0)
    expect(again.phase).toBe('live')
  })

  it('a re-visit inside the grace window cancels the pending alarm', async () => {
    const session = await visit()
    const alarms = manualAlarms()
    session.release()
    session.retain()
    expect(alarms.count()).toBe(0)
    session.release()
    expect(alarms.count()).toBe(1)
    alarms.fire()
    await flush()
    expect(sent('session.unsubscribe').length).toBe(1)
    expect(liveSessionStoreCount()).toBe(0)
  })
})
