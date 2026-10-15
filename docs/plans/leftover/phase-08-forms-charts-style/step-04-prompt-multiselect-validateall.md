# Step 08.04 — candy-forms MultiSelect vim keys + Form validateAll + Select enum mode + errorSummary

**Source:** `leftover_updates_later.md` candy-forms §3.4-§3.6 + §2.4
**Branch:** `ai/prompt-misc`

## Deliverable

Four small features bundled:

- `MultiSelect` vim keys (j/k for nav, space to toggle).
- `Form::validateAll(): array<string,string>` cross-field validation.
- `Select::withEnum(\BackedEnum::class)` — coerce to enum values.
- `Theme::$errorSummary` slot separate from `$error` (already exists).

## Files

**Modify:**

- `candy-forms/src/MultiSelect.php`.
- `candy-forms/src/Form.php` — `validateAll`.
- `candy-forms/src/Select.php` — enum mode.
- `candy-forms/src/Theme.php` (or canonical Theme from step 02.01) —
  `errorSummary` slot.

## Tests

- One test file per feature.

## Acceptance

- `cd candy-forms && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
