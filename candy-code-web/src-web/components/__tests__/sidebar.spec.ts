import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import { flush } from '../../__tests__/fakes'
import { createAppRouter } from '../../router'
import { useSessionsStore } from '../../stores/sessions'
import { Wire } from '../grid/__tests__/harness'
import SessionSidebar from '../SessionSidebar.vue'

let router: ReturnType<typeof createAppRouter>

/** Let the pushed navigation settle. */
async function settle(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0))
}

async function mountSidebar(ids: string[]) {
  await Wire.connect()
  const sessions = useSessionsStore()
  sessions.byId = Object.fromEntries(
    ids.map((id, index) => [id, { id, name: `session ${index}`, status: 'idle', open: true, updatedAt: `2026-10-17 00:00:0${ids.length - index}`, createdAt: '', permissionMode: 'default', root: '/repo', contextTokens: 0, contextWindow: 0 }]),
  )
  router = createAppRouter(createMemoryHistory())
  await router.push({ name: 'session', params: { id: ids[0] ?? 'x' } })
  await router.isReady()
  const wrapper = mount(SessionSidebar, {
    props: { activeId: ids[0] ?? null },
    global: { plugins: [router] },
  })
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('sidebar j/k (ISS-053)', () => {
  it('moves between sessions when the list itself has the keys', async () => {
    const wrapper = await mountSidebar(['s1', 's2', 's3'])
    await wrapper.find('nav').trigger('keydown', { key: 'j' })
    await settle()
    expect(router.currentRoute.value.params.id).toBe('s2')
    await wrapper.setProps({ activeId: 's2' })
    await wrapper.find('nav').trigger('keydown', { key: 'k' })
    await settle()
    expect(router.currentRoute.value.params.id).toBe('s1')
  })

  it('leaves typing in the filter box alone', async () => {
    const wrapper = await mountSidebar(['s1', 's2'])
    const input = wrapper.find('input[type="search"]')
    await input.trigger('keydown', { key: 'j' })
    await input.trigger('keydown', { key: 'k' })
    await settle()
    expect(router.currentRoute.value.params.id).toBe('s1')
  })

  it('ignores modified j/k', async () => {
    const wrapper = await mountSidebar(['s1', 's2'])
    await wrapper.find('nav').trigger('keydown', { key: 'j', ctrlKey: true })
    await wrapper.find('nav').trigger('keydown', { key: 'j', metaKey: true })
    await wrapper.find('nav').trigger('keydown', { key: 'k', shiftKey: true })
    await wrapper.find('nav').trigger('keydown', { key: 'k', altKey: true })
    await settle()
    expect(router.currentRoute.value.params.id).toBe('s1')
  })
})

describe('sidebar server search (C-SES-2.c)', () => {
  it('routes the filter through session.list and paints snippet + honest spend', async () => {
    // Same shape as mountSidebar, but keeping the wire handle: the point of
    // this test is the FRAME the debounced filter sends.
    const wire = await Wire.connect()
    const sessions = useSessionsStore()
    sessions.byId = Object.fromEntries(
      ['keeper', 'quiet'].map((id, index) => [id, { id, name: `session ${index}`, status: 'idle', open: true, updatedAt: `2026-10-17 00:00:0${2 - index}`, createdAt: '', permissionMode: 'default', root: '/repo', contextTokens: 0, contextWindow: 0 }]),
    )
    router = createAppRouter(createMemoryHistory())
    await router.push({ name: 'session', params: { id: 'keeper' } })
    await router.isReady()
    const wrapper = mount(SessionSidebar, {
      props: { activeId: 'keeper' },
      global: { plugins: [router] },
    })

    await wrapper.find('input[type="search"]').setValue('octopus')
    // Real timers: the store debounces the request by 250ms, so the frame
    // only leaves the socket after that window.
    await new Promise((resolve) => setTimeout(resolve, 400))
    expect(wire.sent('session.list').at(-1)?.params).toMatchObject({ query: 'octopus', limit: 200 })

    wire.answer('session.list', {
      items: [{
        id: 'keeper', name: 'session 0', status: 'idle', open: false,
        preview: 'closing thoughts for now',
        snippet: 'fell through over the [octopus] clause',
        spentUsd: 0.5, spendUnpriced: true,
        createdAt: '', updatedAt: '2026-10-17 00:00:02',
      }],
      nextCursor: null,
    })
    await flush()
    await wrapper.vm.$nextTick()

    // Server results win: 'quiet' hides even though the client index holds it,
    // because a non-empty filter shows the ranked list, not the local index.
    expect(wrapper.findAll('[data-testid="session-link"]').map((row) => row.attributes('data-session-id')))
      .toEqual(['keeper'])
    expect(wrapper.find('[data-testid="session-snippet"]').text()).toContain('[octopus]')
    expect(wrapper.find('[data-testid="session-spend"]').text()).toBe('≥$0.50')

    // Clearing the box takes the immediate no-request path back to the index.
    await wrapper.find('input[type="search"]').setValue('')
    await wrapper.vm.$nextTick()
    expect(wrapper.findAll('[data-testid="session-link"]')).toHaveLength(2)
    expect(wire.sent('session.list').filter((frame) => (frame.params as { query?: string } | undefined)?.query === 'octopus')).toHaveLength(1)
  })
})
