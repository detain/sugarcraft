# Step 03.03 — sugar-dash Grid reorg part 3: events + state plumbing

**Source:** `leftover_updates_later.md` Dash-01 (part 3 of 3)
**Branch:** `ai/dash-reorg-events-state`

## Deliverable

Move the remaining `src/Grid/` files — event plumbing, state widgets,
keymap helpers. After this step, `src/Grid/` should be empty (or
contain at most one BC stub).

## Files

**Move event-plumbing files** from `src/Grid/`:
- `Event.php`, `EventDispatcher.php`, `EventHandler.php` →
  `src/Events/`
- `FocusEvent.php`, `KeyEvent.php`, `MouseEvent.php`,
  `PasteEvent.php`, `ResizeEvent.php` → `src/Events/`
- `Key.php`, `KeyAction.php`, `KeyMap.php` → `src/Keys/`
- `Focus.php` → `src/Layout/FocusManager.php` (if not already there) or
  `src/Events/Focus.php` for the event-bus version

**Move state widgets:**
- `State.php` → split per step 03.12 (deferred to that step; for now
  just move the file as-is into `src/State/State.php` if not already
  there).
- `EdgeStyle.php`, `Segment.php` → `src/Foundation/`
- `Progress.php`, `ProgressRing.php` → `src/Plot/Gauge/` or wherever
  the rest of the progress family lives.

**Delete the now-empty `src/Grid/` directory** — if `git rm -r src/Grid`
is clean (no files left), drop the directory entirely.

**Update** all imports.

## Acceptance

- `find sugar-dash/src/Grid -type f` returns nothing (or one BC stub
  with a comment).
- `cd sugar-dash && vendor/bin/phpunit` green.
- Every example runs.
- README's namespace table no longer references `Grid\`.

## Notes

- After this lands, the stalled Phase-0 reorg is done. Subsequent
  sugar-dash steps (03.04 onwards) build on a clean tree.
- The `State.php` UML-widget split (PSR-4 violation) happens in
  step 03.12 — leave the file intact here.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
