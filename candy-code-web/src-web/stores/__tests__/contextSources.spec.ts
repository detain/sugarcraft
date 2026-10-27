import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { Wire } from '../agents/__tests__/harness'
import { useContextSourcesStore } from '../contextSources'

/**
 * WEB-3a review MINOR-2: the store's ordering latch. A late `context.sources`
 * answer for session A must never overwrite session B's fresh record (the
 * stale-wins lesson: the panel guard would then blank B until a manual reload),
 * and neither may A's failure land as B's error line.
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
    trust: null,
  }
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

describe('contextSources ordering latch (WEB-3a MINOR-2)', () => {
  it('a late answer for the previous session is dropped whole', async () => {
    await wire.connect()
    const store = useContextSourcesStore()
    const first = store.load('sA')
    await flushPromises()
    void store.load('sB')
    await flushPromises()

    const frames = wire.sent('context.sources')
    expect(frames).toHaveLength(2)
    // B settles first; A's straggler lands after it was superseded.
    wire.socket().answer(sourcesFor('B'), frames[1]?.id as string)
    await flushPromises()
    expect(store.sessionId).toBe('sB')
    expect((store.sources as { instructionFiles: { path: string }[] } | null)?.instructionFiles[0]?.path).toBe('/repo-B/CLAUDE.md')

    wire.socket().answer(sourcesFor('A'), frames[0]?.id as string)
    await first
    await flushPromises()
    // A's answer changed nothing: still B's data, B's stamp, no error.
    expect(store.sessionId).toBe('sB')
    expect((store.sources as { instructionFiles: { path: string }[] } | null)?.instructionFiles[0]?.path).toBe('/repo-B/CLAUDE.md')
    expect(store.error).toBeNull()
  })

  it('a failure for the superseded session does not poison the current one', async () => {
    await wire.connect()
    const store = useContextSourcesStore()
    const first = store.load('sA')
    await flushPromises()
    void store.load('sB')
    await flushPromises()

    const frames = wire.sent('context.sources')
    wire.socket().answer(sourcesFor('B'), frames[1]?.id as string)
    await flushPromises()
    wire.socket().fail(-32001, 'forbidden', 'principal lacks read', frames[0]?.id as string)
    await first
    await flushPromises()
    expect(store.error).toBeNull()
    expect(store.sessionId).toBe('sB')
    expect(store.loaded).toBe(true)
    // Loading settled (the current generation finished), so no stuck spinner.
    expect(store.loading).toBe(false)
  })

  it('C-SEC-4: the trust answer carries the session it was asked on', async () => {
    await wire.connect()
    const store = useContextSourcesStore()
    const load = store.load('s1')
    await flushPromises()
    const frame = wire.sent('context.sources').at(-1)
    wire.socket().answer(
      {
        ...sourcesFor('s1'),
        trust: {
          askId: 'trust-9f0e1d2c3',
          kind: 'trust',
          target: 'mcp',
          root: '/repo-s1',
          status: 'asked',
          persisted: false,
        },
      },
      frame?.id as string,
    )
    await load
    await flushPromises()

    const settled = store.respondTrust('grant', 'user')
    await flushPromises()
    expect(wire.sent('context.trust').at(-1)?.params).toEqual({
      sessionId: 's1',
      askId: 'trust-9f0e1d2c3',
      decision: 'grant',
      remember: 'user',
    })
    wire.socket().answer(
      { askId: 'trust-9f0e1d2c3', status: 'granted', persisted: true },
      wire.sent('context.trust').at(-1)?.id as string,
    )
    await expect(settled).resolves.toBe(true)
    expect(store.pendingTrust?.status).toBe('granted')
  })
})
