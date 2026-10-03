# Aider vs sugar-crush: competitor deep-dive

Feeds steps: 0.11, 1.A-1, 2.1, 2.3, 2.6, 2.7-1a, 2.7-2, 2.10, 3.A-2, 3.E, 3.G, 3.H, 3.I-1, 3.I-3, 4.1-1, 5.5-1, 5.5-2, 5.5-3, 5.5-4, 5.5-5, 5.6, 5.10, 5.13a, 5.14a, 5.14h, 5.14i

**Competitor:** Aider (`Aider-AI/aider`), Python. Clone at `/home/sites/crush-research-repos/aider`, HEAD `5dc9490bb`, version `0.86.3.dev`. All Aider paths are relative to the clone; sugar-crush paths are relative to `sugar-crush/`.

Aider is not a tool-calling agent: the harness applies text edit blocks, then auto-commits, auto-lints and optionally auto-tests, feeding failures back as a new user message ("reflection", at most `max_reflections = 3` per user message, `base_coder.py:101`, `run_one` `:924-944`). The pieces below are the ones a roadmap step reuses.

---

## 1. Failed-edit reflection and edit matching (→ 0.11, 3.I-1, 3.I-3)

**Matching cascade** (`replace_most_similar_chunk`, `editblock_coder.py:157-187`):
1. `perfect_replace`: exact line-tuple match.
2. `replace_part_with_missing_leading_whitespace` (`:243-273`): outdent SEARCH and REPLACE by their common minimum indent, find a window that matches except for a **uniform** leading-whitespace offset, then re-indent REPLACE by that offset.
3. Drop a spurious leading blank line in SEARCH, then retry 1 and 2.
4. `try_dotdotdots` (`:190-240`): SEARCH/REPLACE with matching `...` lines is applied piecewise. Each piece must be unique; unpaired or mismatched `...` raises.
5. *(Dead code)* an edit-distance matcher (0.8 `SequenceMatcher`) sits after an unconditional `return` (`:183-187`). Aider deliberately **does not** apply fuzzy edits; it reflects instead. Pitfall to avoid in 3.I: never apply a non-unique or similarity-only match.

Two-phase apply: `apply_edits_dry_run` first, then permission/dirty-commit per path, then the real write.

**The failure reflection** (`:79-124`), sent back as the next user turn:

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
- `find_similar_lines` (`:602-628`) slides a window the size of SEARCH over the file and scores it with line-level `SequenceMatcher.ratio()`; threshold **0.6**. If the best window's first and last lines equal SEARCH's, that window is shown verbatim; otherwise it is padded with **±5 lines**.
- The "already applied" check is `if updated in content and updated` (`:108-111`).
- "The other N blocks applied, don't re-send them" is the multi-edit (3.I-1 `edits[]`) equivalent of a partial-success report.

**Indentation-flexible matching** (udiff, → 3.I-1): `RelativeIndenter` (`search_replace.py:18-171`) rewrites indentation as deltas from the previous line, using a `←` outdent marker (or a private-use code point if `←` occurs), so an edit at the wrong absolute indent still matches. `search_and_replace` tries four preprocessing combinations (`:528-538`: strip blank lines × relative indentation) and refuses tiny (< 10 non-whitespace chars) or non-unique contexts. On failure `apply_partial_hunk` retries with progressively less context (`udiff_coder.py:282-309`). Errors `UnifiedDiffNoMatch` / `UnifiedDiffNotUnique` say "Use additional ` ` lines to provide context that uniquely indicates which code needs to be changed."

**Patch format** (OpenAI V4A, `patch_coder.py`, → 3.I-3): context search runs in fuzz tiers exact (0) → `rstrip` (1) → `strip` (100); an `*** End of File` anchor not found at EOF adds `+10_000`; `@@ scope` lines match exactly, then whitespace-insensitively (+1). Total fuzz is recorded on the `Patch`. Format rules: `*** Begin Patch` / `*** [Add|Update|Delete] File:` / 3 lines of context before and after, `@@ [CLASS_OR_FUNCTION_NAME]` when 3 lines are not unique, "Each file MUST appear only once in the patch."

**Recommendation (→ 0.11, 3.I-1).** In `src/Tools/BuiltIn/Edit.php`, zero-match branch:
1. If `str_contains($originalContent, $newString) && $newString !== ''`, append "new_string is already present in <path> — this edit may already be applied."
2. **Uniform-indent retry.** Compute the minimum common indent of `old_string`/`new_string`. Find windows that equal `old_string` except for one constant leading-whitespace prefix. If **exactly one** such window exists, apply with `new_string` re-indented. Mark the result `File updated (indentation-adjusted): …` so the model learns.
3. Otherwise compute the nearest window. Use a line-level LCS ratio — the LCS already exists in `Tools/Concerns/BuildsUnifiedDiff.php` and its `MAX_LCS_CELLS` guard bounds cost — or `similar_text()` per window. If the score is ≥ 0.6, append "Did you mean to match these actual lines from <path> (lines N-M)?" with the real lines **including line numbers**. Cap the excerpt at about 60 lines / 4 KiB.
4. For more than one match, list the line numbers of each match, not just the count.
- Tests: an Edit unit test per branch.

---

## 2. Post-edit lint feedback (→ 3.E)

**Default per file** (`linter.py` `lint`, `:82-116`):
- Python: tree-sitter `basic_lint` + `compile()` + `flake8 --select=E9,F821,F823,F831,F406,F407,F701,F702,F704,F706` (fatal errors only).
- Other languages: `basic_lint` walks the tree-sitter tree for `ERROR`/missing nodes.
- `--lint-cmd "lang: cmd"` overrides per language. The command runs with the filename appended; a non-zero exit counts as errors.

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

`find_filenames_and_linenums` (`:272-285`) pulls `file:line` pairs out of the linter text; `tree_context()` shows them with **3 lines of padding and their enclosing scopes** (`:234-256`). Auto-lint runs after every edit (`base_coder.py:1599-1607`).

**Recommendation (→ 3.E).**
- Add `src/Hooks/BuiltIn/LintAfterEditHook.php` as a **PostToolUse** built-in beside `ProtectFilesHook`/`ConfirmRemoveHook`/`AuditHook`, matching `Edit|Write`. `Runtime::settle()` already appends a PostToolUse hook's `additionalContext` to the model-visible result.
- Default linter: `php -l <file>` for `.php`. Optional `lintCommands` map (`{"php": "vendor/bin/phpstan analyse --no-progress --error-format=raw {file}", "js": "node --check {file}"}`) in `LayeredSettings`, **user tier only**, because it executes commands. Exit 0 → nothing appended.
- Non-zero → append Aider's format, lines of interest ±3 with line numbers and a `█` marker. A PHP scope header can use `token_get_all` to find the enclosing `class`/`function` line.
- Bound: 10 s per lint and 8 KiB output, reusing `ScriptHook`'s caps. Document `lintCommands` in `docs/SETTINGS.md` (drift tests).

---

## 3. Auto-test reflection (→ 3.H)

`--auto-test` / `/test` runs `test_cmd`; a non-zero exit adds the output to the chat and reflects, bounded by `max_reflections = 3` (`base_coder.py:1616-1623`, `commands.py:993-1053`). The `run_output` shape is "I ran this command:\n\n{command}\n\nAnd got this output:\n\n{output}".

**Recommendation (→ 3.H).** Settings `testCommand` and `autoTest` (user tier). When writes occurred and the model's final step had no tool calls, run the test command (bounded, with a heartbeat so the turn watchdog is not tripped). On non-zero, append a `UserMessage` in the `run_output` shape and **continue the step loop**, at most `maxTestReflections = 3`. Surface each round in the transcript.

---

## 4. Git: auto-commit, dirty-commit, `/undo`, `/diff` (→ 3.G, 3.A-2)

**Commit message** (`get_commit_message`, `repo.py:326-373`). The diff (`git diff HEAD -- files`, or index + worktree on an unborn branch) plus the exchange text go to the **weak model, then the main model**, skipping any model whose window the diff does not fit (`repo.py:342-363`). Prompt (`aider/prompts.py:8-22`, overridable with `--commit-prompt`):

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

`{language_instruction}` becomes `"\n- Is written in {lang}."`.

**Attribution** (`repo.py:149-200`): default trailer `Co-authored-by: aider (<model name>) <aider@aider.chat>`; otherwise author/committer names become `"<git user.name> (aider)"`.

**Bookkeeping.** The commit hash is recorded in `aider_commit_hashes`; the model is told `"I committed the changes with git hash {hash} & commit msg: {message}"` (`base_prompts.py:4`).

**Pitfall — do not copy.** `--git-commit-verify` defaults to False, so Aider passes `--no-verify` and skips the user's pre-commit hooks (`args.py:492-497`, `repo.py:278-279`).

**Dirty commits.** Before editing a file with uncommitted user changes, `check_for_dirty_commit` adds it to `need_commit_before_edits`; `dirty_commit()` commits the user's work first with its own message (`base_coder.py:2175-2189`, `:2411-2423`). AI and human changes never share a commit, which makes `/undo` precise.

**`/undo`** (`raw_cmd_undo`, `commands.py:560-655`) refuses when:
1. HEAD is the first commit;
2. HEAD is **not in `aider_commit_hashes`** ("The last commit was not made by aider in this chat session.", suggesting `/git reset --hard HEAD^`);
3. HEAD is a merge commit;
4. any file changed in it is **dirty now** ("Please stash them before undoing");
5. a file did not exist in the parent;
6. HEAD equals `origin/<branch>` (**already pushed**).

Then `git checkout HEAD~1 -- <files>` and `git reset --soft HEAD~1`. With `send_undo_reply`, the model is told: *"I did `git reset --hard HEAD~1` to discard the last edits. Please wait for further instructions before attempting that change again. Feel free to ask relevant questions about why the changes were reverted."*

**`/diff`** shows the diff since the HEAD recorded before the last message (`commit_before_message`) (→ 3.A-2).

**Recommendation (→ 3.G).**
- Setting `autoCommit: off|turn|edit` in user settings, default `off`.
- Dirty-commit: per Edit/Write (a PreToolUse built-in), if `git status --porcelain` shows user changes to the target path, commit those paths first as `"chore: snapshot user changes before sugar-crush edit"`.
- At turn end, when the turn wrote: collect the changed paths (Bash edits too, via `git status`), generate the message with the tool-less weak backend (`titleBackend`) using Aider's commit prompt and the diff, then commit with a `Co-authored-by: sugar-crush (<model>) <…>` trailer. Never pass `--no-verify`.
- Record commit hashes in the session store.
- `/undo` (shared with 3.A-2): Aider's refusals (not ours, merge commit, dirty files, file absent in parent, already pushed), then `git checkout HEAD~1 -- files` + `git reset --soft HEAD~1`, and append a row telling the model the change was reverted.
- Update `docs/COMMANDS.md`, `docs/SETTINGS.md` and the drift tests.

---

## 5. Ahead-of-need background summarisation (→ 2.10)

**Budget.** `max_chat_history_tokens = min(max(max_input_tokens / 16, 1024), 8192)` (`models.py:356-358`).

**When.** Every time an exchange moves into `done_messages`, `summarize_start()` (`base_coder.py:1002-1012`) checks `too_big()`; if over budget it summarises in a background thread (`summarize_worker`). `summarize_end()` (`:1024-1034`) joins the thread before the next request and applies the result **only if `done_messages` did not change meanwhile** (`if self.summarizing_messages == self.done_messages`).

**How** (`summarize_real`, `history.py:33-96`):
1. If the total is ≤ the budget and `depth == 0`, return unchanged.
2. If there are ≤ 4 messages or `depth > 3`, summarise everything.
3. Walk backwards, keeping a **verbatim tail** of up to `max_tokens // 2`. Move the split point back until the head ends on an assistant message.
4. The head (truncated to `model.max_input_tokens - 512`) is summarised. If summary + tail fits, return it; **otherwise recurse** on `summary + tail` with `depth + 1`.

Summarisation runs on the weak model with fallback to the main model if it fails or the input exceeds its window (`history.py:114-123`).

**Recommendation (→ 2.10).** After `AssistantMsg` settles and the estimate crosses **70%**, schedule the same summary request `scheduleModelCompaction()` uses as a background `Cmd`, and cache the result with the history fingerprint it was computed from. At 85% on submit, if the cached summary's fingerprint is a prefix of the current history, splice it in immediately (`applyModelCompaction`); otherwise fall back to the parked path. Size the verbatim tail by **token budget** (half the history budget) instead of "last N pairs", which degenerates to a run of tool rows in tool-heavy sessions.

---

## 6. Output-limit continuation and error classification (→ 2.7-2, 2.7-1a)

**Continuation** (`base_coder.py:1492-1505`). On `FinishReasonLength`, if the model supports assistant prefill, the partial reply is re-sent as a trailing `assistant` message with `prefix=True` and generation **continues**; the results are concatenated. Otherwise the turn ends with `show_exhausted_error()` (`:1628-1679`), which uses a 0.7 "fudge" heuristic to tell an input overflow from an output overflow.

**Retry classification** (`aider/exceptions.py:12-55`): each litellm exception maps to retry yes/no plus a description. Retried: rate limit, 5xx, timeouts, connection errors. Not retried: auth, bad request, not found, **context-window-exceeded**. Delay starts at 0.125 s and doubles until it exceeds `RETRY_TIMEOUT = 60` s (`base_coder.py:1449-1488`).

**Recommendation (→ 2.7-2).** When a step is length-stopped with no complete tool calls and the provider declares an assistant-prefill capability, re-issue with the buffer as a trailing assistant message. SGLang accepts `continue_final_message: true` in `chat/completions`; Anthropic routes accept a trailing assistant turn. Limit to 3 continuations; keep the notice for providers without prefill.

---

## 7. Token counting and `/tokens` (→ 2.1, 5.6)

- **Pre-flight check** (`check_tokens`, `base_coder.py:1396-1417`) counts the *whole* request (system, examples, files, map, history) with the real tokenizer through litellm before sending; over `max_input_tokens` it prints remediation (`/drop`, `/clear`) and asks "Try to proceed anyway?".
- `Model.token_count()` (`models.py:650-670`): `litellm.token_counter` for message lists, `litellm.encode` for strings; images use 170 tokens per 512² tile plus 85.
- **Sampled estimate** for big text (`RepoMap.token_count()`, `repomap.py:89-101`): exact below 200 chars; above that, sample ~1% of lines and extrapolate by character ratio — cheap enough inside a binary search (→ 5.5-3).
- **`/tokens`** (`commands.py:445-551`): a per-component table of tokens and cost — system messages, chat history ("use /clear to clear"), repository map ("use --map-tokens to resize"), each file ("/drop to remove") — with total, remaining window and max window. Each row carries the remedy.

**Recommendation (→ 5.6, 2.1).** `/tokens` (or `/context`) lists each system section (base, maxims, tool guidance, repo map, rules, instructions, memory, skills, env), tool schemas (JSON-encoded `ToolSchema` output), history, and the largest individual messages ("/compact or /clear"), each with an estimate and %, plus remaining window. The pressure estimate must include a system-prompt + tool-schema term.

---

## 8. Symbol-level repo map (→ 5.5-1, 5.5-2, 5.5-3, 5.5-4, 5.5-5)

**Inputs** (`Coder.get_repo_map()`, `base_coder.py:709-748`):
- `chat_files`: editable plus in-repo read-only files;
- `other_files`: every tracked file, minus `.aiderignore`, optionally `--subtree-only`;
- `mentioned_fnames`: paths and unique basenames in the current message, plus files whose stem (≥5 chars) equals a word in the message (`:684-707`);
- `mentioned_idents`: every `\W+`-split word of the current message (`:678-682`).

Fallbacks: if the result is empty, retry with no chat files (a global map); then retry with no hints.

**Tag extraction** (`get_tags_raw`, `repomap.py:279-363`):
- tree-sitter with the language's `*-tags.scm` query (`queries/`, including `php-tags.scm`). `name.definition.*` captures → **def** tags, `name.reference.*` → **ref** tags, each `(rel_fname, fname, line, name, kind)`.
- When a query yields defs but no refs, **pygments** backfills refs from every `Token.Name` token (`:338-363`).
- PHP's query captures class, function and method definitions, plus references for `new X`, function calls, `X::y()` and `$x->y()`.
- **Cache** (`get_tags`, `:233-264`): `TAGS_CACHE[fname] = {"mtime", "data"}` in diskcache (SQLite). On any SQLite error it rebuilds the cache dir, then falls back to an in-memory dict (`:177-215`). If more than 100 files are uncached on the first scan, it shows a progress bar.

**Graph and ranking** (`get_ranked_tags`, `:365-574`):
- `personalize = 100 / len(fnames)`. A file gets `personalize` if it is in the chat or mentioned, and another `+personalize` if any path component or basename (with or without extension) matches a mentioned identifier.
- A `networkx.MultiDiGraph` gets one edge **referencer → definer** per shared identifier. Edge weight is `mul * sqrt(num_refs)`, where `mul` starts at 1.0 and:
  - `×10` if the identifier was **mentioned** by the user;
  - `×10` if it is snake/kebab/camel case and ≥ 8 chars (a "specific" name);
  - `×0.1` if it starts with `_` (private);
  - `×0.1` if it is defined in more than 5 files (generic name);
  - `×50` if the **referencer is a chat file**.
- Definitions with no references get a `0.1` self-edge.
- `nx.pagerank(G, weight="weight", personalization=…, dangling=…)`. On `ZeroDivisionError` retry unpersonalised, then give up.
- Rank is distributed from each node over its out-edges onto `(definer, ident)` pairs, sorted; chat files are excluded from the output (their text is already sent).
- Files without tags are appended in node-rank order, then remaining files by name.
- **"Important files"** (`special.py`: README, `composer.json`, `package.json`, `pyproject.toml`, `.github/workflows/*.yml`, …) are prepended (`:656-662`).

**Budget sizing** (`get_ranked_tags_map_uncached`, `:629-706`): binary search over *how many ranked tags to render*, starting at `middle = min(max_map_tokens // 25, num_tags)`; each candidate is rendered and token-counted by sampling; keep the best tree ≤ budget and **stop early within 15%** (`ok_err = 0.15`).

**Rendering** (`to_tree`, `render_tree`, `:710-784`): per file, `grep_ast.TreeContext` prints the definition lines with their enclosing scopes and elides the rest; every line is truncated to 100 chars. Rendered trees are cached by `(rel_fname, lois, mtime)`. Output:
  ```
  aider/coders/base_coder.py:
  ⋮
  │class Coder:
  ⋮
  │    def get_repo_map(self, force_refresh=False):
  ⋮
  ```

**Budget defaults.** `max_input_tokens / 8` clamped to **[1024, 4096]** (`models.py:782-789`). With no chat files, multiplied by `map_mul_no_files` (CLI default 2), capped at `max_context_window − 4096` (`repomap.py:120-132`). Warn when the budget is > 2× recommended: "Too much irrelevant code can confuse LLMs."

**Refresh policy** (`:576-627`, `--map-refresh auto|always|files|manual`): `auto` recomputes every time unless the last computation took > 1 s, then serves cached results keyed by (chat files, other files, budget, mentions); `files` keys only on the file sets; `manual` reuses until `/map-refresh`. On `RecursionError` the map is disabled. **Under prompt caching Aider switches `auto` to `files`** (`main.py:954-955`) so the map's bytes stay cache-stable (→ 5.5-5).

**Recommendation.**
1. **5.5-1** `src/Context/SymbolIndex.php` (or `src/RepoMap/*`): PHP tags through `token_get_all()` — defs `T_CLASS/T_INTERFACE/T_TRAIT/T_ENUM/T_FUNCTION` + the following `T_STRING`; refs `T_STRING` after `new`, `::`, `->`, `?->`, `extends`/`implements`/`use`, and bare calls. Cache rows `(path, mtime, tags)` in SQLite under `~/.sugar-crush/cache/tags-<roothash>.sqlite`. Files from `git ls-files`, honouring the `.gitignore` filter `Glob` uses.
2. **5.5-2** Other languages: `universal-ctags --output-format=json` through `proc_open` when on `PATH` (defs), plus an identifier-token regex for refs, mirroring the pygments backfill.
3. **5.5-3** Ranker: referencer→definer edges with Aider's exact multipliers (mentioned ×10, specific-name ×10, `_`-private ×0.1, defined in >5 files ×0.1, focus-file referencer ×50, `sqrt(refs)`), then personalised PageRank by power iteration (20-30 iterations, damping 0.85, personalisation also as the dangling vector) — about 80 lines of PHP. Render definition lines plus enclosing class headers, clipped to 100 chars; binary-search the tag count to a budget with the calibrated chars/4 estimate.
4. **5.5-4** Expose it as a `RepoMap` tool first (`ParallelSafe`; args `focus_files[]`, `identifiers[]`, `max_tokens` default 2048) — cache-neutral for the system prefix. Add one base-prompt line ("call RepoMap before broad Grep/Glob exploration of an unfamiliar repo"). Seed personalisation with files touched this session and the latest user message's identifiers.
5. **5.5-5** Optional byte-stable **PerSession** block with **no personalisation** beyond important files plus global rank, beside the existing `RepoMapBlock` (never remove it). Per-message personalisation would break the prefix ordering.

---

## 9. Cache-stable prompt layout (→ 1.A-1)

Chunk order (`chat_chunks.py` `all_messages()`, `:16-26`), most stable first:

```
system + examples + readonly_files + repo(map) + done(history) + chat_files + cur + reminder
```

Editable files sit **after** the history on purpose: editing a file invalidates only the `chat_files` segment and later, so the system, map and history prefix stays cached. The map is frozen under caching (§8). Lesson for 1.A-1: memoise PerSession sections per *session*, not per `Runtime`, so a per-turn rebuild cannot drift their bytes (memory snapshot ordering, repo-map scan).

---

## 10. Per-model prompt quirks and end-of-context reminder (→ 5.10)

- **`lazy_prompt`** (verbatim): *"You are diligent and tireless! You NEVER leave comments describing code without implementing it! You always COMPLETELY IMPLEMENT the needed code!"*
- **`overeager_prompt`** (verbatim): *"Pay careful attention to the scope of the user's request. Do what they ask, but no more. Do not improve, comment, fix or modify unrelated parts of the code in any way!"*
- Both fill `{final_reminders}` in the system prompt (`fmt_system_prompt`, `base_coder.py:1174-1224`); `model-settings.yml` sets `lazy`/`overeager` on 79 entries. Optional `system_prompt_prefix` per model (e.g. `"Formatting re-enabled. "` for o3-mini).
- **Final reminder**: the format rules are re-sent at the end of every request, either as a final `system` message (`reminder: sys`, 43 models) or appended to the last user message (`reminder: user`, default), and only if it still fits in `max_input_tokens` (`:1294-1329`). Recency helps weaker models keep to the rules as context grows.

**Recommendation (→ 5.10).** Add `lazy`/`overeager` booleans to the SGLang per-family defaults (`ProviderFactory`) and append the two texts to the base prompt when set. Optionally send a short tool-use reminder as a final row for models that drift on long contexts.

---

## 11. Weak model and editor model (→ 4.1-1, 3.G)

- `Model.weak_model` (`models.py:603-623`, per model in `model-settings.yml`, e.g. `claude-sonnet-4-6` → `claude-haiku-4-5`) is used for commit messages and history summaries, as a `[weak, main]` fallback chain: the main model is used if the weak one fails or the input exceeds its window.
- The architect→editor split runs the editor on `main_model.editor_model` with a **fresh, empty context** (no history, no repo map, `cache_prompts=False`), the architect's full reply as its only user message, and an editor-specific edit format (`architect_coder.py:6-48`; `get_editor_model()` `models.py:625-645`). It returns only side effects (edits, commits) and copies cost back to the parent.

**Recommendation (→ 4.1-1).** Add `EngineBackend::withModel(string $model)`, which rebuilds the provider through `ProviderFactory` with the same config, and honour `AgentPreset::$model` in `TaskTool::runOnEngine()`.

---

## 12. Model metadata database (→ 5.13a)

`ModelInfoManager` (`models.py:161-326`): downloads litellm's `model_prices_and_context_window.json` into `~/.aider/caches/` with a **24 h TTL** and a 5 s timeout, writing `{}` on failure so it does not retry each launch. Local `--model-metadata-file` entries win. OpenRouter models use a cached API database, then scrape the model page. Window sizes, repo-map budget, history budget and costs all derive from it.

**Recommendation (→ 5.13a).** Fetch the same JSON with a 24 h TTL into `~/.sugar-crush/cache/` (5 s timeout, `{}` on failure), overlaid by a user `modelMetadata` file. Use it in `ContextWindow::ofBackend()` (replacing Custom's fixed 128,000) and in `TokenTracker` pricing (replacing the $0 defaults), keeping `modelPrices` as an override.

---

## 13. Small UX (→ 5.14a, 5.14h, 5.14i)

- **Notifications (→ 5.14a).** `--notifications` rings the terminal bell, or runs `--notifications-command`, when a reply is ready (`io.py:1088-1103`); the bell also rings on each confirmation question.
- **`/editor` (→ 5.14h)** opens `$EDITOR` to compose the prompt.
- **Watch files (→ 5.14i)** (`watch.py`, `--watch-files`):
  - A `watchfiles` thread watches the repo, ignoring gitignored files, `.aider*`, editor temp files, `vendor/`, `node_modules/` and files over 1 MB.
  - A comment matching `(?:#|//|--|;+) *(ai\b.*|.*\bai[?!]?) *$` (`watch.py:69-71`) adds the file to the chat. Ending with `AI!` requests a code change; `AI?` asks a question.
  - It interrupts the input prompt (`io.interrupt_input()`, `watch.py:142`) and sends `watch_code_prompt` ("I've written your instructions in comments in the code and marked them with "ai" … After completing those instructions, also be sure to remove all the "AI" comments from the code too.") or `watch_ask_prompt`, plus every AI comment shown with tree context and █ markers (`watch.py:181-255`).
  - Recommendation: a `Chat::subscriptions()` poller (mtime scan of git-tracked files every 1-2 s, skipping files over 1 MB); on `AI!`, enqueue the prompt plus each comment with ±3 lines through the existing prompt queue; on `AI?`, the ask variant. Opt-in `watchFiles` setting.

---

## 14. Stale file contents (→ 2.3, 2.6)

Aider re-sends in-chat files fresh every request under this header (`base_coder.py` `format_chat_chunks`):

> I have \*added these files to the chat\* so you can go ahead and edit them.
>
> \*Trust this message as the true contents of these files!\*
> Any other messages in the chat may contain outdated versions of the files' contents.

Use the same wording to head re-injected files after compaction (2.6). For 2.3, when pruning or replaying, tag earlier Read rows of a since-edited path with "[outdated: <path> was edited later]" rather than leaving them verbatim.
