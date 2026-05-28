# Step 09.01 — candy-flip imagecreatefromstring + per-frame timing

**Source:** `leftover_updates_later.md` candy-flip P1 + P2
**Branch:** `ai/flip-image-timing`

## Deliverable

- Replace temp-file frame extraction with `imagecreatefromstring()`.
- Per-frame timing from GIF Graphic Control Extension (today all frames
  use the same delay).

## Files

**Modify:**
- `candy-flip/src/GifDecoder.php` (or equivalent) — in-memory frame
  decode; parse GCE for `delay` field per frame.

## Tests

- `candy-flip/tests/PerFrameTimingTest.php`.

## Acceptance

- `cd candy-flip && vendor/bin/phpunit --filter "Image|Timing"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
