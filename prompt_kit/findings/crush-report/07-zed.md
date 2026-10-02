# 07 — Zed's AI agent panel vs sugar-crush

**Competitor:** Zed (zed-industries/zed), the agent panel and its native agent. Rust/GPUI.
**Clone:** `/home/sites/crush-research-repos/zed` @ `20d29fc6b` (2026-10-01). Paths below are relative to that root.
**Baseline:** `prompt_kit/findings/crush-report/00-sugar-crush-baseline.md` ("baseline §N"). Every sugar-crush claim a recommendation depends on was re-checked in `sugar-crush/src/`.

> **The repo has moved on from the crate names in the brief.** There is no `agent2`, `assistant_tool(s)`, `assistant_context` or `language_models` "assistant" crates any more. The native agent lives in `crates/agent` (it used to be `agent2`). The UI lives in `crates/agent_ui`, and the ACP host side lives in `crates/acp_thread` + `crates/agent_servers`.
>
> The system prompt is **not** in `assets/prompts/*.hbs` (those are the inline-assistant and terminal-assistant prompts). It is `crates/agent/src/templates/system_prompt.hbs`.
>
> The **Rules Library is gone** — migrated one-way into skills and a global `AGENTS.md` (`crates/prompt_store/src/rules_to_skills_migration.rs`).
>
> So "@rules" is now a deprecated mention kind, and "default rules" are now the user's personal `AGENTS.md`.

---

## 1. Overview

### What it is

Zed's agent panel is a chat/agent surface inside a GPU-rendered code editor. It hosts two kinds of agents through one abstraction:

1. **The native "Zed Agent"** (`crates/agent`). An in-process Rust agent loop with about 30 built-in tools, MCP ("context servers"), skills, sub-agents and an OS sandbox.
2. **External agents over the Agent Client Protocol (ACP)** (`crates/agent_servers`). Claude Code (`claude-acp`), Gemini CLI, Codex (`codex-acp`), Cursor and any custom command speak JSON-RPC over stdio. Zed is the *client*: it renders their output, serves file reads/writes and terminals, and answers their permission requests.

The key architectural fact is that **the native agent is also driven through the ACP-shaped interface**:
- `NativeAgentConnection` implements the same `acp_thread::AgentConnection` trait as the external `AcpConnection` (`crates/agent/src/agent.rs:2774`, `crates/agent_servers/src/acp.rs:1579`).
- `NativeAgentServer::connect` returns it (`crates/agent/src/native_agent_server.rs`).
- So the UI, checkpoints, review and permission prompts are written once, against `AcpThread`, and work for every agent.

### Size (Rust, including tests)

| Crate | Lines | What it holds |
|---|---|---|
| `agent` | ~89k | Loop, tools, permissions, sandbox glue, ~9.5k-line test module |
| `agent_ui` | ~91k | Panel, thread view, diff review, mention set, message editor |
| `acp_thread` | ~26k | The host-side thread model |
| `action_log` | 3.5k | |
| `agent_servers` | 8.3k | |
| `context_server` | 6.3k | MCP client |
| `sandbox` | 9.4k | Seatbelt, bubblewrap, WSL |
| `agent_skills` | 2.2k | |
| `agent_settings` | 2.8k | |

### What it is best at

1. **Streaming edits that apply to the buffer while the model is still typing.**
   - `edit_file` takes `edits:[{old_text,new_text}]`.
   - A streaming partial-JSON parser feeds `old_text` into a *streaming fuzzy line matcher* (Levenshtein ≥ 0.8 per line).
   - It then feeds `new_text` through a *streaming char diff* plus re-indenter straight into the open buffer (`crates/agent/src/tools/edit_session*.rs`).
2. **An action log that separates agent edits from user edits.** This drives per-hunk **Keep/Reject** in a review multibuffer, undo-last-reject, auto-keep on `git commit`, and staleness detection (`crates/action_log/src/action_log.rs`).
3. **Shadow-git checkpoints before every user message**, with one-click "Restore Checkpoint". It works for *every* agent, including Claude Code over ACP, because it happens host-side (`crates/acp_thread/src/acp_thread.rs:5724`, `crates/git/src/repository.rs:3063`).
4. **Auto-compaction before every model request**, including between tool rounds in a single turn.
   - It is driven by provider-reported usage, at 90% of input capacity.
   - It writes a *handoff* summary and keeps the last ~80 KB of the user's own messages verbatim (`crates/agent/src/thread.rs:2803`, `:4493`, `:4959`).
5. **A layered, pattern-aware permission system.**
   - Hard-coded `rm -rf` breakers, then regex `always_deny` > `always_confirm` > `always_allow`, then per-tool and global defaults.
   - Shell commands are parsed into sub-commands, so `ls && rm -rf x` cannot ride an `^ls` allow rule.
   - The permission prompt offers "Always for `cargo test` commands", which is persisted to settings (`crates/agent/src/tool_permissions.rs`, `crates/agent/src/pattern_extraction.rs`).
6. **An OS sandbox for the terminal tool** (bubblewrap, Seatbelt, WSL+bwrap).
   - Read-only root, writable worktrees, protected `.git`, no network by default.
   - Per-command escalation (`allow_hosts`, `fs_write_paths`, `unsandboxed`) that the user approves once, per thread, or always.
   - The system prompt describes the sandbox to the model exactly (`crates/sandbox`, `system_prompt.hbs:156-212`).
7. **ACP: one protocol for every agent.** Zed gets external agents' tool calls, diffs, plans, terminals, permission prompts, modes and config options in a uniform UI.
8. **Typed context mentions** (`@file/@dir/@symbol/@selection/@thread/@fetch/@diagnostics/@diff/@skill/@merge-conflict`). They are packaged into a `<context>` block with a "don't re-read" promise (`crates/agent/src/thread.rs:329-588`).
9. **Tool profiles (Write / Ask / Minimal, plus custom).** They gate the built-in and per-MCP-server tool sets per thread, can pin a model, and are automatically downgraded to Minimal in untrusted ("restricted") workspaces (`assets/settings/default.json:1284-1343`, `thread.rs:2281`).
10. **Mid-turn steering.** A queued message flagged "Steer" ends the running turn at the next tool-result boundary instead of waiting for the end (`thread.rs:3140-3146`, `thread_view.rs:2522-2537`).

---

## 2. Agent loop

### How a turn runs

The loop is `Thread::run_turn` → `run_turn_internal` (`crates/agent/src/thread.rs:2729-3155`). It is one async task per turn, cancelled through a `watch` channel. Each iteration:

1. **Compaction check**, `perform_compaction_if_needed` (`:2804-2876`, §4). This runs **before every model request, including tool-result rounds**.
2. **Re-read the model and refresh the tools** (`:2878-2893`). The comment says: *"Re-read the model and refresh tools on each iteration so that mid-turn changes (e.g. the user switches model, toggles tools, or changes profile) take effect between tool-call rounds."*
3. **`provider.stream_completion`**, then a `select!` race between three things (`:2920-2970`):
   - the next stream event;
   - **tool results that finish while the model is still streaming**;
   - cancellation.
   Events are batched (`now_or_never`) into one entity update.
4. **Tool execution starts as each tool call completes in the stream**, not after the message ends (`handle_tool_use_event`, `:3548-3657`).
   - Every call is pushed into a `FuturesUnordered`, so **all tool calls run concurrently**. There is no read-only versus write split; ordering safety comes from the single-threaded foreground executor and buffer anchors.
   - Tools that `supports_input_streaming()` (`edit_file`, `write_file`) are started on the *first partial* input. They receive later partials through a `ToolInputSender` channel.
   - An unknown tool returns `"No tool named {} exists"` as an error result.
   - Unparseable JSON becomes `"Error parsing input JSON: …"` fed to the tool as `ToolInput::invalid_json` (`:3766-3834`).
   - Either way the model sees an error result instead of the turn crashing.
5. **Drop the stream before awaiting tools** (`:3019-3024`). The comment: *"Drop the stream to release the rate limit permit before tool execution… Without this, the permit would be held during potentially long-running tool execution, which could cause deadlocks when tools spawn subagents that need their own permits."*
6. **End-of-turn decision** (`:3094-3153`):
   - `end_turn = tool_results.is_empty()` — no tools means a normal answer.
   - An error means retry (below).
   - If `end_turn_at_next_boundary` is set, a *steering message* is queued: the turn ends after this tool round.
   - Otherwise it loops with `intent = ToolResults`.
7. **There is no step cap** in the native loop. The only stops are cancellation, an error, a refusal or `MaxTokens`. The ACP `StopReason::MaxTurnRequests` exists in the protocol and is surfaced for sub-agents (`agent.rs:3604`), but the native loop never produces it.

### Parallel tools and a failing streamed edit

`early_tool_results` are collected while streaming. If a tool errors *while its own input is still streaming* (for example the `old_text` of a streamed edit failed to match), the loop breaks the stream early. Otherwise parallel tools keep going (`:2926-2946`).

### Retries (`handle_completion_error` `:3372`, `retry_strategy_for` `:4601`)

- `MAX_RETRY_ATTEMPTS = 4` and `BASE_RETRY_DELAY = 5s` (`:170-171`), with jitter (`crate::jitter_retry_delay`).

| Error class | Retry strategy |
|---|---|
| Provider rejection that is transient | Honours `retry_after` (`FixedDelay`), else exponential backoff |
| Provider rejection that is permanent (content policy, auth) | Never retried |
| HTTP send / read / deserialise errors | 3 attempts × 5 s |
| `StreamEndedUnexpectedly` / serialise errors | 1 attempt |
| `Other` (mid-stream mapping) | 2 attempts |
| `NoApiKey`, `ModelUnavailable`, `DataRetentionConsentRequired` | Never retried |

- **A partial response survives a retry.** `flush_pending_message` keeps the partial assistant text. If the last agent message had no tool results, a `Message::Resume` is pushed (`:3128-3136`). It renders as a user message, **"Continue where you left off"** (`:254-260`). So a dropped stream *continues* rather than restarting, and the user never loses streamed text.
- **Prompt too large.** `ProviderErrorCategory::PromptTooLarge` marks the usage indicator "Exceeded" by synthesising usage ≥ the context size (`mark_token_limit_exceeded`, `:2406`).
- **Refusal fallback.** On `StopReason::Refusal`, if the model declares `refusal_fallback_model_id()`, the turn switches to that model and continues. It emits a retry banner ("Safety filter triggered") (`:3027-3080`). Without a fallback, the user message is truncated away (`:2770`).

### Cancellation and interrupts

- `Thread::cancel` (`:2323`) cancels **all running sub-agents first**, then the turn task, then flushes the partial message.
- Any tool call without a result gets `"Tool canceled by user"` (`flush_pending_message`, `:4075-4109`). The history therefore always stays a valid tool_use/tool_result pairing.
- **If the user sends a follow-up while a permission prompt is open**, the pending call is denied with `"Permission denied: user sent a follow-up message instead of approving the tool call."` (`:73-74`).

### Mid-turn steering

Queued messages normally wait for the turn to finish. Each queue entry has a **Steer** toggle (`agent_ui/src/conversation_view/message_queue.rs:15,73-80`). If the *front* entry wants to steer, `set_end_turn_at_next_boundary(true)` is pushed into the native thread (`thread_view.rs:2528-2537`). The loop then exits right after the current tool results are recorded (`thread.rs:3140-3146`), and the queued message is sent. The model sees its tool results plus the new instruction — a clean, protocol-valid interruption point.

### Doom-loop detection

None found. There is no repeated-call detector and no step cap; the user's Stop button is the safety valve.

---

## 3. Agents and sub-agents

### Agent "modes"

There is no plan/build/architect split in the native agent. **Profiles** play that role (§9):
- **Write** — all tools plus all MCP servers.
- **Ask** — read-only tools plus `spawn_agent`, `fetch`, `search_web`, `diagnostics`; MCP tools off by default.
- **Minimal** — no tools.
- Custom profiles are allowed.

The profile is per thread and is inherited by sub-agents. `set_profile` propagates to *running* sub-agents (`thread.rs:2300-2321`). ACP agents bring their own modes (`session/set_mode`, `CurrentModeUpdate`) and config options (`session/set_config_option`). Zed renders those in a mode selector.

### `spawn_agent` (`crates/agent/src/tools/spawn_agent_tool.rs`)

**Input:** `label` (UI text), `message`, optional `session_id` (follow up an existing sub-agent), optional `model` (an exact id from `list_agents_and_models`).

**The tool description is a mini orchestration guide** (`:15-45`). Verbatim highlights:
- *"An agent does not see your conversation history. Include all relevant context…"*
- *"Do not use this tool for tasks you could accomplish directly with one or two tool calls."*
- *"For code-edit subtasks, decompose work so each delegated task has a disjoint write set."*
- *"When sending a follow-up using an existing agent session_id, the agent already has the context from the previous turn. Send only a short, direct message."*
- *"A resumed session keeps its existing model, so `model` cannot be combined with `session_id`."*

**How the child is built** (`Thread::new_subagent`, `thread.rs:1342-1377`):
- It is a **full `Thread`** sharing the parent's project, `ProjectContext` (so the same system prompt), MCP registry and templates.
- It inherits speed, thinking, effort, summarisation model and profile.
- Its model comes from `subagent_model`, else the parent's.
- Its `ActionLog` is **linked to the parent's** (`ActionLog::new(...).with_linked_action_log(parent_action_log)`). So a sub-agent's edits appear in the parent's review and Keep/Reject bar while the sub-agent keeps its own diff.
- **Depth is fixed**: `MAX_SUBAGENT_DEPTH = 1` (`thread.rs:77`). `spawn_agent` is added only when `depth() < MAX_SUBAGENT_DEPTH` (`:2235-2237`).

**Execution** (`NativeSubagentHandle::send`, `agent.rs:3550-3650`):
- It sends `message` into the child's `AcpThread` and awaits the end of the turn. Several `spawn_agent` calls in one assistant message run **in parallel** (all tools run concurrently), with no cap.
- **Context-limit guard.** It subscribes to the child's `TokenUsageUpdated`. If auto-compaction is *off* for the child and its ratio crosses the warning band, it cancels the child and returns: *"The agent is nearing the end of its context window and has been stopped. You can prompt the thread again to have the agent wrap up or hand off its work."*
- **Result:** only the child's last agent message text, as `{"session_id":…, "output":…}` (`spawn_agent_tool.rs:107-130`).
- **On error, partial output is salvaged:** *"Partial subagent output (last 3 messages, up to 4096 characters each)"* (`subagent_partial_output_from_messages`, `thread.rs:4654-4696`; `agent.rs:3636-3644`).
- **Resume:** the parent can call `spawn_agent` again with `session_id` for a short follow-up, *whether or not the first run succeeded*. Persistent sub-agent sessions make this a conversation, not a one-shot.

**Parent ↔ child communication while running:**
- The parent's model cannot message a running child; `spawn_agent` blocks.
- The **user** can see every sub-agent live: a sub-agent card with an expandable transcript (`thread_view.rs:10917-11311`) and a "subagents awaiting permission" banner (`:3754`). Sub-agent permission prompts bubble up into the parent's panel.
- Cancelling the parent cancels all children.
- No mailbox or shared todo list.

### `create_thread` and `list_agents_and_models` (`create_thread_tool.rs`, `list_agents_and_models_tool.rs`)

- `create_thread` makes a **sibling** thread in the sidebar (any agent: native, Claude Code, Gemini…). It runs independently, so *"you will NOT receive its output"*.
- It is gated: *"Only use this after the user explicitly asks for or approves another thread."*
- `use_new_worktree: true` creates linked **git worktrees** in a new workspace tab (detached HEAD, optional `base_ref` / `worktree_name`). That gives isolated parallel experiments.
- Threads with worktrees can be archived, and the worktree's git state is persisted (`agent_ui/src/thread_worktree_archive.rs`).

### Background agents and workflows

- Sibling threads in the sidebar are the background-agent story.
- `max_idle_retained_threads: 5` keeps idle sessions loaded (`default.json:1170`).
- `prevent_idle_sleep: true` keeps the machine awake while threads run.
- No workflow DSL.

---

## 4. Context handling and compaction

### Token counting

- **No local estimator.** Zed uses provider-reported usage per request.
- `accumulate_token_usage` takes the **max** of each streamed usage field within a request (providers send cumulative updates) and adds the delta to `cumulative_token_usage` (`thread.rs:2354-2386`).
- Usage is stored per user message (`request_token_usage[user_msg_id]`), so `tokens_before_message` works for the UI.
- "Context fill" = `input + cache_creation + cache_read + output` of the latest request (`total_input_tokens`, `:4698`; used at `:4520`).
- **Capacity** = `min(max_input_tokens, max_total_tokens − max_output_tokens)` (`compaction_input_capacity`, `:4706-4714`).

### When compaction runs

`compaction_message_target_ix` (`thread.rs:4493-4543`) decides. Compaction is skipped when:
- auto-compaction is disabled; or
- the model's capacity is under `MIN_COMPACTION_CONTEXT_WINDOW = 80_000` (`:124`). Small models get a UI warning instead.

The threshold is configurable (`default.json:1269-1281`, parser at `agent_settings.rs:181-206`):

```json
"auto_compact": { "enabled": true, "threshold": "90%" }
```

It accepts three forms:
- `"92.5%"` — a percentage;
- `100000` — compact after that many tokens are used;
- `-20000` — compact when fewer than that many tokens remain.

The check is **at the top of every loop iteration** (`run_turn_internal`, `:2804`), so a long tool-calling turn compacts *between steps*. A compaction that already happened after the last usage report is not repeated (`:4515-4519`).

### How compaction works (`build_compaction_request`, `:4561-4584`; `stream_compaction`, `:3218-3342`)

- The model is `LanguageModelRegistry::compaction_model()`, else the thread model.
- The request is the normal system prompt **rendered with zero tools**, so the template's "no tools" branch applies. It is followed by history up to the insertion point and then the compaction prompt as a user message.
- The summary **streams into the UI** (`CompactionSummaryChunk`, `ContextCompactionStatus::InProgress/Completed/Failed`). Failures are retried through the same retry machinery.
- The compaction prompt, verbatim (`crates/agent_settings/src/prompts/compaction_prompt.txt`):

```
You are compacting this conversation into a handoff for another agent that will resume the work.

Include:
- Goal: what the user is ultimately trying to achieve
- State: progress so far, current blockers, and decisions made
- Context: constraints, preferences, and critical data/examples/references needed to continue
- Next: the specific steps that remain
- Pitfalls: anything tried that didn't work

Write it so the next agent can act without re-asking the user. Be concise and well-structured.
```

- **Storage.** The summary is stored as `Message::Compaction(CompactionInfo::Summary)`.
  - Auto compaction inserts it *before* a trailing unanswered user message.
  - Manual `/compact` appends an empty user marker plus the summary (`CompactionInsertion`, `:4858-4864`).
- **What the next request contains** (`extend_request_history_until`, `:4925-4951`):
  1. the system prompt;
  2. **the most recent user messages before the compaction point, verbatim, newest-first up to `COMPACTION_RETAINED_USER_MESSAGES_BYTE_BUDGET = 80_000` bytes** (~20k tokens, `:127`), truncated at a char boundary, then re-ordered oldest-first (`retained_user_request_messages_before`, `:4959-4992`);
  3. the summary, as a user message: `"The previous conversation was compacted. Use this summary as context:\n\n{summary}"` (`:220-232`);
  4. everything after the compaction point.
- **Everything else is dropped:** assistant text, tool calls, tool results. The user's own words survive verbatim; the agent's work survives only as the handoff.
- `CompactionInfo::ProviderNative { provider, items }` is a second variant for provider-side compaction blobs. They are opaque, not re-sent as text (`:213-232`).

### Manual compaction and the "new thread from summary" handoff

- `/compact` → `Thread::compact` (`:2600-2690`) forces the same summary strategy regardless of size.
- For **small-window models** (< 80k), the token-limit callout says *"To continue, run /compact or start a new thread and @-mention this one"*. It offers **Start New Thread**, which dispatches `NewNativeAgentThreadFromSummary` (`thread_view.rs:12313-12370`; `agent_panel.rs:3473-3518`).
- The new thread's initial content is a `ThreadSummary` mention of the old thread. The mention is resolved with `Thread::summary()` using `SUMMARIZE_THREAD_DETAILED_PROMPT`:

```
Generate a detailed summary of this conversation. Include:
1. A brief overview of what was discussed
2. Key facts or information discovered
3. Outcomes or conclusions reached
4. Any action items or next steps if any
Format it in Markdown with headings and bullet points.
```

### Tool-output limits (at tool time)

| Tool | Limit |
|---|---|
| terminal | 16 KiB to the model (`COMMAND_OUTPUT_LIMIT`, `terminal_tool.rs:24`), plus model-chosen `head_lines` / `tail_lines` |
| read_file | Files > 16 KiB (`AUTO_OUTLINE_SIZE`, `outline.rs:10`) without a line range return a **symbol outline with line numbers** instead of content, falling back to the first 1 KB when there is no outline |
| grep | 20 matches per page, 2 context lines, up to 10 lines of enclosing syntax ancestors (`grep_tool.rs:68,122-123`) |
| find_path | 50 per page |

There is **no age-based pruning** of old tool results and **no agent-controlled self-pruning tool**. Compaction is the only reduction.

### Prompt caching

- **The system prompt is kept byte-stable on purpose.** `maintain_project_context` only replaces `ProjectContext` when it actually differs (`agent.rs:1046-1060`). The comment: *"an unchanged `ProjectContext` means a byte-identical system prompt and a continued hit on the model API's prompt cache."* The only per-request variable in the prompt is the date at day granularity.
- The **last request message gets `cache: true`** (`thread.rs:4434-4436`).
- **Anthropic** (`crates/anthropic/src/completion.rs:360-410`) in `Automatic` mode:
  - the last tool and the system block get an explicit **1-hour TTL** `cache_control`;
  - the conversation relies on Anthropic's top-level automatic cache breakpoint.
  - The comment: *"Anthropic requires that longer TTLs appear earlier in the prefix… tools → system → messages."*
  - `Legacy` mode marks the last cacheable block of each `cache: true` message as ephemeral.
- Tools that stream input set `eager_input_streaming`. This is Anthropic fine-grained tool streaming, which is what makes the live edits possible.

---

## 5. Prompt generation

### What is sent on every request

`build_request_messages_until` (`thread.rs:4402-4439`):
- **one `system` message** rendered from `crates/agent/src/templates/system_prompt.hbs` (279 lines; handlebars strict mode; `contains` helper in `templates.rs`);
- the history (user messages with mention context blocks, agent messages with tool uses and results);
- the tool schemas (in `request.tools`, not in prose).

**Not included automatically:**
- git status;
- a file tree or repo map;
- the cwd beyond the worktree root paths;
- open files or the cursor;
- memory;
- diagnostics.

The model has to call tools or receive @-mentions to get any of those. This is a deliberately **thin, cache-stable prompt**.

### Template variables (`SystemPromptTemplate`, `templates.rs:39-64`)

| Variable | Contents |
|---|---|
| `project` | `ProjectContext`: `worktrees[{root_name, abs_path, rules_file{path_in_worktree,text}}]`, `has_rules`, `os`, `arch`, `shell`, skills catalog |
| `available_tools` | Names of the tools enabled this turn |
| `model_name` | |
| `date` | `Local::now().format("%Y-%m-%d")` |
| `user_agents_md` | |
| `sandboxing`, `is_linux`, `is_windows` | |

### The template, section by section (verbatim excerpts)

1. **Identity:**
   > "You are the Zed coding agent running inside the Zed editor. You help users complete software engineering tasks by understanding their codebase, making careful changes, and explaining your work clearly…"
2. **Communication.** Concise and friendly; ground claims; *"Prioritize technical correctness over affirming the user's assumptions"*; *"If you infer something, label it as an inference"*; don't over-apologize.
3. **Formatting.** Markdown, images via markdown syntax, a **mermaid** section (renderer-supported diagram types; "do not include `%%{init}%%`"; prefer taller over wider).
4. **`{{#if (gt (len available_tools) 0)}}` → Tool Use / Task Execution / Searching / Making Code Changes / Ambition vs Precision / Validation / Fixing Diagnostics / Debugging / External APIs / Multi-agent delegation / Final Message.** Key lines:
   - *"Do not call a tool just because it appeared earlier in the conversation; the user may have disabled it."* This pairs with per-turn tool refresh.
   - *"When running commands that may run indefinitely… specify `timeout_ms`… If a command times out, report that clearly and let the user decide whether to rerun it with a longer timeout."*
   - *"Do not waste tokens by re-reading files after calling `write_file`, `edit_file`, or similar. The tool call will fail if it didn't work."*
   - *"Before a group of related tool calls, send a brief one- to two-sentence preamble…"*
   - *"Keep going until the user's task is completely resolved before ending your turn…"*
   - *"Do not commit changes or create new git branches unless the user explicitly requests it."*
   - *"Do not fix unrelated bugs or broken tests."*
   - *"Do not claim validation passed unless you actually ran it and saw it pass."*
   - Fixing Diagnostics: *"Make 1-2 focused attempts at fixing diagnostics you are likely able to resolve, then defer to the user… Never simplify or discard meaningful code just to silence diagnostics."*
   - Multi-agent delegation appears only if `spawn_agent` is available. It covers when to delegate and *"assign disjoint write scopes"*.
5. **`{{else}}` (no tools).** *"You are being tasked with providing a response, but you have no ability to use tools…"* Do not invent details about unseen files; ask for them.
6. **System Information:**
   ```
   Operating System: {{os}}
   Default Shell: {{shell}}
   Today's Date: {{date}}

   The current project contains the following root directories:
   {{#each worktrees}}- `{{abs_path}}`{{/each}}
   ```
7. **`{{#if sandboxing}}{{#if (contains available_tools 'terminal')}}` → Terminal sandbox** (platform-specific, §10). Ends with: *"These sandbox settings are guaranteed to remain in effect for the entire duration of this thread. If they ever change, you will be told."*
8. **Model Information:** *"You are powered by the model named {{model_name}}."*
9. **Agent Skills.** An `<available_skills>` catalog of `<skill><name/><description/><location/></skill>`. Names and descriptions are HTML-escaped. `location` is raw because the model passes it back to `read_file`. Then a 4-step "To use a Skill" recipe.
10. **User's Custom Instructions** (`{{#if (or user_agents_md has_rules)}}`):
    > "The following additional instructions are provided by the user and should be followed to the best of your ability without interfering with the tool use guidelines."
    - `### Personal AGENTS.md` — *"These instructions apply to every project this user opens. Project-specific rules below may override them."* The body goes in a six-backtick fence.
    - `### Project Rules` — *"These instructions are scoped to the current project. They take precedence over the personal AGENTS.md above when they conflict."* Then for each worktree: `` `{{root_name}}/{{rules_file.path_in_worktree}}`: `` followed by the fenced text.
    - A unit test pins the ordering, so that personal comes before project and project can override (`templates.rs:120-159`).

### How project context, worktrees and rules files are injected

**Rules files.** One per worktree root, **the first match wins** in this order (`crates/prompt_store/src/prompts.rs:22-32`; `agent.rs:1319-1362`):

```rust
pub const RULES_FILE_NAMES: &[&str] = &[
    ".rules", ".cursorrules", ".windsurfrules", ".clinerules",
    ".github/copilot-instructions.md", "AGENT.md", "AGENTS.md", "CLAUDE.md", "GEMINI.md",
];
```

- The file is read through the project buffer, so unsaved edits count. It is trimmed and the whole file is included.
- There is no nested or ancestor lookup and no `@import` expansion.

**Personal `AGENTS.md`.** This is `~/.config/zed/AGENTS.md` (the platform equivalent). It is loaded into a global, file-watched; read errors surface in the UI (`crates/agent_settings/src/user_agents_md.rs`). It **replaced the Rules Library's "default rules"**: the migration appends each default rule under an `## <title>` heading (`rules_to_skills_migration.rs:1-35`).

**Non-default Rules** were migrated to `~/.agents/skills/<slug>/SKILL.md` with `disable-model-invocation: true` (slash-only). The old `@rule` mention survives only for deserialisation (`acp_thread/src/mention.rs:39-46`).

**Refresh.** `maintain_project_context` (`agent.rs:1000-1082`) rebuilds on worktree, rules-file, prompt-store, skill and trust-state changes. It pushes a new `ProjectContext` only when it is different.

### Mid-conversation injections

**There are no periodic system reminders.** Context arrives only in two ways:

**(a) User @-mentions**, packaged per message (`UserMessage::to_request`, `thread.rs:329-588`). The mention text is replaced by a link and the contents are grouped under:

```
<context>
The following items were attached by the user. They are up-to-date and don't need to be re-read.

<files> ```path#Lstart-end … ``` </files>
<directories>…</directories> <symbols>…</symbols> <selections>…</selections>
<diffs>Branch diff against {base_ref}: ```diff …```</diffs>
<threads>…</threads> <fetched_urls>Fetch: {url}\n\n{content}</fetched_urls>
<rules>The user has specified the following rules that should be applied: …</user_rules>
<diagnostics>…</diagnostics> <skills>The user has attached the following agent skills: …</skills>
<merge_conflicts>…</merge_conflicts>
</context>
```

The rules section opens with `<rules>` but closes with `</user_rules>` (`:542-547`). This is a tag-mismatch bug, now only on the deprecated path.

**(b) Tool results.**

### Other prompts

| Purpose | Source | Text |
|---|---|---|
| Title | `summarize_thread_prompt.txt`, used by `build_thread_title_request`, `thread.rs:4994` | *"Generate a concise 3-7 word title for this conversation, omitting punctuation. Go straight to the title, without any preamble and prefix like `Here's a concise suggestion:...` or `Title:`. If the conversation is about a specific subject, include it in the title. Be descriptive. DO NOT speak in the first person."* Only the first line of the stream is kept |
| Commit message | `crates/git_ui/src/commit_message_prompt.txt` | Built by `build_commit_message_prompt`, `git_panel.rs:4071-4114`. Appends the personal AGENTS.md in `<rules>`, project rules in `<project_rules>` (setting `commit_message_include_project_rules: true`), a user `<commit_message_instructions>`, the subject typed so far, and the diff |

---

## 6. Memory

- **No agent memory store.** There is no memory tool, no auto-extraction and no relevance retrieval.
- Persistence comes from three places:
  - the rules files and the personal `AGENTS.md`, which the agent *can* edit with `edit_file`. Edits to them go through the normal permission flow;
  - **skills**: `~/.agents/skills` (global) and `<worktree>/.agents/skills` (project, **trusted worktrees only**). The agent may create and edit global skills — `edit_file` / `write_file` accept `~/.agents/skills/...` — and a built-in `create-skill` skill teaches it how (`agent_skills.rs:695-730`);
  - **threads** as memory, via `@thread` mentions (a summary of another thread) and "new thread from summary".
- **Thread storage.** SQLite `threads.db` in the data dir, each thread serialised as JSON compressed with **zstd level 3** (`crates/agent/src/db.rs:176-185,446-545`). The ACP `session/list` is used to **import other agents' sessions** into the sidebar (`agent_ui/src/thread_import.rs:807`).

Compared with sugar-crush, which has a `MemoryStore` with project, user and agent scopes and a `<project-memory>` prompt block (LIVE, baseline §5), **Zed has nothing to copy here**. Zed's equivalent is the editable personal `AGENTS.md` plus skills.

---

## 7. Tools and editing

### Roster

Registered in `add_default_tools` (`thread.rs:2173-2247`); default profile flags in `default.json:1286-1314`.

| Tool | Notes |
|---|---|
| `read_file` | `path`, `start_line`, `end_line`. Output is `cat -n`-style (6-char right-aligned number + tab). Files > 16 KB without a range return an outline with line numbers. Reads PNG/JPEG/WebP/GIF/BMP/TIFF as images. Refuses paths matching `private_files` (default `**/.env*, **/*.pem, **/*.key, **/*.cert, **/*.crt, **/secrets.yml`, `default.json:498`) and `file_scan_exclusions` |
| `edit_file` | `path` plus `edits:[{old_text,new_text}]`. Streaming, fuzzy, re-indenting (below) |
| `write_file` | Create or overwrite, streaming (`EditSessionMode::Write`) |
| `grep` | Rust regex; `include_pattern` glob; case-insensitive by default; 20 per page with offset; syntax-ancestor context |
| `find_path` | Glob, 50 per page |
| `list_directory`, `copy_path`, `move_path`, `delete_path`, `create_directory` | |
| `terminal` / `sandboxed_terminal` | Exposed as `terminal`: one-liner, `cd` param, `timeout_ms`, `head_lines`, `tail_lines`, 16 KiB cap. **Rejects `$VAR`, `$(...)`, backticks, `<(...)`** in permission-protected commands (`tool_permissions.rs:14-17,273-286`) |
| `fetch` | URL → markdown (`html_to_markdown`, with Wikipedia handlers), ≤ 20 redirects |
| `search_web` | Zed-cloud web search |
| `diagnostics` | Per-file, or a project summary; tries to refresh pull diagnostics first |
| `go_to_definition`, `find_references`, `rename_symbol`, `get_code_actions`, `apply_code_action` | Full LSP access through the editor's language servers |
| `skill` | Loads a SKILL.md body wrapped in `<skill_content>`; XML-escaped so a hostile skill cannot close the envelope |
| `spawn_agent`, `create_thread`, `list_agents_and_models` | §3 |
| `ask_user` | Question plus ≥ 2 options and/or free text, rendered as a form via ACP *elicitation*. **Off in all default profiles** |
| MCP tools | Per server; names are sanitised to `[A-Za-z0-9_-]{≤64}`; collisions get a `<server>_` prefix (`thread.rs:4269-4302`) |

### The `edit_file` pipeline (the standout)

The pipeline lives in `crates/agent/src/tools/edit_session.rs` plus `edit_session/{streaming_parser,streaming_fuzzy_matcher,reindent}.rs` and `crates/streaming_diff`.

**Schema** (`edit_session.rs:46-61`): `old_text` (*"This will be matched using fuzzy matching to handle minor differences in whitespace or formatting. Be minimal with replacements…"*) and `new_text`. Edits are applied sequentially. The tool description tells the model to strip `read_file`'s line-number prefix.

**1. Streaming parser.** It diffs consecutive partial-JSON snapshots into `OldTextChunk` / `NewTextChunk {done}` events. It holds back a trailing `\` because the partial-JSON fixer can momentarily produce `\` instead of `\n` (`streaming_parser.rs` header).

**2. Session open** (`EditSession::new`, `:680-777`):
- resolve the path (project or `~/.agents/skills`);
- **authorize** (§10);
- open the *buffer*;
- `ensure_buffer_saved`. **If the user has unsaved changes, prompt Save / Discard / Keep(cancel).** The prompt auto-dismisses if the user saves meanwhile (`resolve_dirty_buffer`, `:1066-1165`).
- **Staleness:** compare the file mtime with the action log's `file_read_time`. If they differ, set `file_changed_since_last_read` (`:1028-1055`).

**3. Locate `old_text` while it streams** (`StreamingFuzzyMatcher`, `streaming_fuzzy_matcher.rs`):
- It is a **line-level DP alignment** over the whole buffer, with costs `REPLACEMENT_COST = 1`, `INSERTION_COST = 3`, `DELETION_COST = 10` (`:4-6`).
- Lines are compared trimmed. `fuzzy_eq` accepts `strsim::normalized_levenshtein ≥ 0.8`, after a cheap length-difference pre-check (`:358-369`).
- A candidate needs ≥ 80% of its lines aligned (`matched_ratio >= 0.8`, `:243-246`).
- It re-runs per *complete line* as chunks arrive, so the editor **scrolls to and highlights the target region before the model has finished writing `old_text`**.
- On `done`, an **exact** substring search runs first (`MAX_EXACT_MATCHES = 2`, enough to prove ambiguity), with the fuzzy result as the fallback (`finish`, `:96-130`).

**4. Match resolution and error texts** (`extract_match`, `:957-1005`):
- **No match:** *"Could not find matching text for edit at index {i}. The old_text did not match any content in the file.{ The file has changed on disk since you last read it.} Please read the file again to get the current content."*
- **Ambiguous:** *"Edit {i} matched multiple locations in the file at lines: 12, 88. Please provide more context in old_text to uniquely identify the location."*

**5. Re-indent** (`reindent.rs`). Compute the indent delta between the buffer line and the query's first line (tabs or spaces). Compute a separate "rest" delta when the remaining lines agree, which handles a model that stripped only the first line's indent. Apply it to `new_text` as it streams. An exact match that starts mid-line uses delta 0.

**6. Stream `new_text` into the buffer.** `StreamingDiff::push_new` produces char ops (`Insert` / `Delete` / `Keep`) against the matched old text, applied with anchors in one transaction via `agent_edit_buffer`. That function reports to the action log *in the same effect cycle*, so the edit is attributed to the agent, not the user (`:921-955,1008-1026`). The user **watches the code change token by token**.

**7. Finalise** (`run_session`, `:275-328`):
- format-on-save if configured, save, `action_log.buffer_edited`;
- compute the unified diff.
- **On success** the model gets only `"Edited {path} successfully"` (or "No edits were made."). The diff goes to the UI.
- **On partial failure** — say edit #3 of 5 failed — the model gets the error **plus the diff of what *was* applied**: `"{error}\nEdited {path}:\n\n```diff\n…```"` (`Display for EditSessionOutput`, `:95-128`). The model therefore knows the exact file state without re-reading.

### Edit evals

`crates/agent/src/tools/evals/edit_file.rs` runs real-model edit evals on fixture repos, judged by an LLM with `templates/diff_judge.hbs`. The leftover `edit_file_prompt_xml.hbs` / `edit_file_prompt_diff_fenced.hbs` templates belong to the older "edit agent" design and have no Rust references now.

### Diagnostics and lint loop

- **No automatic post-edit lint injection.**
- The model calls `diagnostics` (*"This tool can be invoked after a series of edits to determine if further edits are necessary"*) and is told to make 1-2 attempts.
- Formatting happens through format-on-save inside the edit pipeline.

### Shell (`terminal_tool.rs`)

- A real PTY terminal entity (`acp_thread::Terminal`), visible live in the thread as a terminal card.
- Kill on `timeout_ms`; the user can stop it (`cancel_generation_on_terminal_stop: true`).
- A fresh shell per call.
- The description tells the model to use `git --no-pager`, `GIT_EDITOR=true`, `PAGER=cat`, and not to run servers or watchers.

---

## 8. Git integration

**Status in the prompt:** none. The model runs `git --no-optional-locks status` through the terminal if it wants it.

### Checkpoints (shadow git, no branch pollution)

`RealGitRepository::checkpoint` (`crates/git/src/repository.rs:3063-3089`):

```text
with_temp_index (copy of .git/index):
  head = rev-parse HEAD
  git add --update
  untracked = ls-files --others --exclude-standard -z --exclude-from=<checkpoint.gitignore tmp>
              minus files >= 2 MB                        (:3774-3838, MAX_SIZE = 2 MiB)
  update-index --add -z --stdin <untracked>
  tree = write-tree
  sha  = commit-tree tree -p head -m "Checkpoint"        (author/committer "Zed <hi@zed.dev>", :4278)
```

- `checkpoint.gitignore` excludes binaries, archives, media and similar.
- **Restore:** `git restore --source <sha> --worktree .` (`:3091-3117`). It deliberately does *not* `git clean`, because untracked large or binary files are not in the checkpoint (the code has a TODO).
- **When.** `AcpThread` takes a checkpoint **before sending each user message**, for any agent (`acp_thread.rs:5724-5735`). After the turn, `update_last_checkpoint_if_changed` compares it with a fresh checkpoint (`compare_checkpoints`) and only *shows* the "Restore Checkpoint" button when the turn actually changed files (`:6191-6260`).
- `restore_checkpoint(message_id)` = cancel → **rewind** (truncate the thread at that message, kill its terminals, `action_log.reject_all_edits`) → git restore (`:6111-6143`). `rewind` without git exists too (`:6146-6189`).
- **Inference:** the checkpoint commits are not referenced by any ref, so `git gc` can prune them after `gc.pruneExpire` (2 weeks by default). Old threads' checkpoints may silently stop restoring.

### Review changes (multibuffer, per-hunk Keep/Reject)

**`ActionLog`** (`crates/action_log/src/action_log.rs`):
- For each tracked buffer it keeps a `diff_base` (what the user has accepted) and the `unreviewed_edits`.
- Agent edits are recorded through `buffer_edited`. **User edits are rebased**: `apply_non_conflicting_edits` folds user edits that don't overlap agent hunks into the base, so the user's own typing never shows up as agent changes (`track_edits`, `:329-375`).
- `keep_edits_in_range` (`:641`) and `reject_edits_in_ranges` (`:712`) work per hunk. Reject restores the base text, deletes agent-created files, and restores the original content of overwritten files.
- `undo_last_reject` (`:979`) restores what you just rejected.
- **`keep_committed_edits`** (`:377-470`): when git's HEAD diff base changes because of a commit, agent hunks whose content landed in the commit are auto-accepted.
- `file_read_times` (mtime per path) drives the staleness warning (§7). `linked_action_log` forwards to the parent for sub-agents.

**UI.**
- `AgentDiffPane` (`crates/agent_ui/src/agent_diff.rs`) is a workspace item: a **multibuffer of every file the agent touched**, with inline Keep/Reject buttons per hunk, GoToHunk navigation, and Keep All / Reject All.
- The thread view shows an **edits summary bar** above the input: changed files with +/- counts and Keep All / Reject All (`thread_view.rs:4328`).
- `single_file_review` (off by default) shows the hunks in normal editors too.
- The editor *follows* the agent's cursor (`AgentLocation`).

### Other git features

- **Commit messages:** the git panel's generator (§5).
- **Worktrees:** `create_thread {use_new_worktree}` plus a worktree picker and archive (§3).
- **No auto-commit.** The prompt says "Do not commit changes… unless the user explicitly requests it".

---

## 9. Extensibility

### MCP ("context servers")

The client is `crates/context_server`.

- **Transports:** stdio and streamable HTTP (`transport/{stdio_transport,http}.rs`), with **OAuth** (`oauth.rs`, 2.9k lines).
- **Protocol versions:** up to `2025-11-25` (`types.rs:8-11`).
- **Methods implemented:** `tools/list|call`, `prompts/list|get`, `resources/list|read|subscribe|templates/list`, `completion/complete`, `roots/list`, plus `notifications/{progress,message,cancelled,tools/list_changed,prompts/list_changed,resources/*}` (`types.rs:40-126`).
- **Tool hot-reload.** The registry re-lists tools on `tools/list_changed` (`ContextServerRegistry`, `crates/agent/src/tools/context_server_registry.rs`).
- **MCP prompts become slash commands** in the agent panel.
- **Per-profile control:** `enable_all_context_servers` and `context_servers.<id>.tools.<name>: bool` (`agent_profile.rs:104-150`).
- **Permissions:** MCP tools get their own "always allow/deny" options (`always_allow_mcp:` ids, `thread.rs:6716-6724`). Tool ids for settings are `mcp:<server>:<tool>` (`context_server_registry.rs:8-11`).
- **ACP sessions can forward MCP servers** to the external agent (`session/new` carries `mcpServers`).

### Skills (`crates/agent_skills`)

- Sources: `~/.agents/skills/<name>/SKILL.md` (global, watched lazily) and `<worktree>/.agents/skills/*/SKILL.md` (project, **trusted worktrees only**), plus the built-in `create-skill`.
- **Limits:**
  - `MAX_SKILL_FILE_SIZE = 100 KB`;
  - **catalog budget `MAX_SKILL_DESCRIPTIONS_SIZE = 50 KB`** — skills past the budget are dropped *and* a loading issue is shown to the user (`select_catalog_skills`, `agent.rs:3700-3760`);
  - name ≤ 64 characters, description ≤ 1024.
- `disable-model-invocation: true` gives slash-only skills that are kept out of the catalog.
- Skills are **attachable as `@skill` mentions**.

### Other extension points

- **Rules:** see §5. The Rules Library is gone.
- **Custom slash commands:** come from skills and MCP prompts.
- **Custom agents:** any ACP binary, configured in `agent_servers` settings or installed from the ACP **registry** (`project/src/agent_server_store.rs`, `agent_registry_store`).
- **Hooks:** none for the native agent.

### ACP — what the protocol carries

The crate is `agent-client-protocol = "=2.2.0"` (features `unstable`, `unstable_protocol_v2`). It is JSON-RPC 2.0 over newline-delimited stdio. Message types used by Zed (`crates/agent_servers/src/acp.rs`, `crates/acp_thread`):

**Client → agent:**
- `initialize`, carrying `ClientCapabilities` (`acp.rs:673-710`):
  - `fs.read_text_file`, `fs.write_text_file`
  - `terminal: true`, `auth.terminal`
  - `session.config_options` (boolean), plus beta `compaction` and `notices`
  - `elicitation.form|url`
  - `_meta {terminal_output, terminal-auth}`
- `authenticate`, `logout`
- `session/new` (cwd, mcpServers), `session/load`, `session/resume`, `session/list`, `session/close`, `session/delete`
- `session/prompt` (content blocks: text, image, audio, resource_link, embedded resource)
- `session/cancel` (a notification)
- `session/set_mode`, `session/set_config_option`

**Agent → client notifications** (`session/update`, handled at `acp_thread.rs:4110-4225`):
- `user_message_chunk`, `agent_message_chunk`, `agent_thought_chunk`
- `tool_call` / `tool_call_update` (title, `kind` ∈ read/edit/delete/move/search/execute/think/fetch/other, status, `content` = text | **diff {path, oldText, newText}** | terminal, `locations[{path,line}]`, raw input/output)
- `plan` (todo entries with priority and status)
- `available_commands_update` (slash commands), `current_mode_update`, `config_option_update`
- `session_info_update`, `usage_update`
- beta `compaction_update` / `compaction_summary_chunk` / `notice`

**Agent → client requests:**
- `session/request_permission` (tool call plus options of kind `allow_once | allow_always | reject_once | reject_always`)
- `fs/read_text_file`, `fs/write_text_file`
- `terminal/create|output|wait_for_exit|kill|release`
- `elicitation/create` (+ `complete`)

**Why it matters.** When Claude Code writes through `fs/write_text_file`, Zed diffs the content against the buffer, applies it as an agent transaction and records it in the **same `ActionLog`** (`acp_thread.rs:6421-6500`). So review, Keep/Reject, checkpoints and follow-the-agent work for external agents unchanged. That is the payoff of the shared interface.

### Could sugar-crush speak ACP?

**Yes, as an agent (server). The pieces exist.**
- Methods: `initialize` → `session/new` → `session/prompt` maps onto `EngineBackend::complete()` / `completeTranscript()` (`src/Backend/EngineBackend.php:726,755`), whose callbacks already emit token, reasoning, `ToolStarted` / `ToolFinished` (with `ToolResult::$diff`, `$arguments`, `$description`) and sub-agent events. Those become `session/update`. `session/cancel` maps to a `CancellationToken`.
- Permissions: `session/request_permission` is a natural fit for the existing `EngineBackend::withPermissionApprover(\Closure)` seam (`:540`). The closure sends the request and blocks on the JSON-RPC reply. In-process this is easy, because no fork boundary is involved if `acp` mode runs the loop synchronously, as `-p` does.
- Sessions: `session/load` / `session/list` map to `EnhancedSessionStore` (`loadTranscript`, `list`).
- Framing: JSON-RPC encoding can reuse `sugarcraft/sugar-mcp`'s `McpMessage` (`sugar-mcp/src/McpMessage.php`, with `request/notification/success/error/parse/toJson`).

**Also yes, as a client:** `ClaudeCodeProvider` currently shells out to `claude -p --output-format stream-json`, and Claude Code's tools bypass sugar-crush's hooks and gate (baseline §1.3). Driving `claude-agent-acp` over ACP instead would let sugar-crush answer Claude Code's `request_permission` and serve its `fs/*` writes through sugar-crush's own gate and diff view. `sugar-mcp`'s `StdioMcpServer` already handles spawning a stdio JSON-RPC child process.

---

## 10. Permissions and safety

### Decision order

`ToolPermissionDecision::from_input`, `crates/agent/src/tool_permissions.rs:214-460`:

1. **Hard-coded rules**, which cannot be overridden (`:25-104`).
   - Terminal regexes block `rm` with any flags of `/`, `/*`, `~`, `~/`, `$HOME`, `${HOME}`, `.`, `./`, `..`, `../`. Flags may come before or after the operand (`rm / -rf`).
   - Each sub-command of a chained command is checked, and **path-normalised expansions** catch `rm -rf /tmp/../../` and multi-path `rm -rf /tmp /`.
   - Message: *"Blocked by built-in security rule. This operation is considered too harmful to be allowed, and cannot be overridden by settings."*
2. Invalid user regex → deny the tool entirely.
3. **Terminal input validation.** If the tool is not unconditionally allowed, commands containing `$VAR`, `${VAR}`, `$(...)`, backticks, `$((...))`, `<(...)` or `>(...)` are denied, with instructions to resolve the literals first. This keeps allow patterns sound.
4. Per-tool rules, regex against the tool input (command, path, URL), case-insensitive by default.
   - **Terminal commands are parsed (brush-parser) into sub-commands**, and `check_commands` (`:378-430`) applies three rules:
     - **deny** if ANY sub-command matches a deny pattern;
     - **confirm** if ANY matches a confirm pattern;
     - **allow** only if ALL sub-commands match an allow pattern.
   - If parsing fails or the shell is non-POSIX, **allow patterns are disabled**.
   - Precedence: `always_deny` > `always_confirm` > `always_allow` > tool `default` > global `default`.
5. Global `"tool_permissions": {"default": "confirm"}` — **the out-of-the-box default is to prompt** (`default.json:1234-1263`).

### Prompt options

`build_permission_options`, `thread.rs:988-1178`:
- "Always for {tool}"
- **"Always for `cargo test` commands"** — a pattern extracted by `extract_terminal_pattern`. Command name plus subcommand become `^cargo\s+test(\s|$)`. Path-like commands (`./x`, `/bin/x`) are refused on purpose (`pattern_extraction.rs:55-90`).
- Path patterns for file tools and URL patterns for fetch.
- "Only this time".
- For pipelines, a **per-sub-command dropdown** (`DropdownWithPatterns`).
- "Always" choices are **written into `settings.json`** (`persist_always_permission`, `:6753`).
- A pending prompt **auto-resolves if settings change** to a definitive allow or deny. For example, approving "always" on one of several parallel prompts resolves the rest (`run_authorization_loop`, `:6576-6690`).

### Always-prompt paths

`authorize_always_prompt` covers symlink escapes out of the project and edits to **sensitive settings**: `.zed/` (local settings, which could grant permissions) and `.agents/skills/` (`tools/tool_permissions.rs:287-340`). These prompt even when `always_allow` would match.

### Restricted mode

Untrusted worktrees get:
- the profile downgraded to Minimal (only if both profiles are unmodified defaults);
- tools without `allow_in_restricted_mode()` hidden *and* refused at run time if the workspace becomes restricted mid-thread (`thread.rs:3669-3687`);
- project skills hidden.

### OS sandbox

`crates/sandbox`; policy in `crates/agent/src/sandboxing.rs`. On Linux, `build_bwrap_args_with_sandbox_paths` (`crates/sandbox/src/linux_bubblewrap.rs:248-350`) builds:

```
--ro-bind / /   (or --bind if allow_fs_write)
--dev /dev --proc /proc --tmpfs /tmp
--bind <worktree> <worktree>...          (exact paths, pinned at capture time; no ancestor widening)
--ro-bind <.git dirs> ...                 (protected over the rw binds; "later binds win")
--unshare-user --unshare-ipc --unshare-uts --unshare-pid --unshare-cgroup-try --die-with-parent
--unshare-net                             (or bind a proxy socket for allow_hosts via HTTP proxy)
--chdir <cwd>
```

- An in-sandbox launcher verifies the bound inodes (`validate_binds`) and **fails closed**, defending against TOCTOU symlink swaps.
- Escalation flags per terminal call: `allow_hosts[]` (HTTP/HTTPS through a proxy), `allow_all_hosts`, `fs_write_paths[]`, `allow_fs_write_all`, `unsandboxed`. The user approves once, for the thread, or always.
- **`.git` metadata is never writable inside the sandbox.** Git writes need `unsandboxed: true` "with a reason".

### Secrets

- `private_files` blocks reads (§7).
- `HARDCODED` rules cover only `rm`.
- No credential redaction in outputs.

---

## 11. UX worth copying (TUI-adaptable)

1. **The token ring plus tooltip** (`thread_view.rs:4931-5060`).
   - A 16 px progress ring with the percentage; it turns the warning colour at ≥ 85%.
   - The tooltip shows used/max, an input/output split with separate maxima, and **cost**.
   - It also lists **which rules files and whether the personal AGENTS.md are loaded**, with click-through.
   - The warning band starts at 80% (`TOKEN_USAGE_WARNING_THRESHOLD`, `acp_thread.rs:3220`).
2. **Live compaction in the transcript:** "Compacting…" with the summary streaming in, and a collapsible card afterwards.
3. **Restore Checkpoint**, shown only when the turn changed files.
4. **Edits bar plus review pane** with Keep/Reject per hunk and undo-reject.
5. **A message queue** with an editable entry, "send now" and **Steer**.
6. **Sub-agent cards.** The model-chosen `label` acts as the live title, with an expandable transcript and a permission banner.
7. **Retry banner** showing attempt N/M, the countdown and the last error.
8. **Notifications** when the agent finishes or waits for confirmation (`notify_when_agent_waiting: "primary_screen"`), an optional sound (`play_sound_when_agent_done`), `show_turn_stats` (elapsed time / turn duration), and thumbs up/down feedback.
9. **A thread sidebar** with history, search in thread, archive, import from other agents (ACP `session/list`), worktree threads, a per-thread model/profile/thinking-effort selector, and a draft prompt persisted per thread.
10. **Expandable edit and terminal cards** (`expand_edit_card`, `expand_terminal_card`), with live terminal output inside the conversation.

---

## 12. Comparison table

| Feature | Zed agent | sugar-crush (baseline) | Gap |
|---|---|---|---|
| Loop step cap | None; cancel only | `maxSteps` default **8** (§1.2), then a truncation notice | sugar-crush stops long tasks early; Zed never stops (no doom-loop guard either) |
| Tools start while streaming | Yes; edit tools stream input | No; tools run after the step's message completes | — |
| Parallel tools | All calls concurrent | Read-only `ParallelSafe` segments forked (§1.4) | Comparable; sugar-crush safer for writes |
| Retry after partial stream | Keep partial text plus "Continue where you left off" | **Never** once a token was emitted (§1.3) | **Big** |
| Retry policy | 4 attempts, 5 s base, jitter, honours `retry_after`, per-class | 3 attempts, 0.5 s exponential | Small |
| Refusal fallback model | Yes | ABSENT | Small |
| Mid-turn steering | Steer flag ends the turn at the tool boundary | ABSENT; queue waits for turn end (§1.4) | Medium |
| Cross-turn tool-call replay | Structured tool_use/tool_result kept; canceled calls get a synthetic result | **Lossy**: replayed as assistant text (§0.3) | **Big** |
| Token accounting | Provider-reported, per request, max-merge | chars/4 + calibration; ignores system prompt and tools (§3.2) | Medium |
| Auto-compaction trigger | **Before every request, incl. between tool steps**, at 90% (configurable % / used / remaining) | Only at user submit, 85% (§3.3) | **Big** |
| Compaction content | Handoff (Goal/State/Context/Next/Pitfalls) plus the last 80 KB of user messages verbatim | Six-facet per-exchange records; heuristic pair-clipping | Different; Zed's is simpler and keeps the user's words |
| "New thread from summary" | Yes, plus `@thread` mention | `/branch` forks, no summary handoff | Small |
| Prompt cache control | 1 h TTL on tools+system, auto breakpoint on conversation; byte-stable system prompt | `CacheBreakpoints` DORMANT; implicit prefix only (§3.5) | Medium (Anthropic/Bedrock/Vertex users) |
| System prompt richness | Thin: OS, shell, date, roots, rules, skills, sandbox | Rich: 11 slots incl. `<env>` git status/diff and repo map (§4) | sugar-crush richer; Zed more cache-stable |
| Rules files | First of `.rules/.cursorrules/.windsurfrules/.clinerules/copilot-instructions/AGENT.md/AGENTS.md/CLAUDE.md/GEMINI.md` per root, plus personal AGENTS.md | CLAUDE.md + AGENTS.md, ancestors, nested-on-touch, `@imports`, user rules dir (§4) | sugar-crush deeper; Zed broader aliases plus a personal file |
| @-mentions in input | file/dir/symbol/selection/thread/fetch/diagnostics/diff/skill/merge-conflict → `<context>` | ABSENT (only `@file` inside custom-command templates) (§10) | Medium |
| Edit format | Multi-edit list, fuzzy line DP (≥ 0.8), re-indent, exact-first | Single exact unique `old_string` (§6.3) | **Big** |
| Edit staleness | mtime vs last read → message; dirty-buffer prompt | None (§6.3) | Medium |
| Partial-edit failure feedback | Error plus the diff of the edits that were applied | N/A (single edit) | — |
| Read with ranges / line numbers | `start_line` / `end_line`, `cat -n`, outline > 16 KB, images | Whole file ≤ 1 MiB, no numbers (§6.3) | **Big** |
| LSP tools | definition, references, rename, code actions, diagnostics | LSP DORMANT, no client (§6.6) | Big (wiring) |
| Shell timeout and output | `timeout_ms`, head/tail lines, 16 KiB | No timeout; 64 KiB; 120 s idle kills the turn (§6.4) | Medium |
| Sandbox | bwrap / Seatbelt / WSL, network off, `.git` read-only, per-call escalation | None; `BashEscapeDenyHook` DORMANT (§6.4) | Large |
| Permission default | **confirm** | **bypass-permissions** (§9.5) | **Big** |
| TUI approval prompt in agent loop | Yes (ACP `request_permission`; auto-resolve on settings change) | **ABSENT**: Ask becomes deny (§0.2) | **Big** |
| Argument-aware rules | Regex on input; shell sub-command parse; deny/confirm/allow lists | Tool-name only (§9.5) | **Big** |
| "Always allow this pattern" persisted | Yes, written to settings | ABSENT | Medium |
| Hard-coded `rm` guard | Sub-command and path-normalised | `rm -rf /` / `~` breaker (§9.5) | Small |
| Policy-file protection | Always prompt on `.zed/`, `.agents/skills/` | `.sugar-crush/{hooks.yaml,config.json,agents/}` only | Small–medium |
| Profiles | Write / Ask / Minimal + custom, per thread, per-MCP-tool, per-profile model | Global `allowedTools` / `disabledTools`; permission modes | Medium |
| Untrusted-project downgrade | Auto Minimal; skills hidden | Trust lists for hooks / MCP / commands / settings | Comparable intent |
| Sub-agents | `spawn_agent`, parallel, follow-up via `session_id`, partial output on error, context-limit stop, linked action log | Task, parallel, resume only after failure, final text only (§2.2) | Medium |
| Sibling threads / worktrees | `create_thread`, optional new git worktree | `/bg` (no history), WorktreeManager DORMANT (§2.4, §2.6) | Medium |
| File checkpoints / restore | Shadow-git checkpoint per user message, restore button | `/rewind` transcript only (§8) | **Big** |
| Review hunks Keep/Reject | Multibuffer, per hunk, undo, auto-keep on commit | Diff gutter display only (§10) | Large |
| ACP | Client for any agent; native agent behind the same trait | ABSENT, no serve/ACP mode (§8) | Large (strategic) |
| MCP | stdio + HTTP + OAuth; tools, prompts, resources, list_changed | stdio + http + OAuth; **tools only**; no SSE (§9.4) | Medium |
| Skills | Global/project, 50 KB catalog budget, trust-gated, `@skill` | Tiers + foreign import, Skill tool (§9.1) | Comparable |
| Memory | None (AGENTS.md + skills) | MemoryStore LIVE, project scope in prompt (§5) | sugar-crush ahead |
| Hooks | None for native | PreToolUse / PostToolUse / UserPromptSubmit LIVE (§9.3) | sugar-crush ahead |
| Ask-user tool | `ask_user` (off by default) | ABSENT | Small |
| Plan / todo | Only from ACP agents (`plan` update) | ABSENT | — |
| Notifications | Desktop, sound, turn stats | ABSENT (§10) | Small |
| Thread storage | SQLite + zstd JSON; import other agents' sessions | SQLite session store, checkpoints, transcripts (§8) | Comparable |

---

## 13. Recommended improvements for sugar-crush

Ordered by priority. "Wire" means connect existing, unreached code.

### P0

#### R1. Interactive approval over the existing fork socket, then flip the default from `bypass-permissions`

**Why.** Today any Ask on the TUI path is a silent denial (`Runtime::settleAsk`, `src/Runtime.php:2629-2648`). That forces the unsafe default (`Bootstrap.php:166`). Zed's default is `"confirm"`, and its whole UX assumes a prompt can reach the user mid-tool.

**How Zed does it.**
- A tool calls `event_stream.authorize(...)`, which sends `ThreadEvent::ToolCallAuthorization{options, response: oneshot}` to the UI and awaits the oneshot (`thread.rs:5876-5896`, `:6576-6690`).
- The options are once / always-tool / always-pattern / deny. "Always" choices are persisted (`:6697-6800`).
- A pending prompt auto-resolves if settings change.

**How in sugar-crush.**
1. The plumbing exists.
   - `stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM…)` (`EngineBackend.php:1343`) is **bidirectional**, and the parent keeps `$parentSocket` open as a non-blocking read stream.
   - `EngineBackend::withPermissionApprover(\Closure)` (`:540`) already feeds `Runtime::run(..., $this->permissionApprover, ...)` (`:866`).
   - `Chat::requestPermission()` (`src/Chat.php:2666`) already renders the y/n/a Veil modal.
2. In `runCompleteInChild()`, attach an approver closure that:
   - writes a `permission` frame (tool name, `asAsked` arguments, question);
   - then **blocks reading** a length-prefixed reply frame from the child socket.
3. In the parent frame reader (`EngineBackend.php:1742-1796`):
   - route `permission` frames into the shared inbox as a new event (e.g. `PermissionRequested` carrying a reply callback that `fwrite`s the reply frame to `$parentSocket`);
   - make `ToolEventPumpMsg` handling in Chat open the modal and call the callback on y/n/a.
4. **Pause the 120 s `COMPLETE_TIMEOUT_SECONDS` watchdog** (`:99`, `:1444-1455`) while a request is outstanding. Otherwise a slow human kills the turn.
5. Add Zed's "always for `<cmd> <subcmd>`" option by appending `{pattern, action: allow}` to user-tier `permissionRules`. This needs R10's argument-aware rules to be meaningful.
6. Once green, change the built-in default to `default` (or `accept-edits`) in `Bootstrap.php:166`.

**Effort:** M.

#### R2. Replay tool calls structurally across turns

**Why.** Every later turn currently sees earlier tool output as plain assistant prose (`EngineBackend::toTypedMessages`, `:2071-2082`). The model cannot tell which call produced which text, and some providers degrade without real tool pairing.

**How Zed does it.** `AgentMessage::to_request` (`thread.rs:676-741`):
- emits `ToolUse` only when a result exists;
- puts results in the following user message;
- replaces empty results with `"<Tool returned an empty string>"` ("Surprisingly, the API fails if we return an empty string here");
- gives unanswered calls `"Tool canceled by user"` (`:4075-4109`).

**How in sugar-crush.**
- Tool rows already carry `withToolResults([$result])` (`Chat.php:4017-4023`), and `ToolResult` has `name`, `id` and `arguments` (`src/ToolResult.php:100-111`).
- Rewrite `toTypedMessages()` to coalesce consecutive tool rows into one `AssistantMessage(toolCalls: …)` followed by `ToolResultMessage`s. Apply the empty-result and canceled-call rules.
- Let `HistorySanitizer` handle orphans.
- Optionally persist `completeTranscript()`'s typed transcript (`EngineBackend.php:755`) instead of reconstructing it.
- Keep a size guard: cap replayed outputs older than N turns, since this raises token use.

**Effort:** S–M.

#### R3. Compact at step boundaries, from provider-reported usage, with a handoff summary

**Why.** A single sugar-crush turn grows without bound until the provider rejects it (baseline §3.3). Zed checks before **every** request (`thread.rs:2803-2876`).

**How Zed does it.**
- Threshold = 90% of `min(max_input, max_total − max_output)` (`:4706-4731`), using the **last request's** `input + cache + output`.
- Summarise with the handoff prompt quoted in §4.
- Rebuild the history as `[system, last ≤ 80 KB of user messages verbatim, "The previous conversation was compacted. Use this summary as context:\n\n…", tail]` (`:4925-4992`).
- Skip it for windows under 80k.

**How in sugar-crush.**
- In `EngineBackend::runTurn()` (`:776-1024`), before each `Runtime::run()`, read the previous step's `Usage` (already folded per step) and compare it with `ContextWindow::ofBackend()`.
- If it is over the threshold, call the existing tool-less summary backend (`Bootstrap::summaryBackend()`) with a Zed-style handoff prompt. That prompt is a better fit mid-turn than `COMPACT_SUMMARY_PROMPT`'s per-exchange records, because a mid-turn compaction has no exchange boundaries.
- Splice the summary into the typed `$messages`.
- Emit a `compacting` frame so Chat can show progress.
- Reuse `ContextCompactor` for the retained-user-messages budget.

**Effort:** M.

#### R4. Shadow-git file checkpoints, so `/rewind` restores files

**Why.** `/rewind` restores only the transcript (baseline §8). Its reply even tells users to `/branch` first. Zed's checkpoint-per-message plus Restore is the most user-visible safety feature in the product.

**How Zed does it.** `RealGitRepository::checkpoint` / `restore_checkpoint` (`crates/git/src/repository.rs:3063-3117`) uses a temp index, `add --update`, untracked files < 2 MB minus a binary/archive ignore list, `write-tree`, `commit-tree -p HEAD`, and restores with `git restore --source <sha> --worktree .`. It is taken before each user message (`acp_thread.rs:5724`) and shown only if it changed (`:6191`).

**How in sugar-crush.**
1. Add a `git_checkpoint` column to `checkpoints` (`src/Session/EnhancedSessionStore.php:162-170`, `saveCheckpoint` `:469`).
2. In `Chat::dispatchTurn()` (where the per-turn checkpoint is saved, `:7964-7993`), run the same git sequence through `proc_open` with `GIT_INDEX_FILE=<tmp>`.
3. **Improve on Zed:** pin each sha with `git update-ref refs/sugar-crush/checkpoints/<session>/<n> <sha>` so `git gc` cannot prune it. Delete the ref when the store prunes checkpoints (max 100 per session already).
4. Make `/rewind [n]` offer "transcript + files", and only offer the file restore if `git diff --quiet <sha>` shows a change.

The dormant `GitMcpServer` / `GitCommandHandlers` (`src/MCP/GitMcpServer.php`) have git plumbing worth reusing.

**Effort:** M.

### P1

#### R5. Upgrade `Edit` to Zed's multi-edit, fuzzy, re-indenting, staleness-aware edit

**Why.** Exact unique match is the most common cause of failed agent edits, especially whitespace drift and models dropping the first line's indent. Zed's error texts tell the model exactly what to do next.

**How Zed does it.** `edit_session.rs` and `streaming_fuzzy_matcher.rs`:
- costs 1 / 3 / 10;
- per-line normalised Levenshtein ≥ 0.8 on trimmed lines;
- ≥ 80% aligned lines;
- exact match first;
- ambiguous → line numbers;
- re-indent deltas (`reindent.rs`);
- mtime staleness note;
- partial-failure diff (§7).

**How in sugar-crush.**
- `src/Tools/BuiltIn/Edit.php`: accept `edits: [{old_string,new_string}]` while keeping the single form for compatibility. Apply sequentially to an in-memory copy and write once.
- New `src/Tools/Concerns/FuzzyLocator.php`. PHP's `levenshtein()` is limited to 255 bytes per string in older PHP, so use `similar_text` or a small DP for long lines.
- Record a read mtime in the session state that `Read` already carries across the fork (`CarriesSessionState`). Append Zed's "file has changed on disk since you last read it" to no-match errors.
- Return the applied diff on partial failure. `BuildsUnifiedDiff` exists.

**Effort:** M.

#### R6. `Read` with `start_line` / `end_line`, `cat -n` numbering, and an outline for large files

**How Zed does it.** `read_file_tool.rs` and `outline.rs:10-90`: files > 16 KB with no range return `# File outline for {path}` with symbol line numbers, else the first 1 KB. The description says *"Do NOT retry reading the same file without line numbers if you receive an outline."*

**How in sugar-crush.**
- Extend `src/Tools/BuiltIn/Read.php` with the range parameters and numbering.
- For the outline, **wire the dormant LSP client** (`src/LSP/LspClient.php`, `documentSymbol` via `LspConnection` symbols). Fall back to a regex outline (class/function/method signatures) when no server is configured.
- Tell Edit's description to strip the prefix, as Zed does.

**Effort:** S (ranges) + M (outline/LSP).

#### R7. Steering at the step boundary

**How Zed does it.** `end_turn_at_next_boundary` (`thread.rs:2346`, `:3140-3146`) plus the queue's Steer toggle (`thread_view.rs:2522-2537`).

**How in sugar-crush.**
- Give the queue entries in `Chat::enqueuePrompt()` (`:7533`) a steer flag, bound to a key such as Ctrl+Enter-while-busy.
- When it is set, the parent writes a `steer` frame to the child socket (the same bidirectional channel as R1).
- `EngineBackend::runTurn()` polls the socket non-blockingly after each step's tool results. If steer is set, it ends the turn normally.
- `releaseQueuedPrompts()` (`:7778`) then sends the queued prompt. Because tool results are kept, the model sees them plus the new instruction.

**Effort:** S–M (after R1).

#### R8. Retry after a partial stream with "Continue where you left off"

**How Zed does it.** `thread.rs:3112-3136`, `Message::Resume` `:254-260`, `retry_strategy_for` `:4601-4651`.

**How in sugar-crush.**
- In `Runtime::runStreaming()` (`src/Runtime.php:1324-1445`), when a transient failure happens after tokens were emitted:
  1. keep the partial `AssistantMessage`;
  2. append `UserMessage("Continue where you left off")`;
  3. retry, up to `TransientFailure::MAX_ATTEMPTS`.
- Also honour provider `retry-after` and add jitter.

**Effort:** S.

#### R9. Bash: `timeout_ms`, `head_lines` / `tail_lines`, and heartbeats while sequential tools run

**Why.** A silent command running over 120 s kills the whole turn (baseline §6.4). Zed has model-chosen timeouts and a prompt line telling the model to set them.

**How in sugar-crush.**
- In `src/Tools/BuiltIn/Bash.php` plus `Tools/Concerns/CapturesProcessOutput.php`: add the timeout (kill the process group, since `setsid` is already used) and the line selection.
- In `Runtime::executeSequentially()` (`:1748`), emit a heartbeat (`onHeartbeat`) every few seconds while a tool runs.
- Lower the model-visible output cap to about 16–32 KiB when head/tail are not given.

**Effort:** S.

#### R10. Argument-aware permission rules with shell sub-command parsing

**How Zed does it.** See §10. Deny on ANY sub-command, confirm on ANY, allow only if ALL match. Disable allow rules on parse failure. Refuse `$(...)`, `$VAR` and backticks in commands that a confirm or allow rule protects.

**How in sugar-crush.**
- `src/Permissions/PermissionGate.php`: today `permissionRules` match the tool name only, and `Bash(rm *)` never matches (baseline §9.5).
- Implement `Tool(pattern)` matching against the command or path.
- Add a small POSIX splitter for `;`, `&&`, `||`, `|` and subshells that fails closed.
- Reuse it to make preset `tools: ['Bash(git *)']` real (`AgentManager::resolveGrantedTools`, baseline §2.1).

**Effort:** M.

#### R11. @-mentions in the input → a `<context>` block

**How Zed does it.** `MentionUri` (`acp_thread/src/mention.rs:20-77`), packaged by `UserMessage::to_request` (`thread.rs:329-588`) under "The following items were attached by the user. They are up-to-date and don't need to be re-read."

**How in sugar-crush.**
- Add `@` completion to the `TextArea` popup (the `/` popup machinery exists): files via `Glob`, `@diff` (`git diff`), `@session:<id>` (title plus an LLM summary via the title/summary backend), `@url`.
- Resolve the mentions at submit time, reusing the containment-checked `@file` code from `CommandLoader`.
- Put them in a fenced `<context>` block in the user message.
- The dormant `Message::attachFile()` / `UserMessage::withFile()` (`src/Message.php:247-267`) are the natural carriers — this wires them.

**Effort:** M.

#### R12. Tool profiles (Write / Ask / Minimal) with `/profile`

**How Zed does it.** `default.json:1284-1343`, `agent_profile.rs:104-150`:
- tools re-evaluated every step (`thread.rs:2878-2893`);
- per-MCP-tool toggles;
- per-profile `default_model`;
- downgrade to Minimal on untrusted roots.

**How in sugar-crush.**
- Add a `profiles` key to `Config/LayeredSettings` with a user-tier allowed list.
- `/profile <name>` swaps the tool set used by `Bootstrap::filterToolSet()`.
- Rebuild the tools per turn in `EngineBackend::turnTools()` (`:1049-1087`).
- Map "Ask" onto the existing `plan` permission mode for consistency.

**Effort:** M.

#### R13. Sub-agent upgrades

**How Zed does it.** `spawn_agent_tool.rs`, `agent.rs:3533-3650`, `thread.rs:4654-4696`.

**How in sugar-crush** (`src/Tools/BuiltIn/TaskTool.php`):
1. Return a `session_id` on **success** too, persisting via `SuspendedDelegations` (today only failed or interrupted runs), so the parent can send short follow-ups.
2. On failure, return the last 3 messages × 4096 characters of partial output, not just the error.
3. Stop a sub-agent whose own usage crosses about 80–90% of the window, with Zed's "wrap up or hand off" message.
4. Wire the dormant preset `model` field on the engine path (baseline §2.1), or add a `subagentModel` config key, as Zed's `subagent_model` does.
5. Cap parallel Task fan-out using the unused `AgentPoolConfig::maxConcurrent=5`.

**Effort:** S–M.

#### R14. Provider-reported context fill plus a transparent status segment

**How Zed does it.** `accumulate_token_usage` (`thread.rs:2354`), the ring plus tooltip (`thread_view.rs:4931`).

**How in sugar-crush.**
- Use the last step's `prompt_tokens + completion_tokens`, which is already parsed, as the "context fill". Keep chars/4 only as a pre-first-request estimate.
- In the status bar show `▰▰▱ 62% · 81k/131k · $0.42`.
- Make `/context` (or a click) list the loaded instruction files, rules, memory entry count and skill count. The tooltip of what is loaded is very effective for trust.

**Effort:** S.

### P2

#### R15. ACP agent mode: `sugarcrush acp`

**Why.** It puts sugar-crush inside Zed, JetBrains, Neovim and similar editors' agent panels with no UI work, and those editors provide checkpoints and review for free.

**How in sugar-crush.**
- A new `Cli\Subcommands` entry running a synchronous stdio JSON-RPC loop. Reuse `SugarCraft\Mcp\McpMessage` for framing.
- Map `initialize` (advertise `loadSession: true`, `promptCapabilities{embeddedContext:true}`).
- `session/new` / `load` / `list` → `EnhancedSessionStore`.
- `session/prompt` → `EngineBackend::complete()` with callbacks translated into `session/update` (`agent_message_chunk`, `agent_thought_chunk`, `tool_call{kind, status, locations}`, `tool_call_update{content: diff{path,oldText,newText}}`).
- `session/request_permission` via `withPermissionApprover`.
- `session/cancel` → `CancellationToken`.
- Optionally route Read/Write through the client's `fs/read_text_file` / `write_text_file` when advertised, so the editor's unsaved buffers are respected.

**Effort:** L.

#### R16. ACP client for Claude Code / Gemini

**Why.** It would replace `ClaudeCodeProvider`'s `claude -p` shell-out, in which Claude Code's tools bypass sugar-crush's hooks and gate. Driving `claude-agent-acp` would put sugar-crush's gate in front of Claude Code's `request_permission` and `fs/*`.

**How in sugar-crush.** Reuse `sugar-mcp`'s `StdioMcpServer` for process and stdio management.

**Effort:** L.

#### R17. Agent-edit review with per-hunk Keep/Reject

**How Zed does it.** `ActionLog` (`crates/action_log`) plus `AgentDiffPane`.

**How in sugar-crush.**
1. A PHP `ActionLog` that snapshots each file on its first agent write in a turn. `ToolResult::$diff` already flows to Chat.
2. A `/review` pane listing hunks (the `DiffGutter` renderer exists), with k/r per hunk, where r reverse-applies that hunk.
3. "Reject all" = restore the snapshot.
4. Auto-clear hunks that `git commit` absorbed.

This pairs naturally with R4.

**Effort:** L.

#### R18. Optional bubblewrap sandbox for Bash on Linux

**How Zed does it.** `linux_bubblewrap.rs:248-350`; prompt section `system_prompt.hbs:156-212`.

**How in sugar-crush.**
- When `bwrap` exists and `sandbox: true`, prefix the `ProcessContainment` command with: `--ro-bind / / --dev /dev --proc /proc --tmpfs /tmp --bind <root> <root> --ro-bind <root>/.git <root>/.git --unshare-all --die-with-parent [--share-net]`.
- Add `unsandboxed: true` as an Ask-gated escape (needs R1).
- Describe it in the `<env>` block.
- Wire the dormant `BashEscapeDenyHook` as the non-bwrap fallback.

**Effort:** M–L.

#### R19. Rules-file aliases plus a personal AGENTS.md

**How in sugar-crush.**
- In `src/Context/InstructionFileLoader.php`, also probe `.rules`, `.cursorrules`, `.windsurfrules`, `.clinerules`, `.github/copilot-instructions.md`, `AGENT.md` and `GEMINI.md` (Zed's list, `prompts.rs:22-32`). Load them at least when neither CLAUDE.md nor AGENTS.md exists.
- Load `~/.sugar-crush/AGENTS.md` as a personal tier rendered *before* project instructions with Zed's precedence sentence ("Project-specific rules below may override them").

**Effort:** S.

#### R20. Protect every policy surface

**Why.** `ProtectFilesHook::WRITE_ONLY_PATTERNS` (`src/Hooks/BuiltIn/ProtectFilesHook.php:83-86`) covers only `.sugar-crush/{hooks.yaml,config.json}` and `agents/`. These are left writable:
- `~/.sugar-crush/settings.json` and `<root>/.sugar-crush/settings{,.local}.json` — the user tier holds `permissionMode`, `permissionRules` and all `trustedProject*` keys;
- `.mcp.json`;
- `.sugar-crush/{skills,commands,rules}/`.

The model can therefore use Bash or Write to self-grant trust for an MCP server or command it also writes.

**How Zed does it.** `.zed/` and `.agents/skills/` always prompt (`tools/tool_permissions.rs:287-340`).

**How in sugar-crush.** Extend the patterns. Once R1 exists, turn these into an always-Ask instead of a deny.

**Effort:** S.

#### R21. Small items

| Item | What to do | Effort |
|---|---|---|
| Turn-done / approval-waiting notification | BEL plus OSC 9 / OSC 777 when the terminal is unfocused (focus reporting is available via candy-core); Zed has `notify_when_agent_waiting` / `play_sound_when_agent_done` | S |
| `ask_user` tool | Question plus options, over the R1 channel | S |
| `private_files`-style read denial | Default `**/.env*, *.pem, *.key, *.crt, secrets.yml` | S |
| Skill catalog budget | 50 KB, with a user-visible notice for dropped skills | S |
| Hard-coded `rm` breaker | Extend to `.`, `..`, `$HOME`, flags after operands, and path-normalised `rm -rf /tmp/../../` (`tool_permissions.rs:25-160`) | S |
| Refusal fallback model config | | S |

---

## 14. Problems in sugar-crush exposed by this comparison

1. **The unsafe default exists because approval is missing.**
   - Zed ships `tool_permissions.default = "confirm"` and asks.
   - sugar-crush ships `bypass-permissions` (`Bootstrap.php:166`) because its TUI loop literally cannot ask (`Runtime::settleAsk`, `:2629-2648`).
   - Every stricter mode silently turns Asks into denials. That is the worst combination: unsafe by default, and confusing when hardened.
2. **The 120 s no-frame watchdog conflicts with both long commands and any future approval wait.**
   - Sequential tools send no heartbeat, so a silent `composer install` over 120 s kills the turn (baseline §6.4).
   - Zed instead bounds commands with `timeout_ms`, chosen by the model and announced in the prompt.
   - R1 must suspend the watchdog during approval.
3. **`maxSteps = 8` is far too low for agentic work.**
   - Zed has no step cap. sugar-crush truncates after 8 steps and returns only the last assistant content (`EngineBackend.php:262`, `:1017-1023`). With no resume, the user must re-prompt and the model loses its in-turn tool transcript (see 4).
   - Recommendation: raise the default to 50+ (sub-agents already default to 50), and on truncation keep the typed transcript so "continue" resumes.
4. **Cross-turn history is lossy (baseline §0.3) and compounds with point 3.** After a truncated turn, the next turn sees the tool outputs as unlabeled assistant prose. Zed's history is always valid structured tool_use/tool_result pairs, including a synthetic `"Tool canceled by user"` for interrupted calls.
5. **Context management happens at the wrong granularity.**
   - Compaction only at submit (baseline §3.3) means one long turn can overflow. The chars/4 estimate ignores the system prompt and tool schemas (§3.2), so the 85% trigger fires late.
   - Zed uses provider-reported usage and checks before every request. sugar-crush already parses provider usage per step, so the data is there.
6. **No retry after the first streamed token.**
   - A dropped SGLang stream mid-answer is a hard failure in sugar-crush (baseline §1.3).
   - Zed keeps the partial and continues — cheap and high value with a self-hosted SGLang endpoint.
7. **`/rewind` is a trap.** It looks like undo but leaves files changed (baseline §8). Zed's equivalent restores files and only offers it when files changed.
8. **The edit tool is brittle and stale-blind.**
   - Exact unique match only, no staleness check, and `Write overwrite:true` can clobber user edits made since the last read.
   - Zed detects both on-disk changes (mtime versus last read) and the user's unsaved changes (it prompts).
9. **Policy files are writable by the agent.** `settings.json` (both tiers), `.mcp.json` and the skills / commands / rules dirs are unprotected (R20). Combined with the bypass default, an injected instruction in a fetched page could persist into future sessions.
10. **Unbounded parallel Task fan-out.**
    - Each Task forks a child with no cap (baseline §2.2).
    - Zed is also uncapped, but its tasks are async futures, and it drops the rate-limit permit before tool execution so sub-agents cannot deadlock on it (`thread.rs:3019-3024`).
    - In sugar-crush, N forked PHP children each hold a provider connection. `AgentPoolConfig::maxConcurrent=5` exists but is unused for Task.
11. **The system prompt is not byte-stable.**
    - `Runtime` is rebuilt every turn, and `<env>` with git status and diffs is re-rendered every step (baseline §3.5, §4 slot 11).
    - Zed deliberately keeps the system prompt identical across requests and pushes volatile state into tool calls or mentions.
    - sugar-crush's `<env>` is last, so the prefix cache survives up to it. But because it sits in the *system* message, every following message is uncached on providers that cache only by prefix. Consider moving per-step git state into a trailing user-side `<env>` note rather than the system block, or at least rendering it only on the first step of a turn.

### Zed patterns *not* to copy

- **`Thread::summary()` drops lines.** It keeps only the first line of each streamed chunk (`summary.extend(lines.next())`, `thread.rs:3949-3953`). That truncates the "detailed Markdown summary" used for `@thread` and new-thread-from-summary. It is a title-style parser reused for multi-line text.
- **Mismatched tags.** The `<rules>` … `</user_rules>` mismatch in mention packaging (`thread.rs:542-547`).
- **Unpinned checkpoint commits.** No ref holds them, so `git gc` can prune them (inference). R4 pins them.
- **No doom-loop or step guard at all.** sugar-crush's spend cap plus a higher step cap is a better middle ground.
- **A thin system prompt with no git status and no file map.** It costs extra discovery tool calls. sugar-crush's richer `<env>` and repo map are an advantage worth keeping (outside PHP, generalise the repo map — baseline §11.1 #15).
