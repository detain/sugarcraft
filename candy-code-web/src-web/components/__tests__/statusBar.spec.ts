import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { beforeEach, describe, expect, it } from 'vitest'
import StatusBar from '../StatusBar.vue'
import { summary as sessionSummary, Wire } from '../grid/__tests__/harness'
import type { EventEnvelope, SessionSummary } from '../../protocol/generated'
import { applyEvent, createState } from '../../stores/reducer'
import { usePanelsStore } from '../../stores/agents/panels'

/**
 * WEB-2: the status bar drives the two built-ins that gained host bodies.
 * /compact is a plain runCommand emit; the model is a select whose options
 * come from `server.info` — and when that fetch fails the model stays the
 * plain span it always was, so the chrome never depends on the inventory.
 */

let wire: Wire

beforeEach(() => {
  setActivePinia(createPinia())
})

const infoResult = {
  version: '1.2.3',
  protocol: 1,
  root: '/repo',
  providers: ['anthropic', 'openai'],
  agents: [],
  tools: [],
  commands: ['help'],
  permissionModes: ['default'],
  defaultPermissionMode: 'default',
}

function mountBar(overrides: Record<string, unknown> = {}) {
  return mount(StatusBar, {
    props: {
      state: createState('s1'),
      summary: sessionSummary('s1', { provider: 'openai', model: 'gpt-5', ...overrides }) as unknown as SessionSummary,
      contextPct: null,
      connection: 'live',
    },
  })
}

async function mountConnected() {
  wire = await Wire.connect()
  const wrapper = mountBar()
  await flushPromises()
  wire.answer('server.info', infoResult)
  await flushPromises()
  return wrapper
}

describe('StatusBar (WEB-2)', { retry: 0 }, () => {
  it('turns the model into a select fed by server.info and commands the switch', async () => {
    const wrapper = await mountConnected()
    expect(wire.sent('server.info')).toHaveLength(1)

    const select = wrapper.find('[data-testid="model-select"]')
    expect(select.exists()).toBe(true)
    const options = select.findAll('option')
    expect(options.map((option) => option.text())).toEqual(['anthropic', 'openai · gpt-5'])
    expect((select.element as HTMLSelectElement).value).toBe('openai gpt-5')

    await select.setValue('anthropic')
    expect(wrapper.emitted('runCommand')).toEqual([['/model anthropic']])
  })

  it('re-picking the current entry sends nothing', async () => {
    const wrapper = await mountConnected()
    await wrapper.find('[data-testid="model-select"]').setValue('openai gpt-5')
    expect(wrapper.emitted('runCommand')).toBeUndefined()
  })

  /**
   * C-WEB-1.b: the select must never display a switch the session has not
   * applied. A pick emits and the element is pulled back to the summary's
   * entry; the repaint happens when (and only when) the summary moves.
   * Every drive below uses setValue, which sets target.value BEFORE the
   * change event — a bare synthetic Event would not see the reset.
   */
  async function pickPendingSwitch() {
    const wrapper = await mountConnected()
    const select = wrapper.find('[data-testid="model-select"]')
    await select.setValue('anthropic')
    return { wrapper, select }
  }

  const appliedAnthropic = () =>
    sessionSummary('s1', { provider: 'anthropic', model: 'opus-4.6' }) as unknown as SessionSummary

  it('after picking B the element still shows the summary entry A', async () => {
    const { wrapper, select } = await pickPendingSwitch()
    expect(wrapper.emitted('runCommand')).toEqual([['/model anthropic']])
    expect((select.element as HTMLSelectElement).value).toBe('openai gpt-5')
  })

  it('the summary moving to B repaints the select', async () => {
    const { wrapper, select } = await pickPendingSwitch()
    await wrapper.setProps({ summary: appliedAnthropic() })
    expect((select.element as HTMLSelectElement).value).toBe('anthropic opus-4.6')
  })

  it('picking A after the apply emits the switch back and re-parks on B', async () => {
    const { wrapper, select } = await pickPendingSwitch()
    await wrapper.setProps({ summary: appliedAnthropic() })
    await select.setValue('openai')
    const commands = wrapper.emitted('runCommand')
    if (!commands) throw new Error('no runCommand emitted')
    expect(commands.at(-1)).toEqual(['/model openai'])
    expect((select.element as HTMLSelectElement).value).toBe('anthropic opus-4.6')
  })

  it('the compact button runs /compact and parks itself while the session works', async () => {
    const wrapper = await mountConnected()
    const button = wrapper.find('[data-testid="compact-button"]')
    expect(button.exists()).toBe(true)
    expect(button.attributes('disabled')).toBeUndefined()
    await button.trigger('click')
    expect(wrapper.emitted('runCommand')).toEqual([['/compact']])

    const state = createState('s1')
    state.status = 'busy'
    await wrapper.setProps({ state })
    expect(wrapper.find('[data-testid="compact-button"]').attributes('disabled')).toBeDefined()
  })

  it('a failed inventory keeps the model a plain span', async () => {
    wire = await Wire.connect()
    const wrapper = mountBar()
    await flushPromises()
    const frame = wire.sent('server.info').at(-1)
    if (!frame) throw new Error('no server.info sent')
    wire.socket.fail(-32001, 'forbidden', 'no info for you', frame.id)
    await flushPromises()

    expect(wrapper.find('[data-testid="model-select"]').exists()).toBe(false)
    expect(wrapper.find('.item.model').text()).toBe('openai · gpt-5')
    await wrapper.find('[data-testid="compact-button"]').trigger('click')
    expect(wrapper.emitted('runCommand')).toEqual([['/compact']])
  })
})

/**
 * C-AGT-4.c: the status bar gains a primary-agent select beside the model
 * one. The roster comes from `agents.list` (the `mode` field C-AGT-4.c adds
 * server-side), the pick rides `command.exec` through the existing
 * runCommand emit — `/agent use <name>` — and, exactly like the model
 * select, a pick is only a REQUEST: the element stays on the summary's
 * entry until (and unless) session.updated moves `summary.agent`.
 */
describe('StatusBar primary-agent select (C-AGT-4.c)', { retry: 0 }, () => {
  const rosterResult = {
    items: [
      { name: 'worker', description: '', model: '', provider: '', permissionMode: 'default', active: true, mode: 'all' },
      { name: 'scout', description: '', model: '', provider: '', permissionMode: 'default', active: false, mode: 'subagent' },
    ],
  }

  async function mountWithRoster(items: Record<string, unknown>[] = rosterResult.items) {
    const wrapper = await mountConnected()
    wire.answer('agents.list', { items })
    await flushPromises()
    return wrapper
  }

  it('feeds the select from agents.list and commands the switch', async () => {
    const wrapper = await mountWithRoster()
    expect(wire.sent('agents.list')).toHaveLength(1)

    const select = wrapper.find('[data-testid="agent-select"]')
    expect(select.exists()).toBe(true)
    // `scout` declares mode: subagent — it never presents as a primary.
    expect(select.findAll('option').map((option) => option.text())).toEqual(['default agent', 'worker'])
    expect((select.element as HTMLSelectElement).value).toBe('')

    await select.setValue('worker')
    expect(wrapper.emitted('runCommand')).toEqual([['/agent use worker']])
  })

  it('a pending pick parks until the summary moves, then repainting follows', async () => {
    const wrapper = await mountWithRoster()
    const select = wrapper.find('[data-testid="agent-select"]')
    await select.setValue('worker')
    expect(wrapper.emitted('runCommand')).toEqual([['/agent use worker']])
    expect((select.element as HTMLSelectElement).value).toBe('')

    await wrapper.setProps({
      summary: sessionSummary('s1', { provider: 'openai', model: 'gpt-5', agent: 'worker' }) as unknown as SessionSummary,
    })
    expect((select.element as HTMLSelectElement).value).toBe('worker')
    // The default-agent placeholder only exists while nothing is chosen.
    expect(select.findAll('option').map((option) => option.text())).toEqual(['worker'])
  })

  it('re-picking the current agent sends nothing', async () => {
    const wrapper = await mountWithRoster()
    await wrapper.setProps({
      summary: sessionSummary('s1', { provider: 'openai', model: 'gpt-5', agent: 'worker' }) as unknown as SessionSummary,
    })
    await wrapper.find('[data-testid="agent-select"]').setValue('worker')
    expect(wrapper.emitted('runCommand')).toBeUndefined()
  })

  it('a current agent missing from the roster stays selectable', async () => {
    const wrapper = await mountConnected()
    await wrapper.setProps({
      summary: sessionSummary('s1', { provider: 'openai', model: 'gpt-5', agent: 'ghost' }) as unknown as SessionSummary,
    })
    wire.answer('agents.list', { items: rosterResult.items })
    await flushPromises()
    const select = wrapper.find('[data-testid="agent-select"]')
    expect(select.findAll('option').map((option) => option.text())).toEqual(['ghost', 'worker'])
    expect((select.element as HTMLSelectElement).value).toBe('ghost')
  })

  it('a row without the mode key defaults to all', async () => {
    const wrapper = await mountConnected()
    // An older server's rows carry no `mode`; leniency keeps them primaries.
    wire.answer('agents.list', { items: [{ name: 'old', description: '', model: '', provider: '', permissionMode: 'default', active: true }] })
    await flushPromises()
    const select = wrapper.find('[data-testid="agent-select"]')
    expect(select.findAll('option').map((option) => option.text())).toEqual(['default agent', 'old'])
  })

  it('an unanswered roster request keeps the select off the bar', async () => {
    const wrapper = await mountConnected()
    expect(wrapper.find('[data-testid="agent-select"]').exists()).toBe(false)
  })
})

describe('StatusBar spend bound (WEB-5 parity)', () => {
  function mountState(unpriced: boolean, spentUsd: number) {
    const state = createState('s1')
    state.spentUsd = spentUsd
    state.unpriced = unpriced
    return mount(StatusBar, {
      props: {
        state,
        summary: sessionSummary('s1') as unknown as SessionSummary,
        contextPct: null,
        connection: 'live',
      },
    })
  }

  it('renders an exact figure for a priced session', () => {
    const wrapper = mountState(false, 1.5)
    const spend = wrapper.find('[data-testid="spend"]')
    expect(spend.text()).toBe('$1.50')
    expect(spend.attributes('data-unpriced')).toBe('false')
    expect(spend.attributes('title')).toBe('Spent this session')
  })

  it('marks the lower bound when the snapshot says the model is unpriced', () => {
    const wrapper = mountState(true, 0)
    const spend = wrapper.find('[data-testid="spend"]')
    expect(spend.text()).toBe('≥$0.00')
    expect(spend.attributes('data-unpriced')).toBe('true')
    expect(spend.attributes('title')).toContain('lower bound')
  })

  // C-SES-2.c: the badge no longer waits for the next snapshot. A usage
  // frame in the middle of the FIRST turn of a never-snapshotted state —
  // the exact shape a fresh `serve` session streams — is enough.
  it('reads ≥ on a live turn the moment a usage frame names an unpriced model', async () => {
    const state = reactive(createState('s1'))
    state.turnId = 't1'
    const wrapper = mount(StatusBar, {
      props: {
        state,
        summary: sessionSummary('s1') as unknown as SessionSummary,
        contextPct: null,
        connection: 'live',
      },
    })
    expect(wrapper.find('[data-testid="spend"]').text()).toBe('$0.00')

    applyEvent(state, {
      sessionId: 's1', seq: 1, type: 'usage.updated', ts: 1, durable: true, turnId: 't1',
      data: { turnUsage: { totalTokens: 10, costUsd: 0, unpricedModel: 'mystery-9' } },
    } as EventEnvelope)
    await wrapper.vm.$nextTick()

    const spend = wrapper.find('[data-testid="spend"]')
    expect(spend.text()).toBe('≥$0.00', 'the live turn is a lower bound from its first call')
    expect(spend.attributes('data-unpriced')).toBe('true')

    applyEvent(state, {
      sessionId: 's1', seq: 2, type: 'usage.updated', ts: 2, durable: true, turnId: 't1',
      data: { turnUsage: { totalTokens: 20, costUsd: 0.25, unpricedModel: null } },
    } as EventEnvelope)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="spend"]').text()).toBe('≥$0.25', 'the bound grows, the badge stays')
  })
})

/**
 * P-CTX.g: the context meter is a button now — it opens the Context panel —
 * and the bar carries a cache read-out computed from the live turn usage.
 */
describe('StatusBar PR chip (C-AGT-6.e)', () => {
  const pr = { number: 123, state: 'OPEN', url: 'https://github.example/o/r/pull/123', checks: 'pass' }

  it('links the number and carries the CI dot once the cache has an answer', () => {
    const wrapper = mountBar({ pr })
    const chip = wrapper.find('[data-testid="pr-chip"]')
    expect(chip.exists()).toBe(true)
    expect(chip.attributes('href')).toBe(pr.url)
    expect(chip.attributes('target')).toBe('_blank')
    expect(chip.attributes('rel')).toBe('noopener noreferrer')
    expect(chip.text()).toContain('#123')
    expect(chip.find('[data-testid="pr-dot"]').classes()).toContain('is-pass')
    expect(chip.attributes('title')).toContain('CI: pass')
  })

  it('colors the dot for a failed or still-running rollup', () => {
    for (const [checks, expected] of [['fail', 'is-fail'], ['pending', 'is-pending']] as const) {
      const wrapper = mountBar({ pr: { ...pr, checks } })
      expect(wrapper.find('[data-testid="pr-dot"]').classes()).toContain(expected)
    }
  })

  it('stays out of the bar while the watch has no cached answer', () => {
    expect(mountBar().find('[data-testid="pr-chip"]').exists()).toBe(false)
    expect(mountBar({ pr: null }).find('[data-testid="pr-chip"]').exists()).toBe(false)
  })
})

describe('StatusBar context meter and cache (P-CTX.g)', () => {
  function mountWith(state: ReturnType<typeof createState>, contextPct: number | null) {
    return mount(StatusBar, {
      props: {
        state,
        summary: sessionSummary('s1') as unknown as SessionSummary,
        contextPct,
        connection: 'live',
      },
    })
  }

  it('opens the Context panel from the meter button', async () => {
    const panels = usePanelsStore()
    const wrapper = mountWith(createState('s1'), 41)
    const meter = wrapper.find('[data-testid="context-meter"]')
    expect(meter.element.tagName).toBe('BUTTON')
    expect(meter.text()).toContain('41%')
    await meter.trigger('click')
    expect(panels.active).toBe('context')
    expect(panels.open).toBe(true)
  })

  it('keeps the meter without a percentage when the window is unmeasured', () => {
    const wrapper = mountWith(createState('s1'), null)
    const meter = wrapper.find('[data-testid="context-meter"]')
    expect(meter.element.tagName).toBe('BUTTON')
    expect(meter.text()).toContain('tok')
  })

  describe('cache read-out', () => {
    function mountUsage(usage: Record<string, unknown> | null) {
      const state = createState('s1')
      if (usage) state.turnUsage = usage as never
      return mountWith(state, null)
    }

    it('shows nothing before any usage was measured', () => {
      const wrapper = mountUsage(null)
      expect(wrapper.find('[data-testid="status-cache"]').exists()).toBe(false)
    })

    // The totalTokens leg deliberately carries an output bucket on top of the
    // prompt identity (700 output on every fixture): an implementation that
    // divided by totalTokens instead of the prompt buckets would answer 53% /
    // 51% / 67% and fall over these pins. (Review NIT, P-CTX.g weld.)
    it('reads the OpenAI shape as read / (read + input)', () => {
      const wrapper = mountUsage({ totalTokens: 1700, outputTokens: 700, inputTokens: 100, cacheReadTokens: 900, cacheCreationTokens: null })
      expect(wrapper.find('[data-testid="status-cache"]').text()).toBe('cache 90%')
    })

    it('uses the three-bucket identity when creation is reported', () => {
      const wrapper = mountUsage({ totalTokens: 1750, outputTokens: 700, inputTokens: 100, cacheReadTokens: 900, cacheCreationTokens: 50 })
      expect(wrapper.find('[data-testid="status-cache"]').text()).toBe('cache 86%')
    })

    it('never turns an unreported bucket into a zero', () => {
      const wrapper = mountUsage({ totalTokens: 1000, inputTokens: null, cacheReadTokens: 900, cacheCreationTokens: null })
      expect(wrapper.find('[data-testid="status-cache"]').exists()).toBe(false)
    })

    // The two edges the TUI pins: a zero prompt identity hides the read-out,
    // and a measured zero renders as 'cache 0%' rather than vanishing.
    it('hides the read-out when the prompt identity is zero', () => {
      const wrapper = mountUsage({ totalTokens: 0, inputTokens: 0, cacheReadTokens: 0, cacheCreationTokens: null })
      expect(wrapper.find('[data-testid="status-cache"]').exists()).toBe(false)
    })

    it('renders a measured zero cache hit as 0%', () => {
      const wrapper = mountUsage({ totalTokens: 100, inputTokens: 100, cacheReadTokens: 0, cacheCreationTokens: null })
      expect(wrapper.find('[data-testid="status-cache"]').text()).toBe('cache 0%')
    })

    it('tracks the live turn through usage frames', async () => {
      const state = reactive(createState('s1'))
      state.turnId = 't1'
      const wrapper = mountWith(state, null)
      expect(wrapper.find('[data-testid="status-cache"]').exists()).toBe(false)

      applyEvent(state, {
        sessionId: 's1', seq: 1, type: 'usage.updated', ts: 1, durable: true, turnId: 't1',
        data: { turnUsage: { totalTokens: 2700, outputTokens: 700, inputTokens: 200, cacheReadTokens: 1800, cacheCreationTokens: null, costUsd: 0.1, unpricedModel: null } },
      } as EventEnvelope)
      await wrapper.vm.$nextTick()
      expect(wrapper.find('[data-testid="status-cache"]').text()).toBe('cache 90%')
    })
  })
})
