# Step 17 — sugar-charts onto candy-buffer

**Branch:** `ai/sugar-charts-shared`
**Depends on:** step-02 (candy-buffer)
**Blocks:** —

## Goal

Migrate `sugar-charts` (LineChart, BarChart, Sparkline, etc.) onto candy-buffer for cell-grid rendering. Charts are *the* canonical use case for cell-level diffing (live dashboards re-rendering on tick) — building on candy-buffer enables step-26/27 buffer-diff wins for charts later.

Reference: §369.5 (consolidate buffer rendering), §387.5 (string-composition reinvention).

## Files expected to be modified

- `sugar-charts/composer.json` — add `sugarcraft/candy-buffer` via `path-repo-closure`.
- `sugar-charts/src/LineChart/LineChart.php` — render via candy-buffer.
- `sugar-charts/src/BarChart/BarChart.php` — render via candy-buffer.
- `sugar-charts/src/Sparkline.php` (or wherever sparkline lives) — render via candy-buffer.
- Other chart types as applicable.
- Tests get byte-snapshot pinning before/after.

## Acceptance criteria

- [ ] All existing sugar-charts tests pass; byte-snapshot tests show no diff for any existing fixture.
- [ ] Each chart type's `render()` builds a Buffer internally; existing public string-return API preserved.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **path-repo closure**: add candy-buffer to sugar-charts.
2. **Per chart type**: refactor `render()` to build a Buffer, then call Buffer's toAnsi() (or string-render fallback — note in updates if Buffer::toAnsi isn't fully implemented yet).
3. **Wide-char awareness**: charts that include labels in CJK / emoji should respect cell width through candy-buffer's Cell width field.
4. Run phpunit + check-path-repos.

## Tester brief

- For each chart type: byte-snapshot test of existing fixtures unchanged.
- Add one wide-char label test (e.g., BarChart with `'値1', '値2'` labels) and pin the expected byte output.
- Coverage report per chart class.

## Scribe brief

- `sugar-charts/README.md`: `## Shared foundations` mentioning candy-buffer.
- `sugar-charts/CALIBER_LEARNINGS.md`: "All chart renderers build a Buffer first. Don't re-implement string padding — use candy-buffer."

## Ship brief

- **PR title**: `sugar-charts: render through candy-buffer`
- **PR body**:
  ```
  ## Summary
  - sugar-charts chart renderers (LineChart, BarChart, Sparkline, ...) build a Buffer instead of string-padding.
  - Sets up sugar-charts for the step-27 buffer-diff wiring (live-dashboard SSH-bandwidth wins).
  - Wide-char label rendering correct via Buffer's width-aware Cells.
  - Byte snapshots confirm no regression on existing fixtures.

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-charts (≥95% coverage, byte-snapshots green)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_17.md, docs/repo_map_update.md §369.5, §387.5
  ```
- Commit subject: `sugar-charts: render via candy-buffer`.
