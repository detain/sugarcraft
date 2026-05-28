# Step 04.02 — sugar-stickers composes sugar-bits Viewport/Scrollbar

**Source:** `leftover_updates_later.md` SSOT-03 + sugar-stickers research Priority 1 + 3
**Branch:** `ai/stickers-composes-bits`

## Deliverable

sugar-stickers research lists `Viewport` (Priority 1) and `Scrollbar`
(Priority 3) as missing — but both already exist in
`sugar-bits/src/Viewport/Viewport.php` and `sugar-bits/src/Scrollbar/`.
Compose them; don't reinvent.

## Files

**Modify:**
- `sugar-stickers/src/Viewport.php` — `final class Viewport extends \SugarCraft\Bits\Viewport\Viewport`
  (or composition wrapper). Add sticker-specific extras (sticky
  positioning headers/footers as you scroll).
- `sugar-stickers/composer.json` — add `"sugarcraft/sugar-bits": "@dev"`
  + path-repo.

**Defer** the sticky-positioning + scroll-sync behaviour to step 10.12
(post-SSOT-03 feature work).

## Acceptance

- `grep -rn "class Viewport\b\|class Scrollbar\b" sugar-stickers/src`
  shows it extending or composing sugar-bits, not reimplementing.
- `cd sugar-stickers && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
