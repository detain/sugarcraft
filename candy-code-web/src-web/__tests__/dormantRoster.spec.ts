import { describe, expect, it } from 'vitest'
import { METHOD_SCOPES } from '../protocol/generated'

/**
 * C-WEB-3 (WEB-1-P1): the standing dormant-method roster.
 *
 * Every `METHOD_SCOPES` key must either have a browser caller in a shipped
 * module or carry a deliberate-dormancy reason below. The derivation reuses
 * the `shutdownDormant.spec.ts` source scan: a method counts as called when
 * some shipped module matches `request('M'` (with optional generic) or a
 * literal frame body `method: 'M'`. A protocol lane that adds a method with
 * no caller now fails loudly here until the method is wired or declared
 * dormant; the step that later adds the caller removes the entry.
 *
 * `shutdownDormant.spec.ts` is no longer the negative control for
 * `server.shutdown` — C-WEB-5.g wired its confirmed About caller and removed
 * the roster row in that step — but it stays the standing sole-caller proof,
 * and this roster makes the same caller-or-reason rule apply fleet-wide.
 */

/** Method name -> one-line reason it is deliberately dormant today. */
const DORMANT_REASONS: Record<string, string> = {
  // C-MCP-2.c wired the first browser caller: the composer @mcp: resource
  // picker (SessionView's roster fetch). The row for mcp.resources came out
  // in the same commit that added the caller, per this spec's own rule.
  // C-WEB-5.g wired server.shutdown's only caller (the confirmed About stop);
  // its roster row came out in that step, per this spec's own rule.
  // C-SES-7.e shipped the session.import method; the browser's import dialog
  // arrives with a later step — external clients ride the wire meanwhile.
  'session.import': 'transcript import rides the protocol for external clients; the browser import dialog lands with a later step',
}

/** Every module that ships to the browser: src minus the generated schema, tests and fixtures. */
const MODULES = Object.entries(
  import.meta.glob<string>('../**/*.{ts,vue}', { query: '?raw', import: 'default', eager: true }),
).filter(([path]) => !path.includes('__tests__') && !path.startsWith('../protocol/generated.'))

/** Same call idiom the shutdownDormant negative control matches. */
function callsMethod(text: string, method: string): boolean {
  const dotted = method.replace(/\./g, '\\.')
  return new RegExp(`\\brequest(?:<[^(]*>)?\\(\\s*['"]${dotted}['"]|method:\\s*['"]${dotted}['"]`).test(text)
}

function derivedDormant(): string[] {
  return Object.keys(METHOD_SCOPES)
    .filter((method) => !MODULES.some(([, text]) => callsMethod(String(text), method)))
    .sort()
}

describe('dormant-method roster (C-WEB-3)', () => {
  it('every schema method has a browser caller or a roster reason', () => {
    expect(MODULES.length).toBeGreaterThan(40)
    expect(derivedDormant()).toEqual(Object.keys(DORMANT_REASONS).sort())
  })

  it('the roster declares only real schema methods, each with a reason', () => {
    const scopes = METHOD_SCOPES as Readonly<Record<string, string>>
    for (const method of Object.keys(DORMANT_REASONS)) {
      expect(scopes[method], `${method} must exist in METHOD_SCOPES`).toBeTypeOf('string')
      expect(DORMANT_REASONS[method].length, `${method} needs a reason`).toBeGreaterThan(0)
    }
  })

  it('rostered methods are genuinely uncalled in the shipped modules', () => {
    for (const method of Object.keys(DORMANT_REASONS)) {
      const callers = MODULES.filter(([, text]) => callsMethod(String(text), method))
      expect(callers.map(([path]) => path), `${method} is called, drop the roster row`).toEqual([])
    }
  })
})
