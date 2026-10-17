import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * ISS-107: the WCAG contrast of the theme tokens, derived from tokens.css
 * itself so a recolour that drops a pair below 4.5:1 goes red here and not
 * quietly on a viewer's screen.
 */
// vitest runs from the package root.
const css = readFileSync(resolve(process.cwd(), 'src-web/styles/tokens.css'), 'utf8')

function block(pattern: RegExp): Record<string, string> {
  const match = css.match(pattern)
  if (!match) throw new Error(`tokens.css no longer has a block matching ${pattern}`)
  const tokens: Record<string, string> = {}
  for (const [, name, value] of match[1].matchAll(/--([a-z-]+):\s*(#[0-9a-f]{6});/g)) tokens[name] = value
  return tokens
}

const themes: Record<string, Record<string, string>> = {
  dark: block(/:root \{([^}]*)\}/),
  light: block(/:root\[data-theme='light'\] \{([^}]*)\}/),
}

/** WCAG 2 relative luminance (sRGB). */
function luminance(hex: string): number {
  const channels = [1, 3, 5].map((i) => Number.parseInt(hex.slice(i, i + 2), 16) / 255)
  const [r, g, b] = channels.map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

function ratio(foreground: string, background: string): number {
  const [high, low] = [luminance(foreground), luminance(background)].sort((a, b) => b - a)
  return (high + 0.05) / (low + 0.05)
}

/** The pairs normal body text actually appears in: muted labels, accent
 *  links/primary-button text (which is --bg on --accent), and --text itself. */
const pairs: Array<[string, string]> = [
  ['muted', 'panel'],
  ['muted', 'bg'],
  ['accent', 'bg'],
  ['accent', 'panel'],
  ['bg', 'accent'],
  ['text', 'bg'],
  ['text', 'panel'],
]

describe.each(Object.entries(themes))('%s theme (ISS-107)', (_name, tokens) => {
  it.each(pairs)('--%s on --%s clears WCAG AA for normal text', (fore, back) => {
    const value = ratio(tokens[fore] as string, tokens[back] as string)
    expect(value, `${fore} on ${back}: ${value.toFixed(2)}`).toBeGreaterThanOrEqual(4.5)
  })
})

it('keeps the two light-theme blocks in step (ISS-107)', () => {
  const media = block(/:root:not\(\[data-theme='dark'\]\) \{([^}]*)\}/)
  expect(media).toEqual(themes.light)
})
