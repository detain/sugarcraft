import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * CW-UX-16: numeric readouts (token counts, percentages, durations, costs, diff
 * gutter line numbers) re-flowed their neighbours as digits changed width because
 * nothing requested tabular figures. Each surface component now carries a local
 * `.numbers` rule — deliberately NOT a tokens.css utility (packing contract: the
 * class lives in each component's scoped style) — and applies it to its numeric
 * cells. happy-dom resolves no CSS cascade, so these pins scan the component
 * sources with anchored legs per site (substring legs are vacuous; UXB M3 lesson).
 */
const source = (relative: string): string => readFileSync(resolve(process.cwd(), relative), 'utf8')

const NUMBERS_RULE = /\.numbers \{\s*font-variant-numeric: tabular-nums;\s*\}/

describe('CW-UX-16 tabular figures on numeric cells', () => {
  it('StatusBar declares the rule and marks the context meter and spend chip', () => {
    const src = source('src-web/components/StatusBar.vue')
    expect(NUMBERS_RULE.test(src)).toBe(true)
    expect(src).toContain('class="item action numbers"')
    expect(src).toContain('class="item numbers"')
  })

  it('UsagePanel declares the rule and marks the totals row and table columns', () => {
    const src = source('src-web/components/panels/UsagePanel.vue')
    expect(NUMBERS_RULE.test(src)).toBe(true)
    expect(/<span class="numbers">\{\{ spendLabel/.test(src)).toBe(true)
    expect((src.match(/<td class="numbers">\{\{ tokens\(row\.totalTokens\)\ \}\}/g) ?? []).length).toBe(2)
    expect((src.match(/<td class="numbers">\{\{ spendLabel/g) ?? []).length).toBe(2)
  })

  it('ContextPanel declares the rule and marks the token figures and head badge', () => {
    const src = source('src-web/components/panels/ContextPanel.vue')
    expect(NUMBERS_RULE.test(src)).toBe(true)
    expect((src.match(/class="tokens numbers"/g) ?? []).length).toBeGreaterThanOrEqual(3)
    expect(src).toContain('class="count numbers"')
  })

  it('ToolCard declares the rule and marks the duration readout', () => {
    const src = source('src-web/components/ToolCard.vue')
    expect(NUMBERS_RULE.test(src)).toBe(true)
    expect(src).toContain('class="time numbers"')
  })

  it('DiffView declares the rule and marks all four gutter columns', () => {
    const src = source('src-web/components/DiffView.vue')
    expect(NUMBERS_RULE.test(src)).toBe(true)
    expect((src.match(/<span class="no numbers" role="cell">/g) ?? []).length).toBe(4)
  })
})
