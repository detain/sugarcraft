# sugar-crush: feature baseline (verified against source, 2026-10-01)

Feeds steps: 0.1, 0.2, 0.3, 0.4-a, 0.4-b, 0.5, 0.6, 0.7, 0.8, 0.10, 0.11, 0.12, 0.13-a, 0.13-b, 0.14-b, 0.14-c, 0.15, 0.16, X-31a, X-31b, X-37a, 1.A-1, 1.A-2, 1.B-2, 1.B-3, 1.C-1, 1.C-2, 1.C-3, 1.C-4a, 1.C-5, RELAY, DEF-MODE, 2.1, 2.2-1, 2.3, 2.4-1, 2.5, 2.6, 2.7-1a, 2.8, 2.9, 2.10, 2.11, 2.12, 3.A-1, 3.B-2, 3.C, 3.D-1, 3.E, 3.F, 3.G, 3.H, 3.I-1, 4.1-1, 4.2, 4.3-1, 4.3-3, 4.4, 4.6-2, 4.7-1, 4.7-2, 4.9, 4.10-1, 5.1-1, 5.2, 5.3-1, 5.4-1, 5.5-1, 5.6, 5.7-1, 5.9-1, 5.10, 5.11-1, 5.12, 5.13a, 5.14a, 5.14c, 5.14i, N-P3, N-P3a, N-P4e, P-A1, P-B1, P-D3, O-2a, O-2f

A map of the current `sugar-crush` code that the remaining steps build on: where each subsystem lives, its entry points, and what is DORMANT or PARTIAL and must be wired. Paths are relative to `sugar-crush/`. Line anchors are deliberately omitted (they drift); use the method names here and the current anchors in `impact/*.md`.

**Status legend**

| Status | Meaning |
|---|---|
| **PARTIAL** | Wired, but missing pieces or broken in a common configuration. |
| **DORMANT** | The code exists (usually unit-tested), but nothing on the live path reaches it. Wire it; never delete it. |
| **ABSENT** | Not implemented. |

The live path is `bin/sugarcrush` → `Bootstrap::app()` → `Program(App)` → `Chat` → `EngineBackend` → `Runtime` → provider.

---

## 1. Architecture and turn loop

### 1.1 Entry points

- `bin/sugarcrush` parses argv (`Cli\ArgvParser::parse()`), applies launch overrides (`Bootstrap::useConfigPath/useModel/usePermissionMode/useSessionLaunch`), then dispatches:
  1. a subcommand → `Cli\Subcommands::dispatch()` (`doctor`, `models`, `session list|delete`, `mcp list|auth|import`, `completion`). There is no `serve`, web UI or ACP mode (→ O-*, 5.9).
  2. `-p` / `run "<prompt>"` → `Cli\NonInteractive::run()`: a synchronous in-process `EngineBackend::complete()` with `HeadlessPermissionPrompt` as approver; `--output-format text|json` only (no `stream-json`).
  3. the TUI: `new Program(Bootstrap::app($root), Chat::programOptions())`.
- `Bootstrap::chat()` builds the session store, `SkillRegistry`, `PermissionGate`, `CommandLoader`, `RulesState`, `AgentManager`, `AgentPoolConfig`/`AgentWorkerPool` and the backend, then constructs `Chat`.
- `App` (`src/App/App.php`) is the root pane shell and delegates the conversation to `Chat` (`App::delegateToChat()`). `App` is *also* the per-step state object passed to `Runtime::run(App $app, …)`.
- `Chat` (`src/Chat.php`) owns the input, transcript, slash/palette dispatch, permission modal, compaction, spend accounting, persistence (`persistTranscript()`), and subscriptions (`ToolEventPumpMsg`, `BackgroundTickMsg`, notices, status line).

### 1.2 Backend selection — `Bootstrap::backend()` / `backendFor()`

First match wins: `$SUGARCRUSH_PROVIDER` → `$SUGARCRUSH_BACKEND_CMD` (`CommandBackend`) → `$SUGARCRUSH_BACKEND_CMD_STREAM` (`StreamingCommandBackend`) → `provider` in `~/.sugar-crush/config.json` → `EngineBackend(EchoProvider)`.

`backendFor()` builds an `EngineBackend` with the provider, model, `tools()`, `hooks()`, `permissionGate`, skills, `InstructionFileLoader`, root, `MemoryStore` and `maxSteps` (`maxToolSteps`, default 1000). Its signature accepts `taskManager`/`taskPool`/`rulesState`, but `Chat::selectPaletteProvider()` (used by `/model` and the palette) does not pass them, so after a provider switch the Task tool disappears and rule toggles detach (PARTIAL → N-P3a). `/model <x>` takes a **provider** name, not a model id.

Two tool pipelines:
- `EngineBackend` runs tools inside `Runtime` (the live path).
- Command backends return JSON tool calls that `Chat` runs itself (`beginToolCalls()` → `gateToolCall()` → `forkToolCalls()`). This is the **only** path that reaches the y/n/a Veil modal (`Chat::requestPermission()`).

### 1.3 Providers (`src/Providers/`)

`ProviderFactory::availableTypes()`: `openai, anthropic, claude-code, sglang, bedrock, vertex, custom`. Named configs come from `.sugar-crush/config.dev.json` `providers{}`.

| Type | Facts steps rely on |
|---|---|
| `sglang` (primary) | `SglangProvider`, OpenAI-compatible. Structured `delta.tool_calls` (`resolveStreamedToolCalls`) plus textual fallback parsers (`openai`, `minimax-xml-fallback`, `dsml`) whose ids repeat per response (`dsml_call_0`, `minimax_xml_call_N`) (→ 0.2). `formatMessages()` hoists every in-history System row into one leading system message (→ 1.A-1) and drops `reasoning_content` on assistant tool-call rows (→ 0.1). Family checks `isDeepSeekV4()` / `isQwen3Next()` are public static (→ 0.1, 5.10). |
| `custom` | `CustomProvider::openAiCompatible()`, hand-parsed SSE, near-duplicate `resolveStreamedToolCalls`. Fixed 128,000 context default and $0 pricing (→ 5.13a). |
| `openai` | `OpenAIProvider`. Always streams, and `parseChunk()` hard-codes `toolCalls: null`, so tool calls are dropped on the live path (batch `parseResponse` does build them) (→ X-31a). |
| `anthropic` | `ProviderFactory::createAnthropic()` builds an OpenAI-shaped `CustomProvider` with `supportsFunctionCalling: false`, and the default base URL lacks `/v1` (→ X-31b). |
| `claude-code` | Shells out to `claude -p`; Claude Code's own tools run there, outside sugar-crush hooks and gate. |
| `bedrock`, `vertex` | Native tool calls; system as `systemBlocks`. |

Cross-cutting:
- **Retries:** `Runtime::runStreaming()`/`runBatch()` retry transient failures up to `TransientFailure::MAX_ATTEMPTS = 3`, but a stream is never retried once a token reached the UI (→ 2.7).
- **Watchdog:** `EngineBackend::COMPLETE_TIMEOUT_SECONDS = 120` kills a turn after 120 s with no frame from the child; re-armed by token, reasoning, heartbeat and event frames. Parallel groups heartbeat (`toolWaitHeartbeat`); **sequential tools send nothing**, so a silent sequential Bash/Grep/MCP call over 120 s kills the whole turn (→ 0.4-b). Never add a total timeout on the provider call.
- **Output tokens:** `max_tokens` defaults to 4096 on Custom/OpenAI when `maxOutputTokens` is unset (→ 2.7).
- **Usage:** `Usage::promptTokens()` is null unless input, cache-read and cache-creation are all reported; OpenAI-shaped providers never report cache-creation, so `Renderer::cacheIndicator()` is unreachable there (→ 0.13-b, 2.1).
- **Session affinity:** `Providers/Concerns/SessionAffinity.php` (`X-SugarCrush-Session`) is DORMANT: no caller passes an id, and engine-path hooks receive an empty `sessionId` (→ 0.13-a).
- **Embeddings:** `ProviderInterface::embeddings()` is implemented on every provider but has no consumer (DORMANT → 5.3-1).

### 1.4 How a turn executes

1. **Submit** — `Chat::submit()`. Mid-turn prompts are queued (`enqueuePrompt` / `releaseQueuedPrompts`); slash commands are refused mid-turn except `/exit`. Otherwise: custom-command expansion or `dispatchCommand`, spend-cap refusal, idle-compaction prompt, threshold compaction (§3), `UserPromptSubmit`/`SessionStart` hooks (`dispatchTurnHooks`), then the user message.
2. **Dispatch** — `Chat::dispatchTurn()`: optional 70% reminder, per-turn checkpoint (`EnhancedSessionStore::saveCheckpoint`), `scheduleBackendCompletion()` (wraps `withSpendCap()`, hands the backend a shared `ArrayObject` inbox drained by the tool-event pump), and `scheduleTitleGeneration()` on the first turn.
3. **Fork** — `EngineBackend::completeAsync()` `pcntl_fork`s with a `stream_socket_pair`. The child (`runCompleteInChild()`) streams length-prefixed `serialize()` frames (`writeFrame` / `drainFrames`, `encodeEvent` / `decodeEvent`): `token`, `reasoning`, `started`, `finished`, `subagent`, `spend_cap`, `result`. The channel is **one-way** (child → parent): no permission reply, steer or cancel-tool frames exist (→ 1.C-1). Without pcntl: `completeAsyncBlocking()`.
4. **Agent loop** — `EngineBackend::runTurn()` builds a **new `Runtime` per turn** and an `App` state object, then loops `Runtime::run()` up to `maxSteps`. It exits when a step returns no tool results, the spend cap is crossed, or steps run out (`stepsTruncated`). An empty or reasoning-only reply ends the turn silently (→ 0.10). The reply is the **last assistant content only**; interim narration is lost. There is no context check between steps (→ 2.1).
5. **One step** — `Runtime::run()` builds messages via `Runtime::buildMessages()` → `Messages\HistorySanitizer::sanitize()` (drops orphan results and empty assistant rows; synthesises "interrupted" results), assembles the system prompt (§4) and a `CompleteRequest` (`model, messages, tools, systemPrompt, systemBlocks, maxTokens, onHeartbeat`), streams or batches, then executes tool calls.
6. **Tool execution** — `Runtime::executeToolCalls()` splits calls into segments: consecutive `ParallelSafe` tools (Read, Glob, Grep, WebFetch, WebSearch, Task) run concurrently via `executeConcurrently()` (one fork per call, results through `Support\ToolIpcFiles`, 90 s group deadline, Task exempt); everything else runs in `executeSequentially()`. **Task fan-out is uncapped**: `AgentPoolConfig::maxConcurrent` (5) is never read on the engine path (→ 0.16). Each call goes through `Runtime::gate()` → `HookManager::preToolUse()` (built-ins → `hooks.yaml` → `PermissionGateHook`); verdicts allow/deny/modify/ask; ask goes to `Runtime::settleAsk()`; then `settle()` runs `PostToolUse` and appends its `additionalContext` (→ 3.E).
7. **Back in the UI** — `BackendToolEventsMsg` / `AssistantMsg`. Running placeholders become result rows; usage and the token calibration (`turnEstimateObservation`) are updated.

**Cancellation.** Esc Esc cancels the turn's `CancellationToken`, bumps `generation`, and the backend's `teardown()` SIGKILLs the turn child. Per-call cancel and **mid-turn steering are ABSENT**; a queued prompt goes out only after the turn (→ 1.C-4a).

---

## 2. Agents, sub-agents, background sessions, workflows

### 2.1 Roster and presets

`Bootstrap::agentManager()` → `agentRoster()` merges, lowest precedence first: foreign imports (`ForeignAgentPresetRegistry`: `~/.claude/agents`, `<root>/.claude/agents`, opencode dirs), six built-ins in `AgentDefinition` (`coder`, `reviewer` with `['Read','Grep','Bash(git *)']`, `debugger`, `architect`, `tester`, `devops`), and native presets `<root>/.sugar-crush/agents/*.md`, `~/.sugar-crush/agents/*.md` (`AgentPresetRegistry`, DTO `AgentPreset`). Fields: `name`, `description`, `tools`, `disallowedTools`, `model`, `permissionMode`, `maxTurns`, `skills`, `mcpServers`, `memory`, `background`, `effort`, `isolation`, `color`, `initialPrompt`.

| Field | Status |
|---|---|
| prompt/body, `skills`, `mcpServers` | Applied (`AgentManager::resolveBatchSystemPrompts()`). |
| `maxTurns` | Applied; default `TaskTool::DEFAULT_MAX_TURNS = 200` (`EngineExecutor::DEFAULT_MAX_TURNS` for workflows). |
| `tools` / `disallowedTools` | PARTIAL: `AgentManager::resolveGrantedTools()` matches **tool name only**, so `Bash(git *)` grants all of Bash and argument-scoped denies are skipped; with no `tools:` the grant is null (full tool set) and `disallowedTools` is ignored. The argument checker `refuseCallOutsideGrant()` is reached only from `executeSubAgent()`, which has no production caller (→ 4.2). Permission *rules* (`PermissionRule::matches()`) are already argument-scoped and fail closed; reuse that matcher. |
| `model` | DORMANT on the live path: `TaskTool::runOnEngine()` reuses the parent engine's provider and model (→ 4.1-1). |
| `permissionMode` | DORMANT: stored on `Agent`, never read; sub-agents run under the parent gate (→ 4.1-1). |
| `effort`, `background`, `memory`, `color` | DORMANT (→ 4.1-1, 4.3-1). |
| `isolation` | Carried by `Agent::fromPreset()` but no consumer (TaskTool, WorkflowEngine, `/bg`) (→ 4.9). |

`/agents` and `/agent <name>` are inspect-only.

### 2.2 The Task tool (`src/Tools/BuiltIn/TaskTool.php`)

- Appended by `Bootstrap::tools()` only when an AgentManager is passed; re-bound to the running engine each turn by `EngineBackend::turnTools()` via `DelegatesToEngine`.
- Args: `description`, `prompt`, `agent` (alias `subagent_type`), optional `resume`.
- `runOnEngine()`: the grant (or full set) minus every `DelegatesToEngine` tool, so **depth is fixed at 1** (→ 4.7-2). Messages are `[SystemMessage(preset prompt + skills), UserMessage(task)]` plus the harness prompt. Runs synchronously via `$engine->withTools()->withMaxSteps()->completeTranscript()`, i.e. through `runTurn`, so step-level context work (2.1, 2.2-1, 2.4-1) covers sub-agents automatically.
- The parent gets **only the final text**, unfenced, with no "no authority" framing; the pool arm in `execute()` and the failure string that embeds partial output are also unfenced (→ 0.15).
- **Resume:** failed, interrupted, report-less and step-capped runs are serialised by `SuspendedDelegations` (`sys_get_temp_dir()/sugarcrush-suspended-delegations/<id>.run`, 0600, 7-day expiry); clean reports are not resumable (→ 4.7-1).
- **Telemetry PARTIAL:** `SubAgentActivity` events cross the socket as `subagent` frames and are projected by `AgentManager::projectRemoteSubAgent()` into `AgentsPane` / `AgentDashboardPane`. The emitter in `turnTools()` is pid-bound, so **parallel Tasks (grandchildren) produce no dashboard rows** (→ RELAY, P-B1).
- **Cancel PARTIAL:** `KeyboardHandler` emits `CancelAgentCmd`, `ResumeAgentCmd`, `StopAllAgentsCmd`, `QuitAgentViewCmd`, `GroupInputCmd`, but `App::consumeShellCmd()` maps them to no-ops (→ P-D3).
- **Fallback path DORMANT:** `AgentManager::executeAll()` → `AgentWorkerPool`, `Chat::executeAgents()`, `AgentManager::executeSubAgent()` have no live callers.

### 2.3 Parent ↔ child messaging and teams

- ABSENT: the parent cannot message, steer or read a running sub-agent; the user cannot type to one (→ 1.C, 4.4).
- Team infrastructure DORMANT: `TeamManager`, `Team`, `Teammate`, `Mailbox` (JSONL inbox: `send`, `receive`, `peek`, `waitForMessage`), `TaskList` (SQLite board with claim/release, dependencies, `getUnblockedTasks`). `new Mailbox`/`new TaskList` occur only inside `Team`; nothing constructs `TeamManager` or calls `AgentManager::setTeamManager()`, so `createTeam()` throws (→ 4.4, 4.6-2).
- No todo tool. `TaskList` is the team queue, not a session todo; only `SessionMeta::$tasks` fits, and `saveSessionMeta()` has no caller (→ 3.C).

### 2.4 Background sessions

- `/bg <task>`: `Chat::handleBackgroundCommand()` → `scheduleBackgroundSpawn()` → `BackgroundSupervisor::spawnSession()` → a double-forked, `setsid` daemon running `BackgroundSessionRunner::main()` over a token-authenticated Unix socket. Its backend is `backendFor(..., consolePermissionPrompt: true)`, so an Ask is refused and logged.
- The daemon runs `complete([Message::user($task)])` with **no history**; `/fork <prompt>` copies the session but the daemon does not load it (→ 4.3-1).
- `pumpBackgroundSessions()` appends only a status line; **the final answer never reaches the chat** (→ 4.3-1).
- `BackgroundSupervisor::reconnect()` is DORMANT, and wiring it alone is a no-op: it reads in-memory sessions and the IPC dir is a fresh random dir per process (`ensurePrivateIpcDir`) (→ 4.3-3).

### 2.5 Workflows (`src/Workflows/`)

- `/workflow run|pause|resume|status|list` via `Chat::handleWorkflowCommand`; engine from `Bootstrap::workflowEngine()`; discovery in `WorkflowRegistry` (`<root>/.sugar-crush/workflows/*.yaml`, `~/.sugar-crush/workflows/*.{yaml,php}`).
- A stage's `agent:` is a label only (no preset load); each stage runs through `AgentManager` → `AgentWorkerPool` with `EngineExecutor` bound to the chat engine.
- Only the first task of a stage runs (`WorkflowEngine`). This is intentional and unreachable from YAML; it matters only once model-authored plans can list several tasks per stage (→ 4.10-1).

### 2.6 Worktree isolation (DORMANT → 4.9)

`WorktreeManager` (git worktree per agent, `.worktreeinclude` copying, cleanup policy), `WorktreeConfig` and `PathJail(Config)` are never constructed. `EngineBackend::withWorktreeRoot()` — the only registrar of `BashEscapeDenyHook`, and the method that re-jails every path tool (`AcceptsWorktreeJail`) — has no caller. `worktreeCleanupPeriodDays`, `worktreeIncludeFile` and `SUGARCRUSH_WORKTREES_DIR` are read only by these classes.

---

## 3. Context handling

### 3.1 Messages on the wire

- Within a turn, structured `AssistantMessage(toolCalls)` / `ToolResultMessage` rows are appended per step and cleaned by `HistorySanitizer`.
- **Across turns the replay is lossy** (→ 1.B-2): `Chat::toolResultMessage()` stores each finished engine tool call as an Assistant-role row whose content is the raw output, and `EngineBackend::toTypedMessages()` maps by role only (user → `UserMessage`, assistant → `AssistantMessage(content)`, else `SystemMessage`), skipping rows that fail `Message::agentVisible()` (`uiOnly`). Earlier tool calls therefore reach the model as plain assistant text with no name, arguments or `tool_call_id`. `Message` has no stable `id`/`ref`/`stepId`.
- System-role rows (launch notices up to `LAUNCH_NOTICE_LIMIT = 36`, compaction notices, cancel, spend-cap, placeholders, the context reminder) all go to the provider.
- `/websearch` results are injected as a user + assistant pair.
- Attachments: `Chat::userTurnMessage()` attaches files/images as `<file path="…">` blocks (`UserMessage::wireText`). `@diff`, `@session`, `@url` are ABSENT.

### 3.2 Token counting

- `Chat::rawTokenProxy()` = script-weighted `Util\TokenEstimate` + 10 per message over the history; `estimateTokenCount()` multiplies by the calibration from `turnEstimateObservation()`. **System prompt and tool schemas are not counted** (→ 2.1, 5.6).
- Window: `ContextWindow::ofBackend()` (provider `contextWindow()`, 100,000 fallback; `contextWindow` settings key overrides).
- Thresholds are percentages only; on a 1M window 70% is ~700k tokens (→ 2.9).

### 3.3 Compaction

Config: `Context/CompactorConfig` — reminder 70%, compact 85%, block 95%, `recentPreserveCount` 10, `toolOutputMaxChars` 2000, clips 80/100 chars.

All checks run **only in `Chat::submit()`**; nothing compacts between steps (→ 2.1).

| Trigger | Behaviour |
|---|---|
| ≥70% | `contextReminderMessage()` row rides along (old reminders stripped in `dispatchTurn`). |
| ≥85% with `summaryBackend` | `scheduleParkedCompaction()`: the prompt is parked; older exchanges go to a tool-less `EngineBackend` (`summaryModel` / `SUGARCRUSH_SUMMARY_MODEL`) with `COMPACT_SUMMARY_PROMPT`, a per-exchange six-facet record (`asked / did / files / decided / corrected / error`), prior summaries passed back; spliced by `applyModelCompaction()` on `HistoryCompactedMsg`. Different prompt and prefix from the main loop, so no cache reuse (→ 2.4-1, 2.5). |
| ≥85% otherwise | Heuristic `ContextCompactor::compact()`. |
| ≥95% after compaction | `intraExchangeTruncation()` (`ContextCompactor::truncateOversizedExchange()`), else refuse (`foregroundBlockedResponse`); thrash breaker `IdleCompactionPolicy::REFILL_LIMIT = 3`. |
| Idle > 1 h | `idleCompactionPromptResponse`. |
| `/compact` | `scheduleModelCompaction()` or heuristic `compactNow()`. A focus argument does not steer the summary (→ 2.12). |

Compaction rewrites the displayed and persisted history (`Chat::compactionChanges`), so scrollback is lost (→ 1.B-3).

Heuristic algorithm (`ContextCompactor::stagePairs()` + `compact()`), the targets of 0.7 and 2.2-1:
1. `removeToolResults()` filters `system` rows with a `tool_results` key, a shape `Message::toWire()` never produces — a no-op on live history.
2. `groupIntoPairs()`: the first assistant row after a user message closes the pair; every later assistant (tool-output) row becomes its own standalone pair, so "keep last 10" can mean the last ~10 tool rows.
3. Earlier pairs go through `compactFileReferences()` (`isFileReadMessage()` guesses by regex) and `removeNavigationSteps()` (drops any row with a line starting `rm`/`mv`/`cp`/`mkdir`/`ls`, User rows included).
4. Pairs become `[summary] <user ≤80> → <assistant ≤100 or "[exchanged information]">`; standalone rows clipped to 120; `groupSimilarExchanges()` merges near-duplicates. The LLM path replaces only user/assistant pairs (`exchangesToSummarize`).

ABSENT: age-based tool-output pruning, agent self-pruning, per-step summarisation, overflow recovery. DORMANT: `CompactorConfig::skillBudgetPerSkill/Combined`, `ContextCompactor::compactSkills()`/`filterSkills()` (→ 2.6).

### 3.4 Tool-output caps (at tool time)

| Tool | Cap |
|---|---|
| Bash, Grep, Glob, Lsp | 64 KiB head+tail, `PARTIAL` marker (`Tools/Concerns/TruncatesOutput`) |
| Read | 1 MiB head (`Read::DEFAULT_MAX_BYTES`) |
| WebFetch | 2 MiB |
| WebSearch | 5 MiB / 10 results |
| Instruction files appended to results | 16 KiB |
| MCP bridge (`McpToolBridge::renderContent`) | **uncapped** (→ 0.5); `Runtime::utf8Safe` assumes every tool capped itself |

No spill-to-file (→ 2.8). `SglangProvider::flagTruncationRiskInLatestToolResults()` only logs.

### 3.5 Prompt caching

- System sections are ordered by `Context\Stability` (Static → PerSession → PerTurn) for implicit prefix caching (`docs/PROMPT_ENGINEERING.md`). Explicit marks (`CacheBreakpoints`, `MarksPromptCache`) and `observeCacheHealth()` are wired.
- `Runtime` is rebuilt every turn, so the "per-session" memo of the repo map and memory is per turn (→ 1.A-2).
- `<env>` (branch/status/log) re-polls every step inside the system message, and SGLang hoists history System rows into message 0, so the prefix shifts (→ 1.A-1).

---

## 4. System prompt (`Runtime::systemPromptSections()` → `assembleSections()`, per step)

| # | Section | Stability | Source and facts steps rely on |
|---|---|---|---|
| 1 | Base identity | Static | `Runtime::basePrompt()` heredoc (~60 lines; one prompt for every model family → 5.10). |
| 2 | Maxims | Static | `Context/Sections/MaximsSection`. |
| 3 | Tool guidance | Static | `Runtime::toolGuidanceSection()` joins `PromptGuidance::promptGuidance()` from Bash, Read, Write, Task. **`Bash::promptGuidance()` hard-codes the SugarCraft git/PR cadence** (`ai/<slug>` branches, `unset GITHUB_TOKEN && gh pr create`, `gh pr merge`, `git pull --ff-only`) and is sent to every project; the cadence already lives in the repo's `AGENTS.md`, so the fix is deletion plus generic git-safety rules (→ 0.3). |
| 4 | `<repo-map>` | PerSession | `Context/RepoMapBlock`: composer/PSR-4 only (packages, namespaces, `.php` counts); no symbols, no non-PHP (→ 5.5-1). |
| 5 | `<user-rules>` | PerSession | `Context/RuleLoader`: `~/.sugar-crush/rules/**`, `~/.sugar-crush/rulebooks/**`; `paths:` rules are not standing (`RulePathNudge`); 64 KiB shared budget. |
| 6 | `<project-instructions>` (documents) | PerSession | `InstructionFileLoader::loadRoot()`: `CLAUDE.md`, `AGENTS.md` at root and ancestors up to `.git`; `@path` imports (`ImportResolver`); forced `instructions` globs. **Not loaded:** `~/.claude/CLAUDE.md`, any personal `~/.sugar-crush/AGENTS.md`, `.cursorrules`, `GEMINI.md`, `.clinerules` (→ 5.14). Nested files are injected into tool results on first touch (`loadForPath`). |
| 7 | `<project-instructions>` (rules) | PerSession | `<root>/.sugar-crush/rules/**`, `<root>/RULES.md`. |
| 8 | `<project-memory>` | PerSession | `Context/MemoryBlock::capture()`: project scope only, newest 12 bodies, 4 KiB, 512 B each (→ 0.6, 5.1-1). |
| 9 | Enabled skill bodies | PerTurn | `enabledSkills` config. |
| 10 | Skill listing | PerTurn | `SkillMatcher::listForPrompt()`. |
| 11 | `<env>` | PerTurn | `Context/EnvironmentBlock`: cwd, git?, platform, OS, PHP, model, date; branch, `status --porcelain`, `log -5`; after a write step (`withWriteSinceLastRender`) also `git diff --cached` and `git diff` (≤8 KiB each). Re-rendered every step (→ 1.A-1). |

Not in the prompt: time of day, timezone, shell, session or todo state. There is no snapshot/drift test of the assembled prompt bytes across steps (→ 1.A-1).

Other injections into tool results: nested instruction files, `SkillPathNudge`, `RulePathNudge`, `ScriptHook` exit-0 notes (≤10 KB). Sub-agents get the harness prompt plus their preset prompt as a head `system` message.

Delivery per provider: Sglang joins `systemPrompt` and history system rows into one leading system message; Custom/OpenAI prepend a system message; Bedrock sends `system` blocks; Vertex uses `systemBlocks` / `systemInstruction`; ClaudeCode passes `--system-prompt`.

---

## 5. Memory (`src/Memory/`)

- `MemoryStore` at `~/.sugar-crush/memory`: `<scope>/<uuid>.md` with YAML frontmatter; scopes `user/`, `project/`, `agent/` (`MemoryScope::Local` → `agent/`). Each scope keeps a `MEMORY.md` index (`generateIndex()`, `loadIndex()`, `MAX_INDEX_LINES = 200`, `MAX_INDEX_BYTES = 25 KiB`) that is **never injected** (→ 5.1-1).
- `ProjectMemoryWriter`: `/memory add --scope project` writes `<repo>/.sugar-crush/memory/` (8 KiB cap, refused not truncated).
- `MemoryEntry` types `pattern|convention|decision|preference`; `/memory add` always writes `pattern` with no tags.
- Recall: `Runtime::memorySnapshot()` → `MemoryBlock::capture()` reads only the project scope (home + repo; repo wins on id clash), newest first, cut at 12; no relevance selection (→ 5.3-1).
- `/memory add` defaults to **user** scope, which never reaches the prompt (`Chat::memoryAdd`) (→ 0.6). `ForeignMemoryImporter` (`/memory import claude|opencode`) lands notes in `agent` scope, also never injected.
- `MemoryStore::search()` is a case-insensitive substring match used only by `/memory search`; no FTS, no embeddings (→ 5.3-1).
- ABSENT: memory tool, auto-extraction, consolidation, versioned memory dir (→ 5.1-1, 5.2, 5.4-1, 2.11).

---

## 6. Tools

### 6.1 Roster construction

`Bootstrap::tools()` = `filterToolSet(unfilteredTools(...))` + `TaskTool` when an AgentManager is passed. `unfilteredTools()` order: `Bash`, `Read`, `Edit`, `Glob`, `Grep`, `Write`, `WebFetch`, `WebSearch`, `Doctor`, `SkillTool`, `lspTool()`, then `mcpTools($root)`. `filterToolSet()` applies `allowedTools` / `disabledTools` (fnmatch). File tools are built with the root as `PathJail`; no `worktreeJail` is passed anywhere.

ABSENT tools: TodoWrite/plan/ExitPlanMode (→ 3.C, 5.7-1), AskUserQuestion (→ 5.7-1), MultiEdit/apply_patch (→ 3.I), BashOutput/KillShell/background shell, memory tool (→ 5.1-1), context-pruning tools (→ 3.B), RepoMap (→ 5.5-1).

### 6.2 File tools

- **Read** (`BuiltIn/Read.php`): `file_path` + required `description`; no offset/limit, no line numbers, no continuation; up to 1 MiB then `"... [truncated]"`; raw bytes for binaries (→ 0.12). Appends nested instruction files and path nudges; session state crosses the fork via `CarriesSessionState`.
- **Edit** (`BuiltIn/Edit.php`): exact `substr_count === 1` unless `replace_all`; zero/multiple matches give a terse error (→ 0.11, 3.I-1). No fuzzy matching, no multi-edit, read-before-edit not enforced, no staleness check (→ 3.I). Result text `File updated: <path> (+A -R lines)`; the unified diff goes to `ToolResult::diff` for the TUI only.
- **Write** (`BuiltIn/Write.php`): refuses an existing path without `overwrite:true`; no staleness check.
- Diffs: `Tools/Concerns/BuildsUnifiedDiff` (PHP LCS, 3 context lines, `MAX_LCS_CELLS = 250_000`), carried in the `finished` frame and drawn by `Tui/DiffGutter`.
- Grep: `grep -rn` (BRE), no case-insensitive flag, no context lines, no output modes. Glob: PHP iterator, 1,000 matches.

### 6.3 Bash (`BuiltIn/Bash.php`, `Tools/Concerns/CapturesProcessOutput`)

- `proc_open` inside `setsid -w -- /bin/sh -c` (`Support/ProcessContainment`), stdin closed, non-interactive env. No cwd/env persistence.
- `runCaptured($timeoutSeconds)` and `terminateGroup()` (SIGTERM→SIGKILL the setsid group) exist, but **Bash passes no timeout and has no `timeout` parameter** (→ 0.4-a). `interactive:true` runs on a candy-pty PTY with an 8 s idle / 24 s hard ceiling (`runCapturedInteractive`).
- No sandbox (→ 5.12). Live guards: the gate's `rm -rf /` breaker, `ProtectFilesHook`, `ConfirmRemoveHook`. `BashEscapeDenyHook` is DORMANT (§2.6).

### 6.4 Web

- WebFetch: http(s), SSRF guards (DNS pinning, ≤3 re-checked redirects), 30 s, 2 MiB, raw body (no HTML→markdown).
- WebSearch: SearXNG JSON; no default endpoint by design (`SUGARCRUSH_SEARCH_ENDPOINT`); localhost/private endpoints refused (→ N-P4e for the settings key).
- `/websearch` (`Commands/WebSearchCommand`) runs synchronously in the TUI process.

### 6.5 LSP (→ 3.F)

`src/LSP/` is a full stdio JSON-RPC client (`LspConnection`: initialize, definition, references, hover, symbols, codeActions, diagnostics; `LspClient`: routing, `LspCache`, grep fallback). `Bootstrap::lspTool()` constructs `LspTool`, but no caller of `tools()`/`unfilteredTools()` passes `lsp:`, so the client is null and every call returns "no language server configured". No settings key for servers; nothing subscribes to `publishDiagnostics`. No post-edit lint or test run (→ 3.E, 3.H).

---

## 7. Git, checkpoints and sessions

- **Git:** state reaches the model only via `<env>`. No auto-commit, commit-message generation or attribution (→ 3.G). The in-process git MCP server (`MCP/GitMcpServer`, `GitCommandHandlers`) is reachable only via a `"type":"git"` entry in a trusted `.mcp.json`.
- **File checkpoints/undo: ABSENT** (→ 3.A-1). `/rewind [n]` restores only the transcript and input from a checkpoint.
- **Store:** `Session/EnhancedSessionStore` (SQLite `~/.sugar-crush/session.db`; tables `session_meta`, `checkpoints`, `checkpoint_blobs`, `session_transcripts`; `saveCheckpoint`, `saveTranscript`/`loadTranscript`, `forkSession`, `latestResumableSession`, `pruneEmptySessions`). Max 100 checkpoints per session. Single-writer lock: `Session/SessionLock` (flock), `lockSession` / `sessionLockHolder`, read-only fallback in `Chat::relockedForCurrentSession` (reuse it; no lease table → O-*).
- `Chat::persistTranscript()` rewrites the whole history after every change; running tools are revived as interrupted.
- `dispatchTurn()` saves one checkpoint per turn (messages, input, cursor, session id); 3.A-1 adds the git ref to that row.
- Forks (`/branch`) are named `"<name> (branch)"` but carry **no parent link** (→ P-A1).
- `SessionMeta` (tasks, modifiedFiles, agentStates) is read/written only inside the store (→ 3.C).
- Titles: `scheduleTitleGeneration()` on a tool-less `titleBackend` (`titleModel` / `SUGARCRUSH_TITLE_MODEL`) — the cheap-model seam for 3.D (`/goal` judge), 3.G (commit messages) and 5.11-1 (exec reviewer).
- `/share`: `ShareCommand` builds a `ShareSession` via `Util\Exporter`, but `ShareUploader::upload()` always throws; no local export (→ 5.14).

---

## 8. Skills, commands, hooks, MCP, permissions, settings

### 8.1 Skills (`src/Skills/`)

Tiers: built-in `src/Skills/BuiltIn/` < `~/.sugar-crush/skills/` < `<root>/.sugar-crush/skills/`; foreign `.claude`/`.opencode` trees imported read-only (`ForeignSkillDiscovery`, `SkillManager::loadAll()`).
- Frontmatter applied: `description`, `user-invocable`, `disable-model-invocation`, `paths`.
- Inert: `allowed-tools`, `disallowed-tools`, `effort`, `model` (read only by `App::dispatchSkill()`, which has no caller), `context: fork` — README claims `context: fork` is enforced; it is not (→ X-37a).
- The Ctrl+S picker (`App::handleSelectSkill()`) enables a skill for the session but splices only a heading, no body (→ 5.14 `$skill`).
- `SkillRegistry::findForPrompt` is deliberately unwired (measured precision 0.162). `src/Skills/SkillDiscovery.php` is unreferenced.

### 8.2 Slash commands (`src/Commands/`)

Dispatched in `Chat::dispatchCommand()`; roster generated by `CommandRegistry::all()` into `docs/COMMANDS.md` (drift-tested). Not present: `/init`, `/undo`, `/redo`, `/diff`, `/context`, `/goal`, `/btw`, `/handoff`, `/editor` (→ 3.A-1, 5.6, 3.D, 5.14).
Custom commands (`CommandLoader`, `CommandSpec`): `~/.sugar-crush/commands/**/*.md`, `<root>/.sugar-crush/commands/**/*.md`; `$ARGUMENTS`, `$1..$9`; `` !`cmd` `` (10 s budget, 16 KiB, project tier needs `trustedProjectCommands`); `@file` includes. Frontmatter `model` and `subtask` are parsed but ignored (DORMANT).

### 8.3 Hooks (`src/Hooks/`)

- `~/.sugar-crush/hooks.yaml` always; `<root>/.sugar-crush/hooks.yaml` only if `trustedProjectHooks` lists the root. Keys: `name`, `matcher` (regex on tool name), `command`, `description`, `disabled`, `timeout` (60 s).
- `ScriptHook` exit codes: 0 allow (stdout ≤10 KB appended as a note), 1 deny, 2 hard block, 3 ask, 4 modify (JSON args). No JSON stdout protocol (→ 3.D-1). `HookResult::$refusedBy` / `withRefusedBy` / `refusingHook` exist.
- `HookManager::resolveAsk(HookResult, bool, string $feedback = '')` already takes feedback; only the approver's `bool` return blocks it (→ 1.C-2).
- Events (`HookEvent`): LIVE `PreToolUse` (`Runtime::gate`), `PostToolUse` (`Runtime::settle`), `UserPromptSubmit`, `SessionStart` (`Chat::dispatchTurnHooks`). DORMANT (no dispatch site): `Stop`, `SubagentStop`, `SessionEnd`, `PreCompact` (→ 3.D-1, 2.12); `TaskCreated`, `TaskCompleted`, `TeammateIdle` and `HookDispatcher` only on the dormant `TaskList` (→ 4.6-2).
- Built-ins (`Hooks/BuiltIn/`): `ProtectFilesHook` (`DEFAULT_PROTECTED_PATTERNS` covers `.env`, `.env.*`, `.envrc`; `WRITE_ONLY_PATTERNS` cover `.sugar-crush/{hooks.yaml,config.json,agents/}`, `.git/{hooks,info}` — missing: `settings*.json`, `.mcp.json`, `.sugar-crush/{skills,commands,rules}/`, `*.pem`, private keys → 0.8, 0.14-c), `ConfirmRemoveHook`, `AuditHook`, `PermissionGateHook` (last), `BashEscapeDenyHook` (DORMANT).

### 8.4 MCP (`src/MCP/`)

- Client only. Config is `<root>/.mcp.json` only (no user-level config), honoured when `trustedProjectMcp` lists the root (`Bootstrap::mcpConfigDecision()`), with trust pins (`McpTrustPins`, `UNPINNED_KEYS`). Foreign spellings normalised by `McpForeignTranslate`. Servers start once at launch (`Bootstrap::mcpClient()`).
- Transports (`McpClient::buildServer()`): `stdio` (`StdioMcpServer` over `sugarcraft/sugar-mcp`; `tools/call` unbounded by design), `http` (30 s Guzzle total timeout, OAuth bearer from `McpAuthStore`), `git` (in-process), `claude-mcp`. `sse` ABSENT.
- Tools only: resources, prompts and sampling ABSENT. Bridged tools are `mcp__<server>__<tool>` (`Tools/McpToolBridge`), gated like any tool.
- Stdio env inherits unscrubbed via `ProcessContainment::env()` by documented decision (→ 0.14-b).

### 8.5 Permissions (`src/Permissions/`)

- Modes (`PermissionMode`): `default`, `accept-edits`, `plan`, `auto`, `dont-ask`, `bypass-permissions`. Built-in default **`bypass-permissions`** (`Bootstrap::DEFAULT_PERMISSION_MODE`) (→ DEF-MODE). Precedence: `--permission-mode` > `SUGARCRUSH_PERMISSION_MODE` > `permissionMode` (user tier).
- `PermissionGate::decide()`: `rm -rf /`/`~` breaker → `permissionRules` (argument-scoped via `PermissionRule::matches()` / `matchesShellSubject()`, allow fails closed on `$(…)`, backticks, redirects) → mode evaluator. `auto` uses the regex `SafetyClassifier` with `STRIKE_THRESHOLD = 3` / `TOTAL_BLOCK_THRESHOLD = 20` → Ask; no LLM verdict (→ 5.11-1). Plan mode's command guard exists (`evaluatePlan`, `PLAN_READ_ONLY_COMMANDS`); the Plan→exit transition is in `AgentManager::PLAN_EXIT_MODES` (→ 5.7-1).
- **The TUI engine path cannot ask.** `Bootstrap::chat()` → `backend()` uses `consolePermissionPrompt=false`, so `EngineBackend::$permissionApprover` (set by `withPermissionApprover()`) stays null and `Runtime::settleAsk()` turns every Ask into a deny (→ 1.C-1, 1.C-2). Asks come from default/accept-edits/plan/auto modes, `ask` rules and exit-3 hooks. `-p` and daemons use `HeadlessPermissionPrompt`.
- Grants: exact-call session grants exist (`Chat::$permissionGrants`, `permissionGrantKey`; `Runtime::taskGrantMemoKey`); pattern grants are new (→ 1.C-2).
- `/permissions` is a read-only report.

### 8.6 Settings (`src/Config/`)

- Files, lowest first: `<root>/.sugar-crush/settings.json`, `settings.local.json`, `~/.sugar-crush/settings.json`, `~/.sugar-crush/config.json` (app-written: `provider`, `theme`, `layout`; `Bootstrap::writeUserConfig()`). Project layers apply only when `trustedProjectSettings` lists the root.
- `LayeredSettings::LAYERED_KEYS` (21, incl. `contextWindow`, `promptCache`, `extraBody`, `thinkingBudget`, `secretEnvAllowlist`); `PROJECT_TIER_KEYS` (5). `maxToolSteps`, `enabledSkills`, permission and `trustedProject*` keys are user-tier only.
- The Settings pane is a read-only readout (→ N-P3).

---

## 9. TUI surfaces steps extend

- Shell `App`: menu bar, five dockable panes (Files, Tools, Skills, Agents, Settings), layout persisted via `App::persistDock()`. Tab / Shift+Tab are `shell.pane-next` / `shell.pane-prev` (`Commands/KeyBindingRegistry`, drift-tested), so Shift+Tab is not free for a plan-mode toggle (→ 5.7-1).
- Files pane reads `App::$contextFiles`, which only the DORMANT `App/AppBuilder` sets, so it is empty on the engine path.
- Status bar: `Renderer::renderStatusBar()` composes `contextIndicator`, `spendIndicator`, `cacheIndicator` and the `statusLine` command segment.
- Permission modal (Veil y/n/a, `a` needs confirmation) serves only Command-backend calls today (→ 1.C-2).
- Sessions: tab strip (`Renderer::renderSessionTabStrip()`), Ctrl+Tab, Ctrl+R `SessionPicker`. `src/Tui/SessionTabs.php` is unreferenced.
- ABSENT: desktop notifications / bell on turn end (→ 5.14), external `$EDITOR`.

---

## 10. Remaining DORMANT inventory

Wire, do not delete.

| Subsystem | State | Step |
|---|---|---|
| `src/LSP/*` client | `LspTool` has a null client | 3.F |
| `SessionAffinity` trait | No caller passes an id | 0.13-a |
| Team: `TeamManager`, `Team`, `Teammate`, `Mailbox`, `TaskList`, `HookDispatcher`, Task* hook events | No constructor call | 4.4, 4.6-2 |
| `WorktreeManager`, `WorktreeConfig`, `PathJail(Config)`, `EngineBackend::withWorktreeRoot()`, `BashEscapeDenyHook` | Never constructed/called | 4.9 |
| Hook events `Stop`, `SubagentStop`, `SessionEnd`, `PreCompact` | No dispatch site | 3.D-1, 2.12 |
| `AgentManager::refuseCallOutsideGrant()` / `executeSubAgent()` | No live caller | 4.2 |
| `BackgroundSupervisor::reconnect()` | No caller; needs persisted state | 4.3-3 |
| `SessionMeta::$tasks`, `saveSessionMeta()` | Store-internal only | 3.C |
| `ProviderInterface::embeddings()` | No consumer | 5.3-1 |
| `ContextCompactor::compactSkills()`/`filterSkills()`, skill budgets | No Chat caller | 2.6 |
| Preset `model`/`permissionMode`/`effort`/`background`/`memory`/`isolation` | Parsed, never applied | 4.1-1, 4.3-1, 4.9 |
| Skill `allowed-tools`/`disallowed-tools`/`model`/`effort`/`context:fork`; command `model`/`subtask` | Parsed, never read | X-37a, 5.14 |
| Shell cmds `GroupInputCmd`, `CancelAgentCmd`, `ResumeAgentCmd`, `StopAllAgentsCmd`, `QuitAgentViewCmd` | Mapped to no-ops | P-D3 |
| `Chat::registerTool()`/`onToolCall()`, `Chat::executeAgents()` | No callers | — |
| `App/AppBuilder`, `App/CallToolCmd`, `ToolRegistry`, `Compactor`/`CompactedGroup`, `StreamingDirectoryLister`, `Session.php`, `Tui/SessionTabs`, `Skills/SkillDiscovery` | Unreferenced | — |
| `SkillRegistry::findForPrompt` | Deliberately unwired | — |

---

## Appendix — Quick file index

| Area | Files |
|---|---|
| Entry | `bin/sugarcrush` · `src/Cli/{ArgvParser,Bootstrap,NonInteractive,Subcommands,Help,HeadlessPermissionPrompt}.php` |
| UI models | `src/App/App.php` (shell) · `src/Chat.php` (conversation) · `src/Renderer.php`, `src/Tui/**` |
| Loop | `src/Backend/EngineBackend.php` (fork + step loop + frames) · `src/Runtime.php` (step, tools, gate, system prompt) · `src/Messages/HistorySanitizer.php` |
| Providers | `src/Providers/*` (+ `ToolCallParser/*`, `Concerns/*`) |
| Context | `src/Context/*` (EnvironmentBlock, RepoMapBlock, MemoryBlock, InstructionFileLoader, RuleLoader, ContextCompactor, CompactorConfig, ContextWindow, IdleCompactionPolicy, PromptFence, Sections/MaximsSection) · `src/Util/TokenEstimate.php` |
| Tools | `src/Tools/BuiltIn/*`, `src/Tools/Concerns/*`, `src/Tools/McpToolBridge.php` |
| Agents | `src/Agents/*` (AgentManager, AgentPresetRegistry, ForeignAgentPresetRegistry, AgentWorkerPool, AgentPoolConfig, EngineExecutor, SuspendedDelegations, Team*, Mailbox, TaskList, Worktree*) |
| Sessions | `src/Session/*` (EnhancedSessionStore, SessionLock, PromptHistory), `src/Sessions/*` (BackgroundSupervisor, BackgroundSessionRunner) |
| Extensibility | `src/Skills/*`, `src/Commands/*`, `src/Hooks/*`, `src/MCP/*`, `src/Permissions/*`, `src/Config/*`, `src/Memory/*`, `src/Workflows/*`, `src/Share/*`, `src/LSP/*` |
| Docs | `docs/{ARCHITECTURE,PROMPT_ENGINEERING,PERMISSIONS,MEMORY,SKILLS,HOOKS,MCP,COMMANDS,SETTINGS,ENVIRONMENT,WORKFLOWS,AGENTS_AUTHORING}.md`, `README.md` |
