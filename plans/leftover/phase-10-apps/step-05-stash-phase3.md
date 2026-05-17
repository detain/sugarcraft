# Step 10.05 — sugar-stash Phase 3 (undo/redo / line-level staging / delete / merge / rebase)

**Source:** `leftover_updates_later.md` sugar-stash Phase 3
**Branch:** `ai/stash-phase-3`

## Deliverable

- Undo / redo.
- Line-level staging.
- Branch delete.
- Merge (`M`).
- Rebase (`r`).

## Files

**Modify:** main UI; add command-history undo.

## Tests

- One per feature.

## Acceptance

- `cd sugar-stash && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
