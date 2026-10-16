# Step 10.25 — candy-mdview Glamour theme JSON + file watching + CJK/emoji width

**Source:** `leftover_updates_later.md` candy-mdview §7.3-5
**Branch:** `ai/glow-theme-watch-width`

## Deliverable

- Glamour-style theme JSON (block_prefix / suffix, indent_token, margin,
  chroma block).
- File watching in pager (auto-reload on change).
- CJK / emoji width handling via `mb_strwidth` / UnicodeString.

## Files

**Create:** `candy-mdview/src/GlamourTheme.php`, `FileWatcher.php`,
`WidthHelper.php`.

## Tests

- One per feature.

## Acceptance

- `cd candy-mdview && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
