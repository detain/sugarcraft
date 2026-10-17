import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import { createAppRouter } from '../../../router'
import { useBackgroundStore } from '../../../stores/agents/background'
import { usePanelsStore } from '../../../stores/agents/panels'
import { Wire } from '../../../stores/agents/__tests__/harness'
import { useLayoutStore } from '../../../stores/layout'
import { useSessionsStore } from '../../../stores/sessions'
import BackgroundPanel from '../BackgroundPanel.vue'
import CommandPalette from '../CommandPalette.vue'
import MemoryPanel from '../MemoryPanel.vue'
import SidePanels from '../SidePanels.vue'
import TodoPanel from '../TodoPanel.vue'
import WorkflowPanel from '../WorkflowPanel.vue'

let pinia: ReturnType<typeof createPinia>
let wire: Wire

beforeEach(() => {
  pinia = createPinia()
  setActivePinia(pinia)
  wire = new Wire()
  try {
    globalThis.localStorage?.clear()
  } catch {
    // no storage
  }
})

describe('TodoPanel', () => {
  it('shows the list and follows todo.updated', async () => {
    await wire.connect()
    const wrapper = mount(TodoPanel, { props: { sessionId: 's1' }, global: { plugins: [pinia] } })
    await flushPromises()
    await wire.answer('todo.get', { items: [] })
    expect(wrapper.text()).toContain('keeps no todo list')

    wire.event('s1', 'todo.updated', { toolCallId: 't', items: [{ content: 'write the parser', status: 'completed' }, { content: 'test it', status: 'in_progress' }] })
    await flushPromises()
    const items = wrapper.findAll('[data-testid="todo-item"]')
    expect(items.map((i) => i.attributes('data-status'))).toEqual(['completed', 'in_progress'])
    expect(wrapper.text()).toContain('1 of 2 done')
  })
})

describe('BackgroundPanel', () => {
  it('badges every row with the server spawn mode and warns when it is bypass-class (ISS-004 panel half)', async () => {
    await wire.connect(() => useBackgroundStore())
    await wire.answer('bg.list', {
      items: [
        { bgId: 'b1', name: 'quiet', task: 'read the docs', status: 'running', createdAt: '2026-10-17 00:00:01', outputBytes: 0, adopted: false },
        { bgId: 'b2', name: 'loud', task: 'ship it', status: 'completed', createdAt: '2026-10-17 00:00:02', outputBytes: 0, adopted: false },
      ],
    })
    const wrapper = mount(BackgroundPanel, { props: { sessionId: null }, global: { plugins: [pinia] } })
    await flushPromises()
    // The server truth: bg.list rows carry no per-session mode key, so the chip
    // reads the hello default every daemon inherits — both rows badge `default`
    // and the asking mode warns nobody.
    expect(wrapper.findAll('[data-testid="bg-mode"]').map((m) => m.text())).toEqual(['mode: default', 'mode: default'])
    expect(wrapper.findAll('[data-testid="bg-bypass-warning"]')).toHaveLength(0)
    expect(wrapper.find('[data-testid="bg-mode-note"]').text()).toContain('no terminal to answer them')
  })

  it('badges and warns from a bypass-class server mode (ISS-004 panel half)', async () => {
    await wire.connect(() => useBackgroundStore(), { defaults: { permissionMode: 'bypass-permissions', delivery: 'steer' } })
    await wire.answer('bg.list', {
      items: [
        { bgId: 'b1', name: 'loud', task: 'ship it', status: 'running', createdAt: '2026-10-17 00:00:01', outputBytes: 0, adopted: false },
      ],
    })
    const wrapper = mount(BackgroundPanel, { props: { sessionId: null }, global: { plugins: [pinia] } })
    await flushPromises()
    expect(wrapper.findAll('[data-testid="bg-mode"]').map((m) => m.text())).toEqual(['mode: bypass-permissions'])
    const warnings = wrapper.findAll('[data-testid="bg-bypass-warning"]')
    expect(warnings).toHaveLength(1)
    expect(warnings[0]?.text()).toContain('bypass-permissions')
  })
})

describe('SidePanels', () => {
  it('opens, switches tabs, and asks for a session where one is needed', async () => {
    await wire.connect()
    const wrapper = mount(SidePanels, { props: { sessionId: null }, global: { plugins: [pinia] } })
    await wrapper.find('[data-testid="panels-toggle"]').trigger('click')
    expect(wrapper.find('[data-testid="side-panels"]').attributes('data-panel')).toBe('agents')
    expect(wrapper.text()).toContain('Open a session to see its agents')
    await wrapper.find('[data-testid="panel-tab-memory"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="memory-panel"]').exists()).toBe(true)
    await wrapper.find('[data-testid="panels-close"]').trigger('click')
    expect(wrapper.find('[data-testid="side-panels"]').exists()).toBe(false)
    expect(usePanelsStore().active).toBe('memory')
  })
})

describe('MemoryPanel', () => {
  it('lists a scope and adds a note to it', async () => {
    await wire.connect()
    const wrapper = mount(MemoryPanel, { global: { plugins: [pinia] } })
    await flushPromises()
    await wire.answer('memory.list', { items: [] })
    expect(wrapper.text()).toContain('No project notes.')
    await wrapper.find('[data-testid="memory-scope-user"]').trigger('click')
    await wire.answer('memory.list', { items: [{ id: 'n1', scope: 'user', content: 'prefer tabs' }] })
    expect(wrapper.findAll('[data-testid="memory-entry"]')).toHaveLength(1)

    await wrapper.find('[data-testid="memory-draft"]').setValue('two spaces in yaml')
    await wrapper.find('form.add').trigger('submit')
    await flushPromises()
    expect(wire.sent('memory.add').pop()?.params).toMatchObject({ content: 'two spaces in yaml', scope: 'user' })
  })

  it('badges each note by source after ISS-020 dual-store listing', async () => {
    await wire.connect()
    const wrapper = mount(MemoryPanel, { global: { plugins: [pinia] } })
    await flushPromises()
    await wire.answer('memory.list', {
      items: [
        { id: 'r1', scope: 'project', content: 'use pnpm in this repo', source: 'repo' },
        { id: 'h1', scope: 'project', content: 'my private editor theme', source: 'home' },
      ],
    })
    expect(wrapper.find('[data-testid="memory-source-repo"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="memory-source-home"]').exists()).toBe(true)
  })

  it('says out loud when a project note fell back to the home store', async () => {
    await wire.connect()
    const wrapper = mount(MemoryPanel, { global: { plugins: [pinia] } })
    await flushPromises()
    await wire.answer('memory.list', { items: [] })

    await wrapper.find('[data-testid="memory-draft"]').setValue('a repo note')
    await wrapper.find('form.add').trigger('submit')
    await flushPromises()
    await wire.answer('memory.add', { id: 'n1', source: 'home', fellBackToHome: true })
    await wire.answer('memory.list', { items: [{ id: 'n1', scope: 'project', content: 'a repo note', source: 'home' }] })
    await flushPromises()

    expect(wrapper.find('[data-testid="memory-notice"]').text()).toContain('teammates will not see it')
  })
})

describe('WorkflowPanel', () => {
  it('lists the session\'s runs and refuses context it cannot send', async () => {
    await wire.connect(() => {}, {})
    const wrapper = mount(WorkflowPanel, { props: { sessionId: 's1' }, global: { plugins: [pinia] } })
    await flushPromises()
    await wire.answer('workflow.runs', { items: [{ source: 'tool', name: 'survey', status: 'completed', running: false, toolCallId: 'w1', report: "Workflow 'survey' completed" }] })
    // workflow.list is answered on hello by the store the shell creates; here, by hand.
    const { useWorkflowsStore } = await import('../../../stores/agents/workflows')
    const loading = useWorkflowsStore().load()
    await flushPromises()
    await wire.answer('workflow.list', { available: true, items: [{ name: 'review' }] })
    await loading
    await flushPromises()

    const runs = wrapper.findAll('[data-testid="workflow-runitem"]')
    expect(runs.map((r) => r.attributes('data-source'))).toEqual(['tool'])
    expect(runs[0]?.text()).toContain('survey')

    await wrapper.find('[data-testid="workflow-vars"]').setValue('two words')
    await wrapper.find('form.start').trigger('submit')
    await flushPromises()
    expect(wrapper.find('[data-testid="workflow-error"]').text()).toBe('"two" is not key=value')
    expect(wire.sent('workflow.run')).toHaveLength(0)

    await wrapper.find('[data-testid="workflow-vars"]').setValue('branch=main')
    await wrapper.find('form.start').trigger('submit')
    await flushPromises()
    expect(wire.sent('workflow.run').pop()?.params).toMatchObject({ sessionId: 's1', name: 'review', vars: { branch: 'main' } })
  })
})

describe('CommandPalette', () => {
  it('opens on Ctrl+K, narrows by every word, and runs what is picked', async () => {
    await wire.connect(() => useSessionsStore())
    await wire.answer('session.list', { items: [{ id: 's2', name: 'refactor db', status: 'idle', createdAt: '2026-10-05 10:00:00' }], nextCursor: null })
    const router = createAppRouter(createMemoryHistory())
    await router.push('/')
    await router.isReady()
    const wrapper = mount(CommandPalette, { props: { activeId: null }, global: { plugins: [pinia, router] }, attachTo: document.body })

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }))
    await flushPromises()
    expect(wrapper.find('[data-testid="command-palette"]').exists()).toBe(true)

    await wrapper.find('[data-testid="palette-input"]').setValue('todo pan')
    expect(wrapper.findAll('[data-testid="palette-entry"]').map((e) => e.attributes('data-entry'))).toEqual(['panel-todo'])
    await wrapper.find('[data-testid="palette-input"]').trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(usePanelsStore().active).toBe('todo')
    expect(usePanelsStore().open).toBe(true)
    expect(wrapper.find('[data-testid="command-palette"]').exists()).toBe(false)

    usePanelsStore().togglePalette(true)
    await flushPromises()
    await wrapper.find('[data-testid="palette-input"]').setValue('refactor')
    await wrapper.find('[data-testid="palette-input"]').trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(router.currentRoute.value.params.id).toBe('s2')
    wrapper.unmount()
  })

  it('puts a command that takes an argument into the composer', async () => {
    await wire.connect()
    const router = createAppRouter(createMemoryHistory())
    const { useSessionStore } = await import('../../../stores/session')
    useSessionStore('s1').commands = [{ name: 'rename', argumentHint: '<name>', source: 'builtin', runsIn: 'server' }]
    const wrapper = mount(CommandPalette, { props: { activeId: 's1' }, global: { plugins: [pinia, router] } })
    usePanelsStore().togglePalette(true)
    await flushPromises()
    await wrapper.find('[data-testid="palette-input"]').setValue('/rename')
    await wrapper.find('[data-testid="palette-input"]').trigger('keydown', { key: 'Enter' })
    await flushPromises()
    expect(useLayoutStore().draft('s1')).toBe('/rename ')
    wrapper.unmount()
  })
})
