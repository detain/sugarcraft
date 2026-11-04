import { readFileSync, readdirSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * CW-UX-01/CW-UX-04: four tokens (--ink, --panel-2, --accent-soft, --danger)
 * were consumed across the app while defined nowhere, so every consumer fell
 * through to a hard-coded (theme-blind, low-contrast) var() fallback. These
 * guards make that shape impossible to re-grow: every consumed name must
 * exist, and every theme-dependent name must exist in EVERY theme block.
 */
// vitest runs from the package root.
const ROOT = resolve(process.cwd(), 'src-web')
const tokensCss = readFileSync(resolve(ROOT, 'styles/tokens.css'), 'utf8')

const themeBlockPatterns: Array<[string, RegExp]> = [
  ['dark', /:root \{([^}]*)\}/],
  ['light (media)', /:root:not\(\[data-theme='dark'\]\) \{([^}]*)\}/],
  ['light (attr)', /:root\[data-theme='light'\] \{([^}]*)\}/],
  ['dracula', /:root\[data-theme='dracula'\] \{([^}]*)\}/],
  ['tokyoNight', /:root\[data-theme='tokyoNight'\] \{([^}]*)\}/],
]

function walk(dir: string): string[] {
  const out: string[] = []
  for (const entry of readdirSync(dir)) {
    const p = resolve(dir, entry)
    if (statSync(p).isDirectory()) {
      if (entry === 'node_modules' || entry === '__tests__') continue
      out.push(...walk(p))
    } else if (entry.endsWith('.vue') || (entry.endsWith('.css') && p !== resolve(ROOT, 'styles/tokens.css'))) {
      out.push(p)
    }
  }
  return out
}

const globallyDefined = new Set([...tokensCss.matchAll(/(--[A-Za-z0-9-]+)\s*:/g)].map((m) => m[1]))

describe('token guard (CW-UX-04)', () => {
  it('every var(--x) consumed by a component is defined in tokens.css or locally', () => {
    const phantoms: string[] = []
    for (const file of walk(ROOT)) {
      const text = readFileSync(file, 'utf8')
      // Local definitions count both the CSS property form (--x: value) and
      // the inline style-object binding form ({ '--columns': ... } in
      // PaneGrid.vue); the '--' prefix keeps unrelated object keys out.
      const local = new Set([...text.matchAll(/--([A-Za-z0-9-]+)'?\s*:/g)].map((m) => `--${m[1]}`))
      for (const m of text.matchAll(/var\(\s*(--[A-Za-z0-9-]+)/g)) {
        const name = m[1] as string
        if (!globallyDefined.has(name) && !local.has(name)) phantoms.push(`${name} (consumed in ${file.slice(ROOT.length + 1)})`)
      }
    }
    expect([...new Set(phantoms)].sort(), 'tokens consumed with no definition anywhere').toEqual([])
  })

  it('each theme-dependent token is defined in every theme block', () => {
    // --canvas-paper is excluded by design: the canvas iframe renders
    // model-authored HTML that assumes white paper, so it is theme-invariant
    // and set once in :root (same rule as --sans/--mono).
    const themeDependent = ['--ink']
    for (const [theme, pattern] of themeBlockPatterns) {
      const body = tokensCss.match(pattern)?.[1]
      expect(body, `tokens.css lost the ${theme} block`).toBeTruthy()
      for (const token of themeDependent) {
        expect((body as string).includes(`${token}:`), `${token} missing from ${theme}`).toBe(true)
      }
    }
  })
})
