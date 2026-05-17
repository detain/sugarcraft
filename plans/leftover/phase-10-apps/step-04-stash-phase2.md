# Step 10.04 — sugar-stash Phase 2 (diff viewer / discard / amend / hunk staging / create branch)

**Source:** `leftover_updates_later.md` sugar-stash Phase 2
**Branch:** `ai/stash-phase-2`

## Deliverable

- Diff viewer pane.
- Discard (`d`).
- Amend (`A`).
- Hunk staging.
- Create branch (`n`).

## Files

**Create:** `sugar-stash/src/DiffViewer.php`, hunk-stager helper.

**Modify:** main UI keymap.

## Tests

- One per feature.

## Acceptance

- `cd sugar-stash && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
