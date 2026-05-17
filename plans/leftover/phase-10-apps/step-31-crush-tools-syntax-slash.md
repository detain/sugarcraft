# Step 10.31 — sugar-crush built-in tools + syntax highlighting + slash commands

**Source:** `leftover_updates_later.md` sugar-crush H4 + M5 + M6
**Branch:** `ai/crush-tools-syntax-slash`

## Deliverable

- Built-in file / bash tools (today `ToolCall.php` is a value-object only).
- Syntax highlighting in markdown code blocks (consume sugar-glow from
  step 10.24).
- Slash commands `/help`, `/clear`, `/compact`, `/model`.

## Files

**Create:** `sugar-crush/src/Tool/{FileTool,BashTool}.php`,
`SlashCommand/{Help,Clear,Compact,Model}Command.php`.

**Modify:** `Chat.php` — slash-command dispatch; tools registry.

## Tests

- One per tool / slash command.

## Acceptance

- `cd sugar-crush && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
