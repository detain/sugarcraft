# Step 10.24 — sugar-glow full syntax highlighting + streaming render

**Source:** `leftover_updates_later.md` sugar-glow §7.1-2
**Branch:** `ai/glow-syntax-streaming`

## Deliverable

- Pygments / Scrutiny-style full syntax highlighting (not just headers/
  code-blocks). Use chroma JSON theme format.
- Streaming render for the pager (don't load entire file into memory).

## Files

**Create:** `sugar-glow/src/Highlighter.php` — pluggable highlighter
interface; concrete `ChromaJsonHighlighter`.

**Modify:** `sugar-glow/src/Pager.php` — stream input.

## Tests

- One per feature.

## Acceptance

- `cd sugar-glow && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
