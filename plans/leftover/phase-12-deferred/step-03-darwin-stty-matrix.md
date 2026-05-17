# Step 12.03 — Darwin stty matrix cell in pty-matrix.yml (DEFERRED)

**Source:** `leftover_updates.md` P5-LO-04
**Branch:** `ai/darwin-stty-matrix`
**Deferred per user instruction.**

## Deliverable

Mirror the Ubuntu `SUGARCRAFT_TERMIOS=stty` matrix cell to Darwin so
the BSD-style stty fallback path is actually exercised on its native
quirky variant.

## Files

**Modify:** `.github/workflows/pty-matrix.yml` — add macOS-15 cells
for both PHP 8.3 and 8.4 with `SUGARCRAFT_TERMIOS=stty`.

## Acceptance

- macOS stty fallback cells pass.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
