# 06 — OpenHands vs sugar-crush

Feeds steps: 0.4-a, 0.4-b, 0.5, 0.6, 0.10, 0.11, 0.12, 0.13-a, 1.A-1, 1.A-2, 1.B-1, 1.B-2, 1.C-1, 1.C-2, 1.C-3, 2.1, 2.2-1, 2.4-1, 2.5, 2.7-1b, 2.8, 2.10, 3.C, 3.D-1, 3.D-2, 3.D-3, 3.I-1, 4.1-1, 4.1-2, 4.2, 4.7-1, 4.10-1, 4.10-2, 5.1-1, 5.1-2, 5.7-1, 5.10, 5.11-2, 5.14b, 5.14j

**Sources** (under `/home/sites/crush-research-repos/`): **SDK** = `software-agent-sdk/openhands-sdk/openhands/sdk/` (V1 agent core); **TOOLS** = `software-agent-sdk/openhands-tools/openhands/tools/`; **V0** = `OpenHands-legacy-0.62/openhands/` (legacy monolith: condenser zoo, `AgentDelegateAction`); **CLI** = `OpenHands-CLI/openhands_cli/` (Textual TUI). sugar-crush symbols are under `/home/sites/sugarcraft/sugar-crush/`; current line anchors are in `impact/*.md`.

---

## 2. Agent loop

### 2.1 The run loop (V1)

`LocalConversation._run()` (`SDK/conversation/impl/local_conversation.py:1915-2089`), per iteration under a FIFO state lock:
- if `FINISHED`, run the **Stop hooks**. If a hook denies stopping, append its feedback as an environment-sourced user message, set `RUNNING` and `continue` (`:1952-1973`). (→ 3.D-2)
- clear `WAITING_FOR_CONFIRMATION` (a second `run()` call counts as implicit approval); `agent.step()`;
- after the step: break on `WAITING_FOR_CONFIRMATION`; break with `MaxBudgetReached` if `max_budget_per_run` is crossed (summed across *all* LLMs, condenser included, `:718-732`); break with `MaxIterationsReached` at `max_iteration_per_run`, default 500 (`:219`).
- The comment at `:2005-2013` explains why `FINISHED` is not checked right after the step: "This allows concurrent user messages to be processed… send_message() waits for FIFO lock, then sets status to IDLE… Run loop continues to next iteration and processes the message." (→ 1.C-3)

### 2.2 One step (`Agent._step`, `SDK/agent/agent.py:689-879`)

1. **Pending actions first** (→ 1.C-1, 1.C-2). If the active branch has `ActionEvent`s with no observation (they were left pending for confirmation), execute them and return (`:697-706`).
2. **Blocked user message.** If a `UserPromptSubmit` hook blocked the last user message, mark `FINISHED` (`:708-716`).
3. `prepare_llm_messages(state.view, condenser=…, llm=…)` (`:732`). If the condenser returns a `Condensation`, emit it and **return**: that step is spent condensing, and the next step uses the new view. (→ 2.1, 2.4-1)
4. `llm.generate(messages, tools, add_security_risk_prediction=True, on_token=…)`.
5. Error recovery:

| Exception | Reaction (line) |
|---|---|
| `FunctionCallValidationError` (malformed call) | Emit the error text as a **user message**, return; the loop continues (`:780-790`) |
| `LLMContentPolicyViolationError` | Emit user message *"Your previous response was blocked by the model's content filter. Please continue, rephrasing to avoid the flagged content."* (`:791-812`) |
| `LLMMalformedConversationHistoryError` (e.g. broken tool pairing) | `state.rebuild_view()` + emit `CondensationRequest`, return (`:813-840`) |
| `LLMContextWindowExceedError` | Emit `CondensationRequest`, return; the next step does a hard condensation (`:841-855`) (→ 2.7-1b) |

6. `classify_response()` (`SDK/agent/response_dispatch.py:47-68`) maps the reply to exactly one of `TOOL_CALLS | CONTENT | REASONING_ONLY | EMPTY` (→ 0.10).
   - `CONTENT` → emit the message, `FINISHED`.
   - `REASONING_ONLY`/`EMPTY` → emit, then the **corrective nudge** (`:364-389`): *"Your last response did not include a function call or a message. Please use a tool to proceed with the task."*
   - `TOOL_CALLS` → build `ActionEvent`s, then the confirmation check, then execution.

**Interrupt.** After an interrupt, `_emit_orphaned_action_errors()` (`:2687-2715`) backfills every unmatched `ActionEvent` with an `AgentErrorEvent`: *"Tool call interrupted before completion. The conversation was paused."* This keeps the provider-required tool pairing valid. (→ 1.B-2)

**Mid-turn steering** (→ 1.C-3). `send_message()` (`:1805-1867`) takes the same FIFO state lock. In async `arun()` the lock is **released during the network wait** (`_released_state_lock_during_io`, `:1875-1898`), so a message lands between steps and the next LLM call sees it. A `sender` field tags multi-agent senders.

**Side questions** (→ 5.14b). `ask_agent(question)` (`:2857-2937`) makes a **stateless** LLM call: a snapshot of the current view plus a wrapped question, with the agent's tools passed so the history parses. It is thread-safe while `run()` is executing and records nothing. Template (`SDK/context/prompts/templates/ask_agent_template.j2`):

```
<QUESTION>
Based on the activity so far answer the following question
## Question
{{ question }}
<IMPORTANT>
This is a question, do not make any tool call and just answer my question.
</IMPORTANT>
</QUESTION>
```

**Framework-injected user-role messages** (→ 1.B-1, 0.10): the stuck nudge, the empty-response nudge, malformed-call errors, the content-filter nudge, Stop-hook feedback, critic follow-ups and `/goal` follow-ups are all `MessageEvent(source="environment")`, so the UI can tell them apart from the human.

---

## 3. Agents and sub-agents

### 3.1 Planning agent (→ 5.7-1)

`TOOLS/preset/planning.py` with the `PLANNING` prompt preset (`SDK/context/prompts/sections/planning.py`). It is read-only plus `planning_file_editor`, which can only write the plan file. Its prompt opens: *"You are a Planning Agent that analyzes codebases and helps the user make a detailed plan for their requested changes."* It includes `<IMPORTANT_PRINCIPLES>` ("Don't make large assumptions about user intent", "Ask clarifying questions when needed", "Professional objectivity") and a phased `<PLANNING_WORKFLOW>`.

### 3.2 File-based agent definitions (`SDK/subagent/`, documented in `SDK/subagent/AGENTS.md`) (→ 4.1-1, 4.1-2)

**Frontmatter keys**, all effective:

| Key | Behaviour |
|---|---|
| `name`, `description` | `<example>…</example>` tags in the description become `when_to_use_examples` |
| `tools` | Names, validated at factory time |
| `skills` | Project beats user; an unknown skill raises |
| `model` | `inherit`, or an **LLM profile name** loaded from `profile_store_dir` |
| `max_iteration_per_run` | Positive int |
| `max_budget_per_run` | USD |
| `hooks` | Hook config |
| `mcp_config` | MCP servers |
| `permission_mode` | `always_confirm|never_confirm|confirm_risky`; omitted = inherit the parent's policy |
| `condenser` | Omitted = default summarizing condenser; `none` = off; mapping = configured |

- Unknown keys are kept in `metadata`.
- The body becomes `AgentContext(system_message_suffix=…)`: it is **appended to** the parent system prompt, not a replacement.

### 3.3 TaskToolSet — the V1 sub-agent tool (`TOOLS/task/`)

**Schema** (`definition.py`): `description` (3-5 words), `prompt`, `subagent_type` (default `general-purpose`), `resume` (task id). The generated description includes *"each delegation has overhead — use them when the task genuinely benefits from a separate agent, not for simple lookups"* and *"Tell the agent what to report back (file paths, line numbers, code snippets)"*.

**Execution** (`TOOLS/task/manager.py`):
1. `start_task` (`:164`) → `_create_task` (`:246`) or `_resume_task` (`:203`).
2. **Each task is a full `LocalConversation`.** It gets:
   - its own condenser and stuck detector;
   - `max_iteration_per_run` = the definition's value, else the **parent's**;
   - `max_budget_per_run` = the definition's value, else the parent's;
   - the definition's hooks;
   - `prompt_cache_key=str(parent.state.id)` (`:329`), so children share the parent's provider cache shard (→ 0.13-a);
   - persistence under `<parent>/subagents/` (or a temp dir).
3. The LLM is a `model_copy` of the parent's with `stream=False` and **reset metrics** (`:357-370`).
4. `_run_task` (`:384-410`):
   - `send_message(prompt, sender=parent_name)`;
   - `_run_until_finished()` — loops while the child is `WAITING_FOR_CONFIRMATION`, calling the parent-supplied **`confirmation_handler(task_id, pending_actions)`**, then re-running or `reject_pending_actions()` (→ 4.1-2, 1.C-2);
   - result = `get_agent_final_response()` (the final text or finish message).
5. A non-`FINISHED` stop (iteration limit, stuck, paused) becomes an **error carrying the partial result**: *"{reason}\nPartial result:\n{partial}"* (`_run_stop_detail`, `:412-428`). (→ 4.7-1)
6. Metrics roll up into the parent's `usage_to_metrics`. The child conversation is evicted (paused and closed), but its event log persists, so `resume` can rebuild it from disk by conversation id — successful runs included. (→ 4.7-1)

**DelegateTool** (`TOOLS/delegate/impl.py`): `spawn(ids, agent_types)` creates long-lived named children (`max_children=5`); `delegate(tasks={id: task})` sends follow-up tasks to the **existing** child, keeping its context (multi-round parent→child). Sub-agent metrics are *replaced*, not merged, into `delegate:{id}` to avoid double-counting. (→ 4.7-1)

### 3.4 Model-authored orchestration (→ 4.10-1, 4.10-2)

**WorkflowTool** (`TOOLS/workflow/definition.py:54-…`). The model writes Python:
  ```python
  async def main(wf):
      plans = await wf.map_agents(items=…, subagent_type=…, max_concurrency=3, prompt=lambda s: …)
  ```
  - API: `wf.run_agent`, `wf.map_agents`, `wf.reduce_agent`, `wf.pipeline` (per-item stages with no barrier) and `wf.flatten`.
  - The script runs in a restricted sandbox: private `wf` attributes are rejected and the script may not touch files or shell. Sub-agents do the work.
  - Intended use: "codebase-wide audits, independent plan reviews, security sweeps… where intermediate results should stay outside the main conversation."

For sugar-crush, take the shape (map/reduce/pipeline over agent stages, bounded concurrency) but accept a declarative YAML/JSON plan, not arbitrary code.

---

## 4. Context handling and compaction

### 4.1 Token counting and window (→ 2.1)

- `get_total_token_count(events, llm)` (`SDK/context/condenser/utils.py:8-50`) converts the view to messages and calls `llm.get_token_count(messages, tools=…, add_security_risk_prediction=…)`. That is LiteLLM's tokenizer, and it **includes tool schemas**.
- The window is `llm.effective_max_input_tokens`, from model info and route-aware runtime metadata resolved before the first step (`agent.py:727-729`).
- `get_suffix_length_for_token_reduction` binary-searches the shortest prefix whose removal frees enough tokens (`utils.py:53-173`).
- **Per-message cap:** `LLM.max_message_chars = 30_000` ("Approx max chars in each event/content sent to the LLM", `llm.py:391-396`).

### 4.2 The production condenser — `LLMSummarizingCondenser` (V1) (→ 2.1, 2.4-1, 2.5, 2.7-1b)

Defaults:
- Class: `max_size=240` events, `keep_first=2`, `minimum_progress=0.1`, `hard_context_reset_max_retries=5`, `hard_context_reset_context_scaling=0.8` (`llm_summarizing_condenser.py:63-87`).
- Factory used by the default agent **and every sub-agent**: `max_size=80, keep_first=4` (`:554-562`).

**Triggers** (`get_condensation_reasons`, `:136-173`):

| Reason | Condition | Requirement |
|---|---|---|
| `REQUEST` | An unhandled `CondensationRequest` is in the view (user `/condense`, context-exceeded error, malformed history) | **HARD** |
| `TOKENS` | Token count > min(condenser `max_tokens`, agent `effective_max_input_tokens`) | **HARD** |
| `EVENTS` | `len(view) > max_size` | SOFT |

**What is forgotten** (`_get_forgotten_events`, `:278-352`):
- `REQUEST` keeps half the view; `EVENTS` keeps `max_size//2`; `TOKENS` keeps the longest suffix that brings tokens under **half** the limit. The strictest wins.
- The leading `SystemPromptEvent` and `keep_first` events are protected.
- Start and end are snapped to the next **manipulation index**: the intersection of allowed cut points from four view properties (`SDK/context/view/properties/`) (→ 2.4-1):
  - `tool_call_matching` — never orphan a call or result;
  - `batch_atomicity` — parallel calls from one response stay together;
  - `observation_uniqueness`;
  - `tool_loop_atomicity` — "Anthropic models with thinking enabled… expect the first element of such a tool loop to have a thinking block… if we remove any element of the tool loop we have to remove the whole thing".
- If fewer than 10% of events would go, or none can, it raises `NoCondensationAvailableException`. **SOFT** → use the uncondensed view and retry next step. **HARD** → `hard_context_reset()`: summarize *everything* after the system prompt, and on failure shrink each event string by 20% and retry, up to 5 times (`:354-405`). (The base class logic is `SDK/context/condenser/base.py:159-198`.) (→ 2.7-1b)

**The summarization prompt** (→ 2.5) is sent as *system* (`prompts/summarizing_system.j2`) + *user* (`prompts/summarizing_events.j2`), with `store=False` and streaming disabled. System template, verbatim:

```
You are maintaining a context-aware state summary for an interactive agent.
You will be given a list of events corresponding to actions taken by the agent, which will include previous summaries.
If the events being summarized contain ANY task-tracking, you MUST include a TASK_TRACKING section to maintain continuity.
When referencing tasks make sure to preserve exact task IDs and statuses.

Track:

USER_CONTEXT: (Preserve essential user requirements, goals, and clarifications in concise form)

TASK_TRACKING: {Active tasks, their IDs and statuses - PRESERVE TASK IDs}

COMPLETED: (Tasks completed so far, with brief results)
PENDING: (Tasks that still need to be done)
CURRENT_STATE: (Current variables, data structures, or relevant state)

For code-specific tasks, also include:
CODE_STATE: {File paths, function signatures, data structures}
TESTS: {Failing cases, error messages, outputs}
CHANGES: {Code edits, variable updates}
DEPS: {Dependencies, imports, external calls}
VERSION_CONTROL_STATUS: {Repository state, current branch, PR status, commit history}

PRIORITIZE:
1. Adapt tracking format to match the actual task type
2. Capture key user requirements and goals
3. Distinguish between completed and pending tasks
4. Keep all sections concise and relevant

SKIP: Tracking irrelevant details for the current task type

Example formats:
For code tasks:
USER_CONTEXT: Fix FITS card float representation issue
COMPLETED: Modified mod_float() in card.py, all tests passing
PENDING: Create PR, update documentation
CODE_STATE: mod_float() in card.py updated
TESTS: test_format() passed
CHANGES: str(val) replaces f"{val:.16G}"
DEPS: None modified
VERSION_CONTROL_STATUS: Branch: fix-float-precision, Latest commit: a1b2c3d
For other tasks: … (haiku example)
```

The user template wraps each event in `<EVENT>…</EVENT>` and ends with "Now summarize the events using the rules above."

**Where the summary goes** (→ 1.B-2, 2.5).
- A `Condensation(forgotten_event_ids, summary, summary_offset)` tombstone is appended (`SDK/event/condenser.py:11-96`); nothing is deleted from the event log.
- `View.append_event` applies it, and the summary is re-materialised as a `CondensationSummaryEvent` with id `"{condensation.id}-summary"`, rendered as a **user-role** message (`:120-132`).
- Earlier summaries sit inside the forgotten range, so they are re-summarised: rolling summary-of-summaries.

**Rationale** (`SDK/context/condenser/README.md`) (→ 2.10): "replacing the first half of all events with a single summary event… condensation destroys the prompt cache, but doing so regularly keeps the cost of rebuilding the prompt cache low."

### 4.3 V0 condensers worth reusing (`V0/memory/condenser/impl/`)

| Condenser | Mechanism | Defaults |
|---|---|---|
| `ObservationMaskingCondenser` (→ 2.2-1) | Replaces every **Observation** older than the window with `AgentCondensationObservation('<MASKED>')`; actions stay | `attention_window=100` (config; class default 5) |
| `StructuredSummaryCondenser` (→ 2.5) | Forces a **tool call** into a `StateSummary` schema with fields `user_context, completed_tasks, pending_tasks, current_state, files_modified, function_changes, data_structures, tests_written, tests_passing, failing_tests, error_messages, branch_created, branch_name, commits_made, pr_created, pr_status, dependencies, other_relevant_context` | `max_size=100` |
| `ConversationWindowCondenser` (V0 default) (→ 2.7-1b) | On request (context exceeded), keeps the system message, first user message and recall observation, plus roughly the newer half, preserving action/observation pairs | — |

### 4.4 Tool-output truncation (→ 2.8, 0.5)

`maybe_truncate()` (`SDK/utils/truncate.py:50-117`) keeps head + tail. When a `save_dir` is given, it writes the **full content to `{tool}_output_{sha8}.txt`** (deduplicated by hash) and inserts:

> `<response clipped><NOTE>Due to the max output limit, only part of the full response has been shown to you. The complete output has been saved to {file_path} - you can use other tools to view the full content (truncated part starts around line {line_num}).</NOTE>`

| Limit | Value |
|---|---|
| Terminal | `MAX_CMD_OUTPUT_SIZE = 30000`; full output saved to `conv_state.env_observation_persistence_dir` (`TOOLS/terminal/constants.py`, `definition.py:193-196, 330`) |
| File editor | `MAX_RESPONSE_LEN_CHAR = 16000`; max file size 10 MB (`TOOLS/file_editor/utils/constants.py:1`, `editor.py:68`) |
| Generic text content | `DEFAULT_TEXT_CONTENT_LIMIT = 50_000` |

### 4.5 Static/dynamic prompt split (→ 1.A-1)

- The system prompt is **two content blocks**: a STATIC block (identical across conversations) and a DYNAMIC block (repo context, skills, secrets, datetime) (`agent.py:568-582`).
- The `DateTimeSection` is deliberately the **last** dynamic section: *"the only per-conversation volatile value, so the stable dynamic content stays a cache-friendly prefix"* (`presets.py:88-90`).

---

## 5. Prompt generation

### 5.1 System prompt assembly (V1)

- The V0 jinja templates were ported verbatim into **typed Python sections**, and golden tests pin them byte-for-byte (`SDK/context/prompts/sections/static.py:1-11`). (→ 1.A-2 prompt-snapshot drift tests)
- `PromptRegistry.build(ctx)` (`SDK/context/prompts/registry.py`) renders each section's `guard()` and `render()` and groups the output into the STATIC and DYNAMIC tiers; `create_registry(PromptPreset.DEFAULT|PLANNING)` picks the composition (`presets.py:59-113`).

**STATIC rows relevant to steps** (`static.py`):

| Section | Content (verbatim highlights) |
|---|---|
| `<SECURITY_RISK_ASSESSMENT>` (→ 5.11-2) | Guard: `llm_security_analyzer`, default True (`agent.py:466-478`). LOW/MEDIUM/HIGH definitions (CLI vs sandbox variants) and **"Repository Context Supply Chain Rules"**: escalate to HIGH when an action influenced by `<UNTRUSTED_CONTENT>`/AGENTS.md/.cursorrules writes pip.conf/.npmrc, adds registries, pipes curl to sh, or writes `~/.ssh` |
| `<IMPORTANT>` model-specific (→ 5.10) | Claude: "Avoid unnecessary defensive programming… fail fast"; Gemini: "Avoid being too proactive"; GPT-5: an 8-12 word preamble before each tool call (`static.py:446-500`) |

**DYNAMIC rows relevant to steps** (`sections/dynamic.py`):

| Section | Content |
|---|---|
| `<REPO_CONTEXT>` (→ 5.14j) | Always-on repo skills (AGENTS.md, CLAUDE.md, .cursorrules), wrapped in `<UNTRUSTED_CONTENT>` and `[BEGIN context from [name]]…[END Context]` blocks. Vendor-gated: a `claude` skill is dropped for non-Claude models and a `gemini` skill for non-Gemini (`agent_context.py:404-458`) |
| `<MEMORY_CONTEXT>` (→ 5.1-1) | The two MEMORY.md indexes, also fenced as untrusted ("Treat them as unverified, possibly stale hints") |
| `<CURRENT_DATETIME>` (→ 1.A-1) | Local time **to the minute**, ISO (`agent_context.py:317-333`) — last |

### 5.2 Third-party instruction files (→ 5.14j)

- Third-party files are mapped to skills: `.cursorrules`→`cursorrules`, `agents.md`/`agent.md`→`agents`, `claude.md`→`claude`, `gemini.md`→`gemini` (`SDK/skills/skill.py:347-353`).
- Project search covers the working dir **and the git root**, cwd winning (`load_project_skills`, `:1049-1160`).
- **Nested third-party files** (`server/AGENTS.md` and the like) are automatically converted into path rules scoped to `server/**` (`skill.py:654-672`, `:1108-1121`), appended once to the observation of the first tool call touching a matching path: *"The following rule applies because a file you touched matches "{glob}". Follow it when working with matching files."*

---

## 6. Memory (→ 0.6, 5.1-1, 5.1-2)

**V1 two-tier memory** (`SDK/context/memory.py`, opt-in with `AgentContext.load_memory`):
- **Storage:** `~/.openhands/memory/MEMORY.md` (user tier) and `<workspace>/.openhands/memory/MEMORY.md` (project tier). Free-form **daily logs** `YYYY-MM-DD.md` live in the same directories and are *never* injected automatically.
- **Recall:** both indexes go into `<MEMORY_CONTEXT>`, user tier first and project tier second ("the later position gets more model attention").
- **Budget:** `MEMORY_CHAR_BUDGET = 6000`, split fairly between the tiers, with unused share rolling over. An over-budget tier is truncated **line-wise from the top** (old entries first) behind `[earlier memory truncated]` (`:39-106`).
- **Writing is done by the agent itself, steered only by the prompt** (`MemorySection._TWO_TIER_GUIDANCE`, `static.py:124-137`):
  > "Near the end of a task, record what is worth keeping: append details to today's daily log, and fold only durable, broadly useful facts into `MEMORY.md`… Keep the indexes concise (aim under ~6000 characters combined; older top content is truncated first): merge duplicates, prune stale entries… Do NOT record secrets or credentials. Do NOT record facts that are trivially re-discoverable… Record what was expensive to learn: root causes, environment quirks, user preferences, decisions and their reasons. `AGENTS.md` remains the place for instructions addressed to any agent working in this repository; memory is for what you learned yourself."

---

## 7. Tools and editing

### 7.1 Roster rows relevant to steps

| Tool | Where | Notes |
|---|---|---|
| `file_editor` (→ 0.12) | `TOOLS/file_editor/` | `view` (numbered `cat -n` lines, `view_range=[a,b]` or `[a,-1]`, directory listing two levels deep), `create`, `str_replace`, `insert` (after line N), `undo_edit` (per-file history, 10 deep, `editor.py:88`) |
| `planning_file_editor` (→ 5.7-1) | `TOOLS/planning_file_editor/` | Plan-file-only writer for the planning agent |
| `task_tracker` (→ 3.C) | `TOOLS/task_tracker/definition.py` | `view` / `plan` over `[{title, notes, status: todo|in_progress|done}]`, persisted to `TASKS.json` in the conversation dir (`:234-263`). Long "use / don't use" guidance with scenarios. Shown in the CLI's **plan side panel** |

### 7.2 Editing semantics (`TOOLS/file_editor/editor.py:178-280`) (→ 0.11, 3.I-1)

- `str_replace` requires a literal, unique match. On zero matches it **retries with `old_str.strip()`**; `new_str` is not stripped, so intentional whitespace survives. On several matches the error lists their **line numbers**: *"Multiple occurrences of old_str … in lines [12, 40]. Please ensure it is unique."*
- On success the model sees a **numbered snippet** of the edited region (±`SNIPPET_CONTEXT_WINDOW` lines) and the instruction *"Review the changes and make sure they are as expected. Edit the file again if necessary."*

### 7.3 Terminal timeouts (`TOOLS/terminal/`) (→ 0.4-a, 0.4-b)

- **Soft timeout.** After `NO_CHANGE_TIMEOUT_SECONDS = 30` with no new output, the command keeps running and the observation returns `exit_code=-1` with:
  > "You may wait longer to see additional output by sending empty command '', send other commands to interact with the current process, send keys ("C-c", "C-z", "C-d") to interrupt/kill the previous command before sending your new command, or use the timeout parameter in terminal for future commands."

  (The tool *description* says "10 seconds" while the constant is 30 — keep doc and constant in sync.)
- **Hard timeout:** the `timeout` parameter. On managed runtimes it is capped at 90% of `OH_RUNTIME_IDLE_TIMEOUT_SECONDS`, and longer requests are refused with advice to background the job (`timeout_policy.py`).
- Long-running jobs: the description says *"run them in the background and redirect output to a file, e.g. `python3 app.py > server.log 2>&1 &`"*.

---

## 9. Hooks (→ 3.D-1, 3.D-2)

- **Events:** `PreToolUse`, `PostToolUse`, `UserPromptSubmit`, `SessionStart`, `SessionEnd`, `Stop` (`SDK/hooks/types.py`).
- `command` hooks: a shell script with JSON on stdin. Exit code 2 = block; JSON stdout fields `decision`, `reason`, `additionalContext`, `continue`; Claude-Code-compatible.
- A Stop-hook deny re-enters the loop with feedback (§2.1). A blocked `PreToolUse` becomes a `UserRejectObservation(rejection_source="hook")` (`agent.py:351-378`).
- **Pitfall (don't copy):** the hook loader picks up `<workspace>/.openhands/hooks.json` with no trust check (`SDK/hooks/config.py:284-296`), so a cloned repo can ship hooks that run commands. Keep sugar-crush's project-trust gating.

---

## 10. Permissions and safety

**Confirmation as a resumable pause** (→ 1.C-1, 1.C-2; the alternative design noted in 1.C).

| Policy | Behaviour |
|---|---|
| `AlwaysConfirm` | Ask for everything |
| `NeverConfirm` | Ask for nothing |
| `ConfirmRisky(threshold=HIGH, confirm_unknown=True)` | Ask when the risk is at or above the threshold, or UNKNOWN |

**Decision** (`Agent._requires_user_confirmation`, `agent.py:1113-1154`):
- A lone `finish` or `think` never asks.
- Otherwise each pending action is scored by `state.security_analyzer` (or UNKNOWN when there is none). If *any* action needs confirmation, the status becomes `WAITING_FOR_CONFIRMATION` and the step returns **with the actions recorded but unexecuted**.
- The next `run()` executes them (implicit approval), or the client calls `reject_pending_actions(reason)` to write `UserRejectObservation`s (`local_conversation.py:2647-2685`).
- This needs no live channel back into a running process; it is all state in the event log.

**Analyzers** (→ 5.11-2):
- `LLMSecurityAnalyzer` trusts the model's own `security_risk` argument (`llm_analyzer.py`); every non-read-only tool schema gets a `security_risk` (LOW/MEDIUM/HIGH) property, popped before validation (`agent.py:1156-1181`). A read-only tool, or a run with no analyzer, means UNKNOWN.
- `PatternSecurityAnalyzer` (`defense_in_depth/pattern.py`) uses ReDoS-bounded regexes with stable detector ids (`exec.destruct.rm_rf`, `exec.net.curl_pipe_exec`, `inject.override`, …). It scans two corpora: executable arguments for destructive and exec patterns, and *all* fields, thought included, for injection patterns.
- `EnsembleSecurityAnalyzer` takes the max severity. A child that raises contributes **HIGH (fail closed)** (`ensemble.py`).
- CLI modes: confirm by default, `--always-approve`/`--yolo`, `--llm-approve` (LLM analyzer + ConfirmRisky).

## 11. UX (→ 1.C-2, 1.C-3)

- **Inline confirmation panel** with Accept / Reject / Always proceed / "Confirm risky only" (`CLI/tui/panels/confirmation_panel.py:123-140`).
- **Input during a run is injected, not queued:** `ConversationRunner.queue_message()` calls `conversation.send_message()` on a worker thread while the run continues (`CLI/tui/core/conversation_runner.py:95-108`).

---

## 13. Recommended improvements for sugar-crush

**Empty / reasoning-only reply nudge** (→ 0.10). In `EngineBackend::runTurn()`, when `$assistant` has no tool calls and blank content (or reasoning only), append the "did not include a function call or a message" user message once and `continue` instead of ending; tag it as a framework (environment) row.

**Interactive approval as a resumable stop** (→ 1.C-1, 1.C-2). Two options:
- **(a) Resumable stop.** In `Runtime::settleAsk()`, when no approver is attached *and* the run is an interactive TUI turn:
  1. do not deny; return a `PendingApproval` carrying the unexecuted call(s);
  2. in `runTurn()`, stop the loop and return them in the `result` frame;
  3. route the pending engine calls into Chat's existing Veil y/n/a modal (`Chat::requestPermission`);
  4. on an answer, dispatch a continuation turn whose `runTurn` executes (or rejects with a synthesized `ToolResultMessage` error) the pending calls **before** the first provider call, mirroring `_execute_actions(pending)`.

  The structured in-turn transcript must be carried over for this (needs 1.B-2).
- **(b) Blocking ask** over the full-duplex fork socket (the 1.C default): the child sends an `ask` frame and blocks for the answer; the parent's 120 s no-frame watchdog is paused while a modal is open.

**Mid-turn steering through the duplex socket** (→ 1.C-3).
1. In `runTurn()`, at the top of each step, do a non-blocking read of `steer` frames (length-prefixed like the existing frames).
2. Append each frame as a `UserMessage` to `$app` before `Runtime::run()`.
3. In the parent, `EngineBackend` gets `steer(string $text)`, which writes to `$parentSocket`.
4. `Chat::submit()` calls it instead of `enqueuePrompt()` when a turn is in flight. Keep the queue as the fallback when pcntl is unavailable.
5. Show steered prompts as user rows immediately.

**Structured cross-turn history** (→ 1.B-2). Extend `toTypedMessages()` to emit `AssistantMessage(toolCalls)` + `ToolResultMessage(callId, …)` pairs for rows that carry tool results; group consecutive result rows under one assistant tool-call message and run them through `Messages\HistorySanitizer::sanitize()`. Persist the call arguments on the row.

**Condense between steps, and recover from context-exceeded mid-turn** (→ 2.1, 2.4-1, 2.5, 2.7-1b).
1. Inside `runTurn()`, estimate the step's prompt including the system prompt and tool schemas.
2. Above threshold, summarise the oldest half of *this turn's* messages (keeping the system message and the first user message). Use a state-oriented prompt that adds OpenHands' `TASK_TRACKING`/`CODE_STATE`/`TESTS`/`VERSION_CONTROL_STATUS` sections to the existing six facets.
3. Snap the cut to a boundary where no `ToolResultMessage` is orphaned and parallel batches stay together.
4. Classify provider context-length errors and trigger the same path once; on repeated failure shrink each event string by 20% and retry (bounded).

**Save truncated tool output to a file and point at it** (→ 2.8, 0.5, 0.12). In `Tools/Concerns/TruncatesOutput.php`, write the full output to a session-scoped file `<tool>_<sha8>.txt` and include the path and first-elided line in the marker. Apply the same truncation to MCP results. `Read` needs `offset`/`limit` so the model can page through the saved file.

**Read with line numbers + `view_range`; Edit returns a numbered snippet** (→ 0.12, 0.11).
- `Read.php`: optional `offset`/`limit` and `cat -n`-style numbering.
- `Edit.php`: return a numbered snippet of the edited region in the *model-visible* result; add the line numbers of every match to the "multiple occurrences" error; strip-retry `old_string` on zero matches (uniqueness still required).

**Bash timeout + heartbeat** (→ 0.4-a, 0.4-b). Add a `timeout` parameter (default 120 s) to `Bash.php`; emit heartbeats while sequential tools run so the `EngineBackend` watchdog does not fire.

**Todo tool + plan panel** (→ 3.C). A `Todo` tool over `SessionMeta::$tasks` (not the team `TaskList`) with `task_tracker`-style use / don't-use guidance; render it in a dock pane; add a TASK_TRACKING rule (preserve exact ids and statuses) to the compaction summary prompt.

**Make sub-agent preset fields real** (→ 4.1-1, 4.1-2, 4.2).
- When `preset.model` ≠ `inherit`, build the engine with that model; iterations/budget fall back to the **parent's** values when unset.
- Honour `permissionMode` by attaching a per-sub-agent `PermissionGate`; child approvals bubble to the parent UI (OpenHands' `confirmation_handler`).
- Enforce argument-scoped grants on the live Task path (`refuseCallOutsideGrant`).

**Dispatch the `Stop` hook with a veto + feedback** (→ 3.D-2). In `runTurn()`, when the model answers without tools, run `HookEvent::Stop`. On deny, append the hook's reason as a user message and continue (bounded).

**Agent-maintained memory convention + user-tier recall** (→ 0.6, 5.1-1, 5.1-2).
- Add a static prompt section with OpenHands' "record what was expensive to learn / don't record secrets or re-discoverable facts" guidance.
- Extend `MemoryBlock::capture()` to include **user** scope, user first and project last, with a shared budget and top-truncation of the oldest entries.
- Fix `/memory add` defaulting to a scope that never reaches the prompt.

**Further items:**

| Step | Idea |
|---|---|
| 5.14b | **`/btw` side question.** Use `ask_agent`'s template (§2.2) on the title backend with a snapshot of history while a turn runs; nothing is recorded |
| 3.D-3 | **`/goal <objective>`** judge loop. Port `SDK/conversation/goal/prompts.py` (JUDGE_SYSTEM_PROMPT demands strict JSON `{score, complete, missing}` and treats "merely-claimed-but-unverified evidence as NOT satisfied"). After each turn, re-dispatch `FOLLOWUP_PROMPT` until complete or N rounds (OpenHands caps at 10) |
| 5.14j | **Nested third-party instruction files as path rules + `.cursorrules`/`GEMINI.md`** (`skill.py:347-353`) |
| 4.7-1 | **Resume successful runs too**: `SuspendedDelegations` stores only failures today; OpenHands keeps every child's log resumable by id |
| 4.10-2 | **Model-invokable workflow tool.** Expose `WorkflowEngine` stages as a tool taking a YAML/JSON plan (map/reduce/pipeline), not arbitrary code, reusing `AgentWorkerPool` and `EngineExecutor` |
| 5.11-2 | **`security_risk` self-assessment** on write-capable tool schemas, fed into `PermissionGate` `auto` mode alongside `SafetyClassifier` (max-severity, fail closed); add the repo-context supply-chain rules (pip.conf, .npmrc, curl\|sh, `~/.ssh` → HIGH) |
| 2.2-1 | **Observation masking for old tool rows** (V0 `ObservationMaskingCondenser`); replace the no-op `ContextCompactor::removeToolResults()` |
