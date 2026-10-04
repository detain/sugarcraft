import { expect, newSession, openApp, send, signIn, test } from './fixtures/server'

test('two tabs on one session: both stream, the first answer wins, the other card closes', async ({ page, server }) => {
  await openApp(page, server)
  const id = await newSession(page)

  const other = await page.context().newPage()
  await other.goto(`${server.baseURL}/#/s/${id}`)
  await expect(other.getByTestId('session-view')).toHaveAttribute('data-phase', 'live')

  await send(page, '::tool Bash {"command":"echo two-tabs"}')
  const mine = page.getByTestId('permission-card')
  const theirs = other.getByTestId('permission-card')
  await expect(mine).toBeVisible()
  await expect(theirs).toBeVisible()
  expect(await mine.getAttribute('data-ask-id')).toBe(await theirs.getAttribute('data-ask-id'))

  await theirs.getByTestId('ask-once').click()
  await expect(mine).toHaveCount(0)
  await expect(theirs).toHaveCount(0)

  for (const tab of [page, other]) {
    await expect(tab.getByTestId('tool-card').filter({ hasText: 'Bash' })).toHaveAttribute('data-status', 'done')
    await expect(tab.locator('[data-kind="assistant"]').last()).toContainText('two-tabs')
  }
})

test('a question in a session this tab is not viewing still shows in the sidebar', async ({ page, server, browser }) => {
  await openApp(page, server)
  const watched = await newSession(page)
  const background = await newSession(page)
  await page.locator(`[data-testid="session-link"][data-session-id="${watched}"]`).click()
  await expect(page.getByTestId('session-view')).toHaveAttribute('data-session-id', watched)

  const elsewhere = await browser.newContext()
  await signIn(elsewhere, server)
  const tab = await elsewhere.newPage()
  await tab.goto(`${server.baseURL}/#/s/${background}`)
  await expect(tab.getByTestId('session-view')).toHaveAttribute('data-phase', 'live')
  await send(tab, '::tool Bash {"command":"echo background"}')
  await expect(tab.getByTestId('permission-card')).toBeVisible()

  // permission.pending refreshes on every server.tick (15 s) — or at once on a reconnect.
  await expect(page.locator(`[data-testid="session-link"][data-session-id="${background}"] [data-testid="ask-badge"]`)).toHaveText('1', { timeout: 40_000 })
  await tab.getByTestId('ask-reject').click()
  await elsewhere.close()
})

test('a tab that opens mid-turn sees the running call and its open question', async ({ page, server }) => {
  await openApp(page, server)
  const id = await newSession(page)
  await send(page, '::tool Bash {"command":"echo mid-turn"}')
  await expect(page.getByTestId('permission-card')).toBeVisible()

  const late = await page.context().newPage()
  await late.goto(`${server.baseURL}/#/s/${id}`)
  await expect(late.getByTestId('tool-card').filter({ hasText: 'Bash' })).toHaveAttribute('data-status', 'running')
  await expect(late.getByTestId('permission-card')).toBeVisible()
  await expect(late.getByTestId('status-bar')).toHaveAttribute('data-status', 'waiting_permission')

  await late.getByTestId('ask-once').click()
  await expect(late.getByTestId('tool-card').filter({ hasText: 'Bash' })).toHaveAttribute('data-status', 'done')
  await expect(page.getByTestId('tool-card').filter({ hasText: 'Bash' })).toHaveAttribute('data-status', 'done')
})
