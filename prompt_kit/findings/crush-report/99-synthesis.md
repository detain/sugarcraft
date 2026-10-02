# sugar-crush Competitive Analysis: Combined Report

**Date:** 2026-10-01 · **Subject:** `sugar-crush` (master @ `f2884ae7d`)

**Method.** The research ran in four stages:
1. One agent wrote a sugar-crush **feature baseline**. It traced each feature in source from the entry point and marked it LIVE, PARTIAL, DORMANT or ABSENT.
2. Twelve agents each studied **one competitor** in depth against that baseline. Each one followed the shared brief in `prompt_kit/briefs/crush-report-comparison-brief.md`.
3. Three agents wrote **design reports** for features the user requested on top of that: a settings pane with more configurable behaviour; a WebSocket server mode with a Vue web UI; and session management with live sub-agent activity lines and a clickable agent view with direct chat.
4. Five agents **audited sugar-crush's own code** for new defects, one per area.
5. Every report was then merged into this document.

**Competitors studied:**
- Claude Code (documentation only)
- opencode
- opencode-dynamic-context-pruning (DCP)
- Kilo Code (current version plus legacy)
- Cline (4.x SDK plus classic v3.89)
- OpenHands (software-agent-sdk plus legacy 0.62 plus CLI)
- Zed's agent panel
- Goose
- Aider
- nanobot
- OpenClaw
- DeepSeek Harness (dsh)

**Where things live:**
- Source clones: `/home/sites/crush-research-repos/`
- The individual reports: `prompt_kit/findings/crush-report/NN-*.md`
- Appendices A–U of this file reproduce all 21 reports verbatim: the baseline (A), the 12 comparisons (B–M), the 3 design reports (N–P) and the 5 code audits (Q–U).

**How to read this.**
- **Part I** is the executive summary.
- **Part II** lists the problems found in sugar-crush, de-duplicated across all reports.
- **Part III** is one prioritised roadmap merged from 12 sets of recommendations.
- **Part IV** goes topic by topic through the areas the user asked about: agent loop, sub-agents and messaging, context and compaction (including agent self-pruning), prompt generation, memory, git, skills and extensibility, tools, permissions and UX.
- **Part V** says what each competitor is best at.
- **Part VI** lists patterns not to copy.
- **Part VII** lists what sugar-crush already does better.
- **Part VIII** covers the user-requested settings pane, configurability, server mode, web UI, session management and live agent view.
- **Part IX** summarises the code-audit findings.
- **The appendices** hold the full detail.

**Confidence notes.** Every comparison agent re-checked in source the sugar-crush claims its recommendations depend on. A few findings come from reading the code and were **not** reproduced at runtime. They are marked *(inferred)* below and should be confirmed with a test or a measurement before anyone acts on them.

---

# Part I — Executive summary

## The ten findings that matter most

1. **The forked turn's socket already works in both directions, but only the child ever writes.** Several gaps follow from that one-way use:
   - The TUI cannot answer a permission prompt, so every Ask becomes a deny.
   - That is why the default mode is `bypass-permissions`.
   - Prompts typed mid-turn cannot steer the running turn.
   - Esc Esc has to kill the whole turn.
   - Sub-agents cannot be messaged while they run.

   **10 of 12** reports call a parent→child frame channel (approvals, steer, cancel-tool, mailbox) the highest-leverage fix. The socket is `stream_socket_pair` at `EngineBackend.php:1343`. `docs/PERMISSIONS.md` blames a "one-way frame channel", but that limitation is self-imposed.

2. **The SGLang prompt cache is probably invalidated for the whole conversation on most steps** *(inferred from prompt layout; measure `cached_tokens` across a write step to confirm)*. Two separate causes:
   - `<env>` (git status, `log -5`, post-write diffs) sits inside the **system message** and is re-rendered every step.
   - `SglangProvider::formatMessages()` (`:1573-1612`) moves **every in-history `SystemMessage`** into message 0: reminders, compaction notices, `_Request cancelled._`, launch and spend-cap notices.

   On a 1M-token DeepSeek-V4 session that means re-prefilling everything. Eight reports flag it. Every serious competitor (Claude Code, Goose, nanobot, dsh, opencode) keeps the system prompt byte-stable and **appends** changing context as a user-side message.

3. **DeepSeek reasoning is dropped between tool steps.** `SglangProvider.php:1580-1585` never sends `reasoning_content` back on assistant tool-call messages. DeepSeek's own harness always does. Expect worse multi-step coherence when thinking is on, and the V4 default effort is `max`. *(Flagged by the dsh report; a cheap fix.)*

4. **Tool history is lossy across turns.** `Chat::toolResultMessage()` stores tool output as an assistant row. `EngineBackend::toTypedMessages()` maps rows by role only, so the next turn sees earlier tool output as the assistant's own prose, with no tool name, arguments or call id. The data is already persisted (`Message::jsonSerialize` keeps `toolResults[].id/name/arguments`); it is only thrown away at conversion. This blocks structured pruning, dedup, resumable approval and loop detection over history. *(8 reports.)*

5. **Context is managed only between user turns.** Compaction runs only in `Chat::submit()`, using a chars/4 estimate that ignores the system prompt and tool schemas. Within one turn the context grows without limit, and nothing recovers from a context-overflow error. `removeToolResults()` matches a message shape that is never produced, so it is a no-op. The default `maxSteps = 8` hides all of this, and it also truncates ordinary multi-file work. **11 of 12** reports ask for step-level pressure checks, tool-output pruning and overflow recovery, and for a higher step cap once those exist.

6. **Nothing detects loops, and the step cap is the only brake.**
   - The cap is 8 by default. opencode, Cline, OpenClaw and dsh have no cap at all; OpenHands uses 500, goose 1000, nanobot 200.
   - None of the hash-based repeat detectors those agents use exist here.
   - The spend cap is no backstop on the primary provider, because SGLang and Custom report $0.

7. **A Bash command that prints nothing kills the whole turn.** Bash has no per-command timeout, and sequential tools send no heartbeat. So `composer install` or `phpunit` running quietly for 120 s trips the `COMPLETE_TIMEOUT_SECONDS` watchdog, which SIGKILLs the turn child. **10 reports.** The common fix: a `timeout` parameter, heartbeats, auto-backgrounding with a job/process tool, and output spilled to a file.

8. **A permissive default with no undo.** `bypass-permissions` plus `/rewind` that restores only the transcript means an errant Edit, Write or Bash cannot be recovered. Cline, opencode, Zed, Kilo, Aider and Claude Code all ship file checkpoints (shadow git, `git stash create` refs, or per-file snapshots) because, in Cline's own docs, "checkpoints make auto-approve practical".

9. **Sub-agents are synchronous and closed off.**
   - Task runs in parallel and can be resumed, but only after failure.
   - The parent can't message a child, a child can't spawn further sub-agents, and only the final text comes back, unhardened.
   - Preset `model`, `permissionMode`, `effort`, `isolation` and `background` are silently ignored, and `Bash(git *)` grants all of Bash.
   - Parallel fan-out has no cap.
   - The infrastructure for real orchestration already exists, dormant: `Mailbox`, `TaskList`, `TeamManager`, `SuspendedDelegations`, `BackgroundSupervisor::reconnect()`. OpenClaw, dsh, Goose, Kilo and Cline show the target design: background children, send and steer, wait, list, cancel, and announce-on-settle.

10. **The user's headline wish — agents compacting their own history — is designed in detail.** The DCP report (Appendix D, §13.2) gives a complete design:
    - stable per-row IDs plus unique tool-call IDs;
    - a non-destructive `ContextLedger` and a `ContextProjector` applied before each request;
    - automatic dedup, stale-read, superseded-write and failed-input strategies;
    - agent-callable `Prune` and `Compress` tools, applied mid-turn;
    - anchored nudges at absolute token thresholds;
    - `/compress`, `/decompress`, `/sweep` and `/context` commands;
    - a full test list.

    Kilo legacy (a `condense` tool with a user-approved preview) and Goose (agent-visible vs user-visible flags) supply the remaining pieces.

11. **The code audit found about 136 new defects** (Part IX). 129 of them, including the one Critical and all 20 High items, are already fixed on master, along with 4 more defects found while fixing them: MCP interoperability with official-SDK servers (nested empty arguments included), fork-shared MCP and LSP connections, silent provider errors (in sub-agents too), invalid UTF-8 (command backends included), the permission bypasses (including `$(…)`, backticks and redirects in allow rules), git MCP option injection, the repo-supplied terminal escapes, unfenced repo skill descriptions, Esc Esc tool placeholders that never healed, raw CR and C1 controls reaching the terminal, a turn kill that left its commands running, streamed tool calls dropped on `stop`, built-in skills that told every project to `git clean -fd`, the full-history markdown re-render on every frame, `error_log()` output painted over the TUI, env-block git calls that honoured the user's git config and took `index.lock`, PostToolUse blocks that did nothing, hook input that defeated grep-style deny hooks, a hostile `.gitignore` that stalled Glob for minutes, prompt hooks and custom-command shell blocks that froze the TUI inside `update()`, forks that carried no conversation, the multi-second `/branch` freeze, Vertex quota errors that were never retried, `claude-mcp` calls that gave up after one second, a stray stdout line that aborted MCP requests, workflow pause and resume that skipped the failed stage, uncapped `CLAUDE.md`/`AGENTS.md` and `@imports`, Edit and Write that read huge files whole and truncated files in place, file tools that hung on a FIFO, mid-session path rules that never reached the agent, a status bar and session tab strip wider than the terminal, recovered tool-call markup left in the reply and sent twice, MiniMax parameters turned into arrays, malformed tool arguments that ran the tool with `[]`, a `claude-code` provider that could not stream, menu commands that erased the draft, background sessions nothing could stop and IPC directories left in `/tmp`, a symlinked `config.json` replaced on the first write, glob metacharacters in the checkout path that hid every repo memory note, a Claude memory import that imported nothing, repo memory framed as the user's own notes, WebFetch results that were 32× the Bash cap or reported error pages as success, tool output that could forge a "refused by policy" verdict, a Task grant memo and a Chat "Always" grant that silenced user-hook asks, an accept-edits mode that allowed `rm` but asked for Edit, WebFetch counted as read-only, provider API keys in the Bash and hook environment, cancelled sub-agent workers whose commands kept running, a cleartext default search endpoint, forged image markers that repainted images and blanked Nerd Font glyphs, OAuth logins that failed on path-bearing MCP URLs or lost another process's tokens, a single malformed agent preset that hid every preset, `/tmp` names one user could block for everyone, a sub-agent map that never shrank, the SGLang `max_tokens: 4096` default, UI-only notices fed to the compaction summary, project MCP servers whose changed command ran with no new consent, project memory notes that reached every other repository, `.sugar-crush/*` lookups that missed the repo root on a subdirectory launch, title and summary models a project could pick on the user's key, sub-agent path grants a symlink could launder, workflow retries that never ran, a user `modelPrices` setting that never reached Vertex or Bedrock, a context calibration inflated by sub-agent tokens, and a skill launch notice that called a shadowed skill unreadable. Waves 2 to 6 found 23 more, smaller defects while fixing these, and all 23 are fixed too (a `Width::wrap()` hang, bidi overrides and lone C1 bytes on screen, an empty permission-modal value, uncapped skill file reads, nested empty maps replayed as `[]`, candy-shine streaming that broke its `render()` law, `claude-code` turns that reported 0 tokens, `/workflow pause` refused mid-run, stale docs among them); waves 7 and 8A found 3 more, all fixed in wave 8B (MCP-10, A26, F-D1); wave 8B found 1 (B8, the LSP lock-file leak, fixed in wave 9); wave 9 found 2 (15b-35, CLI-3). About 9 remain, including:
    - **Two TUIs on one session still overwrite each other's transcript** (the writer lock, a read-only second TUI with a fork offer, is scheduled for wave 10).
    - **The TUI engine path still refuses every Ask** (Part II #1), which since wave 8A includes WebFetch under `default` and `plan` and every MCP call under `auto`. Path deny rules now match respellings in sub-agent gates too.
    - **The status bar shows the static default model id** while the SGLang server's served model is discovered only inside the turn child (15b-35), and **the launch-notice cap (24) is below its new worst case (26)** (CLI-3).
    - **Gemini 2.5's output budget and `thinkingBudget` are not yet checked live** (A21 (b)).

    About two thirds are reproduced with scripts. No Critical, High or Medium-High item remains (IX.3, IX.4).

12. **The features you asked for are designed and slotted into the roadmap** (Part VIII):
    - a schema-driven settings editor, built entirely from SugarCraft libraries already in the dependency tree;
    - about 75 behaviours made configurable;
    - `sugarcrush serve`: ReactPHP WebSocket server, JSON-RPC, replayable event log, approvals over the wire;
    - the `sugar-crush-web` Vue 3 multi-session UI;
    - a session picker with search and inline rename;
    - live per-agent activity lines;
    - a clickable Agent View for chatting with sub-agents directly.

    All of them share one foundation with the top comparison finding: the two-way parent↔child frame channel.

## Consensus matrix

● = recommended in the report's §13/§14 · ◐ = partially or indirectly · blank = not raised.

| Issue / improvement | CC | OC | DCP | Kilo | Cline | OH | Zed | Goose | Aider | nano | Claw | dsh | Σ |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Parent→child channel (approvals + steering) | ● | ● | | ● | ● | ● | ● | ● | | ● | ● | ● | 10 |
| Step-level context management + overflow recovery | ● | ● | ● | ● | ● | ● | ● | ● | ◐ | ● | ● | ● | 11 |
| Raise `maxSteps` + graceful last-step summary | ● | ● | | ● | ● | ● | ● | ● | ◐ | ● | ● | ● | 11 |
| State-oriented, cache-reusing compaction summary | ● | ● | ● | ● | ● | ● | ● | ● | ◐ | ● | ● | ● | 11 |
| Bash timeout + heartbeat + background jobs | ● | ● | | ● | ● | ● | ● | ● | | ● | ● | ● | 10 |
| Read offset/limit + line numbers | ● | ● | | ● | ● | ● | ● | | | ● | ● | ● | 9 |
| Prune old tool outputs (fix `removeToolResults`) | ● | ● | ● | ● | ● | ◐ | | ◐ | ◐ | | ● | ● | 9 |
| Spill oversized output to file + cap MCP output | ● | ● | | ● | ● | ● | | ● | | ● | ● | ● | 9 |
| Fuzzy Edit + informative edit-failure feedback | | ● | | ● | ● | ● | ● | ● | ● | ● | ● | | 9 |
| Wire `CacheBreakpoints` (Anthropic routes) | ● | ● | ● | | ● | ● | | ● | ● | ● | ● | | 9 |
| Background sub-agents + messaging (wire Mailbox/TaskList/Team) | ● | ● | | ● | ● | ◐ | ◐ | ● | | ● | ● | ● | 9 |
| Honour preset `model`/`permissionMode` | ● | ● | | | ● | ● | ● | ● | ● | | ● | ● | 9 |
| Cache-stable prompt prefix (`<env>` out, no hoisting) | ● | ● | ● | | | | ● | ● | ◐ | ● | | ● | 8 |
| Structured cross-turn tool replay | | ● | ● | | ● | ● | ● | ● | | ● | | ● | 8 |
| Token estimate counts system + tools; `/context` breakdown | ● | | ● | ● | | | ● | | ● | ● | ● | ● | 8 |
| Todo / plan tool (wire `TaskList`/`SessionMeta::$tasks`) | ● | ● | | ● | ● | ● | | ● | | | ● | ● | 8 |
| File checkpoints + `/rewind --files` / `/undo` | ● | ● | | ● | ● | ◐ | ● | | ● | | | ● | 8 |
| Doom-loop / repeat-call detector | | ● | | ● | ● | ● | | ◐ | | ● | ● | ● | 8 |
| Wire `Stop` hook; `/goal` loop | ● | | | ● | ● | ● | | ● | | ● | | ● | 7 |
| Argument-scoped permission rules + shell splitting | ● | | | ● | ● | ● | ● | | | | ● | ● | 7 |
| Remove hard-coded SugarCraft Bash/PR guidance | ● | ● | | ● | | | | | | ● | ● | ● | 6 |
| File-changed-since-read / staleness check | ● | ● | | ● | ● | | ● | | | | | ● | 6 |
| Memory: fix scope bug, inject index, auto-memory, search | ● | | | ● | | ● | | | | ● | ● | | 5 |

---

# Part II — Problems found in sugar-crush (de-duplicated)

Severity reflects user impact on the live default path.

**Flagged by** gives the report numbers, which match the appendix letters:

| # | Report | | # | Report |
|---|---|---|---|---|
| 01 | Claude Code (CC) | | 07 | Zed |
| 02 | opencode (OC) | | 08 | Goose |
| 03 | DCP | | 09 | Aider |
| 04 | Kilo Code | | 10 | nanobot |
| 05 | Cline | | 11 | OpenClaw |
| 06 | OpenHands (OH) | | 12 | DeepSeek Harness (dsh) |

| # | Severity | Problem | Evidence | Flagged by |
|---|---|---|---|---|
| 1 | **High** | The TUI cannot ask permission. Every Ask becomes a deny, which forces `bypass-permissions` as the default. Stricter modes are unusable interactively, and plan mode can't be used in the TUI. | `Runtime::settleAsk()` `src/Runtime.php:2629-2669`; `Bootstrap.php:166`; socket `EngineBackend.php:1343` | 01 02 04 05 06 07 08 11 12 |
| 2 | **High** | The prompt-cache prefix is invalidated on most steps: `<env>` is re-rendered inside the system message, and SGLang moves every history System row into message 0 *(inferred; measure)* | `SglangProvider.php:1573-1612`; `EnvironmentBlock.php:993/1009` | 01 02 03 07 08 10 12 |
| 3 | **High** | `reasoning_content` is not passed back on DeepSeek tool-call steps | `SglangProvider.php:1580-1585` | 12 |
| 4 | **High** | Cross-turn tool replay is lossy (tool output arrives as assistant prose) | `Chat.php:4017-4023`; `EngineBackend.php:2071-2083` | 02 03 05 06 07 08 10 12 |
| 5 | **High** | No context management inside a turn and no overflow recovery. Task sub-agents (up to 50 steps) have none at all. | baseline §3.3 | all |
| 6 | **High** | `maxSteps = 8`. Truncation returns the last assistant text, often an empty or "let me check…" reply, with no final summary. A truncated turn can't be resumed. | `EngineBackend.php:262`, `:1017-1023` | all |
| 7 | **High** | A silent Bash command kills the whole turn at 120 s; Bash has no timeout | `EngineBackend.php:99`, `:1444-1455`; `Runtime::executeSequentially` `:1748` | 01 02 04 05 06 07 08 10 11 12 |
| 8 | **High** | No file-level undo while the default is bypass; `/rewind` restores only the transcript | `Chat.php:12317-12424` | 01 02 04 05 07 09 |
| 9 | **High** | The agent can write its own policy files (`settings.json` tiers, `.mcp.json`, `.sugar-crush/{skills,commands,rules}`), so it could give itself trust | `ProtectFilesHook::WRITE_ONLY_PATTERNS` `:83-86` | 07 |
| 10 | Med-High | No loop or stuck detection anywhere | grep of `src/` | 02 04 05 06 10 11 12 |
| 11 | Med-High | MCP results are uncapped and MCP calls have no timeout | `McpToolBridge.php:587-622` | 01 02 04 05 06 08 10 11 12 |
| 12 | Med-High | Tool-call ids are not unique on the DSML and MiniMax parsers: `dsml_call_0` repeats every response, which can confuse `HistorySanitizer` *(inferred)* | `DsmlToolCallParser.php:335`; `MinimaxXmlFallbackToolCallParser.php:203`; `HistorySanitizer.php:82-96` | 03 |
| 13 | Medium | The SugarCraft-specific git/PR workflow (`unset GITHUB_TOKEN`, `gh pr merge`, composer rules) is sent to **every** project | `Bash.php:124-163` | 01 02 04 10 11 12 |
| 14 | Medium | `/memory add` defaults to user scope, which is never injected; imported memories land in agent scope, also never injected; recall is project-only, newest 12 | `MemoryBlock.php:213-229` | 01 04 06 10 11 |
| 15 | Medium | `removeToolResults()` is a no-op; "keep 10 pairs" can mean the last ~10 tool rows; the tail is not token-budgeted | `ContextCompactor` (baseline §3.3) | 01 02 03 04 07 09 |
| 16 | Medium | `removeNavigationSteps()` drops any message with a line starting `rm`/`mv`/`cp`/`mkdir`/`ls` (including `lsof…`), including user requests, from compaction input *(inferred)* | `ContextCompactor.php:1037-1110` | 01 |
| 17 | Medium | `isFileReadMessage()` guesses: any content with `<?php`, or a path-like line, can be collapsed to `[file: …]` | `ContextCompactor::compactFileReferences()` `:936-1000` | 05 |
| 18 | Medium | The compaction summary keeps history but not state: no current work, next step or pending tasks; the latest user request is not re-appended verbatim; no audit of the summary | `Chat.php:10569` | 01 04 06 08 11 12 |
| 19 | Medium | The compaction summariser uses a different prompt and prefix, so none of the cache is reused | `Chat.php:10807` | 01 10 12 |
| 20 | Medium | Compaction overwrites the displayed and persisted history: scrollback is lost, and `/rewind` checkpoints after compaction hold the compacted text *(partly inferred)* | `Chat::compactionChanges` `:10477-10565` | 05 08 |
| 21 | Medium | The token estimate ignores the system prompt and tool schemas; percentage thresholds on a 1M window fire at ~734k tokens | `Chat::rawTokenProxy` `:14734-14742` | 03 04 07 09 11 |
| 22 | Medium | Sub-agent output goes to the parent unescaped, with no "no authority" framing; `/websearch` results are injected as user+assistant pairs | `TaskTool.php:604-629`; `Chat.php:9998` | 01 04 |
| 23 | Medium | Preset `model`/`permissionMode`/`effort`/`isolation`/`memory`/`background` are inert; preset `tools: Bash(git *)` grants all of Bash (*permission rules* are argument-scoped, and `docs/PERMISSIONS.md` now says so; see IX.1); `disallowedTools` is ignored without `tools:`; a read-only reviewer preset can run any shell command | baseline §2.1; `AgentManager.php:1103-1260` | 01 04 05 06 07 08 11 12 |
| 24 | Medium | Parallel Task fan-out has no cap (`AgentPoolConfig::maxConcurrent=5` is used only for workflows) | `Runtime::executeConcurrently` `:1853` | 07 10 11 |
| 25 | Medium | Edit is exact-match only, with a terse error; no staleness check; `Write overwrite:true` can overwrite edits the user made since the last read | `Edit.php:178-197` | 02 04 05 07 08 09 10 11 |
| 26 | Medium | Read has no paging (up to 1 MiB), no line numbers, and no continuation | `Read.php` | 02 04 06 07 10 11 12 |
| 27 | Medium | No retry once a stream has produced its first token; no continuation on output-length stops (the 4096 `max_tokens` default on Custom/OpenAI cuts large Writes) | `Runtime::runStreaming` `:1324-1459` | 07 09 10 11 |
| 28 | Medium | A reply that is only reasoning, or empty, ends the turn silently *(inferred)* | `runTurn()` | 06 08 10 |
| 29 | Low-Med | Only the final assistant text of a turn is kept, so interim reasoning is lost for the next turn | `EngineBackend.php:985`, `:1019` | 03 |
| 30 | Low-Med | `/bg` results never come back to chat; `/fork` runs ignore history (the forked session's stored copy is complete since the fix for audit SES-2, `698a1efff`, but the background daemon does not load it); daemons are not picked up again after restart | baseline §2.4 | 04 08 10 12 |
| 31 | Low-Med | The OpenAI provider never emits tool calls (always streams; `parseChunk` hard-codes `toolCalls: null`); `anthropic` is OpenAI-shaped with tools off | `OpenAIProvider.php:488-507`; `ProviderFactory.php:663-694` | baseline |
| 32 | Low-Med | `/model` switches provider, not model, and appears to drop the Task tool and rule toggles *(inferred)* | `Chat.php:14106-14146`; `Bootstrap.php:6748` | baseline |
| 33 | Low | The session-affinity header is dormant, so multi-replica SGLang routers lose radix locality | `SessionAffinity` trait | 02 12 |
| 34 | Low | No Unicode-tag (U+E0000–E007F) stripping in prompt fences; MCP stdio env not filtered (`LD_PRELOAD`, `NODE_OPTIONS`) | `PromptFence::escape` `:174`; `McpClient::resolveEnv` `:608-621` | 08 |
| 35 | Low | `/share` always fails (stub uploader); WebSearch has no default endpoint since F-W3 (b) (`adbb3df16`) and errors until `SUGARCRUSH_SEARCH_ENDPOINT` is set; the LSP tool is registered with a null client | baseline §11 | baseline 02 08 |
| 36 | Low | No snapshot or drift test of the assembled system prompt, even though section order matters for caching | — | 11 |
| 37 | Low | Silently ignored configuration everywhere (dormant frontmatter keys); dsh's rule is "fail loud" | baseline §11.2 | 12 |

---

# Part III — Unified, prioritised roadmap

These are the recommendations from all twelve reports, de-duplicated. They are ordered into **waves** so that prerequisites land first. Effort: S ≤1 day, M 2–5 days, L >1 week.

**Where the code goes:** each item names the main files. The appendices hold the full designs.

**Project obligations:**
- Wire dormant code rather than deleting it.
- Every new tool, command, env var or key binding needs its doc edit (drift tests).
- New test files go into `scripts/parallel-tests-durations.tsv`, with `suite-figure.json` refreshed.

## Wave 0: quick, independent fixes (do first, mostly S)

| # | Item | Where | Effort | Sources |
|---|---|---|---|---|
| 0.1 | Send `reasoning_content` back on assistant tool-call messages (DeepSeek-V4 family flag); verify with `cached_tokens` | `SglangProvider::formatMessages()` `:1580` | S | dsh |
| 0.2 | Unique tool-call ids: `ToolCallIdAllocator` rewrites `dsml_call_N`/`minimax_xml_call_N`/empty ids to `tc_<session>_<seq>` before execution | new `src/Support/ToolCallIdAllocator.php`; `Runtime` before `executeToolCalls` | S | DCP |
| 0.3 | Replace the SugarCraft PR cadence in Bash guidance with generic git-safety rules; move the cadence into this repo's AGENTS.md; add `includeGitInstructions` + `attribution` settings | `Bash.php:124-163`; `LayeredSettings` | S | CC OC Kilo nano Claw dsh |
| 0.4 | Bash `timeout` (default 120 s, max 600 s; SIGTERM→SIGKILL the setsid group) + **heartbeat frames** from sequential tools so the 120 s watchdog measures silence, not work | `Bash.php`, `CapturesProcessOutput`, `Runtime::executeSequentially` | S | 10 reports |
| 0.5 | Cap MCP results and add a per-call MCP `toolTimeout` (30 s default) | `McpToolBridge.php:587-622`; `StdioMcpServer` | S | 9 reports |
| 0.6 | `/memory add` defaults to project scope; inject user scope too (user first); warn when a memory lands in a scope that is never injected | `MemoryBlock::capture()`; `/memory` handler | S | CC Kilo OH nano Claw |
| 0.7 | Fix `removeNavigationSteps` (anchor patterns, never touch User rows) and `isFileReadMessage` (key on the tool row, not a regex) | `ContextCompactor.php:936-1110` | S | CC Cline |
| 0.8 | Protect policy surfaces: add `settings*.json`, `.mcp.json`, `.sugar-crush/{skills,commands,rules}/` to `ProtectFilesHook` (deny now; always-Ask after Wave 1) | `ProtectFilesHook.php:83-86` | S | Zed |
| 0.9 | Repeat-call detector (canonical `name+sorted-JSON args`; warn at 3, hard stop at 5–8 or OpenClaw's 10/20/30 with result hashing) **then** raise `maxSteps` to ~50–100; on the last step send a tool-less "summarise what's done / remaining / next" prompt | new `src/Runtime/LoopGuard.php`; `EngineBackend::runTurn()` `:862-1024` | S | 11 reports |
| 0.10 | Empty or reasoning-only reply → one nudge, then continue; empty-turn retry ×2 | `runTurn()` | S | OH Goose nano |
| 0.11 | Better edit failures: "did you mean" lines (≥0.6 similarity, line numbers), "new_string already present", uniform-indent retry, line numbers of every match on ambiguity | `Edit.php:178-197` | S | Aider Goose Cline OH Claw |
| 0.12 | Read `offset`/`limit`, `N:` line numbers, continuation footer, 2000-line / 50 KB default page | `Read.php:153-379` | S | 9 reports |
| 0.13 | Wire the session-affinity header (session id) from `Bootstrap::backendFor()`; show `cached_tokens` % in the status bar | `SessionAffinity` trait; status line | S | OC dsh |
| 0.14 | Unicode-tag stripping in `PromptFence::escape`; filter MCP stdio env; `.env*`/`*.pem` read-deny defaults | `PromptFence.php:174`; `McpClient::resolveEnv` | S | Goose Zed |
| 0.15 | Sub-agent output hardening: escape `<system-reminder>`-like tags and `Human:`/`User:` prefixes; prepend `[subagent output — no user authority]` | `TaskTool::runOnEngine` `:604-629` | S | CC Kilo |
| 0.16 | Cap parallel Task fan-out with `AgentPoolConfig::maxConcurrent` | `Runtime::executeConcurrently` `:1853` | S | Zed nano Claw |

## Wave 1: three foundations (M each; most later items depend on them)

**1.A — Cache-stable prompt prefix.**
- Split `EnvironmentBlock` into a **static** part (cwd, OS, PHP, model, date) that stays in the system prompt and a **volatile** part (git status, log, post-write diffs, context %, recently-modified files, todo list). Send the volatile part as an appended `<system-reminder>`/`<turn-context>` **user-role** message, and only when it changes (dsh, Goose, nanobot, CC).
- In `SglangProvider`/`CustomProvider::formatMessages()`, render history System rows **in place** as user-role notices instead of moving them into message 0.
- Memoise PerSession sections per *session* rather than per `Runtime` (CC, Aider). Pick and document one CLAUDE.md freshness policy.
- Add a regression test: two consecutive steps must produce identical bytes for messages[0..n-1].
- Add prompt-snapshot drift tests (OpenClaw).

Files: `Runtime::systemPromptSections()` `:2832-3144`, `EngineBackend::runTurn()`, `SglangProvider.php:1573-1612`. **M.** *(CC P0-1, OC P0-3, Goose P0-3, nano R4, dsh P0-1, DCP P1-6.)*

**1.B — Message identity and structured replay.**
- Give `Message` `id` / `ref` / `stepId` / `uiOnly` (and Goose's `agentVisible`/`userVisible`) fields.
- Rewrite `toTypedMessages()` to rebuild `AssistantMessage(toolCalls, reasoning)` + `ToolResultMessage` pairs, grouped by `stepId`. Apply Zed's rules: an empty result becomes `<Tool returned an empty string>`, and an unanswered call becomes `Tool canceled by user`.
- Skip `uiOnly` rows.
- Compaction *flags* original rows as hidden from the agent instead of deleting them, so scrollback survives.
- Keep the interim assistant narration of each step.
- Bump the transcript schema version.

**M–L.** *(OC P0-2, DCP P0-1/P0-2, Cline P0-3, OH P0-4, Zed R2, Goose P0-4, nano R2, dsh P1-2.)*

**1.C — The parent↔child frame channel.** Turn the socketpair into a two-way protocol.

Frames from parent to child:
- `permission_reply{id,allow,always,feedback}`
- `steer{text}`
- `cancel_tool{callId}`
- `mailbox{…}`

Frames from child to parent: `permission_request{id,toolCall,ask}`.

How the pieces connect:
- The child's `permissionApprover` closure (already accepted by `EngineBackend`, `:285`/`:540`) writes the request and blocks on the reply.
- The parent turns it into the **existing** Veil y/n/a modal (`Chat::requestPermission` `:2666`) and **pauses the 120 s watchdog** while it is open.
- Steer frames are drained at each step boundary and before each sequential tool. Unstarted calls get the synthetic result `"Skipped to process an incoming message."` (OpenClaw).
- Queue modes: `steer | followup | interrupt`. Bind Enter-while-busy to steer, with a modifier to queue instead (nanobot, Cline, Zed).
- "Always allow" stores a pattern. A rejection can carry feedback the model sees (opencode).
- Parallel grandchildren relay asks through the turn child, or return "needs-ask" and re-run sequentially (Goose).
- An alternative is OpenHands' *resumable stop*: end the turn with pending calls and execute them first on the next dispatch. It is cleaner with respect to the watchdog, but needs 1.B.
- **Once this is green, change the default mode** from `bypass-permissions` to `default` or `accept-edits`, after Wave 3.A checkpoints land.

**M–L; the highest-leverage item in the whole report.** *(10 reports.)* The same channel later carries server-mode approvals (Part VIII).

## Wave 2: context engine (agent-aware context management)

| # | Item | Effort | Sources |
|---|---|---|---|
| 2.1 | **Step-level pressure check** in `runTurn()` before each `Runtime::run()`. Anchor on the provider-reported `usage.prompt_tokens` of the last step plus a delta estimate for new rows. Count the system prompt and tool schemas. Threshold e.g. `min(0.8·W, W − maxOutput − 64k)` (dsh) or 90% (Zed/Cline) | M | 11 reports |
| 2.2 | **Deterministic tool-output pruning first.** Replace the no-op `removeToolResults()` with a projector rule. Older tool results go to a placeholder that keeps tool + main arg (`[r17 Read src/X.php — output pruned; re-run if needed]`). Protect the newest 40k tokens and the last 2 turns; only fire if ≥20k is freed (opencode/Kilo); head-4096/tail-1024 for >8192 (dsh); soft-trim at 30%, hard-clear at 50% (OpenClaw). Never prune Task/Skill results. Batch rewrites (≥64 KB reclaimable) to protect the cache (Cline) | M | OC Kilo DCP Cline Claw dsh |
| 2.3 | **Path-keyed dedup and stale-read pruning**: newest Read of a path wins; Reads older than an Edit/Write of the same path are pruned; superseded Write `content` arguments are elided; inputs of failed calls older than N turns are blanked (DCP + Cline) | S–M | DCP Cline |
| 2.4 | **Summarise at step level, reusing the cache.** Same system prompt + tools + history, plus a final user instruction "do not call tools" (CC, dsh, nanobot). Default to the main model; keep `SUGARCRUSH_SUMMARY_MODEL` as an option. Snap cuts to tool-pair boundaries (OpenHands) and keep the unsent tail verbatim (nanobot) | M | CC dsh nano OH Zed |
| 2.5 | **State-oriented summary template** layered on the existing six-facet records (which are good for security-constraint fidelity). Add a holistic block — Goal / Constraints / Progress (Done / In-progress / Blocked) / Key decisions / Current work / Next step / Pending tasks with ids / Files read & modified (derived mechanically from tool rows) / Errors+fixes verbatim / Latest unresolved user request verbatim. Merge with the previous summary. Cap ~16k chars. Audit required headings and fall back if missing (OpenClaw safeguard). Reject summaries not smaller than their source or cut short by the length limit. After compaction, re-append the latest user prompt verbatim plus "continue if you have next steps" | S–M | CC OC Kilo OH Goose Claw dsh Cline |
| 2.6 | **Post-compaction re-injection**: the 5 most recently edited/read files (≤5k tokens each) or Cline's "Required Files" (≤8 files/100k chars), invoked skill bodies, fresh git snapshot, todo list, plan | M | CC Cline |
| 2.7 | **Context-overflow and length-stop recovery**: classify provider context-length 400s as `ContextOverflow`, prune maximally and retry once (twice in goose). On `finish_reason=length` without tool calls, continue with prefill (`continue_final_message` on SGLang) up to 3× (Aider/nanobot/Cline). Retry dropped streams with "Continue where you left off" (Zed/OpenClaw) | M | 9 reports |
| 2.8 | **Spill-to-file** for any tool output over budget (16k chars / 50 KB / 2000 lines): head+tail preview plus path and "use Read offset/limit or Grep". Mode 0600, session-scoped, 7-day retention, `PathJail` allow-list. Window-scaled caps (≤30% of window) | S–M | 9 reports |
| 2.9 | **Absolute thresholds** in addition to percentages (DCP's 50k/100k "smart zone"; on a 1M window, 70% is far past the point where quality holds); per-model overrides | S | DCP |
| 2.10 | Ahead-of-need **background summarisation** at 70% so the 85% submit never blocks; splice in if the history fingerprint still matches | M | Aider |
| 2.11 | **Memory flush before compaction**: one silent tool-enabled turn that writes durable notes to memory, once per compaction cycle | S–M | Claw |
| 2.12 | Wire the dormant `PreCompact` hook (deny blocks) and add `PostCompact`; `/compact <focus>` must actually steer the summary | S | CC OC DCP Cline |

## Wave 3: safety net and agent self-management

| # | Item | Effort | Sources |
|---|---|---|---|
| 3.A | **Workspace checkpoints**: per user turn (and optionally after each write step), `git stash create` plus untracked files via scratch `GIT_INDEX_FILE` → `commit-tree` (Cline 4.x / Zed). Pin under `refs/sugar-crush/checkpoints/<session>/<n>` so `git gc` can't prune it. Use a shadow git dir for non-repos (opencode/Kilo). Store the ref in the existing checkpoint row (`EnhancedSessionStore::saveCheckpoint`; `Chat::dispatchTurn` `:7963-7993`). `/rewind [n] --files|--chat|--both`, `/undo`, `/redo`, `/diff [n]`; refuse if HEAD moved; offer the file restore only if it would change something; refuse in `$HOME` | M | CC OC Kilo Cline Zed dsh |
| 3.B | **Agent self-pruning tools** (the user's headline request): `Prune{targets:[{ref,distillation?}],reason}` and `Compress{topic,ranges:[{from,to,summary}]}` over the DCP ledger/projector. Applied mid-turn through a `ledger` fork frame. Nested blocks with placeholders; size guard (`summary ≤ 0.5×source + 2000`, DCP #573); nesting cap 16k. Task/Skill outputs re-attached verbatim. Anchored nudges (never on an assistant message, DCP #520), cooldown after a compress. Commands `/context`, `/compress [focus]`, `/decompress bN`, `/recompress bN`, `/sweep [n]`, `/pruning auto\|manual\|off`. Default: strategies **auto**, Prune **auto**, Compress **manual** until evals show good behaviour. Optional `Recall` tool to bring back pruned content. Sub-agents get an ephemeral ledger. **Full design: Appendix D §13.2 (classes, schemas, tests, rollout phases 1–5).** Add Kilo-legacy `/compact --self` (the model writes the summary, the user previews in a Veil modal; don't repeat Kilo's re-summarise bug) | L | DCP Kilo Goose |
| 3.C | **Todo tool** wiring the dormant `TaskList`/`SessionMeta::$tasks`: whole-list replace, at most one `in_progress`, survives compaction, re-injected via the 1.A turn context every ~6 steps or when stale, shown in a dock pane. Goose's anti-over-use wording: "Never redo or re-verify completed work because of these notes" | S–M | 8 reports |
| 3.D | **Stop / SubagentStop / SessionEnd hooks** dispatched; JSON hook stdout (`decision`, `reason`, `additionalContext`, `updatedInput`, `continue`); a block continues the turn (cap 8). `/goal <condition>` (judge via title backend, strict JSON, "claimed-but-unverified ≠ satisfied" — OpenHands) and `/grind` | M | CC Goose OH Cline dsh |
| 3.E | **Built-in post-edit lint** (`php -l` + user-tier `lintCommands` map) via a PostToolUse built-in hook. `Runtime::settle()` already appends `additionalContext`. Aider's `█` marker format | S–M | Aider |
| 3.F | **Wire the LSP client** (`src/LSP/*`, dormant) for post-edit diagnostics (≤20 errors/file, 5 s wait) and Read outlines for large files (Zed) | M | OC CC Zed |
| 3.G | Opt-in **auto-commit** (`autoCommit: off\|turn\|edit`) with a weak-model Conventional-Commits message (`titleBackend`), dirty-commit of user changes first, `Co-authored-by`, never `--no-verify`; `/undo` with Aider's five refusals | M | Aider |
| 3.H | **Auto-test reflection**: `testCommand` + `autoTest`, up to 3 reflections, using Aider's `run_output` shape | M | Aider |
| 3.I | Fuzzy Edit matcher chain: exact → line-trimmed → whitespace-normalised → indentation-flexible → block-anchor (≥0.65) → NFKC/smart-quote normalisation. Uniqueness is required at each stage, `isDisproportionateMatch` refusal, matched-stage reported. Multi-edit `edits[]` applied atomically. Staleness check (mtime/hash from the session read ledger) plus a "files changed since you read them" notice in the turn context. Optional `ApplyPatch` | M | OC Kilo Cline Zed Claw nano dsh |

## Wave 4: sub-agents and orchestration

| # | Item | Effort | Sources |
|---|---|---|---|
| 4.1 | Honour preset `model`/`effort`/`permissionMode` (new `EngineBackend::withModel()`, per-sub-agent `PermissionGate`); per-call `model` arg; `subagentModel` default; **fail loudly** on unsupported preset fields (dsh) | S–M | 9 reports |
| 4.2 | Argument-scoped **grant** rules for presets, reusing the permission-rule matcher (already argument-scoped, and since `c8fc573a5` fail-closed: the `ShellWords` splitter refuses `$()`, backticks, process substitution and non-inert redirects in allow rules, every segment must match, deny on any segment); (sub-agent gates already get the project root, so path rules match respellings there too: Part IX F-J3, fixed in `3b7d2fd33` and `a5b6e3d78`); route Task through `refuseCallOutsideGrant()` (dormant) | M | CC Zed Cline Kilo |
| 4.3 | **Background Task** (`background:true` / preset `background`): returns `{agent_id}` at once ("DO NOT sleep or poll"). Runs via `BackgroundSupervisor` or `AgentWorkerPool`. On settle, a user-role announce row (`[Subagent '<label>' completed] … status from the runtime outcome (ok/error/timeout), stats line: runtime, tokens, cost, resume id`) is appended, and a turn is auto-dispatched if idle or injected via steer if busy. An "Active subagents" block appears in each turn context. Wire `BackgroundSupervisor::reconnect()`. Also fixes `/bg` results never returning | M–L | Claw nano dsh Goose Kilo OC |
| 4.4 | **Messaging tools** on the dormant `Mailbox`: `SendMessage{to, text, mode: steer\|followup\|note}` (steer a running child, wake an idle one, cold-resume a stored one via `SuspendedDelegations`), child→parent replies, `Subagents{list\|wait\|cancel}`, `InterruptAgent`; delivery at step boundaries through the 1.C seam; untrusted-peer framing | M–L | Claw dsh Kilo Goose nano |
| 4.5 | **Shared board** for parallel children (Kilo): `BoardRead`/`BoardPost` with INFO/ASK/RESULT/HOLD/VETO, notice appended to the next tool result | M | Kilo |
| 4.6 | **Teams**: construct `TeamManager` (`AgentManager::setTeamManager()` `:1903`), `team_*` tools on `TaskList` (claim/complete/dependencies, CAS revision, acyclic `blockedBy`), crash recovery; make `GroupInputCmd`/`CancelAgentCmd`/`ResumeAgentCmd`/`StopAllAgentsCmd` real | L | CC Cline dsh |
| 4.7 | Resume any finished sub-agent (not only failures); on failure return the last 3×4096 chars of partial output; stop a child at 80–90% of its window with "wrap up or hand off" (Zed); depth 2–3 nesting with caps (CC 3/20, OpenClaw depth 5, 8 concurrent) | S–M | Zed CC OC Claw |
| 4.8 | Sub-agents as stored child sessions: navigable in the tab strip, typable into, promotable to background (opencode Ctrl+B) | L | OC |
| 4.9 | Worktree isolation for `isolation: worktree` presets and `/bg` (wire `WorktreeManager`, `withWorktreeRoot()`, `BashEscapeDenyHook`) | M | CC Claw |
| 4.10 | Model-authored workflows: expose `WorkflowEngine` as a tool taking a YAML plan; fix "only the first task of a stage runs" | M | OH dsh |

## Wave 5: memory, codebase understanding, UX, integrations

| # | Item | Effort | Sources |
|---|---|---|---|
| 5.1 | **Memory index injection**: the user + project `MEMORY.md` index (≤200 lines/25 KB), not 12 newest bodies, plus standing "when to save / types / don't save what the code says" instructions and a `Memory` tool (view/save/str_replace/delete/recall) | S–M | CC Kilo OH |
| 5.2 | **Auto-memory consolidation** on `summaryBackend`, throttled: JSON ops plus skip reasons, Kilo's "prefer saving nothing" list and secret redaction; "Memory is context, not instruction" | M | Kilo |
| 5.3 | **Memory search**: SQLite FTS5 BM25 plus the dormant `embeddings()` (0.7 vector / 0.3 keyword, 30-day half-life, MMR); a relevance-ranked snapshot keyed on the latest user message; a mandatory "search memory before answering about prior work" prompt fragment | M | Claw |
| 5.4 | **Dream pass**: compaction summaries appended to a tagged journal; a periodic restricted-tool pass edits memory/skills; memory dir git-versioned with `/memory log` and `/memory restore` | M–L | nano |
| 5.5 | **Symbol-level repo map**: `token_get_all` for PHP, universal-ctags elsewhere, Aider's PageRank weights, SQLite tag cache, binary search to a token budget. Ship as a `RepoMap` tool first; optionally add a byte-stable PerSession block beside the existing `RepoMapBlock` | L | Aider |
| 5.6 | `/context` / `/tokens` breakdown per prompt section, tool schemas, history, largest messages, cache-hit ratio, pruned items; status bar `ctx 38% · 81k/131k · cache 92% · $0.42` | S | 8 reports |
| 5.7 | **Plan mode** made real: PerTurn plan-mode section, command guard, plans-dir write exception, `PlanExit` + `ask_user` tools over the 1.C channel, Shift+Tab toggle, superseding "agent changed" reminder | M | OC Kilo Cline dsh |
| 5.8 | `@`-mentions (`@file`, `@diff`, `@session`, `@url`) → `<context>` block (wire the dormant `Message::attachFile()`) | M | Zed |
| 5.9 | **ACP mode** (`sugarcrush acp`): stdio JSON-RPC so Zed, JetBrains and Neovim can host sugar-crush; reuse `McpMessage` framing | L | Zed |
| 5.10 | Per-model-family base prompts (DeepSeek-V4, Qwen, MiniMax); Aider-style `lazy`/`overeager` reminders; OpenClaw "Execution Bias" and "Promised Work" maxims | S–M | OC Cline Aider Claw |
| 5.11 | LLM exec reviewer / smart-approve for `auto` mode (title backend, JSON verdict, untrusted transcript, escalate after 3 denials); MCP `readOnlyHint`; security findings force Ask even in auto | M | Goose Claw dsh OH |
| 5.12 | Optional bubblewrap sandbox for Bash on Linux | M–L | Zed |
| 5.13 | Model metadata DB (litellm JSON, 24 h TTL) replacing the fixed 128k / $0 Custom defaults; `FallbackProvider` with `fallbackModels` | M | Aider nano |
| 5.14 | Small UX: bell/OSC 9 notifications on turn end or approval wait; `/btw` side question; `/handoff` (new session seeded with a summary); `/newrule`; `/init` (AGENTS.md generator); `/share` local Markdown/HTML export; `!cmd`; `/editor`; watch-files `AI!` comments; personal `~/.sugar-crush/AGENTS.md` + `.cursorrules`/`GEMINI.md`/`.clinerules` aliases; skill `requires` gating; `$skill` per-turn injection | S each | many |
| 5.15 | Settings pane, configurable behaviours, server mode, web UI, session management, live agent lines and agent view | — | **Part VIII** |

---

# Part IV — Topic deep-dives (the areas the user asked about)

## IV.1 How agents run (the turn loop)

| | sugar-crush | Best-in-class |
|---|---|---|
| Execution | One `pcntl_fork` child per user turn; length-prefixed `serialize()` frames over a UNIX socketpair; read-only tools in parallel via grandchild forks | opencode/Kilo: server process with an event bus; Goose: async stream yielding `ActionRequired`; dsh: Cordis plugin pipeline |
| Step cap | **8** (sub-agents 50) | none (OC, Cline, Claw, dsh) · 200 nanobot · 500 OpenHands · 1000 Goose |
| Loop guard | none | OpenClaw 10/20/30 with result hashing + ping-pong detector; Cline 3/5 plus mistake counter; OpenHands 5 patterns + one nudge; dsh reminders at 3/5/8 |
| Retries | 3× transient, **not after first token** | Zed/OpenClaw continue from the partial transcript; goose retries empty turns 3×; OpenClaw 10 attempts for rate limits |
| Interrupt | Esc Esc SIGKILLs the turn | CC: Esc cancels only the running tool; steering read at the next tool boundary |
| Steering | queued until the turn ends | OpenClaw `steer/followup/collect/interrupt`; nanobot Enter=now / Tab=later; Goose steers are drained before the next model call |
| End of budget | `stepsTruncated` notice and last text | opencode `MAX_STEPS_PROMPT`; nanobot `BUDGET_EXHAUSTED_FINALIZATION_PROMPT` (tools disabled) |

Details: Appendix B §2, C §2, J §2, K §2, L §2.

## IV.2 Sub-agents and communicating with them

| Product | Spawn | Runs | Talk to a running child? | Result |
|---|---|---|---|---|
| **sugar-crush** | `Task` (parallel batch) | sync fork, parent model, depth 1, no cap | **No** (Mailbox/TaskList/TeamManager dormant) | final text only; resume only after failure |
| Claude Code | Agent tool, custom agents | foreground/background, own model/tools, nesting ≤3, ≤20 | resume and redirect by message; agent teams with mailbox + locked shared task list; child permission prompts shown in the main session | escaped, "no user authority" header |
| opencode | `task` → child session | resumable `task_id`, Ctrl+B to background | re-calling a running task extends it; the user can open the child session and type | injected into the parent |
| Kilo (current) | task, `background:true` | background | **shared board** INFO/ASK/RESULT/HOLD/VETO; extend a running task | synthetic message wakes the parent |
| Kilo (legacy) | `new_task` "boomerang" | parent suspended, child active | — | child's result becomes the parent's tool result |
| Cline 4.x | `team_*`, `spawn_agent` | async runs, concurrency 2 | mailbox delivered mid-run through the steer seam; shared task board; crash recovery | — |
| OpenHands | DelegateTool / task tool | iterations/budget/permission inherited from the parent | multi-round parent↔child; child approvals relayed through `confirmation_handler` | — |
| Zed | `spawn_agent` | async futures | follow-ups by session id | partial output on failure; stopped at 80–90% of its window |
| Goose | `delegate(async)` | ≤5 concurrent, kept 600 s | `load`/`peek`/`cancel`; orchestrator `send_message`/`interrupt_agent` between sessions | background-tasks block in every turn |
| nanobot | `spawn(wait=false)` | Semaphore(4) | shared inbox; `send_session_message` with rate limit | system message injected mid-turn; the parent waits ≤300 s before finishing |
| **OpenClaw** | `sessions_spawn` | 8 concurrent / 5 active / depth 5 | `sessions_send` (steer, follow-up, note, resume), `sessions_yield`, `subagents list\|wait\|cancel`, Active Subagents block | status from the runtime outcome + stats line (runtime, tokens, cost) |
| **dsh** | background children | — | `send_message` steers a running child, wakes an idle one, cold-resumes a stored one; child→parent messages; `interrupt_agent`; `list_agents` | settlement notice injected into the parent |
| Aider | none (architect→editor two-model handoff) | — | — | — |

**Target design for sugar-crush:** Wave 4 (4.3–4.6) on top of 1.C. Copy OpenClaw's tool surface and dsh's send semantics, and reuse sugar-crush's dormant `Mailbox` (JSONL send/receive/peek/waitForMessage), `TaskList` (SQLite, dependencies) and `SuspendedDelegations` (cold resume). Details: Appendix L §3, M §3, I §3, E §3, K §3, C §3.

## IV.3 Context handling, compaction and agent self-pruning

| | sugar-crush | Notable competitor approaches |
|---|---|---|
| When | at submit only (70% remind / 85% compact / 95% block) | before **every** request (Zed 90%, Cline 0.9, OpenHands, dsh `min(0.8W, W−O−65,536)`, Goose state machine); after any step over the usable window (opencode); on overflow error (all) |
| Counting | chars/4 + 10/msg, calibrated; **excludes system + tools** | provider-reported usage of the last request + delta (Zed, DCP, dsh token meter); Kilo `(system+tools)×1.3 + tail + reported` |
| First line of defence | none (`removeToolResults` no-op) | prune old tool outputs: opencode/Kilo 40k protect / 20k minimum; OpenClaw soft-trim at 30%, hard-clear at 50%; dsh head4096/tail1024; Cline duplicate-read dedup with ≥30% savings and 64 KB batched rewrites; CC context-editing (keep 3, `clear_at_least`) |
| Summariser | separate tool-less backend, different prompt (cache miss); six-facet per-exchange records | same system + tools + history + final instruction (CC, dsh, nanobot); main model on a warm cache (DCP #387) |
| Summary shape | history-oriented | state-oriented: opencode 5-section anchored + merge; dsh 8 sections; OpenClaw Goal/Constraints/Progress/Decisions/Next/Critical + latest unresolved request + file lists + 16k cap + heading audit; Goose JSON schema with `pending_tasks/current_work/next_step`; Zed handoff Goal/State/Context/Next/Pitfalls; OpenHands TASK_TRACKING/CODE_STATE/TESTS/VCS |
| What survives | last 10 "pairs" | token-budgeted tail (opencode `clamp(usable×0.25, 2k, 15k)`), the user's last ~80 KB verbatim (Zed), the unsent tail verbatim (nanobot); re-injection of recent files, skills, git, plan (CC); "Required Files" re-read (Cline) |
| Display | compacted rows replace the history | non-destructive: Goose `agent_visible`/`user_visible`; Kilo legacy tags; Cline immutable transcript + separate compaction file; DCP ledger with undo |
| Agent self-control | none | **DCP `compress` (range→summary, nested blocks) + 2.x `prune`/`distill`**; Kilo legacy `condense` tool with user preview; nanobot Dream; OpenClaw memory flush |

**DCP in one paragraph** (the plugin the user likes; full detail in Appendix D §4):
- **Hidden ids.** Every message carries an injected id such as `mNNNN`.
- **Compress.** The model calls `compress` on a *closed* range and writes the summary itself, on its warm cache. The raw messages vanish from the next request, even within the same turn.
- **Nesting.** Earlier summaries inside a new range must be referenced by placeholder, and are expanded back so nothing is lost. Outputs of task, skill and todo tools are re-attached verbatim.
- **Automatic strategies** run only when the model compresses, so the cache breaks once per compression, not every step: duplicate tool calls are deduped and the inputs of failed calls are purged.
- **Reminders.** Turn, iteration and limit nudges fire between `minContextTokens` 50k and `maxContextTokens` 100k. Each is anchored to a fixed message so it replays byte-identically.
- **Non-destructive.** History is never edited: a per-session state is projected before every call, so `/dcp decompress` and `recompress` are flag flips.
- **Lessons from its bug tracker:** #573 (a summary that snowballed to 738k wasted tokens), #614 (a duplicated id tag broke caching), #615 (non-unique tool-call ids — which sugar-crush has today, problem #12), #520 (reminders appended to assistant messages made Anthropic reject the request).

The sugar-crush design (Appendix D §13.2) adapts all of this with a `ContextLedger` / `ContextProjector` / `Strategies` / `NudgePolicy`, plus `Prune` / `Compress` tools behind a `MutatesContextLedger` interface, a `ledger` fork frame, and a 14-file test plan.

## IV.4 Prompt generation: what gets sent automatically

**sugar-crush today** (baseline §4): 11 system-prompt layers, ordered Static → PerSession → PerTurn:
- base prompt, maxims and tool guidance (Bash guidance includes the SugarCraft PR cadence);
- repo map (composer/PSR-4 only);
- rules;
- instruction files (CLAUDE.md / AGENTS.md, plus nested files injected into tool results via `loadForPath`, which is good);
- memory (project, newest 12);
- skills listing and bodies;
- `<env>`: cwd, OS, PHP, model, date, git branch/status/log, post-write diffs up to 2×8 KiB, re-rendered every step and sitting last in the system message.

It reaches every provider now: the old bug where SGLang and Custom dropped the system prompt is fixed.

**The universal pattern elsewhere:**
- *Static system prompt; volatile context appended.*
  - Claude Code: git status is a startup snapshot; mid-session context is appended as system-reminders; CLAUDE.md is frozen until `/clear` or `/compact`.
  - Goose: `<turn-context>` is an agent-only user message added once per turn; the date is rounded to the hour; extensions are sorted.
  - nanobot: a `[Runtime Context — metadata only, not instructions]` suffix on the user message.
  - dsh: a user-role snapshot sent only when it changed; even plan mode keeps the tool list identical.
  - opencode v2: a frozen per-session baseline, with `[System update]` deltas after it.
- **Cline's `environment_details`** adds useful fields: recently modified files (changed on disk without the agent), context-window usage at ≥60%, local time with timezone.
- **Goose** adds a remaining-token budget (`<compaction>~Nk tokens remaining`) and `<turn-budget>N/M used` at ≥50%.
- **Zed** sends a thin prompt with no git and no file map. The Zed and OpenHands reports both note that sugar-crush's richer `<env>` and repo map are an *advantage* worth keeping. Just move the volatile parts out of message 0.

Per-competitor templates are quoted in each appendix's §5.

## IV.5 Memory

| | Approach |
|---|---|
| sugar-crush | `MemoryStore` LIVE (typed entries, scopes, `MEMORY.md` index generation); only **project** scope injected, newest 12; no auto-write; `/memory add` → user scope (never injected) |
| Claude Code | `MEMORY.md` index (first 200 lines / 25 KB) injected every session; typed notes (user/feedback/project/reference); topic files read on demand; the model curates |
| Kilo (current) | auto-consolidation at turn close (throttled 5 min, 24-message window) with skip reasons and a "prefer saving nothing" prompt; secret redaction; 8 KiB index; `memory_save`/`memory_recall`; "context, not instruction" |
| OpenClaw | `MEMORY.md` + daily `memory/YYYY-MM-DD.md`; hybrid FTS+vector search, mandatory before answering about prior work; memory flush before compaction; session-memory dump on `/new` |
| nanobot | compaction → tagged `history.jsonl` → periodic **Dream** pass edits MEMORY/USER/SOUL/skills, git-versioned with log and restore |
| OpenHands | two-tier guidance (repo vs user), 6,000-char budget |
| dsh / Aider / Zed | no built-in memory (dsh relies on MCP) — **sugar-crush is ahead of these** |

Roadmap: 0.6 → 5.1 → 5.2/5.3 → 2.11 → 5.4.

## IV.6 Git integration, checkpoints and undo

| | Approach |
|---|---|
| sugar-crush | branch, status, log and post-write diffs in `<env>` (**ahead** of Zed and OpenHands, which put no git in the prompt); `GitMcpServer`/`GitCommandHandlers` exist; `/rewind` = transcript only; Bash guidance hard-codes the SugarCraft PR flow |
| Aider | auto-commit per edit, weak-model Conventional-Commits message, `Co-authored-by`, commits dirty user changes first, `/undo` with 5 refusals (but skips pre-commit hooks by default — don't copy) |
| opencode | shadow git dir; `write-tree` at every step; per-file revert from the step's tree; `/undo` puts the prompt back in the input; `/redo` |
| Cline | 4.x `git stash create` + untracked via scratch index → private ref; restore files / chat / both; refuses if HEAD moved; classic shadow repo |
| Zed | checkpoint before each user message (temp index, untracked <2 MB, binary ignore list); "Restore Checkpoint" only when changed; also covers external ACP agents (but unpinned — gc can prune) |
| Claude Code | per-prompt snapshots of files its edit tools touch (100 checkpoints); restore code / conversation / both; summarise from or up to a point |
| Kilo legacy | shadow git with `core.worktree`, commit before the first mutating tool per message |

Roadmap: 0.3, 3.A, 3.G, 3.H.

## IV.7 Skills, hooks, MCP and extensibility

- **sugar-crush:**
  - Skills are LIVE (including importing foreign agents and skills). Automatic skill matching is deliberately unwired (precision 0.162).
  - Hooks are LIVE for several events, but `Stop`, `SubagentStop`, `SessionEnd`, `PreCompact`, `TaskCreated`, `TaskCompleted` and `TeammateIdle` never fire.
  - MCP is a stdio client via `sugar-mcp`, with no resources, prompts or SSE and no user-level config.
  - Trust gating for project hooks, MCP, commands and settings is good; OpenHands has none.
- **Ideas worth taking:**
  - CC: about 35 hook events, JSON hook output, a Stop hook that keeps Claude working (basis of `/goal`), plugins and marketplaces.
  - opencode: a `messages.transform` plugin hook (the seam DCP is built on); propose `PreRequest`.
  - nanobot: `$skill` explicit per-turn injection; `requires.bins/env` gating.
  - OpenClaw: skill gating; ClawHub registry; "Skill Workshop" drafting skills after long turns.
  - OpenHands: author-declared `triggers:` keywords instead of fuzzy matching.
  - Goose: recipes (YAML with parameters, retries and checks); `goose review` with `.agents/checks/*.md`.
  - dsh: everything is a Cordis plugin; Claude Code `hooks.json` compatibility.
  - Zed: ACP.
  - CC: deferred MCP tools via `ToolSearch` to keep the tool list stable and small.

## IV.8 Tools and editing

Common gaps across the reports:
- Read paging and line numbers;
- Edit fuzzy matching, multi-edit and staleness checks;
- Bash timeout and background jobs (Process/JobOutput/KillShell);
- spill-to-file;
- WebFetch HTML→Markdown;
- todo, `ask_user` and `PlanExit` tools;
- LSP diagnostics;
- lint and test loops;
- normalising tool calls before the gate (`functions.X`, `mcp.server.tool` → `mcp__server__tool`, scalar coercion; Goose);
- plain-text tool-call promotion and argument repair (OpenClaw).

## IV.9 Permissions and safety

The root issue is 1.C: asks cannot be answered.

**Additional ideas:**
- argument-scoped rules with a fail-closed shell splitter (Zed, CC, Cline): sugar-crush's permission rules now have both (allow rules fail closed since `c8fc573a5`), but preset grants still match by name;
- always-Ask on policy files (Zed);
- inspectors where the strictest verdict wins, with security findings forcing Ask even in auto (Goose);
- an LLM exec reviewer (OpenClaw, Goose adversary, dsh auto-review);
- a model-rated `security_risk` per tool call (OpenHands);
- secret registry and output masking (OpenHands);
- bubblewrap sandbox (Zed);
- Kilo carries a caller's denies into children ("a tool guarded here but not there would be reachable again through a subagent").

## IV.10 UX worth copying

- **Session tools:** child sessions you can navigate into (opencode); a todo pane; `/context` breakdown; cache-hit % and cost in the status bar.
- **Review and undo:** per-hunk Keep/Reject review with an action log (Zed); `/undo` that restores the prompt into the input (opencode).
- **Attention and input:** desktop/bell notifications; `@`-mentions; an Enter-steer / Tab-queue split.
- **Side commands:** `/btw` side questions; `/handoff`; local export for `/share`.

---

# Part V — What each competitor does best

| Competitor | Standout ideas (see appendix) |
|---|---|
| **Claude Code** (B) | Caching discipline (static prompt, appended reminders, compaction sharing the cache); clear tool outputs before summarising, then re-inject files, skills, git and plan; mid-turn steering and per-tool cancel; mature sub-agents and teams; about 35 hook events with Stop-based `/goal` |
| **opencode** (C) | Mid-turn compaction with a 5-section anchored summary + auto-continue + 40k/20k pruner; sub-agents as navigable child sessions; tree-sitter Bash parsing with "always allow" prefixes and reject-with-feedback; shadow-git `/undo` and `/redo`; 9-stage fuzzy edit + LSP diagnostics after edits; truncate-to-file |
| **DCP** (D) | Agent-driven `compress` (range summaries, nested placeholders), non-destructive ledger, nudges anchored to fixed messages so they stay cache-stable, free dedup and purge strategies run only at compression time, main model summarising on a warm cache |
| **Kilo Code** (E) | Boomerang subtasks; non-destructive condensing and a model-written `condense` with preview; Kilo Memory ("prefer saving nothing", redaction, recall tool); **shared agent board** INFO/ASK/RESULT/HOLD/VETO; background sub-agents; preflight that counts system + tools |
| **Cline** (F) | Workspace checkpoints with three restore modes; duplicate-read dedup before truncation; main-model summary with "Required Files" re-read; loop + mistake counters instead of a step cap; steer that aborts only the current stream; agent teams with a mid-run mailbox |
| **OpenHands** (G) | Append-only event log with condensation markers, pair-safe cuts, overflow → forced condense; approval as a resumable pause; 5-pattern stuck detector with one nudge; spill with file pointers; 30 s soft timeout that returns control; model-rated risk per call |
| **Zed** (H) | Streaming multi-edit with a ≥0.8 line fuzzy matcher and re-indenting; action log with per-hunk Keep/Reject; shadow-git checkpoint per message; compaction before every request with a handoff summary + 80 KB of the user's messages verbatim; **ACP** |
| **Goose** (I) | Approval inside a running turn (approved tools keep running, approvals persisted for reconnect); `<turn-context>` cache discipline; agent/user visibility flags; JSON-schema summary + mid-turn overflow recovery ×2; stacked inspectors (strictest verdict wins) |
| **Aider** (J) | PageRank repo map personalised to chat files and mentioned identifiers; failed-edit reflection; lint and test loops with █ markers; git auto-commit + `/undo`; architect/editor two-model split; background summarisation; cache keep-alive pings |
| **nanobot** (K) | One inbox for steering + sub-agent results + cross-session messages; background spawn with announce and 300 s wait; per-request governor with spill and pressure compaction; compaction → Dream memory with git audit; static prompt + Runtime Context suffix |
| **OpenClaw** (L) | 4 queue modes with synthetic "skipped" results; the most complete sub-agent tool surface; memory flush before compaction + checkpoint summary with audit; hybrid memory search; hash-based loop detection 10/20/30 with no step cap; window-scaled tool caps; LLM exec reviewer |
| **DeepSeek Harness** (M) | KV-cache-first design for DeepSeek-V4 (byte-identical system prompt, changed-only user-role snapshots); `reasoning_content` passback; cache-reusing summariser with 8-section checkpoint; Bash timeout → background job; background children with send/steer/wake/cold-resume |

---

# Part VI — Patterns not to copy (found in competitors)

- **Aider:**
  - `--git-commit-verify` defaults to False, so pre-commit hooks are skipped.
  - Its fuzzy edit-distance matcher never runs because of an early `return` (`editblock_coder.py:183`).
  - A leftover merge-conflict marker sits in a prompt example.
- **Cline classic:**
  - `summarize_task`'s Required-Files section number doesn't match its regex (8 vs 9), so the re-read never fires.
  - The focus chain's "all complete" branch is unreachable.
- **Cline 4.x:** `spawn_agent` allows unlimited nesting and runs child tools without approval.
- **Kilo:**
  - The `condense` tool discards the summary the user approved and re-summarises.
  - Migration silently skips per-mode rules.
  - The legacy `fuzzyThreshold` of 1.0 makes "fuzzy" effectively exact.
- **Zed:**
  - `Thread::summary()` keeps only the first line of each streamed chunk.
  - A `<rules>`/`</user_rules>` tag mismatch.
  - Unpinned checkpoint commits.
  - No loop guard.
- **Goose:**
  - `RepetitionInspector` is registered but never advances its state.
  - Docs describe strategies that no longer exist.
  - Sub-agents are forced to Auto because their approvals cannot be forwarded.
  - Subdirectory hints are added mid-turn, which breaks its own cache.
- **OpenHands:** loads `.openhands/hooks.json` from a cloned repo with no trust check.
- **dsh:** uploads the full session log to DeepSeek's API by default (`dsh_session_log`).
- **DCP:** see bugs #573, #614, #615 and #520 (Part IV.3).

---

# Part VII — Where sugar-crush is already ahead (keep these)

- **Git awareness.** Git state in the prompt (branch, status, log, post-write diffs). Zed and OpenHands send none. *Keep it, but move it out of message 0.*
- **Trust and fencing.**
  - Project-trust gating for hooks, MCP, commands and settings (OpenHands has none).
  - `PromptFence` escaping of instruction, rule and memory fences.
  - Untrusted framing for WebFetch, WebSearch and memory.
- **Prompt construction.**
  - The Static → PerSession → PerTurn ordering discipline.
  - Nested instruction files injected into tool results (`InstructionFileLoader::loadForPath`) instead of the system prompt. Goose's mid-turn system hints break its cache.
- **Compaction prompt quality.** The six-facet compaction records with verbatim security constraints and role-imitation guards. Claude Code publishes nothing equivalent; *extend it, don't replace it*.
- **Memory.** A real typed `MemoryStore` with index generation; dsh, Aider and Zed have nothing comparable.
- **Doc discipline.** Drift tests that keep README and docs rosters honest; Goose's docs have rotted.
- **Spend cap and live workflows.** A spend cap and idle watchdog, workflows running the real tool loop, and foreign agent and skill import.

---

# Part VIII — Requested additions: settings pane, configurability, server mode, web UI, sessions and agent view

> **Decisions recorded (user, 2026-10-01)**
> 1. **Package name:** keep `sugar-crush-web`. Record it in `PROJECT_NAMES.md` as an "app satellite `<app>-<surface>`" exception.
> 2. **Server port:** default `7420`.
> 3. **TUI permission default:** moves off `bypass-permissions` once engine-path approvals (Wave 1.C / server Phase 1) work.
> 4. **Model choice:** the settings editor **persists the model choice**. This reverses the `docs/SETTINGS.md` "no model is persisted" contract, so update that doc and its drift tests in the same change.
> 5. **Settings file:** the editor writes **`config.json`**, the file the app already writes (`Bootstrap::writeUserConfig()`, `Bootstrap.php:3608`). The "settings.json is never written" invariant and the current precedence stay unchanged.
> 6. **Web build:** `sugar-crush-web/dist/` **is committed**, like the GIFs; CI checks it matches the source.
> 7. **Still open:** whether TLS via a reverse proxy is enough for v1 (recommended).

The user asked for these five features while the research was running:
- a settings form or pane, with more of sugar-crush's behaviour made configurable;
- a WebSocket server mode that runs in the foreground or the background;
- a separate `sugar-crush-web` Vite/Vue package for controlling several sessions at once;
- listing and renaming sessions;
- live per-agent activity lines, with clickable agents you can watch and message directly.

Three design agents covered them. **Appendix N** is the settings report, **Appendix O** the server and web report, and **Appendix P** the sessions and agent-view report. Each one checked the current code before designing, so every section below starts from what already exists.

## VIII.1 Settings pane and configurability (Appendix N)

**What exists today:**
- **A read-only sidebar.** `src/Tui/Components/SettingsPane.php` shows 8 rows with the footer "read-only — /theme, /model". `Ctrl+,` only focuses it.
- **Only three keys are ever written:** `provider` and `theme` through `Chat::onConfigChange`, and `layout` through `App::$onLayoutChange`. A drift test pins the first two (`ConfigWriteProducerDocumentationDriftTest`). An editor therefore needs its **own censused write door**; widening that callback would break the pinned contract.
- **The current key set:** about 26 config keys, 25 `SUGARCRUSH_*` environment variables and 10 CLI flags. The report has the full inventory table: type, default, allowed tiers, env override, whether a change applies live / next turn / after restart, and how easy each is to edit in a UI.
- **Next-turn reload is mostly free.** The forked child re-reads the settings files each turn (`EngineBackend.php:782`), so making a key take effect on the next turn usually needs no new plumbing. Today only `parallelToolCalls`, `parallelToolDeadlineSeconds` and `maxOutputTokens` are re-applied that way. `docs/SETTINGS.md` names only the first two.
- **Documentation mismatches found along the way:**
  - providers configured in `~/.sugar-crush/config.json` are never read;
  - `enabledSkills` is read only from `config.json`;
  - the repo-root `.sugar-crush/config.json` is read only by dormant worktree code.

**Behaviour that should become settings.**

The report lists **about 45 hard-coded constants**, each with file:line and a proposed key, type, default and tier. The main ones:
- `maxToolSteps` (8 at the time; 1000 by default since wave 8A, `28f223839`);
- the 120 s idle watchdog, as `turnIdleTimeoutSeconds`;
- the compaction thresholds 70/85/95 and keep-10;
- the 64 KiB and 1 MiB output caps;
- the memory caps of 12 entries, 4 KiB and 512 B;
- the private WebSearch default host;
- the SugarCraft-specific git guidance in the Bash tool;
- sub-agent max turns.

It adds **about 30 future knobs** that the Part III roadmap will create: Bash timeout, doom-loop thresholds, steering mode, `contextPruning.*`, `autoCommit`, `lintCommands`, `testCommand`, sub-agent model and concurrency, and notifications.

**Recommended design:**
- **One `SettingsSchema` registry** of `SettingDefinition` rows. Each row records key, type, default, category, allowed tiers, `RiskClass`, `ApplyMode` (live / next turn / restart), env var, validators, options source and i18n keys. From that one registry come:
  - the editor form;
  - the generated key table in `docs/SETTINGS.md`;
  - the env-var cross-reference;
  - the rules for which tier may set which key;
  - an invariant that a project tier may only set keys whose `RiskClass` is Cosmetic, Narrowing or Tuning.
- **A full-band `SettingsEditor` view** with category tabs (Model & Provider, Agent loop, Context & Compaction, Permissions, Tools, Memory, Sub-agents, UI/Theme, and read-only Hooks/MCP, plus Server). It has fuzzy search, a provenance panel showing where each value came from, env-locked fields, live/next-turn/restart badges, reset-to-default, and a diff preview before saving.
  - **Opened by:** `/settings` (alias `/config`), the palette, the menu, or Enter on the sidebar.
  - **Built from libraries already in the dependency tree:** `candy-forms` (fields, groups, validators, `hydrate()`), `candy-fuzzy`, `candy-focus`, `candy-mouse`, `sugar-veil`, `candy-sprinkles`, `candy-layout`, `candy-core` (`AtomicJsonFile`, i18n).
  - **Optional additions:** `sugar-diff` for the save preview and `sugar-toast` for feedback. The report advises against `sugar-dash`, because it would pull `candy-pty` into the runtime.
- **A `SettingsWriter` with four tiers:**
  - "You" writes `config.json`.
  - "Project-local" writes `settings.local.json`, and only for trusted projects.
  - "Session" stays in memory.
  - Trust grants are user-tier only, need a confirm dialog, and apply on the next launch.

  Edits made mid-turn apply from the next turn. Store keys **flat with dots** (`"compaction.autoPercent"`), because `LayeredSettings::merge()` only merges one level deep.

**Phases** (full class, test and doc list in N §5):
- **P0 — schema and truth (S):** the schema, the resolver, invariant tests, and the generated doc table.
- **P1 — read-only editor (M).**
- **P2 — editing and writer (M).**
- **P3 — live apply and session tier (M).** This includes **fixing `Bootstrap::backendFor()` dropping the Task tool**, which must land before any provider or model field is exposed.
- **P4 — promote the hard-coded constants (L, incremental).**
- **P5 — polish (S).**

**Decisions for you:**
- **Persisting the model.** Should the editor save a model choice? The docs currently promise it never does.
- **Which file the editor writes.** `config.json` (recommended) or `settings.json`.

## VIII.2 Server mode (Appendix O, §0–§6, §8)

**Decisions:**
1. **Transport: ReactPHP-native WebSockets, not Workerman.**
   - **Libraries:** `react/http`, `react/socket` and `ratchet/rfc6455`.
   - **Why not Workerman:** **no sugarcraft library uses Workerman or any WebSocket code today.** Workerman exists on this host only outside the tree, under `/home/my/vendor`. It brings its own event loop, a master/worker fork model, global `$argv` parsing and nine signal handlers. All of those collide with sugar-crush's ReactPHP loop and its `pcntl_fork` turn children.
2. **Protocol: one WebSocket per client, multiplexed across sessions, carrying JSON-RPC 2.0.**
   - It reuses `sugar-mcp`'s `McpMessage` codec, with subprotocol `sugarcrush.v1`.
   - Server→client traffic is `event` notifications. Each session has a durable event log with a monotonic `seq` in a `session_events` table in `session.db`. Streaming deltas are ephemeral; full values are durable.
   - Reconnect sends `resume: {sessionId: lastSeq}`.
   - Approvals are events: any client may answer, the first answer wins, and pending asks are re-sent on reconnect.
   - The method and event catalogue (§6) covers sessions, prompting, steering, cancel, tools, diffs, sub-agents, usage, compaction, settings get/set, slash commands, memory, todos and background agents. It also includes a version handshake and backpressure rules (watermarks, 1013 close).
3. **The keystone is the same parent↔child frame channel as roadmap Wave 1.C.** Frames: `ask`, `ask_reply`, `steer`, `steer_ack`, `cancel_soft`, `usage`, `step`.
   - Building it first fixes TUI approvals **and** gives the server approvals for free.
   - The 120 s watchdog pauses while an ask is pending.
   - Parallel grandchildren get a per-job socketpair relay, which also fixes the missing live dashboard rows.
   - **Phase 1 is worth shipping even if the server never lands.**
4. **Headless core.** `Chat.php` (16,105 lines) and candy-core `Program::run()` own the event loop and the terminal. A strangler-pattern extraction therefore moves non-UI logic into `src/Host/`: `SessionHub`, `SessionHost`, `TurnController`, `TurnRunner`, `TranscriptStore`, `EventLog`, `SpendLedger`, `ContextMeter`, `CompactionService`, `TitleService`. Both `Chat` and the server become clients of it.
   - `Bootstrap` holds more than 25 static, root-sensitive caches, and `RuntimeNoticeSink` is process-global. So one server process handles **one project root**.
   - Multi-root support comes later, with one workspace-host child process per root.
5. **Background mode:**
   - **Commands:** `sugarcrush serve [--detach]`, `serve status|stop|logs|url|token`.
   - **Daemon plumbing:** reuses `BackgroundSupervisor`'s double-fork, `setsid` and 0600 IPC idioms (moved into `Support\Daemonize`), with a pidfile plus a process-start-time check.
   - **Reconnect:** `BackgroundSupervisor::reconnect()` gets its first caller at boot.
6. **Security defaults:**
   - binds `127.0.0.1` only;
   - a **mandatory token even on loopback**, exchanged for an HttpOnly, SameSite=Strict cookie;
   - single-use WebSocket tickets;
   - strict `Origin` and `Host` checks, against cross-site WebSocket hijacking and DNS rebinding;
   - server sessions default to `default` (ask) mode, and `bypass-permissions` is refused over the wire unless `--allow-bypass` is given;
   - TLS through a reverse proxy in v1;
   - the server refuses to start without pcntl/posix, because the blocking fallback would stall every session.
7. **Later phases:**
   - `sugarcrush attach` lets the TUI act as a client.
   - `sugarcrush acp` is an Agent Client Protocol stdio adapter (about 8 methods) so Zed and JetBrains can host sugar-crush.

## VIII.3 `sugar-crush-web` (Appendix O §7)

**Stack:** Vite + Vue 3 + TypeScript + Pinia + vue-router.

**Packaging:**
- It ships as a **composer package** `sugarcraft/sugar-crush-web` with a one-class PHP shim (`SugarCraft\CrushWeb\Assets::distPath()`) and a **committed `dist/`**.
- `sugarcrush serve` serves the UI on the same port, so PHP users need no Node.
- `scripts/affected-libs.php` discovers it through `composer.json` + `phpunit.xml`, and splitsh sync works unchanged.
- A Node CI job (`web.yml`) checks that `dist/` matches the source.

**Code layout:**
- TypeScript lives in `src-web/`.
- `protocol/` holds the generated types from `docs/protocol/sugarcrush.v1.schema.json`, the client, reconnect logic (backoff 0.5 s→15 s with jitter) and the seq cursor.
- Pinia stores: connection, sessions, session, approvals, layout, settings.

**UI:**
- a sessions sidebar;
- tabs **and** a tiled multi-pane grid for watching several sessions at once;
- a virtualised transcript with markdown, code and reasoning folds;
- tool cards with diffs;
- permission cards plus a **cross-session approvals drawer** with browser notifications;
- a composer with queue / steer / interrupt;
- a status bar showing context %, spend and cap, model and permission mode;
- a sub-agent tree, background tasks, workflows, a memory panel and a command palette;
- a **settings form generated from the server's settings schema** — the same `SettingsSchema` as VIII.1, so the TUI and the web share one source of truth.

**Testing:** vitest, plus Playwright end-to-end tests against the offline `EchoProvider` with scripted tool calls.

**Name:** `sugar-crush-web` has three words, which breaks the two-word rule in `PROJECT_NAMES.md`. The report suggests recording an "app satellite `<app>-<surface>`" exception, or renaming to `sugar-console`. **Your call.**

**Server and web phases** (O §9): 0 spikes (S) → 1 bidirectional fork channel and TUI approvals (L) → 2 Host extraction (L) → 3 server and protocol (L) → 4 daemon and background agents (M) → 5 web MVP (L) → 6 multi-session polish (L) → 7 multi-root workspace hosts (M–L) → 8 `attach` and ACP (M–L).

**Effort:** about 7–9 weeks for one engineer to reach phase 6, and about 9–11 weeks through phase 8.

**Open questions:**
- the package name;
- the default port (7420 proposed);
- whether to move the TUI default off bypass once phase 1 lands;
- whether committing `dist/` is acceptable;
- whether TLS via a reverse proxy is enough for v1;
- whether Workerman infrastructure must be shared with another project.

## VIII.4 Sessions, live agent lines, agent view and direct chat (Appendix P)

**Sessions: listing and renaming already exist, but are thin, with three bugs.**
- **LIVE today:** `/sessions`, Ctrl+R, `/rename <name>`, auto-titles, the tab strip, `--resume`, `-c`, and `sugarcrush session list|delete`.
- **Bugs:**
  - **B1:** the picker's Ctrl+B branch filter is always empty, because the branch is hard-coded to null (`Chat.php:11844`).
  - **B2:** a `/rename` typed while the first auto-title is still being generated gets overwritten (`Chat.php:9011-9065`, `:1835`).
  - **B3:** the "summary" column shows the session's system prompt (`Chat.php:11843`).
- **Design:**
  - a revamped `SessionPicker` with `/`-filter fuzzy search and useful columns (title, updated, turns, model, status);
  - grouping, plus inline rename, delete (double press), pin, archive and fork, and a toggle for child sessions;
  - `/rename` with no argument opens inline edit; `--auto` regenerates the title;
  - a schema migration adding `kind`, `parent_id`, `pinned`, `archived_at`, `title_source` and more;
  - a `TitleSource` enum, so a title the user set always beats an auto-title;
  - CLI `session rename|show|pin|archive`;
  - the tab strip and `--continue` exclude sub-agent sessions.

**Live agent activity lines.**
- **Problem:** `TaskTool` already emits `SubAgentActivity` beats as `subagent` frames, but:
  - they have no parent tool-call id, so they cannot be attached to the right Task row;
  - they carry only a 4 KB text tail, have no stats, and always report success;
  - they are **dropped entirely for parallel Task batches**, because the emitter is tied to the turn process and parallel Tasks run in grandchildren (`EngineBackend.php:1068-1077`);
  - the Task row shows a static `⠴ running:`.
- **Design:**
  - a v2 frame with structured, coalesced activity items and stats (at most 4 Hz, 8 KB, 32 items);
  - a **per-grandchild DGRAM socketpair relay** in `Runtime::executeConcurrently()`;
  - an `AgentLiveRegistry` in the parent;
  - one width-safe line per running agent under its Task row, e.g. `└ ⠋ Grep "LoginController" · 7 tools · 0:12 · 4.1k tok`;
  - segments drop in a set priority order on narrow terminals;
  - outcome glyphs ✓/✗/⏹/⏸;
  - a one-row agent strip above the input (Alt+↓);
  - per-instance dashboard rows.

  This is how opencode and Claude Code show running agents.

**Agent view and direct chat.**
- **What exists:** the skeleton (`AgentViewMode::{List,Peek,Attach}`, `AgentOutputPane::renderAttach()`) is **unreachable**. The cancel, resume, stop-all, group-input and quit-view commands are inert, and `Mailbox` is dormant.
- **Opening the view:** clicking an agent line (a `candy-mouse` zone keyed only by the safe agent id) or pressing Enter on it swaps the main transcript area for that agent's live transcript. The parent tails a **per-agent JSONL transcript log written by the agent's own process**, which keeps transcript volume off the frame channel.
- **Messaging the agent:** the input box becomes that agent's composer. Messages go through an `AgentInbox` built on the dormant `Mailbox`, HMAC-signed `from:'user'`, framed as untrusted, and drained at the sub-agent's step boundaries.
  - That uses the same `TurnInbox` seam as the Wave 1.C steering, **but does not depend on it**.
- **Controls:** soft cancel (an inbox control message), then hard cancel (SIGTERM via the turn child); pause, capped at 10 min with heartbeats; stop-all; broadcast; and "open as session".
- **Finished agents** become child sessions (`kind='subagent'`, `parent_id`) that you can view and cold-resume.
- **Web compatibility:** every DTO serialises to the same event envelope the server mode uses (`agent.spawned`, `agent.activity`, `agent.status`, `agent.message`), so the web UI gets the same features.

**Phases** (P §6.5):
- **A — sessions (M):** independent, so it ships first.
- **B — live lines (M).**
- **C — read-only agent view (M).**
- **D — direct chat and controls (M–L).**
- **E — hard cancel, approval relay and background (L):** needs 1.C, 4.3 and server mode.

**Correction to Appendix C:** in opencode's current source the prompt input is hidden in child sessions (`index.tsx:240`), so you cannot type into a child there. sugar-crush's design lets you message agents directly anyway, as Claude Code does.

## VIII.5 How the new features fit the Part III roadmap

The new requests overlap heavily with Wave 1.C. The recommended build order is:
1. **Wave 0** quick fixes, plus the **sessions bugs B1–B3** and **Phase A sessions** (independent).
2. **Wave 1.C (= server Phase 1)**: the bidirectional fork channel, TUI approvals, steering and per-step usage frames. This is the foundation for approvals, steering, direct agent chat and server mode.
3. **Live lines (P-B)** together with the **grandchild relay**. The relay is needed by 1.C for parallel asks, by the live lines and by the server's sub-agent tree, so build it once.
4. **Settings P0–P2** (schema, viewer, writer). The server's `settings.get/set` and the web form reuse the same schema.
5. **Waves 1.A/1.B and 2** (cache-stable prompt, structured replay, context engine), with **agent view P-C/P-D** in parallel.
6. **Host extraction → server → web MVP** (O phases 2–5), then **Wave 3/4** (checkpoints, self-pruning, background sub-agents), and the **multi-session web polish**.

---

# Part IX — Code-audit findings (new defects in sugar-crush)

Five agents audited sugar-crush's own source for **new** defects, one per area. Each was told the Part II list so it would not re-report known items. They worked from master @ `05db616f3` with PHP 8.3.6, wrote repro scripts under `/home/sites/crush-research-repos/_audit-scratch/<id>/`, changed no source and committed nothing.

**Totals at audit time: about 136 findings** — 1 Critical, 20 High, about 47 Medium, and the rest Low-Medium, Low or Info.

**Since the audit, 160 findings have been fixed on master** (each appendix ends with a **Fixed since audit** list giving the commit): 129 of the original findings, the 4 new items found while fixing them in wave 1, all 23 items waves 2 to 6 found while fixing theirs (10 in wave 2, 5 in wave 3, 3 in wave 4, 3 in wave 5, 2 in wave 6), all 3 that waves 7 and 8A found (MCP-10, A26, F-D1, fixed in wave 8B), and the 1 that wave 8B found (B8, fixed in wave 9). Wave 9 found 2 more (15b-35, the status bar's static model id, and CLI-3, a launch-notice cap now below its worst case); both are open. **About 9 findings remain** — 0 Critical, 0 High, 0 Medium-High, 2 Medium (A21 and SES-3, each partly fixed), 1 Low-Medium (CLI-2, partly fixed), and 6 Low (A15, 15b-14, 15b-15, 15b-35, F-J5 and CLI-3). Two residuals are tracked on their parents' **Fixed since audit** lines: R1 (EngineBackend's per-turn `App` has no compactor config) and R3 (`AgentWorkerPool::terminateWorker()` still blocks for about 0.5 s). The tables below count what remains.

At audit time, about two thirds were **reproduced with a script**; the rest are verified by reading, and a few are marked *suspected*. Full write-ups are in Appendices Q–U; each finding has code excerpt, failure scenario, fix and a test that would catch it.

| Appendix | Area | Findings | Critical / High |
|---|---|---|---|
| **Q** (15a) | Engine, runtime, providers, tool-call parsers, process support | 2 | 0 / 0 |
| **R** (15b) | Chat state machine, TUI, rendering, commands | 3 | 0 / 0 |
| **S** (15c) | Tools, permissions, hooks (security) | 1 | 0 / 0 |
| **T** (15d) | Context assembly, memory, skills, config | 0 | 0 / 0 |
| **U** (15e) | Agents, workflows, sessions, MCP, git MCP, CLI | 3 | 0 / 0 |

Appendix P adds three session-picker bugs, B1–B3 (Part VIII.4).

## IX.1 Corrections to earlier parts

- **Argument-scoped permission rules ARE implemented** (Appendix S, top callout; `PermissionRule::matches()` / `matchesShellSubject()`). `Bash(rm *)` deny denies, and `Bash(git *)` no longer grants all of Bash for **permission rules**.
  - Part II #23 now says so; roadmap item 4.2 now covers only preset grants and path respellings.
  - `docs/PERMISSIONS.md` is now corrected: its stale "Pattern matching is name-only" section was rewritten in `d3d90fece` to describe argument-scoped rules as implemented, and since `c8fc573a5` it describes the fail-closed allow rules.
  - Sub-agent **preset grants** still match by name (`AgentManager::resolveGrantedTools`).
  - The new matcher's remaining real gap was F-J3, now fixed: path rules match the root-anchored, resolved and symlinked spellings on the main tool loop (`3b7d2fd33`) and in sub-agent gates and preset grants (`a5b6e3d78`); declaration checks have no path subject, so they need no root, and Chat's own `!` checks judge only Bash (F-J3-rem (b), closed as moot in wave 9 and pinned by `ChatBashGateRootTest`, `e76e8a93e`). F-P5 (`$(…)`, backticks and redirects slipping past allow rules) was fixed in `c8fc573a5`.
- **Two documentation statements were stale:** "rule `paths:` scoping not applied" (it is) and "only two keys re-applied per turn" (`maxOutputTokens` is too). Both are now corrected and pinned by `DocFigureProseDriftTest` (`232013284`).
- **The image-marker / mouse-zone collision from project memory is already fixed** (Appendix P). Since 15b-17's fix (`b38bf8403`, wave 7) an image marker is an authenticating escape plus its cell, so a bare Private-Use codepoint in agent text is inert and no longer needs stripping.
- **Workerman is not used anywhere in the monorepo** (Appendix O §3).

## IX.2 Cross-cutting defect themes

Several audits found the same root cause in different places. Fixing each theme once fixes them all.

1. **Killing a turn did not kill all of its commands (fixed).** Turn teardown and the parallel deadline kill the whole process tree (`ProcessContainment::killTree()`, `c54372b2a`), and since waves 7 and 8A so do the dormant Chat site (`83a92e36d`) and `AgentWorkerPool`'s cancel path (`2b136d35a`); `EngineExecutor` runs inside the pool's fork, so the pool's tree kill covers it (F-E2). Since wave 9 the engine teardown and both Chat cancel sites kill the tree on the loop (`ProcessContainment::killTreeAsync()`, `775bfd1e6`), so Escape no longer holds the loop for the whole walk (about 120 ms → one 30 ms snapshot). What remains: `BackgroundSessionRunner` still kills without `killTree()`, and `AgentWorkerPool::terminateWorker()` still calls the synchronous `killTree($pid, 0.5)`, which can block for about 0.5 s on the TUI thread (R3, partly fixed).
2. **Terminal-injection and rendering hygiene (fixed).** CR, UTF-8 C1 controls, the permission modal's byte wrap and `error_log()` output over the frame are fixed (`Sanitize::untrustedForDisplay()`, the C1 sweep, `Sanitize::visibleControls()`, and `TuiErrorLog`, which sends the TUI's `error_log` to `~/.sugar-crush/logs/sugarcrush.log`), and the status bar, frame and session tab strip are clipped to the terminal width, with tab names sanitized. Waves 7 and 8A closed the rest: forged image markers (15b-17), bidi overrides and zero-width characters (15b-28, marked visibly by `Sanitize::markInvisibleFormatting()`), lone raw C1 bytes in candy-shine (15b-29), the `Width::wrap()` hang (15b-26), and the permission prompts' invalid-UTF-8 and control-character arguments (15b-27, R17). Wave 9 closed the last items: the notice sink's clip and overflow strings name where the full text really went (C4), `TuiErrorLog` falls back to a private temp-dir log or the null device instead of the tty (R16), the image-marker prose describes the two-part marker (15b-32), and the permission modal and transcript tables fit terminals under 26 columns (R4).
3. **Repo-controlled content reaches the prompt without fencing or caps (mostly fixed).**
   - A repo's skills no longer shadow the user's own: precedence is built-in < project < user and every shadowing is reported (15d-03 (b), `9e69d6c9e`), and since wave 9 the launch notice names shadowed skills as well as unreadable ones (15d-03, `32340e1b5`).
   - Instruction documents, `@imports` and enabled skill bodies have byte budgets, and since wave 8A every skill file read is bounded too (15d-27, `9e69d6c9e`). Since wave 9 a launch notice names the instruction files and skill bodies the budgets left out (R1, `d1416fb86`); `EngineBackend`'s per-turn `App` still uses the default compactor budgets (R1, partly fixed).
4. **Prompt assembly read the user's git config and the filesystem nondeterministically (fixed; one prompt-layout item remains).** The env block's git calls now run with `--no-optional-locks -c color.ui=false`, diff through plumbing with `--no-ext-diff`, never write the index, and are bounded at 2 s each; a subdirectory launch reports the repo root and its git state; rule and repo-map walks sort before capping (15d-12, 15d-14, 15d-13 (a), 15d-17).
   - Since wave 8B, `.sugar-crush/*` and `.mcp.json` lookups walk up to the repo root on a subdirectory launch (15d-13 (b), `f2c1f0445`).
   - What remains is not an audit item: the env block is still re-rendered inside the system message, which hurts cache stability (Part I #2, Part II #2).
5. **Errors are swallowed and turns "succeed" (fixed).** The last open case, malformed tool-call arguments that ran the tool with `[]`, now gets an error result and the tool does not run (`16b9d6750`).
6. **The permission layer had holes in the default and stricter modes (mostly fixed).** Accept-edits now grants in-root Edit and Write and asks for `rm`, `mv` and `cp` (F-P4); WebFetch left the read-only class and gained `WebFetch(domain:…)` rules (F-P6); `auto` classifies Write, Edit, WebFetch and `mcp__*` (F-P3 (b)); Bash, Grep and hooks get a scrubbed environment (F-E1); refusals are carried structurally, so tool output cannot forge one (F-P8); hook asks are no longer silenced by the Task memo or a Chat "Always" grant (F-P7, F-P9); path deny rules match respellings and symlinks in sub-agent gates too (F-J3, wave 8B); and `docs/PERMISSIONS.md`'s introduction describes argument-scoped rules (F-D1, wave 8B). What remains:
   - The TUI engine path refuses every Ask (Part II #1), so the new asks are effectively refusals there.
7. **Unbounded or stalled work inside `update()`.**
   - A long streaming reply whose headings follow a closing code fence no longer re-renders whole on every frame: since wave 9 candy-shine's `SectionScanner` cuts there and `stream()` keeps its `render()` law across reference definitions, so sugar-crush dropped both workarounds (15b-30, 15b-31; 8/34/70 ms per frame at 20K/100K/200K).
   - Transcript persistence still runs synchronously inside `update()`, though each save is now one transaction and `/branch` no longer re-interns the history (R2, a residual noted in Appendix R's **Fixed since audit** list; scheduled for wave 10).
8. **Sessions and persistence integrity.**
    - No writer lock: two TUIs on one session still overwrite each other's transcript. The checkpoint-index and blob-intern races are closed (SES-3, partly fixed; the lock and second-TUI behaviour are scheduled for wave 10).
    - A `/fork` now copies the whole conversation, but the background daemon does not load it (Part II #30).
    - UI-only command output and notices are kept off the wire and out of the compaction summary by `Message::$uiOnly`, and they render inline as dimmed `notice:` rows (15b-03, fixed in wave 8B; relates to Part II #2 and the DCP `uiOnly` proposal).
9. **MCP interoperability and trust gaps (fixed).**
    - Trust is bound to each server's command, args and env, and a changed or added server is refused until `sugarcrush mcp trust` re-records it (MCP-5, wave 8B).
    - Dynamic client registration sends its metadata at the top level (MCP-10, wave 8B). The OAuth discovery, storage and expiry bugs (MCP-6/7/8) and the stale MCP.md ordering claim (DOC-2) were fixed in wave 7.
10. **Cost accounting holes (mostly fixed).**
    - Task sub-agent spend reaches the parent, the session total and the cap; since wave 8B, parallel sibling Tasks share a spend ledger, so a batch stops at the cap, and a crashed tool child still bills. Since wave 9 Chat's calibration fallback reads `Usage::ownTokens()`, so sub-agent tokens no longer inflate it (B4, fixed).
    - Vertex and Bedrock have list-price tables, flag unknown models unpriced and now receive the user's `modelPrices`, and the default Bedrock config sends the inference-profile id (A20, fixed). Cache read and write tokens are still unpriced there, though neither provider sends cache breakpoints yet (A15, partly fixed).
    - The OpenAI context window has a `contextWindow` override (A13, fixed), and a project can no longer choose the title and summary models (15d-24, fixed).
    - `claude-code` turns sum the CLI's usage buckets, so they report their tokens (A25, fixed in wave 9).

## IX.3 Critical and High findings: fix first

The Critical item and all 20 High items are fixed on master, as is the latent High in the sub-agent path that was found while fixing them. Both Medium-High items are fixed too: F-E2 in waves 7 and 8A, and 15b-03 in wave 8B (`5d2aaae34`, `6fddd0d3a`: compaction reads agent-visible rows only, and notices render inline in a distinct dim style). No Critical, High or Medium-High finding remains; the two open Medium items (A21, SES-3) are each partly fixed (B4 and 15d-03 were finished in wave 9).

## IX.4 Where the audit fixes slot into the roadmap

- **Before Wave 0, as an "audit hotfix" wave (mostly S):** this wave has landed on master in full (see the **Fixed since audit** list at the end of each of Appendices Q–U).
- **With Wave 0:**
  - cost accounting (theme 10): only A15's cache-token pricing remains, owed by whoever wires cache breakpoints;
  - show the served SGLang model in the status bar (15b-35) and raise the launch-notice cap to cover every bounded source (CLI-3).
- **Before Wave 1.C ships:** nothing remains. The permission modal's empty value for an invalid-UTF-8 argument (15b-27), bidi overrides in the text it shows (15b-28), and the dormant Chat-path mirrors of the F-H1 and F-H3 fixes were all fixed in waves 7 and 8A.
- **With Wave 1.B:** stable unique ids. (15b-03, the `uiOnly` flag with its compaction filter and notice style, landed in full in wave 8B.)
- **With Wave 4 (sub-agents and orchestration):** wire `BashEscapeDenyHook` and the Glob/Grep/Lsp worktree jails, which now work but have no production caller, together with worktree isolation (F-J5, partly fixed; Part II #23).
- **With the sessions phase (VIII.4 A):**
  - SES-3 (b) (writer lease; the server design's `session_leases` table covers it), CLI-2 (b) (leftover words as the TUI's initial prompt) and R2 (debounced transcript persistence) — scheduled together as wave 10;
  - session picker bugs B1–B3.
- **With rendering work:** nothing remains; 15b-30 and 15b-31 landed in wave 9 and sugar-crush dropped its workarounds.
- **Feature wave:** attachments (15b-15, `@file` mentions and image paste), scheduled as wave 11.
- **Deferred:** 15b-14 (i18n), until after the roadmap; F-J5's production wiring waits on worktree isolation (Part II #23); A21 (b) waits on a live Gemini 2.5 check.

**Remaining open findings (9):** A21 (Medium, partly fixed: live check owed), SES-3 (Medium, partly fixed: (b) writer lock), CLI-2 (Low-Medium, partly fixed: (b) initial prompt), A15 (Low: cache tokens unpriced on Vertex and Bedrock), 15b-14 (Low: no i18n), 15b-15 (Low: attachments dormant), 15b-35 (Low: status bar shows the static model id), F-J5 (Low: unwired until worktree isolation) and CLI-3 (Low: launch-notice cap 24 < worst case 26). Residuals on fixed findings: R1, R2, R3.
