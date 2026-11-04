/**
 * The README's "Browser storage" table is the product's promise about what
 * this UI keeps on the machine. A promise nobody checks is how MA-13 happened:
 * ~15 keys shipped across 7 stores and the docs named two. This spec derives
 * the persisted-key set FROM THE SOURCES (every `readJson`/`writeJson`/
 * `eraseKey`/`storageKey` literal, same-file `const` indirections, and the
 * fully-prefixed literals stores hold for raw `localStorage` access) and
 * demands set-equality with the table's key column, both directions: a new
 * store must add a row, and a retired key may not leave a ghost row behind.
 *
 * The floor assert protects the parsers themselves: if harvesting or table
 * reading ever returns a husk, the equality could vacuously pass — so the
 * derived set must also stand at its measured minimum.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, test } from 'vitest'
import { migrateUnversionedKeys } from '../stores/storage'

/** Every file under src-web except generated mirrors and the tests. */
function sourcesUnder(dir: string): string[] {
  const out: string[] = []
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry)
    if (entry === '__tests__' || entry === 'node_modules') continue
    if (statSync(full).isDirectory()) out.push(...sourcesUnder(full))
    else if (/\.([cm]js|ts|vue)$/.test(entry) && !entry.endsWith('.generated.ts')) out.push(full)
  }
  return out
}

const ROOT = resolve(process.cwd(), 'src-web')

/** 'drafts'-style names passed to the storage.ts helpers, incl. const refs. */
function harvestedHelperKeys(source: string): string[] {
  const keys: string[] = []
  for (const match of source.matchAll(/(?:readJson|writeJson|eraseKey|storageKey)(?:<[^>]*>)?\(\s*(?:'([^']+)'|([A-Z][A-Z0-9_]*))/g)) {
    if (match[1]) keys.push(match[1])
    else if (match[2]) {
      const decl = new RegExp(`const\\s+${match[2]}\\s*=\\s*'([^']+)'`).exec(source)
      // A prefixed constant (a raw-localStorage name) is handled by the
      // direct-key leg; an unprefixed one is a helper key by another route.
      if (decl?.[1]) keys.push(decl[1].replace(/^candy-code-web:/, ''))
    }
  }
  return keys
}

/** Fully-prefixed literals written to raw localStorage (auth, history ring). */
function directPrefixedKeys(source: string): string[] {
  return [...source.matchAll(/'candy-code-web:([A-Za-z0-9._-]+)'/g)].flatMap((m) => (m[1] ? [m[1]] : []))
}

function derivedKeys(): string[] {
  const keys = new Set<string>()
  for (const file of sourcesUnder(ROOT)) {
    const source = readFileSync(file, 'utf8')
    if (/stores\/storage\.ts$/.test(file)) continue // the helper definitions
    for (const key of [...harvestedHelperKeys(source), ...directPrefixedKeys(source)]) keys.add(key)
  }
  return [...keys].sort()
}

/** The key column of the README's Browser storage table, cells split on '/'. */
function documentedKeys(): string[] {
  const readme = readFileSync(resolve(process.cwd(), 'README.md'), 'utf8')
  const section = readme.split('### Browser storage')[1]?.split(/\n##\s/)[0]
  if (!section) throw new Error('README.md lost its "Browser storage" section')
  const keys: string[] = []
  for (const line of section.split('\n')) {
    if (!line.trim().startsWith('|')) continue
    const cell = line.split('|')[1]?.trim()
    if (!cell || cell === 'Key' || /^-+$/.test(cell)) continue
    for (const name of cell.split('/')) {
      const bare = name.trim().replace(/`/g, '').replace(/\s*\(.*\)\s*$/, '')
      if (bare) keys.push(bare)
    }
  }
  return [...new Set(keys)].sort()
}

describe('browser storage inventory (MA-13)', () => {
  test('the harvest sees the shipped set (parser self-protection)', () => {
    expect(derivedKeys().length).toBeGreaterThanOrEqual(16)
    expect(documentedKeys().length).toBeGreaterThanOrEqual(16)
  })

  test('every persisted key has a README row, and every row is a live key', () => {
    const derived = derivedKeys()
    const documented = documentedKeys()
    const undocumented = derived.filter((k) => !documented.includes(k))
    const ghost = documented.filter((k) => !derived.includes(k))
    expect(undocumented, `keys persisted without a Browser storage row: ${undocumented.join(', ')}`).toEqual([])
    expect(ghost, `Browser storage rows whose key no longer ships: ${ghost.join(', ')}`).toEqual([])
  })
})

/**
 * CW-DBA-16: the storage namespace gets its migration HOOK here, but the
 * PREFIX FLIP itself is held — raw `'candy-code-web:...'` literals outside
 * this wrapper (the login secret in `protocol/auth.ts`, the keymap mirror,
 * SessionTabs and a dozen specs) would strand mid-flip. The sweep is
 * therefore deliberately per-key: it moves ONLY the suffixes it is handed,
 * never clobbers a destination that already answers, and is idempotent —
 * so a partial fleet sweep is safe by construction, and the flip lands as
 * one mechanical commit once every literal is routed through `storageKey`.
 */
describe('unversioned key migration (CW-DBA-16)', () => {
  test('listed keys move under the versioned prefix and the old name is gone', () => {
    localStorage.clear()
    localStorage.setItem('candy-code-web:drafts', '{"s1":"typed"}')
    localStorage.setItem('candy-code-web:promptHistory', '["a prompt"]')
    migrateUnversionedKeys(['drafts', 'promptHistory'])
    expect(localStorage.getItem('candy-code-web:v1:drafts')).toBe('{"s1":"typed"}')
    expect(localStorage.getItem('candy-code-web:v1:promptHistory')).toBe('["a prompt"]')
    expect(localStorage.getItem('candy-code-web:drafts')).toBeNull()
    expect(localStorage.getItem('candy-code-web:promptHistory')).toBeNull()
  })

  test('an existing destination wins — the sweep consumes the old copy, never overwrites', () => {
    localStorage.clear()
    localStorage.setItem('candy-code-web:drafts', '{"stale":"unversioned"}')
    localStorage.setItem('candy-code-web:v1:drafts', '{"kept":"versioned"}')
    migrateUnversionedKeys(['drafts'])
    expect(localStorage.getItem('candy-code-web:v1:drafts')).toBe('{"kept":"versioned"}')
    expect(localStorage.getItem('candy-code-web:drafts')).toBeNull()
  })

  test('keys that were not listed stay exactly where their owners put them', () => {
    localStorage.clear()
    localStorage.setItem('candy-code-web:keymap', '{"legacy":"a lane E/F literal"}')
    migrateUnversionedKeys(['drafts'])
    expect(localStorage.getItem('candy-code-web:keymap')).toBe('{"legacy":"a lane E/F literal"}')
    expect(localStorage.getItem('candy-code-web:v1:keymap')).toBeNull()
    localStorage.clear()
  })

  test('sweeping twice moves nothing and throws nothing', () => {
    localStorage.clear()
    localStorage.setItem('candy-code-web:drafts', '{"once":true}')
    migrateUnversionedKeys(['drafts'])
    expect(() => migrateUnversionedKeys(['drafts'])).not.toThrow()
    expect(localStorage.getItem('candy-code-web:v1:drafts')).toBe('{"once":true}')
    expect(localStorage.getItem('candy-code-web:drafts')).toBeNull()
    localStorage.clear()
  })

  test('blocked storage skips the sweep silently', () => {
    localStorage.clear()
    localStorage.setItem('candy-code-web:drafts', '{"the":"payload"}')
    // A real Storage instance so the wrapper's own door still passes, with a
    // getItem that always throws (private mode / cleared site data).
    const blocked = Object.create(Object.getPrototypeOf(localStorage)) as Storage
    blocked.getItem = (): string => {
      throw new Error('storage is blocked')
    }
    const original = Object.getOwnPropertyDescriptor(globalThis, 'localStorage')
    Object.defineProperty(globalThis, 'localStorage', { value: blocked, configurable: true, writable: true })
    try {
      expect(() => migrateUnversionedKeys(['drafts'])).not.toThrow()
    } finally {
      if (original) Object.defineProperty(globalThis, 'localStorage', original)
    }
    // Restored: the planted key was never touched by the blocked sweep.
    expect(localStorage.getItem('candy-code-web:drafts')).toBe('{"the":"payload"}')
    expect(localStorage.getItem('candy-code-web:v1:drafts')).toBeNull()
    localStorage.clear()
  })

  test('the prompt-history ring derives its storage name — no full literal left in the file', () => {
    const ring = readFileSync(join(ROOT, 'stores/promptHistory.ts'), 'utf8')
    expect(ring).not.toMatch(/'candy-code-web:[A-Za-z]/)
  })
})
