# Step 07.20 — candy-vcr Relative-timestamp format mode

**Source:** `leftover_updates_later.md` candy-vcr M1
**Branch:** `ai/vcr-relative-timestamps`

## Deliverable

Add a relative-timestamp mode for cassette events. Today JsonlFormat
records absolute timestamps. Relative timestamps are essential for
deterministic replay (recordings are independent of when they were
made).

## Files

**Create:**
- `candy-vcr/src/Format/RelativeFormat.php` — serializes/deserializes
  events with `dt` (delta-since-previous-event) instead of `t`
  (absolute).

**Modify:**
- `candy-vcr/src/Recorder.php` — `withFormat(Format $f)` selector.
- `candy-vcr/src/Player.php` — handles both formats transparently.

## Tests

- `candy-vcr/tests/Format/RelativeFormatTest.php` — round-trip.
- Replay equivalence test: record with both formats, assert visual
  result identical.

## Acceptance

- `cd candy-vcr && vendor/bin/phpunit --filter "Relative"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
