# Step 10.02 — super-candy bulk rename + preview (via mosaic) + async ops

**Source:** `leftover_updates_later.md` super-candy MEDIUM + LOW
**Branch:** `ai/supercandy-bulk-preview-async`

## Deliverable

- Bulk rename UI (regex template + sequential numbering).
- Preview pane — image preview delegates to candy-mosaic. **Do not**
  re-implement.
- Async file ops via ReactPHP (long copies don't block UI).

## Files

**Create:**
- `super-candy/src/BulkRename.php` + UI.
- `super-candy/src/PreviewPane.php` — for image files invokes
  `\SugarCraft\Mosaic\Renderer`.
- `super-candy/src/AsyncOps.php` — wraps copy/move in
  `\React\Promise`.

**Modify:**
- `super-candy/composer.json` — `sugarcraft/candy-mosaic` +
  `react/promise`.

## Tests

- One per feature.

## Acceptance

- `cd super-candy && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
