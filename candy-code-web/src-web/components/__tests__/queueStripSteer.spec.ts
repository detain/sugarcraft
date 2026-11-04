/**
 * CW-EN-EU02 (paint half): a steered prompt must flash where the user watches
 * steering happen — the queue strip. QueueStrip gains an optional `flashKey`:
 * when SessionView sees a new steer-notice key, a one-shot fade plays.
 * The strip itself keeps its queue-driven v-if; the flash is its own node so
 * an empty queue shows the flash without empty-queue chrome.
 *
 * Unit mounts prove visibility shape; the remount trigger and the animation
 * live in CSS happy-dom cannot compute, so they sit pinned as anchored
 * source-scan legs (the house genre for layout/mechanism that the DOM stub
 * cannot measure — substring legs would pass vacuously).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import QueueStrip from '../QueueStrip.vue'
import SessionView from '../../views/SessionView.vue'
import { useSessionStore } from '../../stores/session'
import { Wire } from '../grid/__tests__/harness'
import type { QueueEntry } from '../../protocol/generated'

const entry: QueueEntry = { queueId: 'q1', text: 'later prompt', position: 2 }

function openStrip(props: Record<string, unknown>) {
  return mount(QueueStrip, { props: { queue: [], ...props } })
}

describe('QueueStrip steer flash (CW-EN-EU02)', () => {
  it('shows the flash with an empty queue and no strip chrome', () => {
    const wrapper = openStrip({ flashKey: 'steered:st1' })
    expect(wrapper.find('[data-testid="steer-flash"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="steer-flash"]').text()).toBe('⇥')
    expect(wrapper.find('.strip').exists()).toBe(false)
  })

  it('renders nothing at all when idle — no queue, no flash', () => {
    const wrapper = openStrip({})
    expect(wrapper.find('.strip').exists()).toBe(false)
    expect(wrapper.find('[data-testid="steer-flash"]').exists()).toBe(false)
  })

  it('flashes alongside a live queue', () => {
    const wrapper = openStrip({ queue: [entry], flashKey: 'steered:st1' })
    expect(wrapper.find('.strip').exists()).toBe(true)
    expect(wrapper.find('[data-testid="steer-flash"]').exists()).toBe(true)
  })
})

describe('steer flash mechanism (source scan)', () => {
  const stripSrc = readFileSync(resolve(process.cwd(), 'src-web/components/QueueStrip.vue'), 'utf8')
  const viewSrc = readFileSync(resolve(process.cwd(), 'src-web/views/SessionView.vue'), 'utf8')

  it('the flash node is keyed by flashKey so a new steer remounts and restarts the fade', () => {
    expect(/<p[^>]*v-if="flashKey"[^>]*:key="flashKey"/.test(stripSrc) || /:key="flashKey"[^>]*v-if="flashKey"/.test(stripSrc)).toBe(true)
    expect(/animation:\s*steer-fade/.test(stripSrc)).toBe(true)
    expect(/@keyframes steer-fade \{[^}]*\n\s*from \{ opacity: 1; \}[^}]*\n\s*to \{ opacity: 0; \}/s.test(stripSrc)).toBe(true)
  })

  it('SessionView derives the newest steer-notice key and feeds it as flash-key', () => {
    expect(/const lastSteerKey = computed<string \| null>\(\(\) => \{/.test(viewSrc)).toBe(true)
    expect(/kind === 'notice' && item\.key\.startsWith\('steered:'\)/.test(viewSrc)).toBe(true)
    expect(/<QueueStrip[^>]*:flash-key="lastSteerKey"/.test(viewSrc)).toBe(true)
  })
})

describe('steer flash end to end (Wire)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    try {
      globalThis.localStorage?.clear()
    } catch {
      // no storage
    }
  })

  it('a live turn.steered event paints the flash in the dock', async () => {
    const wire = await Wire.connect()
    const session = useSessionStore('s1')
    const subscribed = session.subscribe()
    wire.answer('session.subscribe', {
      reset: true,
      throughSeq: 0,
      pendingAsks: [],
      snapshot: { sessionId: 's1', status: 'idle', lastSeq: 0, pendingAsks: [], queue: [], messages: [] },
    })
    await subscribed
    const wrapper = mount(SessionView, { props: { id: 's1' }, global: { plugins: [] } })
    expect(wrapper.find('[data-testid="steer-flash"]').exists()).toBe(false)

    wire.event('s1', 'turn.steered', { steerId: 'st1' })
    await flushPromises()
    expect(wrapper.find('[data-testid="steer-flash"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="transcript-empty"]').exists()).toBe(false)
  })
})
