import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it } from 'vitest'
import type { SessionSummary } from '../../protocol/generated'
import { Wire } from '../agents/__tests__/harness'
import { useSessionStore } from '../session'
import { useSessionsStore } from '../sessions'

/**
 * WEB-1a: the store seams behind the session-row menu — fork, close, export
 * and delete ride the protocol methods that already existed unwired, and
 * the queue strip's reload re-reads `session.queue` into the followed state.
 * The `session.fork` / `close` / `delete` requests carry a client-stamped
 * `idempotencyKey` (IDEMPOTENT_METHODS), so the params are matched to the
 * session rather than shape-locked.
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

async function connected(): Promise<{ wire: Wire; sessions: ReturnType<typeof useSessionsStore> }> {
  setActivePinia(createPinia())
  const wire = new Wire()
  await wire.connect(() => useSessionsStore())
  return { wire, sessions: useSessionsStore() }
}

describe('sessions store row actions (WEB-1a)', () => {
  it('forks through session.fork and lists the returned summary', async () => {
    const { wire, sessions } = await connected()
    const pending = sessions.fork('s1')
    expect(wire.sent('session.fork').at(-1)?.params).toMatchObject({ sessionId: 's1' })
    await wire.answer('session.fork', row('s9', { name: 's1 again' }))
    expect((await pending).id).toBe('s9')
    expect(sessions.byId.s9?.name).toBe('s1 again')
  })

  it('closes through session.close and stamps the row closed without dropping it', async () => {
    const { wire, sessions } = await connected()
    sessions.byId = { s1: row('s1') }
    const pending = sessions.close('s1')
    expect(wire.sent('session.close').at(-1)?.params).toMatchObject({ sessionId: 's1' })
    await wire.answer('session.close', { closed: true })
    await pending
    expect(sessions.byId.s1?.status).toBe('closed')
  })

  it('exports through session.export with the asked-for format', async () => {
    const { wire, sessions } = await connected()
    const pending = sessions.exportTranscript('s1', 'json')
    // C-WEB-7.c: the walk always asks for a page; an answer without `more`
    // joins as the single page it is, byte-for-byte the historical shape.
    expect(wire.sent('session.export').at(-1)?.params).toEqual({ sessionId: 's1', format: 'json', offset: 0, limit: 1_048_576 })
    await wire.answer('session.export', { format: 'json', content: '{"messages":[]}' })
    await expect(pending).resolves.toEqual({ format: 'json', content: '{"messages":[]}' })
  })

  it('deletes through session.delete and drops the row (the seam the menu confirms in front of)', async () => {
    const { wire, sessions } = await connected()
    sessions.byId = { s1: row('s1') }
    const pending = sessions.remove('s1')
    expect(wire.sent('session.delete').at(-1)?.params).toMatchObject({ sessionId: 's1' })
    await wire.answer('session.delete', { deleted: ['s1'] })
    await pending
    expect(sessions.byId.s1).toBeUndefined()
  })

  /**
   * C-SES-7.e: the archive tray at store level. The row actions answer with
   * the fresh summary and `applyArchived` enforces the tray law: an archived
   * row while the tray is hidden leaves the index, anything else lands.
   */
  it('archives through session.archive and drops the row while the tray is hidden', async () => {
    const { wire, sessions } = await connected()
    sessions.byId = { s1: row('s1') }
    const pending = sessions.archive('s1')
    expect(wire.sent('session.archive').at(-1)?.params).toMatchObject({ sessionId: 's1' })
    await wire.answer('session.archive', row('s1', { archived: true }))
    await pending
    expect(sessions.byId.s1).toBeUndefined()
  })

  it('keeps an archived row while the tray is shown, and unarchive restores it', async () => {
    const { wire, sessions } = await connected()
    sessions.showArchived = true
    sessions.byId = { s1: row('s1') }
    const archived = sessions.archive('s1')
    await wire.answer('session.archive', row('s1', { archived: true }))
    await archived
    expect(sessions.byId.s1?.archived).toBe(true)

    const restored = sessions.unarchive('s1')
    expect(wire.sent('session.unarchive').at(-1)?.params).toMatchObject({ sessionId: 's1' })
    await wire.answer('session.unarchive', row('s1', { archived: false }))
    await restored
    expect(sessions.byId.s1).toBeDefined()
    expect(sessions.byId.s1?.archived).toBe(false)
  })

  it('creates an ephemeral session through session.create {ephemeral:true}', async () => {
    const { wire, sessions } = await connected()
    const pending = sessions.create({ ephemeral: true })
    expect(wire.sent('session.create').at(-1)?.params).toMatchObject({ ephemeral: true })
    await wire.answer('session.create', row('e1', { ephemeral: true, open: true }))
    const summary = await pending
    expect(summary.id).toBe('e1')
    expect(sessions.byId.e1?.ephemeral).toBe(true)
  })

  it('refreshQueue re-reads session.queue into the followed state', async () => {
    const { wire } = await connected()
    const session = useSessionStore('s1')
    const pending = session.refreshQueue()
    expect(wire.sent('session.queue').at(-1)?.params).toEqual({ sessionId: 's1' })
    await wire.answer('session.queue', { items: [{ queueId: 'q1', text: 'second thought', position: 1 }] })
    await pending
    expect(session.state.queue).toEqual([{ queueId: 'q1', text: 'second thought', position: 1 }])
  })
})

/**
 * C-SES-7.e: the two store laws the row-action specs above cannot express
 * — other clients' ephemeral announcements never enter the index at all,
 * and showing the tray re-reads the server as a second `session.list` walk
 * instead of client-side-sweeping whatever page set was last cached.
 */
describe('sessions store ephemeral skip and tray reload (C-SES-7.e)', () => {
  async function tick(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 0))
  }

  it('never indexes another client\'s ephemeral announcements, created or updated', async () => {
    const { wire, sessions } = await connected()
    const loose = (summary: SessionSummary) => summary as unknown as Record<string, unknown>
    wire.event(null, 'session.created', loose(row('g1', { ephemeral: true })))
    wire.event(null, 'session.updated', loose(row('g1', { ephemeral: true, status: 'busy' })))
    await tick()
    expect(sessions.byId.g1).toBeUndefined()
    // Control: the identical plumbing without the flag does index — the
    // skip is the ephemeral law, not dead event wiring.
    wire.event(null, 'session.created', loose(row('s2')))
    await tick()
    expect(sessions.byId.s2?.id).toBe('s2')
  })

  it('setShowArchived(true) reloads the live walk and then asks for the archived page set', async () => {
    const { wire, sessions } = await connected()
    const settling = sessions.setShowArchived(true)
    await wire.answer('session.list', { items: [row('s1')], nextCursor: null })
    await wire.answer('session.list', { items: [row('a1', { archived: true })], nextCursor: null })
    await settling
    expect(sessions.showArchived).toBe(true)
    const asked = wire.sent('session.list')
    expect(asked.some((frame) => (frame.params as { archived?: boolean } | undefined)?.archived === true)).toBe(true)
    expect(sessions.byId.s1?.id).toBe('s1')
    expect(sessions.byId.a1?.archived).toBe(true)
  })
})
