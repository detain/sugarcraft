# Step 12.04 — Windows ConPTY backend (DEFERRED, multi-PR)

**Source:** `leftover_updates.md` deferred section + plans/x-windows.md
**Branch:** `ai/windows-conpty-NN`
**Deferred per user instruction. Multi-PR effort.**

## Deliverable

Implement `SugarCraft\Pty\Windows\ConPtySystem` and have the full
P0–P5 acceptance criteria from the original PTY plan hold on Windows.

## Files (rough sketch)

- `sugar-pty/src/Windows/ConPtySystem.php`
- `sugar-pty/src/Windows/ConPtyPair.php`
- `sugar-pty/src/Windows/ConPtyMaster.php`
- `sugar-pty/src/Windows/ConPtySlave.php`
- `sugar-pty/src/Windows/ConPtyChild.php`
- `sugar-pty/src/Windows/Kernel32.php` — FFI cdef for `CreatePseudoConsole`,
  `ResizePseudoConsole`, `ClosePseudoConsole`, `CreateProcessW`,
  `STARTUPINFOEXW`, `InitializeProcThreadAttributeList`,
  `UpdateProcThreadAttribute`.
- `.github/workflows/pty-matrix.yml` — Windows cells.

## Acceptance

- Linux/Darwin matrix from `pty-matrix.yml` works identically on
  Windows.

## Notes

- See `plans/x-windows.md` (created in step 01.01) for the design
  document.
- This is a substantial multi-PR effort — split into ConPtySystem
  bring-up, then InProcessTransport integration, then ConPtyChild
  lifecycle, then resize / signals, then the test matrix.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
