import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { Wire } from '../agents/__tests__/harness'
import { useConnectionStore } from '../connection'

/**
 * CW-8 (reviews-1103): follow() overwrote the map entry for a session
 * silently — the replaced follower never learned it had lost delivery, kept
 * a stale UI alive, and its own unsubscribe closure could delete the NEW
 * holder's registration. A superseded follower now hears about it through
 * the same resumed({ error }) channel a lost follow already speaks, and the
 * identity-guarded unsubscribe protects the current holder.
 *
 * The delivery envelopes use `message.created`, a real durable session kind
 * from the server's EventType catalogue — an earlier draft carried the
 * non-existent `notice.shown` as filler (n38, cc-review-1111).
 */

function follower() {
  return {
    cursor: () => null,
    event: vi.fn(),
    resumed: vi.fn(),
    resubscribe: () => {},
  }
}

async function connected() {
  setActivePinia(createPinia())
  const wire = new Wire()
  await wire.connect()
  return { wire, connection: useConnectionStore() }
}

describe('follow supersession (CW-8)', () => {
  it('a replaced follower is told its follow was lost, not left orphaned', async () => {
    const { wire, connection } = await connected()
    const oldOne = follower()
    const newOne = follower()

    connection.follow('s9', oldOne)
    connection.follow('s9', newOne)

    expect(oldOne.resumed).toHaveBeenCalledWith({ error: expect.stringContaining('superseded') })
    expect(newOne.resumed).not.toHaveBeenCalled()

    // Delivery goes to the current holder only.
    const envelope = { sessionId: 's9', seq: 1, type: 'message.created', ts: 1, durable: true, data: {} }
    wire.socket().event(envelope)
    await Promise.resolve()
    expect(newOne.event).toHaveBeenCalledTimes(1)
    expect(oldOne.event).not.toHaveBeenCalled()
  })

  it("the old holder's unsubscribe cannot drop the new registration", async () => {
    const { wire, connection } = await connected()
    const oldOne = follower()
    const newOne = follower()

    const unsubOld = connection.follow('s9', oldOne)
    connection.follow('s9', newOne)
    unsubOld()

    const envelope = { sessionId: 's9', seq: 2, type: 'message.created', ts: 1, durable: true, data: {} }
    wire.socket().event(envelope)
    await Promise.resolve()
    expect(newOne.event).toHaveBeenCalledTimes(1)
  })

  it('re-following with the SAME follower object is not a supersession', async () => {
    const { connection } = await connected()
    const one = follower()
    connection.follow('s9', one)
    connection.follow('s9', one)
    expect(one.resumed).not.toHaveBeenCalled()
  })
})
