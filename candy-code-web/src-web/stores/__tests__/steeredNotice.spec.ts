/**
 * CW-EN-EU02: `turn.steered` was typed and durable on the wire, emitted by
 * the server the moment a prompt is folded into a running turn — but the
 * reducer's case list had no arm, so the steer left no trace in the
 * transcript at all. These pins hold the fold: a notice row keyed by the
 * steerId, glyph-only content (the payload carries no text, and the web i18n
 * key for prose is a filed LANG REQUEST, not a live string), idempotent under
 * replay, and inert toward the queue itself.
 */
import { describe, expect, it } from 'vitest'
import type { EventEnvelope } from '../../protocol/generated'
import { applyEvent, createState, type NoticeItem, type SessionState } from '../reducer'

let seq = 0
function steered(steerId: string): EventEnvelope {
  return { sessionId: 's1', seq: ++seq, type: 'turn.steered', ts: 1, durable: true, turnId: 't1', data: { steerId } }
}
function run(state: SessionState, events: EventEnvelope[]): SessionState {
  for (const event of events) applyEvent(state, event)
  return state
}
const steerNotices = (state: SessionState): NoticeItem[] =>
  state.items.filter((item): item is NoticeItem => item.kind === 'notice' && item.key.startsWith('steered:'))

describe('reducer: turn.steered (CW-EN-EU02)', () => {
  it('folds a steer into a glyph-only info notice keyed by the steerId', () => {
    const state = run(createState('s1'), [steered('st1')])
    expect(steerNotices(state)).toEqual([{ kind: 'notice', key: 'steered:st1', level: 'info', content: '⇥' }])
  })

  it('is idempotent — a replayed steerId replaces its own row, never stacks', () => {
    const state = run(createState('s1'), [steered('st1'), { ...steered('st1'), seq: ++seq, ts: 2 }])
    expect(steerNotices(state)).toHaveLength(1)
  })

  it('keeps distinct steerIds as distinct rows, in arrival order', () => {
    const state = run(createState('s1'), [steered('a'), steered('b')])
    expect(steerNotices(state).map((notice) => notice.key)).toEqual(['steered:a', 'steered:b'])
  })

  it('leaves the pending queue untouched — the notice is the only fold', () => {
    const state = run(createState('s1'), [
      { sessionId: 's1', seq: ++seq, type: 'turn.queued', ts: 1, durable: true, turnId: 't1', data: { queueId: 'q1', text: 'later prompt', position: 2 } },
      steered('st1'),
    ])
    expect(state.queue).toHaveLength(1)
    expect(steerNotices(state)).toHaveLength(1)
  })
})
