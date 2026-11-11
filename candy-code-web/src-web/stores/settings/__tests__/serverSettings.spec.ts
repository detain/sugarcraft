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

function getFrames(readScope: string): Record<string, unknown>[] {
  return sent('settings.get').filter(
    (frame) => (frame.params as { scope?: string } | undefined)?.scope === readScope,
  )
}

function answer(method: string, result: unknown): void {
  const frame = sent(method).pop()
  if (!frame) throw new Error(`no ${method} sent`)
  socket().answer(result, frame.id)
}

function answerGet(readScope: string, result: unknown): void {
  const frame = getFrames(readScope)[getFrames(readScope).length - 1]
  if (!frame) throw new Error(`no settings.get for ${readScope} sent`)
  socket().answer(result, frame.id)
}

/**
 * Answer the tier-file read only if the store actually sent it. The guard lets
 * this helper drive BOTH the effective-comparing store and the tier-comparing
 * one, so the two shadow legs below witness the defect red on the pre-fix
 * code and pin the fix green on the post-fix code with no other change.
 */
function answerTierIfRequested(readScope: string, result: unknown): void {
  if (getFrames(readScope).length > 0) answerGet(readScope, result)
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
    { key: 'connectTimeoutSeconds', type: 'float', group: 'Advanced', label: 'Connect timeout', riskClass: 'tuning', applies: 'restart', envVar: 'CANDY_CODE_CONNECT_TIMEOUT', sensitive: false, writableRemotely: true, projectSettable: true },
  ],
  tiers: [
    { scope: 'user', label: 'You (all projects)', path: '/h/config.json', writable: true },
    { scope: 'project', label: 'This project (local)', writable: false, refusal: 'project settings are ignored until you trust this project' },
  ],
}

// The effective answer: parallelToolCalls is true BECAUSE the user's own file
// says so — which makes the user tier's own read below its truthful twin.
const VALUES = {
  scope: 'effective',
  values: {
    parallelToolCalls: { value: true, source: 'user', sourceLabel: 'your config', shadowed: [], locked: false },
    statusLine: { value: null, source: 'default', sourceLabel: 'default', shadowed: [], locked: false },
    connectTimeoutSeconds: { value: 7, source: 'env', sourceLabel: 'environment', shadowed: [], locked: true, lockReason: 'set by CANDY_CODE_CONNECT_TIMEOUT' },
  },
  files: [{ role: 'your config', path: '/h/config.json', status: 'present', note: 'Layer 4' }],
}

// `settings.get` for a tier answers the file's OWN raw values — a key that
// the tier does not carry is simply absent.
const TIER_USER = { scope: 'user', values: { parallelToolCalls: true } }
const TIER_PROJECT = { scope: 'project', values: {} }

// The shadow world: the project file says true and outranks the user file's
// deliberate false, so effective matches the project answer.
const SHADOW_VALUES = {
  ...VALUES,
  values: {
    ...VALUES.values,
    parallelToolCalls: { value: true, source: 'project', sourceLabel: 'this project (local)', shadowed: [], locked: false },
  },
}
const SHADOW_TIER_USER = { scope: 'user', values: { parallelToolCalls: false } }
const SHADOW_TIER_PROJECT = { scope: 'project', values: { parallelToolCalls: true } }

async function loaded(
  effective: unknown = VALUES,
  userFile: unknown = TIER_USER,
  projectFile: unknown = TIER_PROJECT,
): Promise<ReturnType<typeof useServerSettingsStore>> {
  await connect()
  const settings = useServerSettingsStore()
  const loading = settings.load()
  await flush()
  answer('settings.schema', SCHEMA)
  answerGet('effective', effective)
  await flush()
  answerTierIfRequested('user', userFile)
  answerTierIfRequested('project', projectFile)
  await loading
  return settings
}

beforeEach(() => {
  setActivePinia(createPinia())
  sockets = []
})

describe('server settings store', () => {
  it('loads the schema, the tiers, the effective values and each tier file of its own', async () => {
    const settings = await loaded()
    expect(sent('settings.get')[0]?.params).toEqual({ scope: 'effective' })
    expect(sent('settings.get').map((frame) => (frame.params as { scope: string }).scope)).toEqual([
      'effective',
      'user',
      'project',
    ])
    expect(settings.loaded).toBe(true)
    expect(settings.rows.map((row) => row.key)).toEqual(['parallelToolCalls', 'statusLine', 'connectTimeoutSeconds'])
    expect(settings.tier?.path).toBe('/h/config.json')
    expect(settings.files).toHaveLength(1)
    expect(settings.editabilityOf('parallelToolCalls')).toEqual({ editable: true, reason: null })
    expect(settings.editabilityOf('statusLine').reason).toContain('runs a command')
    expect(settings.editabilityOf('connectTimeoutSeconds').reason).toBe('set by CANDY_CODE_CONNECT_TIMEOUT')
    settings.setScope('project')
    expect(settings.editabilityOf('parallelToolCalls').reason).toContain('trust this project')
  })

  it('stages edits and resets, and drops an edit back to what the tier file itself already holds', async () => {
    const settings = await loaded()
    settings.stage('parallelToolCalls', false)
    settings.stageReset('connectTimeoutSeconds')
    expect(settings.stagedCount).toBe(2)
    settings.stage('parallelToolCalls', true)
    expect(settings.staged).toEqual({ connectTimeoutSeconds: { action: 'reset' } })
    settings.unstage('connectTimeoutSeconds')
    expect(settings.dirty).toBe(false)
  })

  it('stays silent when a tier read answers nothing, and still stages the first edit', async () => {
    // A tier whose own file could not be read is UNKNOWN, and an unknown tier
    // must never silently swallow the edit the way the effective comparison
    // did: the first change to it stages, fail-open.
    const settings = await connectSilentTier()
    settings.stage('parallelToolCalls', false)
    expect(settings.staged).toEqual({ parallelToolCalls: { action: 'set', value: false } })
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
    answerGet('effective', VALUES)
    await flush()
    answerTierIfRequested('user', TIER_USER)
    answerTierIfRequested('project', TIER_PROJECT)
    await saving
    expect(settings.saved?.written).toBe('/h/config.json')
    expect(settings.dirty).toBe(false)
    expect(settings.preview).toBeNull()
    // Both loads read the effective snapshot; the save's re-read knows where
    // every tier now stands.
    expect(getFrames('effective')).toHaveLength(2)
    expect(getFrames('user')).toHaveLength(2)
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
    answer('settings.preview', { scope: 'project-shared', path: '/p/.candy-code/settings.json', canSave: true, refusals: {}, changes: [], applySummary: '', notes: [], diff: '' })
    await previewing

    const shared = { scope: 'project-shared', label: 'This project (shared)', path: '/p/.candy-code/settings.json', writable: true } as TierInfo
    const userOnly = { key: 'theme', type: 'enum', group: 'UI', label: 'Theme', riskClass: 'cosmetic', applies: 'live', sensitive: false, writableRemotely: true, projectSettable: false } as SettingRow
    expect(editability(userOnly, undefined, shared)).toEqual({ editable: false, reason: 'user-tier only: a project may not set it' })
    expect(editability({ ...userOnly, projectSettable: true }, undefined, shared)).toEqual({ editable: true, reason: null })
  })

  // The CW-QUAL-A n45 pair: stage() must weigh an edit against the tier's OWN
  // value, never the merged effective value. Both legs witness the defect red
  // on the effective-comparing store and go green together on the fix.
  it('keeps a deliberate pin: staging the effective value still writes it into a tier whose own file differs', async () => {
    const settings = await loaded(SHADOW_VALUES, SHADOW_TIER_USER, SHADOW_TIER_PROJECT)
    // The user's file says false; a higher tier says true, so effective is
    // true. Copying that true into the user tier is a deliberate pin — the
    // edit that used to look like "no change".
    settings.stage('parallelToolCalls', true)
    expect(settings.staged).toEqual({ parallelToolCalls: { action: 'set', value: true } })
    expect(settings.stagedCount).toBe(1)
  })

  it('drops the no-op: re-staging the value the tier file itself already holds still saves nothing', async () => {
    const settings = await loaded(SHADOW_VALUES, SHADOW_TIER_USER, SHADOW_TIER_PROJECT)
    // Same fixture, mirrored polarity: false IS the user file's own value,
    // and effective says something else — the anti-noop must still drop it.
    settings.stage('parallelToolCalls', false)
    expect(settings.staged).toEqual({})
    expect(settings.stagedCount).toBe(0)
  })
})

/** Load where the user tier's own read answers nothing (a failing tier read). */
async function connectSilentTier(): Promise<ReturnType<typeof useServerSettingsStore>> {
  await connect()
  const settings = useServerSettingsStore()
  const loading = settings.load()
  await flush()
  answer('settings.schema', SCHEMA)
  answerGet('effective', VALUES)
  await flush()
  if (getFrames('user').length > 0) socket().fail(-32603, 'internal', 'tier read failed', getFrames('user')[0]?.id)
  answerTierIfRequested('project', TIER_PROJECT)
  await loading
  return settings
}
