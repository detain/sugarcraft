# Step 08.05 — sugar-bits TextInput validate_on + restrict

**Source:** `leftover_updates_later.md` sugar-bits Phase 1 HIGH
**Branch:** `ai/bits-textinput-validate-restrict`

## Deliverable

- `withValidateOn(\BackedEnum)` — Blur / Change / Submit timing.
- `withRestrict(string $pattern)` — regex that filters keystrokes (no
  non-matching chars enter the buffer).

## Files

**Modify:**
- `sugar-bits/src/TextInput/TextInput.php`.

**Create:**
- `sugar-bits/src/TextInput/ValidateOn.php` (enum).

## Tests

- `sugar-bits/tests/TextInput/ValidateOnTest.php`.
- `sugar-bits/tests/TextInput/RestrictTest.php`.

## Acceptance

- `cd sugar-bits && vendor/bin/phpunit --filter "ValidateOn|Restrict"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
