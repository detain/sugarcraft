# Step 07.21 — candy-vcr idle-trim for SPEED_REALTIME mode

**Source:** `leftover_updates_later.md` candy-vcr L3
**Branch:** `ai/vcr-idle-trim-realtime`

## Deliverable

`Player::play()` with `SPEED_REALTIME` honors original delays. Add
`--idle-trim` semantics (already in RecordCommand) to also apply at
replay-time, so users can shorten long idle gaps in playback even
when the cassette has original-timing data.

## Files

**Modify:**
- `candy-vcr/src/Player.php` — `withIdleTrim(?float $seconds)`.
  When set, delays >threshold are clamped to threshold.
- `candy-vcr/src/Cli/ReplayCommand.php` — `--idle-trim N` flag.

## Tests

- `candy-vcr/tests/PlayerIdleTrimTest.php`.

## Acceptance

- `cd candy-vcr && vendor/bin/phpunit --filter IdleTrim` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
