# Step 08.02 — sugar-forms fuzzy autocomplete

**Source:** `leftover_updates_later.md` sugar-forms §3.2 P2
**Branch:** `ai/prompt-fuzzy`

## Deliverable

`withFuzzySuggestions(array $candidates)` — input matches candidates
by fuzzy substring (Smith-Waterman-style scoring). Suggestion list
ranks by score; user picks via arrow keys.

## Files

**Create:**

- `sugar-forms/src/Fuzzy/FuzzyMatcher.php`.

**Modify:**

- `sugar-forms/src/Select.php` and `sugar-forms/src/TextInput.php` —
  `withFuzzySuggestions()`.

## Tests

- `sugar-forms/tests/Fuzzy/FuzzyMatcherTest.php`.

## Acceptance

- `cd sugar-forms && vendor/bin/phpunit --filter Fuzzy` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
