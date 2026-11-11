import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * UXB-F18 (n57): five aria-modal backdrops used to share one literal
 * `z-index: 40`, so two simultaneous dialogs stacked by DOM order, not by
 * policy — and two ARE simultaneous by design: the help chord is
 * document-level and answers from anywhere outside a field (App.vue
 * onHelpKey), so Help can open over the palette, the lightbox, or About.
 * The fix is the ladder minted in tokens.css (--z-dialog / --z-picker /
 * --z-help) consumed at every overlay root. Source-scan shape (same
 * discipline as chromeTokens.spec.ts): vitest's happy-dom mount does not
 * apply scoped SFC styles, so the ladder is pinned where it is written —
 * in the component stylesheets themselves.
 */

const HERE = process.cwd() // npm test runs from the package root

function read(rel: string): string {
  return readFileSync(join(HERE, rel), 'utf8')
}

/** The z-index value inside one class rule, fail-loud if either is missing. */
function zValue(rel: string, selector: RegExp): string {
  const rule = selector.exec(read(rel))
  if (rule?.[1] === undefined) {
    throw new Error(`${rel}: rule ${selector} not found — renamed? the pin must go red, not vacuous`)
  }
  const z = /z-index:\s*([^;]+)/.exec(rule[1])
  if (z?.[1] === undefined) {
    throw new Error(`${rel}: the overlay rule lost its z-index — the tie family may be re-opening`)
  }
  return z[1].trim()
}

/** The numeric body of one ladder token from the shared sheet. */
function tokenValue(tokens: string, name: string): number {
  const hit = new RegExp(`--${name}:\\s*(\\d+);`).exec(tokens)
  if (hit?.[1] === undefined) throw new Error(`token --${name} not minted — the pin must go red, not vacuous`)
  return Number(hit[1])
}

const backdrop = /\.backdrop\s*\{([^}]*)\}/

/** The mutually-exclusive command/content band. */
const DIALOG_BAND: ReadonlyArray<readonly [string, RegExp]> = [
  ['src-web/components/HistorySearch.vue', backdrop],
  ['src-web/components/panels/CommandPalette.vue', backdrop],
  ['src-web/components/MediaCard.vue', /\.lightbox\s*\{([^}]*)\}/],
  ['src-web/components/AboutModal.vue', backdrop],
]

const LADDER_FILES: ReadonlyArray<string> = [
  ...DIALOG_BAND.map(([rel]) => rel),
  'src-web/components/DirectoryPicker.vue',
  'src-web/components/HelpOverlay.vue',
]

describe('dialog layer ladder (UXB-F18 / n57)', () => {
  const tokens = read('src-web/styles/tokens.css')

  it('the ladder stays minted, tiered, and strictly ordered', () => {
    expect(tokenValue(tokens, 'z-dialog')).toBe(40)
    expect(tokenValue(tokens, 'z-picker')).toBe(45)
    expect(tokenValue(tokens, 'z-help')).toBe(50)
    expect(tokenValue(tokens, 'z-picker')).toBeGreaterThan(tokenValue(tokens, 'z-dialog'))
    expect(tokenValue(tokens, 'z-help')).toBeGreaterThan(tokenValue(tokens, 'z-picker'))
  })

  it('the mutually-exclusive content band rides --z-dialog', () => {
    for (const [rel, selector] of DIALOG_BAND) {
      expect(zValue(rel, selector), rel).toBe('var(--z-dialog)')
    }
  })

  it('the directory picker covers the band it opens from', () => {
    expect(zValue('src-web/components/DirectoryPicker.vue', backdrop)).toBe('var(--z-picker)')
  })

  it('help sits on top — it explains the dialogs it overlays', () => {
    expect(zValue('src-web/components/HelpOverlay.vue', backdrop)).toBe('var(--z-help)')
  })

  it('no member of the ladder carries a bare literal again', () => {
    for (const rel of LADDER_FILES) {
      expect(read(rel), rel).not.toMatch(/z-index:\s*\d+/)
    }
  })
})
