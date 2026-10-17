# Step 06.08 — sugar-ssh-server Context propagation

**Source:** `leftover_updates_later.md` sugar-ssh-server §4.1 P1
**Branch:** `ai/wish-context`

## Deliverable

Middleware-chain `Context` object (Go-style: carries cancellation,
deadlines, key-value metadata across the chain). Today middleware
signature is `handle(Session, callable)`; add `Context` so middleware
can chain timeouts and metadata.

## Files

**Create:**

- `sugar-ssh-server/src/Context.php` — immutable; `withValue($k, $v)`,
  `withDeadline(\DateTimeImmutable)`, `withCancelable()`, `cancel()`,
  `done(): bool`, `err(): ?\Throwable`.

**Modify:**

- `sugar-ssh-server/src/Middleware/Middleware.php` interface —
  `handle(Context $ctx, Session $sess, callable $next): void`.
- Every existing middleware adjusted (signature change).
- `sugar-ssh-server/src/Server.php` — constructs root Context.

**Tests:**

- `sugar-ssh-server/tests/ContextTest.php`.
- `sugar-ssh-server/tests/MiddlewareContextTest.php` — middleware that
  cancels the context aborts downstream handlers.

## Acceptance

- `cd sugar-ssh-server && vendor/bin/phpunit --filter "Context|Middleware"` green.

## Notes

- This is a signature-breaking change for any user-defined middleware.
  Document it; provide a one-release alias shim if any external
  middleware exists.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
