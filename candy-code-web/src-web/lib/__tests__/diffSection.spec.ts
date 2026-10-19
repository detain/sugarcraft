import { describe, expect, it } from 'vitest'
import { diffSectionForPath } from '../diff'

/** WEB-1b: the client-side carving of one file's section out of the whole-workspace diff. */

const TWO_FILES = [
  'diff --git a/one.ts b/one.ts',
  'index 1..2 100644',
  '--- a/one.ts',
  '+++ b/one.ts',
  '@@ -1 +1 @@',
  '-a',
  '+b',
  'diff --git a/dir/two.log b/dir/two.log',
  '--- a/dir/two.log',
  '+++ b/dir/two.log',
  '@@ -1 +1 @@',
  '-c',
  '+d',
].join('\n')

describe('diffSectionForPath (WEB-1b)', () => {
  it('returns one file\'s header through the line before the next header', () => {
    const section = diffSectionForPath(TWO_FILES, 'one.ts')
    expect(section).toBe(
      [
        'diff --git a/one.ts b/one.ts',
        'index 1..2 100644',
        '--- a/one.ts',
        '+++ b/one.ts',
        '@@ -1 +1 @@',
        '-a',
        '+b',
      ].join('\n'),
    )
  })

  it('carves the last section without a following header', () => {
    expect(diffSectionForPath(TWO_FILES, 'dir/two.log')).toContain('+d')
  })

  it('answers a rename to either side of the header', () => {
    const ren = ['diff --git a/old.ts b/new.ts', 'similarity index 90%', '--- a/old.ts', '+++ b/new.ts', '@@ -1 +1 @@', '-x', '+y'].join('\n')
    expect(diffSectionForPath(ren, 'new.ts')).toBe(ren)
    expect(diffSectionForPath(ren, 'old.ts')).toBe(ren)
  })

  it('is null for paths with no section, and for empty inputs', () => {
    expect(diffSectionForPath(TWO_FILES, 'notes.md')).toBeNull()
    expect(diffSectionForPath('', 'one.ts')).toBeNull()
    expect(diffSectionForPath(TWO_FILES, '')).toBeNull()
  })
})
