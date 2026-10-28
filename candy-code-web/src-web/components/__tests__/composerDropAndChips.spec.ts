import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'
import { loadPromptHistory } from '../../stores/promptHistory'
import { writeJson } from '../../stores/storage'
import Composer from '../Composer.vue'

const PNG_BYTES = Uint8Array.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])

function pngFile(name = 'shot.png'): File {
  return new File([PNG_BYTES], name, { type: 'image/png' })
}

function clipboardItem(file: File) {
  return { kind: 'file', type: file.type, getAsFile: () => file }
}

function mountComposer(props: Record<string, unknown> = {}) {
  return mount(Composer, { props: { modelValue: '', busy: false, commands: [], ...props } })
}

async function press(wrapper: ReturnType<typeof mountComposer>, key: string, ctrl = false) {
  await wrapper.find('[data-testid="composer-input"]').trigger('keydown', { key, ctrlKey: ctrl })
}

/**
 * C-WEB-4.a: dropping files is a paste that arrived by mouse — same reader,
 * same MAX_PASTED_IMAGES strip, same chips riding the send.
 *
 * `dragEventTo` mirrors the drop-dispatch idiom (a cancelable drop event
 * over `composer-root`) but keeps the event handle, so a test can read
 * `defaultPrevented` — the only way to see which drops the component claims.
 */
function dragEventTo(root: Element, dataTransfer: Record<string, unknown>): Event {
  const event = new Event('drop', { bubbles: true, cancelable: true })
  Object.defineProperty(event, 'dataTransfer', { value: dataTransfer })
  root.dispatchEvent(event)
  return event
}

describe('Composer drag-drop images (C-WEB-4.a)', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('a drop over the composer previews an image chip that rides the send payload', async () => {
    const wrapper = mountComposer({ modelValue: 'see this' })
    await wrapper.find('[data-testid="composer-root"]').trigger('drop', { dataTransfer: { types: ['Files'], items: [clipboardItem(pngFile())] } })
    await flushPromises()
    const chip = wrapper.find('[data-testid="paste-chips"] img')
    expect(chip.exists()).toBe(true)
    expect(chip.attributes('src')).toMatch(/^data:image\/png;base64,/)
    await wrapper.find('[data-testid="composer-send"]').trigger('click')
    const send = wrapper.emitted('send')?.[0]
    expect(send?.[0]).toBe('see this')
    expect(send?.[2]).toEqual([{ name: 'shot.png', mime: 'image/png', data: btoa(String.fromCharCode(...PNG_BYTES)) }])
  })

  it('the strip caps a drop at MAX_PASTED_IMAGES, in drop order', async () => {
    const wrapper = mountComposer({ modelValue: 'six shots' })
    const six = Array.from({ length: 6 }, (_, i) => clipboardItem(pngFile(`s${i}.png`)))
    await wrapper.find('[data-testid="composer-root"]').trigger('drop', { dataTransfer: { types: ['Files'], items: six } })
    await flushPromises()
    expect(wrapper.findAll('[data-testid="paste-chips"] img')).toHaveLength(4)
  })

  it('a drop of nothing imageable adds nothing (a text/plain drag is the browser\'s)', async () => {
    const wrapper = mountComposer()
    await wrapper.find('[data-testid="composer-root"]').trigger('drop', { dataTransfer: { types: ['text/plain'], items: [{ kind: 'string', type: 'text/plain', getAsFile: () => null }] } })
    await flushPromises()
    expect(wrapper.find('[data-testid="paste-chips"]').exists()).toBe(false)
  })

  it('the component claims only Files drops: defaultPrevented is true for files, false for a text drag', () => {
    const wrapper = mountComposer()
    const root = wrapper.find('[data-testid="composer-root"]').element
    // A Files drop is ours: the handler preventDefaults it synchronously.
    const fileDrop = dragEventTo(root, { types: ['Files'], items: [] })
    expect(fileDrop.defaultPrevented).toBe(true)
    // A text/plain drag is the browser's: the guard answers before any
    // await and never cancels, so native insertion into the textarea lives.
    const textDrop = dragEventTo(root, { types: ['text/plain'], items: [] })
    expect(textDrop.defaultPrevented).toBe(false)
  })
})

/**
 * C-WEB-4.a: Ctrl+G opens the HistorySearch overlay with the TUI's semantics
 * — filter the ring by containment newest-first, Enter fills the draft
 * without submitting, Esc restores the draft it was opened on.
 */
describe('Composer Ctrl+G history search (C-WEB-4.a)', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('Ctrl+G filters newest-first; Enter fills the draft and never sends', async () => {
    writeJson('promptHistory', ['deploy the fix', 'ship it', 'deploy docs'])
    const wrapper = mountComposer({ modelValue: 'my draft' })
    await press(wrapper, 'g', true)
    expect(wrapper.find('[data-testid="history-search"]').exists()).toBe(true)
    await wrapper.find('[data-testid="history-search-input"]').setValue('deploy')
    const rows = wrapper.findAll('[data-testid="history-search-entry"]')
    expect(rows.map((row) => row.text())).toEqual(['deploy the fix', 'deploy docs'])
    expect(wrapper.emitted('send')).toBeUndefined()
    await wrapper.find('[data-testid="history-search-input"]').trigger('keydown', { key: 'Enter' })
    expect(wrapper.find('[data-testid="history-search"]').exists()).toBe(false)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['deploy the fix'])
    expect(wrapper.emitted('send')).toBeUndefined()
  })

  it('Esc restores the seed draft; the overlay never wrote it while open', async () => {
    writeJson('promptHistory', ['only entry'])
    const wrapper = mountComposer({ modelValue: 'work in progress' })
    await press(wrapper, 'g', true)
    const emissions = wrapper.emitted('update:modelValue')?.length ?? 0
    expect(emissions).toBe(0) // nothing touched the draft between open and Esc
    await wrapper.find('[data-testid="history-search-input"]').setValue('only')
    await wrapper.find('[data-testid="history-search-input"]').trigger('keydown', { key: 'Escape' })
    expect(wrapper.find('[data-testid="history-search"]').exists()).toBe(false)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['work in progress'])
  })

  it('arrows walk the hit list with wrap, and a no-match Enter consumes the key', async () => {
    writeJson('promptHistory', ['alpha one', 'beta', 'alpha two'])
    const wrapper = mountComposer({ modelValue: 'draft' })
    await press(wrapper, 'g', true)
    await wrapper.find('[data-testid="history-search-input"]').setValue('ALPHA') // case-insensitive
    expect(wrapper.findAll('[data-testid="history-search-entry"]')).toHaveLength(2)
    await wrapper.find('[data-testid="history-search-input"]').trigger('keydown', { key: 'ArrowDown' })
    await wrapper.find('[data-testid="history-search-input"]').trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['alpha two'])
    await press(wrapper, 'g', true)
    await wrapper.find('[data-testid="history-search-input"]').setValue('zzz')
    expect(wrapper.find('[data-testid="history-search-none"]').exists()).toBe(true)
    await wrapper.find('[data-testid="history-search-input"]').trigger('keydown', { key: 'Enter' })
    // No match: Enter consumes the key, the overlay stays up, the draft stands.
    expect(wrapper.find('[data-testid="history-search"]').exists()).toBe(true)
    expect(wrapper.emitted('update:modelValue')).toHaveLength(1)
    await wrapper.find('[data-testid="history-search-input"]').trigger('keydown', { key: 'Escape' })
    expect(wrapper.find('[data-testid="history-search"]').exists()).toBe(false)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['draft'])
  })

  it('with an empty ring Ctrl+G does not open (the TUI answers null)', async () => {
    const wrapper = mountComposer({ modelValue: 'anything' })
    await press(wrapper, 'g', true)
    expect(wrapper.find('[data-testid="history-search"]').exists()).toBe(false)
  })
})

/**
 * C-WEB-4.a: the ISS-051 collapse, client-side — a long paste becomes a
 * `[Pasted #n +X lines]` placeholder backed by a chip map, submit expands it,
 * and deleting the placeholder drops the filed payload with it.
 */
describe('Composer paste chips (C-WEB-4.a)', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  const LONG = Array.from({ length: 12 }, (_, i) => `line ${i}`).join('\n')

  function textClipboard(text: string) {
    return { items: [], getData: () => text }
  }

  async function pasteInto(wrapper: ReturnType<typeof mountComposer>, text: string) {
    await wrapper.find('[data-testid="composer-input"]').trigger('paste', { clipboardData: textClipboard(text) })
    await flushPromises()
  }

  it('a long paste inserts a chip instead of the payload, and submit sends the expansion', async () => {
    const wrapper = mountComposer()
    await pasteInto(wrapper, LONG)
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([`[Pasted #1 +12 lines]`])
    await wrapper.setProps({ modelValue: '[Pasted #1 +12 lines]' })
    await press(wrapper, 'Enter')
    const send = wrapper.emitted('send')?.[0]
    expect(send?.[0]).toBe(LONG)
    // The ring stores what was sent, expanded — never the placeholder.
    expect(loadPromptHistory()).toEqual([LONG])
  })

  it('a short paste falls through untouched (the browser still inserts it)', async () => {
    const wrapper = mountComposer()
    await pasteInto(wrapper, 'just a line')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('deleting the placeholder drops the chip: re-typed chip text stays literal', async () => {
    const wrapper = mountComposer()
    await pasteInto(wrapper, LONG)
    await wrapper.setProps({ modelValue: '[Pasted #1 +12 lines]' })
    await wrapper.setProps({ modelValue: 'changed my mind' }) // the deletion prunes the map
    await wrapper.setProps({ modelValue: '[Pasted #1 +12 lines] typed back' })
    await press(wrapper, 'Enter')
    const send = wrapper.emitted('send')?.[0]
    expect(send?.[0]).toBe('[Pasted #1 +12 lines] typed back')
  })

  it('two long pastes carry distinct numbers and both expand at submit', async () => {
    const SECOND = Array.from({ length: 11 }, (_, i) => `second ${i}`).join('\n')
    const wrapper = mountComposer()
    await pasteInto(wrapper, LONG) // filed as chip #1
    await wrapper.setProps({ modelValue: '[Pasted #1 +12 lines]\n' })
    await pasteInto(wrapper, SECOND) // filed as chip #2 (caret placement is the parent's draft)
    await wrapper.setProps({ modelValue: '[Pasted #1 +12 lines]\n[Pasted #2 +11 lines]' })
    await press(wrapper, 'Enter')
    const send = wrapper.emitted('send')?.[0]
    expect(send?.[0]).toBe(`${LONG}\n${SECOND}`)
  })
})
