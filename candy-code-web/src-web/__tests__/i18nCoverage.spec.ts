import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { WEB_STRINGS_EN } from '../lib/strings.en.generated'

/**
 * C-TUI-6.f: every `t('web.…')` literal rendered anywhere in the app must
 * exist in the committed English bundle — a typo'd or dropped key would fall
 * back to the raw key on screen, silently. C-TUI-6.g (the locale switcher)
 * leans on this guard: it can only trust `t()` when the key set is proven.
 */
// vitest runs from the package root (npm test); the app sources live under
// src-web/. import.meta.url is NOT usable here — the dev-server rewrites it
// root-relative ('/src-web/…'), which resolves to no real directory.
const ROOT = join(process.cwd(), 'src-web')

function sourceFiles(dir: string): string[] {
  const found: string[] = []
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name)
    if (entry.isDirectory()) {
      if (entry.name !== '__tests__') found.push(...sourceFiles(path))
    } else if (entry.name.endsWith('.vue') || (entry.name.endsWith('.ts') && !entry.name.endsWith('.generated.ts'))) {
      found.push(path)
    }
  }
  return found
}

describe('chrome string coverage (C-TUI-6.f)', () => {
  const files = sourceFiles(ROOT)
  const used: string[] = []
  for (const file of files) {
    const text = readFileSync(file, 'utf8')
    for (const match of text.matchAll(/\bt\(\s*(['"])(web\.[^'"]+)\1/g)) {
      const key = match[2]
      if (key === undefined) throw new Error(`t() call site in ${file} matched no key`)
      used.push(key)
    }
  }

  it('scans the live tree (a scanner that found nothing guards nothing)', () => {
    expect(files.length).toBeGreaterThan(20)
    // The chrome migration itself lands well over 40 distinct call sites; a
    // regression in the walk or the regex must trip this, not pass vacuous.
    expect(new Set(used).size).toBeGreaterThan(40)
  })

  it('renders only keys the English bundle carries', () => {
    const missing = [...new Set(used)].filter((key) => !(key in WEB_STRINGS_EN))
    expect(missing).toEqual([])
  })
})
