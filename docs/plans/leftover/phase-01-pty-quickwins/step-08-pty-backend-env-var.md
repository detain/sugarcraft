# Step 01.08 — SUGARCRAFT_PTY_BACKEND env var

**Source:** `leftover_updates.md` P1-LO-03 + P4-LO-02
**Branch:** `ai/pty-backend-env-var`
**Bundle hint:** small, ~80 LOC

## Deliverable

`SUGARCRAFT_TERMIOS=stty` already toggles `TermiosFactory` between
FFI and stty fallback. There is no equivalent for selecting the PTY
backend. Add `SUGARCRAFT_PTY_BACKEND` that's read by
`PtySystemFactory::default()`. Today only `posix-ffi` is wired, but
the env-var design is cheap and lets phase-12 work (sidecar, PECL)
slot in without changing call sites.

## Files

**Modify:**
- `candy-pty/src/PtySystemFactory.php`:
  - `default(): PtySystem` checks `getenv('SUGARCRAFT_PTY_BACKEND')`
    first.
  - Recognized values: `posix-ffi` (default), `sidecar` (throws
    UnsupportedPlatformException for now with "deferred to phase 12"
    message), `pecl` (same), `auto` (= default behaviour).
  - Unrecognized values throw `\InvalidArgumentException` with a
    helpful message listing valid values.
- `candy-pty/README.md` — document the env var in the Architecture
  section.
- `candy-pty/docs/CONCEPTS.md` — one paragraph on backend selection.

## Tests

- `candy-pty/tests/PtySystemFactoryTest.php`:
  - Asserts default behaviour with no env var set.
  - Asserts `SUGARCRAFT_PTY_BACKEND=posix-ffi` returns
    `PosixPtySystem`.
  - Asserts `SUGARCRAFT_PTY_BACKEND=sidecar` throws
    `UnsupportedPlatformException` with the deferred message.
  - Asserts unrecognized values throw `\InvalidArgumentException`.

## Acceptance

- `cd candy-pty && vendor/bin/phpunit --filter PtySystemFactory` green.
- README has the new section documenting all four recognized values.
- No behavioural change for default (no env var set) callers.

## Notes

- Do not implement sidecar / pecl backends now. The env-var ID is the
  forward-compatible API; phase 12 implements the backends.
- Mirror the `SUGARCRAFT_TERMIOS` shape (lowercase value names, env-var
  uppercase) — consistency matters for users debugging.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
