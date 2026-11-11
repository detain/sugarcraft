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
 * CW-DBA-16 was RETIRED (register B-23): the versioned namespace had no
 * production caller — `physical()` has always built bare-`PREFIX` names, so
 * the migration hook, the versioned sweep arms and their planted-key pins
 * were a migration story with no migration. A real flip must land as ONE
 * mechanical commit routing every raw `'candy-code-web:...'` literal (the
 * login secret in `protocol/auth.ts`, the keymap mirror, SessionTabs and the
 * specs) through `storageKey` — mirroring keymap.ts's LIVE reader-side
 * migration, which is wired and pinned, not a dormant hook again. These pins
 * fail closed if the machinery reappears before that commit does.
 */
describe('storage name routing (CW-DBA-16 retirement)', () => {
  test('storage.ts carries no versioned-prefix machinery', () => {
    const store = readFileSync(join(ROOT, 'stores/storage.ts'), 'utf8')
    expect(store).not.toMatch(/VERSIONED_PREFIX|migrateUnversionedKeys|candy-code-web:v1:/)
  })

  test('the prompt-history ring derives its storage name — no full literal left in the file', () => {
    const ring = readFileSync(join(ROOT, 'stores/promptHistory.ts'), 'utf8')
    expect(ring).not.toMatch(/'candy-code-web:[A-Za-z]/)
  })
})
