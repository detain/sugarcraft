import type { WebSocketRoute } from '@playwright/test'
import { CandyCodeServer, expect, newSession, openApp, send, test } from './fixtures/server'
import { RpcClient } from './fixtures/rpc'

test('a dropped socket reconnects and resumes from its cursor, missing nothing', async ({ page, server }) => {
  const hellos: Record<string, unknown>[] = []
  const routes: WebSocketRoute[] = []
  let blocked = false

  // Sit between the page and the server: record each handshake, and drop
  // the connection on demand.
  await page.routeWebSocket(/\/ws(\?|$)/, (ws) => {
    if (blocked) {
      ws.close({ code: 1006, reason: 'blocked' })
      return
    }
    routes.push(ws)
    const upstream = ws.connectToServer()
    ws.onMessage((message) => {
      const frame = JSON.parse(String(message)) as { method?: string; params?: Record<string, unknown> }
      if (frame.method === 'server.hello' && frame.params) hellos.push(frame.params)
      upstream.send(message)
    })
  })

  await openApp(page, server)
  const id = await newSession(page)
  await send(page, 'before the drop')
  await expect(page.locator('[data-kind="assistant"] blockquote')).toHaveText('before the drop')

  blocked = true
  await routes[routes.length - 1]?.close({ code: 1006, reason: 'gone' })
  await expect(page.getByTestId('banner-reconnecting')).toBeVisible()

  // Meanwhile a script drives the same session.
  const script = await RpcClient.connect(server)
  await script.call('session.subscribe', { sessionId: id })
  await script.call('session.send', { sessionId: id, text: 'while you were away' })
  await script.waitFor((e) => e.sessionId === id && e.type === 'turn.completed')
  script.close()

  blocked = false
  await page.getByRole('button', { name: 'Retry now' }).click()
  await expect(page.getByTestId('connection-status')).toContainText('connected')

  const resume = hellos[hellos.length - 1]?.resume as Record<string, number>
  expect(resume[id]).toBeGreaterThan(0)
  await expect(page.locator('[data-kind="user"]').filter({ hasText: 'while you were away' })).toBeVisible()
  await expect(page.locator('[data-kind="assistant"] blockquote').last()).toHaveText('while you were away')
  // Resumed, not reloaded: the earlier rows are there exactly once.
  await expect(page.locator('[data-kind="user"]').filter({ hasText: 'before the drop' })).toHaveCount(1)
})

test('a restarted server signs the browser out; signing in again finds the session intact', async ({ page, server }) => {
  await openApp(page, server)
  const id = await newSession(page)
  await send(page, 'survive a restart')
  await expect(page.locator('[data-kind="assistant"] blockquote')).toHaveText('survive a restart')

  await server.restart()
  await expect(page.getByTestId('login')).toBeVisible({ timeout: 60_000 })

  await page.getByTestId('login-secret').fill(server.token)
  await page.getByTestId('login-submit').click()
  await expect(page.getByTestId('connection-status')).toContainText('connected')
  await page.locator(`[data-testid="session-link"][data-session-id="${id}"]`).click()
  await expect(page.locator('[data-kind="assistant"] blockquote')).toHaveText('survive a restart')
})

/**
 * ISS-052's deferred leg: the server is SIGKILLed and never comes back. No
 * `server.shutdown` can arrive for a killed process, so the only thing the
 * tab has is a dead socket and a port that no longer answers. The documented
 * verdict for that (SERVER.md, "an exhausted ladder") is the terminal
 * banner — not a "reconnecting… Retrying in 15 s" that lies forever.
 */
test('a server killed for good ends at the terminal verdict, and never retries past it', async ({ page }) => {
  // This arm waits the real ladder out on purpose: six waits — 0.5 + 1 + 2 +
  // 4 + 8 + 15 s, each ±30 % — so it costs about half a minute plus slack.
  test.setTimeout(180_000)
  // Its own server: this one dies for real, so it must not be the worker's,
  // which the specs above still drive.
  const doomed = await CandyCodeServer.start()
  try {
    await openApp(page, doomed)
    await newSession(page)
    await send(page, 'before the kill')
    await expect(page.locator('[data-kind="assistant"] blockquote')).toHaveText('before the kill')

    await doomed.kill()
    await expect(page.getByTestId('banner-reconnecting')).toBeVisible()

    await expect(page.getByTestId('banner-offline')).toBeVisible({ timeout: 120_000 })
    await expect(page.getByTestId('banner-offline')).toContainText('did not answer after 6 tries')
    await expect(page.getByTestId('banner-offline')).toContainText('Retries have stopped')
    await expect(page.getByTestId('banner-reconnecting')).toHaveCount(0)
    await expect(page.getByTestId('connection-status')).toContainText('disconnected')

    // The banner's own way back re-runs a fresh ladder against a port that is
    // still dead: the tab goes back to trying, and lands at the same verdict.
    await page.getByRole('button', { name: 'Try again' }).click()
    await expect(page.getByTestId('banner-reconnecting')).toBeVisible()
    await expect(page.getByTestId('banner-offline')).toHaveCount(0)
  } finally {
    await doomed.dispose()
  }
})
