import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useAgentsStore } from '../../../stores/agents/agents'
import { usePanelsStore } from '../../../stores/agents/panels'
import { Wire } from '../../../stores/agents/__tests__/harness'
import { buildTree, foldRuns } from '../../../stores/agents/tree'
import AgentView from '../AgentView.vue'
import RunningAgentsLine from '../RunningAgentsLine.vue'
import SubAgentTree from '../SubAgentTree.vue'

/**
 * P-AGENT.d — web parity with the TUI's agent surface: the catalogue's
 * colours by name on the run rows, the `mode: interrupt` send with its
 * `⚡`/`⧗` row states, and the working line counting the runs still going.
 */

let pinia: ReturnType<typeof createPinia>
let wire: Wire

beforeEach(() => {
  pinia = createPinia()
  setActivePinia(pinia)
  wire = new Wire()
})

describe('SubAgentTree colour dots (P-AGENT.d)', () => {
  it('paints the server\'s contrast-checked hex, and falls back to the status colour', async () => {
    await wire.connect()
    const agents = useAgentsStore()
    const refreshing = agents.refresh('s1')
    await flushPromises()
    await wire.answer('agents.list', { items: [{ name: 'tester', color: '#22cc88' }] })
    await wire.answer('agents.subtree', {
      items: [
        // The run's own seed carries the resolved preset hex (P-AGENT.c).
        { id: 'a1', name: 'reviewer', op: 'started', color: '#ff8800', seq: 1, stats: { startedAt: 1 } },
        // No seed colour: the catalogue's colour by name paints the dot.
        { id: 'a2', name: 'tester', op: 'started', seq: 1, stats: { startedAt: 2 } },
        // Neither: the row falls back to the status colour (the CSS class).
        { id: 'a3', name: 'oddball', op: 'started', seq: 1, stats: { startedAt: 3 } },
      ],
    })
    await refreshing
    const nodes = buildTree(Object.values(foldRuns(agents.seed('s1'))))
    const wrapper = mount(SubAgentTree, { props: { nodes }, global: { plugins: [pinia] } })
    expect(wrapper.find('[data-testid="agent-dot"][data-agent-id="a1"]').attributes('style')).toContain('#ff8800')
    expect(wrapper.find('[data-testid="agent-dot"][data-agent-id="a2"]').attributes('style')).toContain('#22cc88')
    const fallback = wrapper.find('[data-testid="agent-dot"][data-agent-id="a3"]')
    expect(fallback.attributes('style')).toBeUndefined()
    expect(fallback.classes()).toContain('running')
    expect(wrapper.findAll('[data-testid="agent-dot"]')).toHaveLength(3)
  })
})

describe('AgentView interrupt send (P-AGENT.d)', () => {
  function run() {
    const r = foldRuns([{ id: 'a1', name: 'reviewer', op: 'started', task: 'review the parser', seq: 1 }])['a1']
    if (r === undefined) throw new Error('no run')
    return r
  }

  it('the toggle arms a one-shot interrupt send; Ctrl+Enter is a one-shot; plain sends ride no mode', async () => {
    await wire.connect()
    const wrapper = mount(AgentView, { props: { sessionId: 's1', run: run() }, global: { plugins: [pinia] } })
    await flushPromises()
    await wire.answer('agents.transcript', { agentId: 'a1', offset: 1, items: [], more: false, finished: false })

    // Toggle-armed send rides mode:interrupt and badges the row with ⚡.
    await wrapper.find('[data-testid="agent-interrupt"]').trigger('click')
    expect(wrapper.find('[data-testid="agent-interrupt"]').attributes('aria-pressed')).toBe('true')
    await wrapper.find('[data-testid="agent-input"]').setValue('stop that')
    await wrapper.find('[data-testid="agent-send"]').trigger('click')
    await flushPromises()
    expect(wire.sent('agents.message').pop()?.params).toMatchObject({ sessionId: 's1', agentId: 'a1', text: 'stop that', mode: 'interrupt' })
    await wire.answer('agents.message', { agentId: 'a1', status: 'queued', msgId: 'm1' })
    await flushPromises()
    // The armed toggle is one-shot: the send that rode it releases it (round-2 MAJOR).
    expect(wrapper.find('[data-testid="agent-interrupt"]').attributes('aria-pressed')).toBe('false')
    expect(wrapper.find('[data-testid="agent-pending-badge"]').text()).toBe('⚡ interrupt')

    // So the plain send right after rides NO mode (a sticky toggle would ride interrupt).
    await wrapper.find('[data-testid="agent-input"]').setValue('carry on')
    await wrapper.find('[data-testid="agent-send"]').trigger('click')
    await flushPromises()
    const plain = wire.sent('agents.message').pop()?.params as Record<string, unknown>
    expect(plain.text).toBe('carry on')
    expect(plain.mode).toBeUndefined()
    await wire.answer('agents.message', { agentId: 'a1', status: 'queued', msgId: 'm2' })
    expect(wrapper.findAll('[data-testid="agent-pending-badge"]').map((b) => b.text()).join(' ')).toContain('⧗ waiting')

    // Ctrl+Enter stays a one-shot: the interrupt rides, the toggle was never armed by it.
    await wrapper.find('[data-testid="agent-input"]').setValue('actually stop')
    await wrapper.find('[data-testid="agent-input"]').trigger('keydown', { key: 'Enter', ctrlKey: true })
    await flushPromises()
    expect(wire.sent('agents.message').pop()?.params).toMatchObject({ text: 'actually stop', mode: 'interrupt' })
    await wire.answer('agents.message', { agentId: 'a1', status: 'queued', msgId: 'm3' })
    expect(wrapper.find('[data-testid="agent-interrupt"]').attributes('aria-pressed')).toBe('false')
    wrapper.unmount()
  })
})

describe('RunningAgentsLine (P-AGENT.d)', () => {
  it('counts the runs still going, lists their activity, and opens one', async () => {
    await wire.connect()
    const agents = useAgentsStore()
    const panels = usePanelsStore()
    const refreshing = agents.refresh('s1')
    await flushPromises()
    await wire.answer('agents.list', { items: [] })
    await wire.answer('agents.subtree', {
      items: [
        { id: 'a1', name: 'reviewer', op: 'progress', seq: 2, stats: { startedAt: 1 }, calls: [{ id: 'k', label: 'Read src/Parser.php', state: 'running' }] },
        { id: 'a2', name: 'tester', op: 'started', seq: 1, stats: { startedAt: 2 }, task: 'write the tests' },
        { id: 'a3', name: 'old', op: 'finished', outcome: 'complete', seq: 3, stats: { startedAt: 3 } },
      ],
    })
    await refreshing
    const wrapper = mount(RunningAgentsLine, { props: { sessionId: 's1' }, global: { plugins: [pinia] } })
    expect(wrapper.find('[data-testid="running-agents-count"]').text()).toBe('⠋ 2 agents running')
    const rows = wrapper.findAll('[data-testid="running-agents-row"]')
    expect(rows.map((r) => r.attributes('data-agent-id'))).toEqual(['a1', 'a2'])
    expect(rows[0]?.text()).toContain('Read src/Parser.php')
    await rows[1]?.trigger('click')
    expect(panels.agentId).toBe('a2')
    expect(panels.active).toBe('agents')

    // Nothing going (a session with no seeds): the line is not there.
    const idle = mount(RunningAgentsLine, { props: { sessionId: 'other' }, global: { plugins: [pinia] } })
    expect(idle.find('[data-testid="running-agents-line"]').exists()).toBe(false)
  })
})
