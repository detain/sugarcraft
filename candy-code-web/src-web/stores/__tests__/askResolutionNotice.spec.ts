/**
 * m24 (CW-COVER-B F2): `permission.resolved` is durable on the session scope
 * and carries the outcome's reason — a cancellation note ("the turn was
 * cancelled", "the turn ended before the question was answered", an
 * AskUser-abandonment line) or the reject feedback the model was meant to
 * read. The historical fold filtered the ask out of `state.asks` and kept
 * NONE of that: an ask the server auto-rejected on timeout vanished exactly
 * as if a human had answered it. These pins hold the repaired fold, mirroring
 * CW-EN-EU02: the reducer stores the outcome row (keyed `ask-resolved:
 * <outcome>:<askId>` so replay replaces its own row), with the server's note
 * verbatim as content — server text is never client-translated (the
 * F-M22/respondNotice law) — and TranscriptItem resolves the prose at
 * presentation from the outcome carried in the key.
 *
 * The plain human answer with no note keeps the old behavior byte-for-byte:
 * the ask clears and the transcript gains nothing (the answering client
 * already knows what it did).
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import type { EventEnvelope } from '../../protocol/generated'
import { applyEvent, createState, type NoticeItem, type SessionState } from '../reducer'
import TranscriptItem from '../../components/TranscriptItem.vue'
import type { TranscriptItem as TranscriptItemModel } from '../reducer'

let seq = 0
function envelope<T extends EventEnvelope['type']>(type: T, data: EventEnvelope['data']): EventEnvelope {
  return { sessionId: 's1', seq: ++seq, type, ts: 1, durable: true, turnId: 't1', data }
}
function asked(askId: string): EventEnvelope {
  // Inferred literal (not an annotated PendingAsk): the generated interface
  // carries no index signature, so only a fresh literal satisfies the
  // envelope's Record<string, unknown> data slot.
  return envelope('permission.requested', {
    askId,
    toolCallId: `call-${askId}`,
    tool: 'Bash',
    arguments: { command: 'ls' },
    reason: 'run a shell command',
    options: ['once', 'always', 'reject'],
  })
}
function run(state: SessionState, events: EventEnvelope[]): SessionState {
  for (const event of events) applyEvent(state, event)
  return state
}
const askNotices = (state: SessionState): NoticeItem[] =>
  state.items.filter((item): item is NoticeItem => item.kind === 'notice' && item.key.startsWith('ask-resolved:'))

describe('reducer: permission.resolved outcome fold (m24)', () => {
  it('folds a cancelled ask carrying a timeout note into a warn row with the note verbatim', () => {
    const state = run(createState('s1'), [
      asked('a1'),
      envelope('permission.resolved', { askId: 'a1', cancelled: true, note: 'timed out after 1800s' }),
    ])
    expect(state.asks).toEqual([])
    expect(askNotices(state)).toEqual([
      { kind: 'notice', key: 'ask-resolved:unanswered:a1', level: 'warn', content: 'timed out after 1800s' },
    ])
  })

  it('folds a cancellation with no note into a warn row the view can still name', () => {
    const state = run(createState('s1'), [
      asked('a2'),
      envelope('permission.resolved', { askId: 'a2', cancelled: true }),
    ])
    expect(askNotices(state)).toEqual([
      { kind: 'notice', key: 'ask-resolved:unanswered:a2', level: 'warn', content: '' },
    ])
  })

  it('folds an answered ask that carries note feedback into an info row', () => {
    const state = run(createState('s1'), [
      asked('a3'),
      envelope('permission.resolved', { askId: 'a3', reply: 'reject', note: 'no shell writes outside the repo' }),
    ])
    expect(state.asks).toEqual([])
    expect(askNotices(state)).toEqual([
      {
        kind: 'notice',
        key: 'ask-resolved:answered:a3',
        level: 'info',
        content: 'no shell writes outside the repo',
      },
    ])
  })

  it('pairs: a plain human answer stays byte-compatible — no row, ask cleared', () => {
    const state = run(createState('s1'), [asked('a4'), envelope('permission.resolved', { askId: 'a4', reply: 'once' })])
    expect(state.asks).toEqual([])
    expect(askNotices(state)).toEqual([])
    expect(state.items).toEqual([])
  })

  it('is idempotent — a replayed resolution replaces its own row, never stacks', () => {
    const resolution = envelope('permission.resolved', { askId: 'a5', cancelled: true, note: 'the turn ended' })
    const state = run(createState('s1'), [asked('a5'), resolution, { ...resolution, seq: ++seq, ts: 2 }])
    expect(askNotices(state)).toHaveLength(1)
  })

  it('keeps distinct asks as distinct rows, in arrival order', () => {
    const state = run(createState('s1'), [
      asked('x'),
      asked('y'),
      envelope('permission.resolved', { askId: 'x', cancelled: true, note: 'the turn was cancelled' }),
      envelope('permission.resolved', { askId: 'y', reply: 'always' }),
    ])
    expect(askNotices(state).map((notice) => notice.key)).toEqual(['ask-resolved:unanswered:x'])
  })

  it('names an outcome for a resolution that arrives without a tracked ask', () => {
    // A client joining mid-session sees durable resolutions for asks its
    // snapshot never carried — the reason is still the truth worth painting.
    const state = run(createState('s1'), [
      envelope('permission.resolved', { askId: 'gone', cancelled: true, note: 'the parent connection ended' }),
    ])
    expect(askNotices(state).map((notice) => notice.content)).toEqual(['the parent connection ended'])
  })
})

describe('TranscriptItem: permission.resolved rows paint localized prose (m24)', () => {
  const notice = (key: string, level: NoticeItem['level'], content: string) =>
    mount(TranscriptItem, { props: { item: { kind: 'notice', key, level, content } as TranscriptItemModel } })

  it('paints the unanswered-with-note string over the stored verbatim note', () => {
    expect(notice('ask-resolved:unanswered:a1', 'warn', 'timed out after 1800s').find('.notice').text()).toBe(
      'Permission question went unanswered: timed out after 1800s',
    )
  })

  it('paints the bare unanswered string when no note arrived', () => {
    expect(notice('ask-resolved:unanswered:a2', 'warn', '').find('.notice').text()).toBe(
      'Permission question went unanswered',
    )
  })

  it('paints the answered-with-note string over the stored feedback', () => {
    expect(notice('ask-resolved:answered:a3', 'info', 'denied by hook').find('.notice').text()).toBe(
      'Permission question answered with a note: denied by hook',
    )
  })

  it('leaves every other notice row rendering its content unchanged', () => {
    expect(notice('plain-echo', 'info', 'some echo').find('.notice').text()).toBe('some echo')
  })
})
