# Step 08.02 — candy-forms fuzzy autocomplete

**Source:** `leftover_updates_later.md` candy-forms §3.2 P2
**Branch:** `ai/prompt-fuzzy`

## Deliverable

`withFuzzySuggestions(array $candidates)` — input matches candidates
by fuzzy substring (Smith-Waterman-style scoring). Suggestion list
ranks by score; user picks via arrow keys.

## Files

**Create:**

- `candy-forms/src/Fuzzy/FuzzyMatcher.php`.

**Modify:**

- `candy-forms/src/Select.php` and `candy-forms/src/TextInput.php` —
  `withFuzzySuggestions()`.

## Tests

- `candy-forms/tests/Fuzzy/FuzzyMatcherTest.php`.

## Acceptance

- `cd candy-forms && vendor/bin/phpunit --filter Fuzzy` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
