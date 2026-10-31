import { beforeEach, describe, expect, it } from 'vitest'
import { resetWebStrings, servedLocale, setWebStrings, t } from '../i18n'
import { WEB_STRINGS_EN } from '../strings.en.generated'

/**
 * C-TUI-6.f: the chrome-string lookup the migrated components share.
 * English is the base the bundle generates from `lang/en.php`; the overlay is
 * whatever a `server.strings` answer served. The contract pinned here is the
 * fallback ladder (overlay → English → raw key) and the `{placeholder}`
 * interpolation, plus the filter that keeps the overlay chrome-only.
 */
describe('web chrome i18n (C-TUI-6.f)', () => {
  beforeEach(() => {
    resetWebStrings()
  })

  it('serves English from the generated bundle with no overlay', () => {
    expect(t('web.chrome.app.settings')).toBe('settings')
    expect(t('web.chrome.banner.stopping')).toBe('The server is stopping')
    expect(WEB_STRINGS_EN['web.chrome.status.compact']).toBe('compact')
    expect(servedLocale.value).toBe('en')
  })

  it('prefers the overlay once set and falls back on reset', () => {
    setWebStrings({ 'web.chrome.app.settings': 'Einstellungen' })
    servedLocale.value = 'de'
    expect(t('web.chrome.app.settings')).toBe('Einstellungen')
    expect(t('web.chrome.app.about')).toBe('about') // untouched keys stay English
    resetWebStrings()
    expect(t('web.chrome.app.settings')).toBe('settings')
    // The label rides the bundle: a reset to English never keeps a foreign
    // locale claim (the leak this arm killed in the first draft).
    expect(servedLocale.value).toBe('en')
  })

  it('keeps non-web keys and non-string values out of the overlay', () => {
    // The ruling: server, tool and model text stay untranslated — the store
    // must not be able to smuggle them in through this door either.
    setWebStrings({ 'server.health': 'nope', 'web.chrome.z': 42 } as unknown as Record<string, string>)
    expect(t('server.health')).toBe('server.health')
    expect(t('web.chrome.z')).toBe('web.chrome.z')
  })

  it('interpolates braces placeholders and leaves unknown ones intact', () => {
    expect(t('web.chrome.app.theme', { theme: 'dark' })).toBe('theme: dark')
    expect(t('web.chrome.app.theme', {})).toBe('theme: {theme}')
    expect(t('web.chrome.app.theme')).toBe('theme: {theme}')
    expect(t('web.chrome.status.step', { step: 3, max: 25 })).toBe('step 3/25')
  })

  it('returns the raw key when neither layer knows it', () => {
    expect(t('web.chrome.nope.not.a.key')).toBe('web.chrome.nope.not.a.key')
  })
})
