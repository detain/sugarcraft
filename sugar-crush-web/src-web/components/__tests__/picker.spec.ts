import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import { createAppRouter } from '../../router'
import { Wire } from '../../stores/agents/__tests__/harness'
import { useNewSessionStore } from '../../stores/newSession'
import { useSessionsStore } from '../../stores/sessions'
import DirectoryPicker from '../DirectoryPicker.vue'

let pinia: ReturnType<typeof createPinia>
let wire: Wire

const BROWSE = { features: { methods: [], events: [], dirBrowse: { enabled: true, root: '/home/me' } }, server: { version: '1', connectionId: 'c1', root: '/home/me/src/app', pid: 1 } }

function listing(path: string, parent: string | null, names: string[], extra: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    root: '/home/me',
    path,
    parent,
    entries: names.map((name) => ({ name, path: `${path === '/' ? '' : path}/${name}`, project: name === 'app', readable: true })),
    truncated: false,
    readable: true,
    project: false,
    ...extra,
  }
}

function lastParams(method: string): Record<string, unknown> | undefined {
  return wire.sent(method).pop()?.params as Record<string, unknown> | undefined
}

beforeEach(() => {
  pinia = createPinia()
  setActivePinia(pinia)
  wire = new Wire()
})

describe('new session without directory browsing', () => {
  it('creates a session on the server\'s own root at once', async () => {
    await wire.connect(() => useSessionsStore())
    await wire.answer('session.list', { items: [], nextCursor: null })
    const flow = useNewSessionStore()
    expect(flow.canBrowse).toBe(false)

    const started = flow.start()
    await flushPromises()
    expect(flow.pickerOpen).toBe(false)
    expect(lastParams('session.create')).not.toHaveProperty('root')
    await wire.answer('session.create', { id: 's9', open: true, status: 'idle' })
    expect((await started)?.id).toBe('s9')
    expect(wire.sent('fs.listDirs')).toHaveLength(0)
  })
})

describe('DirectoryPicker', () => {
  async function openPicker(): Promise<{ wrapper: ReturnType<typeof mount>; started: Promise<unknown> }> {
    await wire.connect(() => useSessionsStore(), BROWSE)
    const router = createAppRouter(createMemoryHistory())
    const wrapper = mount(DirectoryPicker, { global: { plugins: [pinia, router] }, attachTo: document.body })
    const flow = useNewSessionStore()
    expect(flow.canBrowse).toBe(true)
    const started = flow.start()
    await flushPromises()
    expect(lastParams('fs.listDirs')).toEqual({ path: '/home/me/src/app', showHidden: false })
    await wire.answer('fs.listDirs', listing('/home/me/src/app', '/home/me/src', ['lib', 'tests']))
    await flushPromises()
    return { wrapper, started }
  }

  it('opens at the server\'s root, browses up and down, and shows a breadcrumb', async () => {
    const { wrapper } = await openPicker()
    expect(wrapper.findAll('[data-testid="picker-crumb"]').map((c) => c.text())).toEqual(['/home/me', 'src', 'app'])
    expect(wrapper.findAll('[data-testid="picker-entry"]').map((e) => e.attributes('data-name'))).toEqual(['lib', 'tests'])

    await wrapper.find('[data-testid="picker-up"]').trigger('click')
    expect(lastParams('fs.listDirs')).toEqual({ path: '/home/me/src', showHidden: false })
    await wire.answer('fs.listDirs', listing('/home/me/src', '/home/me', ['app', 'notes']))
    await flushPromises()
    expect(wrapper.find('[data-testid="picker-project"]').exists()).toBe(true)

    // Keyboard: ↓ then Enter opens the second entry.
    const list = wrapper.find('[data-testid="picker-list"]')
    await list.trigger('keydown', { key: 'ArrowDown' })
    await list.trigger('keydown', { key: 'Enter' })
    expect(lastParams('fs.listDirs')).toEqual({ path: '/home/me/src/notes', showHidden: false })
    await wire.answer('fs.listDirs', listing('/home/me/src/notes', '/home/me/src', []))
    await flushPromises()
    expect(wrapper.text()).toContain('No subdirectories.')

    // Backspace goes up; at the root, Up is disabled.
    await list.trigger('keydown', { key: 'Backspace' })
    expect(lastParams('fs.listDirs')).toEqual({ path: '/home/me/src', showHidden: false })
    await wrapper.findAll('[data-testid="picker-crumb"]')[0]!.trigger('click')
    await wire.answer('fs.listDirs', listing('/home/me', null, ['src']))
    await flushPromises()
    expect(wrapper.find('[data-testid="picker-up"]').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('toggles hidden directories and takes a typed path, showing a refusal', async () => {
    const { wrapper } = await openPicker()
    await wrapper.find('[data-testid="picker-hidden"]').setValue(true)
    expect(lastParams('fs.listDirs')).toEqual({ path: '/home/me/src/app', showHidden: true })
    await wire.answer('fs.listDirs', listing('/home/me/src/app', '/home/me/src', ['.git', 'lib']))
    await flushPromises()
    expect(wrapper.findAll('[data-testid="picker-entry"]').map((e) => e.attributes('data-name'))).toEqual(['.git', 'lib'])

    await wrapper.find('[data-testid="picker-path"]').setValue('/etc')
    await wrapper.find('form.path').trigger('submit')
    expect(lastParams('fs.listDirs')).toEqual({ path: '/etc', showHidden: true })
    await wire.fail('fs.listDirs', -32003, 'outside_browse_root', '/etc is outside the browse root /home/me')
    await flushPromises()
    expect(wrapper.find('[data-testid="picker-error"]').text()).toContain('outside the browse root')
    expect(wrapper.find('[data-testid="picker-current"]').text()).toBe('/home/me/src/app')
    wrapper.unmount()
  })

  it('starts the session in the directory on screen', async () => {
    const { wrapper, started } = await openPicker()
    await wrapper.findAll('[data-testid="picker-entry"]')[0]!.trigger('click')
    await wire.answer('fs.listDirs', listing('/home/me/src/app/lib', '/home/me/src/app', []))
    await flushPromises()

    await wrapper.find('[data-testid="picker-start"]').trigger('click')
    await flushPromises()
    expect(lastParams('workspace.open')).toMatchObject({ root: '/home/me/src/app/lib', browse: true })
    await wire.answer('workspace.open', { root: '/home/me/src/app/lib', primary: false, running: true, pid: 7 })
    await flushPromises()
    expect(lastParams('session.create')).toMatchObject({ root: '/home/me/src/app/lib' })
    await wire.answer('session.create', { id: 's2', open: true, status: 'idle', root: '/home/me/src/app/lib' })
    await flushPromises()

    expect((await started as { id: string }).id).toBe('s2')
    expect(useNewSessionStore().pickerOpen).toBe(false)
    expect(useSessionsStore().byId['s2']?.root).toBe('/home/me/src/app/lib')
    wrapper.unmount()
  })

  it('the server\'s own root starts an ordinary session; Escape cancels', async () => {
    const { wrapper, started } = await openPicker()
    await wrapper.find('[data-testid="picker-start"]').trigger('click')
    await wire.answer('workspace.open', { root: '/home/me/src/app', primary: true, running: true, pid: 1 })
    await flushPromises()
    expect(lastParams('session.create')).not.toHaveProperty('root')
    await wire.answer('session.create', { id: 's3', open: true, status: 'idle' })
    expect((await started as { id: string }).id).toBe('s3')

    const again = useNewSessionStore().start()
    await flushPromises()
    await wire.answer('fs.listDirs', listing('/home/me/src/app', '/home/me/src', []))
    await wrapper.find('[data-testid="dir-picker-backdrop"]').trigger('keydown', { key: 'Escape' })
    expect(await again).toBeNull()
    expect(wrapper.find('[data-testid="dir-picker"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
