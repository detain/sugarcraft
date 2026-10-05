import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { useLayoutStore } from '../../../stores/layout'
import { useSettingsStore } from '../../../stores/settings'
import { ask, summary, Wire } from '../../grid/__tests__/harness'
import ApprovalsButton from '../ApprovalsButton.vue'
import ApprovalsDrawer from '../ApprovalsDrawer.vue'
import { useAttention } from '../attention'

const Stub = defineComponent({ render: () => h('div') })
const Root = defineComponent({ render: () => h('div', [h(ApprovalsButton), h(ApprovalsDrawer)]) })

function appRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'dashboard', component: Stub },
      { path: '/s/:id', name: 'session', component: Stub },
    ],
  })
}

async function setUp() {
  const wire = await Wire.connect()
  wire.answer('session.list', { items: [summary('a', { name: 'fix-auth' }), summary('b', { name: 'refactor-db' })], nextCursor: null })
  wire.answer('permission.pending', { items: [] })
  const router = appRouter()
  await router.push('/')
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

describe('approvals drawer', () => {
  it('counts every open question and lists them by session, oldest first', async () => {
    const { wire, wrapper } = await setUp()
    expect(wrapper.find('[data-testid="approvals-count"]').text()).toBe('0')

    wire.serverEvent('permission.asked', ask('k1', 'b'))
    wire.serverEvent('permission.asked', ask('k2', 'a', 'Write'))
    await flushPromises()
    expect(wrapper.find('[data-testid="approvals-count"]').text()).toBe('2')

    await wrapper.find('[data-testid="approvals-button"]').trigger('click')
    await flushPromises()
    const groups = wrapper.findAll('[data-testid="approvals-group"]')
    expect(groups.map((group) => group.attributes('data-session-id'))).toEqual(['b', 'a'])
    expect(groups[0]?.find('h3').text()).toContain('refactor-db')
    expect(groups[1]?.find('[data-testid="permission-card"]').text()).toContain('Write')
    expect(document.activeElement?.getAttribute('data-ask-id')).toBe('k1')
  })

  it('answers from the drawer; a question answered elsewhere leaves by itself', async () => {
    const { wire, wrapper } = await setUp()
    wire.serverEvent('permission.asked', ask('k1', 'a'))
    wire.serverEvent('permission.asked', ask('k2', 'b'))
    useLayoutStore().toggleApprovals(true)
    await flushPromises()

    await wrapper.find('[data-ask-id="k1"] [data-testid="ask-reject-stop"]').trigger('click')
    expect(wire.sent('permission.respond').pop()?.params).toMatchObject({ sessionId: 'a', askId: 'k1', reply: 'reject', cascade: true })
    wire.answer('permission.respond', { applied: true, reply: 'reject' })
    await flushPromises()
    expect(wrapper.find('[data-ask-id="k1"]').exists()).toBe(false)

    wire.serverEvent('permission.settled', { sessionId: 'b', askId: 'k2', reply: 'once' })
    await flushPromises()
    expect(wrapper.find('[data-testid="approvals-empty"]').exists()).toBe(true)
  })

  it('a refused answer shows why and gives the card its buttons back', async () => {
    const { wire, wrapper } = await setUp()
    wire.serverEvent('permission.asked', ask('k1', 'a'))
    useLayoutStore().toggleApprovals(true)
    await flushPromises()

    await wrapper.find('[data-testid="ask-once"]').trigger('click')
    wire.socket.fail(-32003, 'forbidden', 'this sign-in may not approve')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toBe('this sign-in may not approve')
    expect(wrapper.find('[data-testid="ask-once"]').attributes('disabled')).toBeUndefined()
  })

  it('opens a question’s session, and Escape closes the drawer', async () => {
    const { wire, router, wrapper } = await setUp()
    wire.serverEvent('permission.asked', ask('k1', 'a'))
    useLayoutStore().toggleApprovals(true)
    await flushPromises()

    await wrapper.find('[data-testid="approvals-group"] h3 a').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('a')
    expect(useLayoutStore().approvalsOpen).toBe(false)

    useLayoutStore().toggleApprovals(true)
    await flushPromises()
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await flushPromises()
    expect(wrapper.find('[data-testid="approvals-drawer"]').exists()).toBe(false)
  })
})

describe('attention', () => {
  class FakeNotification {
    static permission = 'granted'
    static shown: { title: string; body?: string; tag?: string }[] = []
    constructor(title: string, options: { body?: string; tag?: string } = {}) {
      FakeNotification.shown.push({ title, ...options })
    }
  }

  async function attending(hidden = false) {
    const { wire } = await setUp()
    FakeNotification.shown = []
    useSettingsStore().notify = true
    const stop = useAttention({ hidden: () => hidden, notification: () => FakeNotification as unknown as typeof Notification })
    return { wire, stop }
  }

  it('counts the questions in the tab title', async () => {
    const { wire, stop } = await attending()
    wire.serverEvent('permission.asked', ask('k1', 'a'))
    await flushPromises()
    expect(document.title).toBe('(1) SugarCrush')
    wire.serverEvent('permission.settled', { sessionId: 'a', askId: 'k1' })
    await flushPromises()
    expect(document.title).toBe('SugarCrush')
    stop()
  })

  it('notifies a question in a session that is not on screen, not one that is', async () => {
    const { wire, stop } = await attending()
    useLayoutStore().show({ sessionIds: ['a'], foreground: 'a', narrate: false })

    wire.serverEvent('permission.asked', ask('k1', 'a'))
    wire.serverEvent('permission.asked', ask('k2', 'b'))
    await flushPromises()
    expect(FakeNotification.shown).toEqual([{ title: 'Bash is waiting for an answer', body: 'refactor-db: needs approval', tag: 'k2' }])
    stop()
  })

  it('notifies a turn that finished out of sight', async () => {
    const { wire, stop } = await attending(true)
    wire.serverEvent('session.updated', summary('b', { name: 'refactor-db', status: 'busy' }))
    await flushPromises()
    wire.serverEvent('session.updated', summary('b', { name: 'refactor-db', status: 'idle' }))
    await flushPromises()
    expect(FakeNotification.shown).toEqual([{ title: 'refactor-db finished', body: 'The turn is done.', tag: 'done:b' }])
    stop()
  })

  it('stays quiet when the viewer did not turn notifications on', async () => {
    const { wire, stop } = await attending(true)
    useSettingsStore().notify = false
    wire.serverEvent('permission.asked', ask('k1', 'a'))
    await flushPromises()
    expect(FakeNotification.shown).toEqual([])
    stop()
  })
})
