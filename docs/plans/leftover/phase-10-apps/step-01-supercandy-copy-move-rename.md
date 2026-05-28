# Step 10.01 — super-candy copy/move/rename ops

**Source:** `leftover_updates_later.md` super-candy HIGH
**Branch:** `ai/supercandy-cmr`

## Deliverable

File-manager core ops: copy / move / rename. Today Manager likely lacks them.

## Files

**Modify:** `super-candy/src/Manager.php` — `copy(string $src, string $dst)`,
`move(string $src, string $dst)`, `rename(string $src, string $newName)`.

## Tests

- `super-candy/tests/Manager{Copy,Move,Rename}Test.php`.

## Acceptance

- `cd super-candy && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
