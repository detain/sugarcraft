import { describe, expect, it } from 'vitest'

import type { EventEnvelope } from '../../protocol/generated'
import { applyEvent, createState, type NoticeItem, type SessionState } from '../reducer'

/**
 * m25 (CW-COVER-B F3): the server distinguishes WHY a queued prompt left the
 * queue ({queueId, text?, reason: 'sent' | 'removed'}), but the reducer read
 * only queueId — the removed entry's text vanished silently. These pins hold
 * the fold: a 'removed' de-queue leaves one keyed notice row carrying the
 * server text verbatim (prose is resolved at presentation in TranscriptItem),
 * while 'sent' — and the legacy reason-less shape — stay byte-identical to
 * the pre-fix behavior (queue shrinks, no rows). Replays replace their own
 * row, so a durable re-feed cannot double-print.
 */

let seq = 0

function ev(type: string, data: Record<string, unknown>, extra: Partial<EventEnvelope> = {}): EventEnvelope {
  return { sessionId: 's1', seq: ++seq, type, ts: 1, durable: true, turnId: 't1', data, ...extra }
}

function run(state: SessionState, events: EventEnvelope[]): SessionState {
  for (const event of events) applyEvent(state, event)
  return state
}

function noticeRows(state: SessionState): NoticeItem[] {
  return state.items.filter((item): item is NoticeItem => item.kind === 'notice')
}

function queued(): EventEnvelope {
  return ev('turn.queued', { queueId: 'q1', text: 'next', position: 1 })
}

describe('turn.dequeued notice surface (m25)', () => {
  it('surfaces a removed de-queue as one keyed notice row with the server text verbatim', () => {
    const state = run(createState('s1'), [
      queued(),
      ev('turn.dequeued', { queueId: 'q1', text: 'next', reason: 'removed' }),
    ])

    expect(state.queue).toHaveLength(0)
    const rows = noticeRows(state)
    expect(rows).toHaveLength(1)
    expect(rows[0]?.key).toBe('dequeued:removed:q1')
    expect(rows[0]?.content).toBe('next')
    expect(rows[0]?.level).toBe('info')
  })

  it('stores an empty content when the server omits the removed text', () => {
    const state = run(createState('s1'), [
      queued(),
      ev('turn.dequeued', { queueId: 'q1', reason: 'removed' }),
    ])

    const rows = noticeRows(state)
    expect(rows).toHaveLength(1)
    expect(rows[0]?.key).toBe('dequeued:removed:q1')
    expect(rows[0]?.content).toBe('')
  })

  it('keeps the sent de-queue silent (byte-compat pair pin)', () => {
    const state = run(createState('s1'), [
      queued(),
      ev('turn.dequeued', { queueId: 'q1', reason: 'sent' }),
    ])

    expect(state.queue).toHaveLength(0)
    expect(noticeRows(state)).toHaveLength(0)
  })

  it('keeps the legacy reason-less shape silent (defensive byte-compat pin)', () => {
    const state = run(createState('s1'), [
      queued(),
      ev('turn.dequeued', { queueId: 'q1' }),
    ])

    expect(state.queue).toHaveLength(0)
    expect(noticeRows(state)).toHaveLength(0)
  })

  it('replaces its own row when the same removed event is replayed', () => {
    const event = ev('turn.dequeued', { queueId: 'q1', text: 'next', reason: 'removed' })
    const state = run(createState('s1'), [queued(), event, event])

    const rows = noticeRows(state)
    expect(rows).toHaveLength(1)
    expect(rows[0]?.key).toBe('dequeued:removed:q1')
    expect(rows[0]?.content).toBe('next')
  })

  it('keys one row per de-queued entry', () => {
    const state = run(createState('s1'), [
      queued(),
      ev('turn.queued', { queueId: 'q2', text: 'later', position: 2 }),
      ev('turn.dequeued', { queueId: 'q1', text: 'next', reason: 'removed' }),
      ev('turn.dequeued', { queueId: 'q2', text: 'later', reason: 'removed' }),
    ])

    expect(state.queue).toHaveLength(0)
    const rows = noticeRows(state)
    expect(rows).toHaveLength(2)
    expect(rows.map((row) => row.key)).toEqual(['dequeued:removed:q1', 'dequeued:removed:q2'])
  })
})
