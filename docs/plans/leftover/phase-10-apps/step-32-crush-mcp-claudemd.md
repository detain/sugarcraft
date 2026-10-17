# Step 10.32 — candy-code MCP client + CLAUDE.md autoload

**Source:** `leftover_updates_later.md` candy-code M7 + M8
**Branch:** `ai/crush-mcp-claudemd`

## Deliverable

- MCP (Model Context Protocol) client — connects to MCP servers,
  surfaces their tools.
- Load `CLAUDE.md` from cwd on startup if present.

## Files

**Create:** `candy-code/src/Mcp/Client.php`, plus DTOs for the MCP
JSON-RPC surface.

**Modify:** `candy-code/src/Chat.php` — `CLAUDE.md` boot hook.

## Tests

- `candy-code/tests/Mcp/ClientTest.php`.
- `candy-code/tests/ClaudeMdBootTest.php`.

## Acceptance

- `cd candy-code && vendor/bin/phpunit --filter "Mcp|ClaudeMd"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
