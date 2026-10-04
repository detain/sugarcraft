import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import App from '../App.vue'
import { createAppRouter } from '../router'
import { useConnectionStore } from '../stores/connection'

describe('connection store', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('starts disconnected', () => {
    const store = useConnectionStore()
    expect(store.status).toBe('disconnected')
    expect(store.statusLabel).toBe('not connected')
  })

  it('relabels on every transition', () => {
    const store = useConnectionStore()
    store.setStatus('connecting')
    expect(store.statusLabel).toBe('connecting…')
    store.setStatus('connected')
    expect(store.statusLabel).toBe('connected')
  })
})

describe('router', () => {
  it('sends unknown paths to the dashboard', async () => {
    const router = createAppRouter(createMemoryHistory())
    await router.push('/no/such/page')
    expect(router.currentRoute.value.name).toBe('dashboard')
  })
})

describe('App', () => {
  it('renders the shell and the dashboard', async () => {
    const pinia = createPinia()
    const router = createAppRouter(createMemoryHistory())
    await router.push('/')
    await router.isReady()

    const wrapper = mount(App, { global: { plugins: [pinia, router] } })

    expect(wrapper.find('.brand').text()).toContain('SugarCrush')
    expect(wrapper.find('.status').attributes('data-status')).toBe('disconnected')
    expect(wrapper.find('.dashboard h1').text()).toBe('SugarCrush web')
  })
})
