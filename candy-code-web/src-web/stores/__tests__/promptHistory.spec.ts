import { beforeEach, describe, expect, it } from 'vitest'
import { PROMPT_HISTORY_CAP, clearPromptHistory, loadPromptHistory, pushPromptHistory } from '../promptHistory'
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
