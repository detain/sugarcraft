import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'
import { createMemoryHistory, createRouter, RouterView, type Router } from 'vue-router'
import { useLayoutStore } from '../../../stores/layout'
import SessionTabs from '../SessionTabs.vue'
import { ask, summary, Wire } from './harness'

/**
 * P-TABS.h: the trailing `+` on the tab strip starts a session (picker-aware,
 * through the same store the dashboard uses) and routes to it, and a tab
 * whose session finished while it was not the active one carries the
 * unseen-finished badge until the viewer selects it.
 */

const Stub = defineComponent({ render: () => h('div', { class: 'stub' }) })

const Root = defineComponent({ render: () => h('div', [h(SessionTabs), h(RouterView)]) })

function appRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'dashboard', component: Stub },
      { path: '/s/:id', name: 'session', component: Stub },
      { path: '/grid', name: 'grid', component: Stub },
    ],
  })
}

async function setUp(): Promise<{ wire: Wire; router: Router; wrapper: ReturnType<typeof mount> }> {
  const wire = await Wire.connect()
  wire.answer('session.list', { items: [summary('a'), summary('b')], nextCursor: null })
  wire.answer('permission.pending', { items: [] })
  const router = appRouter()
  await router.push('/s/a')
  await router.isReady()
  const wrapper = mount(Root, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wire, router, wrapper }
}

function tabIds(wrapper: ReturnType<typeof mount>): string[] {
  return wrapper.findAll('[data-testid="session-tab"]').map((tab) => tab.attributes('data-session-id') ?? '')
}

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
  document.body.innerHTML = ''
})

describe('session tabs: the + button (P-TABS.h)', () => {
  it('starts a session and routes to its new tab', async () => {
    const { wire, router, wrapper } = await setUp()
    expect(tabIds(wrapper)).toEqual(['a'])

    await wrapper.find('[data-testid="new-session-tab"]').trigger('click')
    await wire.answer('session.create', summary('d'))
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('session')
    expect(router.currentRoute.value.params.id).toBe('d')
    expect(tabIds(wrapper)).toEqual(['a', 'd'])
    expect(useLayoutStore().focused).toBe('d')
  })

  it('routes nowhere when the new session is refused', async () => {
    const { wire, router, wrapper } = await setUp()

    await wrapper.find('[data-testid="new-session-tab"]').trigger('click')
    const frame = wire.sent('session.create').pop()
    if (!frame) throw new Error('no session.create sent')
    wire.socket.fail(-32000, 'server_error', 'no room', frame.id)
    await flushPromises()

    expect(router.currentRoute.value.params.id).toBe('a')
    expect(tabIds(wrapper)).toEqual(['a'])
  })

  it('surfaces a refused new session next to the + button and clears on success', async () => {
    const { wire, wrapper } = await setUp()

    await wrapper.find('[data-testid="new-session-tab"]').trigger('click')
    const frame = wire.sent('session.create').pop()
    if (!frame) throw new Error('no session.create sent')
    wire.socket.fail(-32000, 'server_error', 'no room', frame.id)
    await flushPromises()

    const error = wrapper.find('[data-testid="new-session-error"]')
    expect(error.exists()).toBe(true)
    expect(error.text()).toContain('no room')

    // A following success settles the strip: no leftover error row.
    await wrapper.find('[data-testid="new-session-tab"]').trigger('click')
    await wire.answer('session.create', summary('d'))
    await flushPromises()

    expect(wrapper.find('[data-testid="new-session-error"]').exists()).toBe(false)
    expect(tabIds(wrapper)).toEqual(['a', 'd'])
  })
})

describe('session tabs: the unseen badge (P-TABS.h)', () => {
  it('appears when a background session finishes and clears on select', async () => {
    const { wire, router, wrapper } = await setUp()
    useLayoutStore().openTab('b')
    await flushPromises()

    expect(wrapper.find('[data-testid="tab-unseen"]').exists()).toBe(false)

    wire.serverEvent('session.updated', summary('b', { status: 'busy' }))
    await flushPromises()
    wire.serverEvent('session.updated', summary('b', { status: 'idle' }))
    await flushPromises()

    const tabB = wrapper.find('[data-testid="session-tab"][data-session-id="b"]')
    expect(tabB.find('[data-testid="tab-unseen"]').exists()).toBe(true)
    // UX-14: the labelled mark is a real image role for assistive tech.
    expect(tabB.find('[data-testid="tab-unseen"]').attributes('role')).toBe('img')
    // The focused tab never carries it.
    expect(wrapper.find('[data-testid="session-tab"][data-session-id="a"] [data-testid="tab-unseen"]').exists()).toBe(false)

    await tabB.find('.pick').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('b')
    expect(wrapper.find('[data-testid="tab-unseen"]').exists()).toBe(false)
    expect(useLayoutStore().unseen).toEqual({})
  })

  it('keeps the ask badge in front of the unseen mark on the same tab', async () => {
    const { wire, wrapper } = await setUp()
    useLayoutStore().openTab('b')
    await flushPromises()

    wire.serverEvent('permission.asked', ask('k1', 'b'))
    await flushPromises()
    wire.serverEvent('session.updated', summary('b', { status: 'busy' }))
    await flushPromises()
    wire.serverEvent('session.updated', summary('b', { status: 'idle' }))
    await flushPromises()

    // The finish was marked — the store still carries the debt...
    expect(useLayoutStore().unseen['b']).toBe(true)
    // ...but a pending question outranks the unseen mark on the strip.
    const tabB = wrapper.find('[data-testid="session-tab"][data-session-id="b"]')
    expect(tabB.find('[data-testid="tab-ask-badge"]').exists()).toBe(true)
    expect(tabB.find('[data-testid="tab-unseen"]').exists()).toBe(false)
  })
})

/**
 * UX-13: the tab status dot is colour-only decoration (aria-hidden);
 * assistive tech must get the same state as a visually-hidden word.
 */
describe('session tabs: the status dot word (UX-13)', () => {
  it('renders the session state as assistive text beside the hidden dot', async () => {
    const { wire, wrapper } = await setUp()
    wire.serverEvent('session.updated', summary('a', { status: 'busy' }))
    await flushPromises()
    const tabA = wrapper.find('[data-testid="session-tab"][data-session-id="a"]')
    expect(tabA.find('.dot').attributes('aria-hidden')).toBe('true')
    expect(tabA.find('.sr-only').text()).toBe('working')
    wire.serverEvent('session.updated', summary('a', { status: 'idle' }))
    await flushPromises()
    expect(tabA.find('.sr-only').text()).toBe('idle')
  })

  it('gives the neutral closed dot no state word', async () => {
    const { wire, wrapper } = await setUp()
    useLayoutStore().openTab('b')
    await flushPromises()
    wire.serverEvent('session.updated', summary('b', { status: 'closed' }))
    await flushPromises()
    const tabB = wrapper.find('[data-testid="session-tab"][data-session-id="b"]')
    expect(tabB.find('.dot').exists()).toBe(true)
    expect(tabB.find('.sr-only').exists()).toBe(false)
  })
})

/**
 * UXB-F3: the tab strip scrolls horizontally on narrow viewports, and
 * Firefox/Safari only give keyboard scroll to focusable overflow regions.
 */
describe('session tabs: keyboard scroll affordance (UXB-F3)', () => {
  it('makes the scrollable tab strip focusable', async () => {
    const { wrapper } = await setUp()
    const tabs = wrapper.find('.tabs')
    expect(tabs.attributes('tabindex')).toBe('0')
    expect(tabs.attributes('role')).toBe('tablist')
  })
})
