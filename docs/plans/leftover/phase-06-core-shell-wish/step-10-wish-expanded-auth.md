# Step 06.10 — sugar-ssh-server expanded auth middleware

**Source:** `leftover_updates_later.md` sugar-ssh-server §4.3 P2
**Branch:** `ai/wish-expanded-auth`

## Deliverable

Add `PasswordAuth`, `CertificateAuth`, `AuthMethods`, `KeyboardInteractive`
middleware so wish's auth surface is complete.

## Files

**Create:**

- `sugar-ssh-server/src/Middleware/Auth/PasswordAuth.php` — callback
  validates `(user, password)`.
- `sugar-ssh-server/src/Middleware/Auth/CertificateAuth.php` — validates
  X.509 certs.
- `sugar-ssh-server/src/Middleware/Auth/AuthMethods.php` — declares which
  methods the server accepts; emits server-side method list to client.
- `sugar-ssh-server/src/Middleware/Auth/KeyboardInteractive.php` — challenge-
  response interactive prompt.

**Tests:**

- `sugar-ssh-server/tests/Middleware/Auth/<Each>Test.php`.

## Acceptance

- `cd sugar-ssh-server && vendor/bin/phpunit --filter Auth` green.

## Notes

- These middlewares MUST use the `Context` from step 06.08 — they're
  added on top.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
