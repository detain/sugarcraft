# Step 06.06 — candy-scriptui enhanced help (examples / aliases / typo suggestions)

**Source:** `leftover_updates_later.md` candy-scriptui §P4–P6
**Branch:** `ai/shell-enhanced-help`

## Deliverable

Three help-system improvements:

- Examples per command (`#[Example(...)]` attribute).
- Aliases per command (`#[Alias('co')]`).
- Levenshtein typo suggestions when a user types an unknown command.

## Files

**Create:**

- `candy-scriptui/src/Attribute/Example.php`
- `candy-scriptui/src/Attribute/Alias.php`
- `candy-scriptui/src/Help/HelpFormatter.php` — renders the enhanced help.
- `candy-scriptui/src/Help/TypoSuggester.php` — Levenshtein over the
  command registry; suggests if distance ≤ 2.

**Modify:**

- `candy-scriptui/src/Application.php` — wire all three into the run
  loop. Unknown command → suggester runs.

**Tests:**

- `candy-scriptui/tests/Help/TypoSuggesterTest.php`.
- `candy-scriptui/tests/Help/HelpFormatterTest.php` — snapshot.

## Acceptance

- `cd candy-scriptui && vendor/bin/phpunit --filter Help` green.
- Manual smoke: `php examples/cli.php sttatus` (typo) suggests `status`.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
