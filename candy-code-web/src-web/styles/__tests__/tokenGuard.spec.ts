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
    // CW-UX-02: the 16-entry ANSI palette plus its readable-black leg —
    // every theme must carry the full set, or a tail pane renders SGR
    // colours through a fallback (or nothing) on that theme.
    const themeDependent = [
      '--ink',
      '--ansi-black-fg',
      ...Array.from({ length: 16 }, (_, i) => `--ansi-${i}`),
    ]
    for (const [theme, pattern] of themeBlockPatterns) {
      const body = tokensCss.match(pattern)?.[1]
      expect(body, `tokens.css lost the ${theme} block`).toBeTruthy()
      for (const token of themeDependent) {
        expect((body as string).includes(`${token}:`), `${token} missing from ${theme}`).toBe(true)
      }
    }
  })

  it('paints the ANSI rules from tokens, never literal hex (CW-UX-02)', () => {
    // The 24 :deep(.ansi-fg-*/.ansi-bg-*) rules carry the content palette;
    // a hex literal there is theme-blind by construction — that was the bug.
    const vue = readFileSync(resolve(ROOT, 'components/panels/ProcessesSection.vue'), 'utf8')
    const rules = [...vue.matchAll(/:deep\(\.ansi-(?:fg|bg)-[a-z-]+\) \{[^}]*\}/g)].map((m) => m[0])
    expect(rules.length, 'the ANSI style block moved or shrank').toBe(24)
    const strays = rules.filter((rule) => /#[0-9a-fA-F]{3,8}\b/.test(rule))
    expect(strays, `literal hex in ANSI rules: ${strays.join(' | ')}`).toEqual([])
    const untokens = rules.filter((rule) => !rule.includes('var(--ansi-'))
    expect(untokens, `ANSI rules not driven by --ansi-*: ${untokens.join(' | ')}`).toEqual([])
  })

  it('keeps lane-A component colors token-driven (CW-UX-04/05/17)', () => {
    // Lane A (reviews-1103) converted the canvas/panel/grid components to
    // theme tokens. A literal hex — or a hex-bearing var() fallback — re-
    // entering one of these files resurrects the theme-blind rendering the
    // CW-UX-01/02/04/05 cluster fixed. Font-family fallbacks (var(--mono, …))
    // are NOT colors and stay allowed.
    const owned = [
      'components/CanvasCard.vue',
      'components/panels/GeneratePanel.vue',
      'components/panels/UsagePanel.vue',
      'components/panels/ChangesPanel.vue',
      'components/panels/FilesPanel.vue',
      'components/panels/ContextPanel.vue',
      'components/panels/SidePanels.vue',
      'components/panels/ProcessesSection.vue',
      'components/grid/SessionTabs.vue',
    ]
    const hexes: string[] = []
    const fallbacks: string[] = []
    for (const rel of owned) {
      const text = readFileSync(resolve(ROOT, rel), 'utf8')
      const style = (text.match(/<style[^>]*>[\s\S]*?<\/style>/g) ?? []).join('\n')
      const code = style.replace(/\/\*[\s\S]*?\*\//g, '')
      for (const m of code.matchAll(/#[0-9a-fA-F]{3,8}\b/g)) hexes.push(`${rel}: ${m[0]}`)
      for (const m of code.matchAll(/var\(\s*--[A-Za-z0-9-]+\s*,[^)]*#[^)]*\)/g)) fallbacks.push(`${rel}: ${m[0]}`)
    }
    expect(hexes, 'literal hex inside a lane-A style block').toEqual([])
    expect(fallbacks, 'hex-bearing var() fallbacks (dead weight masking a token gap)').toEqual([])
  })

  it('paints the eleven context categories from the --ctx-* family (CW-UX-17)', () => {
    const vue = readFileSync(resolve(ROOT, 'components/panels/ContextPanel.vue'), 'utf8')
    const block = vue.match(/const CATEGORY_COLORS[^=]*=\s*\{([\s\S]*?)\n\}/)?.[1]
    expect(block, 'CATEGORY_COLORS moved out of ContextPanel').toBeTruthy()
    const entries = [...(block as string).matchAll(/'?([a-z-]+)'?\s*:\s*'([^']+)'/g)].map((m) => [m[1], m[2]] as const)
    expect(entries.length, 'the category roster changed size').toBe(11)
    for (const [key, value] of entries) {
      expect(value, `category ${key} is not painted from its --ctx-* token`).toBe(`var(--ctx-${key})`)
      const name = `--ctx-${key}`
      const declarations = [...tokensCss.matchAll(new RegExp(`^\\s*${name}:\\s*(#[0-9a-fA-F]{6});$`, 'gm'))].map((m) => m[1])
      // dark + the two light blocks; dracula/tokyoNight are dark surfaces
      // and inherit the dark set by design (tokens.css carries the comment).
      expect(declarations.length, `${name} must be declared once in dark and once in each light block`).toBe(3)
      expect(declarations[1], `the two ${name} light-block values drifted apart`).toBe(declarations[2])
    }
  })

  it('holds the invariant geometry zone and the un-dimmed resting tab', () => {
    // CW-UX-19/21/25/11: geometry, typography and width tokens are not
    // colors — each lives exactly once in the :root invariant zone. A second
    // declaration means someone painted a layout token by theme.
    for (const name of [
      '--sans', '--mono', '--canvas-paper',
      '--panels-width', '--chrome-height', '--chrome-reserve', '--fs-small',
      '--radius-1', '--radius-2', '--radius-3', '--shadow-1', '--shadow-2',
    ]) {
      const declared = (tokensCss.match(new RegExp(`^\\s*${name}:`, 'gm')) ?? []).length
      expect(declared, `${name} declared outside its single invariant home`).toBe(1)
    }
    // CW-UX-06: resting tabs dim through an explicit muted color, never
    // through opacity — opacity washed every layer under the tab, the close
    // glyph included (measured 3.19-3.91:1 before the fix).
    const tabs = readFileSync(resolve(ROOT, 'components/grid/SessionTabs.vue'), 'utf8')
    const tabRule = tabs.match(/\.tab \{[^}]*\}/)?.[0]
    expect(tabRule, '.tab rule moved or renamed').toBeTruthy()
    expect(tabRule).toContain('color: var(--muted)')
    expect(tabRule).not.toMatch(/opacity:/)
    const activeRule = tabs.match(/\.tab\.active \{[^}]*\}/)?.[0]
    expect(activeRule, '.tab.active rule moved or renamed').toBeTruthy()
    expect(activeRule).toContain('color: var(--text)')
    expect(activeRule).not.toMatch(/opacity:/)
  })
})
