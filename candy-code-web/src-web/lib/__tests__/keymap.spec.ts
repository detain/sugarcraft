import { beforeEach, describe, expect, it } from 'vitest'
import {
  bindingFor,
  bindingFromEvent,
  bindingLabel,
  chordBinding,
  DEFAULT_BINDINGS,
  invalidateKeymap,
  loadKeymap,
  matchesBinding,
  resetKeymap,
  setBinding,
} from '../keymap'
import { saveTabChord } from '../tabChord'

/**
 * C-WEB-4.b: the three web shortcuts as one rebindable map — defaults,
 * per-viewer persistence through `stores/storage`, the one-time fold of the
 * old `tab-chord` key, and the refusal that keeps two actions from ever
 * answering the same chord.
 */

function key(init: KeyboardEventInit): KeyboardEvent {
  return new KeyboardEvent('keydown', init)
}

const storedKeymap = () => localStorage.getItem('candy-code-web:keymap')

describe('web keymap (C-WEB-4.b)', () => {
  beforeEach(() => {
    localStorage.clear()
    // CW-11: loadKeymap memoizes; clearing storage behind it must drop the
    // snapshot, exactly as a cross-tab pulse would in a real browser.
    invalidateKeymap()
  })

  it('starts every viewer on the shipped defaults', () => {
    expect(loadKeymap()).toEqual(DEFAULT_BINDINGS)
  })

  it('matches the palette chord as Ctrl or Cmd, and nothing narrower', () => {
    const palette = DEFAULT_BINDINGS.palette
    expect(matchesBinding(key({ key: 'k', ctrlKey: true }), palette)).toBe(true)
    expect(matchesBinding(key({ key: 'K', metaKey: true }), palette)).toBe(true)
    expect(matchesBinding(key({ key: 'k', ctrlKey: true, altKey: true }), palette)).toBe(false)
    expect(matchesBinding(key({ key: 'k', ctrlKey: true, shiftKey: true }), palette)).toBe(false)
    expect(matchesBinding(key({ key: 'k' }), palette)).toBe(false)
  })

  it('matches the help chord as a bare shifted question mark', () => {
    const help = DEFAULT_BINDINGS.help
    expect(matchesBinding(key({ key: '?', shiftKey: true }), help)).toBe(true)
    expect(matchesBinding(key({ key: '?', shiftKey: true, ctrlKey: true }), help)).toBe(false)
    expect(matchesBinding(key({ key: '?' }), help)).toBe(false)
  })

  it('matches the tab entry on the physical digit with exactly the chord mods', () => {
    const tabs = DEFAULT_BINDINGS.tabs
    expect(matchesBinding(key({ key: '¡', code: 'Digit1', altKey: true }), tabs)).toBe(true)
    expect(matchesBinding(key({ key: '1', code: 'Digit1', altKey: true, shiftKey: true }), tabs)).toBe(false)
    expect(matchesBinding(key({ key: '1', code: 'Digit1', metaKey: true, altKey: true }), tabs)).toBe(false)
    expect(matchesBinding(key({ key: '1', altKey: true }), tabs)).toBe(false) // no physical digit code → not a digit
  })

  it('persists a rebind per viewer under the keymap storage key', () => {
    expect(setBinding('palette', { key: 'p', primary: 'ctrl-or-meta', alt: false, shift: false })).toBeNull()
    expect(storedKeymap()).toContain('"p"')
    expect(loadKeymap().palette.key).toBe('p')
    expect(matchesBinding(key({ key: 'p', ctrlKey: true }), DEFAULT_BINDINGS.palette)).toBe(false)
    expect(matchesBinding(key({ key: 'p', ctrlKey: true }), loadKeymap().palette)).toBe(true)
  })

  it('refuses a chord another action already answers', () => {
    expect(setBinding('help', DEFAULT_BINDINGS.palette)).toBe('palette')
    expect(loadKeymap().help.key).toBe('?')
    expect(setBinding('palette', DEFAULT_BINDINGS.help)).toBe('help')
    expect(loadKeymap().palette.key).toBe('k')
    expect(storedKeymap()).toBeNull() // nothing landed, so nothing was written
  })

  it('keeps the tab entry inside the Alt-family digit chords', () => {
    expect(setBinding('tabs', { key: 'k', primary: 'none', alt: false, shift: false })).toBe('tabs')
    expect(setBinding('tabs', { key: 'digit', primary: 'ctrl-or-meta', alt: true, shift: false })).toBe('tabs')
    expect(setBinding('tabs', chordBinding('alt+shift'))).toBeNull()
    expect(loadKeymap().tabs).toEqual(chordBinding('alt+shift'))
  })

  it('folds the old tab-chord key into the tabs entry once', () => {
    localStorage.setItem('candy-code-web:tab-chord', '"ctrl+alt"')
    expect(loadKeymap().tabs).toEqual(chordBinding('ctrl+alt'))
    expect(storedKeymap()).toContain('"primary":"ctrl"')
    expect(localStorage.getItem('candy-code-web:tab-chord')).toBeNull()
  })

  it('falls back action-by-action over corrupt storage, stranger chords included', () => {
    localStorage.setItem('candy-code-web:keymap', 'not json at all')
    expect(loadKeymap()).toEqual(DEFAULT_BINDINGS)
    localStorage.clear()
    localStorage.setItem('candy-code-web:tab-chord', '"hyper"')
    // CW-11: a same-file raw write is invisible to the memo until the pulse a
    // stranger tab's write really fires is simulated.
    window.dispatchEvent(new StorageEvent('storage', { key: 'candy-code-web:tab-chord' }))
    expect(loadKeymap().tabs).toEqual(DEFAULT_BINDINGS.tabs)
    expect(localStorage.getItem('candy-code-web:tab-chord')).toBeNull()
    localStorage.setItem('candy-code-web:keymap', '{"palette":42}')
    window.dispatchEvent(new StorageEvent('storage', { key: 'candy-code-web:keymap' }))
    expect(loadKeymap().palette).toEqual(DEFAULT_BINDINGS.palette)
  })

  it('reads only bindable chords in the editor', () => {
    expect(bindingFromEvent(key({ key: 'k', ctrlKey: true }), 'key')).toEqual(DEFAULT_BINDINGS.palette)
    expect(bindingFromEvent(key({ key: '?', shiftKey: true }), 'key')).toEqual(DEFAULT_BINDINGS.help)
    expect(bindingFromEvent(key({ key: 'Escape' }), 'key')).toBeNull()
    expect(bindingFromEvent(key({ key: 'Control' }), 'key')).toBeNull()
    expect(bindingFromEvent(key({ key: 'Enter' }), 'key')).toBeNull()
    expect(bindingFromEvent(key({ key: '1', code: 'Digit1', altKey: true }), 'chord')).toEqual(chordBinding('alt'))
    expect(bindingFromEvent(key({ key: '1', code: 'Digit1', altKey: true, ctrlKey: true, metaKey: true }), 'chord')).toBeNull()
    expect(bindingFromEvent(key({ key: 'k', altKey: true }), 'chord')).toBeNull()
  })

  it('spells bindings the way the overlay and settings show them', () => {
    expect(bindingLabel(DEFAULT_BINDINGS.palette)).toBe('Ctrl/⌘+K')
    expect(bindingLabel(DEFAULT_BINDINGS.help)).toBe('?')
    expect(bindingLabel(chordBinding('ctrl+alt'))).toBe('Ctrl+Alt+1…9')
    expect(bindingLabel({ key: 'f', primary: 'none', alt: false, shift: true })).toBe('Shift+F')
  })

  it('lets a saved tab chord ride the legacy key as a pulse mirror only', () => {
    saveTabChord('alt+shift')
    expect(localStorage.getItem('candy-code-web:tab-chord')).toBe('"alt+shift"')
    localStorage.setItem('candy-code-web:tab-chord', '"alt"') // stale mirror: the keymap stays the truth
    expect(loadKeymap().tabs).toEqual(chordBinding('alt+shift'))
  })

  it('performs no storage write on a plain load', () => {
    // Fresh: reading defaults must not materialize them.
    loadKeymap()
    expect(localStorage.getItem('candy-code-web:keymap')).toBeNull()
    expect(localStorage.getItem('candy-code-web:tab-chord')).toBeNull()
    // Sound keymap with a stale mirror: load must neither rewrite nor sweep.
    setBinding('tabs', chordBinding('ctrl+alt'))
    localStorage.setItem('candy-code-web:tab-chord', '"alt"')
    const keymapBefore = localStorage.getItem('candy-code-web:keymap')
    expect(loadKeymap().tabs).toEqual(chordBinding('ctrl+alt'))
    expect(localStorage.getItem('candy-code-web:keymap')).toBe(keymapBefore)
    expect(localStorage.getItem('candy-code-web:tab-chord')).toBe('"alt"')
  })

  it('rides the legacy mirror on tab saves only', () => {
    setBinding('tabs', chordBinding('ctrl+alt'))
    localStorage.setItem('candy-code-web:tab-chord', '"alt"') // sentinel: a non-tabs save must leave it be
    const keymapBefore = localStorage.getItem('candy-code-web:keymap')
    expect(setBinding('palette', { key: 'p', primary: 'ctrl-or-meta', alt: false, shift: false })).toBeNull()
    expect(localStorage.getItem('candy-code-web:keymap')).not.toBe(keymapBefore) // the save itself landed…
    expect(localStorage.getItem('candy-code-web:tab-chord')).toBe('"alt"') // …and touched nothing else
    expect(setBinding('tabs', chordBinding('alt+shift'))).toBeNull()
    expect(localStorage.getItem('candy-code-web:tab-chord')).toBe('"alt+shift"') // tab saves mirror the chord
  })

  it('refuses overlaps, not just exact duplicates', () => {
    setBinding('tabs', chordBinding('ctrl+alt')) // the shape the review names: Ctrl+Alt+digit answers both
    const keymapAtSeed = localStorage.getItem('candy-code-web:keymap')
    expect(setBinding('help', { key: 'digit', primary: 'ctrl-or-meta', alt: true, shift: false })).toBe('tabs')
    expect(setBinding('help', { key: 'k', primary: 'ctrl', alt: false, shift: false })).toBe('palette') // Ctrl ⊂ Ctrl/⌘
    expect(loadKeymap().help).toEqual(DEFAULT_BINDINGS.help)
    expect(localStorage.getItem('candy-code-web:keymap')).toBe(keymapAtSeed) // refusals write nothing
    // A shift the other binding cannot carry makes no press answer both — bindable.
    expect(setBinding('help', { key: 'k', primary: 'ctrl', alt: false, shift: true })).toBeNull()
    expect(loadKeymap().help).toEqual({ key: 'k', primary: 'ctrl', alt: false, shift: true })
  })
})

/**
 * CW-11: the parsed map is memoized — `bindingFor` runs under every keydown,
 * and each lookup used to re-read and re-parse both storage keys. The cache
 * must stay coherent with module writes, real cross-tab pulses, and resets,
 * and the shared snapshot must not be writable.
 */
describe('keymap memoization (CW-11)', () => {
  beforeEach(() => {
    localStorage.clear()
    invalidateKeymap()
  })

  it('serves repeated lookups from one pair of storage reads', () => {
    const reads: string[] = []
    // happy-dom wraps Storage in a Proxy whose set trap swallows method
    // assignment (and whose binder caches getItem as an own property), so the
    // only interception that reaches production is defineProperty — the trap
    // forwards it to the real target.
    const store = localStorage
    const real = store.getItem
    Object.defineProperty(store, 'getItem', {
      value: (k: string) => {
        reads.push(k)
        return real.call(store, k)
      },
      configurable: true,
      writable: true,
    })
    try {
      expect(bindingFor('palette')).toEqual(DEFAULT_BINDINGS.palette)
      const afterFirst = reads.filter((k) => k.startsWith('candy-code-web:')).length
      expect(bindingFor('palette')).toEqual(DEFAULT_BINDINGS.palette)
      expect(bindingFor('help')).toEqual(DEFAULT_BINDINGS.help)
      expect(loadKeymap().tabs).toEqual(DEFAULT_BINDINGS.tabs)
      const afterMore = reads.filter((k) => k.startsWith('candy-code-web:')).length
      expect(afterFirst).toBe(2) // keymap + legacy mirror, exactly once per invalidation
      expect(afterMore).toBe(afterFirst)
    } finally {
      Object.defineProperty(store, 'getItem', { value: real, configurable: true, writable: true })
    }
  })

  it('re-derives when a stranger pulses either key, or clears storage', () => {
    loadKeymap()
    localStorage.setItem('candy-code-web:keymap', '{"palette":{"key":"j","primary":"ctrl","alt":false,"shift":false}}')
    window.dispatchEvent(new StorageEvent('storage', { key: 'candy-code-web:keymap' }))
    expect(bindingFor('palette')).toEqual({ key: 'j', primary: 'ctrl', alt: false, shift: false })

    localStorage.setItem('candy-code-web:keymap', 'garbage')
    localStorage.setItem('candy-code-web:tab-chord', '"ctrl+alt"')
    window.dispatchEvent(new StorageEvent('storage', { key: 'candy-code-web:tab-chord' }))
    expect(bindingFor('tabs')).toEqual(chordBinding('ctrl+alt')) // the fold rides the re-derive too

    localStorage.setItem('candy-code-web:keymap', '{"palette":{"key":"j","primary":"ctrl","alt":false,"shift":false}}')
    window.dispatchEvent(new StorageEvent('storage', { key: 'candy-code-web:keymap' }))
    expect(bindingFor('palette')).toEqual({ key: 'j', primary: 'ctrl', alt: false, shift: false })
    localStorage.clear()
    window.dispatchEvent(new StorageEvent('storage', { key: null })) // a keyless event = storage.clear()
    // Without the keyless branch the memo would still answer 'j' here — an
    // empty storage may only ever yield the shipped defaults.
    expect(bindingFor('palette')).toEqual(DEFAULT_BINDINGS.palette)
  })

  it('keeps the snapshot coherent across module writes and resets', () => {
    expect(setBinding('palette', { key: 'p', primary: 'ctrl-or-meta', alt: false, shift: false })).toBeNull()
    expect(bindingFor('palette')).toEqual({ key: 'p', primary: 'ctrl-or-meta', alt: false, shift: false })
    resetKeymap()
    expect(bindingFor('palette')).toEqual(DEFAULT_BINDINGS.palette)
  })

  it('refuses to hand out a writable shared snapshot', () => {
    const snapshot = loadKeymap()
    expect(() => {
      ;(snapshot as unknown as Record<string, unknown>).palette = 'tampered'
    }).toThrow()
    expect(loadKeymap()).toBe(snapshot) // the memo is THE instance until invalidated
  })
})
