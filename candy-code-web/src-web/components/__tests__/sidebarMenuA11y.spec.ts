import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import { createAppRouter } from '../../router'
import { useSessionsStore } from '../../stores/sessions'
import { Wire } from '../grid/__tests__/harness'
import SessionSidebar from '../SessionSidebar.vue'

/**
 * MA-32 / MA-33: the row-action menu was mouse-bound — no dismissal on an outside
 * click, no way into or around it from the keyboard, and its error line invisible
 * to screen readers. The share dialog also appeared at the bottom of a scrollable
 * list without ever scrolling into view. These pins exercise the affordances, on a
 * body-attached mount because focus movement and the document-level pointerdown
 * listener only exist where the tree is really in the document.
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
  const wrapper = mount(SessionSidebar, {
    props: { activeId: ids[0] ?? null },
    global: { plugins: [router] },
    attachTo: document.body,
  })
  return { wire, wrapper }
}

function activeTestId(): string | null {
  const el = document.activeElement
  return el instanceof HTMLElement ? el.getAttribute('data-testid') : null
}

beforeEach(() => {
  setActivePinia(createPinia())
})

afterEach(() => {
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

describe('row-action menu keyboard + dismissal (MA-32)', () => {
  it('opening the menu moves focus to its first item', async () => {
    const { wrapper } = await mountSidebar(['s1'])
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    await flushPromises()
    expect(activeTestId()).toBe('row-fork-s1')
    wrapper.unmount()
  })

  it('arrows walk the items, wrapping at the ends; Home and End jump', async () => {
    const { wrapper } = await mountSidebar(['s1'])
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    await flushPromises()
    const menu = wrapper.find('[data-testid="row-actions"]')
    const items = menu.findAll('[role="menuitem"]')
    expect(items.length).toBeGreaterThan(2)
    await menu.trigger('keydown', { key: 'End' })
    expect(document.activeElement).toBe(items[items.length - 1]?.element)
    await menu.trigger('keydown', { key: 'ArrowDown' })
    expect(document.activeElement).toBe(items[0]?.element) // wraps
    await menu.trigger('keydown', { key: 'ArrowUp' })
    expect(document.activeElement).toBe(items[items.length - 1]?.element) // wraps back
    await menu.trigger('keydown', { key: 'Home' })
    expect(document.activeElement).toBe(items[0]?.element)
    wrapper.unmount()
  })

  it('a pointerdown outside the open row closes the menu, one inside does not', async () => {
    const { wrapper } = await mountSidebar(['s1', 's2'])
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="row-actions"]').exists()).toBe(true)
    // Clicking inside the open row (an item press is preceded by a pointerdown on it)
    // must not dismiss before the click lands.
    wrapper.find('[data-testid="row-fork-s1"]').element.dispatchEvent(new Event('pointerdown', { bubbles: true }))
    await flushPromises()
    expect(wrapper.find('[data-testid="row-actions"]').exists()).toBe(true)
    // A pointerdown on the sidebar heading is outside `.row.open` — the menu goes away.
    wrapper.find('h2').element.dispatchEvent(new Event('pointerdown', { bubbles: true }))
    await flushPromises()
    expect(wrapper.find('[data-testid="row-actions"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('Tab out closes the menu', async () => {
    const { wrapper } = await mountSidebar(['s1'])
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-testid="row-actions"]').trigger('keydown', { key: 'Tab' })
    expect(wrapper.find('[data-testid="row-actions"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('j/k navigation stands down while the menu is open', async () => {
    const { wrapper } = await mountSidebar(['s1', 's2'])
    // Without the menu, j walks to the next row's session.
    await wrapper.find('[data-testid="sidebar"]').trigger('keydown', { key: 'j' })
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('s2')
    // Back on s1 with the menu open, j must not navigate underneath it.
    await router.push({ name: 'session', params: { id: 's1' } })
    await flushPromises()
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-testid="sidebar"]').trigger('keydown', { key: 'j' })
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('s1')
    wrapper.unmount()
  })

  it('the sidebar error line is announced (role=alert)', async () => {
    const { wire, wrapper } = await mountSidebar(['s1'])
    expect(document.querySelector('[data-testid="sidebar-error"]')).toBeNull()
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    await wrapper.find('[data-testid="row-close-s1"]').trigger('click')
    const frame = wire.sent('session.close').pop()
    wire.socket.fail(-32603, 'unavailable', 'the host is away', frame?.id)
    await flushPromises()
    const error = wrapper.find('[data-testid="sidebar-error"]')
    expect(error.exists()).toBe(true)
    expect(error.attributes('role')).toBe('alert')
    expect(error.text()).toContain('the host is away')
    wrapper.unmount()
  })
})

describe('share dialog reveal (MA-33)', () => {
  it('publishing scrolls the dialog into view and focuses its copy button', async () => {
    const { wire, wrapper } = await mountSidebar(['s1'])
    await wrapper.find('[data-testid="row-menu-s1"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-testid="row-share-s1"]').trigger('click')
    wire.answer('session.share', { path: '/s/deadbeefcafe0123deadbeefcafe0123', expiresAt: 1806796800 })
    await flushPromises()
    const dialog = wrapper.find('[data-testid="share-dialog"]')
    expect(dialog.exists()).toBe(true)
    // MA-33: the just-minted token must not sit below the fold unfocused.
    expect(activeTestId()).toBe('share-copy')
    wrapper.unmount()
  })
})
