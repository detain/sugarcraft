import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'
import Composer from '../Composer.vue'

/**
 * C-WEB-4.e: PDF attachments in the browser. A dropped, pasted or picked PDF
 * fills its own strip under the server's cap (two), rides `session.send`'s
 * fourth emit slot beside the images, and shares the images' busy/command
 * refusals — the composer mirrors the `documents_while_busy` and
 * `documents_with_command` laws so a refusal never appears as a mystery.
 */

const PDF_BYTES = Uint8Array.from(new TextEncoder().encode('%PDF-1.7 hello world'))
const PNG_BYTES = Uint8Array.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])

function pdfFile(name = 'paper.pdf'): File {
  return new File([PDF_BYTES], name, { type: 'application/pdf' })
}

function pngFile(name = 'shot.png'): File {
  return new File([PNG_BYTES], name, { type: 'image/png' })
}

function clipboardItem(file: File) {
  return { kind: 'file', type: file.type, getAsFile: () => file }
}

function mountComposer(props: Record<string, unknown> = {}) {
  return mount(Composer, { props: { modelValue: '', busy: false, commands: [], ...props } })
}

async function dropOnto(wrapper: ReturnType<typeof mountComposer>, items: unknown[]) {
  await wrapper.find('[data-testid="composer-root"]').trigger('drop', { dataTransfer: { types: ['Files'], items } })
  await flushPromises()
}

/** jsdom cannot open a picker, but the change handler only reads `files`. */
async function pickInto(wrapper: ReturnType<typeof mountComposer>, files: File[]) {
  const input = wrapper.find('[data-testid="composer-file-input"]')
  Object.defineProperty(input.element, 'files', { value: files, configurable: true })
  await input.trigger('change')
  await flushPromises()
}

const encoded = (bytes: Uint8Array) => btoa(String.fromCharCode(...bytes))

describe('Composer document attachments (C-WEB-4.e)', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('a dropped PDF fills the document strip and rides the send as documents', async () => {
    const wrapper = mountComposer({ modelValue: 'read this' })
    await dropOnto(wrapper, [clipboardItem(pdfFile())])
    const strip = wrapper.find('[data-testid="document-chips"]')
    expect(strip.exists()).toBe(true)
    expect(strip.text()).toContain('paper.pdf')
    await wrapper.find('[data-testid="composer-send"]').trigger('click')
    const send = wrapper.emitted('send')?.[0]
    expect(send?.[0]).toBe('read this')
    expect(send?.[2]).toEqual([])
    expect(send?.[3]).toEqual([{ name: 'paper.pdf', mime: 'application/pdf', data: encoded(PDF_BYTES) }])
  })

  it('one drop fills both strips and one send carries both arrays', async () => {
    const wrapper = mountComposer({ modelValue: 'compare these' })
    await dropOnto(wrapper, [clipboardItem(pngFile()), clipboardItem(pdfFile('spec.pdf'))])
    expect(wrapper.findAll('[data-testid="paste-chips"] img')).toHaveLength(1)
    expect(wrapper.findAll('[data-testid="document-chips"] .doc-chip')).toHaveLength(1)
    await wrapper.find('[data-testid="composer-send"]').trigger('click')
    const send = wrapper.emitted('send')?.[0]
    expect(send?.[2]).toEqual([{ name: 'shot.png', mime: 'image/png', data: encoded(PNG_BYTES) }])
    expect(send?.[3]).toEqual([{ name: 'spec.pdf', mime: 'application/pdf', data: encoded(PDF_BYTES) }])
  })

  it('the strip caps a drop at two documents, in drop order', async () => {
    const wrapper = mountComposer({ modelValue: 'a stack' })
    await dropOnto(wrapper, [clipboardItem(pdfFile('a.pdf')), clipboardItem(pdfFile('b.pdf')), clipboardItem(pdfFile('c.pdf'))])
    const chips = wrapper.findAll('[data-testid="document-chips"] .doc-chip')
    expect(chips).toHaveLength(2)
    expect(chips.map((chip) => chip.find('.doc-name').text())).toEqual(['a.pdf', 'b.pdf'])
  })

  it('a pasted PDF is a document, not a skipped image', async () => {
    const wrapper = mountComposer()
    await wrapper.find('[data-testid="composer-input"]').trigger('paste', { clipboardData: { items: [clipboardItem(pdfFile('clip.pdf'))] } })
    await flushPromises()
    expect(wrapper.find('[data-testid="document-chips"]').text()).toContain('clip.pdf')
    expect(wrapper.find('[data-testid="paste-chips"]').exists()).toBe(false)
  })

  it('the Attach picker converts admitted kinds and re-arms for the same file', async () => {
    const wrapper = mountComposer({ modelValue: 'picked' })
    const input = wrapper.find('[data-testid="composer-file-input"]')
    expect(input.attributes('accept')).toContain('application/pdf')
    expect(input.attributes('accept')).toContain('image/')
    expect(input.attributes('multiple')).toBeDefined()
    await pickInto(wrapper, [pdfFile('from-picker.pdf'), pngFile()])
    expect(wrapper.find('[data-testid="document-chips"]').text()).toContain('from-picker.pdf')
    expect(wrapper.findAll('[data-testid="paste-chips"] img')).toHaveLength(1)
    await wrapper.find('[data-testid="composer-send"]').trigger('click')
    const send = wrapper.emitted('send')?.[0]
    expect(send?.[3]).toMatchObject([{ name: 'from-picker.pdf', mime: 'application/pdf' }])
  })

  it('removing the document chip closes it out of the next send', async () => {
    const wrapper = mountComposer({ modelValue: 'watch this' })
    await dropOnto(wrapper, [clipboardItem(pdfFile()), clipboardItem(pdfFile('keep.pdf'))])
    await wrapper.find('[data-testid="document-chip-remove"]').trigger('click')
    await wrapper.find('[data-testid="composer-send"]').trigger('click')
    const send = wrapper.emitted('send')?.[0]
    expect(send?.[3]).toEqual([{ name: 'keep.pdf', mime: 'application/pdf', data: encoded(PDF_BYTES) }])
  })

  it('a document never rides a /command or !bang: the button names the reason', async () => {
    for (const draft of ['/model sonnet', '!ls']) {
      const wrapper = mountComposer({ modelValue: draft })
      await dropOnto(wrapper, [clipboardItem(pdfFile())])
      const send = wrapper.find('[data-testid="composer-send"]')
      expect(send.attributes('disabled')).toBeDefined()
      expect(send.attributes('title')).toBe('Documents ride a prose prompt; a /command or !bang takes none.')
      await send.trigger('click')
      expect(wrapper.emitted('send')).toBeUndefined()
    }
  })

  it('with images and a command the images leg answers first, as on the server', async () => {
    const wrapper = mountComposer({ modelValue: '/model sonnet' })
    await dropOnto(wrapper, [clipboardItem(pngFile()), clipboardItem(pdfFile())])
    expect(wrapper.find('[data-testid="composer-send"]').attributes('title')).toBe('Images ride a prose prompt; a /command or !bang takes none.')
  })

  it('mid-turn a document blocks queue and steer but interrupt is admitted', async () => {
    const wrapper = mountComposer({ modelValue: 'urgent', busy: true })
    await dropOnto(wrapper, [clipboardItem(pdfFile())])
    const send = wrapper.find('[data-testid="composer-send"]')
    expect(send.attributes('disabled')).toBeDefined()
    expect(send.attributes('title')).toContain('switch delivery to interrupt')
    await send.trigger('click')
    expect(wrapper.emitted('send')).toBeUndefined()
    await wrapper.find('[data-testid="composer-delivery"]').setValue('interrupt')
    expect(send.attributes('disabled')).toBeUndefined()
    await send.trigger('click')
    const emitted = wrapper.emitted('send')?.[0]
    expect(emitted?.[1]).toBe('interrupt')
    expect(emitted?.[3]?.[0]).toMatchObject({ name: 'paper.pdf' })
  })

  it('an accepted send (draft cleared by the parent) empties the document strip too', async () => {
    const wrapper = mountComposer({ modelValue: 'one and done' })
    await dropOnto(wrapper, [clipboardItem(pdfFile())])
    await wrapper.find('[data-testid="composer-send"]').trigger('click')
    await wrapper.setProps({ modelValue: '' })
    expect(wrapper.find('[data-testid="document-chips"]').exists()).toBe(false)
  })

  it('a dropped file the server would refuse never enters either strip', async () => {
    const svg = new File(['<svg/>'], 'art.svg', { type: 'image/svg+xml' })
    const txt = new File(['hello'], 'notes.txt', { type: 'text/plain' })
    const wrapper = mountComposer({ modelValue: 'nothing here' })
    await dropOnto(wrapper, [clipboardItem(svg), clipboardItem(txt)])
    expect(wrapper.find('[data-testid="document-chips"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="paste-chips"]').exists()).toBe(false)
    await wrapper.find('[data-testid="composer-send"]').trigger('click')
    const send = wrapper.emitted('send')?.[0]
    expect(send?.[2]).toEqual([])
    expect(send?.[3]).toEqual([])
  })
})
