import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { Wire } from '../../../stores/agents/__tests__/harness'
import RulesPanel from '../RulesPanel.vue'

/**
 * C-SEC-6.d: the 'Recent decisions' section of the Rules panel — rows come
 * from `permission.audit` (the server's own bounded audit tail), the empty
 * case says so plainly, the unreadable case is not confused with the empty
 * one, and an older server that does not know the method must leave the
 * policy view above intact with a quiet, honest audit line.
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

function rulesResult(rows: Record<string, unknown>[]) {
  return {
    mode: 'default',
    modeSource: null,
    rules: rows,
    sessionGrants: [],
  }
}

function auditResult(rows: Record<string, unknown>[], unreadable = false) {
  return { rows, unreadable }
}

const policyRow = { pattern: 'Bash(npm test)', action: 'allow', tier: 'config', savedIndex: null }

const decisions = [
  { time: '2026-10-28 09:00:01', session: 's1', tool: 'Read', verdict: 'allow', kind: null, reason: null },
  { time: '2026-10-28 09:00:02', session: 's1', tool: 'Bash', verdict: 'deny', kind: 'refused', reason: 'writing outside the project' },
  { time: '2026-10-28 09:00:03', session: 's1', tool: 'Write', verdict: 'withheld', kind: 'RedactorHook', reason: 'the chain stopped at the first refusal' },
]

async function mountedWithPolicy() {
  await wire.connect()
  const wrapper = mount(RulesPanel, { props: { sessionId: 's1' } })
  await flushPromises()
  await wire.answer('permission.rules', rulesResult([policyRow]))
  await flushPromises()

  return wrapper
}

describe('RulesPanel recent decisions (C-SEC-6.d)', () => {
  it('renders the audited decisions with verdict, kind and reason, narrowed to the open session', async () => {
    const wrapper = await mountedWithPolicy()

    expect(wire.sent('permission.audit').at(-1)?.params).toMatchObject({ sessionId: 's1' })
    await wire.answer('permission.audit', auditResult(decisions))
    await flushPromises()

    const rows = wrapper.findAll('[data-testid="audit-row"]')
    expect(rows).toHaveLength(3)
    expect(rows[0]!.text()).toContain('Read')
    expect(rows[0]!.text()).toContain('allow')
    expect(rows[1]!.text()).toContain('deny')
    expect(rows[1]!.text()).toContain('refused')
    expect(rows[1]!.text()).toContain('writing outside the project')
    expect(rows[2]!.text()).toContain('withheld')
    expect(rows[2]!.text()).toContain('RedactorHook')
    expect(wrapper.find('[data-testid="rules-audit"]').exists()).toBe(true)
    // the policy table above the trail is unaffected
    expect(wrapper.findAll('[data-testid="rule-row"]')).toHaveLength(1)
  })

  it('says nothing has been audited yet when the trail is empty — and always states where the trail lives', async () => {
    const wrapper = await mountedWithPolicy()
    await wire.answer('permission.audit', auditResult([]))
    await flushPromises()

    expect(wrapper.find('[data-testid="rules-audit-none"]').text()).toContain('No tool calls have been audited yet')
    expect(wrapper.findAll('[data-testid="audit-row"]')).toHaveLength(0)
    expect(wrapper.find('[data-testid="rules-audit-note"]').text()).toContain('HOME')
    expect(wrapper.find('[data-testid="rules-audit-note"]').text()).toContain('TMPDIR')
  })

  it('calls an unreadable trail unreadable, not empty', async () => {
    const wrapper = await mountedWithPolicy()
    await wire.answer('permission.audit', auditResult([], true))
    await flushPromises()

    expect(wrapper.find('[data-testid="rules-audit-unreadable"]').text()).toContain('could not read its audit trail')
    expect(wrapper.find('[data-testid="rules-audit-none"]').exists()).toBe(false)
  })

  it('an older server without the method leaves the policy view whole and says so quietly', async () => {
    const wrapper = await mountedWithPolicy()
    await wire.fail('permission.audit', -32601, 'method_not_found', 'unknown method permission.audit')
    await flushPromises()

    expect(wrapper.find('[data-testid="rules-audit-none"]').text()).toContain('does not report audited decisions')
    // no error banner, and the rules table stands exactly as it was
    expect(wrapper.find('[data-testid="rules-error"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid="rule-row"]')).toHaveLength(1)
  })

  it('a reload re-reads both the policy and the trail', async () => {
    const wrapper = await mountedWithPolicy()
    await wire.answer('permission.audit', auditResult([decisions[0]!]))
    await flushPromises()
    expect(wrapper.findAll('[data-testid="audit-row"]')).toHaveLength(1)

    await wrapper.find('[data-testid="rules-reload"]').trigger('click')
    expect(wire.sent('permission.audit')).toHaveLength(2)
    await wire.answer('permission.rules', rulesResult([policyRow]))
    await wire.answer('permission.audit', auditResult(decisions.slice(0, 2)))
    await flushPromises()

    expect(wrapper.findAll('[data-testid="audit-row"]')).toHaveLength(2)
  })
})
