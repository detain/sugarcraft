import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { Wire } from '../../../stores/agents/__tests__/harness'
import ContextPanel from '../ContextPanel.vue'

/**
 * WEB-3a: the Context tab — one `context.sources` call per load, every
 * section of the answer rendered, and the XSS posture pinned: server paths
 * land as plain span text, never as markup.
 */

let wire: Wire

const SOURCES = {
  instructionFiles: [
    { path: '/repo/CLAUDE.md', bytes: 2048, tier: 'project' },
    { path: '/home/op/.candy-code/AGENTS.md', bytes: 512, tier: 'personal' },
  ],
  memorySections: [
    { id: 'a1', type: 'pattern', tags: [], scope: 'project', content: 'use pnpm', createdAt: '', modifiedAt: '', source: 'repo' },
  ],
  skills: [
    { name: 'release', tier: 'built-in', enabled: true },
    { name: 'legacy', tier: 'project', enabled: false },
  ],
  mcp: { status: 'trusted', error: null, skipped: [], servers: [{ name: 'fs', transport: 'stdio', trust: 'trusted' }] },
  untrusted: [{ path: '/repo/imported.md', reason: 'over the size ceiling' }],
  breakdown: {
    window: 131072,
    sections: [{ label: 'base', stability: 'stable', sections: 3, bytes: 4096, tokens: 900 }],
    toolCount: 30,
    toolTokens: 3000,
    historyTokens: 12345,
    historyMessages: 7,
    uiOnlyRows: 2,
    largest: [],
    lastCache: null,
    sessionCache: null,
    pruning: null,
    cacheBreaks: null,
  },
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

async function openPanel(): Promise<ReturnType<typeof mount>> {
  const wrapper = mount(ContextPanel, { props: { sessionId: 's1' } })
  await flushPromises()
  await wire.answer('context.sources', SOURCES)
  await flushPromises()
  return wrapper
}

describe('ContextPanel (WEB-3a)', () => {
  it('asks for the session sources on mount and renders every section', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    expect(wire.sent('context.sources').at(-1)?.params).toEqual({ sessionId: 's1' })
    expect(wrapper.find('[data-testid="context-breakdown"]').text()).toContain('12,345')
    expect(wrapper.find('[data-testid="context-layers"]').text()).toContain('base')
    expect(wrapper.find('[data-testid="context-instruction-files"]').text()).toContain('CLAUDE.md')
    expect(wrapper.find('[data-testid="context-memory"]').text()).toContain('use pnpm')
    expect(wrapper.find('[data-testid="context-skills"]').text()).toContain('release')
    expect(wrapper.find('[data-testid="context-mcp-status"]').text()).toBe('trusted')
  })

  it('renders server paths as plain spans — no markup, no links', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    const row = wrapper.find('[data-testid="context-file-/repo/CLAUDE.md"]')
    expect(row.element.tagName).toBe('SPAN')
    expect(row.text()).toBe('/repo/CLAUDE.md')
    expect(wrapper.find('[data-testid="context-instruction-files"]').find('a').exists()).toBe(false)
    expect(wrapper.find('[data-testid="context-untrusted"]').text()).toContain('over the size ceiling')
  })

  it('badges the personal file, the memory source, and the disabled skill', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    expect(wrapper.find('[data-testid="context-file-/home/op/.candy-code/AGENTS.md"]').text()).toBe('/home/op/.candy-code/AGENTS.md')
    expect(wrapper.find('[data-testid="context-memory-source-repo"]').text()).toBe('repo')
    expect(wrapper.find('[data-testid="context-skills"]').text()).toContain('disabled')
  })

  it('says "not measured" when the server could not price the instruction files', async () => {
    await wire.connect()
    const wrapper = mount(ContextPanel, { props: { sessionId: 's1' } })
    await flushPromises()
    await wire.answer('context.sources', { ...SOURCES, instructionFiles: null })
    await flushPromises()
    expect(wrapper.find('[data-testid="context-instruction-files"]').text()).toContain('not measured')
  })

  it('fails soft: a refusal is an error line, and reload asks again', async () => {
    await wire.connect()
    const wrapper = mount(ContextPanel, { props: { sessionId: 's1' } })
    await flushPromises()
    await wire.fail('context.sources', -32004, 'not_found', 'no such session')
    await flushPromises()
    expect(wrapper.find('[data-testid="context-error"]').text()).toContain('no such session')

    await wrapper.find('[data-testid="context-reload"]').trigger('click')
    await flushPromises()
    await wire.answer('context.sources', SOURCES)
    await flushPromises()
    expect(wire.sent('context.sources')).toHaveLength(2)
    expect(wrapper.find('[data-testid="context-panel"]').text()).toContain('131,072')
  })
})
