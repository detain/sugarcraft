# Step 10.32 — sugar-crush MCP client + CLAUDE.md autoload

**Source:** `leftover_updates_later.md` sugar-crush M7 + M8
**Branch:** `ai/crush-mcp-claudemd`

## Deliverable

- MCP (Model Context Protocol) client — connects to MCP servers,
  surfaces their tools.
- Load `CLAUDE.md` from cwd on startup if present.

## Files

**Create:** `sugar-crush/src/Mcp/Client.php`, plus DTOs for the MCP
JSON-RPC surface.

**Modify:** `sugar-crush/src/Chat.php` — `CLAUDE.md` boot hook.

## Tests

- `sugar-crush/tests/Mcp/ClientTest.php`.
- `sugar-crush/tests/ClaudeMdBootTest.php`.

## Acceptance

- `cd sugar-crush && vendor/bin/phpunit --filter "Mcp|ClaudeMd"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
