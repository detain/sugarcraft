# Step 18 — sugar-table onto candy-buffer

**Branch:** `ai/sugar-table-shared`
**Depends on:** step-02 (candy-buffer), step-03 (candy-layout) optional for column sizing
**Blocks:** —

## Goal

Migrate `sugar-table` onto candy-buffer. sugar-table is the most table-heavy lib outside of sugar-bits' Table primitive; per-cell styleFunc (cited as a sugar-bits superiority over upstream bubbles) benefits from cell-grid rendering.

Reference: §369.5 (consolidate buffer rendering).

## Files expected to be modified

- `sugar-table/composer.json` — add `sugarcraft/candy-buffer` and optionally `sugarcraft/candy-layout` via `path-repo-closure`.
- `sugar-table/src/Table.php` (or main renderer) — build cells through candy-buffer.
- Tests get byte-snapshot pinning.

## Acceptance criteria

- [ ] All existing sugar-table tests pass; byte snapshots confirm no diff.
- [ ] Per-cell styleFunc still works — it now sets styles on Buffer Cells rather than building styled strings.
- [ ] Wide-char (CJK) column content lays out correctly.
- [ ] If candy-layout was added: column constraints `[Min, Fill, Fixed]` work.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **path-repo closure**: candy-buffer (+ optionally candy-layout).
2. **Refactor renderer** to build a Buffer; populate per-cell with rune + style + width.
3. **styleFunc**: changes from `(int $row, int $col, string $value): string` (returning styled string) to `(int $row, int $col, string $value): Style` (returning Style applied to the cell). Provide a back-compat wrapper if any consumer uses the old signature.
4. Run phpunit + check-path-repos.

## Tester brief

- Existing suite green.
- styleFunc test with Style return — assert Cell.style at given coord.
- Wide-char column: row with `['short', '中文', 'longer label']` — assert column widths and overall byte output.

## Scribe brief

- `sugar-table/README.md`: `## Shared foundations` + styleFunc signature note (Style-return is the new way; string-return wrapper exists for back-compat).
- `sugar-table/CALIBER_LEARNINGS.md`: styleFunc signature shift documented.

## Ship brief

- **PR title**: `sugar-table: render through candy-buffer`
- **PR body**:
  ```
  ## Summary
  - sugar-table renderer migrated to candy-buffer cell grid.
  - styleFunc now returns a Style (back-compat wrapper preserves the string-return form).
  - Wide-char column content lays out correctly.
  - Byte snapshots confirm no regression.

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-table (≥95% coverage)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_18.md, docs/repo_map_update.md §369.5
  ```
- Commit subject: `sugar-table: render via candy-buffer`.
