import { describe, expect, it } from 'vitest'
import { askDiffPreview, diffPairsFor, unifiedDiff } from '../lineDiff'

describe('lineDiff (WEB-5a-ii)', () => {
  it('renders an Edit single-pair diff with unified headers and line numbers', () => {
    const preview = askDiffPreview('Edit', {
      file_path: 'src/a.ts',
      old_string: 'alpha\nbeta\ngamma\n',
      new_string: 'alpha\nBETA\ngamma\n',
    })
    expect(preview).not.toBeNull()
    expect(preview?.label).toBe('src/a.ts (snippet-relative)')
    expect(preview?.added).toBe(1)
    expect(preview?.removed).toBe(1)
    // The trailing newline must not mint a phantom empty line on either side.
    expect(preview?.text).toBe(
      ['--- a/src/a.ts', '+++ b/src/a.ts', '@@ -1,3 +1,3 @@', ' alpha', '-beta', '+BETA', ' gamma'].join('\n'),
    )
  })

  it('renders the top-level pair AND every batched `edits` entry, counted together', () => {
    // C-SEC-2 (WEB-5-P1): this is the shape a real batched Edit carries —
    // editList() runs the top-level pair as edit 1 and `edits` as 2..N. The
    // old preview showed only the top pair and hid the rest of the consent.
    const preview = askDiffPreview('Edit', {
      file_path: 'x',
      old_string: 'x = 1\n',
      new_string: 'x = 2\n',
      edits: [
        { old_string: 'safe()\n', new_string: 'exfiltrate()\n' },
        { old_string: 'c\n', new_string: 'd\n' },
      ],
    })
    expect(preview).not.toBeNull()
    expect(preview?.text).toContain('-x = 1')
    expect(preview?.text).toContain('+x = 2')
    expect(preview?.text).toContain('-safe()')
    expect(preview?.text).toContain('+exfiltrate()')
    expect(preview?.text).toContain('-c')
    expect(preview?.text).toContain('+d')
    expect(preview?.added).toBe(3)
    expect(preview?.removed).toBe(3)
    // editList order: the top-level hunk leads, then each entry in order.
    const removedLines = preview!.text.split('\n').filter((line) => line.startsWith('-') && !line.startsWith('--- '))
    expect(removedLines).toEqual(['-x = 1', '-safe()', '-c'])
    expect(preview?.text.split('\n').filter((line) => line.startsWith('@@'))).toEqual([
      '@@ -1,1 +1,1 @@',
      '@@ -1,1 +1,1 @@',
      '@@ -1,1 +1,1 @@',
    ])
    expect(preview?.label).toBe('x (3 edits, snippet-relative)')
  })

  it('previews nothing the server itself would refuse (fail closed to the raw dump)', () => {
    // editList rejects all of these whole calls, so the card shows raw JSON
    // rather than a partial preview (C-SEC-2).
    expect(diffPairsFor('Edit', { old_string: 'a', new_string: 'b', edits: [{ old_string: 'c' }] })).toBeNull()
    expect(diffPairsFor('Edit', { old_string: 'a', new_string: 'b', edits: ['junk'] })).toBeNull()
    expect(diffPairsFor('Edit', { old_string: 'a', new_string: 'b', edits: [{ old_string: '', new_string: 'd' }] })).toBeNull()
    expect(diffPairsFor('Edit', { old_string: 'a', new_string: 'b', edits: {} })).toBeNull()
    // editList demands the top-level strings before it looks at `edits`.
    expect(diffPairsFor('Edit', { file_path: 'x', edits: [{ old_string: 'a\n', new_string: 'b\n' }] })).toBeNull()
    // An absent or empty `edits` is the plain single edit editList accepts.
    expect(diffPairsFor('Edit', { old_string: 'a', new_string: 'b', edits: [] })).toEqual([
      { before: 'a', after: 'b', replaceAll: false },
    ])
  })

  it('labels each replace-all pair the way editList runs it', () => {
    const preview = askDiffPreview('Edit', {
      file_path: 'f',
      old_string: 'a\n',
      new_string: 'b\n',
      replace_all: true,
      edits: [{ old_string: 'c\n', new_string: 'd\n' }],
    })
    const fileHeaders = preview!.text.split('\n').filter((line) => line.startsWith('--- '))
    expect(fileHeaders).toEqual(['--- a/f (replace all occurrences)', '--- a/f'])
    // The flag is per pair: the second entry does not inherit it.
    expect(preview?.text).toContain('+++ b/f (replace all occurrences)')
    expect(preview?.text).toContain('+++ b/f\n')
  })

  it('reads replace_all with PHP truthiness, as editList casts it', () => {
    const truthy = askDiffPreview('Edit', { file_path: 'f', old_string: 'a\n', new_string: 'b\n', replace_all: 'false' })
    expect(truthy?.text).toContain('--- a/f (replace all occurrences)')
    const falsy = askDiffPreview('Edit', { file_path: 'f', old_string: 'a\n', new_string: 'b\n', replace_all: '0' })
    expect(falsy?.text).toContain('--- a/f\n')
    const entryFlag = askDiffPreview('Edit', {
      file_path: 'f',
      old_string: 'a\n',
      new_string: 'b\n',
      edits: [{ old_string: 'c\n', new_string: 'd\n', replace_all: 1 }],
    })
    expect(entryFlag?.text).toContain('--- a/f (replace all occurrences)')
  })

  it('marks the Edit summary as snippet-relative, leaving the hunk grammar alone', () => {
    // WEB-5-P4: hunk line numbers count from each snippet's start, never the
    // file's — the summary says so while the @@ lines keep the grammar
    // DiffView parses.
    const single = askDiffPreview('Edit', { file_path: 'src/a.ts', old_string: 'a\n', new_string: 'b\n' })
    expect(single?.label).toBe('src/a.ts (snippet-relative)')
    const batched = askDiffPreview('Edit', {
      file_path: 'src/a.ts',
      old_string: 'a\n',
      new_string: 'b\n',
      edits: [{ old_string: 'c\n', new_string: 'd\n' }],
    })
    expect(batched?.label).toBe('src/a.ts (2 edits, snippet-relative)')
    expect(batched?.text).toContain('@@ -1,1 +1,1 @@')
  })

  it('renders a Write as an all-added new-file view', () => {
    const preview = askDiffPreview('Write', { file_path: 'notes.md', content: '# Title\nbody\n' })
    expect(preview?.label).toBe('notes.md (new file)')
    expect(preview?.added).toBe(2)
    expect(preview?.removed).toBe(0)
    expect(preview?.text).toBe(
      ['--- a/notes.md (new file)', '+++ b/notes.md (new file)', '@@ -1,0 +1,2 @@', '+# Title', '+body'].join('\n'),
    )
  })

  it('says so when the Write replaces an existing file', () => {
    const preview = askDiffPreview('Write', { file_path: 'notes.md', content: 'x\n', overwrite: true })
    expect(preview?.label).toBe('notes.md (replaces the file)')
  })

  it('falls back (null) for non-edit shapes and half-edit payloads', () => {
    expect(askDiffPreview('Bash', { command: 'git push' })).toBeNull()
    expect(diffPairsFor('Edit', { old_string: 'a' })).toBeNull()
    expect(diffPairsFor('Edit', { edits: [] })).toBeNull()
    expect(diffPairsFor('Edit', { edits: [{ old_string: 'a' }] })).toBeNull()
    expect(diffPairsFor('Edit', { edits: ['junk'] })).toBeNull()
    expect(diffPairsFor('Write', { description: 'no content' })).toBeNull()
  })

  it('labels an Edit without a file path by its tool name', () => {
    const preview = askDiffPreview('Edit', { old_string: 'a\n', new_string: 'b\n' })
    expect(preview?.label).toBe('Edit (snippet-relative)')
  })

  it('treats identical before/after as an honest empty diff', () => {
    const result = unifiedDiff('same\n', 'same\n', 'f')
    expect(result?.text).toBe('--- a/f\n+++ b/f\n@@ -1,0 +1,0 @@')
    expect(result?.added).toBe(0)
    expect(result?.removed).toBe(0)
  })

  it('refuses inputs past the work caps (fail-soft to the raw dump)', () => {
    const hugeBefore = Array(1300).fill('x').join('\n')
    expect(askDiffPreview('Edit', { file_path: 'f', old_string: hugeBefore, new_string: 'x\n' })).toBeNull()
    const wideAfter = 'x'.repeat(200_001)
    expect(askDiffPreview('Edit', { file_path: 'f', old_string: '', new_string: wideAfter })).toBeNull()
  })
})
