import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import type { SessionSummary } from '../../protocol/generated'
import { createAppRouter } from '../../router'
import { useSessionsStore } from '../../stores/sessions'
import { Wire } from '../grid/__tests__/harness'
import SessionSidebar from '../SessionSidebar.vue'

/**
 * C-WEB-3.d: `workspace.close {root, force?}` gets its first caller. Rows
 * whose root is not the server's own (`elsewhere()` shape) carry a "Close
 * <root>/ host" menu item; it confirms first, retries with force when the
 * server refuses with `turn_running`, and the store drops the root's rows
 * and reloads the index after.
 */

const SERVER_ROOT = '/repo'
const OTHER_ROOT = '/work/other'

let router: ReturnType<typeof createAppRouter>

function row(id: string, root: string): SessionSummary {
  return {
    id,
    name: `session ${id}`,
    status: 'idle',
    open: true,
    preview: null,
    createdAt: '2026-10-17 00:00:00',
    updatedAt: '2026-10-17 00:00:00',
    permissionMode: 'default',
    root,
  }
}

async function settle(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0))
}

async function mountSidebar(): Promise<{ wire: Wire; wrapper: ReturnType<typeof mount> }> {
  const wire = await Wire.connect()
  const sessions = useSessionsStore()
  sessions.byId = { s1: row('s1', SERVER_ROOT), s2: row('s2', OTHER_ROOT) }
  await settle()
  router = createAppRouter(createMemoryHistory())
  await router.push({ name: 'session', params: { id: 's1' } })
  await router.isReady()
  const wrapper = mount(SessionSidebar, { props: { activeId: 's1' }, global: { plugins: [router] } })
  return { wire, wrapper }
}

/** Answer the newest `workspace.close` request with a `turn_running` refusal. */
function refuseTurnRunning(wire: Wire): void {
  const frame = wire.sent('workspace.close').at(-1)
  if (!frame) throw new Error('no workspace.close sent')
  wire.socket.fail(-32010, 'turn_running', 'a turn is running in that workspace; cancel it or close with force', frame.id)
}

beforeEach(() => {
  setActivePinia(createPinia())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('close a non-primary root host from the sidebar (C-WEB-3.d)', () => {
  it('offers the item on rows for another root only, naming that root', async () => {
    const { wrapper } = await mountSidebar()

    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    expect(wrapper.find('[data-testid="row-close-host-s1"]').exists()).toBe(false)

    await wrapper.find('[data-testid="row-menu-s2"]').trigger('click')
    const item = wrapper.find('[data-testid="row-close-host-s2"]')
    expect(item.exists()).toBe(true)
    expect(item.text()).toBe('Close other/ host')
  })

  it('asks before closing the host, and sends nothing when the answer is no', async () => {
    const { wire, wrapper } = await mountSidebar()
    const confirmSpy = vi.fn(() => false)
    vi.stubGlobal('confirm', confirmSpy)

    await wrapper.find('[data-testid="row-menu-s2"]').trigger('click')
    await wrapper.find('[data-testid="row-close-host-s2"]').trigger('click')
    await settle()
    expect(confirmSpy).toHaveBeenCalledTimes(1)
    expect(wire.sent('workspace.close')).toHaveLength(0)
    expect(useSessionsStore().byId.s2).toBeDefined()
  })

  it('retries with force on the second confirm when the server refuses with turn_running, then reloads', async () => {
    const { wire, wrapper } = await mountSidebar()
    const confirmSpy = vi.fn(() => true)
    vi.stubGlobal('confirm', confirmSpy)

    await wrapper.find('[data-testid="row-menu-s2"]').trigger('click')
    await wrapper.find('[data-testid="row-close-host-s2"]').trigger('click')
    expect(wire.sent('workspace.close').at(-1)?.params).toMatchObject({ root: OTHER_ROOT })
    expect(wire.sent('workspace.close').at(-1)?.params).not.toHaveProperty('force')

    refuseTurnRunning(wire)
    await settle()
    // The refusal asks a second time before anything is dropped: the host
    // is still up on the first answer's rejection.
    expect(confirmSpy).toHaveBeenCalledTimes(2)
    expect(useSessionsStore().byId.s2).toBeDefined()

    // The second confirm's yes sends the force retry from the same click.
    expect(wire.sent('workspace.close').at(-1)?.params).toMatchObject({ root: OTHER_ROOT, force: true })

    const closeFrame = wire.sent('workspace.close').at(-1)
    if (!closeFrame) throw new Error('no forced workspace.close sent')
    wire.socket.answer({ closed: true }, closeFrame.id)
    await settle()
    expect(useSessionsStore().byId.s2).toBeUndefined()

    // The reload re-reads the index; the closed root's rows stay gone.
    expect(wire.sent('session.list').length).toBeGreaterThan(1)
    wire.answer('session.list', { items: [row('s1', SERVER_ROOT)], nextCursor: null })
    await settle()
    expect(Object.keys(useSessionsStore().byId)).toEqual(['s1'])
    expect(wrapper.find('[data-testid="session-link"]').exists()).toBe(true)
  })

  it('stays silent on a plain success: one confirm, no force, rows gone, index reloaded', async () => {
    const { wire, wrapper } = await mountSidebar()
    const confirmSpy = vi.fn(() => true)
    vi.stubGlobal('confirm', confirmSpy)

    await wrapper.find('[data-testid="row-menu-s2"]').trigger('click')
    await wrapper.find('[data-testid="row-close-host-s2"]').trigger('click')
    expect(wire.sent('workspace.close').at(-1)?.params).toMatchObject({ root: OTHER_ROOT })

    const closeFrame = wire.sent('workspace.close').at(-1)
    if (!closeFrame) throw new Error('no workspace.close sent')
    wire.socket.answer({ closed: true }, closeFrame.id)
    await settle()
    expect(confirmSpy).toHaveBeenCalledTimes(1)
    expect(useSessionsStore().byId.s2).toBeUndefined()

    wire.answer('session.list', { items: [row('s1', SERVER_ROOT)], nextCursor: null })
    await settle()
    expect(Object.keys(useSessionsStore().byId)).toEqual(['s1'])
    expect(wire.sent('workspace.close').filter((frame) => (frame.params as Record<string, unknown>)?.force === true)).toHaveLength(0)
  })
})
