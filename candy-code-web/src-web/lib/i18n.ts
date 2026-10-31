// C-TUI-6.f: the web UI's chrome strings.
//
// The English baseline is the committed mirror of candy-code's chrome
// catalogue; `server.strings` (locale.ts) may lay a chosen locale OVER it,
// and any key neither the overlay nor the baseline carries renders as the
// raw key — loud on screen, never a silent blank.
//
// Owner ruling scopes this module: chrome/UI strings only. Server, tool and
// model text is never translated and never arrives through setWebStrings;
// the PHP side (WebStrings.php) enforces the same `web.` prefix at its
// choke, so the two ends share one contract.

import { shallowRef } from 'vue'
import { WEB_STRINGS_EN } from './strings.en.generated'

/** Keys arrive in `web.chrome.<area>.<name>` form; anything else is passed through. */
export type WebStringKey = string

type Params = Record<string, string | number>

/** Server-served locale map layered over the English baseline (locale.ts owns it). */
const strings = shallowRef<Record<string, string>>({})

/** Replace the overlay (full map per fetch — the wire always sends the whole bundle). */
export function setWebStrings(map: Record<string, string>): void {
  const clean: Record<string, string> = {}
  for (const [key, value] of Object.entries(map)) {
    if (key.startsWith('web.') && typeof value === 'string') {
      clean[key] = value
    }
  }
  strings.value = clean
}

/** Drop the overlay back to the committed English (used by tests and on connection loss). */
export function resetWebStrings(): void {
  strings.value = {}
  // The label belongs to the bundle: an 'en' chrome must never claim 'de'.
  servedLocale.value = 'en'
}

/** What locale the overlay currently answers in, for debug surfaces. `en` = baseline. */
export const servedLocale = shallowRef('en')

function interpolate(template: string, params: Params | undefined): string {
  if (params === undefined) return template
  return template.replace(/\{([A-Za-z_][A-Za-z0-9_]*)\}/g, (whole: string, name: string): string =>
    Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : whole,
  )
}

/** The chrome string for a key, interpolated with params; English until a server answers. */
export function t(key: WebStringKey, params?: Params): string {
  const value = strings.value[key] ?? WEB_STRINGS_EN[key]
  return interpolate(value ?? key, params)
}
