# Step 11.03 — Write plans/candy-vt-graphics.md

**Source:** `leftover_updates_later.md` §6 + candy-vt research P3
**Branch:** `ai/plan-candy-vt-graphics`
**This step writes a plan file only. No code changes to candy-vt itself.**

## Deliverable

Create `plans/candy-vt-graphics.md` — plan for candy-vt P3-tier graphics
support (Kitty image protocol + Sixel decode). Each is months of work
and explicitly deferred post-1.0.

The plan file should cover:

- **Scope** — receive-side decode for Kitty + Sixel; render to
  candy-vt Buffer or pass to candy-mosaic for display.
- **Architecture** — how candy-vt's Parser is extended (OSC 1337 +
  APC for Kitty; DCS for Sixel).
- **Dependencies** — candy-mosaic for the render path.
- **Phases**.
- **Risks** — Sixel parsing is non-trivial; Kitty image-data chunks
  span many escape sequences.
- **Decision criteria** — when (or whether) to start.

## Files

**Create:** `plans/candy-vt-graphics.md` (~300-600 lines).

## Acceptance

- `ls plans/candy-vt-graphics.md` returns the file.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
