import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import { createAppRouter } from '../../router'
import { useSessionStore } from '../../stores/session'
import { Wire } from '../../stores/agents/__tests__/harness'
import Composer from '../Composer.vue'

/**
 * C-CTX-2.d: the served `prompt.suggested` event renders as a grey ghost in an
 * EMPTY draft and is accepted by → (or Tab, the TUI's chord). The offer is
 * live-only: it never becomes a transcript item, a fresh `turn.started`
 * retires it, and a draft with text in it is not overwritten by an arrow.
 */

let wire: Wire

const INPUT = '[data-testid="composer-input"]'

function placeholderOf(wrapper: ReturnType<typeof mount>): string {
  const el = wrapper.find(INPUT).attributes('placeholder')
  if (el === undefined) throw new Error('composer has no placeholder')
  return el
}

async function liveSession(id: string) {
  await wire.connect()
  const session = useSessionStore(id)
  const subscribed = session.subscribe()
  await flushPromises()
  wire.socket().answer(
    {
      reset: true,
      throughSeq: 0,
      pendingAsks: [],
      snapshot: { sessionId: id, status: 'idle', lastSeq: 0, pendingAsks: [], queue: [], messages: [] },
    },
    wire.sent('session.subscribe').pop()?.id as string,
  )
  await subscribed
  return session
}

async function mountAt(id: string) {
  const router = createAppRouter(createMemoryHistory())
  await router.push({ name: 'session', params: { id } })
  await router.isReady()
  return mount(Composer, {
    props: { modelValue: '', busy: false, commands: [] },
    global: { plugins: [router] },
  })
}

beforeEach(() => {
  setActivePinia(createPinia())
  wire = new Wire()
})

describe('composer prompt suggestion (C-CTX-2.d)', () => {
  it('the offer greys an empty draft and never becomes a transcript row', async () => {
    const session = await liveSession('sA')
    const wrapper = await mountAt('sA')
    await flushPromises()
    expect(placeholderOf(wrapper)).toContain('Message CandyCode')

    wire.event('sA', 'prompt.suggested', { text: 'Ship the release', generation: 1 }, false)
    await flushPromises()
    await wrapper.vm.$nextTick()

    expect(session.state.items).toHaveLength(0)
    expect(session.state.suggestedPrompt).toBe('Ship the release')
    expect(placeholderOf(wrapper)).toBe('Ship the release')
  })

  it('ArrowRight accepts the offer into the draft and retires it exactly once', async () => {
    const session = await liveSession('sA')
    const wrapper = await mountAt('sA')
    wire.event('sA', 'prompt.suggested', { text: 'Run the tests', generation: 2 }, false)
    await flushPromises()
    await wrapper.vm.$nextTick()

    await wrapper.find(INPUT).trigger('keydown', { key: 'ArrowRight' })
    expect(wrapper.emitted('update:modelValue')).toEqual([['Run the tests']])
    expect(session.state.suggestedPrompt).toBeNull()
    await wrapper.vm.$nextTick()
    expect(placeholderOf(wrapper)).toContain('Message CandyCode')

    // The offer is spent: a second arrow with the draft still empty does nothing.
    await wrapper.find(INPUT).trigger('keydown', { key: 'ArrowRight' })
    expect(wrapper.emitted('update:modelValue')).toHaveLength(1)
  })

  it('Tab accepts too, and a started turn retires an untouched offer', async () => {
    const session = await liveSession('sA')
    const wrapper = await mountAt('sA')
    wire.event('sA', 'prompt.suggested', { text: 'Push the branch', generation: 3 }, false)
    await flushPromises()
    await wrapper.vm.$nextTick()

    await wrapper.find(INPUT).trigger('keydown', { key: 'Tab' })
    expect(wrapper.emitted('update:modelValue')).toEqual([['Push the branch']])

    wire.event('sA', 'prompt.suggested', { text: 'Open a draft PR', generation: 4 }, false)
    await flushPromises()
    expect(session.state.suggestedPrompt).toBe('Open a draft PR')
    wire.event('sA', 'turn.started', { turnId: 't1' }, true)
    await flushPromises()
    await wrapper.vm.$nextTick()
    expect(session.state.suggestedPrompt).toBeNull()
    expect(placeholderOf(wrapper)).toContain('Message CandyCode')
  })

  it('a draft with text is not overwritten by the accept chord', async () => {
    await liveSession('sA')
    const wrapper = await mountAt('sA')
    wire.event('sA', 'prompt.suggested', { text: 'Ship it', generation: 1 }, false)
    await flushPromises()
    await wrapper.setProps({ modelValue: 'half-typed' })
    await wrapper.vm.$nextTick()

    await wrapper.find(INPUT).trigger('keydown', { key: 'ArrowRight' })
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('a mount without a routed session never shows an offer', async () => {
    await wire.connect()
    const wrapper = mount(Composer, { props: { modelValue: '', busy: false, commands: [] } })
    wire.event('sA', 'prompt.suggested', { text: 'Ship the release', generation: 1 }, false)
    await flushPromises()
    await wrapper.vm.$nextTick()

    expect(placeholderOf(wrapper)).toContain('Message CandyCode')
    await wrapper.find(INPUT).trigger('keydown', { key: 'ArrowRight' })
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })
})
