# Step 10.28 — candy-ssh-launcher import from ~/.ssh/config

**Source:** `leftover_updates_later.md` candy-ssh-launcher P2.1
**Branch:** `ai/wishlist-import-sshconfig`

## Deliverable

Read `~/.ssh/config`; parse Host blocks; convert into `Endpoint`
objects. Wired as a CLI subcommand `candy-ssh-launcher import-ssh`.

## Files

**Create:** `candy-ssh-launcher/src/Import/SshConfigParser.php`,
`Cli/ImportSshCommand.php`.

## Tests

- `candy-ssh-launcher/tests/Import/SshConfigParserTest.php` — fixture
  config; assert correct Endpoint set.

## Acceptance

- `cd candy-ssh-launcher && vendor/bin/phpunit --filter Import` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
