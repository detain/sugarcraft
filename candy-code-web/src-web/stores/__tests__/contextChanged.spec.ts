import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { Wire } from '../agents/__tests__/harness'
import { useContextSourcesStore } from '../contextSources'

/**
 * C-CTX-2.d: `context.changed` is the server's "what the prompt is built from
 * moved" hint — a settle, a compaction, a rules/memory/init command. The
 * panel re-reads `context.sources` for its OWN measured session, debounced by
 * at least half a second so a burst of moves costs one refetch, and a session
 * nobody measured is left alone.
 */

let wire: Wire

function sourcesFor(id: string): Record<string, unknown> {
  return {
    instructionFiles: [{ path: `/repo-${id}/CLAUDE.md`, bytes: 10, tier: 'project' }],
    memorySections: [],
    skills: [],
    mcp: { status: 'trusted', error: null, skipped: [], servers: [] },
    untrusted: [],
    breakdown: null,
    trusts: [],
  }
}

function firstPath(store: ReturnType<typeof useContextSourcesStore>): string {
  const sources = store.sources as { instructionFiles: { path: string }[] } | null
  if (!sources) throw new Error('no sources loaded')
  return sources.instructionFiles[0]?.path as string
}

const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms))

async function loadPanel(id: string, store: ReturnType<typeof useContextSourcesStore>) {
  const load = store.load(id)
  await flushPromises()
  wire.socket().answer(sourcesFor(id), wire.sent('context.sources').pop()?.id as string)
  await load
  await flushPromises()
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

describe('contextSources on context.changed (C-CTX-2.d)', () => {
  it('refetches after the debounce window, never inside it', async () => {
    await wire.connect()
    const store = useContextSourcesStore()
    await loadPanel('sA', store)
    expect(wire.sent('context.sources')).toHaveLength(1)

    wire.event('sA', 'context.changed', { reason: 'settle' }, false)
    await wait(250)
    await flushPromises()
    expect(wire.sent('context.sources')).toHaveLength(1)

    await wait(350)
    await flushPromises()
    const frames = wire.sent('context.sources')
    expect(frames).toHaveLength(2)
    wire.socket().answer(sourcesFor('refreshed'), frames[1]?.id as string)
    await flushPromises()
    expect(firstPath(store)).toBe('/repo-refreshed/CLAUDE.md')
    expect(store.sessionId).toBe('sA')
  })

  it('a burst of moves costs exactly one refetch', async () => {
    await wire.connect()
    const store = useContextSourcesStore()
    await loadPanel('sA', store)

    wire.event('sA', 'context.changed', { reason: 'settle' }, false)
    wire.event('sA', 'context.changed', { reason: 'compaction' }, false)
    wire.event('sA', 'context.changed', { reason: 'rules' }, false)
    await wait(600)
    await flushPromises()
    expect(wire.sent('context.sources')).toHaveLength(2)
    expect(store.sessionId).toBe('sA')
  })

  it('another session, or an anonymous one, never refetches the panel', async () => {
    await wire.connect()
    const store = useContextSourcesStore()
    await loadPanel('sA', store)

    wire.event('sB', 'context.changed', { reason: 'memory' }, false)
    wire.event(null, 'context.changed', { reason: 'init' }, false)
    await wait(600)
    await flushPromises()
    expect(wire.sent('context.sources')).toHaveLength(1)
    expect(firstPath(store)).toBe('/repo-sA/CLAUDE.md')
  })

  it('an unmeasured panel ignores every move', async () => {
    await wire.connect()
    const store = useContextSourcesStore()
    wire.event('sA', 'context.changed', { reason: 'settle' }, false)
    await wait(600)
    await flushPromises()
    expect(wire.sent('context.sources')).toHaveLength(0)
    expect(store.sources).toBeNull()
  })
})
