import { onScopeDispose, ref, watch, type Ref } from 'vue'

/**
 * MA-36: the app's async surfaces were disabled-only — grey is not a status.
 * The fix on each surface is the pending verb the catalog already ships, but a
 * label that flashes for 40 ms on a fast round-trip is noise, not feedback.
 * This turns a `pending` source into a SLOW pending flag: it flips true only
 * after the wait has lasted `delayMs`, and snaps back to false the moment the
 * work settles. A response under the threshold never changes the label at all.
 */
export const PENDING_VERB_DELAY_MS = 200

export function useSlowPending(pending: () => boolean, delayMs = PENDING_VERB_DELAY_MS): Ref<boolean> {
  const slow = ref(false)
  let timer: ReturnType<typeof setTimeout> | null = null
  const stop = (): void => {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }
  watch(
    pending,
    (isPending) => {
      stop()
      if (isPending) {
        timer = setTimeout(() => {
          timer = null
          slow.value = true
        }, delayMs)
      } else {
        slow.value = false
      }
    },
    // Immediate so a component mounted WHILE a request is already in flight arms
    // the verb too — a post-mount-only watcher would never flip for it.
    { immediate: true },
  )
  onScopeDispose(stop)
  return slow
}
