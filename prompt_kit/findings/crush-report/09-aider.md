# Aider vs sugar-crush: competitor deep-dive

**Competitor:** Aider (`Aider-AI/aider`), Python. Clone at `/home/sites/crush-research-repos/aider`, HEAD `5dc9490bb` (2026-05-22), version `0.86.3.dev` (`aider/__init__.py:3`).
**Baseline:** `prompt_kit/findings/crush-report/00-sugar-crush-baseline.md` (sugar-crush master @ `f2884ae7d`). Sugar-crush claims that a recommendation depends on were re-checked in source; those spots are marked *(verified)*.

All Aider paths below are relative to `/home/sites/crush-research-repos/aider/`. All sugar-crush paths are relative to `sugar-crush/`.

---

## 1. Overview

### What it is

Aider is an "AI pair programmer in your terminal". It is **not a tool-calling agent**. The model never calls `Read`, `Bash` or `Edit`. Instead:

- The **user decides which files are "in the chat"** (`/add`, `/read-only`, or files named on the command line). Aider pastes their **full current contents** into every request.
- A **ranked, token-budgeted repo map** (tree-sitter symbols + PageRank) shows the model the rest of the repository.
- The model answers in prose plus **edit blocks in a text format** (SEARCH/REPLACE, unified diff, whole file, or OpenAI's V4A patch). Aider parses the blocks out of the reply and applies them.
- After applying, Aider **auto-commits** with a weak-model commit message, **auto-lints** the edited files, optionally **auto-tests**, and feeds any failure back as a new user message. This "reflection" loop runs at most 3 times.

The "agent loop" is therefore one LLM call per reflection, with the harness doing all I/O deterministically. This design makes Aider cheap, predictable and model-agnostic: it works with any chat model through litellm, including ones with no function calling.

### Stack and size

| Item | Value |
|---|---|
| Language | Python 3, ~20.3k lines in `aider/**/*.py` (`base_coder.py` 2,485, `commands.py` 1,712, `models.py` 1,338, `main.py` 1,274, `io.py` 1,191, `repomap.py` 867) |
| LLM access | `litellm` (`aider/llm.py`); every provider and model, metadata from litellm's `model_prices_and_context_window.json` |
| Parsing | `tree-sitter` via `grep_ast` (tags queries for ~30 languages in `aider/queries/`), `pygments` for reference backfill, `networkx` PageRank |
| UI | `prompt_toolkit` line UI with `rich` markdown streaming (`aider/io.py`, `aider/mdstream.py`); optional Streamlit GUI (`aider/gui.py`) |
| Git | GitPython (`aider/repo.py`) |
| Tests | 41 test files under `tests/` plus a polyglot edit benchmark harness (`benchmark/`) |

### Architecture in one paragraph

`main.py` builds a `Model` (with an optional **weak model** and **editor model**), a `GitRepo`, a `ChatSummary` and a `Coder` subclass chosen by **edit format** (`Coder.create()`, `aider/coders/base_coder.py:124-201`). The `Coder` owns:

- `abs_fnames` (editable files) and `abs_read_only_fnames`
- `done_messages` (summarised history) and `cur_messages` (the current exchange)
- an optional `RepoMap`, a `Linter`, and a `Commands` object for slash commands

Switching modes (`/ask`, `/code`, `/architect`, `/context`, `/chat-mode`) raises `SwitchCoder`. `main.py:1161-1177` catches it and constructs a new `Coder` `from_coder=` the old one, carrying files, history, commit hashes and cost across.

### What Aider is best at

1. **The repo map** (`aider/repomap.py`). It extracts tree-sitter definitions and references, builds a file graph, and runs **personalised PageRank** biased toward in-chat files, files mentioned in the prompt, and identifiers mentioned in the prompt. A binary search then sizes the map to a token budget, and the result is cached by file mtime in SQLite. This is the single most transferable idea here.
2. **Edit-format engineering.** There are six or more formats, each with a carefully tuned prompt, few-shot examples, and a "system reminder" re-sent at the end of every request. Formats are chosen per model from a 357-entry `model-settings.yml`.
3. **Forgiving edit application with informative failures.** Matching cascades: exact → uniform-leading-whitespace → spurious blank line → `...` elision. Filenames are fuzzy-recovered. On failure the model gets a **"Did you mean to match some of these actual lines?"** reflection with the nearest real lines, plus "the REPLACE lines are already in the file" detection and "the other N blocks applied, don't re-send them".
4. **The lint/test reflection loop.** Edited files get a tree-sitter syntax check, Python compile and flake8, or a user `--lint-cmd`. Errors are rendered with **enclosing-scope context and █ markers**, fed back automatically, and capped at 3 reflections.
5. **Git as the safety net.** Every AI edit is auto-committed with a weak-model Conventional-Commits message and attribution (`Co-authored-by: aider (<model>)`). The user's pre-existing dirty changes are committed first so `/undo` is clean. `/undo` has five safety checks.
6. **Two-model architect/editor mode.** A strong reasoning model describes the change; a cheaper or more format-reliable editor model writes the edit blocks.
7. **Background chat-history summarisation** on the weak model. It is recursive, keeps a verbatim tail, and runs in a thread so it is usually done before it is needed.
8. **Prompt-cache layout and cache-warming pings.** Message chunks are ordered for cache stability, `cache_control` goes on up to three breakpoints, and an optional 1-token ping every 4 m 55 s keeps the Anthropic cache alive.
9. **Model metadata handling.** litellm's price/context JSON is cached for 24 h, there are local overrides, and the OpenRouter page is scraped as a fallback. Window sizes, repo-map budget, history budget and costs all derive from it.
10. **Small, high-value UX:** `/tokens` per-component cost table, `/diff` since last message, watch-files `AI!`/`AI?` comments from any editor, `/voice`, `/web`, `/copy-context` for web chat UIs, and a terminal bell when a reply is ready.

---

## 2. Agent loop

There is no tool loop. A "turn" is `Coder.run_one()` (`base_coder.py:924-944`):

```python
while message:
    self.reflected_message = None
    list(self.send_message(message))
    if not self.reflected_message:
        break
    if self.num_reflections >= self.max_reflections:   # max_reflections = 3 (:101)
        self.io.tool_warning(f"Only {self.max_reflections} reflections allowed, stopping.")
        return
    self.num_reflections += 1
    message = self.reflected_message
```

`send_message()` (`:1419-1623`) does one round:

1. **Append** the user message to `cur_messages`.
2. **Build the chunks** (`format_messages()` → `format_chat_chunks()`, §5).
3. **Pre-flight token check.** `check_tokens()` (`:1396-1417`) counts with the real model tokenizer through litellm. Over `max_input_tokens` it prints remediation (`/drop`, `/clear`, smaller files) and asks "Try to proceed anyway?".
4. **Start cache warming** (`warm_cache`, §4).
5. **Stream** through `send()` → `model.send_completion()` → `litellm.completion()` (`models.py:985-1037`, 600 s request timeout, `models.py:28`).
   - Reasoning content (`delta.reasoning_content` or `delta.reasoning`) is wrapped in `<thinking-content-…>` tags for display and stripped before parsing (`base_coder.py:1900-1975`, `reasoning_tags.py`).
   - Markdown is rendered live through `MarkdownStream`.
6. **Retry.** On a litellm exception, look it up in the `EXCEPTIONS` table (`aider/exceptions.py:12-55`: retry yes/no plus a friendly description). The delay starts at `0.125` s and doubles until it exceeds `RETRY_TIMEOUT = 60` s (`base_coder.py:1449-1488`, `models.py:26`). Retried: rate limit, 5xx, timeouts, connection errors. Not retried: auth, bad request, not found, context-window-exceeded.
7. **`FinishReasonLength` → "infinite output"** (`:1492-1505`). If the model supports assistant prefill, the partial reply is re-sent as a trailing `assistant` message with `prefix=True` and generation **continues**. The announcement line reports this as `infinite output` (`:234-235`). Otherwise the turn ends with a detailed `show_exhausted_error()` (`:1628-1679`, with a 0.7 "fudge" heuristic to tell an input overflow from an output overflow).
8. **Ctrl-C.** Keeps the partial reply and appends `"^C KeyboardInterrupt"` to the user message plus an assistant row `"I see that you interrupted my previous reply."` (`:1575-1583`). A second Ctrl-C within 2 s exits (`keyboard_interrupt`, `:986-1000`).
9. **File-mention reflection.** `check_for_file_mentions(content)` (`:1761-1781`) scans the reply for repo file paths, and for unique basenames that contain `. _ - /`, that are not in the chat. It asks "Add file to the chat?" (Yes/No/All/Skip all/Don't ask again). If any are added, the reflection message is `"I added these files to the chat: {fnames}\nLet me know if there are others we should add."` (`aider/prompts.py`). This is how the model "requests a Read".
10. **`reply_completed()` hook.** `ArchitectCoder` and `ContextCoder` override it (§3).
11. **Apply edits.** `apply_updates()` (`:2296-2336`) runs `get_edits()` → `apply_edits_dry_run()` → `prepare_to_edit()` (permission and dirty-commit) → `apply_edits()`. A `ValueError` (malformed or unmatched edit) sets `reflected_message = str(err)`; the error text is written *for the model* (§7).
12. **Auto-commit** the edited files (§8). Then `move_back_cur_messages(saved_message)` moves the exchange into `done_messages` with a synthetic pair `"I committed the changes with git hash {hash} & commit msg: {message}"` (`base_prompts.py:4`). This kicks off background summarisation.
13. **Auto-lint** (default on). It lints the edited files, commits the lint-fix state, and on errors asks "Attempt to fix lint errors?" → `reflected_message = lint_errors` (`:1599-1607`).
14. **Suggested shell commands.** Blocks such as ` ```bash ` in the reply are collected by the edit parser (`editblock_coder.py:450-485`). Each is confirmed with `explicit_yes_required=True` (`--yes-always` does *not* auto-run them; `io.py:866-867`). The user may then add the output to the chat (`:2434-2485`).
15. **Auto-test** (default off). Runs `test_cmd`; on a non-zero exit asks "Attempt to fix test errors?" → reflect (`:1616-1623`).

**Parallel tools, step limits, doom loops.** Not applicable: there are no tools. The bound is `max_reflections = 3` per user message. Malformed-response and exhausted-context counters exist (`num_malformed_responses`, `num_exhausted_context_windows`) but only feed analytics.

**Mid-turn steering.** None. The input prompt is blocked while streaming. The **file watcher** can interrupt the *input* prompt (`io.interrupt_input()`, `watch.py:142`) to inject an AI-comment request between turns.

**Function calling.** Only in the legacy `*_func_coder.py` formats. They force a single function with `tool_choice` (`models.py:1005-1008`) and are commented out of `coders.__all__` (`aider/coders/__init__.py:18`): Aider found plain-text edit formats more reliable.

---

## 3. Agents and sub-agents

Aider has **no sub-agents, no parallelism, no background agents, and no parent↔child messaging**. It has **modes** (a `Coder` subclass per edit format) and one **two-stage pipeline**.

### Chat modes (`commands.py:138-207`, `cmd_chat_mode`)

| Mode | Class | Prompt intent |
|---|---|---|
| `code` (default) | the model's `edit_format` coder (`diff`, `whole`, `udiff`, …) | Make changes with edit blocks |
| `ask` | `AskCoder` (`ask_prompts.py`) | `"Act as an expert code analyst. Answer questions about the supplied code. Always reply to the user in {language}. If you need to describe code changes, do so *briefly*."` |
| `architect` | `ArchitectCoder` (`architect_coder.py`, `architect_prompts.py`) | Plan for an editor, see below |
| `context` | `ContextCoder` (`context_coder.py`, `context_prompts.py`) | Identify the files to edit |
| `help` | `HelpCoder` | Answers questions about Aider from its bundled docs |

`/ask <q>`, `/code <q>`, `/architect <q>` and `/context <q>` run **one message** in the other mode, then switch back. The one-off exchange stays in the shared history (`_generic_chat_command`, `commands.py:1206-1230`).

When the edit format changes, `Coder.create(from_coder=…)` **summarises the old history** first (`base_coder.py:156-168`). The source comment explains why: *"If the edit format changes, we can't leave old ASSISTANT messages in the chat history. The old edit format will confused the new LLM. It may try and imitate it, disobeying the system prompt."*

### Architect → editor (the one multi-model pipeline)

`ArchitectCoder(AskCoder)`, `architect_coder.py:6-48`:

- The architect runs on the **main model** with this prompt (`architect_prompts.py`):
  > Act as an expert architect engineer and provide direction to your editor engineer. Study the change request and the current code. Describe how to modify the code to complete the request. The editor engineer will rely solely on your instructions, so make them unambiguous and complete. Explain all needed code changes clearly and completely, but concisely. Just show the changes needed. DO NOT show the entire updated function/file/etc! Always reply to the user in {language}.
- In `reply_completed()`, unless `auto_accept_architect` is set (default **True**, `args.py:179-183`), the user is asked "Edit the files?".
- An **editor coder** is then created on `main_model.editor_model` with `edit_format = editor_edit_format` (`editor-diff`, `editor-whole` or `editor-diff-fenced`) and:
  - `suggest_shell_commands=False`, `map_tokens=0` (no repo map), `cache_prompts=False`
  - **empty `cur_messages` and `done_messages`**
- The editor is sent **the architect's full reply as its only user message** (`editor_coder.run(with_message=content, preproc=False)`).
- The editor prompts are stripped down to "Act as an expert software developer who edits source code. … ONLY EVER RETURN CODE IN A *SEARCH/REPLACE BLOCK*!" (`editor_editblock_prompts.py`).
- Afterwards the architect's history gets `"I made those changes to the files."`, and cost and commit hashes are copied back.
- Editor model defaults come from `model-settings.yml`. For example `claude-opus-4-7` → `editor_model_name: claude-sonnet-4-6`, `editor_edit_format: editor-diff` (`resources/model-settings.yml:1871-1882`). `get_editor_model()` maps `diff`/`whole`/`diff-fenced` to `editor-*` (`models.py:625-645`).

So the "sub-agent" is synchronous, gets a fresh isolated context, can run on a different model, and returns only its side effects (edits and commits). It is one level deep and cannot talk back.

### Context mode (an automatic file-selection loop)

`ContextCoder` (`context_coder.py`):
- It **enlarges the repo map**: `max_map_tokens *= map_mul_no_files`, `refresh="always"`.
- It asks the model to list the files that need editing plus relevant symbols, in a strict format (`context_prompts.py`, quoted in §5).
- In `reply_completed()` it parses the mentioned files and **replaces the chat's file set with them**. If the set changed, it reflects with `try_again`:
  > I have updated the set of files added to the chat. Review them to decide if this is the correct set of files or if we need to add more or remove files. If this is the right set, just return the current list of files.
- It stops when the set is stable or the reflection limit is hit.

This is a self-converging retrieval loop.

### Weak model

`Model.weak_model` (`models.py:603-623`, set per model in `model-settings.yml`, e.g. `claude-sonnet-4-6` → `weak_model_name: claude-haiku-4-5`) is used for:
- commit messages: `commit_message_models()` returns `[weak, main]` as a fallback chain;
- chat-history summarisation: `ChatSummary([weak, main], …)`.

Each falls back to the main model if the weak one fails or the input exceeds its window (`repo.py:342-363`, `history.py:114-123`).

---

## 4. Context handling and compaction

### Token counting

- **Real tokenizer.** `Model.token_count()` (`models.py:650-670`) uses `litellm.token_counter(model, messages)` for message lists and `litellm.encode` for strings. Images are costed with the OpenAI tile formula: 170 tokens per 512² tile plus 85 (`:672-701`).
- **Sampled estimate for big text.** `RepoMap.token_count()` (`repomap.py:89-101`) tokenises exactly below 200 chars. Above that it **samples about 1% of lines** (`step = num_lines // 100`) and extrapolates by character ratio. That is cheap enough to call inside a binary search.
- **Windows** come from model metadata: `info["max_input_tokens"]`, `max_output_tokens` (§11).

### Chat-history summarisation (`aider/history.py`, `ChatSummary`)

**Budget.** `max_chat_history_tokens = min(max(max_input_tokens / 16, 1024), 8192)` (`models.py:356-358`); `--max-chat-history-tokens` overrides it. The history is therefore kept **small on purpose**: 1/16 of the window, at most 8k tokens. The files in the chat are the real context, and history is only narrative.

**When.** Every time an exchange moves into `done_messages`, `summarize_start()` (`base_coder.py:1002-1012`) checks `too_big()`. If it is over budget, it **summarises in a background thread** (`summarize_worker`) with no user wait. `summarize_end()` (`:1024-1034`) joins the thread before the next request. The result is applied only if `done_messages` did not change meanwhile (`if self.summarizing_messages == self.done_messages`).

**How** (`summarize_real`, `history.py:33-96`):

1. If the total is ≤ the budget and `depth == 0`, return unchanged.
2. If there are ≤ 4 messages or `depth > 3`, summarise everything.
3. Walk backwards, keeping a **verbatim tail** of up to `max_tokens // 2`. Move the split point back until the head ends on an assistant message.
4. The head (truncated to `model.max_input_tokens - 512`) goes through `summarize_all()`. If summary + tail fits, return it; **otherwise recurse** on `summary + tail` with `depth + 1`.
5. `summarize_all()` flattens USER/ASSISTANT turns into `# USER\n…# ASSISTANT\n…` and sends them with this system prompt (`aider/prompts.py:46-59`):

> \*Briefly\* summarize this partial conversation about programming.
> Include less detail about older parts and more detail about the most recent messages.
> Start a new paragraph every time the topic changes!
>
> This is only part of a longer conversation so \*DO NOT\* conclude the summary with language like "Finally, ...". Because the conversation continues after the summary.
> The summary \*MUST\* include the function names, libraries, packages that are being discussed.
> The summary \*MUST\* include the filenames that are being referenced by the assistant inside the \`\`\`...\`\`\` fenced code blocks!
> The summaries \*MUST NOT\* include \`\`\`...\`\`\` fenced code blocks!
>
> Phrase the summary with the USER in first person, telling the ASSISTANT about the conversation.
> Write \*as\* the user.
> The user should refer to the assistant as \*you\*.
> Start the summary with "I asked you...".

The result is prefixed with `"I spoke to you previously about a number of things.\n"` (`prompts.py:61`) and stored as a single **user** message, followed by an `"Ok."` assistant message (`history.py:27-31`).

**What is dropped.** Code blocks are dropped by instruction, and old assistant edit blocks are always summarised away. The *files themselves* are never summarised, because their current contents are re-sent fresh every request (§5).

**Agent-controlled self-pruning.** None. The user controls context with `/drop`, `/clear`, `/reset`, `/read-only`, and `/tokens` to see what costs what.

### Tool-output truncation

Not applicable. Shell output enters the chat only after "Add N lines of output to the chat?" / "Add 1.2k tokens of command output to the chat?" confirmation (`commands.py:1013-1053`). There is no automatic cap, but the user sees the token count before accepting.

### Prompt caching (`aider/coders/chat_chunks.py`)

**Chunk order** (`all_messages()`, `:16-26`), chosen so the most stable content comes first:

```
system + examples + readonly_files + repo(map) + done(history) + chat_files + cur + reminder
```

**Breakpoints** (`add_cache_control_headers()`, `:28-41`). Up to three `{"type":"ephemeral"}` marks go on the *last message* of:
1. `examples`, or `system` when there are none;
2. `repo`, which also covers the read-only files before it, or `readonly_files` when there is no map;
3. `chat_files`.

Editable files sit **after** the history on purpose. Editing a file invalidates only the `chat_files` segment and later; the system, map and history prefix stays cached.

**Settings.**
- Caching is on when `--cache-prompts` is set and the model's settings say `cache_control: true`. The Anthropic beta header is `prompt-caching-2024-07-31,pdfs-2024-09-25` (`models.py:31`).
- `--cache-prompts` flips `--map-refresh auto` to `files` (`main.py:954-955`). The map is then rebuilt only when the file set changes, not on every mentioned identifier, so its bytes stay cache-stable.

**Cache-warming pings** (`warm_cache`, `base_coder.py:1340-1394`):
- With `--cache-keepalive-pings N` (default 0), a daemon thread sleeps until 5 min − 5 s (`AIDER_CACHE_KEEPALIVE_DELAY`) after the last request.
- It then sends `cacheable_messages()` — everything up to the last cache mark — with `max_tokens=1`, up to N times. That keeps the 5-minute Anthropic cache warm while the user thinks.

**Usage reporting.** Cache hit and write tokens are parsed from `prompt_cache_hit_tokens` (DeepSeek) and `cache_read_input_tokens`/`cache_creation_input_tokens` (Anthropic). Cost applies Anthropic's ×1.25 write and ×0.10 read multipliers, or DeepSeek's `input_cost_per_token_cache_hit` (`:1994-2100`).

**Dedup.** None needed: a file is sent once per request, from disk.

---

## 5. Prompt generation

### Assembly (`format_chat_chunks`, `base_coder.py:1226-1331`)

1. **`choose_fence()`** (`:609-635`) picks the first fence that no in-chat file contains as a line prefix: ```` ``` ````, ```` ```` ````, `<source>`, `<code>`, `<pre>`, `<codeblock>`, `<sourcecode>`. This is so edit blocks around Markdown files do not break. With quad backticks the prompt gains `"IMPORTANT: Use *quadruple* backticks ```` as fences, not triple backticks!"`.
2. **System message** = optional `system_prompt_prefix` from model settings (e.g. `"Formatting re-enabled. "` for o3-mini) + `main_system` formatted by `fmt_system_prompt()` (`:1174-1224`), which fills in:
   - `{final_reminders}`: per-model `lazy_prompt` (*"You are diligent and tireless! You NEVER leave comments describing code without implementing it! You always COMPLETELY IMPLEMENT the needed code!"*) and/or `overeager_prompt` (*"Pay careful attention to the scope of the user's request. Do what they ask, but no more. Do not improve, comment, fix or modify unrelated parts of the code in any way!"*), plus `"Reply in {user_lang}."`. `model-settings.yml` sets `lazy`/`overeager` on 79 entries.
   - `{platform}` (`get_platform_info`, `:1127-1172`):
     ```
     - Platform: <platform.platform()>
     - Shell: SHELL=/bin/bash
     - Language: English
     - Current date: 2026-10-01
     - The user is operating inside a git repository
     - The user's pre-commit runs these lint commands, don't suggest running them:
       - python: flake8 …
     - The user prefers this test command: pytest
     ```
   - `{shell_cmd_prompt}` (`coders/shell.py`): "*Concisely* suggest any shell commands the user might want to run in ```bash blocks … Only suggest at most a few shell commands at a time, not more than 1-3, one per line. Do not suggest multi-line shell commands. All shell commands will run from the root directory of the user's project. Use the appropriate shell based on the user's system info: {platform} …" followed by examples (open the HTML file, run the CLI, run the new test, install new dependencies).
   - `{go_ahead_tip}`, `{rename_with_shell}`, `{language}`.
3. **Few-shot examples.** Two worked user/assistant pairs per edit format. They are sent as real messages, then separated with `"I switched to a new code base. Please don't consider the above files or try to edit them any longer."` + `"Ok."` (`:1249-1259`). If `examples_as_sys_msg` is set, they are folded into the system prompt as `## USER: …` / `## ASSISTANT: …` instead.
4. **The system reminder is appended to the system message too**, and is sent again at the end (step 9).
5. **Read-only files**: `"Here are some READ ONLY files, provided for your reference.\nDo not edit these files!\n"` + `path\n```\n<content>```` per file, then the assistant reply `"Ok, I will use these files as references."`. Images and PDFs go as `image_url` parts when the model supports vision or PDF.
6. **Repo map** (§7.1): `"Here are summaries of some files present in my {other}git repository.\nDo not propose changes to these files, treat them as *read-only*.\nIf you need to edit any of these files, ask me to *add them to the chat* first.\n"` + the map, then the assistant reply `"Ok, I won't try and edit those files without asking first."`.
7. **History** (`done_messages`, summarised).
8. **Editable files**:
   > I have \*added these files to the chat\* so you can go ahead and edit them.
   >
   > \*Trust this message as the true contents of these files!\*
   > Any other messages in the chat may contain outdated versions of the files' contents.

   followed by each file, then the assistant reply `"Ok, any changes I propose will be to those files."`. When **no** files are in the chat but a repo map exists, the message instead says:
   > Don't try and edit any existing code without asking me to add the files to the chat! Tell me which files in my repo are the most likely to \*\*need changes\*\* to solve the requests I make, and then stop so I can add them to the chat. …
9. **Current exchange**, then the **reminder** (`:1294-1329`). This is the edit-format `system_reminder` again — the full SEARCH/REPLACE rules — sent either as a final `system` message (`reminder: sys`, 43 models) or appended to the last user message (`reminder: user`, the default). It is added only if it still fits in `max_input_tokens`.

### Why it works

The protocol is a fake dialogue: every block of context is a *user* message answered by a short *assistant* acknowledgement. Instructions are repeated **at the very end**, where recency bias helps weaker models keep to the format.

### What is not sent

Aider sends **no instruction files**: there is no AGENTS.md/CLAUDE.md loading (grep finds none). Conventions are added by the user with `--read CONVENTIONS.md` as a read-only file. It sends no memory, no skills, no git status, and no tool schemas.

### Edit-format prompts (quoted)

**`diff` / SEARCH/REPLACE** (`editblock_prompts.py:8-29`, reminder `:120-158`). Main system:

> Act as an expert software developer. Always use best practices when coding. Respect and use existing conventions, libraries, etc that are already present in the code base. {final_reminders} Take requests for changes to the supplied code. If the request is ambiguous, ask questions.
>
> Once you understand the request you MUST:
> 1. Decide if you need to propose \*SEARCH/REPLACE\* edits to any files that haven't been added to the chat. You can create new files without asking! But if you need to propose edits to existing files not already added to the chat, you \*MUST\* tell the user their full path names and ask them to \*add the files to the chat\*. End your reply and wait for their approval. …
> 2. Think step-by-step and explain the needed changes in a few short sentences.
> 3. Describe each change with a \*SEARCH/REPLACE block\* per the examples below.
>
> All changes to files must use this \*SEARCH/REPLACE block\* format. ONLY EVER RETURN CODE IN A \*SEARCH/REPLACE BLOCK\*!

Reminder (abridged): the 8-part block anatomy (path alone on a line → fence + language → `<<<<<<< SEARCH` → exact lines → `=======` → replacement → `>>>>>>> REPLACE` → fence), followed by these rules:

- *"Every \*SEARCH\* section must \*EXACTLY MATCH\* the existing file content, character for character, including all comments, docstrings, etc."*
- *"\*SEARCH/REPLACE\* blocks will \*only\* replace the first match occurrence."*
- *"Keep \*SEARCH/REPLACE\* blocks concise. Break large \*SEARCH/REPLACE\* blocks into a series of smaller blocks … Include just the changing lines, and a few surrounding lines if needed for uniqueness."*
- *"To move code within a file, use 2 \*SEARCH/REPLACE\* blocks"*
- New file = empty SEARCH.
- The go-ahead tip: *"If the user just says something like "ok" or "go ahead" or "do that" they probably want you to make SEARCH/REPLACE blocks for the code changes you just proposed."*

**`udiff`** (`udiff_prompts.py`). *"For each file that needs to be changed, write out the changes similar to a unified diff like `diff -U0` would produce."* Reminder:

> Start each hunk of changes with a `@@ ... @@` line. Don't include line numbers like `diff -U0` does. The user's patch tool doesn't need them. … **When editing a function, method, loop, etc use a hunk to replace the \*entire\* code block. Delete the entire existing version with `-` lines and then add a new, updated version with `+` lines. This will help you generate correct code and correct diffs.**

`udiff-simple` drops the `@@`/line-number rules (`udiff_simple_prompts.py`).

**`whole`** (`wholefile_prompts.py`). *"To suggest changes to a file you MUST return the entire content of the updated file. … \*NEVER\* skip, omit or elide content from a \*file listing\* using "..." or by adding comments like "... rest of code..."!"*

**`patch`** (OpenAI V4A, `patch_prompts.py`). `*** Begin Patch` / `*** [Add|Update|Delete] File:` / 3 lines of context before and after, `@@ [CLASS_OR_FUNCTION_NAME]` scope markers when 3 lines are not unique, *"Each file MUST appear only once in the patch."*

**`diff-fenced`**: same as `diff`, but the path goes *inside* the fence (built for Gemini). The `editor-*` variants strip the explanation and shell parts.

**`context`** (`context_prompts.py`): *"Understand the user's question or request, solely to determine ALL the existing sources files which will need to be modified. … The user will use every file you mention, regardless of your commentary. So \*ONLY\* mention the names of relevant files. … Return: 1. A bulleted list of files the will need to be edited, and symbols that are highly relevant … 2. A list of classes/functions/methods/variables that are located OUTSIDE those files which will need to be understood."* with a mandatory `## ALL files we need to modify, with their relevant symbols:` format and the reminder `NEVER RETURN CODE!`.

---

## 6. Memory

Aider has **no persistent memory system**. What persists:

| Store | What | Default |
|---|---|---|
| `.aider.chat.history.md` (git root) | Markdown log of every chat. `--restore-chat-history` re-parses it into `done_messages` (`split_chat_history_markdown`) and summarises immediately (`base_coder.py:519-523`) | written always; restore **off** |
| `.aider.input.history` | prompt_toolkit input history | on |
| `--llm-history-file` | raw TO LLM / LLM RESPONSE log | off |
| `/save <file>` / `/load <file>` | a script of `/add` and `/read-only` commands that rebuilds the file set (`commands.py:1465-1522`) | manual |
| `.aider.conf.yml`, `.env`, `.aider.model.settings.yml`, `.aider.model.metadata.json` | config, keys, per-model overrides | — |
| `.aider.tags.cache.v4/` | diskcache (SQLite) of tree-sitter tags keyed by absolute path, invalidated by mtime | always |

"Conventions" are by convention: the user keeps a `CONVENTIONS.md` and loads it with `--read`, which puts it in the read-only, cached chunk. There is no auto-extraction, no retrieval, and no scopes.

**Relevance selection** exists only in the repo map. Its personalisation (§7.1) is effectively "semantic recall" of *code*, keyed on what the user just typed.

---

## 7. Tools and editing

### 7.1 Repo map (`aider/repomap.py`) — the standout

**Inputs.** `Coder.get_repo_map()` (`base_coder.py:709-748`) passes four sets:
- `chat_files`: editable files plus read-only files that are in the repo;
- `other_files`: every tracked file, minus `.aiderignore`, optionally `--subtree-only`;
- `mentioned_fnames`: paths and unique basenames found in the current message (`get_file_mentions`), plus files whose stem (≥5 chars) equals a word in the message (`get_ident_filename_matches`, `:684-707`);
- `mentioned_idents`: every `\W+`-split word of the current message (`get_ident_mentions`, `:678-682`).

There are two fallbacks: if the result is empty, retry with no chat files (a global map); then retry with no hints at all.

**Tag extraction** (`get_tags_raw`, `repomap.py:279-363`):
- tree-sitter parses the file with the language's `*-tags.scm` query, from `queries/tree-sitter-language-pack/` (31 languages) or `queries/tree-sitter-languages/` (28, including `php-tags.scm`).
- Captures named `name.definition.*` become **def** tags and `name.reference.*` become **ref** tags, each `(rel_fname, fname, line, name, kind)`.
- When a query yields defs but no refs (C++ for example), **pygments** backfills refs from every `Token.Name` token.
- PHP's query captures class, function and method definitions, plus references for `new X`, function calls, `X::y()` and `$x->y()`.
- **Cache** (`get_tags`, `:233-264`): `TAGS_CACHE[fname] = {"mtime", "data"}` in diskcache. On any SQLite error it rebuilds the cache directory, then falls back to an in-memory dict (`tags_cache_error`, `:177-215`). On the first scan, if more than 100 files are uncached, it shows a progress bar: "Initial repo scan can be slow in larger repos, but only happens once."

**Graph and ranking** (`get_ranked_tags`, `:365-574`):
- `personalize = 100 / len(fnames)`. A file gets `personalize` if it is in the chat or mentioned, and another `+personalize` if any path component or basename (with or without extension) matches a mentioned identifier.
- A `networkx.MultiDiGraph` gets one edge **referencer → definer** per shared identifier. Edge weight is `mul * sqrt(num_refs)`, where `mul` starts at 1.0 and:
  - `×10` if the identifier was **mentioned** by the user;
  - `×10` if it is snake/kebab/camel case and ≥ 8 chars (a "specific" name);
  - `×0.1` if it starts with `_` (private);
  - `×0.1` if it is defined in more than 5 files (generic name);
  - `×50` on the edge if the **referencer is a chat file** (what the files you are editing depend on).
- Definitions with no references get a `0.1` self-edge.
- `nx.pagerank(G, weight="weight", personalization=…, dangling=…)`. On `ZeroDivisionError` it retries unpersonalised, then gives up.
- Rank is distributed from each node over its out-edges onto `(definer, ident)` pairs. These are sorted, and chat files are excluded from the output, because their full text is already sent.
- Files without tags are appended in node-rank order, then any remaining files by name.
- **"Important files"** (`special.py`: README, `composer.json`, `package.json`, `pyproject.toml`, `.github/workflows/*.yml`, …) are prepended (`get_ranked_tags_map_uncached`, `:656-662`).

**Budget sizing** (`get_ranked_tags_map_uncached`, `:629-706`):
- **Binary search** over *how many ranked tags to render*, starting at `middle = min(max_map_tokens // 25, num_tags)`.
- Each candidate is rendered with `to_tree()` and token-counted by sampling.
- The search keeps the best tree that is ≤ budget, and **stops early when within 15%** (`ok_err = 0.15`).

**Rendering** (`to_tree`, `render_tree`, `:710-784`):
- For each file, `grep_ast.TreeContext` prints the **lines of interest** (the definition lines) with their enclosing scopes (class → method header) and elides the rest.
- Every line is truncated to 100 chars ("in case we get minified js").
- The output looks like:
  ```
  aider/coders/base_coder.py:
  ⋮
  │class Coder:
  ⋮
  │    def get_repo_map(self, force_refresh=False):
  ⋮
  ```
- Rendered trees are cached by `(rel_fname, lois, mtime)`.

**Budget defaults.**
- `get_repo_map_tokens()` = `max_input_tokens / 8` clamped to **[1024, 4096]** (`models.py:782-789`); `--map-tokens` overrides.
- With **no files in the chat**, the budget is multiplied by `map_mul_no_files` (CLI default **2**, `args.py:263`; constructor default 8), capped at `max_context_window − 4096` (`repomap.py:120-132`).
- Announcement warning when map-tokens > 2× recommended: "Too much irrelevant code can confuse LLMs." (`base_coder.py:270-275`).

**Refresh policy** (`get_ranked_tags_map`, `:576-627`), `--map-refresh auto|always|files|manual`:
- **`auto`** recomputes every time *unless the last computation took > 1 s*. After that it serves cached results keyed by (chat files, other files, budget, mentions).
- `files` keys only on the file sets.
- `manual` reuses the last map until `/map-refresh`.
- On a `RecursionError` the map is disabled: "Disabling repo map, git repo too large?".

### 7.2 Edit application and fuzzy matching

**SEARCH/REPLACE parsing** (`find_original_update_blocks`, `editblock_coder.py:439-535`):
- Header regexes accept 5-9 marker characters: `^<{5,9} SEARCH>?\s*$`, `^={5,9}\s*$`, `^>{5,9} REPLACE\s*$`.
- `=======` may also terminate a block.
- Shell fences (`bash sh shell cmd batch powershell ps1 zsh fish ksh csh tcsh`) that are not followed by an edit block become suggested **shell commands**.
- A parse error raises `ValueError` showing everything up to the failure point and `^^^ Expected `=======``.

**Filename recovery** (`find_filename`, `:538-599`). It looks back up to 3 lines through fences and strips `` ` ``, `*`, `#`, `:`. It then picks the first match by: **exact** path in the chat files → **basename** match → `difflib.get_close_matches(cutoff=0.8)` → any candidate with an extension. If the block has no filename, the previous block's is reused.

**Matching cascade** (`replace_most_similar_chunk`, `:157-187`):
1. `perfect_replace`: exact line-tuple match.
2. `replace_part_with_missing_leading_whitespace` (`:243-273`): outdent SEARCH and REPLACE by their common minimum indent, find a window that matches except for a **uniform** leading-whitespace offset, then re-indent REPLACE by that offset.
3. Drop a spurious leading blank line in SEARCH (issue #25), then retry 1 and 2.
4. `try_dotdotdots` (`:190-240`): SEARCH/REPLACE with matching `...` lines is applied piecewise. Each piece must be unique; unpaired or mismatched `...` raises.
5. *(Dead code)* `replace_closest_edit_distance` with a 0.8 `SequenceMatcher` threshold sits after an unconditional `return` (`:183-187`). Aider deliberately **does not** apply fuzzy edits; it reflects instead.

**Wrong-file rescue** (`apply_edits`, `:55-65`). If a non-create edit fails on its named file, it is tried against **every other file in the chat**.

**Two-phase apply.** `apply_edits_dry_run` runs first, then `prepare_to_edit` handles permission, gitignore and dirty-commit per path, then the edits are written for real.

**The failure reflection** (`:79-124`) — the "did you mean" message sent back as the next user turn:

```
# 1 SEARCH/REPLACE block failed to match!

## SearchReplaceNoExactMatch: This SEARCH block failed to exactly match lines in app.py
<<<<<<< SEARCH
…=======
…>>>>>>> REPLACE

Did you mean to match some of these actual lines from app.py?

```
<nearest real lines>
```

Are you sure you need this SEARCH/REPLACE block?
The REPLACE lines are already in app.py!

The SEARCH section must exactly match an existing block of lines including all white space, comments, indentation, docstrings, etc

# The other 2 SEARCH/REPLACE blocks were applied successfully.
Don't re-send them.
Just reply with fixed versions of the block above that failed to match.
```

How the parts are produced:
- `find_similar_lines` (`:602-628`) slides a window the size of SEARCH over the file and scores it with line-level `SequenceMatcher.ratio()`; the threshold is **0.6**. If the best window's first and last lines equal SEARCH's, that window is shown verbatim; otherwise it is padded with **±5 lines**.
- The "already applied" check is `if updated in content and updated`.

**udiff application** (`udiff_coder.py`):
- Hunks are normalised and deduplicated.
- `directly_apply_hunk` uses `search_and_replace` with four preprocessing combinations (`search_replace.py:528-538`: strip blank lines × **relative indentation**). It refuses tiny (< 10 non-whitespace chars), non-unique contexts.
- On failure, `make_new_lines_explicit` re-diffs the hunk's "before" against the file, then splits the hunk into context/change/context sections. `apply_partial_hunk` retries each with **progressively less context** (`:282-309`).
- `RelativeIndenter` (`search_replace.py:18-171`) rewrites indentation as deltas from the previous line, using a `←` outdent marker (or a private-use code point if `←` occurs). An edit at the wrong absolute indent still matches.
- Errors: `UnifiedDiffNoMatch` / `UnifiedDiffNotUnique`, with the exact lines and "Use additional ` ` lines to provide context that uniquely indicates which code needs to be changed."
- *(Unused)* `git_cherry_pick_osr_onto_o` and `dmp_lines_apply` (diff-match-patch) strategies are defined in `editblock_strategies`/`udiff_strategies` (`search_replace.py:547-562`) and referenced only by the dev harness `proc()`.

**patch (V4A)** (`patch_coder.py`). Context search runs in fuzz tiers: exact (0) → `rstrip` (1) → `strip` (100). An `*** End of File` anchor that is not found at EOF adds `+10_000`. `@@ scope` lines are matched exactly, then whitespace-insensitively (+1 fuzz). Total fuzz is recorded on the `Patch`.

**whole** (`wholefile_coder.py`). Recovers the filename (strips `*`, `:`, `` ` ``, `#`; fixes a bogus `path/to/` prefix copied from the example; falls back to the only chat file). While streaming it **renders a live diff** of the file being rewritten (`render_incremental_response` → `do_live_diff`).

### 7.3 Lint and test feedback (`aider/linter.py`)

**Default per file** (`lint`, `:82-116`):
- Python: `py_lint` = tree-sitter `basic_lint` + `compile()` + `flake8 --select=E9,F821,F823,F831,F406,F407,F701,F702,F704,F706` (fatal errors only).
- Other languages: `basic_lint`, which walks the tree-sitter tree for `ERROR`/missing nodes (skipped for TypeScript).
- `--lint-cmd "lang: cmd"` or a global lint command overrides this. The command runs with the filename appended, and a non-zero exit counts as errors.

**Output format** sent to the model:

```
# Fix any errors below, if possible.

## Running: flake8 --select=… app.py

app.py:12:5: F821 undefined name 'foo'

## See relevant lines below marked with █.

app.py:
⋮
│class App:
│    def run(self):
█        return foo()
⋮
```

`find_filenames_and_linenums` pulls `file:line` pairs out of the linter text, and `tree_context()` shows them with **3 lines of padding and their enclosing scopes** (`:234-256`).

**Loop.** Auto-lint runs after every edit, then `auto_commit(context="Ran the linter")`, then asks "Attempt to fix lint errors?" → reflect. `/lint` lints and fixes dirty files in a **cloned coder with empty history**, committing before and after (`commands.py:356-409`). `--auto-test` / `/test` runs `test_cmd`; a non-zero exit adds the output to the chat and reflects (`commands.py:993-1053`).

### 7.4 Shell, web and file limits

- **Shell.** `run_cmd` uses `pexpect` (an interactive PTY) when stdin is a TTY, otherwise a subprocess under `$SHELL` (`run_cmd.py:11-23`). It is user-triggered (`/run`, `!cmd`) or model-suggested with explicit confirmation. There is no timeout and no sandbox.
- **Web.** `/web <url>` scrapes with Playwright (offering to install Chromium) or httpx, converts **HTML → Markdown with pandoc**, and adds it to the chat (`scrape.py`). URLs in user input trigger "Add URL to the chat?" (`check_for_urls`, `base_coder.py:964-984`).
- **Images and PDFs.** `/add` an image or `/paste` from the clipboard become `image_url` parts for vision models.
- **File limits.** Whole files are sent. The only guard is a warning once ≥ 4 files and ≥ 20k tokens are in the chat: "it's best to only add files that need changes to the chat" (`check_added_files`, `:2244-2267`).
- **LSP.** None.

---

## 8. Git integration (`aider/repo.py`, `commands.py`)

### Auto-commit after every AI edit

`auto_commit()` (`base_coder.py:2375-2395`) → `GitRepo.commit(fnames=edited, context=<this exchange's messages>, aider_edits=True)` (`repo.py:131-318`):

**Commit message** (`get_commit_message`, `repo.py:326-373`). The diff (`git diff HEAD -- files`, or index + worktree on an unborn branch) plus the exchange text as context go to the **weak model, then the main model**, skipping any model whose window the diff does not fit. The prompt is `aider/prompts.py:8-22`, overridable with `--commit-prompt`:

> You are an expert software engineer that generates concise, one-line Git commit messages based on the provided diffs.
> Review the provided context and diffs which are about to be committed to a git repo.
> Review the diffs carefully.
> Generate a one-line commit message for those changes.
> The commit message should be structured as follows: <type>: <description>
> Use these for <type>: fix, feat, build, chore, ci, docs, style, refactor, perf, test
>
> Ensure the commit message:{language_instruction}
> - Starts with the appropriate prefix.
> - Is in the imperative mood (e.g., "add feature" not "added feature" or "adding feature").
> - Does not exceed 72 characters.
>
> Reply only with the one-line commit message, without any additional text, explanations, or line breaks.

`{language_instruction}` becomes `"\n- Is written in {lang}."` from `--commit-language` or the detected locale.

**Attribution**, with the full truth table in the `commit()` docstring (`repo.py:149-200`):
- Default `--attribute-co-authored-by=True` adds the trailer `Co-authored-by: aider (<model name>) <aider@aider.chat>`.
- Otherwise the author and committer names become `"<git user.name> (aider)"` through `GIT_AUTHOR_NAME`/`GIT_COMMITTER_NAME`.
- Optional `aider: ` message prefixes are available.

**Bookkeeping.** The commit hash is recorded in `aider_commit_hashes`. The model is told `"I committed the changes with git hash {hash} & commit msg: {message}"`. `--show-diffs` prints the diff.

**Hooks.** `--git-commit-verify` defaults to **False**, so Aider passes `--no-verify` and **skips pre-commit hooks** (`args.py:492-497`, `repo.py:278-279`). This is a deliberate speed and robustness trade-off and a questionable default (§14).

### Dirty commits

Before Aider edits a file that has *uncommitted user changes*, `check_for_dirty_commit` adds it to `need_commit_before_edits`. `dirty_commit()` then commits the user's work first, with its own generated message (`base_coder.py:2175-2189`, `:2411-2423`). AI changes and human changes therefore never share a commit, which is what makes `/undo` precise.

### `/undo` (`raw_cmd_undo`, `commands.py:560-655`)

Refuses when:
1. HEAD is the first commit;
2. HEAD is **not in `aider_commit_hashes`** ("The last commit was not made by aider in this chat session.", suggesting `/git reset --hard HEAD^`);
3. HEAD is a merge commit;
4. any file changed in it is **dirty now** ("Please stash them before undoing");
5. a file did not exist in the parent;
6. HEAD equals `origin/<branch>` (**already pushed**).

Then it runs `git checkout HEAD~1 -- <files>` and `git reset --soft HEAD~1`. When the model setting `send_undo_reply` is set, the model is told: *"I did `git reset --hard HEAD~1` to discard the last edits. Please wait for further instructions before attempting that change again. Feel free to ask relevant questions about why the changes were reverted."* `show_undo_hint()` reminds the user after any turn that moved HEAD.

### Other git commands

- `/diff`: the diff since the HEAD recorded before the last message (`commit_before_message`).
- `/commit [msg]`: commit outside edits.
- `/git <args>`: raw git.
- `--commit`: commit everything and exit.
- `--subtree-only`, `.aiderignore`, gitignored-file skips, and "Files are in different git repos" detection.

Aider has **no worktrees and no PR flow**. There is **no git status in the prompt**, only "The user is operating inside a git repository".

---

## 9. Extensibility

Aider is deliberately minimal here.

| Mechanism | Aider |
|---|---|
| MCP | **None** (no MCP code in `aider/`) |
| Hooks / plugins / skills | **None** |
| Custom commands | None. `/load <file>` replays a script of slash commands |
| Config | `.aider.conf.yml` (home, git root, cwd), `.env`, CLI flags, `AIDER_*` env vars (`main.py:464`) |
| Model extension | `.aider.model.settings.yml` (per-model `ModelSettings`: edit format, weak/editor model, `use_repo_map`, `lazy`/`overeager`, `reminder`, `examples_as_sys_msg`, `extra_params`, `cache_control`, `use_temperature`, `system_prompt_prefix`, `reasoning_tag`, `accepts_settings`) and `.aider.model.metadata.json` (litellm-format window and prices); `aider/extra_params` applies to all models (`models.py:385-425`) |
| External commands | `--lint-cmd` per language, `--test-cmd`, `--notifications-command`, `--commit-prompt`, `--editor` |
| Scripting | Python API: `Coder.create(...)` + `coder.run("…")`; `main(return_coder=True)`; `--message` / `--message-file` for one-shot runs |
| IDE integration | `--watch-files` (§11); `--copy-paste` clipboard bridge to web chat UIs |

---

## 10. Permissions and safety

**Approval model.** Questions go through `io.confirm_ask()` (`io.py:806-900`): `(Y)es/(N)o/(A)ll/(S)kip all/(D)on't ask again`. A `ConfirmGroup` batches related questions such as "add these 3 files". "Don't ask again" stores `(question, subject)` in `never_prompts`. The terminal bell rings on each question.

**What needs confirmation:**
- creating a new file ("Create new file?");
- editing a file not in the chat ("Allow edits to file that has not been added to the chat?") (`allowed_to_edit`, `base_coder.py:2191-2240`);
- adding mentioned files or URLs;
- running suggested shell commands, which need `explicit_yes_required`, so `--yes-always` answers **No** to them (`io.py:866-867`);
- adding command output to the chat;
- fixing lint or test errors;
- architect hand-off, when auto-accept is off.

**Guards.**
- Gitignored files are skipped unless `--add-gitignore-files`; `.aiderignore` is honoured.
- `.env` is added to `.gitignore` on first run (`main.py:166-168`).
- `--dry-run` writes nothing.
- The git history *is* the undo mechanism.

**No sandbox.** `/run` and suggested commands run with the user's shell and environment. There are no allow/deny lists and no secret redaction. API keys come from env, `.env` or `~/.aider/oauth-keys.env`.

**Risk profile.** Edits land without per-edit approval for chat files, but every one is a revertable commit. Shell execution is always gated.

---

## 11. UX

- **Startup announcement** (`get_announcements`, `base_coder.py:207-295`), for example: `Main model: claude-sonnet-4-6 with diff edit format, 8k think tokens, prompt cache, infinite output` / `Weak model: …` / `Git repo: .git with 1,234 files` / `Repo-map: using 2048 tokens, auto refresh`. Every degradation is explained up front, e.g. "Warning: For large repos, consider using --subtree-only and .aiderignore".
- **Per-message usage line** (`calculate_and_show_tokens_and_cost`, `:1994-2068`): `Tokens: 12k sent, 9.8k cache hit, 412 received. Cost: $0.0123 message, $0.4567 session.`
- **`/tokens`** (`commands.py:445-551`): a per-component table of cost and tokens — system messages, chat history ("use /clear to clear"), repository map ("use --map-tokens to resize"), each file ("/drop to remove") — with the total, remaining window and max window.
- **Model metadata** (`ModelInfoManager`, `models.py:161-326`): downloads litellm's `model_prices_and_context_window.json` into `~/.aider/caches/` with a **24 h TTL** (5 s timeout; writes `{}` on failure so it does not retry each launch). Local `--model-metadata-file` entries win. OpenRouter models use a cached API database, then **scrape the model page** for context and prices. Model aliases (`sonnet`, `opus`, `deepseek`, `r1`, `flash`, …) live at `models.py:96-123`. `sanity_check_models` warns about unknown models and missing keys. `/models <search>` fuzzy-searches the catalogue. `/think-tokens` and `/reasoning-effort` set per-model knobs, checked against `accepts_settings`.
- **Input** (`io.py`): prompt_toolkit with **autocompletion of filenames, slash commands and identifiers** (pygments tokens from in-chat files, `AutoCompleter`, `io.py:~100-150`), vi mode, multi-line mode (`/multiline-mode`, Alt-Enter), `/editor` (opens `$EDITOR` for the prompt), `/paste` (image or text from the clipboard), `/copy` (last reply), `/copy-context` (the whole context as Markdown for a web UI, `commands.py:1638-1678`).
- **`/voice`**: records audio, transcribes with Whisper through litellm, and puts the text in the input placeholder for editing (`voice.py:106-180`, `commands.py:1252-1277`).
- **Watch files** (`watch.py`, `--watch-files`):
  - A `watchfiles` thread watches the repo (ignoring gitignore, `.aider*`, editor temp files, `vendor/`, `node_modules/`, files over 1 MB).
  - A comment matching `(?:#|//|--|;+) *(ai\b.*|.*\bai[?!]?) *$` adds the file to the chat. Ending with `AI!` requests a code change; `AI?` asks a question.
  - It interrupts the input prompt and sends `watch_code_prompt` ("I've written your instructions in comments in the code and marked them with "ai" … After completing those instructions, also be sure to remove all the "AI" comments from the code too.") or `watch_ask_prompt`, plus every AI comment shown with tree context and █ markers.
  - Net effect: you drive Aider from **any editor** by typing a comment.
- **Notifications**: `--notifications` rings the terminal bell, or runs `--notifications-command`, when a reply is ready (`io.py:1088-1103`).
- **Streaming Markdown** rendering with a "Waiting for <model>" spinner. In `whole` mode a live diff is shown while streaming.
- **Other**: `/help` answers from Aider's own docs through `HelpCoder`. `/report` opens a GitHub issue. `--show-prompts` dumps the exact messages. Analytics are opt-in. A Streamlit GUI is available with `--gui`. `--chat-language`/`--commit-language` are auto-detected from the locale (`get_user_language`, `base_coder.py:1094-1125`).
- **Missing**: sessions, a resume picker, sharing, a todo display, sub-agent panes and a TUI layout. Aider is a line-oriented REPL.

---

## 12. Comparison table

Sugar-crush status is cited from the baseline (§ numbers refer to `00-sugar-crush-baseline.md`).

| Feature | Aider | sugar-crush | Gap |
|---|---|---|---|
| Agent loop | Single call + reflection loop (≤3) driven by edits, lint, tests and file mentions | LIVE: tool loop, `maxSteps` default 8, forked child (§1.4) | Different paradigm; sugar-crush is more capable but has no *harness-driven* verification step |
| Symbol-level repo map | tree-sitter defs/refs, personalised PageRank, token-budget binary search, mtime cache, ~30 languages | LIVE but **composer/PSR-4 only**, no symbols, 8 KiB (§4 slot 4, `Context/RepoMapBlock.php`) | **Large.** Aider's best idea |
| Personalisation to prompt and focus files | Yes (×10 mentioned idents, ×50 chat-file edges, path-component matches) | ABSENT | Large |
| Edit format | 6+ text formats, chosen per model | LIVE: exact `old_string` Edit + Write only; ABSENT MultiEdit/patch (§6.2) | Medium; tool-call Edit is fine, but no batch editing |
| Whitespace-tolerant matching | Uniform leading-indent offset, blank-line, `...`, relative-indent (udiff) | ABSENT: `substr_count` exact only (`Edit.php:178-197`, *verified*) | Medium |
| "Did you mean" on failed edit | Nearest window by line ratio ≥ 0.6 ±5 lines; "REPLACE already present"; "others applied, don't re-send" | ABSENT: `"Error: old_string not found in $path; file left unchanged"` (`Edit.php:192-197`, *verified*) | **Large** for cost: each blind retry burns a step of 8 |
| Filename fuzzy recovery | exact → basename → difflib 0.8 | n/a (JSON args); PathJail rejects wrong paths | Small |
| Post-edit lint feedback | Auto-lint (tree-sitter + compile + flake8 / `--lint-cmd`), █-marked scope context, reflection | ABSENT (§6.6) | **Large** |
| Auto-test loop | `--auto-test` + `--test-cmd`, reflection ≤3 | ABSENT; base prompt asks the model to run tests (§1.4) | Medium |
| Auto-commit per edit | Default on; weak-model Conventional-Commits message; Co-authored-by trailer | ABSENT (§7) | **Large** (no file-level undo exists) |
| Dirty-commit before edit | Yes | ABSENT | Medium |
| `/undo` | Five safety checks; tells the model why | ABSENT; `/rewind` restores the transcript only (§8) | **Large** |
| Commit-message generation | Weak→main fallback chain, language-aware | ABSENT (GitMcpServer `gitCommit` needs a message; opt-in MCP only) | Medium |
| Weak model | Commits + summaries, fallback to main | LIVE: `titleModel`/`summaryModel` tool-less backends (`Bootstrap.php:7711`, `:7745`, *verified*) | Small: reuse for commits |
| Architect/editor two-model | Built-in mode, editor model per model setting, auto-accept | PARTIAL: `architect` agent definition exists; preset `model` DORMANT (§2.1); no `EngineBackend::withModel()` (*verified*) | Medium |
| Context/file-finder mode | `ContextCoder` with convergence reflection | PARTIAL: `explore-codebase` skill; Task sub-agents | Small |
| Ask mode | `/ask` one-shot or sticky | PARTIAL: `plan` permission mode exists, but Ask→deny on the TUI path (§9.5) | Small |
| History summarisation | Background thread, recursive head/tail, budget 1/16 window (1k-8k), "I asked you…" | LIVE at 85% on submit, parked (blocks the prompt), six-facet record (§3.3) | Medium: no ahead-of-need summarisation; only between turns |
| Token counting | Real tokenizer (litellm), sampled for big text, `/tokens` breakdown incl. system | chars/4 + 10 per message, calibrated; **ignores system prompt and tool schemas** (§3.2) | Medium |
| `/tokens` per-component view | Yes | ABSENT (status bar total only) | Small, easy |
| Explicit cache breakpoints | 3 breakpoints on stable chunks | DORMANT (`CacheBreakpoints.php`, §3.5) | Medium (Bedrock/Vertex Anthropic) |
| Cache keep-alive pings | `--cache-keepalive-pings` (295 s) | ABSENT | Small |
| Output-limit continuation | Assistant-prefill "infinite output" | ABSENT: notice tells the user to raise `maxOutputTokens` (`Chat.php:15909-15916`, *verified*); Custom/OpenAI default `max_tokens` 4096 (§1.3) | Medium |
| Model metadata DB | litellm JSON, 24 h cache, local overrides, OpenRouter scrape | PARTIAL: per-provider constants; Custom fixed 128k, Sglang/Custom $0 (§1.3) | Medium |
| Per-model prompt quirks | `lazy`/`overeager`/`reminder`/`examples_as_sys_msg`/`system_prompt_prefix` | PARTIAL: SGLang per-family sampling defaults only | Small-medium |
| Final reminder at end of context | Edit rules re-sent as last system/user msg each request | PARTIAL: 70% context reminder only | Small |
| Fresh file contents each request | In-chat files re-read and re-sent; "Trust this message as the true contents" | ABSENT: stale `Read` outputs persist in history; attachments DORMANT (§3.1) | Medium |
| Read-only reference files | `/read-only`, `--read` | PARTIAL: forced `instructions` globs (§4 slot 6) | Small |
| File-mention auto-add | Assistant mentions → confirm add → reflect | n/a (the model has Read) | — |
| Instruction files (AGENTS.md/CLAUDE.md) | **None** (`--read CONVENTIONS.md` by convention) | LIVE, rich (§4) | sugar-crush ahead |
| Memory | None | LIVE store, project-scope recall (§5) | sugar-crush ahead |
| MCP / hooks / skills / sub-agents | None | LIVE (§2, §9) | sugar-crush far ahead |
| Sessions / resume | Markdown log + `--restore-chat-history` | LIVE SQLite sessions, picker, tabs (§8) | sugar-crush ahead |
| Watch-files AI comments | `AI!` / `AI?` from any editor | ABSENT | Medium (nice IDE bridge) |
| Voice input | Whisper | ABSENT | Low priority |
| `/web` HTML→Markdown | pandoc | ABSENT: WebFetch returns the raw body (§6.5) | Medium |
| Notifications | Bell or command | ABSENT (§10) | Small, easy |
| `$EDITOR` prompt editing | `/editor` | ABSENT (§10) | Small |
| Identifier autocompletion in input | Yes | ABSENT (`/` popup only) | Small |
| Copy context for a web UI | `/copy-context` | ABSENT | Small |
| Reply and commit language from locale | Yes | ABSENT | Small |
| Shell command approval | Always explicit, even under `--yes-always` | Bypass by default; TUI Ask→deny (§9.5) | Design difference |
| Pre-commit hooks honoured | **No** by default (`--no-verify`) | n/a | Do not copy |

---

## 13. Recommended improvements for sugar-crush

Ordered by value per effort. The project rule is "wire dormant code, don't delete", and each item says what existing code it builds on.

### P0

**1. Failed-edit reflection: "did you mean", "already applied", and uniform-indent recovery** — Effort **S**

- *Why.* With `maxSteps = 8` (`EngineBackend.php:262`), every blind `old_string` retry costs 12.5% of the turn. Today's error (`Edit.php:192-197`, *verified*) gives the model nothing to correct against, so it usually re-Reads the whole file (up to 1 MiB) and tries again.
- *How Aider does it.* `editblock_coder.py:79-124` (message), `find_similar_lines` `:602-628` (window score ≥ 0.6, ±5 lines unless first and last lines match), the "REPLACE lines are already in" check (`:108-111`), `replace_part_with_missing_leading_whitespace` `:243-293`.
- *Implement.* In `src/Tools/BuiltIn/Edit.php`, `count === 0` branch:
  1. If `str_contains($originalContent, $newString) && $newString !== ''`, append "new_string is already present in <path> — this edit may already be applied."
  2. **Uniform-indent retry.** Compute the minimum common indent of `old_string`/`new_string`. Find windows that equal `old_string` except for one constant leading-whitespace prefix. If **exactly one** such window exists, apply with `new_string` re-indented. Mark the result `File updated (indentation-adjusted): …` so the model learns.
  3. Otherwise compute the nearest window. Use a line-level LCS ratio — the LCS already exists in `Tools/Concerns/BuildsUnifiedDiff.php` and its `MAX_LCS_CELLS` guard bounds cost — or `similar_text()` per window. If the score is ≥ 0.6, append "Did you mean to match these actual lines from <path> (lines N-M)?" with the real lines **including line numbers**. Cap the excerpt at about 60 lines / 4 KiB.
  4. For `count > 1`, list the line numbers of each match, not just the count.
- Tests: an Edit unit test per branch.

**2. Built-in post-edit lint hook (syntax feedback in the tool result)** — Effort **S-M**

- *Why.* Under the default `bypass-permissions` mode, with no checkpoint, a syntactically broken PHP file is saved silently until the model happens to run tests. Aider's auto-lint is cheap and catches this class of error immediately.
- *How Aider does it.* `base_coder.py:1599-1607`, `linter.py:82-116` (`# Fix any errors below, if possible.` + `## Running: cmd` + output + `## See relevant lines below marked with █.` + scope context), `find_filenames_and_linenums` `:272-285`.
- *Implement.*
  - Add `src/Hooks/BuiltIn/LintAfterEditHook.php` as a **PostToolUse** built-in registered beside `ProtectFilesHook`/`ConfirmRemoveHook`/`AuditHook`, matching `Edit|Write`.
  - `Runtime::settle()` already appends a PostToolUse hook's `additionalContext` to the model-visible result (`src/Runtime.php:2322-2345`, *verified*), so no new plumbing is needed.
  - Default linters: `php -l <file>` for `.php` (always available); optional `lintCommands` map (`{"php": "vendor/bin/phpstan analyse --no-progress --error-format=raw {file}", "js": "node --check {file}"}`) in `LayeredSettings`, **user tier only**, because it executes commands. Exit 0 → nothing appended.
  - Non-zero → append Aider's format, with lines of interest shown ±3 lines with line numbers and a `█` marker. A PHP "scope header" can use `token_get_all` to find the enclosing `class`/`function` line.
  - Bound: 10 s per lint and 8 KiB output, reusing `ScriptHook`'s caps.
- Add `lintCommands` to `docs/SETTINGS.md`; the drift tests require the doc edit.

**3. A symbol-level, personalised repo map, as a tool first and a prompt section second** — Effort **L**

- *Why.* The current `<repo-map>` lists composer packages and PSR-4 directories. It has no symbols and is useless outside PHP (baseline §11.1 #15). Aider's map is what lets a model "know" an unseen codebase in 1-4k tokens.
- *How Aider does it.* `repomap.py:365-574` (graph and weights), `:629-706` (binary search to budget with 15% tolerance), `:233-264` (mtime cache), `:748-784` (scope-context rendering), `special.py` (important files first).
- *Implement.*
  1. `src/Context/SymbolIndex.php`: tag extraction.
     - **PHP** through `token_get_all()`, already used in-tree (`Runtime.php:487`, `Chat.php:7066`). Defs: `T_CLASS/T_INTERFACE/T_TRAIT/T_ENUM/T_FUNCTION` + the following `T_STRING`. Refs: `T_STRING` after `new`, `::`, `->`, `?->`, `extends`/`implements`/`use`, and bare calls.
     - **Other languages**: `universal-ctags --output-format=json` through `proc_open` when on `PATH` (defs), plus a cheap identifier-token regex for refs, mirroring Aider's pygments backfill (`repomap.py:338-363`).
     - Cache rows `(path, mtime, tags)` in SQLite under `~/.sugar-crush/cache/tags-<roothash>.sqlite`, keyed like Aider's `TAGS_CACHE`.
     - Files come from `git ls-files`, honouring the `.gitignore` filter that `Glob` already uses.
  2. `src/Context/SymbolRanker.php`: build referencer→definer edges with Aider's exact multipliers (mentioned ×10, specific-name ×10, `_`-private ×0.1, defined in >5 files ×0.1, focus-file referencer ×50, `sqrt(refs)`), then personalised PageRank by power iteration (20-30 iterations, damping 0.85, personalisation also used as the dangling vector). About 80 lines of PHP; no dependency needed.
  3. Render: per file, definition lines plus enclosing class headers, each line clipped to 100 chars. Binary-search the tag count to a budget, using the existing calibrated chars/4 estimate.
  4. **Expose it as a `RepoMap` tool first** (`src/Tools/BuiltIn/RepoMapTool.php`, `ParallelSafe`). Args: `focus_files[]`, `identifiers[]`, `max_tokens` (default 2048). A tool is cache-neutral for the system prefix and fits the agent paradigm: the model pulls the map when it needs orientation. Add one line to `Runtime::basePrompt()` ("call RepoMap before broad Grep/Glob exploration of an unfamiliar repo").
  5. Optionally add `src/Context/SymbolMapBlock.php` as a **PerSession** section with **no personalisation** beyond "important files" plus global rank (Aider's no-chat-files map), so it is byte-stable and cache-friendly. Keep `RepoMapBlock` as is (never remove); the new block sits beside it. Personalising per message would break the Static→PerSession→PerTurn prefix ordering, and Aider itself switches to `map_refresh=files` when caching (`main.py:954-955`).
  6. Seed personalisation with files touched this session (Read/Edit/Write calls are already tracked for nested-instruction injection, `InstructionFileLoader::loadForPath`) and the latest user message's identifiers.

**4. Git safety net: opt-in auto-commit with a weak-model message, dirty-commit first, and `/undo`** — Effort **M**

- *Why.* Out of the box sugar-crush edits under `bypass-permissions`, and `/rewind` restores only the transcript (§8). There is **no file-level recovery at all**. Aider's per-edit commits are its whole safety story.
- *How Aider does it.* `base_coder.py:2175-2189`, `:2375-2423`; `repo.py:131-373`; `commands.py:560-655`; `prompts.py:8-22`.
- *Implement.*
  - **Setting.** `autoCommit: off|turn|edit` in user settings, default `off` so the behaviour does not surprise people; recommend `turn`.
  - **At turn start** (`EngineBackend::runTurn()`, before the step loop): if `git status --porcelain` shows user changes to files the turn will touch (unknown in advance, so check per Edit/Write in a PreToolUse built-in), commit those paths first as `"chore: snapshot user changes before sugar-crush edit"`. This is the dirty-commit.
  - **At turn end**, if `Runtime::stepRequestedAWrite()` fired in any step: collect the paths changed by Edit/Write (Bash edits too, via `git status` diff), generate a message with **`Bootstrap::titleBackend()`** (*verified* tool-less weak backend) using Aider's commit prompt and the diff (the env block's diff capture can be reused), then commit with a `Co-authored-by: sugar-crush (<model>) <…>` trailer. Use `GitCommandHandlers::gitCommit()` (`src/MCP/GitCommandHandlers.php:421-444`, *verified*) and do **not** pass `--no-verify`.
  - **Record** hashes in the session store (the `SessionMeta` payload is DORMANT; wire it: `modifiedFiles` already exists there).
  - **`/undo`** in `Chat::dispatchCommand()`: apply Aider's five refusals (not ours, merge commit, dirty files, file absent in parent, already pushed), then `git checkout HEAD~1 -- files` + `git reset --soft HEAD~1`. Append a system row telling the model the change was reverted (Aider's `undo_command_reply`).
  - Update `docs/COMMANDS.md`, `docs/SETTINGS.md` and the drift tests.

### P1

**5. Harness-driven auto-test reflection** — Effort **M**

- *Why.* "Verify with tests" is advice in the base prompt. Aider makes it deterministic.
- *How Aider does it.* `base_coder.py:1616-1623`, `commands.py:993-1053`, `max_reflections = 3`.
- *Implement.* Settings `testCommand` and `autoTest` (user tier). At the end of `EngineBackend::runTurn()`, when writes occurred and the model's final step had no tool calls, run the test command (bounded, with a heartbeat so the 120 s no-progress watchdog is not tripped). On non-zero, append a `UserMessage` with Aider's `run_output` shape ("I ran this command:\n\n{command}\n\nAnd got this output:\n\n{output}") and **continue the step loop**, at most `maxTestReflections = 3`. Surface each round in the transcript.

**6. Ahead-of-need background summarisation** — Effort **M**

- *Why.* Today compaction runs at 85% **on submit**, and the user's prompt is parked while the summary model runs (`scheduleParkedCompaction`, §3.3). Aider summarises in a background thread as soon as history passes its budget, so the next request rarely waits.
- *How Aider does it.* `base_coder.py:1002-1034` (start, worker, end, with the "only apply if history unchanged" check) and `history.py:33-96` (verbatim tail = half budget, recursion depth ≤ 3).
- *Implement.* After `AssistantMsg` settles and the estimate crosses **70%** (the existing reminder threshold), schedule the same summary request `scheduleModelCompaction()` uses, as a background `Cmd`, and cache the result with the history fingerprint it was computed from. At 85% on submit, if the cached summary's fingerprint is a prefix of the current history, splice it in immediately (`applyModelCompaction`). Otherwise fall back to today's parked path. Also adopt Aider's **verbatim tail by token budget** instead of "last 10 pairs", which the baseline shows degenerates to about 10 tool rows (§3.3 step 3).

**7. Output-limit continuation ("infinite output")** — Effort **M**

- *Why.* Custom and OpenAI default to `max_tokens` 4096 (§1.3). A long Write is truncated mid-JSON and only a notice is shown (`Chat.php:15909-15916`, *verified*).
- *How Aider does it.* `base_coder.py:1492-1505`: on `finish_reason=length`, re-send with the partial reply as a trailing `assistant` message with `prefix=True` and concatenate.
- *Implement.* In `Runtime::runStreaming()` (`src/Runtime.php:1340-1459`, where `$lengthStopped` is tracked), when `lengthStopped`, there are no complete tool calls, and the provider declares `supportsAssistantPrefill()` (new `ProviderInterface` capability), re-issue with the buffer as a trailing assistant message. SGLang accepts `continue_final_message: true` in `chat/completions`; Anthropic routes accept a trailing assistant turn. Limit to 3 continuations. Keep the notice for providers without prefill.

**8. `/tokens` context-breakdown command, and count the system prompt and tool schemas** — Effort **S**

- *Why.* Users cannot see where the window goes. The estimator also ignores the system prompt and tool schemas (§3.2), so the thresholds fire late; Aider's `check_tokens` counts everything.
- *How Aider does it.* `commands.py:445-551`.
- *Implement.* `Runtime::assembleSections()` already returns per-section blocks (§4). Add `/tokens` listing each system section (base, maxims, tool guidance, repo-map, rules, instructions, memory, skills, env), tool schemas (JSON-encode `ToolSchema` output), history, and the largest individual messages ("/compact or /clear"), each with an estimate and %, plus remaining window. Separately, add a fixed `systemAndToolsEstimate` term to `Chat::estimateTokenCount()`, refreshed from the last turn's sections.

**9. Wire preset `model` and add an architect→editor mode** — Effort **M**

- *Why.* The two-model split is Aider's answer to "strong reasoners are bad at exact edit syntax". Sugar-crush already ships an `architect` definition, but preset `model` is DORMANT (§2.1).
- *How Aider does it.* `architect_coder.py:6-48`. Editor: fresh context, no repo map, the architect's reply as its only message, `editor_model_name` and `editor_edit_format` per model.
- *Implement.*
  - Add `EngineBackend::withModel(string $model)`, which rebuilds the provider through `ProviderFactory` with the same config (*verified* no such wither today).
  - Honour `AgentPreset::$model` in `TaskTool::runOnEngine()` (`TaskTool.php:557`) instead of only on the dormant fallback path.
  - Add `/architect <request>`: run the turn with only read-only tools (Read/Grep/Glob/RepoMap) and Aider's architect prompt as a system row, then automatically issue a Task to an `editor` preset (with `editorModel` from settings) whose prompt is the architect's final text verbatim.
  - Gate on `autoAcceptArchitect` (default true, as in Aider).

**10. Wire `CacheBreakpoints` (Anthropic routes) and optional keep-alive pings** — Effort **M**

- *Why.* `src/Providers/CacheBreakpoints.php` (690 lines) is DORMANT (§3.5). Aider's three-breakpoint layout is proven.
- *How Aider does it.* `chat_chunks.py:16-63`, `base_coder.py:1340-1394`.
- *Implement.* Call `CacheBreakpoints::apply($messages, $tools)` (*verified* public API at `:258`) in `BedrockProvider` and in `VertexProvider`'s Anthropic route. Mark the end of the Static+PerSession system sections and the last history message before the current turn; this matches Aider's "mark the last message of each stable chunk". Honour the already-documented `SUGARCRUSH_DISABLE_PROMPT_CACHE`. Keep-alive: a ReactPHP timer in `Chat` (295 s after the last completion, N pings via `cacheKeepalivePings`) that sends the cached prefix with `max_tokens: 1`. Useful only for Anthropic-routed providers, so P1/P2.

### P2

**11. Model metadata database** — Effort **M**. Load litellm's `model_prices_and_context_window.json` (fetched with a 24 h TTL into `~/.sugar-crush/cache/`, 5 s timeout, `{}` on failure, exactly as `models.py:161-221` does), overlaid by a user `modelMetadata` file. Use it in `ContextWindow::ofBackend()` (replacing Custom's fixed 128,000) and in `TokenTracker` pricing (replacing Sglang/Custom $0), keeping `modelPrices` as an override.

**12. Watch-files `AI!` / `AI?` comments** — Effort **M**. A `Chat::subscriptions()` poller (mtime scan of git-tracked files every 1-2 s, skipping files over 1 MB) using Aider's regex `(?:#|//|--|;+) *(ai\b.*|.*\bai[?!]?) *$` (`watch.py:69-71`). On `AI!`, enqueue a prompt built from `watch_code_prompt` plus each comment shown with ±3 lines (`watch.py:181-255`) through the existing prompt queue (`enqueuePrompt`). On `AI?`, the ask variant. Opt-in `watchFiles` setting.

**13. Pinned "working files" with fresh contents each request** — Effort **M**. `/add <path>` and `/read-only <path>` keep a session set. The latest contents render as a **PerTurn** section placed after history (Aider puts `chat_files` after `done` so edits invalidate only the tail), headed by Aider's *"Trust this message as the true contents of these files! Any other messages in the chat may contain outdated versions"*. This removes stale-Read confusion after Edits. Wire the dormant `Message::attachFile()` / `UserMessage::withFile()` path (§3.1) rather than inventing a new one.

**14. Per-model prompt quirks and an end-of-context reminder** — Effort **S**. Add `lazy`/`overeager` booleans to the SGLang per-family defaults (`ProviderFactory.php:951-957` area) and append Aider's two reminder texts (`base_prompts.py:12-20`) to the base prompt when set. Optionally send a short tool-use reminder as a final system row for models that drift on long contexts, as Aider's `reminder: sys` does.

**15. Small UX wins** — Effort **S** each:
- a terminal bell / `notificationsCommand` when a turn finishes and the window is unfocused (`io.py:1088-1103`);
- `/editor` to compose a prompt in `$EDITOR`;
- `/copy-context` (`commands.py:1638-1678`);
- reply and commit language from the locale (`base_coder.py:1094-1125`);
- shell name and timezone in `<env>` (Aider sends `Shell: SHELL=…`; the baseline lists both as absent);
- WebFetch HTML→Markdown (Aider uses pandoc; in PHP use `league/html-to-markdown` or a strip-tags fallback).

**16. Optional "context" pre-pass preset** — Effort **S**. Ship a `.sugar-crush/agents/context.md` preset carrying Aider's context prompt (`context_prompts.py`, quoted in §5) with read-only tools plus RepoMap. It returns "files to modify + relevant outside symbols", which the parent can use before editing.

**Do not copy:**
- `--git-commit-verify` defaulting to False, which silently skips the user's pre-commit hooks;
- Aider's "edits apply without approval" when sugar-crush is not in bypass mode;
- the full-file-in-context model, which does not scale like sugar-crush's tool reads.

---

## 14. Problems in sugar-crush exposed by this comparison

1. **Failed edits get no guidance.** `"Error: old_string not found in $path; file left unchanged"` (`Edit.php:192-197`) forces a full re-Read or a guess. With an 8-step default turn this turns indentation slips into truncated turns. Aider's reflection text shows how much a model can be helped. (Rec. 1)
2. **No harness-side verification of edits.** There is no syntax check, lint or test after Edit/Write (§6.6). A broken file can sit unnoticed for the rest of the turn, and across turns. (Rec. 2, 5)
3. **No file-level undo or checkpoint, and the default mode is bypass.** `/rewind` rewinds only the transcript and its reply says to `/branch` first, which also does not restore files. Combined with `bypass-permissions` (`Bootstrap.php:166`), an errant `Edit`/`Write`/`Bash` has no recovery path inside the tool. Aider's per-edit commits plus `/undo` are the minimum viable safety net. (Rec. 4)
4. **Token estimate excludes the system prompt and tool schemas.** The 70/85/95% thresholds are computed on history only (`Chat::rawTokenProxy`, §3.2). The system prompt (eleven sections, up to 64 KiB of rules, 8 KiB repo map, 4 KiB memory, `<env>` with up to 16 KiB of diffs) and every tool schema are invisible to it, so compaction triggers late and the "95% block" can be overshot by the provider. Aider counts the whole request with the real tokenizer before sending (`check_tokens`). (Rec. 8)
5. **Output truncation is reported, not handled.** A 4096 `max_tokens` default (Custom/OpenAI) with no continuation means a large Write tool call is cut mid-arguments (hence `flushTruncatedToolCalls`). The user is told to edit config. (Rec. 7)
6. **The repo map is not a map of code.** It is composer/PSR-4 only, has no symbols, is not personalised, and is empty for non-PHP repos. Models compensate with Grep/Glob sweeps that cost steps and tokens. (Rec. 3)
7. **Compaction keeps the wrong "recent" window and runs only at submit.** "Keep the last 10 pairs" degenerates to about 10 tool-output rows in tool-heavy sessions (§3.3), and a single long turn grows unbounded. Aider sizes its verbatim tail by *tokens* (half the history budget) and summarises off the critical path. (Rec. 6)
8. **Stale file contents persist in history.** After an Edit, earlier `Read` outputs of the same file stay verbatim in history (and across turns as plain assistant text, baseline §0.3), with nothing marking them outdated. Aider avoids this by construction: files are re-sent fresh, and the prompt says *"Any other messages in the chat may contain outdated versions of the files' contents."* A cheap mitigation short of Rec. 13: when compacting, or when replaying earlier turns, tag earlier Read rows of a since-edited path with "[outdated: <path> was edited later]".
9. **Model-capability metadata is hard-coded.** The Custom provider assumes a 128k window and $0 cost (§1.3). Misconfigured windows also mis-scale every compaction threshold. (Rec. 11)
10. **Cache layout is only implicit.** The Static→PerSession→PerTurn ordering is good, but `Runtime` is rebuilt every turn, so "PerSession" sections are recomputed per turn. Any byte drift — the memory snapshot ordering, the repo-map scan — silently breaks the prefix cache. Aider freezes its map under caching (`map_refresh=files`). Consider memoising PerSession sections per session, not per `Runtime`. Explicit breakpoints remain DORMANT. (Rec. 10)
11. **There is no deterministic "final reminder" to the model.** Aider re-sends its format rules as the last message every request, because weaker models drift as context grows. Sugar-crush's primary target (DeepSeek-V4 on SGLang) is exactly that kind of model, yet the only end-of-context injection is the 70% compaction hint. (Rec. 14)

---

## Appendix — Aider file index

| Area | Files |
|---|---|
| Turn and messages | `aider/coders/base_coder.py` (`run_one` :924, `send_message` :1419, `format_chat_chunks` :1226, `apply_updates` :2296, `auto_commit` :2375) |
| Cache layout | `aider/coders/chat_chunks.py` |
| Prompts | `aider/coders/*_prompts.py`, `aider/coders/shell.py`, `aider/prompts.py` (commit, summarize, undo) |
| Edit formats | `editblock_coder.py`, `udiff_coder.py`, `search_replace.py`, `patch_coder.py`, `wholefile_coder.py`, `editor_*_coder.py` |
| Modes | `architect_coder.py`, `ask_coder.py`, `context_coder.py`, `help_coder.py`, `commands.py` (`cmd_chat_mode` :138, `_generic_chat_command` :1206) |
| Repo map | `aider/repomap.py`, `aider/special.py`, `aider/queries/**/*-tags.scm` |
| History | `aider/history.py` |
| Lint and test | `aider/linter.py`, `commands.py` (`cmd_lint` :356, `cmd_test` :993, `cmd_run` :1013) |
| Git | `aider/repo.py`, `commands.py` (`raw_cmd_undo` :560, `cmd_commit` :337, `cmd_diff` :657) |
| Models | `aider/models.py`, `aider/resources/model-settings.yml` (357 entries), `aider/resources/model-metadata.json`, `aider/exceptions.py` |
| UX | `aider/io.py`, `aider/watch.py` + `watch_prompts.py`, `aider/voice.py`, `aider/scrape.py`, `aider/copypaste.py`, `aider/gui.py` |
| CLI | `aider/main.py`, `aider/args.py` |
