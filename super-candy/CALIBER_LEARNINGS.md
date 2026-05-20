# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:confirmation-gate]** Destructive operations (delete/copy/move/rename) use a three-phase confirm gate: `armXxx()` sets `ConfirmState::XxxSelected` + status prompt; the next `KeyMsg` is consumed by `resolveConfirm()` which checks for `y` to `performXxx()` or any other key to cancel. Copy undo is informational only — `UndoAction::copy` logs the mapping but `reverseAction()` skips it since the original is preserved. This prevents accidental destructive acts while keeping the undo stack accurate for real reversals.
