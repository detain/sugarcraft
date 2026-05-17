# Step 10.11 — sugar-table multi-line rows + border styles (candy-sprinkles)

**Source:** `leftover_updates_later.md` sugar-table
**Branch:** `ai/table-multiline-borders`

## Deliverable

- Multi-line row support.
- Multiple border styles — consume candy-sprinkles' Border family.
  **Do not** re-implement border characters.

## Files

**Modify:** `sugar-table/src/Table.php` — `withBorder(\SugarCraft\Sprinkles\Border\Border)`,
`withMultilineMode(bool)`.

## Tests

- One per feature.

## Acceptance

- `cd sugar-table && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
