# Step 07.08 — candy-mouse ZoneHoverTracker

**Source:** `leftover_updates_later.md` candy-mouse Priority 1
**Branch:** `ai/zone-hover-tracker`

## Deliverable

Add `ZoneHoverTracker` + `MsgZoneEnter` / `MsgZoneExit`. Tracks which
zone the cursor is in across mouse-move events and emits enter/exit
messages on boundary crossings.

## Files

**Create:**

- `candy-mouse/src/ZoneHoverTracker.php` — wraps `Manager`; tracks
  `?string $currentZone` across consecutive move events; emits Msg
  on transitions.
- `candy-mouse/src/Msg/ZoneEnterMsg.php`, `ZoneExitMsg.php`.

## Tests

- `candy-mouse/tests/ZoneHoverTrackerTest.php` — simulate mouse-move
  events across zones; assert enter/exit sequence.

## Acceptance

- `cd candy-mouse && vendor/bin/phpunit --filter ZoneHover` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
