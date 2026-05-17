# Step 09.07 — candy-mines O(1) win detection + board serialization + custom difficulty UI

**Source:** `leftover_updates_later.md` candy-mines MEDIUM
**Branch:** `ai/mines-win-serialize-custom`

## Deliverable

- Track revealed-count counter so win-detection is O(1) (today scans
  the board).
- Board serialization (save/load mid-game).
- Custom-difficulty UI.

## Files

**Modify:**
- `candy-mines/src/Board.php` — track `revealedCount` field; win check
  becomes `revealedCount === totalSafeCells`.
- Add `serialize(): string`, `unserialize(string)`.

**Create:**
- `candy-mines/src/Ui/CustomDifficulty.php` — form for rows/cols/mines.

## Tests

- One per feature.

## Acceptance

- `cd candy-mines && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
