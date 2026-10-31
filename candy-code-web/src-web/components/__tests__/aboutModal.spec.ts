import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AboutModal from '../AboutModal.vue'
import { Wire } from '../grid/__tests__/harness'
import { useConnectionStore } from '../../stores/connection'

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

  it('drops a superseded answer that settles after the current visit (generation latch)', async () => {
    wire = await Wire.connect()
    const wrapper = mount(AboutModal, { props: { open: true } })
    await flushPromises()
    const staleInfo = wire.sent('server.info').at(-1)
    const staleHealth = wire.sent('server.health').at(-1)
    if (!staleInfo || !staleHealth) throw new Error('first open asked nothing')

    // Close and re-open while the first visit is STILL in flight — the
    // race w4j/w2h review named. Two loads now share one set of refs;
    // only the newest generation may write.
    await wrapper.setProps({ open: false })
    await wrapper.setProps({ open: true })
    await flushPromises()
    const currentInfo = wire.sent('server.info').at(-1)
    const currentHealth = wire.sent('server.health').at(-1)
    if (!currentInfo || !currentHealth || currentInfo === staleInfo) throw new Error('re-open did not ask again')
    wire.socket.answer({ version: '2.0.0', protocol: 1, permissionModes: ['default'], defaultPermissionMode: 'default' }, currentInfo.id)
    wire.socket.answer({ ok: true, version: '2.0.0', uptimeS: 10, sessionsOpen: 0, turnsRunning: 0, draining: false }, currentHealth.id)
    await flushPromises()
    expect(wrapper.find('[data-testid="about-info"]').text()).toContain('2.0.0')

    // The superseded visit settles LAST — both its frames, so its
    // allSettled really resumes after the current visit painted. Without
    // the latch its answers overwrite the settled feeds.
    wire.socket.answer({
      version: '0.0.1-STALE', protocol: 1, permissionModes: ['default'], defaultPermissionMode: 'default',
    }, staleInfo.id)
    wire.socket.answer({ ok: true, version: '0.0.1', uptimeS: 99_999, sessionsOpen: 42, turnsRunning: 0, draining: false }, staleHealth.id)
    await flushPromises()
    expect(wrapper.find('[data-testid="about-info"]').text()).not.toContain('STALE')
    expect(wrapper.find('[data-testid="about-info"]').text()).toContain('2.0.0')
    expect(wrapper.find('[data-testid="about-health"]').text()).toContain('up 10 s')
    expect(wrapper.find('[data-testid="about-health"]').text()).not.toContain('42 sessions')
  })

  it('a superseded refusal cannot splat an error over the open visit', async () => {
    wire = await Wire.connect()
    const wrapper = mount(AboutModal, { props: { open: true } })
    await flushPromises()
    const staleInfo = wire.sent('server.info').at(-1)
    const staleHealth = wire.sent('server.health').at(-1)
    if (!staleInfo || !staleHealth) throw new Error('first open asked nothing')

    await wrapper.setProps({ open: false })
    await wrapper.setProps({ open: true })
    await flushPromises()
    const currentInfo = wire.sent('server.info').at(-1)
    const currentHealth = wire.sent('server.health').at(-1)
    if (!currentInfo || !currentHealth) throw new Error('re-open did not ask again')
    wire.socket.answer({ version: '2.0.0', protocol: 1, permissionModes: ['default'], defaultPermissionMode: 'default' }, currentInfo.id)
    wire.socket.answer({ ok: true, version: '2.0.0', uptimeS: 10, sessionsOpen: 0, turnsRunning: 0, draining: false }, currentHealth.id)
    await flushPromises()

    // The dead visit dies last: info REFUSED and health answered — its
    // allSettled completes, and a writer without the latch would set the
    // error line (and overwrite info) on the healthy open visit.
    wire.socket.fail(-32001, 'gone', 'refused by a dead visit', staleInfo.id)
    wire.socket.answer({ ok: true, version: '9.9.9', uptimeS: 1, sessionsOpen: 7, turnsRunning: 0, draining: false }, staleHealth.id)
    await flushPromises()
    expect(wrapper.find('[data-testid="about-error"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="about-info"]').text()).toContain('2.0.0')
    expect(wrapper.find('[data-testid="about-health"]').text()).toContain('up 10 s')
  })
})

/**
 * C-WEB-5.g: the confirmed "Stop server" control. R13's two doors — an
 * admin-scoped principal AND a confirm that names the blast radius — plus
 * a loud refusal row, are pinned here; the sole-caller source scan lives
 * in __tests__/shutdownDormant.spec.ts.
 */
const INFO = {
  version: '1.2.3',
  protocol: 1,
  permissionModes: ['default'],
  defaultPermissionMode: 'default',
}
const HEALTH = { ok: true, version: '1.2.3', uptimeS: 60, sessionsOpen: 2, turnsRunning: 3, draining: false }

/** Re-point the live hello at a token principal with only the given scopes. */
function demoteTo(scopes: string[]): void {
  const connection = useConnectionStore()
  const hello = connection.hello
  if (!hello) throw new Error('Wire.connect() should have established a hello')
  connection.hello = { ...hello, principal: { ...hello.principal, scopes } }
}

describe('AboutModal confirmed stop (C-WEB-5.g)', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  async function openAdminInformed(): Promise<ReturnType<typeof mount>> {
    const wrapper = await openModal()
    wire.answer('server.info', INFO)
    wire.answer('server.health', HEALTH)
    await flushPromises()
    return wrapper
  }

  it('offers no stop control without admin scope', async () => {
    const wrapper = await openModal()
    demoteTo(['read'])
    wire.answer('server.info', INFO)
    wire.answer('server.health', HEALTH)
    await flushPromises()
    expect(wrapper.find('[data-testid="about-health"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="about-stop"]').exists()).toBe(false)
    expect(wire.sent('server.shutdown')).toEqual([])
  })

  it('offers no stop control while the health feed is unknown — the confirm must be able to name what it interrupts', async () => {
    const wrapper = await openModal()
    wire.answer('server.info', INFO)
    const frame = wire.sent('server.health').at(-1)
    if (!frame) throw new Error('no server.health sent')
    wire.socket.fail(-32001, 'forbidden', 'no health for you', frame.id)
    await flushPromises()
    expect(wrapper.find('[data-testid="about-info"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="about-stop"]').exists()).toBe(false)
  })

  it('a cancelled confirm names the counts in the question and sends nothing', async () => {
    const confirmSpy = vi.fn(() => false)
    vi.stubGlobal('confirm', confirmSpy)
    const wrapper = await openAdminInformed()
    const stop = wrapper.find('[data-testid="about-stop"]')
    expect(stop.exists()).toBe(true)
    await stop.trigger('click')
    expect(confirmSpy).toHaveBeenCalledTimes(1)
    const question = String(confirmSpy.mock.calls[0]?.[0])
    expect(question).toContain('last health check')
    expect(question).toContain('3 running turns')
    expect(question).toContain('2 open sessions')
    expect(wire.sent('server.shutdown')).toEqual([])
    expect(wrapper.emitted('close')).toBeUndefined()
  })

  it('a confirmed stop sends server.shutdown exactly once and the draining event takes over', async () => {
    const confirmSpy = vi.fn(() => true)
    vi.stubGlobal('confirm', confirmSpy)
    const wrapper = await openAdminInformed()
    const stop = wrapper.find('[data-testid="about-stop"]')
    await stop.trigger('click')
    await stop.trigger('click') // double-click: the stopping latch sends nothing more
    const frames = wire.sent('server.shutdown')
    expect(frames).toHaveLength(1)
    // The client's mutating-method machinery rides an idempotency key on the
    // request; no drainSeconds override — the server drains its configured grace.
    expect(frames[0]?.params).toEqual({ idempotencyKey: expect.any(String) })
    expect(wrapper.find('[data-testid="about-stop"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="about-stop-state"]').exists()).toBe(true)
    wire.answer('server.shutdown', { accepted: true })
    await flushPromises()
    expect(wrapper.emitted('close')).toHaveLength(1)
    const connection = useConnectionStore()
    expect(connection.shutdown).toBeNull()
    wire.serverEvent('server.shutdown', { reason: 'server.shutdown was requested', graceSeconds: 30 })
    expect(connection.shutdown).toEqual({ reason: 'server.shutdown was requested', graceSeconds: 30 })
  })

  it('a refusal lands loudly on the control and re-arms it', async () => {
    const confirmSpy = vi.fn(() => true)
    vi.stubGlobal('confirm', confirmSpy)
    const wrapper = await openAdminInformed()
    await wrapper.find('[data-testid="about-stop"]').trigger('click')
    const frame = wire.sent('server.shutdown').at(-1)
    if (!frame) throw new Error('confirmed click sent nothing')
    wire.socket.fail(-32001, 'forbidden', 'admin scope required', frame.id)
    await flushPromises()
    expect(wrapper.find('[data-testid="about-stop-error"]').text()).toContain('admin scope required')
    expect(wrapper.find('[data-testid="about-stop"]').attributes('disabled')).toBeUndefined()
  })
})
