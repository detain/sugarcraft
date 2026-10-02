# Cline vs sugar-crush: competitor deep-dive

**Competitor:** Cline (cline/cline). It ships as a VS Code extension, a terminal CLI and a TypeScript SDK.

**Sources read.** Cline replaced its agent engine during 2026, so this report covers two codebases:

| Tag | Path | What it is |
|---|---|---|
| **C3** | `/home/sites/crush-research-repos/cline-classic/apps/vscode/src/` | Cline **v3.89.2**, the last release on the *classic* engine. I cloned it at that tag for this report. It holds the code most people mean by "Cline": the model-family system-prompt variants, `environment_details`, `ContextManager`, `summarize_task`, the focus chain, `replace_in_file`, the shadow-git checkpoints and the diff-view approval flow. |
| **SDK** | `/home/sites/crush-research-repos/cline/sdk/packages/` | Cline **4.1.22**, the current HEAD (`5349bed08`, 2026-10-01). The engine is now a layered SDK: `@cline/shared` ← `@cline/llms` ← `@cline/agents` ← `@cline/core`. The VS Code extension (`apps/vscode/src/sdk/*`) and the CLI (`apps/cli`) run on it. |
| **CLI** | `/home/sites/crush-research-repos/cline/apps/cli/src/` | The 4.x terminal client: OpenTUI/React, with a detached hub daemon. |
| **docs** | `/home/sites/crush-research-repos/cline/docs/`, `.../cline-classic/docs/` | Official docs (Mintlify). |

sugar-crush paths are relative to `/home/sites/sugarcraft/sugar-crush/`. "Baseline §N" refers to `prompt_kit/findings/crush-report/00-sugar-crush-baseline.md`. Every sugar-crush claim that a recommendation depends on was re-checked in source. Those checks are noted inline as **[verified]**.

---

## 1. Overview

**What it is.** Cline is an open-source (Apache-2.0) autonomous coding agent. It started as the VS Code extension `claude-dev`. It is now a Bun/TypeScript monorepo that serves several hosts from one SDK: VS Code, JetBrains, a terminal CLI (`cline`, built with OpenTUI and React), a Tauri desktop app, Kanban, and a hosted "cloud" agent.

**Stack.**
- TypeScript, built with Bun 1.4.2 and run on Node ≥ 22.
- Providers go through the Vercel AI SDK (`@cline/llms`). The bundled model catalog lists 6,386 models across 209 providers (CHANGELOG 4.1.21).
- The classic engine used Anthropic-format message history throughout. It supported both XML tool calls and native tool calls.

**Size** (non-test `.ts`/`.tsx` LOC, from `wc`):

| Package | LOC |
|---|---|
| `sdk/packages/core` | ~113k |
| `sdk/packages/llms` | ~218k (mostly the generated catalog) |
| `sdk/packages/agents` | ~2.9k (`agent-runtime.ts` alone is 2,862 lines) |
| `sdk/packages/shared` | ~18k |
| `apps/cli` | ~58k |
| `apps/vscode/src` | ~77k (classic C3 engine `core/task/index.ts` alone: 3,767 lines) |

**Architecture (4.x).**
- `@cline/agents` is a *stateless* loop. It owns the iteration loop, tool orchestration, hooks and the `prepareTurn` seam.
- `@cline/core` is stateful. It owns sessions, persistence, compaction policy, default tools, plugins, teams, checkpoints, scheduling (cron "routines") and the hub.
- A detached **hub daemon** (WebSocket, auth token in an owner-only discovery file) can own sessions. Several clients (CLI, VS Code, desktop) can attach to a running session and detach again without stopping it (`sdk/ARCHITECTURE.md:177-250`).

**The 10 things Cline does best:**
1. **Workspace checkpoints with three restore modes.**
   - Classic: a shadow git repo whose `core.worktree` points at the workspace.
   - 4.x: `git stash create` plus an untracked-files third parent, stored under private refs.
   - Restore modes are "Files", "Task only" and "Files & Task". Checkpoints are what make auto-approve practical (§8).
2. **Context-window engineering.**
   - Removes duplicate file reads, keyed by tool and path, *before* it truncates anything. If deduplication saves ≥ 30% of characters, truncation is skipped (C3).
   - Rewrites stale reads to `[outdated - see the latest file content]`, batched until ≥ 64 KB is reclaimable so the prompt-cache prefix survives (SDK).
   - Keeps the first user/assistant pair and drops half or three quarters of the middle (§4).
3. **Model-written compaction inside the main loop (`summarize_task`).**
   - The model summarises itself while its prompt cache is warm, so the call costs about the same as one turn.
   - The harness then **automatically re-reads the files the summary names as "Required Files"** (up to 8 files / 100k chars).
   - The 4.x SDK adds deterministic and agentic strategies, a summary sidecar validated by a prefix hash, and overflow → compact → retry (§4).
4. **`environment_details` appended to every user turn.**
   - Contents: visible files, open tabs, running terminals with new output, files modified *externally* since the model last read them, time and timezone, context-window usage, and current mode (§5).
5. **Focus chain (todo list).** Classic only; 4.x dropped it (§11).
   - Every tool takes an optional `task_progress` checklist. The list is mirrored to a markdown file the user can edit.
   - It is re-injected every 6 requests, on plan→act, and whenever the user edits it (§5.4).
6. **Plan/Act modes.**
   - Each mode can have its own model.
   - 4.x stamps every user message with `<user_input mode="…">` and inserts a `<mode_notice>` at the exact point the user switched.
   - Plan mode has a hard guard that blocks file-editing shell commands (§3.1, §10).
7. **Forgiving edit application.**
   - `replace_in_file` SEARCH/REPLACE tries, in order: exact match, line-trimmed match, block-anchor match, then out-of-order match.
   - `apply_patch` uses a four-tier fuzzy search with a Levenshtein ≥ 0.66 fallback.
   - The diff-view flow sends the user's own edits and the editor's auto-formatting back to the model, together with the `<final_file_content>` (§7).
8. **Agent hygiene in the loop.**
   - Identical-call loop detection: soft at 3, hard at 5.
   - A consecutive-mistake counter. The limit is 3 in classic and in the CLI, 6 in the core runtime.
   - Output-token-limit recovery: compact and retry once, then up to 3 "be concise" nudges.
   - Empty-response retries, and transient-error retries with backoff (§2).
9. **Mid-run steering.**
   - A "steer" prompt aborts only the current model stream. Running tools finish.
   - The message is injected at the next iteration (§2.2).
10. **Multi-agent.**
    - Parallel read-only `use_subagents` (classic) and parallel `spawn_agent` (4.x).
    - Persistent **agent teams** with a task board, a mailbox and a mission log (§3).
    - sugar-crush already has this machinery, but DORMANT.

---

## 2. Agent loop

### 2.1 Classic loop (C3)

**Outer loop:** `initiateTaskLoop` (`C3 core/task/index.ts:1453-1480`):
```ts
while (!this.taskState.abort) {
    const didEndLoop = await this.recursivelyMakeClineRequests(nextUserContent, includeFileDetails)
    includeFileDetails = false // we only need file details the first time
    ...
    nextUserContent = [{ type: "text", text: formatResponse.noToolsUsed(this.useNativeToolCalls) }]
    this.taskState.consecutiveMistakeCount++
}
```
- The comment says "For now a task never 'completes'".
- A task ends only through `attempt_completion` or abort. A reply with no tool call is not an answer. It is a *mistake*: the model gets `[ERROR] You did not use a tool in your previous response! Please retry with a tool use.` (`C3 core/prompts/responses.ts:33-43`).

**Per turn:** `recursivelyMakeClineRequests` (`index.ts:2357-3323`). Steps in order:
1. Abort check.
2. Increment `apiRequestCount`.
3. **Mistake-limit gate.**
4. On the first request only, initialise the checkpoint and start the initial commit in the background.
5. Decide whether to auto-compact.
6. Run `loadContext`: mentions, slash commands, `environment_details` and the focus-chain block.
7. Send the API request.
8. Consume the stream.
9. Wait until every tool has run (`pWaitFor(userMessageContentReady)`).
10. **Save a checkpoint.**
11. Recurse.

**Streaming:**
- `StreamChunkCoordinator` (`C3 core/task/StreamChunkCoordinator.ts:67-111`) pumps the iterator in the background and routes `usage` chunks out of band, so cost and token counts keep updating while a tool blocks.
- XML tool calls: the whole buffer is re-parsed on every chunk with `parseAssistantMessageV2` (`C3 core/assistant-message/parse-assistant-message.ts:139-351`). A block left unclosed at the end of the buffer is marked `partial: true`.
- Native tool calls are parsed incrementally with `@streamparser/json`. MCP tools are rewritten to `use_mcp_tool` (`C3 core/task/StreamResponseHandler.ts:308-386`).

**One tool or many.**
- The XML prompt says "You can use one tool per message".
- When parallel calling is off, the next tool is refused: `Tool [${toolName}] was not executed because a tool has already been used in this message. Only one tool may be used per message...` (`responses.ts:306-307`). The stream is then cut with `[Response interrupted by a tool use result. Only one tool may be used at a time and should be placed at the end of the message.]` (`index.ts:2982-2990`).
- In 3.89 `enableParallelToolCalling` defaults to `true` (`shared/storage/state-keys.ts:144`). Even so, the tools in one message run **sequentially**. Only `use_subagents` runs anything concurrently.

**Retries.** There are two layers:
1. The `@withRetry` provider decorator: `maxRetries: 3, baseDelay: 1_000, maxDelay: 10_000`. It honours `retry-after` and `x-ratelimit-reset` (`C3 core/api/retry.ts:10-70`).
2. Task-level `autoRetryAttempts`. The comment reads "Calculate delay: 2s, 4s, 8s" (`index.ts:2113-2114`). It covers three cases:
   - a failure on the first chunk;
   - a failure mid-stream, after which the task is re-initialised from disk and "Resume" is clicked programmatically;
   - an empty response, which is recorded as the synthetic assistant message `"Failure: I did not provide a response."`

   After 3 attempts the user is asked `api_req_failed`.

**Context-window-exceeded errors.** The first one is handled automatically: it truncates with `"quarter"` (keeping a quarter of the middle) and retries. A second one asks the user, with "Context window exceeded. Click retry to truncate the conversation and try again." (`index.ts:2034-2060`, `1801-1863`).

**Mistake gate.**
- `maxConsecutiveMistakes` defaults to **3** (`state-keys.ts:261`).
- Every handler increments the count on a missing parameter, a diff failure or a read error, and resets it on success.
- At the limit, the task asks `mistake_limit_reached`. If the user types a reply, it becomes `You seem to be having trouble proceeding. The user has provided the following feedback to help guide you:\n<feedback>…</feedback>` (`responses.ts:45-46`).
- In YOLO mode the task fails instead (`index.ts:2385-2443`).

**Doom-loop detection** (`C3 core/task/loop-detection.ts`):
```ts
export const LOOP_DETECTION_SOFT_THRESHOLD = 3
const LOOP_DETECTION_HARD_THRESHOLD = 5
const IGNORED_PARAMS = new Set(["task_progress"])
```
- The signature is the tool name plus its sorted-key JSON params.
- At **3** identical consecutive calls the model is told: `Tool [${toolName}] has been called ${count} times consecutively with identical arguments. This is not making progress. Please use a different tool or different arguments instead of repeating the same call.` (`responses.ts:309-310`).
- At **5**, `consecutiveMistakeCount` is set straight to the limit, which escalates to the user (`ToolExecutor.ts:579-598`).
- **Repeated reads have their own guard.** `ReadFileToolHandler.ts:341-356` keys reads on path plus mtime. From the 3rd unchanged read it prefixes the content: `[DUPLICATE READ] You have already read '${displayPath}' ${n} times in this conversation. The content has not changed since your last read. Please use the information you already have and proceed with your task.`
- `act_mode_respond` refuses to narrate twice in a row: `[BLOCKED] … Stop explaining and start doing.`

**Interrupts and feedback.**
- Typing a message instead of pressing *Approve* counts as rejecting the pending tool. The text is attached as `The user provided the following feedback:\n<feedback>…</feedback>` and `didRejectTool = true` is set. Every later tool in the same message gets `Skipping tool due to user rejecting a previous tool.` (`C3 core/task/tools/utils/ToolResultUtils.ts:107-149`, `ToolExecutor.ts:325-331`).
- Typing while a command runs delivers the text *with* the partial output: `Command is still running in the user's terminal.\nHere's the output so far:\n…\n\nThe user provided the following feedback:\n<feedback>…` (`C3 integrations/terminal/CommandOrchestrator.ts:610-631`).
- Abort reverts any half-streamed diff-view edit (`DiffViewProvider.revertChanges()`).
- On resume the model gets `[TASK RESUMPTION] This task was interrupted ${agoText}…` plus: "If the last tool use was a replace_in_file or write_to_file that was interrupted, the file was reverted back to its original state" (`responses.ts:231-258`).

**Completion.**
- `attempt_completion` can carry a `command` that demos the result.
- An optional "double-check" mode rejects the first completion with a 6-point re-verification checklist (`C3 core/task/tools/handlers/AttemptCompletionHandler.ts:70-89`).
- If the user replies with feedback, the loop continues: `The user has provided feedback on the results. Consider their input to continue the task, and then attempt completion again.`

### 2.2 SDK loop (4.x): `sdk/packages/agents/src/agent-runtime.ts`

**Iteration loop** (`execute()`, `:783-1065`):
- `while (maxIterations === undefined || iteration < maxIterations)`. There is **no default step cap**: VS Code passes `maxIterations: undefined`.
- Unlike classic, **a reply without tool calls ends the run** (`:950-957`), unless a completion policy demands a terminal tool. The YOLO preset requires `submit_and_exit {summary, verified}`. A text-only turn then gets `[SYSTEM] This run is not complete until you call one of these terminal completion tools: …` (`:758-760`).

**Native tool calls.** These go through AI SDK `streamText` with `experimental_repairToolCall`, which fixes only broken JSON (`llms/src/providers/ai-sdk.ts:847-924`).
- A call that fails to parse becomes an error tool result with an explicit instruction: `"Tool call arguments could not be parsed as JSON. Ensure the outer tool payload is valid JSON and escape embedded quotes/newlines inside string fields."` (`:2828`).

**Parallel tools** (`executeToolCalls`, `:2285-2323`):
- Every call is *prepared* serially first: hooks, policy and approval.
- Then **adjacent** calls whose `executionMode` is `"parallel"` run together under `Promise.all`. A sequential call acts as a barrier, and results keep the original order.
- Only `spawn_agent` and `subagent_*` declare `executionMode: "parallel"`.
- Instead, the built-in tools take **arrays**: `read_files{files[]}`, `search_codebase{queries[]}`, `run_commands{commands[]}`, `fetch_web_content{requests[]}`. Each runs its entries concurrently internally (`core/src/extensions/tools/definitions.ts:194,321,393,576`).
- The prompt pushes this hard: "Before using tools, identify every independent read, search, command, or edit needed for the next step and emit all of those tool calls now…" (`shared/src/prompt/system/act.ts:22-23`).

**Recovery stack** (all constants from `agent-runtime.ts:61-121`):

| Failure | Handling |
|---|---|
| Transient provider error | `PROVIDER_ERROR_MAX_RETRIES = 3`, backoff `min(1000·2^(n-1), 15000)` ms. Never retried for auth or context-overflow errors (`:1189-1261`). |
| Context overflow (provider rejects) | **Compact and retry once** with `prepareTurn({overflowRecovery:true})`. Fails with an explicit "nothing to compact" message unless compaction actually shrank the request (`:1310-1355`, `:2205-2228`). |
| `finish_reason = length` with no tool call | Step 1: compact and retry once (`retryTruncatedTurnWithCompaction`, `:1428-1542`). Step 2: up to `MAX_TOKENS_RECOVERY_LIMIT = 3` nudges: `"Your previous response was cut off because it reached the model's output-token limit before finishing. Keep responses concise: take one small step at a time, avoid long explanations, and write large files or command output in smaller chunks across multiple tool calls."` |
| Empty response | Middleware retries up to 3 attempts, buffering each "until it proves itself". A tool-call-only turn counts as content (`llms/src/providers/middleware/retry-empty-response.ts:1-187`). |
| Content filter | Terminal: `"Model returned no content because the response was blocked by a content filter. Retrying is unlikely to help — try rephrasing the request."` |

**Loop detection and mistakes.** These moved into core.
- `core/src/runtime/safety/loop-detection.ts` uses soft 3 / hard 5 over consecutive identical `toolName + sortKeys(input)`.
- The soft message is `` `Detected ${n} consecutive identical calls to \`${name}\`; consider trying a different approach.` ``.
- `mistake-tracker.ts` counts turns in which *every* tool failed. The limit is 6 in core and 3 in the CLI. At the limit, a host callback decides to continue (with guidance) or stop: `Stopped after ${n}/${max} consecutive mistakes … Session state was preserved. Send a new prompt to resume from the latest state.`

**Mid-run steering** (`core/src/runtime/turn-queue/pending-prompt-service.ts`):
- Pending prompts are delivered as either `"queue"` or `"steer"`.
- A queued prompt runs as a new turn after the run ends.
- A steer prompt calls `agent.notifyPendingUserMessage()`. That aborts **only the current model stream**: "Interrupt only the current model request; running tools finish normally." (`agent-runtime.ts:631-634`).
- At the next iteration (`iteration > 1`), `consumePendingUserMessage()` appends the steer as a user message *before* the model request (`agent-runtime.ts:1634-1645`, `2244-2263`; wired in `core/src/runtime/host/local-runtime-host.ts:817-824`).
- The interrupted stream keeps its visible text but drops partial tool JSON and unsigned reasoning ("A cancelled stream may contain incomplete tool JSON or unsigned reasoning. Keep only replayable visible content", `:1959-1965`).

**Cancellation.**
- `abort()` aborts the run's `AbortController`. Shell tools then kill the whole process tree (SIGKILL to the process group, or `taskkill /T /F` on Windows; `core/src/extensions/tools/executors/bash.ts:770-817`).
- A cancel issued before `run-started` is forwarded once the run starts (`agent-runtime.ts:1033-1041`).

### 2.3 Against sugar-crush

| Aspect | Cline | sugar-crush (baseline §1.4) |
|---|---|---|
| Step cap | none (classic and SDK); the safety nets are mistake and loop counters | `maxSteps` **8** by default (`EngineBackend.php:262` [verified]); `stepsTruncated` notice when hit |
| Doom-loop detection | soft 3 / hard 5 identical calls, plus duplicate-read nudges | **ABSENT** (grep for any loop or identical-call detector [verified]) |
| Mistake counter | 3 (classic, CLI) / 6 (core) consecutive failures → ask the user or stop | ABSENT. An errored tool result goes back to the model with no counter |
| Output-length stop | compact and retry, then up to 3 "be concise" nudges | `lengthStopped` only sets a transcript notice (`Chat.php:1733`) |
| Context overflow mid-turn | compact and retry once | the turn grows without bound until the provider rejects it (baseline §3.3) |
| Steering | "steer" aborts the stream and injects at the next iteration | prompts queue until the turn ends; Esc Esc kills the whole turn |
| Parallel tools | array-input tools plus parallel `spawn_agent` | `ParallelSafe` segments forked per call (LIVE), roughly equivalent |
| Usage while tools block | usage chunks routed out of band | per-step usage folded after each step (LIVE) |

## 3. Agents and sub-agents

### 3.1 Modes rather than agent personas

Neither Cline engine has a roster of named "build/plan/architect" agents. It has **modes** instead.

**Classic (C3)**
- **Plan and Act**, with an optional separate model for each (`planModeApiProvider` / `actModeApiProvider`; `C3 core/controller/index.ts:385-417`).
- **Plan mode is enforced mostly by prompt.** The `PLAN_MODE_RESTRICTED_TOOLS = [write_to_file, replace_in_file, new_rule, apply_patch]` check only fires when `strictPlanModeEnabled` is on, and that defaults to **false**. `execute_command` is never restricted (`C3 core/task/ToolExecutor.ts:291-357`).
- The model talks to the user through `plan_mode_respond {response, needs_more_exploration, task_progress}`.
- Switching mode during a pending plan answer injects `[The user has switched to ACT MODE, so you may now proceed with the task.]` (`C3 core/task/tools/handlers/PlanModeRespondHandler.ts:137-152`).

**4.x**
- Three modes, each a tool preset (`sdk/packages/core/src/extensions/tools/presets.ts:57-148`):
  - `act`: read, search, bash, web, editor, skills, ask, spawn, teams
  - `plan`: the same set **without the editor**
  - `yolo`: read, bash, editor, and `submit_and_exit`; everything auto-approved; a "background worker" prompt
- In plan mode, `run_commands` is guarded by `createPlanModeCommandGuardExtension` (`core/src/extensions/tools/command-guard-extension.ts`). It is a `beforeTool` hook that parses each command and returns `skip` with an explanation for any file-editing construct. Blocked constructs:
  - `rm mv cp dd touch mkdir ln chmod chown truncate patch rsync …`
  - mutating `git`/`npm`/`pip`/`cargo`/`composer` subcommands
  - output redirection anywhere other than `/tmp`
- The block message: "Command not executed: ${reason} can modify files, and file modifications are blocked in plan mode. You are in PLAN MODE — explore, analyze, and present a plan; do not make changes. … put it in your plan so it can run after the user approves switching to act mode." (`command-guard.ts:514-520`)
- The CLI gives the model a `switch_to_act_mode` tool (`apps/cli/src/runtime/interactive/mode.ts:37-67`):
  - Its description says: "only call this after the user has explicitly approved the plan in a message sent AFTER you presented it".
  - It has `lifecycle.completesRun: true`. The session is rebuilt in act mode and continues with `"The user approved switching to act mode. Continue with the approved plan now."`

### 3.2 Classic sub-agents: `use_subagents` (C3 `core/task/tools/subagent/`)

**The tool.** `use_subagents {prompt_1..prompt_5}`, described as: "Run up to five focused in-process subagents in parallel. Each subagent gets its own prompt and returns a comprehensive research result with tool and token stats. Use this for broad exploration when reading many files would consume the main agent's context window…" (`C3 core/prompts/system-prompt/tools/subagent.ts:10-13`).

**Limits and isolation.**
- `MAX_SUBAGENT_PROMPTS = 5`. The runners execute with `Promise.allSettled` (`handlers/SubagentToolHandler.ts:20, 214-258`).
- **Depth 1.** The tool's `contextRequirements` is `subagentsEnabled && !isSubagentRun`.
- **Read-only.** Default tools are `read_file, list_files, search_files, list_code_definition_names, execute_command, use_skill, attempt_completion`.
- The whole batch is approved once. After that the children's tool calls skip approval.

**Custom agents.** User-defined agents live in `~/Documents/Cline/Agents/*.yaml`, with frontmatter `name, description, modelId?, tools?, skills?` and the body as the prompt. Each becomes its own `use_subagent_<name>` tool (`AgentConfigLoader.ts`).

**System prompt suffix** (`SubagentBuilder.ts:24-36`). This is the part worth copying: it *shapes the return value* for a parent that has limited context.
```
# Subagent Execution Mode
You are running as a research subagent. Your job is to explore the codebase and gather information to answer the question.
…
Only use execute_command for readonly operations like ls, grep, git log, git diff, gh, etc.
…
The attempt_completion result field is sent directly to the main agent, so put your full final findings there.
Unless the subagent prompt explicitly asks for detailed analysis, keep the result concise and focus on the files the main agent should read next.
Include a section titled "Relevant file paths" and list only file paths, one per line.
```

**What the parent gets back.**
- Results come back as `Subagent results:` with Total/Succeeded/Failed/Tool calls/"Peak context usage: X / Y (Z%)", and a 1,200-char excerpt per child.
- The UI shows per-subagent tool-call, token and cost stats while the children run.
- Each runner has its own auto-compact at 75% of its window (`SubagentRunner.ts:816-821`).

### 3.3 4.x sub-agents: `spawn_agent` and configured `subagent_*`

**`spawn_agent {systemPrompt, task}`** (`core/src/extensions/tools/team/spawn-agent-tool.ts:30-202`):
- Declared with `executionMode: "parallel"` and `timeoutMs: 300000`.
- Each call builds a separate `SessionRuntime` with its own conversation. It inherits provider, model, hooks and extensions, and is aborted with the parent.
- Returns only `{text, iterations, finishReason, usage}`.
- **It has no depth limit.** Every sub-agent gets `spawn_agent` again (`runtime/host/local/spawn-tool.ts:132-147`).
- **No approvals run inside it.** The child is built without `toolPolicies`, and the SDK default policy is `autoApprove: true`.

**Configured agents** live in `<ws>/.cline/agents/*.yaml` and `~/.cline/agents/*.yaml` (`configured-agent-config.ts:8-16`):
- Frontmatter `name, description, tools, skills, providerId, modelId, maxIterations`.
- Each becomes a `subagent_<name>` tool with input `{prompt}`. It is parallel, and it may use **its own model**.
- These agents do *not* get `spawn_agent`.
- Classic tool names in `tools:` are translated, e.g. `replace_in_file→editor`, `execute_command→run_commands` (`runtime-builder.ts:101-135`).

**Persistence.** Child sessions are saved as `${root}__${agent}` rows with `isSubagent`, `parentSessionId` and their own message files (`session/team/team-child-session-manager.ts:126-188`). History listing filters them out at the query level (`rootOnly`).

### 3.4 4.x agent teams: persistent multi-agent with communication

This is the most relevant part for sugar-crush, because sugar-crush has the same building blocks and keeps them DORMANT (baseline §2.3: `TeamManager`, `Mailbox`, `TaskList`).

**Setup.** Teams are on by default (`enableAgentTeams ?? preset ?? true`; `yolo` and `minimal` turn them off). `cline --team-name auth-sprint "…"` or `/team <task>` makes the lead agent a coordinator.

**Tools** (`core/src/extensions/tools/team/team-tools.ts:197-860`):

| Tool | Purpose |
|---|---|
| `team_spawn_teammate {agentId, rolePrompt}` | **Lead only.** Teammates do not even see it: "exposing the tool to teammates just makes them burn turns on … rejections" |
| `team_task` | A **shared task board**. Actions: `create {title, description, dependsOn?, assignee?}`, `list`, `claim`, `complete {summary}`, `block {reason}` |
| `team_run_task` | Delegate a task, **sync or async**. Async returns a `runId` |
| `team_list_runs` | Live progress of async runs |
| `team_await_runs` | Wait for runs; 1 h timeout |
| `team_cancel_run` | Cancel an async run |
| `team_send_message` / `team_broadcast` / `team_read_mailbox` | **Mailbox** between agents |
| `team_mission_log` | Append-only activity log. Auto-updated every 3 steps or 120 s |
| `team_create_outcome` / `team_attach_outcome_fragment` / `team_review_outcome_fragment` / `team_finalize_outcome` / `team_list_outcomes` | "Converged outcome" documents assembled from reviewed fragments |
| `team_status` / `team_cleanup` / `team_shutdown_teammate` | Housekeeping |

**Runtime** (`multi-agent.ts`):
- `maxConcurrentRuns = 2` by default, with priority dispatch.
- Teammate API timeout of 10 min.
- Heartbeat every 2 s.
- Retry backoff `min(30000, 1000·2^n)`.
- **Crash recovery.** An interrupted run is re-queued with "This is an automatic recovery of interrupted team run ${run.id}. The previous process stopped before completion. Continue the task safely, inspect the current workspace state before making changes, and avoid duplicating completed work."

**Communicating with a running agent.** When a message is sent to a teammate that is running, the teammate gets `[MAILBOX] You got a message from ${from}. Subject: "${subject}". Use the team_read_mailbox tool to read it at your convenience.` This goes through the same `consumePendingUserMessage` steer seam as user steering (`multi-agent.ts:935-943, 1567-1597`), so it arrives at the teammate's next iteration. When a new run starts, unread mail is placed in front of its task.

**The lead cannot quit early.** A completion guard re-prompts it: `[SYSTEM] You still have team obligations. ${parts}. Use team_run_task to delegate work, or team_task with action=complete to mark tasks done, or team_await_runs to wait for active runs. Do NOT stop until all tasks are completed.` (`runtime-builder.ts:823-853`)

**Persistence.**
- SQLite tables `team_tasks, team_runs, team_members, team_mailbox, team_mission_log, team_outcomes…`, with a JSON file fallback (`services/storage/team-store.ts:17-37`).
- Data lives under `~/.cline/data/teams/<name>/`. Run results are cut to 4,000 chars.
- Teammates are respawned on the next launch, and `recoverActiveRuns()` resumes in-flight work.

**Cancellation.** Aborting the lead session cancels queued and running teammate runs (`cancelOutstandingWork("parent_session_abort")`). Teammate definitions survive for later turns.

### 3.5 Background, scheduled and remote agents (4.x)

- **Zen mode** (`cline -z`) runs a session inside the detached hub. The client can quit and re-attach later from any host.
- **Scheduled routines.**
  - Spec files:
    - `~/.cline/cron/*.cron.md`: frontmatter `schedule`, `timezone`, `mode` (default yolo), `maxIterations`, `tools`; the body is the prompt.
    - `events/*.event.md`: event triggers with debounce, dedupe and cooldown.
  - Reports are written to `~/.cline/cron/reports/<run-id>.md` (`shared/src/cron/cron-spec-types.ts:9-63`).
  - The agent can create schedules itself with the `tasks` tool (`kind: "scheduled"`). The tool rule says: "never guess EST, UTC, or another zone".
- **Connectors.** Discord, Google Chat, Linear, Slack, Telegram and WhatsApp bridges drive sessions from chat threads, supervised by the hub (`apps/cli/src/connectors/adapters/`).
- **Cloud handoff.** A local session can be moved to a cloud sandbox. Git preflight refuses dirty or unpushed trees, with error codes `dirty_worktree`, `unpushed_commits`, `detached_head` and others (`core/src/services/cloud-handoff/`).
- **`--worktree`** runs the task in a detached git worktree under `~/.cline/worktrees/`.

### 3.6 Against sugar-crush (baseline §2)

| Aspect | Cline | sugar-crush |
|---|---|---|
| Sub-agent tool | parallel, own context, own model (configured agents), concise "Relevant file paths" return contract | `Task`: parallel, own context, **parent's model** (`model` field DORMANT), returns final text only. LIVE |
| Depth | 1 (classic), unlimited (4.x) | fixed 1 (`TaskTool.php:449-452`) |
| Talking to a running child | team mailbox injected at the child's next iteration | ABSENT; `Mailbox`/`TaskList`/`TeamManager` DORMANT |
| Shared task board | `team_task` with claim, dependencies and block | `TaskList` (SQLite, claim/dependencies/`getUnblockedTasks`) DORMANT |
| Async background runs | `team_run_task` async with `runId`, plus await, list and cancel | `/bg` daemon with no history and results never injected (PARTIAL) |
| Persistence and recovery | team state persisted, teammates respawned, interrupted runs resumed | `SuspendedDelegations` resume on failure (LIVE); background re-adoption DORMANT |
| Plan mode | prompt plus a hard command guard, `switch_to_act_mode` tool, per-mode model | `PermissionMode::Plan` exists, but in the TUI an Ask becomes a denial, and there is no plan prompt or mode switch tool |

## 4. Context handling and compaction

Cline has three generations of context management. All three are still useful as reference designs.

### 4.1 Classic programmatic truncation: `ContextManager` (C3)

**Token accounting** uses provider-reported numbers, not an estimate. Before each request the manager reads the *previous* request's `api_req_started` record and computes `tokensIn + tokensOut + cacheWrites + cacheReads`. The comment explains why: "This is the most reliable way to know when we're close to hitting the context window" (`C3 core/context/context-management/ContextManager.ts:240-249`).

**Window budget** (`C3 core/context/context-management/context-window-utils.ts:10-35`):
```ts
let contextWindow = api.getModel().info.contextWindow || 128_000
switch (contextWindow) {
    case 64_000:  maxAllowedSize = contextWindow - 27_000; break  // deepseek models
    case 128_000: maxAllowedSize = contextWindow - 30_000; break  // most models
    case 200_000: maxAllowedSize = contextWindow - 40_000; break  // claude models
    default: maxAllowedSize = Math.max(contextWindow - 40_000, contextWindow * 0.8)
}
```
For a 1M-token window this gives 960k. For a 200k window it gives 160k.

**Order of operations** once `totalTokens >= maxAllowedSize` (`:227-294`):
1. Pick how much to drop: `keep = totalTokens / 2 > maxAllowedSize ? "quarter" : "half"`. The "quarter" case exists because after a model switch, for example from a 200k model to a 64k one, dropping half may not be enough.
2. Try the file-read optimisation first (§4.2). **If it saves at least 30% of characters, nothing is truncated** (`needToTruncate: percentSaved < 0.3`, `:626-656`).
3. Otherwise extend `conversationHistoryDeletedRange` using `getNextTruncationRange` (`:299-339`):

```ts
// We always keep the first user-assistant pairing, and truncate an even number of messages from there
const rangeStartIndex = 2 // index 0 and 1 are kept
const startOfRest = currentDeletedRange ? currentDeletedRange[1] + 1 : 2
if (keep === "half")   messagesToRemove = Math.floor((apiMessages.length - startOfRest) / 4) * 2
else /* quarter */     messagesToRemove = Math.floor(((apiMessages.length - startOfRest) * 3) / 4 / 2) * 2
// "none" removes everything after the first pair; "lastTwo" keeps the last pair too
let rangeEndIndex = startOfRest + messagesToRemove - 1
if (apiMessages[rangeEndIndex] && apiMessages[rangeEndIndex].role !== "assistant") rangeEndIndex -= 1
```

- **Nothing is deleted.** The stored `api_conversation_history.json` stays complete. The deleted range is a *mask* applied when each request is built, and it is saved on the task's history item.
- Each UI message records the `conversationHistoryIndex` and deleted range in effect when it was created, so a checkpoint restore can rebuild the exact masked history (`C3 core/task/message-state.ts:204-205`).
- After a cut, tool results whose matching `tool_use` was removed are dropped. `ensureToolResultsFollowToolUse` re-pairs everything else and fills any gap with `"result missing"` (`ContextManager.ts:375-505`).

**Notices** (`C3 core/prompts/responses.ts:10-18`):
- The first assistant message is replaced by `[NOTE] Some previous conversation history with the user has been removed to maintain optimal context window length. The initial user task has been retained for continuity, while intermediate conversation history has been removed. Keep this in mind as you continue assisting the user. Pay special attention to the user's latest messages.`
- On the summarise, condense and error paths, the original task text is also replaced, with `[Continue assisting the user!]`.

### 4.2 Duplicate file-read deduplication (C3)

This is the standout idea of the classic engine. It treats context as an editable overlay on top of an immutable transcript.

**Overlay structure.**
- `contextHistoryUpdates: Map<messageIndex, [EditType, Map<blockIndex, ContextUpdate[]>]>`, where each `ContextUpdate` is `[timestamp, updateType, update, metadata]` (`ContextManager.ts:13-53`).
- It is persisted as `context_history.json` next to the transcript.
- When a request is built, the newest edit per block is applied to *deep clones*. The stored history is never modified.
- On checkpoint restore, `truncateContextHistory(timestamp)` rolls back every overlay edit made after the restored point (`:552-601`).

**What counts as a file read** (`:809-1188`):

| Source | Detection | Replacement |
|---|---|---|
| `read_file` result | header regex `/^\[([^\s]+) for '([^']+)'\] Result:/` | the whole body becomes the notice |
| `write_to_file` / `replace_in_file` result | `/(<final_file_content path="[^"]*">)[\s\S]*?(<\/final_file_content>)/` | only the file body inside the tags; the diff text stays |
| `@file` mention | `/<file_content path="([^"]*)">([\s\S]*?)<\/file_content>/g` | per file, tracking which files in a multi-file message are already replaced |

- All three sources are grouped **by path**. Every occurrence except the newest is replaced with `[[NOTE] This file read has been removed to save space in the context window. Refer to the latest file read for the most up to date version of this file.]`.
- The result: the model always holds exactly one, current copy of each file it has touched.

**A second guard inside `read_file`.** `fileReadCache` keys reads by path and mtime. It is invalidated by writes and patches, and cleared completely by `execute_command`.
- 2nd read: `[File already read] … Returning content:`
- 3rd and later reads: `[DUPLICATE READ] …` (see §2.1).

### 4.3 Classic auto-compact: `summarize_task`, `condense`, `new_task` (C3)

**Trigger** (`C3 core/task/index.ts:2513-2618`). The trigger needs all of:
- the `useAutoCondense` setting;
- `isNextGenModelFamily(model)`, which covers Claude 4+, GPT-5, Gemini 2.5/3, Grok 4, Kimi K2, DeepSeek 3.2 and others;
- previous-request tokens ≥ `maxAllowedSize`;
- more than 2 active messages, so a summary is never summarised;
- the file-read optimisation *not* already saving 30%.

Other models fall back to truncation. When the trigger fires:
- `environment_details` and mention parsing are **skipped** for that turn.
- The prompt below is appended to the user message instead. The model must answer with `summarize_task` (or `attempt_completion`).

**The prompt, verbatim** (`C3 core/prompts/contextManagement.ts:10-44`; the example block and the focus-chain branch are omitted here):
```
<explicit_instructions type="summarize_task">
The current conversation is rapidly running out of context. Now, your urgent task is to create a comprehensive detailed summary of the conversation so far, paying close attention to the user's explicit requests and your previous actions.
This summary should be thorough in capturing technical details, code patterns, and architectural decisions that would be essential for continuing development work without losing context.

You have only two options: If you are immediately prepared to call the attempt_completion tool, and have completed all items in your task_progress list, you may call attempt_completion at this time. If you are not prepared to call the attempt_completion tool, and have not completed all items in your task_progress list, you must call the summarize_task tool - in this case you must call the summarize_task tool whether you are in PLAN or ACT mode.

You MUST ONLY respond to this message by using either the attempt_completion tool or the summarize_task tool call. When using the summarize_task tool call, you must include ALL information in the summary required for continuing with the task at hand. This is because you will lose access to all messages other than this summary.

When responding with the summarize_task tool call, follow these instructions:

Before providing your final summary, wrap your analysis in <thinking> tags to organize your thoughts and ensure you've covered all necessary points. In your analysis process:
1. Chronologically analyze each message and section of the conversation. For each section thoroughly identify:
   - The user's explicit requests and intents
   - Your approach to addressing the user's requests
   - Key decisions, technical concepts and code patterns
   - Specific details like file names, full code snippets, function signatures, file edits, etc
2. Double-check for technical accuracy and completeness, addressing each required element thoroughly.

Your summary should include the following sections:
1. Primary Request and Intent: Capture all of the user's explicit requests and intents in detail
2. Key Technical Concepts: List all important technical concepts, technologies, and frameworks discussed.
3. Files and Code Sections: Enumerate specific files and code sections examined, modified, or created. Pay special attention to the most recent messages and include full code snippets where applicable and include a summary of why this file read or edit is important.
4. Problem Solving: Document problems solved and any ongoing troubleshooting efforts.
5. Pending Tasks: Outline any pending tasks that you have explicitly been asked to work on.
6. Task Evolution: If the user provided additional requests or modified the original task during the conversation, document this progression:
   - Original Task: [Summary of the initial user request, including copying verbatim any relevant information/steps required to continue working]
   - Task Modifications: [Chronological list of how the user redirected or modified the work since the original task]
   - Current Active Task: [What the user most recently asked to work on]
   - Context for Changes: [Why the task evolved - user feedback, new requirements, etc. (Include direct quotes from user messages that caused task changes to prevent drift after context compacting)]
7. Current Work: Describe in detail precisely what was being worked on immediately before this summary request, paying special attention to the most recent messages from both user and assistant. Include file names and code snippets where applicable.
8. Next Step: List the next step that you will take that is related to the most recent work you were doing. IMPORTANT: ensure that this step is DIRECTLY in line with the user's explicit requests, and the task you were working on immediately before this summary request. If your last task was concluded, then only list next steps if they are explicitly in line with the users request. Do not start on tangential requests without confirming with the user first.
   If there is a next step, include direct quotes from the most recent conversation showing exactly what task you were working on and where you left off. This should be verbatim to ensure there's no drift in task interpretation.
9. Required Files: List the most important files needed for continuing the work you laid out in Next Step. ... List each file path on a new line starting with "- " such as: - src/main.js. ... You must list the minimum number of files necessary to continue with the task.
   Only list files you know will for sure be necessary, rather than speculating. The file paths must be relative to the current working directory ${CWD}.
10. You should pay special attention to the most recent user message, as it indicates the user's most recent intent.
```

**What the handler does** (`C3 core/task/tools/handlers/SummarizeTaskHandler.ts:30-269`):
1. Runs the **PreCompact hook**. The hook can cancel compaction, which aborts the task, or add context.
2. Parses `9. Required Files:` with `/9\.\s*(?:Optional\s+)?Required Files:\s*((?:\n\s*-\s*.+)+)/m`.
3. **Automatically re-reads those files.** Limits are `MAX_FILES_LOADED = 8`, `MAX_FILES_PROCESSED = 10`, `MAX_CHARS = 100_000`. It respects `.clineignore` and reads a file only if reading it would be auto-approved. The header is: `The following files were automatically read based on the files listed in the Required Files section: … These are the latest versions of these files - you should reference them directly and not re-read them:`
4. Returns `continuationPrompt(summary) + files` as the tool result:
   ```
   This session is being continued from a previous conversation that ran out of context. The conversation is summarized below:
   ${summaryText}.

   Please continue the conversation from where we left it off without asking the user any further questions. Continue with the last task that you were asked to work on. Pay special attention to the most recent user message when responding rather than the initial task message, if applicable.
   If the most recent user's message starts with "/newtask", "/smol", "/compact", "/newrule", or "/reportbug", you should indicate to the user that they will need to run this command again.
   ```
5. Sets the deleted range with `keep = "none"`. On the next request, it extends the range by 2 more messages so the summarisation exchange itself is hidden.

The model then sees:
- `[Continue assisting the user!]`
- the truncation notice
- the tool result carrying the summary and the re-read files
- new turns

**Why this is clever.** The summary is produced by the main model on the main conversation. The prompt cache is therefore hot, and per the docs (`docs/features/auto-compact.mdx`) the call costs "about the same as any other tool call". The automatic file re-read means the first post-compaction turn does not waste steps re-discovering the working set.

**Known bug.** The example block in the prompt labels the section `8. Optional Required Files`, but the regex only accepts `9.`. A model that copies the example gets no files loaded.

**User-triggered variants** (`C3 core/prompts/commands.ts`):
- **`/smol` and `/compact`** send `<explicit_instructions type="condense">`. Its 6 sections are: Previous Conversation, Current Work, Key Technical Concepts, Relevant Files and Code, Problem Solving, Pending Tasks and Next Steps. With the focus chain on, it also requires a `task_progress` in which only completion state may change.
  - The user **previews and approves** the summary.
  - On acceptance the model is told to ONLY ask what to do next: `formatResponse.condense()`, `responses.ts:20-21`.
- **`/newtask`** sends `<explicit_instructions type="new_task">`. The model writes a 5-section context (Current Work, Key Technical Concepts, Relevant Files and Code, Problem Solving, Pending Tasks and Next Steps, "include direct quotes from the most recent conversation … verbatim"). The user previews it, and clicking the button starts a **fresh task seeded with that context**. This is a clean handoff instead of a compaction.

### 4.4 4.x SDK compaction (`sdk/packages/core/src/extensions/context/`)

**Architecture.**
- `@cline/agents` exposes a `prepareTurn` hook that projects messages *only for the outgoing request*. The canonical transcript stays append-only and at full fidelity.
- `@cline/core` installs a compaction pipeline with a strategy registry `{ basic, agentic }` (`compaction.ts:161-186`).
- The latest compacted working context is persisted separately as `${sessionId}.compaction.json` (`core/src/session/models/session-compaction.ts:25-34`) with:
  - `source_message_count`
  - `source_prefix_hash`: sha256 over `"cline-session-compaction-source-v2\n"` + count + per-message `[role, content, agent, sessionId, metadata, modelInfo, metrics]`, with ids and timestamps deliberately excluded (`:85-138`).
- On resume, the state is reused **only if the hash of the current transcript prefix matches**. The projection is then `[...state.messages, ...canonical.slice(source_message_count)]` (`:168-198`).
- Sessions imported from other agents are summarised in full on their first turn, "so the model never replays the source agent's tool calls" (`sdk/ARCHITECTURE.md:599`).

**Trigger** (`compaction.ts:303-362`, constants in `compaction-shared.ts:13-35`):
- `requestInputTokens` is estimated as chars/3 over `{systemPrompt, messages, tools}` (`shared/src/llms/tokens.ts:8-12`). The comment: "Uses 3 chars/token (slightly over-counts vs the conventional 4) so trigger thresholds fire before provider rejection".
- When the *provider-reported* input tokens of the previous request exceed the estimate, the budget is scaled down by up to `MAX_INPUT_UNDERESTIMATE_FACTOR = 4`.
- `shouldCompact = requestInputTokens >= maxInputTokens * 0.9`, where `maxInputTokens` defaults to `contextWindow * 0.9`.
- The target is 0.5 × max input for long conversations (≥ 5 pairs), otherwise 0.7 × trigger.
- **The check runs on every iteration, not only on user turns.**

**Agentic strategy** (`agentic-compaction.ts:116-318`):
- `findCutIndex` keeps about `DEFAULT_PRESERVE_RECENT_TOKENS = 20_000` from the tail. It never cuts after the latest typed user prompt, and it snaps to an assistant message or a typed user turn so tool pairs are never split.
- The prefix goes to the summariser with thinking off, `maxOutputTokens = min(8192, model.maxTokens)`, and the system prompt `"Summarize the provided coding session into a concise continuation note with detailed next steps."` The user message (`compaction-shared.ts:669-701`, verbatim):
```
Summarize this session for continuation. Be concise and factual.

## Goal
One sentence: what is being built or fixed.

## State
- Done: completed steps
- In Progress: current work
- Blocked: blockers or open questions

## Highlights
Key technical choices or notable findings (omit if none).

## Next
Immediate next steps.

## Files
Read: ${fileOps.readFiles.join(", ") || "none"}
Edited: ${fileOps.modifiedFiles.join(", ") || "none"}

Previous summary:
${previousSummary}

Conversation:
${conversationText}
```
- **The `## Files` lists are extracted mechanically from tool calls** (`extractFileOps`). They do not depend on the model remembering them, and `ensureFilesSection` re-appends them if the model drops the section.
- The previous summary is folded in, so successive compactions stay incremental.
- The result is a user message `Context summary:\n\n${summary}` with `metadata.kind = "compaction_summary"`, followed by the preserved tail verbatim.

**Basic (deterministic) strategy** (`basic-compaction.ts:443-711`):
- From its docblock: "Typed user prompts always survive. The latest typed turn keeps its newest messages verbatim within the token target … Older turns keep their concluding assistant answer when it fits … Everything else is dropped and re-surfaced as dropped-work summaries attached to the surviving prompts."
- The dropped-work block is `<SYSTEM_NOTICE>\nEarlier context was compacted. Summary of your actions after the request above:\nFiles read:\n…\n\nFiles edited:\n…\n\nCommands ran:\n…</SYSTEM_NOTICE>`. It is built mechanically, with commands clipped to 100 chars and edited line ranges parsed from the editor's diff.
- Overflow recovery always uses basic, so recovery never depends on a second model call succeeding. Agentic failures in auto mode also fall back to basic (`compaction.ts:480-565`).

**Per-request message builder** (`core/src/session/services/message-builder.ts:29-62`):
- Every tool result string is middle-truncated to `8_000` chars (`...[truncated N chars]...`).
- User file attachments are capped at 50,000 chars, and the whole request at 6 MB.
- **Stale-read rewriting.** An older `read_files` result is rewritten to `"[outdated - see the latest file content]"` when it was superseded by a later read of the same path and range, or by a later full read. Rewrites are **batched until at least 64 KB is reclaimable**, "to avoid breaking provider prefix caches on every re-read" (`:38-40`, `:372-454`, `:933-1005`).
- Missing tool results are synthesised: `"Tool execution was interrupted before a result was produced."`

**Oversized-result cache with recovery URIs** (`core/src/session/services/tool-result-cache.ts`, `sdk/DOC.md:1-8`):
- MCP and Composio tools declare `resultPolicy: "cache-oversized"`.
- The model receives an 8k preview plus `Full result is temporarily saved to cline://cache/<session>/<id>.result.txt. Only read_files can access this cache URI. Use read_files with specific line ranges if omitted content is needed.`
- Entries expire after **5 model iterations without a read**. The per-session cap is 16 MiB, with LRU eviction.
- A miss says `"Cache not found. Make a new tool call for the latest result again if needed. DO NOT repeat side-effecting actions to recover output."`

### 4.5 Prompt caching

- **Classic, Anthropic direct** (`C3 core/api/providers/anthropic.ts:114-141`, `core/api/transform/anthropic-format.ts:14-90`) places 3 breakpoints:
  - on the system prompt ("so new tasks can reuse it");
  - on the **last** user message ("to cache it for the next request");
  - on the **second-to-last** user message ("to let the server know the last message to retrieve from the cache for the current request").

  The tools come before the system prompt and never change, so a separate tools breakpoint is unnecessary.
- OpenRouter and Bedrock use the same "last two user messages" scheme, with `cachePoint` for Bedrock.
- **4.x**: one `cache_control: ephemeral` on the last text part of the last user message only (`llms/src/ai-sdk.ts:405-424`), routed by provider metadata.
- Cache-preserving design shows up elsewhere too: the 64 KB batching of stale-read rewrites, and the aggregate 6 MB valve kept rare because "budget truncation rewrites bytes mid-transcript, which invalidates provider prefix caches".
- **Agent-controlled self-pruning.**
  - Cline has no "forget this" tool.
  - Its agent-controlled compaction is `summarize_task`, `condense` and `new_task`: the model writes its own summary on request or on trigger.
  - The 4.x CLI lets the user switch strategy at runtime (`agentic|basic|off`, `apps/cli/src/utils/compaction-mode.ts:3-43`).

### 4.6 Against sugar-crush

| Aspect | Cline | sugar-crush |
|---|---|---|
| When compaction runs | every request (classic) / every iteration (SDK) | only in `Chat::submit()` between user turns (baseline §3.3) |
| Token measure | provider-reported previous-request tokens (classic); chars/3 estimate calibrated against provider usage, scaled by up to 4× (SDK) | chars/4 + 10 per message, calibrated per turn, **ignoring system prompt and tool schemas** (baseline §3.2) |
| Duplicate reads | precise, keyed by tool name + path, newest copy wins, ≥ 30% savings skips truncation | `compactFileReferences()` guesses from content with regexes (`ContextCompactor.php:936-1000` [verified]), and only on pairs outside the preserved window |
| Tool-output size in history | 8k per result at request build time (SDK), plus a recovery cache | full output kept until it leaves the 10-pair window; MCP results uncapped |
| LLM summary | main model, warm cache, required-files re-read (classic); separate summariser with mechanical file lists and previous-summary folding (SDK) | separate tool-less `EngineBackend` with a 6-facet per-exchange record (`Chat.php:10569` [verified]); no file re-read; no mechanical file list |
| Transcript integrity | canonical transcript immutable; overlay or sidecar holds the compacted view; prefix-hash validated | compaction rewrites `Chat::$history` in place; persisted transcript is the compacted one |
| Prompt cache | explicit breakpoints (system + last 2 user, classic) | `CacheBreakpoints` DORMANT (baseline §3.5) |

## 5. Prompt generation

### 5.1 Classic system-prompt builder (C3 `core/prompts/system-prompt/`)

**Registry of model-family variants** (`registry/PromptRegistry.ts:39-115`, `variants/index.ts:41-96`):
- Variants are tried in insertion order and the first `matcher(context)` that returns true wins. If none matches, the generic variant is used.
- Order:

  | # | Variant | Notes |
  |---|---|---|
  | 1 | `NATIVE_GPT_5` | |
  | 2 | `GPT_5` | XML |
  | 3 | `NATIVE_GPT_5_1` | |
  | 4 | `GEMINI_3` | |
  | 5 | `NATIVE_NEXT_GEN` | Claude 4+ etc. with native tools |
  | 6 | `GLM` | |
  | 7 | `HERMES` | |
  | 8 | `DEVSTRAL` | |
  | 9 | `NEXT_GEN` | XML |
  | 10 | `TRINITY` | |
  | 11 | `XS` | local Ollama/LM Studio with the "compact" prompt |
  | 12 | `GENERIC` | fallback |

- Each variant declares:
  - a `componentOrder`;
  - a `baseTemplate` with `{{PLACEHOLDER}}` slots;
  - per-component `overrides`;
  - its tool list;
  - labels such as `use_native_tools: 1`.
- `variant-validator.ts` runs at module load in strict mode.

**Pipeline** (`registry/PromptBuilder.ts:23-194`):
1. Build each component, in order.
2. Merge placeholders. Precedence from low to high: variant, then `CWD`/`SUPPORTS_BROWSER`/`MODEL_FAMILY`/`CURRENT_DATE`, then components, then runtime.
3. Resolve the template. `{{a.b}}` dot paths are supported; unknown placeholders are left as-is.
4. `postProcess`:
   - collapses runs of 3+ newlines;
   - removes empty `====` sections and empty `##` headers;
   - avoids touching anything that looks like SEARCH/REPLACE diff text.

**Tool docs depend on the variant.**
- XML variants render each tool as `## name / Description / Parameters: - p: (required) … / Usage: <name><p>…</p></name>`.
- A parameter is dropped when its `dependencies` (other tool ids) are not enabled, or when its `contextRequirements(context)` is false. This is how `task_progress` appears only when the focus chain is on.
- Native variants omit tool prose. The schemas go to the API, converted per provider: Anthropic `input_schema`, Gemini function declarations, OpenAI functions. MCP tools whose names exceed 64 chars are dropped.

**Emitted section order** (generic template, `variants/generic/template.ts:3-49`):

`AGENT_ROLE · TOOL_USE · TASK_PROGRESS · MCP · EDITING_FILES · ACT_VS_PLAN · CAPABILITIES · SKILLS · FEEDBACK · RULES · SYSTEM_INFO · OBJECTIVE · USER_INSTRUCTIONS`

Sections are separated by `====`. Key texts, verbatim:
- **AGENT_ROLE** (`components/agent_role.ts:4-8`): "You are Cline, a highly skilled software engineer with extensive knowledge in many programming languages, frameworks, design patterns, and best practices." Variants override it. For example, Gemini 3 gets "…execute precisely what is requested - implement exactly what was asked for, with the simplest solution…"
- **TOOL_USE, XML variant** (`components/tool_use/index.ts:23-33`, `guidelines.ts:4-23`): "You have access to a set of tools that are executed upon the user's approval. You can use one tool per message…" and "1. In <thinking> tags, assess what information you already have and what information you need to proceed with the task." / "6. ALWAYS wait for user confirmation after each tool use before proceeding."
- **TOOL_USE, native next-gen variant** (`variants/native-next-gen/template.ts:69-71`): "You may use multiple tools in a single response when the operations are independent (e.g., reading several files, searching in parallel). For dependent operations where one result informs the next, use tools sequentially."
- **EDITING_FILES** (`components/editing_files.ts:4-77`):
  - "**Default to replace_in_file** for most changes."
  - "prefer to use a single replace_in_file call with multiple SEARCH/REPLACE blocks. DO NOT prefer to make multiple successive replace_in_file calls for the same file."
  - An **Auto-formatting Considerations** block, omitted in the CLI. It explains that the editor may reformat the file, that "The write_to_file and replace_in_file tool responses will include the final state of the file after any auto-formatting", and that "Use this final state as your reference point for any subsequent edits."
- **ACT_VS_PLAN** (`components/act_vs_plan_mode.ts:5-21`): "In each user message, the environment_details will specify the current mode… PLAN MODE: In this special mode, you have access to the plan_mode_respond tool… the goal is to gather information and get context to create a detailed plan for accomplishing the task, which the user will review and approve before they switch you to ACT MODE…"
- **RULES** (`components/rules.ts:11-41`), selected lines:
  - "You cannot `cd` into a different directory…"
  - "When executing commands, do not assume success when expected output is missing or incomplete. Treat the result as unverified and run follow-up checks…"
  - "When passing untrusted or variable text as positional command arguments, insert `--` before the positional values…"
  - "When fixing a bug, if existing tests fail after your change, your code is likely wrong. Fix your code to pass the tests rather than modifying test assertions…"
  - "You are STRICTLY FORBIDDEN from starting your messages with "Great", "Certainly", "Okay", "Sure"."
  - "At the end of each user message, you will automatically receive environment_details. This information is not written by the user themselves…"
  - "Before executing commands, check the "Actively Running Terminals" section in environment_details."

  The CLI-only rule: "After making code changes, consider running any available validation tools for the project (such as type checkers, linters, test suites, or build scripts) to catch errors, since you won't receive automatic diagnostics after edits."
- **SYSTEM_INFO** (`components/system_info.ts:9-82`): `Operating System`, `IDE`, `Default Shell`, `Home Directory`, then `Current Working Directory`. In multi-root mode the last item is `Workspace Roots` with a VCS per root.
- **OBJECTIVE** (`components/objective.ts:5-14`): "Before using attempt_completion, verify the task requirements with available tools. Confirm required output files exist, required content/format constraints are satisfied, and no forbidden extra artifacts were introduced."
- **USER_INSTRUCTIONS** (`components/user_instructions.ts:5-74`) comes last, in this wrapper: `USER'S CUSTOM INSTRUCTIONS\n\nThe following additional instructions are provided by the user, and should be followed to the best of your ability without interfering with the TOOL USE guidelines.` It concatenates, in order:
  1. preferred language
  2. global `.clinerules/`
  3. local `.clinerules`
  4. `.cursorrules`
  5. `.cursor/rules`
  6. `.windsurfrules`
  7. `AGENTS.md` (every nested one, each as `## relpath`, with "only apply the instructions for each AGENTS.md file that is directly applicable to the current task")
  8. the `.clineignore` text

  Each item has a provenance header, e.g. `# .clinerules/\n\nThe following is provided by a root-level .clinerules/ directory where the user has specified instructions for this working directory (${cwd})` (`responses.ts:312-334`).

The system prompt is **rebuilt on every request** (`C3 core/task/index.ts:1883-2001`), so rule, skill and MCP changes take effect without a restart.

### 5.2 `environment_details`: what is appended to every user turn (C3)

`getEnvironmentDetails(includeFileDetails)` (`C3 core/task/index.ts:3556-3766`) builds a trailing text block of the user message. It is the model's live window onto the IDE. Section headers, in order:

```
<environment_details>
# Visual Studio Code Visible Files        (relative paths, .clineignore-filtered)
# Visual Studio Code Open Tabs
# Actively Running Terminals              ## Original command: `npm run dev`  ### New Output …
# Inactive Terminals                      (only when they have unretrieved output)
# Recently Modified Files
These files have been modified since you last accessed them (file was just edited so you may need to re-read it before editing):
# Current Time                            10/1/2026, 4:12:03 PM (America/New_York, UTC-4:00)
# Current Working Directory (/repo) Files (FIRST request only: recursive listFiles(cwd, true, 200), dirs first, 🔒 on ignored)
# Workspace Configuration                 (first request: JSON of roots, git remotes, latest commit)
# Detected CLI Tools                      (first request: `which` over gh, git, docker, kubectl, aws, npm, cargo, go, jq, make…)
# Context Window Usage                    123,456 / 200K tokens used (62%)
# Current Mode                            ACT MODE   |   PLAN MODE + planModeInstructions()
</environment_details>
```

- **Terminal "cool-down".** Before the block is built, the code waits for busy terminals to settle: poll every 100 ms with a 15 s timeout, plus a 300 ms grace after an edit (`:3600-3611`). The model therefore sees fresh compiler or server output.
- **Recently Modified Files** comes from `FileContextTracker`. It watches files the model has read or edited and reports *external* changes, made by the user or a formatter, since the model last read them. On task resume it escalates to `CRITICAL FILE STATE ALERT: ${n} files have been externally modified since your last interaction… you must execute read_file…` (`responses.ts:336-347`).
- **Context Window Usage** is shown only at ≥ **60%** (`autoCondenseThreshold - 0.15`) for Claude 4+ and GPT-5. Other models always see it (`:3732-3745`).
- **Old blocks are never stripped.** Every past turn keeps its own `environment_details` until truncation hides it. This is a cost Cline accepts in return for cache stability: changing the past would bust the prefix.
- On the auto-compact turn the block is omitted.

### 5.3 User-content preprocessing (C3)

Only text wrapped in `<task>`, `<feedback>`, `<answer>` or `<user_message>` is parsed (`shared/messages/constants.ts:10`). Two kinds of expansion apply.

**@-mentions** (`core/mentions/index.ts:67-317`) are expanded inline with tagged appendices:

| Mention | Appendix |
|---|---|
| `@/path` | `<file_content path=…>` |
| `@/dir/` | `<folder_content>`: the tree, plus every top-level non-binary file |
| `@problems` | `<workspace_diagnostics>` |
| `@terminal` | `<terminal_output>` |
| `@git-changes` | `<git_working_state>` |
| `@<sha>` | `<git_commit>` |
| `@https://…` | `<url_content>` (puppeteer → markdown) |

**Slash commands** (`core/slash-commands/index.ts:52-239`):
- Built-ins: `/newtask /smol /compact /newrule /reportbug /deep-planning /explain-changes`. Each **prepends** an `<explicit_instructions type="…">` block that forces a specific tool response.
- `/mcp:<server>:<prompt>` inserts MCP prompts.
- Workflow files are invoked as `/<file>.md` and injected as `<explicit_instructions type="file.md">…`.
- `/deep-planning` has model-specific variants. It runs a four-step flow:
  1. silent investigation;
  2. questions;
  3. write `implementation_plan.md` with sections `[Overview] [Types] [Files] [Functions] [Classes] [Dependencies] [Testing] [Implementation Order]`;
  4. `new_task` with a `task_progress` checklist and a mode-switch request.

  It ends: "Your role is to plan thoroughly, not to implement."

### 5.4 Focus chain: the todo list that keeps the model on track (C3)

**Settings.** `DEFAULT_FOCUS_CHAIN_SETTINGS = { enabled: true, remindClineInterval: 6 }` (`shared/FocusChainSettings.ts:8-11`).

**Mechanism:**
- Every tool gets an optional `task_progress` parameter: a markdown checklist (`- [ ]` / `- [x]`). It is excluded from loop-detection signatures.
- `updateFCListFromToolResponse` stores the list in `taskState` and **writes it to `<taskDir>/focus_chain_taskid_<id>.md`** with edit instructions as HTML comments (`core/task/focus-chain/file-utils.ts`).
- A chokidar watcher (300 ms debounce) detects **user edits** to that file and sets `todoListWasUpdatedByUser = true` (`focus-chain/index.ts:106-135`).
- `shouldIncludeFocusChainInstructions()` (`:337-358`) is an OR of:
  - `apiRequestsSinceLastTodoUpdate >= 6`;
  - just switched plan→act;
  - the user edited the list;
  - in plan mode;
  - first request with no list;
  - no list after ≥ 2 requests.
- When it fires, a block is pushed into the user message, before `environment_details`:
  ```
  # TODO LIST UPDATE REQUIRED - You MUST include the task_progress parameter in your NEXT tool call.
  **Current Progress: 3/7 items completed (43%)**
  - [x] …
  - [ ] …
  1. To create or update a todo list, include the task_progress parameter in the next tool call
  2. Review each item and update its status: …
  **Note:** 43% of items are complete.
  ```
  If the user edited the list: `**CRITICAL INFORMATION:** The user has modified this todo list - review ALL changes carefully`. Without a list, early in a task it says `# task_progress RECOMMENDED`; after 10 requests it becomes `You've made {{apiRequestCount}} API requests without a task_progress parameter. It is strongly recomended that you create one…` (`focus-chain/prompts.ts`).
- The list lives in task state, not in API history, so **it survives compaction**. Both `summarize_task` and `condense` are told to carry it over unchanged except for completion marks.

**Bugs found:**
- The `completed` ("All N items have been completed!") branch can never be reached: it sits after a `>= 75%` test that already matches.
- The plan→act "CREATION REQUIRED" prompt never fires: its flag is cleared before `loadContext` reads it.

### 5.5 4.x SDK prompt (`sdk/packages/shared/src/prompt/`)

The 4.x prompt is deliberately small. `buildClineSystemPrompt` (`cline.ts:152-213`) fills one of two templates:
- `act.ts:1-36`: "You are Cline, an AI coding agent…"
- `yolo.ts:1-40`: "You are Cline, a careful and helpful coding agent that works in the background. You are tasked to solve an issue reported by the user who you cannot communicate with directly."

Both include a 4-line `<env>` block: Platform, Date (day only), IDE and Working Directory. There are **no model-family variants**. Model specifics moved into tool routing: GPT and Codex models get `apply_patch` instead of `editor` (`core/src/extensions/tools/model-tool-routing.ts:60-75`).

Other notable lines:
- **YOLO RULES:**
  - "If repeated fixes fail without new evidence, stop making similar edits. Test your assumptions with a focused check or minimal reproduction, then adjust your approach based on the result."
  - "Verify by execution, never by assumption."
  - "Treat "this should work", "assume it works", or "probably correct" as a signal that you have NOT verified yet — go run the check instead of finishing."
  - "set 'verified' to true only if your tool output shows the requirements are met".
- **Mode tagging** (`format.ts:5-46`, `cline.ts:15-17`):
  - Every user message is wrapped as `<user_input mode="plan|act|yolo">…</user_input>`.
  - A UI toggle prepends `<mode_notice>The user switched from plan mode to act mode before sending this message.</mode_notice>`. A plan→act→plan round trip before sending cancels out (`createModeSwitchNoticeTracker`).
  - The system prompt explains: "If the mode attribute changes between messages, the user switched modes -- the newest message's mode is what governs right now, regardless of what earlier messages allowed."
- **Plan contract** (`cline.ts:28-53`): "File-editing commands (rm/mv/cp, in-place edits like sed -i, output redirection to files outside /tmp, git commands that change the working tree, package installs) are hard-blocked in plan mode: they are not executed and return a tool error instead…". The CLI adds "use the switch_to_act_mode tool … never call it in the same turn you present a plan and never treat the original task request as approval".
- **Rules** are appended *per run*, under `# Rules\n## <name>\n<instructions>`. Sources:
  - `<ws>/AGENTS.md`, `.clinerules/`, `.cline/rules/`
  - `~/.agents/AGENTS.md`, `~/.cline/rules`, `~/Documents/Cline/Rules`

  (`shared/src/storage/paths.ts:578-593`, `core/src/runtime/safety/rules.ts:10-43`)
- **Workspace git metadata** (remotes, latest commit, branch) is injected *only for the Cline provider* (`cline.ts:79-120`).
- **Hook context** goes in as one user message of `<hook_context source="RunStart|PreToolUse|PostToolUse" …>` blocks, displayed as system. It is inserted *before* a trailing unresolved tool call so pairing is never broken (`agent-runtime.ts:404-424`, `1071-1105`).

### 5.6 Against sugar-crush (baseline §4)

**sugar-crush is ahead on:**
- prompt *stability* engineering (Static → PerSession → PerTurn ordering);
- fenced instruction provenance with escaping;
- nested-`CLAUDE.md` injection on first touch.

**Cline is ahead on:**
- a live view of the environment: running processes, external file changes, context-usage percentage, time with timezone, detected CLI tools, and an initial file tree;
- plan/act mode signalling inside the message stream;
- a recurring todo reminder;
- model-family prompt variants (classic).

sugar-crush's `<env>` (`Context/EnvironmentBlock.php:744-1138`) already re-renders git status and diffs every step. A "recently modified externally" list and a context-percentage line would slot into the same block (§13).

## 6. Memory

Cline has **no memory subsystem**. There is no memory store, no automatic extraction and no recall. Memory is built from rules files and conventions.

**Memory Bank** is a documented convention, not code (`cline-classic/docs/best-practices/memory-bank.mdx:9-157`).
- How it works:
  1. The user pastes a provided custom-instructions block into `.clinerules/memory-bank.md`.
  2. The user says "initialize memory bank".
  3. The model maintains a `memory-bank/` folder of markdown files.
- The six core files:

  | File | Holds |
  |---|---|
  | `projectbrief.md` | foundation, scope |
  | `productContext.md` | why the project exists, UX goals |
  | `activeContext.md` | current focus, recent changes, next steps, learnings |
  | `systemPatterns.md` | architecture, key decisions |
  | `techContext.md` | stack, setup, constraints |
  | `progress.md` | what works, what's left, known issues |

- The instruction text opens: "I am Cline, an expert software engineer with a unique characteristic: my memory resets completely between sessions. … I MUST read ALL memory bank files at the start of EVERY task - this is not optional."
- Updates happen "1. Discovering new project patterns 2. After implementing significant changes 3. When user requests with **update memory bank** (MUST review ALL files) 4. When context needs clarification".
- The recommended loop is: "update memory bank" → start a new task → "follow your custom instructions".
- The FAQ suggests a conditional rule (`paths: memory-bank/**`) so the convention costs tokens only when relevant.

**`/newrule`** (`C3 core/prompts/commands.ts:140-196`) is the built-in way to turn a conversation into a persistent rule.
- The model writes a new `.clinerules/<succinct-name>.md` with sections `## Brief overview`, `Communication style`, `Development workflow`, `Coding best practices`, `Project context`, `Other guidelines`.
- The prompt says not to invent preferences, not to overwrite existing rule files, and that the file should not be "a recollection of the conversation".

**Rule toggles and conditional rules** (C3 `core/context/instructions/user-instructions/`):
- Every rule file has an on/off toggle. New files default to on.
- A `paths:` glob in YAML frontmatter activates the rule only when a candidate path matches (`rule-conditionals.ts:42-106`, `RuleContextBuilder.ts:67-154`). Candidates (at most 100) are gathered from:
  - path-like tokens in the latest user message;
  - visible and open tabs;
  - files the task has edited;
  - pending tool-call paths, including `apply_patch` headers.

  With no evidence the rule is *not* active ("Conservative: no evidence"). The 4.x SDK has no conditional rules.

**Against sugar-crush.** sugar-crush is ahead here:
- a real `MemoryStore` with scopes, a `MEMORY.md` index, `/memory`, a project-local store and foreign import (baseline §5);
- `paths:`-scoped rules that are named in tool results through `RulePathNudge`, which is close to Cline's conditional rules.

Ideas worth borrowing:
- **`/newrule`-style distillation**: the model writes a durable rule or memory note *from the conversation*, which sugar-crush lacks because its auto-memory is ABSENT.
- The Memory Bank's **fixed file taxonomy with an explicit update trigger**, as a built-in skill.

---

## 7. Tools and editing

### 7.1 Tool rosters

**Classic** (`C3 shared/tools.ts:8-36`, `core/task/tools/handlers/*`), all optionally carrying `task_progress`:

| Tool | Parameters |
|---|---|
| `execute_command` | `command`, `requires_approval`, `timeout` (yolo only) |
| `read_file` | `path`, `start_line`/`end_line` |
| `write_to_file` | `path`, `content` |
| `replace_in_file` | `path`, `diff` |
| `apply_patch` | V4A format; GPT-5 variants only |
| `search_files` | `path`, `regex`, `file_pattern` |
| `list_files` | `path`, `recursive` |
| `list_code_definition_names` | `path` |

Other tools:
- `browser_action` (launch, click, type, scroll, close)
- `web_fetch` / `web_search` (Cline provider only)
- `use_mcp_tool`, `access_mcp_resource`, `load_mcp_documentation`
- `ask_followup_question {question, options}`
- `attempt_completion {result, command}`
- `plan_mode_respond`, `act_mode_respond`
- `new_task`, `condense`, `summarize_task`, `new_rule`, `report_bug`
- `generate_explanation {title, from_ref, to_ref}`: opens a multi-file diff with AI inline comments
- `use_skill`, `use_subagents`

**4.x** (`sdk/packages/core/src/extensions/tools/definitions.ts`, schemas in `schemas.ts`). Most tools take arrays:

| Tool | Parameters | Timeout |
|---|---|---|
| `read_files` | `{files:[{path, start_line?, end_line?}]}` | 10 s/file |
| `search_codebase` | `{queries: string[]}` | 30 s |
| `run_commands` | `{commands: string[]}`, also accepts `{command, args}` with no shell | 30 s |
| `fetch_web_content` | `{requests:[{url, prompt}]}` | 30 s |
| `editor` | `{path, old_text?, new_text, insert_line?}` | |
| `apply_patch` | `{input}` | |
| `skills` | `{skill, args?}` | |
| `ask_question` | `{question, options: 2-5}` | |
| `submit_and_exit` | `{summary, verified}` | |

Also: `spawn_agent`, `subagent_*`, `team_*`, `tasks` (schedules and agenda), and `web_search` as a provider-executed model tool.

The schemas are deliberately **lenient unions**:
- `read_files` accepts a string, a string array, `file_path` or `paths`.
- Line numbers given as strings are coerced.
- Heredocs split across array entries are re-joined (`schemas.ts:75-172`, `definitions.ts:116-178`).

This cuts schema-error round trips with weaker models.

### 7.2 `replace_in_file` SEARCH/REPLACE (C3)

**Format** (`C3 core/prompts/system-prompt/tools/replace_in_file.ts:16-40`):
```
------- SEARCH
[exact content to find]
=======
[new content to replace with]
+++++++ REPLACE
```
- Rules: match EXACTLY; each block replaces only the first match; list blocks in file order; use complete lines; to move code, use two blocks; to delete, use an empty REPLACE; never include `42 | ` line-number prefixes.
- The parser also accepts the legacy `<<<<<<< SEARCH` / `>>>>>>> REPLACE` markers, any run of 3 or more marker characters, and an optional trailing `>` (`C3 core/assistant-message/diff.ts:1-41`).

**Matching cascade** (`diff.ts:348-486`, v1):
1. **Exact** `indexOf(search, lastProcessedIndex)`.
2. **Line-trimmed** (`lineTrimmedFallbackMatch`, `:51-103`): every line compared after `.trim()`.
3. **Block-anchor** (`blockAnchorFallbackMatch`, `:132-185`): only for blocks of **≥ 3 lines**. The trimmed first and last lines must match at the same distance apart; middle lines are *not* compared.
4. **Out-of-order**: an exact match before the cursor is recorded, and all replacements are applied sorted by position at the end.
5. Otherwise it throws `The SEARCH block:\n…\n...does not match anything in the file.`

**Streaming.**
- `constructNewFileContent` runs on **every streamed chunk**, so the diff view fills in live.
- A trailing half-written marker line is dropped while streaming.
- An empty SEARCH on a non-empty file is rejected as a malformed marker.

**On failure the model gets the whole file back** (`responses.ts:300-304`):
```
This is likely because the SEARCH block content doesn't match exactly with what's in the file, or if you used multiple SEARCH/REPLACE blocks they may not have been in the order they appear in the file. (...)

The file was reverted to its original state:

<file_content path="${relPath}">
${originalContent}
</file_content>

Now that you have the latest state of the file, try the operation again with fewer, more precise SEARCH blocks. For large files especially, it may be prudent to try to limit yourself to <5 SEARCH/REPLACE blocks at a time, then wait for the user to respond with the result of the operation before following up with another replace_in_file call to make additional edits.
(If you run into this error 3 times in a row, you may use the write_to_file tool as a fallback.)
```

**Other write-side handling:**
- **`write_to_file` with empty content** escalates over 1, 2 and 3 or more failures. At 3: `CRITICAL: You have failed to write this file ${n} times in a row. You MUST change your approach — do NOT retry write_to_file for this file again.` It suggests three strategies (skeleton + `replace_in_file`, split files, …), with a context-usage warning above 50% (`responses.ts:56-97`).
- **Model-specific fixups**: DeepSeek's unescaped HTML entities are repaired, and code fences wrapped around `write_to_file` content are stripped (`WriteToFileToolHandler.ts:492-570`).

**`apply_patch`** (C3 `core/task/tools/utils/PatchParser.ts:259-334`; 4.x `executors/apply-patch-parser.ts:347-431`):
- Context is found in four passes, after canonicalisation (NFC normalisation, unicode dashes and quotes → ASCII, unescaping `` \` ``):
  1. exact (fuzz 0)
  2. `trimEnd` (fuzz 1)
  3. `trim` (fuzz 100)
  4. **similarity ≥ 0.66** (fuzz 1000)
- EOF context is searched from the end first.
- The result reports `Note: Patch applied with fuzz factor ${fuzz}`.
- Classic skips a chunk that does not match and warns. 4.x fails the whole patch.

**4.x `editor`** is *exact match only*:
- `No replacement performed: text not found…` / `multiple occurrences…`
- It caps `old_text`/`new_text` at 6,000 chars ("Split the edit into smaller tool calls").
- It returns a numbered `-N:`/`+N:` diff capped at 200 lines.

### 7.3 Diff-view approval and the user-edit feedback loop (C3 `integrations/editor/DiffViewProvider.ts`)

1. **Open.** The diff view opens before approval, with a snapshot of the pre-edit diagnostics.
2. **Stream.** Content streams into the right-hand pane at up to 10 updates per second (`UPDATE_THROTTLE_MS = 100`), with an animated scroll to the line being written.
3. **Edit or approve.** The user may **edit the proposed content in place** before approving.
4. **Save and compare** (`saveChanges`, `:337-404`). Three texts are compared:
   - the model's content
   - pre-save: the model's content plus the user's edits
   - post-save: after the editor ran format-on-save

   This yields `userEdits` and `autoFormattingEdits` patches. New **error-severity** diagnostics are collected as `New problems detected after saving the file:`.
5. **Tool result** (`responses.ts:265-298`). It states the user's edits as a patch, then:

   `The user's editor also applied the following auto-formatting to your content: … (Note: Pay close attention to changes such as single quotes being converted to double quotes, semicolons being removed or added, long lines being broken into multiple lines … This will help you ensure future SEARCH/REPLACE operations to this file are accurate.)`

   It then returns **the full saved file**: `<final_file_content path="…">…</final_file_content>` with "IMPORTANT: For any future changes to this file, use the final_file_content shown above as your reference."

   Old copies of this block are later removed by the dedup in §4.2.
6. **Reject.** `The user denied this operation. The file was not updated, and maintains its original contents.` Created directories are removed too.
7. **Auto-approved writes** wait 3.5 s "to let the diagnostics catch up" (`WriteToFileToolHandler.ts:234-235`). This is a lightweight LSP feedback loop that needs no LSP client of its own: it reads VS Code's diagnostics.

### 7.4 Shell

**Classic `execute_command`** (`C3 core/task/tools/handlers/ExecuteCommandToolHandler.ts:19-327`, `integrations/terminal/*`):
- Runs in **a real VS Code terminal with shell integration**, by default.
- `requires_approval` is set by the model and drives "safe command" auto-approval.
- Managed timeouts only apply in yolo or background-exec mode. The default is 30 s; known long runners (installs, builds, test runners, docker build, training scripts) get 300 s.
- Otherwise a command runs until done, or until the user clicks **"Proceed While Running"**. The model is then told `Command is still running in the user's terminal… You will be updated on the terminal status and new output in the future.` New output arrives through `environment_details` "Actively Running Terminals".
- Output spills to a log file past 1,000 lines or 512 KB, keeping the first and last 100 lines plus `Full output saved to: ${path}`.
- `fileReadCache` is cleared after every command.

**4.x `run_commands`** (`executors/bash.ts`):
- 30 s default timeout, after which the process tree is killed.
- Several commands per call run **concurrently**.
- Output is middle-truncated at 48,000 chars with `[... output truncated: ${total} chars total. Refine the command (grep, head, tail) to view the elided middle ...]`.
- **`run.proceed_while_running`** detaches a running command: its output goes to `cline-command-*/output.log` (10 MiB cap, 24 h retention) and the tool returns `[Command is still running. Output will continue in ${path}]`.
- Detached commands are tracked across host restarts using PID plus process-start-token identity (`sdk/ARCHITECTURE.md:220-250`).

### 7.5 File reading

- **Classic `read_file`:**
  - 1,000 lines per call, with `N | ` line labels and `(Showing lines a-b of N total. Use start_line=b+1 to continue reading.)`.
  - 20 MB file limit and 400 KB content cap.
  - Extracts text from PDF, DOCX, IPYNB and XLSX. Images become image blocks.
- **4.x `read_files`:** 2,000 lines, 2,000 chars per line, 48,000 chars per read, with `[Showing lines a-b of N. Use start_line/end_line to read other sections.]` (`output-limits.ts:41-47`, `file-read.ts:60-191`).
- **Search:**
  - Classic: ripgrep, 300 results, ±1 line of context.
  - 4.x: ripgrep, 100 results, ±2 lines of context, case-insensitive.
- **`list_code_definition_names`** (classic) uses tree-sitter over at most 50 files.

### 7.6 Browser (C3)

- `browser_action` drives Puppeteer at **900×600**. It is off by default (`disableToolUse: true`) and is offered only to vision-capable models.
- Actions: `launch` (must be first), `click {coordinate}`, `type`, `scroll_down`/`up`, `close` (must be last). Only `browser_action` may run while the browser is open.
- Each action returns a screenshot (webp) and the console logs.
- A remote Chrome on `localhost:9222` is supported.
- The 4.x SDK has no browser tool.

### 7.7 Against sugar-crush (baseline §6)

| Aspect | Cline | sugar-crush |
|---|---|---|
| Edit matching | exact → line-trimmed → block-anchor → out-of-order (classic); patch fuzzy ≥ 0.66 | exact `substr_count === 1` only (`Edit.php:178-197` [verified]) |
| Failed edit | reverts and returns the current file content | `Error: old_string not found in $path; file left unchanged` and nothing else |
| Post-edit state to the model | full `<final_file_content>`, plus user-edit and formatter diffs, plus new diagnostics | `File updated: <path> (+A -R lines)`; the diff goes to the TUI only |
| Read paging | line ranges, line numbers, continuation hint | none: 1 MiB head, no line numbers |
| Multi-file batch | `read_files[]`, `run_commands[]` | parallel `Read` calls through `ParallelSafe` fork |
| Shell timeout | 30 s, or managed, or "proceed while running" with a log file | none; the 120 s idle watchdog kills the *turn* |
| Diagnostics after edit | VS Code diagnostics diffed before and after the save (classic) | LSP DORMANT, no post-edit check |

---

## 8. Git integration

### 8.1 Checkpoints, classic shadow git (C3 `integrations/checkpoints/`)

**Shadow repository.**
- Location: `<globalStorage>/checkpoints/<cwdHash>/.git`.
- It is initialised with `core.worktree = <workspace>`, so git tracks the user's files while the git data lives outside the project. **The user's own `.git` is never touched** (`CheckpointGitOperations.ts:59-115`).
- Config: `commit.gpgSign false`, user `Cline Checkpoint <checkpoint@cline.bot>`.
- One shadow repo is shared per workspace, on one branch. Commit messages are `checkpoint-<cwdHash>-<taskId>`.

**Nested repositories.** Nested `.git` folders are temporarily renamed to `.git_disabled` during `git add . --ignore-errors`, "to work around git's requirement of using submodules for nested repos". They are restored in `finally` with a retry.

**Excludes** are written to `info/exclude` (`CheckpointExclusions.ts:42-325`):
- build and cache directories (`node_modules/ dist/ vendor/ venv/ target/dependency/ …`)
- media files
- archives
- database files (`*.sqlite *.db *.parquet …`)
- `*.env*`
- logs
- every LFS pattern from `.gitattributes`

The workspace `.gitignore` also applies.

**Refusals.** Checkpoints are refused in the home, Desktop, Documents and Downloads directories, and when git is missing. A warning shows at 7 s. At 15 s initialisation is abandoned and checkpoints are disabled for that task.

**When commits are taken:**
- on the first request;
- **after every assistant turn, once all its tools have run** (`C3 core/task/index.ts:3205-3208`);
- on user feedback;
- on `attempt_completion` (awaited, with its hash attached to the completion message).

**Restore** (`integrations/checkpoints/index.ts:238-747`, UI in `CheckmarkControl.tsx`):

| UI option | Action |
|---|---|
| **Restore Files** | `git reset --hard <hash>` in the shadow repo, which rewrites the workspace. The chat is kept. |
| **Restore Task Only** | Truncates the API history to the message's `conversationHistoryIndex + 2`, restores that message's deleted range, rolls back context-overlay edits (`truncateContextHistory`), and leaves files alone. It also records files edited after that point, so the model gets a "files modified" warning next time. |
| **Restore Files & Task** | Both. |

- The cost of discarded requests is kept as a `deleted_api_reqs` entry.
- Editing an old user message offers the same choice: Enter restores the task, Cmd+Enter restores task and workspace.

**Compare and "View Changes".**
- **Compare** opens a multi-file diff from a checkpoint to the current workspace.
- After `attempt_completion`, **View Changes** shows the diff since the previous completion (or the first checkpoint). The `HAS_CHANGES` flag is computed with `git diff --count`.

### 8.2 4.x checkpoints: stash-shaped commits in the real repo (`sdk/packages/core/src/hooks/checkpoint-hooks.ts`)

This version is lighter and cheaper to port.
- A `beforeRun` hook takes **one snapshot per user run** (`:497-710`).
- `createWorktreeStashCommit` (`:341-430`):
  1. Runs `git stash create "cline checkpoint session=<id> run=<n>"`, which captures tracked changes without touching the worktree or the stash list.
  2. Builds an extra commit of **untracked, non-ignored files** using a private `GIT_INDEX_FILE` in a 0700 scratch directory: `ls-files --others --exclude-standard -z`, then `add --force --pathspec-from-file … --pathspec-file-nul`, then `write-tree`, then `commit-tree`. Index entries that went stale are pruned with `update-index --force-remove --stdin`. A corrupt index or stale lock is wiped and rebuilt once.
  3. Combines the results with `commit-tree <tree> -p base -p index -p untracked`, giving a stash-compatible commit with three parents.
  4. If the tree is clean, it records `HEAD` instead (`kind: "commit"`).
- The snapshot is pinned at the **private ref `refs/cline/checkpoints/<session>/<run>`**. It stays reachable through GC but is "invisible to the user's normal `git stash list` workflow".
- Telemetry records the outcome: `stash`, `head_clean`, `head_fallback` or `skipped`.

**Restore** (`core/src/session/checkpoint-restore.ts:44-478`):
1. If HEAD has moved past the checkpoint, the restore is **refused**: "Cannot restore the workspace: N commits were added to the current branch after this checkpoint and would be removed by the restore. Restore the chat only, or move the branch back to <sha> manually".
2. A **restore transaction** first saves the current state to `refs/cline/restore-transactions/<uuid>` using `stash push --include-untracked`, so a failed restore can be rolled back.
3. Then: compare-and-swap `update-ref HEAD`, `reset --hard`, `clean -fd` (only if untracked files were captured), and `stash apply <ref>`.

**Modes and diff.**
- "Restore chat only" vs "Restore chat and workspace". The CLI dialog warns: "This runs git reset --hard and git clean -fd in the workspace."
- Restoring messages **forks a new session** rather than mutating the old one.
- `/undo` in the CLI opens the checkpoint picker.
- `checkpoint-diff.ts` diffs a checkpoint against the worktree, including untracked files.

### 8.3 Other git features

- **No auto-commit and no commit-message generation** in either engine.
  - The VS Code SCM panel has a separate "generate commit message" feature (`apps/vscode`, not studied).
  - Workspace git metadata (remotes with credentials redacted, latest commit, branch) goes to the model on the first request (classic `# Workspace Configuration`) or in the system prompt (4.x, Cline provider only).
- **`@git-changes`** and **`@<sha>`** mentions inline the working state or a commit.
- **`/explain-changes`** with `generate_explanation {title, from_ref, to_ref}` opens a multi-file diff annotated with AI inline comments.
- **`--worktree`** (CLI) creates a detached worktree under `~/.cline/worktrees/`.
- **Cloud handoff** checks git state first (dirty, unpushed, detached HEAD, missing upstream).

### 8.4 Against sugar-crush (baseline §7, §8)

**sugar-crush has:**
- git status, log and post-write diffs in `<env>` every step (better than Cline 4.x, which has no per-turn git state);
- a git MCP server;
- a DORMANT `WorktreeManager`.

**It has no file-level undo at all.** `/rewind` restores only the transcript and tells the user to run `/branch` first (`Chat.php:12317-12424`). Combined with the **default `bypass-permissions` mode**, an agent mistake on disk cannot be undone from inside the tool. This is the single largest safety gap this comparison exposes (§13 P0-1).

## 9. Extensibility

### 9.1 Rules, workflows, skills

**Rules** (§5.1, §5.5, §6):
- Directories: `.clinerules/` or `.cline/rules/` in the workspace, and a global `~/Documents/Cline/Rules` (also `~/.cline/rules`, `~/Cline/Rules`).
- Rule files from other tools are also read: `.cursorrules`, `.cursor/rules/*.mdc`, `.windsurfrules`, and `AGENTS.md` (nested, plus a global `~/.agents/AGENTS.md` in 4.x).
- Each rule can be toggled on or off. Classic also supports conditional `paths:` frontmatter.
- Remote-config (enterprise) rules, workflows and skills are written to `.cline/<plugin>/` and loaded by the same file watchers (`sdk/ARCHITECTURE.md:409-437`). The design rule is: "new instruction sources should usually materialize into files and reuse watcher-based loading".

**Workflows:**
- Locations: `.clinerules/workflows/*.md`, `~/Documents/Cline/Workflows`, `~/.cline/workflows`.
- Invoked as `/name.md`. The file body is injected as `<explicit_instructions type="name.md">` (C3 `core/slash-commands/index.ts:176-239`).
- 4.x deprecates workflows in favour of skills. A workflow whose name collides with a skill is dropped (`core/src/extensions/config/runtime-commands.ts:100-119`).

**Skills:**
- A skill is a `SKILL.md` with `name` (which must equal the directory name in classic) and `description`.
- Search paths: `.clinerules/skills`, `.cline/skills`, `.claude/skills` (classic), `.agents/skills`, `~/.cline/skills`, `~/.agents/skills`.
- They are disclosed progressively: the prompt lists names only, and `use_skill` / `skills` loads the body.
- The 4.x tool text is forceful: "When a skill matches the user's request, invoking this tool is a blocking requirement before any other response. Never mention a skill without invoking this tool."
- Skills double as slash commands.

### 9.2 Hooks

**Events:**

| Engine | Events |
|---|---|
| Classic | `TaskStart, TaskResume, TaskCancel, TaskComplete, PreToolUse, PostToolUse, UserPromptSubmit, Notification, PreCompact` |
| 4.x | the same, plus `TaskError` and `SessionShutdown`. `PreCompact` is discovered but **not run** (`core/src/hooks/hook-file-config.ts:17-43`) |

**Locations:**
- Classic: `~/Documents/Cline/Hooks/<HookName>` and `<ws>/.clinerules/hooks/<HookName>`.
- 4.x adds `~/.cline/hooks` and `<ws>/.cline/hooks`, and accepts any of `.sh .bash .zsh .js .mjs .cjs .ts .py .ps1`. The interpreter is chosen from the shebang or extension.

**Input** (JSON on stdin; `shared/src/hooks/events.ts:168-198`):
- Common fields: `clineVersion, hookName, timestamp, taskId, workspaceRoots, workspaceInfo{remotes, latest commit, branch}, userId, agent_id, parent_agent_id`.
- Per-event payloads: `preToolUse{toolName, parameters}`, `postToolUse{…, result, success, executionTimeMs}`, `userPromptSubmit{prompt, attachments}`, `preCompact{contextJsonPath, contextRawPath}`.

**Output:**
- `{cancel, contextModification|context, errorMessage, overrideInput}`.
- A hook may print `HOOK_CONTROL\t<json>` lines mixed with other output; the last one wins (`core/src/hooks/subprocess-runner.ts:72-88`).
- Context is capped at 50,000 chars.
- Context is injected as `<hook_context source="PreToolUse" tool_name="…" tool_call_id="…">…</hook_context>`. Spoofed tags inside the body are neutralised.

**Timeouts:**
- Classic: 30 s.
- 4.x: 120 s for tool hooks. Run-start hooks are fire-and-forget unless `blockingRunStartHooks` is set, and no host sets it.

**Gaps found in 4.x:**
- `UserPromptSubmit` is asynchronous, so it can neither cancel nor inject.
- `--hooks-dir` sets an env var that no code reads.
- The `review` output field is never used.

**The PreCompact hook in classic is the most interesting one.** It receives the about-to-be-compacted context as JSON and raw files, can **cancel compaction** (which aborts the task), or can add text that is appended to the continuation as `[Context Modification from PreCompact Hook]` (`C3 SummarizeTaskHandler.ts:50-104`).

### 9.3 Plugins (4.x)

**Loading** (`shared/src/extensions/contribution-registry.ts:125-212`, `core/src/extensions/plugin/plugin-sandbox.ts`):
- A plugin is a TypeScript or JavaScript module that exports `{name, manifest:{capabilities, providerIds?, modelIds?}, hooks?, setup(api, ctx)}`.
- Discovered in `.cline/plugins`, `~/.cline/plugins` and `~/Documents/Cline/Plugins`.
- Runs in a **sandboxed subprocess** by default:
  - import timeout 4 s
  - hook timeout 3 s
  - contribution timeout 60 s
  - idle reclaim after 30 min

**What `setup` can register:** tools, slash commands (whose result can be `{reply, submitPrompt}`), rules (optionally gated on whether a tool is available), message builders (which rewrite the outgoing request), providers, automation event types and MCP servers.

**Runtime hooks** (`shared/src/agent.ts`):
- `beforeRun`, `afterRun`
- `beforeModel` (can rewrite messages, tools and options)
- `afterModel`
- `beforeTool` (can `skip`, `stop`, override `input` or `policy`, `appendContext`)
- `afterTool`
- `onEvent`

**agent-plugins.org packages** (`plugin.json` + `mcp.json`) contribute only skills and MCP servers. They are auto-discovered **only** from `~/.agents/plugins`, "so opening a repository cannot activate repository-controlled MCP servers" (`shared/src/storage/paths.ts:630-632`).

### 9.4 MCP

**Classic** (`C3 services/mcp/McpHub.ts`):
- Settings file: `cline_mcp_settings.json`.
- Transports: stdio, sse and streamableHttp.
- Supports tools, **resources** (`access_mcp_resource`, templates), and **prompts** (`/mcp:server:prompt`).
- `autoApprove: string[]` per server.
- MCP tool results are capped at 400 KB.
- The system prompt lists connected servers with their tools, schemas and resources.

**The classic MCP "marketplace"** is the most distinctive part (`C3 core/controller/mcp/downloadMcp.ts:60-70`):
- It fetches the server's README and `llms-install.md`.
- It then **starts an agent task that installs the server itself**. The instructions:
  - "Start by loading the MCP documentation."
  - "Use "${mcpId}" as the server name in cline_mcp_settings.json."
  - "Make sure you read the user's existing cline_mcp_settings.json file before editing it…"
  - "Use commands aligned with the user's shell and operating system best practices."
  - "Once installed, demonstrate the server's capabilities by using one of its tools."
- `load_mcp_documentation` gives the model the full MCP-server authoring guide, so "build me an MCP server for X" is a supported flow.

**4.x** (`core/src/extensions/mcp/*`):
- Settings at `~/.cline/data/settings/cline_mcp_settings.json`. Default timeout 60 s, protocol `2024-11-05`.
- Tools are named `${server}__${tool}`, capped at 64 chars with a sha1 suffix.
- OAuth uses loopback ports 1456-1458.
- **Tools only.** 4.x has no resources and no prompts.
- `cline mcp install <name> --yes -- <cmd…>` / `--url` / `--header`.
- The marketplace catalog (`https://cline.github.io/marketplace/catalog.json`) installs MCP servers, skills (`npx skills add … -a cline`) and plugins.

### 9.5 Against sugar-crush (baseline §9)

**sugar-crush is at least on par for:** skills (with foreign import), custom commands with `!cmd` and `@file`, a `hooks.yaml` with exit-code semantics, project-trust gating, and MCP with OAuth and PKCE.

**What sugar-crush lacks:**
- hook **context injection** from `UserPromptSubmit`/`SessionStart`; its exit-0 stdout only annotates tool results;
- a `PreCompact` hook (DORMANT in sugar-crush);
- MCP resources and prompts;
- a user-level MCP config;
- an agent-driven MCP install flow;
- a plugin API with `beforeModel` request rewriting.

---

## 10. Permissions and safety

### 10.1 Classic auto-approve (C3 `shared/AutoApprovalSettings.ts`, `core/task/tools/autoApprove.ts`)

**Defaults:**
```ts
actions: { readFiles: true, readFilesExternally: false, editFiles: false, editFilesExternally: false,
           executeSafeCommands: true, executeAllCommands: false, useBrowser: false, useMcp: true }
```

**Local vs external paths.** Every file tool gets a `[local, external]` pair. "Local" means inside cwd or any workspace root. An external path needs *both* flags (`autoApprove.ts:122-167`).

**Commands.**
- "Safe" is the model's own `requires_approval=false`.
- `executeSafeCommands` auto-runs those. `executeAllCommands` also auto-runs the ones the model flagged.
- A flagged command gets a `REQ_APP` marker in the UI.

**YOLO / "auto-approve all":**
- approves everything;
- hides `ask_followup_question`, or auto-answers it with "[YOLO MODE: User input is not available…]";
- turns on managed command timeouts;
- fails the task at the mistake limit instead of asking the user.

**A long auto-approved command** triggers a notification at 30 s.

**`CLINE_COMMAND_PERMISSIONS`** (`C3 core/permissions/CommandPermissionController.ts:27-383`) is a JSON env var: `{"allow":["npm *","git status"],"deny":["rm -rf *"],"allowRedirects":false}`. The algorithm, verbatim:
> 1. Parse command into segments split by operators (&&, ||, |, ;) 2. Check for dangerous characters (backticks outside single quotes, newlines outside quotes) 3. If redirects detected and allowRedirects !== true → DENIED 4. Validate EACH segment against allow/deny rules - ALL must pass 5. Recursively validate any subshell contents 6. If no rules are defined (env var not set) → ALLOWED

- Deny rules are checked before allow rules. A command that matches neither under an allow-list is denied (`no_match_deny_default`).
- Parsing uses `shell-quote`. A parse failure blocks the command.
- The model is told: `Command execution blocked by CLINE_COMMAND_PERMISSIONS: ${reason}. You must try a different approach or ask the user to update the permission settings.`

**`.clineignore`** (`C3 core/ignore/ClineIgnoreController.ts`):
- gitignore syntax, with `!include <file>`, watched live.
- Blocked files show 🔒 in listings. A blocked read returns `Access to ${path} is blocked by the .clineignore file settings.`
- `validateCommand` blocks `cat/less/head/tail/grep/awk/sed/…` when they target an ignored file.

**Other guards:**
- Checkpoints are refused in home, Desktop, Documents and Downloads.
- `write_to_file` and `replace_in_file` against paths outside the workspace need the separate "external" approval flag.

### 10.2 4.x

**Tool policy** (`agents/src/agent-runtime.ts:2416-2454`):
- Each tool has a policy `{enabled, autoApprove}`. The SDK **default is `autoApprove: true`** (`shared/src/llms/tools.ts:7-18`).
- The CLI defaults to auto-approving all tools (`apps/cli/src/main.ts:896-902`, `--auto-approve` default true). The status bar shows "Auto-approve all enabled (Shift+Tab)". So Cline's terminal default is permissive, **like sugar-crush's**.
- A rejection is returned to the model with `TOOL_REJECTION_SUFFIX = "NOT a tool or system failure. Clarify with user before proceeding."`. This stops the model from "fixing" a deliberate denial.

**Approvals are brokered through the hub** (`core/src/hub/server/handlers/approval-handlers.ts:10-83`):
- `approval.requested {approvalId, toolName, inputJson, policy}` is broadcast to every attached client.
- **Pending approvals are re-sent to a client that reconnects.**
- A non-interactive session is denied with "Tool approval requires an interactive session".
- **This is how a runtime in another process asks a UI process for permission.** It is exactly the plumbing sugar-crush's forked turn child is missing (baseline §9.5).

**VS Code 4.x** keeps the classic AutoApprovalSettings. It maps them live onto policies and forces `autoApprove:false` for read, edit, command, browser and MCP tools so each call is decided at runtime (`apps/vscode/src/sdk/sdk-tool-policies.ts:13-64`).

**Plan-mode command guard:** see §3.1.

**Not carried into 4.x:** `CLINE_COMMAND_PERMISSIONS` and `.clineignore` enforcement on SDK tools.

**Secrets:**
- Git remote URLs have credentials redacted before reaching the prompt (`shared/src/prompt/cline.ts:55-77`).
- Marketplace command output is redacted.
- Checkpoint excludes `*.env*`.
- The hub token lives in an owner-only discovery file and is compared in constant time.

### 10.3 Against sugar-crush (baseline §9.5)

**sugar-crush's model is richer on paper:**
- six modes;
- `permissionRules`;
- a regex `SafetyClassifier`;
- `ProtectFilesHook` / `ConfirmRemoveHook`;
- trust keys.

**But on the live TUI path an Ask cannot be answered.** That is why the default is `bypass-permissions`.

**What Cline shows is the missing piece:**
1. a *bidirectional* approval channel between the process that runs the turn and the UI (hub `approval.request`/`respond`, re-sent on reconnect);
2. **checkpoints as the safety net that makes permissive defaults acceptable**.

**Cline's segment-wise shell parser** (split on `&& || | ;`, deny redirects, backticks and newlines, recurse into subshells) is also better than sugar-crush's rules, which match on tool name only. sugar-crush's `Bash(git *)` grants **all** of Bash (baseline §2.1).

---

## 11. UX

**Classic VS Code UI:**
- **Streaming diff view in the editor.** The user can edit before approving, and the edits are sent back to the model.
- Checkpoint markers on every step, with **Compare** and **Restore** (3 modes).
- **View Changes** after completion.
- A per-request cost and token row (`api_req_started` with tokensIn/out, cacheWrites/reads, cost), plus the task total.
- Context-window bar.
- Focus-chain checklist in the chat, editable as a markdown file.
- Plan/Act toggle with a per-mode model.
- Mention autocomplete (`@file`, `@problems`, `@terminal`, `@git-changes`, URL).
- Approve/Reject buttons where the user can type feedback instead.
- "Proceed While Running" for long commands.
- A subagent panel with per-child tool, token and cost stats.
- `deleted_api_reqs` keeps the cost of discarded turns honest after a restore.
- Notifications for approvals ("Cline is having trouble…") and for auto-approved commands still running after 30 s.

**4.x CLI (OpenTUI):**
- **Status bar** (`apps/cli/src/tui/components/status-bar.tsx:43-278`):
  - model and reasoning level;
  - a segmented context bar with `(tokens) $cost` (cost hidden for subscription plans);
  - the Plan/Act toggle;
  - `workspace (branch) | N files +a -d` live git diff stats;
  - the auto-approve indicator with its Shift+Tab hint.
- **Queued prompts panel:**
  - "Enter with empty input to steer first · ↑ select or edit"
  - "↑/↓ navigate, Enter steer, Tab edit"
  - The user can reorder, edit or promote queued prompts while a run is in flight.
- `/undo` checkpoint picker, offering "Restore chat only" or "Restore chat and workspace".
- `/fork`, `/compact`, `/model`, `/mcp`, `/plugins`, `/skills`, `/history`, `/team`.
- **Sessions:**
  - `cline history export <id> -o file.html`, plus FTS5 full-text search over history.
  - **Import of Claude Code, Codex and opencode sessions** (`core/src/services/session-import/`). An imported session is compacted on its first turn, so foreign tool calls are never replayed.
- `cline doctor` cleans up stale hub, CLI and sidecar processes and locks. Hub `upgrade`/`drain` replace the daemon without dropping accepted runs.
- **Output and integration:**
  - `--json` NDJSON event stream: `run_start, agent_event, team_event, run_result, …`.
  - `--acp` Agent Client Protocol server for IDEs (Zed, …).
  - Desktop notifications through the hub's `ui.notify`.
  - Browser dashboard (`apps/cline-hub`) with live clients, sessions, streaming chat, approvals and the marketplace.
- **4.x removed the focus chain.** A grep for `task_progress`/`focus_chain` finds nothing in the SDK or CLI. The `tasks` tool's `kind:"todo"` is a separate longer-lived "Agenda" that needs approval. This is a regression compared with classic.

**Against sugar-crush (baseline §10):**
- **sugar-crush already matches or beats** the 4.x CLI on: panes, mouse, themes, the palette, session tabs, prompt suggestions, inline images, the diff gutter, and thinking rows.
- **Worth copying:**
  - the steer/queue panel;
  - live git diff stats in the status bar;
  - checkpoint restore with chat/workspace options;
  - HTML export;
  - session import from other agents;
  - an NDJSON event stream for `-p`;
  - ACP;
  - completion notifications.

## 12. Comparison table

sugar-crush statuses come from the baseline. Rows marked [verified] were re-checked in source for this report.

| Feature | Cline (C3 = classic 3.89, SDK = 4.x) | sugar-crush status | Gap |
|---|---|---|---|
| Agent loop, streaming, native tool calls | both | LIVE (sglang/custom/bedrock/vertex); OpenAI provider drops tool calls (§1.3) | small |
| Step cap | none; mistake + loop limits instead | `maxSteps=8` LIVE [verified] | **8 is low with no doom-loop guard; cap and guard are the wrong way round** |
| Identical-call loop detection (soft 3 / hard 5) | C3 + SDK | ABSENT [verified] | **large** |
| Consecutive-mistake counter → ask the user | C3 (3), SDK (6, CLI 3) | ABSENT | medium |
| Output-length recovery (compact+retry, concise nudge ×3) | SDK | ABSENT (notice only, `Chat.php:1733` [verified]) | medium |
| Context-overflow recovery (compact, retry once) | C3 (quarter truncate), SDK | ABSENT | medium |
| Transient-error retry with backoff | C3 (3: 2/4/8 s), SDK (3: 1/2/4… ≤15 s) | LIVE (3, 0.5 s base; never after the first token) | none |
| Empty-response retry | C3, SDK | ABSENT (inferred) | small |
| Mid-run steering | SDK (`steer` aborts stream, injected next iteration) | ABSENT; prompts queue (LIVE) | **large** |
| Parallel tools | SDK array tools, parallel `spawn_agent` | LIVE (`ParallelSafe` fork) | none |
| Sub-agents with own context | C3 `use_subagents` (≤5, read-only), SDK `spawn_agent`/`subagent_*` | `Task` LIVE (parallel, resumable) | small |
| Sub-agent own model | SDK configured agents | DORMANT (`model` ignored on the engine path) | medium |
| Sub-agent return contract ("Relevant file paths") | C3 | ABSENT (free text) | small |
| Teams: task board, mailbox, mission log, async runs | SDK | DORMANT (`TeamManager`, `Mailbox`, `TaskList`) | **large; wire, don't write** |
| Plan/Act with per-mode model and hard command guard | C3 (prompt), SDK (guard + `switch_to_act_mode`) | `PermissionMode::Plan` exists, but Ask = deny on TUI; no plan prompt | medium |
| Mode tagging of user messages (`<user_input mode>`, `<mode_notice>`) | SDK | ABSENT | small |
| Model-family prompt variants | C3 (12 variants) | ABSENT (one base prompt; per-family *sampling* defaults only) | medium/low |
| Per-turn environment block | C3 `environment_details` (tabs, terminals, external edits, time, ctx %) | `<env>` LIVE (git status/diff, date) | medium: no external-edit list, ctx %, time |
| Initial file tree | C3 (200 entries, first request) | ABSENT (composer-only repo map) | medium for non-PHP repos |
| Todo / focus chain with periodic re-injection | C3 (every 6 requests, user-editable file); SDK dropped it | ABSENT (baseline §2.3) | **large** |
| Compaction timing | C3 every request; SDK every iteration | only at `Chat::submit()` | **large** |
| Duplicate/stale file-read dedup | C3 (path-keyed, ≥30% skips truncation), SDK (`[outdated]`, 64 KB batched) | content-sniffing `compactFileReferences` [verified] | **large** |
| Model-written summary with file re-read | C3 `summarize_task` (Required Files, 8 files/100k) | LLM 6-facet summary LIVE; no file re-read | medium |
| Mechanical file-ops list in summary | SDK (`## Files Read/Edited` from tool calls) | ABSENT | small |
| Immutable transcript + compaction overlay/sidecar | C3 overlay, SDK `.compaction.json` + prefix hash | in-place rewrite of history | medium |
| Per-result truncation at request build | SDK 8k/result + 6 MB cap | ABSENT (MCP uncapped) | medium |
| Oversized-result recovery cache | SDK `cline://cache` URIs, 5-iteration TTL | ABSENT | medium |
| Structured cross-turn tool replay | both (tool_use/tool_result pairs; `"result missing"` filler) | **lossy**: earlier tool calls replayed as assistant text (`EngineBackend.php:2071-2082` [verified]) | **large** |
| Prompt-cache breakpoints | C3 system + last 2 user msgs; SDK last user msg | `CacheBreakpoints` DORMANT | medium (Anthropic/Bedrock) |
| Edit fuzzy fallbacks | C3 line-trimmed, block-anchor, out-of-order; patch ≥0.66 | ABSENT (exact only) [verified] | medium |
| Failed edit returns file content | C3 | ABSENT | small |
| Post-edit final content / formatter / user-edit diff to model | C3 | ABSENT (diff goes to the TUI only) | medium |
| Read with line ranges and numbers | both | ABSENT | medium |
| Shell timeout / background / detach-to-log | C3, SDK | ABSENT; 120 s idle watchdog kills the turn | **large** |
| Segment-wise command allow/deny | C3 `CLINE_COMMAND_PERMISSIONS` | name-only rules (`Bash(git *)` = all Bash) | medium |
| Ignore file for agent access | C3 `.clineignore` | `ProtectFilesHook` for secrets only | small |
| Interactive approval from the running loop | C3 in-process; SDK hub `approval.request/respond`, re-sent on reconnect | **ABSENT on the TUI engine path** (Ask → deny) | **large** |
| Workspace checkpoints + 3 restore modes | C3 shadow git; SDK stash refs + restore transaction | ABSENT (`/rewind` is transcript only) | **largest** |
| Checkpoint diff ("Compare"/"View changes") | C3, SDK | ABSENT | medium |
| Auto-commit / commit message generation | none | ABSENT | none |
| Hooks with context injection | C3, SDK (`<hook_context>`) | exit-0 stdout appended to tool results only; Stop/PreCompact DORMANT | medium |
| PreCompact hook (cancel/augment) | C3 | DORMANT event | small |
| Plugins with `beforeModel`/message builders | SDK (sandboxed) | PHP `HookInterface` needs an embedder | low priority |
| MCP resources/prompts | C3 | ABSENT | medium |
| Agent-driven MCP install from README/`llms-install.md` | C3 | ABSENT | low |
| Memory | conventions (Memory Bank), `/newrule` | `MemoryStore` LIVE (sugar-crush ahead) | sugar-crush ahead; add `/newrule`-style distillation |
| Conditional (path-scoped) rules | C3 | `paths:` rules + `RulePathNudge` LIVE | parity |
| Session import from other agents | SDK (Claude Code, Codex, opencode) | memory/agents/skills import only | small |
| NDJSON event output / ACP | SDK CLI | `text|json` only; no ACP | small |
| Scheduled agents / connectors | SDK cron + Slack/Telegram/… | ABSENT | out of scope |
| Live git stats in status bar | SDK CLI | ABSENT | small |

---

## 13. Recommended improvements for sugar-crush

Each item lists:
- the idea and why it matters;
- **Cline:** how Cline does it, with file references;
- **Implementation:** how to build it in sugar-crush, preferring to WIRE dormant code;
- **Effort:** S, M or L.

### P0: safety net and loop integrity

**P0-1. Workspace checkpoints with three restore modes.**

*Why.* sugar-crush defaults to `bypass-permissions` because the TUI cannot ask (baseline §9.5). Today a bad Edit, Write or `Bash` cannot be undone. Cline's own docs make the argument: "Checkpoints make auto-approve practical… The cost of a mistake drops to nearly zero" (`docs/core-workflows/checkpoints.mdx`).

*Cline.* The 4.x `createWorktreeStashCommit` (`sdk/packages/core/src/hooks/checkpoint-hooks.ts:248-430`) does:
- `git stash create` for tracked changes;
- a third parent holding untracked files, built with a scratch `GIT_INDEX_FILE` (`ls-files --others --exclude-standard -z` → `add --pathspec-from-file` → `write-tree` → `commit-tree`);
- a pin at a private ref `refs/cline/checkpoints/<session>/<run>`.

Restore (`core/src/session/checkpoint-restore.ts:44-478`):
- refuses if HEAD moved;
- saves a rollback stash first;
- then `reset --hard` → `clean -fd` → `stash apply`.

Classic offers Files / Task only / Files & Task (`C3 integrations/checkpoints/index.ts:238-747`). Use the classic shadow-git (`core.worktree`) variant for directories that are not git repos.

*Implementation.*
- Add a `Session\WorkspaceCheckpoint` class that shells out through the existing `Support\ProcessContainment` with the git env hardening already in `Tools/Concerns/CapturesProcessOutput.php`.
- In `Chat::dispatchTurn()` (`src/Chat.php:7963-7993` [verified]), add `'workspaceRef' => WorkspaceCheckpoint::snapshot($root, $sessionId, $n)` to the existing `$chatState`. `EnhancedSessionStore::saveCheckpoint` already stores arbitrary state and caps it at 100 per session.
- Snapshot once per user turn, as the SDK does. Optionally also snapshot inside `EngineBackend::runTurn()` after any step where `Runtime::stepRequestedAWrite()` is true. That hook point already exists (`EngineBackend.php:974` [verified]).
- Extend `/rewind [n] [--files|--chat|--both]` (`Chat.php:12317-12424`) and add a palette action.
- Add `/diff [n]` to show `git diff <ref>`, reusing `Tui/DiffGutter`.
- Refuse in `$HOME` (Cline refuses home, Desktop, Documents and Downloads).
- *Effort:* M.

**P0-2. Doom-loop detection plus a consecutive-mistake counter. Then raise `maxSteps`.**

*Why.* `maxSteps = 8` (`EngineBackend.php:262` [verified]) is the only brake. It stops legitimate long tasks and does not catch real loops. Cline has no step cap and relies on loop and mistake guards.

*Cline.* `C3 core/task/loop-detection.ts`: signature = tool name + sorted-key JSON, ignoring `task_progress`.
- At 3 consecutive identical calls, inject `Tool [X] has been called 3 times consecutively with identical arguments. This is not making progress…`.
- At 5, escalate.

Mistakes: every turn in which all tools failed increments a counter; at 3 (classic) or 6 (core), ask the user or stop (`core/src/runtime/safety/mistake-tracker.ts`).

*Implementation.*
- Add `Runtime\LoopGuard` (pure, unit-testable).
- Call it in `EngineBackend::runTurn()` inside `for ($step…)` (`:862-978` [verified]), after tool results are collected.
- **Soft:** append the notice to the *last `ToolResultMessage`'s content*, as sugar-crush already does for `RulePathNudge`/`SkillPathNudge`, so tool-call pairing stays intact.
- **Hard:** break the loop with a new `loopStopped` flag next to `stepsTruncated`, and surface it through `Chat::stepsTruncatedNotice()`.
- Raise the `maxSteps` default to 50, the same default sub-agents already get (`TaskTool.php:135`).
- *Effort:* S.

**P0-3. Structured cross-turn tool replay.**

*Why.* Earlier turns' tool calls reach the model as anonymous assistant text (`EngineBackend::toTypedMessages()`, `:2071-2082` [verified]). This loses which tool produced what, wastes tokens, and makes path-keyed dedup (P0-4) impossible. Cline always replays proper `tool_use`/`tool_result` pairs and repairs gaps (`C3 ContextManager.ts:375-477`, `"result missing"`; SDK `message-builder.ts:51-52`).

*Implementation.*
- Chat history rows already carry `toolResults[]` with `name`, `id` and `arguments` (`src/ToolResult.php:100-112`; `Chat::replaceToolRunningPlaceholder()` `:3974-4007` attaches `pendingToolArguments` [verified]).
- In `toTypedMessages()`, turn each run of tool-result rows into an `AssistantMessage(toolCalls: [...])` followed by `ToolResultMessage`s with matching ids.
- Run the result through the existing `Messages\HistorySanitizer` so orphans and interrupted calls stay well-formed.
- *Effort:* M.

**P0-4. Path-keyed duplicate and stale file-read dedup, applied at request build time.**

*Why.* Re-reads of the same file are the largest avoidable cost in long sessions. sugar-crush's `ContextCompactor::compactFileReferences()` (`:936-1000` [verified]) guesses "file-ness" with regexes and runs only at submit time.

*Cline.*
- Classic groups `read_file` results, `<final_file_content>` blocks and `@file` mentions by path, and keeps only the newest. If that saves ≥ 30% of characters, truncation is skipped (`ContextManager.ts:626-1188`).
- The SDK rewrites a superseded read to `[outdated - see the latest file content]`, batched until ≥ 64 KB is reclaimable to protect the prefix cache (`message-builder.ts:372-454, 933-1005`).

*Implementation.*
- Add `Messages\StaleReadPruner`. It works on the typed messages after P0-3: for each `ToolResultMessage` from `Read` (and from `Edit`/`Write` if P1-6 lands), key on `arguments.file_path`, and replace all but the newest with a fixed notice.
- Apply it in `Runtime::run()` before `HistorySanitizer::sanitize()`, so it covers every step.
- Use the SDK's 64 KB batching rule so the implicit SGLang prefix cache is not invalidated on every read (sugar-crush's prompt-stability design depends on that cache, baseline §3.5).
- Keep `compactFileReferences()` for legacy rows. Do not remove it.
- *Effort:* S–M.

**P0-5. Bidirectional approval channel from the forked turn child to the TUI.**

*Why.* This is the root cause of the bypass default. Every Ask is denied with "no approver is attached to this run" (`Runtime::settleAsk()`, `src/Runtime.php:2629-2643` [verified]). The Veil y/n/a modal already exists in `Chat::requestPermission()` (`:2666`).

*Cline.*
- Classic runs the loop in the UI process and awaits `ask()`.
- 4.x runs it in a hub daemon and brokers approvals: `approval.request` → client `approval.respond`, pending approvals re-sent on reconnect, non-interactive sessions denied explicitly, the session marked `pending` while it waits (`core/src/hub/server/handlers/approval-handlers.ts:10-83`, `local-runtime-host.ts:790-811`).

*Implementation.* The socketpair from `EngineBackend::completeAsync()` (`:1343`) is already full-duplex.
1. Give the child a `permissionApprover` closure that writes an `ask` frame (`{id, toolCall, message}`) and blocks reading the socket for an `answer` frame.
2. In the parent's frame pump, turn `ask` into a `Chat` message that opens the existing Veil modal. Write back `answer`.
3. Keep the 120 s watchdog paused while an ask is pending, the way the SDK marks the session `pending`.

Once this works, change the default mode to `accept-edits` or `default`, *after* P0-1 lands.
- *Effort:* M–L.

### P1: context, prompt and editing quality

**P1-1. Compaction and recovery between steps, not only at submit.**

*Cline.*
- The SDK checks on every iteration: `requestInputTokens >= 0.9 × maxInput`, where the estimate is calibrated against provider-reported tokens (`compaction.ts:303-362`).
- On a provider overflow it compacts and retries once (`agent-runtime.ts:1310-1355`).
- On `finish_reason=length` with no tool call: compact and retry, then up to 3 nudges with `MAX_TOKENS_RECOVERY_NUDGE`.

*Implementation.*
- In `EngineBackend::runTurn()`, before `Runtime::run()` on step > 0, use `$assistant->usage()` (already collected into `$stepUsages`) as the "previous request tokens" signal, Cline-classic style.
- When it crosses the `CompactorConfig` 85% threshold, apply `StaleReadPruner` (P0-4), then `ContextCompactor::truncateOversizedExchange()` (already LIVE) to the in-turn messages.
- Catch the provider's context-length error in `Runtime::runStreaming()` (`TransientFailure` classifies errors already) and retry once after pruning.
- Handle `lengthStopped` with no tool calls by appending Cline's nudge text as a user message and continuing, at most 3 times.
- *Effort:* M.

**P1-2. Main-model summaries that name their working set, plus an automatic re-read.**

*Cline.* `summarize_task` section 9 "Required Files" → re-read ≤ 8 files / 100k chars (`SummarizeTaskHandler.ts:113-210`). The SDK also adds a *mechanical* `## Files\nRead: …\nEdited: …` built from tool calls (`compaction-shared.ts:669-701`, `extractFileOps`).

*Implementation.* sugar-crush's 6-facet `COMPACT_SUMMARY_PROMPT` (`Chat.php:10569`) already has a `files:` facet. In `applyModelCompaction()` (`:11550`):
1. Derive read and edited paths from the compacted rows' `toolResults[].arguments` (available after P0-3).
2. Append them as a fixed `## Files` block.
3. Read up to 8 of the most recently edited files (100k-char budget, through `Read`'s `PathJail`) and attach them to the first post-compaction user turn as `<file_content>`, the way Cline does.
- *Effort:* M.

**P1-3. A todo/progress tool with periodic re-injection (the focus chain).**

*Why.* Long tasks drift after compaction. Cline-classic's checklist lives *outside* the history, so it survives compaction, and it is re-injected every 6 requests (`C3 core/task/focus-chain/index.ts:143-358`, `prompts.ts`).

*Implementation.*
- Add a `TodoWrite`-style tool (`Tools/BuiltIn/TodoTool.php`) whose state goes in `SessionMeta` (currently a DORMANT payload, baseline §8). Mirror it to `<root>/.sugar-crush/todo/<session>.md` and watch that file for user edits, as Cline does.
- Re-inject the list as a `PerTurn` system section that sits just before `<env>`. Add it to `Runtime::systemPromptSections()` so the stable prefix stays unchanged. Inject when `stepsSinceTodoUpdate >= 6`, after a user edit, or when no list exists after 2 steps.
- Show it in the Agents or Tools pane.
- Teams (P2-1) can later reuse the DORMANT `TaskList` as the multi-agent board.
- *Effort:* M.

**P1-4. Mid-turn steering.**

*Cline.* `notifyPendingUserMessage()` aborts only the in-flight model stream. `consumePendingUserMessage()` appends the steer before the next request (`agent-runtime.ts:631-634, 1634-1645, 2244-2263`). The CLI's queue panel offers "Enter steer, Tab edit".

*Implementation.*
- `Chat::enqueuePrompt()` (`:7533`) already queues.
- Add a `steer` frame from parent to child over the same socket as P0-5.
- In `runTurn()`, check the socket non-blockingly at each step boundary. If a steer is waiting, append `UserMessage(text)` to `$app->messages` before the next `Runtime::run()`.
- Optionally abort the current stream through the existing `CancellationToken` and keep the partial text, as Cline does.
- Key binding: Enter on an empty input while a turn is running = "steer first queued prompt".
- *Effort:* M.

**P1-5. A richer `<env>`: externally modified files, context %, local time, running jobs.**

*Cline.* `environment_details` (`C3 core/task/index.ts:3556-3766`) includes:
- `# Recently Modified Files` from `FileContextTracker` (files the model read that changed on disk without it);
- `# Context Window Usage` shown at ≥ 60%;
- `# Current Time` with the IANA timezone.

*Implementation.*
- `EnvironmentBlock` (`src/Context/EnvironmentBlock.php:744-1138`) already re-renders every step.
- Track `path → mtime` at Read time (`Read.php` already threads per-session state through `CarriesSessionState`).
- At render time, list read paths whose mtime moved without an Edit or Write by the agent.
- Add the context % from `Chat::estimateTokenCount()`/`contextTokenLimit()`, passed through `EngineBackend`.
- Keep these lines at the end of the PerTurn block, so the cache cost is the same as today's date line.
- *Effort:* S.

**P1-6. A more forgiving Edit, with better failure feedback.**

*Cline.* Exact → line-trimmed → block-anchor (≥ 3 lines, first and last lines as anchors) (`C3 core/assistant-message/diff.ts:51-185, 348-486`). On failure it returns the current file content with "try the operation again with fewer, more precise SEARCH blocks" (`responses.ts:300-304`).

*Implementation.* In `Tools/BuiltIn/Edit.php` (`:178-197` [verified]), when `substr_count === 0`:
1. Try a line-trimmed match.
2. Then a block-anchor match.
3. Accept a candidate only if it is **unique** in the file, which keeps sugar-crush's uniqueness guarantee.

On final failure, include the nearest-matching region (or the whole file if it is under about 16 KB) in the error text. Optionally add a `Read` `offset`/`limit` with `N | ` line labels and a continuation hint (§7.5).
- *Effort:* S–M.

**P1-7. Shell timeouts and detach-to-log instead of killing the turn.**

*Cline.* The SDK `run_commands` uses a 30 s default, kills the process tree on timeout, and caps output at 48k chars (middle-truncated). `proceed_while_running` detaches the command to a log file and tells the model `[Command is still running. Output will continue in ${path}]` (`executors/bash.ts:395-897`).

*Implementation.*
- Add `timeout` (default 120 s, max 600 s) and `run_in_background` to `Bash.php`.
- For background runs, reuse `BackgroundSupervisor`'s double-fork plus log approach (`src/Sessions/BackgroundSupervisor.php`) to spawn the command with a log path, and return the path.
- Make sequential tools emit heartbeats so a silent `make` no longer trips the 120 s idle watchdog (baseline §1.4).
- *Effort:* M.

**P1-8. A real plan mode: prompt, hard guard and mode tagging.**

*Cline.*
- 4.x plan contract (`shared/src/prompt/cline.ts:28-53`).
- A `beforeTool` command guard that blocks file-editing shell constructs with a teaching error (`core/src/extensions/tools/command-guard.ts:23-74, 514-520`).
- `<user_input mode>` and `<mode_notice>` (`shared/src/prompt/format.ts:5-78`).
- `switch_to_act_mode`, which only fires after an explicit approval.

*Implementation.*
- `PermissionMode::Plan` already refuses write tools in `PermissionGate::evaluatePlan()`.
- Add a `Context/Sections/PlanModeSection.php` that is PerTurn and present only in plan mode.
- Add a `PlanModeCommandGuardHook` modelled on the DORMANT `BashEscapeDenyHook`. Register it in `Bootstrap::hooks()` when the mode is Plan.
- Add a Shift+Tab mode toggle in `KeyBindingRegistry` (the drift test is mandatory), which prepends a `<mode_notice>` to the next user message.
- Allow an optional `planModel` config, the way `titleModel`/`summaryModel` already exist.
- *Effort:* M.

### P2: wiring dormant subsystems and polish

**P2-1. Wire the dormant team stack, the Cline 4.x way.**

*Cline.* `team_*` tools (`core/src/extensions/tools/team/team-tools.ts:197-860`). The runtime lives in `multi-agent.ts`:
- concurrency 2;
- async runs with `runId`;
- the mailbox delivered mid-run through the steer seam (`[MAILBOX] You got a message from…`);
- crash recovery;
- the lead completion guard.

*Implementation.*
- Construct `TeamManager` in `Bootstrap::chat()` and call `AgentManager::setTeamManager()` (`AgentManager.php:1903` [verified]). Today `createTeam()` throws because nobody does this.
- Expose `Mailbox` (`send/receive/peek`) and `TaskList` (`addTask/claimTask/completeTask/getUnblockedTasks`, `TaskList.php:98-499` [verified]) as `team_*` tools, bound through `DelegatesToEngine` like `TaskTool`.
- Deliver mailbox messages at each step boundary of a teammate's `runTurn()` (the same seam as P1-4).
- Finish the inert `GroupInputCmd`/`CancelAgentCmd` (`App::consumeShellCmd()`).
- *Effort:* L.

**P2-2. Wire `CacheBreakpoints`.**

*Cline.* Classic puts breakpoints on the system prompt plus the last two user messages (`C3 core/api/transform/anthropic-format.ts:14-90`). Bedrock uses `cachePoint` (`bedrock.ts:864-1146`).

*Implementation.*
- Call `CacheBreakpoints::apply()` (`src/Providers/CacheBreakpoints.php:258`) in `BedrockProvider` and the `VertexProvider` Anthropic route, and in `CustomProvider` when the endpoint is Anthropic-compatible.
- Wire `observeCacheHealth()` (`:342`) into `Chat`'s usage notice.
- *Effort:* S–M.

**P2-3. A sub-agent return contract and its own model.**

*Cline.* The classic subagent suffix (`SubagentBuilder.ts:24-36`) requires a final "Relevant file paths" section. 4.x `subagent_*` honours `providerId`/`modelId`/`maxIterations` (`configured-agent-tool.ts:133-143`).

*Implementation.*
- Append the suffix in `AgentManager::resolveBatchSystemPrompts()` for read-only presets.
- Honour the preset `model` in `TaskTool::runOnEngine()` (`:557-561`) through `EngineBackend::withModel()` when the provider is the same.
- *Effort:* S.

**P2-4. Hook context injection and the PreCompact/Stop events.**

*Cline.*
- `UserPromptSubmit`/`TaskStart` hooks return `contextModification`, delivered as `<hook_context source="…">` (CHANGELOG 4.1.20).
- PreCompact can cancel compaction or add to it (`C3 SummarizeTaskHandler.ts:50-104`).

*Implementation.*
- Have `Chat::dispatchTurnHooks()` (`:4581`) collect exit-0 stdout and add it to the user message as a fenced `<hook-context>`, escaped with `PromptFence::escape`.
- Dispatch the DORMANT `HookEvent::PreCompact` from `scheduleParkedCompaction()`/`compactNow()`.
- Dispatch `Stop` when a turn ends.
- The `HOOKS.md` drift tests must be updated.
- *Effort:* S.

**P2-5. `/newtask` handoff and `/newrule` distillation.**

*Cline.* `/newtask` (`C3 core/prompts/commands.ts:4-56`): the model writes a 5-section context that seeds a **new** session. `/newrule` (`:140-196`): the model writes a rule file from the conversation.

*Implementation.*
- `/handoff`: run the summary backend with the `/newtask` text, then `EnhancedSessionStore::create` + `switch` with that summary as the first user message. Reuse the `/branch` plumbing (`Chat.php:12023`).
- `/remember-rules`: write to `<root>/.sugar-crush/rules/<slug>.md` through the existing `RuleLoader` directory, or to `ProjectMemoryWriter`.
- *Effort:* S each.

**P2-6. Segment-aware command permission rules.**

*Cline.* `CommandPermissionController` (`C3 core/permissions/CommandPermissionController.ts:27-383`) does the following:
- splits on `&& || | ;`;
- denies redirects unless allowed;
- denies backticks and newlines outside quotes;
- requires every segment to pass;
- recurses into subshells;
- checks deny before allow.

*Implementation.* Teach `PermissionRule` to match `Bash(<glob>)` against each parsed segment instead of the tool name only (baseline §9.5: argument patterns never match today). Reuse it in `AgentManager::resolveGrantedTools()` so `Bash(git *)` stops granting all of Bash.
- *Effort:* M.

**P2-7. Oversized-result cache.**

*Cline.* SDK `ToolResultCache`: an 8k preview plus a `cline://cache/...` URI readable only by the read tool; 5-iteration idle expiry; 16 MiB per session.

*Implementation.*
- Cap `McpToolBridge` results (currently uncapped, `McpToolBridge.php:587-622`) and `WebFetch` at about 16 KB in the prompt.
- Save the full text to `sys_get_temp_dir()/sugarcrush-results/<session>/<id>.txt` (mode 0600, like `SuspendedDelegations`).
- Let `Read` accept that path with a line range.
- *Effort:* M.

**P2-8. Model-family prompt variants for the primary targets.**

*Cline.* The classic `PromptRegistry` matches by family and applies component overrides (`C3 core/prompts/system-prompt/registry/PromptRegistry.ts:59-115`, `variants/*/overrides.ts`).

*Implementation.* sugar-crush already branches sampling per family in `SglangProvider`. Add a small `Context/PromptVariant` with overrides for the base identity and tool-use sections, keyed on the same family detection (DeepSeek-V4, Qwen3.8, MiniMax). Keep it Static-stability so prefix caching is unaffected.
- *Effort:* M. Lower priority.

---

## 14. Problems in sugar-crush exposed by this comparison

1. **Permissive default with no undo.**
   - Cline's CLI is also auto-approve-by-default, but it pairs that with per-run checkpoints and a "Restore chat and workspace" option.
   - sugar-crush pairs `bypass-permissions` (`Bootstrap.php:166`) with **no file-level undo**: `/rewind` restores the transcript only.
   - That is the riskiest default found in this comparison. Fix P0-1 before anything cosmetic.
2. **The step cap is the only loop guard, and it is set too low.**
   - With `maxSteps = 8` [verified], real multi-file tasks end in `stepsTruncated`.
   - A loop of 8 identical failing calls still runs to the cap, because nothing detects repetition.
   - Cline has no step cap: loop detection (3/5) and mistake counters (3/6) do the work. (P0-2)
3. **Tool history is replayed as unattributed text.**
   - `toTypedMessages()` maps role only [verified], so the model never sees which tool or arguments produced an earlier output.
   - This defeats precise compaction and dedup, and it lets old tool output look like the assistant's own prose. Treating tool output as data rather than instructions is a point the base prompt itself makes (MaximsSection). (P0-3)
4. **Context can only shrink between user turns.**
   - A single long agentic turn has no in-turn compaction, no overflow recovery and no length-stop recovery.
   - Cline's SDK checks on every iteration and has a 3-stage recovery. (P1-1)
5. **Compaction rewrites the only copy of the history.**
   - sugar-crush's compacted rows replace `Chat::$history`, and that compacted history is what gets persisted. *(Inferred: `persistTranscript()` writes the current `$history` after every update.)*
   - Cline keeps the canonical transcript immutable. It stores the compacted view separately (a classic overlay, or the SDK `.compaction.json` validated by a prefix hash), so `/rewind` and later re-compaction work from full fidelity.
   - Consider persisting the pre-compaction transcript beside the checkpoint rows.
6. **Duplicate-read detection works by guessing.**
   - `isFileReadMessage()` treats any content containing `<?php`, or a line starting with a path-looking token, as a "file read" [verified]. Ordinary assistant prose that quotes code can be collapsed to `[file: …, N lines]`.
   - Cline keys on the tool name and path header. (P0-4)
7. **Silent long commands kill the whole turn.** With no Bash timeout and no heartbeat from sequential tools, a quiet build longer than 120 s trips the idle watchdog and SIGKILLs the turn child (baseline §1.4). Cline's 30 s / 300 s managed timeouts and its detach-to-log avoid this. (P1-7)
8. **Edits fail harder than they need to.** Exact-only matching with a terse error gives the model no material to recover from. Cline's three fallbacks plus "here is the file as it is now" make most retries succeed on the next try. (P1-6)
9. **Plan mode cannot be used in the TUI.** Its Ask verdicts are denied (no approver). There is also no plan-mode prompt section, so the model does not know it is in plan mode until a write is refused. (P0-5, P1-8)
10. **No external-change awareness.** If the user edits a file the agent already read, nothing tells the agent before its next `Edit`. sugar-crush's `old_string` then fails, or worse, matches stale content. Cline's "Recently Modified Files" and the "CRITICAL FILE STATE ALERT" on resume address this. (P1-5)
11. **Sub-agent preset `model` is silently ignored on the live path.** Cline's configured agents honour `modelId`. A user who writes `model: haiku` in a sugar-crush preset gets the parent model, with no warning (baseline §2.1). At minimum, emit a launch notice. (P2-3)
12. **Bugs found in Cline that sugar-crush should not copy:**
    - Classic `summarize_task`'s example labels Required Files as section 8 while the regex wants section 9, so the file re-read silently does nothing.
    - The focus chain's "All items completed" branch is unreachable.
    - The plan→act "create a list" prompt never fires.
    - 4.x loop-detection soft notices are appended to the `ConversationStore` but are probably dropped on the next `replaceMessages` sync (they carry no id and no `displayOnly`).
    - 4.x `spawn_agent` allows unlimited nesting and runs child tools without approval.

    When porting any of these mechanisms, add tests for the trigger path itself, not only for the formatter.
