import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import { beforeEach, describe, expect, it } from 'vitest'
import StatusBar from '../StatusBar.vue'
import { summary as sessionSummary, Wire } from '../grid/__tests__/harness'
import type { EventEnvelope, SessionSummary } from '../../protocol/generated'
import { applyEvent, createState } from '../../stores/reducer'

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
