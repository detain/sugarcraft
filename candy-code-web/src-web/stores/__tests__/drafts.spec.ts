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
