# Step 08.03 — sugar-forms async debounced suggestions

**Source:** `leftover_updates_later.md` sugar-forms §3.3
**Branch:** `ai/prompt-async-debounce`

## Deliverable

Suggestions can be async — e.g. HTTP-fetched. Debounce 150ms while user
types, then fetch.

## Files

**Modify:**

- `sugar-forms/src/Select.php` and `sugar-forms/src/TextInput.php` —
  `withAsyncSuggestions(callable $fetcher, int $debounceMs = 150)`.
- Uses sugar-core's `WorkerPool` (step 06.04) for the async fetch.

## Tests

- `sugar-forms/tests/AsyncSuggestionsTest.php`.

## Acceptance

- `cd sugar-forms && vendor/bin/phpunit --filter Async` green.

## Notes

- Depends on step 06.04 (WorkerPool) shipping first.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
