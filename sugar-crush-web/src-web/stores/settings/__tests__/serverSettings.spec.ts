import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { FakeSocket, FakeTimers, flush, helloResult } from '../../../__tests__/fakes'
import { Backoff } from '../../../protocol/reconnect'
import { useConnectionStore } from '../../connection'
import { editability, type SettingRow, type TierInfo } from '../fields'
import { useServerSettingsStore } from '../serverSettings'

let sockets: FakeSocket[]

function socket(): FakeSocket {
  const last = sockets[sockets.length - 1]
  if (!last) throw new Error('no socket')
  return last
}

function sent(method: string): Record<string, unknown>[] {
  return socket().sent.filter((frame) => frame.method === method)
}

function answer(method: string, result: unknown): void {
  const frame = sent(method).pop()
  if (!frame) throw new Error(`no ${method} sent`)
  socket().answer(result, frame.id)
}

async function connect(): Promise<void> {
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
  socket().answer(helloResult(), 'hello')
  await flush()
}

const SCHEMA = {
  items: [
    { key: 'parallelToolCalls', type: 'bool', group: 'Tools', label: 'Parallel tool calls', riskClass: 'tuning', applies: 'next-turn', appliesLabel: 'next turn', sensitive: false, writableRemotely: true, projectSettable: true },
    { key: 'statusLine', type: 'map', group: 'UI', label: 'Status line', riskClass: 'exec', applies: 'live', sensitive: false, writableRemotely: false, remoteRefusal: 'runs a command: not writable remotely' },
    { key: 'connectTimeoutSeconds', type: 'float', group: 'Advanced', label: 'Connect timeout', riskClass: 'tuning', applies: 'restart', envVar: 'SUGARCRUSH_CONNECT_TIMEOUT', sensitive: false, writableRemotely: true, projectSettable: true },
  ],
  tiers: [
    { scope: 'user', label: 'You (all projects)', path: '/h/config.json', writable: true },
    { scope: 'project', label: 'This project (local)', writable: false, refusal: 'project settings are ignored until you trust this project' },
  ],
}

const VALUES = {
  scope: 'effective',
  values: {
    parallelToolCalls: { value: true, source: 'default', sourceLabel: 'default', shadowed: [], locked: false },
    statusLine: { value: null, source: 'default', sourceLabel: 'default', shadowed: [], locked: false },
    connectTimeoutSeconds: { value: 7, source: 'env', sourceLabel: 'environment', shadowed: [], locked: true, lockReason: 'set by SUGARCRUSH_CONNECT_TIMEOUT' },
  },
  files: [{ role: 'your config', path: '/h/config.json', status: 'absent', note: 'Layer 4' }],
}

async function loaded(): Promise<ReturnType<typeof useServerSettingsStore>> {
  await connect()
  const settings = useServerSettingsStore()
  const loading = settings.load()
  await flush()
  answer('settings.schema', SCHEMA)
  answer('settings.get', VALUES)
  await loading
  return settings
}

beforeEach(() => {
  setActivePinia(createPinia())
  sockets = []
})

describe('server settings store', () => {
  it('loads the schema, the tiers and the effective values with their provenance', async () => {
    const settings = await loaded()
    expect(sent('settings.get')[0]?.params).toEqual({ scope: 'effective' })
    expect(settings.loaded).toBe(true)
    expect(settings.rows.map((row) => row.key)).toEqual(['parallelToolCalls', 'statusLine', 'connectTimeoutSeconds'])
    expect(settings.tier?.path).toBe('/h/config.json')
    expect(settings.files).toHaveLength(1)
    expect(settings.editabilityOf('parallelToolCalls')).toEqual({ editable: true, reason: null })
    expect(settings.editabilityOf('statusLine').reason).toContain('runs a command')
    expect(settings.editabilityOf('connectTimeoutSeconds').reason).toBe('set by SUGARCRUSH_CONNECT_TIMEOUT')
    settings.setScope('project')
    expect(settings.editabilityOf('parallelToolCalls').reason).toContain('trust this project')
  })

  it('stages edits and resets, and drops an edit back to the value it already has', async () => {
    const settings = await loaded()
    settings.stage('parallelToolCalls', false)
    settings.stageReset('connectTimeoutSeconds')
    expect(settings.stagedCount).toBe(2)
    settings.stage('parallelToolCalls', true)
    expect(settings.staged).toEqual({ connectTimeoutSeconds: { action: 'reset' } })
    settings.unstage('connectTimeoutSeconds')
    expect(settings.dirty).toBe(false)
  })

  it('previews the staged set against the chosen tier, then saves it in one write and re-reads', async () => {
    const settings = await loaded()
    settings.stage('parallelToolCalls', false)
    const previewing = settings.requestPreview()
    await flush()
    expect(sent('settings.preview')[0]?.params).toEqual({ scope: 'user', set: { parallelToolCalls: false }, unset: [] })
    answer('settings.preview', {
      scope: 'user',
      path: '/h/config.json',
      canSave: true,
      refusals: {},
      changes: [{ key: 'parallelToolCalls', action: 'set', value: false, applies: 'next-turn', appliesLabel: 'next turn' }],
      applySummary: '1 next turn',
      notes: [],
      diff: '@@ -1 +1,3 @@\n-{}\n+{\n+    "parallelToolCalls": false\n+}\n',
    })
    await previewing
    expect(settings.preview?.canSave).toBe(true)

    const saving = settings.save()
    await flush()
    const frame = sent('settings.set')[0]
    expect(frame?.params).toMatchObject({ scope: 'user', set: { parallelToolCalls: false }, unset: [] })
    expect(typeof (frame?.params as Record<string, unknown>).idempotencyKey).toBe('string')
    answer('settings.set', { scope: 'user', written: '/h/config.json', changed: ['parallelToolCalls'], appliesByKey: { parallelToolCalls: 'next-turn' } })
    await flush()
    answer('settings.schema', SCHEMA)
    answer('settings.get', VALUES)
    await saving
    expect(settings.saved?.written).toBe('/h/config.json')
    expect(settings.dirty).toBe(false)
    expect(settings.preview).toBeNull()
    expect(sent('settings.get')).toHaveLength(2)
  })

  it('does not save without a preview that can save, and keeps the edit set when the server refuses', async () => {
    const settings = await loaded()
    expect(await settings.save()).toBeNull()
    settings.stage('parallelToolCalls', false)
    const previewing = settings.requestPreview()
    await flush()
    answer('settings.preview', { scope: 'user', path: null, canSave: false, refusals: { '*': 'no' }, changes: [], applySummary: '', notes: [], diff: '' })
    await previewing
    expect(await settings.save()).toBeNull()
    expect(sent('settings.set')).toHaveLength(0)

    settings.closePreview()
    const again = settings.requestPreview()
    await flush()
    answer('settings.preview', { scope: 'user', path: '/h/config.json', canSave: true, refusals: {}, changes: [], applySummary: '', notes: [], diff: '' })
    await again
    const saving = settings.save()
    await flush()
    socket().fail(-32003, 'not_writable_remotely', 'parallelToolCalls cannot be changed over the wire', sent('settings.set')[0]?.id)
    await saving
    expect(settings.error).toContain('cannot be changed over the wire')
    expect(settings.stagedCount).toBe(1)
  })

  it('offers the committed project-shared tier, which takes only the keys a project may set (N-P5)', async () => {
    const settings = await loaded()
    settings.stage('parallelToolCalls', false)
    settings.setScope('project-shared')
    const previewing = settings.requestPreview()
    await flush()
    expect(sent('settings.preview')[0]?.params).toEqual({ scope: 'project-shared', set: { parallelToolCalls: false }, unset: [] })
    answer('settings.preview', { scope: 'project-shared', path: '/p/.sugar-crush/settings.json', canSave: true, refusals: {}, changes: [], applySummary: '', notes: [], diff: '' })
    await previewing

    const shared = { scope: 'project-shared', label: 'This project (shared)', path: '/p/.sugar-crush/settings.json', writable: true } as TierInfo
    const userOnly = { key: 'theme', type: 'enum', group: 'UI', label: 'Theme', riskClass: 'cosmetic', applies: 'live', sensitive: false, writableRemotely: true, projectSettable: false } as SettingRow
    expect(editability(userOnly, undefined, shared)).toEqual({ editable: false, reason: 'user-tier only: a project may not set it' })
    expect(editability({ ...userOnly, projectSettable: true }, undefined, shared)).toEqual({ editable: true, reason: null })
  })
})
