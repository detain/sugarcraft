import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { Wire } from '../agents/__tests__/harness'
import { useContextSourcesStore } from '../contextSources'

/**
 * C-SEC-4: the trust prompt's life in the store when no panel has measured.
 * `session.subscribe` mints the prompt server-side without `context.sources`
 * ever running, so the badge shelf (`pendingTrust`) must hold prompts the
 * `sources` record cannot (it is null), the answer must carry the sessionId
 * the gateway routes by, and a refused `trust_write_failed` must leave the
 * prompt open on both shelves rather than lying about persistence.
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
    trust: null,
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
    expect(store.pendingTrust?.askId).toBe('trust-9f0e1d2c3')
    expect(store.pendingTrust?.status).toBe('asked')
  })

  it('answers a shelf-only prompt with the session that was asked on', async () => {
    await wire.connect()
    const store = useContextSourcesStore()
    wire.event(null, 'permission.asked', { ...TRUST }, false)

    const settled = store.respondTrust('reject', 'session')
    await flushPromises()
    expect(wire.sent('context.trust').at(-1)?.params).toEqual({
      sessionId: 's1',
      askId: 'trust-9f0e1d2c3',
      decision: 'reject',
      remember: 'session',
    })
    await wire.answer('context.trust', { askId: 'trust-9f0e1d2c3', status: 'rejected', persisted: false })
    await expect(settled).resolves.toBe(true)
    expect(store.pendingTrust?.status).toBe('rejected')
    expect(store.sources).toBeNull()
  })

  it('a refused write leaves the prompt asked on both shelves and allows a retry', async () => {
    await wire.connect()
    const store = useContextSourcesStore()
    const load = store.load('s1')
    await flushPromises()
    wire.socket().answer(
      { ...sourcesFor('s1'), trust: { askId: TRUST.askId, kind: 'trust', target: 'mcp', root: '/repo', status: 'asked', persisted: false } },
      wire.sent('context.sources').at(-1)?.id as string,
    )
    await load
    await flushPromises()

    const first = store.respondTrust('grant', 'user')
    await flushPromises()
    const refusal = expect(first).rejects.toMatchObject({ kind: 'trust_write_failed' })
    await wire.fail('context.trust', -32009, 'trust_write_failed', 'the trustedProjectMcp list was not updated')
    await refusal
    expect(store.pendingTrust?.status).toBe('asked')
    expect((store.sources as { trust?: { status: string } } | null)?.trust?.status).toBe('asked')

    const second = store.respondTrust('grant', 'user')
    await flushPromises()
    await wire.answer('context.trust', { askId: TRUST.askId, status: 'granted', persisted: true })
    await expect(second).resolves.toBe(true)
    expect(store.pendingTrust?.status).toBe('granted')
  })

  it('a prompt from another session never displaces the open one', async () => {
    await wire.connect()
    const store = useContextSourcesStore()
    wire.event(null, 'permission.asked', { ...TRUST }, false)

    wire.event(null, 'permission.asked', { ...TRUST, askId: 'trust-111122223', sessionId: 'other' }, false)
    expect(store.pendingTrust?.askId).toBe('trust-9f0e1d2c3')

    // Even a same-session different prompt must not clobber an unanswered one.
    wire.event(null, 'permission.asked', { ...TRUST, askId: 'trust-444455556' }, false)
    expect(store.pendingTrust?.askId).toBe('trust-9f0e1d2c3')
  })
})
