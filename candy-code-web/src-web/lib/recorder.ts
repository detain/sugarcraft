/**
 * Microphone capture for server-side dictation (C-TUI-6.n). The composer's
 * mic button rides this module when the server advertises the `transcribe`
 * affordance: the take is recorded HERE and handed to the candy-code
 * server, which forwards it to the user's own configured voice endpoint.
 * Nothing in this file dials anything — it only produces bytes; the RPC
 * lives with the caller through the connection store.
 *
 * Format law: the server accepts exactly the bare types it declares
 * (`audio/webm`, `audio/ogg`, `audio/mp4`), so the ladder below tries the
 * opus-parameterised spelling first for the encoder, and reports the bare
 * type for the wire. Chrome yields webm/opus, Firefox webm or ogg/opus,
 * Safari mp4 — every branch lands on a declared mime or fails loudly.
 *
 * Cap: the caller passes the seconds budget and an onCapped callback; the
 * recorder stops the MediaRecorder itself when the timer fires and delivers
 * the finished take there, so a forgotten button cannot stream forever and
 * a capped take still reaches its transcription. The server's transport
 * enforces its own cap independently. A stop() landing between the cap
 * halting the encoder and its queued onstop event claims the delivery, so
 * the take reaches exactly one arm and a billed transcription never doubles.
 *
 * Permission UX is browser truth: getUserMedia prompts, and a refusal
 * surfaces as a named error code the composer turns into a notice. There
 * is no silent fallback to another engine from here.
 */

/** The bare wire types, in preference order, with the encoder spellings tried for each. */
const LADDER: ReadonlyArray<{ send: 'audio/webm' | 'audio/ogg' | 'audio/mp4'; record: string }> = [
  { send: 'audio/webm', record: 'audio/webm;codecs=opus' },
  { send: 'audio/webm', record: 'audio/webm' },
  { send: 'audio/ogg', record: 'audio/ogg;codecs=opus' },
  { send: 'audio/ogg', record: 'audio/ogg' },
  { send: 'audio/mp4', record: 'audio/mp4' },
]

/** The window slice the recorder reads; a plain object satisfies it in tests. */
export interface RecorderWindow {
  isSecureContext?: boolean
  MediaRecorder?: {
    new (stream: MediaStream, options?: { mimeType?: string }): MediaRecorder
    isTypeSupported(mime: string): boolean
  } | undefined
  navigator?:
    | {
        mediaDevices?: {
          getUserMedia?: (constraints: MediaStreamConstraints) => Promise<MediaStream>
        } | null
      }
    | undefined
}

/** The failure codes the composer maps to notices; no message pretends to know more. */
export type RecorderFailure = 'permission-denied' | 'no-device' | 'no-supported-mime' | 'not-secure' | 'unknown'

export class RecorderError extends Error {
  readonly code: RecorderFailure
  constructor(code: RecorderFailure, message?: string) {
    super(message ?? code)
    this.code = code
  }
}

/** True only when this page could actually run a take: secure context, mediaDevices, MediaRecorder. */
export function recorderSupported(win: RecorderWindow | undefined): boolean {
  if (win === undefined) return false
  if (!win.isSecureContext) return false
  if (typeof win.MediaRecorder !== 'function') return false
  return typeof win.navigator?.mediaDevices?.getUserMedia === 'function'
}

/**
 * The ladder's first acceptable encoder spelling, or null when this browser
 * can encode none of the declared types. Exported for tests and for the
 * honest "the server says yes, the browser says no" answer the composer
 * turns into a visible degrade.
 */
export function pickRecorderMime(
  ctor: { isTypeSupported: (mime: string) => boolean } | undefined,
): (typeof LADDER)[number] | null {
  if (ctor === undefined) return null
  for (const rung of LADDER) {
    try {
      if (ctor.isTypeSupported(rung.record)) return rung
    } catch {
      // A platform that throws where it should answer false: keep climbing.
      continue
    }
  }
  return null
}

/** A finished take: the encoded bytes and the bare mime to declare to the server. */
export interface Take {
  blob: Blob
  mime: 'audio/webm' | 'audio/ogg' | 'audio/mp4'
}

/** A running take; exactly one of stop/cancel (or the cap) delivers it. */
export interface ActiveTake {
  /** Stop the encoder and resolve with the whole take. */
  stop(): Promise<Take>
  /** Abandon: nothing resolves, tracks are released. */
  cancel(): void
}

/**
 * Open the microphone and start encoding. Rejects with a RecorderError
 * before any prompt appears when the context or the format ladder cannot
 * work, and after the prompt when the user (or the device) says no.
 * At `maxSeconds` the recorder stops itself and the take lands on
 * `onCapped` instead of the caller's `stop()`.
 */
export async function startTake(
  win: RecorderWindow | undefined,
  maxSeconds: number,
  onCapped?: (take: Take) => void,
): Promise<ActiveTake> {
  const media = win?.navigator?.mediaDevices
  if (win === undefined || media?.getUserMedia === undefined || win.MediaRecorder === undefined) {
    throw new RecorderError('not-secure')
  }
  if (!win.isSecureContext) {
    throw new RecorderError('not-secure')
  }
  const rung = pickRecorderMime(win.MediaRecorder)
  if (rung === null) {
    throw new RecorderError('no-supported-mime')
  }
  let stream: MediaStream
  try {
    stream = await media.getUserMedia({ audio: true })
  } catch (cause) {
    throw new RecorderError(recorderFailureFor(cause), cause instanceof Error ? cause.message : undefined)
  }
  const recorder = new win.MediaRecorder(stream, { mimeType: rung.record })
  const chunks: BlobPart[] = []
  recorder.ondataavailable = (event: BlobEvent) => {
    if (event.data.size > 0) chunks.push(event.data)
  }
  let settle: ((take: Take) => void) | null = null
  let dead = false
  let claimed = false
  const release = (): void => {
    clearTimeout(timer)
    for (const track of stream.getTracks()) track.stop()
  }
  recorder.onstop = () => {
    const take: Take = { blob: new Blob(chunks, { type: rung.send }), mime: rung.send }
    const resolve = settle
    settle = null
    if (resolve !== null) {
      release()
      resolve(take)
    } else if (!dead && !claimed) {
      release()
      onCapped?.(take)
    }
  }
  const cap = Math.max(1, Math.min(Math.floor(maxSeconds), 600)) * 1000
  const timer = setTimeout(() => {
    if (recorder.state !== 'inactive') recorder.stop()
  }, cap)
  recorder.start()
  return {
    stop(): Promise<Take> {
      if (dead) return Promise.reject(new RecorderError('unknown', 'the take was already abandoned'))
      if (recorder.state === 'inactive') {
        // The cap already stopped the encoder, but its onstop event may still
        // be in flight. Claiming here keeps that event from handing the same
        // take to onCapped for a second (billed) delivery; the bytes ride
        // this promise instead — the caller still receives them once.
        claimed = true
        const take: Take = { blob: new Blob(chunks, { type: rung.send }), mime: rung.send }
        release()
        return Promise.resolve(take)
      }
      return new Promise<Take>((resolve) => {
        settle = resolve
        recorder.stop()
      })
    },
    cancel(): void {
      dead = true
      try {
        if (recorder.state !== 'inactive') recorder.stop()
      } catch {
        /* already gone */
      }
      recorder.onstop = null
      release()
    },
  }
}

/** The browser's DOMException name → this module's honest failure code. */
export function recorderFailureFor(cause: unknown): RecorderFailure {
  const name = cause instanceof DOMException ? cause.name : cause instanceof Error ? cause.name : ''
  switch (name) {
    case 'NotAllowedError':
    case 'PermissionDeniedError':
    case 'SecurityError':
      return 'permission-denied'
    case 'NotFoundError':
    case 'DevicesNotFoundError':
    case 'NotReadableError':
    case 'TrackStartError':
      return 'no-device'
    default:
      return 'unknown'
  }
}

/** Whole Blob → base64 for the `audio.transcribe` param, chunked under a 32 KiB spread per btoa call. */
export async function encodeTakeBase64(blob: Blob): Promise<string> {
  const bytes = new Uint8Array(await blob.arrayBuffer())
  let binary = ''
  const CHUNK = 0x8000
  for (let i = 0; i < bytes.length; i += CHUNK) {
    binary += String.fromCharCode(...bytes.subarray(i, i + CHUNK))
  }
  return btoa(binary)
}
