# Step 04.02 — sugar-flexbox composes sugar-widgets Viewport/Scrollbar

**Source:** `leftover_updates_later.md` SSOT-03 + sugar-flexbox research Priority 1 + 3
**Branch:** `ai/stickers-composes-bits`

## Deliverable

sugar-flexbox research lists `Viewport` (Priority 1) and `Scrollbar`
(Priority 3) as missing — but both already exist in
`sugar-widgets/src/Viewport/Viewport.php` and `sugar-widgets/src/Scrollbar/`.
Compose them; don't reinvent.

## Files

**Modify:**

- `sugar-flexbox/src/Viewport.php` — `final class Viewport extends \SugarCraft\Widgets\Viewport\Viewport`
  (or composition wrapper). Add sticker-specific extras (sticky
  positioning headers/footers as you scroll).
- `sugar-flexbox/composer.json` — add `"sugarcraft/sugar-widgets": "@dev"`
  + path-repo.

**Defer** the sticky-positioning + scroll-sync behaviour to step 10.12
(post-SSOT-03 feature work).

## Acceptance

- `grep -rn "class Viewport\b\|class Scrollbar\b" sugar-flexbox/src`
  shows it extending or composing sugar-widgets, not reimplementing.
- `cd sugar-flexbox && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
