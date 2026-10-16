# Step 09.01 — sugar-animation imagecreatefromstring + per-frame timing

**Source:** `leftover_updates_later.md` sugar-animation P1 + P2
**Branch:** `ai/flip-image-timing`

## Deliverable

- Replace temp-file frame extraction with `imagecreatefromstring()`.
- Per-frame timing from GIF Graphic Control Extension (today all frames
  use the same delay).

## Files

**Modify:**

- `sugar-animation/src/GifDecoder.php` (or equivalent) — in-memory frame
  decode; parse GCE for `delay` field per frame.

## Tests

- `sugar-animation/tests/PerFrameTimingTest.php`.

## Acceptance

- `cd sugar-animation && vendor/bin/phpunit --filter "Image|Timing"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
