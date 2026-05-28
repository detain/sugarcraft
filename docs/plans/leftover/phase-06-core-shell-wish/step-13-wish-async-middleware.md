# Step 06.13 — candy-wish async middleware

**Source:** `leftover_updates_later.md` candy-wish §4.7
**Branch:** `ai/wish-async-middleware`

## Deliverable

Support async middleware that returns a Promise instead of blocking.
Lets servers handle slow auth backends (LDAP / OAuth / database) without
stalling the chain.

## Files

**Modify:**
- `candy-wish/src/Middleware/Middleware.php` — handler can return
  `void` (existing) OR `\React\Promise\PromiseInterface` (new).
- `candy-wish/src/Server.php` — awaits promises before continuing
  the chain.

**Create:**
- `candy-wish/src/Middleware/AsyncMiddleware.php` — abstract base for
  promise-returning middleware.

**Tests:**
- `candy-wish/tests/Middleware/AsyncMiddlewareTest.php`.

## Acceptance

- `cd candy-wish && vendor/bin/phpunit --filter Async` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
