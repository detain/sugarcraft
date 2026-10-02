# sugar-crush: feature baseline (verified against source, 2026-10-01)

**What this is.** A baseline of the `sugar-crush` PHP AI coding-agent TUI at `/home/sites/sugarcraft/sugar-crush`, for competitor comparisons. Every claim below was checked against `src/`, not just against `docs/`. Paths are relative to `sugar-crush/` unless they start with `/`. Line numbers come from the working tree on master @ `f2884ae7d`.

The code is unusually comment-heavy (`Chat.php` is 16,105 lines, `Cli/Bootstrap.php` is 8,042), so most of it was read with comments stripped.

**Status legend**

| Status | Meaning |
|---|---|
| **LIVE** | Reachable from `bin/sugarcrush`'s real interactive path: `Bootstrap::app()` → `Program(App)` → `Chat` → `EngineBackend` → `Runtime` → provider. |
| **PARTIAL** | Wired, but missing pieces or broken in a common configuration. |
| **DORMANT** | The code exists (and is usually unit-tested), but nothing on the live path reaches it. |
| **ABSENT** | Not implemented. |

**Doc caveat.** `docs/old/crush_feat.md` was written before a large wiring push. Many of its "built but never wired" items are now LIVE: root `CLAUDE.md`/`AGENTS.md` loading, the `<env>` block, skills, the session store and picker, mouse, candy-mosaic images, `-p` non-interactive mode, session titles, streaming tool calls on SGLang/Custom, and per-call `description`. Where an old finding still holds, it says so.

---

## 0. Top-line facts for comparison agents (read this first)

1. **The interactive tool loop runs in a forked child, one per user turn.**
   - `EngineBackend::completeAsync()` (`src/Backend/EngineBackend.php:1324-1554`) `pcntl_fork()`s.
   - The child runs the agent loop (`runTurn()`, `:776-1024`): at most `maxSteps` iterations of `Runtime::run()`.
   - The child streams length-prefixed `serialize()`d frames back over a UNIX socketpair: `token`, `reasoning`, `started`, `finished`, `subagent`, `spend_cap`, `result`.
   - Without `pcntl` it falls back to a blocking in-process call (`completeAsyncBlocking`, `:1998`).
2. **Default permission mode is `bypass-permissions`** (`src/Cli/Bootstrap.php:166`). If you pick a stricter mode, an `Ask` verdict on the TUI engine path **cannot be answered**: there is no approver, so it is denied (`Runtime::settleAsk()`, `src/Runtime.php:2629-2669`; `docs/PERMISSIONS.md:199-223`).
   - The y/n/a modal in `Chat` only serves "Chat-native" tool calls (`Chat::beginToolCalls()`, `src/Chat.php:2635`), which only Command backends produce.
   - Interactive approval works only on the one-shot `-p` path, through `HeadlessPermissionPrompt` on stderr.
3. **Tool calls are not replayed as structured tool calls across turns.**
   - `Chat` stores each finished engine tool call as an **Assistant-role row whose content is the raw tool output** (`Chat::toolResultMessage()`, `src/Chat.php:4017-4023`).
   - `EngineBackend::toTypedMessages()` (`src/Backend/EngineBackend.php:2071-2082`) maps rows by role only: user → `UserMessage`, assistant → `AssistantMessage(content)`, anything else → `SystemMessage`.
   - So on the next turn the model sees earlier tool outputs as plain assistant text, with no `tool_calls`/`tool_call_id` pairing and no arguments.
   - Inside a single turn, structured tool calls/results are kept properly (`EngineBackend.php:978-982`, `Messages/HistorySanitizer.php`).
4. **Context compaction happens only between user turns, in `Chat::submit()`**, never between steps of a turn.
   - Token counts are a chars/4 + 10-per-message estimate, calibrated against provider-reported prompt tokens.
   - Thresholds: 70% reminder, 85% compact (LLM summary when a provider is configured), 95% block or truncate.
5. **The OpenAI provider cannot call tools on the live path.** `OpenAIProvider::supportsStreaming()` is `true`, so `Runtime` uses `runStreaming()`, and `OpenAIProvider::parseChunk()` hard-codes `toolCalls: null` (`src/Providers/OpenAIProvider.php:488-507`).
   - The `anthropic` provider type is an OpenAI-shaped `CustomProvider` with function calling **off** (`src/Providers/ProviderFactory.php:663-694`).
   - The tool-capable providers are `sglang` (the primary target, defaulting to DeepSeek-V4-Flash), `custom` (OpenAI-compatible), `bedrock`, `vertex` (Anthropic and Gemini routes), and `claude-code`, which shells out to the `claude` CLI and uses Claude Code's own tools.
6. **Several notable subsystems are DORMANT:**
   - the LSP client (the `Lsp` tool is registered with no client, so every call errors)
   - `CacheBreakpoints` (explicit Anthropic `cache_control`)
   - `ToolRegistry`
   - `Compactor` (a file-listing helper, not context compaction)
   - `AppBuilder`
   - `StreamingDirectoryLister`
   - session-affinity headers
   - user attachments and images *to* the model
   - `BashEscapeDenyHook` and worktree jails on the main loop

   See §11 for the full list.

---

## 1. Architecture overview

### 1.1 Entry point → TUI loop (LIVE)

`bin/sugarcrush` (454 lines, mostly comments) does the following:
- Loads the autoloader. If it is missing, it prints an error and exits 2; with `--output-format json` it also emits a JSON error document.
- Parses argv with `Cli\ArgvParser::parse()`.
- Handles `--help` (`Cli\Help::screen()`) and `--version`, and turns usage, root and config errors into `NonInteractive::failUsage()` with exit 2.
- Applies launch overrides: `Bootstrap::useConfigPath/useModel/usePermissionMode/useSessionLaunch` (`--config`, `--model`, `--permission-mode`, `-c/--continue`, `--resume [id]`).
- Dispatches in this order:
  1. a subcommand → `Cli\Subcommands::dispatch()`: `doctor`, `models`, `session list|delete`, `mcp list|auth|import`, `completion bash|zsh|fish`
  2. `-p/--prompt` or `run "<prompt>"` → `Cli\NonInteractive::run()`. This is a synchronous in-process `EngineBackend::complete()` (`src/Cli/NonInteractive.php:259`) with `HeadlessPermissionPrompt` as the approver; output is `text|json`.
  3. otherwise the interactive TUI:
     ```php
     (new Program(Bootstrap::app($args->root), Chat::programOptions()))->run();
     ```
     `Chat::programOptions()` turns on the alt screen, mouse (unless `SUGARCRUSH_DISABLE_MOUSE`) and bracketed paste (`src/Chat.php:6185-6199`). On exit it pops the kitty keyboard protocol.

`Program` is candy-core's Elm-style runtime (ReactPHP loop, `init/update/view`, `Cmd`, `Subscriptions`).

`Cli\Bootstrap::app()` (`src/Cli/Bootstrap.php:2372-2440`) does this:
- Calls `Bootstrap::chat($root)` (`:992-1450`), which:
  - arms `RuntimeNoticeSink`;
  - reads the layered user config and configures `StatusLineCommand`;
  - opens or creates the session (`sessionStore()` + `openSession()`);
  - builds the `SkillRegistry`, `PermissionGate`, `CommandLoader`, `RulesState`, `AgentManager`, `AgentPoolConfig`/`AgentWorkerPool` and the **backend**;
  - constructs `Chat` with `memoryStore`, `sessionStore`, `titleBackend`, `summaryBackend`, `maxCostUsd`, `themeName`, `mosaic`, `promptHistory`, `hooks`, `BackgroundSupervisor`, `workflowEngine`, `commandLoader`, `rulesState`, `streaming: true`.
- Wraps the `Chat` in `App::new($provider, $model)->withChat(...)->withTools(...)->withAvailableSkills(...)->withDock(...)`.
  - `App` (`src/App/App.php`, 2,295 lines) is the root model: the pane shell with menu bar, dockable panes, focus cycling, pane drag/resize and the skill picker.
  - It delegates the conversation to `Chat` (`App::delegateToChat()`, `:2057`).
  - `App` is *also* the engine's per-step state object that `Runtime::run(App $app, …)` takes (README "Architecture" section).

`Chat` (`src/Chat.php`, 16,105 lines) is the conversation model:
- input `TextArea`, transcript, slash and palette dispatch, permission modal, session picker, compaction, spend accounting;
- persistence: `Chat::persistTranscript()`, called after every history-changing update (`:1512`);
- subscriptions that poll the tool-event inbox while a turn is in flight (`ToolEventPumpMsg`), background sessions (`BackgroundTickMsg`), runtime notices, and the status-line command (`Chat::subscriptions()`, `:14368-14465`).

Rendering:
- `src/Renderer.php` is a buffer-diff renderer for the transcript, status bar, session tab strip (`renderSessionTabStrip`, `:1367`) and diff gutter (`:3681`).
- `src/Tui/Renderer.php` plus `src/Tui/Components/*` draw the shell chrome.

### 1.2 Backend selection (LIVE) — `Bootstrap::backend()` (`src/Cli/Bootstrap.php:2687-2819`)

First match wins:
1. `$SUGARCRUSH_PROVIDER` → `backendFor($name)`. If that fails, it falls back to echo and adds a transcript warning.
2. `$SUGARCRUSH_BACKEND_CMD` → `Backend\CommandBackend`: one shot of an external command per turn, history on stdin.
3. `$SUGARCRUSH_BACKEND_CMD_STREAM` → `Backend\StreamingCommandBackend`: a streaming line protocol.
4. `provider` persisted in `~/.sugar-crush/config.json` → `backendFor()`.
5. `EngineBackend(EchoProvider, 'echo')`, an offline echo with the full tool set.

`backendFor()` (`:2852-2915`) builds an `EngineBackend` that carries:
- `ProviderFactory->create(defaultConfig(name))`
- the model from `--model`/`SUGARCRUSH_MODEL`, else the provider default
- `tools()`, `hooks()`, `permissionGate`, `SkillRegistry`
- `promptEnabledSkills`, `InstructionFileLoader`, `root`, `MemoryStore`
- `maxSteps` from the `maxToolSteps` config key (default **8**, `EngineBackend.php:262`)

Two backend families, two tool pipelines:
- **`EngineBackend`** (`src/Backend/EngineBackend.php`) runs tools inside `Runtime`.
- **Command backends** return JSON-shaped tool calls that `Chat` runs itself: `Chat::beginToolCalls()` → `gateToolCall()` → `forkToolCalls()` (`src/Chat.php:2635`, `:4182`, `:4272`). That is the only path with the interactive y/n/a modal.
- `Chat::registerTool()`/`onToolCall()` (`:6987`, `:7000`) have no callers in `src/`.

### 1.3 Providers

`Providers\ProviderFactory::availableTypes()` returns `openai, anthropic, claude-code, sglang, bedrock, vertex, custom` (`src/Providers/ProviderFactory.php:338-341`). Named provider configs can also come from the package's `.sugar-crush/config.dev.json` `providers{}`. `${VAR:-default}` interpolation is applied (`:307-331`).

| Type | Class / wire | Streaming | Tool calls in the live loop | Reasoning | Context window | Status |
|---|---|---|---|---|---|---|
| `sglang` (primary) | `SglangProvider`, OpenAI-compatible `chat/completions` on SGLang. Default model `deepseek-ai/DeepSeek-V4-Flash-0731` (`SglangProvider.php:93`) | yes | yes. Structured `delta.tool_calls` buffered by index (`resolveStreamedToolCalls` `:2038`) **plus** textual fallback parsers selected by `toolCallParser`: `openai`, `minimax-xml-fallback`, `dsml` (auto-chosen for DeepSeek-V4, `ProviderFactory.php:951-957`). Truncated calls are flushed (`flushTruncatedToolCalls` `:2110`) | `separate_reasoning`, `reasoning_effort` and `chat_template_kwargs` (`enable_thinking`, effort translation for Qwen3.8) (`:1012-1090`). Per-family defaults: DeepSeek-V4 temp 1.0 / top_p 0.95 agentic / effort `max` | DeepSeek-V4 1,048,570; Qwen3.8 744,506; legacy 196,608 | LIVE |
| `custom` | `CustomProvider::openAiCompatible()`, Guzzle `chat/completions` | yes (SSE parsed by hand) | yes, `delta.tool_calls` buffered and emitted on `finish_reason=tool_calls` (`CustomProvider.php:610-634`). No textual parser | `ReasoningExtractor` (`reasoning_content`) | fixed 128,000 (`:148`) | LIVE |
| `openai` | `OpenAIProvider` (openai-php client) | yes | **NO**. `parseChunk()` hard-codes `toolCalls: null` (`OpenAIProvider.php:488-507`) and `Runtime` always streams when `supportsStreaming()` is true, so tool calls are dropped. `complete()` (batch) does parse them, but it is never used. README "Limitations" admits this | `ReasoningExtractor` | per model | PARTIAL (chat only) |
| `anthropic` | `CustomProvider('anthropic', baseUrl, …, supportsFunctionCalling: false)` with an `x-api-key` header (`ProviderFactory.php:663-694`) | yes | **NO**: function calling is off, and it posts the OpenAI `chat/completions` shape to `https://api.anthropic.com` with no `/v1`. README says to use `claude-code` instead | — | 128,000 | PARTIAL / likely broken |
| `claude-code` | `ClaudeCodeProvider`, which shells out to `claude -p --output-format json/stream-json --system-prompt … --allowedTools <names>` (`ClaudeCodeInvocation.php:42-80`, `ClaudeCodeProvider.php:74-150`) | yes (stream-json) | Claude Code's **own** tools run inside the `claude` process. sugar-crush tools, hooks and gate do not apply to them | from Claude Code | 200,000 | LIVE (delegating) |
| `bedrock` | `BedrockProvider` (AWS SDK). System goes out as `system` blocks (`:363`) | per model | yes (Converse) | — | per model | LIVE (code; untested live per README) |
| `vertex` | `VertexProvider`: `rawPredict` for Anthropic models, `generateContent` for Gemini (`:284-330`) | Anthropic route | yes | Anthropic blocks | 200,000 | LIVE (code) |
| `echo` | `EchoProvider` (offline fallback) | — | — | — | 100,000 fallback (`Context/ContextWindow.php:52`) | LIVE |

Cross-cutting provider behaviour:
- **Retries.** `Runtime::runStreaming()`/`runBatch()` retry transient failures up to `TransientFailure::MAX_ATTEMPTS = 3`, with exponential backoff from `BASE_BACKOFF_MICROSECONDS = 500_000` (`src/Providers/TransientFailure.php:165-174`; `src/Runtime.php:1324-1445`, `:1496-1515`). A streamed attempt is **never** retried once any token has been emitted to the UI.
- **Timeouts.**
  - Guzzle connect timeout is 15 s (`SUGARCRUSH_CONNECT_TIMEOUT`).
  - The stream read-idle timeout is 3600 s. There is **no total request timeout** (`Providers/Concerns/HttpClientDefaults.php:116-275`).
  - Separately, `EngineBackend` kills a turn after **120 s with no frame** from the forked child (`COMPLETE_TIMEOUT_SECONDS`, `EngineBackend.php:99`, `:1444-1455`). The timer is re-armed by every token, reasoning, heartbeat or event frame. Guzzle `progress` callbacks emit heartbeats at most once per second (`HttpClientDefaults::heartbeatOptions`, `:195`).
- **Output tokens.** `max_tokens` defaults to 4096 in Custom/OpenAI when the `maxOutputTokens` config key is unset (`CustomProvider.php:164`, `OpenAIProvider.php:158`). Temperature defaults to 0.7, except for the SGLang per-family defaults.
- **Usage and cost.**
  - Usage is folded per step and summed per turn (`Usage::sum`) into `Util\TokenTracker`.
  - Cost needs prices. `OpenAIProvider` takes the `modelPrices` config. Sglang, Custom and ClaudeCode report $0.
  - A spend cap comes from `SUGARCRUSH_MAX_COST` or `/budget`. It is enforced between steps (`EngineBackend.php:922-940`, `SpendCapBreached`) and before turns (`Chat::spendCapRefusal()`, `:15109`).
- **Session-affinity header** `X-SugarCrush-Session` (`Providers/Concerns/SessionAffinity.php:65`) is supported by Sglang and Custom. No factory or bootstrap caller passes `sessionAffinityId`, so it is DORMANT.
- **Embeddings.** `ProviderInterface::embeddings()` exists, but nothing on the live path calls it.

### 1.4 How a turn executes (LIVE unless noted)

1. **Submit** (`Chat::submit()`, `src/Chat.php:7150-7472`).
   - If a turn is in flight, typed prompts are **queued** (`enqueuePrompt`, `:7533`; released after the turn by `releaseQueuedPrompts`, `:7778`). Slash commands are refused mid-turn, except `/exit`.
   - Otherwise, in order:
     1. expand custom commands (`expandCustomCommand`, `:8031`), else `dispatchCommand` (`:8240`)
     2. spend-cap refusal
     3. idle-compaction prompt (after 3,600 s idle, `Context/IdleCompactionPolicy.php:43`)
     4. threshold compaction (§3)
     5. `UserPromptSubmit`/`SessionStart` hooks (`dispatchTurnHooks`, `:4581`)
     6. `Message::user($text)`
2. **Dispatch** (`Chat::dispatchTurn()`, `:7869-8002`).
   - Optionally appends the 70% context reminder.
   - Saves a per-turn checkpoint (`EnhancedSessionStore::saveCheckpoint`).
   - Schedules `scheduleBackendCompletion()` (`:9226`) and, on the first turn of an unnamed session, `scheduleTitleGeneration()` (`:9011`).
   - `scheduleBackendCompletion()` wraps `EngineBackend::withSpendCap()`. It gives the backend a shared `ArrayObject` inbox that collects `TokenDelta`, `ReasoningDelta`, `ToolStarted/ToolFinished`, `SubAgentActivity` and `SpendCapBreached`. A 'tool event pump' subscription drains it into the transcript live.
3. **Fork** (`EngineBackend::completeAsync()`, `EngineBackend.php:1324-1554`).
   - Uses `pcntl_fork` with a `stream_socket_pair` and length-prefixed `serialize()` frames, max 64 MiB per frame (`:218`, `:1742-1796`).
   - The child calls `complete()` → `runTurn()` and exits with `ForkedChild::exitNow`.
   - The parent settles a ReactPHP `Deferred` from the `result` frame.
4. **Agent loop** (`EngineBackend::runTurn()`, `:776-1024`).
   - Each turn builds a **new `Runtime`**. Parallel-tool flags and `maxOutputTokens` come from user config.
   - It builds an `App` state object with the tools, the enabled and available skills, the instruction loader, root, memory store, rules state and the typed messages.
   - Then: `for ($step = 0; $step < maxSteps; $step++)` → `Runtime::run()`.
   - The loop ends when:
     - a step returns **no tool results** (a normal answer);
     - the spend cap is crossed; or
     - steps run out, which sets `stepsTruncated` and appends a transcript notice (`Chat::stepsTruncatedNotice()`, `:15931`).
   - The reply is the **last assistant content only**, plus usage, the last image, `lengthStopped` and `stepsTruncated` (`:1017-1023`).
5. **One step** (`Runtime::run()`, `src/Runtime.php:1185-1228`).
   - Builds messages through `Messages\HistorySanitizer::sanitize()`. That drops orphan tool results, drops empty assistant rows, and synthesises `"Tool call interrupted by restart"` error results for unanswered calls (`HistorySanitizer.php:80-140`).
   - Builds the system prompt sections (§4) and a `CompleteRequest` (`model, messages, tools, systemPrompt, systemBlocks, maxTokens, onHeartbeat`).
   - Streams (`runStreaming`) or batches (`runBatch`), then yields an `AssistantMessage` and executes its tool calls.
6. **Tool execution** (`Runtime::executeToolCalls()`, `:1650-1745`).
   - Calls are split into **segments**: a run of consecutive `ParallelSafe` tools (Read, Glob, Grep, WebFetch, WebSearch, Task) becomes one concurrent group. Every other call runs alone, in the order requested.
   - Concurrent groups `pcntl_fork` one child per call (`executeConcurrently`, `:1853`). Results come back through temp files (`Support\ToolIpcFiles`).
   - Group deadline: 90 s (`PARALLEL_TOOL_DEADLINE_SECONDS`; config `parallelToolDeadlineSeconds`, env `SUGARCRUSH_PARALLEL_TOOL_DEADLINE`). Task is exempt.
   - Parallelism can be disabled with `SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS` or `parallelToolCalls:false` (`EngineBackend.php:1114-1124`).
   - Each call passes through `Runtime::gate()` (`:2129-2197`), which runs `HookManager::preToolUse()` in this order: built-ins (`ProtectFilesHook`, `ConfirmRemoveHook`, `AuditHook`) → `hooks.yaml` scripts → `PermissionGateHook`.
     - Verdicts: allow, deny, modify (rewritten args) or ask. Ask goes to `settleAsk()`; in the TUI there is no approver, so it is **denied** (see §9).
     - Then the tool executes and `PostToolUse` runs (`settle()`, `:2304`).
   - `Runtime::markWriteSinceLastRender()` records whether the step requested a write (`Bash`, `Edit`, `Write`, `Task`, `mcp__*`; `:566`, `:1060-1081`). It decides whether the next `<env>` block includes git diffs (§4).
7. **Return to the UI.** `Chat` receives `BackendToolEventsMsg`/`AssistantMsg`.
   - Running placeholders (System-role rows) become result rows (Assistant-role rows carrying `toolResults`) (`Chat.php:3534-4023`).
   - The final assistant message is appended, then usage is accounted and the token-estimate calibration updated (`turnEstimateObservation`, `:14944`).
   - A prompt suggestion is scheduled on the title backend (`schedulePromptSuggestion`, `:9144`; disabled by `SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS`).

**Cancellation / interrupt.**
- `Esc Esc` within 0.6 s (`Chat.php:2056-2083`) cancels the turn's `CancellationToken`, bumps `generation` so late frames are dropped, and appends a `_Request cancelled._` system row. A 0.1 s periodic timer in the backend notices the cancel and runs `teardown()`, which SIGKILLs the turn child (`EngineBackend.php:1396-1465`). Tool rows that already streamed stay in the transcript.
- Per-call interruption, or resuming a partially executed turn, is not supported. Interrupted calls surface as "interrupted" rows. Prompts typed mid-turn wait in the queue.
- **Mid-turn steering is ABSENT.** A queued prompt only goes out after the turn ends.

**Not present in the loop:**
- no "plan then act" phase;
- no automatic lint/test after edits (the base prompt tells the model to run tests itself);
- no tool-output pruning between steps (§3);
- no per-turn wall-clock cap besides the 120 s *idle* watchdog. Note that a sequential Bash call that stays silent for 120 s gets the whole turn killed, because sequential tools send no heartbeat (fork-verified by reading; `Runtime::executeSequentially`, `:1748`).

---

## 2. Agents, sub-agents, background sessions and workflows

### 2.1 Agent definitions and presets

**The roster is LIVE.** It is assembled by `Bootstrap::chat()` → `Bootstrap::agentManager()` (`src/Cli/Bootstrap.php:1718`) → `agentRoster()` (`:1919`). Three layers apply, lowest precedence first (`:1930-2008`):

1. **Foreign imports**, via `ForeignAgentPresetRegistry::discover()` (`src/Agents/ForeignAgentPresetRegistry.php:203-205`):
   - Claude style: `~/.claude/agents`, `<root>/.claude/agents` (`:287-288`)
   - opencode style: `~/.config/opencode/agents`, `<root>/.opencode/agents` (`:301-302`)
   - When both define the same agent, Claude beats opencode.
   - opencode `permission.*` maps are collapsed into tool lists, with a warning.
2. **Six built-in definitions**: `coder`, `reviewer`, `debugger`, `architect`, `tester`, `devops` (`src/Agents/AgentDefinition.php:9-14`). Examples of their default tools:
   - coder: `['Read','Edit','Bash']` (`:49`)
   - reviewer: `['Read','Grep','Bash(git *)']` (`:86`)
3. **Native presets**: `<root>/.sugar-crush/agents/*.md` and `~/.sugar-crush/agents/*.md` (`Bootstrap::agentPresetTiers()`, `:2319-2339`).
   - Each tier is anchored: a symlink that escapes the anchor is refused.
   - A preset with no prompt inherits the prompt of the definition it shadows, with an attribution line (`:1988-2005`, `:2059`).
   - The repo ships `/home/sites/sugarcraft/.sugar-crush/agents/{coder,reviewer,security-auditor}.md`.

**File format.** Markdown with YAML frontmatter; a file without frontmatter is refused (`src/Agents/AgentPresetRegistry.php:355-406`, DTO `AgentPreset.php:21-38`). Fields:
- `name`, `description`
- `tools`, `disallowedTools` (PermissionRule patterns such as `Bash(git *)`)
- `model` (`inherit` is accepted)
- `permissionMode`, `maxTurns`, `skills`, `mcpServers`
- `memory`, `background`, `effort`, `isolation`, `color`
- `initialPrompt`; when absent, the markdown body is the prompt

The authoring guide is `docs/AGENTS_AUTHORING.md`.

**Which fields actually take effect at runtime:**

| Field | Status | Evidence |
|---|---|---|
| prompt/body, `skills` (each skill's prompt contribution is appended) | LIVE | `AgentManager::resolveBatchSystemPrompts()` `src/Agents/AgentManager.php:1771-1813`. An unresolvable skill name **throws, and the Task call is refused** (`:1796`). The repo's own `coder.md` lists `php-pro` and `code-philosophy`, which are not built-ins, so whether it loads depends on the host's foreign skills (unverified). |
| `tools` / `disallowedTools` | PARTIAL | `resolveGrantedTools()` (`:1103-1260`) filters **by tool name only**. `Bash(git *)` grants all of Bash, and argument-scoped deny rules are skipped (`:1154`). Argument checking lives in `refuseCallOutsideGrant()` (`:1419`), which is reachable only from `executeSubAgent()`, and that has no production caller. With no `tools:` declared the grant is null: the agent gets the full engine tool set and `disallowedTools` is ignored (`:1109-1120`). |
| `mcpServers` | LIVE | Filters the MCP bridges in the grant (`:1235-1236`). |
| `maxTurns` | LIVE | Step cap, default 50 (`src/Tools/BuiltIn/TaskTool.php:135,453`). |
| `model` | DORMANT on the live path | `TaskTool::runOnEngine()` reuses the parent EngineBackend's provider and model (`TaskTool.php:557-561`). The field is only honoured on the non-engine fallback (`:365-369`). |
| `permissionMode` | DORMANT | Stored on `Agent` (`Agent.php:283-285`) but never read. Sub-agents run under the parent session's hook chain and gate. |
| `effort`, `background`, `memory`, `isolation`, `color` | DORMANT | Nothing outside the DTOs reads them. `isolation` is read only for workflow tasks (`WorkflowEngine.php:1096`). |

Narrowed-grant launch warnings are LIVE: a preset that names a tool the config removed gets a notice (`AgentManager::narrowedGrantWarnings()` `:152-196`). `/agents` and `/agent <name>` (and Ctrl+A) are LIVE but **inspect-only** (`src/Commands/AgentsCommand.php:83-162`).

### 2.2 The Task tool (LIVE)

Task is constructed by `Bootstrap::tools()` whenever an AgentManager is passed (`Bootstrap.php:6748-6749`). Every turn it is re-bound to the running engine by `EngineBackend::turnTools()` (`EngineBackend.php:1049-1087`) through `DelegatesToEngine`.

**Arguments** (`TaskTool.php:240-277`):
- required: `description` (a 5-10 word label), `prompt` (must be self-contained), `agent` (a roster name)
- optional: `subagent_type` (alias of `agent`), `resume` (an id)
- An unknown agent is refused, and the refusal lists the roster (`:315-320`).
- `promptGuidance()` injects orchestration rules (batch independent Tasks in one message; keep them on disjoint files) plus the live roster (`:226-234`).

**Execution** (`runOnEngine`, `TaskTool.php:430-630`):
1. `AgentManager::createSubAgent()` registers a row.
2. Tools = the grant (or the full set), **minus every `DelegatesToEngine` tool**. Task cannot recurse; **depth is fixed at 1** (`:449-452`).
3. Messages = `[SystemMessage(preset prompt + skills), UserMessage(task)]`. The harness system prompt (§4) is also built for the sub-turn.
4. The sub-agent runs **synchronously** inside the tool call: `$engine->withTools()->withMaxSteps($maxTurns)->completeTranscript()` (`:557-592`). It reuses the parent's provider, model, hooks, gate, root, instruction loader, memory store and spend cap.
5. **The parent model gets only the sub-agent's final text** — no transcript, no diff. Usage is added to the sub-agent row (`:604-629`).
6. `ParentProcessGuard` kills the sub-agent if the parent turn dies (`:464-471`).

**Parallel sub-agents — LIVE.** Task is `ParallelSafe` (when bound) and `ExemptFromParallelDeadline`. Several Task calls in one assistant message run in forked children, **one per call with no concurrency cap** (`Runtime::executeConcurrently`, `src/Runtime.php:1853`). This needs pcntl and `parallelToolCalls`. `AgentPoolConfig::maxConcurrent=5` (`src/Agents/AgentPoolConfig.php:22`) applies to workflows only, not to Task.

**Resume — LIVE.** A sub-agent that fails, is interrupted, or ends without a report is resumable:
- Its transcript is serialised to `sys_get_temp_dir()/sugarcrush-suspended-delegations/<16hex>.run` (mode 0600, 7-day expiry, unserialize restricted to allow-listed classes; `src/Agents/SuspendedDelegations.php:42-111`).
- The failure text tells the model to call Task again with `"resume": "<id>"`. The sub-agent then continues its own conversation (`TaskTool.php:323-345`, `:638-651`).

**Live TUI telemetry — PARTIAL.**
- `SubAgentActivity` events (start, progress at most once per second with a 4 KB tail of `-> Tool` / `<- Tool` / `thinking:` lines, then finish; `TaskTool.php:488-555`) cross the fork socket as `subagent` frames (`EngineBackend.php:1880-1889`).
- `Chat` projects them through `AgentManager::projectRemoteSubAgent()` (`src/Chat.php:3569-3577`) into `AgentsPane` / `AgentDashboardPane`.
- **For parallel batches no dashboard rows appear.** The emitter is pid-bound (`EngineBackend.php:1068-1077`), and concurrent Tasks run in grandchildren, so only the ToolStarted/ToolFinished pair shows.

**Cancellation — PARTIAL.**
- Esc Esc kills the whole turn, and the orphan guards take sub-agents with it.
- Per-agent cancel, resume, stop-all, quit-view and group-input bindings are **inert**. `KeyboardHandler` emits `CancelAgentCmd`, `ResumeAgentCmd`, `StopAllAgentsCmd`, `QuitAgentViewCmd` and `GroupInputCmd` (`src/Tui/KeyboardHandler.php:652-669,845`), but `App::consumeShellCmd()` maps them to no-ops (`src/App/App.php:1700-1729`; README "Limitations").

**Fallback path DORMANT.** The non-engine path (`AgentManager::executeAll()` → `AgentWorkerPool`, `TaskTool.php:365-420`), `Chat::executeAgents()` (`Chat.php:6411`) and `AgentManager::executeSubAgent()` (`:678`) have no live callers.

### 2.3 Parent ↔ child communication mid-run

**ABSENT on every live path.**
- A sub-agent is a blocking tool call. The parent cannot message it, steer it, or read partial results.
- The user cannot type to a running sub-agent (`GroupInputCmd` is an empty class).
- The only shared state is the working tree, the memory store and the hooks.

**Team infrastructure is DORMANT.**
- What exists:
  - `TeamManager` / `Team` / `Teammate`
  - `Mailbox`: a JSONL inbox with `send`, `receive`, `peek` and `waitForMessage` (`src/Agents/Mailbox.php:37-222`)
  - `TaskList`: a SQLite task board with claim/release, dependencies and `getUnblockedTasks` (`src/Agents/TaskList.php:98-499`)
- Nothing in `src/` constructs a `TeamManager` or calls `AgentManager::setTeamManager()` (`:1903`). `createTeam()` throws "TeamManager has not been set" (`:1929`).

**ABSENT:** no TodoWrite or todo/plan tool, and no shared scratchpad tool.

### 2.4 Background sessions (`/bg`, `/fork`, `/branch`)

**`/bg <task>` (alias `/background`) — LIVE.**
- `Chat::handleBackgroundCommand()` (`Chat.php:12073`) → `scheduleBackgroundSpawn()` (`:12177-12221`) → `BackgroundSupervisor::spawnSession()` (`src/Sessions/BackgroundSupervisor.php:186`).
- The spawned process `proc_open`s a PHP bootstrap, double-forks, calls `posix_setsid`, and runs `BackgroundSessionRunner::main()`. It talks back over a Unix socket with a token handshake, with stdin set to `/dev/null`.
- The daemon builds its backend with `Bootstrap::backendFor(..., consolePermissionPrompt: true)` (`BackgroundSessionRunner.php:580-610`), so an Ask is refused and logged.
- **The task runs with no conversation history**: `complete([Message::user($task)])` (`:388-395`).
- The agent used is the roster's `default` entry, or the first entry (`Chat.php:12198-12241`).

**`/fork <prompt>` — PARTIAL.** It forks the stored session (`Chat.php:12125`), but the fork id is passed only as a tag. The daemon still runs just the single prompt, without the cloned conversation.

**`/branch` — LIVE.** It forks the session in the store and switches to the fork, synchronously and with no agent involved (`Chat.php:12023-12057`).

**Results — PARTIAL.**
- A `BackgroundTickMsg` subscription (`Chat.php:14373`) drives `pumpBackgroundSessions()` (`:14490-14536`), which appends a system notice on each status change.
- Streamed output is shown in `AgentDashboardPane` (`:142`, `:430`).
- **The final answer is never injected into the chat**; it stays in the session's buffer and log files.
- Stall detection runs off a 15 s heartbeat (`BackgroundSupervisor.php:55,699`).

**Re-adoption after a TUI restart — DORMANT.** `BackgroundSupervisor::reconnect()` (`:851`) has no caller. Daemons outlive the TUI but are not re-attached.

### 2.5 Workflows

**`/workflow run|pause|resume|status|list` — LIVE** (`Chat::handleWorkflowCommand`, `Chat.php:9454`). The engine comes from `Bootstrap::workflowEngine()` (`Bootstrap.php:1453-1551`), and Chat binds its AgentManager and EngineBackend into it (`Chat.php:1339-1351`).

**Discovery** (`src/Workflows/WorkflowRegistry.php`, `docs/WORKFLOWS.md`):
- project: `<root>/.sugar-crush/workflows/*.yaml`, YAML only, consulted first
- user: `~/.sugar-crush/workflows/*.{yaml,php}`
- The bundled `sugar-crush/workflows/deep-research.php` and `examples/workflows/lint-then-fix.yaml` are **not on any search path**.

**Format.**
- YAML keys: `name`, `description`, `stages[]` (each either `agent`/`prompt`/`tools`, or `parallel: true` + `agents[]`), and `config.maxConcurrent` / `config.timeout`.
- Interpolation: `{{var}}` and `{{stage.output}}`.
- The PHP DSL (`WorkflowBuilder`, `Tasks::agent()`) adds `pipeline()` and `withVerification()` stages (`WorkflowEngine.php:1132,1266`).

**Execution.**
- A stage's `agent:` is only a label; it does not load a preset. `prompt` is the whole instruction (`WorkflowEngine.php:1077-1088`).
- Each stage runs through `AgentManager` → `AgentWorkerPool` (forked), with `EngineExecutor` bound to the chat's engine (`src/Agents/EngineExecutor.php:153-220`). That means the full tool loop, Task withheld, a 50-step cap, and the same hooks and gate.
- Progress streams into the live-agent pane through a Fiber stepped by a loop timer (`Chat::driveWorkflowFiber`, `Chat.php:9726-9759`).
- A real provider is required; the echo provider is refused.

**Limits.**
- Only the first task of a stage runs (`WorkflowEngine.php:1069`).
- Pause and resume work per whole stage, with pause files in `<workflowsPath>/.running/*.json`.
- **`/workflow resume` runs synchronously in the update loop** (`Chat.php:9800-9809`).
- Double-Escape releases the UI but does not stop the run.

### 2.6 Worktree isolation and teams

- **`WorktreeManager`, `WorktreeConfig` and `PathJail(Config)` are DORMANT.** `src/Agents/WorktreeManager.php` (1,368 lines) does a git worktree per agent with `.worktreeinclude` copying and a cleanup policy, but nothing constructs it.
- `EngineBackend::withWorktreeRoot()`, the only registrar of `BashEscapeDenyHook`, has no caller.
- `worktreeCleanupPeriodDays` and `worktreeIncludeFile` in `/home/sites/sugarcraft/.sugar-crush/config.json` are read only by those dormant classes.
- The preset `isolation: worktree` setting is never acted on.
- The `worktree-workflow` built-in skill is prose guidance only.
- The tool-level `PathJail` used by the file tools is root containment, not per-agent isolation.

### 2.7 Changes since `docs/old/crush_feat.md`

- Background sessions are now spawnable with `/bg` and `/fork`; `reconnect()` is still uncalled.
- `Chat::subscriptions()` is no longer hard-coded to null.
- Foreign agent import is now LIVE.
- Task now runs the full engine loop, in parallel, with resume (it used to be single-shot with empty output).
- `AgentDashboardPane` exists.
- Teams, Mailbox, TaskList and WorktreeManager remain DORMANT. The old "multi-agent teams with mailboxes + isolated worktrees" claim should be read as built but unwired.

---

## 3. Context handling

### 3.1 What goes into each request (LIVE)

Each provider call is a `CompleteRequest` (`src/Providers/CompleteRequest.php`) with these fields:
- `systemPrompt` (string) and `systemBlocks` (the same text as an array of per-section strings) (§4)
- `messages`
- `tools` (every registered tool, as JSON-schema function definitions via `Providers/Concerns/ToolSchema.php`)
- `maxTokens`, `onHeartbeat`

**`messages` within a turn.**
- The Chat history converted by `EngineBackend::toTypedMessages()` (`EngineBackend.php:2071-2082`), plus this turn's structured `AssistantMessage(toolCalls)` / `ToolResultMessage` rows.
- Those rows are appended step by step (`:978-982`) and cleaned by `HistorySanitizer`.

**`messages` across turns** — the Chat history holds:
- user prompts
- final assistant replies
- **one Assistant-role row per finished engine tool call**, whose content is the full tool output, or `Tool error: …` (`Chat::toolResultMessage()`, `src/Chat.php:4017`)
- System-role rows:
  - launch notices, capped at 24 (`Bootstrap.php:168-193` says these are deliberately sent to the model)
  - compaction notices
  - `_Request cancelled._`
  - the spend-cap notice
  - running placeholders for tools that never finished
  - the context reminder
- `/websearch` results, injected as a user + assistant pair (`Chat.php:9998`)

All of these go to the provider. Roles map user → user, assistant → assistant, everything else → `system`. **Tool calls from earlier turns are therefore replayed as plain assistant text, with no arguments and no `tool_call_id` pairing.** That is cheaper in structure, but the model loses which tool produced which text.

**Attachments.** `Message::attachFile()`/`attachImage()` (`src/Message.php:247-267`) and `UserMessage::withFile()`/`withImage()` have **no callers**. No provider sends image parts, and every provider's `supportsVision()` returns false. User-side images and file attachments are therefore DORMANT/ABSENT.
- Images produced by tools are rendered in the TUI (candy-mosaic), but are not sent to the model.
- The only file-inclusion syntax is `@file` inside custom slash-command templates (§9).

### 3.2 Token counting and context-window tracking (LIVE)

- **Estimate.** `Chat::rawTokenProxy()` = Σ (`ceil(mb_strlen(content)/4)` + 10) over the history (`src/Chat.php:14734-14742`).
  - `ContextCompactor::countTokens()` uses the same formula (`Context/ContextCompactor.php:1189-1197`).
  - It ignores the system prompt and tool schemas.
- **Calibration.** After each turn, the provider-reported prompt tokens are divided by the estimate at dispatch time, clamped, and stored as `tokenEstimateCalibration`. `estimateTokenCount()` multiplies by it (`:14709-14717`, `:14944-14961`).
- **Window.** `Chat::contextTokenLimit()` = `ContextWindow::ofBackend($backend)`, i.e. the provider's `contextWindow()`, with a 100,000 fallback (`Context/ContextWindow.php:52-77`).
- **Display.** The status bar shows `~N tokens` and a percentage, plus a separate spend segment (README "What you see while a turn runs").

### 3.3 Compaction: when, how, what is kept

Thresholds come from `Context/CompactorConfig.php:67-77`: reminder 70%, background compaction 85%, foreground block 95%, `recentPreserveCount` 10, `toolOutputMaxChars` 2000, summary clip 80 (user) / 100 (assistant) characters.

All checks run **only in `Chat::submit()` when a new user message is sent** (`src/Chat.php:7239-7472`). Nothing compacts between the steps of a running turn: a single turn of up to `maxSteps` steps grows without bound until the provider rejects it.

| Trigger | What happens | Status |
|---|---|---|
| ≥70% | A system row (`Heads up: this conversation has grown to ~N…consider /compact`) rides along with the turn. Earlier reminders are stripped first (`dispatchTurn`, `:7882-7930`; `contextReminderMessage` `:15312`) | LIVE |
| ≥85%, provider configured (`summaryBackend` ≠ null, i.e. any real provider; `Bootstrap::summaryBackend()` `:7745`) | **Parked LLM compaction** (`scheduleParkedCompaction`, `:10975-11087`): the prompt is held, older exchanges go to a tool-less `EngineBackend` (`SUGARCRUSH_SUMMARY_MODEL` / `summaryModel`, else the provider default) using `COMPACT_SUMMARY_PROMPT` (`:10569`). The prompt asks for a per-exchange six-facet record: `asked / did / files / decided / corrected / error`. Prior summaries are passed back in for continuity. On `HistoryCompactedMsg` the summaries are spliced in (`applyModelCompaction`, `:11550`) and the parked prompt is sent | LIVE |
| ≥85%, no summary backend, or spend cap reached | **Heuristic compaction** (`ContextCompactor::compact()`, `:501-531`, see below). A `contextCompactedMessage` notice reports the savings | LIVE |
| Still ≥95% after compaction | `intraExchangeTruncation()` (`:15714`; `ContextCompactor::truncateOversizedExchange()` `:254`) head-truncates oversized messages in the newest exchange, with a marker. If that cannot fit either, the turn is **refused** (`foregroundBlockedResponse`, `:15574`); each retry drops the oldest preserved exchange. A thrash breaker stops repeated refill-compactions (`IdleCompactionPolicy::REFILL_LIMIT = 3`, `thrashBreakerRefusal` `:15270`) | LIVE |
| Idle for more than 1 h with large context | Offers compaction before sending (`idleCompactionPromptResponse`, `:15994`) | LIVE |
| `/compact` (manual) | LLM summaries when a summary backend exists (`scheduleModelCompaction`, `:10683`), else heuristic `compactNow` (`:10409`) | LIVE |

**Heuristic algorithm** (`ContextCompactor::stagePairs()` + `compact()`, `:501-591`):
1. Calls `removeToolResults()`. It filters `system` rows carrying a `tool_results` key, but `Message::toWire()` never emits that key and engine tool rows are `assistant`. *Inferred from reading; not run.* So this step is effectively a no-op on the live history.
2. Groups rows into user→assistant pairs (`groupIntoPairs`, `:822-859`). The first assistant row after a user message closes the pair. **Each later assistant row — every additional tool-output row — becomes its own "standalone" pair.** *Inferred from reading.*
3. Keeps the last `recentPreserveCount = 10` pairs verbatim. In a tool-heavy session that can be as few as ten tool-output rows.
4. Earlier pairs go through `compactFileReferences()` and `removeNavigationSteps()` (drops `cd`/`ls`/`pwd`-style rows, `:1037-1104`).
5. Each pair becomes a `[summary] <user clipped to 80 chars> → <assistant if ≤100 chars, else "[exchanged information]">` row. Standalone rows are clipped to 120 characters (`summarizeExchanges` `:1256`, `generateExchangeSummary` `:1333-1353`). The LLM path replaces only the user/assistant pairs, not standalone rows (`exchangesToSummarize`, `:631-655`).
6. `groupSimilarExchanges()` merges near-duplicate summaries.

What is **not** there:
- **Tool-output pruning or clearing by age (e.g. "clear old tool results")** — ABSENT. Old tool outputs survive verbatim until they fall outside the preserved window.
- **Agent-controlled self-pruning or forgetting of history** — ABSENT (no such tool in the roster, §6).
- **Per-step summarisation inside long turns** — ABSENT.
- `CompactorConfig::skillBudgetPerSkill/Combined` and `ContextCompactor::compactSkills()`/`filterSkills()` have no Chat callers (Chat uses only `compact`, `exchangesToSummarize`, `savingsPercentage`, `shouldCompact`, `shouldCompactForeground`, `shouldSendReminder`, `truncateOversizedExchange`, `withExchangeSummaries`) — DORMANT.
- `src/Compactor.php` + `CompactedGroup.php` are a **file-listing grouper** ("Mirrors gum's file compaction"), unrelated to context, and referenced by nothing — DORMANT.
- `HistoryCompactedMsg` is the LIVE carrier for LLM compaction results.

### 3.4 Tool-output truncation (at tool time, LIVE)

| Tool | Limit |
|---|---|
| Bash, Grep, Glob, Lsp | 64 KiB head+tail with a `PARTIAL` marker (`Tools/Concerns/TruncatesOutput.php:123,177-224`) |
| Read | 1 MiB head |
| WebFetch | 2 MiB |
| WebSearch | 5 MiB response / 10 results |
| Instruction files appended to tool results | 16 KiB |
| MCP bridge results | **uncapped** (`McpToolBridge.php:587-622`) |

Detail is in §6.

### 3.5 Prompt caching

- **Explicit cache control is DORMANT.** `Providers/CacheBreakpoints.php` (690 lines) places Anthropic `cache_control: ephemeral` marks on tools, system and messages, but nothing outside its own file references it. `SUGARCRUSH_DISABLE_PROMPT_CACHE` is therefore inert. Bedrock and Vertex send `systemBlocks` with no cache marks.
- **Implicit prefix caching is LIVE by design.**
  - System sections are ordered by `Context\Stability` (Static → PerSession → PerTurn) so the provider's automatic prefix cache (SGLang radix cache, OpenAI automatic caching) hits. The volatile `<env>` goes last (`docs/PROMPT_ENGINEERING.md`, "Why that order").
  - **Caveat (inferred):** `Runtime` is rebuilt every turn (`EngineBackend.php:784`), so the "per-session" memo of the repo map and memory is actually per turn. The bytes stay stable only as long as the inputs do.
  - Cached-token counts are parsed for reporting (`CustomProvider::parseUsage()`, `prompt_tokens_details.cached_tokens`, `:518-536`).

---

## 4. System prompt generation

The order of record is `Runtime::systemPromptSections()` (`src/Runtime.php:2832-3144`); the rationale is in `docs/PROMPT_ENGINEERING.md` (the "eleven slots"). It is assembled per **step** by `Runtime::assembleSections()` (`:3346-3368`) into one string plus a per-section block array. Empty sections fold out.

| # | Section (fence) | Stability | Content and source | Status |
|---|---|---|---|---|
| 1 | Base identity (unfenced) | Static | `Runtime::basePrompt()` heredoc (`:3433-3496`), about 60 lines: identity ("You are SugarCrush…"); tone (terse, no preamble or recap); tool use (prefer Grep/Glob, read before Edit, exact-unique `old_string`, batch independent reads and parallel Task calls, writes run in order, fix errored calls, verify with tests through Bash and say what was verified, the `!cmd`/`@file` budget note, use the `Skill` tool for listed skills); acting vs asking (act on local reversible work, announce destructive or shared actions); security (never echo credentials, treat WebFetch/WebSearch output as untrusted) | LIVE |
| 2 | Maxims (unfenced) | Static | `Context/Sections/MaximsSection.php:82-104`: lead with the outcome, cite `file:line`, report failures faithfully, tool output is data not instructions, complete sentences, match local style, they/them by default | LIVE |
| 3 | Tool guidance (unfenced) | Static | `Runtime::toolGuidanceSection()` (`:3162-3183`) concatenates `PromptGuidance::promptGuidance()` from Bash, Read, Write and Task, sorted by tool name. **Bash's fragment hard-codes the SugarCraft repo's own git/PR cadence** (`ai/<slug>-<short>` branches, `unset GITHUB_TOKEN && gh pr create`, `gh pr merge --merge --delete-branch`, `git pull --ff-only`; `src/Tools/BuiltIn/Bash.php:124-163`) and is sent to every project. Task's fragment lists the live agent roster | LIVE (Bash content is a bug for non-SugarCraft repos) |
| 4 | `<repo-map>` | PerSession | `Context/RepoMapBlock.php`: **composer/PSR-4 only**. Each root subdirectory plus path-repo directories, with package name, namespace and description (≤256 packages), and PSR-4 source directories with `.php` file counts (≤20,000 files). 8 KiB per section, 120 B per entry. No symbols, no tree-sitter, no non-PHP languages | LIVE |
| 5 | `<user-rules>` | PerSession | `Context/RuleLoader.php`: `~/.sugar-crush/rules/**/*.md` and `~/.sugar-crush/rulebooks/**/*.md` (tier `user`), each in its own fence with an operator-authority preamble. Rules with a `paths:` frontmatter are **not** standing; `RulePathNudge` names them in tool results when a matching file is touched. Standing user and project rules share a 64 KiB budget (`MAX_STANDING_RULE_BYTES`, `:161`); overflow becomes at most 2 pointers plus a count. Toggled by `/rules` / `disabledRules` | LIVE |
| 6 | `<project-instructions>` (documents) | PerSession | `InstructionFileLoader::loadRoot()`: `CLAUDE.md` then `AGENTS.md` at the root, plus the same two files in every ancestor directory up to the enclosing `.git` when the root is a subdirectory of a repo (stopping at `$HOME`) (`Context/InstructionFileLoader.php:198-460`). `@path` imports are expanded (`ImportResolver`, depth 4, `.md` only). `loadForced()` adds glob matches from the user config key `instructions` (`Bootstrap::forcedInstructions()`, `:7281`). Each document gets a project-authority preamble and block markers are escaped (`PromptFence::escape`). **Not loaded:** `~/.claude/CLAUDE.md` or any user-home instruction file (the user tier is `~/.sugar-crush/rules`), `.cursorrules`, `CONVENTIONS.md`. Nested `CLAUDE.md`/`AGENTS.md` in subdirectories are injected into **tool results** the first time Read, Edit or Write touches that directory (`loadForPath`, `:577`; `Read.php:288`, `Edit.php:176`, `Write.php:204`) | LIVE |
| 7 | `<project-instructions>` (rules) | PerSession | `<root>/.sugar-crush/rules/**/*.md` and `<root>/RULES.md` (`RuleLoader::loadProjectRules/loadRootRules`, `:416-461`) | LIVE |
| 8 | `<project-memory>` | PerSession | `Context/MemoryBlock.php`: **project-scope** notes only, from `~/.sugar-crush/memory/project/` plus `<root>/.sugar-crush/memory/`, newest first. Capped at 12 entries, 4 KiB, 512 B per entry. No relevance selection (§5) | LIVE |
| 9 | Enabled skill bodies | PerTurn | Full `Skill::systemPromptContribution()` for each skill in the `enabledSkills` config (`Bootstrap::promptEnabledSkills()`, `:3837`) | LIVE (default empty) |
| 10 | Skill listing | PerTurn | `SkillMatcher::listForPrompt()`: one line (name + description) for each discovered, model-invocable skill not already enabled. Bodies are loaded through the `Skill` tool | LIVE |
| 11 | `<env>` (last) | PerTurn | `Context/EnvironmentBlock.php:744-1138`: working directory, whether it is a git repo, platform, OS, PHP version, model, date (Y-m-d). If it is a repo: a caveat, `git branch --show-current`, `status --porcelain` (≤4 KiB) and `log --oneline -5` (≤4 KiB). **After a step that requested a write**: also `git diff --cached` and `git diff` (`--shortstat --patch`, ≤8 KiB each). Rendered via `proc_open`, and re-rendered every step | LIVE |

**Not in the system prompt:**
- a file tree or directory listing (only the composer repo map)
- the time of day or timezone
- a shell name
- the tool list as prose (the schemas carry it)
- the user's name
- the session or todo state
- the date — it is in `<env>` but at day granularity

**Other prompt-adjacent injections.**
- Tool results can carry appended content (§6):
  - the nested-`CLAUDE.md` sections
  - `SkillPathNudge` (once per session per skill, ≤8 entries of 300 B)
  - `RulePathNudge`
  - `ScriptHook` exit-0 notes (≤10 KB)
- Launch notices and compaction notices travel as `system` messages inside the message list.
- Sub-agents get the same harness prompt **plus** their preset prompt as an extra `system` message at the head of their history (`TaskTool::runOnEngine`, `TaskTool.php:455-461`).

**Does each provider receive the prompt?** Yes, for every provider. The prior finding that "SglangProvider/CustomProvider drop `systemPrompt`" is **fixed**.

| Provider | How the prompt is sent |
|---|---|
| `SglangProvider` | Joins `systemPrompt` with any in-history system rows into one leading `system` message (`formatMessages`, `SglangProvider.php:1573-1600`; `buildParams`, `:1024`) |
| `CustomProvider` (also used for `anthropic`) | Prepends a `system` message (`CustomProvider.php:182-186`, `:250-254`) |
| `OpenAIProvider` | Prepends a `system` message (`:165-169`, `:219-220`) |
| `BedrockProvider` | Sends `system` blocks built from `systemPrompt` (`:363-368`) |
| `VertexProvider` | Uses `systemBlocks` (Anthropic route) or `systemInstruction` (Gemini) (`:562`, `:626-680`) |
| `ClaudeCodeProvider` | Passes `--system-prompt <text>`, which replaces Claude Code's own system prompt (`ClaudeCodeInvocation.php:77-80`) |

---

## 5. Memory

**Store — LIVE.** `MemoryStore` (`src/Memory/MemoryStore.php`) lives at `~/.sugar-crush/memory` (`Bootstrap::memoryStore()`, `src/Cli/Bootstrap.php:7862-7867`).
- Each entry is `<scope>/<uuid>.md` with YAML frontmatter (`writeEntry`, `MemoryStore.php:543-546`).
- Scopes are `user/`, `project/` and `agent/`; `MemoryScope::Local` maps to `agent/` (`normalizeScope()` `:444`).
- Each scope keeps a `MEMORY.md` index, rebuilt on every change and capped at 200 lines / 25 KiB (`:59-61`, `generateIndex()` `:308`).
- It is wired into Chat (`Bootstrap.php:1034`) and into the engine (`->withMemoryStore(...)`, `:2810`/`:2906`).

**Entry model — PARTIAL.** `MemoryEntry` (`src/Memory/MemoryEntry.php`) has id, type, tags, scope, content, created and modified. The types `pattern|convention|decision|preference` exist, but `/memory add` always writes `pattern` with no tags (`docs/MEMORY.md:66-68`).

**Project-local store — LIVE.** `ProjectMemoryWriter` (`src/Context/ProjectMemoryWriter.php:51`, `RELATIVE_DIRECTORY='.sugar-crush/memory'`, `MAX_CONTENT_BYTES=8192`).
- `/memory add --scope project` writes into `<repo>/.sugar-crush/memory/`, which git can track.
- If the root cannot hold a store, the note falls back to the home store.
- A project note over 8 KiB is refused, not truncated.

**Recall — LIVE, project scope only.** `Runtime::memorySnapshot()` (`src/Runtime.php:3606-3611`) → `MemoryBlock::capture()` (`src/Context/MemoryBlock.php:213-229`).
- Only the **project** scope is read, from both the home store and the repo store; the repo copy wins on an id clash.
- Entries are sorted newest-modified first. There is **no relevance or semantic selection**: the list is simply cut at 12 entries, 4 KiB total, and 512 B per entry (`:118-170`).
- It renders as a `<project-memory>` fence with a "not verified fact" header, each line escaped with `PromptFence::escape()`.
- The snapshot is cached per `Runtime`, i.e. per turn (`EngineBackend.php:784`).
- User- and agent-scope notes **never reach the prompt** (`docs/MEMORY.md:110-115`).

**Search — LIVE (substring).** `MemoryStore::search()` (`:115`) is a case-insensitive substring match across scopes, used only by `/memory search`. There are no embeddings: `ProviderInterface::embeddings()` exists but is unused here. Semantic recall is ABSENT.

**`/memory` subcommands — LIVE** (`Chat::handleMemoryCommand`, `src/Chat.php:12429`; handlers `:12504-13040`):
- `list [scope]`
- `add <content> [--scope]` (the default scope is `user`, which means the note will *not* appear in the prompt)
- `search`
- `delete <id>`, `edit <id> <content>` (look in the repo store first)
- `clear --scope X --confirm`
- `import claude|opencode`

**Foreign import — LIVE, read-only.** `ForeignMemoryImporter` reads `~/.claude/projects/<slug>/memory/` and `.opencode/memory`. Imported notes land in the `agent` scope, so they never reach the prompt. A sentinel file `.sugar-crush/memory/.imported-<target>` blocks a second import (`docs/MEMORY.md:196-222`).

**Auto-memory — ABSENT.**
- No memory tool is in the roster.
- Nothing extracts memories automatically at the end of a turn or session, and no "remember this" handling exists.
- The model can persist something only by writing files itself with Write or Bash.

**Instruction files (memory-adjacent) — LIVE:** root and ancestor `CLAUDE.md`/`AGENTS.md`, forced `instructions` globs, nested files injected on first touch, and `@imports`. See §4.

---

## 6. Tools

### 6.1 How the live roster is built

`Bootstrap::tools()` (`src/Cli/Bootstrap.php:6702-6753`) is called by both `Bootstrap::app()` (`:2372`) and `backendFor()` (`:2880`). It computes `filterToolSet(unfilteredTools(...))` and then appends `TaskTool` **only if an AgentManager is passed** (`:6748-6749`).

- `unfilteredTools()` (`:6815-6937`) returns, in order: `Bash`, `Read`, `Edit`, `Glob`, `Grep`, `Write`, `WebFetch`, `WebSearch`, `Doctor`, `SkillTool`, `lspTool()`, then `...mcpTools($root)`.
- `filterToolSet()` / `toolSetUnder()` (`:6995-7076`) apply the `allowedTools` / `disabledTools` config keys, matched case-sensitively with fnmatch.
  - If filtering leaves no tools, a launch notice warns that the model is toolless.
  - Tools removed at the project tier are also reported in a notice.
- File tools are built with the root as their `PathJail`. **No `worktreeJail` is passed anywhere**, so the worktree-jail variants are DORMANT.
- `src/ToolRegistry.php` is DORMANT. It is a viewport-command registry (`filter`/`sort`/`goto`/`select`/`quit`) used only by tests.

### 6.2 Roster

Names are the runtime names the model sees.

| Name | Class | What it does | ParallelSafe | When registered | Status |
|---|---|---|---|---|---|
| `Bash` | `src/Tools/BuiltIn/Bash.php:33` | Fresh `bash -c 'cd <root> && <cmd>'` per call. Optional `interactive:true` runs on a private PTY | no | always | LIVE |
| `Read` | `BuiltIn/Read.php:19` | Whole file up to 1 MiB | yes (`:51`) | always | LIVE |
| `Edit` | `BuiltIn/Edit.php:17` | Exact search/replace: must be unique, or use `replace_all` | no | always | LIVE |
| `Write` | `BuiltIn/Write.php:41` | Create a file; an existing path needs `overwrite:true` | no | always | LIVE |
| `Glob` | `BuiltIn/Glob.php:18` | PHP-iterator glob, max 1,000 matches, honours `.gitignore`, opt-in `include_ignored` | yes | always | LIVE |
| `Grep` | `BuiltIn/Grep.php:19` | `grep -rn` (GNU BRE), `--include` glob, post-filters ignored paths | yes | always | LIVE |
| `WebFetch` | `BuiltIn/WebFetch.php:46` | Raw HTTP(S) GET with SSRF guards | yes | always | LIVE |
| `WebSearch` | `BuiltIn/WebSearch.php:24` | SearXNG JSON search, returned as a digest | yes | always | LIVE (needs an endpoint) |
| `doctor` | `BuiltIn/Doctor.php:33` | Capability probe (terminal image protocol, 16×16 PNG swatch) | no | always | LIVE |
| `Skill` | `BuiltIn/SkillTool.php:29` | Load one SKILL.md body by name | no | always | LIVE |
| `Lsp` | `BuiltIn/LspTool.php:52` | definition, references, hover, symbols, codeActions, diagnostics | no | always, **with no client** | registered but every call errors → effectively DORMANT |
| `Task` | `BuiltIn/TaskTool.php:132` | Sub-agent delegation (§2) | yes when bound; deadline-exempt | when AgentManager passed (always on launch) | LIVE |
| `mcp__<server>__<tool>` | `src/Tools/McpToolBridge.php:73` | One bridge per MCP tool (`Bootstrap::mcpTools()`, `:6478-6490`) | no | only with a trusted `.mcp.json` | LIVE, conditional |

**ABSENT tools:**
- TodoWrite / plan / ExitPlanMode
- AskUserQuestion
- NotebookEdit
- MultiEdit / apply_patch / any diff-patch editing
- BashOutput / KillShell / background shell
- image viewing for the model
- a Read with offset/limit
- a memory tool
- a context-pruning tool

### 6.3 File tools

**Read** (`Read.php:153-379`)
- Parameters: `file_path` and a required `description` (the model's human-readable label).
- **No offset/limit and no line numbers.** It reads up to 1 MiB, then appends `"... [truncated]"` (`:23`, `:240-250`).
- No binary, image or PDF handling: the raw bytes come back.
- Paths are confined by `Tools\PathJail::resolve`, and NUL bytes are rejected.
- Appended to the result, as described in §4:
  - nested `CLAUDE.md`/`AGENTS.md` for the touched directory (once per directory per session)
  - skill path nudges
  - rule path nudges
  - Session-state bookkeeping crosses the forked tool child via `CarriesSessionState`.

**Edit** (`Edit.php:61-266`)
- Parameters: `old_string`, `new_string`, optional `replace_all`, required `description`.
- `substr_count` must equal 1 unless `replace_all`; zero matches or multiple matches leave the file untouched with an explicit error (`:178-197`).
- No fuzzy or whitespace-tolerant matching and no multi-edit.
- Read-before-edit is advice only and is **not enforced**; there is no staleness check.
- The result text is `File updated: <path> (+A -R lines)`. The unified diff goes into `ToolResult::diff` for the TUI, not to the model.
- The `$maxBytes` constructor parameter is unused.

**Write** (`Write.php:65-263`)
- Refuses an existing path without `overwrite:true`.
- Creates parent directories (mode 0755).
- Returns `File created|overwritten` plus a diff.

**Diffs** come from `Tools/Concerns/BuildsUnifiedDiff.php`: a PHP LCS diff with 3 lines of context and a `MAX_LCS_CELLS=250_000` guard.

**Glob and Grep**
- Grep has no case-insensitive flag, no context lines and no output modes. It uses BRE, not PCRE.
- Having `rg` or `fd` installed only changes the tool *description* (it hints the model to call them through Bash). The implementations do not use them.

### 6.4 Bash (`Bash.php:189-234`, `Tools/Concerns/CapturesProcessOutput.php`)

**Process setup**
- Runs through `proc_open` inside `setsid -w -- /bin/sh -c` (`Support/ProcessContainment.php:160-169`). stdin is closed.
- Non-interactive environment: `GIT_TERMINAL_PROMPT=0`, `GIT_ASKPASS=/bin/false`, `PAGER=cat`, … (`:79-85`, `:187-194`).
- **No cwd or env persistence between calls.**

**Output**
- stderr is included only when the exit code is non-zero; on success it is replaced by a one-line marker.
- Output cap: 64 KiB head+tail, marked PARTIAL.

**Interactive mode**
- `interactive:true` spawns the command on a candy-pty PTY at 80×24.
- It is killed after an 8 s idle ceiling (24 s hard limit) and reports exit 124 on silence.

**Timeouts**
- Non-interactive Bash has **no per-command timeout**.
- The only bound is EngineBackend's 120 s *no-progress* watchdog. Sequential tools send no heartbeat, so a command that stays silent for more than 120 s kills the whole turn (inferred from reading).
- Bash is never parallel, so the 90 s group deadline does not apply to it.

**Not available**
- **No background processes** (no `run_in_background`, BashOutput or KillShell).
- **No sandbox.** Bash is deliberately not path-jailed. `BashEscapeDenyHook` is DORMANT. The live guards are the gate's `rm -rf /` breaker, `ProtectFilesHook` and `ConfirmRemoveHook`.

### 6.5 Web

**WebFetch** (`WebFetch.php:111-319`)
- http(s) only.
- SSRF protection: rejects localhost, private and link-local targets, pins DNS to the checked IP, and allows at most 3 redirects, each re-checked.
- 30 s read timeout; 2 MiB cap.
- Returns the **raw body**: no HTML-to-text or markdown conversion and no summarisation.
- Its description carries prompt-injection guidance.

**WebSearch** (`WebSearch.php:47-258`)
- Calls SearXNG at `?q=…&format=json`, with `safesearch` and `time_range` parameters.
- 30 s timeout, 10 results.
- **The default endpoint is the hard-coded private host `http://skynet2.interserver.net:8080/search`** (`:52`). Elsewhere you must set `$SUGARCRUSH_SEARCH_ENDPOINT`.
- Localhost and private endpoints are refused, so a self-hosted SearXNG on localhost cannot be used.

**`/websearch`** (`Chat.php:9914`, `src/Commands/WebSearchCommand.php`)
- Runs the same class **synchronously in the TUI process**.
- Appends the results as a user + assistant pair, which the model then sees as history.

### 6.6 LSP and diagnostics

**LSP — DORMANT.**
- `src/LSP/` is a full stdio JSON-RPC client: `LspConnection` (`proc_open`, initialize, definition, references, hover, symbols, codeActions, diagnostics; `:221-636`) and `LspClient` (routing, `LspCache`, grep fallback).
- But nothing in `src/` constructs `LspClient` or `LspConnection`. `Bootstrap::tools()` is always called without `lsp:`, so the tool is `new LspTool(null, $root)` (`Bootstrap.php:6624-6626`, `:6910`).
- Every call returns "no language server configured … fall back to Grep/Glob" (`LspTool.php:255-262`).
- No settings key exists for language servers.
- Diagnostics would be pull-only; nothing subscribes to `publishDiagnostics`.

**No post-edit diagnostics, lint, or test run** — ABSENT.

**`src/Diagnostics/`** holds only `RuntimeNoticeSink.php`, the launch and runtime notice channel. It is not code diagnostics.

### 6.7 Per-tool prompt guidance

Tools implementing `Tools\PromptGuidance` contribute system-prompt section 3 (§4):

| Tool | Guidance |
|---|---|
| Bash | SugarCraft-specific git/PR cadence (`Bash.php:124-163`) |
| Read | Size cap and the instruction-surfacing behaviour |
| Write | Create-only plus the overwrite flag |
| Task | Orchestration rules plus the live roster |

Every other tool relies on its description and JSON schema alone.

### 6.8 Output caps

| Tool | Cap |
|---|---|
| Bash, Grep, Glob, Lsp | 64 KiB |
| Read | 1 MiB |
| WebFetch | 2 MiB |
| WebSearch | 5 MiB / 10 results |
| Instruction-file sections | 16 KiB |
| **MCP results** | **uncapped** (`McpToolBridge.php:587-622`; non-text parts become placeholders like `[image]`) |

`SglangProvider::flagTruncationRiskInLatestToolResults()` (`:2387-2422`) prunes nothing. It only logs a MiniMax `</parameter>` truncation-risk warning.

---

## 7. Git integration

**Git state in the prompt — LIVE.** The `<env>` block (§4; `src/Context/EnvironmentBlock.php:744-1138`) carries:
- branch, `status --porcelain` and `log --oneline -5`, re-rendered every step;
- after a write-requesting step, staged and unstaged diffs as well (`--shortstat --patch`, ≤8 KiB each), gated by `Runtime::stepRequestedAWrite()` (`src/Runtime.php:1060`) and `EngineBackend.php:974`.

**Repo map — LIVE, not git-based.** It is derived from composer, not git (§4).

**Auto-commit, commit-message generation and Co-Authored-By attribution — ABSENT.** No `git commit` exists outside the MCP git handlers. The *Bash prompt guidance* tells the model the SugarCraft PR cadence (§6.7), but nothing commits automatically.

**Git MCP server — PARTIAL, opt-in.** `src/MCP/GitMcpServer.php` + `GitCommandHandlers.php` run in-process and cover:
- status, log, show, blame, reflog
- add, commit/amend, revert, reset
- branch, checkout, `worktree add/list/remove`
- git-flow, LFS, rev-parse, remote, tag

It is reachable only through a `"type":"git"` entry in a *trusted* `<root>/.mcp.json` (`McpClient::buildServer()`, `src/MCP/McpClient.php:407-412`). Its tools then appear as `mcp__<name>__*`. Nothing is configured by default.

**Worktrees — DORMANT.** See §2.6:
- `WorktreeManager` (`git worktree add -b`, `:264`) is never constructed.
- `withWorktreeRoot()` and `BashEscapeDenyHook` have no caller.
- `SUGARCRUSH_WORKTREES_DIR` is read only by the dormant manager.

**Checkpoints / undo / stash snapshots of files — ABSENT.** `/rewind` restores the transcript only (§8).

**Per-edit diffs — LIVE.** These come from the tool (LCS), not from git. They cross the fork boundary in the `finished` frame's `diff` field (`EngineBackend.php:1911`) and are drawn with `src/Tui/DiffGutter.php` (`src/Renderer.php:3681`).

---

## 8. Checkpoints, undo and session management

**Store — LIVE.** `EnhancedSessionStore` uses SQLite at `~/.sugar-crush/session.db` (`Bootstrap::sessionStore()`, `src/Cli/Bootstrap.php:7347`).
- Tables: `session_meta`, `checkpoints`, `checkpoint_blobs` (content-addressed message blobs) and `session_transcripts` (`src/Session/EnhancedSessionStore.php:140-210`).
- API: create, get, rename, fork, delete, list; `saveTranscript`/`loadTranscript` (`:872`, `:902`); `latestResumableSession` (`:934`); `pruneSessions`; `pruneEmptySessions` (`:963`).

**Transcript persistence — LIVE.** `Chat::persistTranscript()` (`src/Chat.php:1512-1526`) rewrites the whole Chat history after every history-changing update. That includes tool placeholder and result rows. A tool that was still running at save time is revived as *interrupted*.

**Per-turn checkpoints — LIVE.** `Chat::dispatchTurn()` saves one checkpoint per turn: messages, input buffer, cursor and session id (`:7964-7993`). At most 100 are kept per session (`EnhancedSessionStore.php:285`).

**Session commands:**
- **`/rewind [n]`** (`:12317-12424`) — restores the transcript and the input from the nth-latest checkpoint. **It does not restore files on disk**; its reply suggests running `/branch` first. LIVE for the transcript; file-level undo is ABSENT.
- **`/branch`** — LIVE: forks the session and switches to it (`:12023-12057`).
- **`/fork <prompt>`** and **`/bg <task>`** — background daemons; see §2.4 (`/fork` does not carry the history).
- **`/clear`** (`:8924`) — empties the transcript but keeps the session id and its checkpoints.
- **`/rename <name>`** (`:12274`).
- **"New session"** — palette-only (`handlePaletteNewSession`, `:14163`).

**Launch-time selection — LIVE** (`Bootstrap::openSession()`, `:3188-3228`):
- The default is a new session with a random 16-hex id.
- `-c/--continue` opens the latest resumable session.
- `--resume <id|prefix|name>` opens a specific session; a bare `--resume` opens the picker. An unknown target exits 2.
- Empty unnamed sessions older than 1 h are pruned at launch.

**In-TUI navigation — LIVE.**
- `Ctrl+R` or `/sessions` opens `SessionPicker`, with paging (`Chat.php:11726-11998`).
- `Ctrl+Tab` / `Ctrl+Shift+Tab` and clicks on the session tab strip switch sessions (`cycleSessionTab`, `:2590`; `selectSessionTab`, `:5968`). The strip is drawn by `Renderer::renderSessionTabStrip()` (`src/Renderer.php:1367`). The separate `src/Tui/SessionTabs.php` helper class is unreferenced.

**Titles — LIVE.** `scheduleTitleGeneration()` (`Chat.php:9011-9065`) runs after the first turn of an unnamed session. It uses a tool-less `EngineBackend` (`SUGARCRUSH_TITLE_MODEL` / `titleModel`, else the provider default; `Bootstrap::titleBackend()`, `:7711`) → sanitise → `renameSession` → `SessionTitledMsg`.

**Retention — LIVE, opt-in.** `SUGARCRUSH_SESSION_RETENTION_DAYS` (default 0, which means off). Named and resumable sessions are exempt.

**Prompt history — LIVE.** `src/Session/PromptHistory.php` keeps `~/.sugar-crush/prompt_history.jsonl`; Up/Down walk it across sessions.

**Dormant:**
- `src/Session.php` is unused.
- `SessionMeta` (tasks, modifiedFiles, agentStates) is only read and written inside the store.

**Share — command LIVE, upload ABSENT.** `/share [markdown|json|text] [expiry]` (`Chat::handleShareCommand()`, `:9889` → `src/Commands/ShareCommand.php:34-70`) builds a `ShareSession` with `Util\Exporter`. But `ShareUploader::upload()` **always throws** "Share upload is not yet implemented" (`src/Share/ShareUploader.php:32-39`). There is no local export fallback.

**CLI subcommands — LIVE** (`src/Cli/Subcommands.php`): `doctor`, `models`, `session list|delete`, `mcp list|auth|import`, `completion bash|zsh|fish`. There is **no `serve`, web UI, or ACP/IDE mode**.

**Non-interactive mode — LIVE.**
- `-p "<prompt>"` / `run "<prompt>"` → `NonInteractive::run()` (synchronous `EngineBackend::complete()`).
- `--output-format text|json` only; there is no `stream-json`.
- Errors are JSON `{result:null, error:{type,message}}` with exit 2 for usage or installation problems.
- `HeadlessPermissionPrompt` asks on stderr at a TTY.
- `-p` runs are not saved as sessions (inferred from grep), and `--continue`/`--resume` cannot be combined with `-p`.

---

## 9. Skills, slash commands, hooks, MCP, permissions, settings

### 9.1 Skills

**Discovery — LIVE.**
- Tiers, in increasing precedence (later wins on a name clash):
  1. built-in `src/Skills/BuiltIn/`
  2. `~/.sugar-crush/skills/`
  3. `<root>/.sugar-crush/skills/`
- Foreign trees are imported read-only and badged:
  - `<root>/.claude/skills`, `~/.claude/skills`
  - `<root>/.opencode/skills`, `~/.config/opencode/skills`
  - Native beats foreign; between foreign sources, opencode beats Claude (`docs/SKILLS.md:19-48`; `SkillLoader`, `ForeignSkillDiscovery`, `SkillManager::loadAll()`).

**12 built-ins:** api-design, composer-wizard, explore-codebase, laravel-best-practices, matchups-sync, mcp-authoring, php-best-practices, phpunit-master, security-audit, symfony-best-practices, testing-strategies, worktree-workflow.

**SKILL.md frontmatter.**
- Parsed: `description`, `user-invocable`, `disable-model-invocation`, `paths`, `allowed-tools`, `disallowed-tools`, `model`, `effort`, `context`.
- LIVE: `description`, `user-invocable`, `disable-model-invocation`, `paths`.
- Inert:
  - `allowed-tools`, `disallowed-tools`, `effort`
  - `model` (read only by `App::dispatchSkill()`, which has no caller)
  - `context: fork`

**How skills reach the model:**
1. A one-line listing in the system prompt (§4 slot 10) — LIVE.
2. The `Skill` tool loads a skill's body on demand — LIVE.
3. The `enabledSkills` config key splices full bodies into every turn — LIVE (empty by default).
4. `SkillPathNudge` mentions a skill once per session when a tool touches a file matching its `paths:` (≤8 entries of 300 B) — LIVE.
5. The TUI skill picker (`Ctrl+S`; `SourceSkillCmd` → `App::handleSelectSkill()`) enables a skill for the session, but splices only a heading with **no body** — PARTIAL.
6. Automatic prompt-keyword matching (`SkillRegistry::findForPrompt`) is deliberately unwired (its measured precision was 0.162) — DORMANT.

`disabledSkills` is a layered key that the project tier is allowed to set.

### 9.2 Slash commands

**Built-ins — LIVE.** Dispatched in `Chat::dispatchCommand()` (`src/Chat.php:8240-8332`); the roster is in `docs/COMMANDS.md`, generated by `CommandRegistry::all()`:
- `/exit` (`/quit`), `/keys`, `/help`, `/clear`
- `/permissions`, `/notices`, `/rules`
- `/compact`, `/budget`
- `/workflow`, `/share`, `/agents` (`/agent`), `/memory`
- `/bg` (`/background`), `/fork`, `/branch`, `/rename`, `/rewind`, `/sessions`
- `/theme`, `/mcp`, `/websearch`, `/pane`, `/layout`
- `/model` — takes a **provider** name, not a model id (§11)

Not present:
- `/init` (no generation of an AGENTS.md/CLAUDE.md)
- `/undo`, `/cost` (covered by `/budget`), `/doctor` (exists as a CLI subcommand and a tool)
- `/review`, `/diff`, `/export`, `/login`, `/resume` (Ctrl+R and `/sessions` cover resume)

**Custom commands — LIVE** (`src/Commands/CommandLoader.php`, `CommandSpec.php`):
- Files: `~/.sugar-crush/commands/**/*.md` and `<root>/.sugar-crush/commands/**/*.md`. A nested path becomes the name, e.g. `/deploy/staging`.
- Frontmatter: `description`, `argument-hint`, `model`, `subtask`.
- Template placeholders: `$ARGUMENTS`, `$1`..`$9`, `$$`.
- `` !`cmd` `` runs a shell command in the root. All shell forms share a 10 s budget and each output is capped at 16 KiB. Project-tier shell forms require `trustedProjectCommands`.
- `@file` includes are containment-checked.
- A project command can override a built-in, except for the control-plane names (budget, clear, exit, help, model, permissions, quit).
- **`model` and `subtask` are parsed but ignored**, so a command always runs inline on the current model (DORMANT).

### 9.3 Hooks

**Config files.**
- `~/.sugar-crush/hooks.yaml` is always loaded.
- `<root>/.sugar-crush/hooks.yaml` is loaded only if `trustedProjectHooks` lists the root.
- A malformed file aborts the launch (`Bootstrap::hooks()`, `:4108-4137`; `docs/HOOKS.md`).
- Entry keys: `name`, `matcher` (a regex on the tool name), `command`, `description`, `disabled`, `timeout` (default 60 s).

**Script exit codes** (`src/Hooks/ScriptHook.php`; README):

| Exit code | Meaning |
|---|---|
| 0 | allow; stdout (≤10 KB) is appended to the tool result as a note |
| 1 | deny |
| 2 | hard block |
| 3 | ask (stdout becomes the question) |
| 4 | modify (stdout is JSON replacement arguments) |

PHP `HookInterface` hooks need an embedder.

**Events** (`src/Hooks/HookEvent.php`, 11 in total):
- LIVE:
  - `PreToolUse` (`Runtime::gate()`, `src/Runtime.php:2129`)
  - `PostToolUse` (`Runtime::settle()`)
  - `UserPromptSubmit` and `SessionStart` (`Chat::dispatchTurnHooks()`, `:4581`; an Ask there fails closed)
- DORMANT:
  - `Stop`, `SubagentStop`, `SessionEnd`, `PreCompact` — no dispatch site
  - `TaskCreated`, `TaskCompleted`, `TeammateIdle` — only on the dormant TaskList, and nothing constructs a `HookDispatcher`

**Built-ins.**
- `ProtectFilesHook`: blocks secret and policy files for Bash, Edit, Write and Read.
- `ConfirmRemoveHook`: denies `rm -rf`, `find -delete` and similar.
- `AuditHook`: logs every call into a 0700 per-user directory.
- `PermissionGateHook`: registered last.
- `BashEscapeDenyHook`: DORMANT.
- Order: built-ins → `hooks.yaml` → gate.

### 9.4 MCP

**Client only.** sugar-crush has no `serve` mode; MCP server classes exist only for the in-process git server (`docs/MCP.md:438-453`).

**Config and trust.**
- The only config file is `<root>/.mcp.json`; there is **no user-level MCP config**. Foreign spellings are normalised by `McpForeignTranslate` (e.g. opencode `local` → stdio).
- The file is honoured only if `trustedProjectMcp` lists the root (`Bootstrap::mcpConfigDecision()`, `:5808-5895`).
- `SUGARCRUSH_MCP_DISABLE` turns MCP off.

**Startup.** Servers start once, at launch (`Bootstrap::mcpClient()`, `:6262-6469`). A config change needs a relaunch; the client keeps a config digest.

**Transports** (`McpClient::buildServer()`, `src/MCP/McpClient.php:375-436`):

| Transport | Details |
|---|---|
| `stdio` | `StdioMcpServer`, an adapter over `sugarcraft/sugar-mcp` (`src/MCP/StdioMcpServer.php`; `composer.json:49`). Handshake bounded at 60 s by default; `tools/call` is unbounded |
| `http` | Stateless POST, with an OAuth bearer from `McpAuthStore` |
| `git` | In-process git server |
| `claude-mcp` | Spawns the operator-configured `claudeMcpBinary` |
| `sse` | **ABSENT** (throws "Unknown MCP server type") |

**Capabilities.**
- **Tools only.** MCP resources, prompts and sampling are ABSENT (`McpServer` interface: start, stop, listTools, callTool; `src/MCP/McpServer.php:28-43`).
- Bridged tools are named `mcp__<server>__<tool>` and go through PreToolUse and the gate.
- The main chat is unrestricted. Sub-agent presets filter tools by their `mcpServers` list.

**Auth — LIVE.**
- `/mcp list|add|remove|login` (`src/Commands/McpAuthCommand.php`).
- `sugarcrush mcp auth login` runs a full OAuth 2.0 authorization-code flow with PKCE S256, RFC 8414 discovery and dynamic client registration, on a loopback 127.0.0.1 listener with a 300 s timeout.

**Status display.** `src/Tui/McpPanel.php` is only a text renderer inside `/mcp list`, not a pane (PARTIAL).

### 9.5 Permissions

**Modes** (`src/Permissions/PermissionMode.php`): `default`, `accept-edits`, `plan`, `auto`, `dont-ask`, `bypass-permissions` (table at `docs/PERMISSIONS.md:38-67`).

| Tool class | Tools |
|---|---|
| Read-only | Read, Grep, Glob, WebFetch, Lsp |
| Write-capable | Bash, Edit, Write, `mcp__*` |
| Neither (falls to the mode's default arm) | WebSearch, Skill, doctor |

**Default and precedence.** The built-in default is **`bypass-permissions`** (`Bootstrap.php:166`). Precedence: `--permission-mode` > `SUGARCRUSH_PERMISSION_MODE` > the `permissionMode` key (user tier only). An invalid value exits 2 (`Bootstrap::permissionGate()`, `:4807-4872`).

**`PermissionGate::decide()`** runs three steps:
1. An `rm -rf /` / `rm -rf ~` circuit breaker that always denies.
2. `permissionRules` (`{pattern, action: allow|deny|ask}`), first match wins. Rules match the **tool name only** (exact, or a trailing `*` prefix); argument patterns like `Bash(rm *)` never match (`docs/PERMISSIONS.md:155-191`).
3. The mode's evaluator. `auto` uses `SafetyClassifier`, a regex heuristic, not an LLM, with a 3-strike / 20-total breaker (`src/Permissions/SafetyClassifier.php`).

**Key finding: no interactive approval on the TUI engine path.**
- `Bootstrap::chat()` → `backend()` uses `consolePermissionPrompt=false`, so `EngineBackend::$permissionApprover` stays null (`Bootstrap.php:2981-2990`).
- `Runtime::settleAsk()` therefore turns every Ask into `deny("no approver is attached to this run …")` (`src/Runtime.php:2629-2648`).
- Asks come from the modes default, accept-edits, plan and auto, from `ask` rules, and from exit-3 hooks. In the TUI the user never sees a prompt for any of them.
- The y/n/a Veil modal (`Chat::beginToolCalls()`, `:2635` → `requestPermission`, `:2666`) serves only Command-backend tool calls.
- Confirmed by `docs/PERMISSIONS.md:219-223` and README "Limitations": the turn runs in a forked child with a one-way frame channel, so a question cannot reach the screen.
- `-p` and background daemons attach `HeadlessPermissionPrompt` instead.
- Status: interactive approval is **ABSENT** in the TUI agent loop; this is why the default is bypass.

**Related:**
- A granted Task call is memoised per agent within a turn (`Runtime::taskGrantMemoKey()`, `:2231`).
- `/permissions` is a read-only report (LIVE).

### 9.6 Settings and config layering

**Settings files** (`docs/SETTINGS.md:10-21`), lowest precedence first:
1. `<root>/.sugar-crush/settings.json`
2. `<root>/.sugar-crush/settings.local.json`
3. `~/.sugar-crush/settings.json`
4. `~/.sugar-crush/config.json` — written by the app for `provider`, `theme` and `layout`; `--config` repoints this layer.

User files outrank project files. Layers 1-2 apply only when the root is listed in `trustedProjectSettings`.

**Layered keys** (`src/Config/LayeredSettings.php:365-379`): provider, theme, titleModel, summaryModel, instructions, disabledSkills, disabledRules, parallelToolCalls, parallelToolDeadlineSeconds, maxOutputTokens, modelPrices, allowedTools, disabledTools, statusLine, and others.
- The project tier may set only: theme, titleModel, summaryModel, disabledSkills, parallelToolCalls, parallelToolDeadlineSeconds, disabledTools (`:584-592`).
- `maxToolSteps` and `enabledSkills` are read from user config.
- Permission keys and all `trustedProject*` keys are user-tier only.

**Trust keys:** `trustedProjectHooks`, `trustedProjectMcp`, `trustedProjectCommands`, `trustedProjectSettings`. Each takes absolute paths, matches roots exactly (not subtrees), and is frozen for the life of the process.

**Provider definitions** come from the package's `.sugar-crush/config.dev.json` (`providers{}`, `defaultProvider`; `ProviderFactory::CONFIG_PATH`).

**Main environment variables** (`docs/ENVIRONMENT.md`):

| Group | Variables |
|---|---|
| Provider and models | `SUGARCRUSH_PROVIDER`, `SUGARCRUSH_MODEL`, `SUGARCRUSH_TITLE_MODEL`, `SUGARCRUSH_SUMMARY_MODEL` |
| Shell-out backends | `SUGARCRUSH_BACKEND_CMD[_STREAM]` |
| Limits | `SUGARCRUSH_MAX_COST`, `SUGARCRUSH_PERMISSION_MODE`, `SUGARCRUSH_SESSION_RETENTION_DAYS`, `SUGARCRUSH_CONNECT_TIMEOUT` (default 15 s) |
| Parallel tools | `SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS`, `SUGARCRUSH_PARALLEL_TOOL_DEADLINE` (default 90 s) |
| UI | `SUGARCRUSH_DISABLE_MOUSE[_CLICKS]`, `SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS` |
| Other | `SUGARCRUSH_SEARCH_ENDPOINT`, `SUGARCRUSH_MCP_DISABLE` |
| Inert | `SUGARCRUSH_DISABLE_PROMPT_CACHE`, `SUGARCRUSH_WORKTREES_DIR`, `SUGARCRUSH_SHARE_UPLOAD_URL` (stub uploader) |

**Status line** (`src/Config/StatusLineCommand.php`): `{"statusLine":{"type":"command","command":"…"}}`, in the same shape as Claude Code. The command's stdout becomes a status-bar segment, refreshed on a timer. It is user-tier only, because it executes a command.

---

## 10. TUI and UX features

All items are LIVE unless marked otherwise. The authoritative key list is `src/Commands/KeyBindingRegistry.php`, which is drift-tested; README "Using the TUI" summarises it.

### Shell (`App`)
- **Menu bar** (F10) with dropdowns and right-hand pane tabs.
- **Five dockable panes:** Files and Tools on the left; Skills, Agents and Settings on the right.
- Drag a pane header to re-dock it; drag the border to resize.
- `/pane dock left|right`, `/pane toggle`, `/layout reset`.
- The layout persists in the `layout` key of `~/.sugar-crush/config.json` (`App::persistDock()`, `src/App/App.php:722`).
- Focus: Tab / Shift+Tab move between panes; Esc returns to chat.
- **Pane status:**
  - Tools pane — LIVE: recent tool results ✔/✖ and running ◌ (`Tui/Components/ToolsPane.php:79-111`).
  - Files pane — **effectively empty on the engine path.** It reads `App::contextFiles`, which only the dormant `AppBuilder` sets, and `Message::toolCalls`, which engine tool rows do not carry, so it shows "(no files attached)" (`FilesPane.php:91-124`; inferred).
  - Settings pane — a read-only readout.
  - Agents pane / dashboard — live for single Task calls, background sessions and workflow stages. Its c/r/s/q and Alt+1…9 *actions* are inert (§2.2).

### Transcript (`src/Renderer.php`, buffer-diff)
- Streams tokens as they arrive.
- Live **thinking** text folds into a collapsed `💭 Thought` row.
- Tool calls appear live with the model-written `description` and a running → done transition.
- Distinct visual states for denied and interrupted calls.
- Successful tool output is collapsed by default (Ctrl+O or a click expands it).
- **Unified diff view** with a coloured gutter for Edit and Write (`Tui/DiffGutter.php`, `Renderer.php:3681`).
- **Inline images** from tool results via candy-mosaic (Sixel, Kitty, iTerm2, block fallback). The `doctor` tool probes which protocol works.
- Spend-cap, compaction, truncation, steps-truncated, output-length-stopped and unpriced-model notices (`Chat.php:15878-15994`).

### Status bar
- `~N tokens` and a context percentage (an estimate, calibrated against provider usage).
- Spend in dollars, and the cap if one is set.
- An "Esc Esc to cancel" hint while a turn is running.
- An optional `statusLine` command segment.
- A stall warning (`Tui/StallDetector.php` and `StallWarning.php`, used by the background supervisor).

### Input
- `TextArea` with bracketed paste.
- Multi-line drafts with Alt+Enter (Shift+Enter or Ctrl+Enter where the terminal distinguishes them).
- Ctrl+W / Alt+Backspace delete a word.
- Up/Down recall prompt history across sessions.
- **Prompt suggestions:** after a turn, a greyed guess at the next prompt, generated by the title backend. Press Right to accept (`Chat::schedulePromptSuggestion()`, `:9144`).
- **Prompt queueing** while a turn is in flight.
- The `/` popup has fuzzy matching and Tab completion.
- `?` on a blank line shows the key reference; `/keys` does the same.
- **ABSENT:** a vim mode, an external `$EDITOR`, `@`-mention file attachment from the input (only inside custom-command templates), and image paste.

### Command palette (Ctrl+P)
- Fuzzy search, grouped by category, with MRU bias (`src/Palette/PaletteState.php`, `PaletteAction.php`).
- Actions (`Chat::runRootPaletteAction()`, `:14038-14074`): switch model/provider, switch theme, share, agents, sessions, MCP, new session, open docs, dock left/right, layout reset, exit.

### Themes
- `dark`, `light`, `dracula`, `tokyoNight`, `ansi`, `adaptive` (`src/Theme.php:60`).
- Set with `/theme` or the palette; persisted.
- The terminal background colour is detected (`App::observeBackground()`, `:1116`; `Tui/TerminalBackground.php`).

### Mouse
- On by default (`SUGARCRUSH_DISABLE_MOUSE` turns it off).
- Zone hit-testing: wheel scroll, click to expand a tool or thought row, click session tabs, click palette and picker rows, click the menu.
- Drag-select text in the transcript, copied with OSC 52 plus the host clipboard (tmux `load-buffer`, pbcopy, wl-copy, xclip, xsel; `Support/SystemClipboard.php`).
- Click and drag are told apart.

### Sessions
- Tab strip, Ctrl+Tab cycling, Ctrl+R picker, automatic titles (§8).

### Permission modal
- A Veil overlay with armed y/n/a answers; `a` needs a second confirmation.
- **It serves only Command-backend tool calls** (§9.5).

### Not present
- Desktop notifications or a terminal bell on turn completion — ABSENT (none found).
- Kitty keyboard protocol — LIVE; popped on exit in `bin/sugarcrush`.

---

## 11. Notable gaps, known bugs and dormant code

### 11.1 Behavioural gaps and bugs

1. **The TUI agent loop cannot ask permission.** Any Ask becomes a denial. The default mode is `bypass-permissions`, so out of the box *nothing* is gated except the `rm -rf /` breaker, `ProtectFilesHook` and `ConfirmRemoveHook` (§9.5).
2. **OpenAI provider tool calls are dropped when streaming**, which is always the case (`OpenAIProvider.php:503`). The `anthropic` type is OpenAI-shaped with tools disabled and probably the wrong URL (§1.3).
3. **Cross-turn history is lossy.** Prior tool calls are replayed as assistant text, without names, arguments or call ids (§3.1).
4. **Compaction runs only at submit time.** Its pair grouping treats every extra tool-output row as a standalone "exchange", so "keep the last 10 exchanges" can mean the last ~10 rows. `removeToolResults()` matches a wire shape that is never produced (§3.3; inferred from reading).
5. **The Bash prompt guidance hard-codes the SugarCraft repo's git/PR workflow** and sends it to every project (`Bash.php:124-163`).
6. **WebSearch defaults to a private host** (`skynet2.interserver.net`) and refuses localhost SearXNG (`WebSearch.php:52`, `:151-166`).
7. **Bash has no command timeout.** A silent command longer than 120 s kills the whole turn through the no-progress watchdog (§6.4).
8. **`/model <name>` switches *provider*, not model,** via `Bootstrap::backendFor($name, $root, gate: …)` (`Chat.php:14106-14146`). It passes **no `taskManager`, `taskPool` or `rulesState`**, and `Bootstrap::tools()` only appends `TaskTool` when a manager is given (`Bootstrap.php:6748`). So after a provider switch the **Task tool disappears** and rule toggles detach (inferred from code, not run). Choosing a model id needs `--model` / `SUGARCRUSH_MODEL` at launch.
9. **The `/fork` background run ignores the forked history.** `/bg` results never land back in the chat. Background daemons are not re-adopted after a restart (§2.4).
10. **Parallel Task batches show no live dashboard rows.** The per-agent cancel, resume and stop keys are inert (§2.2).
11. **Sub-agent preset `model`, `permissionMode`, `effort`, `isolation`, `memory` and `background` have no effect.** `tools` grants are matched by name only, so `Bash(git *)` grants all of Bash (§2.1).
12. **`/share` always fails.** The uploader is a stub (§8).
13. **`/rewind` restores the transcript only.** There is no file checkpoint or undo (§7, §8).
14. **No interrupt or steering inside a turn.** Prompts typed mid-turn are queued; Esc Esc kills the turn (§1.4).
15. **The repo map is composer/PSR-4 only**, which is useless outside PHP (§4).
16. **Memory recall is project-scope only, newest-first, 12 entries.** `/memory add` defaults to the *user* scope, which never reaches the prompt (§5).
17. **The Files pane is likely always empty** on the engine path (§10).

### 11.2 DORMANT subsystems

These exist (and are mostly unit-tested) but are not reachable live. They are verified with a comment-stripped reference scan of `src/` and `bin/`, plus call-site checks.

| Subsystem | Why it is dormant |
|---|---|
| `src/LSP/*` (`LspClient`, `LspConnection`, `LspCache`) | Never constructed; the `Lsp` tool has a null client |
| `src/Providers/CacheBreakpoints.php` | Explicit prompt-cache marks; unreferenced |
| `src/ToolRegistry.php` | Viewport commands; tests only |
| `src/Compactor.php` + `src/CompactedGroup.php` | File-listing grouper; unreferenced |
| `src/App/AppBuilder.php`, `src/App/CallToolCmd.php` | Unreferenced |
| `src/StreamingDirectoryLister.php` | Unreferenced |
| `src/Tui/SessionTabs.php` | The tab strip is drawn by `Renderer` instead |
| `src/Skills/SkillDiscovery.php` | No code reference outside its own file (`ForeignSkillDiscovery` is the live one) |
| `src/Session.php`, `SessionMeta` payload | Unused outside the store |
| Agent teams: `TeamManager`, `Team`, `Teammate`, `Mailbox`, `TaskList` | No constructor call |
| `WorktreeManager`, `WorktreeConfig`, `PathJail(Config)`, `EngineBackend::withWorktreeRoot()`, `BashEscapeDenyHook` | Never constructed or called |
| Hook events `Stop`, `SubagentStop`, `SessionEnd`, `PreCompact`, `TaskCreated`, `TaskCompleted`, `TeammateIdle`, `HookDispatcher` | No dispatch site |
| `ContextCompactor::compactSkills()`/`filterSkills()`, skill budgets in `CompactorConfig` | No Chat callers |
| Session-affinity header (`SessionAffinity` trait) | No caller passes an id |
| User attachments (`Message::attachFile/attachImage`, `UserMessage::withFile/withImage`); vision | No callers; every `supportsVision()` is false |
| `Chat::registerTool()`/`onToolCall()`, `Chat::executeAgents()`, `AgentManager::executeSubAgent()`/`refuseCallOutsideGrant()` | No callers |
| `BackgroundSupervisor::reconnect()` | No caller |
| Skill frontmatter `allowed-tools`/`disallowed-tools`/`model`/`effort`/`context:fork`; command frontmatter `model`/`subtask` | Parsed but never read |
| Automatic skill matching (`SkillRegistry::findForPrompt`) | Deliberately unwired |
| Shell commands `GroupInputCmd`, `CancelAgentCmd`, `ResumeAgentCmd`, `StopAllAgentsCmd`, `QuitAgentViewCmd` | Mapped to no-ops |
| `ProviderInterface::embeddings()` | No live consumer |

### 11.3 Absent compared with typical competitors

Most of these are mentioned above:
- auto-commit and commit attribution
- file checkpoints and undo
- todo/plan tool, ask-user tool, plan-mode exit tool
- patch/multi-edit editing, Read offset/limit
- background shells
- HTML→markdown in WebFetch
- LSP diagnostics after edits, lint/test auto-loop
- MCP resources, prompts and SSE; user-level MCP config; MCP server mode
- headless `serve`/ACP/IDE integration
- `stream-json` output
- `/init`
- vision input
- semantic memory and auto-memory
- tree-sitter repo map
- per-turn wall-clock budget
- sandboxed shell
- desktop notifications

---

## Appendix — Quick file index

| Area | Files |
|---|---|
| Entry | `bin/sugarcrush` · `src/Cli/{ArgvParser,Bootstrap,NonInteractive,Subcommands,Help,HeadlessPermissionPrompt}.php` |
| UI models | `src/App/App.php` (shell) · `src/Chat.php` (conversation) · `src/Renderer.php`, `src/Tui/**` |
| Loop | `src/Backend/EngineBackend.php` (fork + step loop) · `src/Runtime.php` (step, tools, gate, system prompt) |
| Providers | `src/Providers/*` (+ `ToolCallParser/*`, `Concerns/*`) |
| Context | `src/Context/*` (EnvironmentBlock, RepoMapBlock, MemoryBlock, InstructionFileLoader, RuleLoader, ContextCompactor, CompactorConfig, ContextWindow, IdleCompactionPolicy, Sections/MaximsSection) |
| Tools | `src/Tools/BuiltIn/*`, `src/Tools/Concerns/*`, `src/Tools/McpToolBridge.php` |
| Agents | `src/Agents/*` (AgentManager, AgentPresetRegistry, ForeignAgentPresetRegistry, AgentWorkerPool, EngineExecutor, SuspendedDelegations, Team*, Mailbox, TaskList, Worktree*) |
| Sessions | `src/Session/*` (EnhancedSessionStore, PromptHistory), `src/Sessions/*` (BackgroundSupervisor, BackgroundSessionRunner) |
| Extensibility | `src/Skills/*`, `src/Commands/*`, `src/Hooks/*`, `src/MCP/*`, `src/Permissions/*`, `src/Config/*`, `src/Memory/*`, `src/Workflows/*`, `src/Share/*` |
| Docs | `docs/{ARCHITECTURE,PROMPT_ENGINEERING,PERMISSIONS,MEMORY,SKILLS,HOOKS,MCP,COMMANDS,SETTINGS,ENVIRONMENT,WORKFLOWS,AGENTS_AUTHORING,TROUBLESHOOTING}.md`, `README.md` ("Limitations" is accurate and worth reading) |
