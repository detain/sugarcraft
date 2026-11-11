import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PROMPT_HISTORY_CAP, PROMPT_HISTORY_MAX_ENTRY_CHARS, clearPromptHistory, loadPromptHistory, onPromptHistoryCleared, pushPromptHistory, searchPromptHistory, storageDegraded } from '../promptHistory'
import { dropCacheScope, readJson, rotateCacheScope, storageKey, writeJson } from '../storage'

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
    // CW-DBA-9: the ring lives under the login's cache scope, so the pin
    // asks the wrapper for the name instead of spelling it raw.
    expect(localStorage.getItem(storageKey('promptHistory'))).toContain('second prompt')
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
    const ringKey = storageKey('promptHistory')
    expect(localStorage.getItem(ringKey)).not.toBeNull()

    clearPromptHistory()

    expect(localStorage.getItem(ringKey)).toBeNull()
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
    const stored = JSON.parse(localStorage.getItem(storageKey('promptHistory')) as string) as string[]
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

/**
 * F-NIT-1 (cc-review-1111): the head dedupe compared the RAW prompt against
 * heads that are stored CLAMPED — an oversized re-send could never equal the
 * 16 KiB head it duplicated, so every repeat pasted a byte-identical entry at
 * the head and, at cap, each one evicted a tail entry the ring kept. The
 * compare now runs on clamp forms on BOTH sides (a legacy row may predate the
 * clamp, and the incoming prompt may exceed it). Two prompts that diverge
 * only beyond the bound are STORED identically, so their clamped heads — and
 * only those — are the dedupe subject.
 */
describe('prompt history dedupes on clamped heads (F-NIT-1)', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('re-sending the same oversized prompt does not paste a duplicate at the head', () => {
    const big = 'A'.repeat(PROMPT_HISTORY_MAX_ENTRY_CHARS + 5_000)
    let ring = pushPromptHistory([], big)
    expect(ring).toHaveLength(1)
    ring = pushPromptHistory(ring, big)
    expect(ring).toHaveLength(1)
    expect(loadPromptHistory()).toHaveLength(1)
  })

  it('an oversized re-send at the cap costs no tail entry', () => {
    let ring: string[] = []
    for (let i = 0; i < PROMPT_HISTORY_CAP; i++) ring = pushPromptHistory(ring, `filler ${i}`)
    const big = 'B'.repeat(PROMPT_HISTORY_MAX_ENTRY_CHARS + 5_000)
    ring = pushPromptHistory(ring, big)
    expect(ring).toHaveLength(PROMPT_HISTORY_CAP)
    expect(ring[0]).toBe(big.slice(0, PROMPT_HISTORY_MAX_ENTRY_CHARS))
    expect(ring.at(-1)).toBe('filler 1')
    ring = pushPromptHistory(ring, big)
    expect(ring).toHaveLength(PROMPT_HISTORY_CAP)
    expect(ring.at(-1)).toBe('filler 1')
  })

  it('the bound edge: exactly-16KiB dedupes unchanged; bound+1 dedupes on the clamped form', () => {
    const exact = 'e'.repeat(PROMPT_HISTORY_MAX_ENTRY_CHARS)
    const exactRing = pushPromptHistory([], exact)
    expect(pushPromptHistory(exactRing, exact)).toHaveLength(1)
    const over = 'e'.repeat(PROMPT_HISTORY_MAX_ENTRY_CHARS + 1)
    let ring = pushPromptHistory([], over)
    expect(ring).toHaveLength(1)
    ring = pushPromptHistory(ring, over)
    expect(ring).toHaveLength(1)
  })

  it('a prompt matching a legacy un-clamped stored head dedupes too', () => {
    const legacy = 'L'.repeat(PROMPT_HISTORY_MAX_ENTRY_CHARS + 5_000)
    writeJson('promptHistory', [legacy, 'older'])
    const ring = pushPromptHistory([], legacy.slice(0, PROMPT_HISTORY_MAX_ENTRY_CHARS))
    expect(ring).toEqual([legacy, 'older'])
    expect(loadPromptHistory()).toEqual(ring)
  })

  it('two long prompts that diverge EARLY are both kept', () => {
    const tail = 'y'.repeat(PROMPT_HISTORY_MAX_ENTRY_CHARS)
    const ring = pushPromptHistory(pushPromptHistory([], `1-${tail}`), `2-${tail}`)
    expect(ring).toHaveLength(2)
    expect(ring[0]).toBe(`2-${tail}`.slice(0, PROMPT_HISTORY_MAX_ENTRY_CHARS))
    expect(loadPromptHistory()).toHaveLength(2)
  })
})

/**
 * L-DBA MAJOR-1 (reviews-1103): the cross-tab clear once matched a physical
 * name snapshotted at MODULE IMPORT — pre-login, so every scope rotation the
 * ring later lived under was an unknown key to the watcher, and the cleared
 * listeners silently stopped hearing sign-outs from the other tab.
 *
 * The pins below change the pointer FIRST and deliver the event under the
 * NEW physical name SECOND — the real topology the old within-one-process
 * spec could never see, because pre-rotation the module-load ephemeral name
 * and the test-derived name happened to agree.
 */
describe('cross-tab clear survives scope transitions (L-DBA MAJOR-1)', () => {
  const POINTER = 'candy-code-web:cacheScope'

  beforeEach(() => {
    localStorage.clear()
  })

  it('empties the ring when the scope moves out from under the import-time name', () => {
    // Seed a ring under the current (import-time) scope so holders have
    // something live to drop.
    pushPromptHistory(loadPromptHistory(), 'keep me until a real clear')

    const cleared = vi.fn()
    const off = onPromptHistoryCleared(cleared)

    // Foreign-tab sign-in: rotate the pointer by rewriting it, then remove
    // the key under the NEW physical name and deliver the storage event. No
    // local bus fires, so only per-event matching can see it.
    localStorage.setItem(POINTER, 'a0a0a0a0')
    const key = storageKey('promptHistory')
    expect(key).toBe('candy-code-web:a0a0a0a0/promptHistory') // the live name, not the import snapshot
    localStorage.removeItem(key)
    window.dispatchEvent(new StorageEvent('storage', { key, newValue: null }))

    expect(cleared).toHaveBeenCalledTimes(1)
    off()
  })

  it('clears on the legacy unversioned name a removal under an old namespace still carries', () => {
    const cleared = vi.fn()
    const off = onPromptHistoryCleared(cleared)
    window.dispatchEvent(
      new StorageEvent('storage', { key: 'candy-code-web:promptHistory', newValue: null }),
    )
    expect(cleared).toHaveBeenCalledTimes(1)
    off()
  })

  it('leaves the ring alone for a scoped bucket that names a DIFFERENT key', () => {
    const cleared = vi.fn()
    const off = onPromptHistoryCleared(cleared)
    localStorage.setItem(POINTER, 'a0a0a0a0')
    window.dispatchEvent(
      new StorageEvent('storage', { key: 'candy-code-web:a0a0a0a0/drafts', newValue: null }),
    )
    expect(cleared).not.toHaveBeenCalled()
    off()
  })

  it('a same-tab rotateCacheScope clears the ring even though storage events never fire locally', () => {
    // CW-DBA-9 consequence (ii): the re-login sweep deletes the old bucket
    // but the `storage` event only reaches OTHER tabs. The ring used to be
    // absent from rotate's fireErase list, so this tab's live ring survived a
    // re-login with no notification — now rotate fires the ring's logical key
    // and this module listens on the in-tab bus.
    const cleared = vi.fn()
    const off = onPromptHistoryCleared(cleared)

    rotateCacheScope()

    expect(cleared).toHaveBeenCalledTimes(1)
    off()
  })

  it('a same-tab dropCacheScope clears the ring too, and the logout double-notify is idempotent', () => {
    // dropCacheScope() fires the ring's bus key; auth.ts logout also calls
    // clearPromptHistory() directly. Both land on notifyCleared, so holders
    // hear it twice on a real sign-out — every holder resets to empty, so the
    // double is harmless; pin that both routes reach the bus.
    const cleared = vi.fn()
    const off = onPromptHistoryCleared(cleared)

    dropCacheScope()
    expect(cleared).toHaveBeenCalledTimes(1)

    clearPromptHistory() // the other logout route
    expect(cleared).toHaveBeenCalledTimes(2)
    off()
  })

  it('the predicate answers live, foreign-bucket and legacy names, and no other key', async () => {
    // Dynamic import: the pre-fix tree lacks the export, and a static one
    // would sink every behavioral pin above into one module-load error
    // instead of showing them fail for the real reason.
    const { physicalKeyMatches } = await import('../storage')

    // Live name (the current scope):
    localStorage.setItem(POINTER, 'a0a0a0a0')
    expect(physicalKeyMatches('promptHistory', 'candy-code-web:a0a0a0a0/promptHistory')).toBe(true)
    // A different principal's bucket for the SAME logical key — over-clearing
    // is the safe direction (a sweep elsewhere deletes our old bucket after
    // the pointer has already moved):
    expect(physicalKeyMatches('promptHistory', 'candy-code-web:b9b9b9b9/promptHistory')).toBe(true)
    // Legacy unversioned bare name:
    expect(physicalKeyMatches('promptHistory', 'candy-code-web:promptHistory')).toBe(true)
    // The versioned namespace was retired with CW-DBA-16 — a v1-shaped name
    // answers nothing now:
    expect(physicalKeyMatches('drafts', 'candy-code-web:v1:drafts')).toBe(false)
    // A different logical key must never match:
    expect(physicalKeyMatches('drafts', 'candy-code-web:a0a0a0a0/promptHistory')).toBe(false)
    expect(physicalKeyMatches('promptHistory', 'candy-code-web:a0a0a0a0/drafts')).toBe(false)
    // Empty or nested bucket shapes are not a principal scope:
    expect(physicalKeyMatches('drafts', 'candy-code-web:/drafts')).toBe(false)
    expect(physicalKeyMatches('drafts', 'candy-code-web:a/b/drafts')).toBe(false)
    // An unscoped key (theme/pref) never takes the foreign-bucket leg:
    expect(physicalKeyMatches('theme', 'candy-code-web:b9b9b9b9/theme')).toBe(false)
    expect(physicalKeyMatches('theme', 'candy-code-web:theme')).toBe(true)
  })
})
