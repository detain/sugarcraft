import { describe, expect, it } from 'vitest'
import { diffPathForHeader, parseUnifiedDiff, resolveDiffStyle, splitDiffRows } from '../diff'

/** Pair rows of `diff` as [leftText|null, rightText|null]; span rows ride the
 *  unified stream and are what the pairing must not touch. */
function pairsOf(diff: string): [string | null, string | null][] {
  const out: [string | null, string | null][] = []
  for (const row of splitDiffRows(parseUnifiedDiff(diff))) {
    if (row.type === 'pair') out.push([row.left?.text ?? null, row.right?.text ?? null])
  }
  return out
}

function spansOf(diff: string): string[] {
  const out: string[] = []
  for (const row of splitDiffRows(parseUnifiedDiff(diff))) {
    if (row.type === 'span') out.push(row.span.text)
  }
  return out
}

const HEADER = '--- a/f.txt\n+++ b/f.txt\n@@ -1,3 +1,3 @@\n'

describe('splitDiffRows pairs a changed run line by line (C-TUI-2.b rule)', () => {
  it('pairs the nth removal with the nth addition in order', () => {
    expect(pairsOf(`${HEADER} keep\n-r1\n-r2\n-r3\n+a1\n+a2\n`)).toEqual([
      ['keep', 'keep'],
      ['r1', 'a1'],
      ['r2', 'a2'],
      ['r3', null],
    ])
  })

  it('lets an addition with no queued removal stand alone on the right', () => {
    expect(pairsOf(`${HEADER}+a1\n-r1\n+a2\n`)).toEqual([
      [null, 'a1'],
      ['r1', 'a2'],
    ])
  })

  it('pairs context with itself and closes the pending run', () => {
    expect(pairsOf(`${HEADER}-r1\n ctx\n+a1\n`)).toEqual([
      ['r1', null],
      ['ctx', 'ctx'],
      [null, 'a1'],
    ])
  })

  it('never pairs across a hunk boundary', () => {
    const twoHunks = `${HEADER}-r1\n@@ -10,1 +10,1 @@\n+a1\n`
    expect(pairsOf(twoHunks)).toEqual([
      ['r1', null],
      [null, 'a1'],
    ])
  })

  it('carries the "\ No newline" annotation as a span without cutting a run', () => {
    const annotated = `${HEADER}-r1\n\\ No newline at end of file\n+a1\n`
    expect(pairsOf(annotated)).toEqual([['r1', 'a1']])
    expect(spansOf(annotated)).toContain('\\ No newline at end of file')
  })

  it('keeps every input row: spans are the headers, pairs the body', () => {
    const diff = `${HEADER}-gone\n+here\n`
    const rows = splitDiffRows(parseUnifiedDiff(diff))
    expect(spansOf(diff)).toEqual([
      '--- a/f.txt',
      '+++ b/f.txt',
      '@@ -1,3 +1,3 @@',
    ])
    expect(rows.filter((r) => r.type === 'pair')).toHaveLength(1)
  })

  it('emits nothing but spans for a header-only diff', () => {
    const rows = splitDiffRows(parseUnifiedDiff('--- a/f.txt\n+++ b/f.txt\n'))
    expect(rows.every((row) => row.type === 'span')).toBe(true)
  })
})

describe('resolveDiffStyle picks a layout from the pref and width', () => {
  it('honours the explicit choices at any width', () => {
    expect(resolveDiffStyle('unified', 4000)).toBe('unified')
    expect(resolveDiffStyle('split', 200)).toBe('split')
  })

  it('splits on auto only at or past 1100 px', () => {
    expect(resolveDiffStyle('auto', 1099)).toBe('unified')
    expect(resolveDiffStyle('auto', 1100)).toBe('split')
    expect(resolveDiffStyle('auto', 1600)).toBe('split')
  })

  it('falls back to unified for an unrecognised stored value', () => {
    expect(resolveDiffStyle('columns', 4000)).toBe('unified')
  })
})

describe('diffPathForHeader names the new-side path', () => {
  it('reads the b/ side of a diff --git header, dropping quotepath-free noise', () => {
    expect(diffPathForHeader('diff --git a/src/app.ts b/src/app.ts')).toBe('src/app.ts')
  })

  it('reads a +++ line verbatim past the prefix', () => {
    expect(diffPathForHeader('+++ b/README.md')).toBe('README.md')
    expect(diffPathForHeader('--- a/README.md')).toBe('README.md')
  })

  it('drops git’s trailing timestamp tab column', () => {
    expect(diffPathForHeader('+++ b/notes.txt\t2026-10-30 12:00:00 +0000')).toBe('notes.txt')
  })

  it('declines /dev/null and non-header rows', () => {
    expect(diffPathForHeader('+++ /dev/null')).toBe('/dev/null')
    expect(diffPathForHeader('@@ -1,1 +1,1 @@')).toBe('')
  })
})
