# Step 06.07 — candy-shell completions + version-from-composer + env-var fallbacks

**Source:** `leftover_updates_later.md` candy-shell §7–§9
**Branch:** `ai/shell-completions-version-env`

## Deliverable

Three operator-facing features:

- Bash / Zsh / Fish shell completions generation (auto-emit from
  registered commands + flags).
- `Application::versionFromComposer(): string` — reads root `composer.json`.
- Env-var fallbacks for any flag (`MYAPP_VERBOSE=1` ≡ `--verbose`).

## Files

**Create:**
- `candy-shell/src/Completion/BashCompletion.php`.
- `candy-shell/src/Completion/ZshCompletion.php`.
- `candy-shell/src/Completion/FishCompletion.php`.
- `candy-shell/src/CompletionCommand.php` — built-in `completion`
  subcommand that emits the right shell.

**Modify:**
- `candy-shell/src/Application.php` — `versionFromComposer()` helper;
  env-var fallback wiring in flag resolution.

**Tests:**
- `candy-shell/tests/Completion/<Each>CompletionTest.php`.
- `candy-shell/tests/EnvVarFallbackTest.php`.

## Acceptance

- `cd candy-shell && vendor/bin/phpunit --filter "Completion|EnvVar"` green.
- `php examples/cli.php completion bash > /tmp/comp; bash /tmp/comp`
  loads without errors.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
