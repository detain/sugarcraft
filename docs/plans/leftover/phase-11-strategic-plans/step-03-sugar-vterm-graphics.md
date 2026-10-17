# Step 11.03 — Write plans/sugar-vterm-graphics.md

**Source:** `leftover_updates_later.md` §6 + sugar-vterm research P3
**Branch:** `ai/plan-sugar-vterm-graphics`
**This step writes a plan file only. No code changes to sugar-vterm itself.**

## Deliverable

Create `plans/sugar-vterm-graphics.md` — plan for sugar-vterm P3-tier graphics
support (Kitty image protocol + Sixel decode). Each is months of work
and explicitly deferred post-1.0.

The plan file should cover:

- **Scope** — receive-side decode for Kitty + Sixel; render to
  sugar-vterm Buffer or pass to sugar-image for display.
- **Architecture** — how sugar-vterm's Parser is extended (OSC 1337 +
  APC for Kitty; DCS for Sixel).
- **Dependencies** — sugar-image for the render path.
- **Phases**.
- **Risks** — Sixel parsing is non-trivial; Kitty image-data chunks
  span many escape sequences.
- **Decision criteria** — when (or whether) to start.

## Files

**Create:** `plans/sugar-vterm-graphics.md` (~300-600 lines).

## Acceptance

- `ls plans/sugar-vterm-graphics.md` returns the file.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
