import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { Wire } from '../grid/__tests__/harness'
import { useSessionStore } from '../../stores/session'
import SessionView from '../../views/SessionView.vue'
import Composer, { type McpMention } from '../Composer.vue'

/**
 * C-MCP-2.c: the composer's `@mcp:` resource popup. A trailing partial that
 * starts with `mcp:` consults the `searchMcpResources` prop (the session's
 * cached `mcp.resources` roster); accepting inserts the keyword form
 * `@mcp:<server>:<uri> ` the server already resolves. An empty roster hands
 * the line back to the `fs.search` file popup.
 */

const GITHUB: McpMention = { server: 'github', uri: 'docs/readme', name: 'README' }
const NOTES: McpMention = { server: 'workspace', uri: 'notes/todo.md', name: 'todo list' }
const ROSTER: McpMention[] = [GITHUB, NOTES]

function mountComposer(props: Record<string, unknown> = {}) {
  return mount(Composer, { props: { modelValue: '', busy: false, commands: [], ...props } })
}

async function typeInto(wrapper: ReturnType<typeof mountComposer>, modelValue: string) {
  await wrapper.setProps({ modelValue })
  await flushPromises()
}

describe('Composer @mcp: resource popup (C-MCP-2.c)', () => {
  it('an @mcp: partial lists roster rows (queried without the prefix) instead of searching files', async () => {
    const searchMcpResources = vi.fn(async () => ROSTER)
    const searchFiles = vi.fn(async () => ['src/a.rs'])
    const wrapper = mountComposer({ searchFiles, searchMcpResources })
    await typeInto(wrapper, '@mcp:')
    expect(searchMcpResources).toHaveBeenCalledWith('')
    expect(searchFiles).not.toHaveBeenCalled()
    const items = wrapper.findAll('[data-testid="mcp-suggestions"] li')
    expect(items).toHaveLength(2)
    expect(items[0]?.text()).toContain('mcp:github:docs/readme')
    expect(items[1]?.text()).toContain('mcp:workspace:notes/todo.md')
    expect(wrapper.find('[data-testid="file-suggestions"]').exists()).toBe(false)
  })

  it('Enter accepts the highlighted row with the two-colon keyword form, never sending', async () => {
    const wrapper = mountComposer({ searchMcpResources: async () => ROSTER, searchFiles: async () => [] })
    await typeInto(wrapper, 'look @mcp:gith')
    await wrapper.find('[data-testid="composer-input"]').trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['look @mcp:github:docs/readme '])
    expect(wrapper.emitted('send')).toBeUndefined()
  })

  it('arrows cycle and Tab completes the second row, sharing the file popup keyboard', async () => {
    const wrapper = mountComposer({ searchMcpResources: async () => ROSTER, searchFiles: async () => [] })
    await typeInto(wrapper, '@mcp:')
    const input = wrapper.find('[data-testid="composer-input"]')
    await input.trigger('keydown', { key: 'ArrowDown' })
    await input.trigger('keydown', { key: 'Tab' })
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['@mcp:workspace:notes/todo.md '])
  })

  it('an empty roster falls back to the file popup on the same partial', async () => {
    const searchMcpResources = vi.fn(async () => [])
    const searchFiles = vi.fn(async (query: string) => (query === 'mcp:zzz' ? ['docs/mcp-notes.md'] : []))
    const wrapper = mountComposer({ searchFiles, searchMcpResources })
    await typeInto(wrapper, '@mcp:zzz')
    expect(searchMcpResources).toHaveBeenCalledWith('zzz')
    expect(searchFiles).toHaveBeenCalledWith('mcp:zzz')
    expect(wrapper.find('[data-testid="mcp-suggestions"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid="file-suggestions"] li')).toHaveLength(1)
  })

  it('a rejected roster fetch falls back to the file popup too', async () => {
    const searchMcpResources = vi.fn(async () => {
      throw new Error('transport closed')
    })
    const searchFiles = vi.fn(async () => ['src/a.rs'])
    const wrapper = mountComposer({ searchFiles, searchMcpResources })
    await typeInto(wrapper, '@mcp:github')
    expect(searchFiles).toHaveBeenCalledWith('mcp:github')
    expect(wrapper.findAll('[data-testid="file-suggestions"] li')).toHaveLength(1)
  })

  it('without the prop the @ popup stays file-only, as before', async () => {
    const searchFiles = vi.fn(async () => ['src/a.rs'])
    const wrapper = mountComposer({ searchFiles })
    await typeInto(wrapper, '@mcp:gith')
    expect(searchFiles).toHaveBeenCalledWith('mcp:gith')
    expect(wrapper.find('[data-testid="mcp-suggestions"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid="file-suggestions"] li')).toHaveLength(1)
  })
})

describe('SessionView @mcp: roster wiring (C-MCP-2.c)', () => {
  let wire: Wire

  beforeEach(() => {
    setActivePinia(createPinia())
    wire = new Wire()
    try {
      globalThis.localStorage?.clear()
    } catch {
      // no storage
    }
  })

  async function openSession(): Promise<ReturnType<typeof mount>> {
    wire = await Wire.connect()
    const session = useSessionStore('s1')
    const subscribed = session.subscribe()
    wire.answer('session.subscribe', {
      reset: true,
      throughSeq: 1,
      pendingAsks: [],
      snapshot: { sessionId: 's1', status: 'idle', lastSeq: 1, pendingAsks: [], queue: [], messages: [] },
    })
    await subscribed
    return mount(SessionView, { props: { id: 's1' }, global: { plugins: [] } })
  }

  it('fetches mcp.resources once per session, caching the roster for every later query', async () => {
    const wrapper = await openSession()
    const search = wrapper.findComponent(Composer).props('searchMcpResources') as (query: string) => Promise<McpMention[]>

    const first = search('gith')
    wire.answer('mcp.resources', {
      status: 'started',
      servers: [
        { server: 'github', resources: [{ uri: 'docs/readme', name: 'README' }] },
        { server: 'workspace', resources: [{ uri: 'notes/todo.md', name: 'todo list' }] },
      ],
    })
    await flushPromises()
    expect(await first).toEqual([GITHUB])
    expect(wire.sent('mcp.resources')).toHaveLength(1)

    // Later partials ride the cache — no second fetch, and both the uri and
    // name legs filter live.
    const second = search('todo')
    expect(wire.sent('mcp.resources')).toHaveLength(1)
    await flushPromises()
    expect(await second).toEqual([NOTES])

    const third = search('readme')
    expect(wire.sent('mcp.resources')).toHaveLength(1)
    await flushPromises()
    expect(await third).toEqual([GITHUB])
  })

  it('a not-started or failed roster caches empty rows so the composer falls back to files', async () => {
    const wrapper = await openSession()
    const search = wrapper.findComponent(Composer).props('searchMcpResources') as (query: string) => Promise<McpMention[]>

    const pending = search('any')
    wire.answer('mcp.resources', { status: 'not-started', servers: [] })
    await flushPromises()
    expect(await pending).toEqual([])
    expect(wire.sent('mcp.resources')).toHaveLength(1)
  })
})
