import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import type { SessionSummary } from '../../protocol/generated'
import { sessionLabel } from '../format'

/**
 * n50 (register B-77): sessionLabel was relocated from components/approvals/
 * attention.ts to lib/format.ts — the label names tabs, tiles, drawer links,
 * announcements and notifications, so it belongs beside its `oneLine`
 * collaborator, not inside one consumer. These pins hold the move honest:
 * behavior is byte-identical to the old definition, and the placement itself
 * is pinned (this import failing to resolve IS the discrimination).
 */

function summary(fields: Partial<SessionSummary>): SessionSummary {
  return { open: true, ...fields } as unknown as SessionSummary
}

describe('sessionLabel lives in lib/format (n50 relocation)', () => {
  it('prefers the human name when the session has one', () => {
    expect(sessionLabel(summary({ id: 's1', name: 'Refactor parser' }), 's1')).toBe('Refactor parser')
  })

  it('falls back to a flattened preview truncated to 48 cells', () => {
    expect(sessionLabel(summary({ id: 's2', preview: 'fix\n  the\t thing  ' }), 's2')).toBe('fix the thing')
    const long = 'x'.repeat(60)
    const label = sessionLabel(summary({ id: 's3', preview: long }), 's3')
    expect(label).toBe(`${'x'.repeat(47)}…`)
    expect(label.length).toBe(48)
  })

  it('falls back to the raw id when there is nothing to show', () => {
    expect(sessionLabel(summary({ id: 's4' }), 's4')).toBe('s4')
    expect(sessionLabel(undefined, 's5')).toBe('s5')
  })

  it('left attention.ts without a definition of its own', () => {
    // House source-reading idiom (rawCopyCensus/storageInventory): process.cwd()
    // is the package root under happy-dom, where import.meta.url is not a file URL.
    const attention = readFileSync(resolve(process.cwd(), 'src-web/components/approvals/attention.ts'), 'utf8')
    expect(attention).not.toMatch(/function sessionLabel/)
    expect(attention).toMatch("import { sessionLabel } from '../../lib/format'")
  })
})
