import { existsSync, mkdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { test as base, expect, openApp, send, SugarCrushServer } from './fixtures/server'

/**
 * `serve --allow-dir-browse`: "New session" opens a directory picker over the
 * browse root (here the scratch directory, which holds the server's own repo
 * and a `projects/` tree), and the session starts in the picked directory —
 * a workspace host of its own, whose tools act there.
 */
const test = base.extend<object, { browseServer: SugarCrushServer }>({
  browseServer: [
    async ({}, use) => {
      const server = await SugarCrushServer.start((scratch) => {
        mkdirSync(join(scratch, 'projects', 'alpha', '.git'), { recursive: true })
        mkdirSync(join(scratch, 'projects', 'beta', 'deeper'), { recursive: true })
        mkdirSync(join(scratch, 'projects', '.hidden'), { recursive: true })
        return ['--allow-dir-browse', '--browse-root', scratch]
      })
      await use(server)
      await server.dispose()
    },
    { scope: 'worker' },
  ],
})

test('browse up and down, then start a session in the picked directory', async ({ page, browseServer: server }) => {
  await openApp(page, server)
  await page.getByTestId('new-session').click()

  const picker = page.getByTestId('dir-picker')
  await expect(picker).toBeVisible()
  // It opens on the server's own project root.
  await expect(page.getByTestId('picker-current')).toHaveText(server.repo)
  await expect(page.getByTestId('picker-up')).toBeEnabled()

  // Up to the browse root: Up is then disabled.
  await page.getByTestId('picker-up').click()
  await expect(page.getByTestId('picker-current')).toHaveText(server.scratch)
  await expect(page.getByTestId('picker-up')).toBeDisabled()

  // Down into projects/: directories only, hidden ones on request, project badge.
  await picker.locator('[data-testid="picker-entry"][data-name="projects"]').click()
  await expect(page.getByTestId('picker-current')).toHaveText(join(server.scratch, 'projects'))
  const names = picker.getByTestId('picker-entry')
  await expect(names).toHaveText(['alpha/project', 'beta/'])
  await page.getByTestId('picker-hidden').check()
  await expect(names).toHaveCount(3)
  await page.getByTestId('picker-hidden').uncheck()
  await expect(names).toHaveCount(2)

  // Keyboard: ↓ to beta, Enter opens it, Backspace comes back up.
  const list = page.getByTestId('picker-list')
  await list.focus()
  await list.press('ArrowDown')
  await list.press('Enter')
  await expect(page.getByTestId('picker-current')).toHaveText(join(server.scratch, 'projects', 'beta'))
  await list.press('Backspace')
  await expect(page.getByTestId('picker-current')).toHaveText(join(server.scratch, 'projects'))

  // A typed path outside the browse root is refused, and the view stays put.
  await page.getByTestId('picker-path').fill('/etc')
  await page.getByTestId('picker-go').click()
  await expect(page.getByTestId('picker-error')).toContainText('outside the browse root')
  await expect(page.getByTestId('picker-current')).toHaveText(join(server.scratch, 'projects'))

  // A typed path inside it is followed.
  const beta = join(server.scratch, 'projects', 'beta')
  await page.getByTestId('picker-path').fill(beta)
  await page.getByTestId('picker-path').press('Enter')
  await expect(page.getByTestId('picker-current')).toHaveText(beta)

  await page.getByTestId('picker-start').click()
  await expect(picker).toHaveCount(0)
  const view = page.getByTestId('session-view')
  await expect(view).toHaveAttribute('data-phase', 'live')
  const id = (await view.getAttribute('data-session-id')) ?? ''
  await expect(page.locator(`[data-testid="session-link"][data-session-id="${id}"] [data-testid="session-root"]`)).toHaveText('beta/')

  // The session runs in beta: a file it writes lands there.
  await send(page, '::tool Write {"file_path":"picked.txt","content":"from the picker\\n"}')
  await page.getByTestId('permission-card').getByTestId('ask-once').click()
  await expect(page.getByTestId('tool-card').filter({ hasText: 'Write' })).toHaveAttribute('data-status', 'done')
  expect(existsSync(join(beta, 'picked.txt'))).toBe(true)
  expect(readFileSync(join(beta, 'picked.txt'), 'utf8')).toBe('from the picker\n')
  expect(existsSync(join(server.repo, 'picked.txt'))).toBe(false)
})

test('Escape cancels the picker and starts nothing', async ({ page, browseServer: server }) => {
  await openApp(page, server)
  const before = await page.getByTestId('session-link').count()
  await page.getByTestId('new-session').click()
  await expect(page.getByTestId('dir-picker')).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.getByTestId('dir-picker')).toHaveCount(0)
  await expect(page.getByTestId('session-link')).toHaveCount(before)
})
