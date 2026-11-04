import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useAgentsStore } from '../agents'
import { normalizeRun, type AgentRun } from '../tree'
import {
  CHARS_PER_TOKEN,
  STALL_MIN_TOKENS_PER_SECOND,
  STALL_THRESHOLD_SECONDS,
  isStalled,
  observeStall,
  stallSeconds,
} from '../stall'

/**
 * CWP-03 — the web port of the TUI's StallDetector (PTV-P01 parity). These
 * pins hold the client arithmetic to the server's semantics: the 30s window,
 * the 0.5 tok/s floor, reset-on-good-beat, accrue-on-slow-beat, the
 * first-sight baseline, the clock-skew keep-state, and the never-flag rule
 * for runs that are not live. The char proxy (tail chars at ~3 per token)
 * is the one disclosed deviation: a browser never sees billed token deltas,
 * only the beats' running totals and bounded tail.
 */

function beat(overrides: Record<string, unknown> = {}): AgentRun {
  const r = normalizeRun({ id: 'a1', name: 'reviewer', op: 'progress', seq: 1, tail: '', tokens: 0, stats: { startedAt: 1 }, ...overrides })
  if (r === null) throw new Error('not a run')
  return r
}

const T0 = 1_700_000_000_000

describe('stall detector parity constants (CWP-03)', () => {
  it('mirrors the TUI StallDetector window, floor, and the char proxy', () => {
    expect(STALL_THRESHOLD_SECONDS).toBe(30)
    expect(STALL_MIN_TOKENS_PER_SECOND).toBe(0.5)
    expect(CHARS_PER_TOKEN).toBe(3)
  })
})

describe('observeStall', () => {
  it('records a first-sight baseline and never flags it immediately', () => {
    const track = observeStall(undefined, beat(), T0)
    expect(track).toBeDefined()
    expect(track?.slowSeconds).toBe(0)
    expect(isStalled(track, beat(), T0)).toBe(false)
    expect(isStalled(track, beat(), T0 + STALL_THRESHOLD_SECONDS * 1000 - 1)).toBe(false)
  })

  it('accrues silence against the wall clock and flags at the threshold', () => {
    const track = observeStall(undefined, beat(), T0)
    expect(isStalled(track, beat(), T0 + 30_000)).toBe(true)
    expect(stallSeconds(track, beat(), T0 + 45_000)).toBe(45)
  })

  it('a beat at or above the token floor resets the slow accumulation', () => {
    const first = observeStall(undefined, beat({ seq: 1, tokens: 0 }), T0)
    // 20s below floor, then a beat that moved 200 tokens in the next 5s:
    const slow = observeStall(first, beat({ seq: 2, tokens: 0 }), T0 + 20_000)
    expect(slow?.slowSeconds).toBe(20)
    const good = observeStall(slow, beat({ seq: 3, tokens: 200 }), T0 + 25_000)
    expect(good?.slowSeconds).toBe(0)
    expect(isStalled(good, beat({ seq: 3, tokens: 200 }), T0 + 25_000)).toBe(false)
  })

  it('accrues slow time across below-floor beats, exactly like the server', () => {
    // One token per 10s is 0.1 tok/s < 0.5: each window counts toward the stall.
    let track = observeStall(undefined, beat({ seq: 1, tokens: 0 }), T0)
    // Three below-floor arrivals (seqs 2, 3 and 4), one ten-second window each.
    for (const seq of [2, 3, 4]) {
      track = observeStall(track, beat({ seq, tokens: seq - 1 }), T0 + (seq - 1) * 10_000)
    }
    expect(track?.slowSeconds).toBe(30)
    expect(isStalled(track, beat({ seq: 4, tokens: 3 }), T0 + 30_000)).toBe(true)
  })

  it('counts tail chars at three per token when the token total stands still', () => {
    const first = observeStall(undefined, beat({ seq: 1, tokens: 0, tail: '' }), T0)
    // 20 chars in 10s = 2 chars/s, over the 1.5 chars/s char-proxy floor: healthy.
    const streaming = observeStall(first, beat({ seq: 2, tokens: 0, tail: 'x'.repeat(20) }), T0 + 10_000)
    expect(streaming?.slowSeconds).toBe(0)
    // 10 chars in 10s = 1 char/s, under the floor: this window is slow time.
    const crawling = observeStall(streaming, beat({ seq: 3, tokens: 0, tail: 'x'.repeat(30) }), T0 + 20_000)
    expect(crawling?.slowSeconds).toBe(10)
  })

  it('ignores repeated and regressed beats (the fold can replay what it holds)', () => {
    const track = observeStall(undefined, beat({ seq: 5, tokens: 50 }), T0)
    expect(observeStall(track, beat({ seq: 5, tokens: 50 }), T0 + 10_000)).toBe(track)
    expect(observeStall(track, beat({ seq: 4, tokens: 40 }), T0 + 10_000)).toBe(track)
  })

  it('keeps the prior state on a clock-skewed arrival', () => {
    const track = observeStall(undefined, beat({ seq: 1, tokens: 0 }), T0 + 60_000)
    const skewed = observeStall(track, beat({ seq: 2, tokens: 10_000 }), T0)
    expect(skewed).toBe(track)
    // The window was kept, not consumed: the next sane arrival still sees it.
    expect(skewed?.seq).toBe(1)
  })

  it('never tracks a queued run and clears the track of a finished one', () => {
    const queued = observeStall(undefined, beat({ op: 'queued' }), T0)
    expect(queued).toBeUndefined()
    const track = observeStall(undefined, beat(), T0)
    expect(observeStall(track, beat({ op: 'finished', outcome: 'complete' }), T0 + 5_000)).toBeUndefined()
    expect(observeStall(track, beat({ op: 'finished', outcome: 'failed' }), T0 + 5_000)).toBeUndefined()
    expect(observeStall(track, beat({ op: 'finished', outcome: 'cancelled' }), T0 + 5_000)).toBeUndefined()
    expect(isStalled(track, beat({ op: 'finished', outcome: 'complete' }), T0 + 60_000)).toBe(false)
  })
})

describe('agents store stall wiring', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  const fold = (run: AgentRun): Record<string, AgentRun> => ({ [run.id]: run })

  it('marks a silent running agent and clears it when its beats resume', () => {
    const agents = useAgentsStore()
    agents.observeStalls('s1', fold(beat({ seq: 1, tokens: 0 })), T0)
    expect(agents.stalledRuns.s1).toBeUndefined()

    agents.observeStalls('s1', fold(beat({ seq: 1, tokens: 0 })), T0 + 31_000)
    expect(agents.stalledRuns.s1).toEqual({ a1: 31 })

    agents.observeStalls('s1', fold(beat({ seq: 2, tokens: 4000 })), T0 + 32_000)
    expect(agents.stalledRuns.s1).toBeUndefined()
  })

  it('drops the track of a run that reaches a terminal state and of one that leaves the fold', () => {
    const agents = useAgentsStore()
    agents.observeStalls('s1', fold(beat({ seq: 1 })), T0)
    agents.observeStalls('s1', fold(beat({ seq: 1 })), T0 + 40_000)
    expect(agents.stalledRuns.s1).toEqual({ a1: 40 })

    agents.observeStalls('s1', fold(beat({ seq: 2, op: 'finished', outcome: 'complete' })), T0 + 41_000)
    expect(agents.stalledRuns.s1).toBeUndefined()
    expect(agents.stallTracks['s1/a1']).toBeUndefined()

    agents.observeStalls('s1', fold(beat({ seq: 1 })), T0)
    agents.observeStalls('s1', {}, T0 + 1_000)
    expect(agents.stallTracks['s1/a1']).toBeUndefined()
  })

  it('keys tracks per session so a second session folds independently', () => {
    const agents = useAgentsStore()
    agents.observeStalls('s1', fold(beat({ seq: 1 })), T0)
    agents.observeStalls('s2', fold(beat({ seq: 1 })), T0)
    // Only s1 is re-observed into silence: s2 keeps its own clock.
    agents.observeStalls('s1', fold(beat({ seq: 1 })), T0 + 40_000)
    expect(agents.stalledRuns.s1).toEqual({ a1: 40 })
    agents.observeStalls('s2', fold(beat({ seq: 1 })), T0 + 10_000)
    expect(agents.stalledRuns.s2).toBeUndefined()
    expect(agents.stalledRuns.s1).toEqual({ a1: 40 })
  })
})
