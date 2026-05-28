# Step 10.03 — sugar-stash Phase 1 (help / checkout / commit / stage-all)

**Source:** `leftover_updates_later.md` sugar-stash Phase 1
**Branch:** `ai/stash-phase-1`

## Deliverable

- Context-sensitive help (`?` key).
- Branch checkout (Space on a branch).
- Commit (`c`).
- Stage-all (`a`).

## Files

**Modify:** `sugar-stash/src/StashUi.php` (or equivalents) — key
bindings + git invocations via `\SugarCraft\Pty\Posix\PosixProcess`.

## Tests

- One per binding.

## Acceptance

- `cd sugar-stash && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
