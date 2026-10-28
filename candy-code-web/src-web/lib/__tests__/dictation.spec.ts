import { describe, expect, it } from 'vitest'
import {
  createDictation,
  dictationNoticeFor,
  dictationSupported,
  type DictationWindow,
  type RecognitionErrorEvent,
  type RecognitionResult,
  type RecognitionResultEvent,
  type RecognitionResultList,
} from '../dictation'

/** One utterance of a fake service: transcript plus the finality flag. */
type Utterance = [transcript: string, isFinal: boolean]

function resultEvent(utterances: Utterance[], resultIndex = 0): RecognitionResultEvent {
  const results = { length: utterances.length } as unknown as RecognitionResultList
  utterances.forEach(([transcript, isFinal], i) => {
    results[i] = { length: 1, isFinal, 0: { transcript } }
  })
  return { resultIndex, results }
}

class FakeRecognition {
  static instances: FakeRecognition[] = []

  lang = ''
  continuous = false
  interimResults = false
  starts = 0
  stops = 0
  onresult: ((event: RecognitionResultEvent) => void) | null = null
  onerror: ((event: RecognitionErrorEvent) => void) | null = null
  onend: (() => void) | null = null

  constructor() {
    FakeRecognition.instances.push(this)
  }

  start(): void {
    this.starts++
  }

  stop(): void {
    this.stops++
  }

  /** The test-side service: push events through the wired handlers. */
  speak(...utterances: Utterance[]): void {
    this.onresult?.(resultEvent(utterances))
  }

  fail(error: string): void {
    this.onerror?.({ error })
  }

  end(): void {
    this.onend?.()
  }
}

function secureWin(withCtor: boolean): DictationWindow {
  return withCtor
    ? { SpeechRecognition: FakeRecognition, isSecureContext: true }
    : { isSecureContext: true }
}

describe('dictationSupported (feature detection)', () => {
  it('needs the constructor and a secure context together', () => {
    expect(dictationSupported({ SpeechRecognition: FakeRecognition, isSecureContext: true })).toBe(true)
    // Safari spells the constructor with the vendor prefix.
    expect(dictationSupported({ webkitSpeechRecognition: FakeRecognition, isSecureContext: true })).toBe(true)
  })

  it('is false without any constructor — Firefox has none', () => {
    expect(dictationSupported({ isSecureContext: true })).toBe(false)
  })

  it('is false in an insecure context even with the constructor — plain-http LAN serve', () => {
    expect(dictationSupported({ SpeechRecognition: FakeRecognition, isSecureContext: false })).toBe(false)
    expect(dictationSupported({ SpeechRecognition: FakeRecognition })).toBe(false)
  })

  it('is false with no window at all', () => {
    expect(dictationSupported(undefined)).toBe(false)
  })
})

describe('createDictation lifecycle', () => {
  it('refuses when the platform is unsupported', () => {
    FakeRecognition.instances = []
    expect(createDictation({}, secureWin(false))).toBeNull()
    expect(createDictation({}, { SpeechRecognition: FakeRecognition })).toBeNull() // insecure
    expect(FakeRecognition.instances).toHaveLength(0) // never even constructed
  })

  it('refuses when the constructor itself throws', () => {
    class AngryRecognition {
      constructor() {
        throw new Error('no audio hardware')
      }
    }
    const win: DictationWindow = {
      SpeechRecognition: AngryRecognition as never,
      isSecureContext: true,
    }
    expect(createDictation({}, win)).toBeNull()
  })

  it('arms continuous + interimResults and drives start/stop on one instance', () => {
    FakeRecognition.instances = []
    const events: string[] = []
    const session = createDictation(
      {
        lang: 'en-GB',
        onInterim: (text) => events.push(`interim:${text}`),
        onFinal: (text) => events.push(`final:${text}`),
        onError: (code) => events.push(`error:${code}`),
        onEnd: () => events.push('end'),
      },
      secureWin(true),
    )
    expect(session).not.toBeNull()
    session!.start()
    const rec = FakeRecognition.instances[0]
    expect(rec).toBeDefined()
    expect(rec!.continuous).toBe(true)
    expect(rec!.interimResults).toBe(true)
    expect(rec!.lang).toBe('en-GB')
    expect(rec!.starts).toBe(1)

    rec!.speak(['half a si', false])
    expect(events).toEqual(['interim:half a si'])

    rec!.speak(['half a sentence.', true])
    expect(events).toEqual(['interim:half a si', 'interim:', 'final:half a sentence.'])

    // The user's stop: the service is told, and its late end event is spent.
    session!.stop()
    expect(rec!.stops).toBe(1)
    rec!.end()
    expect(events).toEqual(['interim:half a si', 'interim:', 'final:half a sentence.']) // no 'end'

    // A result that still arrives after the stop changes nothing.
    rec!.speak(['ghost', false])
    expect(events).toHaveLength(3)
  })

  it('a service end (silence timeout, mic lost) reports once and deactivates', () => {
    FakeRecognition.instances = []
    const events: string[] = []
    const session = createDictation(
      { onInterim: (t) => events.push(t), onEnd: () => events.push('end') },
      secureWin(true),
    )
    session!.start()
    const rec = FakeRecognition.instances[0]!
    rec.speak(['still there?', false])
    rec.end()
    expect(events).toEqual(['still there?', 'end'])
    rec.end() // a second end is the platform's noise, not a new event
    expect(events).toHaveLength(2)
    session!.stop() // stop after an external end is a no-op
    expect(rec.stops).toBe(0)
  })

  it('takes the best alternative and walks from resultIndex, finals in order', () => {
    FakeRecognition.instances = []
    const finals: string[] = []
    const interims: string[] = []
    const session = createDictation(
      { onFinal: (t) => finals.push(t), onInterim: (t) => interims.push(t) },
      secureWin(true),
    )
    session!.start()
    const rec = FakeRecognition.instances[0]!
    const first: RecognitionResult = { length: 1, isFinal: true, 0: { transcript: 'one ' } }
    const second: RecognitionResult = {
      length: 2,
      isFinal: false,
      0: { transcript: 'two ' },
      1: { transcript: 'deux ' },
    }
    rec.onresult?.({
      resultIndex: 1,
      results: { length: 3, 0: first, 1: second } as unknown as RecognitionResultList,
    })
    // resultIndex 1: the already-consumed first result is not re-delivered.
    expect(finals).toEqual([])
    expect(interims).toEqual(['two ']) // first alternative wins
    rec.speak(['done.', true], ['now', false])
    expect(finals).toEqual(['done.'])
    expect(interims).toEqual(['two ', 'now'])
  })

  it('error codes reach the handler', () => {
    FakeRecognition.instances = []
    const codes: string[] = []
    createDictation({ onError: (c) => codes.push(c) }, secureWin(true))!.start()
    const rec = FakeRecognition.instances[0]!
    rec.fail('not-allowed')
    rec.fail('no-speech')
    expect(codes).toEqual(['not-allowed', 'no-speech'])
  })

  it('a second start on a live session does not double-start', () => {
    FakeRecognition.instances = []
    const errors: string[] = []
    const session = createDictation({ onError: (c) => errors.push(c) }, secureWin(true))
    session!.start()
    session!.start()
    const rec = FakeRecognition.instances[0]!
    expect(rec.starts).toBe(1)
    expect(errors).toEqual([])
  })

  it('a service that refuses the start reports through onError', () => {
    FakeRecognition.instances = []
    class BusyRecognition extends FakeRecognition {
      override start(): void {
        throw new Error('already running')
      }
    }
    const errors: string[] = []
    const session = createDictation(
      { onError: (c) => errors.push(c) },
      { SpeechRecognition: BusyRecognition, isSecureContext: true },
    )
    session!.start()
    expect(errors).toEqual(['already-running'])
    // The session is dead, so its stop is quiet.
    session!.stop()
    expect(FakeRecognition.instances[0]!.stops).toBe(0)
  })
})

describe('dictationNoticeFor (error mapping)', () => {
  it('names the microphone for not-allowed', () => {
    expect(dictationNoticeFor('not-allowed')).toContain('microphone')
  })

  it('says nothing was heard for no-speech', () => {
    expect(dictationNoticeFor('no-speech')).toContain('Heard nothing')
  })

  it('the user abort is silent — it is the stop button, not a failure', () => {
    expect(dictationNoticeFor('aborted')).toBe('')
  })

  it('every other code names itself', () => {
    expect(dictationNoticeFor('service-not-allowed')).toContain('speech service')
    expect(dictationNoticeFor('audio-capture')).toContain('microphone')
    expect(dictationNoticeFor('network')).toContain('network')
    expect(dictationNoticeFor('already-running')).toContain('already running')
    expect(dictationNoticeFor('something-new')).toBe('Dictation failed (something-new).')
  })
})
