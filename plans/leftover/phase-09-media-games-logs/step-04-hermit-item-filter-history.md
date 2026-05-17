# Step 09.04 — candy-hermit Item interface + filter fn + persistent history

**Source:** `leftover_updates_later.md` candy-hermit §3.1-3
**Branch:** `ai/hermit-item-filter-history`

## Deliverable

- `Item` interface + numbered items (today stores plain strings).
- `setFilterFn(callable)` (today hardcoded substring match).
- Persistent history (file-backed).

## Files

**Create:**
- `candy-hermit/src/Item.php` (interface).
- `candy-hermit/src/FilteredItem.php` (numbered impl).
- `candy-hermit/src/History/FileHistory.php`.

**Modify:**
- `candy-hermit/src/Hermit.php` — accept `Item[]`; `setFilterFn`.

## Tests

- One per feature.

## Acceptance

- `cd candy-hermit && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
