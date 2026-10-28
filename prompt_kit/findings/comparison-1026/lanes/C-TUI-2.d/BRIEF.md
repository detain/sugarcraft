### C-TUI-2.d — Diff-review core (model, per-file stats, per-turn diff)
- item: C-TUI-2 (sources: GAP-GIT-09)
- kind: gap · effort M
- approach: Today `/diff [n]` prints fenced text (`src/Host/Commands/DiffCommand.php`) from `CheckpointDiff::of()`, which is the checkpoint against now.
  - `CheckpointDiff` gains `files()`: per-file `{status, path, added, removed, patch slice}` parsed from its bounded patch with sugar-diff `UnifiedScan`.
  - It also gains `between()`: checkpoint k against checkpoint k−1, from two `WorkspaceCheckpointer::trees()` targets. That is the per-turn view.
  - A pure `DiffReview` model holds the file list with ±counts, the selected file, the base index (steps back), a `this turn only` toggle (`t`) and the body scroll. `update(KeyMsg)` returns `[self, ?DiffReviewAction]`.
  - The view is a file list plus a body painted through DiffGutter.
  - `DiffReviewSource` loads a review for (store, session id, root, stepsBack) via `Checkpoints::fileCheckpointPositions()` and reports how many bases exist.

  No wiring in this step.
- files.create:
  - `candy-code/src/Tui/DiffReview/DiffReview.php`
  - `candy-code/src/Tui/DiffReview/DiffReviewAction.php`
  - `candy-code/src/Tui/DiffReview/DiffReviewActionKind.php`
  - `candy-code/src/Tui/DiffReview/DiffReviewSource.php`
  - `candy-code/src/Tui/DiffReview/OpenDiffReviewMsg.php`
- files.modify: `candy-code/src/Workspace/CheckpointDiff.php` (`files()`, `between()`); `candy-code/lang/en.php` (`tui.diffreview.*`)
- tests:
  - Create `candy-code/tests/Tui/DiffReview/DiffReviewTest.php`: navigation, base cycling bounds, the turn toggle, empty and omitted-lines states.
  - Create `candy-code/tests/Workspace/CheckpointDiffFilesTest.php`: per-file counts; `between()` on two real git snapshots; binary rows.
- docs/guards: `tools/check-one-type-per-file.php`; LangParityTest
- serialized: [lang:tui.diffreview.]
- deps: none
- phase: normal
- ruling: none
- risks: `between()` must keep the `screenSafe`/`bounded` treatment, or a binary diff reaches the frame.

