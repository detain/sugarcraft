import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * CW-UX-10: the transcript toolbar is `position: sticky; z-index: 2`, but at
 * base its rule carried no `background` — transcript rows scrolled THROUGH
 * the toggle buttons instead of under an opaque bar. happy-dom resolves no
 * scoped `<style>` cascade, so this is the house source-scan genre: every leg
 * is anchored to the `.toolbar {` block itself (a bare substring anywhere in
 * the file would stay green under the mutation that matters — UXB's disclosed
 * M3 lesson).
 */
const source = readFileSync(resolve(__dirname, '../Transcript.vue'), 'utf8')

describe('CW-UX-10 — the sticky toolbar is painted opaque', () => {
  it('the .toolbar rule carries a background', () => {
    expect(source).toMatch(/\.toolbar \{[^}]*\n  background: var\(--bg\);/s)
  })

  it('and it is still the sticky element the finding describes (presence guard)', () => {
    expect(source).toMatch(/\.toolbar \{[^}]*\n  position: sticky;/s)
    expect(source).toMatch(/\.toolbar \{[^}]*\n  z-index: 2;/s)
  })
})
