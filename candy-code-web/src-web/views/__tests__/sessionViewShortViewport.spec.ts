import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * CW-UX-08 (SessionView leg): the chrome stack below the transcript — queue strip,
 * error line, composer, status bar — grew unbounded padding on short viewports and
 * squeezed the scroller to a few visible rows. The view now carries a max-height
 * media block that compacts the head and dock paddings, caps the dock at a scrollable
 * fraction of the viewport, and floors the transcript's flex area. The five overlay
 * files of the same register row were capped by lane G5-UXB; this leg completes the
 * row on the main view.
 *
 * happy-dom performs no CSS layout, so — like this campaign's other geometry rows —
 * these pins are source scans with anchored legs per rule (substring legs are
 * vacuous; see the disclosed UXB M3 lesson).
 */
const viewSource = readFileSync(resolve(process.cwd(), 'src-web/views/SessionView.vue'), 'utf8')

describe('SessionView short-viewport chrome cap', () => {
  it('carries a max-height media block around the chrome rules', () => {
    expect(/@media \(max-height: \d+px\) \{/.test(viewSource)).toBe(true)
  })

  it('caps the dock at a scrollable fraction of the viewport inside the block', () => {
    const block = /@media \(max-height: \d+px\) \{([\s\S]*?)\n\}/.exec(viewSource)
    expect(block).not.toBeNull()
    const body = block?.[1] ?? ''
    expect(/\.dock \{[^}]*max-height: \d+vh;[^}]*overflow-y: auto;[^}]*\}/s.test(body)).toBe(true)
  })

  it('compacts the dock and head paddings inside the block', () => {
    const block = /@media \(max-height: \d+px\) \{([\s\S]*?)\n\}/.exec(viewSource)
    const body = block?.[1] ?? ''
    expect(/\.head \{[^}]*padding: 0\.25rem 1rem;[^}]*\}/s.test(body)).toBe(true)
    expect(/\.dock \{[^}]*padding: 0\.25rem [^;]+;/.test(body)).toBe(true)
  })

  it('floors the transcript scroller so the conversation keeps rows visible', () => {
    const block = /@media \(max-height: \d+px\) \{([\s\S]*?)\n\}/.exec(viewSource)
    const body = block?.[1] ?? ''
    expect(/\.scroller \{[^}]*min-height: [68]\.?[05]*rem;[^}]*\}/s.test(body)).toBe(true)
  })
})
