import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'
import { createMemoryHistory, createRouter, RouterView, type Router } from 'vue-router'
import { useLayoutStore } from '../../../stores/layout'
import PaneGrid from '../PaneGrid.vue'
import SessionTabs from '../SessionTabs.vue'
import { ask, summary, Wire } from './harness'

const Stub = defineComponent({ render: () => h('div', { class: 'stub' }) })

const Root = defineComponent({ render: () => h('div', [h(SessionTabs), h(RouterView)]) })

function appRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'dashboard', component: Stub },
      { path: '/s/:id', name: 'session', component: Stub },
      { path: '/grid', name: 'grid', component: PaneGrid },
    ],
  })
}

/** Answer the newest `session.subscribe` for $sessionId with an empty snapshot. */
function subscribe(wire: Wire, sessionId: string, status = 'idle'): void {
  const frame = wire.sent('session.subscribe').filter((f) => (f.params as Record<string, unknown>).sessionId === sessionId).pop()
  if (!frame) throw new Error(`no subscribe for ${sessionId}`)
  wire.socket.answer({
    reset: true,
    throughSeq: 0,
    pendingAsks: [],
    snapshot: { sessionId, status, lastSeq: 0, pendingAsks: [], queue: [], messages: [{ id: `m-${sessionId}`, role: 'user', content: `hello from ${sessionId}` }] },
  }, frame.id)
}

function viewing(wire: Wire): unknown {
  return wire.sent('client.viewing').pop()?.params
}

async function setUp(): Promise<{ wire: Wire; router: Router; wrapper: ReturnType<typeof mount> }> {
  const wire = await Wire.connect()
  wire.answer('session.list', { items: [summary('a'), summary('b'), summary('c')], nextCursor: null })
  wire.answer('permission.pending', { items: [] })
  const router = appRouter()
  await router.push('/s/a')
  await router.isReady()
  const wrapper = mount(Root, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wire, router, wrapper }
}

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
  document.body.innerHTML = ''
})

describe('tabs', () => {
  it('opening a session opens its tab and tells the server it is in front, streamed', async () => {
    const { wire, wrapper } = await setUp()
    expect(wrapper.findAll('[data-testid="session-tab"]').map((tab) => tab.attributes('data-session-id'))).toEqual(['a'])
    expect(wrapper.find('[data-testid="session-tab"]').attributes('aria-selected')).toBe('true')
    expect(viewing(wire)).toEqual({ sessionIds: ['a'], foreground: 'a', narrate: false })
  })

  it('a tab counts its session’s open questions, and closing the open tab fronts its neighbour', async () => {
    const { wire, router, wrapper } = await setUp()
    await router.push('/s/b')
    await flushPromises()
    wire.serverEvent('permission.asked', ask('k1', 'a'))
    await flushPromises()
    const tabA = wrapper.find('[data-testid="session-tab"][data-session-id="a"]')
    expect(tabA.find('[data-testid="tab-ask-badge"]').text()).toBe('1')

    await wrapper.find('[data-testid="session-tab"][data-session-id="b"] [data-testid="tab-close"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('a')
    expect(useLayoutStore().tabs).toEqual(['a'])
  })

  it('Alt+digit picks a tab', async () => {
    const { router } = await setUp()
    await router.push('/s/b')
    await flushPromises()
    window.dispatchEvent(new KeyboardEvent('keydown', { key: '1', altKey: true }))
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('a')
  })
})

describe('grid', () => {
  it('tiles the open sessions; the focused one streams and the rest are narrated', async () => {
    const { wire, router, wrapper } = await setUp()
    useLayoutStore().openTab('b')
    await wrapper.find('[data-testid="layout-grid"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('grid')

    const tiles = wrapper.findAll('[data-testid="grid-tile"]')
    expect(tiles.map((tile) => tile.attributes('data-session-id'))).toEqual(['a', 'b'])
    expect(tiles.map((tile) => tile.attributes('data-focused'))).toEqual(['true', 'false'])
    expect(wrapper.find('[data-testid="pane-grid"]').attributes('data-columns')).toBe('2')
    expect(viewing(wire)).toEqual({ sessionIds: ['a', 'b'], foreground: 'a', narrate: true })

    subscribe(wire, 'a')
    subscribe(wire, 'b', 'busy')
    await flushPromises()
    expect(wrapper.find('[data-testid="grid-tile"][data-session-id="b"]').text()).toContain('you ▸ hello from b')

    // The server narrates b: its tail shows in the tile.
    wire.event('b', 'assistant.narration', { partId: 't1:text', tail: 'migrating the pool', offset: 0 }, false)
    await flushPromises()
    expect(wrapper.find('[data-testid="grid-tile"][data-session-id="b"] [data-testid="tile-live"]').text()).toBe('migrating the pool')

    // Focusing b brings it to the front.
    await wrapper.find('[data-testid="grid-tile"][data-session-id="b"]').trigger('mousedown')
    await flushPromises()
    expect(viewing(wire)).toEqual({ sessionIds: ['a', 'b'], foreground: 'b', narrate: true })
    expect(wrapper.find('[data-testid="grid-tile"][data-session-id="b"]').attributes('data-focused')).toBe('true')
  })

  it('answers a tile’s question in place and steers a busy tile', async () => {
    const { wire, wrapper } = await setUp()
    await wrapper.find('[data-testid="layout-grid"]').trigger('click')
    await flushPromises()
    subscribe(wire, 'a', 'busy')
    await flushPromises()

    wire.event('a', 'permission.requested', ask('k9', 'a'))
    await flushPromises()
    expect(wrapper.find('[data-testid="tile-ask-badge"]').text()).toBe('1')
    await wrapper.find('[data-testid="grid-tile"] [data-testid="ask-once"]').trigger('click')
    expect(wire.sent('permission.respond').pop()?.params).toMatchObject({ sessionId: 'a', askId: 'k9', reply: 'once' })

    const input = wrapper.find('[data-testid="tile-input"]')
    expect(input.attributes('placeholder')).toBe('type to steer…')
    await input.setValue('also add a test')
    await wrapper.find('[data-testid="grid-tile"] form').trigger('submit')
    expect(wire.sent('session.send').pop()?.params).toMatchObject({ sessionId: 'a', text: 'also add a test', delivery: 'steer' })
  })

  it('opens another session into the grid, closes a tile, and maximizes one into a tab', async () => {
    const { router, wrapper } = await setUp()
    await wrapper.find('[data-testid="layout-grid"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="grid-pick"]').setValue('c')
    await wrapper.find('[data-testid="grid-add"] form').trigger('submit')
    await flushPromises()
    expect(wrapper.findAll('[data-testid="grid-tile"]').map((tile) => tile.attributes('data-session-id'))).toEqual(['a', 'c'])
    expect(wrapper.find('[data-testid="grid-tile"][data-session-id="c"]').attributes('data-focused')).toBe('true')

    await wrapper.find('[data-testid="grid-tile"][data-session-id="a"] [data-testid="tile-close"]').trigger('click')
    await flushPromises()
    expect(wrapper.findAll('[data-testid="grid-tile"]').map((tile) => tile.attributes('data-session-id'))).toEqual(['c'])

    await wrapper.find('[data-testid="tile-maximize"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('session')
    expect(router.currentRoute.value.params.id).toBe('c')
  })
})
