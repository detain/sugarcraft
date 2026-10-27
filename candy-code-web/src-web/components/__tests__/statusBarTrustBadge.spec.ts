import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import StatusBar from '../StatusBar.vue'
import { summary as sessionSummary, Wire } from '../grid/__tests__/harness'
import { usePanelsStore } from '../../stores/agents/panels'
import type { SessionSummary } from '../../protocol/generated'
import { createState } from '../../stores/reducer'

/**
 * C-SEC-4: a trust prompt minted by `session.subscribe` must be visible
 * before any panel measures — the badge rides the store's `pendingTrust`
 * shelf and opens the Context panel that answers it.
 */

let wire: Wire

const TRUST = {
  askId: 'trust-9f0e1d2c3',
  kind: 'trust',
  target: 'mcp',
  root: '/repo',
  reason: 'This project declares MCP servers in .mcp.json.',
  status: 'asked',
  persisted: false,
  sessionId: 's1',
}

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

beforeEach(() => {
  setActivePinia(createPinia())
  try {
    globalThis.localStorage?.clear()
  } catch {
    // no storage
  }
})

async function mountConnected() {
  wire = await Wire.connect()
  const wrapper = mount(StatusBar, {
    props: {
      state: createState('s1'),
      summary: sessionSummary('s1') as unknown as SessionSummary,
      contextPct: null,
      connection: 'live',
    },
  })
  await flushPromises()
  wire.answer('server.info', infoResult)
  await flushPromises()
  return wrapper
}

describe('StatusBar trust badge (C-SEC-4)', { retry: 0 }, () => {
  it('badges an unanswered trust prompt with no panel mounted and opens the Context panel', async () => {
    const wrapper = await mountConnected()
    expect(wrapper.find('[data-testid="trust-badge"]').exists()).toBe(false)

    wire.serverEvent('permission.asked', TRUST)
    await flushPromises()

    const badge = wrapper.find('[data-testid="trust-badge"]')
    expect(badge.exists()).toBe(true)
    await badge.trigger('click')
    const panels = usePanelsStore()
    expect(panels.active).toBe('context')
    expect(panels.open).toBe(true)
  })

  it('a settled prompt never badges', async () => {
    const wrapper = await mountConnected()
    wire.serverEvent('permission.asked', { ...TRUST, status: 'granted', persisted: true })
    await flushPromises()
    expect(wrapper.find('[data-testid="trust-badge"]').exists()).toBe(false)
  })
})
