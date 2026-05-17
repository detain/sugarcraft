# Step 10.37 — sugar-veil z-index + click-outside-to-dismiss + auto-sizing + border chrome

**Source:** `leftover_updates_later.md` sugar-veil §4.3-6 Medium
**Branch:** `ai/veil-zindex-clickoutside-autosize-border`

## Deliverable

- Z-index / stacking (multiple veils, ordered).
- Click-outside-to-dismiss (consume candy-zone for hit testing).
- Auto-sizing / content-aware dimensions.
- Border chrome (consume candy-sprinkles Border family).

## Files

**Modify:** `sugar-veil/src/Veil.php` — `withZIndex(int)`,
`withClickOutsideDismiss(bool)`, `withAutoSize(bool)`,
`withBorder(Border)`.

**Modify:** `sugar-veil/composer.json` — add candy-zone + candy-sprinkles.

## Tests

- One per feature.

## Acceptance

- `cd sugar-veil && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
