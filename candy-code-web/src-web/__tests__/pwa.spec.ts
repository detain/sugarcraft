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
