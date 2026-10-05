import { expect, newSession, openApp, send, test } from './fixtures/server'

test('a fenced code block is coloured by the lazily loaded highlighter, under the server CSP', async ({ page, server }) => {
  const chunks: string[] = []
  page.on('request', (request) => {
    if (/\/assets\/highlight-[\w-]+\.js$/.test(request.url())) chunks.push(request.url())
  })
  await openApp(page, server)
  expect(chunks).toEqual([])

  await newSession(page)
  await send(page, '```php\n$total = 42; // the answer\n```')

  const code = page.locator('[data-kind="assistant"] pre code.language-php').last()
  await expect(code.locator('.hl-variable')).toHaveText('$total')
  await expect(code.locator('.hl-number')).toHaveText('42')
  await expect(code.locator('.hl-comment')).toHaveText('// the answer')
  expect(chunks).toHaveLength(1)
})
