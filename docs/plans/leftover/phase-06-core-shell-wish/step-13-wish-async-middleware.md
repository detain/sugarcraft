# Step 06.13 — sugar-ssh-server async middleware

**Source:** `leftover_updates_later.md` sugar-ssh-server §4.7
**Branch:** `ai/wish-async-middleware`

## Deliverable

Support async middleware that returns a Promise instead of blocking.
Lets servers handle slow auth backends (LDAP / OAuth / database) without
stalling the chain.

## Files

**Modify:**

- `sugar-ssh-server/src/Middleware/Middleware.php` — handler can return
  `void` (existing) OR `\React\Promise\PromiseInterface` (new).
- `sugar-ssh-server/src/Server.php` — awaits promises before continuing
  the chain.

**Create:**

- `sugar-ssh-server/src/Middleware/AsyncMiddleware.php` — abstract base for
  promise-returning middleware.

**Tests:**

- `sugar-ssh-server/tests/Middleware/AsyncMiddlewareTest.php`.

## Acceptance

- `cd sugar-ssh-server && vendor/bin/phpunit --filter Async` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
