import { describe, expect, it } from 'vitest'
import type { EventEnvelope, SessionSnapshot } from '../../protocol/generated'
import { applyEvent, applySnapshot, createState, type SessionState } from '../reducer'

/**
 * C-WEB-4.d (GAP-UI-31): the reducer's half of the elapsed turn timer.
 * `turnStartedAt` is written by exactly two arms — stamped from the
 * `turn.started` envelope's `ts`, cleared by `turn.completed` — and a
 * snapshot resume never carries a start, so the status-bar timer can stay
 * hidden on a mid-turn reload instead of guessing an elapsed value.
 */

let seq = 0
function ev(type: string, data: Record<string, unknown>, extra: Partial<EventEnvelope> = {}): EventEnvelope {
  return { sessionId: 's1', seq: ++seq, type, ts: 1, durable: true, turnId: 't1', data, ...extra }
}
function run(state: SessionState, events: EventEnvelope[]): SessionState {
  for (const event of events) applyEvent(state, event)
  return state
}

describe('reducer: turnStartedAt (C-WEB-4.d)', () => {
  it('is null on a fresh state', () => {
    expect(createState('s1').turnStartedAt).toBeNull()
  })

  it('stamps the turn.started envelope ts', () => {
    const state = run(createState('s1'), [ev('turn.started', { turnId: 't1' }, { ts: 1_700_000_042 })])
    expect(state.turnStartedAt).toBe(1_700_000_042)
  })

  it('clears on turn.completed', () => {
    const state = run(createState('s1'), [
      ev('turn.started', { turnId: 't1' }, { ts: 10 }),
      ev('turn.step', { step: 1, maxSteps: 20 }, { ts: 11 }),
      ev('turn.completed', { stopReason: 'end_turn' }, { ts: 12 }),
    ])
    expect(state.turnStartedAt).toBeNull()
  })

  it('clears on a non-clean completion too', () => {
    // Every turn.completed stop reason ends the turn; none may strand a start.
    for (const stopReason of ['cancelled', 'error', 'spend_cap', 'max_steps']) {
      const state = run(createState('s1'), [
        ev('turn.started', { turnId: 't1' }, { ts: 5 }),
        ev('turn.completed', { stopReason }, { ts: 6 }),
      ])
      expect(state.turnStartedAt, stopReason).toBeNull()
    }
  })

  it('re-stamps for the next turn', () => {
    const state = run(createState('s1'), [
      ev('turn.started', { turnId: 't1' }, { ts: 100 }),
      ev('turn.completed', { stopReason: 'end_turn' }, { ts: 150 }),
      ev('turn.started', { turnId: 't2' }, { ts: 200 }),
    ])
    expect(state.turnStartedAt).toBe(200)
  })

  it('survives the mid-turn frames that touch other fields', () => {
    // usage.step / turn.step / tool traffic must not move the stamp: only
    // the two arms write it, so the timer does not jump mid-turn.
    const state = run(createState('s1'), [
      ev('turn.started', { turnId: 't1' }, { ts: 100 }),
      ev('turn.step', { step: 2, maxSteps: 20 }, { ts: 110 }),
      ev('usage.updated', { turnUsage: { totalTokens: 10, costUsd: 0.1 } }, { ts: 120 }),
    ])
    expect(state.turnStartedAt).toBe(100)
  })

  it('a snapshot resume mid-turn leaves it null — hidden, not guessed', () => {
    // The mid-turn reload shape: the server says busy, but there is no
    // observed turn.started envelope to count from. applySnapshot never
    // writes the field, so the display keeps the timer hidden.
    const snapshot: SessionSnapshot = {
      sessionId: 's1', status: 'busy', lastSeq: 0, pendingAsks: [], queue: [],
      messages: [], spentUsd: 0, turnId: 't7',
    }
    const state = createState('s1')
    applySnapshot(state, snapshot)
    expect(state.status).toBe('busy')
    expect(state.turnId).toBe('t7')
    expect(state.turnStartedAt).toBeNull()
  })
})
