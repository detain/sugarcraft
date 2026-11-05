import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { DRAFT_MAX_PERSISTED_CHARS, useLayoutStore } from '../layout'
import { storageKey } from '../storage'

/**
 * The draft map: what it costs per keystroke (debounce), what it promises
 * across tabs (read-modify-write + storage fan-out), what it refuses to do
 * (resurrect text sign-out erased, swallow a quota failure silently).
 */

let layout: ReturnType<typeof useLayoutStore>

function storedDrafts(): Record<string, string> | null {
  const raw = globalThis.localStorage?.getItem(storageKey('drafts')) ?? null
  return raw === null ? null : (JSON.parse(raw) as Record<string, string>)
}

beforeEach(() => {
  setActivePinia(createPinia())
  try {
    globalThis.localStorage?.clear()
  } catch {
    // no storage
  }
  vi.useFakeTimers()
  layout = useLayoutStore()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('draft persistence (MA-14/MA-15)', () => {
  it('does not serialize on the keystroke; it flushes on the trailing edge', () => {
    layout.setDraft('s1', 'hel')
    layout.setDraft('s1', 'hell')
    layout.setDraft('s1', 'hello')
    expect(storedDrafts()).toBeNull()
    vi.advanceTimersByTime(300)
    expect(storedDrafts()).toEqual({ s1: 'hello' })
  })

  it('keeps another tab drafts it never held (read-modify-write)', () => {
    // Tab B's persisted text, absent from this tab's snapshot in memory:
    globalThis.localStorage?.setItem(storageKey('drafts'), JSON.stringify({ other: 'tab B newer text' }))
    layout.setDraft('mine', 'x')
    vi.advanceTimersByTime(300)
    expect(storedDrafts()).toEqual({ other: 'tab B newer text', mine: 'x' })
  })

  it('treats an emptied draft as a deletion, not a preservation', () => {
    globalThis.localStorage?.setItem(storageKey('drafts'), JSON.stringify({ ghost: 'old' }))
    layout.setDraft('ghost', 'new')
    vi.advanceTimersByTime(300)
    layout.setDraft('ghost', '')
    vi.advanceTimersByTime(300)
    expect(storedDrafts()).toEqual({})
  })

  it('clamps one pasted megabyte at the persisted bound, memory keeps it whole', () => {
    const huge = 'a'.repeat(DRAFT_MAX_PERSISTED_CHARS + 4096)
    layout.setDraft('s1', huge)
    vi.advanceTimersByTime(300)
    expect(storedDrafts()?.s1).toHaveLength(DRAFT_MAX_PERSISTED_CHARS)
    expect(layout.draft('s1')).toHaveLength(huge.length)
  })

  it('surfaces a swallowed quota failure once, instead of silently diverging', () => {
    // Replace the whole accessor: happy-dom's Storage instance ignores own
    // property assignments over its prototype methods, so the seam has to be
    // globalThis.localStorage itself (writeJson reads it per call).
    const original = Object.getOwnPropertyDescriptor(globalThis, 'localStorage')
    const blocker = {
      getItem: () => null,
      setItem: (): never => {
        throw new Error('QuotaExceededError')
      },
      removeItem: () => undefined,
    }
    Object.defineProperty(globalThis, 'localStorage', { value: blocker, configurable: true })
    try {
      layout.setDraft('s1', 'text')
      vi.advanceTimersByTime(300)
    } finally {
      if (original !== undefined) Object.defineProperty(globalThis, 'localStorage', original)
    }
    expect(layout.storageDegraded).toBe(true)
  })

  it('hydrates from another tab write, and empties on another tab erase', () => {
    window.dispatchEvent(
      new StorageEvent('storage', {
        key: storageKey('drafts'),
        newValue: JSON.stringify({ fromB: 'live' }),
      }),
    )
    expect(layout.draft('fromB')).toBe('live')

    window.dispatchEvent(new StorageEvent('storage', { key: storageKey('drafts'), newValue: null }))
    expect(layout.draft('fromB')).toBe('')
  })

  it('a sign-out erasure cancels the pending flush so drafts cannot resurrect', () => {
    layout.setDraft('s1', 'typed while signed in')
    layout.clearAllDrafts()
    vi.advanceTimersByTime(300)
    expect(storedDrafts()).toBeNull()
    expect(layout.draft('s1')).toBe('')
  })

  it('flushes on pagehide instead of losing the last words', () => {
    layout.setDraft('s1', 'ending')
    window.dispatchEvent(new Event('pagehide'))
    expect(storedDrafts()).toEqual({ s1: 'ending' })
  })
})

describe('forgetting a session (MA-12)', () => {
  it('drops the draft, the pointers and the tab for a deleted session', () => {
    layout.focus('s1')
    layout.lastSessionId = 's1'
    layout.setDraft('s1', 'pasted credentials')
    layout.setDraft('keep', 'another session stays')
    vi.advanceTimersByTime(300)

    layout.forget('s1')
    vi.advanceTimersByTime(300)

    expect(storedDrafts()).toEqual({ keep: 'another session stays' })
    expect(layout.tabs).toEqual([])
    expect(layout.focused).toBeNull()
    expect(layout.lastSessionId).toBeNull()
  })
})

/**
 * CW-DBA-5 (reviews-1103): the map is bounded to DRAFT_LIMIT entries by
 * dropping insertion-first keys — but a TOUCH (re-setting a draft that
 * already has a slot) kept the original slot, so the draft you keep
 * editing was the first to be evicted once the map filled. A touch is
 * use: it must move the entry to the recency tail, and eviction keeps
 * dropping only what has gone stale.
 */
describe('draft touch keeps recency (CW-DBA-5)', () => {
  it('a re-touched draft is not evicted when a newcomer overflows the limit', () => {
    for (let i = 0; i < 50; i++) layout.setDraft(`s${i}`, `text ${i}`)
    layout.setDraft('s0', 'revisited')
    layout.setDraft('newcomer', 'fresh')

    expect(layout.drafts.s0).toBe('revisited')
    expect(layout.drafts.newcomer).toBe('fresh')
    expect(layout.drafts.s1).toBeUndefined()
  })
})

/**
 * L-DBA MAJOR-1 (reviews-1103): the cross-tab drafts watcher once compared
 * the event key against `storageKey('drafts')` snapshotted in the store's
 * setup — which runs pre-login, so every rotateCacheScope()/dropCacheScope()
 * moved the drafts under a fresh physical name the snapshot could never
 * match again. The sign-out in the other tab then silently stopped emptying
 * this tab, and this tab's pagehide flush re-persisted the dead principal's
 * whole map into whoever's live bucket the pointer named (the write side
 * resolves live). These pins run the real topology: the pointer changes
 * first, the event arrives under the NEW name second — the shape the
 * in-process suite could not see while setup-snapshot and test-time key
 * were the same ephemeral name.
 */
describe('cross-tab drafts watcher survives scope transitions (L-DBA MAJOR-1)', () => {
  // Foreign-tab transitions are simulated as they actually arrive here: a
  // direct pointer rewrite plus the storage event, never the local rotate/
  // drop functions — those would fire this tab's erase bus and pre-empt the
  // stale-watcher condition under test.
  const POINTER = 'candy-code-web:cacheScope'

  it('empties the map when a removal lands under the bucket a foreign login rotated into', () => {
    layout.setDraft('s1', 'secret A')
    vi.advanceTimersByTime(300)
    const snapshotKey = storageKey('drafts') // the name the setup-time matcher knew

    globalThis.localStorage?.setItem(POINTER, 'b1b1b1b1')
    const liveKey = storageKey('drafts')
    expect(liveKey).toBe('candy-code-web:b1b1b1b1/drafts')
    expect(liveKey).not.toBe(snapshotKey)

    globalThis.localStorage?.removeItem(liveKey)
    window.dispatchEvent(new StorageEvent('storage', { key: liveKey, newValue: null }))
    expect(layout.draft('s1')).toBe('')
  })

  it('empties the map when a foreign sign-out sweeps the bucket this tab used to live in', () => {
    globalThis.localStorage?.setItem(POINTER, 'a0a0a0a0')
    layout.setDraft('s1', 'old principal text')
    vi.advanceTimersByTime(300)
    const oldKey = 'candy-code-web:a0a0a0a0/drafts'
    expect(storageKey('drafts')).toBe(oldKey)

    globalThis.localStorage?.removeItem(POINTER) // dropCacheScope, minus this tab's bus
    globalThis.localStorage?.removeItem(oldKey)
    window.dispatchEvent(new StorageEvent('storage', { key: oldKey, newValue: null }))
    // The old bucket is dead weight whoever's pointer is live now; the sweep
    // of it must still empty this tab.
    expect(layout.draft('s1')).toBe('')
  })

  it('a pagehide after such a clear cannot re-persist the dead principal into the new bucket', () => {
    globalThis.localStorage?.setItem(POINTER, 'a0a0a0a0')
    layout.setDraft('s1', 'victim draft')
    vi.advanceTimersByTime(300)
    const oldKey = 'candy-code-web:a0a0a0a0/drafts'

    globalThis.localStorage?.setItem(POINTER, 'b2b2b2b2') // foreign login rotates: the pointer is read live at write time
    globalThis.localStorage?.removeItem(oldKey) // foreign sweep of the old bucket
    window.dispatchEvent(new StorageEvent('storage', { key: oldKey, newValue: null }))

    window.dispatchEvent(new Event('pagehide'))
    vi.advanceTimersByTime(400)
    expect(layout.draft('s1')).toBe('')
    const raw = globalThis.localStorage?.getItem('candy-code-web:b2b2b2b2/drafts') ?? null
    expect(raw === null || Object.keys(JSON.parse(raw) as object).length === 0).toBe(true)
  })
})
