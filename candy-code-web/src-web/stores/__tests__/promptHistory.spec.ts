import { beforeEach, describe, expect, it } from 'vitest'
import { PROMPT_HISTORY_CAP, PROMPT_HISTORY_MAX_ENTRY_CHARS, clearPromptHistory, loadPromptHistory, pushPromptHistory, searchPromptHistory, storageDegraded } from '../promptHistory'
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

/**
 * CW-DBA-3 (reviews-1103): pushPromptHistory persisted its own in-memory
 * argument — a blind last-writer write. Two tabs open, one pushes, the
 * other pushes from its stale ring: the first prompt vanishes. The ring
 * now follows the drafts law from layout.ts: read storage, merge, write,
 * and hand the merged ring back so the caller adopts the other tab too.
 * The documented head-only dedupe law survives: only a prompt the stored
 * ring already carries AT THE HEAD is skipped.
 */
describe('prompt history merges across tabs (CW-DBA-3)', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('adopts entries another tab stored while this tab held a stale ring', () => {
    writeJson('promptHistory', ['from other tab', 'shared old'])
    const stale = ['shared old']
    const ring = pushPromptHistory(stale, 'mine')
    expect(ring).toEqual(['mine', 'from other tab', 'shared old'])
    expect(loadPromptHistory()).toEqual(ring)
  })

  it('a prompt the stored ring already carries at the head is not written twice', () => {
    writeJson('promptHistory', ['x', 'older'])
    const ring = pushPromptHistory([], 'x')
    expect(ring).toEqual(['x', 'older'])
    expect(loadPromptHistory()).toEqual(['x', 'older'])
  })

  it('keeps a stored tail duplicate — only the head dedupes', () => {
    // Preserves the WEB-5a-i law pinned above: a repeat after a different
    // prompt rides the ring again, and the cross-tab merge must not
    // silently widen that rule.
    writeJson('promptHistory', ['two', 'one'])
    const ring = pushPromptHistory(['two'], 'one')
    expect(ring).toEqual(['one', 'two', 'one'])
  })
})

/**
 * CW-DBA-4 (reviews-1103): ring entries were length-unbounded — one
 * pasted megabyte prompt eats the whole budget, and the 50-entry count
 * protects nothing. Entries now carry the same 16 KiB character clamp the
 * drafts map persists (layout.ts DRAFT_MAX_PERSISTED_CHARS), and a write
 * the browser REFUSES (quota) is no longer swallowed: storageDegraded
 * says so, the drafts map's own flag is the precedent.
 */
describe('prompt history entry bound and write truth (CW-DBA-4)', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('clamps an oversized prompt to the persisted entry bound', () => {
    const ring = pushPromptHistory([], 'x'.repeat(PROMPT_HISTORY_MAX_ENTRY_CHARS + 10_000))
    expect(ring[0]).toHaveLength(PROMPT_HISTORY_MAX_ENTRY_CHARS)
    expect(loadPromptHistory()[0]).toHaveLength(PROMPT_HISTORY_MAX_ENTRY_CHARS)
    const stored = JSON.parse(localStorage.getItem('candy-code-web:promptHistory') as string) as string[]
    expect(stored[0]).toHaveLength(PROMPT_HISTORY_MAX_ENTRY_CHARS)
  })

  it('flags degraded storage when the browser refuses the write, keeping the ring usable', () => {
    // jsdom's Storage exotic object hides its setItem from prototype spies
    // once real writes have happened in the file, so the quota condition is
    // simulated by swapping globalThis.localStorage — the very binding
    // storage.ts resolves at call time.
    const real = globalThis.localStorage
    let writes = 0
    Object.defineProperty(globalThis, 'localStorage', { configurable: true, value: {
      getItem: (key: string) => real.getItem(key),
      setItem: () => { writes++; throw new Error('QuotaExceededError') },
      removeItem: (key: string) => real.removeItem(key),
    } })
    try {
      const ring = pushPromptHistory([], 'first')
      // The ring still works in memory; only persistence failed.
      expect(ring).toEqual(['first'])
      expect(storageDegraded.value).toBe(true)
      expect(writes).toBe(1)
    } finally {
      Object.defineProperty(globalThis, 'localStorage', { configurable: true, value: real })
    }
    pushPromptHistory(loadPromptHistory(), 'second')
    expect(storageDegraded.value).toBe(false)
    expect(loadPromptHistory()).toEqual(['second'])
  })
})
