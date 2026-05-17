# Step 12.02 — macOS flake test fix (OrphanedChildReap + Python REPL) (DEFERRED)

**Source:** `leftover_updates.md` P5-LO-03
**Branch:** `ai/macos-flake-tests`
**Deferred per user instruction.**

## Deliverable

`OrphanedChildReapTest` and `PythonReplTest` flake on macOS. Two-step
fix:

1. Add `markTestSkipped` on Darwin with a clear comment referencing
   this step file.
2. Fix the CI image — pre-install `python3`; fix the orphan-detection
   harness so it doesn't rely on the test process being its own session
   leader (use a pgrep-based approach instead).

## Files

**Modify:**
- `candy-pty/tests/Integration/OrphanedChildReapTest.php` — add Darwin
  skip with comment.
- `candy-pty/tests/Integration/PythonReplTest.php` — same.
- `.github/workflows/pty-matrix.yml` — pre-install python3 step.

## Acceptance

- macOS CI matrix is stable across 10 consecutive runs.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
