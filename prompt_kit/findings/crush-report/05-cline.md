# Cline vs sugar-crush: competitor deep-dive

Feeds steps: 0.4-a, 0.4-b, 0.5, 0.7, 0.10, 0.11, 0.12, 1.A-1, 1.B-2, 1.B-3, 1.C-1, 1.C-2, 1.C-3, DEF-MODE, 2.1, 2.2-1, 2.2-2, 2.3, 2.4-1, 2.5, 2.6, 2.7-1b, 2.7-2, 2.8, 2.9, 2.12, 3.A-1, 3.A-2, 3.B-4, 3.C, 3.D-1, 3.D-2, 3.F, 3.I-1, 3.I-2, 3.I-3, 4.1-1, 4.1-2, 4.3-2, 4.4, 4.6-2, 4.7-2, 4.7-3, 5.7-1, 5.7-2, 5.8, 5.9-1, 5.10, 5.14a, 5.14c, 5.14d, 5.14f, 5.14j, O-2f

**Sources.** Two Cline engines: **C3** = classic v3.89.2 (`/home/sites/crush-research-repos/cline-classic/apps/vscode/src/`) and **SDK** = 4.1.22 (`/home/sites/crush-research-repos/cline/sdk/packages/`); the 4.x CLI is under `.../cline/apps/cli/src/`. sugar-crush symbols are under `/home/sites/sugarcraft/sugar-crush/`; current line anchors are in `impact/*.md`.

---

## 2. Agent loop

### 2.1 Classic loop (C3)

**Retries.**
- Task-level `autoRetryAttempts` (2 s, 4 s, 8 s; `index.ts:2113-2114`) cover a first-chunk failure, a mid-stream failure (task re-initialised from disk, "Resume" clicked programmatically) and an **empty response**, which is recorded as the synthetic assistant message `"Failure: I did not provide a response."` After 3 attempts the user is asked `api_req_failed`. (→ 0.10, 2.7-1b)

**Context-window-exceeded errors.** The first one is handled automatically: truncate with `"quarter"` (keep a quarter of the middle) and retry. A second one asks the user: "Context window exceeded. Click retry to truncate the conversation and try again." (`index.ts:2034-2060`, `1801-1863`). (→ 2.7-1b)

**Repeated reads have their own guard** (→ 2.3). `ReadFileToolHandler.ts:341-356` keys reads on path plus mtime. From the 3rd unchanged read it prefixes the content: `[DUPLICATE READ] You have already read '${displayPath}' ${n} times in this conversation. The content has not changed since your last read. Please use the information you already have and proceed with your task.`

**Rejection with feedback** (→ 1.C-2).
- Typing a message instead of pressing *Approve* counts as rejecting the pending tool. The text is attached as `The user provided the following feedback:\n<feedback>…</feedback>` and `didRejectTool = true` is set. Every later tool in the same message gets `Skipping tool due to user rejecting a previous tool.` (`C3 core/task/tools/utils/ToolResultUtils.ts:107-149`, `ToolExecutor.ts:325-331`).
- Typing while a command runs delivers the text *with* the partial output: `Command is still running in the user's terminal.\nHere's the output so far:\n…\n\nThe user provided the following feedback:\n<feedback>…` (`C3 integrations/terminal/CommandOrchestrator.ts:610-631`).

### 2.2 SDK loop (4.x): `sdk/packages/agents/src/agent-runtime.ts`

**Recovery stack** (all constants from `agent-runtime.ts:61-121`):

| Failure | Handling |
|---|---|
| Transient provider error | `PROVIDER_ERROR_MAX_RETRIES = 3`, backoff `min(1000·2^(n-1), 15000)` ms. Never retried for auth or context-overflow errors (`:1189-1261`). |
| Context overflow (provider rejects) | **Compact and retry once** with `prepareTurn({overflowRecovery:true})`. Fails with an explicit "nothing to compact" message unless compaction actually shrank the request (`:1310-1355`, `:2205-2228`). (→ 2.7-1b) |
| `finish_reason = length` with no tool call | Step 1: compact and retry once (`retryTruncatedTurnWithCompaction`, `:1428-1542`). Step 2: up to `MAX_TOKENS_RECOVERY_LIMIT = 3` nudges: `"Your previous response was cut off because it reached the model's output-token limit before finishing. Keep responses concise: take one small step at a time, avoid long explanations, and write large files or command output in smaller chunks across multiple tool calls."` (→ 2.7-2) |
| Empty response | Middleware retries up to 3 attempts, buffering each "until it proves itself". A tool-call-only turn counts as content (`llms/src/providers/middleware/retry-empty-response.ts:1-187`). (→ 0.10) |
| Content filter | Terminal: `"Model returned no content because the response was blocked by a content filter. Retrying is unlikely to help — try rephrasing the request."` |

**Mid-run steering** (`core/src/runtime/turn-queue/pending-prompt-service.ts`) (→ 1.C-3):
- Pending prompts are delivered as either `"queue"` or `"steer"`.
- A queued prompt runs as a new turn after the run ends.
- A steer prompt calls `agent.notifyPendingUserMessage()`. That aborts **only the current model stream**: "Interrupt only the current model request; running tools finish normally." (`agent-runtime.ts:631-634`).
- At the next iteration (`iteration > 1`), `consumePendingUserMessage()` appends the steer as a user message *before* the model request (`agent-runtime.ts:1634-1645`, `2244-2263`; wired in `core/src/runtime/host/local-runtime-host.ts:817-824`).
- The interrupted stream keeps its visible text but drops partial tool JSON and unsigned reasoning ("A cancelled stream may contain incomplete tool JSON or unsigned reasoning. Keep only replayable visible content", `:1959-1965`).
- A cancel issued before `run-started` is forwarded once the run starts (`agent-runtime.ts:1033-1041`).

## 3. Agents and sub-agents

### 3.1 Plan mode (→ 5.7-1, 5.7-2)

**Classic (C3)**
- Plan and Act, with an optional separate model for each.
- **Plan mode is enforced mostly by prompt.** `PLAN_MODE_RESTRICTED_TOOLS` only fires when `strictPlanModeEnabled` is on (default **false**); `execute_command` is never restricted (`C3 core/task/ToolExecutor.ts:291-357`). Pitfall: don't rely on prompt-only enforcement.
- The model talks to the user through `plan_mode_respond {response, needs_more_exploration, task_progress}`.
- Switching mode during a pending plan answer injects `[The user has switched to ACT MODE, so you may now proceed with the task.]` (`C3 core/task/tools/handlers/PlanModeRespondHandler.ts:137-152`).

**4.x**
- `plan` is the `act` tool preset **without the editor** (`sdk/packages/core/src/extensions/tools/presets.ts:57-148`).
- In plan mode, `run_commands` is guarded by `createPlanModeCommandGuardExtension` (`core/src/extensions/tools/command-guard-extension.ts`), a `beforeTool` hook returning `skip` with an explanation for any file-editing construct: `rm mv cp dd touch mkdir ln chmod chown truncate patch rsync …`, mutating `git`/`npm`/`pip`/`cargo`/`composer` subcommands, and output redirection anywhere other than `/tmp`.
- The block message (teaching error): "Command not executed: ${reason} can modify files, and file modifications are blocked in plan mode. You are in PLAN MODE — explore, analyze, and present a plan; do not make changes. … put it in your plan so it can run after the user approves switching to act mode." (`command-guard.ts:514-520`)
- The CLI gives the model a `switch_to_act_mode` tool (`apps/cli/src/runtime/interactive/mode.ts:37-67`):
  - Its description says: "only call this after the user has explicitly approved the plan in a message sent AFTER you presented it".
  - It has `lifecycle.completesRun: true`. The session is rebuilt in act mode and continues with `"The user approved switching to act mode. Continue with the approved plan now."`

### 3.3 4.x sub-agents: `spawn_agent` and configured `subagent_*`

**`spawn_agent {systemPrompt, task}`** (`core/src/extensions/tools/team/spawn-agent-tool.ts:30-202`):
- Declared with `executionMode: "parallel"` and `timeoutMs: 300000`. Each call builds a separate `SessionRuntime` that inherits provider, model, hooks and extensions, and is aborted with the parent.
- **Pitfalls not to copy** (→ 4.7-3, 4.1-2): it has **no depth limit** (every sub-agent gets `spawn_agent` again, `runtime/host/local/spawn-tool.ts:132-147`), and **no approvals run inside it** (the child is built without `toolPolicies`; SDK default policy is `autoApprove: true`).

**Configured agents** (→ 4.1-1) live in `<ws>/.cline/agents/*.yaml` and `~/.cline/agents/*.yaml` (`configured-agent-config.ts:8-16`):
- Frontmatter `name, description, tools, skills, providerId, modelId, maxIterations`. Each becomes a `subagent_<name>` tool with input `{prompt}`; it may use **its own model** (`configured-agent-tool.ts:133-143`).
- Classic tool names in `tools:` are translated, e.g. `replace_in_file→editor` (`runtime-builder.ts:101-135`).

**Classic sub-agent context guard** (→ 4.7-2): each `use_subagents` runner has its own auto-compact at 75% of its window (`SubagentRunner.ts:816-821`).

### 3.4 4.x agent teams: persistent multi-agent with communication (→ 4.6-2, 4.4, 4.3-2)

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
| `team_status` / `team_cleanup` / `team_shutdown_teammate` | Housekeeping |

**Runtime** (`multi-agent.ts`):
- `maxConcurrentRuns = 2` by default, with priority dispatch.
- Teammate API timeout of 10 min. Heartbeat every 2 s. Retry backoff `min(30000, 1000·2^n)`.
- **Crash recovery.** An interrupted run is re-queued with "This is an automatic recovery of interrupted team run ${run.id}. The previous process stopped before completion. Continue the task safely, inspect the current workspace state before making changes, and avoid duplicating completed work."

**Communicating with a running agent** (→ 4.4). When a message is sent to a teammate that is running, the teammate gets `[MAILBOX] You got a message from ${from}. Subject: "${subject}". Use the team_read_mailbox tool to read it at your convenience.` This goes through the same `consumePendingUserMessage` steer seam as user steering (`multi-agent.ts:935-943, 1567-1597`), so it arrives at the teammate's next iteration. When a new run starts, unread mail is placed in front of its task.

**The lead cannot quit early.** A completion guard re-prompts it: `[SYSTEM] You still have team obligations. ${parts}. Use team_run_task to delegate work, or team_task with action=complete to mark tasks done, or team_await_runs to wait for active runs. Do NOT stop until all tasks are completed.` (`runtime-builder.ts:823-853`)

**Persistence.**
- SQLite tables `team_tasks, team_runs, team_members, team_mailbox, team_mission_log, team_outcomes…`, with a JSON file fallback (`services/storage/team-store.ts:17-37`).
- Data lives under `~/.cline/data/teams/<name>/`. Run results are cut to 4,000 chars.
- Teammates are respawned on the next launch, and `recoverActiveRuns()` resumes in-flight work.

**Cancellation.** Aborting the lead session cancels queued and running teammate runs (`cancelOutstandingWork("parent_session_abort")`). Teammate definitions survive for later turns.

## 4. Context handling and compaction

### 4.1 Classic programmatic truncation: `ContextManager` (C3)

**Token accounting** (→ 2.1) uses provider-reported numbers, not an estimate. Before each request the manager reads the *previous* request's `api_req_started` record and computes `tokensIn + tokensOut + cacheWrites + cacheReads`. The comment explains why: "This is the most reliable way to know when we're close to hitting the context window" (`C3 core/context/context-management/ContextManager.ts:240-249`).

**Window budget** (→ 2.1, 2.9) (`C3 core/context/context-management/context-window-utils.ts:10-35`):
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

**Hide, don't delete** (→ 1.B-3, 1.B-2):
- The stored `api_conversation_history.json` stays complete. The deleted range is a *mask* applied when each request is built, and it is saved on the task's history item.
- Each UI message records the `conversationHistoryIndex` and deleted range in effect when it was created, so a checkpoint restore can rebuild the exact masked history (`C3 core/task/message-state.ts:204-205`).
- After a cut, tool results whose matching `tool_use` was removed are dropped. `ensureToolResultsFollowToolUse` re-pairs everything else and fills any gap with `"result missing"` (`ContextManager.ts:375-505`).

**Notices** (`C3 core/prompts/responses.ts:10-18`):
- The first assistant message is replaced by `[NOTE] Some previous conversation history with the user has been removed to maintain optimal context window length. The initial user task has been retained for continuity, while intermediate conversation history has been removed. Keep this in mind as you continue assisting the user. Pay special attention to the user's latest messages.`
- On the summarise, condense and error paths, the original task text is also replaced, with `[Continue assisting the user!]`.

### 4.2 Duplicate file-read deduplication (C3) (→ 2.3, 2.2-2, 0.7)

Context is an editable overlay on top of an immutable transcript.

**Overlay structure.**
- `contextHistoryUpdates: Map<messageIndex, [EditType, Map<blockIndex, ContextUpdate[]>]>`, where each `ContextUpdate` is `[timestamp, updateType, update, metadata]` (`ContextManager.ts:13-53`).
- It is persisted as `context_history.json` next to the transcript.
- When a request is built, the newest edit per block is applied to *deep clones*. The stored history is never modified.
- On checkpoint restore, `truncateContextHistory(timestamp)` rolls back every overlay edit made after the restored point (`:552-601`). (→ 3.A-2)

**What counts as a file read** (`:809-1188`) — keyed on the tool/header, never on content sniffing (→ 0.7):

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

### 4.3 Classic auto-compact: `summarize_task`, `condense`, `new_task` (C3) (→ 2.5, 2.6, 2.12, 5.14c, 3.B-4)

**Trigger** (`C3 core/task/index.ts:2513-2618`). The trigger needs all of:
- the `useAutoCondense` setting;
- previous-request tokens ≥ `maxAllowedSize`;
- more than 2 active messages, so a summary is never summarised;
- the file-read optimisation *not* already saving 30%.

When the trigger fires, `environment_details` and mention parsing are **skipped** for that turn; the prompt below is appended to the user message, and the model must answer with `summarize_task` (or `attempt_completion`). The summary is produced by the main model on the main conversation, so the prompt cache is hot ("about the same as any other tool call", `docs/features/auto-compact.mdx`). (→ 2.4-1)

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
1. Runs the **PreCompact hook**. The hook can cancel compaction, which aborts the task, or add context. (→ 2.12)
2. Parses `9. Required Files:` with `/9\.\s*(?:Optional\s+)?Required Files:\s*((?:\n\s*-\s*.+)+)/m`.
3. **Automatically re-reads those files** (→ 2.6). Limits are `MAX_FILES_LOADED = 8`, `MAX_FILES_PROCESSED = 10`, `MAX_CHARS = 100_000`. It respects `.clineignore` and reads a file only if reading it would be auto-approved. The header is: `The following files were automatically read based on the files listed in the Required Files section: … These are the latest versions of these files - you should reference them directly and not re-read them:`
4. Returns `continuationPrompt(summary) + files` as the tool result:
   ```
   This session is being continued from a previous conversation that ran out of context. The conversation is summarized below:
   ${summaryText}.

   Please continue the conversation from where we left it off without asking the user any further questions. Continue with the last task that you were asked to work on. Pay special attention to the most recent user message when responding rather than the initial task message, if applicable.
   If the most recent user's message starts with "/newtask", "/smol", "/compact", "/newrule", or "/reportbug", you should indicate to the user that they will need to run this command again.
   ```
5. Sets the deleted range with `keep = "none"`. On the next request, it extends the range by 2 more messages so the summarisation exchange itself is hidden.

**Known bug (don't copy).** The example block in the prompt labels the section `8. Optional Required Files`, but the regex only accepts `9.`. A model that copies the example gets no files loaded. Test the trigger path, not only the formatter.

**User-triggered variants** (`C3 core/prompts/commands.ts`):
- **`/smol` and `/compact`** send `<explicit_instructions type="condense">`. Its 6 sections are: Previous Conversation, Current Work, Key Technical Concepts, Relevant Files and Code, Problem Solving, Pending Tasks and Next Steps. The user **previews and approves** the summary; on acceptance the model is told to ONLY ask what to do next (`formatResponse.condense()`, `responses.ts:20-21`). (→ 3.B-4 `/compact --self`)
- **`/newtask`** (→ 5.14c) sends `<explicit_instructions type="new_task">`. The model writes a 5-section context (Current Work, Key Technical Concepts, Relevant Files and Code, Problem Solving, Pending Tasks and Next Steps, "include direct quotes from the most recent conversation … verbatim"). The user previews it, and clicking the button starts a **fresh task seeded with that context** (`commands.ts:4-56`).

### 4.4 4.x SDK compaction (`sdk/packages/core/src/extensions/context/`)

**Architecture** (→ 1.B-3, 2.2-2).
- `@cline/agents` exposes a `prepareTurn` hook that projects messages *only for the outgoing request*. The canonical transcript stays append-only and at full fidelity.
- `@cline/core` installs a compaction pipeline with a strategy registry `{ basic, agentic }` (`compaction.ts:161-186`).
- The latest compacted working context is persisted separately as `${sessionId}.compaction.json` (`core/src/session/models/session-compaction.ts:25-34`) with:
  - `source_message_count`
  - `source_prefix_hash`: sha256 over `"cline-session-compaction-source-v2\n"` + count + per-message `[role, content, agent, sessionId, metadata, modelInfo, metrics]`, with ids and timestamps deliberately excluded (`:85-138`).
- On resume, the state is reused **only if the hash of the current transcript prefix matches**. The projection is then `[...state.messages, ...canonical.slice(source_message_count)]` (`:168-198`).

**Trigger** (→ 2.1) (`compaction.ts:303-362`, constants in `compaction-shared.ts:13-35`):
- `requestInputTokens` is estimated as chars/3 over `{systemPrompt, messages, tools}` (`shared/src/llms/tokens.ts:8-12`). The comment: "Uses 3 chars/token (slightly over-counts vs the conventional 4) so trigger thresholds fire before provider rejection".
- When the *provider-reported* input tokens of the previous request exceed the estimate, the budget is scaled down by up to `MAX_INPUT_UNDERESTIMATE_FACTOR = 4`.
- `shouldCompact = requestInputTokens >= maxInputTokens * 0.9`, where `maxInputTokens` defaults to `contextWindow * 0.9`.
- The target is 0.5 × max input for long conversations (≥ 5 pairs), otherwise 0.7 × trigger.
- **The check runs on every iteration, not only on user turns.**

**Agentic strategy** (→ 2.4-1, 2.5) (`agentic-compaction.ts:116-318`):
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

**Basic (deterministic) strategy** (→ 2.2-1, 2.7-1b) (`basic-compaction.ts:443-711`):
- From its docblock: "Typed user prompts always survive. The latest typed turn keeps its newest messages verbatim within the token target … Older turns keep their concluding assistant answer when it fits … Everything else is dropped and re-surfaced as dropped-work summaries attached to the surviving prompts."
- The dropped-work block is `<SYSTEM_NOTICE>\nEarlier context was compacted. Summary of your actions after the request above:\nFiles read:\n…\n\nFiles edited:\n…\n\nCommands ran:\n…</SYSTEM_NOTICE>`. It is built mechanically, with commands clipped to 100 chars and edited line ranges parsed from the editor's diff.
- Overflow recovery always uses basic, so recovery never depends on a second model call succeeding. Agentic failures in auto mode also fall back to basic (`compaction.ts:480-565`).

**Per-request message builder** (→ 2.2-1, 2.3, 1.B-2) (`core/src/session/services/message-builder.ts:29-62`):
- Every tool result string is middle-truncated to `8_000` chars (`...[truncated N chars]...`).
- User file attachments are capped at 50,000 chars, and the whole request at 6 MB (kept rare because "budget truncation rewrites bytes mid-transcript, which invalidates provider prefix caches").
- **Stale-read rewriting.** An older `read_files` result is rewritten to `"[outdated - see the latest file content]"` when it was superseded by a later read of the same path and range, or by a later full read. Rewrites are **batched until at least 64 KB is reclaimable**, "to avoid breaking provider prefix caches on every re-read" (`:38-40`, `:372-454`, `:933-1005`).
- Missing tool results are synthesised: `"Tool execution was interrupted before a result was produced."`

**Oversized-result cache with recovery URIs** (→ 2.8, 0.5) (`core/src/session/services/tool-result-cache.ts`, `sdk/DOC.md:1-8`):
- MCP and Composio tools declare `resultPolicy: "cache-oversized"`.
- The model receives an 8k preview plus `Full result is temporarily saved to cline://cache/<session>/<id>.result.txt. Only read_files can access this cache URI. Use read_files with specific line ranges if omitted content is needed.`
- Entries expire after **5 model iterations without a read**. The per-session cap is 16 MiB, with LRU eviction.
- A miss says `"Cache not found. Make a new tool call for the latest result again if needed. DO NOT repeat side-effecting actions to recover output."`

**Runtime strategy switch.** The 4.x CLI lets the user switch compaction strategy at runtime (`agentic|basic|off`, `apps/cli/src/utils/compaction-mode.ts:3-43`).

## 5. Prompt generation

### 5.1 Classic system-prompt builder (C3 `core/prompts/system-prompt/`) (→ 5.10, 5.14j)

**Registry of model-family variants** (`registry/PromptRegistry.ts:39-115`, `variants/index.ts:41-96`):
- Variants are tried in insertion order and the first `matcher(context)` that returns true wins; otherwise the generic variant is used. Order: `NATIVE_GPT_5`, `GPT_5`, `NATIVE_GPT_5_1`, `GEMINI_3`, `NATIVE_NEXT_GEN`, `GLM`, `HERMES`, `DEVSTRAL`, `NEXT_GEN`, `TRINITY`, `XS` (local Ollama/LM Studio, "compact" prompt), `GENERIC`.
- Each variant declares a `componentOrder`, a `baseTemplate` with `{{PLACEHOLDER}}` slots, per-component `overrides`, its tool list and labels such as `use_native_tools: 1`. `variant-validator.ts` runs at module load in strict mode.
- Example override: Gemini 3's AGENT_ROLE gets "…execute precisely what is requested - implement exactly what was asked for, with the simplest solution…".
- Native next-gen TOOL_USE: "You may use multiple tools in a single response when the operations are independent (e.g., reading several files, searching in parallel). For dependent operations where one result informs the next, use tools sequentially." (`variants/native-next-gen/template.ts:69-71`)

**RULES lines worth reusing** (`components/rules.ts:11-41`) (→ 5.10):
- "When executing commands, do not assume success when expected output is missing or incomplete. Treat the result as unverified and run follow-up checks…"
- "When passing untrusted or variable text as positional command arguments, insert `--` before the positional values…"
- "When fixing a bug, if existing tests fail after your change, your code is likely wrong. Fix your code to pass the tests rather than modifying test assertions…"
- CLI-only: "After making code changes, consider running any available validation tools for the project (such as type checkers, linters, test suites, or build scripts) to catch errors, since you won't receive automatic diagnostics after edits."
- **OBJECTIVE** (`components/objective.ts:5-14`): "Before using attempt_completion, verify the task requirements with available tools. Confirm required output files exist, required content/format constraints are satisfied, and no forbidden extra artifacts were introduced."

**USER_INSTRUCTIONS** (→ 5.14j) (`components/user_instructions.ts:5-74`) comes last, in this wrapper: `USER'S CUSTOM INSTRUCTIONS\n\nThe following additional instructions are provided by the user, and should be followed to the best of your ability without interfering with the TOOL USE guidelines.` It concatenates, in order:
  1. preferred language
  2. global `.clinerules/`
  3. local `.clinerules`
  4. `.cursorrules`
  5. `.cursor/rules`
  6. `.windsurfrules`
  7. `AGENTS.md` (every nested one, each as `## relpath`, with "only apply the instructions for each AGENTS.md file that is directly applicable to the current task")
  8. the `.clineignore` text

  Each item has a provenance header, e.g. `# .clinerules/\n\nThe following is provided by a root-level .clinerules/ directory where the user has specified instructions for this working directory (${cwd})` (`responses.ts:312-334`). 4.x rule sources: `<ws>/AGENTS.md`, `.clinerules/`, `.cline/rules/`, `~/.agents/AGENTS.md`, `~/.cline/rules`, `~/Documents/Cline/Rules` (`shared/src/storage/paths.ts:578-593`).

### 5.2 `environment_details`: what is appended to every user turn (C3) (→ 1.A-1, 3.I-2)

`getEnvironmentDetails(includeFileDetails)` (`C3 core/task/index.ts:3556-3766`) builds a trailing text block of the user message. Section headers, in order:

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

- **Recently Modified Files** comes from `FileContextTracker`. It watches files the model has read or edited and reports *external* changes, made by the user or a formatter, since the model last read them. On task resume it escalates to `CRITICAL FILE STATE ALERT: ${n} files have been externally modified since your last interaction… you must execute read_file…` (`responses.ts:336-347`). (→ 3.I-2)
- **Context Window Usage** is shown only at ≥ **60%** (`autoCondenseThreshold - 0.15`) for Claude 4+ and GPT-5. Other models always see it (`:3732-3745`).
- **Terminal "cool-down".** Before the block is built, busy terminals are polled every 100 ms with a 15 s timeout, plus a 300 ms grace after an edit (`:3600-3611`).
- **Old blocks are never stripped.** Every past turn keeps its own `environment_details` until truncation hides it — changing the past would bust the prefix cache. On the auto-compact turn the block is omitted.

### 5.3 User-content preprocessing (C3) (→ 5.8, 5.14d)

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

**Slash commands** (`core/slash-commands/index.ts:52-239`): built-ins `/newtask /smol /compact /newrule /reportbug /deep-planning /explain-changes` each **prepend** an `<explicit_instructions type="…">` block that forces a specific tool response.

### 5.4 Focus chain: the todo list that keeps the model on track (C3) (→ 3.C)

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

**Bugs found (don't copy):**
- The `completed` ("All N items have been completed!") branch can never be reached: it sits after a `>= 75%` test that already matches.
- The plan→act "CREATION REQUIRED" prompt never fires: its flag is cleared before `loadContext` reads it.
- 4.x dropped the focus chain entirely (a regression).

### 5.5 4.x SDK prompt (`sdk/packages/shared/src/prompt/`)

- **YOLO RULES** (→ 5.10):
  - "If repeated fixes fail without new evidence, stop making similar edits. Test your assumptions with a focused check or minimal reproduction, then adjust your approach based on the result."
  - "Verify by execution, never by assumption."
  - "Treat "this should work", "assume it works", or "probably correct" as a signal that you have NOT verified yet — go run the check instead of finishing."
  - "set 'verified' to true only if your tool output shows the requirements are met".
- **Mode tagging** (→ 5.7-1) (`format.ts:5-46`, `cline.ts:15-17`):
  - Every user message is wrapped as `<user_input mode="plan|act|yolo">…</user_input>`.
  - A UI toggle prepends `<mode_notice>The user switched from plan mode to act mode before sending this message.</mode_notice>`. A plan→act→plan round trip before sending cancels out (`createModeSwitchNoticeTracker`).
  - The system prompt explains: "If the mode attribute changes between messages, the user switched modes -- the newest message's mode is what governs right now, regardless of what earlier messages allowed."
- **Plan contract** (→ 5.7-1) (`cline.ts:28-53`): "File-editing commands (rm/mv/cp, in-place edits like sed -i, output redirection to files outside /tmp, git commands that change the working tree, package installs) are hard-blocked in plan mode: they are not executed and return a tool error instead…". The CLI adds "use the switch_to_act_mode tool … never call it in the same turn you present a plan and never treat the original task request as approval".
- **Hook context** (→ 3.D-1) goes in as one user message of `<hook_context source="RunStart|PreToolUse|PostToolUse" …>` blocks, displayed as system. It is inserted *before* a trailing unresolved tool call so pairing is never broken (`agent-runtime.ts:404-424`, `1071-1105`).

## 6. Memory: `/newrule` (→ 5.14d)

`/newrule` (`C3 core/prompts/commands.ts:140-196`) turns a conversation into a persistent rule.
- The model writes a new `.clinerules/<succinct-name>.md` with sections `## Brief overview`, `Communication style`, `Development workflow`, `Coding best practices`, `Project context`, `Other guidelines`.
- The prompt says not to invent preferences, not to overwrite existing rule files, and that the file should not be "a recollection of the conversation".

## 7. Tools and editing

### 7.2 `replace_in_file` SEARCH/REPLACE (C3) (→ 0.11, 3.I-1, 3.I-3)

**Matching cascade** (`diff.ts:348-486`, v1):
1. **Exact** `indexOf(search, lastProcessedIndex)`.
2. **Line-trimmed** (`lineTrimmedFallbackMatch`, `:51-103`): every line compared after `.trim()`.
3. **Block-anchor** (`blockAnchorFallbackMatch`, `:132-185`): only for blocks of **≥ 3 lines**. The trimmed first and last lines must match at the same distance apart; middle lines are *not* compared.
4. **Out-of-order**: an exact match before the cursor is recorded, and all replacements are applied sorted by position at the end.
5. Otherwise it throws `The SEARCH block:\n…\n...does not match anything in the file.`

**On failure the model gets the whole file back** (`responses.ts:300-304`) (→ 0.11):
```
This is likely because the SEARCH block content doesn't match exactly with what's in the file, or if you used multiple SEARCH/REPLACE blocks they may not have been in the order they appear in the file. (...)

The file was reverted to its original state:

<file_content path="${relPath}">
${originalContent}
</file_content>

Now that you have the latest state of the file, try the operation again with fewer, more precise SEARCH blocks. For large files especially, it may be prudent to try to limit yourself to <5 SEARCH/REPLACE blocks at a time, then wait for the user to respond with the result of the operation before following up with another replace_in_file call to make additional edits.
(If you run into this error 3 times in a row, you may use the write_to_file tool as a fallback.)
```

**`write_to_file` with empty content** escalates over 1, 2 and 3 or more failures. At 3: `CRITICAL: You have failed to write this file ${n} times in a row. You MUST change your approach — do NOT retry write_to_file for this file again.` (`responses.ts:56-97`). Code fences wrapped around `write_to_file` content are stripped (`WriteToFileToolHandler.ts:492-570`).

**`apply_patch`** (→ 3.I-3) (C3 `core/task/tools/utils/PatchParser.ts:259-334`; 4.x `executors/apply-patch-parser.ts:347-431`):
- Context is found in four passes, after canonicalisation (NFC normalisation, unicode dashes and quotes → ASCII, unescaping `` \` ``):
  1. exact (fuzz 0)
  2. `trimEnd` (fuzz 1)
  3. `trim` (fuzz 100)
  4. **similarity ≥ 0.66** (fuzz 1000)
- EOF context is searched from the end first.
- The result reports `Note: Patch applied with fuzz factor ${fuzz}`.
- Classic skips a chunk that does not match and warns. 4.x fails the whole patch (prefer this).

**4.x `editor`** is exact match only: `No replacement performed: text not found…` / `multiple occurrences…`; caps `old_text`/`new_text` at 6,000 chars ("Split the edit into smaller tool calls"); returns a numbered `-N:`/`+N:` diff capped at 200 lines.

### 7.3 Post-edit feedback (C3 `integrations/editor/DiffViewProvider.ts`) (→ 3.F)

- The tool result returns **the full saved file**: `<final_file_content path="…">…</final_file_content>` with "IMPORTANT: For any future changes to this file, use the final_file_content shown above as your reference." Old copies are removed by the §4.2 dedup.
- New **error-severity** diagnostics are diffed against a pre-edit snapshot and reported as `New problems detected after saving the file:`. Auto-approved writes wait 3.5 s "to let the diagnostics catch up" (`WriteToFileToolHandler.ts:234-235`).

### 7.4 Shell (→ 0.4-a, 2.8)

- Classic managed timeouts: default 30 s; known long runners (installs, builds, test runners, docker build, training scripts) get 300 s. Output spills to a log file past 1,000 lines or 512 KB, keeping the first and last 100 lines plus `Full output saved to: ${path}`. `fileReadCache` is cleared after every command.
- 4.x `run_commands` (`executors/bash.ts`): 30 s default timeout, after which the process tree is killed; output middle-truncated at 48,000 chars with `[... output truncated: ${total} chars total. Refine the command (grep, head, tail) to view the elided middle ...]`.

### 7.5 File reading (→ 0.12)

- **Classic `read_file`:** 1,000 lines per call, with `N | ` line labels and `(Showing lines a-b of N total. Use start_line=b+1 to continue reading.)`; 20 MB file limit and 400 KB content cap.
- **4.x `read_files`:** 2,000 lines, 2,000 chars per line, 48,000 chars per read, with `[Showing lines a-b of N. Use start_line/end_line to read other sections.]` (`output-limits.ts:41-47`, `file-read.ts:60-191`).

## 8. Git integration: checkpoints (→ 3.A-1, 3.A-2)

### 8.1 Checkpoints, classic shadow git (C3 `integrations/checkpoints/`) — the non-repo fallback

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
- **Compare** opens a multi-file diff from a checkpoint to the current workspace. After `attempt_completion`, **View Changes** shows the diff since the previous completion (or the first checkpoint); `HAS_CHANGES` is computed with `git diff --count`.

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

## 9. Hooks (→ 3.D-1, 3.D-2, 2.12)

**Events:**

| Engine | Events |
|---|---|
| Classic | `TaskStart, TaskResume, TaskCancel, TaskComplete, PreToolUse, PostToolUse, UserPromptSubmit, Notification, PreCompact` |
| 4.x | the same, plus `TaskError` and `SessionShutdown`. `PreCompact` is discovered but **not run** (`core/src/hooks/hook-file-config.ts:17-43`) |

**Input** (JSON on stdin; `shared/src/hooks/events.ts:168-198`):
- Common fields: `clineVersion, hookName, timestamp, taskId, workspaceRoots, workspaceInfo{remotes, latest commit, branch}, userId, agent_id, parent_agent_id`.
- Per-event payloads: `preToolUse{toolName, parameters}`, `postToolUse{…, result, success, executionTimeMs}`, `userPromptSubmit{prompt, attachments}`, `preCompact{contextJsonPath, contextRawPath}`.

**Output:**
- `{cancel, contextModification|context, errorMessage, overrideInput}`.
- A hook may print `HOOK_CONTROL\t<json>` lines mixed with other output; the last one wins (`core/src/hooks/subprocess-runner.ts:72-88`).
- Context is capped at 50,000 chars.
- Context is injected as `<hook_context source="PreToolUse" tool_name="…" tool_call_id="…">…</hook_context>`. Spoofed tags inside the body are neutralised.

**Timeouts:** classic 30 s; 4.x 120 s for tool hooks.

**Gaps found in 4.x (don't copy):** `UserPromptSubmit` is asynchronous, so it can neither cancel nor inject; `--hooks-dir` sets an env var that no code reads; the `review` output field is never used.

**PreCompact in classic** (→ 2.12) receives the about-to-be-compacted context as JSON and raw files, can **cancel compaction** (which aborts the task), or can add text that is appended to the continuation as `[Context Modification from PreCompact Hook]` (`C3 SummarizeTaskHandler.ts:50-104`).

## 10. Permissions and approvals (→ 1.C-1, 1.C-2, DEF-MODE, O-2f)

- A rejection is returned to the model with `TOOL_REJECTION_SUFFIX = "NOT a tool or system failure. Clarify with user before proceeding."`. This stops the model from "fixing" a deliberate denial. (→ 1.C-2)
- **Approvals are brokered through the hub** (`core/src/hub/server/handlers/approval-handlers.ts:10-83`, `local-runtime-host.ts:790-811`):
  - `approval.requested {approvalId, toolName, inputJson, policy}` is broadcast to every attached client; a client answers with `approval.respond`.
  - **Pending approvals are re-sent to a client that reconnects.**
  - A non-interactive session is denied with "Tool approval requires an interactive session".
  - The session is marked `pending` while it waits (the model for pausing sugar-crush's 120 s watchdog while an ask is open).
- **Checkpoints are what make a permissive default acceptable** (Cline docs: "Checkpoints make auto-approve practical… The cost of a mistake drops to nearly zero", `docs/core-workflows/checkpoints.mdx`). (→ DEF-MODE after 3.A-1)
- **Desktop notifications:** "Cline is having trouble…" on approval waits, and a notification for auto-approved commands still running after 30 s. (→ 5.14a)

## 11. UX worth copying

- **Queued prompts panel** (→ 1.C-3): "Enter with empty input to steer first · ↑ select or edit"; "↑/↓ navigate, Enter steer, Tab edit". The user can reorder, edit or promote queued prompts while a run is in flight.
- `/undo` checkpoint picker, offering "Restore chat only" or "Restore chat and workspace" (→ 3.A-2).
- `cline history export <id> -o file.html` (→ 5.14f).
- `--acp` Agent Client Protocol server for IDEs (Zed, …) (→ 5.9-1).

---

## 13. Recommended improvements for sugar-crush

**P0-1. Workspace checkpoints with three restore modes** (→ 3.A-1, 3.A-2).
- Add a `Session\WorkspaceCheckpoint` class that shells out through `Support\ProcessContainment` with the git env hardening in `Tools/Concerns/CapturesProcessOutput.php`.
- In `Chat::dispatchTurn()`, add `'workspaceRef' => WorkspaceCheckpoint::snapshot($root, $sessionId, $n)` to the existing checkpoint `$chatState`. `EnhancedSessionStore::saveCheckpoint` already stores arbitrary state and caps it at 100 per session.
- Snapshot once per user turn (SDK style). Optionally also after any step where `Runtime::stepRequestedAWrite()` is true.
- Use the 4.x stash-commit + private ref for git repos (`refs/sugar-crush/checkpoints/<session>/<n>`); the classic shadow-git (`core.worktree`) variant for non-repos.
- Restore: refuse if HEAD moved; save a rollback stash first; then `reset --hard` → `clean -fd` → `stash apply`.
- Extend `/rewind [n] [--files|--chat|--both]` and add a palette action; add `/diff [n]` showing `git diff <ref>`, reusing `Tui/DiffGutter`. Refuse in `$HOME`.

**P0-3. Structured cross-turn tool replay** (→ 1.B-2). In `toTypedMessages()`, turn each run of tool-result rows into an `AssistantMessage(toolCalls: [...])` followed by `ToolResultMessage`s with matching ids, then run it through `Messages\HistorySanitizer` so orphans and interrupted calls stay well-formed (Cline fills gaps with `"result missing"` / `"Tool execution was interrupted before a result was produced."`).

**P0-4. Path-keyed duplicate and stale file-read dedup, applied at request build time** (→ 2.3, 2.2-1).
- A `StaleReadStrategy` over typed messages: for each `ToolResultMessage` from `Read` (and `Edit`/`Write` results carrying file content), key on `arguments.file_path`, replace all but the newest with a fixed notice.
- Apply in `Runtime::buildMessages()` before `HistorySanitizer::sanitize()`, so it covers every step.
- Use the SDK's 64 KB batching rule so the SGLang prefix cache is not invalidated on every read.
- Keep `compactFileReferences()` for legacy rows. Do not remove it.

**P0-5. Bidirectional approval channel from the forked turn child to the TUI** (→ 1.C-1, 1.C-2, DEF-MODE).
1. Give the child a `permissionApprover` closure that writes an `ask` frame (`{id, toolCall, message}`) and blocks reading the socket for an `answer` frame.
2. In the parent's frame pump, turn `ask` into a `Chat` message that opens the existing Veil modal. Write back `answer`.
3. Keep the 120 s watchdog paused while an ask is pending, the way the SDK marks the session `pending`.

Once this works, change the default mode to `accept-edits` or `default`, *after* P0-1 lands.

**P1-1. Compaction and recovery between steps, not only at submit** (→ 2.1, 2.7-1b, 2.7-2).
- In `EngineBackend::runTurn()`, before `Runtime::run()` on step > 0, use the last step's provider usage as the "previous request tokens" signal, Cline-classic style.
- When over threshold, apply the stale-read pruner first, then truncation, to the in-turn messages.
- Classify the provider's context-length error and retry once after pruning.
- Handle `lengthStopped` with no tool calls by appending Cline's nudge text as a user message and continuing, at most 3 times.

**P1-2. Summaries that name their working set, plus an automatic re-read** (→ 2.5, 2.6).
1. Derive read and edited paths from the compacted rows' tool arguments (after 1.B-2).
2. Append them as a fixed `## Files` block (re-append if the model drops it).
3. Read up to 8 of the most recently edited files (100k-char budget, through `Read`'s `PathJail`) and attach them to the first post-compaction user turn as `<file_content>`.

**P1-3. A todo/progress tool with periodic re-injection** (→ 3.C).
- A `Todo` tool whose state goes in `SessionMeta::$tasks`. Optionally mirror to `<root>/.sugar-crush/todo/<session>.md` and watch for user edits.
- Re-inject via the turn context when `stepsSinceTodoUpdate >= 6`, after a user edit, or when no list exists after 2 steps. Show it in a dock pane.

**P1-4. Mid-turn steering** (→ 1.C-3).
- Add a `steer` frame from parent to child over the P0-5 socket.
- In `runTurn()`, check the socket non-blockingly at each step boundary; if a steer is waiting, append `UserMessage(text)` before the next `Runtime::run()`.
- Optionally abort the current stream through the existing `CancellationToken` and keep the partial visible text (drop partial tool JSON).
- Key binding: Enter on an empty input while a turn is running = "steer first queued prompt".

**P1-5. External-change awareness** (→ 3.I-2, 1.A-1).
- Track `path → mtime` at Read time; at render time list read paths whose mtime moved without an Edit or Write by the agent, as a "files changed since you read them" notice in the volatile turn context. Add local time with IANA timezone there too.

**P1-6. A more forgiving Edit, with better failure feedback** (→ 0.11, 3.I-1).
- When `substr_count === 0`: try line-trimmed, then block-anchor (≥ 3 lines, first/last as anchors). Accept a candidate only if it is **unique** in the file.
- On final failure, include the nearest-matching region (or the whole file if under about 16 KB) in the error text.

**P1-7. Shell timeouts instead of killing the turn** (→ 0.4-a, 0.4-b). Add `timeout` (default 120 s, max 600 s) to `Bash.php`; make sequential tools emit heartbeats so a silent `make` no longer trips the 120 s idle watchdog.

**P1-8. A real plan mode** (→ 5.7-1, 5.7-2).
- A PerTurn `PlanModeSection` present only in plan mode, with Cline's plan contract text.
- A mode toggle that prepends a `<mode_notice>` to the next user message (Shift+Tab is taken — see D8; drift test mandatory).
- A `switch_to_act_mode`/`PlanExit` tool that only fires after explicit approval in a later message.

**P2-1. Wire the dormant team stack, the Cline 4.x way** (→ 4.6-2, 4.4).
- Construct `TeamManager` in `Bootstrap` and call `AgentManager::setTeamManager()`. Expose `Mailbox` and `TaskList` as `team_*` tools, bound through `DelegatesToEngine` like `TaskTool`.
- Deliver mailbox messages at each step boundary of a teammate's `runTurn()` (the same seam as P1-4). Add Cline's lead completion guard and crash-recovery re-queue text.

**P2-3. Sub-agent own model** (→ 4.1-1). Honour the preset `model` in `TaskTool::runOnEngine()` through `EngineBackend::withModel()`.

**P2-4. Hook context injection and the PreCompact/Stop events** (→ 3.D-1, 3.D-2, 2.12).
- Have `UserPromptSubmit`/session-start hook output added to the user message as a fenced `<hook-context>`, escaped with `PromptFence::escape`.
- Dispatch `HookEvent::PreCompact` from the compaction paths (cancel or augment); dispatch `Stop` when a turn ends. Update the `HOOKS.md` drift tests.

**P2-5. `/handoff` and `/newrule` distillation** (→ 5.14c, 5.14d).
- `/handoff`: run the summary backend with the `/newtask` text, then create and switch to a new session with that summary as the first user message.
- `/newrule`: write to `<root>/.sugar-crush/rules/<slug>.md` through the existing `RuleLoader` directory (the parent writes after the user confirms).

**P2-7. Oversized-result cache** (→ 2.8, 0.5). Cap MCP and `WebFetch` results at about 16 KB in the prompt; save the full text to a session-scoped 0600 file; let `Read` accept that path with a line range.

**P2-8. Model-family prompt variants** (→ 5.10). A small `PromptVariant` with overrides for the base identity and tool-use sections, keyed on the existing family detection (DeepSeek-V4, Qwen, MiniMax). Keep it Static-stable so prefix caching is unaffected.

### Pitfalls found in Cline (don't copy)

- Classic `summarize_task`'s example labels Required Files as section 8 while the regex wants section 9, so the file re-read silently does nothing.
- The focus chain's "All items completed" branch is unreachable; the plan→act "create a list" prompt never fires.
- 4.x loop-detection soft notices are appended to the `ConversationStore` but are probably dropped on the next `replaceMessages` sync (they carry no id and no `displayOnly`).
- 4.x `spawn_agent` allows unlimited nesting and runs child tools without approval.

When porting any of these mechanisms, add tests for the trigger path itself, not only for the formatter.
