import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { objectUrls } from '../../lib/media'
import type { MediaRef } from '../../protocol/generated'
import type { ToolItem } from '../../stores/reducer'
import { Wire } from '../../stores/agents/__tests__/harness'
import MediaCard from '../MediaCard.vue'
import ToolCard from '../ToolCard.vue'

let pinia: ReturnType<typeof createPinia>
let wire: Wire

beforeEach(async () => {
  pinia = createPinia()
  setActivePinia(pinia)
  wire = new Wire()
  await wire.connect()
})

afterEach(() => {
  vi.restoreAllMocks()
})

function media(overrides: Partial<MediaRef> = {}): MediaRef {
  return { artifactId: 'a1', kind: 'image', path: '/media/s1/a1.png', bytes: 0, mime: 'image/png', ...overrides }
}

function row(overrides: Partial<{ artifactId: string; offset: number; bytes: string; totalBytes: number; eof: boolean; mime: string }> = {}) {
  return { artifactId: 'a1', offset: 0, bytes: btoa('PNG!'), totalBytes: 4, eof: true, mime: 'image/png', ...overrides }
}

function mountCard(props: { media: MediaRef; sessionId?: string }) {
  return mount(MediaCard, { props, global: { plugins: [pinia] } })
}

describe('MediaCard', () => {
  it('fetches an image eagerly and renders the blob url', async () => {
    const wrapper = mountCard({ media: media(), sessionId: 's1' })
    await flushPromises()
    expect(wrapper.find('[data-testid="media-loading"]').exists()).toBe(true)
    const frames = wire.sent('media.fetch')
    expect(frames).toHaveLength(1)
    expect(frames[0]).toMatchObject({
      method: 'media.fetch',
      params: { sessionId: 's1', artifactId: 'a1', offset: 0, limit: 1_048_576 },
    })
    await wire.answer('media.fetch', row())
    expect(wrapper.attributes('data-phase')).toBe('ready')
    const img = wrapper.find('img')
    expect(img.attributes('src')).toMatch(/^blob:/)
    expect(img.attributes('alt')).toBe('image a1')
    expect(wrapper.find('[data-testid="media-badge"]').text()).toBe('image')
  })

  it('keeps a video idle until the ▶ tap, then plays it', async () => {
    const wrapper = mountCard({ media: media({ kind: 'video', artifactId: 'v1', mime: 'video/mp4' }), sessionId: 's1' })
    await flushPromises()
    expect(wire.sent('media.fetch')).toHaveLength(0)
    expect(wrapper.find('video').exists()).toBe(false)
    await wrapper.find('[data-testid="media-play"]').trigger('click')
    await wire.answer('media.fetch', { artifactId: 'v1', offset: 0, bytes: btoa('MP4'), totalBytes: 3, eof: true, mime: 'video/mp4' })
    expect(wrapper.find('[data-testid="media-video"]').attributes('src')).toMatch(/^blob:/)
  })

  it('shows the honest unsupported line when the browser rejects the codec', async () => {
    const wrapper = mountCard({ media: media({ kind: 'video', mime: 'video/mp4' }), sessionId: 's1' })
    await wrapper.find('[data-testid="media-play"]').trigger('click')
    await wire.answer('media.fetch', { artifactId: 'a1', offset: 0, bytes: btoa('MP4'), totalBytes: 3, eof: true, mime: 'video/mp4' })
    await wrapper.find('[data-testid="media-video"]').trigger('error')
    expect(wrapper.find('[data-testid="media-unsupported"]').text()).toContain('cannot play video/mp4')
  })

  it('surfaces a refusal as a named error line, never a blank card', async () => {
    const wrapper = mountCard({ media: media(), sessionId: 's1' })
    await flushPromises()
    await wire.fail('media.fetch', -32004, 'media_artifact_not_found', 'no such artifact')
    expect(wrapper.attributes('data-phase')).toBe('error')
    expect(wrapper.find('[data-testid="media-error"]').text()).toBe('no such artifact')
    expect(wrapper.find('img').exists()).toBe(false)
  })

  it('refuses to fetch without a session and asks the server nothing', async () => {
    const wrapper = mountCard({ media: media() })
    await flushPromises()
    expect(wrapper.find('[data-testid="media-error"]').text()).toContain('No session')
    expect(wire.sent('media.fetch')).toHaveLength(0)
  })

  it('walks slices through to eof', async () => {
    const wrapper = mountCard({ media: media(), sessionId: 's1' })
    await flushPromises()
    await wire.answer('media.fetch', { artifactId: 'a1', offset: 0, bytes: btoa('ab'), totalBytes: 4, eof: false })
    await flushPromises()
    expect(wire.sent('media.fetch').map((f) => (f.params as Record<string, unknown>).offset)).toEqual([0, 2])
    await wire.answer('media.fetch', { artifactId: 'a1', offset: 2, bytes: btoa('cd'), totalBytes: 4, eof: true, mime: 'image/png' })
    expect(wrapper.attributes('data-phase')).toBe('ready')
  })

  it('revokes the object URL on unmount (the leak law)', async () => {
    const created: string[] = []
    vi.spyOn(objectUrls, 'create').mockImplementation(() => {
      created.push('blob:fake-1')
      return 'blob:fake-1'
    })
    const revoked: string[] = []
    vi.spyOn(objectUrls, 'revoke').mockImplementation((url) => void revoked.push(url))
    const wrapper = mountCard({ media: media(), sessionId: 's1' })
    await flushPromises()
    await wire.answer('media.fetch', row())
    expect(wrapper.find('img').attributes('src')).toBe('blob:fake-1')
    wrapper.unmount()
    expect(revoked).toEqual(['blob:fake-1'])
  })

  it('offers a download row when the image mime is not playable', async () => {
    const wrapper = mountCard({ media: media({ mime: 'application/octet-stream' }), sessionId: 's1' })
    await flushPromises()
    await wire.answer('media.fetch', { artifactId: 'a1', offset: 0, bytes: btoa('B'), totalBytes: 1, eof: true, mime: 'application/octet-stream' })
    expect(wrapper.find('[data-testid="media-file"]').text()).toContain('Download the application/octet-stream artifact')
    expect(wrapper.find('img').exists()).toBe(false)
  })

  it('expands the infotext digest when the ref carries one', () => {
    const wrapper = mountCard({ media: media({ infotextDigest: 'deadbeef', indexLabel: 'sample-3' }) })
    expect(wrapper.find('[data-testid="media-digest"]').text()).toContain('deadbeef')
    expect(wrapper.text()).toContain('sample-3')
  })

  it('opens and closes the click-through lightbox', async () => {
    const wrapper = mountCard({ media: media(), sessionId: 's1' })
    await flushPromises()
    await wire.answer('media.fetch', row())
    await wrapper.find('[data-testid="media-image"]').trigger('click')
    expect(wrapper.find('[data-testid="media-lightbox"]').exists()).toBe(true)
    await wrapper.find('[data-testid="lightbox-close"]').trigger('click')
    expect(wrapper.find('[data-testid="media-lightbox"]').exists()).toBe(false)
  })

  it('shows an honest error row when the browser cannot decode the bytes', async () => {
    const wrapper = mountCard({ media: media(), sessionId: 's1' })
    await flushPromises()
    await wire.answer('media.fetch', row())
    expect(wrapper.find('img').exists()).toBe(true)
    await wrapper.find('[data-testid="media-image"] img').trigger('error')
    expect(wrapper.find('[data-testid="media-image"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="media-error"]').text()).toContain('failed to decode')
  })

  it('discards a superseded load without ever creating its object URL', async () => {
    const created: string[] = []
    vi.spyOn(objectUrls, 'create').mockImplementation(() => {
      created.push('blob:superseded')
      return 'blob:superseded'
    })
    const wrapper = mountCard({ media: media(), sessionId: 's1' })
    await flushPromises()
    // a1's fetch is in flight; swapping the artifact supersedes it with a2.
    await wrapper.setProps({ media: media({ artifactId: 'a2' }) })
    await flushPromises()
    expect(wire.sent('media.fetch')).toHaveLength(2)
    // Resolve the OLD request first: its continuation must hit the token
    // gate and create nothing.
    const old = wire.sent('media.fetch')[0]
    if (!old) throw new Error('a1 fetch was never sent')
    wire.socket().answer(row(), old.id)
    await flushPromises()
    expect(created).toHaveLength(0)
    expect(wrapper.attributes('data-phase')).toBe('loading')
    // The surviving load still lands normally.
    await wire.answer('media.fetch', { ...row(), artifactId: 'a2' })
    expect(created).toEqual(['blob:superseded'])
    expect(wrapper.attributes('data-phase')).toBe('ready')
  })
})

function tool(overrides: Partial<ToolItem> = {}): ToolItem {
  return { kind: 'tool', key: 'k', toolCallId: 'c1', name: 'GenerateImage', arguments: {}, status: 'done', content: 'saved', ...overrides }
}

describe('ToolCard media arm', () => {
  it('renders a MediaCard per ref, threaded with the session id, after the output', async () => {
    const wrapper = mount(ToolCard, {
      props: { item: tool({ media: [media({ artifactId: 'a1' }), media({ artifactId: 'a2', kind: 'video', mime: 'video/mp4' })] }), sessionId: 's9' },
      global: { plugins: [pinia] },
    })
    expect(wrapper.find('[data-testid="tool-media"]').exists()).toBe(false)
    await wrapper.find('button.head').trigger('click')
    const cards = wrapper.findAll('[data-testid="media-card"]')
    expect(cards.map((c) => c.attributes('data-kind'))).toEqual(['image', 'video'])
    // The eager image asked the server through the threaded session id.
    await flushPromises()
    expect(wire.sent('media.fetch').map((f) => f.params)).toEqual([{ sessionId: 's9', artifactId: 'a1', offset: 0, limit: 1_048_576 }])
  })

  it('marks an honest truncation when refs were dropped', async () => {
    const wrapper = mount(ToolCard, {
      props: { item: tool({ media: [media()], mediaTruncated: true }), sessionId: 's9' },
      global: { plugins: [pinia] },
    })
    await wrapper.find('button.head').trigger('click')
    expect(wrapper.find('[data-testid="media-truncated"]').text()).toContain('not listed')
  })

  it('stays byte-familiar when the event carried no media at all', async () => {
    const wrapper = mount(ToolCard, { props: { item: tool() }, global: { plugins: [pinia] } })
    await wrapper.find('button.head').trigger('click')
    expect(wrapper.find('[data-testid="tool-media"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="media-truncated"]').exists()).toBe(false)
  })
})
