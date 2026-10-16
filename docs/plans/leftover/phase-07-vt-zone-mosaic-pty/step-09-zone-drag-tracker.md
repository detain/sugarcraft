# Step 07.09 — sugar-mouse DragTracker

**Source:** `leftover_updates_later.md` sugar-mouse Priority 2
**Branch:** `ai/zone-drag-tracker`

## Deliverable

Add `DragTracker` + `MsgZoneDragStart` / `MsgZoneDragMove` / `MsgZoneDragEnd`.
Tracks press → move → release sequences within and across zones.

## Files

**Create:**

- `sugar-mouse/src/DragTracker.php`.
- `sugar-mouse/src/Msg/ZoneDragStartMsg.php`, `ZoneDragMoveMsg.php`,
  `ZoneDragEndMsg.php`.

## Tests

- `sugar-mouse/tests/DragTrackerTest.php` — simulate press in zone-A,
  move into zone-B, release; assert correct sequence with
  `originZone='A'`, `currentZone='B'`.

## Acceptance

- `cd sugar-mouse && vendor/bin/phpunit --filter DragTracker` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
