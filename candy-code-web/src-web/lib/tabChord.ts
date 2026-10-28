import { bindingFor, bindingToChord, chordBinding, digitNumber, matchesModifiers, setBinding, type TabChord } from './keymap'

/**
 * The chord that picks the Nth open tab (ISS-106).
 *
 * Alt+digit is the browser's own menu accelerator on Linux desktop builds and
 * macOS Option+digit types ¡/™ instead of a digit, so the key *event* to match
 * is the physical `event.code` Digit1…Digit9 (a layout-independent scan code —
 * AZERTY's shifted glyphs included), and the chord itself is configurable so a
 * user whose browser eats Alt can move to one it does not. Ctrl+digit and
 * Super+digit are deliberately absent: browsers and the OS own those.
 *
 * C-WEB-4.b: the chord is one entry of `lib/keymap` now. This module keeps
 * speaking chords for its callers — `SessionTabs.vue` unchanged — and the old
 * `tab-chord` storage key migrates into the keymap on first read (a derived
 * mirror of a *saved* chord rides the old key only so the cross-tab storage
 * pulse keeps firing; the keymap is the truth).
 */
export type { TabChord } from './keymap'
export { TAB_CHORDS, nextTabChord, tabChordLabel } from './keymap'

export function loadTabChord(): TabChord {
  return bindingToChord(bindingFor('tabs')) ?? 'alt'
}

export function saveTabChord(chord: TabChord): void {
  setBinding('tabs', chordBinding(chord))
}

/** True when `event` carries exactly this chord's modifiers (ctrl/meta never over-match). */
export function matchesTabChord(event: KeyboardEvent, chord: TabChord): boolean {
  return matchesModifiers(event, chordBinding(chord))
}

/** 1…9 from the physical digit, independent of the glyph `event.key` would carry. */
export function tabDigit(event: KeyboardEvent): number | null {
  return digitNumber(event)
}
