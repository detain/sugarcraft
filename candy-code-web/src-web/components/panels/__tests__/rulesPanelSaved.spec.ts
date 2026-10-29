import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { Wire } from '../../../stores/agents/__tests__/harness'
import RulesPanel from '../RulesPanel.vue'

/**
 * C-SEC-6.c: the Saved section of the Rules panel — rows the server tiers
 * `user` are listed apart with their saved number, a Forget button takes one
 * back out through `permission.forget` and re-reads the truth, and the note
 * says plainly that removal lands at the next launch.
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

const savedTwo = [
  { pattern: 'Bash(rm:*)', action: 'deny', tier: 'config', savedIndex: null },
  { pattern: 'Bash(git status)', action: 'allow', tier: 'user', savedIndex: 2 },
  { pattern: 'Bash(git status *)', action: 'allow', tier: 'user', savedIndex: 3 },
]

describe('RulesPanel saved rules (C-SEC-6.c)', () => {
  it('splits user-saved rows out of the configured table and says when they take effect', async () => {
    await wire.connect()
    const wrapper = mount(RulesPanel, { props: { sessionId: 's1' } })
    await flushPromises()
    await wire.answer('permission.rules', rulesResult(savedTwo))

    expect(wrapper.findAll('[data-testid="rule-row"]')).toHaveLength(1)
    const savedRows = wrapper.findAll('[data-testid="saved-row"]')
    expect(savedRows).toHaveLength(2)
    expect(savedRows[0]!.text()).toContain('2.')
    expect(savedRows[0]!.text()).toContain('Bash(git status)')
    expect(savedRows[1]!.text()).toContain('Bash(git status *)')
    expect(wrapper.find('[data-testid="rules-saved-note"]').text()).toContain('next launch')
    expect(wrapper.find('[data-testid="rules-saved-none"]').exists()).toBe(false)
  })

  it('forgets one saved rule by pattern, re-reads, and the saved list empties', async () => {
    await wire.connect()
    const wrapper = mount(RulesPanel, { props: { sessionId: 's1' } })
    await flushPromises()
    await wire.answer('permission.rules', rulesResult(savedTwo))

    await wrapper.findAll('[data-testid="saved-forget"]')[0]!.trigger('click')
    // `permission.forget` is a side-effecting method: the connection stamps an
    // idempotency key on the frame, so match the target and not the envelope.
    expect(wire.sent('permission.forget').at(-1)?.params).toMatchObject({ pattern: 'Bash(git status)' })
    await wire.answer('permission.forget', {
      removed: [{ pattern: 'Bash(git status)', action: 'allow' }],
      path: '/home/x/.candy-code/config.json',
    })
    await flushPromises()
    expect(wire.sent('permission.rules')).toHaveLength(2)
    await wire.answer('permission.rules', rulesResult([savedTwo[0]!, savedTwo[2]!]))
    await flushPromises()
    expect(wrapper.findAll('[data-testid="saved-row"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-testid="saved-forget"]')[0]!.text()).toBe('Forget')
  })

  it('names a failed forget and still shows the list untouched', async () => {
    await wire.connect()
    const wrapper = mount(RulesPanel, { props: { sessionId: 's1' } })
    await flushPromises()
    await wire.answer('permission.rules', rulesResult(savedTwo))

    await wrapper.findAll('[data-testid="saved-forget"]')[0]!.trigger('click')
    await wire.fail('permission.forget', -32099, 'forget_failed', 'the saved file could not be written')
    expect(wrapper.find('[data-testid="rules-forget-error"]').text()).toContain('could not be written')
    expect(wrapper.findAll('[data-testid="saved-row"]')).toHaveLength(2)
  })

  it('says nothing is saved when the server reports no user-tier rows', async () => {
    await wire.connect()
    const wrapper = mount(RulesPanel, { props: { sessionId: 's1' } })
    await flushPromises()
    await wire.answer('permission.rules', rulesResult([savedTwo[0]!]))

    expect(wrapper.find('[data-testid="rules-saved-none"]').text()).toContain('Nothing has been saved')
    expect(wrapper.find('[data-testid="rules-saved-note"]').exists()).toBe(false)
  })
})
