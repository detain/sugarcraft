import { expect, newSession, openApp, send, test } from './fixtures/server'

/**
 * Fenced-block colouring under the server's CSP, end to end.
 *
 * The highlighter is deliberately part of the index chunk: DiffView reads
 * lib/highlight.ts with a static eager import (C-TUI-2.f, commit 8ca8956aa),
 * because every tool diff resolves a language from its file path, so the
 * module lives in the shared graph and MessageMarkdown's dynamic import
 * folds into the same chunk rather than a separate fetch. An earlier leg of
 * this test sniffed for exactly one `assets/highlight-*.js` request — a
 * measurement of the pre-diff-split bundle layout that the eager DiffView
 * edge made impossible to emit. It stayed red from 8ca8956aa on (recorded
 * base-red in the L-UX20 review) and is removed here; what the app still
 * promises, and what remains pinned, is that CSP-served scripts alone turn
 * a fenced `php` block into coloured tokens the transcript can show.
 */
test('a fenced code block is coloured by the in-graph highlighter, under the server CSP', async ({ page, server }) => {
  await openApp(page, server)

  await newSession(page)
  await send(page, '```php\n$total = 42; // the answer\n```')

  const code = page.locator('[data-kind="assistant"] pre code.language-php').last()
  await expect(code.locator('.hl-variable')).toHaveText('$total')
  await expect(code.locator('.hl-number')).toHaveText('42')
  await expect(code.locator('.hl-comment')).toHaveText('// the answer')
})
