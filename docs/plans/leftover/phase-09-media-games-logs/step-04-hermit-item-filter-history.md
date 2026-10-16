# Step 09.04 — sugar-quickpick Item interface + filter fn + persistent history

**Source:** `leftover_updates_later.md` sugar-quickpick §3.1-3
**Branch:** `ai/hermit-item-filter-history`

## Deliverable

- `Item` interface + numbered items (today stores plain strings).
- `setFilterFn(callable)` (today hardcoded substring match).
- Persistent history (file-backed).

## Files

**Create:**

- `sugar-quickpick/src/Item.php` (interface).
- `sugar-quickpick/src/FilteredItem.php` (numbered impl).
- `sugar-quickpick/src/History/FileHistory.php`.

**Modify:**

- `sugar-quickpick/src/Hermit.php` — accept `Item[]`; `setFilterFn`.

## Tests

- One per feature.

## Acceptance

- `cd sugar-quickpick && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
