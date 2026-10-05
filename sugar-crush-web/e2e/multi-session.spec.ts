import type { Page } from '@playwright/test'
import { RpcClient } from './fixtures/rpc'
import { expect, newSession, openApp, test } from './fixtures/server'

/**
 * Roadmap O-6a: watching several sessions at once — tabs, the tiled grid
 * (the focused tile streams, the others are narrated by the server), and the
 * cross-session approvals drawer with its desktop notifications.
 */

/** Start one more session: the view still shows $previous until the route moves. */
async function anotherSession(page: Page, previous: string): Promise<string> {
  await page.getByTestId('new-session').click()
  const view = page.getByTestId('session-view')
  await expect(view).not.toHaveAttribute('data-session-id', previous)
  await expect(view).toHaveAttribute('data-phase', 'live')
  return (await view.getAttribute('data-session-id')) ?? ''
}

test('the grid watches two sessions at once, and each tile prompts and answers in place', async ({ page, server }) => {
  await openApp(page, server)
  const first = await newSession(page)
  const second = await anotherSession(page, first)
  await expect(page.getByTestId('session-tab')).toHaveCount(2)

  await page.getByTestId('layout-grid').click()
  const one = page.locator(`[data-testid="grid-tile"][data-session-id="${first}"]`)
  const two = page.locator(`[data-testid="grid-tile"][data-session-id="${second}"]`)
  await expect(page.getByTestId('grid-tile')).toHaveCount(2)
  await expect(one).toHaveAttribute('data-phase', 'live')
  await expect(two).toHaveAttribute('data-phase', 'live')
  await expect(two).toHaveAttribute('data-focused', 'true')

  await one.getByTestId('tile-input').fill('hello from tile one')
  await one.getByTestId('tile-input').press('Enter')
  await expect(one).toHaveAttribute('data-focused', 'true')
  await two.getByTestId('tile-input').fill('hello from tile two')
  await two.getByTestId('tile-input').press('Enter')

  // EchoProvider answers each with the prompt it was sent.
  await expect(one.locator('.recent')).toContainText('you ▸ hello from tile one')
  await expect(two.locator('.recent')).toContainText('you ▸ hello from tile two')
  await expect(one).toHaveAttribute('data-status', 'idle')
  await expect(two).toHaveAttribute('data-status', 'idle')
  await expect(one.locator('.recent li.assistant').last()).toContainText('hello from tile one')

  // A question in a tile is answered in the tile.
  await one.getByTestId('tile-input').fill('::tool Bash {"command":"echo from-a-tile"}')
  await one.getByTestId('tile-input').press('Enter')
  await expect(one.getByTestId('tile-ask-badge')).toHaveText('1')
  await one.getByTestId('ask-once').click()
  await expect(one.locator('.recent')).toContainText('✔ Bash echo from-a-tile')
  await expect(one).toHaveAttribute('data-status', 'idle')

  // Maximize: back to one session at a time, in its tab.
  await one.getByTestId('tile-maximize').click()
  await expect(page.getByTestId('session-view')).toHaveAttribute('data-session-id', first)
  await expect(page.locator(`[data-testid="session-tab"][data-session-id="${first}"]`)).toHaveAttribute('aria-selected', 'true')
})

test('a question in a session nobody here watches reaches the drawer at once, and is answered there', async ({ page, server }) => {
  await page.addInitScript(() => {
    const shown: string[] = []
    class RecordingNotification {
      static permission = 'granted'
      static requestPermission(): Promise<string> {
        return Promise.resolve('granted')
      }
      constructor(title: string) {
        shown.push(title)
      }
    }
    Object.assign(window, { Notification: RecordingNotification, __notifications: shown })
  })
  await openApp(page, server)
  const watched = await newSession(page)
  await page.getByRole('button', { name: 'notify', exact: true }).click()

  const script = await RpcClient.connect(server)
  try {
    const created = await script.call<{ id: string }>('session.create', { name: 'background-job' })
    await script.call('session.send', { sessionId: created.id, text: '::tool Bash {"command":"echo from-the-drawer"}' })

    // No subscription, no tick: the server-scope permission.asked is enough.
    await expect(page.getByTestId('approvals-count')).toHaveText('1', { timeout: 10_000 })
    await expect(page).toHaveTitle('(1) SugarCrush')
    await expect(page.getByTestId('session-view')).toHaveAttribute('data-session-id', watched)
    await expect.poll(() => page.evaluate(() => (window as unknown as { __notifications: string[] }).__notifications)).toContain('Bash is waiting for an answer')

    await page.getByTestId('approvals-button').click()
    const group = page.locator(`[data-testid="approvals-group"][data-session-id="${created.id}"]`)
    await expect(group).toContainText('background-job')
    await expect(group.getByTestId('permission-card')).toContainText('echo from-the-drawer')
    await group.getByTestId('ask-once').click()

    await expect(page.getByTestId('approvals-empty')).toBeVisible()
    await expect(page.getByTestId('approvals-count')).toHaveText('0')
    const settled = await script.waitFor((e) => e.type === 'permission.settled' && e.data.sessionId === created.id)
    expect(settled.data.reply).toBe('once')
    await script.waitFor((e) => e.type === 'session.updated' && e.data.id === created.id && e.data.status === 'idle')
    await expect.poll(() => page.evaluate(() => (window as unknown as { __notifications: string[] }).__notifications)).toContain('background-job finished')
  } finally {
    script.close()
  }
})
