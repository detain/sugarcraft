import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, ref } from 'vue'
import { PENDING_VERB_DELAY_MS, useSlowPending } from '../slowPending'

/**
 * MA-36: the busy-verb helper. An instant action must never flash 'Asking the
 * server…'; only a pending state that outlives the grace window renames its
 * button. The timer is per-scope, and unmounting mid-pending must not leave a
 * callback behind.
 */

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('useSlowPending', () => {
  it('flips only after the grace window, and snaps back at once', async () => {
    const pending = ref(false)
    const scope = effectScope()
    const slow = scope.run(() => useSlowPending(() => pending.value))!
    expect(slow.value).toBe(false)
    pending.value = true
    await nextTick() // the watcher flushes as a job, not synchronously
    vi.advanceTimersByTime(PENDING_VERB_DELAY_MS - 1)
    expect(slow.value).toBe(false)
    vi.advanceTimersByTime(1)
    expect(slow.value).toBe(true)
    pending.value = false
    await nextTick()
    expect(slow.value).toBe(false)
    scope.stop()
  })

  it('a stop while the timer is armed cancels it — nothing fires later', () => {
    const pending = ref(true) // armed on creation via the immediate watcher
    const scope = effectScope()
    const slow = scope.run(() => useSlowPending(() => pending.value))!
    expect(slow.value).toBe(false)
    scope.stop()
    vi.advanceTimersByTime(PENDING_VERB_DELAY_MS * 5)
    expect(slow.value).toBe(false)
  })
})
