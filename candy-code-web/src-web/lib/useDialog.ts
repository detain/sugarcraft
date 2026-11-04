import { nextTick, onScopeDispose, toValue, watch, type MaybeRefOrGetter, type Ref } from 'vue'

/**
 * CW-UX-07: the app's dialogs were wired one by one, and each one stopped at a
 * different point — six declared `aria-modal` while three did not, most moved
 * focus somewhere on open, none ever trapped Tab inside the dialog or carried
 * focus back to the opener on close. A keyboard user could Tab out of a modal
 * that had just told the assistive tech "everything else is inert". This is the
 * shared spine every `role="dialog"` consumes: focus-on-open, Tab trap,
 * optional Escape-to-close, focus-restore on close, and — for the inline
 * modals that have no backdrop element — pointerdown-outside dismissal.
 */

/** What the WAI-ARIA dialog pattern counts as tabbable inside the dialog. */
const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ')

export interface UseDialogOptions {
  /** The dialog root element (`role="dialog"` lives on this node). */
  panel: Ref<HTMLElement | null>
  /** Reactive open state: the false→true edge focuses, the true→false edge restores. */
  open: MaybeRefOrGetter<boolean>
  /** What Escape and a pointerdown outside press. Required with `handleEscape`. */
  onClose?: () => void
  /** Explicit first focus; defaults to the first tabbable inside the panel, else the panel. */
  focusOn?: () => HTMLElement | null | undefined
  /** Let Escape close (default true). False where the component already owns Escape. */
  handleEscape?: boolean
  /** Close on a pointerdown outside the panel — the backdrop equivalent for inline modals. */
  outsidePointerClose?: boolean
}

export interface DialogBinding {
  /** Bind on the dialog root: `@keydown="dialog.onKeydown"`. */
  onKeydown: (event: KeyboardEvent) => void
}

export function useDialog(options: UseDialogOptions): DialogBinding {
  const handleEscape = options.handleEscape !== false
  if (handleEscape && options.onClose === undefined) {
    throw new Error('useDialog: onClose is required')
  }
  let opener: HTMLElement | null = null
  let lastOpen = false

  const restoreFocus = (): void => {
    if (opener !== null && opener.isConnected) opener.focus()
    opener = null
  }

  const focusInside = async (): Promise<void> => {
    await nextTick()
    const root = options.panel.value
    if (root === null) return
    const target = options.focusOn?.() ?? root.querySelector<HTMLElement>(FOCUSABLE) ?? root
    target.focus()
  }

  const onPointerDown = (event: Event): void => {
    const root = options.panel.value
    const target = event.target
    if (root !== null && target instanceof Node && !root.contains(target)) options.onClose?.()
  }

  const armOutside = (on: boolean): void => {
    if (!options.outsidePointerClose) return
    if (on) document.addEventListener('pointerdown', onPointerDown)
    else document.removeEventListener('pointerdown', onPointerDown)
  }

  watch(
    () => toValue(options.open),
    (isOpen, wasOpen) => {
      if (isOpen === wasOpen) return
      if (isOpen) {
        opener = document.activeElement instanceof HTMLElement ? document.activeElement : null
        lastOpen = true
        armOutside(true)
        void focusInside()
      } else {
        lastOpen = false
        armOutside(false)
        restoreFocus()
      }
    },
    // Immediate so a dialog MOUNTED open (HistorySearch: the parent v-ifs it)
    // still captures its opener and takes focus — a post-mount watcher would
    // miss the edge entirely.
    { immediate: true },
  )

  // A dialog torn down while open (v-if on the whole subtree) restores just
  // like a graceful close: the unmount is the close.
  onScopeDispose(() => {
    if (lastOpen) {
      lastOpen = false
      armOutside(false)
      restoreFocus()
    }
  })

  function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      if (!handleEscape) return
      event.preventDefault()
      options.onClose?.()
      return
    }
    if (event.key !== 'Tab') return
    const root = options.panel.value
    if (root === null) return
    const focusables = [...root.querySelectorAll<HTMLElement>(FOCUSABLE)]
    if (focusables.length === 0) {
      event.preventDefault()
      root.focus()
      return
    }
    const first = focusables[0]
    const last = focusables[focusables.length - 1]
    if (first === undefined || last === undefined) return
    const active = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const outside = active === null || active === root || !root.contains(active)
    if (event.shiftKey) {
      if (outside || active === first) {
        event.preventDefault()
        last.focus()
      }
    } else if (outside || active === last) {
      event.preventDefault()
      first.focus()
    }
  }

  return { onKeydown }
}
