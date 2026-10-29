import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { Wire } from '../../../stores/agents/__tests__/harness'
import ContextPanel from '../ContextPanel.vue'

/**
 * C-CTX-3.c: the Context panel's write side. Every listed instruction row
 * answers to Edit when the principal holds `write`; the save spells
 * `context.instructionWrite` with the byte count THIS listing measured (the
 * expectBytes latch), the personal row writes under the reserved `personal`
 * path, and an `instruction_changed` refusal keeps the modal open, shows why,
 * and reloads the listing so the next Edit carries fresh bytes. A read-only
 * principal never sees the affordance.
 */

let wire: Wire

const PROJECT = '/repo/CLAUDE.md'
const PERSONAL = '/home/op/.candy-code/AGENTS.md'

const SOURCES = {
  instructionFiles: [
    { path: PROJECT, bytes: 2048, tier: 'project' },
    { path: PERSONAL, bytes: 512, tier: 'personal' },
  ],
  memorySections: [],
  skills: [],
  mcp: {
    status: 'trusted', error: null, skipped: [],
    userStatus: 'absent', userError: null, userSkipped: [],
    servers: [],
  },
  untrusted: [],
  breakdown: {
    window: 131072,
    sections: [],
    toolCount: 0,
    toolTokens: 0,
    historyTokens: 0,
    historyMessages: 0,
    uiOnlyRows: 0,
    largest: [],
    lastCache: null,
    sessionCache: null,
    pruning: null,
    cacheBreaks: null,
  },
}

const MODAL = '[data-testid="instruction-edit-modal"]'
const TEXT = '[data-testid="instruction-edit-text"]'
const SAVE = '[data-testid="instruction-edit-save"]'
const ERROR = '[data-testid="instruction-edit-error"]'

async function openPanel(scopes: string[] = ['read', 'write', 'approve', 'admin']) {
  await wire.connect(() => {}, { principal: { kind: 'owner', scopes } })
  const wrapper = mount(ContextPanel, { props: { sessionId: 's1' } })
  await flushPromises()
  await wire.answer('context.sources', SOURCES)
  await flushPromises()
  return wrapper
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

describe('ContextPanel instruction editing (C-CTX-3.c)', () => {
  it('lists rows with an Edit affordance only for a principal that holds write', async () => {
    const writable = await openPanel()
    expect(writable.find(`[data-testid="context-edit-${PROJECT}"]`).exists()).toBe(true)
    expect(writable.find(`[data-testid="context-edit-${PERSONAL}"]`).exists()).toBe(true)

    const readonly = await openPanel(['read'])
    expect(readonly.find(`[data-testid="context-edit-${PROJECT}"]`).exists()).toBe(false)
    expect(readonly.find(`[data-testid="context-edit-${PERSONAL}"]`).exists()).toBe(false)
  })

  it('saving a project row spells instructionWrite with the listed bytes and closes on success', async () => {
    const wrapper = await openPanel()
    await wrapper.find(`[data-testid="context-edit-${PROJECT}"]`).trigger('click')
    expect(wrapper.find(MODAL).exists()).toBe(true)
    // The textarea starts empty on purpose: the protocol never ships file
    // content to the browser, so a save replaces what you type.
    expect(wrapper.find(TEXT).element.value).toBe('')

    await wrapper.find(TEXT).setValue('new content')
    await wrapper.find(SAVE).trigger('click')
    await flushPromises()
    // The store adds its `idempotencyKey` to every write-scope request; the
    // latch the server judges is the four spelled fields.
    expect(wire.sent('context.instructionWrite').at(-1)?.params).toMatchObject({
      sessionId: 's1',
      path: PROJECT,
      content: 'new content',
      expectBytes: 2048,
    })
    // Replace mode: the panel never spells `append`.
    expect(wire.sent('context.instructionWrite').at(-1)?.params).not.toHaveProperty('append')

    await wire.answer('context.instructionWrite', { path: PROJECT, bytes: 11, mode: 'replace' })
    await flushPromises()
    expect(wrapper.find(MODAL).exists()).toBe(false)
  })

  it('the personal row writes through the reserved personal path, not its listed path', async () => {
    const wrapper = await openPanel()
    await wrapper.find(`[data-testid="context-edit-${PERSONAL}"]`).trigger('click')
    await wrapper.find(TEXT).setValue('- keep secrets out of the logs\n')
    await wrapper.find(SAVE).trigger('click')
    await flushPromises()
    const frame = wire.sent('context.instructionWrite').at(-1)?.params as Record<string, unknown>
    expect(frame.path).toBe('personal')
    expect(frame.expectBytes).toBe(512)
    expect(frame.sessionId).toBe('s1')
  })

  it('a changed-bytes refusal keeps the modal open, says why, and re-measures the listing', async () => {
    const wrapper = await openPanel()
    await wrapper.find(`[data-testid="context-edit-${PROJECT}"]`).trigger('click')
    await wrapper.find(TEXT).setValue('stale save')
    await wrapper.find(SAVE).trigger('click')
    await flushPromises()

    await wire.fail('context.instructionWrite', -32009, 'instruction_changed', 'the file changed since the listing')
    await flushPromises()
    expect(wrapper.find(MODAL).exists()).toBe(true)
    expect(wrapper.find(ERROR).text()).toContain('no longer holds the bytes this listing measured')

    // The refusal reloads at once: no announcement follows it, and the retry
    // must carry the current bytes.
    expect(wire.sent('context.sources')).toHaveLength(2)
    await wire.answer('context.sources', SOURCES)
    await flushPromises()
    expect(wrapper.find(MODAL).exists()).toBe(true)
  })

  it('cancel retires the modal without a write', async () => {
    const wrapper = await openPanel()
    await wrapper.find(`[data-testid="context-edit-${PROJECT}"]`).trigger('click')
    await wrapper.find('[data-testid="instruction-edit-cancel"]').trigger('click')
    expect(wrapper.find(MODAL).exists()).toBe(false)
    expect(wire.sent('context.instructionWrite')).toHaveLength(0)
  })
})
