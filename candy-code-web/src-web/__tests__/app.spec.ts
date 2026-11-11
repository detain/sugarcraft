import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import App from '../App.vue'
import { SIGN_IN_CODE } from '../keys'
import { createAppRouter } from '../router'
import { useConnectionStore } from '../stores/connection'
import { useLayoutStore } from '../stores/layout'
import { readPastedImages } from '../lib/paste'
import { FakeSocket, FakeTimers, helloResult } from './fakes'

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

async function mountApp(options: { code?: string | null; fetch: (url: string, init?: RequestInit) => Promise<Response> }) {
  vi.stubGlobal('fetch', vi.fn(options.fetch))
  const pinia = createPinia()
  setActivePinia(pinia)
  const sockets: FakeSocket[] = []
  useConnectionStore().configure({
    socket: (url, protocols) => {
      const socket = new FakeSocket(url, protocols)
      sockets.push(socket)
      return socket
    },
    timers: new FakeTimers(),
  }, (fn) => fn())
  const router = createAppRouter(createMemoryHistory())
  await router.push('/')
  await router.isReady()
  const wrapper = mount(App, { global: { plugins: [pinia, router], provide: { [SIGN_IN_CODE as symbol]: options.code ?? null } } })
  await flushPromises()
  return { wrapper, router, sockets }
}

beforeEach(() => {
  vi.unstubAllGlobals()
})

describe('router', () => {
  it('sends unknown paths to the dashboard and opens a session by id', async () => {
    const router = createAppRouter(createMemoryHistory())
    await router.push('/no/such/page')
    expect(router.currentRoute.value.name).toBe('dashboard')
    await router.push('/s/abc')
    expect(router.currentRoute.value.name).toBe('session')
    expect(router.currentRoute.value.params.id).toBe('abc')
  })
})

describe('App', () => {
  it('trades a sign-in code for a cookie, then connects with a ticket', async () => {
    const calls: string[] = []
    const { wrapper, sockets } = await mountApp({
      code: 'abcdef0123456789',
      fetch: async (url, init) => {
        calls.push(`${String(url).replace(/^.*\/api\//, '')} ${init?.body ?? ''}`)
        return String(url).endsWith('/login') ? json(200, { ok: true }) : json(200, { ticket: 'tk', expiresInSeconds: 30 })
      },
    })
    expect(calls).toEqual(['login {"code":"abcdef0123456789"}', 'ticket {}'])
    expect(sockets[0]?.url).toMatch(/\/ws\?ticket=tk$/)
    sockets[0]?.open()
    sockets[0]?.answer(helloResult(), 'hello')
    await flushPromises()
    expect(wrapper.find('[data-testid="connection-status"]').text()).toContain('connected')
    expect(wrapper.find('[data-testid="dashboard"]').exists()).toBe(true)
    expect(wrapper.find('.root').text()).toBe('/repo')
  })

  it('carries the WEB-7a live region for question arrivals at the app root', async () => {
    const { wrapper } = await mountApp({ fetch: async () => json(200, { ticket: 'tk', expiresInSeconds: 30 }) })
    const region = wrapper.find('[data-testid="question-announcement"]')
    expect(region.exists()).toBe(true)
    expect(region.attributes('role')).toBe('status')
    expect(region.attributes('aria-live')).toBe('polite')
  })

  it('surfaces a skipped untyped paste at the app root instead of swallowing it (NIT web58-1)', async () => {
    const { wrapper } = await mountApp({ fetch: async () => json(200, { ticket: 'tk', expiresInSeconds: 30 }) })
    expect(wrapper.find('[data-testid="paste-notice"]').exists()).toBe(false)
    await readPastedImages({ items: [{ kind: 'file', type: '', getAsFile: () => null }] })
    await flushPromises()
    const line = wrapper.find('[data-testid="paste-notice"]')
    expect(line.exists()).toBe(true)
    expect(line.text()).toContain('skipped')
    expect(line.attributes('role')).toBe('status')
  })

  it('routes a navigate message from the push worker to the session it names (CW-6)', async () => {
    const { wrapper, router } = await mountApp({ fetch: async () => json(200, { ticket: 'tk', expiresInSeconds: 30 }) })
    expect(wrapper.exists()).toBe(true)
    window.dispatchEvent(new MessageEvent('message', { data: { type: 'navigate', href: '#/s/s-42_x' } }))
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('session')
    expect(router.currentRoute.value.params.id).toBe('s-42_x')
  })

  it('refuses a forged or off-shape navigate message (CW-6)', async () => {
    // A message is data, not authority: the anchored session-route shape is
    // the only thing allowed to drive a navigation.
    const { router } = await mountApp({ fetch: async () => json(200, { ticket: 'tk', expiresInSeconds: 30 }) })
    const before = router.currentRoute.value.fullPath
    for (const data of [
      { type: 'navigate', href: 'javascript:alert(1)' },
      { type: 'navigate', href: '#/settings' },
      { type: 'navigate', href: '#/s/../etc' },
      { type: 'navigate', href: 42 },
      { type: 'other', href: '#/s/abc' },
      'not an object at all',
    ]) {
      window.dispatchEvent(new MessageEvent('message', { data }))
    }
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe(before)
  })

  it('stops listening once unmounted, so a dead app shell never re-routes (CW-6)', async () => {
    const { wrapper, router } = await mountApp({ fetch: async () => json(200, { ticket: 'tk', expiresInSeconds: 30 }) })
    wrapper.unmount()
    window.dispatchEvent(new MessageEvent('message', { data: { type: 'navigate', href: '#/s/gone-after-unmount' } }))
    await flushPromises()
    expect(router.currentRoute.value.name).not.toBe('session')
  })

  it('shows the sign-in form when the browser holds no session', async () => {
    const { wrapper, router } = await mountApp({ fetch: async () => json(401, { error: { kind: 'unauthorized', message: 'sign in' } }) })
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('login')
    expect(wrapper.find('[data-testid="login"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="connection-status"]').text()).toBe('signed out')
  })
})

describe('session drawer shell (F-m39 / UX-A F4)', () => {
  const signedIn = { fetch: async () => json(200, { ticket: 'tk', expiresInSeconds: 30 }) }

  it('puts a click-to-close scrim over the content while the drawer is open', async () => {
    const { wrapper } = await mountApp(signedIn)
    const layout = useLayoutStore()
    expect(wrapper.find('[data-testid="drawer-scrim"]').exists()).toBe(false)
    layout.toggleSidebar(true)
    await flushPromises()
    const scrim = wrapper.find('[data-testid="drawer-scrim"]')
    expect(scrim.exists()).toBe(true)
    // Presentational catcher: the accessible closes stay the ☰ button and Escape.
    expect(scrim.attributes('aria-hidden')).toBe('true')
    await scrim.trigger('click')
    expect(layout.sidebarOpen).toBe(false)
    expect(wrapper.find('[data-testid="drawer-scrim"]').exists()).toBe(false)
  })

  it('closes the drawer on Escape, on the window like the approvals drawer answers it', async () => {
    await mountApp(signedIn)
    const layout = useLayoutStore()
    layout.toggleSidebar(true)
    await flushPromises()
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(layout.sidebarOpen).toBe(false)
  })

  it('leaves the drawer alone when Escape was consumed or belongs to a layer above it', async () => {
    await mountApp(signedIn)
    const layout = useLayoutStore()
    layout.toggleSidebar(true)
    await flushPromises()
    // An overlay in front answers the key first: help/About/spine dialogs
    // preventDefault during dispatch (a flag set before dispatchEvent is
    // reset by the dispatch algorithm itself), so imitate them with a capture
    // listener — the exact phase the help chord runs in. The key rides from
    // the body like a real one: a window-TARGETED event fires every window
    // listener in registration order regardless of phase, hiding the ordering.
    const swallow = (event: Event) => event.preventDefault()
    window.addEventListener('keydown', swallow, true)
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))
    window.removeEventListener('keydown', swallow, true)
    expect(layout.sidebarOpen).toBe(true)
    // The sidebar's own inner surfaces — share dialog [role=dialog], row menu
    // [role=menu] — own their Escape legs; closing the drawer on top of them
    // would be a double-close. Cancelable but NOT prevented here: this leg
    // alone must discriminate the role guard. The host rides document.body,
    // not the (by default detached) wrapper — the key must really reach window.
    const host = document.createElement('div')
    host.setAttribute('role', 'dialog')
    const inner = document.createElement('button')
    host.append(inner)
    document.body.append(host)
    const innerKey = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })
    inner.dispatchEvent(innerKey)
    expect(innerKey.defaultPrevented).toBe(false)
    expect(layout.sidebarOpen).toBe(true)
    host.remove()
    // With nothing in front, the plain key does land — the guards are selective, not blanket.
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(layout.sidebarOpen).toBe(false)
  })

  it('is inert while the drawer is closed and answers to its unmount, dead shell never closing later state', async () => {
    const { wrapper } = await mountApp(signedIn)
    const layout = useLayoutStore()
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(layout.sidebarOpen).toBe(false)
    layout.toggleSidebar(true)
    await flushPromises()
    wrapper.unmount()
    // The window listener must not survive the shell: a later drawer from a
    // remounted app is not this instance's to close.
    layout.toggleSidebar(true)
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(layout.sidebarOpen).toBe(true)
  })
})
