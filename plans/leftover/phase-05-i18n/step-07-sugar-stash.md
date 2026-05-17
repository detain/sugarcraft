# Step 05.07 — sugar-stash i18n via Lang::t()

**Source:** `leftover_updates_later.md` §2 i18n
**Branch:** `ai/stash-i18n`

## Deliverable

i18n for commit prompts, diff-view labels, error strings, key-hint
bar, branch-list labels.

## Files

**Create:**
- `sugar-stash/lang/en.php`.
- `sugar-stash/src/Lang.php`.

**Modify:**
- Files under `sugar-stash/src/`.

**Tests:**
- `sugar-stash/tests/LangCoverageTest.php`.

## Acceptance

- `cd sugar-stash && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
