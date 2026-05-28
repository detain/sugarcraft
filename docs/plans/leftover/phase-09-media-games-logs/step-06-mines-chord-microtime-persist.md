# Step 09.06 — candy-mines chord clicks + microtime precision + difficulty persistence

**Source:** `leftover_updates_later.md` candy-mines HIGH
**Branch:** `ai/mines-chord-micro-persist`

## Deliverable

- Chord clicks — middle-click (or left+right simultaneously) reveals
  all neighbors of a satisfied number.
- Timer precision: `time()` → `microtime(true)`.
- Difficulty stats persistence (best times per difficulty, JSON file).

## Files

**Modify:**
- `candy-mines/src/Board.php` — `chord(int $x, int $y): self`.
- `candy-mines/src/Timer.php` — microtime.
- `candy-mines/src/Stats/DifficultyStats.php` — atomic JSON
  persistence (Homedash pattern from step 03.12).

## Tests

- One per feature.

## Acceptance

- `cd candy-mines && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
