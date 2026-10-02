# opencode vs sugar-crush: competitor deep-dive

**Competitor:** opencode (anomalyco/opencode), TypeScript on Bun.
**Clone:** `/home/sites/crush-research-repos/opencode` @ `a79ecfe10` (2026-10-01).
**Baseline:** `prompt_kit/findings/crush-report/00-sugar-crush-baseline.md` (sugar-crush master @ `f2884ae7d`).

**Path conventions.** Paths below are relative to `/home/sites/crush-research-repos/opencode/packages/`:

| Prefix | Expands to | What lives there |
|---|---|---|
| `OC/` | `packages/opencode/src/` | the main agent runtime |
| `CORE/` | `packages/core/src/` | shared core, plus the in-progress "v2" session engine |
| `TUI/` | `packages/tui/src/` | the terminal client |
| `PLUGIN/` | `packages/plugin/src/` | the public plugin API |

sugar-crush paths are relative to `/home/sites/sugarcraft/sugar-crush/`.

---

## 1. Overview

### What it is

opencode is an open-source terminal coding agent in the Claude Code mould. It is built as a client/server system:

- **Server.** A headless server process (Effect + an HTTP API with an SSE event stream; `OC/server/`) owns sessions, the agent loop, tools, permissions, LSP and snapshots.
- **Clients.** The TUI (`packages/tui`, written with OpenTUI/Solid), a desktop app, a web UI, an ACP (Agent Client Protocol) server for IDEs, a GitHub-Action agent and a generated JS SDK all talk to that server.
- **Persistence.** Everything is stored in SQLite through drizzle (`CORE/session/sql.ts`).

### Stack

- TypeScript with the Effect library throughout (services, layers, fibers).
- The Vercel AI SDK (`streamText`) drives provider I/O.
- An experimental "native" LLM runtime lives in `packages/llm` (`OC/session/llm.ts:224-269`).
- Provider metadata comes from models.dev, so 75+ providers work.

### Size (measured with `wc -l` over `*.ts`/`*.tsx`)

| Area | Lines | Files |
|---|---|---|
| `OC/` | 81,395 | 366 |
| `CORE/` | 32,993 | 317 |
| `TUI/` | 27,058 | 152 |
| `packages/llm/src` | 9,533 | 56 |

A v2 core (`CORE/session/*`, `CORE/system-context/*`) is being built next to the v1 engine in `OC/session/*`. The live loop is still v1. The v2 pieces matter here mainly as design signals (see the context-epoch item in §4).

### What opencode does best

1. **A two-tier context manager that compacts mid-turn.**
   - After every model step it compares the provider-reported tokens against `usable = input_limit − min(20k, max_output)`. On overflow it stops the stream and inserts a "compaction" user message.
   - A dedicated hidden `compaction` agent then writes an **anchored, structured summary** (Objective / Important Details / Work State / Next Move / Relevant Files). Each new summary is merged into the prior one.
   - The most recent turns are kept verbatim within a token budget.
   - Finally it auto-continues with a synthetic "Continue if you have next steps…" message.
   - Separately, an opt-in **pruner** replaces old tool outputs with `[Old tool result content cleared]` once more than 40k tokens of newer tool output exist (`OC/session/compaction.ts`, `OC/session/overflow.ts`).
2. **Everything is a session, including sub-agents.**
   - `task` creates a **child session** (`parentID`) with its own permission ruleset.
   - The child can be resumed by `task_id`, promoted to the background with Ctrl+B, and sent more context while it runs.
   - Its result is pushed back into the parent as a synthetic message.
   - The user can navigate into any child session and type to it (`OC/tool/task.ts`, `TUI/config/keybind.ts:103-106`).
3. **Interactive permissions with semantic "always" patterns.**
   - Bash commands are parsed with tree-sitter. Each sub-command becomes a permission pattern, and "always allow" stores an **arity prefix** (`git checkout *`, `npm run dev *`) rather than the literal command.
   - A user can reject *with feedback*, and the feedback text reaches the model.
   - Rules match on argument patterns: last match wins, and the default action is `ask` (`OC/permission/index.ts`, `OC/permission/arity.ts`, `OC/tool/shell.ts:378-413`).
4. **Shadow-git snapshots for undo and redo.**
   - A separate git dir (`--git-dir/--work-tree`, with alternates into the repo's own object DB) takes a tree hash at every step start and finish.
   - Per-step patches list the changed files.
   - `/undo` reverts the files *and* puts the reverted prompt back into the input box; `/redo` restores (`OC/snapshot/index.ts`, `OC/session/revert.ts`).
5. **A closed edit → LSP feedback loop.**
   - 30+ language servers are auto-detected (and auto-installed) by file extension and root markers.
   - After every edit, write or patch, the file is formatted, the LSP is touched, the tool waits for diagnostics (150 ms debounce, 5 s cap), and up to 20 ERROR lines are appended under "LSP errors detected in this file, please fix" (`OC/tool/edit.ts:197-201`, `OC/lsp/client.ts:13-16`).
6. **A forgiving edit tool.** A chain of nine replacers (exact → line-trimmed → block-anchor with Levenshtein similarity → whitespace-normalised → indentation-flexible → escape-normalised → trimmed-boundary → context-aware → multi-occurrence) is guarded against "disproportionate" matches and protected by a per-file lock (`OC/tool/edit.ts:682-737`).
7. **Truncation that spills to a file.** Any tool output over 2,000 lines or 50 KB is saved whole to a temp file. The model gets a preview plus the path and an instruction to Grep it or delegate to the `explore` sub-agent (`OC/tool/truncate.ts:85-141`).
8. **A small, well-defined plugin hook surface**, including the `experimental.chat.messages.transform` seam that context-pruning plugins use (`PLUGIN/index.ts:222-335`).
9. **Mid-turn steering for free.** The loop re-reads the session from the database before every step, so a message typed while the agent works is seen at the next step boundary (`OC/session/prompt.ts:1092`).
10. **Per-model-family system prompts** (anthropic, gpt, codex, gemini, kimi, trinity, meta, beast, default; `OC/session/system.ts:28-51`), and per-family tool sets: GPT models get `apply_patch` instead of `edit`/`write` (`OC/tool/registry.ts:289-293`).

---

## 2. Agent loop

### 2.1 The outer loop (`SessionPrompt.runLoop`, `OC/session/prompt.ts:1081-1341`)

**Concurrency control.**
- A session has at most one runner (`SessionRunState`, `OC/session/run-state.ts:52-94`).
- `prompt()` always **persists the user message first** (`createUserMessage`, `:635`), then calls `loop()` → `state.ensureRunning()`.
- If a runner is already busy, the caller simply joins it (`OC/effect/runner.ts:115-134`).

**Each iteration (`while (true)`):**
1. **Reload history from SQLite** with `MessageV2.filterCompactedEffect(sessionID)` (`:1092`).
   - Messages before the last completed compaction are dropped.
   - The compaction message, its summary and the retained tail are reordered into model order (`OC/session/message-v2.ts:525-576`).
   - *This reload is what makes mid-turn steering work:* a user message inserted while a step runs becomes `lastUser`, and the exit check below no longer matches.
2. **Exit check** (`:1103-1130`). Stop when the last assistant message has a final `finish` (not `tool-calls`/`unknown`), has no tool parts, and answers the latest user message.
   - Some providers report `stop` even though tool calls are present; that case is detected and the loop continues.
   - Orphaned interrupted tools are ignored.
3. **On step 1**, fork the title generation (`:1133`) and the per-message diff summary (`:1252`).
4. **Pending tasks** found in the history:
   - `subtask` parts (from `/command` with `subtask: true`, or `@agent` mentions) → `handleSubtask` (`:255-449`).
   - `compaction` parts → `compaction.process()` (`:1149-1160`).
5. **Pre-flight overflow.** If the last finished assistant reported tokens ≥ the usable window, create a compaction message and loop again (`:1161-1167`).
6. **Step budget.** `maxSteps = agent.steps ?? Infinity` (`:1178`). There is **no default cap.** On the last step the request gets an extra **assistant-role** message, `MAX_STEPS_PROMPT` (`:1281`, text in `CORE/session/runner/max-steps.ts`):
   > `CRITICAL - MAXIMUM STEPS REACHED … Tools are disabled until next user input. Respond with text only. … Response must include: - Statement that maximum steps for this agent have been reached - Summary of what has been accomplished so far - List of any remaining tasks that were not completed - Recommendations for what should be done next`
7. **Reminders.** `SessionReminders.apply` adds synthetic text parts to the last user message (plan mode, build switch; §5.5).
8. **Tools.** `SessionTools.resolve` gathers the tools: built-ins, plugin tools and MCP tools, each wrapped with permission `ask`, `tool.execute.before/after` hooks and truncation (`OC/session/tools.ts`).
9. **Plugin seam.** `plugin.trigger("experimental.chat.messages.transform", {}, { messages: msgs })` (`:1255`). The array was freshly loaded from the DB, so plugin edits are **per-request and non-destructive**.
10. **System prompt and request** (§5), then `processor.process()`.
11. **Outcome handling.** `content-filter` finishes become visible errors (`:1297-1306`), `"compact"` creates an overflow compaction (`:1320-1328`) and the loop continues.
12. **After the loop**, `compaction.prune()` is forked off (`:1338`).

### 2.2 One step (`SessionProcessor`, `OC/session/processor.ts`)

- **Snapshot first.** A snapshot is taken *before* streaming begins (`:99-102`), because the AI SDK may run tools before it emits `step-start`.
- **One AI SDK call per step.** `LLM.stream()` calls `streamText()` without `stopWhen`, so each step is one model call plus execution of its tool calls (`OC/session/llm.ts:278-354`).
  - **Tool calls run in parallel:** the AI SDK starts every tool call in a step concurrently.
  - Concurrent edits to one file are serialised by a per-file `Semaphore` (`OC/tool/edit.ts:37-45`).
- **Stream events become persisted "parts"** (`handleEvent`, `:278-551`): `reasoning-*`, `text-*`, `tool-input-*`, `tool-call`, `tool-result`, `tool-error`, `step-start`, `step-finish`.
  - Every part is written to SQLite, and text deltas go out as part-delta events, so any client can render the stream.
- **`step-finish`** (`:435-498`):
  - snapshot again;
  - compute usage and cost;
  - write a `patch` part listing the files changed in this step;
  - fork the diff summary;
  - **check for overflow:** `isOverflow({tokens: usage.tokens})` sets `ctx.needsCompaction`.
  - The stream is wrapped in `Stream.takeUntil(() => ctx.needsCompaction)` (`:658`), so the step stops cleanly and returns `"compact"`.
- **Doom-loop detection** (`:353-380`, `DOOM_LOOP_THRESHOLD = 3`). If the last three parts of the current assistant message are calls to the same tool with byte-identical JSON input, the processor raises `permission.ask({ permission: "doom_loop", … })`. The default rule for `doom_loop` is `ask` (`OC/agent/agent.ts:117`), so the user decides whether to continue.
- **Tool-call repair** (`OC/session/llm.ts:296-312`):
  - A wrong-case tool name is fixed.
  - Any other unparsable call is rerouted to the hidden `invalid` tool, which returns `The arguments provided to the tool are invalid: <error>` (`OC/tool/invalid.ts`). The loop never crashes on malformed calls.
- **Retries** (`OC/session/retry.ts:26-31`):

  | Setting | Value |
  |---|---|
  | Attempts | `RETRY_MAX_RETRIES = 5` |
  | Initial delay | 2 s |
  | Backoff factor | ×2 |
  | Jitter | 25% |
  | Cap without headers | 30 s |
  | Server headers | `retry-after-ms` / `retry-after` honoured |
  | Retryable | 429/5xx/"rate limit"/"Overloaded" patterns |

  The status bar shows `{type:"retry", attempt, message, next}`.
- **Provider context overflow** (`ContextOverflowError`) does not fail the turn. It sets `needsCompaction` (`:621-631`) and leads to an *overflow* compaction, which replays the last user message with media stripped (§4.4).
- **Cleanup on abort or error** (`:553-611`):
  - waits up to 250 ms for in-flight tools;
  - marks any still running as `error: "Tool execution aborted"` with `metadata.interrupted = true`.
  - On replay, pending or running tool parts become `output-error "[Tool execution was interrupted]"`, so every `tool_use` keeps a `tool_result` (`OC/session/message-v2.ts:352-363`).
- **Permission rejection stops the loop.** A `RejectedError`/`CorrectedError` sets `ctx.blocked` (`:200-202`) unless `experimental.continue_loop_on_deny` is set.

### 2.3 Cancellation, interrupts and steering

**Cancellation.**
- `Esc` (`session_interrupt`) → `SessionPrompt.cancel` → `SessionRunState.cancel`, which interrupts the runner fiber.
- `cancelBackgroundJobs` walks the job graph by `metadata.parentSessionId` and cancels every descendant sub-agent (`OC/session/run-state.ts:111-143`).
- The shell tool kills with `forceKillAfter: "3 seconds"` (`OC/tool/shell.ts:547-554`).

**Mid-turn steering is LIVE (at step granularity).**
- Typing while busy persists the message and the TUI tags it **`QUEUED`** (`TUI/routes/session/index.tsx:1387-1450`).
- `<leader>q` "Manage queued prompts" lets the user edit or remove queued items.
- The running loop picks the message up at its next DB reload.

**Shell passthrough.** `!cmd` runs a shell command from the prompt as a user-executed tool (`shellImpl`, `:451-597`). It is recorded in history as `"The following tool was executed by the user"` so the model sees it.

---

## 3. Agents and sub-agents

### 3.1 Agent definitions (`OC/agent/agent.ts:94-247`)

**Fields.** `name, description, mode (primary|subagent|all), hidden, model, variant, temperature, topP, color, prompt, permission (ruleset), steps, options`.

**Base permission defaults** (`:113-127`):
```
"*": "allow", doom_loop: "ask",
external_directory: { "*": "ask", <truncation dir>/*: "allow", <skill dirs>/*: "allow" },
question: "deny", plan_enter: "deny", plan_exit: "deny",
read: { "*": "allow", "*.env": "ask", "*.env.*": "ask", "*.env.example": "allow" }
```

**Built-in agents:**

| Agent | Mode | Notes |
|---|---|---|
| `build` | primary | The default; adds `question`/`plan_enter` allow |
| `plan` | primary | `edit: {"*": "deny", ".opencode/plans/*.md": "allow", <data>/plans/*.md: "allow"}`; `task.general: deny` |
| `general` | subagent | Parallel multi-step work; `todowrite: deny` |
| `explore` | subagent | `"*": "deny"` except grep/glob/list/bash/webfetch/websearch/read; prompt `OC/agent/prompt/explore.txt` ("You are a file search specialist… Do not create any files, or run bash commands that modify the user's system state") |
| `compaction` / `title` / `summary` | hidden | Every tool denied; each has its own prompt file |

**Custom agents.**
- Markdown files under `{agent,agents}/**/*.md` in `.opencode/` or `~/.config/opencode/` (`OC/config/agent.ts:11-30`). YAML frontmatter holds the fields above; the body is the prompt.
- The parser accepts Claude-Code-style invalid YAML (`OC/config/markdown.ts:16`).
- Legacy `{mode,modes}/*.md` files are forced to `mode: primary`.
- Agents can also be defined in `opencode.json` under `agent:`; config can disable built-ins (`disable: true`).
- `opencode agent create` generates the agent's identifier, whenToUse and systemPrompt with an LLM call (`generate()`, `:350-430`; prompt `OC/agent/generate.txt`).

**Important semantics.**
- A custom agent's `prompt` **replaces** the model-family base prompt; it is not appended (`OC/session/llm/request.ts:60`: `input.agent.prompt ? [input.agent.prompt] : SystemPrompt.provider(model)`).
- Primary agents are cycled with `Tab`/`Shift+Tab` (`agent_cycle`, `TUI/config/keybind.ts:130-131`). Every user message records which agent and model it used, so switching mid-session is first-class.

### 3.2 Sub-agent spawn (the `task` tool, `OC/tool/task.ts`)

**Parameters.**
- `description` (3-5 words), `prompt`, `subagent_type`.
- optional `task_id` (resume), `command`.
- `background` (behind the `OPENCODE_EXPERIMENTAL_BACKGROUND_SUBAGENTS` flag).

**Execution.**
1. **Depth guard.** Walk `parentID` up to the root; fail if `depth >= cfg.subagent_depth ?? 1` (`:104-117`). Nesting is configurable; the default is one level.
2. **Permission.** `ctx.ask({permission: "task", patterns: [subagent_type]})`, so callable sub-agents can be restricted per agent (for example plan denies `general`).
3. **Child session.**
   - Created with `parentID = ctx.sessionID`, title `"<description> (@<agent> subagent)"`.
   - Permission comes from `deriveSubagentSessionPermission` (`OC/agent/subagent-permissions.ts`): the parent's **deny** rules and `external_directory` rules, plus `todowrite` and `task` **denied** unless the sub-agent's own ruleset grants them.
   - `experimental.primary_tools` are also denied to children (`:143-155`).
4. **Model.** `next.model ?? the parent message's model`, so a per-agent model override is LIVE.
5. **Running the child.** `ops.prompt({sessionID: child, agent, parts})` runs the **ordinary session loop on the child session**. The child gets its own system prompt, compaction, snapshots and permission asks, all surfaced in the UI under that session.
6. **Result.** Only the child's **last text part** is returned (`:224`), wrapped as:
   ```
   <task id="<childSessionID>" state="completed">
   <task_result>
   …
   </task_result>
   </task>
   ```
   Errors become `Subagent failed (task_id: …): …` so the parent can resume the same task.
7. **Resume.** Passing `task_id` reuses the existing child session, which continues "with its previous messages and tool outputs" (`OC/tool/task.txt` note 4). This works for any finished task, not only failed ones.

### 3.3 Background sub-agents and parent↔child communication

Every task runs as a `BackgroundJob` keyed by the child session id (`CORE/background-job.ts`).

**Foreground → background promotion.**
- The foreground path races `background.wait` against `background.waitForPromotion` (`OC/tool/task.ts:334-337`).
- The user presses **Ctrl+B** ("Background synchronous subagents", `TUI/config/keybind.ts:98`). The experimental promote endpoint (`OC/server/routes/instance/httpapi/handlers/experimental.ts:170`) promotes every running job.
- The parent's tool call returns immediately with `state="running"` and this text (`BACKGROUND_STARTED`, `:31-35`):
  > `The task is working in the background. You will be notified automatically when it finishes. DO NOT sleep, poll for progress, ask the task for status, or duplicate this task's work — avoid working with the same files or topics it is using.`

**Result injection.**
- When a background job settles, `inject()` calls `ops.prompt()` on the **parent** session with a synthetic `<task … state="completed"><summary>Background task completed: …</summary>…` part (`:227-254`).
- If the parent is busy, that message is picked up at its next step. If the parent is idle, **a new parent turn starts**.

**Parent → running child messaging.**
- Calling `task` again with the `task_id` of a *running* background task triggers `background.extend()`. That queues another `ops.prompt` into the child session, which the child loop sees at its next step, and returns `BACKGROUND_UPDATED` ("Additional context sent to the running background task…", `:36-41`, `:267-282`).
- This is a real mailbox, built from the same "sessions are re-read every step" property.

**User ↔ child.** Child sessions are ordinary sessions, so the TUI can enter them:

| Key | Action |
|---|---|
| `<leader>down` | first child |
| `right` / `left` | next / previous sibling |
| `up` | back to parent |

(`TUI/config/keybind.ts:103-106`.) While in a child, a footer shows "Subagent N of M", its context % and its cost (`TUI/routes/session/subagent-footer.tsx`). The user can type into the child, and its permission prompts surface there.

**Cancellation** cascades parent → children (§2.3).

**Not present:**
- no shared scratchpad between siblings (the working tree is the shared state);
- no workflow engine or teams;
- no git-worktree isolation per sub-agent. `OC/worktree/` creates `opencode/<name>` branches for *workspaces* (experimental), not for tasks.

### 3.4 Commands as sub-agents

- A markdown command with `subtask: true`, or one whose `agent` is a subagent, becomes a `subtask` part (`OC/session/prompt.ts:1439-1452`).
- The loop runs it through `handleSubtask` → `TaskTool.execute`.
- Afterwards it appends the synthetic user text `"Summarize the task tool output above and continue with your task."` (`:446`).
- `@agentname` in a prompt turns into an instruction to call `task` with that sub-agent (`:974-990`).

---

## 4. Context handling and compaction

### 4.1 Token counting and window

- **Estimates** are `Math.round(chars / 4)` (`CORE/util/token.ts`). They are used only for choosing the compaction tail and for pruning.
- **Overflow decisions use provider-reported usage** from the last finished step: `total || input + output + cache.read + cache.write` (`OC/session/overflow.ts:31-33`).
- **The usable window** (`OC/session/overflow.ts:8-20`):
  ```ts
  const COMPACTION_BUFFER = 20_000
  reserved = cfg.compaction?.reserved ?? Math.min(COMPACTION_BUFFER, maxOutputTokens(model))
  usable   = model.limit.input ? model.limit.input - reserved
                               : model.limit.context - maxOutputTokens(model)
  ```
  `maxOutputTokens = min(model.limit.output, 32_000)` (`OC/provider/transform.ts:18,1481`).
- `compaction.auto: false` or `OPENCODE_DISABLE_AUTOCOMPACT` turns auto-compaction off.

### 4.2 When compaction triggers

| Trigger | Where | Notes |
|---|---|---|
| After any step, reported tokens ≥ usable | `OC/session/processor.ts:491-496` → `takeUntil` → `"compact"` → `prompt.ts:1320` | **Mid-turn.** The turn is not lost; the loop continues after the summary |
| Before a step, the last finished assistant is over the limit | `prompt.ts:1161-1167` | Catches overflow carried over from a previous turn |
| Provider throws `ContextOverflowError` | `processor.ts:621-631` | Compaction with `overflow: true` (replay plus media strip) |
| Manual `/compact` (`<leader>c`) or the `summarize` endpoint | `TUI` / `OC/server/.../session.ts:303` | Same machinery with `auto: false` (no auto-continue) |

### 4.3 What is kept: tail selection (`OC/session/compaction.ts:115-269`)

**Budget for the verbatim tail:**
```ts
preserve = cfg.compaction?.preserve_recent_tokens
        ?? min(15_000, max(2_000, floor(usable * 0.25)))
```

**Selection.**
- Walk user *turns* backwards from the newest, estimating each one's serialized size.
- Keep whole turns while they fit.
- When the next turn does not fit, `splitTurn` keeps the **largest suffix of that turn** that fits (it can start inside a turn, at a step boundary).
- `compaction.tail_turns` optionally caps how many turns are kept.
- The tail's first message id is stored on the compaction part as `tail_start_id`.
- `filterCompacted` later rebuilds the model view as `[compaction-user, summary-assistant, …tail…, continue-user]` (`message-v2.ts:525-576`).

**Rendering trick.** The compaction user message is rendered to the model as the text **`"What did we do so far?"`** (`message-v2.ts:232-236`), so the summary reads as the assistant's natural answer to that question.

### 4.4 The summarisation prompt

**Agent.** The `compaction` agent's system prompt (`OC/agent/prompt/compaction.txt`), verbatim:
> You are a context summarization agent. You are given a conversation between a user and an agent. Your goal is to produce a structured summary matching the format specified so another coding agent can continue the work.
> Always follow the exact output structure requested by the user prompt. Keep every section, preserve exact file paths and identifiers when known, and prefer terse bullets over paragraphs.
> Do not continue the conversation. Do not respond to any questions in the conversation. Only output the structured summary in the exact format requested by the user prompt. Respond in the same language as the conversation.

**User prompt.** Built by `buildPrompt()` (`CORE/session/compaction.ts:160-174`):
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

**Incremental ("anchored") summaries.** When a previous summary exists, the prompt also carries `<prior-summary>…</prior-summary>` plus these instructions (`CORE/session/compaction.ts:47-55`):
> The <prior-summary> summarizes everything that happened before the <conversation>. Construct a new summary that combines both. The <prior-summary> is discarded after this: anything you do not carry into the new summary is lost.
> - Carry forward objectives, constraints, user directives, decisions, and parallel workstreams from the <prior-summary> even when the <conversation> does not mention them. Drop only what is finished and no longer needed.
> - The <conversation> is more recent than the <prior-summary>. Where they conflict, the conversation wins: state the corrected fact and drop the old claim.
> - Add new progress … Move completed work from "Active" to "Completed". … Update "Objective" and "Next Move" to reflect the current work state.

Only the messages **outside** the verbatim tail are serialised (`selected.head`). Earlier compaction pairs are hidden from the input.

**Execution.**
- The summary runs through the normal processor with `tools: {}` and `system: []`, using the compaction agent's model or else the user's model (`compaction.ts:358-448`).
- A tool call during summary generation throws (`processor.ts:316-318`).
- If the summary itself overflows, the result is `"Session too large to compact - context exceeds model limit even after stripping media"` and the loop stops.

**After compaction** (`compaction.ts:468-550`):
- **Overflow case:** the user message that overflowed is *replayed* after the summary, with media parts swapped for `[Attached <mime>: <name>]`.
- **Normal auto case:** a synthetic user message is added (unless the `experimental.compaction.autocontinue` plugin hook disables it):
  > `Continue if you have next steps, or stop and ask for clarification if you are unsure how to proceed.`

  For overflow it is prefixed with an explanation that oversized media was removed. The message is tagged `metadata.compaction_continue = true`.

**Plugin hooks inside compaction.**
- `experimental.session.compacting` can append `context[]` strings or **replace the whole prompt** (`:373-391`).
- `experimental.chat.messages.transform` also runs on a `structuredClone` of the head before serialisation (`:378-379`).

### 4.5 Pruning old tool outputs (`compaction.ts:271-317`)

```ts
export const PRUNE_MINIMUM = 20_000
export const PRUNE_PROTECT = 40_000
const PRUNE_PROTECTED_TOOLS = ["skill"]
```

**Algorithm.**
1. Walk messages from newest to oldest. Skip everything until **two user turns** have been passed: the current and previous turns are never pruned.
2. Stop at a summary message, or at a tool part that is already compacted (pruning is incremental).
3. For each completed tool part (except `skill`), add its estimated output tokens. The first **40k tokens** of tool output are protected; every older part goes on the prune list.
4. Apply only if the prune list totals **more than 20k tokens**, so the cache is not churned for small gains.
5. Applying it sets `part.state.time.compacted = Date.now()` on each part. The stored output is kept for the UI and transcript.

**Model view.** `toModelMessages` renders pruned parts as `"[Old tool result content cleared]"` and drops their attachments (`message-v2.ts:297-300`). The tool call and its arguments are still sent, so structure is preserved.

**When it runs.** After each finished loop (`prompt.ts:1338`, forked).

**Default.** Opt-in in the current schema: `compaction.prune` is described as "Enable pruning of old tool outputs (default: false)" (`CORE/v1/config/config.ts:154-156`), with an `OPENCODE_DISABLE_PRUNE` kill switch (`OC/config/config.ts:596`).

**Compaction-aware instruction re-injection.** Nested `AGENTS.md` files are attached to Read results once (§5.3). The dedup set `extract()` **skips compacted read parts** (`OC/session/instruction.ts:17-32`), so when the Read that carried an instruction file is pruned, the next Read in that directory re-attaches it. Instructions cannot be pruned away permanently.

### 4.6 Tool-output truncation at tool time (`OC/tool/truncate.ts`)

**Limits.** `MAX_LINES = 2000`, `MAX_BYTES = 50 * 1024`, configurable via `tool_output.{max_lines,max_bytes}`.

**What it does.**
- Applied to every tool by the `Tool.define` wrapper (`OC/tool/tool.ts:131-142`), unless the tool sets `metadata.truncated` itself, and to MCP tools.
- Over the limit, the **full text is written** to the truncation dir as `tool_<id>` (swept after 7 days).
- The model gets the head (or tail) preview plus a hint (`:129-131`):
  > `The tool call succeeded but the output was truncated. Full output saved to: <file>\nUse the Task tool to have explore agent process this file with Grep and Read (with offset/limit). Do NOT read the full file yourself - delegate to save context.`

  If the agent cannot use `task`, the hint says to use Grep or Read with offset/limit instead.
- The truncation dir is pre-allowed for `external_directory` in every agent (`agent.ts:247-262`).

**Bash specifics** (`OC/tool/shell.ts:440-590`):
- streams output while keeping a bounded tail in memory;
- spills to a file as soon as output passes `maxBytes`;
- returns the tail with `...output truncated...\n\nFull output saved to: <file>`.

### 4.7 Prompt caching

**Explicit cache marks for Anthropic-family models** (`applyCaching`, `OC/provider/transform.ts:358-405`):
- `cacheControl: ephemeral` (Bedrock uses `cachePoint`) on the **first 2 system messages and the last 2 non-system messages**.
- The system prompt is deliberately kept as at most two system messages, `[header, rest]` (`OC/session/llm/request.ts:68-78`).

**Cache keys and affinity.**
- OpenAI, Azure, xAI, Mistral and Venice get `promptCacheKey = sessionID`; DeepInfra and Cerebras get `prompt_cache_key` (`transform.ts:1323-1336`).
- **Every non-opencode provider is sent `x-session-affinity: <sessionID>` and `X-Session-Id`** (`request.ts:198-201`). That is exactly what an SGLang router needs for sticky radix-cache routing.

**Stable prefix by design.**
- The environment block contains only static facts plus the date at **day** granularity (`OC/session/system.ts:74-85`).
- No git status, no file tree, nothing that changes per step.
- Tool lists are sorted by name (`request.ts:184`).

**v2 "context epoch"** (`CORE/session/context-epoch.ts`, `CORE/system-context/*`, `CORE/instruction-context.ts`):
- The system context (env, date, AGENTS.md set) is frozen as a per-session **baseline**.
- When a source changes (date rollover, an edited AGENTS.md), it is **not** re-rendered into the system prompt. A delta message is published into history instead, for example `"Today's date is now: …"` or `"These instructions replace all previously loaded ambient instructions.\n\n…"`. It is serialised as `[System update]: …`.
- The baseline is regenerated only after a compaction, when the prefix is being rewritten anyway.
- This is the clearest statement of "never mutate the cached prefix".

### 4.8 Agent-controlled pruning

opencode core has **no** model-callable "forget" or "prune" tool. Self-pruning is delegated to plugins through `experimental.chat.messages.transform` (§9). The dynamic-context-pruning plugin is studied by another agent.

---

## 5. Prompt generation: what is sent each request

### 5.1 Assembly order (`OC/session/prompt.ts:1257-1271` + `OC/session/llm/request.ts:56-78`)

```
system[0] = join("\n", [
   agent.prompt  OR  SystemPrompt.provider(model)   // model-family base prompt
   ...env           // SystemPrompt.environment
   ...instructions  // Instruction.system(): AGENTS.md/CLAUDE.md/config instructions
   mcpInstructions  // <mcp_instructions><server name=…>…</server></mcp_instructions>
   skills           // verbose <available_skills> listing
   user.system      // per-request override (SDK)
   STRUCTURED_OUTPUT_SYSTEM_PROMPT if json_schema format
])
→ plugin "experimental.chat.system.transform" may push more → collapsed to [header, rest]
messages = toModelMessages(history)  (+ MAX_STEPS_PROMPT assistant prefill on the last step)
tools    = sorted, permission-filtered, plugin "tool.definition"-rewritten
```

### 5.2 Model-family base prompts (`OC/session/system.ts:28-51`)

| Match on `model.api.id` | File | Size |
|---|---|---|
| `muse` | `meta.txt` (with `{{MODEL_NAME}}`) | 9.2 KB |
| `gpt-4`, `o1`, `o3` | `beast.txt` ("keep going until the user's query is completely resolved… THE PROBLEM CAN NOT BE SOLVED WITHOUT EXTENSIVE INTERNET RESEARCH") | 11 KB |
| `gpt-6` | `gpt-astra.txt` | 4 KB |
| `gpt` + `codex` | `codex.txt` | 7.4 KB |
| other `gpt` | `gpt.txt` ("You and the user share the same workspace… pragmatic… senior software engineer") | 9.3 KB |
| `gemini-` | `gemini.txt` ("Core Mandates") | 15 KB |
| `claude` | `anthropic.txt` | 8.2 KB |
| `trinity` / `kimi` | `trinity.txt` / `kimi.txt` | ~8 KB |
| everything else (DeepSeek, Qwen, GLM…) | `default.txt` | 8.5 KB |

**`anthropic.txt` main sections** (quoted, abbreviated):
- Identity: "You are OpenCode, the best coding agent on the planet."
- "IMPORTANT: You must NEVER generate or guess URLs…".
- When asked about OpenCode itself, use WebFetch on `https://opencode.ai/docs`.
- **Tone and style:** no emojis, short and concise, "Output text to communicate with the user… Never use tools like Bash or code comments as means to communicate", "NEVER create files unless they're absolutely necessary".
- **Professional objectivity:** "Prioritize technical accuracy and truthfulness over validating the user's beliefs…".
- **Task management:** "Use these tools VERY frequently…" (TodoWrite), with two worked examples.
- **Tool usage policy:** "When doing file search, prefer to use the Task tool in order to reduce context usage"; "VERY IMPORTANT: When exploring the codebase … it is CRITICAL that you use the Task tool instead of running search commands directly"; maximise parallel calls; use specialised tools instead of bash.
- "Tool results and user messages may include <system-reminder> tags…".
- **Code references:** `file_path:line_number`.

**`default.txt`** (what DeepSeek-V4 or Qwen would get):
- the older terse Claude-Code style: "You MUST answer concisely with fewer than 4 lines…";
- "IMPORTANT: DO NOT ADD ***ANY*** COMMENTS unless asked";
- "When you have completed a task, you MUST run the lint and typecheck commands… proactively suggest writing it to AGENTS.md";
- "NEVER commit changes unless the user explicitly asks you to".

### 5.3 Environment, instructions, MCP, skills

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
It is optionally followed by `<available_references>` (extra directories registered as project references). It contains **no git status, no branch, no file tree.** The shell tool's *description* adds OS, shell and temp dir (§7.3).

**Instruction files** (`OC/session/instruction.ts:60-169`):
- **Global:** first existing of `~/.config/opencode/AGENTS.md`, `~/.claude/CLAUDE.md` (the latter unless `disableClaudeCodePrompt`).
- **Project:** `findUp` from cwd to the worktree root for `AGENTS.md`, else `CLAUDE.md`, else `CONTEXT.md`. The first *filename* that matches wins, but every ancestor copy of that file is included.
- **Config `instructions[]`:** globs (relative ones are globbed upward), absolute paths, `~/` paths, and **https URLs fetched with a 5 s timeout**.
- Each file becomes `Instructions from: <path>\n<content>`.
- **Nested AGENTS.md:** when Read touches a file, `Instruction.resolve` walks from that file's directory up to the root. Any not-yet-attached instruction file is appended to the Read output inside `<system-reminder>…</system-reminder>` (`OC/tool/read.ts:300,355-356`). It is attached once per assistant message and re-attached after pruning (§4.5).

**MCP server instructions** come in `<mcp_instructions>`, filtered to servers whose tools are not all denied (`system.ts:121-137`).

**Skills listing** (`system.ts:107-119`):
```
Skills provide specialized instructions and workflows for specific tasks.
Use the skill tool to load a skill when a task matches its description.
<available_skills> … </available_skills>
```
A code comment explains the design choice: "the agents seem to ingest the information about skills a bit better if we present a more verbose version of them here and a less verbose version in tool description".

### 5.4 Tool descriptions as prompt

- Each tool's `.txt` file is its description: `OC/tool/{edit,read,write,task,todowrite,question,webfetch,…}.txt`.
- The `task` description appends the live sub-agent roster: `"- <name>: <description>"`, filtered by the caller's `task` permission (`OC/tool/registry.ts:265-278`).
- The shell description is rendered per platform and shell (§7.3).

### 5.5 Mid-conversation reminders (`OC/session/reminders.ts`)

These are synthetic text parts appended to the **latest user message**, so they ride in the user turn rather than the system prompt.

- **Plan agent (classic):** `plan.txt`:
  > `<system-reminder># Plan Mode - System Reminder\nCRITICAL: Plan mode ACTIVE - you are in READ-ONLY phase. STRICTLY FORBIDDEN: ANY file edits, modifications, or system changes. … ZERO exceptions. … Ask the user clarifying questions…</system-reminder>`
- **Switching plan → build:** `build-switch.txt`: "Your operational mode has changed from plan to build. You are no longer in read-only mode…". In experimental plan mode it adds "A plan file exists at X. You should execute on the plan defined within it".
- **Experimental plan mode:** `plan-mode.txt`, a 5-phase workflow:
  1. Launch up to 3 `explore` agents in parallel.
  2. Launch a design agent.
  3. Review.
  4. Write the final plan to the plan file, "the only file you can edit".
  5. Call `plan_exit`. "Your turn should only end with either asking the user a question or calling plan_exit."
- **Other synthetic user texts:**
  - `"Summarize the task tool output above and continue with your task."` after subtask commands;
  - the compaction auto-continue message;
  - `"The following tool was executed by the user"` for `!cmd`;
  - `Called the Read tool with the following input: {...}` plus the file content when the user attaches `@file` (`prompt.ts:778-840`). User attachments are converted into **faux Read tool output**, honouring `?start=&end=` line ranges, and LSP symbol lookup when start == end.

---

## 6. Memory

opencode has **no memory subsystem**:
- no memory tool;
- no auto-extraction;
- no per-user note store.

Persistence of knowledge rests on three things:
1. **`AGENTS.md`.** The `/init` command generates or improves it with a strong prompt (`OC/command/template/initialize.txt`). The test it applies to each line: *"Would an agent likely miss this without help?"* It covers exact commands, single-test invocation, ordering, monorepo boundaries, quirks; it excludes generic advice; and "If `AGENTS.md` already exists … improve it in place rather than rewriting blindly".
2. **The global `~/.config/opencode/AGENTS.md`.**
3. **Config `instructions[]`**, including remote URLs.

`default.txt` also nudges the model to suggest writing the lint and test commands into AGENTS.md.

**Comparison.** sugar-crush's `MemoryStore` plus `/memory` is *ahead* of opencode here (baseline §5), even though its recall is crude.

---

## 7. Tools and editing

### 7.1 Roster (`OC/tool/registry.ts:207-251`)

| Tool | Notes |
|---|---|
| `invalid` | hidden repair sink |
| `question` | for the app/cli/desktop clients; multiple-choice with a free-text option |
| `shell` (`bash`) | tree-sitter permission scan; timeout and workdir parameters |
| `read` | offset/limit, line numbers, images/PDF, directories |
| `glob`, `grep` | ripgrep-backed, 100-result cap |
| `edit`, `write` | for non-GPT models |
| `apply_patch` | **instead of** edit/write for `gpt-*` (not oss/gpt-4) (`:289-293`) |
| `task` | sub-agents |
| `webfetch` | text / markdown (turndown) / html; 5 MB; timeout ≤120 s |
| `todowrite` | session todo list |
| `websearch` | Exa / Parallel, provider-gated |
| `skill` | |
| `lsp` | experimental flag: goToDefinition, findReferences, hover, documentSymbol, workspaceSymbol, goToImplementation, call hierarchy |
| `plan_exit` | experimental plan mode |
| `execute` | experimental **code mode**: "Run a confined orchestration script with access to connected MCP tools" (`OC/tool/code-mode.ts:14`) |
| MCP tools | plus the MCP resource tools `list_mcp_resources`, `list_mcp_resource_templates`, `read_mcp_resource` (`OC/session/tools.ts:28-30`) |
| custom tools | `{tool,tools}/*.{js,ts}` in config dirs, plus plugin-provided tools (`registry.ts:185-202`) |

### 7.2 Editing

**Edit parameters:** `filePath`, `oldString`, `newString`, `replaceAll`.

**Behaviour** (`OC/tool/edit.ts:59-213`):
- `oldString === ""` creates a new file, but refuses an existing one ("use write for an intentional full-file replacement").
- BOM handling and line-ending normalisation (CRLF preserved).
- **Per-file semaphore.**
- A diff is computed and passed to `ctx.ask({permission: "edit", metadata: {diff}})`, so the permission dialog shows the diff before the write.
- Write → **formatter** (`format.file()`; e.g. `pint` when `laravel/pint` is in composer.json, `OC/format/formatter.ts:362-374`) → LSP diagnostics.

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
- **Safety guard** `isDisproportionateMatch` (`:731-737`): refuse if the matched span has ≥ max(old+3, 2×old) lines, or is >4× (or +500 chars) longer than `oldString`. The error is "Refusing replacement because the matched span is much larger than oldString. Re-read the file…".
- Error texts are actionable: "Could not find oldString in the file. It must match exactly, including whitespace, indentation, and line endings." / "Found multiple matches for oldString. Provide more surrounding context to make the match unique."
- The description *claims* read-before-edit is enforced ("This tool will error if you attempt an edit without reading the file"), but **no check exists in `edit.ts`**. The same gap exists in sugar-crush.

**`apply_patch`** (`OC/tool/apply_patch.ts`, `OC/patch/index.ts`) uses the Codex `*** Begin Patch` format (add/update/delete/move). After applying, it collects LSP diagnostics per touched file.

### 7.3 Read, shell, web

**Read** (`OC/tool/read.ts`):

| Setting | Value |
|---|---|
| default lines | `DEFAULT_READ_LIMIT = 2000` |
| line format | `N: content` |
| long lines | cut at 2,000 chars with `... (line truncated to 2000 chars)` |
| byte cap | `MAX_BYTES = 50 KB`, with a footer `(Output capped at 50 KB. Showing lines a-b. Use offset=N to continue.)` |
| paging | 1-indexed `offset`, `limit`; out-of-range offset is an error |
| binary | refused (`:328`) |
| images, PDFs | returned as attachments |
| directories | entry listing with `/` suffix, paged |

The description adds: "Avoid tiny repeated slices (30 line chunks). If you need more context, read a larger window." and "Call this tool in parallel…".

**Shell** (`OC/tool/shell.ts`, `OC/tool/shell/prompt.ts`, `shell.txt`):
- **Parameters:** `command`, `timeout` (ms), `workdir` ("Use this instead of 'cd' commands"), `description`.
- **Default timeout** 2 min (`:347`, `OPENCODE_EXPERIMENTAL_BASH_DEFAULT_TIMEOUT_MS`). On expiry the process is killed and this is appended in `<shell_metadata>` (`:562-565`):
  > `shell tool terminated command after exceeding timeout N ms. If this command is expected to take longer and is not waiting for interactive input, retry with a larger timeout value in milliseconds.`
- **Output streams live** into `metadata.output`, so the UI shows progress (`:486-534`).
- `shell.env` plugin hook for environment injection.
- **Shell-aware:** bash, zsh, PowerShell and cmd are parsed with tree-sitter.
- **The description is rendered with** OS, shell, a pre-approved temp dir, and a generic "# Git and GitHub" section:
  > Only commit, amend, push, or create PRs when explicitly requested. Before committing, inspect `git status`, `git diff`, and `git log --oneline -10`; stage only intended files and never commit secrets… Do not update git config, skip hooks, use interactive `-i`, force-push, or create empty commits unless explicitly requested. If a commit fails or hooks reject it, fix the issue and create a new commit; do not amend the failed commit… Use `gh` for GitHub tasks… return the PR URL when done.
- **No background shells and no PTY tool** in the agent toolset. The server does expose PTY endpoints for the UI terminal (`OC/server/routes/instance/httpapi/groups/pty.ts`).

**Web.**
- `webfetch` converts HTML to markdown with Turndown by default (`OC/tool/webfetch.ts:5,15-20`).
- `websearch` uses Exa or Parallel and is enabled per provider or flag.

### 7.4 LSP and the diagnostics loop

**Servers** (`OC/lsp/server.ts`). More than 30 definitions: deno, typescript, vue, eslint, oxlint, biome, gopls, ruby-lsp, ty, pyright, elixir-ls, zls, csharp, razor, fsharp, sourcekit, rust-analyzer, clangd, svelte, astro, jdtls, kotlin-ls, yaml-ls, lua-ls, **PHP Intelephense** (root `composer.json`/`composer.lock`/`.php-version`; auto-installed from npm unless `disableLspDownload`, `:1515-1544`), prisma, and more.

**Configuration.** Config `lsp` can disable servers or add custom ones (`command`, `extensions`, `env`, `initialization`; `OC/lsp/lsp.ts:151-183`).

**Feedback loop after every edit/write/patch:**
1. `lsp.touchFile(path, "document")` opens or changes the document and **waits for fresh diagnostics**: `DIAGNOSTICS_DEBOUNCE_MS = 150`, `DIAGNOSTICS_DOCUMENT_WAIT_TIMEOUT_MS = 5_000`, full-project wait 10 s, request timeout 3 s (`OC/lsp/client.ts:13-16`).
2. `LSP.Diagnostic.report()` keeps **severity-1 (ERROR) only**, at most 20 per file (`OC/lsp/diagnostic.ts`):
   ```
   LSP errors detected in this file, please fix:
   <diagnostics file="/abs/path.ts">
   ERROR [12:5] Property 'x' does not exist on type …
   … and N more
   </diagnostics>
   ```
3. `write` additionally reports errors in **up to 5 other files** ("LSP errors detected in other files:", `OC/tool/write.ts:18,74-90`). This catches breakage the edit caused elsewhere.

The sidebar has an LSP status panel (`TUI/feature-plugins/sidebar/lsp.tsx`).

### 7.5 Todo and question tools

**`todowrite`** (`OC/tool/todo.ts`, `todowrite.txt`):
- Replaces the whole list `{content, status: pending|in_progress|completed|cancelled, priority}`, stored per session in SQLite.
- Usage rules: use for 3+ step tasks, exactly one item `in_progress`, mark items done immediately.
- Rendered in the sidebar (`TUI/feature-plugins/sidebar/todo.tsx`) and exposed at `GET /session/:id/todo`.
- Denied to sub-agents by default.

**`question`**: options, `multiple`, and a "Type your own answer" choice added automatically. "If you recommend a specific option, make that the first option… add '(Recommended)'". A rejection is a `Question.RejectedError` and stops the loop.

---

## 8. Git integration

| Feature | opencode |
|---|---|
| Git status in the prompt | **No.** Only "Is directory a git repo: yes/no" (§5.3) |
| Auto-commit | **No.** All prompts say "NEVER commit… unless explicitly asked"; the shell description carries generic commit/PR etiquette |
| Checkpoints / undo | **Shadow git snapshots**, below |
| Per-turn diff | `SessionSummary.summarize` computes the files changed by each user message from the first `step-start` snapshot to the last `step-finish` snapshot; shown in the UI diff viewer (`OC/session/summary.ts`, `TUI/feature-plugins/system/diff-viewer*.tsx`) |
| Worktrees | `OC/worktree/index.ts` creates `git worktree add --no-checkout -b opencode/<name>`, for experimental *workspaces*, not per agent |
| PR flows | `opencode pr`, `opencode github` (a GitHub-Action agent triggered by `/opencode` comments), `/review [commit|branch|pr]` built-in command (subtask; `OC/command/template/review.txt`) |

### 8.1 Snapshots (`OC/snapshot/index.ts`)

**Storage.**
- The git dir lives at `<data>/snapshot/<projectID>/<hash(worktree)>` and is driven with `--git-dir <gitdir> --work-tree <worktree>` (`:71-75`). The user's repo is never touched.
- **Seeding:** `objects/info/alternates` points at the project's real object DB (and its alternates), and the project's `index` is copied in. "on huge repos like chromium checkout the git add --all rebuilding the hashes can take minutes. By doing this we eliminating this at all" (`:195-233`).
- Init config: `core.autocrlf false`, `core.longpaths`, `core.symlinks`, `fsmonitor false`, `feature.manyFiles`, `index.version 4`, `untrackedCache` (`:326-336`).
- The project's `info/exclude` is mirrored. Ignored files are removed from the snapshot index. **Untracked files over 2 MB are skipped** (`limit = 2 * 1024 * 1024`, `:24`).

**Operations.**
- `track()` stages the changed files (`diff-files` plus `ls-files --others --exclude-standard`), then `write-tree`. The result is a **tree hash** with no commits and no refs (`:318-346`).
- Pruning: `git gc --prune=7.days`.
- `patch(hash)` lists the changed files since that hash. `diffFull(from, to)` gives structured file diffs.
- `revert(patches)` runs, per file, `git checkout <hash> -- <file>`. If the file did not exist in the snapshot, **it is deleted**. Runs are batched up to 100 non-clashing paths (`:408-470`).
- `restore(snapshot)` runs `read-tree` plus `checkout-index -a -f`.

### 8.2 Revert and unrevert (`OC/session/revert.ts`)

- **`revert(messageID, partID?)`:**
  - collects every `patch` part after the target and reverts those files;
  - records `session.revert = {messageID, partID, snapshot (pre-revert tree), diff}` so the revert itself can be undone;
  - the messages are **soft-hidden**, not deleted.
- **`unrevert`** restores the pre-revert snapshot.
- **`cleanup`** (called on the next prompt or shell) permanently deletes the hidden messages (`:101-124`).
- **TUI integration** (`TUI/routes/session/index.tsx:614-660`):
  - `/undo` (`<leader>u`) reverts the last user message's file changes **and puts that message's text and file attachments back in the input box**, ready to edit and resend;
  - `/redo` (`<leader>r`) unreverts;
  - `/timeline` jumps to any message; `/fork` forks from any message.

---

## 9. Extensibility

### 9.1 Plugins (`PLUGIN/index.ts`, `OC/plugin/*`)

**Loading.**
- Plugins are listed in config `plugin: ["npm-pkg", ["pkg", {opts}]]`; npm packages are auto-installed with a version-compatibility check (`OC/plugin/loader.ts:82-147`).
- Local plugins live in `.opencode/plugin(s)/`.

**Signature.**
```ts
type Plugin = (input: {
  client,        // a full SDK client bound to this server
  project, directory, worktree,
  serverUrl,
  $              // Bun shell
}, options?) => Promise<Hooks>
```

**Hooks** (`PLUGIN/index.ts:222-335`), in full:

| Hook | Can intercept / mutate |
|---|---|
| `event` | every bus event (read-only observer) |
| `config` | the loaded config |
| `tool: {name: ToolDefinition}` | add tools (zod args, `execute(args, ctx)` with `ctx.ask()`, `ctx.metadata()`, abort) |
| `auth`, `provider` | auth methods (OAuth / API prompts); dynamic provider model lists |
| `chat.message` | the incoming user message and its parts (before save) |
| `chat.params` | temperature, topP, topK, maxOutputTokens, provider options |
| `chat.headers` | outgoing HTTP headers |
| `permission.ask` | force `ask`/`deny`/`allow` |
| `command.execute.before` | parts of an expanded command |
| `tool.execute.before` / `tool.execute.after` | tool **args** before; **title/output/metadata** after (so output can be rewritten or shrunk) |
| `shell.env` | environment for shell and `!cmd` |
| `experimental.chat.messages.transform` | **the whole message list (info + parts) before conversion to model messages, every step, plus a cloned copy before compaction serialisation.** Mutations are not persisted (the list is reloaded from the DB each step), making it the natural seam for dynamic context pruning (dedup, superseded-write removal, stale-output elision) |
| `experimental.chat.system.transform` | the system string array |
| `experimental.provider.small_model` | choice of the small model (titles) |
| `experimental.session.compacting` | add context to, or replace, the compaction prompt |
| `experimental.compaction.autocontinue` | disable the synthetic "Continue…" turn |
| `experimental.text.complete` | rewrite the final text part |
| `tool.definition` | rewrite any tool's description or parameters sent to the LLM |

TUI plugins exist separately (`TUI/plugin`, `feature-plugins/*`): the sidebar panels, notifications and the diff viewer are themselves built as plugins.

### 9.2 MCP (`OC/mcp/*`)

- **Transports:** stdio, **Streamable HTTP and SSE**.
- **Auth:** OAuth with dynamic registration and a callback server.
- **Capabilities:**
  - tools;
  - **resources**, via the three resource tools and `@`-attachable resources (`prompt.ts:675-761`, binary blobs ≤10 MB for pdf/png/jpeg/gif/webp);
  - **prompts**, surfaced as slash commands (`OC/command/index.ts:105-132`);
  - server `instructions`, injected into the system prompt.
- Progress tokens reset tool timeouts (`OC/mcp/catalog.ts:62-64`).
- MCP tool output passes through `Truncate` like every other tool.

### 9.3 Commands, skills, rules

**Commands.**
- Markdown files with frontmatter `description, agent, model, subtask`.
- Template features: `$ARGUMENTS`, `$1..$n` (the last placeholder swallows the rest), `` !`cmd` `` shell expansion, `@file` includes.
- Arguments are appended if the template has no placeholders (`prompt.ts:1356-1458`).
- Built-ins: `/init`, `/review`. Skills and MCP prompts also appear as commands.

**Skills.**
- `SKILL.md` files under `.opencode/{skill,skills}/**`, `.claude/skills/**`, `.agents/skills/**` and global dirs (`OC/skill/index.ts:21-25`).
- Permission per skill name.
- The skill tool returns `<skill_content name=…>`, plus "Base directory for this skill: …", plus a `<skill_files>` list (`OC/tool/skill.ts:47-59`).
- Skill outputs are **never pruned** (§4.5).

### 9.4 Client/server and SDK

- `opencode serve` runs the headless HTTP server; `opencode attach <url>` connects a TUI to a remote server (basic auth).
- `opencode run "…"` supports `--format json`, `--attach`, `--continue/--session/--fork`, `--share`, `--agent`, `--model`, `--variant`, `--thinking`, `--dangerously-skip-permissions`, `--replay` (`OC/cli/cmd/run.ts:143-260`).
- `opencode acp` runs an Agent Client Protocol server for Zed and similar editors.
- `opencode web` serves the web UI.
- `export`/`import` move sessions; `stats` reports usage.
- **Session HTTP API** (`OC/server/routes/instance/httpapi/groups/session.ts:111-433`): list, status, get, **children**, **todo**, diff, messages, create, update, **fork**, **abort**, init, **share/unshare**, summarize, prompt, **promptAsync**, command, shell, **revert/unrevert**, permission respond, delete/update message or part.
- Every UI action is an API call, so the TUI, desktop and web clients share one engine.

---

## 10. Permissions and safety

**Model.** Flat rulesets of `{permission, pattern, action: allow|deny|ask}`.
- `evaluate` uses **`findLast`**: the *last* matching rule wins, so later layers (agent → user config → session) override earlier ones.
- An unmatched permission defaults to `ask` (`OC/permission/index.ts:27-37`).

**Config shape** (`Permission.fromConfig`, `:180-193`):
```json
"permission": {
  "edit": "ask",
  "bash": { "*": "ask", "git status *": "allow", "rm *": "deny" },
  "external_directory": { "~/scratch/*": "allow" },
  "read": { "*.env": "deny" },
  "task": { "general": "deny" },
  "doom_loop": "ask", "webfetch": "allow", "skill": { "secret-*": "deny" }
}
```
`~` and `$HOME` are expanded.

**Tool visibility.** `Permission.disabled()` removes a tool from the request entirely when its last rule is `"*": deny` (edit, write and apply_patch share the `edit` key; `:199-209`). The model never sees forbidden tools.

**The ask flow:**
1. The tool calls `ctx.ask({permission, patterns, always, metadata})`.
2. If any pattern evaluates to `deny`, a `DeniedError` is returned to the model:
   > `The user has specified a rule which prevents you from using this specific tool call. Here are some of the relevant rules [...]`
3. If any pattern evaluates to `ask`, an `Asked` event is published and the tool fiber awaits a `Deferred`.
4. The client replies with:
   - **`once`**;
   - **`always`**: the request's `always` patterns are added to the session's `approved` list, and other pending asks that are now satisfied are auto-approved;
   - **`reject`** with an optional **message**. The message becomes a `CorrectedError`: `The user rejected permission to use this specific tool call with the following feedback: <msg>` (`CORE/v1/permission.ts:13-19`). Reject also cascades to every other pending ask in the session (`:124-137`).
5. v2 adds **persisted** per-project saved approvals (`CORE/permission.ts`, `PermissionSaved`).

**Bash pattern extraction** (`OC/tool/shell.ts:378-413`, `OC/permission/arity.ts`):
- The command is parsed with tree-sitter (bash or PowerShell). Every simple command in pipes or chains is checked individually.
- `patterns` gets the exact source of each command.
- `always` gets `BashArity.prefix(tokens).join(" ") + " *"`. The arity table is LLM-generated: `git`=2, `npm`=2, `npm run`=3, `docker compose`=3, `aws`=3, …. So "always allow `git checkout main`" stores `git checkout *`.
- For file commands (`rm`, `cp`, `mv`, `mkdir`, `touch`, `chmod`, `chown`, `cat`, plus PowerShell and cmd equivalents), path arguments are resolved. A path outside the project raises an **`external_directory`** ask for that directory, and so does a `workdir` outside the project.

**Other guards.**
- `.env` reads ask by default; `.env.example` is allowed.
- Edit asks carry the diff, so the dialog shows exactly what will change.
- `doom_loop` asks (§2.2).
- Plan mode is enforced by permission, not just by prompt (`edit: {"*": "deny"}`).
- Sub-agents inherit the parent's deny rules (§3.2).

**Not present:** no OS sandbox (no bubblewrap or seatbelt), no secret redaction, no LLM safety classifier.

---

## 11. UX worth copying

**Interaction and editing.**
- **Undo/redo restore the prompt text** (§8.2); a timeline view; fork from any message.
- **Queued prompts** show a `QUEUED` badge and can be managed (`<leader>q`).
- **Child-session navigation** with arrow keys, plus the sub-agent footer (index, context %, cost).
- Agent cycling with `Tab`; **model variants** (reasoning-effort presets) with `Ctrl+T`.
- A permission dialog that shows the diff; replies once / always / reject-with-message.
- The `question` tool renders as an interactive picker.

**Panels.**
- **Sidebar panels** (`TUI/feature-plugins/sidebar/`): context (tokens and % of window), cost, **todo list**, modified files, **LSP status**, MCP status.
- A diff viewer with a file tree (`TUI/feature-plugins/system/diff-viewer*.tsx`).

**Notifications** (`TUI/feature-plugins/system/notifications.ts`): attention plus sound on "Session done" (a distinct sound when a *subagent* finishes), "Permission needs input", "Question needs input", and errors.

**Sessions and sharing.**
- `/share` gives a live share URL. The share service subscribes to session, message, part and diff events and syncs them (debounced 1 s, coalesced by key) to the share server (`OC/share/share-next.ts:111-145`). Config `share: auto|manual|disabled`; `OPENCODE_DISABLE_SHARE`.
- `/export` (to `$EDITOR`), `/copy`, external editor for the prompt (`<leader>e`).
- Toggles for timestamps, thinking blocks and generic tool output.
- Session list with pins and quick-switch slots 1-9 (`<leader>1..9`).

**Status.** A retry countdown appears in the status area while retrying (§2.2).

---

## 12. Comparison table

sugar-crush status column cites the baseline (`§` = baseline section).

| Feature | opencode | sugar-crush (baseline) | Gap |
|---|---|---|---|
| Loop granularity | Step loop, history reloaded from DB each step | `runTurn` ≤ `maxSteps` in a forked child (§0.1, §1.4) — LIVE | Default step cap **8** vs opencode **∞** |
| Parallel tools | All calls in a step concurrently; per-file edit lock | ParallelSafe segments forked (§1.4.6) — LIVE | Comparable; sugar-crush serialises writes, safer but slower |
| Mid-turn steering | LIVE (queued messages picked up next step) | Queue released only after the turn — ABSENT (§1.4) | Large |
| Doom-loop detection | 3 identical calls → ask | ABSENT | Small to fix |
| Max-steps behaviour | `MAX_STEPS_PROMPT` forces a text summary | Hard truncation + notice (§1.4.4) — LIVE | Medium |
| Tool-call repair | `invalid` tool sink, case fix | Truncated-call flush only (Sglang) | Small |
| Retry | 5×, retry-after honoured, UI countdown | 3×, 0.5 s base, never after streaming (§1.3) — LIVE | Small |
| Interrupt / cancel | Esc; cascades to children; interrupted tools keep pairing | Esc Esc kills the child; per-agent cancel inert (§2.2) | Medium |
| Agent definitions | build/plan/general/explore + md agents; `mode`, `steps`, `model`, `permission` all honoured | Roster LIVE; `model`, `permissionMode`, `effort`, `isolation` DORMANT (§2.1) | Medium |
| Sub-agent result | `<task id state><task_result>` + task_id | Final text only; resume only after failure (§2.2) — LIVE | Medium |
| Sub-agent nesting | `subagent_depth` (default 1) | Fixed depth 1 (§2.2) | Small |
| Background sub-agents + result injection | LIVE (flag) + Ctrl+B promotion | `/bg` daemons, result never injected (§2.4) — PARTIAL | Large |
| Parent→child messaging | `task(task_id)` on a running task = extend | Mailbox/Team DORMANT (§2.3) | Large (dormant code exists) |
| User ↔ child session | Navigate into children, type there | ABSENT | Large |
| Todo tool | `todowrite` + sidebar | ABSENT (§2.3, §6.2) | Medium |
| Plan mode | Plan agent with permission-enforced read-only + plan file + `plan_exit` | `plan` permission mode exists, but Ask→deny in the TUI (§9.5) | Medium |
| Mid-turn compaction | LIVE (after any step) | Submit-time only (§3.3) | **Large** |
| Summary format | Anchored 5-section template, merged with the prior summary | 6-facet per-exchange record (§3.3) — LIVE | Comparable; opencode's is state-oriented |
| Verbatim tail | Token budget (2k–15k, 25% of usable), can split a turn | `recentPreserveCount=10` "pairs" (§3.3) | Medium |
| Old tool-output pruning | 40k protect / 20k min, protect 2 turns + skill | ABSENT; `removeToolResults` is a no-op (§3.3) | **Large** |
| Structured tool history across turns | Full tool_use/tool_result replay | Tool outputs replayed as assistant text (§0.3) | **Large** |
| Truncate-to-file | 2000 lines / 50 KB → file + hint | 64 KiB head+tail, Read 1 MiB, MCP uncapped (§3.4) | Medium |
| Prompt-cache marks | First 2 system + last 2 messages (Anthropic) | `CacheBreakpoints` DORMANT (§3.5) | Medium |
| Session affinity header | `x-session-affinity: sessionID` | `SessionAffinity` DORMANT (§1.3) | **Small effort, big win on SGLang** |
| Stable system prefix | Static env, day-granular date; v2 deltas as messages | `<env>` with git status/diffs re-rendered per step; Sglang folds history system rows into the head (§4, verified) | **Large (cache)** |
| Per-model prompts | 9 families | One base prompt (§4) | Medium |
| Instruction files | AGENTS/CLAUDE/CONTEXT findUp, global, URLs, nested on Read, re-attached after prune | Root + ancestors, forced globs, nested on touch (§4) — LIVE | sugar-crush lacks global `~/.claude/CLAUDE.md` and URLs |
| Memory | None | MemoryStore, project-scope recall (§5) — LIVE | sugar-crush ahead |
| Edit fuzzy matching | 9-stage replacer chain + disproportion guard | Exact `substr_count` (§6.3) | Medium |
| apply_patch | For GPT family | ABSENT | Small |
| Read paging | offset/limit, line numbers, 2000 lines / 50 KB, images, PDF | Whole file ≤1 MiB, no line numbers (§6.3) | Medium |
| Bash timeout / workdir | Per-call `timeout` (2 min default), `workdir`, live output | No timeout; 120 s silence kills the turn (§6.4) | **Medium; risky default** |
| LSP diagnostics after edit | LIVE, 30+ servers, auto-install | LSP client DORMANT (§6.6) | **Large (dormant code exists)** |
| Formatter after write | LIVE | ABSENT | Medium |
| Shadow-git snapshots + undo | LIVE, per step | `/rewind` restores the transcript only (§7, §8) | **Large** |
| Per-turn diff summary | LIVE (snapshot range) | Per-edit LCS diffs only (§7) | Medium |
| Permission prompts in TUI | LIVE, with diff, once/always/reject+feedback | Ask→deny on the engine path; default bypass (§9.5) | **Critical** |
| Argument-pattern rules | `bash: {"git *": allow}` + tree-sitter arity | Tool-name only (§9.5) | Large |
| External-directory guard | Ask for paths outside the project | PathJail for file tools; Bash unjailed (§6.4) | Medium |
| Plugin hooks | 20 hooks incl. message/system transform, compaction | Script hooks: PreToolUse/PostToolUse/UserPromptSubmit/SessionStart LIVE; others DORMANT (§9.3) | Medium |
| MCP | stdio/HTTP/SSE, OAuth, resources, prompts, instructions | stdio/http/git, OAuth, tools only (§9.4) | Medium |
| Commands | md + `!cmd` + `@file`; `subtask`, `agent`, `model` honoured; `/init`, `/review` | md + `!cmd` + `@file`; `model`/`subtask` DORMANT; no `/init` (§9.2) | Medium |
| Client/server, SDK, ACP | LIVE | ABSENT (§8) | Large (architectural) |
| Share | Live-synced URL | Uploader stub always throws (§8) | Medium |
| Notifications | Sound/attention on done, permission, question | ABSENT (§10) | Small |
| Sidebar: todo / context / cost / LSP / MCP | LIVE | Panes exist; Files pane empty (§10) | Medium |
| Title generation | Small model, title agent prompt | LIVE (§8) | Parity |

---

## 13. Recommended improvements for sugar-crush

The order is P0, then P1, then P2. Each item follows the project rule "wire dormant code before writing new code".

### P0-1: Interactive permission asks on the engine path, then retire the `bypass-permissions` default

**Why.** Today every `Ask` in the TUI becomes a deny (`Runtime::settleAsk()`, `src/Runtime.php:2629-2669`), so the only safe-feeling setting is to bypass everything (`Bootstrap.php:166`). opencode's whole safety model rests on asks that can be answered.

**How opencode does it.** `Permission.ask` publishes an event and awaits a `Deferred`. The client replies once, always or reject+message. "Always" stores semantic patterns, and a reject with a message becomes model-visible feedback (`OC/permission/index.ts:66-170`, `CORE/v1/permission.ts:13-19`).

**How in sugar-crush.**
1. The UNIX socketpair in `EngineBackend::completeAsync()` (`src/Backend/EngineBackend.php:1343-1370`) is full duplex, but only child→parent frames are used today.
2. Add a `permission_ask` frame written by the child from a new `PermissionApprover` implementation that blocks reading the child socket for a matching `permission_reply` frame.
3. Bind that approver in `runCompleteInChild()` (`:1629`).
4. In the parent's read-stream handler (`:1467`), turn `permission_ask` into the **existing** Veil y/n/a modal (`Chat::requestPermission`, `src/Chat.php:2666`), which today serves only Command backends. Write the reply frame back.
5. Pause the 120 s no-frame watchdog while a modal is open.
6. Add reject-with-message: the deny reason text the model sees becomes `The user rejected … with the following feedback: …`.
7. Then change the default mode to `default` or `accept-edits`.

**Effort.** M (frame plumbing plus the modal already exist).

### P0-2: Structured cross-turn tool history

**Why.**
- Earlier tool results are replayed as plain assistant text with no call or arguments (baseline §0.3).
- Models trained on tool_use/tool_result pairing degrade under that format, and may start emitting fake "tool output" text.
- It also makes *structured* pruning (P0-4) impossible.

**How opencode does it.** Tool parts are stored with `callID`, input, output and status, and replayed as `tool-<name>` parts with `toolCallId`. Interrupted ones still get a synthetic result (`OC/session/message-v2.ts:292-363`).

**How in sugar-crush.**
- Persist `toolCallId`, name and arguments on the engine tool rows: `Chat::toolResultMessage()` (`src/Chat.php:4017-4023`), where the data comes from the `finished` frame.
- Make `EngineBackend::toTypedMessages()` (`src/Backend/EngineBackend.php:2071-2083`) regroup consecutive tool rows into `AssistantMessage(toolCalls)` plus `ToolResultMessage`s.
- `HistorySanitizer` already synthesises results for orphans (`Messages/HistorySanitizer.php:80-140`), so pairing safety is in place.

**Effort.** M.

### P0-3: Make the prompt prefix cache-stable, and wire the dormant affinity and cache code

**Why.** On the primary target (SGLang radix cache, contexts up to about 1M tokens) every prefix miss means re-prefilling the whole conversation. Two verified issues:
- `SglangProvider::formatMessages()` **collects every in-history `SystemMessage`** (launch notices, compaction notices, context reminders, `_Request cancelled._`, running placeholders) **and merges them into the single leading system message** (`src/Providers/SglangProvider.php:1573-1610`). Any new system row rewrites the head of the prompt, so the cache misses for the entire history. It also moves notices out of their chronological position.
- The `<env>` block, with `git status --porcelain`, the log and, after a write, diffs, is re-rendered **every step** at the end of the system prompt (baseline §4 slot 11; `EnvironmentBlock.php:993-994`). Every message after it therefore misses the cache whenever the status changes, which is after every write step.

**How opencode does it.**
- The env is static apart from the date at day granularity (`OC/session/system.ts:74-85`).
- Reminders ride on the latest *user* message as synthetic parts (`OC/session/reminders.ts`).
- v2 freezes a per-session baseline and emits only deltas as `[System update]` messages (`CORE/session/context-epoch.ts`).
- It sends `x-session-affinity: <sessionID>` (`OC/session/llm/request.ts:198-201`).
- It marks the first 2 system and last 2 messages for Anthropic (`OC/provider/transform.ts:358-405`).

**How in sugar-crush.**
1. In `SglangProvider::formatMessages()` and the `CustomProvider` equivalent, render in-history system rows **in place**, as `user`-role `<system-reminder>` content, instead of hoisting them.
2. Move the volatile git part of `EnvironmentBlock` (status, log, diffs) out of `systemPromptSections()` into a per-step reminder appended to the newest user or tool-result message. Keep cwd, OS and date in the static block.
3. Pass `sessionAffinityId` (the `SessionAffinity` trait, `Providers/Concerns/SessionAffinity.php:65`) from `Bootstrap::backendFor()` using the session id. It is DORMANT today.
4. Wire `Providers/CacheBreakpoints.php` (DORMANT, 690 lines) into Bedrock, Vertex-Anthropic and claude-style providers.

**Effort.** M (1 and 3 are S).

### P0-4: Compact and prune between steps, not only at submit

**Why.**
- A single long turn grows without bound until the provider rejects it (baseline §3.3).
- `CustomProvider` has a fixed 128k window, so a tool-heavy turn overflows easily.

**How opencode does it.**
- After every step it checks reported usage against `usable` (`overflow.ts:10-34`; `processor.ts:491-496`), stops the stream, summarises with the anchored template, keeps a token-budgeted tail, and auto-continues ("Continue if you have next steps…", `compaction.ts:527-531`).
- A provider overflow error triggers the same path with the user message replayed.
- The pruner protects the last 2 turns plus 40k tokens of tool output and clears the rest when the total is over 20k (`compaction.ts:271-317`).

**How in sugar-crush.**
1. In `EngineBackend::runTurn()` (`:776-1024`), after each `Runtime::run()` step, compute usage against `ContextWindow::ofBackend()` minus `min(20k, maxOutputTokens)`.
2. On overflow, first **prune**: replace the content of older `ToolResultMessage`s with `[Old tool result content cleared]`, keeping the call. This needs P0-2 for cross-turn rows, but works inside a turn today.
3. If still over, summarise in the child with the existing `COMPACT_SUMMARY_PROMPT` (`Chat.php:10569`) through a tool-less engine, then continue the loop. Emit a `compaction` frame so `Chat` can splice `HistoryCompactedMsg`, which is LIVE.
4. Fix `ContextCompactor::removeToolResults()` so it matches the real wire shape (baseline §3.3 notes it is a no-op), and switch tail preservation from "10 pairs" to a token budget (`CompactorConfig.php:71`).
5. Consider adopting opencode's 5-section anchored template with prior-summary merge rules (`CORE/session/compaction.ts:16-55`). It is state-oriented (Active/Blocked/Next Move), which is what a continuing agent needs.
6. Dispatch the DORMANT `PreCompact` hook event (`src/Hooks/HookEvent.php:45`) at this point.

**Effort.** M–L.

### P0-5: Bash timeout and the silent-command watchdog

**Why.** Bash has no per-command timeout, and a sequential tool that stays silent for 120 s kills the **whole turn** through the no-frame watchdog (baseline §6.4, §11.1.7). A `composer install` or a test suite can trip this.

**How opencode does it.** A `timeout` parameter (default 2 min, configurable) kills the process, and the model sees a `<shell_metadata>` note telling it to retry with a larger timeout. Output streams live (`OC/tool/shell.ts:540-565`, `:347`). There is also a `workdir` parameter.

**How in sugar-crush.**
- Add `timeout` (ms, default 120 000, max e.g. 600 000) and `workdir` to `src/Tools/BuiltIn/Bash.php`, enforced in `Tools/Concerns/CapturesProcessOutput.php` (setsid group kill already exists in `Support/ProcessContainment.php`).
- Have sequential tool execution (`Runtime::executeSequentially`, `:1748`) emit heartbeat frames, or exempt a running Bash from the 120 s idle timer, as `HttpClientDefaults::heartbeatOptions` already does for HTTP.

**Effort.** S.

### P1-6: Truncate-to-file with a navigation hint; cap MCP output

**Why.** The 64 KiB head+tail and the 1 MiB Read flood the context. MCP results are **uncapped** (`McpToolBridge.php:587-622`).

**How opencode does it.** Outputs over 2000 lines or 50 KB are saved to a temp file with 7-day retention, and the preview carries the path plus "use Grep/Read offset" or "delegate to the explore agent" (`OC/tool/truncate.ts:85-141`). The same wrapper applies to every tool, MCP included.

**How in sugar-crush.**
- Extend `Tools/Concerns/TruncatesOutput.php`. Write the full text to `sys_get_temp_dir()/sugarcrush-tool-output/` (0600), return a preview plus the hint, and apply it in `McpToolBridge`.
- Allow-list that directory in `PathJail` for Read and Grep.

**Effort.** S.

### P1-7: Read with offset/limit and line numbers

**How opencode does it.** Default 2000 lines, `N: ` prefixes, 2,000-char line cap, 50 KB cap, a continuation footer with the next offset, binary refusal, and images or PDFs as attachments (`OC/tool/read.ts:13-16`, `:331-347`).

**How in sugar-crush.** Add `offset`/`limit` to `src/Tools/BuiltIn/Read.php`, which today reads up to 1 MiB with no paging. Update the Edit description so `old_string` excludes the prefix (opencode's `edit.txt` wording).

**Effort.** S.

### P1-8: A fuzzy edit replacer chain

**Why.** Exact `substr_count` matching (`Edit.php:178`) fails on whitespace, indentation or escaping drift. Each failure costs a re-read plus a retry step, and the step budget is only 8.

**How opencode does it.** Nine replacers with uniqueness checks, a Levenshtein block anchor (threshold 0.65), and a disproportionate-match refusal (`OC/tool/edit.ts:217-737`).

**How in sugar-crush.** Port `LineTrimmed`, `BlockAnchor`, `WhitespaceNormalized`, `IndentationFlexible` and `EscapeNormalized`, plus `isDisproportionateMatch`, into a `Tools/Concerns/FuzzyReplace` trait used by `Edit.php`. Keep the exact-match path first. Also add a per-file `flock` around read-modify-write.

**Effort.** S–M.

### P1-9: Wire the dormant LSP client into a post-edit diagnostics loop

**Why.** This is the single biggest code-quality feedback loop opencode has. sugar-crush already has a full stdio JSON-RPC client (`src/LSP/LspClient.php`, `LspConnection.php`) that nothing constructs (baseline §6.6).

**How opencode does it.**
- After edit/write/patch: format the file, `touchFile`, wait up to 5 s for push or pull diagnostics (150 ms debounce), then append `LSP errors detected in this file, please fix:` with ERROR lines, at most 20 per file.
- `write` also reports 5 other files.
- Intelephense is detected via `composer.json` (`OC/tool/edit.ts:197-201`, `OC/lsp/client.ts:13-16`, `OC/lsp/server.ts:1515-1544`).

**How in sugar-crush.**
- Add a user-tier `lsp` settings key (`Config/LayeredSettings.php`), with phpactor or intelephense as the default for `.php`.
- Construct `LspClient` in `Bootstrap::tools()` and pass it as `lsp:` to both `LspTool` (`Bootstrap.php:6624-6626`) and `Edit`/`Write`.
- Subscribe to `textDocument/publishDiagnostics` in `LspConnection` (today diagnostics are pull-only).
- Append the diagnostics block to the Edit and Write results.

**Effort.** M.

### P1-10: Shadow-git snapshots and file-level undo in `/rewind`

**Why.** `/rewind` restores only the transcript (baseline §7, §8). Undoing an agent's edits is the most-requested safety net.

**How opencode does it.**
- A separate git dir with alternates and a seeded index; `write-tree` at every step start and finish; per-step `patch` lists of changed files.
- Revert is per-file checkout from the step's tree hash, or delete if the file is new.
- Revert is soft (unrevert keeps the pre-revert tree), and the reverted prompt text goes back into the input (`OC/snapshot/index.ts`, `OC/session/revert.ts`, `TUI/routes/session/index.tsx:614-660`).

**How in sugar-crush.**
- New `src/Snapshot/ShadowGit.php`, using `~/.sugar-crush/snapshot/<sha1(root)>` as the git dir.
- Call `track()` in `EngineBackend::runTurn()` before and after each step (the child has the root). Ship `{fromTree, toTree, files}` in the `result` frame.
- Store it in the per-turn checkpoint (`EnhancedSessionStore::saveCheckpoint`, `Chat::dispatchTurn` `:7964-7993`).
- Extend `/rewind` (`Chat.php:12317-12424`) to revert files, and add `/undo` and `/redo`.
- Skip untracked files over 2 MB, and run `gc --prune=7.days`.

**Effort.** M–L.

### P1-11: Raise the step cap and finish gracefully

**Why.** `maxToolSteps` defaults to **8** (`EngineBackend.php:262`). A real edit → test → fix cycle needs more steps than that, so turns end truncated with a notice. opencode defaults to unlimited (`agent.steps ?? Infinity`, `prompt.ts:1178`).

**How in sugar-crush.**
- Default to something like 50 (matching `TaskTool`'s 50).
- On the final step, add `MAX_STEPS_PROMPT` (§2.1) as a trailing message, with tools disabled, so the model writes a "done so far / remaining / next" summary instead of the turn ending mid-tool.
- Pair this with P1-12.

**Effort.** S.

### P1-12: Doom-loop guard

**How opencode does it.** If the last 3 tool calls are the same tool with identical JSON input, it asks the user (`processor.ts:353-380`).

**How in sugar-crush.** In `Runtime::executeToolCalls()` (`:1650`), keep a per-turn ring of `(name, json_encode(args))`. On the third repeat, return an error result telling the model it is looping. Once P0-1 exists, raise a permission ask instead.

**Effort.** S.

### P1-13: A TodoWrite tool and a todo pane

**Why.** Every opencode base prompt leans hard on TodoWrite for visible progress and to keep long work on track. sugar-crush has none (baseline §2.3).

**How in sugar-crush.**
- `src/Tools/BuiltIn/TodoWrite.php`: whole-list replace, at most one `in_progress`. Store it per session in `EnhancedSessionStore`; the DORMANT `SessionMeta` already has a `tasks` slot.
- Ship it to the parent in a frame, and render it in a new dock pane, or reuse `AgentsPane` styling.
- Deny it to sub-agents by default, as opencode does.
- The DORMANT SQLite `TaskList` (`src/Agents/TaskList.php`) is an option for a shared, team-visible version later.

**Effort.** S–M.

### P1-14: Mid-turn steering

**How opencode does it.** Prompts typed mid-turn are persisted immediately and the next step sees them, because the loop reloads history each step (`prompt.ts:1092`). They are shown as `QUEUED`.

**How in sugar-crush.**
- When `Chat::enqueuePrompt()` (`:7533`) runs during an engine turn, also write a `user_message` frame down the (full-duplex) socket.
- The child's `runTurn()` loop drains pending frames between steps and appends a `UserMessage` before the next `Runtime::run()`.
- Keep the queue for prompts that arrive after the final step.

**Effort.** M (it shares the parent→child frame plumbing with P0-1).

### P2-15: Sub-agents as stored, navigable, resumable child sessions

**How opencode does it.** A child session with `parentID`; `task_id` resumes any finished task; Ctrl+B promotes to background; the result is injected into the parent; re-calling a running task extends it; arrow keys navigate between sessions (`OC/tool/task.ts`).

**How in sugar-crush.**
- Store each sub-agent transcript as an `EnhancedSessionStore` session with a parent id. `fork()` exists, and today's `SuspendedDelegations` temp files cover only failures.
- Return `task_id` on success too.
- Show children in the session tab strip (`Renderer::renderSessionTabStrip`).
- Wire `TaskTool::runOnEngine` to honour the preset `model` (`TaskTool.php:557-561`, DORMANT).
- For background, wire the DORMANT `Mailbox` (`src/Agents/Mailbox.php`) as the parent↔child channel and `BackgroundSupervisor::reconnect()`. Inject the final answer into the chat, which baseline §2.4 says never happens today.

**Effort.** L.

### P2-16: A plan agent with a plan file and an exit tool

**How opencode does it.** `plan` agent permissions deny edits except `.opencode/plans/*.md`. A reminder lays out the phased workflow. `plan_exit` asks "switch to build?" and injects "The plan at X has been approved, you can now edit files. Execute the plan" (`OC/agent/agent.ts:146-171`, `OC/session/prompt/plan-mode.txt`, `OC/tool/plan.ts`).

**How in sugar-crush.** Depends on P0-1. Add `PermissionMode::Plan` path rules: allow Write/Edit to `.sugar-crush/plans/*.md`. Add a `PlanExit` tool and a `question` tool, both using the P0-1 modal plumbing.

**Effort.** M.

### P2-17: Per-model-family base prompts

**Why.** DeepSeek-V4, Qwen and MiniMax would benefit from tuned instructions. opencode routes by `model.api.id` (`OC/session/system.ts:28-51`).

**How in sugar-crush.** Make `Runtime::basePrompt()` (`:3433`) select a heredoc by model family, using the family detection that `ProviderFactory` already does for parser selection (`:951-957`).

**Effort.** S.

### P2-18: `/init` to generate AGENTS.md

**How in sugar-crush.** Port `OC/command/template/initialize.txt` as a built-in custom command template in `src/Commands/`.

**Effort.** S.

### P2-19: Honour command `subtask` and `model` (DORMANT) and `@agent` mentions

**How opencode does it.** Subtask commands run through the task tool, then "Summarize the task tool output above and continue with your task." (`prompt.ts:446`, `:1439`).

**How in sugar-crush.** In `Chat::expandCustomCommand()` (`:8031`), route `subtask: true` through `TaskTool`.

**Effort.** S–M.

### P2-20: Message and system transform hook events

**Why.** opencode's `experimental.chat.messages.transform` is the seam that lets context-pruning strategies exist outside core.

**How in sugar-crush.** Add `PreRequest` (messages + system) and wire the DORMANT `PreCompact` in `HookManager`. Call them from `Runtime::run()` before `CompleteRequest`. Script hooks receive the JSON message list and return a replacement (exit 4 = modify already exists).

**Effort.** M.

### P2-21: Smaller UX items

| Item | Detail | Effort |
|---|---|---|
| Notifications | Desktop or bell notification on turn done or permission needed (opencode `TUI/feature-plugins/system/notifications.ts`) | S |
| Share fallback | Local markdown/JSON export fallback for `/share` (`ShareUploader` always throws) | S |
| Tool-call repair | Route unparsable calls to an `invalid` tool result instead of dropping them | S |
| Retry headers | Honour `retry-after` in `TransientFailure` | S |
| Global instructions | Load the global `~/.claude/CLAUDE.md` / `~/.sugar-crush/AGENTS.md` | S |

---

## 14. Problems in sugar-crush exposed by this comparison

1. **Prompt-cache destruction on the primary provider.**
   - `SglangProvider::formatMessages()` hoists every in-history `SystemMessage` into the leading system message (`src/Providers/SglangProvider.php:1600-1610`, read in this pass).
   - Launch notices (up to 24), the 70% reminder (stripped and re-added each turn), cancellations, compaction notices and running placeholders all mutate the **head** of the prompt. The whole history then misses SGLang's radix cache.
   - Together with the per-step git-status `<env>` re-render, long sessions on a 1M-token DeepSeek-V4 context may re-prefill everything on many steps.
   - It also removes these notices from their chronological position, so "Request cancelled" appears as a standing system instruction.
2. **The default step cap of 8** (`EngineBackend.php:262`) is very low next to opencode's unlimited default. Normal multi-file work hits it, and the turn ends with a truncation notice instead of a model-written summary.
3. **No doom-loop guard.** This is harmless at 8 steps, but becomes a cost and runaway risk once the cap is raised. The spend cap is the only backstop, and it needs prices that Sglang/Custom report as $0 (baseline §1.3). In practice there is **no** backstop on the primary provider.
4. **Silent Bash kills the turn.** There is no per-command timeout, and 120 s of silence kills the whole forked turn (baseline §6.4). opencode's explicit `timeout` with a model-visible retry hint is the safer design.
5. **The default permission mode is bypass, because asks cannot be answered.** Out of the box, the model can run any Bash command anywhere; Bash is not path-jailed. Nothing like opencode's `external_directory` ask exists. Only `rm -rf /` and the protect and confirm-remove hooks guard against it.
6. **Lossy cross-turn history** (tool output as assistant text). Beyond the quality cost, it invites the model to *imitate* tool-output text in its answers, and makes structured pruning impossible.
7. **Compaction's "keep 10 pairs" is not token-aware.** One 60 KiB Read inside the preserved window survives every compaction. opencode budgets the tail in tokens (2k–15k) and can split a turn.
8. **`removeToolResults()` is effectively a no-op** (baseline §3.3). The one place meant to drop old tool output never fires, and nothing replaces it.
9. **MCP results are uncapped** (`McpToolBridge.php:587-622`). One large MCP response can blow the window, and there is no mid-turn compaction to recover.
10. **Read has no paging.** Files over 1 MiB cannot be read past the cut, and every read of a mid-size file pays for the whole file. opencode caps reads at 50 KB / 2000 lines with an offset to continue.
11. **The Bash prompt guidance hard-codes the SugarCraft PR cadence** (`Bash.php:124-163`, already in baseline §11.1.5). opencode's equivalent is project-neutral ("Only commit… when explicitly requested").
12. **Sub-agents cannot be resumed after success, and the preset `model` is ignored.** opencode's `task_id` works for any task and its per-agent model is honoured, which makes cheap explore sub-agents on a small model possible. sugar-crush always runs sub-agents on the parent's (largest) model.
13. **The session-affinity header is dormant** although the trait exists. With an SGLang router in front of several workers, sugar-crush requests are not pinned. opencode sends `x-session-affinity` on every request by default.
14. **Read-before-edit is unenforced in both projects.** opencode's edit description falsely claims enforcement; sugar-crush's base prompt only advises it. This is not a gap versus opencode, but neither checks staleness. A cheap mtime check would let sugar-crush do better than both.
