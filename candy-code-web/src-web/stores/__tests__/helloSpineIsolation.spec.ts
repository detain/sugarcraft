import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'

import { Wire } from '../agents/__tests__/harness'
import { useConnectionStore, type SessionFollower } from '../connection'

/**
 * M-19 (cc-review-1111, CW-QUAL-A): the connection store's onHello callback used
 * to dereference the hello's `resumed` map blindly and run both dispatch loops
 * un-isolated. A hello that omitted the map — or a single follower/listener that
 * threw — took a TypeError mid-callback, stranding every follower behind it and
 * silencing every helloListener (the sessions index, approvals and the viewing
 * re-announce all stay dead for the whole connect cycle) inside the client's own
 * per-listener catch, with zero visible symptom.
 *
 * The contract these pins lock, jointly with the agreement family in
 * protocol/__tests__/client.spec.ts:
 * - a hello WITHOUT the map is tolerated by the guard and healed at the store:
 *   each follower receives `undefined` and recovers through a fresh resubscribe;
 * - a follower or listener that throws silences neither its siblings nor the spine.
 * If either layer changes its mind about the absent map, a pin here goes red.
 */

function recordingFollower(id: string, sink: string[], explodes = false): SessionFollower {
  return {
    cursor: () => null,
    event: () => undefined,
    resumed: (result) => {
      // Recorded before the throw so a strand is visible as a missing sibling.
      sink.push(`${id}:${result === undefined ? 'healed' : 'resumed'}`)
      if (explodes) throw new Error('follower exploded')
    },
    resubscribe: () => undefined,
  }
}

describe('hello spine isolation (M-19)', () => {
  let wire: Wire

  beforeEach(() => {
    setActivePinia(createPinia())
    wire = new Wire()
  })

  it('a hello without the resumed map heals every follower and runs every listener', async () => {
    const seen: string[] = []
    const connection = await wire.connect(() => {
      const store = useConnectionStore()
      store.follow('s1', recordingFollower('s1', seen))
      store.follow('s2', recordingFollower('s2', seen))
      store.onHello(() => {
        seen.push('listener')
      })
    }, { resumed: undefined })

    expect(seen).toEqual(['s1:healed', 's2:healed', 'listener'])
    expect(connection.status).toBe('connected')
  })

  it('a throwing follower silences neither the later followers nor the listeners', async () => {
    const seen: string[] = []
    await wire.connect(() => {
      const store = useConnectionStore()
      store.follow('boom', recordingFollower('boom', seen, true))
      store.follow('s2', recordingFollower('s2', seen))
      store.onHello(() => {
        seen.push('listener')
      })
    })

    expect(seen).toEqual(['boom:healed', 's2:healed', 'listener'])
  })

  it('a throwing hello listener silences neither the later listeners nor the spine', async () => {
    const seen: string[] = []
    await wire.connect(() => {
      const store = useConnectionStore()
      store.onHello(() => {
        seen.push('first:threw')
        throw new Error('listener exploded')
      })
      store.onHello(() => {
        seen.push('second')
      })
    })

    expect(seen).toEqual(['first:threw', 'second'])
  })

  it('a well-formed hello still delivers the resumed entry per id', async () => {
    const seen: string[] = []
    await wire.connect(() => {
      const store = useConnectionStore()
      store.follow('s1', recordingFollower('s1', seen))
      store.follow('s2', recordingFollower('s2', seen))
      store.onHello(() => {
        seen.push('listener')
      })
    }, { resumed: { s1: { fromSeq: 8, throughSeq: 8, pendingAsks: [] } } })

    expect(seen).toEqual(['s1:resumed', 's2:healed', 'listener'])
  })
})
