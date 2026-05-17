# Step 07.06 — candy-vt DECOM origin + DECSCUSR cursor shape + focus events 1004 (P1.3-5)

**Source:** `leftover_updates_later.md` candy-vt P1.3 + P1.4 + P1.5
**Branch:** `ai/vt-decom-decscusr-focus`

## Deliverable

Three small mode-handler additions:

- **P1.3 DECOM** (Origin Mode) — `CSI ? 6 h/l`. When set, cursor
  addressing is relative to scroll region.
- **P1.4 DECSCUSR** (cursor shape) — `CSI Ps SP q`. 0/1=blinking block,
  2=steady block, 3=blinking underline, 4=steady underline, 5=blinking
  bar, 6=steady bar.
- **P1.5 Focus events 1004** — `CSI ? 1004 h/l` enables focus-in/out
  reports (`CSI I` / `CSI O`).

## Files

**Modify:**
- `candy-vt/src/Mode/Mode.php` — add `$originMode`, `$cursorShape`
  (enum), `$reportFocusEvents` fields with immutable withers.
- `candy-vt/src/Handler/{CursorHandler,ModeParser,SgrHandler}.php`
  honor them.
- New `candy-vt/src/CursorShape.php` enum.
- New `candy-vt/src/Msg/FocusInMsg.php` + `FocusOutMsg.php`.

## Tests

- One test per feature under `tests/Mode/`.

## Acceptance

- `cd candy-vt && vendor/bin/phpunit --filter "Origin|CursorShape|FocusEvent"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
