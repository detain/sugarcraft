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
    expect(preview?.label).toBe('src/a.ts')
    expect(preview?.added).toBe(1)
    expect(preview?.removed).toBe(1)
    // The trailing newline must not mint a phantom empty line on either side.
    expect(preview?.text).toBe(
      ['--- a/src/a.ts', '+++ b/src/a.ts', '@@ -1,3 +1,3 @@', ' alpha', '-beta', '+BETA', ' gamma'].join('\n'),
    )
  })

  it('renders every batched `edits` pair, counted together', () => {
    const preview = askDiffPreview('Edit', {
      file_path: 'x',
      edits: [
        { old_string: 'a\n', new_string: 'b\n' },
        { old_string: 'c\n', new_string: 'd\n' },
      ],
    })
    expect(preview?.added).toBe(2)
    expect(preview?.removed).toBe(2)
    expect(preview?.text.split('\n').filter((line) => line.startsWith('@@'))).toEqual([
      '@@ -1,1 +1,1 @@',
      '@@ -1,1 +1,1 @@',
    ])
    expect(preview?.text).toContain('-a')
    expect(preview?.text).toContain('-c')
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
    expect(preview?.label).toBe('Edit')
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
