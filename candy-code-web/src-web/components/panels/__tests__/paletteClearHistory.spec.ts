/**
 * WEB-6-P2: the palette's “Clear prompt history” action is the UI the README
 * disclosure always promised — the only callers used to be sign-out and the
 * function's own export. The action lives beside the theme entry in the
 * `Actions` group and asks before it erases.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import { createAppRouter } from '../../../router'
import { usePanelsStore } from '../../../stores/agents/panels'
import { Wire } from '../../../stores/agents/__tests__/harness'
import { loadPromptHistory } from '../../../stores/promptHistory'
import { writeJson } from '../../../stores/storage'
import CommandPalette from '../CommandPalette.vue'

let pinia: ReturnType<typeof createPinia>

beforeEach(async () => {
  pinia = createPinia()
  setActivePinia(pinia)
  try {
    globalThis.localStorage?.clear()
  } catch {
    // no storage
  }
  const wire = new Wire()
  await wire.connect()
})

/** happy-dom's window carries no confirm() — the stub is the whole gate. */
function stubConfirm(answer: boolean): ReturnType<typeof vi.fn> {
  const fn = vi.fn(() => answer)
  window.confirm = fn as unknown as Window['confirm']
  return fn
}

afterEach(() => {
  ;(window as { confirm?: unknown }).confirm = undefined
  vi.restoreAllMocks()
})

async function openPalette() {
  const router = createAppRouter(createMemoryHistory())
  await router.push('/')
  await router.isReady()
  const wrapper = mount(CommandPalette, { props: { activeId: null }, global: { plugins: [pinia, router] }, attachTo: document.body })
  usePanelsStore().togglePalette(true)
  await flushPromises()
  return wrapper
}

describe('CommandPalette clear-prompt-history action', () => {
  it('lists “Clear prompt history” among the Actions', async () => {
    const wrapper = await openPalette()
    await wrapper.find('[data-testid="palette-input"]').setValue('clear prompt')
    expect(wrapper.findAll('[data-testid="palette-entry"]').map((e) => e.attributes('data-entry'))).toContain('clear-history')
    expect(wrapper.text()).toContain('Clear prompt history')
    wrapper.unmount()
  })

  it('confirming clears the stored ring', async () => {
    writeJson('promptHistory', ['remember this prompt'])
    expect(loadPromptHistory()).toEqual(['remember this prompt'])
    const confirm = stubConfirm(true)
    const wrapper = await openPalette()
    await wrapper.find('[data-testid="palette-input"]').setValue('clear prompt')
    const entry = wrapper.findAll('[data-testid="palette-entry"]').find((e) => e.attributes('data-entry') === 'clear-history')
    expect(entry).toBeDefined()
    await entry!.trigger('mousedown')
    await flushPromises()
    expect(confirm).toHaveBeenCalledOnce()
    expect(loadPromptHistory()).toEqual([])
    expect(globalThis.localStorage?.getItem('candy-code-web:promptHistory')).toBeNull()
    wrapper.unmount()
  })

  it('cancelling keeps the stored ring', async () => {
    writeJson('promptHistory', ['remember this prompt'])
    const confirm = stubConfirm(false)
    const wrapper = await openPalette()
    await wrapper.find('[data-testid="palette-input"]').setValue('clear prompt')
    const entry = wrapper.findAll('[data-testid="palette-entry"]').find((e) => e.attributes('data-entry') === 'clear-history')
    expect(entry).toBeDefined()
    await entry!.trigger('mousedown')
    await flushPromises()
    expect(confirm).toHaveBeenCalledOnce()
    expect(loadPromptHistory()).toEqual(['remember this prompt'])
    wrapper.unmount()
  })
})
