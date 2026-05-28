# Step 10.35 — sugar-skate import/export + levenshtein + TTL + STDIN + atomic tx

**Source:** `leftover_updates_later.md` sugar-skate P1-P6
**Branch:** `ai/skate-import-typo-ttl-stdin-tx`

## Deliverable

- Import / export JSON / YAML.
- Levenshtein typo suggestions on db-not-found error.
- TTL / expiration `Store::setWithTtl()`.
- STDIN value input.
- Atomic `Database::transaction(callable)` wrapping SQLite tx.
- (Skip key-case normalization documentation — low value.)

## Files

**Modify:** `sugar-skate/src/Store.php`, `Database.php`.

**Create:** `sugar-skate/src/Import/JsonImporter.php`,
`YamlImporter.php`, `Cli/ImportCommand.php`, `Cli/ExportCommand.php`.

## Tests

- One per feature.

## Acceptance

- `cd sugar-skate && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
