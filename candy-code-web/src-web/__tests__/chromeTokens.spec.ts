import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * UX-11: the tab strip's right reserve and the side panels' opener offset are
 * two halves of one coupling — the opener floats over the strip, so its top
 * must equal the chrome height and the strip must reserve its width. The UXA
 * pass minted `--chrome-height` / `--chrome-reserve` in tokens.css; this guard
 * pins the shell styles to CONSUME them: a re-spread of the bare 3.4rem / 7rem
 * literals would let the two halves drift apart again in separate edits.
 *
 * Source-scan shape (same discipline as rawCopyCensus.spec.ts): vitest's
 * happy-dom mount does not apply scoped SFC styles, so the coupling is pinned
 * where it is written — in the stylesheets themselves.
 */

const HERE = process.cwd() // npm test runs from the package root

function read(rel: string): string {
  return readFileSync(join(HERE, rel), 'utf8')
}

/** The body of a single class rule, by name, from a stylesheet source. */
function ruleBody(src: string, selector: RegExp): string {
  const hit = selector.exec(src)
  if (hit?.[1] === undefined) throw new Error(`rule ${selector} not found — renamed? the pin must go red, not vacuous`)
  return hit[1]
}

describe('chrome-reserve token consumption (UX-11)', () => {
  const tokens = read('src-web/styles/tokens.css')
  const strip = ruleBody(read('src-web/components/grid/SessionTabs.vue'), /\.tabs-strip\s*\{([^}]*)\}/)
  const opener = ruleBody(read('src-web/components/panels/SidePanels.vue'), /\.opener\s*\{([^}]*)\}/)

  it('the tokens stay minted in the shared sheet', () => {
    expect(tokens).toMatch(/--chrome-height:\s*3\.4rem/)
    expect(tokens).toMatch(/--chrome-reserve:\s*7rem/)
  })

  it('the tab strip reserves through the token, never a bare 7rem', () => {
    expect(strip).toContain('var(--chrome-reserve)')
    expect(strip).not.toMatch(/7rem/)
  })

  it('the opener offsets through the token, never a bare 3.4rem', () => {
    expect(opener).toContain('var(--chrome-height)')
    expect(opener).not.toMatch(/3\.4rem/)
  })
})
