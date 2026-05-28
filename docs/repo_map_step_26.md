# Step 26 — candy-buffer::diff() — delta ANSI emission

**Branch:** `ai/buffer-diff-impl`
**Depends on:** step-02 (candy-buffer skeleton)
**Blocks:** step-27 (consumers of buffer-diff)

## Goal

Implement the real `Buffer::diff()` method (stubbed in step-02). Produces a minimal sequence of delta ANSI ops (ECH = Erase Character, REP = Repeat, ICH = Insert Character, DCH = Delete Character, CUP = Cursor Position, SGR transitions) given two Buffers (previous frame, current frame). Critical for SSH bandwidth + flicker-free animations.

Reference: `docs/repo_map_update.md` §59 (buffer diffing called critical for 6+ packages), §89.1 (SSH bandwidth), §327.3 (framework).

## Files expected to be created

- `candy-buffer/src/DiffOp.php` — abstract base (or interface) for delta ops.
- `candy-buffer/src/Diff/SetCellOp.php`, `EraseRunOp.php`, `RepeatRunOp.php`, `MoveCursorOp.php`, `SetStyleOp.php`, `SetHyperlinkOp.php` — concrete ops.
- `candy-buffer/src/Diff/DiffEncoder.php` — `encode(list<DiffOp>): string` produces the raw ANSI byte stream.
- `candy-buffer/src/Diff/DiffOptimiser.php` — peephole pass: coalesce adjacent SetStyle into one SGR; coalesce adjacent SetCells into RepeatRunOp; etc.

## Files expected to be modified

- `candy-buffer/src/Buffer.php` — `diff(Buffer $previous): list<DiffOp>` real impl + `applyDiff(list<DiffOp> $ops): self` for round-trip testing.

## Acceptance criteria

- [ ] `Buffer::diff(Buffer $previous): list<DiffOp>` returns a sequence that, when encoded + applied to `$previous`, produces a Buffer equal to `$this`.
- [ ] Identical buffers → empty diff (`[]`).
- [ ] Single-cell change → 1 MoveCursorOp + 1 SetCellOp (+ optional SGR transition).
- [ ] Long horizontal run of same cell → uses RepeatRunOp (REP `\x1b[N b`).
- [ ] Cleared region → uses EraseRunOp (ECH `\x1b[N X`).
- [ ] Per `DiffEncoder::encode`, the byte stream is minimal vs naïve full-repaint (assert via byte-count comparison on representative frames).
- [ ] `Buffer::applyDiff($ops)` is a round-trip inverse: `current.diff(prev).apply(prev) === current`.
- [ ] ≥95 % coverage on diff machinery.
- [ ] `git status` clean on master.

## Coder brief

1. **Spawn a Researcher**: "Provide the canonical ANSI delta-rendering sequences with byte syntax: ECH (Erase Character), REP (Repeat preceding graphic char), ICH (Insert Character), DCH (Delete Character), CUP (Cursor Position). Cite xterm ctlseqs. Provide ratatui's Buffer::diff algorithm (or ultraviolet's) as the reference for the cell-walk + SGR-transition rules."
2. **DiffOp concrete types**: each op stores its minimal data (position, count, cell, style, etc.).
3. **Diff algorithm**:
   - Walk the two buffers row by row.
   - For each row, find runs of equal cells (no op) and runs of changed cells.
   - For a changed run: emit MoveCursorOp to its start, then per-cell SetCellOps (with SGR transitions between style changes), or coalesce to RepeatRunOp if cells match each other.
   - For cleared regions (large blocks of default cells), emit EraseRunOp.
4. **DiffEncoder**: walks ops, emits the ANSI byte stream. Track current cursor position + current SGR style so transitions are minimal.
5. **DiffOptimiser**: peephole over the op list. Merge adjacent SetStyleOps (last wins). Coalesce SetCellOps with same style into a single span. Detect REP-eligible runs.
6. **applyDiff**: interpret ops to produce a new Buffer from a source. Used in tests; also useful for replay debugging.
7. Run phpunit + check-path-repos.

## Tester brief

- Identical buffers: diff returns empty.
- 1-cell change: diff has 1 SetCell + 1 MoveCursor (+ SGR if style differs).
- Horizontal run of 'X' replacing run of ' ': diff has RepeatRun or EraseRun depending on style.
- Vertical scroll: diff handles row shifts (use MoveCursor between rows; full re-emit of changed rows).
- Round-trip: for 20 random buffer pairs, `$current === $previous->applyDiff($current->diff($previous))`.
- Byte-count: for a "typing one char in a 80×24 buffer" diff, output should be ≤ 30 bytes (1 CUP + 1 char + maybe an SGR), NOT a full 80×24 re-emit.
- Wide-char: CJK char replacement updates both the wide cell and its continuation cell correctly.

## Scribe brief

- `candy-buffer/README.md`: a new `## Diffing & delta ANSI` section with worked example (before / after buffer + emitted diff bytes).
- `candy-buffer/CALIBER_LEARNINGS.md`: "DiffEncoder tracks cursor + SGR state across ops — don't reset between op emits unless necessary. The optimiser is what makes the byte stream minimal; don't bypass it."

## Ship brief

- **PR title**: `candy-buffer: implement diff() with delta ANSI emission (ECH/REP/ICH/DCH)`
- **PR body**:
  ```
  ## Summary
  - Buffer::diff() now produces minimal delta ops.
  - DiffEncoder emits ECH / REP / ICH / DCH / CUP / SGR transitions.
  - DiffOptimiser collapses adjacent ops to keep the byte stream tight.
  - Buffer::applyDiff round-trip-tested.
  - Step-27 wires this into 6 renderers (sugar-boxer, sugar-dash, sugar-crush, sugar-veil, sugar-stickers, candy-lister).

  ## Test plan
  - [x] vendor/bin/phpunit in candy-buffer (≥95% coverage, round-trip property tests)
  - [x] Byte-count assertion: 1-char change in 80×24 stays under 30 bytes
  - [x] Wide-char handling correct
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_26.md, docs/repo_map_update.md §59, §89.1, §327.3
  ```
- Commit subject: `candy-buffer: implement Buffer::diff() with delta ANSI ops`.
