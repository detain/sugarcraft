# Step 10.28 — sugar-wishlist import from ~/.ssh/config

**Source:** `leftover_updates_later.md` sugar-wishlist P2.1
**Branch:** `ai/wishlist-import-sshconfig`

## Deliverable

Read `~/.ssh/config`; parse Host blocks; convert into `Endpoint`
objects. Wired as a CLI subcommand `wishlist import-ssh`.

## Files

**Create:** `sugar-wishlist/src/Import/SshConfigParser.php`,
`Cli/ImportSshCommand.php`.

## Tests

- `sugar-wishlist/tests/Import/SshConfigParserTest.php` — fixture
  config; assert correct Endpoint set.

## Acceptance

- `cd sugar-wishlist && vendor/bin/phpunit --filter Import` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
