import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { Wire } from '../../../stores/agents/__tests__/harness'
import UsagePanel from '../UsagePanel.vue'

/**
 * C-SES-8.a: the Usage tab — one `usage.rollup` call per load, the `≥`
 * lower-bound law on every dollar figure when the fold carried an unpriced
 * call, the retention line stated (and a Settings link ONLY when the schema
 * already loaded says the key is remotely writable), and the server's
 * `reason` rendered as plain text — never markup.
 */

let wire: Wire

const ROLLUP = {
  days: [
    { day: '2026-10-29', model: 'claude-sonnet-4-5', calls: 3, totalTokens: 1200, costUsd: 0.42, unpriced: false },
    { day: '2026-10-29', model: 'brand-new-model', calls: 1, totalTokens: 400, costUsd: 0, unpriced: true },
  ],
  models: [
    { model: 'claude-sonnet-4-5', calls: 3, totalTokens: 1200, costUsd: 0.42, unpriced: false },
    { model: 'brand-new-model', calls: 1, totalTokens: 400, costUsd: 0, unpriced: true },
  ],
  totals: { calls: 4, totalTokens: 1600, costUsd: 0.42 },
  unpriced: true,
  retentionDays: 45,
  reason: '',
}

beforeEach(() => {
  setActivePinia(createPinia())
  wire = new Wire()
  try {
    globalThis.localStorage?.clear()
  } catch {
    // no storage
  }
})

async function openPanel(): Promise<ReturnType<typeof mount>> {
  const wrapper = mount(UsagePanel, { props: { sessionId: 's1' } })
  await flushPromises()
  await wire.answer('usage.rollup', ROLLUP)
  await flushPromises()
  return wrapper
}

describe('UsagePanel (C-SES-8.a)', () => {
  it('asks for the session rollup on mount with scope and sessionId', async () => {
    await wire.connect()
    await openPanel()
    expect(wire.sent('usage.rollup').at(-1)?.params).toEqual({ sessionId: 's1', scope: 'session' })
  })

  it('renders the `≥` totals and both tables from the one answer', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    expect(wrapper.find('[data-testid="usage-totals"]').text()).toContain('≥$0.42')
    expect(wrapper.find('[data-testid="usage-totals"]').text()).toContain('1.6k tokens')
    const dayRows = wrapper.findAll('[data-testid="usage-day-grid"] tbody tr')
    expect(dayRows).toHaveLength(2)
    expect(dayRows[0]!.text()).toContain('$0.42')
    expect(dayRows[0]!.text()).not.toContain('≥')
    expect(dayRows[1]!.text()).toContain('≥$0.00')
    const modelRows = wrapper.findAll('[data-testid="usage-model-table"] tbody tr')
    expect(modelRows).toHaveLength(2)
    expect(modelRows[1]!.text()).toContain('brand-new-model')
    expect(modelRows[1]!.text()).toContain('≥')
  })

  it('states the retention line read-only and offers no Settings link while the schema is not on the client', async () => {
    await wire.connect()
    const wrapper = await openPanel()
    expect(wrapper.find('[data-testid="usage-retention"]').text()).toContain('Sessions kept 45 days')
    expect(wrapper.find('[data-testid="usage-settings-link"]').exists()).toBe(false)
  })

  it('links to Settings once the schema says sessionRetentionDays is remotely writable', async () => {
    await wire.connect()
    const wrapper = mount(UsagePanel, { props: { sessionId: 's1' } })
    await flushPromises()
    await wire.answer('settings.schema', {
      items: [{
        key: 'sessionRetentionDays',
        type: 'int',
        group: 'sessions',
        label: 'Session retention',
        riskClass: 'normal',
        applies: 'restart',
        sensitive: false,
        writableRemotely: true,
      }],
      tiers: [{ scope: 'user', label: 'User', writable: true }],
    })
    await wire.answer('settings.get', { scope: 'effective', values: {} })
    await wire.answer('usage.rollup', ROLLUP)
    await flushPromises()
    expect(wrapper.find('[data-testid="usage-settings-link"]').exists()).toBe(true)
  })

  it('keeps the link away when the schema says the key is not writable remotely', async () => {
    await wire.connect()
    const wrapper = mount(UsagePanel, { props: { sessionId: 's1' } })
    await flushPromises()
    await wire.answer('settings.schema', {
      items: [{
        key: 'sessionRetentionDays',
        type: 'int',
        group: 'sessions',
        label: 'Session retention',
        riskClass: 'normal',
        applies: 'restart',
        sensitive: false,
        writableRemotely: false,
        remoteRefusal: 'frozen for this build',
      }],
      tiers: [{ scope: 'user', label: 'User', writable: true }],
    })
    await wire.answer('settings.get', { scope: 'effective', values: {} })
    await wire.answer('usage.rollup', ROLLUP)
    await flushPromises()
    expect(wrapper.find('[data-testid="usage-settings-link"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="usage-retention"]').text()).toContain('45')
  })

  it('prints the server reason as plain text when the ledger cannot answer', async () => {
    await wire.connect()
    const wrapper = mount(UsagePanel, { props: { sessionId: 's1' } })
    await flushPromises()
    await wire.answer('usage.rollup', {
      days: [], models: [], totals: { calls: 0, totalTokens: 0, costUsd: 0 },
      unpriced: false, retentionDays: 30,
      reason: 'no durable ledger store is open here',
    })
    await flushPromises()
    expect(wrapper.find('[data-testid="usage-reason"]').text()).toBe('no durable ledger store is open here')
    expect(wrapper.find('[data-testid="usage-empty"]').exists()).toBe(false)
  })

  it('re-asks with the switched scope and window, and ignores a superseded answer', async () => {
    await wire.connect()
    const wrapper = mount(UsagePanel, { props: { sessionId: 's1' } })
    await flushPromises()
    await wrapper.find('[data-testid="usage-scope-all"]').trigger('click')
    expect(wire.sent('usage.rollup').at(-1)?.params).toEqual({ sessionId: 's1', scope: 'all' })
    await wrapper.find('[data-testid="usage-window-7"]').trigger('click')
    expect(wire.sent('usage.rollup').at(-1)?.params).toEqual({ sessionId: 's1', scope: 'all', days: 7 })
    // The two superseded requests may still answer late; neither may paint.
    const frames = wire.sent('usage.rollup')
    wire.socket().answer({ ...ROLLUP, reason: 'STALE-ONE' }, String(frames[0]?.['id'] ?? ''))
    wire.socket().answer({ ...ROLLUP, reason: 'STALE-TWO' }, String(frames[1]?.['id'] ?? ''))
    await flushPromises()
    expect(wrapper.text()).not.toContain('STALE')
    await wire.answer('usage.rollup', { ...ROLLUP, reason: 'CURRENT' })
    await flushPromises()
    expect(wrapper.find('[data-testid="usage-reason"]').text()).toBe('CURRENT')
  })
})
