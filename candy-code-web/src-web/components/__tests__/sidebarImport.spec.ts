import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import { FakeSocket, flush, helloResult } from '../../__tests__/fakes'
import type { SessionSummary } from '../../protocol/generated'
import { Backoff } from '../../protocol/reconnect'
import { createAppRouter } from '../../router'
import { useConnectionStore } from '../../stores/connection'
import { useLayoutStore } from '../../stores/layout'
import { useSessionsStore } from '../../stores/sessions'
import { Wire } from '../grid/__tests__/harness'
import SessionSidebar from '../SessionSidebar.vue'

/**
 * CW-EU-01/CWPM-3 (web seat for session.import): the sidebar's top row gains
 * an Import button driving a hidden file input. The picked file's text rides
 * `session.import { content, name }` verbatim and the minted summary is
 * followed exactly like a creation is. The method is write-scoped, so a
 * read-only principal is not shown the door. A refusal lands in the one
 * error line — no navigation, no swallowed message.
 */

let router: ReturnType<typeof createAppRouter>

function row(id: string): SessionSummary {
  return {
    id,
    name: `session ${id}`,
    status: 'idle',
    open: true,
    updatedAt: '2026-10-17 00:00:01',
    createdAt: '2026-10-17 00:00:00',
    permissionMode: 'default',
    root: '/repo',
  }
}

async function mountSidebar(scopes: string[] | null = null): Promise<{ wire: Wire; wrapper: ReturnType<typeof mount> }> {
  let wire: Wire
  if (scopes === null) {
    wire = await Wire.connect()
  } else {
    // Same wiring as Wire.connect, but the handshake carries the test's scopes.
    wire = new Wire()
    const connection = useConnectionStore()
    connection.configure(
      {
        connectUrl: async () => 'ws://x/ws?ticket=t',
        socket: (url, protocols) => {
          const created = new FakeSocket(url, protocols)
          wire.sockets.push(created)
          return created
        },
        timers: wire.timers,
        backoff: new Backoff({ random: () => 0.5 }),
      },
      (fn) => fn(),
    )
    useSessionsStore()
    connection.start()
    await flush()
    wire.socket.open()
    wire.socket.answer(helloResult({ principal: { kind: 'guest', scopes } }), 'hello')
    await flush()
  }
  const sessions = useSessionsStore()
  sessions.byId = { s1: row('s1') }
  await flush()
  router = createAppRouter(createMemoryHistory())
  await router.push({ name: 'session', params: { id: 's1' } })
  await router.isReady()
  const wrapper = mount(SessionSidebar, { props: { activeId: 's1' }, global: { plugins: [router] } })
  return { wire, wrapper }
}

/** Load a file into the hidden input the way the OS picker would, then change. */
async function chooseFile(wrapper: ReturnType<typeof mount>, content: string, filename: string): Promise<void> {
  const input = wrapper.find<HTMLInputElement>('[data-testid="import-file"]')
  const file = new File([content], filename)
  Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
  await input.trigger('change')
  await flush()
}

beforeEach(() => {
  setActivePinia(createPinia())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('sidebar transcript import (CW-EU-01)', () => {
  // LANG REQUEST web.chrome.sidebar.import / import_hint (G5-B): the label
  // and tooltip ride the catalogue now, so the connected-state tooltip is a
  // real hint instead of silence (red-before: the title was undefined when
  // connected; the raw 'Import' word sat outside the copy census by design).
  it('the import seat answers to the catalogue: label and connected tooltip', async () => {
    const { wrapper } = await mountSidebar()
    const button = wrapper.find('[data-testid="import-session"]')
    expect(button.text()).toBe('Import')
    expect(button.attributes('title')).toBe('Import a transcript file as a new session')
  })

  it('sends the file text and name through session.import and follows the minted session', async () => {
    const { wire, wrapper } = await mountSidebar()
    // Open the mobile drawer first so "closing it on follow" is a real flip.
    useLayoutStore().sidebarOpen = true
    await chooseFile(wrapper, '{"transcript":true}', 'chat.json')
    const frame = wire.sent('session.import').at(-1)
    expect(frame?.params).toMatchObject({ content: '{"transcript":true}', name: 'chat.json' })
    wire.answer('session.import', { ...row('s9'), name: 'imported chat' })
    await flush()
    expect(router.currentRoute.value.params.id).toBe('s9')
    // Following the session closes the mobile sidebar exactly like create() does.
    expect(useLayoutStore().sidebarOpen).toBe(false)
  })

  it('guards the in-flight import, clears the input, and re-fires once idle', async () => {
    const { wire, wrapper } = await mountSidebar()
    const input = wrapper.find<HTMLInputElement>('[data-testid="import-file"]')
    await chooseFile(wrapper, 'x', 'a.json')
    expect(wire.sent('session.import')).toHaveLength(1)
    expect(input.element.value, 'the handler resets the value').toBe('')
    // While the request is in flight, the busy door refuses a second send…
    const first = new File(['second-pick'], 'a.json')
    Object.defineProperty(input.element, 'files', { value: [first], configurable: true })
    await input.trigger('change')
    await flush()
    expect(wire.sent('session.import'), 'no double bill mid-import').toHaveLength(1)
    // …and once settled, the cleared input lets the same file fire again.
    wire.answer('session.import', row('s9'))
    await flush()
    const second = new File(['y'], 'a.json')
    Object.defineProperty(input.element, 'files', { value: [second], configurable: true })
    await input.trigger('change')
    await flush()
    expect(wire.sent('session.import')).toHaveLength(2)
    expect(wire.sent('session.import').at(-1)?.params).toMatchObject({ content: 'y' })
  })

  it('lands a server refusal in the one error line without navigating', async () => {
    const { wire, wrapper } = await mountSidebar()
    await chooseFile(wrapper, 'not a transcript', 'junk.txt')
    wire.socket.fail(-32602, 'invalid_params', 'transcript format not recognised')
    await flush()
    const line = wrapper.find('[data-testid="sidebar-error"]')
    expect(line.exists()).toBe(true)
    expect(line.text()).toContain('transcript format not recognised')
    expect(router.currentRoute.value.params.id).toBe('s1')
  })

  it('stays out of the top row for a read-only connection', async () => {
    const { wrapper } = await mountSidebar(['read'])
    expect(wrapper.find('[data-testid="import-session"]').exists()).toBe(false)
  })

  it('appears for a write-scoped connection', async () => {
    const { wrapper } = await mountSidebar(['read', 'write'])
    expect(wrapper.find('[data-testid="import-session"]').exists()).toBe(true)
  })

  it('drives the hidden picker from the button', async () => {
    const { wrapper } = await mountSidebar()
    const clickSpy = vi.fn()
    const input = wrapper.find<HTMLInputElement>('[data-testid="import-file"]')
    input.element.click = clickSpy
    await wrapper.find('[data-testid="import-session"]').trigger('click')
    expect(clickSpy).toHaveBeenCalledTimes(1)
    expect(input.attributes('type')).toBe('file')
    expect(input.classes()).toContain('pick-file')
  })
})
