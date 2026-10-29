import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import { createAppRouter } from '../../router'
import { Wire } from '../../stores/agents/__tests__/harness'
import Composer from '../Composer.vue'

/**
 * C-CTX-3.c: the web twin of the TUI's `#` quick-add. A single-line `#` draft
 * is answered by a confirm chip instead of a turn; confirming appends
 * `- text\n` to the personal file through the served `context.instructionWrite`
 * (path `personal`) and clears the draft only after the write landed. Escape
 * retires the chip with the draft untouched — the note never left the
 * textarea. `##`, multi-line, and bare `#` drafts are prose and send as
 * before; while a turn runs the offer is never raised.
 */

let wire: Wire

const INPUT = '[data-testid="composer-input"]'
const CHIP = '[data-testid="composer-quick-add"]'
const CONFIRM = '[data-testid="composer-quick-add-confirm"]'
const CANCEL = '[data-testid="composer-quick-add-cancel"]'
const CHIP_ERROR = '[data-testid="composer-quick-add-error"]'

async function mountAt(id: string) {
  const router = createAppRouter(createMemoryHistory())
  await router.push({ name: 'session', params: { id } })
  await router.isReady()
  return mount(Composer, {
    props: { modelValue: '', busy: false, commands: [] },
    global: { plugins: [router] },
  })
}

beforeEach(() => {
  setActivePinia(createPinia())
  wire = new Wire()
  try {
    globalThis.localStorage?.clear()
  } catch {
    // no storage
  }
})

describe('composer # quick-add (C-CTX-3.c)', () => {
  it('a one-line # draft raises the chip instead of a turn, and Enter lands the append', async () => {
    await wire.connect()
    const wrapper = await mountAt('s1')
    await wrapper.setProps({ modelValue: '# keep secrets out of the logs' })

    await wrapper.find(INPUT).trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(wrapper.find(CHIP).exists()).toBe(true)
    expect(wrapper.find(CHIP).text()).toContain('- keep secrets out of the logs')
    expect(wrapper.emitted('send')).toBeUndefined()
    expect(wire.sent('context.instructionWrite')).toHaveLength(0)

    // Enter again confirms the offer on screen.
    await wrapper.find(INPUT).trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(wire.sent('context.instructionWrite').at(-1)?.params).toMatchObject({
      sessionId: 's1',
      path: 'personal',
      append: '- keep secrets out of the logs\n',
    })
    expect(wire.sent('context.instructionWrite').at(-1)?.params).not.toHaveProperty('content')

    await wire.answer('context.instructionWrite', {
      path: '/home/op/.candy-code/AGENTS.md',
      bytes: 31,
      mode: 'append',
    })
    await flushPromises()
    expect(wrapper.find(CHIP).exists()).toBe(false)
    expect(wrapper.emitted('update:modelValue')).toEqual([['']])
    expect(wrapper.emitted('send')).toBeUndefined()
  })

  it('the confirm button answers the chip the same way Enter does', async () => {
    await wire.connect()
    const wrapper = await mountAt('s1')
    await wrapper.setProps({ modelValue: '#run the tests first' })
    await wrapper.find(INPUT).trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(wrapper.find(CHIP).exists()).toBe(true)

    await wrapper.find(CONFIRM).trigger('click')
    await flushPromises()
    expect(wire.sent('context.instructionWrite').at(-1)?.params).toMatchObject({
      sessionId: 's1',
      path: 'personal',
      append: '- run the tests first\n',
    })
    expect(wire.sent('context.instructionWrite').at(-1)?.params).not.toHaveProperty('content')
    await wire.answer('context.instructionWrite', { path: '/home/op/.candy-code/AGENTS.md', bytes: 24, mode: 'append' })
    await flushPromises()
    expect(wrapper.find(CHIP).exists()).toBe(false)
  })

  it('Escape retires the chip with the draft untouched and no write was made', async () => {
    await wire.connect()
    const wrapper = await mountAt('s1')
    await wrapper.setProps({ modelValue: '# not yet' })
    await wrapper.find(INPUT).trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(wrapper.find(CHIP).exists()).toBe(true)

    await wrapper.find(INPUT).trigger('keydown', { key: 'Escape' })
    await flushPromises()
    expect(wrapper.find(CHIP).exists()).toBe(false)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(wrapper.emitted('send')).toBeUndefined()
    expect(wire.sent('context.instructionWrite')).toHaveLength(0)

    // The Cancel button answers the reopened chip just the same.
    await wrapper.find(INPUT).trigger('keydown', { key: 'Enter' })
    await flushPromises()
    await wrapper.find(CANCEL).trigger('click')
    expect(wrapper.find(CHIP).exists()).toBe(false)
  })

  it('prose shapes send as before: ## heading, multi-line, and bare hash', async () => {
    await wire.connect()
    const wrapper = await mountAt('s1')

    await wrapper.setProps({ modelValue: '## a section heading' })
    await wrapper.find(INPUT).trigger('keydown', { key: 'Enter' })
    expect(wrapper.find(CHIP).exists()).toBe(false)
    expect(wrapper.emitted('send')?.at(-1)?.[0]).toBe('## a section heading')

    await wrapper.setProps({ modelValue: '# first\nsecond line' })
    await wrapper.find(INPUT).trigger('keydown', { key: 'Enter' })
    expect(wrapper.find(CHIP).exists()).toBe(false)
    expect(wrapper.emitted('send')?.at(-1)?.[0]).toBe('# first\nsecond line')

    await wrapper.setProps({ modelValue: '#' })
    await wrapper.find(INPUT).trigger('keydown', { key: 'Enter' })
    expect(wrapper.find(CHIP).exists()).toBe(false)
    expect(wrapper.emitted('send')?.at(-1)?.[0]).toBe('#')
  })

  it('while a turn runs the offer is never raised', async () => {
    await wire.connect()
    const wrapper = await mountAt('s1')
    await wrapper.setProps({ modelValue: '# mid-turn note', busy: true })
    await wrapper.find(INPUT).trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(wrapper.find(CHIP).exists()).toBe(false)
    expect(wire.sent('context.instructionWrite')).toHaveLength(0)
  })

  it('an edited draft under the chip appends the EDITED line, not the stale offer', async () => {
    // Round-1 fix (MINOR-3): Enter re-derives the offer from the current
    // draft. The stale offered line used to win — and the success then
    // cleared the edit, so the edited note was lost and the unedited one
    // landed in the personal file.
    await wire.connect()
    const wrapper = await mountAt('s1')
    await wrapper.setProps({ modelValue: '# first thought' })
    await wrapper.find(INPUT).trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(wrapper.find(CHIP).exists()).toBe(true)
    expect(wrapper.find(CHIP).text()).toContain('- first thought')

    await wrapper.setProps({ modelValue: '# second, better thought' })
    await wrapper.find(INPUT).trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(wire.sent('context.instructionWrite').at(-1)?.params).toMatchObject({
      sessionId: 's1',
      path: 'personal',
      append: '- second, better thought\n',
    })

    await wire.answer('context.instructionWrite', {
      path: '/home/op/.candy-code/AGENTS.md',
      bytes: 27,
      mode: 'append',
    })
    await flushPromises()
    expect(wrapper.find(CHIP).exists()).toBe(false)
    expect(wrapper.emitted('update:modelValue')).toEqual([['']])
    expect(wrapper.emitted('send')).toBeUndefined()
  })

  it('an edit that leaves the # shape retires the chip and sends as a prompt', async () => {
    await wire.connect()
    const wrapper = await mountAt('s1')
    await wrapper.setProps({ modelValue: '# note' })
    await wrapper.find(INPUT).trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(wrapper.find(CHIP).exists()).toBe(true)

    await wrapper.setProps({ modelValue: '## prose heading now' })
    await wrapper.find(INPUT).trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(wrapper.find(CHIP).exists()).toBe(false)
    expect(wire.sent('context.instructionWrite')).toHaveLength(0)
    expect(wrapper.emitted('send')?.at(-1)?.[0]).toBe('## prose heading now')
  })

  it('a refused write keeps the chip up with the reason and the draft intact', async () => {
    await wire.connect()
    const wrapper = await mountAt('s1')
    await wrapper.setProps({ modelValue: '# refused note' })
    await wrapper.find(INPUT).trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(wrapper.find(CHIP).exists()).toBe(true)

    await wrapper.find(INPUT).trigger('keydown', { key: 'Enter' })
    await flushPromises()
    await wire.fail('context.instructionWrite', -32003, 'permission_denied', 'the principal holds no write scope')
    await flushPromises()
    expect(wrapper.find(CHIP).exists()).toBe(true)
    expect(wrapper.find(CHIP_ERROR).text()).toContain('no write scope')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })
})
