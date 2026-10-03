# 15e — Defect audit: agents, sessions, MCP, CLI (sugar-crush)

Scope: `src/Agents/`, `src/Workflows/`, `src/Session*`, `src/Sessions/`, `src/MCP/` (+ `sugar-mcp` transport it delegates to), `src/ClaudeCodeMcpClient.php`, `src/Cli/`, `bin/`.
Baseline excluded: everything in `99-synthesis.md` Part II (not re-reported).
Status: **FINAL.** Sections A–D are from the first pass. Sections E–H were added when the audit resumed. What was read, what was only skimmed, and how each open lead was settled is listed under **Coverage** at the end.

Repro scripts live in `/home/sites/crush-research-repos/_audit-scratch/15e/` (each named per finding below). All repros were run against the working tree at 2026-10-01 (PHP 8.3, sugar-mcp symlinked from the monorepo), with a scratch `HOME` (never the real `~/.sugar-crush`). Every server, daemon and child process the repros started was killed afterwards.

---

## A. MCP transports

Both findings here (MCP-3, MCP-4) were fixed in wave 5; see **Fixed since audit**.

---

## B. Sessions and persistence

Every finding here has been fixed, the last (SES-3) in wave 10; see **Fixed since audit**.

---

## C. Background sessions and daemons

Its only finding, TMP-1, was fixed in wave 7; see **Fixed since audit**.

---

## D. CLI / headless

Its only finding, CLI-1, was fixed in wave 3; see **Fixed since audit**.

---

## E. Forked sub-agents (workflows and Task fan-out)

Every finding here has been fixed: AG-1 to AG-4, the last (AG-2, AG-3) in wave 7, and AG-5 in wave 9; see **Fixed since audit**.

---

## F. Workflows

The baseline item "only the first task of a stage runs" (`WorkflowEngine.php:1069`) was run down and is **not a separate defect**. `executeStage()` and `executePipelineStage()` (`:1185`) take `$tasks[0]` on purpose (comment at `:1067`). No public constructor can build a multi-task `stage`: `WorkflowBuilder::stage()` takes exactly one `TaskBuilder` (`WorkflowBuilder.php:53-60`), and a non-parallel YAML stage yields exactly one task (`WorkflowRegistry.php:1382-1392`). `docs/WORKFLOWS.md:195-203` documents the limit. Only a hand-built `Workflow` can hit it. Every real workflow defect (WF-1 to WF-4) has been fixed, the last (WF-4) in wave 9; see **Fixed since audit**.

---

## G. MCP configuration trust and OAuth

Every finding here is fixed: MCP-6, MCP-7 and MCP-8 in wave 7, MCP-5 and MCP-10 in wave 8B; see **Fixed since audit**.

---

## H. CLI and docs

Every finding here has been fixed, the last (CLI-2 and CLI-3) in wave 10; see **Fixed since audit**.

---

## Summary table (sorted by severity)

No finding in this appendix remains open: the last, SES-3, CLI-2 and CLI-3, were closed in wave 10. See **Fixed since audit**.

---

## Coverage

**Read end to end:**
- `src/Session/EnhancedSessionStore.php`, `src/Session/SessionStore.php`
- `src/Sessions/BackgroundSupervisor.php`, `src/Sessions/BackgroundSessionRunner.php`
- `src/Agents/SuspendedDelegations.php`, `src/Agents/EngineExecutor.php`, `src/Agents/AgentPresetRegistry.php` (parse/list/load)
- `src/MCP/McpClient.php`, `src/MCP/StdioMcpServer.php`, `sugar-mcp/src/StdioMcpServer.php`, `sugar-mcp/src/McpMessage.php`, `src/MCP/McpRouter.php`, `src/MCP/McpAuthStore.php`, `src/MCP/OAuthPkce.php`, `src/MCP/OAuthLoopbackFlow.php`, `src/MCP/OAuthClientRegistration.php` (storage, refresh and re-registration paths)
- `src/Cli/NonInteractive.php`, `src/Cli/HeadlessPermissionPrompt.php`, `src/Cli/ArgvParser.php`, `bin/sugarcrush`
- `src/Workflows/WorkflowEngine.php`: stage loop `:878-1040`, all four stage executors `:1051-1665`, run/pause/resume/status `:400-760`, interpolation, ids and interrupt handlers `:1900-2258`
- `src/Workflows/WorkflowRegistry.php`: YAML parsing `:1086-1470`
- `src/Workflows/WorkflowBuilder.php`

**Read in part:**
- `src/Agents/AgentWorkerPool.php`: `executeAll`/`executeOne`/`cancel*`/`startAgent`/`runStreaming`/`waitForCompletion`/`idle`, result and progress files, and `makeResultDirPath`/`ensureResultDir` `:378-1020`, `:1197-1430`, `:1700-1707`.
- `src/Agents/AgentManager.php`: `executeAll`/`drain`/`settleAbandoned` and batch grant/prompt resolution `:1581-1860`, plus the `$subAgents` writers.
- `src/Agents/ProcessExecutor.php`: `spawnWorker()` `:576-735` only. It is the no-provider fallback that refuses; the live path is `EngineExecutor`. Its 5 s ready loop busy-spins on a non-blocking `fgets` (a CPU hot loop of at most 5 s). Not filed.
- `src/MCP/GitMcpServer.php`: schemas and `callTool()` dispatch `:290-455`. `src/MCP/GitCommandHandlers.php`: `:620-1100` checked for argv shapes only; every arm shares GIT-1's missing `--` (worktree add/remove also take arbitrary on-disk paths).
- `src/Cli/Bootstrap.php`: `workflowEngine()` `:1453-1540`, `agentManager()` `:1718-1775`, preset loading `:2215-2245`, session open/find `:3150-3216`, MCP trust/decision/digest/client/tools `:5759-6081`, `:6262-6340`, `:6470-6520`, `tools()`/`unfilteredTools()` around `:6900-6930`, `sessionStore()`/retention `:7349-7495`, `ensureDir()` `:8036-8040`.
- `src/Cli/Subcommands.php`: `session list|delete`, `mcp import`, `mcp auth` dispatch.
- `src/Tools/McpToolBridge.php`: `name()`, `sanitize()`, `execute()`.
- `src/ClaudeCodeMcpClient.php`: `:340-520`, `:753-830`.
- `src/Chat.php` workflow handlers `:9454-9830` and the session handlers listed in SES-1/SES-2.
- `src/Diagnostics/RuntimeNoticeSink.php` (code only).
- Docs: `docs/MCP.md` (transports, trust, auth, serving), `docs/WORKFLOWS.md` (whole), `docs/TROUBLESHOOTING.md` MCP section, `docs/PERMISSIONS.md` rules section, `docs/ARCHITECTURE.md` grep for agents/MCP/workflow claims.

**Not audited, with reason:**
- `src/LSP/*`: dormant per the baseline; no live caller.
- `src/Share/*`: a known stub (Part II #35).
- `src/Agents/WorktreeManager.php`, `PathJail.php`, `PathJailConfig.php`, `TeamManager.php`, `Mailbox.php`, `Team*.php`: nothing outside `src/Agents/` constructs them (`grep 'new Mailbox|new TeamManager|new PathJail'`), so they are dormant (baseline §2.6).
- `AgentManager::executeSubAgent()` `:678-940`: no `src/` caller (also noted at `src/Renderer.php:166-167`). AG-4 was found in it later, during the 15a A1 fix, and fixed in `48e9a3f65`.
- `ForeignAgentPresetRegistry.php`: function list only.
- `src/Events/*`: not reached.
- `McpForeignTranslate.php`: used only by `mcp import`, which prints and writes nothing.

**Leads from the checkpoint, settled:**
1. *Only the first task of a stage runs* (`WorkflowEngine.php:1069`): **dropped as a defect.** It is intentional and documented, and no builder or YAML path can produce a multi-task `stage` (see the section F preamble). The real workflow defects are WF-1, WF-2 and WF-3.
2. *`/tmp/sc_pool_<pid>_*` predictable name* (`AgentWorkerPool.php:1368`): **dropped.** The suffix is `bin2hex(random_bytes(8))` (`:1360-1366`), the directory is created 0700 (`:1380-1393`), and files inside are named by sha256 of the agent id.
3. *`callToolByName` first-match across servers*: **dropped.** `McpToolBridge::execute()` `:397-410` calls `McpClient::callTool($serverName, …)` by server, and `callToolByName()` has no caller in `src/` (the bridge docblock `:366-379` records the earlier mis-routing as fixed). The sanitiser is injective (`_XX` escapes), so two servers cannot share a wire name. *Unconfirmed side note:* the escape doubles `_` into `_5F`, and no 64-character cap is applied to wire names. A long `server__tool` pair can exceed the limit OpenAI-style APIs enforce. The default SGLang path does not enforce it, so this was not tested.
4. *Blob intern TOCTOU*: **folded into SES-3.** Blobs are per session, so the race needs the two-writers setup SES-3 already describes.
5. *Daemon inherits the TUI's fds*: **mechanism confirmed, not filed.** `fdleak.php` shows PHP `proc_open` passes every non-CLOEXEC `fopen`/socket fd to the child (SQLite's fd is CLOEXEC and was not inherited). The `/bg` daemon therefore holds copies of TUI sockets and pipes, including the `RuntimeNoticeSink` socketpair and MCP stdio pipes. I could not show user-visible harm: the TUI's `stopMcpServers()` still terminates servers explicitly on exit. The same class was filed in 15a (B3) for the turn fork's frame socket, and fixed there in `53da0a291` (close-on-exec on both ends); the `/bg` daemon's inherited fds are untouched by that fix.
6. *ESC injection through `HeadlessPermissionPrompt::question()`*: **dropped.** Arguments go through `json_encode`, which always escapes C0 controls (ESC becomes `\u001b`). The built-in Ask message is `"Allow {$toolName} to run? …"` (`PermissionGateHook.php:137-139`), and tool names are registry names (MCP names are sanitised to `[A-Za-z0-9_-]`). A ScriptHook's ask text is operator-authored. Residual: `JSON_UNESCAPED_UNICODE` emitted UTF-8 C1 controls (U+009B) raw. Fixed in `e1acd6f0f` (wave 8A, R17): the encoded arguments, the tool name and the ask message go through `Sanitize::visibleControls()` on both the tty question and the no-tty refusal.
7. *`--resume ''` / prefix match* (`Bootstrap::findSession` `:3150-3162`): **dropped.** `--resume ''` and `--resume=` become "no target", which opens the picker (`ArgvParser.php:497-518`). Prefix matching requires a unique match, and an ambiguous prefix reports "no stored session" (wording only). Name resolution's non-determinism is already in SES-2.
8. *Daemon permission mode*: **dropped (by design).** `BackgroundSessionRunner::backend()` `:580-611` passes `consolePermissionPrompt=true`. With stdin `/dev/null`, every Ask is refused with the full refusal text written to the daemon's log (`BackgroundSupervisor.php:254-258` routes stderr to `<ipc>.buffer.log`). The permission mode is whatever the config resolves, which defaults to bypass (Part II #1). The stop gap was BG-1 (fixed since in `0214944fc`; since BG-2's fix a settled session's `.buffer.log` is deleted).

**CLI-1:** confirmed. It was Suspected and is now Verified-by-repro.

**Repro scripts and fixtures** (all in `/home/sites/crush-research-repos/_audit-scratch/15e/`):

| Script(s) | Finding | What it shows |
|---|---|---|
| `rewind.php` | SES-1 | |
| `fork.php` | SES-2 | |
| `blobs.php` | SES-4 | |
| `mcp_everything.php`, `pyserver.py`, `mcp_py.php` | MCP-1 | |
| `mcp_http.php` | MCP-2 | |
| `ccmcp.php` | MCP-3 | |
| `git_handlers.php` + `gitrepo/` | GIT-1, GIT-2 | Recreate `gitrepo/` before re-running GIT-2 |
| `mcp_fork.php`, `fake_mcp_server.php`, `mcp_fork_exit.php`, `fork_http.php`, `ka_server.py` | AG-1 | |
| `preset_list.php` + `presets/` | AG-2 | |
| `wf_pause.php`, `wf_ctx.php` + `wf/home/.sugar-crush/workflows/{three,fan}.yaml` | WF-2, WF-3 | |
| `oauth_discovery.php` | MCP-6 | Network: discovery GET to mcp.notion.com only |
| `oauth_store.php` | MCP-7, MCP-8 | |
| `argv.php` | CLI-2 | |
| `cli_warn.sh` | CLI-1 | |
| `fdleak.php` | lead 5 | |

`ps` after the last run showed no leftover node, python, php, MCP or daemon processes.

---

## Fixed since audit

These findings were fixed on master after the audit. Their sections and table rows were removed; the coverage and repro lists above still name them.

- **MCP-1** stdio MCP sent `[]` for empty maps; official TS/Python SDK servers started with 0 tools — fixed on master in `44ba1a20b` (+ `b80b267f1` for `claude-mcp` tool arguments). Residual: nested empty maps (MCP-9), fixed later in `f84a97364`.
- **MCP-2** Streamable HTTP MCP: no `Accept`, no `Mcp-Session-Id`, no SSE — fixed on master in `72eaf4642`.
- **GIT-1** Git MCP option injection (`gitShow --output=`) and an uncontained per-call `path` — fixed on master in `43fe9cd06`. Residual: checkout and reset cannot take `--end-of-options`.
- **GIT-2** `execGit()` deadlock on more than 64 KiB of stderr; no timeout; env stripped — fixed on master in `4c341ef6a`. Residual: the git timeout could not be configured from `.mcp.json`; fixed since in `0c856c75e` (R18, wave 8B: a `"type": "git"` entry takes `timeout` in seconds through `GitCommandHandlers::configuredTimeout()`, honoured only when positive and finite, else 300 s, so the config cannot switch the bound off).
- **SES-1** `/rewind` kept the undone prompt in history and in the input box — fixed on master in `abd65fd16`. Residual: checkpoints saved before the fix are restored by dropping a trailing user row that matches the restored draft.
- **AG-1** Forked sub-agents shared the parent's MCP pipes, ids and keep-alive sockets — fixed on master in `ea6e178fd` (stdio: process-unique ids, locked exchanges, shared read buffer) and `2d96e2edb` (HTTP: pid-unique ids, fresh connection per process). Residual: parallel agents now serialise their calls to one stdio server. The lock file per MCP server left behind when the TUI was killed (R14) is fixed since in sugar-mcp `8bd3e5a4f` (wave 8B: lock files are named `sugar-mcp-lock-<pidns>-<ownerpid>-<rand>`, and `create()` first runs `ExchangeLock::sweepStale()`, which unlinks a file only when its owner is gone, it comes from the same pid namespace and nobody holds its flock; legacy `tempnam()` names are never touched). Its LSP twin is 15a B8.
- **AG-4** `AgentManager::executeSubAgent()` swallowed provider errors the way 15a A1 did — fixed on master in `48e9a3f65` (it throws `ProviderResponseException` after the retry loops; the sub-agent ends `STATUS_FAILED` carrying the provider text). `TaskTool::runOnEngine` and the workflow `EngineExecutor` were checked: they go through `EngineBackend`/`Runtime`, which A1 fixed, and are pinned by regression tests.
- **MCP-9** Empty maps nested inside a tool's arguments went on the wire as `[]` — fixed on master in `f84a97364` (new sugar-mcp `ArgumentShape::conform()` walks the arguments against the tool's `inputSchema`; applied in sugar-mcp `StdioMcpServer`, crush `HttpMcpServer` and `ClaudeCodeMcpServer`).
- **CLI-1** Headless JSON stdout was not protected from PHP diagnostics — fixed on master in `0c2bbd0a7` (`display_errors=stderr` is the first statement of `bin/sugarcrush`; `Bootstrap::ensureDir()` reports the `mkdir` reason in its own exception through a handler scoped to the call, because `@` plus `error_get_last()` loses it under a host handler; on the TUI with the 15a C2 log file in place, `display_errors` is 0 and a fatal prints one "details in <log>" line to stderr).
- **SES-2** `forkSession()` copied only the dead legacy tables, so a fork had no transcript, checkpoints or meta and shared its parent's name — fixed on master in `698a1efff` (`EnhancedSessionStore::forkSession()` copies the transcript, checkpoints, blobs (re-keyed to the fork, with checkpoint envelope ids remapped) and meta in one transaction; the fork is named `<name> (branch)`, then `<name> (branch N)`; `getSessionByName()` is deterministic). Still open nearby: the `/fork` background daemon does not load the copied transcript (Part II #30), and a `/fork` docblock still names the old method (15b-33).
- **SES-6** `getMessages()` ordered by `created_at` with no tiebreak — fixed on master in `698a1efff` (folded into SES-2: `ORDER BY created_at, id`).
- **SES-4** Checkpoint blobs were garbage-collected only on `/rewind` — fixed on master in `698a1efff` (`pruneOldCheckpoints()` runs the blob GC, and the GC drops only the deleted ids from the intern cache).
- **SES-5** Checkpoint and meta timestamps mixed local time and UTC — fixed on master in `698a1efff` (checkpoint `created_at` and `last_activity` are written and read as UTC). Residual: rows written in local time before the fix are not converted.
- **MCP-4** One stray non-JSON stdout line aborted the in-flight MCP request — fixed on master in `e97fe5e5b` (sugar-mcp `StdioMcpServer::readResponse()` skips unparseable lines, banners and blank keep-alives alike, and gives up only on EOF or the deadline; an envelope with our id but neither `result` nor `error` fails the call instead of hanging it; the fork-safety fragment recovery is subsumed by the skip).
- **MCP-3** `ClaudeCodeMcpClient::callTool()` gave up after about 1 s, and `initialize` was sent as a notification — fixed on master in `0ed1b0f70` (the 100 × 10 ms poll is replaced by a `stream_select` read, `readFrame()`, that ends on the reply, the deadline, EOF or the child's death; `tools/call` has no total deadline; `initialize` and `tools/list` are bounded at 60 s, `StdioMcpServer::DEFAULT_START_TIMEOUT_SECONDS`, which a new optional `handshakeTimeoutSeconds` constructor argument can override; `initialize` is a real request followed by `notifications/initialized`; a failed handshake or `tools/list` shuts the child down; id matching is strict and a malformed reply to our own id fails fast; `ExchangeLock` and `RequestIdSequence` fork safety are kept). Measured with `ccmcp.php`: before, the call threw after 1.01 s; after, the 3 s operation completes.
- **WF-2** `/workflow pause` could not pause a running workflow, resuming a failed run skipped the failed stage and reported success, and the pause file was never cleared — fixed on master in `efdfe79f8` (one pause-file writer saves only the stages that succeeded; a live run can be paused: the file is written at once, the current stage finishes, and the run returns `Paused` before the next one; resume restores the earlier successful stages, tokens, cost and start time, re-runs the failed stage and deletes the pause file, and a second resume throws `WorkflowNotRunningException`; `getStatus()` checks a live run, then the pause file, then a finished run the engine remembers; Chat's `/workflow resume` runs in a Fiber through `driveWorkflowFiber()`, so it no longer freezes the TUI, and its reply says completed, failed or paused). Pausing a completed run is still allowed: resuming it runs nothing and deletes the file. Still open nearby: the TUI refuses `/workflow pause` while the run is in flight (WF-4).
- **WF-3** `{{agent.results}}` never resolved for a parallel agent, and a run-context key equal to an agent type crashed the stage — fixed on master in `98821a7a2` (results live under a reserved `$context['@results']` key and every stage type writes them, named by the task name, else the stage name, `<stage>_<n>` for a parallel agent (1-based), the step name for a pipeline step, and `<stage>_verifier` for a verifier; parallel results are matched to their agents by SubAgent id, since they come back in completion order; context keys starting with `@` are refused with `InvalidArgumentException`, so the `coder=x` crash is gone, and tokens from agents that ran are kept on a failed stage). Behaviour change: the agent-type alias is gone, so `{{coder.results}}` resolves only for a task named `coder` (nothing in the repository used it).
- **DOC-1** Smaller doc/code drift: TROUBLESHOOTING's startup-ordering advice and the doc halves of WF-1 and WF-3 — fixed on master in three commits: item 2 (WF-1's timeout and retries semantics in `docs/WORKFLOWS.md` and `examples/workflows/lint-then-fix.yaml`) in `4fa805970` (wave 4); item 1 in `bc5c3dd3b` (the ordering paragraph in `docs/TROUBLESHOOTING.md` is rewritten: every entry is attempted, and the error line names the entries that failed; pinned by the new `McpStartupOrderingDocDriftTest`); item 3 in `98821a7a2` (with WF-3: the Interpolation table in `docs/WORKFLOWS.md` is rewritten, with new sections on how results are named and on pause, resume and status). Item 4 had resolved itself earlier. `docs/MCP.md:97-100` made the same stale claim (DOC-2), fixed in `ec0640ee8`.
- **BG-1** Nothing could stop a background session, because the daemon's `STOP` command had no sender — fixed on master in `0214944fc` (new `/bg stop <id>`: `BackgroundSupervisor::stopSession()` tries the authenticated `STOP`, then SIGTERM, then a tree kill, sending each signal only after re-checking the pid's `/proc` start time; the daemon traps SIGTERM; a stopped session settles as `Stopped`; `docs/COMMANDS.md` documents the command).
- **BG-2** Per-launch IPC directories under `/tmp` were never removed — fixed on master in `2c12e9c9a` + `f7a6ac85b` (a settled session releases its `.sock`, `.buffer`, `.buffer.log` and `.token`, and the private directory when the last one settles, only inside the supervisor's own directory; `BackgroundSupervisor::sweepStaleIpcDirs()`, run from `ToolIpcFiles::sweepOnce()` at boot, removes stale IPC directories: older than 86400 s, with the exact minted name, owned by the user, holding only files and sockets). Behaviour change: a settled session's `.buffer.log` (the daemon's stderr) is deleted too, so a failed `/bg` task no longer leaves that log behind. Residual: the `/bg` tests in `tests/ChatTest.php` never ticked, so each full run left about two `sugar_crush_bg_*` directories in `/tmp` (R13); fixed since in `aa3251ea8` (wave 9: the two tests run under a `SUGARCRUSH_BACKEND_CMD` that answers at once, tick Chat until the session settles, and assert the IPC files and the directory are gone; measured 2 leaked dirs per run before, 0 after).
- **AG-2** One preset file in the Claude Code `tools:` spelling disabled every agent preset in every tier — fixed on master in `7c74ea7d7` (`AgentPresetRegistry::list()` parses each file on its own; a bad file is skipped and recorded in the new `skippedFiles()`; `tools`, `disallowedTools`, `skills` and `mcpServers` accept comma strings; a scalar string field that is not a string is refused naming the file and field; `Bootstrap::agentPresets()` shows one launch notice per skip, `AGENT_PRESET_SKIP_NOTICE_FORMAT`).
- **TMP-1** Fixed, shared `/tmp` directory names let the first user disable Task resume for everyone — fixed on master in `b468ef96e` (`sugarcrush-suspended-delegations-<euid>` and `sc-hook-ctx-<euid>`, with a `-noposix` form when ext-posix is missing, following `AuditHook::directoryFor()`; `verifiedDirectory()`'s checks are unchanged; `docs/HOOKS.md` names the new path).
- **AG-3** `AgentManager` never forgot a sub-agent — fixed on master in `54a3f4d52` (at most 64 finished sub-agents are kept, `RETAINED_TERMINAL_SUB_AGENTS`, oldest dropped through the formerly uncalled `removeSubAgent()`; pending and running agents are never dropped; pruning runs on creation and in `executeAll()`'s `finally`, so a batch larger than 64 still returns every result; dropped agents' tokens, cost and elapsed time are folded into per-agent totals).
- **MCP-7** The OAuth store wrote back a stale per-process cache and was not atomic — fixed on master in `1f3df682e` (every write takes an exclusive lock on a `<file>.lock` sidecar, re-reads the file, merges one row and publishes through `AtomicJsonFile` at 0600; `deleteAuth()` takes the same path; the cache follows the disk; a write over a corrupt file refuses instead of dropping every credential).
- **MCP-8** An expired login with no refresh token stored the client id as a never-expiring bearer — fixed on master in `0b748df43` + `909703a7d` + `164d6cf9a` (such an entry throws a "re-run `sugarcrush mcp auth login`" error, or names `/mcp add` for client-credentials entries, sends no request and leaves the store unchanged; `updateRegistration()` is off the token path and RFC 7592-correct, using `registration_client_uri` with the full body, kept on the new `AuthEntry::registrationClientUri` (legacy rows still load); a missing `expires_in` is accepted as an unknown expiry). Residual: `HttpMcpServer` has no refresh-on-401, so an unknown-expiry token is sent until the user logs in again; a client-credentials entry with no refresh token needs a manual re-add when it expires.
- **MCP-6** `mcp auth login` could not log in to a path-bearing MCP URL — fixed on master in `0687c8dc8` (new `OAuthDiscovery`: RFC 9728 protected-resource metadata first, then the RFC 8414 path-inserted and origin-only forms, at most 6 fetches, deduplicated; shared by login and `/mcp add`; `fetchOAuthMetadata()` throws on non-2xx; login takes a fourth `[registration-url]` operand; explicit `/mcp add` endpoints beat discovered ones; the stored key stays the exact URL the user gave). Residual: the `WWW-Authenticate` `resource_metadata` hint is not read, and only `authorization_servers[0]` is tried. The registration body's `client_metadata` envelope (MCP-10) is fixed since in `0aad80376`.
- **DOC-2** `docs/MCP.md` still said an unknown server `type` made startup ordering-dependent — fixed on master in `ec0640ee8` + `1360cbd01` (the sentence is gone, the bold **throws** that `DocFigureProseDriftTest` pins is kept, the page says `startTimeout` is stdio-only and that `claude-mcp` uses the fixed 60 s `DEFAULT_START_TIMEOUT_SECONDS`; `McpStartupOrderingDocDriftTest` now scans MCP.md too).
- **WF-1** Workflow and task timeouts and retries were never enforced, and a stage had no wall-clock bound — part (a) fixed in `4fa805970` (a per-agent deadline with `killTree()`, `withTimeBudget()`, and `Workflow::$timeout` reaching every stage) and `2b136d35a` (wave 8A: inline runs honour the agent's own timeout and the cancel path kills the tree); part (b) and the inline-budget gap in `553cf059a` (wave 8B: `AgentWorkerPool::executeAll()` re-queues a Failed or TimedOut attempt while the agent has retries left — the higher of `SubAgent::$maxRetries` and the pool floor `withMaxRetries()`, fed from `AgentPoolConfig::$maxRetries` at every pool built from the config, whose default is now 0 and whose "DORMANT SEAM" note is retired; a Stopped agent, a cancelled run and a spent budget are never retried; each attempt gets a fresh timeout under the shared stage budget; there is one result per agent, carrying every attempt's tokens and cost and the new `AgentResult::$attempts`; YAML `retries:` is accepted on a stage and on a parallel agent; an inline agent is handed the budget's remainder as its timeout when that is tighter). Residual: `EngineExecutor` enforces the timeout cooperatively, so a provider call or tool already running finishes first (documented); Chat did not show `attempts` in a workflow result; fixed since in `16fc401ae` (wave 9: `describeWorkflowResult()` adds "Stage 'x': N attempts" for a stage whose agent needed more than one run).
- **MCP-5** Project MCP trust was bound to the root path, not to what `.mcp.json` says — fixed on master in `53a97179e` (the new `MCP/McpTrustPins` keeps `~/.sugar-crush/mcp-trust.json`, 0600 and atomic, with one sha256 fingerprint and summary per server per canonical root, over the entry as McpClient builds it minus `enabled`, `startTimeout`, `timeout` and `description`, env pinned unresolved; `McpClient` gains an `admit` closure that judges the bytes it loaded before build or spawn, so there is no TOCTOU window; the first trusted launch records, and afterwards a changed or added server is refused and named in one `MCP_SERVER_REFUSED_NOTICE_FORMAT` notice while unchanged servers start; `sugarcrush mcp trust` reviews each entry, adds the root to `trustedProjectMcp` when missing and re-records the pins). Residual: `mcp list` shows no pin status; `mcp trust` is the review surface.
- **MCP-10** Dynamic client registration wrapped the metadata in a `client_metadata` envelope — fixed on master in `0aad80376` (the metadata is the top-level JSON body, RFC 7591 §3.1; only `client_id` is required in the response, §3.2.1, and `client_secret`, `registration_access_token` and `registration_client_uri` become `''` when absent, so a server without RFC 7592 no longer breaks login; the `McpAuthStoreLoginTest` pin asserts there is no envelope).
- **WF-4** While a workflow ran, Chat refused every slash command, so a live `/workflow pause` or `/workflow status` got through only after Esc Esc — fixed on master in `2fc6da1eb` (wave 9: a new Chat field `workflowTurnInFlight`, set by `/workflow run` and `/workflow resume` and cleared by the settle and by the Esc Esc cancel arm, lets `/workflow pause|status` through the in-flight refusal in any spelling unless a custom `workflow.md` overrides the command; `workflowResponse()` no longer writes `inFlight=false`, which mid-run would have released the turn; `docs/COMMANDS.md` and `docs/WORKFLOWS.md` describe the rule).
- **AG-5** Two dormant sub-agent paths would have killed their agents at 300 s — fixed on master in `3afe07a42` (wave 9, Chat half: when `executeAgents()` builds its pool from `AgentPoolConfig`, each SubAgent is dispatched as a copy carrying `defaultTimeoutSeconds`, 0 meaning no bound; an explicit `withWorkerPool()` pool gets its agents as given) and `d1416fb86` (App half: `App::dispatchSkill(…, int $timeoutSeconds = 0)` passes the value as the SubAgent's `timeout:`). Both paths still have no production caller.
- **SES-3** No single-writer guard per session: two TUIs on one session clobbered each other's transcript and interleaved checkpoints — part (a) fixed in `698a1efff` (`UNIQUE(session_id, "index")`, index allocation and every save, checkpoint and restore in one `BEGIN IMMEDIATE` transaction, closing the index and blob-intern races); part (b) in `14f682fad` (wave 10: a new `Session\SessionLock` takes a non-blocking `flock()` on `<configDir>/sessions/<id>.lock`, opened close-on-exec, released and unlinked only by the owner pid, inode re-checked on acquire, failing open; `EnhancedSessionStore::lockSession()`/`sessionLockHolder()`; `Bootstrap::chat()` calls `Chat::withSessionLocking()` last; a second TUI opens the session read-only with `READ_ONLY_SESSION_NOTICE` naming the holder's pid and offering `/branch`, refuses prompts and every command outside the fail-closed `READ_ONLY_COMMANDS` allowlist, refuses `/workflow run|resume` before `workflowTurnInFlight` is set, persists nothing and stashes the refused draft for `/branch` to restore; `update()` re-locks on every session-id change, so `/branch` out of a read-only window is writable). Residuals: a read-only window does not become writable by itself when the holder exits (switch, `/branch` or relaunch); there is no read-only marker in the status bar; at launch the transcript is loaded before the lock is taken, so a holder that saves and exits in that window leaves a slightly stale load; a forked turn child that outlives a crashed parent holds the lock until it ends.
- **CLI-2** `sugarcrush <dir>` ignored a bare directory name and dropped every other positional word — part (a) fixed in `b899773a6` (an existing-directory positional is the root; `--root` refuses a flag-shaped value); part (b) in `5560c305c` + `14f682fad` (wave 10: on a TUI launch `ArgvParser::resolveOperands()` joins the leftover words into the new `ParsedArgs::$initialPrompt`, and only the first operand can be the root, so `sugarcrush explain src/Chat.php` is a prompt; a lone second directory, leftovers on `-p`/`run`, words before a subcommand and an empty operand still exit 2; `bin/sugarcrush` passes it on the existing `Program` line through `Bootstrap::app(..., initialPrompt:)` to `Chat::withInitialPrompt()`, and `Chat::init()` sends an `InitialPromptMsg` that `update()` submits as if typed; with the picker open it becomes the draft, and in a read-only window it is refused and stashed). Residual: `--resume fix it` still takes `fix` as the resume target.
- **CLI-3** The launch-notice cap (24) was below the worst case a launch could raise — fixed on master in `216d77566` (wave 10: `Bootstrap::LAUNCH_NOTICE_BOUNDED_SOURCES` lists 18 sources with their bounded row counts; the recount found 31, not 26, because the narrowed-grant aggregate, the MCP partial-start and refused rows and the `enabledSkills` shape row had never been counted; `LAUNCH_NOTICE_LIMIT` is 36; the new `BootstrapLaunchNoticeCapCensusTest` requires every method that reaches the notice seam to be on the roster and the sum to stay below the cap, and `BootstrapLaunchNoticeRoutingTest` derives its counts from the constant). Chat's read-only-session notice is a Chat row, not a launch notice.
