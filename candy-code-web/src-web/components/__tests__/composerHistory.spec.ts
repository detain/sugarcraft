import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'
import { clearPromptHistory, loadPromptHistory } from '../../stores/promptHistory'
import { writeJson } from '../../stores/storage'
import Composer from '../Composer.vue'

function mountComposer(props: Record<string, unknown> = {}) {
  return mount(Composer, { props: { modelValue: '', busy: false, commands: [], ...props } })
}

async function press(wrapper: ReturnType<typeof mountComposer>, key: string, ctrl = false) {
  await wrapper.find('[data-testid="composer-input"]').trigger('keydown', { key, ctrlKey: ctrl })
}

describe('Composer prompt history (WEB-5a-i)', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('a sent prompt lands in the ring; blanks and repeats do not', async () => {
    const wrapper = mountComposer()
    await wrapper.setProps({ modelValue: 'ship it' })
    await press(wrapper, 'Enter')
    expect(loadPromptHistory()).toEqual(['ship it'])
    // A blank send is refused before the ring is touched.
    await wrapper.setProps({ modelValue: '   ' })
    await press(wrapper, 'Enter')
    expect(wrapper.emitted('send')).toHaveLength(1)
    expect(loadPromptHistory()).toEqual(['ship it'])
    // Re-sending the same prompt adds nothing (head dedupe).
    await wrapper.setProps({ modelValue: 'ship it' })
    await press(wrapper, 'Enter')
    expect(loadPromptHistory()).toEqual(['ship it'])
  })

  it('Ctrl+Up walks the ring oldest-ward, Ctrl+Down walks back to the draft', async () => {
    writeJson('promptHistory', ['newest', 'older', 'oldest'])
    const wrapper = mountComposer({ modelValue: 'a draft' })
    await press(wrapper, 'ArrowUp', true)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['newest'])
    await press(wrapper, 'ArrowUp', true)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['older'])
    // The Enter popup must not steal the chord: no send fired.
    expect(wrapper.emitted('send')).toBeUndefined()
    await press(wrapper, 'ArrowDown', true)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['newest'])
    await press(wrapper, 'ArrowDown', true)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['a draft'])
    // Below the draft the chord is the user's again.
    const emissions = wrapper.emitted('update:modelValue')?.length ?? 0
    await press(wrapper, 'ArrowDown', true)
    expect(wrapper.emitted('update:modelValue')).toHaveLength(emissions)
  })

  it('Ctrl+Up at the oldest end is consumed without change, and typing drops the position', async () => {
    writeJson('promptHistory', ['only'])
    const wrapper = mountComposer({ modelValue: 'work in progress' })
    await press(wrapper, 'ArrowUp', true)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['only'])
    await press(wrapper, 'ArrowUp', true)
    expect(wrapper.emitted('update:modelValue')).toHaveLength(1)
    // After typing, the next Ctrl+Up starts from the newest again.
    await wrapper.find('[data-testid="composer-input"]').setValue('typed over')
    await press(wrapper, 'ArrowUp', true)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['only'])
  })

  it('without a stored ring the chords stay untouched', async () => {
    const wrapper = mountComposer({ modelValue: 'anything' })
    await press(wrapper, 'ArrowUp', true)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })
})

/**
 * WEB-6-P1: a clear made in another tab — sign-out there, DevTools, a
 * whole-storage wipe — must drop this tab's in-memory ring too. Holding it
 * was the resurrection bug: the next send re-persisted the whole pre-clear
 * history from memory.
 */
describe('Composer prompt-history clear propagation (WEB-6-P1)', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('a cross-tab storage event empties the mounted ring: the next send persists only the new prompt', async () => {
    writeJson('promptHistory', ['one', 'two'])
    const wrapper = mountComposer({ modelValue: 'draft' })
    window.dispatchEvent(new StorageEvent('storage', { key: 'candy-code-web:promptHistory', newValue: null }))
    await wrapper.setProps({ modelValue: 'ship it' })
    await press(wrapper, 'Enter')
    expect(loadPromptHistory()).toEqual(['ship it'])
    // Recall sees only the post-clear prompt; the resurrected entries are gone.
    await press(wrapper, 'ArrowUp', true)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['ship it'])
    await press(wrapper, 'ArrowUp', true)
    expect(wrapper.emitted('update:modelValue')).toHaveLength(1)
    wrapper.unmount()
  })

  it('a same-tab clearPromptHistory() does the same (the storage event never fires here)', async () => {
    writeJson('promptHistory', ['one', 'two'])
    const wrapper = mountComposer({ modelValue: 'draft' })
    clearPromptHistory()
    await wrapper.setProps({ modelValue: 'send now' })
    await press(wrapper, 'Enter')
    expect(loadPromptHistory()).toEqual(['send now'])
    wrapper.unmount()
  })

  it('a whole-storage clear event (key null) empties the mounted ring', async () => {
    writeJson('promptHistory', ['one', 'two'])
    const wrapper = mountComposer({ modelValue: 'draft' })
    window.dispatchEvent(new StorageEvent('storage', { key: null, newValue: null }))
    await wrapper.setProps({ modelValue: 'only me' })
    await press(wrapper, 'Enter')
    expect(loadPromptHistory()).toEqual(['only me'])
    wrapper.unmount()
  })

  it('an unrelated key removal leaves the ring untouched', async () => {
    writeJson('promptHistory', ['keep me'])
    const wrapper = mountComposer({ modelValue: 'x' })
    window.dispatchEvent(new StorageEvent('storage', { key: 'candy-code-web:draft', newValue: null }))
    await wrapper.setProps({ modelValue: 'again' })
    await press(wrapper, 'Enter')
    expect(loadPromptHistory()).toEqual(['again', 'keep me'])
    wrapper.unmount()
  })
})
