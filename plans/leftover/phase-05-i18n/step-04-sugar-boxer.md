# Step 05.04 — sugar-boxer i18n via Lang::t()

**Source:** `leftover_updates_later.md` §2 i18n
**Branch:** `ai/boxer-i18n`

## Deliverable

Add i18n for alignment-mode labels and error strings in sugar-boxer.

## Files

**Create:**
- `sugar-boxer/lang/en.php`.
- `sugar-boxer/src/Lang.php`.

**Modify:**
- `sugar-boxer/src/` files using English literals.

**Tests:**
- `sugar-boxer/tests/LangCoverageTest.php`.

## Acceptance

- `cd sugar-boxer && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
