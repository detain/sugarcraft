# Step 10.31 — candy-code built-in tools + syntax highlighting + slash commands

**Source:** `leftover_updates_later.md` candy-code H4 + M5 + M6
**Branch:** `ai/crush-tools-syntax-slash`

## Deliverable

- Built-in file / bash tools (today `ToolCall.php` is a value-object only).
- Syntax highlighting in markdown code blocks (consume candy-mdview from
  step 10.24).
- Slash commands `/help`, `/clear`, `/compact`, `/model`.

## Files

**Create:** `candy-code/src/Tool/{FileTool,BashTool}.php`,
`SlashCommand/{Help,Clear,Compact,Model}Command.php`.

**Modify:** `Chat.php` — slash-command dispatch; tools registry.

## Tests

- One per tool / slash command.

## Acceptance

- `cd candy-code && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
