import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { Wire } from '../agents/__tests__/harness'
import { useContextSourcesStore } from '../contextSources'

/**
 * C-SEC-4: the trust prompts' life in the store when no panel has measured.
 * `session.subscribe` mints them server-side without `context.sources` ever
 * running, so the badge shelf (`pendingTrusts`) must hold prompts the
 * `sources` record cannot (it is null), the answer must carry the sessionId
 * the gateway routes by, and a refused `trust_write_failed` must leave the
 * prompt open on both shelves rather than lying about persistence.
 *
 * C-SEC-5.b: the shelf is a LIST — one row per pending grant door, merged by
 * askId. A second prompt is never an override of the first; answering one row
 * by its askId leaves the others untouched.
 */

const TRUST = {
  askId: 'trust-9f0e1d2c3',
  kind: 'trust' as const,
  target: 'mcp' as const,
  root: '/repo',
  reason: 'This project declares MCP servers in .mcp.json.',
  status: 'asked' as const,
  persisted: false,
  sessionId: 's1',
}

function sourcesFor(id: string): Record<string, unknown> {
  return {
    instructionFiles: [],
    memorySections: [],
    skills: [],
    mcp: { status: 'trusted', error: null, skipped: [], servers: [] },
    untrusted: [],
    breakdown: null,
    trusts: [],
  }
}

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

describe('contextSources trust routing (C-SEC-4)', () => {
  it('holds a prompt that arrives before any measurement on the badge shelf', async () => {
    await wire.connect()
    const store = useContextSourcesStore()

    wire.event(null, 'permission.asked', { ...TRUST }, false)

    // Pre-fix this was dropped whole: `sources === null` meant no shelf at all,
    // and the StatusBar had nothing to point the watcher at.
    expect(store.sources).toBeNull()
    expect(store.pendingTrusts.find((row) => row.askId === TRUST.askId)?.askId).toBe('trust-9f0e1d2c3')
    expect(store.pendingTrusts.find((row) => row.askId === TRUST.askId)?.status).toBe('asked')
  })

  it('answers a shelf-only prompt with the session that was asked on', async () => {
    await wire.connect()
    const store = useContextSourcesStore()
    wire.event(null, 'permission.asked', { ...TRUST }, false)

    const settled = store.respondTrust(TRUST.askId, 'reject', 'session')
    await flushPromises()
    expect(wire.sent('context.trust').at(-1)?.params).toEqual({
      sessionId: 's1',
      askId: 'trust-9f0e1d2c3',
      decision: 'reject',
      remember: 'session',
    })
    await wire.answer('context.trust', { askId: 'trust-9f0e1d2c3', status: 'rejected', persisted: false })
    await expect(settled).resolves.toBe(true)
    expect(store.pendingTrusts.find((row) => row.askId === TRUST.askId)?.status).toBe('rejected')
    expect(store.sources).toBeNull()
  })

  it('a refused write leaves the prompt asked on both shelves and allows a retry', async () => {
    await wire.connect()
    const store = useContextSourcesStore()
    const load = store.load('s1')
    await flushPromises()
    wire.socket().answer(
      { ...sourcesFor('s1'), trusts: [{ askId: TRUST.askId, kind: 'trust', target: 'mcp', root: '/repo', status: 'asked', persisted: false }] },
      wire.sent('context.sources').at(-1)?.id as string,
    )
    await load
    await flushPromises()

    const first = store.respondTrust(TRUST.askId, 'grant', 'user')
    await flushPromises()
    const refusal = expect(first).rejects.toMatchObject({ kind: 'trust_write_failed' })
    await wire.fail('context.trust', -32009, 'trust_write_failed', 'the trustedProjectMcp list was not updated')
    await refusal
    expect(store.pendingTrusts.find((row) => row.askId === TRUST.askId)?.status).toBe('asked')
    expect((store.sources as { trusts?: { askId: string; status: string }[] } | null)?.trusts?.find((row) => row.askId === TRUST.askId)?.status).toBe('asked')

    const second = store.respondTrust(TRUST.askId, 'grant', 'user')
    await flushPromises()
    await wire.answer('context.trust', { askId: TRUST.askId, status: 'granted', persisted: true })
    await expect(second).resolves.toBe(true)
    expect(store.pendingTrusts.find((row) => row.askId === TRUST.askId)?.status).toBe('granted')
  })

  /**
   * C-SEC-5.b: the shelf holds every door's prompt side by side. A prompt
   * from another session never joins it once a measurement has named the
   * session — before any measurement the store cannot know which session is
   * its own, so filtering begins with the answer. A same-session prompt from
   * a DIFFERENT door is its own row — not an override — and a re-delivery of
   * the same askId updates that row only.
   */
  it('keeps same-session prompts side by side and filters other sessions', async () => {
    await wire.connect()
    const store = useContextSourcesStore()
    const load = store.load('s1')
    await flushPromises()
    wire.socket().answer(
      { ...sourcesFor('s1'), trusts: [TRUST] },
      wire.sent('context.sources').at(-1)?.id as string,
    )
    await load
    await flushPromises()
    expect(store.pendingTrusts).toHaveLength(1)

    // The measured session is known now; a foreign prompt is dropped.
    wire.event(null, 'permission.asked', { ...TRUST, askId: 'trust-111122223', sessionId: 'other' }, false)
    await flushPromises()
    expect(store.pendingTrusts).toHaveLength(1)

    // A second door's prompt joins as its OWN row (the census lists them all).
    wire.event(null, 'permission.asked', { ...TRUST, askId: 'trust-444455556', target: 'hooks' as const }, false)
    await flushPromises()
    expect(store.pendingTrusts.map((row) => row.askId)).toEqual(['trust-9f0e1d2c3', 'trust-444455556'])

    // Re-delivery of the first askId replaces its row, never adds a twin.
    wire.event(null, 'permission.asked', { ...TRUST, status: 'granted' as const, persisted: true }, false)
    await flushPromises()
    expect(store.pendingTrusts.map((row) => row.askId)).toEqual(['trust-9f0e1d2c3', 'trust-444455556'])
    expect(store.pendingTrusts[0].status).toBe('granted')
  })
})
