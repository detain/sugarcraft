import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { loadKeymap } from '../../../lib/keymap'
import SettingsView from '../SettingsView.vue'

/**
 * C-WEB-4.b review fix (round 1): the rebind editor's display contract. The
 * row shown after a SUCCESSFUL capture-complete rebind is the one the clash
 * path already refreshed and the success path did not — until this spec
 * existed, the editor kept painting the old chord until the page remounted.
 */

const shown = (wrapper: ReturnType<typeof mount>, action: string) =>
  wrapper.find(`[data-testid="keymap-binding"][data-action="${action}"]`).text()

describe('SettingsView keymap editor (C-WEB-4.b)', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })

  it('shows the new chord in the row the moment a rebind lands', async () => {
    const wrapper = mount(SettingsView)
    expect(shown(wrapper, 'palette')).toBe('Ctrl/⌘+K')

    await wrapper.find('[data-testid="keymap-rebind-palette"]').trigger('click')
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'p', ctrlKey: true, bubbles: true, cancelable: true }))
    await nextTick()

    expect(shown(wrapper, 'palette')).toBe('Ctrl/⌘+P') // the stored truth AND the displayed row, no remount
    expect(wrapper.find('[data-testid="keymap-note"]').text()).toBe('Command palette → Ctrl/⌘+P.')
    expect(loadKeymap().palette).toEqual({ key: 'p', primary: 'ctrl-or-meta', alt: false, shift: false })
    expect(wrapper.find('[data-testid="keymap-reset-palette"]').attributes('disabled')).toBeUndefined()
  })

  it('keeps the row honest on the other success paths too', async () => {
    const wrapper = mount(SettingsView)
    await wrapper.find('[data-testid="keymap-rebind-tabs"]').trigger('click')
    window.dispatchEvent(new KeyboardEvent('keydown', { key: '3', code: 'Digit3', altKey: true, shiftKey: true, bubbles: true, cancelable: true }))
    await nextTick()
    expect(shown(wrapper, 'tabs')).toBe('Alt+Shift+1…9')

    await wrapper.find('[data-testid="keymap-reset-tabs"]').trigger('click')
    await nextTick()
    expect(shown(wrapper, 'tabs')).toBe('Alt+1…9') // resetOne was already refreshing; pinned beside the fix
    expect(localStorage.getItem('candy-code-web:tab-chord')).toBe('"alt"') // the mirror rides the reset too
  })
})
