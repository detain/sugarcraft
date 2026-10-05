import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { flush } from '../../../__tests__/fakes'
import { useAgentsStore } from '../agents'
import { useBackgroundStore } from '../background'
import { useMemoryStore } from '../memory'
import { usePanelsStore } from '../panels'
import { useTodosStore } from '../todos'
import { parseVars, useWorkflowsStore } from '../workflows'
import { Wire } from './harness'

let wire: Wire

beforeEach(() => {
  setActivePinia(createPinia())
  wire = new Wire()
  try {
    globalThis.localStorage?.clear()
  } catch {
    // no storage: nothing to clear
  }
})

const bg = (overrides: Record<string, unknown> = {}) => ({ bgId: 'bg1', name: 'lint', task: 'lint it', status: 'running', createdAt: '2026-10-05 10:00:00', outputBytes: 0, adopted: false, ...overrides })

describe('agents store', () => {
  it('reads the subtree, and a run\'s transcript page by page', async () => {
    await wire.connect()
    const agents = useAgentsStore()
    const refreshed = agents.refresh('s1')
    await wire.answer('agents.subtree', { items: [{ id: 'a1', op: 'started', name: 'reviewer', task: 'review' }] })
    await refreshed
    expect(agents.seed('s1')).toHaveLength(1)

    const loading = agents.loadTranscript('s1', 'a1')
    await flush()
    expect(wire.sent('agents.transcript').pop()?.params).toEqual({ sessionId: 's1', agentId: 'a1', offset: 0 })
    await wire.answer('agents.transcript', { agentId: 'a1', offset: 40, items: [{ t: 'user', text: 'review' }], more: true, finished: false })
    expect(wire.sent('agents.transcript').pop()?.params).toEqual({ sessionId: 's1', agentId: 'a1', offset: 40 })
    await wire.answer('agents.transcript', { agentId: 'a1', offset: 90, items: [{ t: 'assistant', text: 'ok' }], more: false, finished: false })
    await loading
    const view = agents.transcript('s1', 'a1')
    expect(view.items.map((i) => i.t)).toEqual(['user', 'assistant'])
    expect(view.offset).toBe(90)
    expect(view.loading).toBe(false)
  })

  it('shows a sent message as pending until the run reads it', async () => {
    await wire.connect()
    const agents = useAgentsStore()
    const sending = agents.message('s1', 'a1', 'also the tests')
    await flush()
    expect(agents.transcript('s1', 'a1').pending[0]?.status).toBe('sending')
    await wire.answer('agents.message', { agentId: 'a1', status: 'queued', msgId: 'm1' })
    expect((await sending).status).toBe('queued')
    expect(agents.transcript('s1', 'a1').pending).toEqual([{ text: 'also the tests', status: 'queued', msgId: 'm1' }])

    const loading = agents.loadTranscript('s1', 'a1')
    await flush()
    await wire.answer('agents.transcript', { agentId: 'a1', offset: 10, items: [{ t: 'inbox', text: 'also the tests', from: 'user', msgId: 'm1' }], more: false, finished: false })
    await loading
    expect(agents.transcript('s1', 'a1').pending).toEqual([])
  })

  it('keeps a refused message with its reason, and sends controls', async () => {
    await wire.connect()
    const agents = useAgentsStore()
    const sending = agents.message('s1', 'a1', 'hi')
    await flush()
    await wire.fail('agents.message', -32009, 'not_resumable', 'reviewer cannot be continued')
    expect(await sending).toMatchObject({ status: 'failed', error: 'reviewer cannot be continued' })
    agents.dismissPending('s1', 'a1', 0)
    expect(agents.transcript('s1', 'a1').pending).toEqual([])

    const pausing = agents.control('s1', 'a1', 'pause')
    await flush()
    expect(wire.sent('agents.control').pop()?.params).toMatchObject({ sessionId: 's1', agentId: 'a1', verb: 'pause' })
    await wire.answer('agents.control', { agentId: 'a1', status: 'queued', msgId: 'm2' })
    await pausing
  })
})

describe('todos store', () => {
  it('loads a session\'s list, then follows todo.updated without going backwards', async () => {
    await wire.connect(() => useTodosStore())
    const todos = useTodosStore()
    const loading = todos.load('s1')
    await flush()
    await wire.answer('todo.get', { items: [{ content: 'a', status: 'pending' }] })
    await loading
    expect(todos.forSession('s1').items).toEqual([{ content: 'a', status: 'pending' }])

    wire.event('s1', 'todo.updated', { toolCallId: 't1', items: [{ content: 'a', status: 'completed' }] }, true, 7)
    expect(todos.forSession('s1')).toMatchObject({ seq: 7, items: [{ content: 'a', status: 'completed' }] })
    wire.event('s1', 'todo.updated', { toolCallId: 't0', items: [{ content: 'old', status: 'pending' }] }, true, 3)
    expect(todos.forSession('s1').items[0]?.content).toBe('a')
  })
})

describe('background store', () => {
  it('lists on hello and follows the bg events', async () => {
    await wire.connect(() => useBackgroundStore())
    const background = useBackgroundStore()
    await wire.answer('bg.list', { items: [bg()] })
    expect(background.running).toBe(1)

    wire.event(null, 'bg.status', { bgId: 'bg1', status: 'stalled', previous: 'running' }, false)
    expect(background.byId.bg1?.status).toBe('stalled')
    wire.event(null, 'bg.completed', bg({ status: 'completed', outputBytes: 5 }), false)
    expect(background.running).toBe(0)

    const reading = background.loadOutput('bg1')
    await flush()
    await wire.answer('bg.output', { bgId: 'bg1', offset: 0, text: 'héllo', total: 6, status: 'completed' })
    await reading
    expect(background.output('bg1')).toMatchObject({ text: 'héllo', offset: 6, total: 6 })

    const injecting = background.inject('bg1', 's1')
    await flush()
    expect(wire.sent('bg.inject').pop()?.params).toMatchObject({ bgId: 'bg1', sessionId: 's1', delivery: 'queue' })
    await wire.answer('bg.inject', { bgId: 'bg1', sessionId: 's1', admitted: 'started' })
    await injecting
  })
})

describe('workflows store', () => {
  it('lists on hello, runs one with its context, and re-reads the runs when the turn ends', async () => {
    await wire.connect(() => useWorkflowsStore())
    const workflows = useWorkflowsStore()
    await wire.answer('workflow.list', { available: true, items: [{ name: 'review' }] })
    expect(workflows.names).toEqual(['review'])

    const listing = workflows.loadRuns('s1')
    await flush()
    await wire.answer('workflow.runs', { items: [] })
    await listing

    const running = workflows.run('s1', 'review', { branch: 'main' })
    await flush()
    expect(wire.sent('workflow.run').pop()?.params).toMatchObject({ sessionId: 's1', name: 'review', vars: { branch: 'main' } })
    await wire.answer('workflow.run', { rows: [], effects: ['occupy_turn'] })
    await running
    await wire.answer('workflow.runs', { items: [] })

    wire.event('s1', 'turn.completed', { stopReason: 'end_turn' })
    await flush()
    await wire.answer('workflow.runs', { items: [{ source: 'command', name: 'review', status: 'completed', running: false, workflowId: 'wf1' }] })
    expect(workflows.runsFor('s1')[0]).toMatchObject({ name: 'review', workflowId: 'wf1' })
  })

  it('parses key=value context, and says why it cannot', () => {
    expect(parseVars('  branch=main strict=true ')).toEqual({ branch: 'main', strict: 'true' })
    expect(parseVars('')).toEqual({})
    expect(parseVars('branch')).toBe('"branch" is not key=value')
    expect(parseVars('9x=1')).toBe('"9x" is not a valid name')
  })
})

describe('memory store', () => {
  it('lists a scope, searches every scope, adds into the scope shown, deletes', async () => {
    await wire.connect()
    const memory = useMemoryStore()
    const listing = memory.load('user')
    await flush()
    expect(wire.sent('memory.list').pop()?.params).toEqual({ scope: 'user' })
    await wire.answer('memory.list', { items: [{ id: 'n1', scope: 'user', content: 'tabs' }] })
    await listing
    expect(memory.entries).toHaveLength(1)

    const adding = memory.add('spaces', ['style'])
    await flush()
    expect(wire.sent('memory.add').pop()?.params).toMatchObject({ content: 'spaces', scope: 'user', tags: ['style'] })
    await wire.answer('memory.add', { id: 'n2' })
    await wire.answer('memory.list', { items: [{ id: 'n1', scope: 'user', content: 'tabs' }, { id: 'n2', scope: 'user', content: 'spaces' }] })
    await adding
    expect(memory.entries).toHaveLength(2)

    const searching = memory.search('tab')
    await flush()
    expect(wire.sent('memory.search').pop()?.params).toEqual({ query: 'tab', limit: 100 })
    await wire.answer('memory.search', { items: [{ id: 'n1', scope: 'user', content: 'tabs' }] })
    await searching

    const removing = memory.remove('n1')
    await flush()
    await wire.answer('memory.delete', { deleted: true })
    await removing
    expect(memory.entries).toEqual([])
  })
})

describe('panels store', () => {
  it('opens a run in the Agents panel, and closes it when the session changes', async () => {
    const panels = usePanelsStore()
    panels.sessionId = 's1'
    await flush()
    panels.openAgent('a1')
    expect(panels.open).toBe(true)
    expect(panels.active).toBe('agents')
    expect(panels.agentId).toBe('a1')
    panels.sessionId = 's2'
    await flush()
    expect(panels.agentId).toBeNull()
  })
})
