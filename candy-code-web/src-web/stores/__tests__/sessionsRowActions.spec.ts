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
    expect(wire.sent('session.export').at(-1)?.params).toEqual({ sessionId: 's1', format: 'json' })
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
