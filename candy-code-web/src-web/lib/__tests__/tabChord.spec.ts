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

  it('folds the old stored chord into the keymap once, then the keymap is the truth', () => {
    expect(loadTabChord()).toBe('alt')
    localStorage.setItem('candy-code-web:tab-chord', '"ctrl+alt"')
    expect(loadTabChord()).toBe('ctrl+alt')
    expect(localStorage.getItem('candy-code-web:keymap')).toContain('"primary":"ctrl"')
    expect(localStorage.getItem('candy-code-web:tab-chord')).toBeNull()
    localStorage.setItem('candy-code-web:tab-chord', '"hyper"') // a stranger now rides a dead key
    expect(loadTabChord()).toBe('ctrl+alt')
    saveTabChord('alt+shift')
    expect(loadTabChord()).toBe('alt+shift')
    expect(localStorage.getItem('candy-code-web:tab-chord')).toBe('"alt+shift"') // the pulse mirror, not the authority
  })
})
