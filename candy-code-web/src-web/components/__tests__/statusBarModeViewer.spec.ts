import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import StatusBar from '../StatusBar.vue'
import { summary as sessionSummary } from '../grid/__tests__/harness'
import { FakeSocket, FakeTimers, flush, helloResult } from '../../__tests__/fakes'
import { Backoff } from '../../protocol/reconnect'
import { useApprovalsStore } from '../../stores/approvals'
import { useConnectionStore } from '../../stores/connection'
import { useLayoutStore } from '../../stores/layout'
import { useSessionsStore } from '../../stores/sessions'
import type { SessionSummary } from '../../protocol/generated'
import { createState } from '../../stores/reducer'

/**
 * m23: the permission-mode switcher must not be offered to a principal that
 * cannot change the mode. `session.setMode` is write-scoped server-side
 * (Dispatcher requireScope against the method spec; a viewer grant carries
 * `read` alone) and the refusal there is honest — an interactive control
 * whose every change dies at the server is the lie. The gate mirrors the
 * server predicate (the scopes hello reports), not a hard-coded kind, since
 * the principal-kind vocabulary may widen; before the hello handshake the
 * server has stated nothing yet, so the chrome keeps its prior shape.
 *
 * Pair pins prove this is not a blind hide: the owner mount below keeps the
 * select, and the viewer mount still sees the CURRENT mode as plain read-out
 * (the provider/model chrome's own v-else pattern).
 */

const VIEWER = { kind: 'viewer', via: 'viewer', scopes: ['read'] }
const OWNER = { kind: 'owner', via: 'bearer', scopes: ['read', 'write', 'approve', 'admin'] }

async function mountConnected(principal: Record<string, unknown>) {
  const connection = useConnectionStore()
  const sockets: FakeSocket[] = []
  const timers = new FakeTimers()
  connection.configure(
    {
      connectUrl: async () => 'ws://x/ws?ticket=t',
      socket: (url, protocols) => {
        const socket = new FakeSocket(url, protocols)
        sockets.push(socket)
        return socket
      },
      timers,
      backoff: new Backoff({ random: () => 0.5 }),
    },
    (fn) => fn(),
  )
  useSessionsStore()
  useApprovalsStore()
  useLayoutStore()
  connection.start()
  await flush()
  const socket = sockets[sockets.length - 1] as FakeSocket
  socket.open()
  socket.answer(helloResult({ principal }), 'hello')
  await flush()
  const wrapper = mount(StatusBar, {
    props: {
      state: createState('s1'),
      summary: sessionSummary('s1', { provider: 'openai', model: 'gpt-5' }) as unknown as SessionSummary,
      contextPct: null,
      connection: 'live',
    },
  })
  await flushPromises()
  return wrapper
}

describe('StatusBar permission-mode principal gate (m23)', { retry: 0 }, () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('hides the mode switcher from a read-only viewer principal', async () => {
    const wrapper = await mountConnected(VIEWER)
    expect(wrapper.find('[data-testid="mode-select"]').exists()).toBe(false)
    // The current mode stays visible as a plain read-out — state, not an affordance.
    const readOut = wrapper.find('[data-testid="mode-state"]')
    expect(readOut.exists()).toBe(true)
    expect(readOut.text()).toContain('mode: default')
  })

  it('keeps the mode switcher for a write-scoped owner principal (pair pin: not a blind hide)', async () => {
    const wrapper = await mountConnected(OWNER)
    expect(wrapper.find('[data-testid="mode-select"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="mode-state"]').exists()).toBe(false)
  })
})
