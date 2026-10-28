/**
 * Browser dictation over the Web Speech API (C-TUI-6.k). The composer's mic
 * button rides this module; every browser-shaped detail lives here so the
 * component stays presentation and a test can feed a fake recognition class.
 *
 * Privacy: the audio is heard by the BROWSER VENDOR's speech service (Chrome
 * routes through Google, Safari through Apple). Nothing here contacts the
 * candy-code server — the button's title discloses exactly that.
 *
 * Firefox ships no SpeechRecognition at all, so the button is simply absent
 * there; plain-http serve on a LAN host is not a secure context, so it stays
 * hidden there too (the API itself refuses in an insecure context).
 */

/** One alternative inside a result; only the transcript is read. */
export interface RecognitionAlternative {
  transcript: string
}

/** One result: an array-like of alternatives with a finality flag. */
export interface RecognitionResult {
  readonly length: number
  isFinal: boolean
  [index: number]: RecognitionAlternative
}

/** The results carried by one result event; indexed from 0 across the session. */
export interface RecognitionResultList {
  readonly length: number
  [index: number]: RecognitionResult
}

/** The result event shape this module reads. */
export interface RecognitionResultEvent {
  resultIndex: number
  results: RecognitionResultList
}

/** The error event shape this module reads (`not-allowed`, `no-speech`, …). */
export interface RecognitionErrorEvent {
  error: string
}

/** The recognition instance shape this module drives; the browser class satisfies it. */
export interface RecognitionLike {
  lang: string
  continuous: boolean
  interimResults: boolean
  onresult?: ((event: RecognitionResultEvent) => void) | null
  onerror?: ((event: RecognitionErrorEvent) => void) | null
  onend?: (() => void) | null
  start(): void
  stop(): void
}

/** The window slice feature detection reads; a plain object satisfies it in tests. */
export interface DictationWindow {
  SpeechRecognition?: (new () => RecognitionLike) | undefined
  webkitSpeechRecognition?: (new () => RecognitionLike) | undefined
  isSecureContext?: boolean
}

/**
 * Dictation needs the constructor AND a secure context. The first half is
 * Firefox's absence; the second is the plain-http LAN serve: the browser
 * refuses the API there, so the composer must not offer a button that only
 * fails.
 */
export function dictationSupported(win: DictationWindow | undefined): boolean {
  if (win === undefined) return false
  if (!win.isSecureContext) return false
  return win.SpeechRecognition !== undefined || win.webkitSpeechRecognition !== undefined
}

export interface DictationHandlers {
  /** BCP-47 tag; left unset falls back to the page locale, as the API does. */
  lang?: string
  /** Live in-progress text, replaced (not appended) as the service revises it. */
  onInterim?: (text: string) => void
  /** One settled utterance, delivered once. */
  onFinal?: (text: string) => void
  /** A service error code (`not-allowed`, `no-speech`, …). */
  onError?: (code: string) => void
  /** The service ended the session on its own (silence timeout, mic lost);
   *  a `stop()` call deliberately suppresses this. */
  onEnd?: () => void
}

/** A dictation session; `start`/`stop` are safe to call in any order. */
export interface Dictation {
  start(): void
  stop(): void
}

/** The transcripts of one result event, finality included (exported for tests). */
function collect(event: RecognitionResultEvent): { interim: string; finals: string[] } {
  const finals: string[] = []
  const live: string[] = []
  for (let i = event.resultIndex; i < event.results.length; i++) {
    const result = event.results[i]
    if (result === undefined) continue
    // Alternatives are ranked hypotheses for the same span; [0] is the best.
    const transcript = result[0]?.transcript ?? ''
    if (result.isFinal) finals.push(transcript)
    else live.push(transcript)
  }
  return { interim: live.join(''), finals }
}

/**
 * A live dictation session over ONE recognition instance: `continuous` and
 * `interimResults` are on, so the session spans pauses and the hint text
 * updates while speaking. Returns null when the platform cannot do dictation
 * (no constructor, insecure context) or the constructor itself refuses.
 * The second argument exists for tests and for the component, which passes
 * its own `window`.
 */
export function createDictation(
  handlers: DictationHandlers,
  win: DictationWindow | undefined = globalThis as unknown as DictationWindow,
): Dictation | null {
  if (!dictationSupported(win)) return null
  const ctor = (win as DictationWindow).SpeechRecognition ?? (win as DictationWindow).webkitSpeechRecognition
  if (ctor === undefined) return null
  let recognition: RecognitionLike
  try {
    recognition = new ctor()
  } catch {
    // Safari has thrown here for no-microphone hardware; hide, do not crash.
    return null
  }
  recognition.lang = handlers.lang ?? ''
  recognition.continuous = true
  recognition.interimResults = true
  let active = false
  recognition.onresult = (event: RecognitionResultEvent) => {
    if (!active) return
    const { interim, finals } = collect(event)
    handlers.onInterim?.(interim)
    for (const final of finals) handlers.onFinal?.(final)
  }
  recognition.onerror = (event: RecognitionErrorEvent) => {
    handlers.onError?.(event.error)
  }
  recognition.onend = () => {
    if (!active) return
    active = false
    handlers.onEnd?.()
  }
  return {
    start(): void {
      if (active) return
      active = true
      try {
        recognition.start()
      } catch {
        // Chrome throws InvalidStateError when one is already running; the
        // toggle keeps the user honest, so report the dead start instead.
        active = false
        handlers.onError?.('already-running')
      }
    },
    stop(): void {
      if (!active) return
      active = false
      recognition.onend = null
      try {
        recognition.stop()
      } catch {
        /* an already-dead session: the flag is cleared, that is all it owes */
      }
    },
  }
}

/**
 * The short inline notice for a service error code (C-TUI-6.k): the composer
 * shows this under the textarea instead of failing silently. `aborted` is the
 * user's own stop — nothing to say.
 */
export function dictationNoticeFor(code: string): string {
  switch (code) {
    case 'not-allowed':
      return 'Dictation needs the microphone — allow it in the browser, then try again.'
    case 'service-not-allowed':
      return 'The browser blocked its speech service for this page.'
    case 'no-speech':
      return 'Heard nothing — dictation stopped.'
    case 'audio-capture':
      return 'No microphone was found to dictate with.'
    case 'network':
      return 'The browser speech service was unreachable; check the network.'
    case 'already-running':
      return 'Dictation is already running.'
    default:
      return code === 'aborted' ? '' : `Dictation failed (${code}).`
  }
}
