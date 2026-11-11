import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { Wire } from '../../../stores/agents/__tests__/harness'
import { useWorkflowsStore } from '../../../stores/agents/workflows'
import WorkflowPanel from '../WorkflowPanel.vue'

/**
 * C-WEB-3.c: while a workflow run is open, the panel polls its live status
 * (`workflow.status`) every 2 s; a terminal status reloads the session's runs
 * so the finished row carries its report; close/unmount stops the poll. A
 * running `tool` row without a `workflowId` stays unpolled — by design.
 */

const RUNNING = { source: 'command', name: 'review', status: 'running', running: true, workflowId: 'wf1' }
const SETTLED = { source: 'command', name: 'review', status: 'completed', running: false, workflowId: 'wf1', report: "Workflow 'review' completed" }
const TOOL_LIVE = { source: 'tool', name: 'survey', status: 'running', running: true, toolCallId: 't1' }

let pinia: ReturnType<typeof createPinia>
let wire: Wire

beforeEach(() => {
  pinia = createPinia()
  setActivePinia(pinia)
  wire = new Wire()
  // Fake only the interval calls the panel arms; the Wire harness flushes its
  // request/response flow through a real setTimeout(0).
  vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
})

afterEach(() => {
  vi.useRealTimers()
})

/** Mount the panel on $items' run rows (the mount's workflow.runs answered). */
async function openPanel(items: Record<string, unknown>[]) {
  await wire.connect(() => {}, {})
  const wrapper = mount(WorkflowPanel, { props: { sessionId: 's1' }, global: { plugins: [pinia] } })
  await flushPromises()
  await wire.answer('workflow.runs', { items })
  await flushPromises()
  return wrapper
}

describe('WorkflowPanel live status poll', () => {
  it('polls an open running row every 2 s, only while it runs', async () => {
    await openPanel([SETTLED])
    // A settled row arms nothing: the panel was open with no run at all.
    await vi.advanceTimersByTimeAsync(6000)
    expect(wire.sent('workflow.status')).toHaveLength(0)

    // The turn-end reload brings the open running row; now the poll arms.
    wire.event('s1', 'turn.completed', {})
    await flushPromises()
    await wire.answer('workflow.runs', { items: [RUNNING] })
    await flushPromises()

    await vi.advanceTimersByTimeAsync(1999)
    expect(wire.sent('workflow.status')).toHaveLength(0)
    await vi.advanceTimersByTimeAsync(1)
    expect(wire.sent('workflow.status')).toHaveLength(1)
    expect(wire.sent('workflow.status')[0]?.params).toMatchObject({ workflowId: 'wf1' })

    // Still running: the poll keeps its cadence.
    await wire.answer('workflow.status', { workflowId: 'wf1', status: 'running' })
    await vi.advanceTimersByTimeAsync(2000)
    expect(wire.sent('workflow.status')).toHaveLength(2)
    await wire.answer('workflow.status', { workflowId: 'wf1', status: 'running' })
  })

  it('stops on a terminal status and reloads the runs', async () => {
    await openPanel([RUNNING])
    await vi.advanceTimersByTimeAsync(2000)
    expect(wire.sent('workflow.status')).toHaveLength(1)

    const runsBefore = wire.sent('workflow.runs').length
    await wire.answer('workflow.status', { workflowId: 'wf1', status: 'completed' })
    await flushPromises()
    expect(wire.sent('workflow.runs')).toHaveLength(runsBefore + 1)
    await wire.answer('workflow.runs', { items: [SETTLED] })

    // Settled: no further status reads, however long the panel stays open.
    await vi.advanceTimersByTimeAsync(6000)
    expect(wire.sent('workflow.status')).toHaveLength(1)
  })

  it('stops when the panel unmounts (close)', async () => {
    const wrapper = await openPanel([RUNNING])
    await vi.advanceTimersByTimeAsync(2000)
    await wire.answer('workflow.status', { workflowId: 'wf1', status: 'running' })

    wrapper.unmount()
    await flushPromises()
    const seen = wire.sent('workflow.status').length
    await vi.advanceTimersByTimeAsync(6000)
    expect(wire.sent('workflow.status')).toHaveLength(seen)
  })

  it('leaves a running tool row without a workflowId unpolled', async () => {
    await openPanel([TOOL_LIVE, SETTLED])
    await vi.advanceTimersByTimeAsync(6000)
    expect(wire.sent('workflow.status')).toHaveLength(0)
  })

  it('skips the newest idless row and polls the older running one', async () => {
    // Rows arrive oldest-first; reversed, the newest is the running `tool` row
    // with no workflowId — nothing to ask the engine about. The id filter in
    // `polled` must carry past it to the older `/workflow` run that answers:
    // without the filter the idless newest row wins and nothing arms.
    await openPanel([RUNNING, TOOL_LIVE])
    await vi.advanceTimersByTimeAsync(2000)
    const statusSends = wire.sent('workflow.status')
    expect(statusSends).toHaveLength(1)
    expect(statusSends[0]?.params).toMatchObject({ workflowId: 'wf1' })
  })

  it('stops polling on a session change whose rows keep the same id polled', async () => {
    // s2's rows are read into the store BEFORE the switch and carry the same
    // workflowId, so `polled` never changes value and its watch stays silent:
    // clearing the interval at the switch is the sessionId watch's stopPolling
    // alone. Without it the stale timer keeps polling into the next session.
    const wrapper = await openPanel([RUNNING])
    await vi.advanceTimersByTimeAsync(2000)
    expect(wire.sent('workflow.status')).toHaveLength(1)
    await wire.answer('workflow.status', { workflowId: 'wf1', status: 'running' })

    void useWorkflowsStore().loadRuns('s2')
    await flushPromises()
    await wire.answer('workflow.runs', { items: [RUNNING] })
    await flushPromises()

    await wrapper.setProps({ sessionId: 's2' })
    await flushPromises()

    await vi.advanceTimersByTimeAsync(6000)
    expect(wire.sent('workflow.status')).toHaveLength(1)
  })
})

describe('WorkflowPanel event watch lifecycle', () => {
  it('stops refreshing a session once its panel closes', async () => {
    // F-n46 (CW-QUAL-A F-NIT-6): the store's event watch must not outlive the
    // panel that opened it — one live `workflow.runs` subscription per session
    // EVER opened was the zombie leak. A closed panel leaves nothing to
    // refresh: neither the turn-end reload nor a `Workflow` tool call may
    // reach the unwatched session again.
    const wrapper = await openPanel([SETTLED])
    wrapper.unmount()
    await flushPromises()
    const closed = wire.sent('workflow.runs').length

    wire.event('s1', 'turn.completed', {})
    wire.event('s1', 'tool.finished', { name: 'Workflow' })
    await flushPromises()
    expect(wire.sent('workflow.runs')).toHaveLength(closed)
  })

  it('watches a reopened panel again — exactly one reload per event', async () => {
    // Re-open re-watches (the store keeps one Set, so re-watching is idempotent),
    // and one watch means one reload per event — a leaked double-watch would
    // send twice per turn completion.
    const first = await openPanel([SETTLED])
    first.unmount()
    await flushPromises()

    const reopened = wire.sent('workflow.runs').length
    mount(WorkflowPanel, { props: { sessionId: 's1' }, global: { plugins: [pinia] } })
    await flushPromises()
    await wire.answer('workflow.runs', { items: [SETTLED] })
    expect(wire.sent('workflow.runs')).toHaveLength(reopened + 1)

    wire.event('s1', 'turn.completed', {})
    await flushPromises()
    await wire.answer('workflow.runs', { items: [SETTLED] })
    expect(wire.sent('workflow.runs')).toHaveLength(reopened + 2)
  })

  it('drops the previous session from the watch on a switch', async () => {
    // Switching the panel's session must drop the session left behind: its
    // events reload rows no open panel shows, and a failure poisons the shared
    // store's `error` ref that the visible session's header reads.
    const wrapper = await openPanel([SETTLED])
    await wrapper.setProps({ sessionId: 's2' })
    await flushPromises()
    await wire.answer('workflow.runs', { items: [SETTLED] })

    const switched = wire.sent('workflow.runs').length
    wire.event('s1', 'turn.completed', {})
    await flushPromises()
    expect(wire.sent('workflow.runs')).toHaveLength(switched)

    wire.event('s2', 'turn.completed', {})
    await flushPromises()
    await wire.answer('workflow.runs', { items: [SETTLED] })
    expect(wire.sent('workflow.runs')).toHaveLength(switched + 1)
  })
})
