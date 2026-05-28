# Step 08.01 — sugar-prompt built-in validators

**Source:** `leftover_updates_later.md` sugar-prompt §3.1 P1
**Branch:** `ai/prompt-validators`

## Deliverable

Built-in `Validator` subclasses: `Required`, `Email`, `MinLength`,
`MaxLength`, `Pattern`. Today every consumer rolls their own validators.

## Files

**Create:**
- `sugar-prompt/src/Validator/Required.php`
- `sugar-prompt/src/Validator/Email.php`
- `sugar-prompt/src/Validator/MinLength.php`
- `sugar-prompt/src/Validator/MaxLength.php`
- `sugar-prompt/src/Validator/Pattern.php`

**Modify:**
- `sugar-prompt/src/TextInput.php` — `withValidator(Validator)` accepts
  any of the above; multiple validators chain.

## Tests

- `sugar-prompt/tests/Validator/<Each>Test.php` per validator.

## Acceptance

- `cd sugar-prompt && vendor/bin/phpunit --filter Validator` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
