import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import Composer from '../../components/Composer.vue'
import { useConnectionStore } from '../../stores/connection'
import { helloResult } from '../../__tests__/fakes'

// The composer's sessionId rides the routed session id; the mock below is
// the single knob (C-TUI-6.n voice legs share the shape of
// composerDictation.spec.ts's planted window fakes).
const mocks = vi.hoisted(() => ({ routeParams: { id: 'ses-voice-test' } as Record<string, string> }))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: mocks.routeParams }) }))

interface RecInstance {
  state: string
  started: number
  stops: number
  requestedMime: string | undefined
  ondataavailable: ((event: BlobEvent) => void) | null
  onstop: (() => void) | null
  start(): void
  stop(): void
  emit(bytes: number): void
}

class FakeMediaRecorder {
  static supported: string[] = ['audio/webm;codecs=opus']
  static instances: FakeMediaRecorder[] = []

  static isTypeSupported(mime: string): boolean {
    return FakeMediaRecorder.supported.includes(mime)
  }

  state = 'inactive'
  started = 0
  stops = 0
  requestedMime: string | undefined
  ondataavailable: ((event: BlobEvent) => void) | null = null
  onstop: (() => void) | null = null

  constructor(_stream: MediaStream, options?: { mimeType?: string }) {
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
    this.onstop?.()
  }

  emit(bytes: number): void {
    this.ondataavailable?.({ data: new Blob([new Uint8Array(bytes)]) } as BlobEvent)
  }
}

class FakeRecognition {
  static instances: FakeRecognition[] = []
  starts = 0
  continuous = false
  interimResults = false
  lang = ''
  onresult: ((event: never) => void) | null = null
  onerror: ((event: never) => void) | null = null
  onend: (() => void) | null = null

  constructor() {
    FakeRecognition.instances.push(this)
  }

  start(): void {
    this.starts++
  }

  stop(): void {
    /* the voice suite never needs late events */
  }
}

function plantRecorder(): void {
  Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true, writable: true })
  Object.defineProperty(window, 'MediaRecorder', { value: FakeMediaRecorder, configurable: true, writable: true })
  Object.defineProperty(window.navigator, 'mediaDevices', {
    value: {
      getUserMedia: () => Promise.resolve({ getTracks: () => [{ stop(): void {} }] } as unknown as MediaStream),
    },
    configurable: true,
    writable: true,
  })
}

function plantSpeech(): void {
  Object.defineProperty(window, 'SpeechRecognition', { value: FakeRecognition, configurable: true, writable: true })
}

function sweepWindow(): void {
  Reflect.deleteProperty(window, 'MediaRecorder')
  Reflect.deleteProperty(window.navigator, 'mediaDevices')
  Reflect.deleteProperty(window, 'SpeechRecognition')
  Reflect.deleteProperty(window, 'isSecureContext')
}

function plantHello(media: Record<string, unknown> | undefined): void {
  const connection = useConnectionStore()
  connection.hello = helloResult({
    features: { methods: [], events: [], ...(media === undefined ? {} : { media }) },
  }) as never
}

async function mountComposer() {
  const wrapper = mount(Composer, {
    props: { modelValue: '', busy: false, commands: [] },
  })
  await flushPromises()
  return wrapper
}

const VOICE_TITLE = 'Dictate — the audio is transcribed by the voice endpoint you configured.'

describe('composer voice engine routing (server dictation)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    FakeMediaRecorder.instances = []
    FakeMediaRecorder.supported = ['audio/webm;codecs=opus']
    FakeRecognition.instances = []
    mocks.routeParams.id = 'ses-voice-test'
    plantRecorder()
    plantSpeech()
  })

  afterEach(() => {
    sweepWindow()
    vi.useRealTimers()
  })

  it('prefers the server door: a click records, and the text rides update:modelValue — never send', async () => {
    plantHello({ image: false, video: false, transcribe: true, baseUrlHostOnly: null })
    const connection = useConnectionStore()
    const requests: { method: string; params: Record<string, unknown> }[] = []
    vi.spyOn(connection, 'request').mockImplementation(async (method: string, params: Record<string, unknown>) => {
      requests.push({ method, params })
      return method === 'audio.transcribe' ? { jobId: 'a'.repeat(16) } : { state: 'done', text: 'server words' }
    })

    const wrapper = await mountComposer()
    const mic = wrapper.get('[data-testid="composer-mic"]')
    expect(mic.attributes('title')).toBe(VOICE_TITLE)

    await mic.trigger('click')
    await flushPromises()
    expect(FakeMediaRecorder.instances).toHaveLength(1)
    expect(FakeMediaRecorder.instances[0]!.started).toBe(1)
    expect(FakeRecognition.instances).toHaveLength(0) // the browser engine stays untouched

    FakeMediaRecorder.instances[0]!.emit(12)
    await mic.trigger('click') // stop-and-submit

    // The poll arms a real 500 ms timer; waitFor rides it honestly.
    await vi.waitFor(() => expect(requests.some((r) => r.method === 'audio.status')).toBe(true), { timeout: 5000 })
    await flushPromises()

    const transcribe = requests.find((r) => r.method === 'audio.transcribe')
    expect(transcribe).toBeDefined()
    expect(transcribe!.params.sessionId).toBe('ses-voice-test')
    expect(transcribe!.params.mime).toBe('audio/webm')
    expect(typeof transcribe!.params.audioBase64).toBe('string')
    expect((transcribe!.params.audioBase64 as string).length).toBeGreaterThan(0)
    const status = requests.find((r) => r.method === 'audio.status')
    expect(status!.params.jobId).toBe('a'.repeat(16))

    // Inserted into the draft, not sent: the transcript lands as a value
    // update, and no send ever leaves the component.
    const updates = wrapper.emitted('update:modelValue') ?? []
    expect(updates.map((u) => u[0])).toContain('server words')
    expect(wrapper.emitted('send')).toBeUndefined()
  })

  it('falls to the browser engine when the server door is shut, title and all', async () => {
    plantHello({ image: false, video: false, baseUrlHostOnly: null }) // no transcribe key at all
    const wrapper = await mountComposer()
    const mic = wrapper.get('[data-testid="composer-mic"]')
    expect(mic.attributes('title')).not.toBe(VOICE_TITLE)

    await mic.trigger('click')
    await flushPromises()
    expect(FakeRecognition.instances).toHaveLength(1) // the C-TUI-6.k path still works
    expect(FakeMediaRecorder.instances).toHaveLength(0) // the recorder never opens
  })

  it('a dead recorder degrades to the browser engine instead of lying', async () => {
    // canTranscribe true but no MediaRecorder on this window: pickEngine
    // answers browser, and the click proves it — no server door is faked.
    Reflect.deleteProperty(window, 'MediaRecorder')
    plantHello({ image: false, video: false, transcribe: true, baseUrlHostOnly: null })
    const connection = useConnectionStore()
    const requestSpy = vi.spyOn(connection, 'request').mockImplementation(async () => {
      throw new Error('must not dial')
    })

    const wrapper = await mountComposer()
    await wrapper.get('[data-testid="composer-mic"]').trigger('click')
    await flushPromises()
    expect(FakeRecognition.instances).toHaveLength(1)
    expect(requestSpy).not.toHaveBeenCalled()
  })

  it('without a routed session the door refuses loudly and files nothing', async () => {
    delete mocks.routeParams.id
    plantHello({ image: false, video: false, transcribe: true, baseUrlHostOnly: null })
    const connection = useConnectionStore()
    const requestSpy = vi.spyOn(connection, 'request').mockImplementation(async () => {
      throw new Error('must not dial')
    })

    const wrapper = await mountComposer()
    await wrapper.get('[data-testid="composer-mic"]').trigger('click')
    await flushPromises()
    expect(requestSpy).not.toHaveBeenCalled()
    expect(FakeMediaRecorder.instances).toHaveLength(0)
    expect(wrapper.text()).toContain('Open a session before dictating.')
  })

  it('a failed job lands a notice and inserts nothing', async () => {
    plantHello({ image: false, video: false, transcribe: true, baseUrlHostOnly: null })
    const connection = useConnectionStore()
    vi.spyOn(connection, 'request').mockImplementation(async (method: string) =>
      method === 'audio.transcribe' ? { jobId: 'b'.repeat(16) } : { state: 'failed', error: 'endpoint down' },
    )

    const wrapper = await mountComposer()
    const mic = wrapper.get('[data-testid="composer-mic"]')
    await mic.trigger('click')
    await flushPromises()
    FakeMediaRecorder.instances[0]!.emit(5)
    await mic.trigger('click')
    await vi.waitFor(() => expect(wrapper.text()).toContain('Voice transcription failed.'), { timeout: 5000 })
    await flushPromises()

    const updates = wrapper.emitted('update:modelValue') ?? []
    expect(updates.map((u) => u[0])).not.toContain(undefined)
    for (const [value] of updates) expect(value).not.toContain('endpoint down')
    expect(wrapper.text()).toContain('Voice transcription failed.')
  })

  it('the mic hides itself entirely when neither engine works', async () => {
    // No hello at all (no server door) and no browser engine: canDictate
    // answers none — the button must not exist to be clicked.
    Reflect.deleteProperty(window, 'MediaRecorder')
    Reflect.deleteProperty(window, 'SpeechRecognition')
    plantHello(undefined)

    const wrapper = await mountComposer()
    expect(wrapper.find('[data-testid="composer-mic"]').exists()).toBe(false)
  })
})
