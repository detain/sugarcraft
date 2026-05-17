# Step 09.09 — candy-tetris T-Spin + Back-to-Back / combo + DAS/ARR + perfect-clear

**Source:** `leftover_updates_later.md` candy-tetris MEDIUM + LOW
**Branch:** `ai/tetris-tspin-b2b-das`

## Deliverable

Four modern-Tetris mechanics:
- T-Spin detection (3-corner rule).
- Back-to-Back bonus + combo counter.
- DAS / ARR keyboard timing.
- Perfect-clear detection (cleared the whole stack).

## Files

**Modify:**
- `candy-tetris/src/Game.php` — scoring updates, combo state.
- `candy-tetris/src/Input/Das.php` (new).
- `candy-tetris/src/Scoring/TSpin.php` (new).

## Tests

- One per feature.

## Acceptance

- `cd candy-tetris && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
