import { describe, expect, it } from 'vitest'
import {
  appliesLabel,
  changeSet,
  choices,
  displayValue,
  editability,
  fieldKind,
  groupRows,
  inputText,
  parseInput,
  provenance,
  sameValue,
  type SettingRow,
  type TierInfo,
} from '../fields'

function row(overrides: Partial<SettingRow> = {}): SettingRow {
  return {
    key: 'parallelToolDeadlineSeconds',
    type: 'int',
    group: 'Tools',
    label: 'Parallel deadline',
    riskClass: 'tuning',
    applies: 'next-turn',
    appliesLabel: 'next turn',
    sensitive: false,
    writableRemotely: true,
    projectSettable: true,
    ...overrides,
  }
}

const USER: TierInfo = { scope: 'user', label: 'You (all projects)', path: '/h/config.json', writable: true }
const PROJECT: TierInfo = { scope: 'project', label: 'This project (local)', writable: true }

describe('editability', () => {
  it('lets a writable, unlocked key be edited on a writable tier', () => {
    expect(editability(row(), { value: 30, source: 'default', locked: false }, USER)).toEqual({ editable: true, reason: null })
  })

  it('names the first reason a field is read-only, in the order a user wants to hear them', () => {
    expect(editability(row({ writableRemotely: false, remoteRefusal: 'runs a command: not writable remotely' }), undefined, USER).reason).toBe('runs a command: not writable remotely')
    expect(editability(row(), { value: 7, source: 'env', locked: true, lockReason: 'set by SUGARCRUSH_X' }, USER).reason).toBe('set by SUGARCRUSH_X')
    expect(editability(row(), undefined, { ...PROJECT, writable: false, refusal: 'project settings are ignored until you trust this project' }).reason).toContain('trust this project')
    expect(editability(row({ projectSettable: false }), undefined, PROJECT).reason).toBe('user-tier only: a project may not set it')
    expect(editability(row({ projectSettable: false }), undefined, USER).editable).toBe(true)
    expect(editability(row(), undefined, undefined).editable).toBe(false)
  })
})

describe('fieldKind and parseInput', () => {
  it('picks the control the type asks for', () => {
    expect(fieldKind(row({ type: 'bool' }))).toBe('bool')
    expect(fieldKind(row({ type: 'float' }))).toBe('number')
    expect(fieldKind(row({ type: 'enum', enum: ['auto', 'off'] }))).toBe('select')
    expect(fieldKind(row({ type: 'string', options: ['dark', 'light'] }))).toBe('select')
    expect(fieldKind(row({ type: 'list' }))).toBe('list')
    expect(fieldKind(row({ type: 'map' }))).toBe('json')
    expect(fieldKind(row({ type: 'json' }))).toBe('json')
    expect(fieldKind(row({ type: 'string' }))).toBe('text')
    expect(choices(row({ type: 'enum', enum: ['a', 'b'] }))).toEqual(['a', 'b'])
  })

  it('refuses nonsense numbers rather than sending them', () => {
    const bounded = row({ min: 1, max: 600 })
    expect(parseInput(bounded, '40')).toEqual({ ok: true, value: 40 })
    expect(parseInput(bounded, '')).toEqual({ ok: false, error: 'enter a number' })
    expect(parseInput(bounded, 'many')).toEqual({ ok: false, error: 'not a number' })
    expect(parseInput(bounded, '1.5')).toEqual({ ok: false, error: 'a whole number' })
    expect(parseInput(bounded, '0')).toEqual({ ok: false, error: 'between 1 and 600' })
    expect(parseInput(row({ type: 'float', min: 0 }), '-1')).toEqual({ ok: false, error: 'at least 0' })
    expect(parseInput(row({ type: 'float' }), '2.5')).toEqual({ ok: true, value: 2.5 })
  })

  it('reads lists one per line, JSON strictly, and a choice only from its options', () => {
    expect(parseInput(row({ type: 'list' }), ' WebFetch \n\nBash\n')).toEqual({ ok: true, value: ['WebFetch', 'Bash'] })
    expect(parseInput(row({ type: 'json' }), '{"a": 1}')).toEqual({ ok: true, value: { a: 1 } })
    expect(parseInput(row({ type: 'json' }), '{a')).toEqual({ ok: false, error: 'not valid JSON' })
    expect(parseInput(row({ type: 'enum', enum: ['auto', 'off'] }), 'auto')).toEqual({ ok: true, value: 'auto' })
    expect(parseInput(row({ type: 'enum', enum: ['auto', 'off'] }), 'on').ok).toBe(false)
    expect(parseInput(row({ type: 'bool' }), false)).toEqual({ ok: true, value: false })
  })
})

describe('display', () => {
  it('turns values into field text and one-line readouts', () => {
    expect(inputText(row({ type: 'list' }), ['a', 'b'])).toBe('a\nb')
    expect(inputText(row({ type: 'json' }), { a: 1 })).toBe('{\n  "a": 1\n}')
    expect(inputText(row(), null)).toBe('')
    expect(displayValue(undefined)).toBe('unset')
    expect(displayValue(true)).toBe('on')
    expect(displayValue([])).toBe('(none)')
    expect(displayValue(['a', 'b'])).toBe('a, b')
    expect(displayValue({ a: 1 })).toBe('{"a":1}')
    expect(appliesLabel({ applies: 'restart' })).toBe('restart')
    expect(appliesLabel({ applies: 'frozen' })).toBe('next launch')
    expect(appliesLabel({ applies: 'live', appliesLabel: 'live' })).toBe('live')
  })

  it('says where a value comes from, naming the lock when there is one', () => {
    expect(provenance(undefined)).toBe('default')
    expect(provenance({ value: 1, source: 'user-config', sourceLabel: 'config.json', locked: false })).toBe('config.json')
    expect(provenance({ value: 1, source: 'env', sourceLabel: 'environment', locked: true, lockReason: 'set by SUGARCRUSH_X' })).toBe('set by SUGARCRUSH_X')
  })

  it('compares values deeply', () => {
    expect(sameValue(['a'], ['a'])).toBe(true)
    expect(sameValue(undefined, null)).toBe(true)
    expect(sameValue(1, '1')).toBe(false)
  })
})

describe('groupRows and changeSet', () => {
  it('groups in schema order, leaves hidden rows out, and filters on key, label and help', () => {
    const rows = [
      row({ key: 'a', group: 'Model' }),
      row({ key: 'b', group: 'Tools', help: 'how long a batch may run' }),
      row({ key: 'c', group: 'Model', label: 'Theme' }),
      row({ key: 'd', group: 'UI', ui: 'hidden' }),
    ]
    expect(groupRows(rows).map((g) => [g.group, g.rows.map((r) => r.key)])).toEqual([['Model', ['a', 'c']], ['Tools', ['b']]])
    expect(groupRows(rows, 'BATCH').map((g) => g.group)).toEqual(['Tools'])
    expect(groupRows(rows, 'theme').flatMap((g) => g.rows.map((r) => r.key))).toEqual(['c'])
  })

  it('splits staged edits into the set / unset pair the server takes', () => {
    expect(changeSet({ a: { action: 'set', value: false }, b: { action: 'reset' } })).toEqual({ set: { a: false }, unset: ['b'] })
    expect(changeSet({})).toEqual({ set: {}, unset: [] })
  })
})
