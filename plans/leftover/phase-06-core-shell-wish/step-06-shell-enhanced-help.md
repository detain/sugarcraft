# Step 06.06 — candy-shell enhanced help (examples / aliases / typo suggestions)

**Source:** `leftover_updates_later.md` candy-shell §P4–P6
**Branch:** `ai/shell-enhanced-help`

## Deliverable

Three help-system improvements:

- Examples per command (`#[Example(...)]` attribute).
- Aliases per command (`#[Alias('co')]`).
- Levenshtein typo suggestions when a user types an unknown command.

## Files

**Create:**
- `candy-shell/src/Attribute/Example.php`
- `candy-shell/src/Attribute/Alias.php`
- `candy-shell/src/Help/HelpFormatter.php` — renders the enhanced help.
- `candy-shell/src/Help/TypoSuggester.php` — Levenshtein over the
  command registry; suggests if distance ≤ 2.

**Modify:**
- `candy-shell/src/Application.php` — wire all three into the run
  loop. Unknown command → suggester runs.

**Tests:**
- `candy-shell/tests/Help/TypoSuggesterTest.php`.
- `candy-shell/tests/Help/HelpFormatterTest.php` — snapshot.

## Acceptance

- `cd candy-shell && vendor/bin/phpunit --filter Help` green.
- Manual smoke: `php examples/cli.php sttatus` (typo) suggests `status`.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
