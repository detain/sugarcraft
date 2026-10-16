# Step 10.06 — candy-git Phase 4 (interactive rebase / stash mgmt / cherry-pick / worktrees / syntax)

**Source:** `leftover_updates_later.md` candy-git Phase 4
**Branch:** `ai/stash-phase-4`

## Deliverable

- Interactive rebase TUI.
- Stash management (list / apply / drop).
- Cherry-pick.
- Worktrees (list / add / remove).
- Syntax highlighting in diff view (consume candy-mdview, step 10.24).

## Files

**Create:** `candy-git/src/InteractiveRebase.php`,
`StashManager.php`, `CherryPick.php`, `Worktrees.php`.

## Tests

- One per feature.

## Acceptance

- `cd candy-git && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
