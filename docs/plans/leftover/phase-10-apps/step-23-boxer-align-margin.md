# Step 10.23 — sugar-boxer alignment + margin (post SSOT-02)

**Source:** `leftover_updates_later.md` sugar-boxer
**Branch:** `ai/boxer-align-margin`
**Bundle hint:** depends on step 04.01

## Deliverable

After SSOT-02 made sugar-boxer compose candy-sprinkles, add the
sugar-boxer-specific features that don't belong upstream:

- Text alignment Left / Center / Right within the box.
- Outer margin (vs inner padding).

## Files

**Modify:** `sugar-boxer/src/Boxer.php` — `withAlign(HAlign)`,
`withMargin(int $top, int $right = 0, int $bottom = 0, int $left = 0)`.

## Tests

- One per feature.

## Acceptance

- `cd sugar-boxer && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
