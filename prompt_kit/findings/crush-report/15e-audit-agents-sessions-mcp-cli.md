# 15e — Defect audit: agents, sessions, MCP, CLI (sugar-crush)

Scope: `src/Agents/`, `src/Workflows/`, `src/Session*`, `src/Sessions/`, `src/MCP/` (+ `sugar-mcp` transport it delegates to), `src/ClaudeCodeMcpClient.php`, `src/Cli/`, `bin/`.
Baseline excluded: everything in `99-synthesis.md` Part II (not re-reported).
Repro scripts live in `/home/sites/crush-research-repos/_audit-scratch/15e/`, run with a scratch `HOME` (never the real `~/.sugar-crush`).

**No finding from this audit remains open.** Fixed findings have been removed from this appendix.

**Workflows, not a defect:** the baseline item "only the first task of a stage runs" (`WorkflowEngine.php:1069`) is intentional. `executeStage()` and `executePipelineStage()` take `$tasks[0]` on purpose, no public constructor can build a multi-task `stage` (`WorkflowBuilder::stage()` takes exactly one `TaskBuilder`; a non-parallel YAML stage yields exactly one task), and `docs/WORKFLOWS.md:195-203` documents the limit. Only a hand-built `Workflow` can hit it.

---

## Coverage

**Read end to end:** `src/Session/EnhancedSessionStore.php`, `src/Session/SessionStore.php`; `src/Sessions/BackgroundSupervisor.php`, `BackgroundSessionRunner.php`; `src/Agents/SuspendedDelegations.php`, `EngineExecutor.php`, `AgentPresetRegistry.php`; `src/MCP/McpClient.php`, `StdioMcpServer.php`, `sugar-mcp/src/StdioMcpServer.php`, `sugar-mcp/src/McpMessage.php`, `McpRouter.php`, `McpAuthStore.php`, `OAuthPkce.php`, `OAuthLoopbackFlow.php`, `OAuthClientRegistration.php`; `src/Cli/NonInteractive.php`, `HeadlessPermissionPrompt.php`, `ArgvParser.php`, `bin/sugarcrush`; `src/Workflows/WorkflowEngine.php` (stage loop, stage executors, run/pause/resume/status, interpolation and interrupt handlers), `WorkflowRegistry.php` (YAML parsing), `WorkflowBuilder.php`.

**Read in part:** `AgentWorkerPool.php`, `AgentManager.php`, `ProcessExecutor.php` (`spawnWorker()` only — the no-provider fallback, whose 5 s ready loop busy-spins on a non-blocking `fgets`; not filed), `GitMcpServer.php`, `GitCommandHandlers.php`, the relevant `Bootstrap.php` and `Subcommands.php` ranges, `McpToolBridge.php`, `ClaudeCodeMcpClient.php`, Chat's workflow and session handlers, `RuntimeNoticeSink.php`, and `docs/MCP.md`, `docs/WORKFLOWS.md`, `docs/TROUBLESHOOTING.md`, `docs/PERMISSIONS.md`, `docs/ARCHITECTURE.md`.

**Not audited, with reason:**
- `src/LSP/*`: dormant per the baseline; no live caller.
- `src/Share/*`: a known stub (Part II #35).
- `src/Agents/WorktreeManager.php`, `PathJail.php`, `PathJailConfig.php`, `TeamManager.php`, `Mailbox.php`, `Team*.php`: nothing outside `src/Agents/` constructs them, so they are dormant (baseline §2.6).
- `ForeignAgentPresetRegistry.php`: function list only. `src/Events/*`: not reached. `McpForeignTranslate.php`: used only by `mcp import`, which prints and writes nothing.

**Leads dropped:**
- *`/tmp/sc_pool_<pid>_*` predictable name* (`AgentWorkerPool.php:1368`): the suffix is `bin2hex(random_bytes(8))`, the directory is created 0700, and files inside are named by sha256 of the agent id.
- *`callToolByName` first-match across servers*: `McpToolBridge::execute()` calls `McpClient::callTool($serverName, …)` by server, and `callToolByName()` has no caller in `src/`. The sanitiser is injective (`_XX` escapes). *Unconfirmed side note:* the escape doubles `_` into `_5F`, and no 64-character cap is applied to wire names, so a long `server__tool` pair can exceed the limit OpenAI-style APIs enforce. The default SGLang path does not enforce it, so this was not tested.
- *Daemon inherits the TUI's fds*: **mechanism confirmed, not filed.** PHP `proc_open` passes every non-CLOEXEC `fopen`/socket fd to the child, so the `/bg` daemon holds copies of TUI sockets and pipes, including the `RuntimeNoticeSink` socketpair and MCP stdio pipes. No user-visible harm was shown: the TUI's `stopMcpServers()` still terminates servers explicitly on exit.
- *ESC injection through `HeadlessPermissionPrompt::question()`*: arguments go through `json_encode`, which escapes C0 controls; tool names are registry names, and a ScriptHook's ask text is operator-authored.
- *`--resume ''` / prefix match*: `--resume ''` and `--resume=` open the picker, and prefix matching requires a unique match (an ambiguous prefix reports "no stored session", wording only).
- *Daemon permission mode*: by design. `BackgroundSessionRunner::backend()` passes `consolePermissionPrompt=true`; with stdin `/dev/null`, every Ask is refused with the refusal text written to the daemon's log. The permission mode is whatever the config resolves, which defaults to bypass (Part II #1).
