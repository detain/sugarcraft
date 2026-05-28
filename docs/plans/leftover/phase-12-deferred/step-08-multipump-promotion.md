# Step 12.08 — MultiPump promotion to first-class API (DEFERRED)

**Source:** `leftover_updates.md` deferred section
**Branch:** `ai/multipump-promotion`
**Deferred per user instruction.**

## Deliverable

`MultiPump` shipped as a P6 stretch item. If `candy-zone` (tmux-style
panes) starts using it, profile against ~16 concurrent PTYs and
promote to first-class with documented scaling claims.

## Files

**Modify:** `candy-pty/src/Posix/MultiPump.php` — tighten API; add
backpressure + per-pump error handling; expanded test coverage.

## Acceptance

- Documented benchmark showing linear scaling to N=16 PTYs.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
