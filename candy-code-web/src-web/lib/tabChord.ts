import { readJson, writeJson } from '../stores/storage'

/**
 * The chord that picks the Nth open tab (ISS-106).
 *
 * Alt+digit is the browser's own menu accelerator on Linux desktop builds and
 * macOS Option+digit types ¡/™ instead of a digit, so the key *event* to match
 * is the physical `event.code` Digit1…Digit9 (a layout-independent scan code —
 * AZERTY's shifted glyphs included), and the chord itself is configurable so a
 * user whose browser eats Alt can move to one it does not. Ctrl+digit and
 * Super+digit are deliberately absent: browsers and the OS own those.
 */
export type TabChord = 'alt' | 'alt+shift' | 'ctrl+alt'

export const TAB_CHORDS: readonly TabChord[] = ['alt', 'alt+shift', 'ctrl+alt']

const STORAGE_KEY = 'tab-chord'

function isTabChord(value: unknown): value is TabChord {
  return typeof value === 'string' && (TAB_CHORDS as readonly string[]).includes(value)
}

export function loadTabChord(): TabChord {
  const stored = readJson<unknown>(STORAGE_KEY, 'alt')
  return isTabChord(stored) ? stored : 'alt'
}

export function saveTabChord(chord: TabChord): void {
  writeJson(STORAGE_KEY, chord)
}

export function nextTabChord(chord: TabChord): TabChord {
  return TAB_CHORDS[(TAB_CHORDS.indexOf(chord) + 1) % TAB_CHORDS.length] ?? 'alt'
}

/** Human spelling for tooltips and the toggle button label. */
export function tabChordLabel(chord: TabChord): string {
  return chord === 'alt' ? 'Alt' : chord === 'alt+shift' ? 'Alt+Shift' : 'Ctrl+Alt'
}

/** True when `event` carries exactly this chord's modifiers (ctrl/meta never over-match). */
export function matchesTabChord(event: KeyboardEvent, chord: TabChord): boolean {
  switch (chord) {
    case 'alt':
      return event.altKey && !event.ctrlKey && !event.metaKey && !event.shiftKey
    case 'alt+shift':
      return event.altKey && event.shiftKey && !event.ctrlKey && !event.metaKey
    case 'ctrl+alt':
      return event.altKey && event.ctrlKey && !event.metaKey && !event.shiftKey
  }
}

/** 1…9 from the physical digit, independent of the glyph `event.key` would carry. */
export function tabDigit(event: KeyboardEvent): number | null {
  const match = /^Digit([1-9])$/.exec(event.code)
  return match === null ? null : Number.parseInt(match[1] as string, 10)
}
