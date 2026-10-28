import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import { nextTick } from 'vue'
import App from '../../App.vue'
import HelpOverlay from '../../components/HelpOverlay.vue'
import { SIGN_IN_CODE } from '../../keys'
import { createAppRouter } from '../../router'
import { useConnectionStore } from '../../stores/connection'
import type { CommandInfo } from '../../stores/session'
import { FakeSocket, FakeTimers, helloResult } from '../../__tests__/fakes'

/**
 * C-WEB-4.b (GAP-UI-28): `?` on the empty composer — or outside any field —
 * opens the help overlay from App's capture listener; mid-draft it stays the
 * character it is. The overlay itself lists the viewer's three rebindable
 * shortcuts and the session's command rows with their runsIn markers.
 */

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

async function mountApp(): Promise<ReturnType<typeof mount>> {
  vi.stubGlobal('fetch', vi.fn(async () => json(200, { ticket: 'tk', expiresInSeconds: 30 })))
  const pinia = createPinia()
  setActivePinia(pinia)
  const sockets: FakeSocket[] = []
  useConnectionStore().configure(
    {
      socket: (url, protocols) => {
        const socket = new FakeSocket(url, protocols)
        sockets.push(socket)
        return socket
      },
      timers: new FakeTimers(),
    },
    (fn) => fn(),
  )
  const router = createAppRouter(createMemoryHistory())
  await router.push('/')
  await router.isReady()
  const wrapper = mount(App, { global: { plugins: [pinia, router], provide: { [SIGN_IN_CODE as symbol]: null } } })
  await flushPromises()
  const socket = sockets[0]
  if (!socket) throw new Error('the app never opened a socket')
  socket.open()
  socket.answer(helloResult(), 'hello')
  await flushPromises()
  return wrapper
}

/** The handler is window-level; the target is whatever the browser was typing into. */
function press(init: KeyboardEventInit, target: Element | null): KeyboardEvent {
  const event = new KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init })
  if (target !== null) Object.defineProperty(event, 'target', { value: target })
  window.dispatchEvent(event)
  return event
}

function composerField(value: string): HTMLTextAreaElement {
  const field = document.createElement('textarea')
  field.setAttribute('data-testid', 'composer-input')
  field.value = value
  document.body.appendChild(field)
  return field
}

const HELP_CHORD: KeyboardEventInit = { key: '?', shiftKey: true }

let wrapper: ReturnType<typeof mount> | null = null

function overlayOpen(): boolean {
  return wrapper?.find('[data-testid="help-overlay"]').exists() ?? false
}

beforeEach(() => localStorage.clear())

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.body.innerHTML = ''
  vi.unstubAllGlobals()
})

describe('help overlay key (C-WEB-4.b)', () => {
  it('opens on ? outside any field, from App itself', async () => {
    wrapper = await mountApp()
    expect(overlayOpen()).toBe(false)
    const event = press(HELP_CHORD, null)
    await nextTick()
    expect(overlayOpen()).toBe(true)
    expect(event.defaultPrevented).toBe(true)
  })

  it('opens on ? while focus rests in the empty composer', async () => {
    wrapper = await mountApp()
    press(HELP_CHORD, composerField(''))
    await nextTick()
    expect(overlayOpen()).toBe(true)
  })

  it('types normally mid-draft — a ? in a live draft is just the character', async () => {
    wrapper = await mountApp()
    const event = press(HELP_CHORD, composerField('work in progress'))
    await nextTick()
    expect(overlayOpen()).toBe(false)
    expect(event.defaultPrevented).toBe(false)
  })

  it('hears the field rule through closest(): a plain input keeps its ?', async () => {
    wrapper = await mountApp()
    const field = document.createElement('input')
    field.type = 'search'
    field.value = 'filter text'
    document.body.appendChild(field)
    const event = press(HELP_CHORD, field)
    await nextTick()
    expect(overlayOpen()).toBe(false) // the escape hatch covers inputs, not just the composer
    expect(event.defaultPrevented).toBe(false)
  })

  it('escapes and toggles back out', async () => {
    wrapper = await mountApp()
    press(HELP_CHORD, null)
    await nextTick()
    expect(overlayOpen()).toBe(true)
    press({ key: 'Escape' }, null)
    await nextTick()
    expect(overlayOpen()).toBe(false)
    press(HELP_CHORD, null)
    await nextTick()
    expect(overlayOpen()).toBe(true)
    press(HELP_CHORD, null)
    await nextTick()
    expect(overlayOpen()).toBe(false)
  })

  it('does not answer while a rebind capture is in flight', async () => {
    wrapper = await mountApp()
    press(HELP_CHORD, null)
    await nextTick()
    expect(overlayOpen()).toBe(true)
    press({ key: 'Escape' }, null) // back to closed so the guard is what answers this one
    await nextTick()
    const { beginKeyCapture, endKeyCapture } = await import('../../lib/keymap')
    beginKeyCapture()
    press(HELP_CHORD, null)
    await nextTick()
    expect(overlayOpen()).toBe(false)
    endKeyCapture()
  })
})

describe('help overlay listing (C-WEB-4.b)', () => {
  const COMMANDS: CommandInfo[] = [
    { name: '/status', description: 'Session status', argumentHint: null, source: 'builtin', runsIn: 'server' },
    { name: 'agents', description: 'Agents panel', source: 'builtin', runsIn: 'client' },
  ]

  it('lists the three shortcuts, fixed composer keys, and command rows with their runsIn marker', async () => {
    const view = mount(HelpOverlay, { props: { open: false, commands: COMMANDS } })
    expect(view.find('[data-testid="help-overlay"]').exists()).toBe(false)
    await view.setProps({ open: true })
    const actions = view.findAll('[data-testid="help-shortcut"]').map((row) => row.attributes('data-action'))
    expect(actions).toEqual(['palette', 'help', 'tabs'])
    expect(view.find('[data-testid="help-shortcut"][data-action="palette"] .keys').text()).toBe('Ctrl/⌘+K')
    expect(view.findAll('[data-testid="help-fixed-row"]')).toHaveLength(4)
    const rows = view.findAll('[data-testid="help-command"]')
    expect(rows.map((row) => row.attributes('data-runs-in'))).toEqual(['server', 'client'])
    expect(rows[1]?.text()).toContain('terminal UI only')
    expect(rows[0]?.text()).toContain('runs here')
    expect(rows[0]?.text()).toContain('/status')
    await view.setProps({ open: false })
    expect(view.find('[data-testid="help-overlay"]').exists()).toBe(false)
  })

  it('says so plainly when no session has listed commands yet', async () => {
    const view = mount(HelpOverlay, { props: { open: true, commands: [] } })
    expect(view.find('[data-testid="help-no-commands"]').exists()).toBe(true)
    expect(view.findAll('[data-testid="help-command"]')).toHaveLength(0)
  })
})
