# Step 06.05 — candy-shell auto-discovery + FlagSpec + ValueEnum

**Source:** `leftover_updates_later.md` candy-shell §5 P1–P3
**Branch:** `ai/shell-discovery-flagspec-valueenum`

## Deliverable

Three declarative-CLI features:

- **P1** — Auto-discovery: classes with a `#[Command]` attribute
  auto-register into the `Application`.
- **P2** — `FlagSpec` attribute trait for declarative options
  (`#[Flag(name: 'verbose', short: 'v')]`).
- **P3** — `ValueEnum` for constrained-option enums (e.g. `--format
  json|yaml|toml`).

## Files

**Create:**
- `candy-shell/src/Attribute/Command.php`
- `candy-shell/src/Attribute/Flag.php`
- `candy-shell/src/Attribute/ValueEnum.php`
- `candy-shell/src/Discovery/CommandScanner.php` — walks namespaces
  via reflection, finds `#[Command]` classes, registers them.

**Modify:**
- `candy-shell/src/Application.php` — opt-in `scan(string $namespace)`
  method that runs the CommandScanner.

**Tests:**
- `candy-shell/tests/Attribute/CommandScannerTest.php` — fixture
  namespace with two commands; assert both register.
- `candy-shell/tests/Attribute/FlagAttributeTest.php`.
- `candy-shell/tests/Attribute/ValueEnumTest.php` — assert invalid
  value yields a clear error.

## Acceptance

- `cd candy-shell && vendor/bin/phpunit --filter "Command|Flag|ValueEnum"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
