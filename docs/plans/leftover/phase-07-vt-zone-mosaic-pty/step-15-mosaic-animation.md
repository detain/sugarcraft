# Step 07.15 — sugar-image animation support (consumes sugar-animation frames)

**Source:** `leftover_updates_later.md` sugar-image P2 #8 + strategic split
**Branch:** `ai/mosaic-animation`

## Deliverable

GIF/MP4-style animation in mosaic. Per the strategic split documented
in plans/leftover_updates_later.md §6: **sugar-animation emits frame bytes;
sugar-image owns the Kitty/iTerm2/WezTerm protocol output**.

This step adds the consumer side — `Animation` class that takes a
sequence of frames from sugar-animation and drives the renderer.

## Files

**Create:**

- `sugar-image/src/Animation.php` — readonly: array of frames + per-frame
  delays. Methods to play / pause / step.
- `sugar-image/src/AnimationDriver.php` — drives an Animation onto a
  Renderer; uses candy-core `Cmd::tick(...)` for frame timing.

**Modify:**

- `sugar-image/src/Renderer/KittyRenderer.php` — `renderFrame()` /
  `deletePrevious()` API for animation.
- `sugar-image/composer.json` — add `"sugarcraft/sugar-animation": "@dev"`
  (it's the source of frames) — IF the split says so. Verify with
  strategic-plan step 11.04.

## Tests

- `sugar-image/tests/AnimationDriverTest.php` — fixture animation
  with 5 frames, asserting render+delete cycle.

## Acceptance

- `cd sugar-image && vendor/bin/phpunit --filter Animation` green.

## Notes

- This step assumes step 11.04 (strategic plan for sugar-animation/mosaic
  split) has landed first or its conclusions are stable. If unclear,
  block this step until 11.04 ships.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
