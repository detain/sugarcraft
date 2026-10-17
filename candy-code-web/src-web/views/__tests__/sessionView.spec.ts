import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useSessionStore } from '../../stores/session'
import { Wire } from '../../components/grid/__tests__/harness'
import SessionView from '../SessionView.vue'

let wire: Wire

beforeEach(() => {
  setActivePinia(createPinia())
  wire = new Wire()
  try {
    globalThis.localStorage?.clear()
  } catch {
    // no storage
  }
})

async function openSession(): Promise<ReturnType<typeof mount>> {
  wire = await Wire.connect()
  const session = useSessionStore('s1')
  const subscribed = session.subscribe()
  wire.answer('session.subscribe', {
    reset: true,
    throughSeq: 1,
    pendingAsks: [],
    snapshot: { sessionId: 's1', status: 'idle', lastSeq: 1, pendingAsks: [], queue: [], messages: [] },
  })
  await subscribed
  return mount(SessionView, { props: { id: 's1' }, global: { plugins: [] } })
}

describe('live tail (ISS-108)', () => {
  it('streams as pre-wrap text without an aria-live flood', async () => {
    const wrapper = await openSession()
    wire.event('s1', 'session.status', { status: 'busy' })
    wire.event('s1', 'assistant.delta', { partId: 'p1', offset: 0, text: '# Heading\ntail text' })
    await flushPromises()

    const live = wrapper.find('[data-testid="live-reply"]')
    expect(live.exists()).toBe(true)
    expect(live.attributes('aria-live')).toBeUndefined()
    expect(live.find('pre.live-text').text()).toContain('# Heading')
    expect(wrapper.find('[data-testid="reply-announcement"]').text()).toBe('')
  })

  it('announces once the reply lands as a completed row', async () => {
    const wrapper = await openSession()
    wire.event('s1', 'session.status', { status: 'busy' })
    wire.event('s1', 'assistant.delta', { partId: 'p1', offset: 0, text: 'partial' })
    await flushPromises()
    wire.event('s1', 'assistant.completed', { content: 'the full answer' })
    wire.event('s1', 'session.status', { status: 'idle' })
    await flushPromises()

    expect(wrapper.find('[data-testid="live-reply"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="reply-announcement"]').text()).toBe('Reply complete: the full answer')
  })
})
