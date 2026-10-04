import { expect, test } from './fixtures/server'

test.describe('signing in', () => {
  test('the sign-in link signs the browser in and drops the code from the address bar', async ({ page, server }) => {
    await page.goto(server.freshSignInUrl())
    await expect(page.getByTestId('connection-status')).toContainText('connected')
    await expect(page.getByTestId('dashboard')).toBeVisible()
    expect(new URL(page.url()).hash).toBe('#/')
    await expect(page.locator('.topbar .root')).toHaveText(server.repo)
  })

  test('a browser without a session is asked to sign in, and the token works', async ({ page, server }) => {
    await page.goto(server.baseURL)
    await expect(page.getByTestId('login')).toBeVisible()
    await expect(page.getByTestId('connection-status')).toHaveText('signed out')

    await page.getByTestId('login-secret').fill(server.token)
    await page.getByTestId('login-submit').click()
    await expect(page.getByTestId('connection-status')).toContainText('connected')
    await expect(page.getByTestId('dashboard')).toBeVisible()
  })

  test('a spent sign-in code is refused', async ({ page, server }) => {
    const url = server.freshSignInUrl()
    const first = await page.context().newPage()
    await first.goto(url)
    await expect(first.getByTestId('connection-status')).toContainText('connected')

    const other = await page.context().browser()!.newContext()
    const second = await other.newPage()
    await second.goto(url)
    await expect(second.getByTestId('login')).toBeVisible()
    await other.close()
  })
})
