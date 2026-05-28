# Step 06.08 — candy-wish Context propagation

**Source:** `leftover_updates_later.md` candy-wish §4.1 P1
**Branch:** `ai/wish-context`

## Deliverable

Middleware-chain `Context` object (Go-style: carries cancellation,
deadlines, key-value metadata across the chain). Today middleware
signature is `handle(Session, callable)`; add `Context` so middleware
can chain timeouts and metadata.

## Files

**Create:**
- `candy-wish/src/Context.php` — immutable; `withValue($k, $v)`,
  `withDeadline(\DateTimeImmutable)`, `withCancelable()`, `cancel()`,
  `done(): bool`, `err(): ?\Throwable`.

**Modify:**
- `candy-wish/src/Middleware/Middleware.php` interface —
  `handle(Context $ctx, Session $sess, callable $next): void`.
- Every existing middleware adjusted (signature change).
- `candy-wish/src/Server.php` — constructs root Context.

**Tests:**
- `candy-wish/tests/ContextTest.php`.
- `candy-wish/tests/MiddlewareContextTest.php` — middleware that
  cancels the context aborts downstream handlers.

## Acceptance

- `cd candy-wish && vendor/bin/phpunit --filter "Context|Middleware"` green.

## Notes

- This is a signature-breaking change for any user-defined middleware.
  Document it; provide a one-release alias shim if any external
  middleware exists.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
