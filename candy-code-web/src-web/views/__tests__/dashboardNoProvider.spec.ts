import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { flushPromises } from '@vue/test-utils'
import { createMemoryHistory } from 'vue-router'
import { createAppRouter } from '../../router'
import { Wire } from '../../components/grid/__tests__/harness'
import DashboardView from '../DashboardView.vue'

/**
 * C-TUI-5.e: the dashboard flags a launch with nothing to talk to. The
 * predicate is the server's own `server.info` inventory — only the offline
 * `echo` stand-in (or an explicitly empty list) earns the "run
 * candy-code login" notice; a failed or fieldless fetch stays silent, so
 * a broken inventory never fabricates a missing-provider claim.
 */

let wire: Wire

beforeEach(() => {
  setActivePinia(createPinia())
})

async function mountDashboard(info: Record<string, unknown>) {
  wire = await Wire.connect()
  const router = createAppRouter(createMemoryHistory())
  await router.push({ name: 'dashboard' })
  await router.isReady()
  const wrapper = mount(DashboardView, { global: { plugins: [router] } })
  wire.answer('server.info', info)
  await flushPromises()
  return wrapper
}

const baseInfo = {
  version: '1.2.3',
  protocol: 1,
  root: '/repo',
  agents: [],
  tools: [],
  commands: [],
  permissionModes: ['default'],
  defaultPermissionMode: 'default',
}

describe('dashboard no-provider notice (C-TUI-5.e)', { retry: 0 }, () => {
  it('stays quiet when a real provider is configured', async () => {
    const wrapper = await mountDashboard({ ...baseInfo, providers: ['anthropic', 'openai'] })
    expect(wrapper.find('[data-testid="dashboard-no-provider"]').exists()).toBe(false)
  })

  it('nags when the only provider on the wire is the echo stand-in', async () => {
    const wrapper = await mountDashboard({ ...baseInfo, providers: ['echo'] })
    const notice = wrapper.find('[data-testid="dashboard-no-provider"]')
    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('candy-code login')
  })

  it('nags when the server explicitly lists no providers', async () => {
    const wrapper = await mountDashboard({ ...baseInfo, providers: [] })
    expect(wrapper.find('[data-testid="dashboard-no-provider"]').exists()).toBe(true)
  })

  it('says nothing when the inventory carries no providers field at all', async () => {
    const wrapper = await mountDashboard({ ...baseInfo })
    expect(wrapper.find('[data-testid="dashboard-no-provider"]').exists()).toBe(false)
  })
})
