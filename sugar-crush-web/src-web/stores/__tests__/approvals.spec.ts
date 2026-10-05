import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { flush } from '../../__tests__/fakes'
import { ask, Wire } from '../../components/grid/__tests__/harness'
import { useApprovalsStore } from '../approvals'

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
})

async function connected(): Promise<Wire> {
  const wire = await Wire.connect()
  wire.answer('permission.pending', { items: [] })
  await flush()
  return wire
}

describe('approvals across sessions', () => {
  it('shows a question in a session nobody here follows the moment it is put', async () => {
    const wire = await connected()
    const approvals = useApprovalsStore()
    const seen: string[] = []
    approvals.onNew((item) => seen.push(item.askId))

    wire.serverEvent('permission.asked', ask('k1', 'elsewhere'))
    expect(approvals.total).toBe(1)
    expect(approvals.countFor('elsewhere')).toBe(1)
    expect(seen).toEqual(['k1'])

    // A follower also hears the session's own copy: one question, still.
    wire.event('elsewhere', 'permission.requested', ask('k1', 'elsewhere'))
    expect(approvals.total).toBe(1)
    expect(seen).toEqual(['k1'])

    wire.serverEvent('permission.settled', { sessionId: 'elsewhere', askId: 'k1', reply: 'once' })
    expect(approvals.total).toBe(0)
  })

  it('groups by session, the longest-waiting question first', async () => {
    const wire = await connected()
    const approvals = useApprovalsStore()
    wire.serverEvent('permission.asked', ask('k1', 'b'))
    wire.serverEvent('permission.asked', ask('k2', 'a'))
    wire.serverEvent('permission.asked', ask('k3', 'b'))

    expect(approvals.all.map((item) => item.askId)).toEqual(['k1', 'k2', 'k3'])
    expect(approvals.bySession.map((group) => [group.sessionId, group.asks.map((item) => item.askId)])).toEqual([
      ['b', ['k1', 'k3']],
      ['a', ['k2']],
    ])
  })

  it('answers from the drawer, and drops a question another client already answered', async () => {
    const wire = await connected()
    const approvals = useApprovalsStore()
    wire.serverEvent('permission.asked', ask('k1', 's1'))
    wire.serverEvent('permission.asked', ask('k2', 's2'))

    const answered = approvals.respond(approvals.all[0]!, 'once', { cascade: true })
    expect(wire.sent('permission.respond').pop()?.params).toMatchObject({ sessionId: 's1', askId: 'k1', reply: 'once', cascade: true })
    wire.answer('permission.respond', { applied: true, reply: 'once' })
    await answered
    expect(approvals.all.map((item) => item.askId)).toEqual(['k2'])

    const late = approvals.respond(approvals.all[0]!, 'reject')
    wire.socket.fail(-32009, 'already_resolved')
    await late
    expect(approvals.total).toBe(0)
  })

  it('keeps the newest server list on a tick, announcing only what is new', async () => {
    const wire = await connected()
    const approvals = useApprovalsStore()
    const seen: string[] = []
    approvals.onNew((item) => seen.push(item.askId))
    wire.serverEvent('permission.asked', ask('k1', 's1'))

    wire.serverEvent('server.tick', { now: 1, turnsRunning: 1 })
    wire.answer('permission.pending', { items: [ask('k2', 's2'), ask('k1', 's1')] })
    await flush()

    expect(approvals.all.map((item) => item.askId)).toEqual(['k1', 'k2'])
    expect(seen).toEqual(['k1', 'k2'])
  })
})
