import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it } from 'vitest'
import type { SessionSummary } from '../../protocol/generated'
import { Wire } from '../agents/__tests__/harness'
import { useSessionsStore } from '../sessions'

/**
 * CW-DBA-2 (reviews-1103): `load()` used to answer into a wholesale
 * `byId.value = next` clobber, so any row the events put into the index
 * WHILE the walk was in flight vanished the moment the (older) page
 * arrived. The merge law now pinned here is the same before-keys law the
 * approvals shelf follows: rows that appeared while the request was in
 * flight survive the replace, a mid-flight update with a newer stamp
 * beats a staler page copy, and pre-existing rows the server omits are
 * still retired.
 */

function row(id: string, overrides: Partial<SessionSummary> = {}): SessionSummary {
  return {
    id,
    name: `session ${id}`,
    status: 'idle',
    open: true,
    preview: null,
    createdAt: '2026-10-05 10:00:00',
    updatedAt: '2026-10-05 10:00:00',
    permissionMode: 'default',
    root: '/repo',
    ...overrides,
  }
}

async function quiet(): Promise<{ wire: Wire; sessions: ReturnType<typeof useSessionsStore> }> {
  setActivePinia(createPinia())
  const wire = new Wire()
  await wire.connect(() => useSessionsStore())
  // Settle the handshake-triggered listing walk so the controlled load
  // below owns the only in-flight session.list frame.
  await wire.answer('session.list', { items: [], nextCursor: null })
  await wire.answer('session.shares', { items: [] })
  return { wire, sessions: useSessionsStore() }
}

describe('sessions load merges instead of clobbering (CW-DBA-2)', () => {
  it('keeps a row created while the listing walk is in flight', async () => {
    const { wire, sessions } = await quiet()

    const pending = sessions.load()
    // A session.created event lands mid-walk — the event listener puts the
    // row into the index while the page request is still unanswered.
    wire.event(null, 'session.created', { ...row('b') })
    await wire.answer('session.list', { items: [row('a')], nextCursor: null })
    await pending

    expect(sessions.byId.a?.id).toBe('a')
    expect(sessions.byId.b?.id).toBe('b')
  })

  it('lets a mid-flight update with a newer stamp beat the staler page copy', async () => {
    const { wire, sessions } = await quiet()

    const pending = sessions.load()
    wire.event(null, 'session.updated', { ...row('a', { updatedAt: '2026-10-05 12:00:00' }) })
    await wire.answer('session.list', { items: [row('a', { updatedAt: '2026-10-05 11:00:00' })], nextCursor: null })
    await pending

    expect(sessions.byId.a?.updatedAt).toBe('2026-10-05 12:00:00')
  })

  it('still retires a pre-existing row the fresh page omits', async () => {
    const { wire, sessions } = await quiet()
    sessions.byId = { z: row('z') }

    const pending = sessions.load()
    await wire.answer('session.list', { items: [row('a')], nextCursor: null })
    await pending

    expect(sessions.byId.a?.id).toBe('a')
    expect(sessions.byId.z).toBeUndefined()
  })
})

/**
 * CW-DBA-7 (reviews-1103): the walk silently stopped at PAGE x MAX_PAGES
 * (2,000 rows) with no signal that the server had more. `capped` now says
 * so; the sidebar/banner paint is deferred to its own lane (seam row in
 * the lane report).
 */
describe('sessions listing reports its page budget (CW-DBA-7)', () => {
  it('flags capped when the walk stops on its budget with rows still listed', async () => {
    const { wire, sessions } = await quiet()

    const pending = sessions.load()
    for (let page = 0; page < 10; page++) {
      await wire.answer('session.list', { items: [row(`p${page}`)], nextCursor: `c${page}` })
    }
    await pending

    expect(sessions.capped).toBe(true)
    expect(Object.keys(sessions.byId)).toHaveLength(10)
  })

  it('keeps the flag down when a walk runs to the end of the listing', async () => {
    const { wire, sessions } = await quiet()
    expect(sessions.capped).toBe(false)

    const pending = sessions.load()
    await wire.answer('session.list', { items: [row('a')], nextCursor: null })
    await pending

    expect(sessions.capped).toBe(false)
  })
})
