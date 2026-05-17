# Step 05.02 — sugar-table i18n via Lang::t()

**Source:** `leftover_updates_later.md` §2 i18n + sugar-table
**Branch:** `ai/table-i18n`

## Deliverable

Add `Lang.php` + `lang/en.php` per the canonical pattern
(`sugar-wishlist/src/Lang.php`). Replace inline English in sugar-table:
header / pagination / empty-state strings, error messages.

## Files

**Create:**
- `sugar-table/lang/en.php` — keys for "No data", "Page X of Y", "Sort",
  "Filter", "Showing N of M rows", column-error strings.
- `sugar-table/src/Lang.php` — `Lang::t()` wrapper.

**Modify:**
- Every PHP file in `sugar-table/src/` — replace inline English.

**Tests:**
- `sugar-table/tests/LangCoverageTest.php` — coverage assertion.

## Acceptance

- `cd sugar-table && vendor/bin/phpunit` green.
- `grep -rn "echo \|return ['\"][A-Z]" sugar-table/src` clean.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
