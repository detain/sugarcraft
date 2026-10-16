# Step 10.37 — sugar-overlay z-index + click-outside-to-dismiss + auto-sizing + border chrome

**Source:** `leftover_updates_later.md` sugar-overlay §4.3-6 Medium
**Branch:** `ai/veil-zindex-clickoutside-autosize-border`

## Deliverable

- Z-index / stacking (multiple veils, ordered).
- Click-outside-to-dismiss (consume sugar-mouse for hit testing).
- Auto-sizing / content-aware dimensions.
- Border chrome (consume candy-sprinkles Border family).

## Files

**Modify:** `sugar-overlay/src/Veil.php` — `withZIndex(int)`,
`withClickOutsideDismiss(bool)`, `withAutoSize(bool)`,
`withBorder(Border)`.

**Modify:** `sugar-overlay/composer.json` — add sugar-mouse + candy-sprinkles.

## Tests

- One per feature.

## Acceptance

- `cd sugar-overlay && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
