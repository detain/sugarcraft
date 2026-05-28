# Step 34 — sugar-calendar + sugar-toast adopt candy-buffer + candy-testing

**Branch:** `ai/widget-shared`
**Depends on:** step-02 (candy-buffer), step-04 (candy-testing), step-30 (audit)
**Blocks:** —

## Goal

Two widget libs (sugar-calendar, sugar-toast) adopt candy-buffer for grid rendering and candy-testing for snapshot coverage. sugar-calendar's date grid is naturally cell-based; sugar-toast's notification queue benefits from per-cell composition for overlapping toasts.

Reference: step-30 audit. sugar-toast superiority cited (§427).

## Files expected to be modified

- 2 composer.json — add candy-buffer (prod) + candy-testing (dev) via `path-repo-closure`.
- sugar-calendar date grid + event overlay → Buffer cells.
- sugar-toast positioning + multi-alert stacking → Buffer composition.
- Tests in each: assertGoldenAnsi snapshot for canonical layouts.

## Acceptance criteria

- [ ] Both libs render via Buffer.
- [ ] Snapshot tests pin canonical layouts (calendar of month, toast queue with 3 alerts).
- [ ] Existing tests pass.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. Step-30 audit for both libs.
2. **path-repo closure**.
3. sugar-calendar: month grid as 7-col × N-row Buffer; events laid into target cells; render to ANSI via Buffer.
4. sugar-toast: position+stack via Buffer compositions; each toast a sub-Buffer composited into the screen Buffer.
5. Snapshot tests.
6. Run phpunit + check-path-repos.

## Tester brief

- sugar-calendar: snapshot of January 2026 with 3 events scattered, snapshot of week-view.
- sugar-toast: snapshot of empty queue, 1 alert, 3 stacked alerts, queue at max-positions.

## Scribe brief

- README + CALIBER_LEARNINGS each.

## Ship brief

- **PR title**: `sugar-calendar + sugar-toast: adopt candy-buffer + candy-testing`
- **PR body**:
  ```
  ## Summary
  - sugar-calendar grid and sugar-toast queue render via Buffer.
  - Snapshot tests via candy-testing pin canonical layouts.
  - Existing UX + superiority over upstream preserved.

  ## Test plan
  - [x] vendor/bin/phpunit in both (≥95%)
  - [x] Snapshot fixtures committed
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_34.md, docs/repo_map_update_followups.md
  ```
- Commit subject: `sugar-calendar + sugar-toast: adopt candy-buffer + candy-testing`.
