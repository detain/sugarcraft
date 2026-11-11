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

/**
 * m52 (cc-review-1111, CW-QUAL-A MIN4): the debounce timer captured the session
 * it was armed for, while the latch inside load() ranks RECENCY only. A reload
 * armed for sA that fires after the user switched (and measured) sB becomes the
 * newest generation of the VACATED session — it wins the latch and stamps sA's
 * record over sB's selection, blanking the panel the user is now looking at.
 * The armed work must carry identity: drop it when its session is no longer
 * the one the last load was armed for.
 */
describe('contextSources debounce across a session switch (m52)', () => {
  it('a reload armed for the previous session is dropped once the selection moved', async () => {
    await wire.connect()
    const store = useContextSourcesStore()
    await loadPanel('sA', store)

    // The debounced reload arms for sA while sA is the measured session.
    wire.event('sA', 'context.changed', { reason: 'settle' }, false)

    // The user switches to sB, and that measurement completes, BEFORE the
    // debounce window closes.
    const switching = store.load('sB')
    await flushPromises()
    await wire.answer('context.sources', sourcesFor('sB'))
    expect(store.sessionId).toBe('sB')
    expect(firstPath(store)).toBe('/repo-sB/CLAUDE.md')

    await wait(600)
    await flushPromises()

    // The armed sA reload must never fire once the selection moved.
    expect(wire.sent('context.sources')).toHaveLength(2)

    // If a straggler frame was dialled anyway (red-before), let its answer
    // land so the stale-write below is caught by the state asserts too.
    const stale = wire.sent('context.sources')[2]
    if (stale !== undefined) {
      wire.socket().answer(sourcesFor('sA-stale'), stale.id as string)
      await flushPromises()
    }
    expect(store.sessionId).toBe('sB')
    expect(firstPath(store)).toBe('/repo-sB/CLAUDE.md')
    expect(store.error).toBeNull()
  })

  it('the reload still runs for the session that stays selected', async () => {
    // Positive control: the identity gate must not neuter the debounce —
    // that half is pinned jointly with the first test of this file.
    await wire.connect()
    const store = useContextSourcesStore()
    await loadPanel('sA', store)
    wire.event('sA', 'context.changed', { reason: 'settle' }, false)
    await wait(600)
    await flushPromises()
    expect(wire.sent('context.sources')).toHaveLength(2)
  })
})
