import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import { createAppRouter } from '../../router'
import { useSessionsStore } from '../../stores/sessions'
import { Wire } from '../grid/__tests__/harness'
import SessionSidebar from '../SessionSidebar.vue'

/**
 * WEB-1a: the session-row menu. Fork, export, close and delete were
 * protocol methods with no caller at all; they live behind a per-row ⋯
 * button now, delete behind a confirm.
 */

let router: ReturnType<typeof createAppRouter>

async function settle(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0))
}

async function mountSidebar(ids: string[]): Promise<{ wire: Wire; wrapper: ReturnType<typeof mount> }> {
  const wire = await Wire.connect()
  const sessions = useSessionsStore()
  sessions.byId = Object.fromEntries(
    ids.map((id, index) => [
      id,
      {
        id,
        name: `session ${index}`,
        status: 'idle',
        open: true,
        updatedAt: `2026-10-17 00:00:0${ids.length - index}`,
        createdAt: '',
        permissionMode: 'default',
        root: '/repo',
        contextTokens: 0,
        contextWindow: 0,
      },
    ]),
  )
  await settle()
  router = createAppRouter(createMemoryHistory())
  await router.push({ name: 'session', params: { id: ids[0] ?? 'x' } })
  await router.isReady()
  const wrapper = mount(SessionSidebar, { props: { activeId: ids[0] ?? null }, global: { plugins: [router] } })
  return { wire, wrapper }
}

beforeEach(() => {
  setActivePinia(createPinia())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('session-row menu (WEB-1a)', () => {
  it('opens on the row button and forks, then follows the forked session', async () => {
    const { wire, wrapper } = await mountSidebar(['s1', 's2'])
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    expect(wrapper.find('[data-testid="row-actions"]').exists()).toBe(true)
    await wrapper.find('[data-testid="row-fork-s1"]').trigger('click')
    expect(wire.sent('session.fork').at(-1)?.params).toMatchObject({ sessionId: 's1' })
    wire.answer('session.fork', { id: 's9', name: 's1 again', status: 'idle', open: true, updatedAt: '2026-10-17 01:00:00', createdAt: '2026-10-17 01:00:00', permissionMode: 'default', root: '/repo', contextTokens: 0, contextWindow: 0 })
    await settle()
    expect(router.currentRoute.value.params.id).toBe('s9')
    expect(useSessionsStore().byId.s9?.name).toBe('s1 again')
  })

  it('closes the session and leaves the row listed as closed', async () => {
    const { wire, wrapper } = await mountSidebar(['s1'])
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    await wrapper.find('[data-testid="row-close-s1"]').trigger('click')
    expect(wire.sent('session.close').at(-1)?.params).toMatchObject({ sessionId: 's1' })
    wire.answer('session.close', { closed: true })
    await settle()
    expect(useSessionsStore().byId.s1?.status).toBe('closed')
    expect(wrapper.find('[data-testid="row-actions"]').exists()).toBe(false)
  })

  it('asks before deleting, and only deletes once asked', async () => {
    const { wire, wrapper } = await mountSidebar(['s1'])
    const confirmSpy = vi.fn(() => false)
    vi.stubGlobal('confirm', confirmSpy)
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    await wrapper.find('[data-testid="row-delete-s1"]').trigger('click')
    await settle()
    expect(confirmSpy).toHaveBeenCalledTimes(1)
    expect(wire.sent('session.delete')).toHaveLength(0)
    expect(useSessionsStore().byId.s1).toBeDefined()

    confirmSpy.mockImplementation(() => true)
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    await wrapper.find('[data-testid="row-delete-s1"]').trigger('click')
    expect(wire.sent('session.delete').at(-1)?.params).toMatchObject({ sessionId: 's1' })
    wire.answer('session.delete', { deleted: ['s1'] })
    await settle()
    expect(useSessionsStore().byId.s1).toBeUndefined()
  })

  it('downloads the exported transcript as a file, revoking the blob URL on replace or unmount', async () => {
    const { wire, wrapper } = await mountSidebar(['s1'])
    let next = 0
    const createObjectURL: Mock<(blob: Blob) => string> = vi.fn(() => `blob:download-${++next}`)
    const revokeObjectURL = vi.fn()
    Object.defineProperty(URL, 'createObjectURL', { value: createObjectURL, configurable: true, writable: true })
    Object.defineProperty(URL, 'revokeObjectURL', { value: revokeObjectURL, configurable: true, writable: true })
    let anchor: { download?: string; href?: string } = {}
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      anchor = { download: this.download, href: this.href }
    })
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    await wrapper.find('[data-testid="row-export-markdown-s1"]').trigger('click')
    // C-WEB-7.c: the export walk pages; a `more`-less answer is one whole page.
    expect(wire.sent('session.export').at(-1)?.params).toEqual({ sessionId: 's1', format: 'markdown', offset: 0, limit: 1_048_576 })
    wire.answer('session.export', { format: 'markdown', content: '# transcript' })
    await settle()
    expect(createObjectURL).toHaveBeenCalledTimes(1)
    const blob = createObjectURL.mock.calls[0]?.[0]
    expect(blob).toBeInstanceOf(Blob)
    expect(anchor).toEqual({ download: 'session-s1.md', href: 'blob:download-1' })
    // w2h M4: no eager revoke — the browser may not have read the blob yet.
    expect(revokeObjectURL).not.toHaveBeenCalled()

    // A second export revokes the URL the first one used, at replacement.
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    await wrapper.find('[data-testid="row-export-markdown-s1"]').trigger('click')
    wire.answer('session.export', { format: 'markdown', content: '# transcript two' })
    await settle()
    expect(createObjectURL).toHaveBeenCalledTimes(2)
    expect(anchor).toEqual({ download: 'session-s1.md', href: 'blob:download-2' })
    expect(revokeObjectURL.mock.calls.map((call) => call[0])).toEqual(['blob:download-1'])

    // Unmount revokes whatever URL is still live.
    wrapper.unmount()
    expect(revokeObjectURL.mock.calls.map((call) => call[0])).toEqual(['blob:download-1', 'blob:download-2'])
  })

  it('Escape closes the menu', async () => {
    const { wrapper } = await mountSidebar(['s1'])
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    await wrapper.find('nav').trigger('keydown', { key: 'Escape' })
    expect(wrapper.find('[data-testid="row-actions"]').exists()).toBe(false)
  })
})

/**
 * C-SES-7.e: the tray and incognito UI on the same menu seams — Archive and
 * Restore rows, the Incognito create button, the Show-archived checkbox,
 * and the row markers. The tray toggle never client-side-sweeps: it drives
 * the store's re-read of the server (pinned in the store spec).
 */
describe('session tray + incognito (C-SES-7.e)', () => {
  const summary = (id: string, extra: Record<string, unknown> = {}) => ({
    id,
    name: `session ${id}`,
    status: 'idle',
    open: false,
    updatedAt: '2026-10-17 02:00:00',
    createdAt: '2026-10-17 00:00:00',
    permissionMode: 'default',
    root: '/repo',
    ...extra,
  })

  it('archives from the row menu, and the hidden tray drops the row', async () => {
    const { wire, wrapper } = await mountSidebar(['s1'])
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    await wrapper.find('[data-testid="row-archive-s1"]').trigger('click')
    expect(wire.sent('session.archive').at(-1)?.params).toMatchObject({ sessionId: 's1' })
    wire.answer('session.archive', summary('s1', { archived: true }))
    await settle()
    expect(useSessionsStore().byId.s1).toBeUndefined()
    expect(wire.sent('session.unarchive')).toHaveLength(0)
  })

  it('an archived row shows Restore instead, and it comes back', async () => {
    const { wire, wrapper } = await mountSidebar(['s1'])
    const seeded = useSessionsStore().byId.s1
    if (seeded) useSessionsStore().byId = { s1: { ...seeded, archived: true } }
    await settle()
    expect(wrapper.find('[data-testid="session-archived"]').exists()).toBe(true)
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    expect(wrapper.find('[data-testid="row-archive-s1"]').exists()).toBe(false)
    await wrapper.find('[data-testid="row-restore-s1"]').trigger('click')
    expect(wire.sent('session.unarchive').at(-1)?.params).toMatchObject({ sessionId: 's1' })
    wire.answer('session.unarchive', summary('s1', { archived: false }))
    await settle()
    expect(useSessionsStore().byId.s1?.archived).toBe(false)
    expect(wrapper.find('[data-testid="session-archived"]').exists()).toBe(false)
  })

  it('the tray checkbox walks the archived listing and keeps its rows', async () => {
    const { wire, wrapper } = await mountSidebar(['s1'])
    await wrapper.find('[data-testid="show-archived"]').setValue(true)
    await settle()
    // The live page first, then the tray page — the newest unanswered frame
    // is the walk currently in flight at each answer.
    wire.answer('session.list', { items: [], nextCursor: null })
    await settle()
    wire.answer('session.list', { items: [summary('s1', { archived: true })], nextCursor: null })
    await settle()
    expect(wire.sent('session.list').at(-1)?.params).toMatchObject({ archived: true })
    expect(useSessionsStore().showArchived).toBe(true)
    expect(useSessionsStore().byId.s1?.archived).toBe(true)
  })

  it('the Incognito button creates an ephemeral session and marks its row', async () => {
    const { wire, wrapper } = await mountSidebar(['s1'])
    await wrapper.find('[data-testid="new-incognito"]').trigger('click')
    expect(wire.sent('session.create').at(-1)?.params).toMatchObject({ ephemeral: true })
    wire.answer('session.create', summary('e1', { name: 'incognito', ephemeral: true, open: true }))
    await settle()
    expect(useSessionsStore().byId.e1?.ephemeral).toBe(true)
    expect(wrapper.find('[data-testid="session-incognito"]').exists()).toBe(true)
  })

  it('the Incognito button creates ephemeral, marks the row, and gates the destructive menu items', async () => {
    const { wire, wrapper } = await mountSidebar(['s1'])
    await wrapper.find('[data-testid="new-incognito"]').trigger('click')
    expect(wire.sent('session.create').at(-1)?.params).toMatchObject({ ephemeral: true })
    wire.answer('session.create', {
      id: 'g1', name: '', status: 'idle', open: true,
      updatedAt: '2026-10-17 05:00:00', createdAt: '', permissionMode: 'default',
      root: '/repo', contextTokens: 0, contextWindow: 0, ephemeral: true,
    })
    await settle()
    expect(useSessionsStore().byId.g1?.ephemeral).toBe(true)
    expect(wrapper.find('[data-testid="session-incognito"]').exists()).toBe(true)
    await wrapper.find('[data-testid="row-menu-g1"]').trigger('click')
    expect(wrapper.find('[data-testid="row-delete-g1"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="row-archive-g1"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="row-close-g1"]').exists()).toBe(true)
  })

  it('keeps the full menu on a live row: Archive present, Restore absent', async () => {
    // Discriminating shape-check for the row-action v-if gates.
    const { wrapper } = await mountSidebar(['s1'])
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    expect(wrapper.find('[data-testid="row-archive-s1"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="row-restore-s1"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="row-delete-s1"]').exists()).toBe(true)
  })
})
