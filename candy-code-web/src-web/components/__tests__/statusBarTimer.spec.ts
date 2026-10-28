import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import StatusBar from '../StatusBar.vue'
import { summary as sessionSummary, Wire } from '../grid/__tests__/harness'
import type { EventEnvelope, SessionSnapshot, SessionSummary } from '../../protocol/generated'
import { applyEvent, applySnapshot, createState } from '../../stores/reducer'

/**
 * C-WEB-4.d (GAP-UI-31): the status bar's elapsed turn timer. Fake timers
 * pin the exact contract — the 1 s interval exists ONLY while the session
 * is busy with an observed start (armed on `turn.started`, cleared on
 * `turn.completed` and on unmount), the read-out reads `working · 0:42`
 * (with `· step n/m` when the turn names its budget), and a snapshot-resumed
 * mid-turn session shows the plain `working` with no interval armed —
 * hidden, not guessed.
 *
 * The live-interval count is tracked by spying setInterval/clearInterval and
 * following only 1000 ms handles: the connection store arms its own
 * housekeeping timers behind `Wire`, so a global `vi.getTimerCount()` would
 * measure the wrong thing (and drift while promises settle).
 */

// An epoch the fake clock starts at; turn.started stamps reuse it.
const T0 = 1_700_000_000_000
const TICK_MS = 1000

let wire: Wire
let tickHandles: number[]

/** Wrap the (already-faked) interval pair and follow only 1 s handles. */
function trackTickIntervals(): void {
  tickHandles = []
  const live: number[] = tickHandles
  const originalSet = globalThis.setInterval
  const originalClear = globalThis.clearInterval
  vi.spyOn(globalThis, 'setInterval').mockImplementation(((handler: TimerHandler, ms?: number, ...args: unknown[]) => {
    const id = originalSet(handler, ms, ...args) as unknown as number
    if (ms === TICK_MS) live.push(Number(id))
    return id
  }) as typeof setInterval)
  vi.spyOn(globalThis, 'clearInterval').mockImplementation(((handle?: number | ReturnType<typeof setInterval> | string) => {
    const at = live.indexOf(Number(handle))
    if (at >= 0) live.splice(at, 1)
    return originalClear(handle as never)
  }) as typeof clearInterval)
}

const liveTicks = () => tickHandles.length

async function mountBar() {
  // Connect the socket first (real timers), then freeze the clock and start
  // tracking: everything that arms a 1 s interval from here is the component.
  wire = await Wire.connect()
  vi.useFakeTimers({ now: T0, toFake: ['setTimeout', 'setInterval', 'Date'] })
  trackTickIntervals()
  const state = reactive(createState('s1'))
  const wrapper = mount(StatusBar, {
    props: {
      state,
      summary: sessionSummary('s1') as unknown as SessionSummary,
      contextPct: null,
      connection: 'live',
    },
  })
  await flushPromises()
  wire.answer('server.info', infoResult)
  await flushPromises()
  return { wrapper, state }
}

const infoResult = {
  version: '1.2.3',
  protocol: 1,
  root: '/repo',
  providers: [],
  agents: [],
  tools: [],
  commands: ['help'],
  permissionModes: ['default'],
  defaultPermissionMode: 'default',
}

function statusOf(wrapper: ReturnType<typeof mount>): string {
  return wrapper.find('.item.state').text()
}

function tick(wrapper: { vm: { $nextTick: () => Promise<void> } }): Promise<void> {
  return wrapper.vm.$nextTick()
}

function ev(type: string, data: Record<string, unknown>, ts: number): EventEnvelope {
  return { sessionId: 's1', seq: 1, type, ts, durable: true, turnId: 't1', data }
}

beforeEach(() => {
  setActivePinia(createPinia())
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('StatusBar elapsed turn timer (C-WEB-4.d)', { retry: 0 }, () => {
  it('arms no tick interval while idle', async () => {
    const { wrapper } = await mountBar()
    expect(statusOf(wrapper)).toBe('idle')
    expect(liveTicks()).toBe(0)
  })

  it('arms the tick on turn.started, shows 0:00, and counts up each second', async () => {
    const { wrapper, state } = await mountBar()

    applyEvent(state, ev('turn.started', { turnId: 't1' }, T0))
    await tick(wrapper)
    expect(liveTicks()).toBe(1)
    expect(statusOf(wrapper)).toBe('working · 0:00')

    vi.advanceTimersByTime(42_000)
    await tick(wrapper)
    expect(statusOf(wrapper)).toBe('working · 0:42')

    vi.advanceTimersByTime(23_000)
    await tick(wrapper)
    expect(statusOf(wrapper)).toBe('working · 1:05')
  })

  it('keeps the step read-out alongside the elapsed timer', async () => {
    const { wrapper, state } = await mountBar()
    applyEvent(state, ev('turn.started', { turnId: 't1' }, T0))
    applyEvent(state, ev('turn.step', { step: 3, maxSteps: 20 }, T0 + 1_000))
    await tick(wrapper)
    vi.advanceTimersByTime(42_000)
    await tick(wrapper)
    expect(statusOf(wrapper)).toBe('working · 0:42 · step 3/20')
  })

  it('stops ticking on turn.completed and the text never moves again', async () => {
    const { wrapper, state } = await mountBar()

    applyEvent(state, ev('turn.started', { turnId: 't1' }, T0))
    await tick(wrapper)
    vi.advanceTimersByTime(5_000)
    await tick(wrapper)
    expect(statusOf(wrapper)).toBe('working · 0:05')

    applyEvent(state, ev('turn.completed', { stopReason: 'end_turn' }, T0 + 5_000))
    await tick(wrapper)
    expect(liveTicks()).toBe(0)
    // Wire reality (as in reducer.spec): the status itself drops on the
    // following `session.status` frame. Between the two the bar reads plain
    // `working` — the timer cleared with the turn even while busy shows.
    expect(statusOf(wrapper)).toBe('working')
    applyEvent(state, ev('session.status', { status: 'idle' }, T0 + 5_500))
    await tick(wrapper)
    expect(statusOf(wrapper)).toBe('idle')

    vi.advanceTimersByTime(120_000)
    await tick(wrapper)
    expect(statusOf(wrapper)).toBe('idle')
  })

  it('clears the interval on unmount while still busy', async () => {
    const { wrapper, state } = await mountBar()
    applyEvent(state, ev('turn.started', { turnId: 't1' }, T0))
    await tick(wrapper)
    expect(liveTicks()).toBe(1)

    wrapper.unmount()
    expect(liveTicks()).toBe(0)
  })

  it('a mid-turn snapshot resume shows plain working, no interval', async () => {
    const { wrapper, state } = await mountBar()

    // The reload shape: the snapshot says busy, but no turn.started was
    // ever observed — there is nothing honest to count from.
    const snapshot: SessionSnapshot = {
      sessionId: 's1', status: 'busy', lastSeq: 0, pendingAsks: [], queue: [],
      messages: [], spentUsd: 0, turnId: 't7',
    }
    applySnapshot(state, snapshot)
    await tick(wrapper)
    expect(statusOf(wrapper)).toBe('working')
    expect(liveTicks()).toBe(0)

    vi.advanceTimersByTime(60_000)
    await tick(wrapper)
    // 'working' persists with no ticking without a start (was a toBe message
    // argument; toBe takes one — the intent lives here instead).
    expect(statusOf(wrapper)).toBe('working')
  })

  it('clamps a server clock ahead of ours to 0:00 instead of going negative', async () => {
    const { wrapper, state } = await mountBar()
    // Envelope ts in the future: skew must not print '-0:03'.
    applyEvent(state, ev('turn.started', { turnId: 't1' }, T0 + 3_000))
    await tick(wrapper)
    expect(statusOf(wrapper)).toBe('working · 0:00')
    vi.advanceTimersByTime(2_000)
    await tick(wrapper)
    expect(statusOf(wrapper)).toBe('working · 0:00')
    vi.advanceTimersByTime(2_000)
    await tick(wrapper)
    expect(statusOf(wrapper)).toBe('working · 0:01')
  })
})
