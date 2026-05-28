# Step 01.01 — plans/x-windows.md stub

**Source:** `leftover_updates.md` P5-LO-01
**Branch:** `ai/x-windows-stub`
**Bundle hint:** standalone, ~50 LOC

## Deliverable

Create `/home/sites/sugarcraft/plans/x-windows.md` — the missing tracking
doc that `PTY_PLAN.md:53` and `sugarcraft-is-a-mono-logical-twilight.md:822`
both reference. It is a stub plan for the eventual Windows ConPTY backend.

## Files

**Create:**
- `plans/x-windows.md`

Contents should cover (one short paragraph or bullet each):

- **Goal**: ship `SugarCraft\Pty\Windows\ConPtySystem` and have the
  full P0–P5 acceptance criteria hold on Windows.
- **FFI surface**: `kernel32.dll` exports —
  `CreatePseudoConsole`, `ResizePseudoConsole`, `ClosePseudoConsole`,
  `CreateProcessW` with `STARTUPINFOEXW` extended attributes,
  `InitializeProcThreadAttributeList`, `UpdateProcThreadAttribute`.
- **Threading model TBD**: ConPTY's input/output pipes are blocking;
  may need async ReadFile/WriteFile or a sidecar process.
- **Open questions**: how does `pcntl` absence interact with the
  existing `SignalForwarder`? Where does `SUGARCRAFT_PTY_BACKEND`
  switch in?
- **Acceptance criteria**: parity with the Linux/Darwin matrix from
  `pty-matrix.yml`.
- **Dependencies**: phase 12 of the leftover rollout (step
  `phase-12-deferred/step-04-windows-conpty.md`).

Cross-link bidirectionally: link to phase-12 step 04, and to the two
parent plans that reference this file.

## Acceptance

- `ls plans/x-windows.md` returns the file.
- `MATCHUPS.md` Windows row (if it exists) points at the new plan; if
  not, leave a TODO comment in `MATCHUPS.md` saying "TODO when Windows
  row created, point at plans/x-windows.md".
- File is ≤200 lines; it's a stub, not a full plan.

## Notes

- Do not start any Windows implementation work in this step. The
  goal is purely to make the plan reference valid.
- This is the only docs-only step in phase 01.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
