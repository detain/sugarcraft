import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { FakeSocket, FakeTimers, flush, helloResult } from '../../__tests__/fakes'
import { Backoff } from '../../protocol/reconnect'
import { useConnectionStore } from '../connection'

/**
 * W6.7 (plan_crush_media): the store surfaces the server's boot-time media
 * flags from the handshake so generation affordances can hide themselves
 * WITHOUT the browser ever dialing the SD server. The laws pinned here:
 * an old server (no `features.media`) says nothing — the store answers null
 * and both affordances are false; a present block maps flag-for-flag, and
 * the flags stay independent (a future W7 server may light video alone).
 */
let sockets: FakeSocket[]

function socket(): FakeSocket {
  const last = sockets[sockets.length - 1]
  if (!last) throw new Error('no socket')
  return last
}

async function connectWith(features: Record<string, unknown>): Promise<ReturnType<typeof useConnectionStore>> {
  const connection = useConnectionStore()
  connection.configure({
    connectUrl: async () => 'ws://x/ws?ticket=t',
    socket: (url, protocols) => {
      const created = new FakeSocket(url, protocols)
      sockets.push(created)
      return created
    },
    timers: new FakeTimers(),
    backoff: new Backoff({ random: () => 0.5 }),
  }, (fn) => fn())
  connection.start()
  await flush()
  socket().open()
  socket().answer(helloResult({ features: { methods: [], events: [], ...features } }), 'hello')
  await flush()
  return connection
}

describe('connection media capabilities (W6.7)', () => {
  beforeEach(() => {
    sockets = []
    setActivePinia(createPinia())
  })

  it('says nothing when the server predates the field', async () => {
    const connection = await connectWith({})
    expect(connection.capabilitiesMedia).toBeNull()
    expect(connection.canGenerateImage).toBe(false)
    expect(connection.canGenerateVideo).toBe(false)
    expect(connection.canTranscribe).toBe(false)
  })

  it('offers images when the server says sdapi is ready', async () => {
    const connection = await connectWith({
      media: { image: true, video: false, baseUrlHostOnly: 'da0e86db9b77f6b8' },
    })
    expect(connection.capabilitiesMedia).toEqual({ image: true, video: false, baseUrlHostOnly: 'da0e86db9b77f6b8' })
    expect(connection.canGenerateImage).toBe(true)
    expect(connection.canGenerateVideo).toBe(false)
  })

  it('keeps the flags independent for a future video-only server', async () => {
    const connection = await connectWith({
      media: { image: false, video: true, baseUrlHostOnly: '0123456789abcdef' },
    })
    expect(connection.canGenerateImage).toBe(false)
    expect(connection.canGenerateVideo).toBe(true)
  })

  it('hides both when the server configured no media base', async () => {
    const connection = await connectWith({
      media: { image: false, video: false, baseUrlHostOnly: null },
    })
    expect(connection.capabilitiesMedia).not.toBeNull()
    expect(connection.canGenerateImage).toBe(false)
    expect(connection.canGenerateVideo).toBe(false)
    expect(connection.canTranscribe).toBe(false)
  })

  it('raises the voice door on the transcribe flag alone', async () => {
    // The flag is independent of the SD pair: a server with a voice
    // endpoint and no image backend advertises exactly this quad.
    const connection = await connectWith({
      media: { image: false, video: false, transcribe: true, baseUrlHostOnly: null },
    })
    expect(connection.canTranscribe).toBe(true)
    expect(connection.canGenerateImage).toBe(false)
  })

  it('an old server block without the key answers false — the flag is optional on the wire', async () => {
    // A pre-C-TUI-6.n server says image/video and nothing about voice;
    // an absent key is silence, never an implicit yes.
    const connection = await connectWith({
      media: { image: true, video: true, baseUrlHostOnly: 'da0e86db9b77f6b8' },
    })
    expect(connection.canTranscribe).toBe(false)
    expect(connection.canGenerateImage).toBe(true)
  })
})
