# Step 09.08 — candy-tetris SRS rotation with official kick tables

**Source:** `leftover_updates_later.md` candy-tetris HIGH
**Branch:** `ai/tetris-srs`

## Deliverable

Implement the Super Rotation System (SRS) with the official wall-kick
tables. Today rotation is naive.

## Files

**Modify:**
- `candy-tetris/src/Piece.php` — rotation states 0/R/2/L; transitions
  via kick tables.

**Create:**
- `candy-tetris/src/Rotation/SrsKickTable.php` — per-tetromino kick
  data (separate for J/L/S/T/Z and I).

## Tests

- `candy-tetris/tests/Rotation/SrsKickTableTest.php` — verifies each
  rotation transition.

## Acceptance

- `cd candy-tetris && vendor/bin/phpunit --filter Srs` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
