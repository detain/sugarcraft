import { beforeEach, describe, expect, it } from 'vitest'
import { loadTabChord, matchesTabChord, nextTabChord, saveTabChord, tabChordLabel, tabDigit, TAB_CHORDS } from '../tabChord'

function key(mods: Partial<KeyboardEventInit>): KeyboardEvent {
  return new KeyboardEvent('keydown', { code: 'Digit3', ...mods })
}

describe('tab chord (ISS-106)', () => {
  beforeEach(() => localStorage.clear())

  it('reads the physical digit regardless of the glyph', () => {
    expect(tabDigit(key({ altKey: true, key: '¡' }))).toBe(3)
    expect(tabDigit(new KeyboardEvent('keydown', { code: 'Digit9' }))).toBe(9)
    expect(tabDigit(new KeyboardEvent('keydown', { code: 'Digit0' }))).toBeNull()
    expect(tabDigit(new KeyboardEvent('keydown', { code: 'KeyJ' }))).toBeNull()
    expect(tabDigit(new KeyboardEvent('keydown', { code: 'Numpad3' }))).toBeNull()
  })

  it('matches each chord exactly, and never a lone modifier-less digit', () => {
    expect(matchesTabChord(key({ altKey: true }), 'alt')).toBe(true)
    expect(matchesTabChord(key({ altKey: true, shiftKey: true }), 'alt')).toBe(false)
    expect(matchesTabChord(key({ altKey: true, shiftKey: true }), 'alt+shift')).toBe(true)
    expect(matchesTabChord(key({ altKey: true, ctrlKey: true }), 'ctrl+alt')).toBe(true)
    expect(matchesTabChord(key({ altKey: true, metaKey: true }), 'alt')).toBe(false)
    expect(matchesTabChord(key({}), 'alt')).toBe(false)
    expect(matchesTabChord(key({ ctrlKey: true }), 'alt')).toBe(false)
  })

  it('cycles through every offer and back to Alt', () => {
    expect(nextTabChord('alt')).toBe('alt+shift')
    expect(nextTabChord('alt+shift')).toBe('ctrl+alt')
    expect(nextTabChord('ctrl+alt')).toBe('alt')
    expect(TAB_CHORDS.every((chord) => tabChordLabel(chord).length > 0)).toBe(true)
  })

  it('falls back to Alt when storage holds a stranger', () => {
    expect(loadTabChord()).toBe('alt')
    saveTabChord('ctrl+alt')
    expect(loadTabChord()).toBe('ctrl+alt')
    localStorage.setItem('candy-code-web:tab-chord', '"hyper"')
    expect(loadTabChord()).toBe('alt')
  })
})
