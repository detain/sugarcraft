# Step 09.10 — candy-lister filter interface + FuzzyMatch + filter states

**Source:** `leftover_updates_later.md` candy-lister Priorities 1-3
**Branch:** `ai/lister-filter-fuzzy`

## Deliverable

- Filter interface (exposed `withFilterFn()`).
- `FuzzyMatch` class with Smith-Waterman scoring.
- Filter states / transitions (unfiltered / filtering / filtered).

## Files

**Modify:**
- `candy-lister/src/Lister.php` — `withFilterFn(callable)`.

**Create:**
- `candy-lister/src/FuzzyMatch.php` — share impl with sugar-prompt
  fuzzy (step 08.02) where possible; consider lifting to candy-core
  if both consume it.
- `candy-lister/src/FilterState.php` (enum).

## Tests

- One per feature.

## Acceptance

- `cd candy-lister && vendor/bin/phpunit --filter "Filter|Fuzzy"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
