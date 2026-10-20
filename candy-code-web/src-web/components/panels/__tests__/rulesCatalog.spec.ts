import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { Wire } from '../../../stores/agents/__tests__/harness'
import { usePanelsStore } from '../../../stores/agents/panels'
import AgentCatalogPanel from '../../agents/AgentCatalogPanel.vue'
import SidePanels from '../SidePanels.vue'
import RulesPanel from '../RulesPanel.vue'

/**
 * WEB-1c: the audit views over previously-dormant read methods —
 * `permission.rules` as a Rules tab, `agents.list` as a Catalog tab.
 */

let wire: Wire

beforeEach(() => {
  setActivePinia(createPinia())
  wire = new Wire()
  try {
    globalThis.localStorage?.clear()
  } catch {
    // no storage
  }
})

describe('RulesPanel (WEB-1c)', () => {
  it('asks for the session policy and lists mode, rules and grants', async () => {
    await wire.connect()
    const wrapper = mount(RulesPanel, { props: { sessionId: 's1' } })
    await flushPromises()
    expect(wire.sent('permission.rules').at(-1)?.params).toEqual({ sessionId: 's1' })
    await wire.answer('permission.rules', {
      mode: 'accept-edits',
      modeSource: 'session.setMode',
      rules: [
        { pattern: 'Bash(rm:*)', action: 'deny' },
        { pattern: 'Edit(src/**)', action: 'allow' },
      ],
      sessionGrants: ['Bash(git commit:*)'],
    })
    expect(wrapper.find('[data-testid="rules-mode"]').text()).toBe('mode: accept-edits')
    expect(wrapper.find('[data-testid="rules-source"]').text()).toBe('session.setMode')
    expect(wrapper.findAll('[data-testid="rule-row"]').map((row) => row.findAll('td').map((cell) => cell.text()))).toEqual([
      ['Bash(rm:*)', 'deny'],
      ['Edit(src/**)', 'allow'],
    ])
    expect(wrapper.findAll('[data-testid="grant-row"]').map((row) => row.text())).toEqual(['Bash(git commit:*)'])

    await wrapper.find('[data-testid="rules-reload"]').trigger('click')
    await flushPromises()
    expect(wire.sent('permission.rules')).toHaveLength(2)
  })

  it('reads the server-wide policy with no session on screen, and names a refusal', async () => {
    await wire.connect()
    const wrapper = mount(RulesPanel, { props: { sessionId: null } })
    await flushPromises()
    expect(wire.sent('permission.rules').at(-1)?.params).toEqual({})
    await wire.fail('permission.rules', -32001, 'forbidden', 'principal lacks read')
    expect(wrapper.find('[data-testid="rules-error"]').text()).toContain('principal lacks read')
  })

  it('a late answer for the previous session never repaints the panel (MINOR-1 generation latch)', async () => {
    await wire.connect()
    const wrapper = mount(RulesPanel, { props: { sessionId: 'sA' } })
    await flushPromises()
    // sA's request is still in flight when the session changes; sB loads fresh.
    await wrapper.setProps({ sessionId: 'sB' })
    await flushPromises()
    const frames = wire.sent('permission.rules')
    expect(frames).toHaveLength(2)
    expect(frames[0]?.params).toEqual({ sessionId: 'sA' })
    expect(frames[1]?.params).toEqual({ sessionId: 'sB' })

    // The newer answer lands first…
    wire.socket().answer({ mode: 'plan', modeSource: null, rules: [{ pattern: 'Edit(a)', action: 'ask' }], sessionGrants: [] }, frames[1]?.id as string)
    await flushPromises()
    expect(wrapper.find('[data-testid="rules-mode"]').text()).toBe('mode: plan')
    // …and the superseded sA answer that lands after it is dropped whole.
    wire.socket().answer({ mode: 'default', modeSource: 'config', rules: [{ pattern: 'Bash(rm:*)', action: 'deny' }], sessionGrants: ['A-grant'] }, frames[0]?.id as string)
    await flushPromises()
    expect(wrapper.find('[data-testid="rules-mode"]').text()).toBe('mode: plan')
    expect(wrapper.find('[data-testid="rules-source"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="grants-none"]').text()).toContain('Nothing has been granted')
    expect(wrapper.findAll('[data-testid="grant-row"]')).toHaveLength(0)
  })
})

describe('AgentCatalogPanel (WEB-1c)', () => {
  it('lists the delegation roster with model, provider and mode', async () => {
    await wire.connect()
    const wrapper = mount(AgentCatalogPanel)
    await flushPromises()
    expect(wire.sent('agents.list').at(-1)?.params).toEqual({})
    await wire.answer('agents.list', {
      items: [
        { name: 'worker', description: 'does the work', model: 'sonnet', provider: 'anthropic', permissionMode: 'accept-edits', active: true },
        { name: 'reviewer', description: 'reads the diff', model: null, provider: null, permissionMode: null, active: false },
      ],
    })
    expect(wrapper.find('[data-testid="catalog-count"]').text()).toBe('2 agents')
    expect(wrapper.find('[data-testid="catalog-worker"]').text()).toContain('does the work')
    expect(wrapper.find('[data-testid="catalog-worker"]').text()).toContain('anthropic · sonnet')
    expect(wrapper.find('[data-testid="catalog-worker"]').text()).toContain('mode: accept-edits')
    expect(wrapper.find('[data-testid="catalog-worker"] .on').exists()).toBe(true)
    expect(wrapper.find('[data-testid="catalog-reviewer"] .on').exists()).toBe(false)

    await wrapper.find('[data-testid="catalog-reload"]').trigger('click')
    await flushPromises()
    expect(wire.sent('agents.list')).toHaveLength(2)
  })
})

describe('drawer wiring (WEB-1c)', () => {
  it('grows Rules and Catalog tabs that load through the drawer', async () => {
    await wire.connect(() => usePanelsStore())
    const wrapper = mount(SidePanels, { props: { sessionId: 's1' } })
    await flushPromises()
    await wrapper.find('[data-testid="panels-toggle"]').trigger('click')
    await wrapper.find('[data-testid="panel-tab-rules"]').trigger('click')
    await flushPromises()
    expect(wire.sent('permission.rules').at(-1)?.params).toEqual({ sessionId: 's1' })
    await wrapper.find('[data-testid="panel-tab-catalog"]').trigger('click')
    await flushPromises()
    expect(wire.sent('agents.list').at(-1)?.params).toEqual({})
    expect(wrapper.find('[data-testid="side-panels"]').attributes('data-panel')).toBe('catalog')
  })
})
