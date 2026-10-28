import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import Composer from '../Composer.vue'
import type { RecognitionErrorEvent, RecognitionResultEvent } from '../../lib/dictation'

/**
 * C-TUI-6.k pins for the composer's dictation. The component reads
 * `window` at setup, so each test plants (or does not plant) the
 * SpeechRecognition constructor on the real happy-dom window and removes
 * it again — feature detection itself is covered in lib/dictation.spec.ts.
 */

class FakeRecognition {
  static last: FakeRecognition | null = null

  lang = ''
  continuous = false
  interimResults = false
  starts = 0
  stops = 0
  onresult: ((event: RecognitionResultEvent) => void) | null = null
  onerror: ((event: RecognitionErrorEvent) => void) | null = null
  onend: (() => void) | null = null

  constructor() {
    FakeRecognition.last = this
  }

  start(): void {
    this.starts++
  }

  stop(): void {
    this.stops++
  }

  speak(transcript: string, isFinal: boolean): void {
    this.onresult?.({
      resultIndex: 0,
      results: { length: 1, 0: { length: 1, isFinal, 0: { transcript } } },
    })
  }

  fail(error: string): void {
    this.onerror?.({ error })
  }

  end(): void {
    this.onend?.()
  }
}

/** A recognition whose start() dies on the spot (Chrome's InvalidStateError). */
class ThrowingRecognition extends FakeRecognition {
  override start(): void {
    throw new Error('InvalidStateError')
  }
}

function plantDictation(): void {
  Object.defineProperty(window, 'SpeechRecognition', { value: FakeRecognition, configurable: true })
  Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true })
}

function sweepDictation(): void {
  Reflect.deleteProperty(window, 'SpeechRecognition')
  Reflect.deleteProperty(window, 'isSecureContext')
}

function mountComposer(props: Record<string, unknown> = {}) {
  return mount(Composer, { props: { modelValue: '', busy: false, commands: [], ...props } })
}

afterEach(() => {
  sweepDictation()
  FakeRecognition.last = null
})

describe('Composer dictation (C-TUI-6.k)', () => {
  it('hides the mic where dictation is unavailable (Firefox, plain-http serve)', () => {
    const wrapper = mountComposer()
    expect(wrapper.find('[data-testid="composer-mic"]').exists()).toBe(false)
  })

  it('hides the mic on a plain-http (insecure) page that still has the constructor', () => {
    Object.defineProperty(window, 'SpeechRecognition', { value: FakeRecognition, configurable: true })
    const wrapper = mountComposer()
    expect(wrapper.find('[data-testid="composer-mic"]').exists()).toBe(false)
  })

  it('toggles the session: one instance, started once, stopped on the second click and on Esc', async () => {
    plantDictation()
    const wrapper = mountComposer()
    const mic = wrapper.find('[data-testid="composer-mic"]')
    expect(mic.exists()).toBe(true)
    expect(mic.attributes('aria-pressed')).toBe('false')

    await mic.trigger('click')
    const rec = FakeRecognition.last!
    expect(rec.starts).toBe(1)
    expect(mic.attributes('aria-pressed')).toBe('true')
    expect(rec.continuous).toBe(true)
    expect(rec.interimResults).toBe(true)

    await wrapper.find('[data-testid="composer-mic"]').trigger('click')
    expect(rec.stops).toBe(1)
    expect(wrapper.find('[data-testid="composer-mic"]').attributes('aria-pressed')).toBe('false')

    // Esc stops a live session and is its own key while it runs.
    await wrapper.find('[data-testid="composer-mic"]').trigger('click')
    const second = FakeRecognition.last!
    expect(second).not.toBe(rec) // a fresh session per toggle
    expect(second.starts).toBe(1)
    await wrapper.find('[data-testid="composer-input"]').trigger('keydown', { key: 'Escape' })
    expect(second.stops).toBe(1)
    expect(wrapper.emitted('stop')).toBeUndefined() // not the busy-turn stop
  })

  it('shows interim text as a hint under the textarea, cleared when the utterance settles', async () => {
    plantDictation()
    const wrapper = mountComposer()
    await wrapper.find('[data-testid="composer-mic"]').trigger('click')
    const rec = FakeRecognition.last!
    expect(wrapper.find('[data-testid="composer-dictation-hint"]').exists()).toBe(false)

    rec.speak('typing with my voi', false)
    await wrapper.vm.$nextTick()
    const hint = wrapper.find('[data-testid="composer-dictation-hint"]')
    expect(hint.exists()).toBe(true)
    expect(hint.text()).toBe('typing with my voi')
    // Interim revisions are hint-only: the draft is never touched by them.
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()

    rec.speak('typing with my voice.', true)
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="composer-dictation-hint"]').exists()).toBe(false)
  })

  it('inserts the final text at the caret and never sends', async () => {
    plantDictation()
    const wrapper = mountComposer({ modelValue: 'ab' })
    const area = wrapper.find('[data-testid="composer-input"]').element as HTMLTextAreaElement
    area.setSelectionRange(1, 1)
    await wrapper.find('[data-testid="composer-mic"]').trigger('click')
    FakeRecognition.last!.speak('X', true)
    await wrapper.vm.$nextTick()
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['aXb'])
    expect(wrapper.emitted('send')).toBeUndefined()
  })

  it('maps a service error to the inline notice', async () => {
    plantDictation()
    const wrapper = mountComposer()
    await wrapper.find('[data-testid="composer-mic"]').trigger('click')
    FakeRecognition.last!.fail('not-allowed')
    await wrapper.vm.$nextTick()
    const notice = wrapper.find('[data-testid="composer-dictation-notice"]')
    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('microphone')
  })

  it('a service-side end drops the listening state; the privacy is in the title', async () => {
    plantDictation()
    const wrapper = mountComposer()
    const mic = wrapper.find('[data-testid="composer-mic"]')
    expect(mic.attributes('title')).toContain('browser')
    expect(mic.attributes('title')).toContain('candy-code server')
    await mic.trigger('click')
    FakeRecognition.last!.end()
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="composer-mic"]').attributes('aria-pressed')).toBe('false')
  })

  it('unmounting the composer stops a live session (no orphaned recognition)', async () => {
    plantDictation()
    const wrapper = mountComposer()
    await wrapper.find('[data-testid="composer-mic"]').trigger('click')
    const rec = FakeRecognition.last!
    expect(rec.starts).toBe(1)
    wrapper.unmount()
    expect(rec.stops).toBe(1)
  })

  it('a start that dies on the spot leaves the mic unlit with the notice', async () => {
    plantDictation()
    Object.defineProperty(window, 'SpeechRecognition', { value: ThrowingRecognition, configurable: true })
    const wrapper = mountComposer()
    await wrapper.find('[data-testid="composer-mic"]').trigger('click')
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="composer-mic"]').attributes('aria-pressed')).toBe('false')
    expect(wrapper.find('[data-testid="composer-dictation-notice"]').text()).toContain('already running')
  })
})
