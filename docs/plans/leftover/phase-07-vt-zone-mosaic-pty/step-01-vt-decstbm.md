# Step 07.01 — sugar-vterm DECSTBM scroll margins (P0.1)

**Source:** `leftover_updates_later.md` sugar-vterm P0.1
**Branch:** `ai/vt-decstbm`

## Deliverable

Implement DEC Set Top and Bottom Margins (`CSI Ps;Ps r`). Today
`Handler/ScrollHandler.php:14-16` comments "Operates on the full screen;
per-region scroll margins (DECSTBM)…" not implemented. Add scroll-region
support throughout the scroll handler.

## Files

**Modify:**

- `sugar-vterm/src/Handler/ScrollHandler.php` — track top/bottom margin
  rows; `scroll up/down` respects them.
- `sugar-vterm/src/Screen/Screen.php` — `scrollRegion(int $top, int $bottom)`
  setter / getter; default to full screen.
- `sugar-vterm/src/Parser/Parser.php` — dispatches `CSI r` → handler.

## Tests

- `sugar-vterm/tests/Handler/ScrollHandlerDecstbmTest.php` — feed
  `\x1b[5;15r` to set margins, then scroll, assert only the region
  scrolls.

## Acceptance

- `cd sugar-vterm && vendor/bin/phpunit --filter Decstbm` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
