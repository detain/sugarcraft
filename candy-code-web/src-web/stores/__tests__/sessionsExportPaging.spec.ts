import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it } from 'vitest'
import { ProtocolError } from '../../protocol/jsonrpc'
import { Wire } from '../agents/__tests__/harness'
import { useSessionsStore } from '../sessions'

/**
 * C-WEB-7.c (WEB-1-P2): the store seam behind a transcript export that is
 * bigger than one frame. `exportTranscript` walks `session.export` by byte
 * pages, echoing the first page's `revision` from page 2 on, joins when a
 * page says `more` is false, restarts the walk once when the server reports
 * `export_changed` (the transcript re-rendered mid-walk), and guards against
 * an answer that claims `more` without advancing its offset.
 */

const EXPORT_LIMIT = 1_048_576

async function connected(): Promise<{ wire: Wire; sessions: ReturnType<typeof useSessionsStore> }> {
  setActivePinia(createPinia())
  const wire = new Wire()
  await wire.connect(() => useSessionsStore())
  return { wire, sessions: useSessionsStore() }
}

function page(over: Record<string, unknown>): Record<string, unknown> {
  return { format: 'markdown', ...over }
}

describe('sessions store export paging (C-WEB-7.c)', () => {
  it('joins three pages in order and asks for each one by the server offset', async () => {
    const { wire, sessions } = await connected()
    const pending = sessions.exportTranscript('s1', 'markdown')

    expect(wire.sent('session.export').at(-1)?.params).toEqual({ sessionId: 's1', format: 'markdown', offset: 0, limit: EXPORT_LIMIT })
    await wire.answer('session.export', page({ content: 'alpha-', offset: 0, nextOffset: 6, total: 15, more: true, revision: 'rev-1' }))

    expect(wire.sent('session.export').at(-1)?.params).toEqual({ sessionId: 's1', format: 'markdown', offset: 6, limit: EXPORT_LIMIT, revision: 'rev-1' })
    await wire.answer('session.export', page({ content: 'bravo-', offset: 6, nextOffset: 12, total: 15, more: true, revision: 'rev-1' }))

    expect(wire.sent('session.export').at(-1)?.params).toEqual({ sessionId: 's1', format: 'markdown', offset: 12, limit: EXPORT_LIMIT, revision: 'rev-1' })
    await wire.answer('session.export', page({ content: 'charlie', offset: 12, nextOffset: 19, total: 19, more: false, revision: 'rev-1' }))

    await expect(pending).resolves.toEqual({ format: 'markdown', content: 'alpha-bravo-charlie' })
    expect(wire.sent('session.export')).toHaveLength(3)
  })

  it('carries no revision on the first request and echoes it from page 2 on', async () => {
    const { wire, sessions } = await connected()
    const pending = sessions.exportTranscript('s1', 'markdown')

    const first = wire.sent('session.export').at(-1)?.params as Record<string, unknown>
    expect(Object.keys(first)).not.toContain('revision')
    await wire.answer('session.export', page({ content: 'one-', offset: 0, nextOffset: 4, total: 8, more: true, revision: 'rev-x' }))

    await wire.answer('session.export', page({ content: 'two', offset: 4, nextOffset: 7, total: 7, more: false, revision: 'rev-x' }))
    await pending

    const second = wire.sent('session.export').at(-1)?.params as Record<string, unknown>
    expect(second.revision).toBe('rev-x')
    expect(second.offset).toBe(4)
  })

  it('restarts once from offset 0 on export_changed', async () => {
    const { wire, sessions } = await connected()
    const pending = sessions.exportTranscript('s1', 'markdown')

    await wire.answer('session.export', page({ content: 'old-', offset: 0, nextOffset: 4, total: 9, more: true, revision: 'rev-old' }))
    await wire.fail('session.export', -32009, 'export_changed')

    // The restart: the whole walk is thrown away and begun again.
    expect(wire.sent('session.export').at(-1)?.params).toEqual({ sessionId: 's1', format: 'markdown', offset: 0, limit: EXPORT_LIMIT })
    await wire.answer('session.export', page({ content: 'fresh-again', offset: 0, nextOffset: 11, total: 11, more: false, revision: 'rev-new' }))

    await expect(pending).resolves.toEqual({ format: 'markdown', content: 'fresh-again' })
  })

  it('a second export_changed rejects through the existing error line', async () => {
    const { wire, sessions } = await connected()
    const pending = sessions.exportTranscript('s1', 'markdown')
    // Attach the rejection observer at once: the conflict answer lands
    // synchronously inside a later wire.fail, before any assert is in flight.
    const settled = pending.catch((error: unknown) => ({ error }))

    await wire.answer('session.export', page({ content: 'old-', offset: 0, nextOffset: 4, total: 9, more: true, revision: 'rev-old' }))
    await wire.fail('session.export', -32009, 'export_changed')
    await wire.answer('session.export', page({ content: 'mid-', offset: 0, nextOffset: 4, total: 9, more: true, revision: 'rev-mid' }))
    await wire.fail('session.export', -32009, 'export_changed')

    const outcome = await settled
    expect((outcome as { error: unknown }).error).toBeInstanceOf(ProtocolError)
    expect((outcome as { error: unknown }).error).toMatchObject({ kind: 'export_changed' })
  })

  it('refuses to spin on a page that answers more without advancing', async () => {
    const { wire, sessions } = await connected()
    const pending = sessions.exportTranscript('s1', 'markdown')
    const settled = pending.catch((error: unknown) => ({ error }))

    await wire.answer('session.export', page({ content: 'same', offset: 0, nextOffset: 0, total: 99, more: true, revision: 'rev-1' }))

    const outcome = await settled
    expect((outcome as { error: Error }).error.message).toMatch(/without advancing/)
    expect(wire.sent('session.export')).toHaveLength(1)
  })

  it('an unrelated protocol error propagates untouched', async () => {
    const { wire, sessions } = await connected()
    const pending = sessions.exportTranscript('s1', 'markdown')
    const settled = pending.catch((error: unknown) => ({ error }))

    await wire.fail('session.export', -32004, 'session_not_found')

    const outcome = await settled
    expect((outcome as { error: unknown }).error).toMatchObject({ kind: 'session_not_found' })
  })
})
