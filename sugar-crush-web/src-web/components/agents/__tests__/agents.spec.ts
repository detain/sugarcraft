import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { usePanelsStore } from '../../../stores/agents/panels'
import { Wire } from '../../../stores/agents/__tests__/harness'
import { buildTree, normalizeRun, type AgentRun } from '../../../stores/agents/tree'
import ToolCard from '../../ToolCard.vue'
import AgentTranscript from '../AgentTranscript.vue'
import AgentView from '../AgentView.vue'
import SubAgentTree from '../SubAgentTree.vue'

function run(overrides: Record<string, unknown>): AgentRun {
  const r = normalizeRun({ id: 'a1', op: 'started', name: 'reviewer', task: 'review the parser', seq: 1, tail: '', parentCallId: 'c1', ...overrides })
  if (r === null) throw new Error('no run')
  return r
}

let pinia: ReturnType<typeof createPinia>

beforeEach(() => {
  pinia = createPinia()
  setActivePinia(pinia)
})

describe('SubAgentTree', () => {
  it('draws each run with its live activity, nested under the run that delegated it', async () => {
    const nodes = buildTree([
      run({ stats: { startedAt: 1, step: 2, maxSteps: 8 }, tokens: 1500, calls: [{ id: 'k', label: 'Read src/Parser.php', state: 'running' }] }),
      run({ id: 'a2', name: 'tester', parentAgentId: 'a1', parentCallId: 'inner', op: 'finished', outcome: 'complete', task: 'write tests', stats: { startedAt: 2 } }),
    ])
    const wrapper = mount(SubAgentTree, { props: { nodes } })
    const rows = wrapper.findAll('[data-testid="agent-node"]')
    expect(rows.map((r) => r.attributes('data-agent-id'))).toEqual(['a1', 'a2'])
    expect(rows[0]?.attributes('data-status')).toBe('running')
    expect(rows[0]?.text()).toContain('step 2/8')
    expect(rows[0]?.text()).toContain('1.5k tok')
    expect(rows[0]?.find('[data-testid="agent-activity"]').text()).toBe('Read src/Parser.php')
    expect(wrapper.find('.nested [data-agent-id="a2"]').exists()).toBe(true)
    await rows[1]?.trigger('click')
    expect(wrapper.emitted('open')?.[0]).toEqual(['a2'])
  })
})

describe('AgentTranscript', () => {
  it('pairs a tool call with its result and names who sent a message', () => {
    const wrapper = mount(AgentTranscript, {
      props: {
        items: [
          { t: 'user', text: 'review the parser' },
          { t: 'tool_call', callId: 'k1', tool: 'Read', args: { file_path: 'a.php' } },
          { t: 'tool_result', callId: 'k1', tool: 'Read', ok: true, content: '<?php' },
          { t: 'inbox', from: 'user', text: 'also the tests', msgId: 'm1' },
          { t: 'assistant', text: '**done**' },
          { t: 'status', status: 'finished', outcome: 'complete' },
        ],
      },
    })
    expect(wrapper.findAll('[data-testid="agent-row"]').map((r) => r.attributes('data-kind'))).toEqual(['user', 'tool', 'inbox', 'assistant', 'status'])
    expect(wrapper.find('.tool').text()).toContain('Read')
    expect(wrapper.find('.tool pre').text()).toBe('<?php')
    expect(wrapper.find('.inbox').text()).toContain('you →')
    expect(wrapper.find('.assistant strong').text()).toBe('done')
    expect(wrapper.find('.status-line').text()).toBe('Sub-agent complete')
  })
})

describe('AgentView', () => {
  it('reads the run\'s transcript, messages it and pauses it', async () => {
    const wire = new Wire()
    await wire.connect()
    const wrapper = mount(AgentView, { props: { sessionId: 's1', run: run({}) }, global: { plugins: [pinia] } })
    await flushPromises()
    await wire.answer('agents.transcript', { agentId: 'a1', offset: 20, items: [{ t: 'user', text: 'review the parser' }], more: false, finished: false })
    await flushPromises()
    expect(wrapper.find('[data-testid="agent-transcript"]').text()).toContain('review the parser')

    await wrapper.find('[data-testid="agent-input"]').setValue('also the tests')
    await wrapper.find('[data-testid="agent-send"]').trigger('click')
    await flushPromises()
    expect(wire.sent('agents.message').pop()?.params).toMatchObject({ sessionId: 's1', agentId: 'a1', text: 'also the tests' })
    await wire.answer('agents.message', { agentId: 'a1', status: 'queued', msgId: 'm1' })
    await flushPromises()
    expect(wrapper.find('[data-testid="agent-pending"]').text()).toContain('waiting for its next step')
    expect((wrapper.find('[data-testid="agent-input"]').element as HTMLTextAreaElement).value).toBe('')

    await wrapper.find('[data-testid="agent-pause"]').trigger('click')
    await flushPromises()
    expect(wire.sent('agents.control').pop()?.params).toMatchObject({ verb: 'pause' })
    wrapper.unmount()
  })

  it('sends a run it delegated to the background, and offers no such thing for a nested run', async () => {
    const wire = new Wire()
    await wire.connect()
    const wrapper = mount(AgentView, { props: { sessionId: 's1', run: run({}) }, global: { plugins: [pinia] } })
    await flushPromises()
    await wrapper.find('[data-testid="agent-background"]').trigger('click')
    await flushPromises()
    expect(wire.sent('agents.control').pop()?.params).toMatchObject({ sessionId: 's1', agentId: 'a1', verb: 'background' })
    wrapper.unmount()

    const nested = mount(AgentView, { props: { sessionId: 's1', run: run({ id: 'a2', parentAgentId: 'a1' }) }, global: { plugins: [pinia] } })
    await flushPromises()
    expect(nested.find('[data-testid="agent-pause"]').exists()).toBe(true)
    expect(nested.find('[data-testid="agent-background"]').exists()).toBe(false)
    nested.unmount()
  })

  it('offers Continue for a finished run that kept a resume id, and nothing else', async () => {
    const wire = new Wire()
    await wire.connect()
    const wrapper = mount(AgentView, { props: { sessionId: 's1', run: run({ op: 'finished', outcome: 'complete', resumeId: 'res' }) }, global: { plugins: [pinia] } })
    await flushPromises()
    expect(wrapper.find('[data-testid="agent-pause"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="agent-continue"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="agent-input"]').attributes('placeholder')).toContain('Continue reviewer')
    await wrapper.find('[data-testid="agent-back"]').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
    wrapper.unmount()
  })
})

describe('ToolCard with delegated runs', () => {
  it('hangs the runs it started under the card, and opens one in the panels', async () => {
    const item = { kind: 'tool' as const, key: 'k', toolCallId: 'c1', name: 'Task', arguments: { prompt: 'review' }, status: 'running' as const }
    const subagents = [{ id: 'a1', op: 'started', name: 'reviewer', task: 'review', parentCallId: 'c1' }, { id: 'zz', op: 'started', name: 'other', parentCallId: 'c9' }]
    const wrapper = mount(ToolCard, { props: { item, subagents }, global: { plugins: [pinia] } })
    const rows = wrapper.findAll('[data-testid="agent-node"]')
    expect(rows.map((r) => r.attributes('data-agent-id'))).toEqual(['a1'])
    await rows[0]?.trigger('click')
    expect(usePanelsStore().agentId).toBe('a1')
    expect(usePanelsStore().active).toBe('agents')
  })
})
