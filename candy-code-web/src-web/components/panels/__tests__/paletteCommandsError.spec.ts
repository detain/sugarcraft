/**
 * n59 (CW-UX-B): a background command.list failure rides the palette's
 * EXISTING alert line (the one pick() rejections already use) — an empty
 * command group is then explained instead of silently indistinguishable from
 * a session with no commands. The line rides the store's commandsError verbatim.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import { createAppRouter } from '../../../router'
import { useSessionStore } from '../../../stores/session'
import { usePanelsStore } from '../../../stores/agents/panels'
import { Wire } from '../../../stores/agents/__tests__/harness'
import CommandPalette from '../CommandPalette.vue'

let pinia: ReturnType<typeof createPinia>

beforeEach(async () => {
  pinia = createPinia()
  setActivePinia(pinia)
  const wire = new Wire()
  await wire.connect()
})

async function openPalette() {
  const router = createAppRouter(createMemoryHistory())
  await router.push('/')
  await router.isReady()
  const wrapper = mount(CommandPalette, { props: { sessionId: 's1' }, global: { plugins: [pinia, router] }, attachTo: document.body })
  usePanelsStore().togglePalette(true)
  await flushPromises()
  return wrapper
}

describe('CommandPalette commands-unavailable line (n59)', () => {
  it('shows the command.load failure on the existing alert line and hides it when cleared', async () => {
    const wrapper = await openPalette()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)

    const session = useSessionStore('s1')
    session.commandsError = 'unknown method'
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toBe('unknown method')

    session.commandsError = null
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
