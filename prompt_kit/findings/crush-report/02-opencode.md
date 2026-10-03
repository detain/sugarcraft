# opencode vs sugar-crush: competitor deep-dive

Feeds steps: 0.3, 0.4-a, 0.4-b, 0.5, 0.10, 0.12, 0.13-a, 1.A-1, 1.A-2, 1.B-2, 1.C-1, 1.C-2, 1.C-3, 2.1, 2.2-1, 2.2-2, 2.4-1, 2.5, 2.6, 2.7-1b, 2.8, 2.12, 3.A-1, 3.A-2, 3.B-2, 3.C, 3.F, 3.I-1, 3.I-2, 3.I-3, 4.1-2, 4.3-2, 4.4, 4.7-1, 5.7-1, 5.7-2, 5.10, 5.14a, 5.14e, 5.14g, 5.14j, X-35a, X-37a, DEF-MODE, O-3b, O-8a, P-A2, P-C1, P-D2, P-D3, P-E3

**Competitor:** opencode (anomalyco/opencode), TypeScript on Bun. **Clone:** `/home/sites/crush-research-repos/opencode` @ `a79ecfe10` (2026-10-01).

**Path conventions.** Paths are relative to `/home/sites/crush-research-repos/opencode/packages/`:

| Prefix | Expands to | What lives there |
|---|---|---|
| `OC/` | `packages/opencode/src/` | the main agent runtime |
| `CORE/` | `packages/core/src/` | shared core, plus the in-progress "v2" session engine |
| `TUI/` | `packages/tui/src/` | the terminal client |
| `PLUGIN/` | `packages/plugin/src/` | the public plugin API |

sugar-crush paths are relative to `/home/sites/sugarcraft/sugar-crush/` (line numbers may have drifted; re-locate by symbol).

---

## 2. Agent loop

### 2.1 Step-boundary reload and end of budget (→ 1.C-3, 0.10)

- `prompt()` always **persists the user message first**, then runs the loop. Each iteration **reloads history from SQLite** (`MessageV2.filterCompactedEffect(sessionID)`, `OC/session/prompt.ts:1092`). A user message inserted while a step runs becomes `lastUser`, so the exit check no longer matches and the loop continues — this is how mid-turn steering works.
- **Exit check** (`:1103-1130`): stop when the last assistant message has a final `finish` (not `tool-calls`/`unknown`), has no tool parts, and answers the latest user message. Some providers report `stop` even though tool calls are present; that case continues the loop.
- **End of budget:** on the last step the request gets an extra **assistant-role** message, `MAX_STEPS_PROMPT` (`:1281`, text in `CORE/session/runner/max-steps.ts`):
  > `CRITICAL - MAXIMUM STEPS REACHED … Tools are disabled until next user input. Respond with text only. … Response must include: - Statement that maximum steps for this agent have been reached - Summary of what has been accomplished so far - List of any remaining tasks that were not completed - Recommendations for what should be done next`

### 2.2 One step: overflow, abort pairing (→ 2.1, 2.7-1b, 1.B-2)

- **`step-finish`** (`OC/session/processor.ts:435-498`): snapshot again; compute usage and cost; write a `patch` part listing the files changed in this step; **check for overflow** — `isOverflow({tokens: usage.tokens})` sets `ctx.needsCompaction`. The stream is wrapped in `Stream.takeUntil(() => ctx.needsCompaction)` (`:658`), so the step stops cleanly and returns `"compact"`.
- **Provider context overflow** (`ContextOverflowError`) does not fail the turn. It sets `needsCompaction` (`:621-631`) and leads to an *overflow* compaction, which replays the last user message with media stripped (§4.4).
- **Cleanup on abort or error** (`:553-611`): waits up to 250 ms for in-flight tools; marks any still running as `error: "Tool execution aborted"` with `metadata.interrupted = true`. On replay, pending or running tool parts become `output-error "[Tool execution was interrupted]"`, so every `tool_use` keeps a `tool_result` (`OC/session/message-v2.ts:352-363`).
- **Permission rejection stops the loop:** a `RejectedError`/`CorrectedError` sets `ctx.blocked` (`:200-202`) unless `experimental.continue_loop_on_deny` is set.

### 2.3 Steering UI (→ 1.C-3, 5.14g)

- Typing while busy persists the message and the TUI tags it **`QUEUED`** (`TUI/routes/session/index.tsx:1387-1450`). `<leader>q` "Manage queued prompts" lets the user edit or remove queued items.
- Cancellation cascades: `cancelBackgroundJobs` walks the job graph by `metadata.parentSessionId` and cancels every descendant sub-agent (`OC/session/run-state.ts:111-143`). The shell tool kills with `forceKillAfter: "3 seconds"`.
- **`!cmd`** runs a shell command from the prompt as a user-executed tool (`shellImpl`, `:451-597`), recorded in history as `"The following tool was executed by the user"` so the model sees it.

---

## 3. Agents and sub-agents

### 3.1 Agent permissions (→ 4.1-2, 5.7-1)

**Base permission defaults** (`OC/agent/agent.ts:113-127`):
```
"*": "allow", doom_loop: "ask",
external_directory: { "*": "ask", <truncation dir>/*: "allow", <skill dirs>/*: "allow" },
question: "deny", plan_enter: "deny", plan_exit: "deny",
read: { "*": "allow", "*.env": "ask", "*.env.*": "ask", "*.env.example": "allow" }
```

- `build` (default primary) adds `question`/`plan_enter` allow.
- `plan` (primary): `edit: {"*": "deny", ".opencode/plans/*.md": "allow", <data>/plans/*.md: "allow"}`; `task.general: deny`. Plan mode is enforced by permission, not just by prompt.
- `explore` (subagent): `"*": "deny"` except grep/glob/list/bash/webfetch/websearch/read; prompt "You are a file search specialist… Do not create any files, or run bash commands that modify the user's system state".
- A custom agent's `prompt` **replaces** the model-family base prompt (`OC/session/llm/request.ts:60`). Every user message records which agent and model it used, so switching mid-session is first-class.

### 3.2 Sub-agent spawn: the `task` tool (→ 4.1-2, 4.7-1)

[`OC/tool/task.ts`] Parameters: `description` (3-5 words), `prompt`, `subagent_type`, optional `task_id` (resume), `command`, `background`.
1. **Depth guard:** walk `parentID` to the root; fail if `depth >= cfg.subagent_depth ?? 1` (`:104-117`).
2. **Permission:** `ctx.ask({permission: "task", patterns: [subagent_type]})`, so callable sub-agents can be restricted per agent.
3. **Child session** with `parentID = ctx.sessionID`, title `"<description> (@<agent> subagent)"`. Permission comes from `deriveSubagentSessionPermission` (`OC/agent/subagent-permissions.ts`): the parent's **deny** rules and `external_directory` rules, plus `todowrite` and `task` **denied** unless the sub-agent's own ruleset grants them. `experimental.primary_tools` are also denied to children.
4. **Model:** `next.model ?? the parent message's model`.
5. The child runs the **ordinary session loop on the child session**, with its own system prompt, compaction, snapshots and permission asks.
6. **Result:** only the child's **last text part**, wrapped as:
   ```
   <task id="<childSessionID>" state="completed">
   <task_result>
   …
   </task_result>
   </task>
   ```
   Errors become `Subagent failed (task_id: …): …` so the parent can resume the same task.
7. **Resume:** passing `task_id` reuses the child session, which continues "with its previous messages and tool outputs". Works for any finished task, not only failed ones.

### 3.3 Background sub-agents and parent↔child communication (→ 4.3-2, 4.4, P-C1, P-D2, P-D3, P-E3)

Every task runs as a `BackgroundJob` keyed by the child session id (`CORE/background-job.ts`).

**Foreground → background promotion.** The foreground path races `background.wait` against `background.waitForPromotion` (`OC/tool/task.ts:334-337`). **Ctrl+B** ("Background synchronous subagents") promotes every running job. The parent's tool call returns immediately with `state="running"` and (`BACKGROUND_STARTED`, `:31-35`):
> `The task is working in the background. You will be notified automatically when it finishes. DO NOT sleep, poll for progress, ask the task for status, or duplicate this task's work — avoid working with the same files or topics it is using.`

**Result injection.** When a background job settles, `inject()` calls `ops.prompt()` on the **parent** session with a synthetic `<task … state="completed"><summary>Background task completed: …</summary>…` part (`:227-254`). If the parent is busy, it is picked up at its next step; if idle, **a new parent turn starts**.

**Parent → running child.** Calling `task` again with the `task_id` of a *running* background task triggers `background.extend()`, which queues another `ops.prompt` into the child session (seen at the child's next step) and returns `BACKGROUND_UPDATED` ("Additional context sent to the running background task…", `:36-41`, `:267-282`).

**User ↔ child.** Child sessions are ordinary sessions, so the TUI can enter them: `<leader>down` first child; `right`/`left` next/previous sibling; `up` back to parent (`TUI/config/keybind.ts:103-106`). While in a child, a footer shows "Subagent N of M", its context % and its cost (`TUI/routes/session/subagent-footer.tsx`). (In current source the prompt input is hidden in child sessions, `index.tsx:240`.) Child permission prompts surface there.

### 3.4 Commands as sub-agents (→ X-37a)

A markdown command with `subtask: true`, or whose `agent` is a subagent, becomes a `subtask` part run through `TaskTool.execute`; afterwards the loop appends the synthetic user text `"Summarize the task tool output above and continue with your task."` (`OC/session/prompt.ts:446`, `:1439-1452`). `@agentname` in a prompt becomes an instruction to call `task` with that sub-agent (`:974-990`).

---

## 4. Context handling and compaction

### 4.1 Window (→ 2.1)

- Overflow decisions use **provider-reported usage** from the last finished step: `total || input + output + cache.read + cache.write` (`OC/session/overflow.ts:31-33`). `chars / 4` estimates are used only for tail selection and pruning.
- **Usable window** (`OC/session/overflow.ts:8-20`):
  ```ts
  const COMPACTION_BUFFER = 20_000
  reserved = cfg.compaction?.reserved ?? Math.min(COMPACTION_BUFFER, maxOutputTokens(model))
  usable   = model.limit.input ? model.limit.input - reserved
                               : model.limit.context - maxOutputTokens(model)
  ```
  `maxOutputTokens = min(model.limit.output, 32_000)` (`OC/provider/transform.ts:18,1481`).

### 4.2 When compaction triggers (→ 2.1, 2.7-1b)

| Trigger | Where | Notes |
|---|---|---|
| After any step, reported tokens ≥ usable | `OC/session/processor.ts:491-496` → `takeUntil` → `"compact"` → `prompt.ts:1320` | **Mid-turn.** The turn is not lost; the loop continues after the summary |
| Before a step, the last finished assistant is over the limit | `prompt.ts:1161-1167` | Catches overflow carried over from a previous turn |
| Provider throws `ContextOverflowError` | `processor.ts:621-631` | Compaction with `overflow: true` (replay plus media strip) |
| Manual `/compact` or the `summarize` endpoint | TUI / `OC/server/.../session.ts:303` | Same machinery with `auto: false` (no auto-continue) |

### 4.3 Verbatim tail selection (→ 2.4-1)

[`OC/session/compaction.ts:115-269`]
```ts
preserve = cfg.compaction?.preserve_recent_tokens
        ?? min(15_000, max(2_000, floor(usable * 0.25)))
```
- Walk user *turns* backwards from the newest, estimating each one's serialized size; keep whole turns while they fit.
- When the next turn does not fit, `splitTurn` keeps the **largest suffix of that turn** that fits (it can start inside a turn, at a step boundary).
- `compaction.tail_turns` optionally caps how many turns are kept. The tail's first message id is stored as `tail_start_id`.
- `filterCompacted` rebuilds the model view as `[compaction-user, summary-assistant, …tail…, continue-user]` (`message-v2.ts:525-576`).
- **Rendering trick:** the compaction user message is rendered to the model as **`"What did we do so far?"`** (`message-v2.ts:232-236`), so the summary reads as the assistant's natural answer.

### 4.4 The summarisation prompt (→ 2.5, 2.4-1, 2.12)

**Agent system prompt** (`OC/agent/prompt/compaction.txt`), verbatim:
> You are a context summarization agent. You are given a conversation between a user and an agent. Your goal is to produce a structured summary matching the format specified so another coding agent can continue the work.
> Always follow the exact output structure requested by the user prompt. Keep every section, preserve exact file paths and identifiers when known, and prefer terse bullets over paragraphs.
> Do not continue the conversation. Do not respond to any questions in the conversation. Only output the structured summary in the exact format requested by the user prompt. Respond in the same language as the conversation.

**User prompt** (`buildPrompt()`, `CORE/session/compaction.ts:160-174`):
```
Here is the conversation so far:

<conversation>
[User]: …
[Assistant]: …
[Assistant reasoning]: …
[Assistant tool call]: edit({"filePath":…})
[Tool result]: <first 2,000 chars>\n[truncated]     (or "[Old tool result content cleared]" if pruned)
[Tool error]: …
</conversation>

Create a new anchored summary from the conversation history in the <conversation> tags above so another coding agent can continue the work.

Output exactly the Markdown structure shown inside <template> and keep the section order unchanged. Do not include the <template> tags in your response.
<template>
## Objective
- [one or two brief sentences describing what the user is trying to accomplish]

## Important Details
- [constraints/preferences, decisions and why, important facts/assumptions, exact context needed to continue, or "(none)"]

## Work State
### Completed
- [finished work, verified facts, or changes made; otherwise "(none)"]

### Active
- [current work, partial changes, or investigation state; otherwise "(none)"]

### Blocked
- [blockers, failing commands, or unknowns; otherwise "(none)"]

## Next Move
1. [immediate concrete action, or "(none)"]
2. [next action if known, or "(none)"]

## Relevant Files
- [file or directory path: why it matters, or "(none)"]
</template>

Rules:
- Keep every section, even when empty.
- Use terse bullets, not prose paragraphs.
- Preserve exact file paths, symbols, commands, error strings, URLs, and identifiers when known.
- Do not mention the summary process or that context was compacted.
```

**Incremental ("anchored") summaries.** With a previous summary, the prompt also carries `<prior-summary>…</prior-summary>` plus (`CORE/session/compaction.ts:47-55`):
> The <prior-summary> summarizes everything that happened before the <conversation>. Construct a new summary that combines both. The <prior-summary> is discarded after this: anything you do not carry into the new summary is lost.
> - Carry forward objectives, constraints, user directives, decisions, and parallel workstreams from the <prior-summary> even when the <conversation> does not mention them. Drop only what is finished and no longer needed.
> - The <conversation> is more recent than the <prior-summary>. Where they conflict, the conversation wins: state the corrected fact and drop the old claim.
> - Add new progress … Move completed work from "Active" to "Completed". … Update "Objective" and "Next Move" to reflect the current work state.

Only messages **outside** the verbatim tail are serialised; earlier compaction pairs are hidden from the input.

**Execution.** The summary runs through the normal processor with `tools: {}` and `system: []`, using the compaction agent's model or else the user's model (`compaction.ts:358-448`). A tool call during summary generation throws. If the summary itself overflows: `"Session too large to compact - context exceeds model limit even after stripping media"` and the loop stops.

**After compaction** (`compaction.ts:468-550`):
- **Overflow case:** the user message that overflowed is *replayed* after the summary, with media parts swapped for `[Attached <mime>: <name>]`.
- **Normal auto case:** a synthetic user message (tagged `metadata.compaction_continue = true`):
  > `Continue if you have next steps, or stop and ask for clarification if you are unsure how to proceed.`

  For overflow it is prefixed with an explanation that oversized media was removed.

**Hooks inside compaction:** `experimental.session.compacting` can append `context[]` strings or **replace the whole prompt** (`:373-391`); `experimental.compaction.autocontinue` can disable the continue turn.

### 4.5 Pruning old tool outputs (→ 2.2-1, 2.2-2, 2.6)

[`compaction.ts:271-317`]
```ts
export const PRUNE_MINIMUM = 20_000
export const PRUNE_PROTECT = 40_000
const PRUNE_PROTECTED_TOOLS = ["skill"]
```
1. Walk messages newest → oldest. Skip everything until **two user turns** have been passed (current and previous turns are never pruned).
2. Stop at a summary message, or at a tool part already compacted (pruning is incremental).
3. For each completed tool part (except `skill`), add its estimated output tokens. The first **40k tokens** of tool output are protected; every older part goes on the prune list.
4. Apply only if the prune list totals **more than 20k tokens**, so the cache is not churned for small gains.
5. Applying sets `part.state.time.compacted = Date.now()`. The stored output is kept for the UI and transcript.

**Model view:** `toModelMessages` renders pruned parts as `"[Old tool result content cleared]"` and drops their attachments (`message-v2.ts:297-300`). The tool call and its arguments are still sent. Runs after each finished loop (`prompt.ts:1338`, forked). Opt-in (`compaction.prune`, default false; `OPENCODE_DISABLE_PRUNE`).

**Compaction-aware instruction re-injection (→ 2.6):** nested `AGENTS.md` files are attached to Read results once. The dedup set `extract()` **skips compacted read parts** (`OC/session/instruction.ts:17-32`), so when the Read that carried an instruction file is pruned, the next Read in that directory re-attaches it.

**Projection seam (→ 2.2-2, 3.B-2):** the plugin hook `experimental.chat.messages.transform` receives the whole message list (info + parts) before conversion to model messages, every step, plus a cloned copy before compaction serialisation (`prompt.ts:1255`, `compaction.ts:378-379`). The list is reloaded from the DB each step, so mutations are **per-request and non-destructive** — the seam context-pruning plugins (dedup, superseded-write removal, stale-output elision) use.

### 4.6 Tool-output truncation at tool time (→ 2.8, 0.5)

[`OC/tool/truncate.ts`] `MAX_LINES = 2000`, `MAX_BYTES = 50 * 1024`, configurable via `tool_output.{max_lines,max_bytes}`.
- Applied to every tool by the `Tool.define` wrapper (`OC/tool/tool.ts:131-142`) unless the tool sets `metadata.truncated` itself, and to MCP tools.
- Over the limit, the **full text is written** to the truncation dir as `tool_<id>` (swept after 7 days). The model gets the head (or tail) preview plus (`:129-131`):
  > `The tool call succeeded but the output was truncated. Full output saved to: <file>\nUse the Task tool to have explore agent process this file with Grep and Read (with offset/limit). Do NOT read the full file yourself - delegate to save context.`

  If the agent cannot use `task`, the hint says to use Grep or Read with offset/limit instead.
- The truncation dir is pre-allowed for `external_directory` in every agent (`agent.ts:247-262`).
- **Bash** (`OC/tool/shell.ts:440-590`): streams output while keeping a bounded tail in memory; spills to a file as soon as output passes `maxBytes`; returns the tail with `...output truncated...\n\nFull output saved to: <file>`.
- **MCP:** progress tokens reset tool timeouts (`OC/mcp/catalog.ts:62-64`).

### 4.7 Prompt caching (→ 0.13-a, 1.A-1, 1.A-2)

- **Session affinity:** every non-opencode provider is sent `x-session-affinity: <sessionID>` and `X-Session-Id` (`request.ts:198-201`) — exactly what an SGLang router needs for sticky radix-cache routing. OpenAI-family providers also get `promptCacheKey = sessionID` (`transform.ts:1323-1336`).
- **Stable prefix by design:** the environment block contains only static facts plus the date at **day** granularity (`OC/session/system.ts:74-85`); no git status, no file tree; tool lists sorted by name (`request.ts:184`). The system prompt is kept as at most two system messages, `[header, rest]`.
- **v2 "context epoch"** (`CORE/session/context-epoch.ts`, `CORE/system-context/*`, `CORE/instruction-context.ts`): the system context (env, date, AGENTS.md set) is frozen as a per-session **baseline**. When a source changes (date rollover, an edited AGENTS.md), it is **not** re-rendered into the system prompt; a delta message goes into history instead, e.g. `"Today's date is now: …"` or `"These instructions replace all previously loaded ambient instructions.\n\n…"`, serialised as `[System update]: …`. The baseline is regenerated only after a compaction, when the prefix is rewritten anyway.

---

## 5. Prompt generation

### 5.1 Model-family base prompts (→ 5.10)

[`OC/session/system.ts:28-51`]

| Match on `model.api.id` | File | Size |
|---|---|---|
| `muse` | `meta.txt` (with `{{MODEL_NAME}}`) | 9.2 KB |
| `gpt-4`, `o1`, `o3` | `beast.txt` ("keep going until the user's query is completely resolved…") | 11 KB |
| `gpt` + `codex` | `codex.txt` | 7.4 KB |
| other `gpt` | `gpt.txt` ("You and the user share the same workspace… pragmatic… senior software engineer") | 9.3 KB |
| `gemini-` | `gemini.txt` ("Core Mandates") | 15 KB |
| `claude` | `anthropic.txt` | 8.2 KB |
| `trinity` / `kimi` | `trinity.txt` / `kimi.txt` | ~8 KB |
| everything else (DeepSeek, Qwen, GLM…) | `default.txt` | 8.5 KB |

**`anthropic.txt` main sections:** tone and style (no emojis, concise, "Never use tools like Bash or code comments as means to communicate", "NEVER create files unless they're absolutely necessary"); professional objectivity; task management ("Use these tools VERY frequently…" TodoWrite, with worked examples); tool usage policy ("prefer to use the Task tool in order to reduce context usage"; maximise parallel calls; specialised tools over bash); "Tool results and user messages may include <system-reminder> tags…"; code references as `file_path:line_number`.

**`default.txt`** (what DeepSeek-V4 or Qwen get): older terse Claude-Code style ("You MUST answer concisely with fewer than 4 lines…"; "DO NOT ADD ***ANY*** COMMENTS unless asked"; "When you have completed a task, you MUST run the lint and typecheck commands… proactively suggest writing it to AGENTS.md"; "NEVER commit changes unless the user explicitly asks you to").

GPT models get `apply_patch` instead of `edit`/`write` (`OC/tool/registry.ts:289-293`).

### 5.2 Environment and instruction files (→ 1.A-1, 5.14j)

**Environment** (`system.ts:74-85`), verbatim shape:
```
You are powered by the model named <api.id>. The exact model ID is <providerID>/<api.id>
Here is some useful information about the environment you are running in:
<env>
  Working directory: <cwd>
  Workspace root folder: <worktree>
  Is directory a git repo: yes|no
  Platform: <process.platform>
  Today's date: <Date.toDateString()>
</env>
```
No git status, no branch, no file tree. The shell tool's *description* adds OS, shell and temp dir.

**Instruction files** (`OC/session/instruction.ts:60-169`):
- **Global:** first existing of `~/.config/opencode/AGENTS.md`, `~/.claude/CLAUDE.md`.
- **Project:** `findUp` from cwd to the worktree root for `AGENTS.md`, else `CLAUDE.md`, else `CONTEXT.md`. The first *filename* that matches wins, but every ancestor copy of it is included.
- **Config `instructions[]`:** globs, absolute paths, `~/` paths, and https URLs (5 s timeout).
- Each file becomes `Instructions from: <path>\n<content>`.
- **Nested AGENTS.md:** when Read touches a file, any not-yet-attached instruction file up to the root is appended to the Read output inside `<system-reminder>…</system-reminder>` (`OC/tool/read.ts:300,355-356`).

### 5.3 Mid-conversation reminders (→ 5.7-1, 5.7-2)

[`OC/session/reminders.ts`] Synthetic text parts appended to the **latest user message**, so they ride in the user turn rather than the system prompt.
- **Plan agent (classic)** `plan.txt`:
  > `<system-reminder># Plan Mode - System Reminder\nCRITICAL: Plan mode ACTIVE - you are in READ-ONLY phase. STRICTLY FORBIDDEN: ANY file edits, modifications, or system changes. … ZERO exceptions. … Ask the user clarifying questions…</system-reminder>`
- **Switching plan → build** `build-switch.txt`: "Your operational mode has changed from plan to build. You are no longer in read-only mode…". In experimental plan mode it adds "A plan file exists at X. You should execute on the plan defined within it".
- **Experimental plan mode** `plan-mode.txt`, a 5-phase workflow: (1) up to 3 `explore` agents in parallel; (2) a design agent; (3) review; (4) write the final plan to the plan file, "the only file you can edit"; (5) call `plan_exit`. "Your turn should only end with either asking the user a question or calling plan_exit."

---

## 6. `/init` (→ 5.14e)

`/init` generates or improves AGENTS.md with `OC/command/template/initialize.txt`. The test it applies to each line: *"Would an agent likely miss this without help?"* It covers exact commands, single-test invocation, ordering, monorepo boundaries, quirks; it excludes generic advice; and "If `AGENTS.md` already exists … improve it in place rather than rewriting blindly".

---

## 7. Tools and editing

### 7.1 Edit and the replacer chain (→ 3.I-1, 3.I-2, 3.I-3)

**Edit** (`OC/tool/edit.ts:59-213`): `filePath`, `oldString`, `newString`, `replaceAll`.
- `oldString === ""` creates a new file, but refuses an existing one ("use write for an intentional full-file replacement").
- BOM handling and line-ending normalisation (CRLF preserved). **Per-file semaphore** (`:37-45`) serialises concurrent edits.
- A diff is computed and passed to `ctx.ask({permission: "edit", metadata: {diff}})`, so the permission dialog shows the diff before the write.

**The replacer chain** (`replace()`, `:682-729`). For each replacer in order, every candidate it yields is tried:

| # | Replacer | How it matches |
|---|---|---|
| 1 | `SimpleReplacer` | exact |
| 2 | `LineTrimmedReplacer` | each line compared `.trim()`-equal |
| 3 | `BlockAnchorReplacer` | ≥3 lines; first and last lines anchor (trimmed); block size within ±25%; middle lines scored by Levenshtein similarity; threshold `0.65` for a single candidate, best-of for several (`:220-221`, `:288-425`) |
| 4 | `WhitespaceNormalizedReplacer` | collapses `\s+` |
| 5 | `IndentationFlexibleReplacer` | strips the common indent |
| 6 | `EscapeNormalizedReplacer` | unescapes `\n`, `\t`, `\"` … |
| 7 | `TrimmedBoundaryReplacer` | |
| 8 | `ContextAwareReplacer` | |
| 9 | `MultiOccurrenceReplacer` | |

- A candidate must be **unique** (`indexOf === lastIndexOf`) unless `replaceAll`.
- **Safety guard** `isDisproportionateMatch` (`:731-737`): refuse if the matched span has ≥ max(old+3, 2×old) lines, or is >4× (or +500 chars) longer than `oldString`. Error: "Refusing replacement because the matched span is much larger than oldString. Re-read the file…".
- Error texts: "Could not find oldString in the file. It must match exactly, including whitespace, indentation, and line endings." / "Found multiple matches for oldString. Provide more surrounding context to make the match unique."
- Pitfall: the description *claims* read-before-edit is enforced, but **no check exists in `edit.ts`**. A real mtime/hash staleness check (→ 3.I-2) beats it.

**`apply_patch`** (→ 3.I-3; `OC/tool/apply_patch.ts`, `OC/patch/index.ts`): Codex `*** Begin Patch` format (add/update/delete/move); collects LSP diagnostics per touched file afterwards.

### 7.2 Read and shell (→ 0.12, 0.4-a, 0.3)

**Read** (`OC/tool/read.ts`):

| Setting | Value |
|---|---|
| default lines | `DEFAULT_READ_LIMIT = 2000` |
| line format | `N: content` |
| long lines | cut at 2,000 chars with `... (line truncated to 2000 chars)` |
| byte cap | `MAX_BYTES = 50 KB`, footer `(Output capped at 50 KB. Showing lines a-b. Use offset=N to continue.)` |
| paging | 1-indexed `offset`, `limit`; out-of-range offset is an error |
| binary | refused (`:328`) |
| directories | entry listing with `/` suffix, paged |

The description adds: "Avoid tiny repeated slices (30 line chunks). If you need more context, read a larger window." The Edit description tells the model `old_string` must exclude the line-number prefix.

**Shell** (`OC/tool/shell.ts`, `shell.txt`):
- Parameters `command`, `timeout` (ms), `workdir` ("Use this instead of 'cd' commands"), `description`.
- Default timeout 2 min (`:347`). On expiry the process is killed and `<shell_metadata>` gets (`:562-565`):
  > `shell tool terminated command after exceeding timeout N ms. If this command is expected to take longer and is not waiting for interactive input, retry with a larger timeout value in milliseconds.`
- Output streams live into `metadata.output`.
- **Generic "# Git and GitHub" section** in the description (→ 0.3):
  > Only commit, amend, push, or create PRs when explicitly requested. Before committing, inspect `git status`, `git diff`, and `git log --oneline -10`; stage only intended files and never commit secrets… Do not update git config, skip hooks, use interactive `-i`, force-push, or create empty commits unless explicitly requested. If a commit fails or hooks reject it, fix the issue and create a new commit; do not amend the failed commit… Use `gh` for GitHub tasks… return the PR URL when done.

### 7.3 LSP diagnostics loop (→ 3.F)

- Config `lsp` can disable servers or add custom ones (`command`, `extensions`, `env`, `initialization`; `OC/lsp/lsp.ts:151-183`). **PHP Intelephense** is detected via root `composer.json`/`composer.lock`/`.php-version` (`OC/lsp/server.ts:1515-1544`).
- After every edit/write/patch:
  1. `lsp.touchFile(path, "document")` opens or changes the document and **waits for fresh diagnostics**: `DIAGNOSTICS_DEBOUNCE_MS = 150`, `DIAGNOSTICS_DOCUMENT_WAIT_TIMEOUT_MS = 5_000`, full-project wait 10 s, request timeout 3 s (`OC/lsp/client.ts:13-16`).
  2. `LSP.Diagnostic.report()` keeps **severity-1 (ERROR) only**, at most 20 per file (`OC/lsp/diagnostic.ts`):
     ```
     LSP errors detected in this file, please fix:
     <diagnostics file="/abs/path.ts">
     ERROR [12:5] Property 'x' does not exist on type …
     … and N more
     </diagnostics>
     ```
  3. `write` additionally reports errors in **up to 5 other files** ("LSP errors detected in other files:", `OC/tool/write.ts:18,74-90`).

### 7.4 Todo and question tools (→ 3.C, 5.7-2)

- **`todowrite`** (`OC/tool/todo.ts`, `todowrite.txt`): replaces the whole list `{content, status: pending|in_progress|completed|cancelled, priority}`, stored per session. Rules: use for 3+ step tasks, exactly one item `in_progress`, mark items done immediately. Rendered in the sidebar; denied to sub-agents by default.
- **`question`**: options, `multiple`, and an auto-added "Type your own answer" choice. "If you recommend a specific option, make that the first option… add '(Recommended)'". A rejection is a `Question.RejectedError` and stops the loop.

---

## 8. Snapshots, undo and per-turn diff (→ 3.A-1, 3.A-2)

**Per-turn diff:** `SessionSummary.summarize` computes the files changed by each user message from the first `step-start` snapshot to the last `step-finish` snapshot (`OC/session/summary.ts`); shown in the diff viewer. The snapshot is taken *before* streaming begins (`processor.ts:99-102`), because tools may run before `step-start`.

### 8.1 Shadow-git snapshots (`OC/snapshot/index.ts`)

- The git dir lives at `<data>/snapshot/<projectID>/<hash(worktree)>`, driven with `--git-dir <gitdir> --work-tree <worktree>` (`:71-75`). The user's repo is never touched.
- **Seeding:** `objects/info/alternates` points at the project's real object DB (and its alternates), and the project's `index` is copied in, so `git add --all` does not rehash a huge repo (`:195-233`).
- Init config: `core.autocrlf false`, `core.longpaths`, `core.symlinks`, `fsmonitor false`, `feature.manyFiles`, `index.version 4`, `untrackedCache` (`:326-336`).
- The project's `info/exclude` is mirrored; ignored files are removed from the snapshot index; **untracked files over 2 MB are skipped** (`:24`).
- `track()` stages changed files (`diff-files` plus `ls-files --others --exclude-standard`), then `write-tree` → a **tree hash** with no commits or refs (`:318-346`). Pruning: `git gc --prune=7.days`.
- `patch(hash)` lists files changed since that hash; `diffFull(from, to)` gives structured diffs.
- `revert(patches)`: per file, `git checkout <hash> -- <file>`; if the file did not exist in the snapshot, **it is deleted**. Batched up to 100 non-clashing paths (`:408-470`). `restore(snapshot)` runs `read-tree` plus `checkout-index -a -f`.

### 8.2 Revert and unrevert (`OC/session/revert.ts`)

- **`revert(messageID, partID?)`** collects every `patch` part after the target and reverts those files; records `session.revert = {messageID, partID, snapshot (pre-revert tree), diff}` so the revert itself can be undone; the messages are **soft-hidden**, not deleted.
- **`unrevert`** restores the pre-revert snapshot. **`cleanup`** (on the next prompt or shell) permanently deletes the hidden messages (`:101-124`).
- **TUI** (`TUI/routes/session/index.tsx:614-660`): `/undo` (`<leader>u`) reverts the last user message's file changes **and puts that message's text and file attachments back in the input box**; `/redo` (`<leader>r`) unreverts; `/timeline` jumps to any message; `/fork` forks from any message.

---

## 9. Client/server (→ O-3b, O-8a)

- `opencode serve` runs the headless HTTP server (SSE event stream); `opencode attach <url>` connects a TUI to a remote server (basic auth).
- **Session HTTP API** (`OC/server/routes/instance/httpapi/groups/session.ts:111-433`): list, status, get, **children**, **todo**, diff, messages, create, update, **fork**, **abort**, init, **share/unshare**, summarize, prompt, **promptAsync**, command, shell, **revert/unrevert**, permission respond, delete/update message or part. Every UI action is an API call, so TUI, desktop and web share one engine.
- Every part is written to SQLite, and text deltas go out as part-delta events, so any client can render the stream.

---

## 10. Permissions: the ask flow (→ 1.C-1, 1.C-2, 4.1-2)

**Model.** Flat rulesets of `{permission, pattern, action: allow|deny|ask}`; `evaluate` uses **`findLast`** (later layers — agent → user config → session — override earlier ones); unmatched defaults to `ask` (`OC/permission/index.ts:27-37`).

**The ask flow:**
1. The tool calls `ctx.ask({permission, patterns, always, metadata})`.
2. If any pattern evaluates to `deny`, a `DeniedError` is returned to the model:
   > `The user has specified a rule which prevents you from using this specific tool call. Here are some of the relevant rules [...]`
3. If any pattern evaluates to `ask`, an `Asked` event is published and the tool fiber awaits a `Deferred`.
4. The client replies with:
   - **`once`**;
   - **`always`**: the request's `always` patterns are added to the session's `approved` list, and other pending asks now satisfied are auto-approved;
   - **`reject`** with an optional **message**, which becomes a `CorrectedError`: `The user rejected permission to use this specific tool call with the following feedback: <msg>` (`CORE/v1/permission.ts:13-19`). Reject also cascades to every other pending ask in the session (`:124-137`).
5. v2 adds **persisted** per-project saved approvals (`CORE/permission.ts`, `PermissionSaved`).

**"Always" patterns for Bash** (`OC/tool/shell.ts:378-413`, `OC/permission/arity.ts`): the command is parsed with tree-sitter; every simple command in pipes or chains is checked individually; `patterns` gets the exact source of each command; `always` gets `BashArity.prefix(tokens).join(" ") + " *"`. The arity table: `git`=2, `npm`=2, `npm run`=3, `docker compose`=3, `aws`=3, … So "always allow `git checkout main`" stores `git checkout *`.

Edit asks carry the diff (§7.1). Sub-agents inherit the parent's deny rules (§3.2).

---

## 11. UX worth copying (→ 1.C-3, 3.A-2, 5.14a, P-A2, P-C1)

- **Undo/redo restore the prompt text** (§8.2); timeline view; fork from any message.
- **Queued prompts** show a `QUEUED` badge and can be managed (`<leader>q`).
- **Child-session navigation** with arrow keys, plus the sub-agent footer (index, context %, cost).
- A permission dialog that shows the diff; replies once / always / reject-with-message.
- **Notifications** (`TUI/feature-plugins/system/notifications.ts`): attention plus sound on "Session done" (a distinct sound when a *subagent* finishes), "Permission needs input", "Question needs input", and errors.
- Session list with pins and quick-switch slots 1-9 (`<leader>1..9`).

---

## 13. Recommended improvements for sugar-crush

### P0-1: Interactive permission asks on the engine path, then retire the `bypass-permissions` default (→ 1.C-1, 1.C-2, DEF-MODE)

1. The UNIX socketpair in `EngineBackend::completeAsync()` is full duplex; only child→parent frames are used today.
2. Add a `permission_ask` frame written by the child from a new `PermissionApprover` implementation that blocks reading the child socket for a matching `permission_reply` frame; bind it in `runCompleteInChild()`.
3. In the parent's read-stream handler, turn `permission_ask` into the **existing** Veil y/n/a modal (`Chat::requestPermission`), which today serves only Command backends. Write the reply frame back.
4. Pause the 120 s no-frame watchdog while a modal is open.
5. Reject-with-message: the model sees `The user rejected … with the following feedback: …`. "Always" stores an arity-prefix pattern (§10), not the literal call.
6. Then change the default mode to `default` or `accept-edits`.

### P0-2: Structured cross-turn tool history (→ 1.B-2)

Earlier tool results are replayed as plain assistant text with no call or arguments; models trained on tool_use/tool_result pairing degrade and may start emitting fake "tool output" text, and structured pruning is impossible.
- opencode stores tool parts with `callID`, input, output and status and replays them as `tool-<name>` parts with `toolCallId`; interrupted ones still get a synthetic result (`OC/session/message-v2.ts:292-363`).
- Persist `toolCallId`, name and arguments on the engine tool rows (`Chat::toolResultMessage()`), from the `finished` frame.
- Make `EngineBackend::toTypedMessages()` regroup tool rows into `AssistantMessage(toolCalls)` plus `ToolResultMessage`s. `HistorySanitizer` already synthesises results for orphans.

### P0-3: Cache-stable prompt prefix and session affinity (→ 1.A-1, 1.A-2, 0.13-a)

- `SglangProvider::formatMessages()` collects every in-history `SystemMessage` (launch notices — up to 24 —, the 70% reminder stripped and re-added each turn, compaction notices, `_Request cancelled._`, running placeholders) and merges them into the single leading system message. Any new system row rewrites the head of the prompt, so the whole history misses SGLang's radix cache, and "Request cancelled" reads as a standing system instruction.
1. In `SglangProvider::formatMessages()` and the `CustomProvider` equivalent, render in-history system rows **in place**, as `user`-role `<system-reminder>` content, instead of hoisting them.
2. Move the volatile git part of `EnvironmentBlock` (status, log, diffs) out of `systemPromptSections()` into a per-step reminder appended to the newest message; keep cwd, OS and date (day granularity) in the static block. For instruction-file or date changes, follow the v2 context-epoch pattern: freeze a per-session baseline and publish deltas as `[System update]` messages, regenerating the baseline only after compaction.
3. Pass `sessionAffinityId` (the `SessionAffinity` trait, `Providers/Concerns/SessionAffinity.php`) from `Bootstrap::backendFor()` using the session id, mirroring `x-session-affinity: <sessionID>`.

### P0-4: Compact and prune between steps (→ 2.1, 2.2-1, 2.4-1, 2.5, 2.12)

1. In `EngineBackend::runTurn()`, after each `Runtime::run()` step, compare reported usage against `ContextWindow::ofBackend()` minus `min(20k, maxOutputTokens)`.
2. On overflow, first **prune**: replace the content of older `ToolResultMessage`s with `[Old tool result content cleared]`, keeping the call (40k protect / 20k minimum / last 2 turns / never `skill`). This works inside a turn today; cross-turn needs P0-2.
3. If still over, summarise in the child through a tool-less engine, then continue the loop with the "Continue if you have next steps…" message. Emit a `compaction` frame so `Chat` can splice `HistoryCompactedMsg`.
4. Fix `ContextCompactor::removeToolResults()` so it matches the real wire shape, and switch tail preservation from "10 pairs" to a token budget (`min(15k, max(2k, 0.25·usable))`, splittable at step boundaries; `CompactorConfig.php`). One 60 KiB Read inside the preserved window otherwise survives every compaction.
5. Adopt the 5-section anchored template with prior-summary merge rules (`CORE/session/compaction.ts:16-55`).
6. Dispatch `PreCompact` at this point.

### P0-5: Bash timeout and the silent-command watchdog (→ 0.4-a, 0.4-b)

- Add `timeout` (ms, default 120 000, max e.g. 600 000) to `src/Tools/BuiltIn/Bash.php`, enforced in `Tools/Concerns/CapturesProcessOutput.php` (the setsid group kill exists). On expiry, return the model-visible retry hint (§7.2).
- Have `Runtime::executeSequentially` emit heartbeat frames, or exempt a running Bash from the 120 s idle timer, as `HttpClientDefaults::heartbeatOptions` already does for HTTP.

### P1-6: Truncate-to-file with a navigation hint; cap MCP output (→ 2.8, 0.5)

- Extend `Tools/Concerns/TruncatesOutput.php`: write the full text to `sys_get_temp_dir()/sugarcrush-tool-output/` (0600), return a preview plus the hint, and apply it in `McpToolBridge` (`:587-622`, uncapped).
- Allow-list that directory in `PathJail` for Read and Grep.

### P1-7: Read with offset/limit and line numbers (→ 0.12)

Add `offset`/`limit` to `src/Tools/BuiltIn/Read.php` (2000 lines / 50 KB default page, `N: ` prefixes, continuation footer). Update the Edit description so `old_string` excludes the prefix.

### P1-8: Fuzzy edit replacer chain (→ 3.I-1)

Port `LineTrimmed`, `BlockAnchor`, `WhitespaceNormalized`, `IndentationFlexible` and `EscapeNormalized`, plus `isDisproportionateMatch`, into the Edit matcher. Keep the exact-match path first. Add a per-file `flock` around read-modify-write.

### P1-9: Wire the LSP client into a post-edit diagnostics loop (→ 3.F)

- Add a user-tier `lsp` settings key, with phpactor or intelephense as the default for `.php`.
- Construct `LspClient` in `Bootstrap::tools()` and pass it as `lsp:` to both `LspTool` and `Edit`/`Write`.
- Subscribe to `textDocument/publishDiagnostics` in `LspConnection` (today diagnostics are pull-only).
- Append the diagnostics block (§7.3) to the Edit and Write results.

### P1-10: Shadow-git snapshots and file-level undo (→ 3.A-1, 3.A-2)

- New `src/Snapshot/ShadowGit.php`, git dir `~/.sugar-crush/snapshot/<sha1(root)>` for non-repos.
- Call `track()` in `EngineBackend::runTurn()` before and after each step; ship `{fromTree, toTree, files}` in the `result` frame.
- Store it in the per-turn checkpoint (`EnhancedSessionStore::saveCheckpoint`, `Chat::dispatchTurn`).
- Extend `/rewind` to revert files; add `/undo` and `/redo` (restoring the reverted prompt text into the input).
- Skip untracked files over 2 MB; `gc --prune=7.days`.

### P1-13: Todo tool and pane (→ 3.C)

`src/Tools/BuiltIn/TodoWrite.php`: whole-list replace, at most one `in_progress`, stored per session in the `SessionMeta` `tasks` slot via `EnhancedSessionStore`. Ship it to the parent in a frame and render it in a dock pane. Deny it to sub-agents by default.

### P1-14: Mid-turn steering (→ 1.C-3)

When `Chat::enqueuePrompt()` runs during an engine turn, also write a `user_message` frame down the socket; the child's `runTurn()` drains pending frames between steps and appends a `UserMessage` before the next `Runtime::run()`. Keep the queue for prompts that arrive after the final step; show them `QUEUED`.

### P2-15: Sub-agents as stored, navigable, resumable child sessions (→ 4.7-1, 4.3-2, 4.4, P-C1, P-D2, P-D3, P-E3)

- Store each sub-agent transcript as an `EnhancedSessionStore` session with a parent id (`fork()` exists; `SuspendedDelegations` covers only failures). Return `task_id` on success too.
- Show children in the session tab strip (`Renderer::renderSessionTabStrip`).
- For background, return the `BACKGROUND_STARTED` text, inject the final answer into the parent (auto-dispatch a turn if idle), and let a repeat call on a running task extend it via the `Mailbox`.

### P2-16: Plan agent with a plan file and an exit tool (→ 5.7-1, 5.7-2)

Plan-mode path rules: allow Write/Edit to `.sugar-crush/plans/*.md`. Add a `PlanExit` tool ("switch to build?", then inject "The plan at X has been approved, you can now edit files. Execute the plan") and a `question` tool, both over the 1.C modal plumbing. Use the plan/build-switch reminders (§5.3).

### P2-17: Per-model-family base prompts (→ 5.10)

Make `Runtime::basePrompt()` select a heredoc by model family, using the family detection `ProviderFactory` already does for parser selection.

### P2-18: `/init` to generate AGENTS.md (→ 5.14e)

Port `OC/command/template/initialize.txt` as a built-in command template in `src/Commands/`.

### P2-19: Honour command `subtask`/`model` (→ X-37a)

In `Chat::expandCustomCommand()`, route `subtask: true` through `TaskTool`, then append "Summarize the task tool output above and continue with your task."

### P2-21: Smaller UX items (→ 5.14a, X-35a, 5.14j)

| Item | Detail |
|---|---|
| Notifications (→ 5.14a) | Bell/desktop notification on turn done or permission needed |
| Share fallback (→ X-35a) | Local markdown/JSON export for `/share` (`ShareUploader` always throws) |
| Global instructions (→ 5.14j) | Load `~/.claude/CLAUDE.md` / `~/.sugar-crush/AGENTS.md` |
