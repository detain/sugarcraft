import { beforeEach, describe, expect, it } from 'vitest'
import { PROMPT_HISTORY_CAP, clearPromptHistory, loadPromptHistory, pushPromptHistory, searchPromptHistory } from '../promptHistory'
import { readJson, writeJson } from '../storage'

describe('prompt history ring (WEB-5a-i)', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('starts empty and survives a junk payload', () => {
    expect(loadPromptHistory()).toEqual([])
    writeJson('promptHistory', { not: 'an array' })
    expect(loadPromptHistory()).toEqual([])
    writeJson('promptHistory', ['keep', 7, '', 'also keep'])
    expect(loadPromptHistory()).toEqual(['keep', 'also keep'])
  })

  it('records newest-first and persists under the storage prefix', () => {
    let ring = loadPromptHistory()
    ring = pushPromptHistory(ring, 'first prompt')
    ring = pushPromptHistory(ring, 'second prompt')
    expect(ring).toEqual(['second prompt', 'first prompt'])
    expect(localStorage.getItem('candy-code-web:promptHistory')).toContain('second prompt')
    expect(loadPromptHistory()).toEqual(ring)
  })

  it('skips a duplicate of the last prompt and never stores blanks', () => {
    let ring = pushPromptHistory([], '  deploy the fix  ')
    expect(ring).toEqual(['deploy the fix'])
    ring = pushPromptHistory(ring, 'deploy the fix')
    expect(ring).toEqual(['deploy the fix'])
    // The skip must not touch storage either: the head is unchanged.
    expect(loadPromptHistory()).toEqual(['deploy the fix'])
    for (const blank of ['', '   ', '\n\t']) {
      expect(pushPromptHistory(ring, blank)).toBe(ring)
    }
  })

  it('a repeat after a different prompt rides the ring again (only the head dedupes)', () => {
    let ring = pushPromptHistory([], 'one')
    ring = pushPromptHistory(ring, 'two')
    ring = pushPromptHistory(ring, 'one')
    expect(ring).toEqual(['one', 'two', 'one'])
  })

  it('caps at the limit, dropping the oldest', () => {
    let ring: string[] = []
    for (let i = 0; i < PROMPT_HISTORY_CAP + 10; i++) ring = pushPromptHistory(ring, `prompt ${i}`)
    expect(ring).toHaveLength(PROMPT_HISTORY_CAP)
    expect(ring[0]).toBe(`prompt ${PROMPT_HISTORY_CAP + 9}`)
    expect(ring.at(-1)).toBe('prompt 10')
    expect(loadPromptHistory()).toHaveLength(PROMPT_HISTORY_CAP)
  })

  it('load clamps an oversized pre-existing payload to the cap', () => {
    writeJson('promptHistory', Array.from({ length: PROMPT_HISTORY_CAP + 5 }, (_, i) => `p${i}`))
    expect(loadPromptHistory()).toHaveLength(PROMPT_HISTORY_CAP)
  })

  it('readJson round-trips the very key the ring wrote (the wrapper is the storage)', () => {
    pushPromptHistory([], 'round trip')
    expect(readJson<string[]>('promptHistory', [])).toEqual(['round trip'])
  })

  it('clearPromptHistory erases the key, not just the view of it', () => {
    pushPromptHistory(loadPromptHistory(), 'a typed prompt')
    expect(localStorage.getItem('candy-code-web:promptHistory')).not.toBeNull()

    clearPromptHistory()

    expect(localStorage.getItem('candy-code-web:promptHistory')).toBeNull()
    expect(loadPromptHistory()).toEqual([])
    // And the ring still works afterwards: clearing is not disabling.
    expect(pushPromptHistory(loadPromptHistory(), 'after clear')).toEqual(['after clear'])
    expect(loadPromptHistory()).toEqual(['after clear'])
  })
})

/**
 * C-WEB-4.a: the Ctrl+G search filter over the persisted ring — TUI
 * semantics: case-insensitive containment, newest-first preserved.
 */
describe('searchPromptHistory (C-WEB-4.a)', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('filters by case-insensitive containment keeping the ring order (newest first)', () => {
    let ring = loadPromptHistory()
    for (const prompt of ['deploy docs', 'ship it', 'DEPLOY the fix']) ring = pushPromptHistory(ring, prompt)
    expect(ring).toEqual(['DEPLOY the fix', 'ship it', 'deploy docs'])
    expect(searchPromptHistory(ring, 'deploy')).toEqual(['DEPLOY the fix', 'deploy docs'])
    expect(searchPromptHistory(ring, 'the')).toEqual(['DEPLOY the fix'])
    expect(searchPromptHistory(ring, 'nothing matches')).toEqual([])
  })

  it('an empty query returns the whole ring; the search never rewrites storage', () => {
    const ring = pushPromptHistory([], 'only me')
    expect(searchPromptHistory(ring, '')).toEqual(ring)
    searchPromptHistory(ring, 'only')
    expect(loadPromptHistory()).toEqual(ring)
  })

  it('searches the loaded ring from storage exactly as recall walks it', () => {
    writeJson('promptHistory', ['newest', 'older'])
    expect(searchPromptHistory(loadPromptHistory(), 'older')).toEqual(['older'])
  })
})
