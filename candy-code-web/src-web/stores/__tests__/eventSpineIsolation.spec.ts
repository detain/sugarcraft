import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import type { EventEnvelope } from '../../protocol/generated'
import { Wire } from '../agents/__tests__/harness'
import { useConnectionStore, type SessionFollower } from '../connection'

/**
 * CW-1 (reviews-1103): the event spine — reducer apply, follower delivery,
 * every store listener — ran as bare calls inside the flush loop, so ONE
 * throwing consumer killed the rest of that envelope's consumers AND every
 * later envelope in the batch (the batch was already spliced off the
 * queue: the loss was silent and permanent). Each consumer is isolated
 * now; a crash is that consumer's problem, not the spine's.
 *
 * The envelope kinds are real catalogue entries (n38, cc-review-1111:
 * `server.tick` for the server-scope pair, `message.created` for the
 * session-scope ones; the earlier `notice.shown` filler never existed on
 * the wire). The envelope SHAPE stays simplified — the spine routes by
 * `type` and `sessionId` and never inspects `durable` or `seq`.
 */

let wire: Wire

beforeEach(async () => {
  setActivePinia(createPinia())
  wire = new Wire()
  await wire.connect()
})

function envelope(sessionId: string | null, type: string, data: Record<string, unknown>, seq: number): Record<string, unknown> {
  return { sessionId, seq, type, ts: 1, durable: true, data }
}

describe('the event spine isolates its consumers (CW-1)', () => {
  it('a throwing listener silences neither later listeners nor later envelopes', () => {
    const connection = useConnectionStore()
    const seen: string[] = []
    connection.onEvent(() => {
      throw new Error('consumer exploded')
    })
    connection.onEvent((event) => {
      seen.push(`${event.type}:${event.seq}`)
    })

    wire.socket().event(envelope(null, 'server.tick', {}, 1))
    wire.socket().event(envelope(null, 'server.tick', {}, 2))

    expect(seen).toEqual(['server.tick:1', 'server.tick:2'])
  })

  it('a throwing follower still leaves the store listeners the delivery', () => {
    const connection = useConnectionStore()
    const deaf: SessionFollower = { cursor: () => null, event: () => { throw new Error('deaf') }, resumed: () => {}, resubscribe: () => {} }
    const heard: string[] = []
    connection.follow('s1', deaf)
    connection.onEvent((event) => {
      heard.push(`${event.type}`)
    })

    wire.socket().event(envelope('s1', 'message.created', {}, 1))

    expect(heard).toEqual(['message.created'])
  })

  it('the follower keeps its delivery even when a store listener explodes', () => {
    const connection = useConnectionStore()
    const events: EventEnvelope[] = []
    const listener = { cursor: () => null, event: (e: EventEnvelope) => { events.push(e) }, resumed: () => {}, resubscribe: () => {} }
    connection.follow('s2', listener as unknown as SessionFollower)
    connection.onEvent(() => {
      throw new Error('listener first crash — on base it ran AFTER follower delivery for envelope one, then killed envelope two')
    })

    wire.socket().event(envelope('s2', 'message.created', {}, 1))
    wire.socket().event(envelope('s2', 'message.created', {}, 2))

    // Base reached envelope one's follower (listener runs after it in the
    // per-envelope loop) but envelope two never flushed at all.
    expect(events).toHaveLength(2)
  })
})
