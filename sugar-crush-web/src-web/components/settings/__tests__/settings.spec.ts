import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { FakeSocket, FakeTimers, flush, helloResult } from '../../../__tests__/fakes'
import { Backoff } from '../../../protocol/reconnect'
import { useConnectionStore } from '../../../stores/connection'
import type { SavePreview, SettingRow } from '../../../stores/settings/fields'
import SettingsField from '../SettingsField.vue'
import SettingsPreview from '../SettingsPreview.vue'
import SettingsView from '../SettingsView.vue'

function row(overrides: Partial<SettingRow> = {}): SettingRow {
  return {
    key: 'parallelToolDeadlineSeconds',
    type: 'int',
    group: 'Tools',
    label: 'Parallel deadline',
    help: 'How long a batch may run.',
    riskClass: 'tuning',
    applies: 'next-turn',
    appliesLabel: 'next turn',
    min: 1,
    max: 600,
    sensitive: false,
    writableRemotely: true,
    projectSettable: true,
    ...overrides,
  }
}

describe('SettingsField', () => {
  it('shows the apply badge and where the value comes from', () => {
    const wrapper = mount(SettingsField, {
      props: { row: row(), effective: { value: 40, source: 'user-config', sourceLabel: 'config.json', sourcePath: '/h/config.json', locked: false }, editable: true, reason: null, staged: undefined },
    })
    expect(wrapper.attributes('data-applies')).toBe('next-turn')
    expect(wrapper.attributes('data-source')).toBe('user-config')
    expect(wrapper.find('[data-testid="settings-applies"]').text()).toBe('next turn')
    expect(wrapper.find('[data-testid="settings-source"]').text()).toBe('config.json')
    expect(wrapper.find('[data-testid="settings-source"]').attributes('title')).toBe('/h/config.json')
    expect((wrapper.find('[data-testid="settings-input"]').element as HTMLInputElement).value).toBe('40')
    expect(wrapper.find('[data-testid="settings-reset"]').exists()).toBe(true)
  })

  it('is disabled, with the lock named, when the environment pins it', () => {
    const wrapper = mount(SettingsField, {
      props: { row: row(), effective: { value: 7, source: 'env', sourceLabel: 'environment', locked: true, lockReason: 'set by SUGARCRUSH_X' }, editable: false, reason: 'set by SUGARCRUSH_X', staged: undefined },
    })
    expect(wrapper.attributes('data-editable')).toBe('false')
    expect(wrapper.find('[data-testid="settings-input"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="settings-reason"]').text()).toBe('set by SUGARCRUSH_X')
    expect(wrapper.find('[data-testid="settings-source"]').text()).toContain('set by SUGARCRUSH_X')
    expect(wrapper.find('[data-testid="settings-reset"]').exists()).toBe(false)
  })

  it('stages a valid value and refuses nonsense in place', async () => {
    const wrapper = mount(SettingsField, { props: { row: row(), effective: undefined, editable: true, reason: null, staged: undefined } })
    const input = wrapper.find('[data-testid="settings-input"]')
    await input.setValue('0')
    expect(wrapper.find('[data-testid="settings-field-error"]').text()).toBe('between 1 and 600')
    expect(wrapper.emitted('stage')).toBeUndefined()
    await input.setValue('45')
    expect(wrapper.emitted('stage')).toEqual([[45]])
    expect(wrapper.find('[data-testid="settings-field-error"]').exists()).toBe(false)
  })

  it('stages a checkbox, a choice and a list, and offers undo once staged', async () => {
    const bool = mount(SettingsField, { props: { row: row({ key: 'parallelToolCalls', type: 'bool' }), effective: { value: true, source: 'default', locked: false }, editable: true, reason: null, staged: undefined } })
    await bool.find('[data-testid="settings-input"]').setValue(false)
    expect(bool.emitted('stage')).toEqual([[false]])

    const select = mount(SettingsField, { props: { row: row({ key: 'notify', type: 'enum', options: ['off', 'bell', 'osc9'] }), effective: undefined, editable: true, reason: null, staged: undefined } })
    await select.find('[data-testid="settings-input"]').setValue('bell')
    expect(select.emitted('stage')).toEqual([['bell']])

    const list = mount(SettingsField, { props: { row: row({ key: 'disabledTools', type: 'list' }), effective: undefined, editable: true, reason: null, staged: { action: 'set', value: ['Bash'] } } })
    expect((list.find('[data-testid="settings-input"]').element as HTMLTextAreaElement).value).toBe('Bash')
    expect(list.find('[data-testid="settings-staged"]').text()).toContain('changed from unset')
    await list.find('[data-testid="settings-unstage"]').trigger('click')
    expect(list.emitted('unstage')).toHaveLength(1)
  })
})

const PREVIEW: SavePreview = {
  scope: 'user',
  path: '/h/config.json',
  canSave: true,
  refusals: {},
  changes: [
    { key: 'parallelToolCalls', action: 'set', value: false, applies: 'next-turn', appliesLabel: 'next turn' },
    { key: 'disabledTools', action: 'reset', applies: 'restart', appliesLabel: 'restart' },
  ],
  applySummary: '1 next turn · 1 restart',
  notes: ['parallelToolCalls: overrides the value in your settings.json'],
  diff: '@@ -1,3 +1,3 @@\n {\n-    "disabledTools": []\n+    "parallelToolCalls": false\n }\n',
}

describe('SettingsPreview', () => {
  it('shows the file, the diff, when each change applies and the notes', async () => {
    const wrapper = mount(SettingsPreview, { props: { preview: PREVIEW, tierLabel: 'You (all projects)', saving: false } })
    expect(wrapper.text()).toContain('Save to You (all projects)')
    expect(wrapper.text()).toContain('/h/config.json')
    const changes = wrapper.findAll('[data-testid="settings-change"]')
    expect(changes.map((c) => c.attributes('data-key'))).toEqual(['parallelToolCalls', 'disabledTools'])
    expect(changes[0]?.text()).toContain('→ off')
    expect(changes[1]?.text()).toContain('reset (deleted from the file)')
    expect(changes.map((c) => c.find('.badge').text())).toEqual(['next turn', 'restart'])
    expect(wrapper.find('[data-testid="diff"]').text()).toContain('"parallelToolCalls": false')
    expect(wrapper.find('[data-testid="settings-apply-summary"]').text()).toBe('Applies: 1 next turn · 1 restart')
    expect(wrapper.find('[data-testid="settings-notes"]').text()).toContain('overrides the value in your settings.json')
    await wrapper.find('[data-testid="settings-save"]').trigger('click')
    expect(wrapper.emitted('save')).toHaveLength(1)
  })

  it('cannot save what the server refuses', () => {
    const wrapper = mount(SettingsPreview, {
      props: { preview: { ...PREVIEW, canSave: false, refusals: { statusLine: 'statusLine: runs a command: not writable remotely' } }, tierLabel: 'You', saving: false },
    })
    expect(wrapper.attributes('data-can-save')).toBe('false')
    expect(wrapper.find('[data-testid="settings-refusals"]').text()).toContain('runs a command')
    expect(wrapper.find('[data-testid="settings-save"]').attributes('disabled')).toBeDefined()
  })
})

describe('SettingsView', () => {
  let sockets: FakeSocket[]

  function socket(): FakeSocket {
    const last = sockets[sockets.length - 1]
    if (!last) throw new Error('no socket')
    return last
  }

  function answer(method: string, result: unknown): void {
    const frame = socket().sent.filter((f) => f.method === method).pop()
    if (!frame) throw new Error(`no ${method} sent`)
    socket().answer(result, frame.id)
  }

  beforeEach(() => {
    setActivePinia(createPinia())
    sockets = []
  })

  async function mountView(scopes = ['read', 'write', 'approve', 'admin']) {
    const connection = useConnectionStore()
    connection.configure({
      connectUrl: async () => 'ws://x/ws?ticket=t',
      socket: (url, protocols) => {
        const created = new FakeSocket(url, protocols)
        sockets.push(created)
        return created
      },
      timers: new FakeTimers(),
      backoff: new Backoff({ random: () => 0.5 }),
    }, (fn) => fn())
    connection.start()
    await flush()
    socket().open()
    socket().answer(helloResult({ principal: { kind: 'owner', scopes } }), 'hello')
    await flush()
    const wrapper = mount(SettingsView)
    await flush()
    answer('settings.schema', {
      items: [
        row({ key: 'parallelToolCalls', type: 'bool', label: 'Parallel tool calls' }),
        row({ key: 'statusLine', type: 'map', group: 'UI', label: 'Status line', riskClass: 'exec', applies: 'live', appliesLabel: 'live', writableRemotely: false, remoteRefusal: 'runs a command: not writable remotely' }),
        row({ key: 'layout', type: 'json', group: 'UI', label: 'Layout', ui: 'hidden' }),
      ],
      tiers: [
        { scope: 'user', label: 'You (all projects)', path: '/h/config.json', writable: true },
        { scope: 'project', label: 'This project (local)', writable: false, refusal: 'project settings are ignored until you trust this project' },
      ],
    })
    answer('settings.get', {
      scope: 'effective',
      values: {
        parallelToolCalls: { value: true, source: 'default', sourceLabel: 'default', shadowed: [], locked: false },
        statusLine: { value: null, source: 'default', sourceLabel: 'default', shadowed: [], locked: false },
      },
      files: [{ role: 'your config', path: '/h/config.json', status: 'absent', note: 'Layer 4' }],
    })
    await flushPromises()
    return wrapper
  }

  it('renders the form from the schema: groups, tiers, read-only reasons, no hidden rows', async () => {
    const wrapper = await mountView()
    expect(wrapper.attributes('data-loaded')).toBe('true')
    expect(wrapper.findAll('[data-testid="settings-group"]').map((g) => g.attributes('data-group'))).toEqual(['Tools', 'UI'])
    expect(wrapper.findAll('[data-testid="settings-field"]').map((f) => f.attributes('data-key'))).toEqual(['parallelToolCalls', 'statusLine'])
    const status = wrapper.find('[data-key="statusLine"]')
    expect(status.attributes('data-editable')).toBe('false')
    expect(status.find('[data-testid="settings-reason"]').text()).toContain('runs a command')
    expect(wrapper.find('[data-testid="settings-tier-project"] input').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="settings-tier-user"] input').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-testid="settings-footer"]').exists()).toBe(false)
  })

  it('stages a change, previews it and saves it', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-key="parallelToolCalls"] [data-testid="settings-input"]').setValue(false)
    expect(wrapper.find('[data-testid="settings-staged-count"]').text()).toContain('1 change staged for You (all projects)')

    await wrapper.find('[data-testid="settings-preview-button"]').trigger('click')
    await flush()
    answer('settings.preview', { ...PREVIEW, changes: [PREVIEW.changes[0]], notes: [] })
    await flushPromises()
    expect(wrapper.find('[data-testid="settings-preview"]').exists()).toBe(true)

    await wrapper.find('[data-testid="settings-save"]').trigger('click')
    await flush()
    answer('settings.set', { scope: 'user', written: '/h/config.json', changed: ['parallelToolCalls'], appliesByKey: { parallelToolCalls: 'next-turn' } })
    await flushPromises()
    expect(wrapper.find('[data-testid="settings-saved"]').text()).toBe('Saved 1 setting to /h/config.json · 1 next turn')
    expect(wrapper.find('[data-testid="settings-preview"]').exists()).toBe(false)
  })

  it('shows every field read-only to a sign-in without the admin scope', async () => {
    const wrapper = await mountView(['read'])
    expect(wrapper.find('[data-testid="settings-read-only"]').exists()).toBe(true)
    const field = wrapper.find('[data-key="parallelToolCalls"]')
    expect(field.attributes('data-editable')).toBe('false')
    expect(field.find('[data-testid="settings-reason"]').text()).toBe('this sign-in may not change settings')
  })
})
