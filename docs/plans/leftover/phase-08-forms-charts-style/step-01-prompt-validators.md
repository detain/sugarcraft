# Step 08.01 — candy-forms built-in validators

**Source:** `leftover_updates_later.md` candy-forms §3.1 P1
**Branch:** `ai/prompt-validators`

## Deliverable

Built-in `Validator` subclasses: `Required`, `Email`, `MinLength`,
`MaxLength`, `Pattern`. Today every consumer rolls their own validators.

## Files

**Create:**

- `candy-forms/src/Validator/Required.php`
- `candy-forms/src/Validator/Email.php`
- `candy-forms/src/Validator/MinLength.php`
- `candy-forms/src/Validator/MaxLength.php`
- `candy-forms/src/Validator/Pattern.php`

**Modify:**

- `candy-forms/src/TextInput.php` — `withValidator(Validator)` accepts
  any of the above; multiple validators chain.

## Tests

- `candy-forms/tests/Validator/<Each>Test.php` per validator.

## Acceptance

- `cd candy-forms && vendor/bin/phpunit --filter Validator` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
