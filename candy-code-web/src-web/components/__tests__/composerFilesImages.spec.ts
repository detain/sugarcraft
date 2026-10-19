import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import type { CommandInfo } from '../../stores/session'
import Composer from '../Composer.vue'

const PNG_BYTES = Uint8Array.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])

function pngFile(name = 'shot.png'): File {
  return new File([PNG_BYTES], name, { type: 'image/png' })
}

function clipboardItem(file: File) {
  return { kind: 'file', type: file.type, getAsFile: () => file }
}

const compact: CommandInfo = { name: 'compact', description: 'Compact', source: 'builtin', runsIn: 'server' }

function mountComposer(props: Record<string, unknown> = {}) {
  return mount(Composer, { props: { modelValue: '', busy: false, commands: [], ...props } })
}

async function typeInto(wrapper: ReturnType<typeof mountComposer>, modelValue: string) {
  await wrapper.setProps({ modelValue })
  await flushPromises()
}

describe('Composer @file popup (WEB-5b)', () => {
  it('opens fs.search on a trailing mention and Enter accepts the highlighted file', async () => {
    const searchFiles = vi.fn(async (query: string) => (query === 'sr' ? ['src/a.rs', 'src/b.rs'] : []))
    const wrapper = mountComposer({ searchFiles })
    await typeInto(wrapper, '@sr')
    const items = wrapper.findAll('[data-testid="file-suggestions"] li')
    expect(searchFiles).toHaveBeenCalledWith('sr')
    expect(items).toHaveLength(2)
    await wrapper.find('[data-testid="composer-input"]').trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['@src/a.rs '])
    expect(wrapper.emitted('send')).toBeUndefined()
  })

  it('shares the keyboard: arrows cycle, Tab completes second, closing edge drops the popup', async () => {
    const searchFiles = vi.fn(async () => ['src/a.rs', 'src/b.rs'])
    const wrapper = mountComposer({ searchFiles })
    await typeInto(wrapper, '@a')
    const input = wrapper.find('[data-testid="composer-input"]')
    await input.trigger('keydown', { key: 'ArrowDown' })
    await input.trigger('keydown', { key: 'Tab' })
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['@src/b.rs '])
    await typeInto(wrapper, '@src/b.rs ')
    expect(wrapper.find('[data-testid="file-suggestions"]').exists()).toBe(false)
  })

  it('the slash popup owns the line, and a failed search shows nothing', async () => {
    const searchFiles = vi.fn(async () => {
      throw new Error('transport closed')
    })
    const wrapper = mountComposer({ searchFiles, commands: [compact] })
    await typeInto(wrapper, '/co')
    expect(searchFiles).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="command-suggestions"]').exists()).toBe(true)
    await typeInto(wrapper, '@co')
    expect(searchFiles).toHaveBeenCalledWith('co')
    expect(wrapper.find('[data-testid="file-suggestions"]').exists()).toBe(false)
  })
})

describe('Composer image paste (WEB-5b)', () => {
  it('a pasted raster previews as a chip and rides the send payload', async () => {
    const wrapper = mountComposer({ modelValue: 'look' })
    await wrapper.find('[data-testid="composer-input"]').trigger('paste', { clipboardData: { items: [clipboardItem(pngFile())] } })
    await flushPromises()
    const chip = wrapper.find('[data-testid="paste-chips"] img')
    expect(chip.exists()).toBe(true)
    expect(chip.attributes('src')).toMatch(/^data:image\/png;base64,/)
    await wrapper.find('[data-testid="composer-send"]').trigger('click')
    const send = wrapper.emitted('send')?.[0]
    expect(send?.[0]).toBe('look')
    expect(send?.[2]).toEqual([{ name: 'shot.png', mime: 'image/png', data: btoa(String.fromCharCode(...PNG_BYTES)) }])
  })

  it('text pastes fall through and a chip is removable', async () => {
    const wrapper = mountComposer({ modelValue: 'look' })
    await wrapper.find('[data-testid="composer-input"]').trigger('paste', {
      clipboardData: { items: [{ kind: 'string', type: 'text/plain', getAsFile: () => null }] },
    })
    await flushPromises()
    expect(wrapper.find('[data-testid="paste-chips"]').exists()).toBe(false)
    await wrapper.find('[data-testid="composer-input"]').trigger('paste', { clipboardData: { items: [clipboardItem(pngFile())] } })
    await flushPromises()
    await wrapper.find('[data-testid="paste-chip-remove"]').trigger('click')
    expect(wrapper.find('[data-testid="paste-chips"]').exists()).toBe(false)
  })

  it('chips clear only when the accepted send clears the draft', async () => {
    const wrapper = mountComposer({ modelValue: 'look' })
    await wrapper.find('[data-testid="composer-input"]').trigger('paste', { clipboardData: { items: [clipboardItem(pngFile())] } })
    await flushPromises()
    expect(wrapper.find('[data-testid="paste-chips"]').exists()).toBe(true)
    await typeInto(wrapper, 'edited')
    expect(wrapper.find('[data-testid="paste-chips"]').exists()).toBe(true)
    await typeInto(wrapper, '')
    expect(wrapper.find('[data-testid="paste-chips"]').exists()).toBe(false)
  })

  it('mirrors the server laws: busy non-interrupt and /commands refuse the send outright', async () => {
    const busy = mountComposer({ modelValue: 'look', busy: true })
    await busy.find('[data-testid="composer-input"]').trigger('paste', { clipboardData: { items: [clipboardItem(pngFile())] } })
    await flushPromises()
    const send = busy.find('[data-testid="composer-send"]')
    expect(send.attributes('disabled')).toBeDefined()
    expect(send.attributes('title')).toContain('interrupt')
    await busy.find('[data-testid="composer-delivery"]').setValue('interrupt')
    await flushPromises()
    expect(busy.find('[data-testid="composer-send"]').attributes('disabled')).toBeUndefined()

    const command = mountComposer({ modelValue: '/model sonnet' })
    await command.find('[data-testid="composer-input"]').trigger('paste', { clipboardData: { items: [clipboardItem(pngFile())] } })
    await flushPromises()
    expect(command.find('[data-testid="composer-send"]').attributes('disabled')).toBeDefined()
  })
})
