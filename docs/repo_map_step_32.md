# Step 32 — candy-tetris + candy-mines adopt candy-buffer/mouse/testing

**Branch:** `ai/games-shared`
**Depends on:** step-02 (candy-buffer), step-05 (candy-mouse), step-04 (candy-testing), step-30 (audit)
**Blocks:** —

## Goal

The two flagship game libs (candy-tetris, candy-mines) adopt candy-buffer for the playfield grid, candy-mouse for click-to-flag/click-to-reveal interaction, and candy-testing for snapshot tests of game-state renders. These libs are cited as already-superior-to-upstream (§442/§444) — bringing them onto shared foundations preserves that superiority while gaining ecosystem-wide improvements.

Reference: step-30 audit; §442 (candy-tetris superiority), §444 (candy-mines superiority), §157 (O(1) win detection only in candy-mines — others need snapshot tests).

## Files expected to be modified

- Each composer.json — add candy-buffer + candy-mouse + candy-testing (dev) via `path-repo-closure`.
- candy-tetris playfield renderer → candy-buffer (10×20 cell grid is canonical for candy-buffer).
- candy-tetris piece preview / score panel → candy-buffer cells.
- candy-mines minefield renderer → candy-buffer; clicks via candy-mouse (zone per cell).
- candy-mines flag-tracking still in domain code — UndoActionType enum from step-9 can route Flag / Reveal / Chord actions if super-candy isn't already showing the way.
- Tests for both: add assertGoldenAnsi snapshot of representative game states.

## Acceptance criteria

- [ ] Both games' existing test suites pass.
- [ ] Playfield/minefield render via Buffer; per-cell rune+style.
- [ ] Mouse clicks on minefield cells trigger reveal/flag.
- [ ] Snapshot tests pin canonical game states (e.g., minesweeper "first click safe area", tetris "T-spin completion").
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. Read step-30 audit for both libs.
2. **path-repo closure**: candy-buffer + candy-mouse + candy-testing (dev) in both.
3. **candy-tetris**: replace ad-hoc playfield string composition with Buffer. Piece rendering = cells with style. Score panel = separate sub-buffer composited via `Buffer::withRegion`.
4. **candy-mines**: minefield as Buffer; each cell zone-tagged via `\SugarCraft\Mouse\Mark::wrap("cell:$r:$c", $glyph)`; on mouse event, `Scanner::hit($x,$y)` → zone id → cell.
5. **Snapshot tests** in candy-testing with golden files.
6. Run phpunit + check-path-repos.

## Tester brief

- candy-tetris: snapshot of starting board, snapshot after a T-Spin, snapshot of pause overlay.
- candy-mines: snapshot of first-click safe area, snapshot of cascade reveal, snapshot of win overlay.
- Mouse-click test: click coords in candy-mines → expected cell revealed.

## Scribe brief

- Each lib's README: `## Shared foundations` section.
- CALIBER_LEARNINGS: per-lib notes.

## Ship brief

- **PR title**: `candy-tetris + candy-mines: adopt candy-buffer + candy-mouse + candy-testing`
- **PR body**:
  ```
  ## Summary
  - Both flagship game libs migrate onto shared foundations.
  - Playfield/minefield renders via Buffer; clicks via candy-mouse Scanner.
  - Snapshot tests via candy-testing pin canonical game states.
  - Preserves the superiority over upstream cited in repo_map_update §442/§444.

  ## Test plan
  - [x] vendor/bin/phpunit in candy-tetris + candy-mines (≥95% each)
  - [x] Snapshot tests for canonical game states
  - [x] Mouse-click tests
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_32.md, docs/repo_map_update_followups.md
  ```
- Commit subject: `candy-tetris + candy-mines: adopt candy-buffer + candy-mouse + candy-testing`.
