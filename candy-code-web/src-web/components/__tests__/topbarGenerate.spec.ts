import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import App from '../../App.vue'
import { SIGN_IN_CODE } from '../../keys'
import { createAppRouter } from '../../router'
import { useConnectionStore } from '../../stores/connection'
import { usePanelsStore } from '../../stores/agents/panels'
import { FakeSocket, FakeTimers, helloResult } from '../../__tests__/fakes'

/**
 * P-IMG.c: the Generate panel gets a top-bar entry beside settings/about,
 * shown only when the server's hello advertises image generation, and a
 * click routes through the panels store exactly like the palette row does.
 *
 * Round-2 cure: the entry gates on either generation kind — a video-only
 * launch has a panel entry too, and the panel lands it on the video tab
 * (the generatePanel spec pins that half).
 */

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

async function mountApp(hello: Record<string, unknown>): Promise<{ wrapper: ReturnType<typeof mount>; socket: FakeSocket }> {
  vi.stubGlobal('fetch', vi.fn(async () => json(200, { ticket: 'tk', expiresInSeconds: 30 })))
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
  const wrapper = mount(App, { global: { plugins: [pinia, router], provide: { [SIGN_IN_CODE as symbol]: null } } })
  await flushPromises()
  const socket = sockets[0]
  if (!socket) throw new Error('the app never opened a socket')
  socket.open()
  socket.answer(hello, 'hello')
  await flushPromises()
  return { wrapper, socket }
}

const MEDIA_HELLO = { features: { methods: [], events: [], media: { image: true, video: false } } }

beforeEach(() => {
  vi.unstubAllGlobals()
})

describe('top-bar generate entry (P-IMG.c)', { retry: 0 }, () => {
  it('is hidden when the server advertises no media capabilities', async () => {
    const { wrapper } = await mountApp(helloResult())
    expect(wrapper.find('[data-testid="generate-button"]').exists()).toBe(false)
  })

  it('appears when the server refuses image generation but can generate video', async () => {
    const { wrapper } = await mountApp(helloResult({ features: { methods: [], events: [], media: { image: false, video: true } } }))
    expect(wrapper.find('[data-testid="generate-button"]').exists()).toBe(true)
  })

  it('appears beside settings/about when the server can generate images', async () => {
    const { wrapper } = await mountApp(helloResult(MEDIA_HELLO))
    const button = wrapper.find('[data-testid="generate-button"]')
    expect(button.exists()).toBe(true)
    expect(button.text()).toBe('generate')
    const order = wrapper.findAll('.topbar .link, .topbar .nav').map((node) => node.attributes('data-testid') ?? node.text())
    expect(order.indexOf('about-button')).toBeGreaterThan(-1)
    expect(order.indexOf('generate-button')).toBe(order.indexOf('about-button') + 1)
  })

  it('shows the generate panel through the panels store on click', async () => {
    const { wrapper } = await mountApp(helloResult(MEDIA_HELLO))
    const panels = usePanelsStore()
    expect(panels.open).toBe(false)
    await wrapper.find('[data-testid="generate-button"]').trigger('click')
    expect(panels.active).toBe('generate')
    expect(panels.open).toBe(true)
  })

  it('shows the video tab entry for video-only capabilities', async () => {
    const { wrapper } = await mountApp(helloResult({ features: { methods: [], events: [], media: { image: false, video: true } } }))
    const button = wrapper.find('[data-testid="generate-button"]')
    expect(button.exists()).toBe(true)
    const panels = usePanelsStore()
    await button.trigger('click')
    expect(panels.active).toBe('generate')
    expect(panels.open).toBe(true)
  })
})
