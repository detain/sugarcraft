import { existsSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { expect, openApp, test } from './fixtures/server'

/**
 * The web settings form round trip (roadmap O-6b, Appendix O §7.8): the form
 * is generated from the real server's `settings.schema`, a change is staged,
 * previewed as a diff of `config.json`, saved through the server's settings
 * writer, and read back with its new provenance; a reset deletes the key.
 */
test.describe('settings', () => {
  function userConfig(home: string): Record<string, unknown> {
    const path = join(home, '.sugar-crush', 'config.json')
    return existsSync(path) ? (JSON.parse(readFileSync(path, 'utf8')) as Record<string, unknown>) : {}
  }

  test('the form comes from the schema, and a change is previewed, saved and read back', async ({ page, server }) => {
    await openApp(page, server)
    await page.getByTestId('settings-link').click()
    const view = page.getByTestId('settings-view')
    await expect(view).toHaveAttribute('data-loaded', 'true')

    // Read-only fields say why; the project tier is closed for an untrusted repo.
    const statusLine = view.locator('[data-testid="settings-field"][data-key="statusLine"]')
    await expect(statusLine).toHaveAttribute('data-editable', 'false')
    await expect(statusLine.getByTestId('settings-reason')).toContainText('runs a command')
    await expect(page.getByTestId('settings-tier-project').locator('input')).toBeDisabled()

    const field = view.locator('[data-testid="settings-field"][data-key="parallelToolCalls"]')
    await expect(field).toHaveAttribute('data-editable', 'true')
    await expect(field).toHaveAttribute('data-source', 'default')
    await expect(field.getByTestId('settings-applies')).toHaveText('next turn')

    await field.getByTestId('settings-input').uncheck()
    await expect(page.getByTestId('settings-staged-count')).toContainText('1 change staged')
    await page.getByTestId('settings-preview-button').click()

    const preview = page.getByTestId('settings-preview')
    await expect(preview).toHaveAttribute('data-can-save', 'true')
    await expect(preview.getByTestId('diff')).toContainText('"parallelToolCalls": false')
    await expect(preview.getByTestId('settings-apply-summary')).toHaveText('Applies: 1 next turn')
    expect(userConfig(server.home)).not.toHaveProperty('parallelToolCalls')

    await preview.getByTestId('settings-save').click()
    await expect(page.getByTestId('settings-saved')).toContainText('Saved 1 setting to')
    await expect(field).toHaveAttribute('data-source', 'user-config')
    await expect(field.getByTestId('settings-source')).toHaveText('config.json')
    expect(userConfig(server.home)).toMatchObject({ parallelToolCalls: false })

    // A reset is staged and previewed the same way, and deletes the key.
    await field.getByTestId('settings-reset').click()
    await page.getByTestId('settings-preview-button').click()
    await expect(preview.locator('[data-testid="settings-change"][data-key="parallelToolCalls"]')).toContainText('reset')
    await preview.getByTestId('settings-save').click()
    await expect(field).toHaveAttribute('data-source', 'default')
    expect(userConfig(server.home)).not.toHaveProperty('parallelToolCalls')
  })

  test('nonsense is refused in the field, before anything is sent', async ({ page, server }) => {
    await openApp(page, server, '/settings')
    const field = page.locator('[data-testid="settings-field"][data-key="parallelToolDeadlineSeconds"]')
    await expect(field).toHaveAttribute('data-editable', 'true')
    await field.getByTestId('settings-input').fill('1.5')
    await field.getByTestId('settings-input').press('Enter')
    await expect(field.getByTestId('settings-field-error')).toHaveText('a whole number')
    await expect(page.getByTestId('settings-footer')).toHaveCount(0)
  })
})
