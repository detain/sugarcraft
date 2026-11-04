import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import App from '../App.vue'
import { SIGN_IN_CODE } from '../keys'
import { createAppRouter } from '../router'
import { useConnectionStore } from '../stores/connection'
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
