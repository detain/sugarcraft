import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { Wire } from '../../../stores/agents/__tests__/harness'
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
})
