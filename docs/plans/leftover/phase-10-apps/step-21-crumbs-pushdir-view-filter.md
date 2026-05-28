# Step 10.21 — sugar-crumbs missing pushDirectory / view / filter

**Source:** `leftover_updates_later.md` sugar-crumbs P1
**Branch:** `ai/crumbs-pushdir-view-filter`

## Deliverable

Implement `pushDirectory`, `view`, `filter` — `examples/navigation.php`
references them but they don't exist.

## Files

**Modify:** `sugar-crumbs/src/NavStack.php` — add the three methods.

## Tests

- One per method.

## Acceptance

- `cd sugar-crumbs && vendor/bin/phpunit` green.
- `php examples/navigation.php` runs.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
