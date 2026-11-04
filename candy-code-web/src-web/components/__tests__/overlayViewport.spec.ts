import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * CW-UX-08: every overlay root sized its cap in `vh` with `vh`-based backdrop
 * padding, so on short viewports (landscape phones, split windows, small
 * embedded terminals) the chrome pushed past the visible area and the scroll
 * affordance sat off-screen. The fix shape is a dynamic-viewport cap —
 * `min(<base cap>, calc(100dvh - X))` — plus a `@media (max-height)` block that
 * collapses the backdrop's vh padding. jsdom cannot measure layout honestly,
 * so these are source scans of the shipped style blocks (the same genre as the
 * MA-35 focus-ring pins), not computed-style assertions.
 */

const read = (path: string): string => readFileSync(resolve(process.cwd(), path), 'utf8')

/** The five program overlays CW-UX-08 covers. SessionView is a routed view
 * (not an overlay) and the media lightbox is full-bleed by design — neither
 * belongs in this roster. */
const OVERLAYS = [
  'src-web/components/AboutModal.vue',
  'src-web/components/DirectoryPicker.vue',
  'src-web/components/HelpOverlay.vue',
  'src-web/components/HistorySearch.vue',
  'src-web/components/panels/CommandPalette.vue',
] as const

describe('overlays fit short viewports (CW-UX-08)', () => {
  for (const component of OVERLAYS) {
    it(`${component} caps its root against the dynamic viewport`, () => {
      const source = read(component)
      expect(source, `${component} caps the root rule in dvh units`).toMatch(/max-height:\s*min\([^)]*calc\(100dvh - \d/)
      expect(source, `${component} collapses the vh gutter under the short-viewport query`).toMatch(/@media \(max-height[^{]*\{[^}]*padding:[^;]*;/)
      expect(source, `${component} tightens the cap itself in the media block`).toMatch(/max-height:\s*calc\(100dvh - 1rem\)/)
    })
  }

  it('exactly the five overlays carry the dvh cap', () => {
    const capped = OVERLAYS.filter((component) => read(component).includes('calc(100dvh -'))
    expect(capped.length).toBe(5)
    expect(read('src-web/components/MediaCard.vue')).not.toContain('calc(100dvh -')
    expect(read('src-web/views/SessionView.vue')).not.toContain('calc(100dvh -')
  })
})
