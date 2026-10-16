# Step 07.10 — sugar-mouse ClickCounter + setMotionTracking

**Source:** `leftover_updates_later.md` sugar-mouse Priority 3 + 4
**Branch:** `ai/zone-clickcounter-motion`

## Deliverable

- `ClickCounter` — tracks rapid successive clicks (double / triple)
  with a configurable interval (default 500ms).
- `Manager::setMotionTracking(bool)` — toggle mouse motion reporting
  on/off at runtime.

## Files

**Create:**

- `sugar-mouse/src/ClickCounter.php`.
- `sugar-mouse/src/Msg/DoubleClickMsg.php`, `TripleClickMsg.php`.

**Modify:**

- `sugar-mouse/src/Manager.php` — `setMotionTracking(bool)` toggles
  `\x1b[?1002` / `\x1b[?1003` mouse modes.

## Tests

- `sugar-mouse/tests/ClickCounterTest.php`.
- `sugar-mouse/tests/ManagerMotionTrackingTest.php`.

## Acceptance

- `cd sugar-mouse && vendor/bin/phpunit --filter "ClickCounter|MotionTracking"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
