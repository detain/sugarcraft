import { describe, expect, it } from 'vitest'
import type { EventEnvelope, SessionSnapshot } from '../../protocol/generated'
import { applyEvent, applyPendingAsks, applySnapshot, createState, itemsFromRow, type SessionState, type ToolItem } from '../reducer'

let seq = 0
function ev(type: string, data: Record<string, unknown>, extra: Partial<EventEnvelope> = {}): EventEnvelope {
  return { sessionId: 's1', seq: ++seq, type, ts: 1, durable: true, turnId: 't1', data, ...extra }
}
function delta(text: string, offset: number, partId = 't1:text'): EventEnvelope {
  return { sessionId: 's1', type: 'assistant.delta', ts: 1, durable: false, turnId: 't1', data: { text, offset, partId } }
}
function run(state: SessionState, events: EventEnvelope[]): SessionState {
  for (const event of events) applyEvent(state, event)
  return state
}
const tools = (state: SessionState): ToolItem[] => state.items.filter((i): i is ToolItem => i.kind === 'tool')

describe('reducer: replay idempotency', () => {
  it('upserts message rows by messageId, so a replayed event changes nothing', () => {
    const created = ev('message.created', { messageId: 'm1', role: 'user', kind: 'user', content: 'hi' })
    const state = run(createState('s1'), [created, created])
    expect(state.items).toEqual([{ kind: 'user', key: 'm1', messageId: 'm1', content: 'hi' }])
  })

  it('pairs a tool finish with its start, and a replayed start does not reopen it', () => {
    const started = ev('tool.started', { toolCallId: 'c1', name: 'Bash', arguments: { command: 'ls' } })
    const finished = ev('tool.finished', { toolCallId: 'c1', name: 'Bash', isError: false, content: 'a.txt', durationMs: 12 })
    const state = run(createState('s1'), [started, finished, started, finished])
    expect(tools(state)).toHaveLength(1)
    expect(tools(state)[0]).toMatchObject({ status: 'done', content: 'a.txt', arguments: { command: 'ls' }, durationMs: 12 })
  })

  it('keeps call ids that repeat across turns apart', () => {
    const state = run(createState('s1'), [
      ev('tool.started', { toolCallId: 'echo_call_1', name: 'Bash', arguments: {} }, { turnId: 't1' }),
      ev('tool.finished', { toolCallId: 'echo_call_1', name: 'Bash', isError: false, content: 'one' }, { turnId: 't1' }),
      ev('tool.started', { toolCallId: 'echo_call_1', name: 'Bash', arguments: {} }, { turnId: 't2' }),
      ev('tool.finished', { toolCallId: 'echo_call_1', name: 'Bash', isError: false, content: 'two' }, { turnId: 't2' }),
    ])
    expect(tools(state).map((t) => t.content)).toEqual(['one', 'two'])
  })

  it('marks a refused call denied and a failed one error', () => {
    const state = run(createState('s1'), [
      ev('tool.finished', { toolCallId: 'a', name: 'Bash', isError: true, content: 'nope', denial: { kind: 'UserRejected', reason: 'no' } }),
      ev('tool.finished', { toolCallId: 'b', name: 'Read', isError: true, content: 'missing' }),
    ])
    expect(tools(state).map((t) => t.status)).toEqual(['denied', 'error'])
  })
})

describe('reducer: deltas', () => {
  it('appends a delta only at the byte offset the part holds; duplicates are dropped', () => {
    const state = run(createState('s1'), [delta('héllo', 0), delta('héllo', 0), delta(' you', 6)])
    expect(state.live?.text).toBe('héllo you')
    expect(state.live?.bytes).toBe(10)
    expect(state.live?.gap).toBe(false)
  })

  it('marks a gap and keeps streaming', () => {
    const state = run(createState('s1'), [delta('ab', 0), delta('ef', 4)])
    expect(state.live?.gap).toBe(true)
    expect(state.live?.text).toBe('abef')
  })

  it('assistant.completed replaces whatever streamed, repairing a dropped delta', () => {
    const state = run(createState('s1'), [
      delta('You', 0),
      delta('said', 4),
      ev('assistant.completed', { messageId: 'm2', content: 'You said', lengthStopped: false, stepsTruncated: false }),
    ])
    expect(state.live?.text).toBe('')
    expect(state.items).toEqual([expect.objectContaining({ kind: 'assistant', key: 'm2', content: 'You said' })])
  })

  it('freezes text streamed before a tool call into a step row above it', () => {
    const state = run(createState('s1'), [
      delta('Let me look.', 0),
      ev('tool.started', { toolCallId: 'c1', name: 'Read', arguments: { file_path: 'a' } }),
    ])
    expect(state.items.map((i) => i.kind)).toEqual(['assistant', 'tool'])
    expect(state.items[0]).toMatchObject({ content: 'Let me look.', step: true })
    expect(state.live?.text).toBe('')
  })

  it('a reply that repeats the step text replaces the step row', () => {
    const state = run(createState('s1'), [
      delta('Let me look.', 0),
      ev('tool.started', { toolCallId: 'c1', name: 'Read', arguments: {} }),
      ev('assistant.completed', { messageId: 'm3', content: 'Let me look. Done.', lengthStopped: false, stepsTruncated: false }),
    ])
    expect(state.items.map((i) => i.kind)).toEqual(['tool', 'assistant'])
  })
})

describe('reducer: turn, status, questions, queue', () => {
  it('follows a turn from start to end', () => {
    const state = run(createState('s1'), [
      ev('turn.started', { turnId: 't1' }),
      ev('turn.step', { step: 2, maxSteps: 8, context: { tokens: 5000, window: 100000 } }, { seq: undefined, durable: false }),
      ev('session.status', { status: 'busy' }),
    ])
    expect(state).toMatchObject({ status: 'busy', turnId: 't1', step: 2, maxSteps: 8, contextTokens: 5000, contextWindow: 100000 })
    run(state, [ev('turn.completed', { stopReason: 'end_turn' }), ev('session.status', { status: 'idle' })])
    expect(state.status).toBe('idle')
    expect(state.turnId).toBeNull()
    expect(state.items).toEqual([])
  })

  it('a cancelled turn interrupts its running tools and says so', () => {
    const state = run(createState('s1'), [
      ev('tool.started', { toolCallId: 'c1', name: 'Bash', arguments: {} }),
      ev('turn.completed', { stopReason: 'cancelled' }),
    ])
    expect(tools(state)[0]?.status).toBe('interrupted')
    expect(state.items[1]).toMatchObject({ kind: 'notice', content: 'Turn cancelled.' })
  })

  it('adds and removes questions by askId', () => {
    const ask = { askId: 'a1', toolCallId: 'c1', tool: 'Bash', arguments: {}, options: ['once', 'always', 'reject'] }
    const state = run(createState('s1'), [ev('permission.requested', ask), ev('permission.requested', ask)])
    expect(state.asks).toHaveLength(1)
    run(state, [ev('permission.resolved', { askId: 'a1', reply: 'once' })])
    expect(state.asks).toHaveLength(0)
  })

  it('tracks the queue', () => {
    const state = run(createState('s1'), [
      ev('turn.queued', { queueId: 'q1', text: 'next', position: 1 }),
      ev('turn.queued', { queueId: 'q1', text: 'next', position: 1 }),
    ])
    expect(state.queue).toHaveLength(1)
    run(state, [ev('turn.dequeued', { queueId: 'q1', reason: 'sent' })])
    expect(state.queue).toHaveLength(0)
  })

  it('ignores event types it does not know (additive protocol)', () => {
    const state = run(createState('s1'), [ev('something.new', { x: 1 })])
    expect(state).toEqual(createState('s1'))
  })
})

describe('reducer: snapshot', () => {
  it('maps saved rows to items, hiding harness rows', () => {
    const snapshot: SessionSnapshot = {
      sessionId: 's1',
      status: 'waiting_permission',
      lastSeq: 9,
      pendingAsks: [],
      queue: [{ queueId: 'q', text: 'later', position: 1 }],
      permissionMode: 'plan',
      messages: [
        { role: 'user', content: 'run ls' },
        { role: 'assistant', content: '', toolCalls: [{ name: 'Bash' }], userVisible: false },
        { role: 'assistant', content: 'a.txt', toolResults: [{ name: 'Bash', result: 'a.txt', error: null, id: 'c1', diff: null, durationMs: 4, arguments: { command: 'ls' }, denial: null }] },
        { role: 'system', content: 'Bash(command: "rm")', pendingToolCallId: 'c2', pendingToolName: 'Bash', pendingToolArguments: { command: 'rm' } },
        { role: 'assistant', content: 'Done.', reasoning: 'thinking' },
      ],
    }
    const state = createState('s1')
    applySnapshot(state, snapshot)
    expect(state.items.map((i) => i.kind)).toEqual(['user', 'tool', 'tool', 'assistant'])
    expect(tools(state).map((t) => t.status)).toEqual(['done', 'running'])
    expect(state.items[3]).toMatchObject({ content: 'Done.', reasoning: 'thinking' })
    expect(state).toMatchObject({ status: 'waiting_permission', permissionMode: 'plan' })
    expect(state.queue).toHaveLength(1)
  })

  it('a refused row keeps its denial', () => {
    const [item] = itemsFromRow({ role: 'assistant', content: 'x', toolResults: [{ name: 'Write', result: '', error: 'refused', id: 'c', denial: 'UserRejected' }] }, 0)
    expect(item).toMatchObject({ kind: 'tool', status: 'denied', denial: { kind: 'UserRejected', reason: 'refused' } })
  })

  it('open questions from a (re)subscribe are authoritative', () => {
    const state = createState('s1')
    applyPendingAsks(state, [{ askId: 'a', toolCallId: 'c', tool: 'Bash', arguments: {}, options: ['once'] }])
    expect(state.status).toBe('waiting_permission')
    expect(state.asks).toHaveLength(1)
  })
})
