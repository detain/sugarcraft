import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import { useSettingsStore, type ThemeChoice } from '../settings'

/**
 * C-TUI-4.b: the theme choice widened from three to five. jsdom/happy-dom
 * never applies tokens.css to the DOM, so every visual contract here is a
 * SOURCE SCAN of tokens.css (the store↔CSS handshake) plus the dataset the
 * store itself writes; the colour math lives in `styles/__tests__/contrast.spec.ts`.
 */
const css = readFileSync(resolve(process.cwd(), 'src-web/styles/tokens.css'), 'utf8')

type Settings = ReturnType<typeof useSettingsStore>

function freshStore(): Settings {
  setActivePinia(createPinia())
  return useSettingsStore()
}

async function step(store: Settings): Promise<void> {
  store.cycleTheme()
  await nextTick() // the store's watch flushes 'pre' — persistence and dataset land on the tick
}

beforeEach(() => {
  globalThis.localStorage.clear()
  document.documentElement.removeAttribute('data-theme')
})

describe('settings store — theme cycle (C-TUI-4.b)', () => {
  it('cycles over all five choices in order and wraps to auto', async () => {
    const store = freshStore()
    const seen: ThemeChoice[] = [store.theme]
    for (let i = 0; i < 5; i++) {
      await step(store)
      seen.push(store.theme)
    }
    expect(seen).toEqual(['auto', 'dark', 'light', 'dracula', 'tokyoNight', 'auto'])
  })

  it('spells the Tokyo Night choice exactly as the TUI names it', async () => {
    const store = freshStore()
    for (let i = 0; i < 4; i++) await step(store)
    expect(store.theme).toBe('tokyoNight')
    expect(document.documentElement.dataset.theme).toBe('tokyoNight')
  })

  it('persists the choice under the theme key', async () => {
    const store = freshStore()
    await step(store) // auto -> dark
    await step(store) // dark -> light
    await step(store) // light -> dracula
    expect(globalThis.localStorage.getItem('candy-code-web:theme')).toBe('"dracula"')
  })

  it('deletes the data-theme attribute when the cycle returns to auto', async () => {
    const store = freshStore()
    await step(store)
    expect(document.documentElement.dataset.theme).toBe('dark')
    for (let i = 0; i < 4; i++) await step(store) // all the way back round to auto
    expect(document.documentElement.dataset.theme).toBeUndefined()
  })

  it('hands every cycled-to theme a token block in tokens.css (source scan)', () => {
    // light, dracula and tokyoNight own explicit [data-theme] blocks;
    // 'dark' rides the :root default block and its attribute only stands
    // off the prefers-color-scheme: light media query — both shapes pinned.
    for (const choice of ['light', 'dracula', 'tokyoNight'] as const) {
      expect(css, choice).toContain(`:root[data-theme='${choice}'] {`)
    }
    expect(css).toContain(":root:not([data-theme='dark'])")
    expect(css).toContain(':root {')
  })

  it('survives a legacy stored value: renders the default, first cycle returns to auto', async () => {
    globalThis.localStorage.setItem('candy-code-web:theme', '"halloween"')
    const store = freshStore()
    // Honest restoration: the value is kept, and with no matching block the
    // :root dark default paints — the app is never wedged, only un-themed.
    expect(store.theme).toBe('halloween' as ThemeChoice)
    expect(document.documentElement.dataset.theme).toBe('halloween')
    await step(store)
    expect(store.theme).toBe('auto')
    expect(document.documentElement.dataset.theme).toBeUndefined()
  })

  it('falls back to auto when the stored value is not even JSON', () => {
    globalThis.localStorage.setItem('candy-code-web:theme', 'not json at all')
    const store = freshStore()
    expect(store.theme).toBe('auto')
  })
})
