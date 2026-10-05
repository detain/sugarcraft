import { expect, newSession, openApp, send, test } from './fixtures/server'

/**
 * Roadmap O-6c in a real browser against a real `sugarcrush serve`: the todo
 * panel following the agent's Todo tool, the memory panel's round trip, the
 * command palette, and a delegated run's tree, agent view and transcript.
 */
test.describe('session panels', () => {
  test('the todo panel follows the agent\'s Todo tool live', async ({ page, server }) => {
    await openApp(page, server)
    await newSession(page)
    await page.getByTestId('panels-toggle').click()
    await page.getByTestId('panel-tab-todo').click()
    await expect(page.getByTestId('todo-panel')).toContainText('keeps no todo list')

    await send(page, '::tool Todo {"todos":[{"content":"write the parser","status":"completed"},{"content":"test it","status":"in_progress"}]}')
    const items = page.getByTestId('todo-item')
    await expect(items).toHaveCount(2)
    await expect(items.first()).toHaveAttribute('data-status', 'completed')
    await expect(items.nth(1)).toContainText('test it')
    await expect(page.getByTestId('todo-panel')).toContainText('1 of 2 done')
  })

  test('the memory panel adds, finds and deletes a note', async ({ page, server }) => {
    await openApp(page, server)
    await page.getByTestId('panels-toggle').click()
    await page.getByTestId('panel-tab-memory').click()
    await page.getByTestId('memory-scope-user').click()
    await page.getByTestId('memory-draft').fill('the e2e note about tabs')
    await page.getByTestId('memory-add').click()
    const note = page.getByTestId('memory-entry').filter({ hasText: 'the e2e note about tabs' })
    await expect(note).toHaveCount(1)

    await page.getByTestId('memory-search').fill('e2e note')
    await page.getByTestId('memory-search').press('Enter')
    await expect(note).toHaveCount(1)

    page.once('dialog', (dialog) => void dialog.accept())
    await note.getByTestId('memory-delete').click()
    await expect(note).toHaveCount(0)
  })

  test('the command palette opens a panel and switches sessions', async ({ page, server }) => {
    await openApp(page, server)
    const first = await newSession(page)
    await newSession(page)

    await page.keyboard.press('Control+k')
    await expect(page.getByTestId('command-palette')).toBeVisible()
    await page.getByTestId('palette-input').fill('workflows panel')
    await page.getByTestId('palette-input').press('Enter')
    await expect(page.getByTestId('command-palette')).toHaveCount(0)
    await expect(page.getByTestId('workflow-panel')).toBeVisible()

    await page.keyboard.press('Control+k')
    await page.getByTestId('palette-input').fill(first)
    await page.getByTestId('palette-entry').filter({ hasText: first }).first().click()
    await expect(page.getByTestId('session-view')).toHaveAttribute('data-session-id', first)
  })

  test('a delegated run hangs under its Task card and opens in the agent view', async ({ page, server }) => {
    await openApp(page, server)
    await newSession(page)
    await send(page, '::tool Task {"description":"echo a greeting","prompt":"hello from the parent","agent":"reviewer"}')

    // Task may ask first, depending on the mode; answer it if it does.
    const ask = page.getByTestId('permission-card')
    const node = page.getByTestId('tool-card').filter({ hasText: 'Task' }).getByTestId('agent-node')
    await expect(ask.or(node).first()).toBeVisible()
    if (await ask.count() > 0) await ask.getByTestId('ask-once').click()

    await expect(node.first()).toHaveAttribute('data-status', /done|failed|empty/)
    await expect(page.getByTestId('status-bar')).toHaveAttribute('data-status', 'idle')
    await node.first().click()

    const view = page.getByTestId('agent-view')
    await expect(view).toBeVisible()
    await expect(view).toContainText('reviewer')
    await expect(view.getByTestId('agent-transcript')).toContainText('hello from the parent')

    // Finished, it is continued rather than messaged: a follow-up of the same
    // conversation, whose words land in the same transcript.
    await view.getByTestId('agent-input').fill('and once more')
    await view.getByTestId('agent-send').click()
    await expect(view.getByTestId('agent-transcript')).toContainText('and once more')
    await expect(view).toHaveAttribute('data-status', /done|failed|empty/)
    await view.getByTestId('agent-back').click()
    // The follow-up is a run of its own, writing on in the same transcript.
    await expect(page.getByTestId('agents-panel').getByTestId('agent-node')).toHaveCount(2)
  })
})
