import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createPinia, getActivePinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { FakeSocket, FakeTimers, flush, helloResult } from '../../__tests__/fakes'
import { Backoff } from '../../protocol/reconnect'
import { useClientToolsStore } from '../clientTools'
import { useConnectionStore } from '../connection'
import { liveSessionStoreCount, useSessionStore } from '../session'

/**
 * CW-DBA-6, CW-DBA-13, CW-7 and CW-5 (reviews-1103 G5-D): the session
 * store's subscription lifecycle, output-walk honesty, client-tool detach,
 * and the pinia-internal cast in disposal. Harness is the plain
 * FakeSocket/Backoff one every session spec already speaks.
 */

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
  const store = useConnectionStore()
  store.configure(
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
  store.start()
  await flush()
  socket().open()
  socket().answer(helloResult(), 'hello')
  await flush()
}

function answer(method: string, result: unknown): void {
  const frame = sent(method).pop()
  if (!frame) throw new Error(`no ${method} sent`)
  socket().answer(result, frame.id)
}

let seq = 7
function event(type: string, data: Record<string, unknown>): void {
  socket().event({ sessionId: 's1', seq: ++seq, type, ts: 1, durable: true, turnId: 't1', data })
}

const SNAPSHOT = {
  reset: true,
  throughSeq: 7,
  pendingAsks: [],
  snapshot: { sessionId: 's1', status: 'idle', lastSeq: 7, pendingAsks: [], queue: [], messages: [] },
} as const

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
  sockets = []
  timers = new FakeTimers()
  seq = 7
})

describe('subscribe dedupes an in-flight request (CW-7)', () => {
  it('a second subscribe while one is in flight sends nothing new', async () => {
    await connect()
    const session = useSessionStore('s1')

    const first = session.subscribe()
    const second = session.subscribe()
    await flush()

    // Base re-entered: two session.subscribe frames are in flight.
    expect(sent('session.subscribe')).toHaveLength(1)

    answer('session.subscribe', SNAPSHOT)
    await Promise.all([first, second])
    expect(session.phase).toBe('live')
  })
})

describe('unsubscribe detaches the tab client tools (CW-DBA-13)', () => {
  it('dropping the follow drops the registration the same session attached', async () => {
    await connect()
    const clientTools = useClientToolsStore()
    clientTools.implement({ name: 'probe', description: 'a probe', inputSchema: {}, run: () => 'pong' })
    const session = useSessionStore('s1')

    const subscribing = session.subscribe()
    answer('session.subscribe', SNAPSHOT)
    await subscribing
    // The post-subscribe attach announced the local tools and registered.
    await flush()
    expect(sent('tools.client.register')).toHaveLength(1)
    answer('tools.client.register', { registrationId: 'r-1' })
    await flush()
    expect(clientTools.registrations.get('s1')).toBe('r-1')

    const dropping = session.unsubscribe()
    answer('session.unsubscribe', { ok: true })
    await dropping
    // Base leaked: the registration outlived the follow it was announced on.
    expect(clientTools.registrations.has('s1')).toBe(false)
  })
})

describe('the full-output walk keeps its truncation truth (CW-DBA-6)', () => {
  async function truncatedTool(): Promise<ReturnType<typeof useSessionStore>> {
    await connect()
    const session = useSessionStore('s1')
    const subscribing = session.subscribe()
    answer('session.subscribe', SNAPSHOT)
    await subscribing
    event('tool.started', { toolCallId: 'c9', name: 'Bash', arguments: {} })
    event('tool.finished', { toolCallId: 'c9', name: 'Bash', content: 'head...', isError: false, truncated: true })
    await flush()
    return session
  }

  it('keeps truncated when the 64-page budget runs out with more still to read', async () => {
    const session = await truncatedTool()
    const item = session.state.items.find((candidate) => candidate.kind === 'tool')
    expect(item?.kind).toBe('tool')
    if (item?.kind !== 'tool') throw new Error('no tool item')

    const walk = session.loadFullOutput(item)
    for (let page = 0; page < 64; page++) {
      const params = sent('tool.output').at(-1)?.params as { offset: number }
      answer('tool.output', { content: 'x', offset: params.offset, more: true })
      await flush()
    }
    await walk

    expect(item.content).toHaveLength(64)
    // Base flipped it false anyway: the budget died, not the output.
    expect(item.truncated).toBe(true)
  })

  it('clears truncated when a walk really reaches the end', async () => {
    const session = await truncatedTool()
    const item = session.state.items.find((candidate) => candidate.kind === 'tool')
    if (item?.kind !== 'tool') throw new Error('no tool item')

    const walk = session.loadFullOutput(item)
    answer('tool.output', { content: 'the whole tail', offset: 0, more: false })
    await walk

    expect(item.content).toBe('the whole tail')
    expect(item.truncated).toBe(false)
  })
})

describe('disposal reaches pinia through the public path (CW-5)', () => {
  /**
   * manualAlarms: the release grace is an ambient setTimeout — the exact
   * shape sessionDispose.spec.ts already runs, copied for the same reason.
   */
  function manualAlarms(): { fire: () => void } {
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
    }
  }

  it('the store source no longer reaches into the private pinia registry', () => {
    const source = readFileSync(resolve(process.cwd(), 'src-web/stores/session.ts'), 'utf8')
    expect(source).not.toContain('as unknown as { _s:')
    expect(source).toContain('store?.$dispose()')
  })

  it('grace disposal still frees the instance, the state entry, and the registry', async () => {
    await connect()
    const session = useSessionStore('s1')
    session.retain()
    await flush()
    answer('session.subscribe', SNAPSHOT)
    await flush()
    expect(liveSessionStoreCount()).toBe(1)

    // flush() rides the real setTimeout, so the manual queue is armed only
    // for the release itself — the sessionDispose.spec order, copied.
    const alarms = manualAlarms()
    session.release()
    alarms.fire()
    await flush()

    expect(liveSessionStoreCount()).toBe(0)
    const pinia = getActivePinia()
    expect(pinia).toBeDefined()
    if (pinia === undefined) throw new Error('no pinia')
    // The public $dispose() path must have removed BOTH registries — the
    // instance map and the state entry (the cast used to do the latter's
    // work, and $dispose alone leaves state.value holding the transcript).
    expect(pinia.state.value['session/s1']).toBeUndefined()
    const registry = (pinia as unknown as { _s: Map<string, unknown> })._s
    expect(registry.has('session/s1')).toBe(false)
  })
})

/**
 * CW-1 session-store half (reviews-1103): cursor.offer() removes the whole
 * contiguous run it hands back — an applyEvent throwing mid-run used to
 * silently drop its already-dequeued siblings (and escape up through the
 * connection's flush, see eventSpineIsolation.spec.ts). The store now
 * applies the run event-by-event in isolation and, on a poison event,
 * re-follows FRESH: a full snapshot is the honest recovery, not a partial
 * transcript with holes.
 */
describe('a poison durable event re-follows fresh (CW-1)', () => {
  it('the crashing batchmate triggers a fresh subscribe, not silent loss', async () => {
    await connect()
    const session = useSessionStore('s1')
    const first = session.subscribe()
    answer('session.subscribe', SNAPSHOT)
    await first

    // Poison, two-stage: a narration whose tail is a number slips into
    // state.live (utf8Length is numeric-safe); the NEXT freezeLive (the
    // tool.started arm) calls live.text.trim() and throws. Real untyped
    // wire shapes, and no reducer guard covers either site.
    event('assistant.narration', { partId: 'p1', tail: 42, offset: 0 })
    event('tool.started', { toolCallId: 'c9', name: 'Bash', arguments: {} })
    await flush()

    // Base: the throw escaped handle()/flush entirely (no fresh subscribe,
    // the error surfaced at the socket). Fixed: a fresh follow was asked for.
    const subscribeFrames = sent('session.subscribe')
    expect(subscribeFrames.length).toBe(2)
    expect(subscribeFrames[1]?.params).toEqual({ sessionId: 's1' })

    answer('session.subscribe', { ...SNAPSHOT, throughSeq: 8 })
    await flush()
    expect(session.phase).toBe('live')
  })
})

/**
 * CW-QUAL-A MINOR1 (m28): spend is authoritative in the snapshot — the
 * transcript walk, spentUsd and every billed turn already in it. The
 * turn-cost carrier (state.turnUsage) is live-turn state: before this fix it
 * survived the RESET boundary, so a turn.completed replayed out of `early[]`
 * folded the same cost a SECOND time onto the snapshot's own spentUsd. The
 * badge over-displayed briefly until the next usage frame. Two shields now,
 * both pinned: the snapshot clears the carrier, and the fold consumes it —
 * a late or duplicate turn.completed can never re-add.
 */
describe('a RESET replay never re-folds the old turn carrier (CW-QUAL-A MINOR1)', () => {
  it('a turn.completed held across a reset subscribe does not double-count', async () => {
    await connect()
    const session = useSessionStore('s1')
    const first = session.subscribe()
    answer('session.subscribe', SNAPSHOT)
    await first

    event('usage.updated', { turnUsage: { totalTokens: 10, costUsd: 0.5 } })
    event('turn.completed', { stopReason: 'end_turn' })
    await flush()
    expect(session.state.spentUsd).toBe(0.5)

    // Force a fresh (reset) subscribe and strand a turn.completed in the
    // held queue: it arrives while phase is 'subscribing'.
    const second = session.subscribe(true, true)
    await flush()
    event('turn.completed', { stopReason: 'end_turn' })
    answer('session.subscribe', {
      reset: true,
      throughSeq: 9,
      pendingAsks: [],
      snapshot: {
        sessionId: 's1',
        status: 'idle',
        lastSeq: 9,
        pendingAsks: [],
        queue: [],
        messages: [],
        spentUsd: 0.5,
      },
    })
    await second
    await flush()

    // Base: the replayed fold re-added the surviving carrier → 1.0.
    // Fixed: the snapshot cleared it → the snapshot's 0.5 stands.
    expect(session.state.spentUsd).toBe(0.5)
  })

  it('a duplicate turn.completed on a live feed cannot re-add the cost', async () => {
    await connect()
    const session = useSessionStore('s1')
    const first = session.subscribe()
    answer('session.subscribe', SNAPSHOT)
    await first

    event('usage.updated', { turnUsage: { totalTokens: 10, costUsd: 0.5 } })
    event('turn.completed', { stopReason: 'end_turn' })
    event('turn.completed', { stopReason: 'end_turn' })
    await flush()

    // Base: the second fold saw the same un-consumed carrier → 1.0.
    // Fixed: the fold consumes the carrier (mirroring the null-at-turn-open
    // in turn.started) → 0.5.
    expect(session.state.spentUsd).toBe(0.5)
  })
})

/**
 * B-75 (n48): retain-while-disconnected used to bump holders and rely on
 * every mounted .vue watch (SessionView, SessionTile) to subscribe when the
 * link came back — call-site discipline, not store authority. A session
 * store with holders but no view-side watch (or a watch ordering gap) sat
 * 'idle' forever. The store now owns the recovery itself: the same rule the
 * views watch, in the store's setup, guarded by holders so a released store
 * never reconnects behind its grace timer. Watching `connected` (not phase)
 * keeps a failed subscribe from retry-looping — the catch path lands on
 * phase 'idle' with connected unchanged.
 */
describe('a retain while disconnected reconnects from the store (B-75)', () => {
  it('the held store subscribes on its own when the link comes up', async () => {
    const session = useSessionStore('s1')
    session.retain()
    await flush()
    // Nothing exists to speak on yet: the disconnected retain only holds.
    expect(sockets).toHaveLength(0)

    await connect()
    await flush()

    // Base: zero frames — only a mounted view's watch could revive it.
    // Fixed: the store's own connection watch fires the subscribe.
    expect(sent('session.subscribe')).toHaveLength(1)

    answer('session.subscribe', SNAPSHOT)
    await flush()
    expect(session.phase).toBe('live')
  })
})
