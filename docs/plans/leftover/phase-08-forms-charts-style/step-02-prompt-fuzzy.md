# Step 08.02 — sugar-prompt fuzzy autocomplete

**Source:** `leftover_updates_later.md` sugar-prompt §3.2 P2
**Branch:** `ai/prompt-fuzzy`

## Deliverable

`withFuzzySuggestions(array $candidates)` — input matches candidates
by fuzzy substring (Smith-Waterman-style scoring). Suggestion list
ranks by score; user picks via arrow keys.

## Files

**Create:**
- `sugar-prompt/src/Fuzzy/FuzzyMatcher.php`.

**Modify:**
- `sugar-prompt/src/Select.php` and `sugar-prompt/src/TextInput.php` —
  `withFuzzySuggestions()`.

## Tests

- `sugar-prompt/tests/Fuzzy/FuzzyMatcherTest.php`.

## Acceptance

- `cd sugar-prompt && vendor/bin/phpunit --filter Fuzzy` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
