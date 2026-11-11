import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useAgentsStore } from '../../../stores/agents/agents'
import { Wire } from '../../../stores/agents/__tests__/harness'
import { useSessionStore } from '../../../stores/session'
import { applySnapshot, createState } from '../../../stores/reducer'
import type { SessionSnapshot } from '../../../protocol/generated'
import AgentsPanel from '../AgentsPanel.vue'

/**
 * m26 (CW-COVER-B F4): the server trims a subagent roster over its display
 * cap and names the trimmed count as `subagentsDropped` on the snapshot —
 * "named only when at least one row was dropped; absent means nothing was
 * trimmed." Before this lane the web typed the field and consumed nothing,
 * so a trimmed roster rendered as if complete. These pins hold the fold
 * (snapshot → state, RESET grammar included) and the honest notice.
 */

function snapshot(overrides: Partial<SessionSnapshot> = {}): SessionSnapshot {
  return {
    sessionId: 's1',
    status: 'idle',
    messages: [],
    lastSeq: 1,
    pendingAsks: [],
    queue: [],
    ...overrides,
  }
}

describe('reducer fold: snapshot subagentsDropped (m26)', () => {
  it('lands the trimmed count from the snapshot on the state', () => {
    const state = createState('s1')
    expect(state.subagentsDropped).toBe(0)
    applySnapshot(state, snapshot({ subagentsDropped: 7 }))
    expect(state.subagentsDropped).toBe(7)
  })

  it('reads an absent field as nothing-trimmed, clearing a prior count', () => {
    const state = createState('s1')
    applySnapshot(state, snapshot({ subagentsDropped: 7 }))
    expect(state.subagentsDropped).toBe(7)
    // A later snapshot (RESET or re-subscribe) that carries no count states
    // nothing was trimmed — the notice must not survive on stale memory.
    applySnapshot(state, snapshot())
    expect(state.subagentsDropped).toBe(0)
  })

  it('takes the count per snapshot, never from live events', () => {
    const state = createState('s1')
    applySnapshot(state, snapshot({ subagentsDropped: 3 }))
    applySnapshot(state, snapshot({ subagentsDropped: 5 }))
    expect(state.subagentsDropped).toBe(5)
  })
})

describe('AgentsPanel drop notice (m26)', () => {
  let pinia: ReturnType<typeof createPinia>

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
  })

  async function panelShowing(dropped: number | undefined) {
    const wire = new Wire()
    await wire.connect()
    const session = useSessionStore('s1')
    const subscribed = session.subscribe()
    await wire.answer('session.subscribe', {
      reset: true,
      throughSeq: 1,
      pendingAsks: [],
      snapshot: {
        sessionId: 's1',
        status: 'idle',
        lastSeq: 1,
        pendingAsks: [],
        queue: [],
        messages: [],
        ...(dropped === undefined ? {} : { subagentsDropped: dropped }),
      },
    })
    await subscribed
    const agents = useAgentsStore()
    const refreshing = agents.refresh('s1')
    await flushPromises()
    await wire.answer('agents.subtree', { items: [] })
    await refreshing
    const wrapper = mount(AgentsPanel, { props: { sessionId: 's1' }, global: { plugins: [pinia] } })
    await flushPromises()
    return { wrapper, wire }
  }

  it('announces a trimmed roster with the plural count', async () => {
    const { wrapper, wire } = await panelShowing(2)
    const notice = wrapper.find('[data-testid="agents-dropped"]')
    expect(notice.exists()).toBe(true)
    expect(notice.text()).toBe('2 earlier subagents omitted')
    expect(notice.attributes('role')).toBe('status')
    wrapper.unmount()
    void wire
  })

  it('uses the single form for exactly one dropped row', async () => {
    const { wrapper } = await panelShowing(1)
    expect(wrapper.find('[data-testid="agents-dropped"]').text()).toBe('1 earlier subagent omitted')
    wrapper.unmount()
  })

  it('stays silent when the snapshot says nothing was trimmed', async () => {
    const { wrapper } = await panelShowing(undefined)
    expect(wrapper.find('[data-testid="agents-dropped"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
