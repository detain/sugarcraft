# Step 10.12 — sugar-stickers sticky positioning + scroll sync (post SSOT-03)

**Source:** `leftover_updates_later.md` sugar-stickers Priority 2 + 4
**Branch:** `ai/stickers-sticky-syncscroll`
**Bundle hint:** depends on step 04.02 SSOT cleanup

## Deliverable

After step 04.02 made sugar-stickers consume sugar-bits, add the
sticker-specific features:

- Sticky positioning: header/footer regions stay visible as content
  scrolls.
- Scroll synchronization between two viewports (e.g. side-by-side
  diff scrolling locked).

## Files

**Modify:** `sugar-stickers/src/Viewport.php` (extends sugar-bits).
Add `withStickyHeader(int $rows)`, `withStickyFooter(int $rows)`,
`syncWith(Viewport)`.

## Tests

- One per feature.

## Acceptance

- `cd sugar-stickers && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
