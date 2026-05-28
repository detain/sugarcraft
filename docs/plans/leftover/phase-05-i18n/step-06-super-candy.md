# Step 05.06 — super-candy i18n via Lang::t()

**Source:** `leftover_updates_later.md` §2 i18n
**Branch:** `ai/supercandy-i18n`

## Deliverable

i18n for file-manager prompts, confirmations, error strings, hint bar.

## Files

**Create:**
- `super-candy/lang/en.php`.
- `super-candy/src/Lang.php`.

**Modify:**
- Files under `super-candy/src/`.

**Tests:**
- `super-candy/tests/LangCoverageTest.php`.

## Acceptance

- `cd super-candy && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
