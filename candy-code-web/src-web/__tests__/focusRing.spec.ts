import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * MA-35: the keyboard focus ring was painted for buttons/links/selects only —
 * the two input surfaces (composer, sidebar filter, palette) and textareas had
 * `outline: none` or nothing at all. The pins here are source scans: the global
 * accent-outline rule must list the text-entry elements, and the two components
 * that scope-override `input { outline: none }` must re-declare the ring for
 * their own inputs (component CSS lands after tokens at equal specificity).
 */

const read = (path: string): string => readFileSync(resolve(process.cwd(), path), 'utf8')

describe('focus ring covers text entry (MA-35)', () => {
  it('the global accent-outline rule lists inputs and textareas', () => {
    const tokens = read('src-web/styles/tokens.css')
    const rule = tokens.slice(tokens.indexOf('button:focus-visible'))
    const block = rule.slice(0, rule.indexOf('{'))
    for (const selector of ['button', 'a', 'select', 'input', 'textarea']) {
      expect(block, `outline rule selector for ${selector}`).toContain(`${selector}:focus-visible`)
    }
    const body = rule.slice(rule.indexOf('{'), rule.indexOf('}') + 1)
    expect(body).toContain('outline: 2px solid var(--accent)')
  })

  it('the two components that kill the native outline restore it on focus-visible', () => {
    for (const component of ['src-web/components/HistorySearch.vue', 'src-web/components/panels/CommandPalette.vue']) {
      const source = read(component)
      expect(source, `${component} scopes input { outline: none }`).toContain('outline: none')
      expect(source, `${component} re-declares the focus ring`).toContain('input:focus-visible')
    }
  })
})
