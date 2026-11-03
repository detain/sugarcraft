import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import type { ToolItem } from '../../stores/reducer'
import Transcript from '../Transcript.vue'

/**
 * MA-29: the store's `loadOlder` had no caller — older history was fetched by
 * nobody. Transcript now pulls one page when the reader sits on the top edge
 * (once per visit, re-armed only by leaving the edge), grows the list without
 * jumping the view, and offers both an explicit button and a loud inline error.
 * Rows ride the virtualiser (which renders none in happy-dom's 0px box — see
 * transcriptToggles.spec.ts), so these pins exercise the scroller contract:
 * scroll positions are defined onto the fake element directly, and a page that
 * lands grows the fake scrollHeight the way prepended rows would.
 */

function tool(key: string): ToolItem {
  return { kind: 'tool', key, toolCallId: `c-${key}`, name: 'Bash', arguments: { command: 'ls' }, status: 'done', content: 'ok' }
}

function settle(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

/** Mutable geometry the component reads through the scroller element. */
interface FakeBox {
  scrollTop: number
  scrollHeight: number
}

function mountPaging(props: {
  fetchOlder: () => Promise<string | null>
  canLoadOlder?: boolean
  loadingOlder?: boolean
  box?: FakeBox
}) {
  const box = props.box ?? { scrollTop: 0, scrollHeight: 100 }
  const wrapper = mount(Transcript, {
    props: {
      items: [tool('k1'), tool('k2')],
      fetchOlder: props.fetchOlder,
      canLoadOlder: props.canLoadOlder ?? true,
      loadingOlder: props.loadingOlder ?? false,
    },
  })
  const el = wrapper.find('.scroller').element as HTMLElement
  Object.defineProperty(el, 'scrollTop', { value: box.scrollTop, writable: true, configurable: true })
  Object.defineProperty(el, 'scrollHeight', { get: () => box.scrollHeight, configurable: true })
  return { wrapper, el, box }
}

/** A page stub that prepends rows: every success grows the scrollHeight. */
function growingPage(box: FakeBox, delta = 40) {
  return vi.fn(async (): Promise<string | null> => {
    box.scrollHeight += delta
    return null
  })
}

async function scroll(el: HTMLElement, to: number): Promise<void> {
  el.scrollTop = to
  el.dispatchEvent(new Event('scroll'))
  await nextTick()
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('top-edge older paging (MA-29)', () => {
  it('a visit to the top edge pulls exactly one page, and the page re-anchors the view', async () => {
    const box = { scrollTop: 0, scrollHeight: 100 }
    const fetchOlder = growingPage(box)
    const { wrapper, el } = mountPaging({ fetchOlder, box })
    await scroll(el, 0)
    expect(fetchOlder).toHaveBeenCalledTimes(1)
    await settle()
    // Re-anchor: what was at the top edge stays there — scrollTop moved by the delta.
    expect(el.scrollTop).toBe(40)
    // Scrolling away and back re-arms one more pull; sitting at the edge does not loop.
    await scroll(el, 200)
    expect(fetchOlder).toHaveBeenCalledTimes(1)
    await scroll(el, 0)
    expect(fetchOlder).toHaveBeenCalledTimes(2)
    await settle()
    expect(wrapper.find('[data-testid="transcript-older-error"]').exists()).toBe(false)
  })

  it('an in-flight page is never doubled, by scroll or by button', async () => {
    const fetchOlder = vi.fn(() => new Promise<string | null>(() => undefined)) // never settles
    const { wrapper, el } = mountPaging({ fetchOlder })
    await scroll(el, 0)
    await scroll(el, 0)
    await wrapper.find('[data-testid="transcript-load-older"]').trigger('click')
    expect(fetchOlder).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid="transcript-load-older"]').attributes('disabled')).toBeDefined()
  })

  it('the button pages on demand even before the reader reaches the edge', async () => {
    const box = { scrollTop: 0, scrollHeight: 100 }
    const fetchOlder = growingPage(box)
    const { wrapper } = mountPaging({ fetchOlder, box })
    await wrapper.find('[data-testid="transcript-load-older"]').trigger('click')
    await settle()
    expect(fetchOlder).toHaveBeenCalledTimes(1)
  })

  it('a failed page shows the server text verbatim as an alert, and the next visit retries', async () => {
    const box = { scrollTop: 0, scrollHeight: 100 }
    const fetchOlder = growingPage(box)
    fetchOlder.mockResolvedValueOnce('the server is not answering.')
    const { wrapper, el } = mountPaging({ fetchOlder, box })
    await scroll(el, 0)
    await settle()
    const problem = wrapper.find('[data-testid="transcript-older-error"]')
    expect(problem.exists()).toBe(true)
    expect(problem.attributes('role')).toBe('alert')
    expect(problem.text()).toBe('the server is not answering.')
    expect(fetchOlder).toHaveBeenCalledTimes(1) // a failed page does not enter the retry chain
    await scroll(el, 200)
    await scroll(el, 0)
    await settle()
    expect(fetchOlder).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="transcript-older-error"]').exists()).toBe(false)
  })

  it('without more-older to load there is no button and the sentinel never fires', async () => {
    const fetchOlder = vi.fn(async () => null as string | null)
    const { wrapper, el } = mountPaging({ fetchOlder, canLoadOlder: false })
    await scroll(el, 0)
    await settle()
    expect(fetchOlder).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="transcript-load-older"]').exists()).toBe(false)
  })

  it('while the store reports a page in flight the sentinel stands down', async () => {
    const fetchOlder = vi.fn(async () => null as string | null)
    const { el } = mountPaging({ fetchOlder, loadingOlder: true })
    await scroll(el, 0)
    await settle()
    expect(fetchOlder).not.toHaveBeenCalled()
  })
})
