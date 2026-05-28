# Step 10.15 — sugar-readline autosuggest + undo/redo + syntax highlighting

**Source:** `leftover_updates_later.md` sugar-readline MEDIUM + LOW
**Branch:** `ai/readline-autosuggest-undo-highlight`

## Deliverable

- Autosuggest from history (fish-style — gray completion of likely next chars).
- Undo / redo on the input buffer.
- Syntax highlighting (consume sugar-glow's highlighter — step 10.24).

## Files

**Create:** `sugar-readline/src/AutoSuggest.php`, `Undo.php`,
`Highlight.php`.

## Tests

- One per feature.

## Acceptance

- `cd sugar-readline && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
