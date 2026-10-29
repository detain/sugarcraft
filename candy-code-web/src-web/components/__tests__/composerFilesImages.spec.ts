import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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

describe('Composer @search debounce (WEB-5-P3 client half)', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  async function typePartial(wrapper: ReturnType<typeof mountComposer>, value: string, gapMs: number) {
    if (gapMs > 0) await vi.advanceTimersByTimeAsync(gapMs)
    await wrapper.setProps({ modelValue: value })
    await flushPromises()
  }

  it('five partials inside 120 ms send exactly the first and the last searches, and the popup shows the last answer', async () => {
    const searchFiles = vi.fn(async (query: string) => [query])
    const wrapper = mountComposer({ searchFiles })
    await typePartial(wrapper, '@a', 0) // leading edge: the first `@` opens at once
    await typePartial(wrapper, '@ab', 20)
    await typePartial(wrapper, '@abc', 20)
    await typePartial(wrapper, '@abcd', 20)
    await typePartial(wrapper, '@abcde', 20)
    expect(searchFiles).toHaveBeenCalledTimes(1) // every follow-up partial is still windowed
    await vi.advanceTimersByTimeAsync(120) // the window closes: one trailing search, newest partial
    await flushPromises()
    expect(searchFiles).toHaveBeenCalledTimes(2)
    expect(searchFiles.mock.calls.map((call) => call[0])).toEqual(['a', 'abcde'])
    const items = wrapper.findAll('[data-testid="file-suggestions"] li')
    expect(items.map((item) => item.text())).toEqual(['abcde'])
    wrapper.unmount()
  })

  it('a stale answer that arrives after a newer search was dispatched is dropped (the fileSeq latch)', async () => {
    let releaseStale: (found: string[]) => void = () => {}
    const searchFiles = vi.fn((query: string) => {
      if (query === 'a') return new Promise<string[]>((resolve) => { releaseStale = resolve })
      return Promise.resolve([query])
    })
    const wrapper = mountComposer({ searchFiles })
    await typePartial(wrapper, '@a', 0) // leading search, left unresolved
    await typePartial(wrapper, '@ab', 20)
    await vi.advanceTimersByTimeAsync(120)
    await flushPromises()
    expect(searchFiles).toHaveBeenCalledTimes(2)
    expect(wrapper.findAll('[data-testid="file-suggestions"] li').map((item) => item.text())).toEqual(['ab'])
    releaseStale(['stale answer']) // the superseded search lands late
    await flushPromises()
    expect(wrapper.findAll('[data-testid="file-suggestions"] li').map((item) => item.text())).toEqual(['ab'])
    wrapper.unmount()
  })

  it('a null partial cancels the pending trailing search and resets the window', async () => {
    const searchFiles = vi.fn(async (query: string) => [query])
    const wrapper = mountComposer({ searchFiles })
    await typePartial(wrapper, '@a', 0) // leading search
    await typePartial(wrapper, '@ab', 20) // trailing search now pending
    await typePartial(wrapper, '@ab ', 20) // the closing edge: partial goes null
    expect(wrapper.find('[data-testid="file-suggestions"]').exists()).toBe(false)
    await vi.advanceTimersByTimeAsync(240)
    await flushPromises()
    expect(searchFiles).toHaveBeenCalledTimes(1) // the pending trailing search never ran
    await typePartial(wrapper, '@ac', 20) // window reset: the next partial leads at once
    expect(searchFiles).toHaveBeenCalledTimes(2)
    expect(searchFiles.mock.calls[1]?.[0]).toBe('ac')
    wrapper.unmount()
  })

  it('unmount clears the pending timer — no search runs from a dead composer', async () => {
    const searchFiles = vi.fn(async (query: string) => [query])
    const wrapper = mountComposer({ searchFiles })
    await typePartial(wrapper, '@a', 0)
    await typePartial(wrapper, '@ab', 20)
    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(240)
    await flushPromises()
    expect(searchFiles).toHaveBeenCalledTimes(1)
  })
})

describe('Composer @agent popup (C-AGT-4.d)', () => {
  // Each row is two sibling spans (keyword + description); the DOM text runs
  // them together, so join per span to assert the row's two halves at once.
  const label = (li: { findAll: (selector: string) => Array<{ text(): string }> }) =>
    li.findAll('span').map((span) => span.text()).join(' ')

  it('screens the launch roster on an @agent: partial and Enter inserts the keyword', async () => {
    const agentRoster = () => ['reviewer', 'researcher', 'tester']
    const searchFiles = vi.fn(async () => ['src/a.rs'])
    const wrapper = mountComposer({ agentRoster, searchFiles })
    await typeInto(wrapper, '@agent:res')
    const items = wrapper.findAll('[data-testid="agent-suggestions"] li')
    expect(items.map(label)).toEqual(['agent:researcher delegate this prompt'])
    expect(searchFiles).not.toHaveBeenCalled()
    await wrapper.find('[data-testid="composer-input"]').trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['@agent:researcher '])
    expect(wrapper.emitted('send')).toBeUndefined()
  })

  it('sorts the full roster and Tab accepts the highlighted second row', async () => {
    const agentRoster = () => ['tester', 'reviewer', 'researcher']
    const wrapper = mountComposer({ agentRoster })
    await typeInto(wrapper, '@agent:')
    const items = wrapper.findAll('[data-testid="agent-suggestions"] li')
    expect(items.map(label)).toEqual([
      'agent:researcher delegate this prompt',
      'agent:reviewer delegate this prompt',
      'agent:tester delegate this prompt',
    ])
    const input = wrapper.find('[data-testid="composer-input"]')
    await input.trigger('keydown', { key: 'ArrowDown' })
    await input.trigger('keydown', { key: 'Tab' })
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['@agent:reviewer '])
  })

  it('an unmatched partial hands the line back to the file search, as @mcp: does', async () => {
    const agentRoster = () => ['reviewer']
    const searchFiles = vi.fn(async (query: string) => (query === 'agent:nope' ? ['nope.txt'] : []))
    const wrapper = mountComposer({ agentRoster, searchFiles })
    await typeInto(wrapper, '@agent:nope')
    expect(wrapper.find('[data-testid="agent-suggestions"]').exists()).toBe(false)
    expect(searchFiles).toHaveBeenCalledWith('agent:nope')
    expect(wrapper.find('[data-testid="file-suggestions"]').exists()).toBe(true)
  })

  it('a view without the roster prop keeps the pre-C-AGT-4.d file behaviour', async () => {
    const searchFiles = vi.fn(async () => ['src/a.rs'])
    const wrapper = mountComposer({ searchFiles })
    await typeInto(wrapper, '@agent:rev')
    expect(wrapper.find('[data-testid="agent-suggestions"]').exists()).toBe(false)
    expect(searchFiles).toHaveBeenCalledWith('agent:rev')
  })
})
