import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { MediaRef } from '../../protocol/generated'
import CanvasCard from '../CanvasCard.vue'

let pinia: ReturnType<typeof createPinia>

/** The mint answer the server gives for one artifact (docs/MEDIA.md). */
const TOKEN_URL = '/canvas/' + 'a'.repeat(64)

beforeEach(() => {
  pinia = createPinia()
  setActivePinia(pinia)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

function media(overrides: Partial<MediaRef> = {}): MediaRef {
  return { artifactId: 'canvas-1', kind: 'html' as unknown as MediaRef['kind'], path: '/media/s1/canvas-1.html', bytes: 0, mime: 'text/html; charset=utf-8', ...overrides }
}

function mintResponder(reply: () => Response): { calls: { url: string; body: unknown }[]; } {
  const calls: { url: string; body: unknown }[] = []
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    calls.push({ url, body: JSON.parse(String(init?.body ?? '{}')) })
    return reply()
  }))
  return { calls }
}

function mountCard(props: { media: MediaRef; sessionId?: string }) {
  return mount(CanvasCard, { props, global: { plugins: [pinia] } })
}

function okMint(): ReturnType<typeof mintResponder> {
  return mintResponder(() => new Response(JSON.stringify({ url: TOKEN_URL, expiresInSeconds: 60 }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
}

describe('CanvasCard', () => {
  it('loads nothing before the click', async () => {
    const { calls } = okMint()
    const wrapper = mountCard({ media: media(), sessionId: 's1' })
    await flushPromises()
    expect(calls).toHaveLength(0)
    expect(wrapper.find('iframe').exists()).toBe(false)
    expect(wrapper.attributes('data-phase')).toBe('idle')
    expect(wrapper.find('[data-testid="canvas-show"]').exists()).toBe(true)
  })

  it('mints with the session and artifact the ref names', async () => {
    const { calls } = okMint()
    const wrapper = mountCard({ media: media({ artifactId: 'canvas-7' }), sessionId: 's1' })
    await wrapper.find('[data-testid="canvas-show"]').trigger('click')
    await flushPromises()
    expect(calls).toHaveLength(1)
    expect(String(calls[0]?.url)).toMatch(/api\/canvas$/)
    expect(calls[0]?.body).toEqual({ sessionId: 's1', artifactId: 'canvas-7' })
  })

  it('the frame sandbox is exactly allow-scripts and the src is the minted url', async () => {
    okMint()
    const wrapper = mountCard({ media: media(), sessionId: 's1' })
    await wrapper.find('[data-testid="canvas-show"]').trigger('click')
    await flushPromises()
    expect(wrapper.attributes('data-phase')).toBe('ready')
    const frame = wrapper.find('[data-testid="canvas-frame"]')
    expect(frame.attributes('sandbox')).toBe('allow-scripts')
    expect(frame.attributes('referrerpolicy')).toBe('no-referrer')
    expect(frame.attributes('src')).toBe(TOKEN_URL)
  })

  it('a remount mints again — the first token is spent on the first load', async () => {
    const first = okMint()
    const wrapper = mountCard({ media: media(), sessionId: 's1' })
    await wrapper.find('[data-testid="canvas-show"]').trigger('click')
    await flushPromises()
    expect(first.calls).toHaveLength(1)
    wrapper.unmount()

    const second = mintResponder(() => new Response(JSON.stringify({ url: '/canvas/' + 'b'.repeat(64), expiresInSeconds: 60 }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    const again = mountCard({ media: media(), sessionId: 's1' })
    await again.find('[data-testid="canvas-show"]').trigger('click')
    await flushPromises()
    expect(second.calls).toHaveLength(1)
    expect(again.find('[data-testid="canvas-frame"]').attributes('src')).toBe('/canvas/' + 'b'.repeat(64))
  })

  it('hide and re-show mints again on the same card', async () => {
    const { calls } = okMint()
    const wrapper = mountCard({ media: media(), sessionId: 's1' })
    await wrapper.find('[data-testid="canvas-show"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-testid="canvas-hide"]').trigger('click')
    expect(wrapper.attributes('data-phase')).toBe('idle')
    await wrapper.find('[data-testid="canvas-show"]').trigger('click')
    await flushPromises()
    expect(calls).toHaveLength(2)
    expect(wrapper.find('[data-testid="canvas-frame"]').exists()).toBe(true)
  })

  it('a refusal shows an error state and no frame', async () => {
    mintResponder(() => new Response(JSON.stringify({ error: { kind: 'not_found', message: 'this canvas page does not exist, or its link has been used or has expired' } }), { status: 404, headers: { 'Content-Type': 'application/json' } }))
    const wrapper = mountCard({ media: media(), sessionId: 's1' })
    await wrapper.find('[data-testid="canvas-show"]').trigger('click')
    await flushPromises()
    expect(wrapper.attributes('data-phase')).toBe('error')
    expect(wrapper.find('[data-testid="canvas-error"]').text()).toContain('does not exist')
    expect(wrapper.find('iframe').exists()).toBe(false)
  })

  it('a card without a bound session refuses to dial at all', async () => {
    const { calls } = okMint()
    const wrapper = mountCard({ media: media() })
    await wrapper.find('[data-testid="canvas-show"]').trigger('click')
    await flushPromises()
    expect(calls).toHaveLength(0)
    expect(wrapper.attributes('data-phase')).toBe('error')
    expect(wrapper.find('[data-testid="canvas-error"]').text()).toContain('No session')
  })
})
