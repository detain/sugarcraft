# Step 11.02 — Write plans/candy-serve-tui.md

**Source:** `leftover_updates_later.md` §6 + candy-serve research §3.1
**Branch:** `ai/plan-candy-serve-tui`
**This step writes a plan file only. No code changes to candy-serve itself.**

## Deliverable

Create `plans/candy-serve-tui.md` — milestone plan for the Interactive
SSH TUI (soft-serve's marquee feature). This is a substantial TUI app;
it warrants its own plan before any implementation.

The plan file should cover:

- **Goal** — interactive SSH-served repo browser equivalent to
  charmbracelet/soft-serve's TUI.
- **Architecture** — how it sits on top of candy-wish (SSH transport)
  + candy-vt (terminal state) + candy-shell (CLI subcommand) +
  sugar-stash (git ops) + sugar-glow (markdown render) + sugar-stickers
  (viewport).
- **User stories** — what a user does with it.
- **Layout sketch** — text mockup of the main screens (repo list,
  repo detail, file viewer, log viewer).
- **Phases** — phase 1 list view, phase 2 detail view, phase 3 file
  navigation, phase 4 commit log, phase 5 PR / issue surface (if any).
- **Dependencies** — every lib this needs (cross-link to plans/leftover/
  steps that must complete first).
- **Acceptance** — what makes phase N done.
- **Out of scope** — issue tracker, PR tracker (those are GitHub-like
  features that may belong in a separate lib).

## Files

**Create:** `plans/candy-serve-tui.md` (~400-800 lines).

## Acceptance

- `ls plans/candy-serve-tui.md` returns the file.

## Notes

- This is research / design work, not implementation.
- After landing, user can authorize phase-1 implementation as a separate
  effort.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
