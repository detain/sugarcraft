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

/**
 * N53/m48: the transcript's jump pill. Its seat was a float:right riding on a
 * position:sticky box — a combination engines resolve differently, which is
 * exactly what made the review read it as an overlap hazard for long pre
 * lines. The fix parks the pill with an INTENTIONAL right seat (max-content
 * width + auto left margin, clamped by the sticky constraint the Chromium
 * measurement in e2e/transcript-jump.spec.ts already pins) and retires the
 * float. m48's "unnamed" half was cured earlier by the visible-text label; the
 * companion pin keeps the button content-named, since a content button that
 * loses its text loses its accessible name.
 */
describe('transcript jump pill (N53, m48)', () => {
  const transcript = read('src-web/components/Transcript.vue')
  const jump = ruleBody(transcript, /\.jump\s*\{([^}]*)\}/)

  it('the pill seats through an explicit right seat, never a float on the sticky box', () => {
    expect(jump).toContain('position: sticky')
    expect(jump).toContain('bottom: 0.75rem')
    expect(jump).toContain('margin-right: 1rem')
    expect(jump).toContain('width: max-content')
    expect(jump).toContain('margin-left: auto')
    expect(jump).not.toMatch(/float\s*:/)
  })

  it('the pill stays named by its visible label text (m48 landed)', () => {
    expect(transcript).toMatch(/class="jump"[^>]*>\{\{ t\('web\.transcript\.jump_latest'\) \}\}/)
  })
})
