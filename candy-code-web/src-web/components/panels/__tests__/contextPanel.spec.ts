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

/** WEB-3b: the trust prompt — plain-span rendering, verdict buttons on context.trust. */

const TRUST = {
  askId: 'trust-9f0e1d2c3',
  kind: 'trust' as const,
  target: 'mcp' as const,
  root: '/repo/project',
  reason: 'This project declares MCP servers in .mcp.json, but /repo/project is not in your trusted projects — nothing of it starts.',
  status: 'asked' as const,
  persisted: false,
  sessionId: 's1',
}

describe('ContextPanel trust prompt (WEB-3b)', () => {
  async function openWithTrust(): Promise<ReturnType<typeof mount>> {
    const wrapper = mount(ContextPanel, { props: { sessionId: 's1' } })
    await flushPromises()
    await wire.answer('context.sources', { ...SOURCES, trust: TRUST })
    await flushPromises()
    return wrapper
  }

  it('renders the prompt with plain spans and three verdict buttons', async () => {
    await wire.connect()
    const wrapper = await openWithTrust()
    const section = wrapper.find('[data-testid="context-trust"]')
    expect(section.exists()).toBe(true)
    expect(section.text()).toContain('not in your trusted projects')
    const root = wrapper.find('[data-testid="context-trust-root"]')
    expect(root.element.tagName).toBe('SPAN')
    expect(root.text()).toBe('/repo/project')
    expect(section.find('a').exists()).toBe(false)
    expect(wrapper.find('[data-testid="context-trust-session"]').text()).toBe('Silence prompts (this run)')
    expect(wrapper.find('[data-testid="context-trust-user"]').text()).toBe('Always trust')
    expect(wrapper.find('[data-testid="context-trust-reject"]').text()).toBe('Leave untrusted')
  })

  it('sends the user-tier grant and states persistence from the answer', async () => {
    await wire.connect()
    const wrapper = await openWithTrust()

    await wrapper.find('[data-testid="context-trust-user"]').trigger('click')
    // C-SEC-4: the answer names its session — the gateway routes it to the
    // workspace host that minted the prompt, not the primary process.
    expect(wire.sent('context.trust').at(-1)?.params).toEqual({ sessionId: 's1', askId: 'trust-9f0e1d2c3', decision: 'grant', remember: 'user' })
    await wire.answer('context.trust', { askId: 'trust-9f0e1d2c3', status: 'granted', persisted: true })
    await flushPromises()

    expect(wrapper.find('[data-testid="context-trust-session"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="context-trust-settled"]').text()).toContain('user config')
    expect(wrapper.find('[data-testid="context-trust-settled"]').text()).toContain('next launch')
  })

  it('adopts a live trust prompt even when the measurement returned none', async () => {
    await wire.connect()
    const wrapper = mount(ContextPanel, { props: { sessionId: 's1' } })
    await flushPromises()
    await wire.answer('context.sources', { ...SOURCES, trust: null })
    await flushPromises()
    expect(wrapper.find('[data-testid="context-trust"]').exists()).toBe(false)

    wire.event(null, 'permission.asked', TRUST, false)
    await flushPromises()
    expect(wrapper.find('[data-testid="context-trust-root"]').text()).toBe('/repo/project')

    await wrapper.find('[data-testid="context-trust-session"]').trigger('click')
    expect(wire.sent('context.trust').at(-1)?.params).toEqual({ sessionId: 's1', askId: 'trust-9f0e1d2c3', decision: 'grant', remember: 'session' })
    await wire.answer('context.trust', { askId: 'trust-9f0e1d2c3', status: 'granted', persisted: false })
    await flushPromises()
    // WEB-3b MINOR-3: the session-tier line speaks the frozen-trust truth —
    // prompt-silencing only, activation needs a saved (user-tier) trust.
    const settled = wrapper.find('[data-testid="context-trust-settled"]').text()
    expect(settled).toContain('Question settled for this run')
    expect(settled).toContain('frozen at launch')
    expect(settled).toContain('silences further prompts')
    expect(settled).toContain('user-tier')
    expect(settled).not.toContain('Trusted for this server run')
  })

  it('ignores prompts measured for another session', async () => {
    await wire.connect()
    const wrapper = await openWithTrust()
    wire.event(null, 'permission.asked', { ...TRUST, askId: 'trust-111122223', sessionId: 'other' }, false)
    await flushPromises()
    // The open prompt is untouched: still the first ask, no second section.
    expect(wrapper.findAll('[data-testid="context-trust"]')).toHaveLength(1)
    expect(wrapper.find('[data-testid="context-trust-root"]').text()).toBe('/repo/project')
  })

  it('a settled-elsewhere refusal clears the prompt with an honest line', async () => {
    await wire.connect()
    const wrapper = await openWithTrust()

    await wrapper.find('[data-testid="context-trust-reject"]').trigger('click')
    await wire.fail('context.trust', -32009, 'already_resolved')
    await flushPromises()

    expect(wrapper.find('[data-testid="context-trust-error"]').text()).toContain('settled elsewhere')
    expect(wrapper.find('[data-testid="context-trust-root"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="context-trust-reject"]').exists()).toBe(false)
  })

  it('a failed user-config write keeps the prompt open with its own line', async () => {
    await wire.connect()
    const wrapper = await openWithTrust()

    await wrapper.find('[data-testid="context-trust-user"]').trigger('click')
    await wire.fail('context.trust', -32009, 'trust_write_failed', 'the trustedProjectMcp list was not updated')
    await flushPromises()

    expect(wrapper.find('[data-testid="context-trust-error"]').text()).toContain('stays open')
    // The server left the record `asked`, so the verdict buttons stay live.
    expect(wrapper.find('[data-testid="context-trust-user"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="context-trust-settled"]').exists()).toBe(false)

    await wrapper.find('[data-testid="context-trust-user"]').trigger('click')
    await wire.answer('context.trust', { askId: 'trust-9f0e1d2c3', status: 'granted', persisted: true })
    await flushPromises()
    expect(wrapper.find('[data-testid="context-trust-settled"]').text()).toContain('user config')
  })
})

/**
 * P-CTX.g: the `By category` model — stacked bar over the measured rows,
 * drillable sub-rows, the not-sent MCP-instructions note, the `/context
 * --raw` hint, and the store's re-measure after every settled turn.
 */

const CATEGORIES = [
  { key: 'system-prompt', label: 'System prompt', tokens: 4000, share: 3, sub: [] },
  {
    key: 'mcp-tools',
    label: 'MCP tools',
    tokens: 2000,
    share: 2,
    sub: [
      { label: 'fs', tokens: 1200, bytes: null, personal: false, note: null },
      { label: 'mcp_list/mcp_call', tokens: 500, bytes: null, personal: false, note: null },
      { label: 'server instructions', tokens: null, bytes: null, personal: false, note: 'not sent — this build splices no MCP server instructions into the prompt' },
    ],
  },
  {
    key: 'memory-files',
    label: 'Instruction files',
    tokens: 1000,
    share: 1,
    sub: [
      { label: '/repo/CLAUDE.md', tokens: null, bytes: 2048, personal: false, note: null },
      { label: '/home/op/.candy-code/AGENTS.md', tokens: null, bytes: 512, personal: true, note: null },
    ],
  },
  { key: 'messages', label: 'Messages', tokens: 6000, share: 5, sub: [{ label: '#12 user', tokens: 300, bytes: null, personal: false, note: null }] },
  { key: 'autocompact-buffer', label: 'Autocompact buffer', tokens: null, share: null, sub: [] },
  { key: 'free-space', label: 'Free space', tokens: 120000, share: 91, sub: [] },
]

const CAT_SOURCES = {
  ...SOURCES,
  breakdown: { ...SOURCES.breakdown, categories: CATEGORIES, buffer: 4096, mcpServers: [{ name: 'fs', count: 3, tokens: 1200 }] },
}

async function openCats(overrides: Record<string, unknown> = {}): Promise<ReturnType<typeof mount>> {
  const wrapper = mount(ContextPanel, { props: { sessionId: 's1' } })
  await flushPromises()
  await wire.answer('context.sources', overrides.breakdown ? { ...CAT_SOURCES, breakdown: { ...CAT_SOURCES.breakdown, ...(overrides.breakdown as object) } } : CAT_SOURCES)
  await flushPromises()
  return wrapper
}

describe('ContextPanel categories (P-CTX.g)', () => {
  it('stacks the measured rows into a bar whose widths sum to 100%', async () => {
    await wire.connect()
    const wrapper = await openCats()
    const segs = wrapper.findAll('[data-testid^="context-cat-seg-"]')
    expect(segs.map((seg) => seg.attributes('data-testid')))
      .toEqual(['context-cat-seg-system-prompt', 'context-cat-seg-mcp-tools', 'context-cat-seg-memory-files', 'context-cat-seg-messages', 'context-cat-seg-free-space'])
    const widths = segs.map((seg) => Number.parseFloat((seg.element as HTMLElement).style.width))
    expect(widths.reduce((sum, pct) => sum + pct, 0)).toBeCloseTo(100, 6)
    // The unmeasured buffer row holds no cell — unmeasured is never zero.
    expect(wrapper.find('[data-testid="context-cat-seg-autocompact-buffer"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="context-cat-autocompact-buffer"]').text()).toContain('not measured')
  })

  it('drills a category in place and toggles it back', async () => {
    await wire.connect()
    const wrapper = await openCats()
    expect(wrapper.find('[data-testid="context-subs-mcp-tools"]').exists()).toBe(false)

    await wrapper.find('[data-testid="context-cat-toggle-mcp-tools"]').trigger('click')
    const subs = wrapper.find('[data-testid="context-subs-mcp-tools"]')
    expect(subs.exists()).toBe(true)
    expect(subs.text()).toContain('fs')
    expect(subs.text()).toContain('~1,200')

    await wrapper.find('[data-testid="context-cat-toggle-mcp-tools"]').trigger('click')
    expect(wrapper.find('[data-testid="context-subs-mcp-tools"]').exists()).toBe(false)

    await wrapper.find('[data-testid="context-cat-toggle-messages"]').trigger('click')
    expect(wrapper.find('[data-testid="context-subs-messages"]').text()).toContain('#12 user')
    expect(wrapper.find('[data-testid="context-subs-mcp-tools"]').exists()).toBe(false)
  })

  it('renders the not-sent MCP-instructions row as a plain note — no markup', async () => {
    await wire.connect()
    const wrapper = await openCats()
    await wrapper.find('[data-testid="context-cat-toggle-mcp-tools"]').trigger('click')
    const note = wrapper.find('[data-testid="context-sub-mcp-tools-2"]')
    // The row is flex items with a CSS gap; the DOM textContent of the adjacent
    // label/figure spans joins without whitespace, so assert each span on its own.
    expect(note.find('.sub-label').text()).toBe('server instructions')
    expect(note.find('.tokens').text()).toBe(
      'not sent — this build splices no MCP server instructions into the prompt',
    )
    expect(note.find('a').exists()).toBe(false)
    expect(note.findAll('span').every((span) => span.text().length > 0)).toBe(true)
  })

  it('prices instruction sub-rows by bytes, badges personal, honors precedence', async () => {
    await wire.connect()
    const wrapper = await openCats()
    await wrapper.find('[data-testid="context-cat-toggle-memory-files"]').trigger('click')
    const subs = wrapper.find('[data-testid="context-subs-memory-files"]')
    expect(subs.text()).toContain('2 KB')
    expect(subs.text()).toContain('512 B')
    expect(subs.text()).toContain('personal')
    expect(subs.text()).not.toContain('2,048')
  })

  it('hints the /context --raw dump and skips the section when unmeasured', async () => {
    await wire.connect()
    const wrapper = await openCats()
    expect(wrapper.find('[data-testid="context-raw-hint"]').text()).toContain('/context --raw')

    const bare = mount(ContextPanel, { props: { sessionId: 's1' } })
    await flushPromises()
    await wire.answer('context.sources', SOURCES)
    await flushPromises()
    expect(bare.find('[data-testid="context-categories"]').exists()).toBe(false)
  })

  it('re-measures the open session after its turn settles', async () => {
    await wire.connect()
    const wrapper = await openCats()
    expect(wire.sent('context.sources')).toHaveLength(1)

    wire.event('other', 'turn.completed', { stopReason: 'end_turn' })
    await flushPromises()
    // A turn ending on another session must not reload this panel's sources.
    expect(wire.sent('context.sources')).toHaveLength(1)

    wire.event('s1', 'turn.completed', { stopReason: 'end_turn' })
    await flushPromises()
    expect(wire.sent('context.sources')).toHaveLength(2)

    const grown = CATEGORIES.map((row) => (row.key === 'messages' ? { ...row, tokens: 8000 } : row))
    await wire.answer('context.sources', { ...CAT_SOURCES, breakdown: { ...CAT_SOURCES.breakdown, categories: grown } })
    await flushPromises()
    expect(wrapper.find('[data-testid="context-cat-messages"]').text()).toContain('~8,000')
  })
})
