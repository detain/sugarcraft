# Step 27 — Wire buffer-diff into renderers

**Branch:** `ai/buffer-diff-consumers`
**Depends on:** step-26 (Buffer::diff implemented)
**Blocks:** —

## Goal

Wire `Buffer::diff()` into six renderers that currently do full re-render every frame: `sugar-boxer`, `sugar-dash`, `sugar-crush`, `sugar-veil`, `sugar-stickers`, `candy-lister`. Each becomes capable of emitting only the delta — major SSH-bandwidth + flicker-reduction win.

Reference: §59 (six libs cited), §89.1 (SSH bandwidth), §387.5.

## Files expected to be modified

- Each of the 6 lib's composer.json — add `sugarcraft/candy-buffer` if not already there (sugar-veil and friends already got it earlier).
- Each lib's main renderer:
  - Maintain a `?Buffer $previousFrame` field.
  - On render: build current `Buffer`, compute `current->diff(previous)`, emit only the delta via `DiffEncoder::encode($ops)`, store current as previous.
  - On first render (previous=null), emit full Buffer (no diff).
  - On force-redraw (window resize / cursor lost), reset previous to null.

## Acceptance criteria

- [ ] Each of the 6 libs renders deltas after the first frame.
- [ ] First-frame output unchanged (full render).
- [ ] Window resize triggers a full re-render (clearing previousFrame).
- [ ] Existing tests pass (most assert byte output; for libs that don't expose the diff path, tests pin first-frame output and behavior remains correct).
- [ ] Add per-lib benchmark test: render N consecutive small-change frames; total bytes emitted ≤ 30× N (vs full re-render baseline ≥ 1920× N for an 80×24 frame).
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **path-repo closure**: candy-buffer in each lib (most are already requiring it).
2. **Per lib**: introduce `previousFrame` instance state; refactor render() to compute Buffer first, then either full-emit (first frame / forced) or diff-emit (subsequent).
3. **Window resize handling**: when the lib receives a WindowSize change, reset previousFrame to null.
4. **Tests**: pin first-frame output (existing); add bytes-emitted assertion for subsequent frames with small changes.
5. Run phpunit + check-path-repos.

## Tester brief

- For each lib: build a fake Program scenario, render frame 1 (assert full output), render frame 2 with 1-cell change (assert byte count tiny), render frame 3 with full clear (assert appropriate diff).
- Window resize test: simulate resize → next frame is a full re-render.
- Regression: existing per-lib byte-snapshot tests must still pass for frame 1.

## Scribe brief

- Each lib's README: add `## Buffer diffing` note explaining the SSH bandwidth + flicker win.
- CALIBER_LEARNINGS in each: "Reset previousFrame on resize / cursor-position-lost / first paint. Don't try to diff across these boundaries."

## Ship brief

- **PR title**: `sugar-boxer + sugar-dash + sugar-crush + sugar-veil + sugar-stickers + candy-lister: emit Buffer diffs`
- **PR body**:
  ```
  ## Summary
  - Six renderers wired to candy-buffer's diff() (step-26).
  - Subsequent frames emit only delta ANSI ops — major SSH-bandwidth & flicker win.
  - First-frame output + window-resize trigger full re-render (correct behavior).
  - Per-lib bytes-emitted tests document the win.

  ## Test plan
  - [x] vendor/bin/phpunit in all 6 libs (≥95% each, byte-snapshots green, diff-emit asserts)
  - [x] Window resize → full re-emit verified
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_27.md, docs/repo_map_update.md §59, §89.1, §387.5
  ```
- Commit subject: `6 renderers: emit Buffer diffs for delta ANSI updates`.
