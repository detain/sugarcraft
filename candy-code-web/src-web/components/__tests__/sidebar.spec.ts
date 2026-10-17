import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import { createAppRouter } from '../../router'
import { useSessionsStore } from '../../stores/sessions'
import { Wire } from '../grid/__tests__/harness'
import SessionSidebar from '../SessionSidebar.vue'

let router: ReturnType<typeof createAppRouter>

/** Let the pushed navigation settle. */
async function settle(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0))
}

async function mountSidebar(ids: string[]) {
  await Wire.connect()
  const sessions = useSessionsStore()
  sessions.byId = Object.fromEntries(
    ids.map((id, index) => [id, { id, name: `session ${index}`, status: 'idle', open: true, updatedAt: `2026-10-17 00:00:0${ids.length - index}`, createdAt: '', permissionMode: 'default', root: '/repo', contextTokens: 0, contextWindow: 0 }]),
  )
  router = createAppRouter(createMemoryHistory())
  await router.push({ name: 'session', params: { id: ids[0] ?? 'x' } })
  await router.isReady()
  const wrapper = mount(SessionSidebar, {
    props: { activeId: ids[0] ?? null },
    global: { plugins: [router] },
  })
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('sidebar j/k (ISS-053)', () => {
  it('moves between sessions when the list itself has the keys', async () => {
    const wrapper = await mountSidebar(['s1', 's2', 's3'])
    await wrapper.find('nav').trigger('keydown', { key: 'j' })
    await settle()
    expect(router.currentRoute.value.params.id).toBe('s2')
    await wrapper.setProps({ activeId: 's2' })
    await wrapper.find('nav').trigger('keydown', { key: 'k' })
    await settle()
    expect(router.currentRoute.value.params.id).toBe('s1')
  })

  it('leaves typing in the filter box alone', async () => {
    const wrapper = await mountSidebar(['s1', 's2'])
    const input = wrapper.find('input[type="search"]')
    await input.trigger('keydown', { key: 'j' })
    await input.trigger('keydown', { key: 'k' })
    await settle()
    expect(router.currentRoute.value.params.id).toBe('s1')
  })

  it('ignores modified j/k', async () => {
    const wrapper = await mountSidebar(['s1', 's2'])
    await wrapper.find('nav').trigger('keydown', { key: 'j', ctrlKey: true })
    await wrapper.find('nav').trigger('keydown', { key: 'j', metaKey: true })
    await wrapper.find('nav').trigger('keydown', { key: 'k', shiftKey: true })
    await wrapper.find('nav').trigger('keydown', { key: 'k', altKey: true })
    await settle()
    expect(router.currentRoute.value.params.id).toBe('s1')
  })
})
