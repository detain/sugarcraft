# Step 07.15 — candy-mosaic animation support (consumes candy-flip frames)

**Source:** `leftover_updates_later.md` candy-mosaic P2 #8 + strategic split
**Branch:** `ai/mosaic-animation`

## Deliverable

GIF/MP4-style animation in mosaic. Per the strategic split documented
in plans/leftover_updates_later.md §6: **candy-flip emits frame bytes;
candy-mosaic owns the Kitty/iTerm2/WezTerm protocol output**.

This step adds the consumer side — `Animation` class that takes a
sequence of frames from candy-flip and drives the renderer.

## Files

**Create:**
- `candy-mosaic/src/Animation.php` — readonly: array of frames + per-frame
  delays. Methods to play / pause / step.
- `candy-mosaic/src/AnimationDriver.php` — drives an Animation onto a
  Renderer; uses candy-core `Cmd::tick(...)` for frame timing.

**Modify:**
- `candy-mosaic/src/Renderer/KittyRenderer.php` — `renderFrame()` /
  `deletePrevious()` API for animation.
- `candy-mosaic/composer.json` — add `"sugarcraft/candy-flip": "@dev"`
  (it's the source of frames) — IF the split says so. Verify with
  strategic-plan step 11.04.

## Tests

- `candy-mosaic/tests/AnimationDriverTest.php` — fixture animation
  with 5 frames, asserting render+delete cycle.

## Acceptance

- `cd candy-mosaic && vendor/bin/phpunit --filter Animation` green.

## Notes

- This step assumes step 11.04 (strategic plan for candy-flip/mosaic
  split) has landed first or its conclusions are stable. If unclear,
  block this step until 11.04 ships.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
