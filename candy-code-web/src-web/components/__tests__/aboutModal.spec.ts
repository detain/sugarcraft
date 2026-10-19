import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import AboutModal from '../AboutModal.vue'
import { Wire } from '../grid/__tests__/harness'

/**
 * WEB-1c: the About overlay — `server.info` for what the server offers,
 * `server.health` for how it is doing, both over the one socket.
 */

let wire: Wire

beforeEach(() => {
  setActivePinia(createPinia())
})

async function openModal(): Promise<ReturnType<typeof mount>> {
  wire = await Wire.connect()
  const wrapper = mount(AboutModal, { props: { open: false } })
  await wrapper.setProps({ open: true })
  await flushPromises()
  return wrapper
}

describe('AboutModal (WEB-1c)', () => {
  it('asks both methods once opened and shows their answers', async () => {
    const wrapper = await openModal()
    expect(wire.sent('server.info')).toHaveLength(1)
    expect(wire.sent('server.health')).toHaveLength(1)
    wire.answer('server.info', {
      version: '1.2.3',
      protocol: 1,
      root: '/repo',
      providers: ['anthropic', 'openai'],
      agents: [{ name: 'worker', description: 'does the work' }],
      tools: ['Read', 'Edit', 'Bash'],
      commands: ['help'],
      permissionModes: ['default', 'plan'],
      defaultPermissionMode: 'default',
    })
    wire.answer('server.health', { ok: true, version: '1.2.3', uptimeS: 3_726, sessionsOpen: 2, turnsRunning: 1, draining: false })
    await flushPromises()
    expect(wrapper.find('[data-testid="about-modal"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="about-info"]').text()).toContain('1.2.3')
    expect(wrapper.find('[data-testid="about-info"]').text()).toContain('anthropic, openai')
    expect(wrapper.find('[data-testid="about-tools"]').text()).toContain('3')
    expect(wrapper.find('[data-testid="about-agents"]').text()).toContain('worker')
    expect(wrapper.find('[data-testid="about-modes"]').text()).toContain('default: default')
    expect(wrapper.find('[data-testid="about-health"]').text()).toContain('up 1 h 2 m')
    expect(wrapper.find('[data-testid="about-health"]').text()).toContain('2 sessions open')
    expect(wrapper.find('[data-testid="about-health"]').text()).toContain('1 turns running')
  })

  it('a health answer can arrive alone; info failing says so', async () => {
    wire = await Wire.connect()
    const wrapper = mount(AboutModal, { props: { open: true } })
    await flushPromises()
    wire.answer('server.health', { ok: false, version: '9', uptimeS: 30, sessionsOpen: 0, turnsRunning: 0, draining: true })
    const infoFrame = wire.sent('server.info').at(-1)
    if (!infoFrame) throw new Error('no server.info sent')
    wire.socket.fail(-32001, 'forbidden', 'no info for you', infoFrame.id)
    await flushPromises()
    expect(wrapper.find('[data-testid="about-error"]').text()).toContain('no info for you')
    expect(wrapper.find('[data-testid="about-health"]').text()).toContain('not ok')
    expect(wrapper.find('[data-testid="about-health"]').text()).toContain('draining')
  })

  it('a re-open that fails shows nothing stale from the previous visit', async () => {
    const wrapper = await openModal()
    wire.answer('server.info', {
      version: '1.2.3',
      protocol: 1,
      permissionModes: ['default', 'plan'],
      defaultPermissionMode: 'default',
    })
    wire.answer('server.health', { ok: true, version: '1.2.3', uptimeS: 60, sessionsOpen: 1, turnsRunning: 0, draining: false })
    await flushPromises()
    expect(wrapper.find('[data-testid="about-info"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="about-health"]').exists()).toBe(true)

    await wrapper.setProps({ open: false })
    await wrapper.setProps({ open: true })
    await flushPromises()
    for (const method of ['server.info', 'server.health'] as const) {
      const frame = wire.sent(method).at(-1)
      if (!frame) throw new Error(`no ${method} sent on re-open`)
      wire.socket.fail(-32001, 'forbidden', `no ${method} this time`, frame.id)
    }
    await flushPromises()
    // w2h M3: both feeds cleared on open — a failed re-fetch is an error,
    // not last visit's version block or health line still on screen.
    expect(wrapper.find('[data-testid="about-error"]').text()).toContain('no server.info this time')
    expect(wrapper.find('[data-testid="about-info"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="about-health"]').exists()).toBe(false)
  })

  it('closes from the button and the backdrop', async () => {
    const wrapper = await openModal()
    await wrapper.find('[data-testid="about-close"]').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
    await wrapper.find('[data-testid="about-backdrop"]').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(2)
  })
})
