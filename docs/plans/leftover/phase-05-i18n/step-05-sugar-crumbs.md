# Step 05.05 — sugar-crumbs i18n via Lang::t()

**Source:** `leftover_updates_later.md` §2 i18n
**Branch:** `ai/crumbs-i18n`

## Deliverable

i18n for separator/ellipsis labels, error strings, "Home" / breadcrumb
landmark text.

## Files

**Create:**
- `sugar-crumbs/lang/en.php`.
- `sugar-crumbs/src/Lang.php`.

**Modify:**
- Files under `sugar-crumbs/src/`.

**Tests:**
- `sugar-crumbs/tests/LangCoverageTest.php`.

## Acceptance

- `cd sugar-crumbs && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
