import { describe, expect, it, vi } from 'vitest'
import {
  encodeTakeBase64,
  pickRecorderMime,
  recorderFailureFor,
  RecorderError,
  recorderSupported,
  startTake,
  type RecorderWindow,
  type Take,
} from '../recorder'

/** The declared encoder set for one fake window; supports() answers isTypeSupported. */
class FakeMediaRecorder {
  static supported: string[] = []
  static instances: FakeMediaRecorder[] = []

  static isTypeSupported(mime: string): boolean {
    return FakeMediaRecorder.supported.includes(mime)
  }

  state: 'inactive' | 'recording' = 'inactive'
  started = 0
  stops = 0
  requestedMime: string | undefined
  /** Real browsers flip the state inside stop() and queue the onstop event; this models that gap. */
  deferStop = false
  stopQueued = false
  ondataavailable: ((event: BlobEvent) => void) | null = null
  onstop: (() => void) | null = null

  constructor(
    public stream: MediaStream,
    options?: { mimeType?: string },
  ) {
    this.requestedMime = options?.mimeType
    FakeMediaRecorder.instances.push(this)
  }

  start(): void {
    this.started++
    this.state = 'recording'
  }

  stop(): void {
    this.stops++
    this.state = 'inactive'
    if (this.deferStop) {
      this.stopQueued = true
      return
    }
    this.onstop?.()
  }

  /** Let the queued onstop dispatch finally run, as the browser's event task would. */
  flushStop(): void {
    if (!this.stopQueued) return
    this.stopQueued = false
    this.onstop?.()
  }

  /** One timeslice of encoded bytes, as the platform would hand it. */
  emit(bytes: number): void {
    this.ondataavailable?.({ data: new Blob([new Uint8Array(bytes)]) } as BlobEvent)
  }
}

function fakeStream(): { stream: MediaStream; tracks: { stopped: boolean; stop(): void }[] } {
  const tracks = [
    { stopped: false, stop(): void { this.stopped = true } },
    { stopped: false, stop(): void { this.stopped = true } },
  ]
  return { stream: { getTracks: () => tracks } as unknown as MediaStream, tracks }
}

interface Dial {
  attempts: number
}

function winFor(opts: {
  secure?: boolean
  supported?: string[]
  gumError?: unknown
  dial?: Dial
}): { win: RecorderWindow; tracks: { stopped: boolean; stop(): void }[] } {
  FakeMediaRecorder.supported = opts.supported ?? ['audio/webm;codecs=opus']
  const { stream, tracks } = fakeStream()
  const dial = opts.dial
  const win: RecorderWindow = {
    isSecureContext: opts.secure ?? true,
    MediaRecorder: FakeMediaRecorder as never,
    navigator: {
      mediaDevices: {
        getUserMedia: (() => {
          if (dial !== undefined) dial.attempts++
          if (opts.gumError !== undefined) return Promise.reject(opts.gumError)
          return Promise.resolve(stream)
        }) as never,
      },
    },
  }
  return { win, tracks }
}

describe('recorderSupported (feature detection)', () => {
  it('needs secure context, constructor and mediaDevices together', () => {
    const { win } = winFor({})
    expect(recorderSupported(win)).toBe(true)
    expect(recorderSupported({ ...win, isSecureContext: false })).toBe(false)
    expect(recorderSupported({ ...win, MediaRecorder: undefined })).toBe(false)
    expect(recorderSupported({ ...win, navigator: {} })).toBe(false)
    expect(recorderSupported(undefined)).toBe(false)
  })
})

describe('pickRecorderMime (the declared ladder)', () => {
  it('prefers opus webm — Chrome and modern Firefox land there', () => {
    FakeMediaRecorder.supported = ['audio/webm;codecs=opus']
    const rung = pickRecorderMime(FakeMediaRecorder)
    expect(rung).toEqual({ send: 'audio/webm', record: 'audio/webm;codecs=opus' })
  })

  it('walks to plain webm before ogg when opus is refused', () => {
    FakeMediaRecorder.supported = ['audio/webm']
    expect(pickRecorderMime(FakeMediaRecorder)?.record).toBe('audio/webm')
  })

  it('takes ogg/opus when webm is absent — the Firefox spelling', () => {
    FakeMediaRecorder.supported = ['audio/ogg;codecs=opus', 'audio/mp4']
    expect(pickRecorderMime(FakeMediaRecorder)).toEqual({ send: 'audio/ogg', record: 'audio/ogg;codecs=opus' })
  })

  it('lands on mp4 for Safari, the last declared rung', () => {
    FakeMediaRecorder.supported = ['audio/mp4']
    expect(pickRecorderMime(FakeMediaRecorder)).toEqual({ send: 'audio/mp4', record: 'audio/mp4' })
  })

  it('answers null when nothing declared is encodable — and survives a throwing isTypeSupported', () => {
    FakeMediaRecorder.supported = ['audio/flac']
    expect(pickRecorderMime(FakeMediaRecorder)).toBeNull()
    const angry = { isTypeSupported: () => { throw new Error('nope') } }
    expect(pickRecorderMime(angry)).toBeNull()
    expect(pickRecorderMime(undefined)).toBeNull()
  })
})

describe('startTake refusals before any prompt', () => {
  it('refuses an insecure or unequipped window with not-secure, dialing nothing', async () => {
    const dial: Dial = { attempts: 0 }
    const insecure = winFor({ secure: false, dial })
    await expect(startTake(insecure.win, 30)).rejects.toMatchObject({ code: 'not-secure' })
    expect(dial.attempts).toBe(0)

    const bare = winFor({ dial })
    await expect(startTake({ ...bare.win, MediaRecorder: undefined }, 30)).rejects.toBeInstanceOf(RecorderError)
    await expect(startTake(undefined, 30)).rejects.toMatchObject({ code: 'not-secure' })
    expect(dial.attempts).toBe(0)
  })

  it('refuses an unencodable browser with no-supported-mime BEFORE asking permission', async () => {
    // The honest order: a page that could never encode must not burn a
    // prompt to discover it.
    const dial: Dial = { attempts: 0 }
    const { win } = winFor({ supported: [], dial })
    await expect(startTake(win, 30)).rejects.toMatchObject({ code: 'no-supported-mime' })
    expect(dial.attempts).toBe(0)
  })
})

describe('startTake permission truth', () => {
  it('a refused prompt surfaces permission-denied', async () => {
    const { win } = winFor({ gumError: new DOMException('user said no', 'NotAllowedError') })
    await expect(startTake(win, 30)).rejects.toMatchObject({ code: 'permission-denied' })
  })

  it('a missing device surfaces no-device', async () => {
    const { win } = winFor({ gumError: new DOMException('nothing here', 'NotFoundError') })
    await expect(startTake(win, 30)).rejects.toMatchObject({ code: 'no-device' })
  })

  it('recorderFailureFor names every documented platform spelling', () => {
    expect(recorderFailureFor(new DOMException('x', 'PermissionDeniedError'))).toBe('permission-denied')
    expect(recorderFailureFor(new DOMException('x', 'SecurityError'))).toBe('permission-denied')
    expect(recorderFailureFor(new DOMException('x', 'DevicesNotFoundError'))).toBe('no-device')
    expect(recorderFailureFor(new DOMException('x', 'NotReadableError'))).toBe('no-device')
    expect(recorderFailureFor(new DOMException('x', 'TrackStartError'))).toBe('no-device')
    expect(recorderFailureFor(new Error('mystery'))).toBe('unknown')
    expect(recorderFailureFor('a string')).toBe('unknown')
  })
})

describe('startTake lifecycle', () => {
  it('records through the chosen encoder and stop() delivers the whole take', async () => {
    FakeMediaRecorder.instances = []
    const { win, tracks } = winFor({ supported: ['audio/webm;codecs=opus'] })
    const active = await startTake(win, 30)
    const rec = FakeMediaRecorder.instances[0]!
    expect(rec.requestedMime).toBe('audio/webm;codecs=opus')
    expect(rec.started).toBe(1)

    rec.emit(10)
    rec.emit(0) // an empty timeslice is not kept
    rec.emit(6)
    const take = await active.stop()
    expect(take.mime).toBe('audio/webm')
    expect(take.blob.type).toBe('audio/webm')
    expect(take.blob.size).toBe(16)
    expect(tracks.every((t) => t.stopped)).toBe(true)
    expect(rec.stops).toBe(1)
  })

  it('the maxSeconds cap stops the recorder itself and delivers on onCapped', async () => {
    vi.useFakeTimers()
    try {
      FakeMediaRecorder.instances = []
      const capped: Take[] = []
      const { win, tracks } = winFor({})
      let active!: Awaited<ReturnType<typeof startTake>>
      const opening = startTake(win, 2, (take) => capped.push(take)).then((a) => { active = a })
      await vi.advanceTimersByTimeAsync(0) // let the awaited gum resolve
      const rec = FakeMediaRecorder.instances[0]!

      rec.emit(4)
      await vi.advanceTimersByTimeAsync(1999)
      expect(capped).toHaveLength(0) // one short: still recording
      expect(rec.state).toBe('recording')

      await vi.advanceTimersByTimeAsync(1)
      expect(capped).toHaveLength(1)
      expect(capped[0]!.mime).toBe('audio/webm')
      expect(capped[0]!.blob.size).toBe(4)
      expect(rec.stops).toBe(1)
      expect(tracks.every((t) => t.stopped)).toBe(true)

      // A caller arriving after the cap still gets the bytes, once, quietly.
      const take = await active.stop()
      expect(take.blob.size).toBe(4)
      expect(rec.stops).toBe(1)
      await opening
    } finally {
      vi.useRealTimers()
    }
  })

  it('the cap clamps into [1, 600] seconds — nonsense budgets cannot stream forever', async () => {
    vi.useFakeTimers()
    try {
      FakeMediaRecorder.instances = []
      const capped: Take[] = []
      const { win } = winFor({})
      const opening = startTake(win, 0, (take) => capped.push(take))
      await vi.advanceTimersByTimeAsync(0)
      await vi.advanceTimersByTimeAsync(999)
      expect(capped).toHaveLength(0)
      await vi.advanceTimersByTimeAsync(1)
      expect(capped).toHaveLength(1)
      await opening

      FakeMediaRecorder.instances = []
      const far: Take[] = []
      const wide = startTake(win, 9_999, (take) => far.push(take))
      await vi.advanceTimersByTimeAsync(0)
      await vi.advanceTimersByTimeAsync(599_999)
      expect(far).toHaveLength(0)
      await vi.advanceTimersByTimeAsync(1)
      expect(far).toHaveLength(1)
      await wide
    } finally {
      vi.useRealTimers()
    }
  })

  it('the cap race: a stop() landing while the onstop event is in flight delivers once, quietly', async () => {
    vi.useFakeTimers()
    try {
      FakeMediaRecorder.instances = []
      const capped: Take[] = []
      const { win, tracks } = winFor({})
      let active!: Awaited<ReturnType<typeof startTake>>
      const opening = startTake(win, 2, (take) => capped.push(take)).then((a) => { active = a })
      await vi.advanceTimersByTimeAsync(0) // let the awaited gum resolve
      const rec = FakeMediaRecorder.instances[0]!
      rec.deferStop = true // state flips in stop(); the event dispatch is still queued

      rec.emit(4)
      await vi.advanceTimersByTimeAsync(2000) // the cap fires
      expect(rec.state).toBe('inactive') // the encoder stopped…
      expect(capped).toHaveLength(0) // …but its event has not run yet

      const take = await active.stop() // the user's click lands in that window
      expect(take.blob.size).toBe(4) // the take is delivered here, once

      rec.flushStop() // now let the in-flight event dispatch
      expect(capped).toHaveLength(0) // the cap arm honours the claim: no second delivery
      expect(rec.stops).toBe(1)
      expect(tracks.every((t) => t.stopped)).toBe(true)
      await opening
    } finally {
      vi.useRealTimers()
    }
  })

  it('a cap event with no racing stop() still delivers onCapped exactly once', async () => {
    vi.useFakeTimers()
    try {
      FakeMediaRecorder.instances = []
      const capped: Take[] = []
      const { win } = winFor({})
      let active!: Awaited<ReturnType<typeof startTake>>
      const opening = startTake(win, 2, (take) => capped.push(take)).then((a) => { active = a })
      await vi.advanceTimersByTimeAsync(0)
      const rec = FakeMediaRecorder.instances[0]!
      rec.deferStop = true

      rec.emit(5)
      await vi.advanceTimersByTimeAsync(2000)
      expect(capped).toHaveLength(0) // queued, not yet dispatched
      rec.flushStop()
      expect(capped).toHaveLength(1) // the deferred arm really dispatches — no vacuity
      expect(capped[0]!.blob.size).toBe(5)
      const grace = await active.stop()
      expect(grace.blob.size).toBe(5) // the stale-handle grace keeps its word
      expect(capped).toHaveLength(1)
      await opening
    } finally {
      vi.useRealTimers()
    }
  })

  it('cancel() delivers nothing, releases the tracks and deadens the take', async () => {
    FakeMediaRecorder.instances = []
    const capped: Take[] = []
    const { win, tracks } = winFor({})
    const active = await startTake(win, 30, (take) => capped.push(take))
    const rec = FakeMediaRecorder.instances[0]!
    rec.emit(8)

    active.cancel()
    expect(capped).toHaveLength(0)
    expect(tracks.every((t) => t.stopped)).toBe(true)
    // The stopped recorder no longer carries the module's handler.
    expect(rec.onstop).toBeNull()
    await expect(active.stop()).rejects.toMatchObject({ code: 'unknown' })
  })
})

describe('encodeTakeBase64', () => {
  it('round-trips the whole blob bytes for the wire param', async () => {
    const bytes = new Uint8Array(300).map((_, i) => (i * 7) % 256)
    const encoded = await encodeTakeBase64(new Blob([bytes]))
    expect(atob(encoded).length).toBe(300)
    expect(Array.from(new Uint8Array(atob(encoded).split('').map((c) => c.charCodeAt(0))))).toEqual(Array.from(bytes))
  })
})
