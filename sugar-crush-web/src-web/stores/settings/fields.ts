import type { MethodResult } from '../../protocol/generated'

/** One setting, as `settings.schema` describes it. */
export type SettingRow = MethodResult<'settings.schema'>['items'][number]

/** A tier a save can target, as `settings.schema` lists them. */
export type TierInfo = NonNullable<MethodResult<'settings.schema'>['tiers']>[number]

export type TierScope = TierInfo['scope']

export type SettingsFile = NonNullable<MethodResult<'settings.get'>['files']>[number]

export type SavePreview = MethodResult<'settings.preview'>

export type SaveResult = MethodResult<'settings.set'>

/** A key's effective value and where it came from (`settings.get`, scope effective). */
export interface EffectiveValue {
  value: unknown
  source: string
  sourceLabel?: string
  sourcePath?: string
  shadowed?: string[]
  locked: boolean
  lockReason?: string
}

/** A change waiting for the save: a new value, or a reset (the save deletes the key). */
export type Staged = { action: 'set'; value: unknown } | { action: 'reset' }

export type FieldKind = 'bool' | 'number' | 'select' | 'list' | 'json' | 'text'

export type Parsed = { ok: true; value: unknown } | { ok: false; error: string }

const APPLY_FALLBACK: Record<string, string> = {
  live: 'live',
  'next-turn': 'next turn',
  restart: 'restart',
  frozen: 'next launch',
}

/** The apply-mode badge text: the server's own label, else the mode spelled out. */
export function appliesLabel(row: Pick<SettingRow, 'applies' | 'appliesLabel'>): string {
  return row.appliesLabel ?? APPLY_FALLBACK[row.applies] ?? row.applies
}

/**
 * Whether the form may edit `row` on `tier`, and the reason it may not —
 * shown beside the disabled field. In the order a user would want to hear
 * them: a key no client may write, a key the environment or a flag pins (a
 * save would be outranked), a tier that cannot be written, and a key a
 * project tier (local or shared) may not hold.
 */
export function editability(
  row: SettingRow,
  effective: EffectiveValue | undefined,
  tier: TierInfo | undefined,
): { editable: boolean; reason: string | null } {
  if (!row.writableRemotely) return { editable: false, reason: row.remoteRefusal ?? 'not writable over the wire' }
  if (effective?.locked) return { editable: false, reason: effective.lockReason ?? 'locked by the environment or a flag' }
  if (tier === undefined || !tier.writable) return { editable: false, reason: tier?.refusal ?? 'this tier cannot be written' }
  // Both project tiers — the local file and the committed shared one — take
  // only the keys a project may set.
  if (tier.scope !== 'user' && !row.projectSettable) return { editable: false, reason: 'user-tier only: a project may not set it' }
  return { editable: true, reason: null }
}

/** Which control a row gets. */
export function fieldKind(row: SettingRow): FieldKind {
  if (row.type === 'bool') return 'bool'
  if (row.type === 'int' || row.type === 'float') return 'number'
  if ((row.options?.length ?? 0) > 0 || (row.enum?.length ?? 0) > 0) return 'select'
  if (row.type === 'list') return 'list'
  if (row.type === 'map' || row.type === 'json') return 'json'
  return 'text'
}

/** The choices a pick-one field offers. */
export function choices(row: SettingRow): string[] {
  if ((row.options?.length ?? 0) > 0) return row.options ?? []
  return (row.enum ?? []).map((value) => String(value))
}

/**
 * A field's text, as the user typed it, turned into the value a save sends —
 * or why it cannot be. Advisory: the server's writer validates again and has
 * the last word, but nonsense is refused here rather than sent.
 */
export function parseInput(row: SettingRow, raw: string | boolean): Parsed {
  const kind = fieldKind(row)
  if (kind === 'bool') return { ok: true, value: raw === true || raw === 'true' }
  const text = String(raw)
  switch (kind) {
    case 'number': {
      const trimmed = text.trim()
      if (trimmed === '') return { ok: false, error: 'enter a number' }
      const value = Number(trimmed)
      if (!Number.isFinite(value)) return { ok: false, error: 'not a number' }
      if (row.type === 'int' && !Number.isInteger(value)) return { ok: false, error: 'a whole number' }
      if (row.min !== undefined && value < row.min) return { ok: false, error: row.max !== undefined ? `between ${row.min} and ${row.max}` : `at least ${row.min}` }
      if (row.max !== undefined && value > row.max) return { ok: false, error: row.min !== undefined ? `between ${row.min} and ${row.max}` : `at most ${row.max}` }
      return { ok: true, value }
    }
    case 'select': {
      const options = choices(row)
      return options.includes(text) ? { ok: true, value: text } : { ok: false, error: `one of ${options.join(', ')}` }
    }
    case 'list':
      return { ok: true, value: text.split('\n').map((line) => line.trim()).filter((line) => line !== '') }
    case 'json': {
      if (text.trim() === '') return { ok: false, error: 'enter JSON, or reset the setting' }
      try {
        return { ok: true, value: JSON.parse(text) as unknown }
      } catch {
        return { ok: false, error: 'not valid JSON' }
      }
    }
    default:
      return { ok: true, value: text }
  }
}

/** A value as the field's editable text. */
export function inputText(row: SettingRow, value: unknown): string {
  if (value === undefined || value === null) return ''
  switch (fieldKind(row)) {
    case 'list':
      return Array.isArray(value) ? value.map((item) => String(item)).join('\n') : String(value)
    case 'json':
      return JSON.stringify(value, null, 2)
    default:
      return typeof value === 'object' ? JSON.stringify(value) : String(value)
  }
}

/** A value on one line, for the field's "now" readout and the preview. */
export function displayValue(value: unknown): string {
  if (value === undefined || value === null) return 'unset'
  if (typeof value === 'boolean') return value ? 'on' : 'off'
  if (Array.isArray(value)) return value.length === 0 ? '(none)' : value.map((item) => (typeof item === 'string' ? item : JSON.stringify(item))).join(', ')
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

/** Whether two values are the same setting value (deep, key order included). */
export function sameValue(a: unknown, b: unknown): boolean {
  return JSON.stringify(a ?? null) === JSON.stringify(b ?? null)
}

/** The rows a form shows, grouped as the schema orders them; hidden rows left out. */
export function groupRows(rows: SettingRow[], filter = ''): { group: string; rows: SettingRow[] }[] {
  const needle = filter.trim().toLowerCase()
  const groups = new Map<string, SettingRow[]>()
  for (const row of rows) {
    if (row.ui === 'hidden') continue
    if (needle !== '' && ![row.key, row.label, row.help ?? '', row.group].some((text) => text.toLowerCase().includes(needle))) continue
    const list = groups.get(row.group) ?? []
    list.push(row)
    groups.set(row.group, list)
  }
  return [...groups].map(([group, list]) => ({ group, rows: list }))
}

/** The staged edits as the `set` / `unset` pair `settings.preview` and `settings.set` take. */
export function changeSet(staged: Record<string, Staged>): { set: Record<string, unknown>; unset: string[] } {
  const set: Record<string, unknown> = {}
  const unset: string[] = []
  for (const [key, change] of Object.entries(staged)) {
    if (change.action === 'reset') unset.push(key)
    else set[key] = change.value
  }
  return { set, unset }
}

/** Where a value comes from, as a field's provenance badge says it. */
export function provenance(effective: EffectiveValue | undefined): string {
  if (effective === undefined) return 'default'
  if (effective.locked && effective.lockReason) return effective.lockReason
  return effective.sourceLabel ?? effective.source
}
