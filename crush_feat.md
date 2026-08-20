# sugar-crush Feature & Architecture Research

Comparative research: [opencode](https://github.com/anomalyco/opencode), Claude Code, and the broader open-source AI-coding-CLI landscape (Aider, Cline/Roo, Continue.dev, Goose, Codex CLI, Gemini CLI, Amp, Warp) vs. sugar-crush's actual current implementation, plus a dedicated pass on SGLang + MiniMax M2.7 backend wiring. Produced by 12 parallel research agents, each combining live web research with direct reading of sugar-crush's source (and the wider SugarCraft monorepo's `candy-*`/`sugar-*` libs).

**How to read this doc**: the Executive Summary below is the actionable part — it separates real bugs from missing features from "already built but never wired up." The 12 numbered sections after it are the full research dossiers each agent produced, kept close to verbatim (including code sketches, file:line citations, and source links) so implementation work can be done directly against them.

---

## Executive Summary

### 🔴 Bugs to fix now (not feature requests — these are broken today)

1. **Streaming tool calls are silently dropped.** `SglangProvider::parseChunk()` and `CustomProvider::parseChunk()` (`src/Providers/`) never read `delta.tool_calls` — tool calling only works in non-streaming mode. See [§12 D2](#12-sglang-backend).
2. ~~**Unverified whether the SGLang deployment has the right parser flags.**~~ **RESOLVED 2026-08-10** — confirmed via the actual `docker run`/`sglang serve` launch command for `skynet2.interserver.net`: it does pass `--tool-call-parser minimax-m2`, so server-side XML→JSON tool-call translation is active. One discrepancy worth noting: the deployment uses `--reasoning-parser minimax`, not `minimax-append-think` (the value found in MiniMax's own docs during research) — these may or may not be aliases; worth a quick check that `reasoning_content` splits out correctly (see D3) since this determines whether §12 D3's `separate_reasoning` wiring behaves as expected. The confirmed launch command also fixes `--context-length 196608`, matching this doc's D8 recommendation to stop hardcoding `contextWindow()` at 128,000. Full command preserved in [§12](#12-sglang-backend) for reference. **The client-side bugs (D2 streaming tool-call parsing, D3 reasoning wiring, D4 missing `extra_body`/sampling params, D8 hardcoded context window) are still open** — only the server-side risk is resolved.
3. **`--help`/`-h` opens a blocking full-screen TUI instead of printing usage.** `bin/sugarcrush` does zero argv parsing — any flag is swallowed as chat input. See [§2 D](#2-cli-commands-parameters-help-screens--non-interactive-mode).
4. **MiniMax-M2.x has a known upstream bug**: tool-call arguments containing the literal substring `</parameter>` get truncated mid-value (confirmed in both vLLM's parser and MiniMax's own hosted API — not SGLang-specific, not fixable client-side, but detectable). Any sugar-crush tool whose arguments could contain that substring (Edit/Write bodies, XML/HTML/PHP/`.tape` content) is exposed. See [§12 D5](#12-sglang-backend).

### 🟡 Fully built, fully tested, never wired into the live app

This is the single biggest recurring pattern across the whole audit — sugar-crush has already built the *right* subsystem for nearly every feature area investigated, and in most cases it just isn't reachable from `bin/sugarcrush`/`Chat.php`/`AppBuilder`:

| Subsystem | Status | Where the wire is missing |
|---|---|---|
| `InstructionFileLoader::loadRoot()`/`loadForced()` | Implemented, unit-tested | Never called — root `CLAUDE.md`/`AGENTS.md` never auto-load into the system prompt. Only nested on-touch loading (`loadForPath()`) is actually wired. See [§6](#6-auto-loading-project-context-files-agentsmdclaudemd--environment-info-to-the-ai). |
| Environment info block (cwd, git status, platform, model, date) | Doesn't exist at all | No equivalent of Claude Code's/opencode's "Environment" system-prompt section anywhere in `Runtime::buildSystemPrompt()`. See [§6](#6-auto-loading-project-context-files-agentsmdclaudemd--environment-info-to-the-ai). |
| Skills subsystem (`SkillLoader`, `SkillRegistry`, 12 built-in `SKILL.md` files) | Fully built, unit-tested, near-1:1 with Claude Code's SKILL.md spec | `App::availableSkills` is **never populated** in `AppBuilder::build()` — the entire skill roster is dormant in a live session. `bin/sugarcrush` has zero references to `Skill` at all. See [§7](#7-automatic-skill-loading-based-on-taskcontentcontext). |
| `SessionStore`/`EnhancedSessionStore` + `SessionPicker` + `SessionTabs` | Fully built, unit-tested | `SessionStore::createSession()` is **never called** in production — `listSessions()` always returns `[]`, so `/sessions`, the tab strip, and Ctrl+Tab cycling are all correctly implemented but permanently unreachable. See [§5](#5-navigating-between-agentssessions--resuming-old-sessions). |
| `BackgroundSupervisor`/`BackgroundSession` (fork+daemonize, heartbeat, stall detection, reconnect-on-reopen) | Fully built | Zero live callers anywhere — no `/bg` command exists to trigger it. See [§5](#5-navigating-between-agentssessions--resuming-old-sessions). |
| `Chat::subscriptions()` | Exists, wired into `candy-core`'s Program by default | Hardcoded to always return `null` — kills any possibility of a live poll loop (which would also be needed for the background-session item above). See [§5](#5-navigating-between-agentssessions--resuming-old-sessions). |
| `candy-mouse`/`candy-zone` (complete bubblezone-equivalent hit-testing: `Mark`, `Scanner`, `ZoneClickTracker`, hover/drag/multi-click trackers) | Complete, tested, sibling libraries | `grep -rln "Mouse" src/` returns **zero files** in sugar-crush. Mouse mode isn't even turned on (`ProgramOptions::$mouseMode` defaults `Off` and is never overridden). See [§8](#8-mouse-integration). |
| `candy-mosaic` (Sixel/Kitty/iTerm2/half-block/quarter-block image rendering, DA1/XTWINOPS auto-detect, tmux passthrough) | Complete, more capable than either opencode or Claude Code's own (nonexistent) image rendering | Not even a `composer.json` dependency of `sugar-crush`. `ToolResult` has no image/attachment field. See [§9](#9-image-video-viewing-in-tui). |
| `AgentPreset`/`AgentPresetRegistry` (file-based subagent definitions, near-identical schema to Claude Code's `.claude/agents/*.md` frontmatter) | Fully built | Never pointed at `.claude/agents/` or `.opencode/agents/` — no foreign-agent import exists. See [§10](#10-cross-tool-skillagentconfig-compatibility). |

### 🟠 Two systems where one should exist (drift risk)

- **Two parallel tool-calling pipelines.** `Chat.php`'s own `registerTool()`/`ToolCall`/`ToolResult` path has a UI (spinners, running/result rendering) but **zero permission/hook gating**. `EngineBackend`/`Runtime`'s `Tools\Tool` path has real pre/post hooks (`HookManager`) but its intermediate tool calls are **thrown away** — only the final message escapes the loop, so the user watching the chat sees nothing during up to 8 rounds of tool use. See [§1](#1-tool-call-parsing-handling--display).
- **Two disconnected UI systems.** The live `Chat.php`/root `Renderer.php` path is what `bin/sugarcrush` actually runs. A second, fully-built `App`/`Tui\Renderer`/`AgentsPane`/`ToolsPane` system exists in parallel and is **provably unreachable** — its own docblocks admit this. `ToolsPane::render()` hardcodes `'(tool history empty)'`. Recommendation: delete it or migrate onto it deliberately, not both. See [§1](#1-tool-call-parsing-handling--display), [§5](#5-navigating-between-agentssessions--resuming-old-sessions).
- **Two drifting command lists.** `PaletteAction` (Ctrl+P) and `CommandRegistry` (`/`-menu) overlap only partially; the real dispatch logic lives in a third place (`Chat::submit()`'s `str_starts_with()` chain), which both metadata classes' own doc-comments admit doesn't reflect reality. See [§4](#4-command-palette-ctrl-p--slash-commands).
- **Two `ToolCall`/`ToolResult` type pairs** with different field names, living in different namespaces. See [§1](#1-tool-call-parsing-handling--display).

### 🟢 Genuine feature gaps vs. the competitive landscape

Ranked roughly by leverage — see each section for full detail and code sketches:

1. **No non-interactive mode at all** (`sugarcrush -p "prompt"` / `run`) — the single biggest CLI gap vs. opencode/Claude Code/Codex/Gemini, all of which treat this as the primary scripting entry point. The primitive it needs (`EngineBackend::complete()`) already exists synchronously. [§2](#2-cli-commands-parameters-help-screens--non-interactive-mode)
2. **No repo-map** (tree-sitter/symbol-graph codebase orientation, à la Aider). [§11](#11-other-notable-features-from-the-broader-landscape)
3. **No deterministic auto-commit hook** — git exists only as a model-*optional* MCP tool, not an automatic post-edit commit. [§11](#11-other-notable-features-from-the-broader-landscape)
4. **No file-system-level checkpoint/undo** — the existing `/rewind` only restores chat/model state, not file contents. [§11](#11-other-notable-features-from-the-broader-landscape)
5. **No lint/test auto-run-and-fix loop** after edits (Aider-style). [§11](#11-other-notable-features-from-the-broader-landscape)
6. **`Edit` tool produces no diff** — not even internally, so there's nothing to show even once tool-call rendering is fixed. [§1](#1-tool-call-parsing-handling--display)
7. **No auto-generated session titles** — every session is unnamed unless manually `/rename`d; even Claude Code itself still has this as an open feature request, so shipping it would be a genuine differentiator. [§3](#3-session--command-auto-summarization)
8. **No per-tool-call human-readable description** — sugar-crush's tool schemas have no `description` field the model fills in, so "what's running" is a raw arg dump instead of English ("Search for the auth bug in src/"). [§3](#3-session--command-auto-summarization)
9. **No process-level sandbox** (Codex/Gemini CLI-style container/namespace isolation) — only a path-prefix jail exists today. [§11](#11-other-notable-features-from-the-broader-landscape)

### ✅ Already ahead / already at parity (don't re-build these)

Confirmed built, and in several cases **more complete** than opencode's or Claude Code's own equivalents: plan-mode-equivalent permission modes (6 modes incl. `plan`), sub-agents with 6 presets incl. architect, multi-agent teams with mailboxes + isolated git worktrees, full workflow/pipeline orchestration engine (YAML or PHP DSL — covers Goose's "recipes" concept), session sharing, a full MCP client/server stack including a native Git MCP server, multi-provider backend abstraction (8 providers), token/cost tracking, LSP integration, persistent cross-session memory (Markdown+YAML, closely mirroring Claude Code's own auto-memory shape), on-touch nested `AGENTS.md`/`CLAUDE.md` loading (ahead of opencode, which has this as an open feature request), and conversation-state checkpoint/rewind. See [§11](#11-other-notable-features-from-the-broader-landscape) for the full verified inventory with file paths.

---

## Table of Contents

1. [Tool-call parsing, handling & display](#1-tool-call-parsing-handling--display)
2. [CLI commands, parameters, help screens & non-interactive mode](#2-cli-commands-parameters-help-screens--non-interactive-mode)
3. [Session & command auto-summarization](#3-session--command-auto-summarization)
4. [Command palette (Ctrl-P) & slash commands](#4-command-palette-ctrl-p--slash-commands)
5. [Navigating between agents/sessions & resuming old sessions](#5-navigating-between-agentssessions--resuming-old-sessions)
6. [Auto-loading project context files (AGENTS.md/CLAUDE.md) & environment info](#6-auto-loading-project-context-files-agentsmdclaudemd--environment-info-to-the-ai)
7. [Automatic skill loading based on task/content/context](#7-automatic-skill-loading-based-on-taskcontentcontext)
8. [Mouse integration](#8-mouse-integration)
9. [Image/video viewing in-TUI](#9-image-video-viewing-in-tui)
10. [Cross-tool skill/agent/config compatibility](#10-cross-tool-skillagentconfig-compatibility)
11. [Other notable features from the broader landscape](#11-other-notable-features-from-the-broader-landscape)
12. [SGLang backend — request parameters, API surface & tool-call parsing (MiniMax M2.7)](#12-sglang-backend)

---

## 1. Tool-call parsing, handling & display

### A. opencode (anomalyco/opencode, `dev` branch — TypeScript/Effect + Bun core, SolidJS + Zig "OpenTUI" frontend)

opencode is **not** the Go/Bubble Tea project some search results surface (that is an older, unrelated fork, `opencode-ai/opencode`). The live `anomalyco/opencode` repo is a full TS rewrite: `packages/opencode/src/*` is the server/engine (Effect-TS), `packages/tui/src/*` is a SolidJS renderer running on the custom OpenTUI (Zig) terminal renderer. Everything below is read directly from source on the `dev` branch, not docs.

**Tool definition & execution — `packages/opencode/src/tool/tool.ts`**
Every tool is a `Tool.Def`: `{ id, description, parameters (Effect Schema), jsonSchema?, execute(args, ctx): Effect<ExecuteResult> }`. `ExecuteResult` is `{ title, metadata, output, attachments? }` — note the **separate `title` field**, distinct from raw `output`: the TUI renders the title as the one-line summary and only shows `output` when expanded. The `wrap()` helper compiles the parameter-schema decoder once per tool, wraps `execute` with an OpenTelemetry span (`Tool.execute`, tagged `tool.name`/`session.id`/`message.id`/`tool.call_id`), and — critically — **auto-truncates** oversized output via a shared `Truncate.Interface`, writing the full content to a side file (`outputPath`) and flagging `metadata.truncated`. A schema-validation failure becomes a typed `InvalidArgumentsError` whose `.message` is deliberately phrased as model-facing feedback ("...Please rewrite the input so it satisfies the expected schema"), i.e. tool-input validation errors round-trip back to the LLM as a correctable tool result rather than crashing the turn.

The `Context` passed to every tool carries `ask(input): Effect<void>` — a tool can request permission **mid-execution**, not just via a blanket pre-hook (e.g. Bash asks before running, Edit asks before writing).

**Permission gating — `packages/opencode/src/permission/index.ts`**
This is the most transferable piece for sugar-crush. A `Permission.Service` holds `pending: Map<ID, {info, deferred}>` and `approved: Rule[]`. `ask(input)`:
- Evaluates `evaluate(permission, pattern, ruleset, approved)` per each of `input.patterns` — a wildcard `{permission, pattern, action}` rule table (`Wildcard.match`), `findLast()` so more-specific/most-recent rules win, default falls through to `{action: "ask"}`.
- A `deny` match short-circuits with `DeniedError` immediately (no prompt).
- An `allow` match skips silently (no prompt).
- Anything left `ask` creates a `Deferred`, stores it in `pending`, publishes an `Event.Asked` on the event bus, and the calling Effect **blocks on `Deferred.await`** until `reply()` is called from elsewhere (the TUI, in another process/tick).
- `reply({reply: "once"|"always"|"reject", requestID, message?})`: `reject` fails the deferred and **cascades**, rejecting every other still-pending request in the same session (one "no" kills a whole batch of parallel asks); `always` appends a new `allow` rule to `approved` for every pattern in `request.always`, then **retroactively resolves any other pending request that now matches** the newly-approved rule (so approving "edit `*.php`" once immediately unblocks 3 other queued Edit calls without individual prompts).
- `fromConfig()` turns a user's config-file permission block into the rule table, with `~`/`$HOME` expansion. `disabled()`/`visibleTools()` let a global `deny` on `edit` hide the `edit`/`write`/`apply_patch` tools from the model's tool list entirely (never offered, not just blocked at call time).

**Permission UI — `packages/tui/src/routes/session/permission.tsx`**
`PermissionPrompt` is a SolidJS component with three stages (`permission` → `always` / `reject`). It builds a per-permission-kind `{icon, title, body}`:
- `edit`: renders a real unified/split **diff** via an OpenTUI `<diff>` element sourced from `request.metadata.diff`, switching unified↔split based on terminal width (`>120` cols → split) or a user config `diff_style: "stacked"` override — syntax-highlighted, with add/remove/context background colors, gutter line numbers.
- `bash`: shows the literal `$ <command>`.
- `task` (subagent spawn): shows subagent type + description.
- `webfetch`/`websearch`: shows URL/query.
- `external_directory`: shows the derived directory + every glob pattern being requested.
- fallback: generic `Call tool <permission>`.

Options are `{once: "Allow once", always: "Allow always", reject: "Reject"}`, keyboard-navigable (←/→/h/l, Enter, Esc = reject), with a `fullscreen` toggle for long diffs. Choosing "Reject" on a **subagent's** permission request (`session()?.parentID` truthy) routes to a `RejectPrompt` textarea instead of a plain reject — the user can type free-text feedback that gets threaded back to the model as the rejection reason (`CorrectedError({feedback})`), not just a bare denial.

**Tool-call rendering in the transcript — `packages/tui/src/routes/session/index.tsx`**
A `ToolPart` dispatcher component (~line 1717) switches on `toolDisplay(part.tool)` to one of ~13 specialized renderers (`Shell`, `Read`, `Grep`, `Glob`, `WebFetch`, `WebSearch`, `Write`, `Edit`, `Task`, `Execute`, `ApplyPatch`, `TodoWrite`, `Question`, `Skill`) with `GenericTool` as fallback. Each tool part carries a `state.status` of `pending | running | completed | error`, and every renderer is a thin wrapper around one of two shared primitives:
- **`InlineTool`/`InlineToolRow`** — a one-line `icon + summary` row. While `status === "running"` it swaps to a live `<Spinner>`; once done it shows a fixed glyph (`✓`/`←`/`$`/`✱`/etc.) plus the one-line summary built from tool input (e.g. `Grep "pattern"`, `WebFetch <url>`). A **denied** tool call renders the icon+text with `TextAttributes.STRIKETHROUGH` — a distinct visual state, not just an error color. An error state shows an alternate `failure` message in place of the normal summary, with an optional expandable `error` detail box.
- **`BlockTool`** — a bordered, click-to-expand/collapse box used when the tool has real multi-line body content (diffs, generic long output). `GenericTool`'s output is passed through `collapseToolOutput(output, maxLines=3, maxChars)` to produce a preview + `overflow` flag; clicking toggles `expanded` state to show the full body. There's a global `showDetails()`/`showGenericToolOutput()` toggle that **hides completed tool parts entirely** by default and only shows raw tool output for tools without a specialized renderer when a user setting is on — i.e. opencode defaults to *not* flooding the transcript with tool noise once a call succeeds.

**Edit-tool diff rendering** (`Edit()`, ~line 2403) reuses the exact same `<diff>` element as the permission prompt, split/unified by width, syntax-highlighted per file extension, plus a `Diagnostics` sub-component that appends LSP diagnostics for the edited file right under the diff.

**Nested/subagent tool calls** (`Task()`, ~line 2228): a `Task` tool call is rendered as a live-updating `InlineTool` whose content is derived by reading the **child session's own message/part stream** — it finds the child's most recent running/completed tool part and shows `↳ <ToolName> <title>` underneath the task line, live, while the sub-agent works, then collapses to `↳ N toolcalls · <duration>` once done. Retries surface inline (`↳ Retrying (attempt N) · <error msg truncated to 80 chars>`), clickable to navigate into the child session's own transcript. A parallel "inline multi-tool" pattern (`Execute()`, ~line 2357) handles a *single* tool call whose metadata streams a list of **child tool calls** — same idea, cheaper substrate.

**Interrupted/dangling tool calls** — `packages/opencode/src/session/message-v2.ts` (~line 349): when converting history back into model-format messages (e.g. resuming a session where the process died mid-tool-call), any part still `status === "pending"` or `"running"` is rewritten to a synthetic `output-error` result (`"[Tool execution was interrupted]"`) before being replayed to the LLM — so a crashed tool call never leaves a dangling, provider-rejected `tool_use` block with no matching `tool_result` on the next turn.

Sources: [Issue #1736 — TUI bash tool title only shows description](https://github.com/anomalyco/opencode/issues/1736), [Issue #12484 — tool call permission arguments not visible for subagents](https://github.com/anomalyco/opencode/issues/12484), source read via GitHub at `packages/opencode/src/tool/tool.ts`, `packages/opencode/src/permission/index.ts`, `packages/opencode/src/session/message-v2.ts`, `packages/tui/src/routes/session/permission.tsx`, `packages/tui/src/routes/session/index.tsx` (dev branch).

### B. Claude Code

- **Streaming tool-use display**: tool calls stream in as structured content blocks alongside text; the CLI shows each call as a collapsed one-line summary (tool name + key argument, e.g. a file path or command) with a spinner/status glyph while running, expanding to full input/diff/output on request or automatically for edits.
- **Permission prompts**: gated by a **permission mode** state machine, cycled with Shift+Tab: `default` (ask per risky call) → `acceptEdits` (file edits auto-approved, other risky tools still ask) → `plan` (read-only; no mutating tool executes until the user approves the plan) → (`bypassPermissions`/headless `dontAsk` for CI/automation). Functionally the same "ruleset with an ask fallback, promotable to a session-scoped allow" shape as opencode's `Permission.Service`, expressed as named modes rather than a wildcard rule table.
- **Diff rendering**: file edits are shown as a unified diff before/while applying, not raw before/after text dumps.
- **Parallel tool call batching**: the model can request several independent tool calls in one turn; the CLI executes them concurrently and renders them as a batch — each with its own running→done transition — rather than serializing the UI updates.
- **Sub-agent (Task tool) calls**: a subagent spawn renders as a single collapsed entry in the parent transcript; the subagent's own tool calls happen in an isolated context and are not interleaved into the parent's visible stream turn-by-turn — the parent sees "Task running…" then the subagent's final report, which is **coarser** granularity than opencode's live "peek into child session" `Task` renderer. Before a background subagent starts, Claude Code prompts once for the tool permissions that subagent will need; once running it inherits exactly that pre-approved set and auto-denies anything outside it.
- **Error rendering**: a failing tool call is shown in-line as an error state (distinct color/marker) with the error text, fed back to the model as a tool result so the next turn can react/retry — errors are not silently swallowed or hidden from the transcript.

Sources: [Claude Code Plan Mode: The Complete Developer Guide (2026)](https://thepromptshelf.dev/blog/claude-code-plan-mode-complete-guide-2026/), [Claude Code Subagents: A 2026 Practical Guide](https://www.tembo.io/blog/claude-code-subagents), [Create custom subagents — Claude Code Docs](https://code.claude.com/docs/en/sub-agents), [Claude Code Permissions: Safe vs Fast Development Modes](https://claudefa.st/blog/guide/development/permission-management).

### C. Other tools (brief)

- **Aider**: applies edits as diffs against a git working tree and auto-commits each accepted change with a generated message — its "permission" model is effectively git itself (every AI edit is a reviewable, revertible commit).
- **Cline**: has an explicit **Plan/Act** mode toggle (close to Claude Code's `plan` mode) and a diff-based "auto-approve" allowlist per tool type, checkbox-configurable — same "rule table with an ask fallback" shape, just checkbox-driven instead of wildcard patterns.

### D. sugar-crush's current implementation

**Two independent, non-unified tool-calling pipelines exist today** — this is the single most important structural finding and should drive the recommendations below.

**Pipeline 1 — "Chat-native" (has UI, no permission gating)**
`src/Chat.php` holds its own `array<string, callable> $tools` (via `registerTool()`), and its own root-namespace `SugarCraft\Crush\ToolCall`/`SugarCraft\Crush\ToolResult` (`src/ToolCall.php`, `src/ToolResult.php`). When an `AssistantMsg`'s `Message` carries `toolCalls !== []`, `Chat::beginToolCalls()` (line 433) immediately appends a `Message::toolRunning($call)` **placeholder** per call to history (visible before any tool has actually run), forks one child process per tool call via `pcntl_fork()` (`forkToolCalls()`, line 566 — real concurrency, real closures, no proc_open/JSON worker indirection), collects results non-blockingly via a ReactPHP periodic timer (`waitForToolChildrenAsync()`, line 662, `PARALLEL_TOOL_TIMEOUT_SECONDS = 30`, SIGKILL on timeout), then `finishToolCalls()` (line 467) replaces each placeholder in history (matched by `Message::$pendingToolCallId`) with the real result.
This pipeline **is** rendered: `src/Renderer.php` (the renderer `Chat::view()` actually calls) has `renderPendingToolCall()` (line 394 — spinner glyph + `running: <describeToolCall>`) and `renderToolResults()` (line 372 — `🔧 tool: <name> ✓ ok`/`✗ error` + raw, untruncated body text). There is **no interactive permission prompt** anywhere in this path — `invokeTool()` (line 513) calls the registered closure directly with zero gating.

**Pipeline 2 — "Engine/Runtime" (has permission-style hooks, invisible to the UI)**
`src/Backend/EngineBackend.php` implements `Backend` (whose contract, `src/Backend.php`, is `complete(history): Message` — a single opaque final message, no incremental events) by driving `src/Runtime.php` in a bounded loop (`$maxSteps = 8`, `EngineBackend::complete()` line 130). `Runtime::executeToolCalls()` (line 107) uses the *other* type hierarchy — `SugarCraft\Crush\Tools\Tool` (`src/Tools/Tool.php`), `SugarCraft\Crush\Tools\ToolCall`/`ToolResult` — and **does** run each call through `HookManager::preToolUse()`/`postToolUse()` (`src/Hooks/HookManager.php`), which can `ALLOW`/`DENY`/`MODIFY` (`src/Hooks/HookResult.php`) via built-ins like `ConfirmRemoveHook` (regex-denies `rm -rf`/`find -delete`/`shred`/`dd of=`, `src/Hooks/BuiltIn/ConfirmRemoveHook.php`), `ProtectFilesHook`, `BashEscapeDenyHook`. **But this entire loop — every intermediate tool call, every hook allow/deny/modify decision, every intermediate assistant message — happens inside `EngineBackend::complete()`'s `for` loop and is thrown away**: only `$lastAssistant?->content()` escapes as the returned `Message`. Chat never sees the individual tool calls this pipeline made, so none of Renderer's tool-rendering code (spinners, running placeholders, result markers) ever fires for it, and the hooks are **automatic allow/deny — never an interactive user-facing prompt** either way.

This directly explains two visible symptoms:
- `src/Tui/Components/ToolsPane.php` (line 16-17) literally hardcodes `'(tool history empty)'` — it's part of a second, entirely disconnected `App`/`Tui\Renderer` system (`src/Tui/Renderer.php`) that "nothing in the live path ever constructs" per that file's own class docblock — `ChatPane.php`/`ToolsPane.php` are dead code from the user's perspective; the real live renderer is the root `Renderer.php` above.
- A user driving sugar-crush through `EngineBackend` (the "full agent" path with real tools/skills/hooks) currently sees **no tool activity at all** during a turn that may silently run up to 8 rounds of tool calls — just a "thinking…" spinner, then the final answer. Only the simpler `Chat::registerTool()` path gets the nice running→result UI, and that path has no safety gating whatsoever.

**Built-in tools** (`src/Tools/BuiltIn/{Bash,Edit,Read,Glob,Grep,WebFetch}.php`) belong to Pipeline 2's `Tool` interface. `Edit::execute()` (line 47) does a plain `str_replace()` and returns only `"File updated: $path"` — **no diff is generated or returned anywhere**, so even if Pipeline 2's results were surfaced, there'd be no diff content to show. `Bash.php` is deliberately un-jailed (documented security tradeoff, mitigated only by the optional `BashEscapeDenyHook` heuristic).

**Two separate `ToolCall`/`ToolResult` type pairs** (`Tools\ToolCall`/`Tools\ToolResult` vs. root `ToolCall`/`ToolResult`) with different field names (`toolCallId`/`content`/`isError`/`durationMs` vs. `name`/`result`/`error`/`id`) is itself a maintenance hazard independent of the rendering gap.

### E. Recommendations

**1. Unify the two tool-calling pipelines into one that both hooks AND renders.**
*Why*: this is the root cause of item D's gap. Both opencode and Claude Code have exactly one tool-execution path with hooks/permissions and rendering wired to the same event stream.
*Sketch*: change `Backend::complete()`'s contract (or add a new method) so `EngineBackend` yields incremental events instead of only a final `Message` — e.g. accept an `onEvent(ToolStarted|ToolFinished $event): void` callback threaded through `EngineBackend::complete()`/`Runtime::run()`/`Runtime::executeToolCalls()`, mirroring the existing `$onToken` callback plumbing already present for streaming text. `Chat::beginToolCalls()`/`finishToolCalls()` already have the right shape (placeholder-then-replace-by-id) — point them at `Tools\ToolCall`/`Tools\ToolResult` (or collapse the two type pairs into one canonical pair) so hook gating (`HookManager::preToolUse`) runs on *every* tool call regardless of which pipeline dispatched it.

**2. Turn the automatic hook DENY into a permission-prompt UI, opencode-style, for risky tools.**
*Why*: right now `HookResult::DENY`/`MODIFY` are silent, non-interactive, regex-only. opencode's `Permission.Service` shows the pattern worth porting: a wildcard `{permission, pattern, action: allow|deny|ask}` rule table with an `ask` fallback that blocks the turn on a UI decision, plus "always"/"once"/"reject-with-feedback" replies, with "always" retroactively resolving other pending prompts in the same batch.
*Sketch*: add `HookResult::ask(string $message)` (a 4th action alongside allow/deny/modify). In `Runtime::executeToolCalls()`, an `ask` result should not resolve synchronously — schedule a `Cmd` (ReactPHP `Deferred`, matching the existing `CancellationToken`/`Deferred` pattern) that resolves once the user answers, and have `Chat::update()` handle a new `PermissionRequestMsg`/reply flow the same way it already handles `ToolResultsMsg`. Render the prompt as a modal via `candy-buffer`'s `Veil` compositing (already used for `Chat`'s Ctrl+P palette) — reuse that exact mechanism for a permission dialog. Persist "always" decisions per-session (in-memory array on `Chat`, `mutate()`-able) the same way opencode keeps `approved: Rule[]`.

**3. Give `Edit` a real diff and render it.**
*Why*: `Edit::execute()` currently returns only `"File updated: $path"` — no before/after visibility, the single most load-bearing thing both opencode's Edit permission prompt and its transcript renderer show.
*Sketch*: compute a unified diff inside `Edit::execute()` and put it on `ToolResult` (add a `diff` field, or a `metadata` array). No need to invent diff math: `sugar-stash/src/DiffViewer.php` already exists in the monorepo (`fromRawDiff()`, hunk cursor/navigation) and `candy-buffer/src/Diff/{DiffEncoder,DiffOp,DiffOptimiser}.php` provide lower-level primitives. Render it in `Renderer::renderToolResults()` by branching on tool name `Edit`/`Write` and formatting through `DiffViewer` with `candy-sprinkles`' `Style`/`Border`.

**4. Surface subagent tool calls live, not just aggregate status.**
*Why*: `Renderer::renderAgentView()` (line 254) already has honest code comments admitting `elapsedSeconds`/`tokensUsed`/`costUsd` are hardcoded `0` because `AgentManager`/`AgentWorkerPool`'s public API exposes only aggregate counts, not a per-agent live output buffer.
*Sketch*: give each sub-agent a lightweight, append-only event log (a JSON-lines temp file, matching the existing `storeToolResult()`/`collectToolResult()` temp-file IPC pattern `Chat::forkToolCalls()` already uses for parallel tool results) that the parent process tails to build a real `AgentDisplayState`.

**5. Collapse/expand for tool output, and hide-on-success by default.**
*Why*: `Renderer::renderToolResults()` currently dumps every tool's full, untruncated output inline forever — for a `Bash`/`Grep` call with a large result this will blow past the fixed-viewport clipping `Renderer::render()` already has to defend against.
*Sketch*: add `collapseToolOutput(string $output, int $maxLines, int $maxChars): array{output:string, overflow:bool}` (pure function, easy to unit-test) and use it in `renderToolResults()`; track an `expanded: array<string,bool>` map (keyed by tool-call id) on `Chat` toggled by a keybinding.

**6. Fix the dead `ToolsPane`/`Tui\Renderer` split, or delete it.**
*Why*: pure confusion for future contributors — `ToolsPane.php` hardcodes `'(tool history empty)'` and belongs to a documented-dead second renderer.
*Sketch*: either wire `ToolsPane::render()` to read from `Chat::history` and actually reach it from the live path, or delete `Tui/Components/{ChatPane,ToolsPane}.php` and `Tui/Renderer.php`'s dead render paths so there's exactly one renderer to reason about.

**7. Denied/interrupted tool-call visual states.**
*Why*: sugar-crush has session persistence (`SessionStore`/`EnhancedSessionStore`, `saveCheckpoint()`) but no guard for a checkpoint saved mid-tool-call — it would resume with a `pendingToolCallId` placeholder that never resolves.
*Sketch*: on checkpoint resume, replace any history entry with non-null `pendingToolCallId` with a synthetic `Message::assistant('Tool call interrupted by restart')->withToolResults([...])`. For the permission-deny visual (once recommendation 2 lands), render via `Style::new()->strikethrough()`.

**Reusable candy-* primitives identified during this research:**
- `sugar-stash/src/DiffViewer.php` — unified diff view/hunk navigation
- `candy-buffer/src/Diff/{DiffEncoder,DiffOp,DiffOptimiser}.php` — lower-level diff computation
- `candy-forms/src/Spinner/Spinner.php` — real `Model` spinner, for the running-state indicator (replaces the ad hoc `⠴` glyph literal)
- `candy-buffer`'s `Veil` — already used for the Ctrl+P palette overlay; reuse for the permission-prompt modal
- `candy-sprinkles/src/{Style,Border}.php` — collapsible bordered boxes
- `candy-focus/src/FocusRing.php` — keyboard-navigable option selection in a permission prompt (once/always/reject)

---

## 2. CLI commands, parameters, help screens & non-interactive mode

### A) opencode (anomalyco/opencode)

opencode's CLI is a single `opencode` binary built on a yargs-style command tree. Invoked bare it launches the TUI; invoked with a subcommand it runs headless.

**Core commands**

| Command | Purpose | Key flags |
|---|---|---|
| `opencode` (default `tui`) | Launch interactive TUI | `--continue/-c`, `--session/-s`, `--fork`, `--prompt`, `--model/-m`, `--agent`, `--auto`, `--port`, `--hostname`, `--mdns[-domain]`, `--cors` |
| `opencode run [message..]` | **Non-interactive one-shot** — the `-p "prompt"` equivalent | `--command`, `--continue/-c`, `--session/-s`, `--fork`, `--share`, `--model/-m`, `--agent`, `--file/-f`, `--format default\|json`, `--title`, `--attach`, `--password/-p`, `--username/-u`, `--dir`, `--port`, `--variant`, `--thinking`, `--auto` |
| `opencode serve` | Headless API server (no TUI) | `--port`, `--hostname`, `--mdns[-domain]`, `--cors` |
| `opencode web` | Headless server + web UI | same as `serve` |
| `opencode attach` | Attach a terminal to a running backend server | `--dir`, `--continue/-c`, `--session/-s`, `--fork`, `--password/-p`, `--username/-u` |
| `opencode acp` | Agent Client Protocol server over stdin/stdout newline-delimited JSON | — |

**Configuration/scripting subcommands** — `agent create/list` (`--path`, `--description`, `--mode all\|primary\|subagent`, `--permissions bash,read,edit,glob,grep,webfetch,task,todowrite,websearch,lsp,skill`, `--model/-m`); `auth login/list/logout` (`--provider/-p`, `--method/-m`); `models [filter]` (`--refresh`, `--verbose`); `mcp add/list/auth/logout/debug`; `session list/delete` (`--max-count/-n`, `--format table\|json`); `export [sessionID] --sanitize`; `import <file>`; `stats` (`--days`, `--tools`, `--models`, `--project`); `plugin <module>` (`--global/-g`, `--force/-f`); `db [query]` (`--format json\|tsv`); `upgrade`/`uninstall`.

**Non-interactive mode specifics**: `opencode run "prompt"` is the direct scripting entry point — never opens the TUI, reads a message positional arg (or `--command` for a stored/named prompt), writes to stdout. `--format json` switches to raw JSON events per message, for `jq` piping. Session export/import (`export --sanitize` / `import`) moves whole conversation transcripts through JSON without ever touching the TUI — a pattern Claude Code lacks natively (its analogous mechanism is `--continue`/`--resume` by session ID, not a portable export blob).

**Global flags**: `--help/-h`, `--version/-v`, `--print-logs`, `--log-level`, `--pure`.

Notably: opencode does not appear to have a first-class `--allowedTools`/permission-preapproval flag on `run` itself — permissions come from `agent create --permissions` rather than a per-invocation flag. Two open GitHub issues (`#10411` "Add non-interactive mode to opencode run", `#13851` "Unable to use opencode cli in a non-interactive pipeline") indicate the non-interactive path has had rough edges around blocking on missing `--path`/`--mode`/`--permissions` combinations.

Sources: [CLI | OpenCode](https://opencode.ai/docs/cli/), [Command Line Interface | DeepWiki](https://deepwiki.com/anomalyco/opencode/3.1-command-line-interface), [Issue #10411](https://github.com/anomalyco/opencode/issues/10411), [Issue #13851](https://github.com/anomalyco/opencode/issues/13851)

### B) Claude Code CLI

Claude Code's non-interactive contract is the most mature of the bunch, documented at `code.claude.com/docs/en/headless` plus a full flag reference at `/docs/en/cli-reference`.

**Entry point**: `claude -p "<prompt>"` (`-p`/`--print`) converts any `claude` invocation from the interactive REPL into a single batch call: prompt in, result on stdout, process exits.

**Exit codes**: `0` on success, non-zero on failure. Invalid flags are reported to stderr *before* the run starts; an in-run failure is printed as the `result` on stdout instead (so JSON-mode consumers still get a well-formed payload). A `SIGTERM`-interrupted run aborts the turn, kills any running Bash subprocess tree, runs `SessionEnd` hooks, exits `143`.

**`--bare` mode**: skips auto-discovery of hooks/skills/plugins/MCP servers/auto-memory/`CLAUDE.md` for faster, deterministic CI runs. In bare mode, credentials must come from `ANTHROPIC_API_KEY`; context can be reintroduced piecemeal via `--append-system-prompt[-file]`, `--settings <file-or-json>`, `--mcp-config <file-or-json>`, `--agents <json>`, `--plugin-dir/--plugin-url`.

**Piping stdin**: `cat build-error.txt | claude -p '...' > output.txt`. Stdin capped at 10MB; if unreadable, warns to stderr and falls back to just the CLI prompt argument.

**`--output-format`**: `text` (default), `json` (structured: `result`, `session_id`, `total_cost_usd`, per-model cost breakdown, metadata), `stream-json` (newline-delimited event stream, paired with `--verbose --include-partial-messages`). `--json-schema '<JSON Schema>'` combined with `--output-format json` constrains the reply into a `structured_output` field.

Streaming events include `system/init` (session metadata: model, tools, MCP servers, loaded plugins, `capabilities` array for feature-detection), `system/api_retry`, `plugin_install` progress, and `mcp_servers`/`mcp_server_errors`/`plugin_errors` arrays shaped so a CI job can fail the build on a non-empty errors array — a genuinely CI-first design choice.

**Permissions for unattended runs**: `--allowedTools "Bash,Read,Edit"` (or finer-grained rule syntax `Bash(git diff *)`) auto-approves specific tools without a baseline mode change; `--permission-mode` sets a session-wide baseline — `dontAsk` (denies anything not explicitly allowed — the CI-safe default), `acceptEdits` (auto-approves file writes + common fs commands, still gates other shell/network use).

**Session continuation**: `--continue` resumes the most recent conversation; `--resume <session_id>` resumes a specific one (ID captured from a prior `--output-format json` run via `jq -r '.session_id'`).

**Slash commands in `-p` mode**: user-invoked skills/custom commands (`/skill-name`) expand inline; a subset of built-ins (`/model`, `/effort`, `/fast`, `/color`, `/rename`, `/mcp`, `/config key=value`) work as value-accepting one-shot directives even headless.

Sources: [Run Claude Code programmatically / headless](https://code.claude.com/docs/en/headless), [CLI reference](https://code.claude.com/docs/en/cli-reference)

### C) Other tools (brief)

- **Codex CLI (OpenAI)**: `codex exec "<task>"` is the dedicated non-interactive subcommand (distinct verb from the interactive `codex` REPL). Flags: `--json`, `--output-schema <file>` (same idea as Claude's `--json-schema`), `-o result.txt`, `--full-auto`. `--sandbox` mode is the only safety control since it never prompts.
- **Gemini CLI (Google)**: mirrors Claude's `-p`/`--prompt` naming directly. Reads stdin as context, prints to stdout. `--output-format` gives a single JSON object or JSONL event stream, matching Claude's `json`/`stream-json` split.

The convergent pattern: a bare-invocation TUI default, a `run`/`exec`/`-p` verb for one-shot scripting, stdin-as-context piping, a `text`/`json`/`stream-json` output-format axis, and a session-ID-based resume mechanism for multi-turn scripting without re-opening the TUI.

### D) sugar-crush's current CLI — what exists today

**`bin/sugarcrush` (21 lines of actual logic)** does exactly one thing: locate `vendor/autoload.php`, then unconditionally run

```php
(new Program(Bootstrap::chat(), new ProgramOptions(useAltScreen: true)))->run();
```

There is **no argv parsing at all** — no `getopt()`, no manual `$argv` loop, no `--help`, no `-h`, no subcommands, no flags of any kind. Every other bin in the monorepo that takes CLI input does its own lightweight parsing (`sugar-post/bin/pop` hand-rolls a `parseArgs($argv)` with `--from`/`--to`/`--subject`/`--attach`, a `help()` screen, and a stdin-body fallback when `!stream_isatty(STDIN)`; `sugar-wishlist/bin/wishlist` uses PHP's built-in `getopt('', ['config:', 'ssh:'])`). `sugar-crush` has neither pattern — it is TUI-only, full stop.

**Configuration is 100% environment-variable-driven**, resolved inside `Bootstrap::backend()` (`src/Cli/Bootstrap.php:78-108`) in this priority order:
1. `SUGARCRUSH_PROVIDER` (+ provider-specific creds like `OPENAI_API_KEY`; `SUGARCRUSH_MODEL` overrides model) → full `EngineBackend`.
2. `SUGARCRUSH_BACKEND_CMD` → dependency-free shell-out (`CommandBackend`).
3. A provider persisted to `~/.sugar-crush/config.json` by a previous in-TUI Ctrl+P "Switch model" action.
4. Default: the offline `EchoProvider`, still routed through the full engine (zero network, zero config) — the "launches with zero keys" behavior the README advertises.

**No help screen exists whatsoever.** Running `./bin/sugarcrush --help` or `-h` does not print usage — it launches the TUI and treats `--help`/`-h` as ordinary chat input. This is a real gap relative to every tool researched above.

**No non-interactive mode exists.** There is no `sugarcrush run "prompt"`, no `-p`, no way to get a single answer printed to stdout and exit — `Program::run()` always attaches to a TTY, enters the alt-screen, blocks on the render loop. sugar-crush cannot be used as `cat error.log | sugarcrush -p "explain this"`, nor dropped into a CI step. `BootstrapTest.php`'s own comment (lines 26-41 of `Bootstrap.php`) explicitly notes `bin/sugarcrush` "cannot be `require`d directly in a test — it unconditionally ends in `Program::run()`, which attaches to a real TTY and blocks."

**What's already in place that a non-interactive mode could reuse directly**: `EngineBackend::complete(array $history, ?callable $onToken = null): Message` (`src/Backend/EngineBackend.php:130`) is a **synchronous**, blocking call that already runs the full bounded agentic loop and returns a finished `Message` — no `Program`/TUI/render loop required. `Bootstrap::backend($root)` already builds a fully-wired backend independent of `Chat`/`Program`. The primitives for a `-p`-style one-shot mode already exist in the engine layer; only the CLI entry-point/argv layer is missing.

### E) Recommendations for sugar-crush

**1. Add a real argv front-end to `bin/sugarcrush`, splitting "parse & dispatch" from "run the TUI".**
*Sketch*: Add `src/Cli/ArgvParser.php` (plain PHP `getopt()`-style manual loop, following `sugar-post/bin/pop`'s precedent — no existing CLI-flag-parsing lib in the monorepo, so this is genuinely new, small, dependency-free code). `bin/sugarcrush` becomes:
```php
$args = ArgvParser::parse($argv);
if ($args->help) { fwrite(STDOUT, Help::screen()); exit(0); }
if ($args->prompt !== null) { exit(NonInteractive::run($args)); }  // see #2
(new Program(Bootstrap::chat($args->root), new ProgramOptions(useAltScreen: true)))->run();
```

**2. Add `sugarcrush -p "<prompt>"` / `sugarcrush run "<prompt>"` one-shot mode built directly on `EngineBackend::complete()`.**
*Why*: the single highest-leverage gap versus every competitor — Claude Code, opencode, Codex, and Gemini CLI all treat this as *the* scripting entry point, and CI/build-script integration (lint-on-diff, commit-message generation, PR review) is impossible without it.
*Sketch*: new `src/Cli/NonInteractive.php`:
```php
final class NonInteractive
{
    public static function run(ParsedArgs $args): int
    {
        $backend = Bootstrap::backend($args->root);
        $history = self::historyFrom($args->prompt, self::readStdinIfPiped());
        try {
            $message = $backend->complete($history);
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
        echo self::format($message, $args->outputFormat) . "\n"; // text|json
        return 0;
    }
}
```
Read stdin only when `!stream_isatty(STDIN)` (mirrors `sugar-post/bin/pop`'s existing pattern). Cap piped input like Claude does (10MB).

**3. `--output-format text|json` on the one-shot path.**
*Sketch*: `EngineBackend::complete()` already returns a typed `Message` with tool calls and (via `Util\TokenTracker`) cost/token data — `json` mode is just `json_encode(['result' => $message->content(), 'session_id' => ..., 'usage' => $tracker->summary()])`.

**4. Exit codes matching the Unix convention already implied by other bins in the repo.**
*Why*: `sugar-post/bin/pop` and `sugar-wishlist/bin/wishlist` both already `exit(1)`/`exit(0)` — sugar-crush should follow that existing repo convention (matching Claude Code's `0`/non-zero/`143`-on-SIGTERM contract).

**5. A real `--help` screen, wired ahead of any backend construction.**
*Why*: currently `--help` opens a blocking full-screen TUI — the worst possible first-interaction for a scripting user.
*Sketch*: static `Help::screen(): string` listing env vars, the new `-p`/`run`/`--output-format` flags, pointing at the README — modeled directly on `sugar-post/bin/pop`'s existing `help($bin)` function.

**6. Session continuation for scripted multi-turn use (`--continue`/`--session <id>`).**
*Why*: `EnhancedSessionStore`/`SessionStore` already back `/branch`/`/rewind` inside the TUI — exposing `--session <id>` to the non-interactive path would let sugar-crush match Claude/opencode's scripted-conversation pattern for near-zero new storage code.

**7. Lower priority — a headless server mode (`sugarcrush serve`), mirroring opencode's `serve`/`web`.**
*Why*: sugar-crush already runs on ReactPHP, and `Backend`/`MCP\*` already exist — a `serve` subcommand exposing `EngineBackend::completeAsync()` (already present, returns a `PromiseInterface`) over HTTP would be low-net-new-code, putting sugar-crush ahead of Claude Code (no first-party persistent server mode) and matching opencode's `serve`/`attach` split. More speculative — sequence after the one-shot mode ships.

**Sequencing note**: items 1, 2, 5 (parser + one-shot + help) are the minimum viable slice — they alone close the "sugar-crush can't be used in CI/scripts at all" gap. Items 3-4 are small additions on top. Items 6-7 are genuine new surface area and should be separate PRs per the repo's ship-as-you-go / bundle-2-4-items convention.

---

## 3. Session & command auto-summarization

### A. opencode (sst/opencode, formerly anomalyco/opencode)

**Session titles are LLM-generated, not just string-truncated.** opencode maintains a hidden built-in "title agent." Per `packages/opencode/src/session/prompt.ts`:

- `ensureTitle()` fires on the first loop iteration of a turn, gated on: the session has no `parentID` (not a sub-agent), the current title still equals the default placeholder, and history contains exactly one real (non-synthetic) user message.
- The prompt sent to the model is minimal — literally `"Generate a title for this conversation:\n"` prepended to the conversation messages. No elaborate system prompt.
- Model tier: explicitly requests `small: true` — uses the agent's configured model if set, else `provider.getSmallModel()`, else falls back to the session's regular model as a last resort. Keeps the extra call cheap/fast.
- Post-processing is defensive: strip `<think>...</think>` blocks, take the first non-empty trimmed line, truncate to 100 chars with an ellipsis.
- Persisted via `sessions.setTitle({ sessionID, title })`, shown in the session list/picker.
- Known pain points from GitHub issues (#9460, #17631, #8436, #11988, #29002): title generation firing repeatedly instead of once; titles going stale as the conversation drifts (users want auto-refresh after N messages); no easy manual "regenerate title" affordance; and (#20269) a real production bug where an `effort` parameter meant for the main model leaked into the small-model call and silently broke titling — a reminder that the cheap side-call needs its own isolated request-building path, not a shared helper that assumes "the model" means the primary one.

**Live one-liner status text for running tools:** opencode's TUI shows a spinner in the input/status gutter while the session is busy, plus per-tool detail in a collapsible sidebar — closer to a generic "busy" spinner than to Claude Code's per-invocation natural-language description. No evidence found of opencode server-side code asking the model to author a human sentence per tool call.

Sources: [Issue #8436](https://github.com/anomalyco/opencode/issues/8436), [Issue #29002](https://github.com/anomalyco/opencode/issues/29002), [Issue #11988](https://github.com/anomalyco/opencode/issues/11988), [Issue #9460](https://github.com/anomalyco/opencode/issues/9460), [Issue #20269](https://github.com/anomalyco/opencode/issues/20269), [Issue #17631](https://github.com/anomalyco/opencode/issues/17631), [TUI | OpenCode docs](https://opencode.ai/docs/tui/)

### B. Claude Code

**Session titles in the resume picker:** if the user hasn't manually named a session, Claude Code generates one automatically — a short summary of the first substantive prompt (skipping tool-notification/system-only turns), produced by a background request to a small/fast model. Shown both in the resume picker and via a `session_name` statusline field. Same shape as opencode's title agent: cheap model, first-message trigger, background/non-blocking call.

**Per-tool-call one-line description ("List files in current directory" style):** this is the pattern this very system prompt enforces on Claude Code's own `Bash` tool — its JSON schema requires a `description` parameter that **the calling model itself must fill in** on every invocation. Concretely, this is *not* a separate LLM call — it's a required field the primary model composes as part of the same tool-call turn, at zero extra latency/cost. The harness then renders that description as the human-readable status line instead of the raw shell string.

**Other summarization surfaces:** `TodoWrite` requires each item to carry both an imperative form ("Run tests") and a present-continuous "active form" ("Running tests") — same "make the acting model produce the human phrasing inline" trick. Status line is user-script-driven, rendering provided fields (including the generated session title) without further summarization. Subagent tool definitions require a caller-supplied `description` (3-5 word task label) distinct from the full `prompt` — again pushing the summary onto the invoking model at call time.

Sources: [Manage sessions - Claude Code Docs](https://code.claude.com/docs/en/sessions), [Issue #61047 — Generate AI-summarized titles for --resume picker](https://github.com/anthropics/claude-code/issues/61047) (open — confirms this is still being iterated on even upstream)

### C. Other tools (brief)

Cursor/Aider/Warp: no evidence of as formalized a "LLM titles the session after message 1" mechanism as opencode/Claude Code's — simpler timestamp- or first-line-derived naming. The common thread across every tool that solves this well: **the description is authored by an LLM turn that is already happening**, never derived by dumping raw argument values through string formatting.

### D. sugar-crush's current implementation

**Session naming is 100% manual — no LLM involved at all.**
- `src/Session/SessionStore.php` — `sessions` table has a `name TEXT` column, populated only via `createSession(..., ?string $name = null)` or explicit `renameSession(string $id, string $name)`. Nothing calls `renameSession` automatically.
- `src/Chat.php` — the only path that sets a session name is the user-typed `/rename <name>` slash command (`handleRenameCommand()`, ~line 1712). New sessions otherwise stay unnamed/ID-labeled.
- `src/Session/SessionMeta.php`/`EnhancedSessionStore.php` — a `summary` field exists (`summary TEXT DEFAULT ''`) and `SessionMeta::withSummary()`, but nothing currently writes a non-empty summary — plumbing without a producer.
- `src/Tui/SessionTab.php` (`agentSummary` field, `withSummary()`) and `SessionTabs.php` — same story: a slot exists but no producer feeds it.

**Tool-call "what's running" text is mechanical arg-dumping, not model-authored English.**
- `src/Message.php`, `describeToolCall(ToolCall $call)` (~line 81): builds a string like `Bash(command: "ls -la && grep foo")` by JSON-encoding each argument, truncating any value over 80 chars.
- `src/Renderer.php`, `renderPendingToolCall()` (~line 394-399): renders that raw `name(args)` string prefixed with a spinner glyph — e.g. `⠴ running: Bash(command: "grep -rn foo src/")`. Strictly weaker than Claude Code's Bash description: shows *what argument was passed*, not *what the model intends to accomplish*.
- `src/Tools/Tool.php` — the `Tool` interface has `name()`, `description()` (a static, tool-level description), `inputSchema()`, `execute()`. There is **no per-call description field** in `inputSchema()` — `BuiltIn/Bash.php`'s `inputSchema()` only declares `command` as required; a model calling this tool has no schema slot for "List files in current directory."
- `src/Tui/Components/ToolsPane.php` — the tools sidebar pane is a stub (`'(tool history empty)'`), no per-call rendering wired up.

**Existing infrastructure that a fix can reuse:**
- `src/Backend.php` — `Backend`'s `completeAsync(array $history, ?callable $onToken, ?CancellationToken $cancellation): PromiseInterface` is the same call shape opencode's title agent and Claude Code's background-Haiku title call both need.
- `src/Chat.php`, `scheduleBackendCompletion()` (~line 1197-1208) — shows the existing `Cmd::promise(...)` pattern for wrapping a `completeAsync()` call as a TEA `Cmd`.
- `src/Context/ContextCompactor.php`/`CompactorConfig.php` — the codebase already has a "summarize the conversation so far" mechanism for `/compact`, prior art for prompting a model to compress history into a short blurb.

### E. Recommendations

**E1. Auto-generate session titles via a background small-model call, opencode/Claude Code-style.**
*Sketch*:
```php
// In Chat.php, alongside scheduleBackendCompletion()
private function scheduleTitleGeneration(self $next): ?\Closure
{
    if ($this->sessionStore === null || $next->currentSessionName !== null) {
        return null; // already named (manual /rename or prior auto-title)
    }
    if (count(array_filter($next->history, fn($m) => $m->role === Role::User)) !== 1) {
        return null; // only fire once, on the first real turn
    }
    $backend = $next->backend;
    $titlePrompt = [
        Message::system('Generate a session title in 4-8 words. One line. No quotes, no trailing punctuation.'),
        ...$next->history,
    ];
    $sessionId = $next->currentSessionId;
    $store = $this->sessionStore;
    return Cmd::promise(static function () use ($backend, $titlePrompt, $sessionId, $store): PromiseInterface {
        return $backend->completeAsync($titlePrompt)->then(
            static function (Message $msg) use ($store, $sessionId): ?Msg {
                $title = trim(explode("\n", trim($msg->content))[0] ?? '');
                $title = mb_substr($title, 0, 100);
                if ($title !== '') {
                    $store->renameSession($sessionId, $title);
                }
                return new SessionTitledMsg($sessionId, $title);
            },
            static fn(\Throwable $e): ?Msg => null, // best-effort; never surface a title-gen failure to the user
        );
    });
}
```
Dispatch alongside (not blocking) `scheduleBackendCompletion()`'s existing `Cmd`. Guard the "small model" tier via `Backend`'s constructor options — pass a cheaper model explicitly rather than defaulting to whatever the main conversation is using. **Learn from opencode issue #20269**: keep the title-call's model/param resolution in its own small helper, not shared with the main-turn request builder. A new `SessionTitledMsg` lets `Chat::update()` refresh `SessionTab::withSummary()`/`sessionName` state so the UI updates live.

**E2. Require a per-call human-readable `description` on tool invocations, Claude-Code-Bash-style.**
*Why*: highest-leverage, lowest-cost fix — no separate LLM call needed, since the same model turn that emits the tool call also emits the description as an extra output field.
*Sketch*:
```php
// src/Tools/BuiltIn/Bash.php inputSchema()
public function inputSchema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'command' => ['type' => 'string', 'description' => 'The bash command to execute'],
            'description' => [
                'type' => 'string',
                'description' => 'Clear, concise 5-10 word description in active voice of what this command does (e.g. "List files in current directory", not "runs ls").',
            ],
        ],
        'required' => ['command', 'description'],
    ];
}

// src/Message.php describeToolCall()
public static function describeToolCall(ToolCall $call): string
{
    if (isset($call->arguments['description']) && is_string($call->arguments['description']) && $call->arguments['description'] !== '') {
        return $call->arguments['description']; // model-authored human summary wins
    }
    // ...existing mechanical fallback for tools/backends that don't send one
}
```
Apply the same `description` property across every tool in `src/Tools/BuiltIn/`. Keep it optional-but-preferred at the `describeToolCall()` layer (fallback to the arg-dump) so older backends/models that don't populate it don't regress — but mark it `required` in the schema so compliant backends always send it.

**E3. Wire the now-stub `ToolsPane` into a live "what's happening" sidebar.**
*What*: `ToolsPane::render()` currently hardcodes `'(tool history empty)'`. Once E2 lands, thread the in-flight/recent `ToolCall`+description pairs into this pane for a persistent, multi-line "recent actions" list instead of only a single inline spinner line.

**E4. Populate `SessionMeta::$summary`/`SessionTab::$agentSummary` as a live "what is the agent doing right now" line, distinct from the one-shot title.**
*What*: reuse the E1 Cmd-scheduling pattern but re-fire periodically (every N assistant turns, or on `/compact`, mirroring opencode's #17631/#29002 asks). Surfaced in `SessionTabs` for multi-session/background-session users. **Caveat from opencode's experience (#9460)**: don't refresh on every single message — gate on a message-count or idle-time threshold, best-effort/silent-fail.

---

## 4. Command palette (Ctrl-P) & slash commands

### A. opencode (sst/opencode, formerly anomalyco/opencode)

**Command palette.** The TUI's fuzzy command menu is a `DialogSelect`-based component, `DialogCommand`, at `packages/opencode/src/cli/cmd/tui/component/dialog-command.tsx`. `DialogSelect` is the generic, reusable fuzzy-searchable list primitive (`DialogSelectOption{title, value, category, footer}`); `DialogCommand` supplies the catalog. Matching runs through `fuzzysort.go()` against title *or* category, so typing a category name ("session", "provider") surfaces everything in it. Results are grouped/sorted by category, items flagged `suggested` float to the top, keybind strings shown in a footer column, disabled items excluded from the candidate set before scoring. A newer "v2" dialog exists in a separate app package, built on a `useCommand()` React-context catalog where UI components self-register `{id, title, keybind, run}` entries — discovery is "any mounted component can add an entry" rather than one static list.

Separately, the terminal TUI uses a **leader-key** scheme: default leader `Ctrl+X` with a configurable timeout, e.g. `Ctrl+X C` compact, `Ctrl+X M` list models, `Ctrl+X N` new session, `Ctrl+X T` themes, `Ctrl+X U/R` undo/redo. `Ctrl+P` (or `mod+k`) opens the actual searchable palette independent of the leader chain.

**Slash commands.** Built-ins are few and orthogonal to the palette: `/init`, `/undo`, `/redo`, `/share`, `/help`. The bulk of the command surface is **user-authored markdown files**, discovered from project `.opencode/commands/` and global `~/.config/opencode/commands/` (also configurable via the `command` key in `opencode.jsonc`). Filename *is* the command name (`test.md` → `/test`); a custom file overrides a built-in. Each file is YAML-frontmatter + a template body:

```markdown
---
description: Run tests with coverage
agent: build
model: anthropic/claude-3-5-sonnet-20241022
subtask: true
---
Recent commits:
!`git log --oneline -10`

Review @src/components/Button.tsx, then run the tests for $1.
```

- `$1`/`$2`/`$3…` — positional args; `$ARGUMENTS` — the full trailing string.
- `` !`command` `` — shell out, splice stdout into the prompt.
- `@path` — inline a file's contents.
- `agent:`/`model:` — pin the command to a specific agent persona / override the default model.
- `subtask: true` — force execution in an isolated subagent so its tool calls/output don't pollute the primary conversation's context window.

No argument-hint frontmatter field found (unlike Claude Code) — the description field alone is shown in the palette/menu.

Sources: [TUI Commands and Dialogs | DeepWiki](https://deepwiki.com/sst/opencode/6.4-tui-theming-keybinds-and-commands), [TUI | OpenCode](https://opencode.ai/docs/tui/), [Commands | OpenCode](https://opencode.ai/docs/commands/)

### B. Claude Code

**Ctrl+P / interactive menu behavior.** Typing `/` at the start of a message opens an inline filtering menu (not a separate Ctrl+P overlay dialog like opencode/VSCode) — `/` + letters narrows the list live; commands only recognized at message start.

**Built-in commands** span several functional groups: project setup (`/init`, `/memory`, `/mcp`, `/permissions`), in-task control (`/plan`, `/model`, `/effort`, `/context`, `/compact`, `/btw`), review/quality (`/code-review`, `/security-review`, `/verify`), parallel/background execution (`/batch`, `/background`, `/fork`, `/subtask`), session management (`/clear`, `/resume`, `/branch`, `/cd`, `/add-dir`), diagnostics (`/debug`, `/doctor`, `/rewind`, `/diff`, `/cost`/`/usage`, `/status`), account/utility (`/login`, `/logout`, `/config`, `/export`, `/copy`, `/help`, `/bug`, `/vim`, `/terminal-setup`, `/color`, `/goal`, `/loop`).

**Custom commands.** `.claude/commands/*.md` and `.claude/skills/*/SKILL.md` are unified: a bare file `.claude/commands/deploy.md` and a directory `.claude/skills/deploy/SKILL.md` both produce `/deploy` and behave identically for explicit invocation. Skills add: a directory for bundled files, and auto-invocation without `/`, unless `disable-model-invocation: true`. Frontmatter: `description` (shown in `/help` and the filter menu), `argument-hint` (placeholder text, e.g. `<environment: staging|prod> [options]`), `allowed-tools`, `model`, `disable-model-invocation`. Body syntax mirrors opencode: `$ARGUMENTS`/`$1`/`$2`/`$@`, `!command` lines, `@file`. Subdirectories namespace commands (`deploy/staging.md` → `/deploy/staging`). Discovery/precedence: project `.claude/commands/` → project `.claude/skills/` → personal `~/.claude/commands/` → personal `~/.claude/skills/` → plugin-provided, more-specific overriding less-specific. Skills support chaining up to 6 in one line, each receiving the same trailing `$ARGUMENTS`.

Sources: [Commands reference — code.claude.com](https://code.claude.com/docs/en/commands), [Skills — code.claude.com](https://code.claude.com/docs/en/skills)

### C. VSCode's Ctrl+Shift+P palette (reference UX pattern, non-AI)

Three conventions worth borrowing: (1) every command carries a **category prefix** (`Git: Commit`), fuzzy-matched against the whole `"Category: Title"` string; (2) **MRU bias** — the palette remembers recently executed commands and rank-biases them to the top when the query is empty/short; (3) commands are contributed declaratively by independently-registered sources (`contributes.commands`), never a single hand-maintained array — analogous to opencode's file-based command directories and Claude Code's project/personal/plugin layering.

### D. sugar-crush's current implementation

**Two independent, drifting command lists.**
1. `PaletteAction` (enum, 9 cases: `NewSession`, `SwitchSession`, `SwitchModel`, `ShareSession`, `OpenDocs`, `Exit`, `SwitchTheme`, `SwitchAgent`, `ToggleMcp`) drives the Ctrl+P palette's root list. Each case carries `label()`, `category()`, and an unused-in-render `shortcut()`.
2. `CommandRegistry::all()` (11 hand-written `CommandSpec` rows: `compact`, `workflow`, `share`, `agents`, `memory`, `branch`, `rename`, `rewind`, `sessions`, `theme`, `exit`) drives the `/`-prefix autocomplete popup.

These overlap only partially — `/compact`, `/workflow`, `/branch`, `/rename`, `/rewind`, `/memory`, `/sessions` exist only as slash commands (invisible to Ctrl+P); `NewSession` and `OpenDocs` exist only as palette actions. The actual dispatch logic lives in a third place entirely: `Chat::submit()`'s `str_starts_with($text, '/compact')`/`'/workflow'`/… chain, which both metadata classes' doc-comments explicitly disclaim ("Adding a command here does not wire it up"). One command, `mcp auth list|add|remove`, is dispatched by `str_starts_with($text, 'mcp auth')` with **no leading slash at all** — unreachable from the `/` popup, only reachable by typing it verbatim or via the palette's `ToggleMcp` action.

**Fuzzy matching exists, but only in one of the two menus.** `Chat::paletteMatches()` (~line 2274) runs `SmithWatermanMatcher::matchAll($query, $items)` (from `sugarcraft/candy-fuzzy`, already a direct composer dependency) against palette item labels. `Chat::slashMenuMatches()` (~line 2162), by contrast, calls `CommandRegistry::filter($prefix)`, which is a plain `str_starts_with()` prefix filter — no fuzzy tolerance, so `/rwd` won't surface `/rewind`.

**Match highlighting is computed and thrown away.** `paletteMatches()` extracts only `$result->haystack` from each `MatchResult`, discarding the matched-character indices that `SugarCraft\Fuzzy\Highlighter` (`candy-fuzzy/src/Highlighter.php`) is purpose-built to render as bolded/colored runs. `Renderer::renderPalette()` (~line 436) therefore only bold+colors the *whole selected row*, never the matched substring.

**No custom/user-defined commands.** Unlike `Skills` (`src/Skills/SkillLoader.php`), which already implements the three-tier discovery pattern opencode/Claude Code use for commands — `loadBuiltInSkills()`, `loadUserSkills()` (`~/.sugar-crush/skills/`), `loadProjectSkills()` (`<project>/.sugar-crush/skills/`), merged built-in < user < project in `loadAll()` — `CommandRegistry::all()` is a `final class` returning a hardcoded PHP array. There is no `.sugar-crush/commands/` directory, no frontmatter parsing for commands, no `$ARGUMENTS`/`$1` templating, no `` !`bash` `` injection, no `@file` inclusion for slash commands. `CommandSpec` has no `argumentHint` field, so `/rename` (needs a name argument) and `/share [format] [expiry]` give no in-popup hint.

**No category grouping or MRU in the palette UI.** Both `PaletteAction::category()` and `CommandSpec::category` are computed (Session/Model/Appearance/Agents/MCP/App/Workflow/Memory) but `Renderer::renderPalette()` renders one flat, ungrouped list with no category headers — the data model already supports grouping, the renderer just doesn't use it. There's also no most-recently-used biasing.

**Palette state machine.** `PaletteState` (`mode: 'root'|'providers'|'themes'`, `query`, `selectedIndex`) is a clean, minimal immutable triple — `withMode()` resets query/selection on transition.

### E. Recommendations

**1. Collapse `PaletteAction` + `CommandRegistry` into one source of truth.**
*Why*: the two-list drift is a live bug generator — a new slash command silently won't appear in Ctrl+P and vice versa.
*Sketch*: give `CommandSpec` an optional `?PaletteAction $paletteAction` and an `argumentHint`. Make `CommandRegistry::all()` the single registry both surfaces read: `Chat::slashMenuMatches()` keeps filtering it; `Chat::paletteItemLabels()`'s `'root'` branch becomes `array_map(fn(CommandSpec $s) => $s->name, CommandRegistry::all())`. Retire `PaletteAction` or shrink it to just the two palette-only pseudo-actions (`NewSession`, `OpenDocs`) that have no slash-command form, folded into `CommandRegistry::all()` with a `slashVisible: bool` flag.

**2. Fuzzy-match the `/` popup with the same matcher already in the palette.**
*Sketch*: `CommandRegistry::filter()` becomes a `matchAll()` call through `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher`, same as `Chat::paletteMatches()`. Empty prefix still short-circuits to `self::all()`.

**3. Render fuzzy-match highlighting instead of discarding it.**
*Why*: `SugarCraft\Fuzzy\Highlighter` already turns a `MatchResult`'s matched indices into styled runs via a `\Closure(string):string` styler — a ~10-line wire-up.
*Sketch*: change `Chat::paletteMatches(): array` to return `list<MatchResult>` (or add a sibling), then in `Renderer::renderPalette()`:
```php
$styler = fn(string $s): string => Style::new()->foreground($theme->userLabel)->bold()->render($s);
$label = (new Highlighter())->highlight($result, $styler);
```
Apply the same to `renderSlashMenu()` once (2) makes it fuzzy.

**4. File-based custom commands, mirroring `SkillLoader`'s already-proven three-tier pattern.**
*Why*: the single biggest capability gap versus both reference tools — users cannot add project- or personal-specific slash commands without a PHP patch to `Chat::submit()`.
*Sketch*: add `CommandLoader` in `src/Commands/`, structurally parallel to `SkillLoader`:
```php
final class CommandLoader
{
    public function loadFromDirectory(string $dir): array { /* find *.md, parse frontmatter, CommandSpec::fromFile() */ }
    public function loadUserCommands(): array    { return $this->loadFromDirectory(($_SERVER['HOME'] ?? '/root') . '/.sugar-crush/commands'); }
    public function loadProjectCommands(string $root): array { return $this->loadFromDirectory(rtrim($root, '/') . '/.sugar-crush/commands'); }
    public function loadAll(string $projectRoot = '.'): array {
        return array_merge(CommandRegistry::all(), $this->loadUserCommands(), $this->loadProjectCommands($projectRoot));
    }
}
```
`CommandSpec` gains `?string $template`, `?string $argumentHint`, `?string $model`, `bool $subtask` fields populated from YAML frontmatter (reuse `symfony/yaml`, already a dependency). Template substitution (`$ARGUMENTS`, `$1`/`$2`, `` !`cmd` ``, `@file`) becomes a small `CommandTemplate::render(string $template, array $args, ?string $cwd): PromiseInterface<string>` — `` !`cmd` `` shell-out must go through ReactPHP's `Process` rather than blocking `shell_exec`.

**5. Show `argumentHint` in both menus.**
*Sketch*: add `public readonly ?string $argumentHint = null` to `CommandSpec`; `Renderer::renderSlashMenu()` appends it faint: `` '/' . $spec->name . ($spec->argumentHint ? ' ' . $spec->argumentHint : '') . ' — ' . $spec->description ``.

**6. Category-group the Ctrl+P palette instead of a flat list.**
*Sketch*: in `Renderer::renderPalette()`, when the query is empty (or always), bucket `$matches` by category preserving first-seen order, emit a faint category header before each bucket. When a query is active, keep the flat fuzzy-ranked list.

**7. MRU bias for the empty-query palette.**
*Sketch*: track `array<string,int> $commandUseCounts` (or a small ring buffer) on `Chat`, persisted the same way `themeName`/session state already is; empty-query branch sorts by `[recency desc, declared order]`. Keep fuzzy-query results purely relevance-sorted.

**8. Make `mcp auth` a real `/mcp` slash command.**
*Sketch*: register `CommandSpec::new('mcp', 'Manage MCP server auth (list/add/remove)', 'MCP', argumentHint: '<list|add|remove> [server]')` in `CommandRegistry::all()`, and add a `str_starts_with($text, '/mcp')` branch in `Chat::submit()` that strips the leading `/` before delegating to `McpAuthCommand::execute()` — keep the bare `mcp auth …` string form working underneath for backward compatibility.

---

## 5. Navigating between agents/sessions & resuming old sessions

### 5A. opencode (anomalyco/opencode)

opencode already supports **multiple concurrent sessions each with their own subagents**, but the TUI's exposure of that concurrency lags the backend — a string of open feature requests describes exactly the gap:

- **[#17838](https://github.com/anomalyco/opencode/issues/17838) "Session & Subagent Tabs in the TUI"** — the core problem statement: users run "multiple SESSIONS each working on their own worktree with their own subagents" but the TUI gives no way to see them side by side. The proposed fix is a **two-level tab system**: session tabs across the top (active tab highlighted, spinner if processing), and a second tab row for the *subagents spawned within the active session*. Switching is mouse-clickable and keybound (`Ctrl+1..9`-style), with per-tab status indicators (running/idle, tool-call counts).
- **[#27746](https://github.com/anomalyco/opencode/issues/27746)** asks for an `opencode agents` CLI subcommand for background-session management — explicitly modeled on Claude Code's `claude agents` — because today background sessions are only reachable via raw commands (`opencode session list`, `opencode -s <id>`, `opencode -c`), no live dashboard.
- **[#15223](https://github.com/anomalyco/opencode/issues/15223)** and **[#15363](https://github.com/anomalyco/opencode/issues/15363)** both describe the same missing piece from different angles: when a session spawns subagents, nothing in the TUI auto-discovers or auto-focuses them.
- **[#14053](https://github.com/anomalyco/opencode/issues/14053)** notes an inconsistency: the web UI's session list already surfaces subagent and archived sessions that the TUI deliberately hides — two divergent session-listing implementations with different completeness. A caution for sugar-crush, which (see 5D) has the same "two systems" problem.

**Takeaway for sugar-crush**: opencode's trajectory validates the shape (session tabs + nested subagent tabs + auto-focus-on-spawn), but also shows the risk of shipping the data model before the picker — exactly what has happened in sugar-crush already.

### 5B. Claude Code

**1. Resuming past conversations** (`code.claude.com/docs/en/sessions`)
- `claude --continue` — silently resumes the most recent session in the cwd.
- `claude --resume` (no args) — opens an **interactive session picker**: rows show session name (user-set or AI-generated title), summary/first-prompt, time since last activity, git branch, file size; `↑/↓` navigate, `Enter` resumes, `Space`/`Ctrl+V` previews without committing, `Ctrl+R` renames in place, `/` enters search (including pasting a PR URL to jump to the session that created it), `Ctrl+A` widens to all projects, `Ctrl+W` widens to all worktrees of the repo, `Ctrl+B` filters to the current git branch, `Esc` exits.
- `claude --resume <name-or-id>` resumes directly on an exact match, or pre-fills search on an ambiguous one.
- **What gets restored**: full conversation history including tool calls/results, model (with fallback rules if retired), agent, permission mode (with `plan`/`bypassPermissions` deliberately *not* auto-restored), any active goal, non-expired scheduled tasks. Not restored: `--mcp-config`, `--settings`, `--plugin-dir`, `--fallback-model`, `--add-dir`.
- **Resume-from-summary dialog**: if a session has been idle >1h and is >100k tokens, resuming offers "resume from summary" (runs `/compact` immediately), "resume full session as-is", or "don't ask again" — a direct precedent for cost-aware resume.
- **`/branch`** forks the current transcript into a new session ID, leaving the original untouched and still present in the picker.
- Sessions stored as JSONL at `~/.claude/projects/<project>/<session-id>.jsonl`, one file per session, one JSON line per message/tool-use/metadata entry.

**2. Agent view — live multiplexing of concurrent sessions** (`code.claude.com/docs/en/agent-view`, `claude agents`)

A standalone terminal dashboard, grouped by state by default (`Ctrl+S` toggles group-by-directory):

```
Pinned
  ✽ clawd walk cycle          Drawing the walk-cycle sprite frames          3m
Ready for review
  ∙ jump physics               Opened PR with collision fix        #2048  2h
Needs input
  ✻ power-up design            double jump or wall climb?                  1m
Working
  ✽ collision detection        Adding swept-AABB checks to CollisionSystem 2m
Completed
  ✻ title screen                result: menu, options, and credits done    9m
```

- Icon **color/animation** = liveness state (animated = working, yellow = needs input, dimmed = idle, green = completed, red = failed, grey = stopped); icon **shape** = process status.
- **Backgrounding a live session**: `←` on an empty prompt backgrounds the current session and drops into agent view with it pre-selected — it keeps running.
- **Attaching**: `Enter`/`→` attaches with full transcript; `Space` opens a non-committing **peek panel** showing the exact blocking question or latest status, reply without leaving the dashboard.
- **Dispatch new background work directly from the dashboard**: typed prompt + `Enter`, or `<agent-name> <prompt>`/`@<agent-name>` to route to a specific subagent, `!<cmd>` to run a shell job, `#<pr-number>` to reopen a PR-linked session.
- **From an active session**: `/background` (`/bg`) backgrounds the current conversation and frees the terminal; `/fork <prompt>` clones the conversation into a *new* background session.
- Other bindings: `Ctrl+T` pin, `Ctrl+R` rename, `Ctrl+X` stop, `Shift+↑/↓` reorder, `Alt+1..9` jump to session N.
- The session picker (`--resume`) and agent view are the same underlying storage — background sessions show a `bg` marker in the picker.

**This exact background-Agent-tool-notification pattern is what this very session runs under** — a spawned background agent completing triggers a notification turn rather than the caller blocking or polling — the same "dispatch → keep working → get pinged" loop Claude Code exposes as `/background` + agent-view peek/attach.

### 5C. tmux/Warp/Cursor — supporting patterns worth stealing

- **tmux/screen**: named sessions, windows within a session, panes within a window; `Ctrl+b s` session tree, `Ctrl+b w` window list; detach/reattach is the original "resume where you left off." sugar-crush's `MultiplexerSplitPane`/`MultiplexerType` already detects tmux/iTerm2 presence — the missing half is *using* that detection to delegate real pane-splitting to tmux when available.
- **Warp Agent Mode**: runs agents in parallel across tabs/panes/worktrees, with a **task pane** listing all running agents and their live plan/progress without leaving the current pane — validates a persistent "status sidebar" as distinct from a full-screen switcher.
- **Cursor Background Agents**: dispatched via `@Cursor <task>`, listed via `@Cursor list my agents`; completion pushed as a **Slack/chat notification** with a deep link back into the IDE — validates routing agent-completion events to an external channel, not just an in-app dashboard.

### 5D. sugar-crush — what exists today

**This is the important part: sugar-crush already contains almost every building block Claude Code's agent-view uses — session tabs, agent status bars, a session picker, background-session daemonization with heartbeats — but a large fraction of it is built, individually unit-tested, and never wired into the live runtime.**

**Two parallel, disconnected UI systems**

1. **The live path** — `SugarCraft\Crush\Chat` (`src/Chat.php`) + `SugarCraft\Crush\Renderer` (`src/Renderer.php`) — is what `bin/sugarcrush` actually runs.
2. **A separate, never-instantiated "App-keyed" system** — `src/App/App.php`, `src/Tui/Pane.php` (9-value enum: Chat/Input/Skills/Agents/Files/Tools/Settings/Help/Menu), `src/Tui/Renderer.php`, `src/Tui/Components/AgentsPane.php`. `AgentsPane::render()` is a **hardcoded stub** — always renders `(no active agents)` regardless of actual state; per `Renderer.php`'s own class docblock, "it belongs entirely to the disconnected `App`-keyed system, so fixing its stub body would not make anything reachable from this, the live, path."

**Session persistence — real, but never populated in production**

- `src/Session/SessionStore.php`: SQLite (WAL mode, 0600 perms) with `sessions`/`messages`/`tool_calls` tables, `createSession()`, `renameSession()`, `forkSession()` (full transcript + tool-call copy), `listSessions()` (deterministic ordering), `pruneSessions()`.
- `src/Session/EnhancedSessionStore.php`: wraps `SessionStore`, adds `session_meta` (summary/tasks/modified-files/agent-states) plus a **checkpoint table for `/rewind`** — `saveCheckpoint()`/`getCheckpoint()`/`restoreCheckpoint()`/`listCheckpoints()`, capped at 100 checkpoints per session.
- `src/Tui/SessionPicker.php`: a fully built, keyboard-navigable picker overlay (↑/↓ browse, Enter resume, Space preview, Esc close, Ctrl+B branch-filter) matching Claude Code's `--resume` picker almost feature-for-feature at the widget level.
- **The gap**: **no production code path — not `Bootstrap::chat()`, not `Chat::init()`, not any `bin/` entry point — ever calls `SessionStore::createSession()`**. So in a real run, `listSessions()` returns `[]` for the process's entire lifetime; `/sessions` renders an empty `SessionPicker::new([])`; `Renderer::renderSessionTabStrip()` reads real rows from `SessionStore::listSessions()` correctly but only ever sees 0 or 1 row, so it self-suppresses below 2 sessions. `Chat::cycleSessionTab()` (`src/Chat.php:398`, bound to Ctrl+Tab/Ctrl+Shift+Tab) is correctly implemented and tested but a guaranteed no-op — and separately, `candy-core`'s `InputReader` doesn't yet decode the `CSI 1;5I` (Ctrl+Tab) sequence most terminals actually send, so the binding is doubly unreachable.
- `src/Tui/SessionTabs.php` + `SessionTab.php`: an independent, fully-tested immutable tab collection (`openTab`/`closeTab`/`setActiveTab`/`detachTab`/`reattachTab`/`updateTabSummary`, Ctrl+Tab cycling with wraparound) — this is the "Session Tabs" opencode is requesting in #17838, **already built in PHP** — but not instantiated anywhere in `Chat`/`Renderer`; its constructor always seeds a synthetic single "main" tab, so `Renderer::renderSessionTabStrip()` reimplements the same concept independently against `SessionStore` rows directly.

**Multi-agent execution — actually wired and working**

- `src/Agents/AgentManager.php`: registers `Agent`s, creates `SubAgent`s, enforces a sealed permission mode once any sub-agent starts, executes sub-agents individually or **in parallel via `AgentWorkerPool`** (default concurrency 5, cancellation support). This is genuinely live: `Renderer::renderAgentView()` (`src/Renderer.php:254`) calls `Chat::agentManager()->active()` and renders through `AgentStatusBar`/`AgentViewPane`.
- **Caveat**: `Renderer::agentDisplayState()` (`src/Renderer.php:282`) hardcodes `elapsedSeconds: 0, tokensUsed: 0, costUsd: 0.0` for every agent row — no per-agent live telemetry accessor exists, so the status line always shows `0s`/`0 tokens` even for agents running for minutes.
- `src/Agents/TeamManager.php` + `Team.php` + `Teammate.php`: a full team-of-agents aggregate — `createTeam()`, per-team `TaskList` (SQLite, `flock`-based task claims) and `Mailbox` (append-only JSONL inbox, poll-with-backoff), persisted to `~/.sugar-crush/teams/{teamId}/registry.json` and re-hydratable — real cross-session resumability for *teams*, just not exposed as a TUI picker.
- `src/Agents/WorktreeManager.php`: creates a real `git worktree` per agent, copies `.worktreeinclude`-listed files into it, tracks a JSON registry, two-tier cleanup policy.

**Background sessions — fully built, zero live callers**

- `src/Sessions/BackgroundSupervisor.php` + `BackgroundSession.php`: `spawnSession()` forks+daemonizes a child PHP process (double-fork, `posix_setsid`), connects back over a Unix domain socket, streams output into a buffer file; `tick()` marks sessions `Stalled` after `HEARTBEAT_TIMEOUT_SECS` (15s) of silence; `reconnect()` restores partial output when the TUI reopens — architecturally sugar-crush's answer to Claude Code's `/background` + agent-view attach. `StallDetector` tracks token throughput to distinguish "stalled" from "just slow."
- **Confirmed via grep**: `BackgroundSupervisor` referenced only inside its own file and two collaborators — no command in `CommandRegistry`/`Chat::submit()`'s dispatch chain, no `bin/` entry point. Dead infrastructure today: a user cannot background a task from chat, and no dashboard analogous to `claude agents` exists to view/attach.
- `Chat::subscriptions()` (`src/Chat.php:2521`) — the hook `candy-core`'s `Program` polls every tick to run background work — **unconditionally returns `null`**. No live polling loop could periodically call `BackgroundSupervisor::tick()`/`reconnect()` even if a session were spawned.

**Net assessment**: sugar-crush is not starting from zero — it has a session store, checkpoint/rewind, a session picker widget, session tabs, agent status/list panes, worktree-per-agent isolation, team task-lists with mailboxes, and a background-daemon supervisor with heartbeat/stall detection and TUI-reopen reconnect. What it lacks is **the wiring that turns these into a single live feature**: session creation at boot, a populated tab strip, a reachable Ctrl+Tab, a background-dispatch command, a subscriptions-driven poll loop, and per-agent live telemetry.

### 5E. Recommendations

**1. Seed a real session at boot — unblocks nearly everything else.**
*Sketch*:
```php
// Bootstrap::chat()
$store = new EnhancedSessionStore($dbPath);
$rows = $store->listSessions(1);
$sessionId = $rows[0]['id'] ?? bin2hex(random_bytes(16));
if ($rows === []) {
    $store->createSession($sessionId, $provider, $model, name: null);
}
$chat = Chat::new(...)->withSessionStore($store)->withCurrentSessionId($sessionId);
```

**2. Decode Ctrl+Tab / Ctrl+Shift+Tab in candy-core's InputReader.**
*What*: add `CSI 1;5I`/`CSI 1;6I` to the modifier-key CSI table, emitting `KeyType::Tab` with `ctrl: true`/`ctrl: true, shift: true`. Second half of the same fix as item 1 (lives in `candy-core`, not `sugar-crush`).

**3. Wire `/bg` and `/fork` commands onto `BackgroundSupervisor`.**
*Sketch*:
```php
// Chat::submit() dispatch chain, alongside handleWorkflowCommand()
if (str_starts_with($text, '/bg') || str_starts_with($text, '/background')) {
    return $this->handleBackgroundCommand($text);
}
private function handleBackgroundCommand(string $inputText): array {
    $task = trim(substr($inputText, strpos($inputText, ' ') ?: strlen($inputText)));
    $session = $this->backgroundSupervisor->spawnSession(
        name: $this->summarizeForName($task),
        agent: $this->agentManager->get('default'),
        task: $task,
        workingDirectory: getcwd(),
    );
    return $this->sessionResponse($inputText, "Backgrounded as {$session->id} — use /agents to check status.");
}
```

**4. Turn `Chat::subscriptions()` into a real heartbeat/poll pump.**
*Sketch*:
```php
public function subscriptions(): ?\SugarCraft\Core\Subscriptions
{
    if ($this->backgroundSupervisor === null || !$this->backgroundSupervisor->hasActiveSessions()) {
        return null;
    }
    return Subscriptions::every(2.0, fn() => new BackgroundTickMsg());
}
// update(): on BackgroundTickMsg, call $this->backgroundSupervisor->tick(),
// diff status per session against last-known, emit a system message /
// bump an unread-notification badge for any that changed.
```

**5. Build an `AgentDashboard` pane modeled on `claude agents` — reuse existing widgets, don't invent new ones.**
*What*: a new `src/Tui/Components/AgentDashboardPane.php` rendering `BackgroundSupervisor::getActiveSessions()` + `AgentManager::active()` grouped by status (Working/Needs-input/Ready/Completed), reusing `AgentStatusBar`/`AgentViewPane` (already correctly styled) instead of the dead `Tui\Components\AgentsPane` stub. Bind to a `/agents` full-pane view in the *live* path. Give each `SubAgent`/`BackgroundSession` a stable index so `Alt+1..9` can jump directly to it, and add a `Space`-triggered "peek" overlay (reuse `Veil`, already used for the command palette).

**6. Fix `Renderer::agentDisplayState()`'s hardcoded zeros.**
*Sketch*: add `elapsedSeconds()`/`tokensUsed`/`costUsd` accessors to `AgentManager`/`SubAgent` (add a `startedAt` timestamp and token counter updated in `executeSubAgent()`'s streaming loop) instead of the literal `0, 0, 0.0` at `src/Renderer.php:288`.

**7. Retire or merge the disconnected `App`/`Tui\Renderer`/`AgentsPane` system.**
*Why*: this is the same trap opencode hit in #14053 (TUI and web UI sessions lists silently diverging) — every future session/agent feature risks being built against the wrong one of the two systems. Given the repo's "fix, don't disable" preference and that the `App` system is provably unreachable from `bin/sugarcrush`, deleting it is the lower-risk choice unless there's a concrete near-term plan to switch the whole app onto the `App`/`Pane` shell.

**8. Session picker: make it live, not a one-shot render.**
*Why*: `/sessions` currently folds the picker's *first frame* into a chat message — no arrow-through navigation. Give `Chat` a persisted `?SessionPicker $sessionPicker` field, entered via `/sessions` or `Ctrl+O`, with `KeyMsg`s routed to it while open (mirroring the palette's `Veil` overlay compositing). Once item 1 makes real rows exist, this is what makes `/sessions` actually behave like Claude Code's `--resume` UI. Also adopt the resume-from-summary dialog, mapping directly onto sugar-crush's existing `ContextCompactor`/idle-compaction machinery.

**9. Multiplexer delegation — finish what `MultiplexerSplitPane` started.**
*What*: when tmux is detected, actually shell out to `tmux split-window`/`tmux new-window` for genuinely separate agent panes rather than always falling back to the in-process `SplitLayout` renderer (its own docblock admits this is a known TODO). Real tmux panes give free scrollback, resize, copy-mode per agent. Good follow-up once background sessions (item 3) exist.

---

## 6. Auto-loading project context files (AGENTS.md/CLAUDE.md) & environment info to the AI

### A) opencode (anomalyco/opencode)

- **Primary filename**: `AGENTS.md`. Historically also `.opencoderules`-style config, but current convention has consolidated on `AGENTS.md` as canonical.
- **Claude Code fallback**: if no `AGENTS.md` is found, opencode falls back to reading `CLAUDE.md` — an explicit interop shim.
- **Upward directory traversal**: opencode walks **up** from cwd looking for the nearest `AGENTS.md`/`CLAUDE.md` — discovery, not a full merge of every ancestor level by default. Narrower than Claude Code's "concatenate every ancestor level."
- **Global + project precedence**: roughly (1) local project rules by upward traversal, (2) global user rules at `~/.config/opencode/AGENTS.md`, (3) `~/.claude/CLAUDE.md` as last-resort fallback (disableable). First matching file wins per category.
- **Config-driven extra instructions**: `opencode.json` supports an `instructions` array accepting glob patterns, relative paths, and remote URLs — combined with the discovered `AGENTS.md`. Conceptually identical to sugar-crush's `forcedInstructions` config array (see D) — same idea, arrived at independently.
- **No native `@import` expansion**: opencode does **not** natively parse `@path` references inside `AGENTS.md` — open issue [#2225](https://github.com/anomalyco/opencode/issues/2225) is asking for exactly this and is still open.
- **Per-directory/on-touch auto-discovery is also requested-not-shipped**: [#6316](https://github.com/anomalyco/opencode/issues/6316) explicitly asks for "load nearest AGENTS.md when a file in that directory is opened" — precisely the mechanism sugar-crush's `InstructionFileLoader::loadForPath()` already implements. **sugar-crush is ahead of opencode on this specific dimension right now.**
- Other open issues: [#5052](https://github.com/anomalyco/opencode/issues/5052) (GitHub Copilot discovery compat), [#20904](https://github.com/anomalyco/opencode/issues/20904) (`.env` files leaking into file-discovery context — security-relevant gotcha worth avoiding in sugar-crush's Glob/Read tools), [#14285](https://github.com/anomalyco/opencode/issues/14285) (auto-discovering `SOUL.md`/`USER.md`/`TOOLS.md`-style identity files).

### B) Claude Code — CLAUDE.md discovery, `@import`, and environment-info injection

**File locations, load order (broadest → most specific):**

| Scope | Location | Notes |
|---|---|---|
| Managed policy | `/etc/claude-code/CLAUDE.md` (Linux) etc. | org-wide, cannot be excluded |
| User | `~/.claude/CLAUDE.md` | personal, all projects |
| Project | `./CLAUDE.md` or `./.claude/CLAUDE.md` | team-shared, committed |
| Local | `./CLAUDE.local.md` | personal, gitignored, deprecated in favor of user-level `@~/...` imports |

**Discovery algorithm**: Claude Code walks up the directory tree from cwd, checking every directory for `CLAUDE.md`/`CLAUDE.local.md`. Every file found in that ancestor chain is loaded **in full at launch** — concatenated, ordered root→cwd (closest file read last, highest recency-bias). Nested `CLAUDE.md` files in subdirectories *below* cwd are **not** loaded at launch — they load lazily "on demand when Claude reads files in those directories" — exactly the on-touch pattern sugar-crush's `InstructionFileLoader::loadForPath()` reimplements.

**`@path` import syntax** (the mechanism this very repo's `CLAUDE.md` uses via `@./AGENTS.md` and `@./CONTRIBUTING.md`): relative or absolute; relative resolves against *the file containing the import*; recursive, capped at **max depth 4**; parsing skips fenced/inline code spans; imported content is fully inlined at launch. **Security gate**: an import in a *project*-level file resolving *outside the working directory* triggers a one-time approval dialog (project files are attacker-influenceable) — user-scope imports skip this. `.claude/rules/*.md` is a structured sibling mechanism: unconditional rules load like CLAUDE.md; rules with `paths: [...]` frontmatter are path-scoped, only entering context when Claude touches a matching file.

**AGENTS.md interop**: documented explicitly — *"Claude Code reads `CLAUDE.md`, not `AGENTS.md`."* The bridge is either `@AGENTS.md` inside `CLAUDE.md`, or a plain symlink (breaks on Windows without admin/dev-mode). `/init`/`/import` can pull `AGENTS.md`, Cursor rules, Copilot instructions into a generated `CLAUDE.md` one time.

**Environment info block — the pattern this very session experiences directly.** In the system prompt, alongside the tool list, a structured block delivers *environment/runtime metadata* (not CLAUDE.md content): working directory, git-repo status, additional working directories, platform, shell, OS version, followed by a `gitStatus` snapshot (current branch, inferred main/base branch, `git status`, last handful of `git log` entries) — an explicitly labeled point-in-time snapshot taken once at session start. Delivered the same way but architecturally separate: user email, current date, and a model self-identification string ("You are powered by the model named Sonnet 5..."). This block is synthesized by the harness from live process/filesystem/git state each session, not read verbatim off disk, delivered as a system-reminder/user-turn injection rather than baked into a cached system-prompt string (cheap facts like date/cwd stay fresh without invalidating prompt caching on the larger static instructions). Auto-memory is injected the same way, as a bullet index of topic-file cross-references.

**AGENTS.md as emerging standard**: proposed by OpenAI (Aug 2025), later transferred to the Linux Foundation's Agentic AI Foundation; by mid-2026 cited as adopted by 60,000+ repos, read natively by Codex CLI, Cursor, Copilot, Gemini CLI, Aider, Windsurf, Zed. Converging pattern: (1) a well-known root filename, (2) global/user-level override file, (3) nested per-directory files with closest-wins or full-concatenation semantics, (4) an explicit interop shim for teams straddling two tools.

### D) sugar-crush's current implementation

All context-file logic lives in `src/Context/`:

- **`InstructionFileLoader.php`** — the only piece doing file discovery today. Three methods:
  - `loadRoot()`: reads `{repoRoot}/CLAUDE.md` and `{repoRoot}/AGENTS.md` (both, if present — no precedence choice, no fallback logic).
  - `loadForced(array $forcedInstructions)`: resolves glob patterns from config, rejects absolute-path patterns as a security guard.
  - `loadForPath(string $touchedPath)`: walks **up** from `dirname($touchedPath)` toward `repoRoot`, checking CLAUDE.md then AGENTS.md at each level (CLAUDE.md preferred — mirrors Claude Code's own precedence choice), injects **at most once per session** via an internal dedup map.
- **`ContextCompactor.php`/`CompactorConfig.php`** — conversation-history compaction (tiered 70/85/95% thresholds), sibling mechanism, fully wired into `Chat.php`.

**Wiring — the actual gap.** `loadForPath()` is genuinely wired: `src/Cli/Bootstrap.php::tools()` (lines 249–262) constructs one shared `InstructionFileLoader` and threads it into `Read`, `Edit`, `Glob` — nested CLAUDE.md/AGENTS.md content surfaces on-demand the moment the agent touches a file in that subtree. This matches (and is ahead of) opencode's not-yet-shipped on-touch-discovery request.

**However, `loadRoot()` and `loadForced()` are dead code** — no caller anywhere in `src/`. Root-level `CLAUDE.md`/`AGENTS.md` are never read at session start in sugar-crush today. A user's `AGENTS.md` sitting in the repo root has zero effect unless the agent happens to `Read`/`Glob`/`Edit` a file in that exact directory.

Corroborated by `src/Runtime.php::buildSystemPrompt()` (lines 189–202) — the actual system-prompt assembly point:

```php
private function buildSystemPrompt(App $app): string
{
    $base = 'You are SugarCrush, an AI coding assistant.';

    if (!empty($app->enabledSkills)) {
        foreach ($app->enabledSkills as $skill) {
            if ($skill instanceof \SugarCraft\Crush\Skills\Skill) {
                $base .= "\n\n" . $skill->systemPromptContribution();
            }
        }
    }

    return $base;
}
```

No `InstructionFileLoader` reference, no CLAUDE.md/AGENTS.md content, and — for the environment-info half — **no cwd, no git status, no platform/OS string, no model self-identification, no current date**. `src/Agents/Agent.php::systemPrompt()` (subagent path) is even barer — a direct passthrough of `$this->prompt` with no injection at all. `Runtime::executeToolCalls()` does construct a `getcwd()`-derived `projectRoot`, but it only feeds `HookContext` for hooks — never surfaced to the model as prompt content.

`tests/Context/InstructionFileLoaderTest.php` (14 tests) confirms the *class* is correct and well-covered in isolation — the gap is purely one of **integration wiring**.

**Summary of the gap, precisely stated:**
1. Root `CLAUDE.md`/`AGENTS.md` auto-load (`loadRoot()`) — implemented, tested, **never called**.
2. Config-driven forced instructions (`loadForced()`) — implemented, tested, **never called**.
3. Nested on-touch loading (`loadForPath()`) — implemented, tested, **wired correctly**.
4. Environment info (cwd, git status, platform, model, date, additional dirs) — **does not exist anywhere**.
5. `@import` expansion inside CLAUDE.md/AGENTS.md — **not implemented** (this repo's own `CLAUDE.md` uses `@./AGENTS.md`/`@./CONTRIBUTING.md` — if sugar-crush is ever pointed at its own repo, those imports would render as literal dead text).

### E) Recommendations

**1. Wire `loadRoot()`/`loadForced()` into session start (closes the biggest gap).**
```php
// src/Runtime.php
private function buildSystemPrompt(App $app): string
{
    $base = 'You are SugarCrush, an AI coding assistant.';

    $base .= "\n\n" . $this->environmentBlock($app);   // see rec #2

    if ($app->instructionLoader !== null) {
        $rootDocs = [
            ...$app->instructionLoader->loadRoot(),
            ...$app->instructionLoader->loadForced(),
        ];
        foreach ($rootDocs as $doc) {
            $base .= "\n\n<project-instructions>\n{$doc}\n</project-instructions>";
        }
    }

    foreach ($app->enabledSkills as $skill) {
        if ($skill instanceof \SugarCraft\Crush\Skills\Skill) {
            $base .= "\n\n" . $skill->systemPromptContribution();
        }
    }

    return $base;
}
```
`App` needs a new `?InstructionFileLoader $instructionLoader` property, populated from `Bootstrap::instructionLoader()` at construction (the same instance already shared with Read/Edit/Glob, keeping `loadForPath()`'s dedup consistent). Cache root/forced content on `App` (or memoize inside `InstructionFileLoader`) since `buildSystemPrompt()` shouldn't re-read from disk every turn.

**2. Add an `EnvironmentBlock` builder — the missing "Environment" system-prompt section.**
```php
declare(strict_types=1);

namespace SugarCraft\Crush\Context;

final readonly class EnvironmentBlock
{
    public function __construct(
        private string $cwd,
        private string $modelName,
        private ?\DateTimeImmutable $now = null,
    ) {}

    public static function capture(string $cwd, string $modelName): self
    {
        return new self($cwd, $modelName, new \DateTimeImmutable());
    }

    public function render(): string
    {
        $lines = [
            'Working directory: ' . $this->cwd,
            'Is directory a git repo: ' . ($this->isGitRepo() ? 'Yes' : 'No'),
            'Platform: ' . strtolower(PHP_OS_FAMILY),
            'PHP version: ' . PHP_VERSION,
            'Model: ' . $this->modelName,
            'Current date: ' . ($this->now ?? new \DateTimeImmutable())->format('Y-m-d'),
        ];

        if ($this->isGitRepo()) {
            $lines[] = '';
            $lines[] = $this->gitStatusSnapshot();
        }

        return "<env>\n" . implode("\n", $lines) . "\n</env>";
    }

    private function isGitRepo(): bool
    {
        return is_dir($this->cwd . '/.git');
    }

    /** One-shot snapshot at session start — never re-run mid-session (matches Claude Code's documented behavior). */
    private function gitStatusSnapshot(): string
    {
        $branch = trim((string) shell_exec('git -C ' . escapeshellarg($this->cwd) . ' branch --show-current 2>/dev/null'));
        $status = trim((string) shell_exec('git -C ' . escapeshellarg($this->cwd) . ' status --porcelain 2>/dev/null'));
        $log = trim((string) shell_exec('git -C ' . escapeshellarg($this->cwd) . ' log --oneline -5 2>/dev/null'));

        return "Current branch: {$branch}\n\nStatus:\n{$status}\n\nRecent commits:\n{$log}";
    }
}
```
Capture this **once** per `Chat` session (not per turn — git status is a snapshot, not live-polled). Reuse `escapeshellarg()` per this repo's own `AGENTS.md` gotcha.

**3. Implement `@path` import expansion for CLAUDE.md/AGENTS.md content.**
*Why*: this repo's own root `CLAUDE.md` already uses `@./AGENTS.md`/`@./CONTRIBUTING.md` — a minimal, depth-capped expander closes this and gets sugar-crush to parity with Claude Code's most distinctive feature here.
```php
// src/Context/ImportResolver.php
final class ImportResolver
{
    private const MAX_DEPTH = 4;

    public function expand(string $content, string $baseDir, int $depth = 0): string
    {
        if ($depth >= self::MAX_DEPTH) {
            return $content;
        }

        // Skip fenced/inline code spans so `@README` inside backticks stays literal.
        return preg_replace_callback(
            '/(?<!`)@(\.?\/?[\w\-\.\/~]+\.md)(?!`)/',
            function (array $m) use ($baseDir, $depth): string {
                $path = str_starts_with($m[1], '~/')
                    ? getenv('HOME') . substr($m[1], 1)
                    : rtrim($baseDir, '/') . '/' . ltrim($m[1], './');

                if (!is_file($path)) {
                    return $m[0]; // leave unresolved refs untouched
                }

                $imported = file_get_contents($path);
                return $this->expand($imported, dirname($path), $depth + 1);
            },
            $content,
        ) ?? $content;
    }
}
```
Wire into `InstructionFileLoader::loadRoot()`/`loadForPath()` right after `file_get_contents()`. Flag (don't silently follow) imports resolving outside `repoRoot` — mirror Claude Code's approval-dialog concept, at minimum a warning-tagged tool-result note.

**4. Promote `forcedInstructions` from a bare constructor array to real config**, sourced from `~/.sugar-crush/config.json` under an `"instructions"` key accepting globs — directly mirroring opencode's `opencode.json` `instructions` array. Turns `loadForced()` from unreachable code into a genuinely user-configurable feature.

**5. Add an integration test proving the wiring**, not just the unit-level `InstructionFileLoaderTest` — a `ChatTest`/`BinSugarcrushWiringTest`-style test asserting `Runtime::buildSystemPrompt()`'s output actually contains a root `AGENTS.md`'s content when present.

**6. Nested `AGENTS.md` fallback semantics**: `loadForPath()` already prefers `CLAUDE.md` over `AGENTS.md`, correctly mirroring Claude Code. Consider exposing this preference order as a config option (`instructionFilePriority: ['CLAUDE.md', 'AGENTS.md']`) rather than a hardcoded array literal, given AGENTS.md's increasing universality.

Sources:
- [How Claude remembers your project — Claude Code Docs](https://code.claude.com/docs/en/memory)
- [opencode/AGENTS.md at dev · anomalyco/opencode](https://github.com/anomalyco/opencode/blob/dev/AGENTS.md)
- [Issue #6316 — Context Auto-Discovery](https://github.com/anomalyco/opencode/issues/6316)
- [Issue #2225 — Automatically load @ referenced files](https://github.com/anomalyco/opencode/issues/2225)
- [Issue #20904 — .env files leaking into context](https://github.com/anomalyco/opencode/issues/20904)
- [Issue #14285 — Auto-discover workspace identity files](https://github.com/anomalyco/opencode/issues/14285)
- [AGENTS.md vs CLAUDE.md vs Cursor Rules vs Copilot (2026) — codersera](https://codersera.com/blog/agents-md-vs-claude-md-vs-cursor-rules-comparison-2026/)
- [AGENTS.md Spec (2026) — morphllm](https://www.morphllm.com/agents-md-guide)

---

## 7. Automatic skill loading based on task/content/context

### A. opencode (anomalyco/opencode)

opencode has **no skill-equivalent concept** — no packaged instruction set that auto-activates by matching a description against task content. What it has instead:

- **Primary vs. subagent architecture.** Agents declare `"mode": "primary" | "subagent" | "all"` in `opencode.json` or as markdown files with frontmatter under `~/.config/opencode/agents/<name>.md` or `.opencode/agents/<name>.md`. Primary agents are the ones a session runs as; subagent-mode agents can only be launched by a primary agent, never selected directly.
- **Explicit invocation only, two mechanisms:** (1) the primary agent calls the built-in `subagent` tool (foreground or background) — a normal tool call the model chooses to make, not a system-triggered match; (2) the user `@`-mentions a subagent by name to force delegation. There is no automatic content-based triggering — the *model* reads subagent descriptions and *decides* to call the tool, functionally similar to any LLM picking among tool schemas, not a separate skill-matching subsystem with progressive disclosure.
- **Plugin system** (`packages/opencode/src/plugin/index.ts`, `Plugin.trigger()`): async TS functions hooking into 25+ lifecycle events. Event-driven middleware, not content/task-driven skill selection.

Net: opencode's closest analogue to "skills" is its subagent roster, but selection is 100% LLM tool-choice or explicit `@mention` — no dedicated matcher, no frontmatter description-vs-prompt scoring, no progressive-disclosure loading stage.

Sources: [opencode Agents docs](https://opencode.ai/v2/docs/agents), [opencode AGENTS.md](https://github.com/anomalyco/opencode/blob/dev/AGENTS.md), [Plugin System (DeepWiki)](https://deepwiki.com/anomalyco/opencode/2.9-plugin-system)

### B. Claude / Claude Code Skills — how the system actually works

**Three-level progressive disclosure**:

| Level | When loaded | Token cost | Content |
|---|---|---|---|
| 1: Metadata | Always, at startup | ~100 tokens/skill | `name` + `description` from frontmatter, folded into the system prompt |
| 2: Instructions | Only when triggered | <5k tokens | Full `SKILL.md` body |
| 3: Resources/code | Only as referenced | 0 until accessed | `scripts/`, `references/`, `assets/` |

The **`description` field is the entire trigger mechanism** — no embedding index, no classifier, no separate keyword-extraction step. The model reads the whole list of name+description pairs sitting in its system prompt every turn and decides, by ordinary language understanding, whether the current request matches. With 100+ skills this becomes a large-context matching problem solved by the LLM's own reasoning, not a separate retrieval system.

**The `Skill` tool invocation pattern**: `Skill(name)`/`Skill(name *)`, subject to normal permission rules. Skill content, once invoked, is inserted as a single message and **persists for the rest of the session**; re-invoking with identical content is deduped. Auto-compaction re-attaches the most recent invocation of each skill (first 5k tokens, 25k-token combined budget).

**Frontmatter controls that distinguish proactive vs. manual skills:**

| Frontmatter | Effect |
|---|---|
| *(default)* | Both user (`/name`) and Claude can invoke; description always in context |
| `disable-model-invocation: true` | Only the user can invoke via `/name`; description not shown to the model — for side-effecting workflows like `/deploy`, `/commit` |
| `user-invocable: false` | Only Claude can invoke (hidden from `/` menu) — background knowledge |
| `paths: [...]` | Glob patterns; Claude auto-loads the skill only when working with matching files |
| `context: fork` + `agent: <type>` | Runs the skill's body as the prompt for a forked subagent |
| `allowed-tools`/`disallowed-tools` | Pre-approves or strips tool access for that turn |
| `model`/`effort` | Overrides model/reasoning-effort for that invocation |

Only `description` is "recommended"; everything else optional. Anthropic explicitly recommends building **evaluations before writing extensive docs** — the description's trigger accuracy is a testable, iterable artifact, not a one-shot guess.

Sources: [Agent Skills overview](https://platform.claude.com/docs/en/agents-and-tools/agent-skills/overview), [Skill authoring best practices](https://platform.claude.com/docs/en/agents-and-tools/agent-skills/best-practices), [Use Skills in Claude Code](https://code.claude.com/docs/en/skills)

### C. ChatGPT / other assistants

OpenAI shipped **"Skills in ChatGPT"** in 2026 — a genuinely comparable auto-trigger system, discovered purely off `name`+`description` matching, same shape as Claude's mechanism, with explicit authoring guidance to front-load key use cases and trigger words, and to test prompts against the description. Turning on by default for Enterprise workspaces from July 23, 2026. Suggests "description-string-matched-by-the-LLM-itself" is becoming the industry-standard pattern rather than a bespoke retrieval system.

Sources: [Skills in ChatGPT — OpenAI Help Center](https://help.openai.com/en/articles/20001066-skills-in-chatgpt)

### D. sugar-crush's current implementation

Files: `src/Skills/Skill.php` (value object), `SkillLoader.php` (filesystem loader, including an *unused* 3-stage progressive API), `SkillRegistry.php` (in-memory store + matching), `SkillManager.php` (thin facade), `SkillDiscovery.php` (locates skill directories), `BuiltIn/` — 12 shipped skills.

**Format** closely mirrors Claude Code's SKILL.md spec: YAML frontmatter with `description`, `user-invocable`, `disable-model-invocation`, `allowed-tools`, `effort`, `paths` (glob-scoped auto-trigger, e.g. `security-audit/SKILL.md` sets `paths: ["**/*.php"]`), plus `context: fork`/`model`. E.g. `security-audit/SKILL.md`:
```yaml
---
description: Security audit for PHP code. Check for SQL injection, XSS, CSRF, authentication issues, and other vulnerabilities.
user-invocable: true
disable-model-invocation: false
allowed-tools: "Read,Grep,Bash"
effort: high
paths:
  - "**/*.php"
---
```

**Matching logic exists but is primitive.** `Skill::matchesPrompt()` (`src/Skills/Skill.php:89-101`) does naive keyword overlap: split description on spaces, keep tokens >3 chars, `stripos()` against the prompt — no stemming, no stopword list, no weighting. `SkillRegistry::findForPrompt()` re-ranks by `substr_count()` — a crude relevance proxy, not TF-IDF or embeddings. Both unit-tested in isolation, but that's where the trail ends.

**Progressive disclosure is designed but dead code.** `SkillLoader` has a genuine 3-stage API — `loadSkillManifest()`, `loadSkillBody()`, `loadSkillAsset()` — explicitly citing the same manifest→body→asset staging Claude Code uses. However, only `loadAll()` is ever called, and it eagerly parses full frontmatter *and* body for every discovered skill up front. The staged methods have zero production callers.

**Skill selection today is manual/static-only in the live app, not auto-triggered. Tracing the wiring end to end:**
- `App::findSkillsForTask()` (`src/App/App.php:185-188`) exists and calls `$this->availableSkills->findForPrompt($task)`, but grepping all non-Skills/non-test source finds **no caller** anywhere in the running application.
- The only way a skill becomes "enabled" in a live session is a hardcoded keybinding opening `SkillsPane` (a pure human picker), or `AppBuilder::withEnabledSkills()` being fed a static list at construction time.
- More fundamentally, `App::availableSkills` (the registry all matching/dispatch/picker methods read from) is **never populated** in the real construction path: `AppBuilder::build()` calls `App::new()->withEnabledSkills(...)` but never `->withAvailableSkills(...)`. `bin/sugarcrush` contains **zero references to `Skill` at all**. So in the actual running TUI, the entire `SkillLoader::loadAll()`/`BuiltIn/` skill roster is **not loaded into a live session by anything today** — a fully built, unit-tested subsystem sitting dormant, disconnected from the runtime bootstrap.

**Summary of the gap:** the right *shape* (SKILL.md format nearly 1:1 with Claude Code's, staged-loading API, fork-vs-inline dispatch, disable-model-invocation/user-invocable distinction) exists, but three integration gaps remain: (1) no code path wires `SkillManager::loadAll()`/`SkillDiscovery` into the live `AppBuilder`/`EngineBackend` bootstrap at all, (2) even if wired, matching is a crude 4-char-token substring check, (3) the built progressive-disclosure staging is orphaned.

### E. Recommendations

**1. Wire the existing Skills subsystem into the live app (prerequisite for everything else).**
```php
// in AppBuilder::build() or an EngineBackend factory
$discovery = new SkillDiscovery();
$loader    = new SkillLoader();
$registry  = new SkillRegistry();
$manager   = new SkillManager($loader, $registry);
$manager->loadAll($this->projectRoot);
$manager->disableFromConfig($this->config->disabledSkills ?? []);

return App::new($this->provider, $this->model)
    ->withAvailableSkills($registry)
    ->withEnabledSkills($this->enabledSkills)
    // ...
```
*Why*: nothing downstream (picker, auto-match, fork dispatch) can work while `availableSkills` is empty in production. This is a one-line-of-impact, high-leverage fix — everything else here is worthless until this lands.

**2. Replace `matchesPrompt()`'s substring heuristic with an actual `SkillMatcher` service — LLM-judged first, not keyword-heuristic-first.**
*What*: introduce `src/Skills/SkillMatcher.php`. Two viable strategies:
- **Strategy A (system-prompt injection, matches how Claude/ChatGPT actually do it):** don't pre-filter with PHP string matching at all. Fold every auto-invocable skill's `name: description` into the agent's system prompt once at session start (~100 tokens/skill — cheap even for 50+ skills), and expose a `Skill` tool the model can call. Let the LLM decide relevance.
- **Strategy B (keyword/embedding fallback):** TF-IDF over description tokens, or cosine-similarity via a local embedding model if one is already in the stack — useful as a secondary filter once skill count grows past ~100.

*Sketch (Strategy A):*
```php
final class SkillTool implements Tool
{
    public function __construct(private SkillRegistry $registry) {}

    public function name(): string { return 'Skill'; }

    public function schema(): array
    {
        return [
            'name' => ['type' => 'string', 'description' => 'Skill name to invoke'],
            'args' => ['type' => 'string', 'description' => 'Optional arguments'],
        ];
    }

    public function call(array $input, App $app): ToolResult
    {
        $skill = $this->registry->get($input['name']);
        if ($skill === null || !$this->registry->isAutoInvocable($skill->name)) {
            return ToolResult::error("Skill not found or not model-invocable: {$input['name']}");
        }
        // Level 2: load body only now, on invocation — not at startup.
        $body = (new SkillLoader())->loadSkillBody($skill->sourcePath);
        return ToolResult::text("## Skill: {$skill->name}\n\n{$body}");
    }
}
```
And at system-prompt build time, only Level-1 metadata is injected:
```php
$listing = implode("\n", array_map(
    fn(Skill $s) => "- {$s->name}: {$s->description}",
    $registry->getAutoInvocable()
));
$systemPrompt .= "\n\nAvailable skills (invoke via Skill tool):\n{$listing}";
```

**3. Actually use the already-built progressive-loading stages, and fix `loadAll()` to be lazy.**
*Sketch*: change `SkillManager::loadAll()` to call `loadSkillManifest()` per discovered `SKILL.md`, registering lightweight manifest-only `Skill` objects into `SkillRegistry`. Only when the `Skill` tool from #2 actually invokes a skill does `loadSkillBody()` backfill the body just-in-time. `loadSkillAsset()` then gets exercised when the body references a `scripts/`/`references/`/`assets/` file.
*Why*: every ReactPHP-loop session currently pays the full I/O + YAML-parse cost of every built-in skill body on startup even if zero skills are used that session — defeats the whole point of the already-designed progressive disclosure.

**4. Keep `paths`-based auto-scoping, but wire it into the ReactPHP file-touch path, not just as static metadata.**
*What*: call `SkillRegistry::getForPaths()` (already correct, tested) from wherever `Read`/`Edit`/`Glob` results resolve file paths; when a path-scoped skill newly matches a touched file, surface it as a system-reminder-style nudge next turn — mirroring Claude Code's "skills load on first read/edit inside that subdirectory."

**5. Preserve and formalize the `disable-model-invocation`/`user-invocable`/`context: fork` matrix — already correctly modeled, just make it reachable.**
Once #1 wires the registry live and #2 exposes a real `Skill` tool, `SkillRegistry::isAutoInvocable()` and `dispatchSkill()`'s fork-vs-inline branch become live, working code paths instead of test-only code.

**Priority order:** (1) is a hard blocker. (2)+(3) are the core "make auto-trigger real and efficient" pair, should ship together. (4) and (5) are incremental hardening once the core loop works.

---

## 8. Mouse integration

### A. opencode (anomalyco/opencode)

`anomalyco/opencode` is a TypeScript/Bun monorepo with both a terminal client and a desktop app. As of v1.0 its TUI is **not** Bubble Tea — it runs on **OpenTUI** (`anomalyco/opentui`, `@opentui/core`), a native terminal-UI core written in **Zig** with TypeScript bindings. OpenTUI's own docs advertise "a complete mouse event system with click tracking, drag handling, and selection" as a first-class primitive of the renderer.

Despite the framework offering full mouse primitives, opencode's *application layer* has not wired mouse support into every surface:

- **Issue #12395** ("feat: add mouse support to TUI menus") — the agent/model/context-switcher menus are keyboard-only; no click-to-select, no hover highlight.
- **Issue #11881** ("Add mouse click support on home screen") — the home screen's session/agent tabs and command-palette equivalent still require keyboard-only interaction — direct precedent for the "click to switch agent/session tab" ask.
- **Issue #292** — request to let the mouse select/copy the assistant's *output text* — mouse selection is passthrough vs. the app's own click handling competing for the same drag gesture.
- **Issue #15760/#7316** — mouse selection "unreliable" in VS Code's integrated terminal, wheel scroll "stops working after some usage" on Windows Terminal.
- **Issue #7926/#11362** — users want to fully disable mouse capture (or disable clicks while keeping wheel scroll) for tmux/Zellij compatibility, because SGR mouse-tracking mode intercepts the terminal's native copy-on-select while active.

**Takeaway**: even a purpose-built native TUI renderer with "complete" mouse primitives still requires per-widget, per-menu opt-in work, and the recurring pain points are consistent: (1) mouse capture vs. native text selection is an unavoidable trade-off, (2) an escape hatch to disable click capture while keeping scroll is a near-universal ask, (3) reliability regressions inside terminal multiplexers/emulators are common and need explicit handling.

### B. Terminal mouse protocols, Claude Code, and cross-framework patterns

**Protocol layer**: Mode 1000 (X10/VT200 normal — press/release, 223-col byte limit), Mode 1002 (button-event/cell-motion — adds drag reporting, most TUI apps' default), Mode 1003 (any-motion — every move, expensive), Mode 1006 (SGR extended coordinates — removes the byte-encoding ceiling, layers on top of 1000/1002/1003), Mode 1015 (urxvt — superseded by SGR).

`candy-core` already gets this right: `SugarCraft\Core\Util\Ansi` exposes `MOUSE_NORMAL=1000`, `MOUSE_BUTTON=1002`, `MOUSE_ANY=1003`, `MOUSE_SGR=1006`, pairs every mode toggle with `?1006h/l`. `MouseMode` (`candy-core/src/MouseMode.php`) exposes `Off|CellMotion|AllMotion`, docblock: "All modes are reported in SGR encoding (CSI 1006)."

**Claude Code's own terminal UI** mouse-enables only in **fullscreen rendering mode** (`/tui fullscreen`, `CLAUDE_CODE_NO_FLICKER=1`) — drawing to the alt-screen buffer. Adds "mouse support for scrolling and selection": scroll-wheel/PageUp scroll the conversation, click-to-expand/hover in multi-select menus, a themeable `selectionBg` for mouse-drag text selection. Two escape hatches:
- `CLAUDE_CODE_DISABLE_MOUSE_CLICKS=1` — disable click/drag/hover, **keep** wheel scroll.
- `CLAUDE_CODE_DISABLE_MOUSE=1` — disable all mouse tracking.

This "scroll-only escape hatch" pattern is the single most consistently requested mouse feature across every tool surveyed — treat as a requirement, not a nice-to-have.

**Cross-framework click-handling pattern.** Bubble Tea, OpenTUI, Ratatui converge on the same shape: (1) enable a mouse mode at program start, (2) render normally but tag interactive regions with an invisible marker or tracked rect, (3) hit-test the region under the reported (x,y) on each mouse event, (4) dispatch a synthetic "zone clicked" message into the normal `Update()` loop. Ratatui ships **no** built-in click/hit-testing — left to the app or third-party crates. Bubble Tea's gap is filled by **bubblezone** (`lrstanley/bubblezone`): `Mark`/`Scan`/`Get` — wrap widget output in zero-width markers, scan once at the root, hit-test via bounding box. Known limitations: strictly-rectangular bounds, truncation can desync a zone's recorded box.

### C. Other mouse-capable AI TUI tool — charmbracelet/crush (the direct upstream)

Because sugar-crush is a literal PHP port of **charmbracelet/crush** (Bubble Tea), crush's own mouse architecture is the most directly applicable reference:

- Mouse events flow through the same central `UI.Update()` as everything else, matching on `tea.MouseClickMsg | tea.MouseMotionMsg | tea.MouseReleaseMsg | tea.MouseWheelMsg`, plus a crush-internal `DelayedClickMsg`.
- **Priority routing**: active dialogs absorb mouse events first — chat only receives clicks when no dialog is open. Same precedence order sugar-crush already uses for keyboard.
- **Coordinate translation**: crush subtracts the layout's `main` rectangle origin before mouse positions reach the Chat sub-component, keeping it decoupled from layout.
- **Multi-click**: a `doubleClickThreshold` of 400ms distinguishes single/double/triple clicks; `DelayedClickMsg` intentionally *defers* firing a click until it's confirmed the user isn't mid-drag-selecting text — explicitly protects native text selection from being hijacked by a premature click handler.
- **Scroll**: mouse wheel triggers `Chat.list.ScrollUp()/ScrollDown()`; a scrollbar becomes visible during scroll, auto-hides after 2 seconds.

This is essentially the same primitive stack candy-mouse/candy-zone already provide in PHP — sugar-crush just hasn't wired it up.

### D. sugar-crush's current mouse state — primitives exist, zero usage

**candy-mouse** (`candy-mouse/src/`) is a complete, tested, bubblezone-equivalent hit-testing primitive:

| File | Role |
|---|---|
| `Mark.php` | Wraps content in invisible PUA sentinels (`U+E000`/`U+E001`), `Mark::zone($id, $content)` |
| `Scanner.php` | `Scanner::new()->scan($rendered)` parses sentinels into a grid-bucketed spatial index; `hit($col, $row)` is sub-linear |
| `Zone.php` | Readonly bounding box |
| `ZoneClickTracker.php` | Press+Release dedup state machine per button, fixes bubblezone's own issue #10 |
| `MouseEvent.php`/`MouseAction.php` | Immutable event + `Press/Release/Drag/Scroll` enum |

**candy-zone** (`candy-zone/src/`) is the higher-level TEA-facing façade: `Manager` (mark/scan/get + `anyInBoundsAndUpdate($model, $mouse)` auto-dispatches `MsgZoneInBounds`), `ZoneHoverTracker`, `DragTracker`, `ClickCounter`, dedicated `Msg` types (`ZoneEnterMsg`, `ZoneExitMsg`, `ZoneDragStartMsg`/`ZoneDragMoveMsg`/`ZoneDragEndMsg`, `DoubleClickMsg`, `TripleClickMsg`).

**candy-core** has full protocol support wired into `Program`: `MouseMode` enum, `MouseButton` enum (incl. `WheelUp`/`WheelDown`/`Backward`/`Forward`), four concrete `Msg` subclasses mirroring Bubble Tea v2's split — `Msg\MouseMsg` (base), `Msg\MouseClickMsg`, `Msg\MouseReleaseMsg`, `Msg\MouseWheelMsg`, `Msg\MouseMotionMsg`. `Program.php` enables/disables terminal escape sequences on `run()`/teardown.

**sugar-crush wires up none of this.** Confirmed:
- `grep -rln "Mouse" src/` returns **zero files**.
- `grep -rn "use SugarCraft\\Mouse\|use SugarCraft\\Zone\|MouseMsg\|MouseEvent"` returns **nothing**.
- `grep -rn "MouseMode|mouseMode"` returns **nothing** — `ProgramOptions::$mouseMode` defaults to `MouseMode::Off` and sugar-crush's `Program` construction in `Chat.php` never overrides it. **The terminal is never even told to start reporting mouse events.**
- Tab switching (`SessionTabs.php`) is keyboard-only: `Ctrl+Tab`/`Ctrl+Shift+Tab`, no click path.
- Pane switching (`KeyboardHandler.php`) is driven entirely by the `tab` key and arrow/vim keys; `Pane::next()` (`Tui/Pane.php`) defines the Chat→Input→Files→Tools→Skills→Agents→Settings→Help cycle that a mouse click could jump into directly.
- `AgentViewPane.php`, `AgentsPane.php`, `ToolsPane.php`, `FilesPane.php`, `SkillsPane.php`, `MenuBar.php` — render selectable lists/menus with keyboard-tracked selection indices; none call `Mark::zone()` or route through `Scanner`/`Manager`.

sugar-crush has the *exact* toolkit crush's own mouse layer needs — sitting in two sibling libraries, fully tested and documented — and imports none of it.

### E. Concrete recommendations

**1. Turn mouse mode on at all, with a disable escape hatch.**
```php
$mouseMode = match (true) {
    (bool) getenv('SUGARCRUSH_DISABLE_MOUSE') => MouseMode::Off,
    default => MouseMode::CellMotion,
};
new ProgramOptions(mouseMode: $mouseMode, /* ... */);
```
Default to `CellMotion` (press/release/drag); skip `AllMotion` initially — hover-everywhere floods the ReactPHP read loop for no current payoff.

**2. Click-to-switch session tab.**
*Why*: the #1 documented user ask across both opencode issues surveyed.
```php
if ($msg instanceof MouseClickMsg && $msg->button === MouseButton::Left) {
    $zone = $this->scanner->hit($msg->x, $msg->y);
    if ($zone !== null && str_starts_with($zone->id, 'tab:')) {
        $tabId = substr($zone->id, 4);
        return [$this->mutate(['sessionTabs' => $this->sessionTabs->setActiveTab($tabId)]), null];
    }
}
```
Wrap each tab label in `Mark::zone("tab:{$tab->id}", $label)`; scan once at the root after composing the full frame (mirroring bubblezone's "scan only at the root model" rule). Route Press+Release through `ZoneClickTracker` so a click-drag-away doesn't fire a spurious switch.

**3. Click-to-switch pane (Chat/Files/Tools/Skills/Agents/Settings/Help).**
Mark each pane's title/border region with `Mark::zone("pane:chat", ...)`; on click, dispatch `$app->withPane(Pane::from($paneName))` directly — same codepath the `tab` key already uses, just a direct jump instead of `next()`.

**4. Scrollwheel in ChatPane / message transcript.**
*Why*: every mouse-capable competitor treats wheel-scroll-in-transcript as table stakes, and it's the one gesture users want to *keep* even when disabling clicks. `ChatPane` currently has no viewport/scroll-offset state — needs one first, independent of mouse work:
```php
if ($msg instanceof MouseWheelMsg && $app->pane === Pane::Chat) {
    $delta = $msg->button === MouseButton::WheelUp ? -3 : 3;
    return [$app->withChatScrollOffset(
        max(0, min($app->chatScrollOffset + $delta, $maxOffset))
    ), null];
}
```
Mirror crush's scrollbar-during-scroll + 2s auto-hide via a `Cmd::tick()`-based timeout, consistent with `StallDetector`/`StallWarning` patterns already in `Tui/`.

**5. Click on a tool-call to expand/collapse.**
```php
if ($zone !== null && str_starts_with($zone->id, 'toolcall:')) {
    $id = substr($zone->id, 9);
    $expanded = $app->expandedToolCallIds;
    $expanded[$id] = !($expanded[$id] ?? false);
    return [$app->withExpandedToolCallIds($expanded), null];
}
```

**6. Click-to-select in command palette / session picker.**
Same `Mark::zone("picker-item:{$index}", $rowText)` + hit-test pattern; on click, dispatch the same `Msg`/`Cmd` the Enter key currently dispatches (reuse `MenuSelectedMsg` so palette/menu confirm logic isn't duplicated).

**7. Hover state for tabs/menu items (stretch, only after 2–6 land).**
Costs real ReactPHP event-loop throughput (`MouseMode::AllMotion` — every pixel-move becomes a `MouseMotionMsg`) — gate behind a separate `--hover`/setting. Reuse candy-zone's `ZoneHoverTracker` (already emits `ZoneEnterMsg`/`ZoneExitMsg`).

**8. Text-selection passthrough / native copy compatibility.**
*Why*: the single most-repeated complaint across every surveyed tool. `ZoneClickTracker` already only fires on a clean Press+Release-on-same-zone pair — extend with a movement-distance check between Press and Release; if `abs(dx)+abs(dy) > threshold`, treat it as a selection drag and suppress the click dispatch entirely (mirror crush's 400ms `doubleClickThreshold`/`DelayedClickMsg`).

**Sequencing note**: items 1–3 are the highest-value, lowest-risk slice (enable mouse mode, wire `Scanner`/`Mark` into `Renderer.php` once at the root, then click-to-switch-tab/pane reuse the same hit-test/dispatch plumbing). Items 4–6 are additive once that plumbing exists. Item 7 (hover) and 8 (drag-vs-select disambiguation) are the highest-fragility items and should land last, tested against tmux/screen pass-through explicitly.

---

## 9. Image/video viewing in-TUI

*(sixel/kitty/iTerm2/half-block/quarter-block/ANSI rendering)*

### A) opencode (github.com/anomalyco/opencode)

Direct repo/docs fetch turned up **no image-rendering code or documentation** — no sixel, kitty, or iTerm2 references anywhere. opencode's TUI is text/markdown-only as far as public docs show; it does not shell out to an external viewer either.

The stronger signal is from opentui (the terminal-UI engine anomalyco also maintains): [issue #92, "Support for rendering images (Kitty Graphics, SIXEL, iterm2 image)"](https://github.com/anomalyco/opentui/issues/92) is **open, unimplemented**. It lays out the landscape:

- **Sixel** — xterm, WezTerm, iTerm2 (≥3.3), Konsole (KDE Gear 22.04+), Windows Terminal (≥1.23), VS Code integrated terminal (≥1.80), foot.
- **Kitty graphics protocol** — Kitty (native, doesn't support sixel by design), WezTerm, Ghostty.
- **iTerm2 inline images (OSC 1337)** — iTerm2 and a handful of others.
- **No support**: Alacritty (PR in flight), Ghostty (sixel; does support kitty-graphics).
- Cites [arewesixelyet.com](https://www.arewesixelyet.com/) as the community capability matrix.

No implementation plan attached — purely capability-survey stage. **Bottom line: image display in-TUI is an acknowledged gap in opencode, not a shipped feature**, and there's no evidence it shells out to `feh`/`imgcat`/etc. either.

Sources: [Issue #92 · anomalyco/opentui](https://github.com/anomalyco/opentui/issues/92), [opencode — Intro docs](https://opencode.ai/docs/)

### B) Claude Code

Two entirely separate pipelines — don't conflate them:

1. **Multimodal understanding (Read tool)** — `Read` on an image path sends the bytes to the model as a vision input; the model reasons over pixel content and replies in text. Never touches the terminal's rendering pipeline — the image is *understood*, not *shown*.
2. **Visual rendering to the terminal** — a fundamentally different, **unimplemented** concern. A cluster of **open** feature requests confirms this gap explicitly: [#35893](https://github.com/anthropics/claude-code/issues/35893) "Support inline image viewing in conversations", [#29254](https://github.com/anthropics/claude-code/issues/29254) "Display images inline in terminal using iTerm2/Sixel protocols", [#2266](https://github.com/anthropics/claude-code/issues/2266) "Terminal Graphics Protocol Support (Sixel, Kitty, iTerm2)", [#36476](https://github.com/anthropics/claude-code/issues/36476), [#54546](https://github.com/anthropics/claude-code/issues/54546), [#6389](https://github.com/anthropics/claude-code/issues/6389), [#39024](https://github.com/anthropics/claude-code/issues/39024). Consensus: Claude Code's TUI currently **blocks** tools/skills/subagents from painting an actual visual image into the terminal — no sixel, no Kitty graphics, no iTerm2 OSC 1337 — even on a terminal that natively supports one of those protocols. Third-party plugins like [hex/claude-image-generation](https://github.com/hex/claude-image-generation) exist specifically to bolt on inline preview because the core product doesn't do it.

**Takeaway for sugar-crush**: neither "market leader" being benchmarked against actually renders pixel graphics in-terminal today. sugar-crush shipping this would be a differentiator, not table stakes it's behind on — and the underlying SugarCraft plumbing to do it already exists (see D), which is the more interesting finding.

### C) Terminal image protocol landscape

| Protocol | Envelope | Terminals | Notes |
|---|---|---|---|
| **Sixel** (DEC) | `ESC P q … ESC \` (DCS), 6-bit vertical-stripe raster, palette-indexed | xterm, mlterm, foot, WezTerm, iTerm2 ≥3.3, Konsole ≥22.04, Windows Terminal ≥1.23, VS Code ≥1.80 | Oldest, most broadly implemented; needs dithering to a small palette; no native alpha. |
| **iTerm2 inline images** | `OSC 1337; File=inline=1;size=N:<base64> BEL` | iTerm2, WezTerm, mintty, Konsole (partial) | Wraps a whole pre-encoded PNG/JPEG file — simplest to implement, needs a real image encoder. |
| **Kitty graphics protocol** | `APC _G<key=val,...>;<base64> ESC \`, optionally chunked | kitty, Ghostty, WezTerm | Richest: virtual placements (transmit once, place many via `a=p`), z-index layering, zlib compression, animation frames. |
| **Half-block (▀)** | Plain UTF-8 + 24-bit/256-color SGR | Universal | Two vertical pixels per cell — lives *inside* the text cell grid, unlike the three above. |
| **Quarter-block (▘▝▖▗/░▒▓█)** | Same, denser glyph set | Universal | 2×2 sub-cell resolution — higher fidelity, fixed glyph set. |
| **chafa/viu/timg** (external) | Shell out, self-detect | Reference implementations of auto-detect-and-degrade | `chafa` supports the full ladder and is itself usable as a leaf renderer. |

**Capability auto-detection**, layered cheapest-first: (1) env vars (`KITTY_WINDOW_ID`, `TERM_PROGRAM`, `LC_TERMINAL`, `TERM` regex, `XTERM_VERSION`, `COLORTERM`) — instant, no TTY I/O, but many terminals give no positive signal this way; (2) active escape-sequence probing (**DA1** `ESC[c`, bit 4 of the reply = sixel support; **XTWINOPS** `ESC[16t`/`ESC[14t`/`ESC[18t` for cell-pixel dimensions) — needs a real TTY, short timeout (~100ms), draining stray bytes after; (3) tmux/screen passthrough (`$TMUX` set → wrap DCS/APC/OSC sequences in `\ePtmux;...\e\\`); (4) fallback ladder Kitty → iTerm2 → Sixel → chafa → half-block → quarter-block → plain ASCII-ramp — never fail to render *something*.

### D) Current SugarCraft capability (already built, mostly unwired into sugar-crush)

**This is the most important finding: the full protocol ladder from section C is already implemented in SugarCraft, spread across a few libs, and it is not yet used by `sugar-crush`.**

**`candy-flip/src/`** is an **animated GIF player**, not a general image-protocol renderer: `Decoder.php`, `Frame.php`, `Player.php` (TEA `Model`), `Renderer.php` (renders as half-block/density ANSI text — no sixel/kitty/iTerm2, text-cell only), `TickMsg.php`, `Lang.php`.

**`candy-mosaic`** is the actual general-purpose, protocol-complete image renderer (composer description: *"Image-to-cell renderer — PNG/JPEG/static GIF to terminal via Sixel, Kitty, iTerm2, or half-block Unicode fallback. Port of charmbracelet/x/mosaic."*):
- `Mosaic.php` — public facade; `Mosaic::probe()`/`::auto()` (env + DA1 + XTWINOPS detection, cached, tmux-aware), `::kitty()`/`::sixel()`/`::iterm2()`/`::halfBlock()`/`::quarterBlock()`/`::ascii()`/`::chafa()`, `::builder()`. `bestBackend()` precedence: Kitty > iTerm2 > Sixel > Chafa > HalfBlock.
- `Detect.php` — the DA1/XTWINOPS probing engine (literally implements C.2: `DA1_QUERY = "\x1b[c"`, `XTWINOP_14/16/18`, 100ms `Deadline`, stdin drain).
- `Capability.php` — immutable snapshot (`sixel`/`kitty`/`iterm2`/`halfblock`/`chafa` + `CellSize` + `inTmux`).
- `Renderer/{Renderer.php,SixelRenderer.php,KittyRenderer.php,Iterm2Renderer.php,HalfBlockRenderer.php,QuarterBlockRenderer.php,AsciiRenderer.php,ChafaRenderer.php}` — one class per protocol.
- `KittyOptions.php` — virtual placement, z-index, zlib compression.
- `ImageSource.php` — decoded-image value object (ext-gd backed), decompression-bomb guard (`MAX_PIXELS = 50_000_000`), sync/async URL loading.
- `ImageLayer.php` + `PlacedImage.php` + `AdaptiveImage.php`/`PrecomputedImage.php` — bridges into `candy-core`'s overlay compositor.
- `Dither.php`, `Scale.php`, `CellSize.php`, `PixelGrid.php`, `TmuxPassthroughDecorator.php`, `DiskCache.php`, `AnimationDriver.php`/`Animation.php`/`FrameTickMsg.php`, `AsyncRenderer.php`/`SyncAsyncRenderer.php`.

**`candy-core/src/ImageOverlay.php`** — the compositor that makes pixel-graphics protocols (out-of-band escape blobs, not text-grid-shaped) safe inside SugarCraft's line/cell-diff `Renderer`. Mechanism: a widget drops a 1-cell Private-Use-Area marker (`U+E000..U+F8FF`) at the top-left of the box it wants an image in; after the text frame is composed, `ImageOverlay::resolve($frame, $images)` walks it, turns markers into `(row, col)` paint instructions, blanks the marker cell; `ImageOverlay::paint($paints)` emits cursor-positioned bytes as an additive layer. **Already wired into the runtime**: `candy-core/src/Program.php` (lines ~874–899) calls `ImageOverlay::resolve()`/`::paint()`/`::signature()`/`::coveredRows()` every frame — any SugarCraft `Model` (including sugar-crush's `Chat`) that returns a `View` with an `images` array gets pixel-graphics painting **for free**, no extra runtime plumbing needed.

**`candy-palette`** — terminal capability detection for *color* (not image protocol): `Palette::detect()`/`Profile` enum, `Probe`/`Probe\TerminalProbe`. `candy-mosaic`'s `Mosaic::autoFromPalette()` fallback consumes this when its own DA1 probe throws (non-TTY, CI, daemon). sugar-crush *does* pull in `candy-palette` — but only transitively via `vendor/sugarcraft/candy-palette/`, for text color, not image protocol selection.

**`sugar-charts/src/Picture/{Picture.php,Protocol.php,Sixel.php}`** — a **second, independent, simpler** sixel/kitty/iTerm2 encoder scoped to chart output. `Picture::detect()` does its own lightweight `TERM_PROGRAM`/`TERM` string-matching (no DA1/XTWINOPS query, no caching). This duplicates real functionality candy-mosaic already has in a more complete form — worth flagging as drift between two libs rather than one canonical image-protocol lib.

**`sugar-reel`** — full **video** player (mp4/gif/avi/webm via ffmpeg/ffprobe, pure-PHP GIF fallback) that also implements the full protocol ladder independently (`Render/{FrameRenderer,GraphicsRenderer,HalfBlockRenderer,QuarterBlockRenderer,AsciiRenderer,RendererFactory,AutoMode}.php`, `Decode/{Decoder,DecoderFactory,FfmpegDecoder,GifDecoder,RgbFrame}.php`). Its `Render/GraphicsRenderer.php` is a *third* independent sixel/kitty/iTerm2 implementation.

**`sugar-gallery/src/PosterCard.php`** demonstrates the full working pattern end-to-end: it renders `posterImage` bytes (produced by candy-mosaic) via `ImageOverlay::markerBlock($id, w, h)` in its inline-cell body, expecting the hosting `Program` to paint them. This is the template sugar-crush should copy.

**What sugar-crush currently has**: `sugar-crush/composer.json` requires `candy-buffer`, `candy-core`, `candy-sprinkles`, `candy-shine`, `candy-fuzzy`, `sugar-veil` — **no `candy-mosaic`, no `sugar-reel`, no `sugar-charts`**. No mosaic/reel/picture/palette usage in `sugar-crush/src/` except `candy-palette` transitively (color only). `sugar-crush/src/Palette/PaletteState.php`/`PaletteAction.php` are the **command palette** UI feature — a naming coincidence, not color/image. `sugar-crush/src/ToolResult.php` and `sugar-crush/src/Tools/ToolResult.php` (two parallel `ToolResult` types already exist — another minor drift point) carry only a plain string `result`/`content` field with no concept of an image/binary attachment — a tool that returns e.g. a screenshot path today can only surface as a text string in chat, with **zero rendering path** to candy-mosaic or the already-wired `ImageOverlay` compositor.

### E) Recommendations for wiring into sugar-crush

**E1 — Add `candy-mosaic` as a direct dependency and give `ToolResult` an image/attachment slot.**
*What*: add `"sugarcraft/candy-mosaic": "dev-master"` to `sugar-crush/composer.json` + matching path-repo entry (`php tools/check-path-repos.php --fix`). Extend `ToolResult.php` with an optional `?string $imagePath`/`?string $imageBytes` field and a `ToolResult::withImage()` fluent constructor.
```php
// sugar-crush/src/ToolResult.php
public static function okWithImage(string $name, string $result, string $imageBytes, ?string $id = null): self
{
    return new self($name, $result, null, $id, imageBytes: $imageBytes);
}
```

**E2 — Probe once, cache, expose via `Chat`'s constructor, following `Mosaic::auto()`.**
```php
use SugarCraft\Mosaic\Mosaic;

$mosaic = Mosaic::auto();   // never throws; caches Detect::probe() result
$chat = new Chat(/* ...existing args..., */ mosaic: $mosaic);
```

**E3 — Render tool-result images through `ImageLayer` + `ImageOverlay`, reusing the exact pattern `sugar-gallery/src/PosterCard.php` already proves out.**
```php
use SugarCraft\Mosaic\ImageLayer;
use SugarCraft\Mosaic\ImageSource;

$imageLayer = new ImageLayer();   // fresh per render() call

if ($toolResult->imageBytes !== null) {
    $image = ImageSource::fromBytes($toolResult->imageBytes);
    $w = 40; $h = (int) round($w / $image->aspectRatio() / 2);
    if ($mosaic->isInline()) {
        $body .= $mosaic->render($image, $w, $h);
    } else {
        $ansi = $mosaic->render($image, $w, $h);
        $body .= $imageLayer->place($ansi, $w, $h);
    }
}
return new View($frame, images: $imageLayer->placements());
```

**E4 — Auto-fallback ladder is already implemented — just consume `Mosaic::auto()`'s precedence, don't reinvent it.** Expose the detected protocol via `Mosaic::diagnose()` in a `/doctor`-style diagnostic so a user can see why images aren't rendering.

**E5 — tmux passthrough is already handled — add an integration test, don't reimplement.** `TmuxPassthroughDecorator` already applies when `$TMUX` is set.

**E6 — Give a video-capable tool a path to `sugar-reel`/`candy-flip`**, reusing the same `View::images` plumbing via `AnimationDriver`/`FrameTickMsg`, subscribed via `candy-core`'s `subscriptions()` pump. Lower priority — sketch only.

**E7 — Resolve the `Picture` vs `Mosaic` duplication before, not after, wiring sugar-crush.** Standardize exclusively on `candy-mosaic` (more complete, capability-probed, tmux-aware); treat `sugar-charts/Picture` as chart-internal legacy, not to be pulled in.

**Suggested PR sequencing**: PR 1 = E1 (dependency + `ToolResult` field) + E2 (probe-once wiring) + a `Mosaic::diagnose()`-backed `/doctor` addition; PR 2 = E3 (actual overlay rendering) + E5 (tmux integration test); E4 needs no PR; E6/E7 are follow-on.

---

## 10. Cross-tool skill/agent/config compatibility

*(opencode + Claude Code + others) and memory/scratchpad conventions*

### 10.1 opencode (github.com/anomalyco/opencode)

**Agents** — Markdown files with YAML frontmatter, discovered from `.opencode/agents/*.md` (project) and `~/.config/opencode/agents/*.md` (global). Filename (minus `.md`) is the agent id. Frontmatter: `description`, `mode` (`primary`|`subagent`|`all`, default `all`), `model`, `temperature`, `permission` (per-tool `allow`/`deny`/`ask`, including glob-pattern bash rules, last-matching-rule-wins), `disable`. Body = system prompt. Agents can also be declared inline under `"agent"` in `opencode.json`/`opencode.jsonc`. Confirmed live in this repo: `/home/sites/sugarcraft/.opencode/opencode.jsonc` has a top-level `"permission"` block plus per-agent overrides, and `.opencode/agents/sugarcrush-orchestrator.md` uses `mode: primary` + `description:` exactly per spec.

**Plugins** — `.opencode/plugins/*.ts` (project) and `~/.config/opencode/plugins/*.ts` (global), auto-loaded at startup. A plugin is a TS/JS module exporting an async factory `({project, client, $, directory, worktree}) => ({...hooks})`. Hooks include `command.executed`, `file.edited`, `tool.execute.before/after`, `session.created/compacted/idle`, `message.updated/removed`, `shell.env`, `tui.prompt.append`/`tui.toast.show`. Also loadable as npm packages via `"plugin": ["opencode-helicone-session", "@my-org/custom-plugin"]`. Confirmed present in this repo: `.opencode/package.json`, `.opencode/plugins/{workspace-plugin.ts,worktree.ts,background-agents.ts}`.

**Skills — the key convergence finding.** opencode's skill discovery reads from four path sets, two of which are literally another tool's directories:

- `.opencode/skills/<name>/SKILL.md` (project)
- `~/.config/opencode/skills/<name>/SKILL.md` (global)
- `.claude/skills/<name>/SKILL.md` and `~/.claude/skills/<name>/SKILL.md` **(Claude Code's own directories, read directly)**
- `.agents/skills/<name>/SKILL.md` and `~/.agents/skills/<name>/SKILL.md` (a third, tool-neutral convention)

Frontmatter scoped to the strict [Agent Skills spec](https://agentskills.io) subset: `name` (required, `^[a-z0-9]+(-[a-z0-9]+)*$`), `description` (required), `license`, `compatibility`, `metadata`. **opencode already treats `.claude/skills/*/SKILL.md` as a native, first-party skill source with no conversion step** — existence proof that SKILL.md + YAML frontmatter is a workable lingua franca across tools today.

**Memory/session storage** — native session *transcripts* moved from JSON to SQLite (`~/.local/share/opencode/opencode.db`, Drizzle ORM, `Projects → Sessions → Messages → Parts`). This is chat-history persistence, not curated notes. opencode has **no native equivalent of Claude Code's CLAUDE.md-adjacent auto-memory** — the `.opencode/memory/*.md` files present in this repo are a **hand-rolled repo convention**, plain markdown, no frontmatter, referenced by convention from agent prompts.

**AGENTS.md** — project root + `~/.config/opencode/AGENTS.md` global. Known bug ([anomalyco/opencode#11534](https://github.com/anomalyco/opencode/issues/11534)): a project-scoped `AGENTS.md` is silently ignored if a global one exists.

Sources: [Agents | OpenCode](https://opencode.ai/docs/agents/), [DeepWiki: Storage and Database](https://deepwiki.com/sst/opencode/2.9-storage-and-database)

### 10.2 Claude Code (full detail)

**Subagents** — `.claude/agents/*.md` and `~/.claude/agents/*.md`, plus plugin `agents/` directories. Precedence (highest first): managed (org) settings → `--agents` CLI flag (session-only) → project → user → plugin. Project subagents found by walking **up** from cwd, scanning every `.claude/agents/` between cwd and repo root — closest wins on name collision.

Frontmatter — only `name`/`description` required; rest: `tools`, `disallowedTools`, `model` (`sonnet`/`opus`/`haiku`/`fable`/full id/`inherit`), `permissionMode`, `maxTurns`, `skills` (preloads *full* skill content at startup), `mcpServers`, `hooks`, `memory` (`user`/`project`/`local` — persistent cross-session memory scope), `background`, `effort`, `isolation` (`worktree`), `color`, `initialPrompt`.

**Notable finding:** sugar-crush's own `AgentPreset` (`src/Agents/AgentPreset.php`) already carries almost this exact field set — `name, description, tools, disallowedTools, model='inherit', permissionMode, maxTurns, skills, mcpServers, memory (MemoryScope::User/Project/Local), background, effort (Effort enum), isolation (Isolation enum), color, initialPrompt`. Its docblock says it "Mirrors charmbracelet/crush preset discovery," but the field list is Claude Code's subagent frontmatter almost verbatim — **sugar-crush has effectively already converged on Claude Code's schema at the data-model level, just without reading Claude Code's actual files.**

**Skills** — `.claude/skills/<name>/SKILL.md` (project) + `~/.claude/skills/<name>/SKILL.md` (user) + plugin skill dirs. Custom commands merged into skills: `.claude/commands/deploy.md` ≡ `.claude/skills/deploy/SKILL.md`. Frontmatter: `name`, `description`, `argument-hint`, `arguments`, `disable-model-invocation`, `user-invocable`, `allowed-tools`, `disallowed-tools`, `model`, `effort`, `context` (`fork`), `agent`, `background`, `license`, `compatibility`, `metadata`. Two compatibility tiers exist explicitly: Claude Code itself accepts **every** field above; claude.ai skill uploads / the Skills API only allow **6**: `name`, `description`, `license`, `compatibility`, `metadata`, `allowed-tools` — extra fields cause a hard packaging error. This 6-field subset is the [Agent Skills spec](https://agentskills.io) — **the same spec opencode's skill loader targets. This is the concrete convergence point.**

**MCP config** — `.mcp.json` (project), `~/.claude.json` (user/local scopes), or `claude mcp add-json`. `type`: `stdio` (default), `http` (alias `streamable-http`), `sse` (deprecated), `ws`. `CLAUDE_PROJECT_DIR` injected into a spawned stdio server's environment.

**`.claude/settings.json`** — hooks (`PreToolUse`, `PostToolUse`, `PostToolUseFailure`, `UserPromptSubmit`, `Stop`, `SessionEnd`, `SubagentStart`, etc), permissions, model, statusline. Confirmed live: `/home/sites/sugarcraft/.claude/settings.json` wires Caliber via `SessionEnd`/`PostToolUse`/`PostToolUseFailure`/`UserPromptSubmit`/`Stop` hooks.

**This session's own auto-memory architecture** (directly inspected): `/home/my/.claude/projects/-home-sites-sugarcraft/memory/` (directory name = project path, `/`→`-`). `MEMORY.md` — flat index, one bullet per topic. Per-topic files named `<prefix>_<slug>.md` (`feedback_`/`project_`/`reference_`), YAML frontmatter + markdown body, e.g.:
```yaml
---
name: stale-vendor-false-test-failures
description: Per-lib composer.lock/vendor go stale and cause false phpunit failures...
metadata:
  node_type: memory
  type: project
  originSessionId: 7c3b320c-e2f9-406c-ab46-adb466653a91
---

In this monorepo each lib has its own vendor/ + composer.lock... Related: [[feedback_phpunit_kill_pattern]].
```
Note the `[[wikilink]]`-style cross-reference syntax and `originSessionId` provenance field. Read-time staleness banner: a memory over a threshold age gets an auto-injected system-reminder that its claims may be outdated and should be verified before being asserted as fact.

This is structurally near-identical to sugar-crush's own `MemoryStore` (10.4) — both independently arrived at "Markdown + YAML frontmatter + generated flat-bullet index" — but Claude's version adds session provenance and wikilink cross-references sugar-crush's `MemoryEntry` has no field for.

### 10.3 AGENTS.md convergence and existing shim tooling

No `.skillshare` directory exists anywhere under `/home/sites/sugarcraft` — the `skillshare` skill visible in this session's tool listing is a generic, non-repo-local Claude Code skill managing/syncing skills across 50+ tools via `~/.config/skillshare/`/`.skillshare/`, target include/exclude filters, copy-vs-symlink modes. Not installed/active in this repo, but exactly the class of tool sugar-crush's own import layer should either shell out to or reimplement natively in PHP.

This repo's own `CLAUDE.md` `@`-includes `AGENTS.md` directly, and the "Context Sync" section of both files describes **Caliber** (`caliber refresh`) as the mechanism keeping `CLAUDE.md`, `.cursor/`, `.cursorrules`, `.github/copilot-instructions.md`, Codex configs derived from one shared source on every commit — an existing translation/shim layer, but only for **static instructions**, not skills, agents, or MCP config.

No importer for foreign *skill* or *agent* definitions exists in sugar-crush's own code today: `Skill.php`, `SkillLoader.php`, `SkillDiscovery.php` only look in `.sugar-crush/skills/`, `~/.sugar-crush/skills/`, `{lib}/.sugar-crush/skills/`, and `BuiltIn/` — never `.claude/skills/` or `.opencode/skills/`. `AgentPresetRegistry` takes an injected `$searchPaths` array (flexible in principle) but nothing currently points it at `.claude/agents/` or `.opencode/agents/`.

### 10.4 sugar-crush's current implementation

**`sugar-crush/src/Skills/`**
- `Skill.php` — final readonly value object. `Skill::fromFile()`/`::parse()` split frontmatter, `Symfony\Component\Yaml\Yaml::parse()`. Fields: `name, description, userInvocable, disableModelInvocation, allowedTools, disallowedTools, model, effort (default 'medium'), context (default 'thread', 'fork' supported), paths` (a sugar-crush-only extension not in either upstream spec), `content, sourcePath`.
- `SkillLoader.php` — 3-stage lazy loading explicitly modeled on Claude Code's "description always in context, full body only on invoke" behavior. `loadAll()` merges `BuiltIn` (lowest) < `~/.sugar-crush/skills/` < `{projectRoot}/.sugar-crush/skills/` (highest).
- `SkillDiscovery.php` — adds a sugar-crush-only third tier, per-lib nested skills at `{libPath}/.sugar-crush/skills/`.
- `SkillManager.php`/`SkillRegistry.php` — `findForPrompt()` does naive keyword-substring matching (materially cruder than Claude Code's model-driven relevance judgment or opencode's native `skill` tool), `getForPaths()` supports glob `**`.
- `BuiltIn/` ships 11 vendored `SKILL.md` files using the same frontmatter shape as Claude Code's SKILL.md.
- `Tui/Components/SkillsPane.php` renders enabled skills/picker as plain bullets — **no provenance/source indicator anywhere in the render path**.

**`sugar-crush/src/Agents/`**
- `AgentDefinition.php` — 6 hardcoded built-in "types," fixed prompt/tools/skills, no file-based definition.
- `AgentPreset.php` + `AgentPresetRegistry.php` — the real, extensible, file-based system: `<name>.md` + YAML frontmatter loaded from an injected `$searchPaths` array (currently never pointed at `.claude/agents/`/`.opencode/agents/`). `resolve(string $taskDescription)` does bag-of-words keyword-overlap auto-delegation — a cruder analog of Claude Code's description-based subagent delegation.
- `AgentManager.php`, `TeamManager.php`/`Team.php`/`Teammate.php`/`Mailbox.php`, `WorktreeManager.php`/`WorktreeConfig.php`/`Isolation.php` — independently-arrived-at analogs of Claude Code's agent teams and `isolation: worktree`.

**`sugar-crush/src/Memory/`** — `MemoryStore.php`+`MemoryEntry.php`: Markdown+YAML-frontmatter, partitioned by scope directory, auto-generated per-scope `MEMORY.md` index (capped 200 lines/25KB), UUID v4 filenames, closed `type` vocabulary (`pattern`/`convention`/`decision`/`preference`). Structurally near-identical to Claude Code's own auto-memory but independently built: no `originSessionId`-style provenance field, no wikilink cross-references.

**`sugar-crush/src/MCP/`** — `McpServer.php` interface, `GitMcpServer.php` (native pure-PHP git ops), `StdioMcpServer.php`/`HttpMcpServer.php`, `McpRouter.php` (per-`AgentPreset` allowlist), `McpClient.php` (fail-closed by default). The `mcpServers[].type` shape textually matches Claude Code's `.mcp.json` but no `.mcp.json`-shaped fixture exists anywhere in `sugar-crush/` to confirm field-for-field parity — a naming convention borrowed in the docblock, not verified-compatible today.

### 10.5 Recommendations

**(1) Foreign skill/agent import + provenance badges**

Add a `SkillSource` enum, thread through `Skill`, tag foreign discovery paths, render a badge in `SkillsPane.php`. Since Claude Code's SKILL.md accepts a superset of opencode's/the Agent-Skills-spec's fields, and `Skill::parse()` already defaults every optional field, **no parser change is required for skill bodies** — only source tagging and precedence.

```php
<?php
declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

/**
 * Where a Skill/AgentPreset definition was discovered on disk. Surfaced in
 * the palette/menu as a provenance badge so a user importing a foreign
 * .claude/skills or .opencode/skills tree can tell native sugar-crush
 * content apart from an imported one.
 */
enum SkillSource: string
{
    case Native = 'native';
    case Claude = 'claude';
    case Opencode = 'opencode';
    case AgentSkillsSpec = 'spec'; // bare 6-field agentskills.io SKILL.md, no tool-specific extras

    public function badge(): string
    {
        return match ($this) {
            self::Native => '',                 // no badge — the default, no visual noise
            self::Claude => '[claude]',
            self::Opencode => '[opencode]',
            self::AgentSkillsSpec => '[spec]',
        };
    }
}
```

`Skill` gains a `public SkillSource $source = SkillSource::Native` field (a pre-1.0 breaking change to the constructor, acceptable per this repo's own audit conventions).

```php
<?php
declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

/**
 * Discovers SKILL.md-shaped directories from OTHER coding-CLI tools'
 * conventions and registers them tagged with the originating SkillSource.
 * No frontmatter translation is needed -- Claude Code's SKILL.md fields are
 * a strict superset of opencode's / the agentskills.io 6-field spec, and
 * Skill::parse() already defaults every field that's absent.
 */
final class ForeignSkillDiscovery
{
    public function __construct(private readonly SkillLoader $loader = new SkillLoader()) {}

    /** @return array<string, Skill> keyed by skill name, tagged SkillSource::Claude */
    public function discoverClaude(string $projectRoot): array
    {
        $skills = [];
        foreach ([$projectRoot . '/.claude/skills', ($_SERVER['HOME'] ?? '/root') . '/.claude/skills'] as $dir) {
            foreach ($this->loader->loadFromDirectory($dir) as $name => $skill) {
                $skills[$name] = $this->tag($skill, SkillSource::Claude);
            }
        }
        return $skills;
    }

    /** @return array<string, Skill> keyed by skill name, tagged SkillSource::Opencode */
    public function discoverOpencode(string $projectRoot): array
    {
        $skills = [];
        foreach ([
            $projectRoot . '/.opencode/skills',
            ($_SERVER['HOME'] ?? '/root') . '/.config/opencode/skills',
        ] as $dir) {
            foreach ($this->loader->loadFromDirectory($dir) as $name => $skill) {
                $skills[$name] = $this->tag($skill, SkillSource::Opencode);
            }
        }
        return $skills;
    }

    private function tag(Skill $skill, SkillSource $source): Skill
    {
        return new Skill(
            name: $skill->name, description: $skill->description,
            userInvocable: $skill->userInvocable, disableModelInvocation: $skill->disableModelInvocation,
            allowedTools: $skill->allowedTools, disallowedTools: $skill->disallowedTools,
            model: $skill->model, effort: $skill->effort, context: $skill->context, paths: $skill->paths,
            content: $skill->content, sourcePath: $skill->sourcePath, source: $source,
        );
    }
}
```

Wire into `SkillLoader::loadAll()` after the existing three sources, with sugar-crush-native content always winning name collisions:

```php
public function loadAll(string $projectRoot = '.'): array
{
    $foreign = new ForeignSkillDiscovery($this);
    return [
        ...$foreign->discoverOpencode($projectRoot),
        ...$foreign->discoverClaude($projectRoot),
        ...$this->loadBuiltInSkills(),
        ...$this->loadUserSkills(),
        ...$this->loadProjectSkills($projectRoot),
    ];
}
```

For **agents**, extend `AgentPreset` with the same `SkillSource $source` field and add `ForeignAgentPresetRegistry::discover()` scanning `.claude/agents/*.md` + `~/.claude/agents/*.md` + `.opencode/agents/*.md` + `~/.config/opencode/agents/*.md`. Field mapping is nearly free given the field-alignment already documented above. The one lossy mapping: opencode's fine-grained bash-glob `permission:` rules have no equivalent granularity in `AgentPreset` — collapse into `tools`/`disallowedTools` and **log a warning that fine-grained rules were dropped** rather than silently discarding them.

`SkillsPane.php` render change:
```php
$badge = $skill->source->badge();
$badgePrefix = $badge === '' ? '' : Style::new()->foreground($skill->source->color())->render($badge . ' ');
$lines[] = $badgePrefix . Style::new()->foreground(Color::hex('#00ffaa'))->render('▸ ' . $skill->name . ' — ' . $skill->description);
```

**(2) Cross-tool memory/scratchpad convention**

sugar-crush's `MemoryStore` already writes files nearly identical in shape to Claude Code's own auto-memory. Rather than inventing a new format, add a **read-only importer** — the write direction is riskier since that directory is harness-managed:

```php
<?php
declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

use Symfony\Component\Yaml\Yaml;

final class ForeignMemoryImporter
{
    public function __construct(private readonly MemoryStore $store) {}

    /** @return int number of entries imported */
    public function importClaudeCode(string $projectRoot, ?string $claudeHome = null): int
    {
        $home = $claudeHome ?? (($_SERVER['HOME'] ?? '/root') . '/.claude');
        $slug = '-' . ltrim(str_replace('/', '-', $projectRoot), '-');
        $dir = "{$home}/projects/{$slug}/memory";

        $files = is_dir($dir) ? glob($dir . '/*.md') : [];
        $imported = 0;

        foreach ($files as $file) {
            if (basename($file) === 'MEMORY.md') { continue; } // generated index, not a source entry
            $raw = file_get_contents($file);
            if ($raw === false || !preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $raw, $m)) { continue; }
            $meta = Yaml::parse($m[1]) ?? [];
            $body = trim(substr($raw, strlen($m[0])));

            $tags = ['source:claude'];
            if (isset($meta['metadata']['originSessionId'])) {
                $tags[] = 'origin:' . $meta['metadata']['originSessionId'];
            }

            $this->store->add(
                content: ($meta['description'] ?? basename($file, '.md')) . "\n\n" . $body,
                scope: MemoryScope::Local,
                tags: $tags,
            );
            $imported++;
        }

        return $imported;
    }

    /** opencode's memory files carry no frontmatter -- import whole-file with a filename-derived title. */
    public function importOpencode(string $projectRoot): int
    {
        $dir = rtrim($projectRoot, '/') . '/.opencode/memory';
        $files = is_dir($dir) ? glob($dir . '/*.md') : [];
        $imported = 0;

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) { continue; }
            $this->store->add(
                content: "# " . basename($file, '.md') . "\n\n" . trim($content),
                scope: MemoryScope::Local,
                tags: ['source:opencode'],
            );
            $imported++;
        }

        return $imported;
    }
}
```

Trigger points: a `/memory import claude`/`/memory import opencode` chat command (parallel to `/memory add|list|clear`), or an automatic one-shot prompt the first time sugar-crush notices `~/.claude/projects/<slug>/memory/` or `.opencode/memory/` exists but hasn't been imported yet (sentinel file, e.g. `.sugar-crush/memory/.imported-claude`).

---

## 11. Other notable features from the broader landscape

### 11.1 What was surveyed

Beyond opencode and Claude Code, six tools were surveyed for features not already covered by the other sections:

**Aider** (github.com/Aider-AI/aider)
- **Repo-map**: a tree-sitter-parsed, PageRank-ranked graph of every symbol in the repo, compressed into a token budget and injected as context — lets the model "see" the whole codebase's shape without reading every file. Aider's signature differentiator.
- **Pluggable edit formats**: `editblock` (SEARCH/REPLACE), `unified diff`, `whole-file`, `patch` — chosen per-model capability.
- **Automatic git commit per change**: every accepted edit is auto-committed with an LLM-generated, conventional-commit-style message; `/undo` reverts the last auto-commit. Unconditional and unprompted — not a tool call the model chooses to make.
- **Architect mode** (`--architect`): a strong/expensive model plans in prose, a cheap/fast model turns the plan into concrete edits.
- **Lint/test auto-run + self-heal loop**: `--lint-cmd`/`--auto-lint`, `--test-cmd`/`--auto-test` run after every edit; on failure, output is fed back to the model to fix, looping until green or a retry cap.
- **Voice input, cost/budget tracking**: real-time token/cost display with prompt-caching awareness.

**Cline / Roo Code** (VS Code extensions, Roo is a Cline fork)
- **Checkpoints**: a *file-system* (not conversation) snapshot before every agent-initiated edit/tool-call, backed by a shadow git repo. `/undo`/restore reverts a single step without touching the user's actual git history.
- **Plan mode vs Act mode**: explicit toggle — Plan explores/proposes without touching files; Act executes with per-tool-call approval.
- **MCP Marketplace**: an in-app browsable/searchable catalog of MCP servers, skills, plugins with install/uninstall/enable/disable UI.
- **Cost tracking**: per-task and filterable-by-workspace/date/token/cost chat management window.

**Continue.dev**
- **`config.yaml`** unifies models, context providers, rules, prompts, custom slash commands.
- **Context providers**: `@codebase`, `@file`, `@docs`, `@terminal`, `@git` — named, typed context injectors the *user* invokes explicitly, rather than the agent deciding what to read.
- **Custom slash commands**: named prompt templates + context selectors + optional model routing.

**Goose** (block/goose)
- **Recipes**: shareable YAML files packaging a goal, required extensions/tools, structured input parameters, and ordered execution steps — version-controllable, diffable, composable (sub-recipes), runnable by teammates without re-explaining the task.
- **Session sharing over Nostr**: sessions encrypted and published to Nostr relays, producing a deeplink for import — no central server required.
- **Extensions run in-process** (Rust) with direct access to agent internal state, vs. arm's-length MCP servers.

**Codex CLI (OpenAI) / Gemini CLI (Google)**
- **Codex's two-dial security model**: `sandbox_mode` (`read-only`/`workspace-write`/`danger-full-access`) orthogonal to `approval_policy` (`untrusted`/`on-request`/`never`) — what the agent *can* do decoupled from when it *must ask*. Per-command approval IDs within a single multi-command shell invocation.
- **Codex cloud two-phase runtime**: network-enabled "setup" phase installs deps, then the agent phase runs network-isolated by default; secrets scoped to setup only.
- **Gemini CLI checkpointing**: automatic shadow-git-repo snapshot the moment a file-mutating tool is approved — pure filesystem revert, decoupled from the user's real git repo.
- **Gemini CLI sandboxing**: OS-level isolation via Docker/Podman, plus "Trusted Folders" scoping execution policy per directory.

**Amp (Sourcegraph)**
- **Subagents as context multiplication**: each subagent gets its own isolated context window/tools/thread; the parent only sees the subagent's final result — explicitly framed as keeping the main thread's context window clean.

**Warp Agent Mode**
- **Transparent, terminal-native context gathering**: the agent gathers context by literally running visible shell commands the user can see and approve — auditable because it's just terminal history.
- **Natural-language auto-detection**: typing plain English auto-switches into Agent Mode.
- **Attach a failed command as context**: one-click "debug this" pipes a command's failing output into the agent.

### 11.2 sugar-crush's current state (verified by file inspection)

sugar-crush is considerably more feature-complete than a typical CLI agent already. Confirmed present, with file paths:

| Area | Status | Evidence |
|---|---|---|
| Permission modes incl. plan mode | **Already built** | `src/Permissions/PermissionMode.php` — 6 modes: `default`, `accept-edits`, `plan`, `auto`, `dont-ask`, `bypass-permissions`; enforced by `PermissionGate.php` with a `SafetyClassifier.php` fail-closed classifier |
| Hooks (pre/post tool-use, allow/deny/modify) | **Already built** | `src/Hooks/{HookManager,HookDispatcher,HookRegistry}.php`; built-ins `AuditHook`, `ConfirmRemoveHook`, `ProtectFilesHook`, `BashEscapeDenyHook`; external `ScriptHook.php` |
| Sub-agents incl. "architect" preset | **Already built** | `src/Agents/AgentType.php`, `AgentDefinition.php` — 6 presets (coder/reviewer/debugger/**architect**/tester/devops); dispatched via `AgentWorkerPool.php` (`pcntl_fork`) |
| Multi-agent teams + isolated worktrees | **Already built** | `src/Agents/{Team,TeamManager,Teammate,TaskList,Mailbox,WorktreeManager,WorktreeConfig,PathJail}.php` |
| Multi-stage workflow orchestration (≈ Goose recipes) | **Already built** | `src/Workflows/{WorkflowBuilder,WorkflowEngine,WorkflowRegistry,TaskBuilder}.php` — sequential/parallel/pipeline/verification stages, PHP DSL or YAML, SIGINT/TERM pause-and-resume |
| Session sharing (≈ Goose Nostr sharing) | **Already built** | `src/Share/{ShareSession,ShareResult,ShareUploader}.php`, `Commands/ShareCommand.php` |
| MCP client + servers, incl. Git tools | **Already built** | `src/MCP/GitMcpServer.php` exposes `git_commits: add/commit/amend/revert/reset` etc. as **model-invocable** tools, plus `McpClient.php`, `Commands/McpAuthCommand.php` |
| Multi-provider backends | **Already built** | `src/Providers/` — OpenAI, Anthropic, Claude Code CLI, SGLang, Bedrock, Vertex, Custom, Echo |
| Token/cost tracking | **Already built** | `src/Util/TokenTracker.php` |
| Conversation-state checkpoint/rewind | **Already built, but conversation-scoped, not file-scoped** | `src/Session/EnhancedSessionStore.php` (`saveCheckpoint`/`restoreCheckpoint`/`listCheckpoints`, 100-per-session cap) + `Chat.php` `/rewind` handler. **Restores chat/model state, not file contents** — see gap below |
| LSP integration | **Already built** | `src/LSP/{LspClient,LspConnection,LspCache}.php` |
| Persistent cross-session memory | **Already built** | `src/Memory/{MemoryStore,MemoryEntry}.php` — Markdown+YAML-frontmatter, scoped project/user/agent |
| Split panes / multiplexer / agent output view | **Already built** | `src/Tui/{SplitLayout,MultiplexerSplitPane,AgentOutputPane,SessionTabs}.php` |

Confirmed **absent** (verified by grep across `src/`, zero hits):
- No `repomap`/`tree-sitter`/`ctags`/codebase-symbol-graph — `Context/ContextCompactor.php`/`InstructionFileLoader.php` handle *conversation* compaction and AGENTS.md/CLAUDE.md loading, not a static codebase-structure index.
- No automatic git-commit-per-edit hook — Git exists only as a **model-invocable MCP tool**; no deterministic post-edit auto-commit hook analogous to Aider's.
- No lint/test auto-run-and-fix hook — no `lint`/`autofix`/`test_cmd` hits anywhere in `Hooks/BuiltIn/`.
- No file-system-level checkpoint/shadow-git-snapshot (Cline/Roo/Gemini-CLI style) — only the conversation-state checkpoint above.
- No container/namespace sandboxing — `Permissions/SafetyClassifier.php` only pattern-*blocks* dangerous commands (a blocklist). The only sandbox primitive is `Agents/PathJail.php`/`Tools/PathJail.php`, a path-prefix jail, not a process/OS sandbox.
- No bang-prefix (`!cmd`) raw-shell passthrough in the input line.
- No MCP marketplace/discovery UI — `Commands/McpAuthCommand.php` handles auth for configured servers, not browsing/installing new ones.

### 11.3 Prioritized recommendations

**1. Repo-map (tree-sitter symbol graph) — HIGH priority, genuinely missing**
*Inspired by*: Aider. *What*: a `Context\RepoMap` service walking the project, parsing each file (PHP's `nikic/php-parser`/`token_get_all()` for `.php` files given this is a PHP-heavy monorepo, tree-sitter only needed for non-PHP files), extracting top-level symbols and call/reference edges, ranking files by centrality. Inject a token-budgeted slice into the system prompt or as a `RepoMap` tool.
*Why*: sugar-crush's `Grep`/`Glob`/`Read` tools require the model to already know roughly where to look — a repo-map gives structural orientation for free on turn one, cutting exploratory tool calls (matters more here since every extra round-trip is a `Runtime` loop iteration against `maxSteps`).
*PHP/ReactPHP angle*: build once per session (cache keyed on file mtimes, mirroring `EnhancedSessionStore`'s SQLite caching), computed off the event loop via `AgentWorkerPool`'s existing `pcntl_fork` machinery.

**2. Deterministic auto-commit hook — HIGH priority, closes a real gap**
*Inspired by*: Aider. *What*: `Hooks\BuiltIn\AutoCommitHook`, firing on post-tool-use for Edit/Write-class tools, staging+committing touched files with a generated conventional-commit message (reusing `MCP/GitMcpServer.php`'s primitives internally). Gate behind a `PermissionMode` (auto-commit only in `accept-edits`/`auto`, never in `plan`); map `/undo` onto `git revert`/`git reset` of that specific commit.
*Why*: git exists only as an opt-in model tool today — an unconditional post-edit commit gives a real, `git log`-visible audit trail independent of whether the model remembered to call the git tool. Distinct from and complements the existing conversation-state `/rewind`.

**3. File-system checkpoint/shadow-snapshot, distinct from the existing chat-state rewind — MEDIUM-HIGH**
*Inspired by*: Cline, Roo Code, Gemini CLI. *What*: before any file-mutating tool call executes, snapshot touched files into a shadow store (second git repo à la Gemini CLI, or content-addressed blobs in SQLite next to `EnhancedSessionStore`'s `checkpoints` table). Add a `/restore-file` (or extend `/rewind`) command reverting file contents without touching the user's real git branch.
*Why*: `EnhancedSessionStore::saveCheckpoint()`/`restoreCheckpoint()` only restores **chat/model state** — rewinding a checkpoint doesn't currently undo a bad `Edit` on disk. The most common "undo" need is "put this file back."

**4. Lint/test auto-run + self-heal loop — MEDIUM-HIGH**
*Inspired by*: Aider. *What*: a configurable post-edit hook (or a `WorkflowBuilder` preset — the existing `withVerification()` stage already expresses "task then verifier") that runs the project's lint/test command after an edit batch, and on non-zero exit feeds the failure back into the agentic loop for one more fix-and-retry pass, bounded like `maxSteps`.
*Why*: this monorepo's own conventions (PHPUnit per-lib, `php-cs-fixer`) are exactly the kind of command this would run automatically — the verifier-stage abstraction already exists (`WorkflowEngine.php`), just not wired to fire automatically after ad-hoc chat edits.

**5. Two-dial sandbox model (execution boundary × approval policy) — MEDIUM**
*Inspired by*: Codex CLI, Gemini CLI. *What*: an actual process-level execution boundary — namespace/`bubblewrap`/Docker-backed `Bash` execution — as a *separate* axis from `PermissionMode` (which today only governs *when to ask*, not *what the process can technically touch* beyond the path-prefix `PathJail`).
*Why*: `SafetyClassifier.php` is a denylist, not a sandbox; a determined or confused model can still find an unlisted way to do damage.
*PHP/ReactPHP angle*: wrap `Tools/BuiltIn/Bash.php` with an optional `bwrap`/`docker run --rm` prefix chosen by a new `SandboxMode` enum, orthogonal to `PermissionMode`. Ship opt-in since it adds a hard dependency on container/namespace tooling.

**6. Per-command granular approval within a single Bash call — LOW-MEDIUM**
*Inspired by*: Codex CLI. *What*: when a `Bash` call contains a compound command (`&&`/`;`/`|`-joined), `PermissionGate` should approve/deny sub-commands individually.
*PHP/ReactPHP angle*: light shell-tokenizing pass in `Tools/BuiltIn/Bash.php` before it reaches `PermissionGate::check()`, running `SafetyClassifier` per segment.

**7. Explicit `@`-style context providers for user-directed context injection — LOW-MEDIUM**
*Inspired by*: Continue.dev. *What*: `@file`, `@diff`, `@terminal`-style tokens the *user* types to force specific context into the prompt, independent of agent tool calls.
*PHP/ReactPHP angle*: extend `CommandParser.php`'s tokenizer (already handles quote-aware splitting for slash commands) to recognize `@token` spans, resolving synchronously since these are local reads.

**8. Bang (`!cmd`) raw-shell passthrough — LOW**
*Inspired by*: Warp's terminal-native philosophy. *What*: a line starting with `!` runs directly as a shell command, bypassing the LLM, output appended to scrollback.
*PHP/ReactPHP angle*: detect the `!` prefix in `CommandParser.php` before slash-detection, shell out via the same non-blocking `proc_open` pattern already used by `Tools/BuiltIn/Bash.php`/`McpClient.php`.

**9. MCP marketplace/discovery command — LOW**
*Inspired by*: Cline/Roo Code. *What*: a `/mcp browse` listing known/curated MCP servers with install-into-`.mcp.json` and one-keystroke enable.
*Why*: sugar-crush already has a full MCP client/server stack and per-agent-preset allowlisting — only *discovery* is missing.

**Explicitly NOT recommended (already built, confirmed above)**: architect-style planner/coder split, plan vs. act toggle, sub-agents with isolated context, reusable multi-step workflow templates, session sharing, cost/token tracking, git tooling for the model (only the *automatic, hook-driven* commit is missing, per #2).

Sources: [Aider — Agent Patterns Catalog](https://www.agentpatternscatalog.org/compositions/aider/), [Linting and testing | aider](https://aider.chat/docs/usage/lint-test.html), [Options reference | aider](https://aider.chat/docs/config/options.html), [Plan and Act Modes | cline/cline | DeepWiki](https://deepwiki.com/cline/cline/3.4-plan-and-act-modes), [Checkpoints | Roo Code Documentation](https://docs.roocode.com/features/checkpoints), [config.yaml Reference | Continue Docs](https://docs.continue.dev/reference), [Recipes | goose](https://goose-docs.ai/docs/guides/recipes/), [Session Management | block/goose | DeepWiki](https://deepwiki.com/block/goose/4.3-session-management), [Agent approvals & security | ChatGPT Learn](https://developers.openai.com/codex/agent-approvals-security), [Checkpointing | gemini-cli](https://google-gemini.github.io/gemini-cli/docs/cli/checkpointing.html), [How to use subagents in AI coding with Amp](https://medium.com/@matthewtanner91/how-to-use-subagents-in-ai-coding-with-amp-8b8418486782), [Agent Mode | Warp](https://www.warp.dev/blog/agent-mode)

---

## 12. SGLang backend

*(request parameters, API surface & tool-call parsing — MiniMax M2.7)*

### A) SGLang server API surface

**HTTP endpoints** (native + OpenAI-compatible, from `docs.sglang.io`):

| Endpoint | Purpose |
|---|---|
| `/generate` | Native low-level generation endpoint — takes `text`/`input_ids` + a `sampling_params` object (the richest surface; not OpenAI-shaped) |
| `/v1/chat/completions` | OpenAI-compatible chat endpoint |
| `/v1/completions` | OpenAI-compatible legacy completions endpoint |
| `/v1/models` | Lists loaded model(s) |
| `/v1/rerank` | Cross-encoder reranking of documents against a query |
| `/v1/score` | Token-probability scoring for specified tokens given a query/items |
| `/get_model_info` | Model config/capabilities metadata |
| `/server_info` | CLI args, token limits, memory-pool sizes the server was launched with |
| `/health` | Liveness check |
| `/health_generate` | Liveness check that actually generates one token (catches a wedged scheduler `/health` alone misses) |
| `/flush_cache` | Flushes the RadixAttention prefix-cache tree; auto-fires on `/update_weights_from_disk` |
| `/update_weights_from_disk` | Hot-swaps model weights without a restart |
| `/encode` | Text → embeddings for embedding models |
| `/classify` | Reward-model scoring/classification of text |
| `/tokenize` / `/detokenize` | Text ↔ token-id conversion |
| `/start_expert_distribution_record`, `/stop_expert_distribution_record`, `/dump_expert_distribution_record` | MoE expert-routing telemetry (relevant since MiniMax-M2.7 is MoE, 230B total / ~10B active) |
| `/parse_function_call` | Standalone post-hoc tool-call parser — hands a completed generation to the configured `--tool-call-parser` without re-running inference |

**Sampling params** (native `/generate`'s `SamplingParams`, most also work through `/v1/chat/completions` via `extra_body`):
`max_new_tokens`, `temperature`, `top_p`, `top_k`, `min_p`, `stop`/`stop_token_ids`/`stop_regex`, `frequency_penalty`, `presence_penalty`, `repetition_penalty`, `min_new_tokens`, `n`, `ignore_eos`, `skip_special_tokens`, `spaces_between_special_tokens`, `no_stop_trim`, `custom_params`. Constrained decoding: `json_schema`, `regex`, `ebnf`, `structural_tag`.

**OpenAI-route (`/v1/chat/completions`) extras**, passed via `extra_body`:
- `chat_template_kwargs` — arbitrary passthrough into the Jinja chat template, e.g. `{"enable_thinking": true}`
- `separate_reasoning` (bool, default `true`) — controls whether `reasoning_content` is split out of `content`
- `return_routed_experts`/`routed_experts_start_len` — MoE expert-routing introspection
- `lora_path` (legacy) or `model: "base:adapter-name"` — LoRA adapter selection per request (LoRA + RadixAttention prefix-cache sharing is still WIP upstream — expect reduced cache-hit rates when LoRA is in play)

**Prompt caching / RadixAttention**: no client-supplied "session id" or cache hint exists or is needed — SGLang automatically matches the longest common token prefix across all in-flight and historical requests against a shared radix tree and reuses that KV cache transparently. The only thing a client controls is whether it *helps or hurts* the hit rate: sending byte-identical, stably-ordered prefixes (system prompt, tool schema JSON) maximizes reuse.

**Streaming (SSE)**: standard OpenAI `chat.completion.chunk` shape terminated by `data: [DONE]`. Tool calls stream incrementally as `delta.tool_calls[]` entries keyed by `index`, where `function.arguments` arrives as successive string fragments concatenated per index and JSON-parsed only once complete. When `separate_reasoning` is on, reasoning tokens stream via `delta.reasoning_content` chunks separately from `delta.content`.

**Tool-call parser plugins** (`--tool-call-parser <name>`): `mistral`, `llama3`, `llama4`, `qwen` (deprecated alias `qwen25`), `qwen3_coder`, `deepseekv3`/`deepseekv31`/`deepseekv32`, `glm`/`glm45`, `gpt-oss`, `kimi_k2`, `step3`, `pythonic`, `apertus2509`, and **`minimax-m2`** — a dedicated parser for MiniMax-M2/M2.1/M2.5/M2.7's XML tool-call format (confirmed live via the MiniMax model card's own deploy guide).

**Reasoning parser** (`--reasoning-parser <name>`): MiniMax-specific value is **`minimax-append-think`**, which wraps/splits the model's thinking span into `reasoning_content` vs `content` per the `separate_reasoning` flag.

Sources: [Sampling Parameters — SGLang](https://docs.sglang.io/basic_usage/sampling_params.html), [OpenAI APIs - Completions — SGLang](https://docs.sglang.io/basic_usage/openai_api_completions.html), [SGLang Native APIs](https://docs.sglang.io/docs/basic_usage/native_api), [Tool Parser — SGLang](https://docs.sglang.io/advanced_features/tool_parser.html), [RadixAttention - SGLang](https://sgl-project-sglang-93.mintlify.app/concepts/radix-attention), [sgl-project/sglang#2141 — LoRA+RadixAttention](https://github.com/sgl-project/sglang/discussions/2141)

### B) MiniMax M2.7 specifics

**Official launch command** (from `MiniMaxAI/MiniMax-M2.7`'s own `docs/sglang_deploy_guide.md`):

```bash
python -m sglang.launch_server \
    --model-path MiniMaxAI/MiniMax-M2.7 \
    --tp-size 4 \
    --tool-call-parser minimax-m2 \
    --reasoning-parser minimax-append-think \
    --host 0.0.0.0 \
    --trust-remote-code \
    --port 8000 \
    --mem-fraction-static 0.85
```
(8-GPU variant adds `--ep-size 8` for expert parallelism and much larger context.)

- **Context**: 196K max per-sequence context length per the deploy guide.
- **Version gate**: MiniMax explicitly says to run **SGLang ≥ v0.5.4.post1** — earlier releases had M2-family compatibility bugs.
- **Chat template / special tokens**: `]~!b[]~b]system`, `]~b]user`, `]~b]ai`, `]~b]tool`, `[e~[` turn boundaries; tool definitions injected inside `<tools>` JSON-Schema blocks — happens server-side, not something sugar-crush needs to replicate.
- **Tool-call wire format — this is the load-bearing finding**: MiniMax-M2.x does **not** emit OpenAI-style `tool_calls` JSON on the wire natively. It emits a custom XML block:

  ```xml
  <minimax:tool_call>
  <invoke name="search_web">
  <parameter name="query_tag">["technology", "events"]</parameter>
  <parameter name="query_list">["\"OpenAI\" \"latest\" \"release\""]</parameter>
  </invoke>
  </minimax:tool_call>
  ```

  MiniMax's own `tool_calling_guide.md` says explicitly: *"We strongly recommend using vLLM or SGLang for parsing tool calls"* — i.e. the `--tool-call-parser minimax-m2` flag is what turns this XML into the OpenAI-shaped `message.tool_calls[]` array sugar-crush's providers already assume. **Without that flag, the raw XML lands in `message.content` as plain text instead.**
- **Known parser bug (still open)**: MiniMax-M2.1/M2.5/M2.7 truncate tool-call *arguments* that themselves contain the literal string `</parameter>` — the XML delimiter and legitimate argument content are ambiguous, so a tool argument containing that exact substring gets silently cut off mid-value. Confirmed both in vLLM's parser and reported as present in the official MiniMax API itself — not SGLang-specific, not fixable by choosing a different inference engine. Directly relevant: any sugar-crush tool whose arguments could plausibly contain the string `</parameter>` (file-write/edit tool bodies, XML/HTML/PHP template content, `.tape` files) is exposed to this truncation.
- **Reasoning-effort control**: no documented `reasoning_effort`/thinking-budget parameter exists for local SGLang serving (unlike OpenRouter's hosted `reasoning.effort` low/medium/high knob) — local deployments can produce very long, budget-consuming thinking blocks with no first-party way to cap them from the request side.

Sources: [MiniMax-M2.7 sglang_deploy_guide.md](https://huggingface.co/MiniMaxAI/MiniMax-M2.7/blob/main/docs/sglang_deploy_guide.md), [MiniMax-M2.7 tool_calling_guide.md](https://huggingface.co/MiniMaxAI/MiniMax-M2.7/blob/main/docs/tool_calling_guide.md), [MiniMax M2.5/M2.1/M2 Usage — SGLang docs](https://docs.sglang.io/basic_usage/minimax_m2.html), [vllm-project/vllm#44060](https://github.com/vllm-project/vllm/issues/44060), [MiniMax-AI/MiniMax-M2#52](https://github.com/MiniMax-AI/MiniMax-M2/issues/52), [sgl-project/sglang#15508](https://github.com/sgl-project/sglang/issues/15508)

### C) sugar-crush's current implementation

Read in full: `src/Providers/SglangProvider.php`, `CustomProvider.php`, `OpenAIProvider.php`, `ProviderInterface.php`, `CompleteRequest.php`, `CompleteResponse.php`, `ProviderFactory.php`, and `src/Backend/EngineBackend.php`. Also `.sugar-crush/config.dev.json` and `src/Tools/ToolCall.php`.

- **HTTP client**: Guzzle, constructed once per provider in `SglangProvider::openAiCompatible()` with `base_uri` trailing-slashed and relative request paths (a deliberate fix for Guzzle's RFC 3986 leading-slash base_uri-drop bug — PR #1399).
- **Request body sent today** (`SglangProvider::complete()`/`completeStream()`), verbatim:
  ```php
  $params = [
      'model' => $request->model,
      'messages' => $this->formatMessages($request->messages),
      'temperature' => $request->temperature ?? 0.7,
      'max_tokens' => $request->maxTokens ?? 4096,
  ];
  if ($request->tools !== null) {
      $params['tools'] = $this->formatTools($request->tools);
  }
  ```
  That's **the entire request surface**: `model`, `messages`, `temperature`, `max_tokens`, `tools`, plus `stream: true` when streaming. Nothing else — no `top_p`, `top_k`, `min_p`, `repetition_penalty`, `frequency_penalty`/`presence_penalty`, `stop`, `n`, `seed`, and critically **no `extra_body`**, so none of SGLang's `chat_template_kwargs`/`separate_reasoning`/constrained-decoding features are reachable at all. `CompleteRequest::$jsonSchema` exists on the DTO but **`SglangProvider` never reads it** — `supportsJsonSchema()` returns `false` and the field is silently dropped, even though SGLang natively supports `json_schema`/`regex`/`ebnf`/`structural_tag` constrained decoding.
- **Tool schema format sent**: generic OpenAI `{"type":"function","function":{...}}` shape via `formatTools()` — correct, matches what SGLang's OpenAI-compatible route expects (SGLang converts this into MiniMax's `<tools>` XML block server-side).
- **Tool-call *response* parsing — generic OpenAI-format assumption, not model-aware**: `parseResponse()` reads `message['tool_calls']` directly assuming the standard array-of-objects shape. This is only correct **if** the SGLang server was launched with `--tool-call-parser minimax-m2` — nothing in sugar-crush verifies that, documents it as a deploy requirement, or has a fallback XML parser. `.sugar-crush/config.dev.json`'s `dev-sglang` provider points at `https://skynet2.interserver.net/v1` with model `MiniMax-M2.7` — whatever launch flags that box was started with are entirely opaque to the PHP code; if it's missing `--tool-call-parser minimax-m2`, tool calls degrade to raw XML dumped into `content`, and `parseResponse()` would silently return `toolCalls: null` with garbage XML text as the "answer."
- **Streaming tool calls: not parsed at all — real functional gap.** `parseChunk()` always sets `toolCalls: null` and `completeStream()`'s inner loop filters `isset($data['choices'][0]['delta'])` before calling `parseChunk`, so a delta chunk containing only `tool_calls` (no `content`) is processed but its tool-call fragments are discarded outright. **`SglangProvider::completeStream()` cannot deliver tool calls at all** — only `complete()` (non-streaming) can. `CustomProvider` has the identical bug, byte-for-byte.
- **Reasoning is a dead field everywhere.** `CompleteResponse::$reasoning` exists but is hardcoded `reasoning: null` in *every* call site across `SglangProvider`, `CustomProvider`, `OpenAIProvider` — `reasoning_content` is never read.
- **No SGLang-specific or MiniMax-specific params passed anywhere.** Confirmed by grep: no `top_k`, `min_p`, `repetition_penalty`, `extra_body`, `chat_template_kwargs`, `separate_reasoning`, `reasoning_parser`, or `tool_call_parser` anywhere under `src/Providers/`or `src/Backend/`. `contextWindow()` hardcodes `128_000` regardless of M2.7's real 196K ceiling.
- **Routing**: `EngineBackend` sits above `ProviderInterface` and is provider-agnostic — it forks a child process per turn (`completeAsync()`) so a blocking Guzzle call never freezes the ReactPHP TUI loop, and drives the tool-execution agentic loop via `Runtime`. It has zero awareness of SGLang/MiniMax specifics; all model-aware logic would need to live inside `SglangProvider`.

### D) Concrete improvement recommendations

**D1. ~~Verify/enforce the server-side parser flags~~ — CONFIRMED 2026-08-10.**
The actual launch command for `skynet2.interserver.net`'s SGLang deployment (`v0.5.16`, Docker):

```bash
export ver=v0.5.16
docker run --gpus all --rm -p 30000:8000 \
  -v ~/.cache/huggingface:/root/.cache/huggingface \
  --ipc=host --shm-size 16g --name sglang \
  -e SGLANG_DISABLE_DEEP_GEMM=1 \
  -e SGLANG_ENABLE_JIT_DEEPGEMM=0 \
  lmsysorg/sglang:${ver} \
  sglang serve --model-path MiniMaxAI/MiniMax-M2.7 \
    --tp-size 4 \
    --tool-call-parser minimax-m2 --reasoning-parser minimax \
    --trust-remote-code \
    --grammar-backend xgrammar \
    --attention-backend flashinfer \
    --fp8-gemm-backend triton \
    --moe-runner-backend triton \
    --moe-a2a-backend none \
    --kv-cache-dtype fp8_e4m3 \
    --mem-fraction-static 0.88 \
    --cuda-graph-max-bs 64 \
    --cuda-graph-backend-prefill tc_piecewise \
    --enable-mixed-chunk \
    --max-running-requests 64 \
    --chunked-prefill-size 8192 --max-prefill-tokens 8192 \
    --prefill-max-requests 64 \
    --schedule-policy lpm \
    --schedule-conservativeness 0.3 \
    --scheduler-recv-interval 5 \
    --enable-dynamic-batch-tokenizer \
    --enable-strict-thinking \
> **SUPERSEDED 2026-08-20 — the deployment this launch command describes no longer exists.** The
> user repointed `skynet2.interserver.net` from `MiniMax-M2.7` to
> `deepseek-ai/DeepSeek-V4-Flash-0731`; `GET /v1/models` reported `max_model_len` **393216** on
> 2026-08-20, not the `196608` fixed below — and then **1048576** later the SAME DAY after a
> relaunch. All readings are recorded because the point generalises: this figure is transcribed, not
> fetched, so every copy of it in this repo is a claim about a date. **The port now uses 1048570**,
> which is `max_req_input_len` from `/server_info` — the enforced per-request INPUT ceiling — rather
> than `max_model_len`'s 1048576, which is the total input-plus-output window. See
> `SglangProvider::DEEPSEEK_V4_CONTEXT_WINDOW`'s doc-block for why the smaller, enforced figure is the
> right one for a compaction denominator. The command is preserved as the record of what was measured on
> 2026-08-10, because a figure's provenance is part of its domain — read every `196608` and every
> `MiniMax` in this section as historical. What the port now does is in `crush_code.md` and
> `docs/plans/crush_code_RESUME.md` §0-DS; the code landed in `ed57d46a`.
>
> Two claims in this section are now **wrong about the live server** rather than merely dated:
> "SGLang converts this into MiniMax's `<tools>` XML block server-side" (DeepSeek-V4's native
> emission is DSML markup — see `fe947427`), and the D8 recommendation to "use `196608` exactly"
> (that is now the MiniMax-only branch of a model-aware `contextWindow()`).

    --context-length 196608 \
    --host 0.0.0.0 --port 8000
```

**Takeaways from the confirmed config:**
- `--tool-call-parser minimax-m2` **is** set — server-side XML→JSON tool-call translation is active. D2 (streaming tool-call parsing) remains the real client-side gap; it's not masked by a server misconfiguration.
- `--reasoning-parser minimax` — **confirmed NOT an alias of `minimax-append-think`**, verified by reading SGLang's actual `python/sglang/srt/parser/reasoning_parser.py` source (`DetectorMap`, current `main` branch). `"minimax": Qwen3Detector` — and `model_type == "minimax"` is special-cased in `ReasoningParser.__init__` to force `force_reasoning=True`, so the detector starts already "in reasoning mode" and splits everything up to the first `</think>` into a real, separate `reasoning_content` field, remainder into `content`. `"minimax-append-think": MiniMaxAppendThinkDetector` is a *different, narrower* detector — it only prepends a literal `<think>` token to the first streamed chunk for well-formedness and returns the **entire** text (think tags included) as `normal_text`; it never populates `reasoning_content` at all, so reasoning stays inline in `content` as raw markup. **The current config (`minimax`) is the one that actually splits reasoning into a clean field — it's the better choice, not a downgrade.** See D3 below for a parser-agnostic client that handles both correctly, so switching between them server-side never breaks anything client-side.
- `--context-length 196608` confirms the 196K figure from part B and directly validates D8 (fix `contextWindow()`'s hardcoded `128_000`) — use `196608` exactly, not a rounded `196_000`.
- `--enable-strict-thinking` forces the model into a stricter thinking-before-acting mode not covered in the original research pass — likely improves tool-call reliability but worth watching for interaction with the `</parameter>` truncation bug (D5): longer, more deliberate thinking traces mean more tokens flowing through the same fragile XML parser.
- Scheduling/batching flags (`--schedule-policy lpm`, `--enable-dynamic-batch-tokenizer`, `--chunked-prefill-size`, `--cuda-graph-*`, MoE/attention backend selection) are server-ops concerns with no corresponding client-side action — noted for completeness, not actionable from `sugar-crush`'s side.
- The original "add a `/get_server_info` sanity-check diagnostic to fail loud on misconfiguration" idea is still worth keeping as a regression guard even though this specific deployment checks out — a future re-deploy or a second environment could silently drop the flag.

**D2. Fix streaming tool-call parsing — closes a real functional gap, not a hypothetical one.**
`SglangProvider::parseChunk()` and `CustomProvider::parseChunk()` both discard `delta.tool_calls`. Accumulation must happen across chunks:
```php
// SglangProvider.php — replace parseChunk()'s tool_calls handling
private array $streamingToolCallBuffer = [];  // index => ['id'=>?, 'name'=>?, 'arguments'=>string]

private function parseChunk(array $data): CompleteResponse
{
    $delta = $data['choices'][0]['delta'] ?? [];
    $finishReason = $data['choices'][0]['finish_reason'] ?? null;

    foreach ($delta['tool_calls'] ?? [] as $tc) {
        $idx = $tc['index'] ?? 0;
        $this->streamingToolCallBuffer[$idx]['id'] ??= $tc['id'] ?? null;
        $this->streamingToolCallBuffer[$idx]['name'] ??= $tc['function']['name'] ?? null;
        $this->streamingToolCallBuffer[$idx]['arguments'] =
            ($this->streamingToolCallBuffer[$idx]['arguments'] ?? '') . ($tc['function']['arguments'] ?? '');
    }

    $toolCalls = null;
    if ($finishReason === 'tool_calls' && $this->streamingToolCallBuffer !== []) {
        $toolCalls = array_map(
            fn($tc) => ToolCall::fromArray([
                'id' => $tc['id'] ?? '',
                'name' => $tc['name'] ?? '',
                'arguments' => json_decode($tc['arguments'] ?? '{}', true) ?? [],
            ]),
            $this->streamingToolCallBuffer
        );
        $this->streamingToolCallBuffer = [];
    }

    return new CompleteResponse(
        content: $delta['content'] ?? '',
        reasoning: $delta['reasoning_content'] ?? null,
        toolCalls: $toolCalls,
        tokensUsed: 0,
        costUsd: 0.0,
    );
}
```
Note this requires making the provider stateful for the buffer, or threading the buffer as a local in `completeStream()`'s generator loop instead of an instance property (cleaner — avoids polluting a `readonly` value-object-style class per this repo's `Mutable`/immutability convention).

**D3. Wire up `reasoning_content` end-to-end — parser-agnostic, so it's correct under either `--reasoning-parser minimax` or `minimax-append-think`.**
Per B/D1, SGLang's `minimax` reasoning parser (the currently deployed value) properly splits reasoning into a `reasoning_content` field via `Qwen3Detector` with `force_reasoning=True`; `minimax-append-think` (an older/narrower detector) never populates that field at all and instead leaves raw `<think>...</think>` markup inline in `content`. Rather than assuming one or the other, make the client defensive so a server-side parser change never silently breaks reasoning display:

```php
// CompleteResponse construction in parseResponse()/parseChunk() — replace the
// hardcoded `reasoning: null` with a parser-agnostic extractor.
private function extractReasoning(array $message): array
{
    // Case 1: server-side parser split it out already (SGLang `minimax`/Qwen3Detector,
    // or any other properly-splitting parser). Trust it directly.
    if (!empty($message['reasoning_content'])) {
        return [$message['reasoning_content'], $message['content'] ?? ''];
    }

    // Case 2: no reasoning_content field, but raw <think>...</think> markup is
    // still inline in content (SGLang `minimax-append-think`, or any parser/model
    // combination that didn't split). Strip it out client-side.
    $content = $message['content'] ?? '';
    if (preg_match('/<think>(.*?)<\/think>/s', $content, $m)) {
        $reasoning = trim($m[1]);
        $content = trim(preg_replace('/<think>.*?<\/think>/s', '', $content, 1));
        return [$reasoning, $content];
    }

    return [null, $content];
}
```
Use this in both `parseResponse()` (non-streaming) and the D2 streaming buffer (accumulate `delta.reasoning_content` the same way `function.arguments` fragments are accumulated, falling back to the `<think>` strip on the final assembled `content` once `finish_reason` arrives — mid-stream tag-stripping on partial chunks is unreliable since a `</think>` closer can straddle a chunk boundary). Have `EngineBackend`/`App` surface the result rendered dimmed/collapsed in the TUI regardless of which path produced it. Explicitly set `extra_body.separate_reasoning = true` in the request (SGLang default is already `true`, but pin it) — this only affects whether the server attempts to populate `reasoning_content` for parsers capable of it; it doesn't change `minimax-append-think`'s behavior, which is exactly why the client-side fallback in Case 2 above is still needed regardless.

**D4. Send the SGLang route extras and the missing sampling knobs.**

> **CORRECTED 2026-08-10 (during W1.A3 implementation).** This step originally
> prescribed nesting the extras under `$params['extra_body']`. That is wrong on
> the wire: `extra_body` is an OpenAI **Python SDK** client-side concept — the
> SDK splices the dict into the top level before sending, so an HTTP body that
> literally contains `{"extra_body": {...}}` is not something SGLang parses.
> Probed live against skynet2's SGLang v0.5.16 / MiniMax-M2.7:
> a top-level `"chat_template_kwargs": "NOT_A_DICT"` or `"separate_reasoning":
> "NOT_A_BOOL"` is rejected `400` by `ChatCompletionRequest`'s pydantic model
> (so both are genuine top-level fields), while the identical garbage nested
> under `extra_body` returns `200` — the nested form was silently discarded.
> Separately, there is **no** top-level `json_schema` field on that route
> (`"json_schema": 12345` sails through `200`, and the output is unconstrained
> prose); constrained decoding on the OpenAI-compatible route is reachable only
> via `response_format`, verified live to actually bind output to the schema.

```php
$params['separate_reasoning'] = true;
if ($request->extraTemplateKwargs) {  // new optional CompleteRequest field
    $params['chat_template_kwargs'] = $request->extraTemplateKwargs;
}
if ($request->jsonSchema !== null) {
    // finally actually use the DTO field that already exists but is dropped today
    $params['response_format'] = [
        'type' => 'json_schema',
        'json_schema' => [
            'name' => 'response',
            // decoded object, not a JSON string - a caller holding pre-encoded
            // JSON is decoded back with JSON_THROW_ON_ERROR so a malformed
            // schema fails loudly instead of silently disabling constraints
            'schema' => is_string($request->jsonSchema)
                ? json_decode($request->jsonSchema, true, 512, JSON_THROW_ON_ERROR)
                : $request->jsonSchema,
        ],
    ];
}
```

`CustomProvider::complete()`/`completeStream()` still send the ineffective
nested `extra_body.separate_reasoning` — same bug, out of D4's scope, worth
folding into whichever step next touches that provider.
Also add `top_p`, `top_k`, `min_p`, `repetition_penalty`, `stop` as optional `CompleteRequest` fields — MiniMax-M2.7 in particular benefits from a nonzero `repetition_penalty`/`min_p` to control repetition in long agentic tool-loop transcripts, exactly sugar-crush's usage pattern (`EngineBackend`'s bounded 8-step tool loop). Turns `supportsJsonSchema()` from a hardcoded `false` into something actually backed by real constrained decoding.

**D5. Guard against the `</parameter>` XML-delimiter truncation bug (B/D2's known-bug finding).**
Since this is a MiniMax-side/protocol-level bug present even in the official API, defend where possible: (1) flag any sugar-crush-authored tool whose result content could plausibly contain the literal substring `</parameter>` (file-read/Edit/Write tool results, `.tape`/HTML/XML content) as higher-risk for this failure mode — not preventable client-side, but detectable; (2) add a sanity check in `parseResponse()`/`parseChunk()` — if a tool call's `arguments` value fails `json_decode` and the raw string ends mid-value without a closing structure, log a distinguishable "possible MiniMax XML-delimiter truncation" warning rather than silently defaulting to `[]` as today's `json_decode(...) ?? []` does. Turns an invisible data-corruption failure mode into an observable one.

**D6. Pluggable tool-call-parser abstraction, mirroring SGLang's own `--tool-call-parser` concept.**
`SglangProvider`/`CustomProvider`/`OpenAIProvider` all inline-duplicate byte-identical `parseResponse()`/`formatTools()` bodies. Extract a small strategy interface so a future non-parser-flag deployment (or a raw-XML fallback per D5) is a drop-in:
```php
// src/Providers/ToolCallParser/ToolCallParserInterface.php
interface ToolCallParserInterface
{
    /** @return array<ToolCall>|null */
    public function parse(array $message): ?array;
}

// src/Providers/ToolCallParser/OpenAiArrayToolCallParser.php
// current parseResponse() tool_calls logic, extracted verbatim — the default for
// any server launched with a real --tool-call-parser flag (sglang/vLLM/OpenAI).

// src/Providers/ToolCallParser/MinimaxXmlFallbackToolCallParser.php
// last-resort regex parser per MiniMax's own tool_calling_guide.md recommendation:
// <minimax:tool_call>(.*?)</minimax:tool_call>, <invoke name="(.*?)">, <parameter name="(.*?)">(.*?)</parameter>
// only exercised if $message['content'] contains a literal '<minimax:tool_call>'
// and $message['tool_calls'] is absent -- i.e. the server-side parser flag was
// missing, so this degrades gracefully instead of losing the tool call entirely.
```
`ProviderFactory::createSglang()` picks the parser based on config (`toolCallParser: 'openai' | 'minimax-xml-fallback'`, defaulting to `'openai'`), giving sugar-crush the same pluggability SGLang itself exposes via its CLI flag — but as a client-side safety net rather than a requirement that the ops side got the flag right.

**D7. Stabilize prompt structure for RadixAttention prefix-cache reuse.**
`SglangProvider::formatMessages()` re-serializes the full message history every turn — worth confirming the resulting JSON key order and tool-schema serialization is **byte-stable** across turns of the same conversation. SGLang's RadixAttention cache hit rate depends entirely on exact token-prefix match; any nondeterminism in `json_encode()` key ordering silently defeats caching and costs a full prefill on every turn instead of incremental decode-only cost. No code change needed today (PHP's `json_encode` on ordered arrays is stable), but worth a regression test — a golden-byte snapshot test asserting the serialized system-prompt+tools prefix is identical across two `CompleteRequest`s built from the same tool list, per this repo's own snapshot-testing convention.

**D8. Update `contextWindow()` to reflect M2.7's real 196K ceiling** (currently hardcoded `128_000` in `SglangProvider.php:80`) so `EngineBackend`/`App` context-window-budget logic doesn't truncate history prematurely or undercount how much room the 8-step tool loop actually has before hitting the model's real limit.

---

*End of research dossier. 12 agents, live web research + direct source reading across the SugarCraft monorepo. Compiled 2026-08-10.*
