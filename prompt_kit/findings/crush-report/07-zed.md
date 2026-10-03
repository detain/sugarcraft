# 07 — Zed's AI agent panel vs sugar-crush

Feeds steps: 0.3, 0.4-a, 0.4-b, 0.8, 0.8b, 0.11, 0.12, 0.14-c, 0.16, 1.A-1, 1.A-2, 1.B-2, 1.C-1, 1.C-2, 1.C-3, 1.C-5, DEF-MODE, 2.1, 2.4-1, 2.4-2, 2.5, 2.7-1a, 2.7-3, 2.8, 2.9, 3.A-1, 3.A-2, 3.F, 3.G, 3.I-1, 3.I-2, 3.I-3, 4.1-1, 4.2, 4.7-1, 4.7-2, 4.7-3, 4.9, 5.6, 5.7-2, 5.8, 5.9-1, 5.9-2, 5.10, 5.12, 5.13b, 5.14a, 5.14c, 5.14j, N-P4b, P-B2, P-E2

**Competitor:** Zed (zed-industries/zed), the agent panel and its native agent. Rust/GPUI.
**Clone:** `/home/sites/crush-research-repos/zed` @ `20d29fc6b` (2026-10-01). Paths below are relative to that root. The native agent lives in `crates/agent`, the UI in `crates/agent_ui`, the ACP host side in `crates/acp_thread` + `crates/agent_servers`. The system prompt is `crates/agent/src/templates/system_prompt.hbs`.

---

## 1. Agent loop: retries, cancellation, steering

### Retries (→ 2.7-1a, 2.7-3, 5.13b)

`handle_completion_error` (`crates/agent/src/thread.rs:3372`), `retry_strategy_for` (`:4601`). `MAX_RETRY_ATTEMPTS = 4`, `BASE_RETRY_DELAY = 5s` (`:170-171`), with jitter.

| Error class | Retry strategy |
|---|---|
| Provider rejection that is transient | Honours `retry_after` (`FixedDelay`), else exponential backoff |
| Provider rejection that is permanent (content policy, auth) | Never retried |
| HTTP send / read / deserialise errors | 3 attempts × 5 s |
| `StreamEndedUnexpectedly` / serialise errors | 1 attempt |
| `Other` (mid-stream mapping) | 2 attempts |
| `NoApiKey`, `ModelUnavailable`, `DataRetentionConsentRequired` | Never retried |

- **A partial response survives a retry.** `flush_pending_message` keeps the partial assistant text. If the last agent message had no tool results, a `Message::Resume` is pushed (`:3128-3136`), rendered as a user message **"Continue where you left off"** (`:254-260`). A dropped stream continues rather than restarting.
- **Prompt too large.** `ProviderErrorCategory::PromptTooLarge` marks the usage indicator "Exceeded" by synthesising usage ≥ the context size (`mark_token_limit_exceeded`, `:2406`).
- **Refusal fallback.** On `StopReason::Refusal`, if the model declares `refusal_fallback_model_id()`, the turn switches to that model and continues, with a retry banner ("Safety filter triggered") (`:3027-3080`).
- UI: a retry banner shows attempt N/M, the countdown and the last error.

**For sugar-crush (R8 → 2.7-3).** In `Runtime::runStreaming()`, on a transient failure after tokens were emitted: keep the partial `AssistantMessage`, append `UserMessage("Continue where you left off")`, retry up to `TransientFailure::MAX_ATTEMPTS`. Also honour provider `retry-after` and add jitter.

### Cancellation (→ 1.B-2, 1.C-2)

- `Thread::cancel` (`:2323`) cancels **all running sub-agents first**, then the turn task, then flushes the partial message.
- Any tool call without a result gets `"Tool canceled by user"` (`flush_pending_message`, `:4075-4109`), so history stays a valid tool_use/tool_result pairing.
- **If the user sends a follow-up while a permission prompt is open**, the pending call is denied with `"Permission denied: user sent a follow-up message instead of approving the tool call."` (`:73-74`).

### Mid-turn steering (→ 1.C-3)

Each queue entry has a **Steer** toggle (`agent_ui/src/conversation_view/message_queue.rs:15,73-80`). If the *front* entry wants to steer, `set_end_turn_at_next_boundary(true)` is pushed into the native thread (`thread_view.rs:2528-2537`). The loop exits right after the current tool results are recorded (`thread.rs:3140-3146`), and the queued message is sent. The model sees its tool results plus the new instruction — a clean, protocol-valid interruption point. The queue UI also has an editable entry and "send now".

**For sugar-crush (R7).** Steer flag on queued prompts bound to a key while busy; the parent writes a `steer` frame to the child socket; `runTurn()` polls non-blockingly after each step's tool results and ends the turn (or injects) at that boundary; tool results are kept, so the model sees them plus the new instruction.

### Rate-limit permit (pitfall for 0.16 / RELAY)

`thread.rs:3019-3024` drops the stream before awaiting tools: *"Drop the stream to release the rate limit permit before tool execution… Without this, the permit would be held during potentially long-running tool execution, which could cause deadlocks when tools spawn subagents that need their own permits."* Any concurrency cap on Task fan-out must not be held by a parent while it waits on children.

---

## 2. Sub-agents (`spawn_agent`) (→ 0.16, 4.1-1, 4.7-1, 4.7-2, 4.7-3, 4.9, P-B2, P-E2)

`crates/agent/src/tools/spawn_agent_tool.rs`. **Input:** `label` (UI text), `message`, optional `session_id` (follow up an existing sub-agent), optional `model` (an exact id from `list_agents_and_models`).

**Tool description = mini orchestration guide** (`:15-45`), verbatim highlights:
- *"An agent does not see your conversation history. Include all relevant context…"*
- *"Do not use this tool for tasks you could accomplish directly with one or two tool calls."*
- *"For code-edit subtasks, decompose work so each delegated task has a disjoint write set."*
- *"When sending a follow-up using an existing agent session_id, the agent already has the context from the previous turn. Send only a short, direct message."*
- *"A resumed session keeps its existing model, so `model` cannot be combined with `session_id`."*

**Child construction** (`Thread::new_subagent`, `thread.rs:1342-1377`): shares the parent's project context (same system prompt), MCP registry and templates; inherits thinking, effort, summarisation model and profile; **model = `subagent_model` setting, else the parent's** (→ 4.1-1). Depth is fixed: `MAX_SUBAGENT_DEPTH = 1` (`thread.rs:77`); `spawn_agent` is added only when `depth() < MAX_SUBAGENT_DEPTH` (`:2235-2237`) (→ 4.7-3).

**Execution** (`NativeSubagentHandle::send`, `agent.rs:3550-3650`):
- Several `spawn_agent` calls in one message run in parallel, with no cap.
- **Context-limit guard (→ 4.7-2).** It subscribes to the child's `TokenUsageUpdated`. If the child's ratio crosses the warning band (80%, `TOKEN_USAGE_WARNING_THRESHOLD`, `acp_thread.rs:3220`) and auto-compaction is off for it, it cancels the child and returns: *"The agent is nearing the end of its context window and has been stopped. You can prompt the thread again to have the agent wrap up or hand off its work."*
- **Result:** only the child's last agent message text, as `{"session_id":…, "output":…}` (`spawn_agent_tool.rs:107-130`).
- **On error, partial output is salvaged (→ 4.7-1):** *"Partial subagent output (last 3 messages, up to 4096 characters each)"* (`subagent_partial_output_from_messages`, `thread.rs:4654-4696`; `agent.rs:3636-3644`).
- **Resume (→ 4.7-1):** the parent can call `spawn_agent` again with `session_id`, *whether or not the first run succeeded*.
- The user sees a sub-agent card per child (model-chosen `label` as the live title, expandable transcript) and a "subagents awaiting permission" banner (`thread_view.rs:10917-11311`, `:3754`); sub-agent permission prompts bubble up into the parent's panel (→ P-B2, P-E2, 1.C-5).

**Worktrees (→ 4.9).** `create_thread {use_new_worktree: true}` creates a linked git worktree (detached HEAD, optional `base_ref` / `worktree_name`); the worktree's git state is persisted on archive (`agent_ui/src/thread_worktree_archive.rs`).

**For sugar-crush (R13, `TaskTool`):**
1. Return a `session_id` on **success** too, persisted via `SuspendedDelegations`, so the parent can send short follow-ups (→ 4.7-1).
2. On failure, return the last 3 messages × 4096 characters of partial output (→ 4.7-1).
3. Stop a sub-agent whose own usage crosses about 80–90% of the window, with the "wrap up or hand off" message (→ 4.7-2).
4. Honour the preset `model` field / a `subagentModel` config key (→ 4.1-1).
5. Cap parallel Task fan-out with `AgentPoolConfig::maxConcurrent=5` (→ 0.16).

---

## 3. Token counting and compaction (→ 2.1, 2.9, 2.4-1, 2.4-2, 2.5, N-P4b)

### Token counting (→ 2.1)

- No local estimator; provider-reported usage per request.
- `accumulate_token_usage` takes the **max** of each streamed usage field within a request (providers send cumulative updates) and adds the delta to `cumulative_token_usage` (`thread.rs:2354-2386`).
- Usage is stored per user message (`request_token_usage[user_msg_id]`).
- "Context fill" = `input + cache_creation + cache_read + output` of the latest request (`total_input_tokens`, `:4698`; used at `:4520`).
- **Capacity** = `min(max_input_tokens, max_total_tokens − max_output_tokens)` (`compaction_input_capacity`, `:4706-4714`).

### When compaction runs (→ 2.1, 2.9, N-P4b)

`compaction_message_target_ix` (`thread.rs:4493-4543`). Skipped when auto-compaction is disabled, or the model's capacity is under `MIN_COMPACTION_CONTEXT_WINDOW = 80_000` (`:124`) — small models get a UI warning instead.

Configurable threshold (`default.json:1269-1281`, parser at `agent_settings.rs:181-206`):

```json
"auto_compact": { "enabled": true, "threshold": "90%" }
```

It accepts three forms:
- `"92.5%"` — a percentage;
- `100000` — compact after that many tokens are used;
- `-20000` — compact when fewer than that many tokens remain.

The check is **at the top of every loop iteration** (`run_turn_internal`, `:2804`), so a long tool-calling turn compacts *between steps*. A compaction that already happened after the last usage report is not repeated (`:4515-4519`).

### How compaction works (→ 2.4-1, 2.4-2, 2.5)

`build_compaction_request` (`:4561-4584`), `stream_compaction` (`:3218-3342`).

- Model = `LanguageModelRegistry::compaction_model()`, else the thread model.
- Request = the normal system prompt **rendered with zero tools** (the template's "no tools" branch), then history up to the insertion point, then the compaction prompt as a user message.
- The summary **streams into the UI** ("Compacting…" then a collapsible card; `ContextCompactionStatus::InProgress/Completed/Failed`). Failures retry through the normal retry machinery.
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

- **Storage.** `Message::Compaction(CompactionInfo::Summary)`. Auto compaction inserts it *before* a trailing unanswered user message; manual `/compact` appends an empty user marker plus the summary (`CompactionInsertion`, `:4858-4864`).
- **Next request contents** (`extend_request_history_until`, `:4925-4951`):
  1. the system prompt;
  2. **the most recent user messages before the compaction point, verbatim, newest-first up to `COMPACTION_RETAINED_USER_MESSAGES_BYTE_BUDGET = 80_000` bytes** (~20k tokens, `:127`), truncated at a char boundary, then re-ordered oldest-first (`retained_user_request_messages_before`, `:4959-4992`);
  3. the summary, as a user message: `"The previous conversation was compacted. Use this summary as context:\n\n{summary}"` (`:220-232`);
  4. everything after the compaction point.
- Everything else (assistant text, tool calls, tool results) is dropped.
- `CompactionInfo::ProviderNative { provider, items }` holds opaque provider-side compaction blobs (`:213-232`).

**For sugar-crush (R3 → 2.1, 2.4-1).** In `EngineBackend::runTurn()`, before each `Runtime::run()`, compare the previous step's provider `Usage` with the context window. Over threshold: summarise with a handoff-style prompt (a better fit mid-turn than the per-exchange records, since a mid-turn compaction has no exchange boundaries), splice into the typed `$messages`, emit a `compacting` frame for Chat, and reuse `ContextCompactor` for the retained-user-messages budget.

### "New thread from summary" (→ 5.14c)

- For small-window models the token-limit callout says *"To continue, run /compact or start a new thread and @-mention this one"* and offers **Start New Thread** (`NewNativeAgentThreadFromSummary`, `thread_view.rs:12313-12370`; `agent_panel.rs:3473-3518`).
- The new thread's initial content is a `ThreadSummary` mention of the old thread, resolved with `Thread::summary()` using `SUMMARIZE_THREAD_DETAILED_PROMPT`:

```
Generate a detailed summary of this conversation. Include:
1. A brief overview of what was discussed
2. Key facts or information discovered
3. Outcomes or conclusions reached
4. Any action items or next steps if any
Format it in Markdown with headings and bullet points.
```

- **Pitfall:** `Thread::summary()` keeps only the first line of each streamed chunk (`summary.extend(lines.next())`, `thread.rs:3949-3953`) — a title-style parser reused for multi-line text, which truncates the detailed summary. Do not reuse a title parser for the handoff summary.

### Tool-output limits at tool time (→ 0.4-a, 0.12, 2.8)

| Tool | Limit |
|---|---|
| terminal | 16 KiB to the model (`COMMAND_OUTPUT_LIMIT`, `terminal_tool.rs:24`), plus model-chosen `head_lines` / `tail_lines` |
| read_file | Files > 16 KiB (`AUTO_OUTLINE_SIZE`, `outline.rs:10`) without a line range return a **symbol outline with line numbers** instead of content, falling back to the first 1 KB when there is no outline |
| grep | 20 matches per page, 2 context lines, up to 10 lines of enclosing syntax ancestors (`grep_tool.rs:68,122-123`) |
| find_path | 50 per page |

---

## 4. Cache-stable system prompt (→ 1.A-1, 1.A-2)

- **The system prompt is kept byte-stable on purpose.** `maintain_project_context` (`agent.rs:1000-1082`) rebuilds on worktree, rules-file, skill and trust-state changes, but only replaces `ProjectContext` when it actually differs (`agent.rs:1046-1060`): *"an unchanged `ProjectContext` means a byte-identical system prompt and a continued hit on the model API's prompt cache."* The only per-request variable is the date at day granularity.
- No git status, file tree, open files, memory or diagnostics are injected automatically; volatile state arrives only through tool results or user @-mentions. There are no periodic system reminders.
- The last request message gets `cache: true` (`thread.rs:4434-4436`).

**For sugar-crush.** Keep the system message identical across steps and turns; move per-step git state out of the system block into a trailing user-side note (or render it only on the first step of a turn), so providers that cache by prefix keep every following message cached. sugar-crush's richer `<env>` / repo map is worth keeping — only its placement changes.

---

## 5. Prompt text worth reusing (→ 0.3, 0.4-a, 3.F, 3.G, 5.10, 5.12, 5.14j)

From `system_prompt.hbs` (tool-use branch):
- *"When running commands that may run indefinitely… specify `timeout_ms`… If a command times out, report that clearly and let the user decide whether to rerun it with a longer timeout."* (→ 0.4-a)
- *"Do not commit changes or create new git branches unless the user explicitly requests it."* (→ 0.3)
- *"Do not waste tokens by re-reading files after calling `write_file`, `edit_file`, or similar. The tool call will fail if it didn't work."* (→ 5.10)
- *"Keep going until the user's task is completely resolved before ending your turn…"*, *"Do not fix unrelated bugs or broken tests."*, *"Do not claim validation passed unless you actually ran it and saw it pass."*, *"Prioritize technical correctness over affirming the user's assumptions"*, *"If you infer something, label it as an inference"* (→ 5.10)
- Fixing Diagnostics: *"Make 1-2 focused attempts at fixing diagnostics you are likely able to resolve, then defer to the user… Never simplify or discard meaningful code just to silence diagnostics."* (→ 3.F)
- Terminal tool description: use `git --no-pager`, `GIT_EDITOR=true`, `PAGER=cat`; do not run servers or watchers (→ 0.3, 0.4-a).
- Sandbox section ends: *"These sandbox settings are guaranteed to remain in effect for the entire duration of this thread. If they ever change, you will be told."* (→ 5.12)
- Commit message generator (`crates/git_ui/src/commit_message_prompt.txt`, `git_panel.rs:4071-4114`) appends the personal AGENTS.md in `<rules>`, project rules in `<project_rules>`, a user `<commit_message_instructions>`, the subject typed so far, and the diff (→ 3.G).

### Personal AGENTS.md and rules-file aliases (→ 5.14j)

User's Custom Instructions section (`{{#if (or user_agents_md has_rules)}}`):
> "The following additional instructions are provided by the user and should be followed to the best of your ability without interfering with the tool use guidelines."
- `### Personal AGENTS.md` — *"These instructions apply to every project this user opens. Project-specific rules below may override them."* Body in a six-backtick fence.
- `### Project Rules` — *"These instructions are scoped to the current project. They take precedence over the personal AGENTS.md above when they conflict."* Then per worktree: `` `{{root_name}}/{{rules_file.path_in_worktree}}`: `` followed by the fenced text.
- A unit test pins the ordering (personal before project) (`templates.rs:120-159`).

Rules files: one per worktree root, **first match wins** (`crates/prompt_store/src/prompts.rs:22-32`; `agent.rs:1319-1362`):

```rust
pub const RULES_FILE_NAMES: &[&str] = &[
    ".rules", ".cursorrules", ".windsurfrules", ".clinerules",
    ".github/copilot-instructions.md", "AGENT.md", "AGENTS.md", "CLAUDE.md", "GEMINI.md",
];
```

Personal file: `~/.config/zed/AGENTS.md`, loaded into a file-watched global; read errors surface in the UI (`crates/agent_settings/src/user_agents_md.rs`).

**For sugar-crush (R19).** In the instruction loader also probe `.rules`, `.cursorrules`, `.windsurfrules`, `.clinerules`, `.github/copilot-instructions.md`, `AGENT.md`, `GEMINI.md` — at least when neither CLAUDE.md nor AGENTS.md exists. Load `~/.sugar-crush/AGENTS.md` as a personal tier rendered *before* project instructions with the precedence sentence above.

---

## 6. @-mention context block (→ 5.8)

Only `@diff`, `@session` and `@url` remain to build. Zed's packaging (`UserMessage::to_request`, `thread.rs:329-588`; `MentionUri`, `acp_thread/src/mention.rs:20-77`) replaces the mention text with a link and groups contents under:

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

- `@diff` → branch diff against a base ref; `@thread` → a summary of another thread (the detailed-summary prompt in §3); `@fetch` → URL contents converted to markdown (`html_to_markdown`, ≤ 20 redirects).
- Reuse the "up-to-date and don't need to be re-read" promise.
- **Pitfall:** the rules section opens `<rules>` but closes `</user_rules>` (`:542-547`). Keep tags matched.

**For sugar-crush (R11).** `@diff` (`git diff`), `@session:<id>` (title plus an LLM summary via the title/summary backend), `@url`; resolve at submit time beside the existing `@file` resolution.

---

## 7. Read, Edit and Terminal tools (→ 0.4-a, 0.11, 0.12, 0.14-c, 3.F, 3.I-1, 3.I-2, 3.I-3)

### `read_file` (→ 0.12, 3.F, 0.14-c)

- `path`, `start_line`, `end_line`. Output is `cat -n`-style (6-char right-aligned number + tab).
- Files > 16 KB without a range return `# File outline for {path}` with symbol line numbers, else the first 1 KB (`read_file_tool.rs`, `outline.rs:10-90`). Description: *"Do NOT retry reading the same file without line numbers if you receive an outline."*
- The `edit_file` description tells the model to strip `read_file`'s line-number prefix.
- Refuses paths matching `private_files` (default `**/.env*, **/*.pem, **/*.key, **/*.cert, **/*.crt, **/secrets.yml`, `default.json:498`).

**For sugar-crush (R6).** Range parameters and numbering in `Read.php`; for the outline, wire the dormant LSP client (`documentSymbol`) and fall back to a regex outline (class/function/method signatures) when no server is configured.

### `edit_file` pipeline (→ 0.11, 3.I-1, 3.I-2, 3.I-3)

`crates/agent/src/tools/edit_session.rs` plus `edit_session/{streaming_parser,streaming_fuzzy_matcher,reindent}.rs`.

- **Schema** (`edit_session.rs:46-61`): `edits:[{old_text,new_text}]`, applied sequentially. `old_text`: *"This will be matched using fuzzy matching to handle minor differences in whitespace or formatting. Be minimal with replacements…"*
- **Staleness (→ 3.I-2):** compare file mtime with the action log's `file_read_time`; if they differ, set `file_changed_since_last_read` (`:1028-1055`). Dirty (unsaved) buffers prompt Save / Discard / Keep (`resolve_dirty_buffer`, `:1066-1165`).
- **Fuzzy locator (→ 3.I-1)** (`StreamingFuzzyMatcher`, `streaming_fuzzy_matcher.rs`):
  - line-level DP alignment over the whole buffer, costs `REPLACEMENT_COST = 1`, `INSERTION_COST = 3`, `DELETION_COST = 10` (`:4-6`);
  - lines compared trimmed; `fuzzy_eq` accepts `strsim::normalized_levenshtein ≥ 0.8` after a cheap length-difference pre-check (`:358-369`);
  - a candidate needs ≥ 80% of its lines aligned (`matched_ratio >= 0.8`, `:243-246`);
  - an **exact** substring search runs first (`MAX_EXACT_MATCHES = 2`, enough to prove ambiguity), fuzzy as fallback (`finish`, `:96-130`).
- **Error texts (→ 0.11)** (`extract_match`, `:957-1005`):
  - No match: *"Could not find matching text for edit at index {i}. The old_text did not match any content in the file.{ The file has changed on disk since you last read it.} Please read the file again to get the current content."*
  - Ambiguous: *"Edit {i} matched multiple locations in the file at lines: 12, 88. Please provide more context in old_text to uniquely identify the location."*
- **Re-indent** (`reindent.rs`): compute the indent delta between the buffer line and the query's first line (tabs or spaces); compute a separate "rest" delta when the remaining lines agree (handles a model that stripped only the first line's indent); apply to `new_text`. An exact match starting mid-line uses delta 0.
- **Result:** on success the model gets only `"Edited {path} successfully"` (or "No edits were made."); the diff goes to the UI. **On partial failure** (edit #3 of 5 failed) the model gets the error **plus the diff of what was applied**: `"{error}\nEdited {path}:\n\n```diff\n…```"` (`Display for EditSessionOutput`, `:95-128`), so it knows the file state without re-reading.
- Edit evals: `crates/agent/src/tools/evals/edit_file.rs` runs real-model edits on fixture repos judged by an LLM (`templates/diff_judge.hbs`) — a model for evaluating the matcher chain.

**For sugar-crush (R5).** `Edit.php`: accept `edits: [{old_string,new_string}]` while keeping the single form; apply sequentially to an in-memory copy and write once. A `FuzzyLocator` concern for the per-line DP. Record a read mtime in the session state that rides `CarriesSessionState` and append the "changed on disk" sentence to no-match errors. Return the applied diff on partial failure (`BuildsUnifiedDiff` exists).

### Terminal (→ 0.4-a)

`terminal_tool.rs`: one-liner, `cd` param, `timeout_ms` (kill on timeout), `head_lines`, `tail_lines`, 16 KiB cap, fresh shell per call. **For sugar-crush (R9 → 0.4-a, 0.4-b):** Bash `timeout` + line selection (kill the process group), a heartbeat while sequential tools run so the 120 s watchdog measures silence, and a model-visible cap of ~16–32 KiB when head/tail are not given.

---

## 8. Checkpoints (→ 3.A-1, 3.A-2)

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
- **Restore:** `git restore --source <sha> --worktree .` (`:3091-3117`). Deliberately no `git clean`, because untracked large/binary files are not in the checkpoint.
- **When:** before sending each user message (`acp_thread.rs:5724-5735`). After the turn, `update_last_checkpoint_if_changed` compares with a fresh checkpoint (`compare_checkpoints`) and only *shows* "Restore Checkpoint" when the turn actually changed files (`:6191-6260`).
- `restore_checkpoint(message_id)` = cancel → rewind (truncate the thread at that message, kill its terminals, reject all agent edits) → git restore (`:6111-6143`). Transcript-only rewind exists too (`:6146-6189`).
- **Pitfall:** the checkpoint commits are not referenced by any ref, so `git gc` can prune them after `gc.pruneExpire` (inference).

**For sugar-crush (R4).** Run the same sequence with `GIT_INDEX_FILE=<tmp>` at the per-turn checkpoint, store the sha in the checkpoint row, **pin** it with `git update-ref refs/sugar-crush/checkpoints/<session>/<n> <sha>`, and delete the ref when the store prunes checkpoints (max 100 per session). `/rewind [n]` offers "transcript + files", and only offers the file restore if `git diff --quiet <sha>` shows a change.

---

## 9. Permissions and sandbox (→ 0.8, 0.8b, 1.C-1, 1.C-2, 4.2, DEF-MODE, 5.12)

### Argument-aware rules (→ 4.2, 1.C-2)

`ToolPermissionDecision::from_input`, `crates/agent/src/tool_permissions.rs:214-460`:
- Invalid user regex → deny the tool entirely.
- **Terminal input validation.** If the tool is not unconditionally allowed, commands containing `$VAR`, `${VAR}`, `$(...)`, backticks, `$((...))`, `<(...)` or `>(...)` are denied, with instructions to resolve the literals first. This keeps allow patterns sound.
- Per-tool regex rules against the tool input (command, path, URL), case-insensitive by default. **Terminal commands are parsed into sub-commands**, and `check_commands` (`:378-430`):
  - **deny** if ANY sub-command matches a deny pattern;
  - **confirm** if ANY matches a confirm pattern;
  - **allow** only if ALL sub-commands match an allow pattern.
  - If parsing fails or the shell is non-POSIX, **allow patterns are disabled** (fail closed).
- Precedence: `always_deny` > `always_confirm` > `always_allow` > tool `default` > global `default`. Out-of-the-box global default is `"confirm"` (`default.json:1234-1263`) (→ DEF-MODE).

### Prompt options (→ 1.C-2)

`build_permission_options`, `thread.rs:988-1178`:
- "Always for {tool}"; **"Always for `cargo test` commands"** — `extract_terminal_pattern` turns command + subcommand into `^cargo\s+test(\s|$)`; path-like commands (`./x`, `/bin/x`) are refused on purpose (`pattern_extraction.rs:55-90`).
- Path patterns for file tools, URL patterns for fetch; "Only this time".
- For pipelines, a per-sub-command dropdown.
- "Always" choices are written into settings (`persist_always_permission`, `:6753`).
- A pending prompt **auto-resolves if settings change** to a definitive allow or deny — approving "always" on one of several parallel prompts resolves the rest (`run_authorization_loop`, `:6576-6690`).
- Mechanism: the tool calls `event_stream.authorize(...)`, which sends `ToolCallAuthorization{options, response: oneshot}` to the UI and awaits it (`thread.rs:5876-5896`).

**For sugar-crush (R1 → 1.C-1, 1.C-2, DEF-MODE).** Child approver closure writes a permission frame and blocks on the reply; the parent routes it to the existing Veil y/n/a modal; **pause the 120 s watchdog** while a request is outstanding; add "always for `<cmd> <subcmd>`" by appending `{pattern, action: allow}` to user-tier `permissionRules`; once green, change the built-in default mode.

### Always-prompt paths (→ 0.8, 0.8b)

`authorize_always_prompt` covers symlink escapes out of the project and edits to sensitive settings: `.zed/` (local settings, which could grant permissions) and `.agents/skills/` (`tools/tool_permissions.rs:287-340`). These prompt even when `always_allow` would match.

**For sugar-crush (R20).** Still writable: `~/.sugar-crush/settings.json`, `<root>/.sugar-crush/settings{,.local}.json` (the user tier holds `permissionMode`, `permissionRules` and all `trustedProject*` keys), `.mcp.json`, `.sugar-crush/{skills,commands,rules}/`. The model could self-grant trust for an MCP server or command it also writes. Extend the protect patterns now; turn them into always-Ask once approvals exist.

### OS sandbox (→ 5.12)

`crates/sandbox`; policy in `crates/agent/src/sandboxing.rs`. Linux `build_bwrap_args_with_sandbox_paths` (`crates/sandbox/src/linux_bubblewrap.rs:248-350`):

```
--ro-bind / /   (or --bind if allow_fs_write)
--dev /dev --proc /proc --tmpfs /tmp
--bind <worktree> <worktree>...          (exact paths, pinned at capture time; no ancestor widening)
--ro-bind <.git dirs> ...                 (protected over the rw binds; "later binds win")
--unshare-user --unshare-ipc --unshare-uts --unshare-pid --unshare-cgroup-try --die-with-parent
--unshare-net                             (or bind a proxy socket for allow_hosts via HTTP proxy)
--chdir <cwd>
```

- An in-sandbox launcher verifies the bound inodes (`validate_binds`) and **fails closed** (TOCTOU symlink swaps).
- Per-call escalation flags: `allow_hosts[]` (HTTP/HTTPS via proxy), `allow_all_hosts`, `fs_write_paths[]`, `allow_fs_write_all`, `unsandboxed`. The user approves once, for the thread, or always.
- `.git` metadata is never writable inside the sandbox; git writes need `unsandboxed: true` "with a reason".
- The system prompt describes the sandbox exactly (`system_prompt.hbs:156-212`).

**For sugar-crush (R18).** When `bwrap` exists and `sandbox: true`, prefix the command with `--ro-bind / / --dev /dev --proc /proc --tmpfs /tmp --bind <root> <root> --ro-bind <root>/.git <root>/.git --unshare-all --die-with-parent [--share-net]`; `unsandboxed: true` as an Ask-gated escape; describe it in the prompt; the dormant `BashEscapeDenyHook` is the non-bwrap fallback.

---

## 10. ACP — Agent Client Protocol (→ 5.9-1, 5.9-2)

Crate `agent-client-protocol = "=2.2.0"` (features `unstable`, `unstable_protocol_v2`). JSON-RPC 2.0 over newline-delimited stdio (`crates/agent_servers/src/acp.rs`, `crates/acp_thread`).

**Client → agent:**
- `initialize`, carrying `ClientCapabilities` (`acp.rs:673-710`): `fs.read_text_file`, `fs.write_text_file`; `terminal: true`, `auth.terminal`; `session.config_options` (boolean), plus beta `compaction` and `notices`; `elicitation.form|url`; `_meta {terminal_output, terminal-auth}`
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

When an external agent writes through `fs/write_text_file`, Zed diffs against the buffer and records it in the same action log, so review and checkpoints work for every agent.

**For sugar-crush (R15, `sugarcrush acp`).**
- New subcommand running a synchronous stdio JSON-RPC loop; reuse `SugarCraft\Mcp\McpMessage` (`request/notification/success/error/parse/toJson`) for framing.
- `initialize`: advertise `loadSession: true`, `promptCapabilities{embeddedContext:true}`.
- `session/new` / `load` / `list` → `EnhancedSessionStore` (`loadTranscript`, `list`).
- `session/prompt` → `EngineBackend::complete()`; callbacks (token, reasoning, `ToolStarted`/`ToolFinished` with `ToolResult::$diff`, `$arguments`, `$description`, sub-agent events) become `session/update` (`agent_message_chunk`, `agent_thought_chunk`, `tool_call{kind, status, locations}`, `tool_call_update{content: diff{path,oldText,newText}}`).
- `session/request_permission` via `EngineBackend::withPermissionApprover(\Closure)` — the closure sends the request and blocks on the JSON-RPC reply; no fork boundary if `acp` mode runs the loop synchronously, as `-p` does.
- `session/cancel` → `CancellationToken`.
- Optionally route Read/Write through the client's `fs/read_text_file` / `write_text_file` when advertised, so the editor's unsaved buffers are respected.

---

## 11. UX items (→ 5.6, 5.7-2, 5.14a)

- **`/context` contents (→ 5.6).** Zed's token tooltip shows used/max, an input/output split with separate maxima, cost, and **which rules files and whether the personal AGENTS.md are loaded**. R14: make `/context` list loaded instruction files, rules, memory entry count and skill count.
- **Notifications (→ 5.14a).** `notify_when_agent_waiting: "primary_screen"` and `play_sound_when_agent_done`. For sugar-crush: BEL plus OSC 9 / OSC 777 on turn end or approval wait when the terminal is unfocused (focus reporting is available via candy-core).
- **`ask_user` (→ 5.7-2).** Question plus ≥ 2 options and/or free text, rendered as a form via ACP *elicitation*; off in all default profiles. For sugar-crush: over the 1.C approval channel.
