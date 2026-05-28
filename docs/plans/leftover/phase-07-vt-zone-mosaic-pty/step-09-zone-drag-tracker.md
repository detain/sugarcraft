# Step 07.09 — candy-zone DragTracker

**Source:** `leftover_updates_later.md` candy-zone Priority 2
**Branch:** `ai/zone-drag-tracker`

## Deliverable

Add `DragTracker` + `MsgZoneDragStart` / `MsgZoneDragMove` / `MsgZoneDragEnd`.
Tracks press → move → release sequences within and across zones.

## Files

**Create:**
- `candy-zone/src/DragTracker.php`.
- `candy-zone/src/Msg/ZoneDragStartMsg.php`, `ZoneDragMoveMsg.php`,
  `ZoneDragEndMsg.php`.

## Tests

- `candy-zone/tests/DragTrackerTest.php` — simulate press in zone-A,
  move into zone-B, release; assert correct sequence with
  `originZone='A'`, `currentZone='B'`.

## Acceptance

- `cd candy-zone && vendor/bin/phpunit --filter DragTracker` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
