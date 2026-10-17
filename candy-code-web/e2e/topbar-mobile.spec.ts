import { expect, test } from './fixtures/server'

/**
 * ISS-105: at phone width the top bar must not push the page into horizontal
 * scroll. The bar wraps into two rows (actions under brand and status) once
 * the viewport drops to 480px, and the whole document stays inner-width wide.
 */
test.describe('top bar on a phone', () => {
  test('nothing overflows the viewport at 375px', async ({ page, server }) => {
    await page.setViewportSize({ width: 375, height: 740 })
    await page.goto(server.freshSignInUrl())
    await expect(page.getByTestId('connection-status')).toContainText('connected')
    await expect(page.getByTestId('dashboard')).toBeVisible()

    const widths = await page.evaluate(() => ({
      document: document.documentElement.scrollWidth,
      inner: window.innerWidth,
      bar: (() => {
        const bar = document.querySelector('.topbar')!
        return { scroll: bar.scrollWidth, client: bar.clientWidth }
      })(),
    }))
    expect(widths.document, `document scrollWidth ${widths.document} overflows ${widths.inner}`).toBeLessThanOrEqual(widths.inner)
    expect(widths.bar.scroll, `topbar scrollWidth ${widths.bar.scroll} overflows ${widths.bar.client}`).toBeLessThanOrEqual(widths.bar.client)
  })

  test('the wrapped bar keeps every action reachable', async ({ page, server }) => {
    await page.setViewportSize({ width: 375, height: 740 })
    await page.goto(server.freshSignInUrl())
    await expect(page.getByTestId('connection-status')).toContainText('connected')

    await expect(page.getByTestId('settings-link')).toBeVisible()
    await expect(page.getByRole('button', { name: /sign out/ })).toBeVisible()
    await page.getByTestId('settings-link').click()
    await expect(page).toHaveURL(/#\/settings/)
  })
})
