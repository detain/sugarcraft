import { expect, newSession, openApp, send, test } from './fixtures/server'

test.describe('a session', () => {
  test('create a session, send a prompt, see the echo stream in', async ({ page, server }) => {
    await openApp(page, server)
    const id = await newSession(page)
    await expect(page.locator(`[data-testid="session-link"][data-session-id="${id}"]`)).toBeVisible()

    await send(page, 'hello from the browser')
    const items = page.getByTestId('transcript-item')
    await expect(items.filter({ hasText: 'hello from the browser' }).first()).toHaveAttribute('data-kind', 'user')
    await expect(page.locator('[data-kind="assistant"] blockquote')).toHaveText('hello from the browser')
    await expect(page.getByTestId('status-bar')).toHaveAttribute('data-status', 'idle')
    await expect(page.getByTestId('composer-input')).toHaveValue('')
  })

  test('model and tool text cannot inject markup', async ({ page, server }) => {
    await openApp(page, server)
    await newSession(page)
    await send(page, '<img src=x onerror="window.__pwned=1"> [x](javascript:window.__pwned=2) <script>window.__pwned=3</script>')
    await expect(page.locator('[data-kind="assistant"] blockquote')).toContainText('<img src=x')
    await expect(page.getByTestId('transcript').locator('img, script')).toHaveCount(0)
    await expect(page.getByTestId('transcript').locator('a[href^="javascript"]')).toHaveCount(0)
    expect(await page.evaluate(() => (window as unknown as { __pwned?: number }).__pwned)).toBeUndefined()
  })

  test('a tool call asks first, then shows its card and its diff', async ({ page, server }) => {
    await openApp(page, server)
    await newSession(page)
    await send(page, '::tool Write {"file_path":"notes.txt","content":"alpha\\nbeta\\n"}')

    const ask = page.getByTestId('permission-card')
    await expect(ask).toContainText('Write')
    await expect(ask).toContainText('notes.txt')
    await expect(page.getByTestId('status-bar')).toHaveAttribute('data-status', 'waiting_permission')
    await expect(page.locator('[data-testid="ask-badge"]')).toHaveText('1')
    await expect(page).toHaveTitle('(1) SugarCrush')

    await ask.getByTestId('ask-once').click()
    await expect(ask).toHaveCount(0)
    const card = page.getByTestId('tool-card').filter({ hasText: 'Write' })
    await expect(card).toHaveAttribute('data-status', 'done')
    await expect(card.locator('.stats')).toHaveText('+2 −0')
    await card.locator('button.head').click()
    await expect(card.getByTestId('diff')).toContainText('alpha')
    await expect(page.locator('[data-kind="assistant"]').last()).toContainText('Tool Write returned')
    await expect(page).toHaveTitle('SugarCrush')
  })

  test('a rejected call is shown refused', async ({ page, server }) => {
    await openApp(page, server)
    await newSession(page)
    await send(page, '::tool Bash {"command":"echo should-not-run"}')
    await page.getByTestId('permission-card').getByTestId('ask-reject').click()
    const card = page.getByTestId('tool-card').filter({ hasText: 'Bash' })
    await expect(card).toHaveAttribute('data-status', /denied|error/)
    await expect(page.locator('[data-kind="assistant"]').last()).toContainText('Tool Bash failed')
  })

  test('a prompt sent while a turn waits is queued, and goes out after', async ({ page, server }) => {
    await openApp(page, server)
    await newSession(page)
    await send(page, '::tool Bash {"command":"echo first"}')
    await expect(page.getByTestId('permission-card')).toBeVisible()

    // The composer offers the server's queueMode first — steer, as the TUI's
    // Enter does mid-turn — and queue is one pick away.
    await expect(page.getByTestId('composer-delivery')).toHaveValue('steer')
    await page.getByTestId('composer-delivery').selectOption('queue')
    await send(page, 'and then this')
    await expect(page.getByTestId('queue-entry')).toContainText('and then this')

    await page.getByTestId('ask-once').click()
    await expect(page.getByTestId('queue-entry')).toHaveCount(0)
    await expect(page.locator('[data-kind="assistant"] blockquote').last()).toHaveText('and then this')
  })

  test('Stop cancels the running turn', async ({ page, server }) => {
    await openApp(page, server)
    await newSession(page)
    await send(page, '::tool Bash {"command":"echo never"}')
    await expect(page.getByTestId('permission-card')).toBeVisible()
    await page.getByTestId('composer-stop').click()
    await expect(page.getByTestId('permission-card')).toHaveCount(0)
    await expect(page.locator('[data-kind="notice"]').last()).toContainText('Turn cancelled.')
    await expect(page.getByTestId('status-bar')).toHaveAttribute('data-status', 'idle')
  })

  test('a server built-in runs through command.exec', async ({ page, server }) => {
    await openApp(page, server)
    await newSession(page)
    await send(page, 'remember me')
    await expect(page.locator('[data-kind="assistant"] blockquote')).toHaveText('remember me')
    await page.getByTestId('composer-input').fill('/cle')
    await expect(page.getByTestId('command-suggestions')).toContainText('/clear')
    await page.getByTestId('composer-input').press('Tab')
    await expect(page.getByTestId('composer-input')).toHaveValue('/clear ')
    await page.getByTestId('composer-input').press('Enter')
    await expect(page.locator('[data-kind="assistant"] blockquote')).toHaveCount(0)
    await expect(page.getByTestId('session-error')).toHaveCount(0)
  })
})
