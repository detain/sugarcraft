import { readJson, removeJson, writeJson } from '../stores/storage'

/**
 * C-WEB-4.b (GAP-UI-25): the web's rebindable shortcuts in one place.
 *
 * Three actions live here: the command palette (was hard-coded Ctrl/⌘+K in
 * `CommandPalette.vue`), the help overlay (`?`), and the tab chord (was the
 * whole of `lib/tabChord.ts`, stored under its own key — migrated in on
 * first read). Bindings persist per viewer through `stores/storage`, and a
 * rebind that would make two actions answer the same keypress is refused,
 * never silently shadowed.
 */
export type KeyAction = 'palette' | 'help' | 'tabs'

export const KEY_ACTIONS: readonly KeyAction[] = ['palette', 'help', 'tabs']

/** The primary modifier a binding demands. */
export type Primary = 'none' | 'ctrl' | 'ctrl-or-meta'

export interface KeyBinding {
  /** Canonical logical key, lower case ('k', '?'), or 'digit' for the physical Digit1…Digit9. */
  readonly key: string
  readonly primary: Primary
  readonly alt: boolean
  readonly shift: boolean
}

/** The tab chord vocabulary (ISS-106), owned here since the chord is one entry now. */
export type TabChord = 'alt' | 'alt+shift' | 'ctrl+alt'

export const TAB_CHORDS: readonly TabChord[] = ['alt', 'alt+shift', 'ctrl+alt']

export const DEFAULT_BINDINGS: Record<KeyAction, KeyBinding> = {
  palette: { key: 'k', primary: 'ctrl-or-meta', alt: false, shift: false },
  help: { key: '?', primary: 'none', alt: false, shift: true },
  tabs: chordBinding('alt'),
}

const STORAGE_KEY = 'keymap'
/** The pre-keymap per-chord key (`lib/tabChord.ts`). Migrated away on first read. */
const LEGACY_TAB_KEY = 'tab-chord'

export function chordBinding(chord: TabChord): KeyBinding {
  return {
    key: 'digit',
    primary: chord === 'ctrl+alt' ? 'ctrl' : 'none',
    alt: true,
    shift: chord === 'alt+shift',
  }
}

/** The chord this tab binding expresses, or null — the tab entry stays an Alt-family digit chord. */
export function bindingToChord(binding: KeyBinding): TabChord | null {
  if (binding.key !== 'digit' || !binding.alt) return null
  if (binding.primary === 'none' && !binding.shift) return 'alt'
  if (binding.primary === 'none' && binding.shift) return 'alt+shift'
  if (binding.primary === 'ctrl' && !binding.shift) return 'ctrl+alt'
  return null
}

export function nextTabChord(chord: TabChord): TabChord {
  return TAB_CHORDS[(TAB_CHORDS.indexOf(chord) + 1) % TAB_CHORDS.length] ?? 'alt'
}

/** Human spelling for tooltips, buttons and the help overlay. */
export function tabChordLabel(chord: TabChord): string {
  return chord === 'alt' ? 'Alt' : chord === 'alt+shift' ? 'Alt+Shift' : 'Ctrl+Alt'
}

function isPrimary(value: unknown): value is Primary {
  return value === 'none' || value === 'ctrl' || value === 'ctrl-or-meta'
}

function parseBinding(value: unknown): KeyBinding | null {
  if (typeof value !== 'object' || value === null) return null
  const raw = value as Record<string, unknown>
  if (typeof raw.key !== 'string' || raw.key === '') return null
  if (!isPrimary(raw.primary) || typeof raw.alt !== 'boolean' || typeof raw.shift !== 'boolean') return null
  return { key: raw.key.toLowerCase(), primary: raw.primary, alt: raw.alt, shift: raw.shift }
}

/**
 * The viewer's current bindings. A missing or corrupt `keymap` entry falls
 * back action-by-action to the defaults, and the pre-keymap `tab-chord` key
 * is folded in once — its value becomes the `tabs` entry, the folded map is
 * written, and the old key goes away (a stranger value goes too). With a
 * sound keymap already stored the legacy key is left exactly as it is: a
 * mirror `setBinding` maintains for the cross-tab pulse, not an authority.
 */
export function loadKeymap(): Record<KeyAction, KeyBinding> {
  const stored = readJson<unknown>(STORAGE_KEY, null)
  const raw = typeof stored === 'object' && stored !== null ? (stored as Record<string, unknown>) : {}
  const rawLegacy = readJson<unknown>(LEGACY_TAB_KEY, null)
  const legacy = typeof rawLegacy === 'string' && (TAB_CHORDS as readonly string[]).includes(rawLegacy) ? (rawLegacy as TabChord) : null
  const tabsBroken = raw.tabs === undefined || parseBinding(raw.tabs) === null
  const migrated = legacy !== null && tabsBroken
  const result: Record<KeyAction, KeyBinding> = {
    palette: parseBinding(raw.palette) ?? DEFAULT_BINDINGS.palette,
    help: parseBinding(raw.help) ?? DEFAULT_BINDINGS.help,
    tabs: parseBinding(raw.tabs) ?? (migrated ? chordBinding(legacy as TabChord) : DEFAULT_BINDINGS.tabs),
  }
  if (tabsBroken && rawLegacy !== null) {
    // The migration window only: fresh storage still reads defaults without
    // writing, and a sound keymap leaves its legacy mirror untouched.
    writeJson(STORAGE_KEY, result)
    removeJson(LEGACY_TAB_KEY)
  }
  return result
}

export function bindingFor(action: KeyAction): KeyBinding {
  return loadKeymap()[action]
}

/**
 * Can one keydown satisfy both primaries? `ctrl` is a subset of
 * `ctrl-or-meta` that still overlaps it — a Ctrl press answers both — while
 * `none` is disjoint from every chord.
 */
function primariesOverlap(a: Primary, b: Primary): boolean {
  return a === b || (a === 'ctrl' && b === 'ctrl-or-meta') || (a === 'ctrl-or-meta' && b === 'ctrl')
}

/**
 * Would some single keydown answer both bindings? Alt and shift are matched
 * exactly by `matchesModifiers`, so they must agree for an overlap to exist;
 * the ctrl/meta spelling only has to intersect. Comparing raw objects instead
 * let a `ctrl-or-meta` rebind double-answer a `ctrl` chord (and the reverse),
 * which is exactly the silent shadowing the refusal exists to prevent.
 */
function bindingsOverlap(a: KeyBinding, b: KeyBinding): boolean {
  return a.key === b.key && a.alt === b.alt && a.shift === b.shift && primariesOverlap(a.primary, b.primary)
}

/** Another action that already answers some keypress this binding would answer, if any. */
export function conflictingAction(binding: KeyBinding, except: KeyAction): KeyAction | null {
  const current = loadKeymap()
  for (const action of KEY_ACTIONS) {
    if (action !== except && bindingsOverlap(current[action], binding)) return action
  }
  return null
}

/**
 * Rebind one action. A binding that clashes with another action is refused —
 * the clashing action comes back, null means it landed. The `tabs` entry
 * must stay expressible as a chord, because the tab strip's tooltip and
 * digit-matching speak chords. The keymap is the truth; the legacy
 * `tab-chord` key rides along as a derived mirror only so the cross-tab
 * `storage` pulse (ISS-106, `SessionTabs.vue` listens on the old key) keeps
 * firing until that listener is retargeted.
 */
export function setBinding(action: KeyAction, binding: KeyBinding): KeyAction | null {
  if (action === 'tabs' && bindingToChord(binding) === null) return 'tabs'
  const conflict = conflictingAction(binding, action)
  if (conflict !== null) return conflict
  const next = { ...loadKeymap(), [action]: binding }
  writeJson(STORAGE_KEY, next)
  const chord = action === 'tabs' ? bindingToChord(binding) : null
  if (action === 'tabs') {
    if (chord !== null) writeJson(LEGACY_TAB_KEY, chord)
    else removeJson(LEGACY_TAB_KEY)
  }
  return null
}

/** Back to defaults for every action (the mirror goes too). */
export function resetKeymap(): void {
  removeJson(STORAGE_KEY)
  removeJson(LEGACY_TAB_KEY)
}

/** Exact modifier match — the rule `matchesTabChord` always enforced. */
export function matchesModifiers(event: KeyboardEvent, binding: KeyBinding): boolean {
  if (event.altKey !== binding.alt || event.shiftKey !== binding.shift) return false
  switch (binding.primary) {
    case 'ctrl-or-meta':
      return event.ctrlKey || event.metaKey
    case 'ctrl':
      return event.ctrlKey && !event.metaKey
    case 'none':
      return !event.ctrlKey && !event.metaKey
  }
}

/** The number 1…9 from the physical digit, independent of the glyph `event.key` would carry. */
export function digitNumber(event: KeyboardEvent): number | null {
  const match = /^Digit([1-9])$/.exec(event.code ?? '')
  return match === null ? null : Number.parseInt(match[1] as string, 10)
}

export function matchesBinding(event: KeyboardEvent, binding: KeyBinding): boolean {
  if (!matchesModifiers(event, binding)) return false
  if (binding.key === 'digit') return digitNumber(event) !== null
  return event.key.toLowerCase() === binding.key
}

/** Keys a capture must never bind: the cancel chord and lone-modifier noise. */
const UNBINDABLE = new Set(['Escape', 'Control', 'Shift', 'Alt', 'Meta', 'CapsLock', 'Tab', 'Enter', ' '])

/**
 * The binding a captured keydown stands for, for the rebind editor.
 * `'chord'` mode is the tabs entry: an Alt-family chord over a physical
 * digit only — the same three the old toggle cycled. `'key'` mode is any
 * printable chord over a letter, digit or function key.
 */
export function bindingFromEvent(event: KeyboardEvent, mode: 'key' | 'chord'): KeyBinding | null {
  if (mode === 'chord') {
    if (!event.altKey || digitNumber(event) === null) return null
    const primary: Primary = event.ctrlKey && !event.metaKey ? 'ctrl' : !event.ctrlKey && !event.metaKey ? 'none' : 'ctrl-or-meta'
    const binding: KeyBinding = { key: 'digit', primary, alt: true, shift: event.shiftKey }
    return bindingToChord(binding) === null ? null : binding
  }
  if (UNBINDABLE.has(event.key)) return null
  const key = digitNumber(event) !== null ? 'digit' : event.key.toLowerCase()
  if (key.length !== 1 && !/^f\d{1,2}$/i.test(key)) return null
  const primary: Primary = event.ctrlKey || event.metaKey ? 'ctrl-or-meta' : 'none'
  return { key, primary, alt: event.altKey, shift: event.shiftKey }
}

/** Spell a binding for readers: `Ctrl/⌘+K`, `?`, `Alt+1…9`. */
export function bindingLabel(binding: KeyBinding): string {
  const parts: string[] = []
  if (binding.primary === 'ctrl-or-meta') parts.push('Ctrl/⌘')
  else if (binding.primary === 'ctrl') parts.push('Ctrl')
  if (binding.alt) parts.push('Alt')
  // A punctuation glyph already carries its Shift on the common layouts;
  // spelling it out would only misdescribe what the user presses.
  const carriesShift = binding.key.length === 1 && !/^[a-z0-9]$/.test(binding.key)
  if (binding.shift && !carriesShift) parts.push('Shift')
  const key = binding.key === 'digit' ? '1…9' : binding.key === '?' ? '?' : binding.key.toUpperCase()
  parts.push(key)
  return parts.join('+')
}

// The rebind editor is the one place a shortcut key is *fuel*, not a
// command. It raises this flag so the global handlers stay off the event.
let captureDepth = 0

export function beginKeyCapture(): void {
  captureDepth += 1
}

export function endKeyCapture(): void {
  captureDepth = Math.max(0, captureDepth - 1)
}

export function captureActive(): boolean {
  return captureDepth > 0
}
