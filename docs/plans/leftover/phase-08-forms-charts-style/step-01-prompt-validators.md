# Step 08.01 — sugar-forms built-in validators

**Source:** `leftover_updates_later.md` sugar-forms §3.1 P1
**Branch:** `ai/prompt-validators`

## Deliverable

Built-in `Validator` subclasses: `Required`, `Email`, `MinLength`,
`MaxLength`, `Pattern`. Today every consumer rolls their own validators.

## Files

**Create:**

- `sugar-forms/src/Validator/Required.php`
- `sugar-forms/src/Validator/Email.php`
- `sugar-forms/src/Validator/MinLength.php`
- `sugar-forms/src/Validator/MaxLength.php`
- `sugar-forms/src/Validator/Pattern.php`

**Modify:**

- `sugar-forms/src/TextInput.php` — `withValidator(Validator)` accepts
  any of the above; multiple validators chain.

## Tests

- `sugar-forms/tests/Validator/<Each>Test.php` per validator.

## Acceptance

- `cd sugar-forms && vendor/bin/phpunit --filter Validator` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
