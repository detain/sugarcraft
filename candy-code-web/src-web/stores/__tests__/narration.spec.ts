import { describe, expect, it } from 'vitest'
import type { EventEnvelope } from '../../protocol/generated'
import { applyEvent, createState } from '../reducer'

function envelope(type: string, data: Record<string, unknown>): EventEnvelope {
  return { sessionId: 's1', type, ts: 1, durable: false, turnId: 't1', data } as unknown as EventEnvelope
}

const narration = (tail: string, offset?: number) => envelope('assistant.narration', offset === undefined ? { partId: 't1:text', tail } : { partId: 't1:text', tail, offset })
const delta = (text: string, offset: number) => envelope('assistant.delta', { partId: 't1:text', text, offset })

describe('narration (O-6a)', () => {
  it('a tail from the start of the reply, then the deltas continue it without a gap', () => {
    const state = createState('s1')
    applyEvent(state, narration('Hello world', 0))
    expect(state.live).toEqual({ partId: 't1:text', bytes: 11, text: 'Hello world', gap: false })

    applyEvent(state, delta('!', 11))
    expect(state.live?.text).toBe('Hello world!')
    expect(state.live?.gap).toBe(false)
  })

  it('a tail that starts mid-reply is marked as a gap', () => {
    const state = createState('s1')
    applyEvent(state, narration('…the end', 4000))
    expect(state.live?.gap).toBe(true)
    expect(state.live?.bytes).toBe(4000 + new TextEncoder().encode('…the end').length)
  })

  it('a tail no newer than the streamed text is stale and changes nothing', () => {
    const state = createState('s1')
    applyEvent(state, delta('Hello world', 0))
    applyEvent(state, narration('world', 6))
    expect(state.live?.text).toBe('Hello world')
  })

  it('after a tool call the tail holds only what followed it, continuing the frozen text', () => {
    const state = createState('s1')
    applyEvent(state, delta('Reading.', 0))
    applyEvent(state, { ...envelope('tool.started', { toolCallId: 'c1', name: 'Read', arguments: {} }), durable: true, seq: 1 } as EventEnvelope)
    expect(state.live?.text).toBe('')
    applyEvent(state, narration('Found it', 8))
    expect(state.live).toMatchObject({ text: 'Found it', bytes: 16, gap: false })
    expect(state.items.filter((item) => item.kind === 'assistant').map((item) => (item as { content: string }).content)).toEqual(['Reading.'])
  })

  it('a later tail that rolled past the window is a gap again', () => {
    const state = createState('s1')
    applyEvent(state, narration('abc', 0))
    applyEvent(state, narration('xyz', 100))
    expect(state.live).toMatchObject({ text: 'xyz', bytes: 103, gap: true })
  })

  it('a server without offsets still shows the tail', () => {
    const state = createState('s1')
    applyEvent(state, narration('tail only'))
    expect(state.live).toEqual({ partId: 't1:text', bytes: 0, text: 'tail only', gap: true })
  })
})
