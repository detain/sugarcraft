import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * WEB-7b: PWA manifest + theming wired into the served index. The audit
 * proved the old "shipped" claim was a misattributed SHA — nothing existed.
 * These pins read the committed tree: source index.html, the manifest, and
 * the GENERATED dist/index.html (dist is tracked and rebuilt with every fix).
 * (.href→fileURLToPath: happy-dom replaces the global URL, so the URL
 * object itself is not one node's fs accepts.)
 */

function file(path: string): string {
  return fileURLToPath(new URL(path, import.meta.url).href)
}

function read(path: string): string {
  return readFileSync(file(path), 'utf8')
}

interface Manifest {
  name: string
  short_name: string
  start_url: string
  display: string
  theme_color: string
  background_color: string
  icons: { src: string; sizes: string; type: string }[]
}

describe('PWA manifest (WEB-7b)', () => {
  it('is wired into the source index with a manifest link and theme-color metas', () => {
    const html = read('../../index.html')
    expect(html).toContain('<link rel="manifest" href="./manifest.webmanifest">')
    expect(html).toContain('<meta name="theme-color" content="#1a1b26">')
    expect(html).toContain('<meta name="theme-color" media="(prefers-color-scheme: light)" content="#f6f7fb">')
  })

  it('survives the build into the generated dist index', () => {
    const html = read('../../dist/index.html')
    expect(html).toContain('<link rel="manifest" href="./manifest.webmanifest">')
    expect(html).toContain('theme-color')
  })

  it('declares a standalone app with the brand colours and served icons', () => {
    const manifest = JSON.parse(read('../../public/manifest.webmanifest')) as Manifest
    expect(manifest.name).toBe('CandyCode')
    expect(manifest.short_name).toBe('CandyCode')
    expect(manifest.start_url).toBe('./')
    expect(manifest.display).toBe('standalone')
    expect(manifest.theme_color).toBe('#1a1b26')
    expect(manifest.background_color).toBe('#1a1b26')
    // The PNG is the icon asset that already exists in the tree (media/icons);
    // the SVG is the favicon itself. No binary was invented here.
    expect(manifest.icons).toEqual([
      { src: './icon-240.png', sizes: '240x240', type: 'image/png' },
      { src: './favicon.svg', sizes: 'any', type: 'image/svg+xml' },
    ])
    const png = readFileSync(file('../../public/icon-240.png'))
    expect([...png.subarray(0, 8)]).toEqual([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])
    expect(read('../../dist/manifest.webmanifest')).toBe(read('../../public/manifest.webmanifest'))
    expect(read('../../dist/favicon.svg')).toContain('<svg')
  })
})

/**
 * C-WEB-5.e: the push service worker exists, but registration is CONSENT-
 * GATED — only the push store (driven by the Settings toggle) may register
 * it, never the app boot path. Pins are source reads: this lane's committed
 * dist predates the sw chunk (the weld rebuilds dist before web.yml's
 * parity gate runs), so asserting on dist/sw.js here would test the weld.
 */
describe('push service worker registration (C-WEB-5.e)', () => {
  it('ships a module worker that answers push and click', () => {
    const worker = read('../sw.ts')
    expect(worker).toContain("addEventListener('push'")
    expect(worker).toContain("addEventListener('notificationclick'")
  })

  it('registers only from the consented push store', () => {
    expect(read('../stores/push.ts')).toContain('serviceWorker.register')
    // The boot path must stay inert: an un-registered worker delivers nothing,
    // and registering one before consent would put a background script on the
    // device without the user ever saying yes.
    expect(read('../main.ts')).not.toContain('serviceWorker.register')
    expect(read('../App.vue')).not.toContain('serviceWorker.register')
  })
})

/**
 * CW-6 (reviews-1103): the focused-window click must reach the page, not just
 * the window manager. The behaviour lives in push.spec.ts / app.spec.ts (real
 * dispatched events); these are the structural legs — the branch that posts,
 * and the page channel that receives, attach, AND detaches. A respell of any
 * pinned literal is intentional: the paired behaviour tests carry the semantics,
 * these carry the wiring's presence at its exact seats.
 */
describe('notification-click route handoff (CW-6)', () => {
  it('the worker posts the validated fragment to the focused client', () => {
    const worker = read('../sw.ts')
    expect(worker).toContain("if (href !== './') focused.postMessage({ type: 'navigate', href })")
  })

  it('the worker reads the localized heading through the same cleanText gate', () => {
    // CW-12 structural leg: the server-composed `localizedTitle` may win the
    // heading, but only swept+clamped (cleanTitle) and only when non-empty —
    // respell the precedence line or bypass the cleaner and this reddens.
    const sw = read('../sw.ts')
    expect(sw).toContain("if (payload !== null && payload.localizedTitle !== '') return payload.localizedTitle")
    expect(sw).toContain('const localizedTitle = cleanTitle(record.localizedTitle)')
    // The R13 law survives the new field: `title` (session name) stays out of
    // the payload shape — cleanTitle must not be applied to record.title.
    expect(sw).not.toContain('cleanTitle(record.title)')
    expect(sw).not.toContain('record.title')
  })

  it('the app shell answers the worker channel and unbinds it on teardown', () => {
    const app = read('../App.vue')
    expect(app).toMatch(/window\.addEventListener\('message', onPushNavigate\)/)
    expect(app).toMatch(/window\.removeEventListener\('message', onPushNavigate\)/)
    // The receive-side re-validation is pinned as the exact source literal —
    // the ^ and $ anchors ride inside the matched text, so a respell (or a
    // dropped anchor) on either side of the pattern reddens this leg. The
    // forgery-refusal behaviour is carried live by app.spec.ts.
    expect(app).toContain("!/^#\\/s\\/[A-Za-z0-9_-]{1,64}$/.test(data.href)")
  })
})
