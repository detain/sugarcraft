# Second-Stage Ecosystem Intelligence Report: charmbracelet/crush

## Repository Overview

**charmbracelet/crush** was a terminal-based AI coding assistant (Go, 24.7k stars, FSL-1.1-MIT license) that connected LLMs to development tools via multi-model support, LSP integration, MCP extensibility, and session-based workflow management. The project was **archived/sunset in March 2026** to focus development efforts elsewhere within the Charm ecosystem. The archive date (March 2026) combined with ongoing active development (137 releases, last push April 4, 2026) suggests strategic repositioning rather than technical failure.

**Key architectural facts:**
- Built on Charm ecosystem: Bubble Tea v2 (TUI), Lipgloss v2 (styling), Glamour v2 (markdown), Fantasy (LLM abstraction), Catwalk (provider database)
- Core components: `internal/agent` (LLM loop), `internal/shell` (bash execution), `internal/lsp` (Language Server Protocol), `internal/ui` (Bubble Tea TUI), `internal/pubsub` (event bus), `internal/skills` (agent skills system)
- Persistence: SQLite via sqlc with WAL mode
- Transports: stdio, HTTP, SSE for MCP; Unix socket or TCP for server-client architecture

**Note on context:** Since Crush is sunset, this analysis treats it as a completed engineering experiment—valuable for observing what was attempted, what succeeded, what failed, and what remains unresolved at the moment of archival.

---

## Existing SugarCraft Mapping

From the first-pass analysis (`repo_map/charmbracelet_crush.md`), SugarCraft maps to:

| Crush Component | SugarCraft Lib | Status |
|---|---|---|
| `internal/shell` | `candy-shell` | Exists |
| `internal/lsp` | `candy-core` (future) | Not implemented |
| `internal/agent/tools` | `sugar-bits`, `sugar-prompt` | Partial |
| `internal/ui` | `candy-shine`, `sugar-charts` | Partial |
| `internal/skills` | (none yet) | Not implemented |
| `internal/hooks` | (none yet) | Not implemented |
| `internal/config` | `SugarCraft\Core` | Pattern only |
| `internal/pubsub` | `SugarCraft\Core` | Pattern only |

**Critical gaps for AI integration:** SugarCraft currently lacks agent infrastructure, MCP client implementation, LSP integration, and workspace/session management—the core capabilities that made Crush a viable AI coding assistant.

---

## Previously Identified Gaps

The first-pass analysis correctly identified:

1. **No AI/LLM agent infrastructure** — Crush's `internal/agent` with its Coordinator, SessionAgent, and tool dispatch system has no SugarCraft equivalent
2. **No MCP client** — Crush's `modelcontextprotocol/go-sdk` integration for bridging LLMs with external tools is absent
3. **No LSP integration** — Crush's sophisticated `internal/lsp` package providing real code intelligence is missing
4. **No session/workspace management** — Crush's SQLite-backed session persistence with per-project context is not present
5. **No agent skills system** — Crush's SKILL.md-based extensibility has no SugarCraft counterpart

These gaps represent the fundamental architectural delta between a TUI component library and an AI-assisted coding tool.

---

## High-Signal Open Issues

### Issue #2368 — Bash Tool Safelist Bypass (SECURITY CRITICAL)

**Finding:** The "safe read-only" command bypass (`ls`, `echo`, `git status`) is a simple string prefix match. Commands like `echo "data" > file.txt` bypass permission prompts entirely, allowing silent file writes. Combined with command injection, an LLM can be tricked into writing arbitrary files.

**Impact:** A malicious web server could inject commands that write SSH keys to `~/.ssh/authorized_keys` through the unfiltered `echo` safe-list.

**Root cause:** The safelist check has no awareness of shell redirects, pipes, or compound commands. `echo` on the safelist combined with `> file` silently writes files.

**Status:** Open as of archival. No fix merged.

**Direct Risk to SugarCraft:** If SugarCraft ever implements a bash/shell tool with permission prompts, the same class of bypass vulnerability exists. The defensive lesson is that "safe command" lists must analyze the full command line, not just the leading command word.

---

### Issue #497 — "Allow for Session" Grants All Bash Commands (CRITICAL)

**Finding:** When a user grants "Allow for session" for bash, any subsequent bash command is auto-approved—not just the one initially approved. The permission granularity is session-wide, not per-command.

**Expected behavior (from issue):** `bash XXX` and `bash YYY` should be separate permission domains. Claude Code implements per-command-plus-flags permission granularity.

**Status:** Open as of archival. No fix merged.

**Direct Risk to SugarCraft:** The permission model decision—session-level vs. per-invocation vs. per-command-pattern—directly affects how dangerous autonomous tool use becomes. SugarCraft's shell tool (candy-shell) would need a similarly robust model.

---

### Issue #2130 — Infinite Tool Call Loops (REGRESSION)

**Finding:** Agent gets into infinite loops calling the same tool repeatedly. The agent loop had no step limit—it only stopped when context window was nearly full. This was fixed by PR #2214 with loop detection (same tool called >5 times in last 10 steps) and a max steps limit.

**Status:** Fixed pre-archival.

**Signal:** Loop detection was a critical missing safeguard that caused real user pain. The fix required adding both a step counter and repeated-tool-call detection.

**Direct Risk to SugarCraft:** Any autonomous agent loop in SugarCraft needs loop detection and step limits. This is a fundamental requirement for AI tool use, not an optional feature.

---

### Issue #2251 — Gemini Vertex Bad Requests (PROVIDER INTEROP)

**Finding:** Intermittent 400 errors from Google Vertex AI with tool calls: "Please ensure that the number of function response parts is equal to the number of function call parts." Affects Gemini 3 Flash/Pro through Vertex. Appears related to tool call serialization differences.

**Status:** Open as of archival. Maintainer could not reproduce consistently.

**Direct Risk to SugarCraft:** Provider-specific quirks in tool call serialization will affect any LLM integration. The fantasy library (Charm's LLM abstraction) handles many of these, but edge cases remain.

---

### Issue #808 — Tools Frequently Fail to Execute Multiple Times (RELIABILITY)

**Finding:** When a model finds a solution, it sometimes takes up to 4 tries to actually run a tool like Edit. Cross-platform issue (Windows, Mac, Linux) with various models.

**Status:** Open as of archival.

**Direct Risk to SugarCraft:** Retry logic and error recovery for tool execution failures is a real-world concern. The issue suggests models may need clearer error feedback to self-correct.

---

### Issue #2903 — Database Corruption Under Sandboxing (REGRESSION)

**Finding:** SQLite database corrupts under sandbox environments (nono with Landlock). Root cause: WAL mode requires `mmap` and `fcntl` advisory locks that sandboxes restrict. The failure is intermittent because WAL checkpoints only trigger every ~1000 pages.

**Root cause:** SQLite in WAL mode needs file re-opening for checkpoints. Sandboxes that allow the initial `.crush/` directory but restrict `/tmp` (where SQLite writes temp files) cause CANTOPEN errors.

**Status:** Open as of archival. Workaround suggested but no fix merged.

**Signal:** Using SQLite WAL mode with sandboxing is fundamentally problematic due to the hidden temp file requirements.

**Direct Risk to SugarCraft:** If SugarCraft uses SQLite, WAL mode, or any database with temp file requirements, sandboxing will cause intermittent corruption.

---

### Issue #1526 — Focus Trap After Project Init (UI)

**Finding:** Clicking in the terminal during project initialization causes focus to move to hidden UI elements behind the splash screen. The terminal appears frozen because keyboard input goes to the wrong component.

**Root cause:** The code remembered to block mouse clicks during "Onboarding" but forgot to block them during "Project Init."

**Status:** Fixed pre-archival (PR #1561).

**Direct Risk to SugarCraft:** The TUI focus management pattern—blocking input to hidden components during modal states—is a common Bubble Tea pitfall. SugarCraft's candy-shine should handle this explicitly.

---

### Issue #1449 — Unaligned 64-bit Atomic on Windows x86 (CROSS-PLATFORM)

**Finding:** `BackgroundShellManager.Start()` panics with "unaligned 64-bit atomic operation" on Windows x86 due to `int64` field not being first in struct.

**Root cause:** Go's `sync/atomic` package requires 64-bit fields to be first in struct on 32-bit platforms. Even reordering fields didn't fully resolve it—the fix required additional attention.

**Status:** Reopened (original fix didn't fully resolve). No final fix in the data.

**Direct Risk to SugarCraft:** Any struct with atomic fields used on 32-bit platforms (Windows x86) must have the atomic field first. This is a subtle but critical cross-platform concern.

---

## Important Closed Issues

### Issue #472 — Compacting Session Hangs (SESSION MANAGEMENT)

**Finding:** Session compaction/summarization hangs indefinitely. Two root causes:
1. Bug in `internal/llm/agent/agent.go` line 814: uses undefined variable `err` instead of `r.Error`, silently swallowing errors
2. No timeout on compaction—uses `context.WithCancel()` instead of `context.WithTimeout()`

**Impact:** Users in long sessions with slow models (Ollama/Qwen3) see compaction appear frozen with no feedback.

**Workaround:** Hotfix in PR #961 addressed error handling, added timeout, and added progress updates. The undefined variable bug is a classic example of a silent failure that made debugging impossible.

**Status:** Closed (hotfixed in PR #961, merged pre-archival).

**Signal:** Error handling that silently swallows errors makes production debugging nearly impossible. The `err` vs `r.Error` typo is a textbook example of why error handling requires explicit care.

---

### Issue #727 — Compacting Session Hangs (OLLAMA)

**Finding:** Compaction hangs with Ollama/Qwen3 0.6b. Model context window (2048 tokens) is too small for summarization to work. The compaction triggers when context is nearly full, but the model can't summarize that much context.

**Signal:** Auto-compaction at the wrong moment (context nearly full, model too small) causes permanent hangs. The issue suggests the compaction trigger should account for model context window size.

**Workaround:** Switch to a model with larger context, then compact.

---

### Issue #767 — Crash with Streaming Output (GOOSE COMPAT)

**Finding:** Crush crashes during Gemini processing but continues streaming output to terminal. Session appears stuck. Related to Goose provider compatibility quirks.

**Status:** Fixed pre-archival (PR #779).

---

### Issue #975 — Repeated HTTP Requests (LOOPING)

**Finding:** Title generation causes repeated HTTP requests to Gemini API with empty responses. The loop didn't use the main loop context, so canceling didn't stop it. Resulted in cost spike for user.

**Root cause:** 
- Title generation set `maxOutputTokens` too low for Gemini, causing empty responses
- The retry loop didn't handle empty responses gracefully
- Context cancellation didn't propagate to the retry loop

**Status:** Fixed pre-archival (v0.7.6).

**Signal:** Provider-specific parameter quirks (maxOutputTokens for Gemini) can cause silent failures that loop infinitely. Retry loops must handle edge cases for each provider.

---

### Issue #523 — Slash Commands / Programmable Prompts (FEATURE)

**Finding:** Request for Claude Code-style `/commands` to define reusable prompt templates. 17 reactions. Community wanted format compatibility with Claude Code's command files.

**Outcome:** Implemented via PR #1377. Command palette became the UX pattern. Eventually renamed `@` for file attachments.

**Maintainer response:** "What we need in the industry is an open format/specification for features like this, so tooling only has to support that standard."

**Strategic note:** The drive toward Claude Code format compatibility was strong. SugarCraft could consider adopting the same standard for skills/commands to ease migration.

---

### Issue #492 — Improved Session Management (FEATURE)

**Finding:** Request for sort by latest message (not start time), rename sessions, delete sessions, temporary sessions.

**Outcome:** Partially implemented pre-archival. Session deletion and renaming available as of v0.39.0.

---

## Recurring Pain Points

### 1. Performance Regressions in v0.40.0 (SIGNAL: 3+ RELATED ISSUES)

Multiple issues (#2223, #2241, #2240) opened within days of v0.40.0 release with the same symptom: "slow", "high CPU", "context clearing".

**Root causes identified:**
- LSP Manager refactoring added a global `sync.Mutex` replacing lock-free `csync.Map`
- Fantasy library upgrade from v0.7.0 to v0.7.1
- Two new parallel MCP tools added
- Tool result handling from up to 5 concurrent goroutines publishing to the same pubsub topic without synchronization
- Sequential tools blocking ALL dispatch (including parallel ones)

**Pattern:** Adding concurrency without proper synchronization caused cascading bottlenecks. The pubsub broker had a 2-second timeout and dropped messages, causing UI state to desync.

**Lesson:** Concurrency bugs in agent loops cause "invisible" failures—tools complete but UI doesn't update. The fix required both proper locking (sync.RWMutex) and proper async handling (dispatching to separate goroutines, not blocking the coordinator).

---

### 2. Global Regex Cache Unbounded Growth (MEMORY)

PR #2161 fixed unbounded growth in `searchRegexCache` and `globRegexCache` (global maps with no eviction). Every unique search/glob pattern compiled during agent tool use was cached permanently. In long-running sessions, memory grew linearly with unique patterns.

**Lesson:** Global caches in agent loops need eviction policies. Session resets should clear caches.

---

### 3. Gitignore Pattern Matching Caused 80% CPU (PERFORMANCE)

PR #2199 found that `sabhiram/go-gitignore` (which compiles each pattern to regex) dominated CPU in large monorepos. The fix replaced it with `go-git/go-git` native glob matching plus two-level caching.

**Lesson:** For large codebases, every regex compilation must be cached and every directory walk must be gitignore-filtered. Native glob is faster than regex-based matching for simple patterns.

---

### 4. LSP Root Marker Detection Walked Millions of Files (PERFORMANCE)

PR #2316 found that `hasRootMarkers()` used `fsext.Glob()` to recursively walk the entire working directory (with no gitignore filtering, no node_modules skipping, symlink following) every time an LSP server check was triggered.

**Fix:** Replace recursive walk with `filepath.Glob(filepath.Join(dir, pattern))` since root markers are always simple filenames at the project root.

**Lesson:** File system operations in agent hot paths must be bounded and cached. Recursive walks are death for monorepos.

---

### 5. Prompt Re-Processing Regression v0.11.0 (CACHE)

Issue #1249 found that starting with v0.11.0, prompts were re-processed on every completion for local models (llama.cpp), causing 48x slowdown. The cause was tool ordering not being preserved, breaking cache keys.

**Fix:** PR #1271 preserved tool ordering.

**Lesson:** Cache invalidation is subtle. Non-deterministic ordering of tool descriptions (maps in Go) will bust caches unpredictably.

---

### 6. Timer Leak in setupSubscriber (RESOURCE)

PR #2147 fixed a timer leak under high load. The `time.After` in `setupSubscriber` wasn't properly cleaned up, causing resource exhaustion.

**Lesson:** `time.After` in long-lived goroutines leaks memory. Use `time.NewTimer` with proper cleanup or resetable timers.

---

## Frequently Requested Features

### 1. Plan/Build Mode Separation (28 REACTIONS)

Issue #1734 requested explicit Plan mode (read-only, no file changes) separate from Build mode (full autonomy). This is the Claude Code shift-tab pattern.

**Maintainer response:** Mixed opinions. Some wanted enforced restrictions; others noted that models already respect conversational instructions not to modify files. The permission system and plan mode serve different purposes.

**Outcome:** PR #2019 implemented plan mode with readonly permission enforcement. Not clear if fully merged by archival.

**Strategic for SugarCraft:** Explicit plan/build mode separation is a high-value feature for safety-critical workflows. It's a simple boolean + separate system prompt + restricted tool set.

---

### 2. Subagents (45 REACTIONS)

Issue #431 requested multi-agent support where different agents have different models, tools, and prompts. This is the Claude Code feature that allows task-specific agents.

**Status:** PR #914 was a step toward subagents (configurable models per agent type), but full subagent definition (new agents, custom tools, custom prompts) was not implemented pre-archival.

**Strategic for SugarCraft:** Subagents require solving: agent coordination, shared context between agents, per-agent resource isolation, and inter-agent messaging.

---

### 3. HTTP/SDK Interface (PLANNED)

Issue #976 requested an HTTP interface to drive the agent from external tools, rather than only through the TTY.

**Outcome:** The server-client architecture (PR #2455, merged March 2026) added a REST API over Unix socket or TCP with OpenAPI docs. This enables programmatic access to the agent loop.

**Strategic for SugarCraft:** Having an HTTP/SDK interface enables AI integration without TUI coupling. SugarCraft should consider whether its AI components should expose an HTTP API or be library-only.

---

### 4. Hooks System (PLANNED)

Issue #1336 requested Claude Code-style hooks for notifications and customization at various stages of execution.

**Outcome:** PR #2598 (v0.63.0) introduced `PreToolUse` hook. The hook system is extensible and compatible with Claude Code hooks format.

**Strategic for SugarCraft:** The hooks system enables users to customize behavior without modifying the core codebase. This is essential for enterprise adoption.

---

### 5. TreeSitter Integration (DISCUSSION)

Discussion #953 explored using TreeSitter for AST-aware tools (better context for LLM, safer edits, refactoring).

**Ideas discussed:**
- Context packer: feed only relevant AST nodes, not whole files
- AST-aware find-refs/go-to-def
- Preflight edit guardrails (blast radius scoring)
- Postflight verification (run tests, lint)
- Semantic chunking for RAG

**Status:** Discussion only, not implemented pre-archival.

**Strategic for SugarCraft:** TreeSitter integration is a significant undertaking but provides the highest-fidelity code understanding. SugarCraft could consider it for future LSP-equivalent functionality.

---

### 6. TreeSitter Integration with Embeddings (DISCUSSION)

Discussion #953 mentioned that RooCode/KiloCode use TreeSitter + embeddings stored in Qdrant for codebase indexing.

**Strategic for SugarCraft:** For large codebase RAG, AST-based chunking (functions, types, modules) is superior to line-based chunking. This is a future opportunity for SugarCraft.

---

### 7. Context Status Injection + new_session Tool (DISCUSSION)

Discussion #2332 proposed:
- Inject system message showing remaining context window when compaction is set to `llm` mode
- `new_session` tool for AI-initiated session branching with custom summaries

**Strategic for SugarCraft:** Long-running AI workflows need explicit context budget management. The "LLM decides when to start a new session" pattern is more robust than pure auto-compaction.

---

### 8. Web UI for Configuration Management (ISSUE #2625)

Request for optional Web UI for configuration (provider selection, templates, validation, import/export, migration).

**Status:** Open as of archival.

**Strategic for SugarCraft:** Configuration complexity grows with extensibility. A web-based config UI reduces friction for non-technical users.

---

### 9. Chat Export/Persistence (ISSUE #1965)

Request to export sessions to Markdown or JSON for knowledge retention and auditability.

**Status:** Open as of archival.

**Strategic for SugarCraft:** AI workflows produce valuable artifacts. Export to Markdown preserves knowledge beyond the tool's lifecycle.

---

### 10. Roles/System Prompts for crush run (DISCUSSION #2404)

Request to add system prompt customization to `crush run` (non-interactive mode), as a replacement for `mods`.

**Maintainer response:** Acknowledged as important. No ETA.

---

## Important PRs

### PR #2455 — Server-Client Architecture (18,070 additions, MERGED)

This was the most significant architectural change in Crush's lifetime. It refactored Crush from a monolithic TUI into a server/client architecture with a REST API.

**Key changes:**
- `Workspace` abstraction unifying local and remote modes
- REST API over Unix socket (default) or TCP
- OpenAPI/Swagger generation
- `crush server` for headless operation
- `CRUSH_CLIENT_SERVER=1` flag for new mode

**Strategic lesson:** Converting a monolithic app to a client/server architecture is expensive. Starting with a service boundary from the beginning is far easier.

---

### PR #1652 — UI Refactor (18,344 additions, MERGED)

Complete reimplementation of the Bubble Tea UI behind `CRUSH_NEW_UI` feature flag. Key goal: simplify frontend architecture and make it sustainable long-term.

**Strategic lesson:** The original TUI architecture required significant rework. SugarCraft's candy-shine should invest in clean component boundaries from day one.

---

### PR #2373 — CLI Session Management (728 additions, MERGED)

Added `crush session list/show/last/delete/rename` subcommands with JSON output for programmatic access.

**Strategic lesson:** CLI access to session state is essential for non-interactive workflows and scripting.

---

### PR #2214 — Loop Detection (FIXED INFINITE LOOPS)

Added max steps limit and loop detection (same tool called >5 times in last 10 steps).

**Strategic lesson:** Infinite loop protection is non-negotiable for autonomous agents.

---

### PR #2147 — Timer Leak Fix

Fixed timer leak in `setupSubscriber` that caused resource exhaustion under high load.

---

### PR #2161 — Regex Cache Fix

Cleared regex caches on new session to prevent unbounded growth.

---

### PR #2316 — LSP Root Marker Fix

Replaced recursive directory walk with `filepath.Glob` for root marker detection.

---

### PR #2199 — Gitignore Performance Fix

Replaced `sabhiram/go-gitignore` with `go-git/go-git` native glob matching + caching.

---

### PR #1812 — Memory Reduction (50% reduction on some platforms)

Multiple optimizations:
- Cached LSP diagnostic counts
- Regex compilation at package level
- `maps.Copy` instead of `maps.Collect`
- Platform-specific SQLite driver selection (modernc.org/sqlite vs ncruces/go-sqlite3)

---

### PR #1271 — Tool Ordering for Cache

Fixed prompt re-processing by preserving tool ordering (maps are unordered in Go).

---

## Architectural Changes

### 1. Coordinator Pattern → Multi-Agent

The original `coordinator.go` managed named agents ("coder", "task") with separate configurations. This pattern was extended in PR #914 to allow configurable models per agent, but full multi-agent (custom agents, custom tools, custom prompts) was not achieved.

**Lesson:** The coordinator pattern for agent types is a good starting point but requires significant plumbing for true multi-agent.

---

### 2. Hooks Decorator Pattern

`hooked_tool.go` wraps tools with pre-execution hooks via `hooks.Runner`. This enables permission checks and arbitrary custom logic without modifying tool implementations.

**Pattern:**
```go
// Wrap tool with hook
tool := hooks.Run(tool, hookFunc)
// Hook runs before tool, can modify params or deny execution
```

**Lesson:** Decorator pattern for tool middleware is superior to modifying each tool individually. SugarCraft should consider hooks as a first-class concept.

---

### 3. Auto-Summarization with Context Windows

Crush uses `largeContextWindowThreshold` (200k tokens) and `smallContextWindowRatio` (0.2) to trigger summarization. The summary is generated by the LLM itself.

**Pattern:** When context exceeds threshold, pause agent loop, generate summary, replace conversation history with summary.

**Lesson:** Auto-summarization is essential for long sessions but requires careful threshold tuning per model context window size.

---

### 4. Permission System Architecture

`permission.Service` uses a priority chain:
1. Check YOLO mode (skip all)
2. Check allow-list (pre-approved tools)
3. Check session permissions (previously granted)
4. Show dialog, wait for user response
5. Cache result for session

**Key design:** `requestMu` (Mutex) serializes all permission requests—only one prompt at a time, others queue.

**Lesson:** Permission systems need both per-invocation granularity and proper async handling. Serialization prevents concurrent prompts from confusing users.

---

### 5. PubSub Event Architecture

`internal/pubsub` is the backbone for decoupled communication:
- Agent publishes tool results
- UI subscribes and updates
- LSP events flow through broker
- Session events (compact, switch) flow through broker

**Problem:** Fan-out from multiple concurrent goroutines caused broker flooding. Added backpressure with 2-second timeout.

**Lesson:** Event brokers in agent loops need backpressure and per-event-type subscription, not global "every subscriber gets everything."

---

### 6. Tool Self-Documentation

Each tool has a `.go` implementation paired with a `.md` template and a `FirstLineDescription` for token-saving mode.

**Pattern:**
```go
// Tool returns both implementation and description
func NewViewTool() *AgentTool { ... }
```

**Lesson:** Self-documenting tools enable LLM to understand tool capabilities without hardcoding descriptions in prompts.

---

## Performance Discussions

### 1. Memory Footprint (200MB → 100MB)

PR #1812 reduced initial memory from ~200MB to ~100MB on some platforms through:
- Cached diagnostic counts (avoid recomputing on every render)
- Package-level regex compilation (avoid re-allocation)
- Efficient map copying (`maps.Copy` vs `maps.Collect`)
- Platform-specific SQLite drivers

### 2. CPU Spikes (27,441 wait events)

Issue #1455 found severe lock contention (27,441 `__psynch_cvwait` events) during text rendering with large files. Root causes:
- No input debouncing on keystrokes
- Repeated file system operations on every keystroke
- Inefficient string operations
- Synchronization overhead in rendering pipeline

**Fix:** Multiple PRs addressed this. Not fully resolved pre-archival.

### 3. Editor Input Latency (>100ms per character at 300KB)

Issue #1455: With 300KB text, typing latency exceeded 100ms per character. Target was <10ms.

**Root cause:** String operations on 300KB buffers without buffering or caching.

### 4. Gitignore Matching (80% CPU)

PR #2199: `sabhiram/go-gitignore` compiled every pattern to regex. In large monorepos, this dominated CPU time.

**Fix:** Replace with native glob + caching. Gitignore matching dropped from 80% → ~2% of CPU.

### 5. LSP Root Markers (Recursive Walk)

PR #2316: `hasRootMarkers()` walked entire working directory on every LSP trigger. For large monorepos, this meant millions of syscalls.

**Fix:** Simple `filepath.Glob` since markers are at project root.

### 6. Parallel Tool Dispatch Bottleneck

v0.40.0 regression: Fantasy runs sequential tools inline in the coordinator goroutine, blocking all parallel tool dispatch while sequential tools run.

**Impact:** A single `view` tool call blocked all other tools (even parallel ones) from being dispatched.

**Lesson:** Sequential and parallel tool execution must be truly concurrent, not serialized through the coordinator goroutine.

---

## Extensibility Discussions

### 1. Agent Skills (SKILL.md)

Skills are loaded from `~/.config/crush/skills/` and `.crush/skills/`. Each skill has a `SKILL.md` defining its name, description, and instructions. Skills can be enabled/disabled per-project.

**Pattern:** Skills are discovered at startup, shown in sidebar with status icons, and can be dynamically refreshed.

**Lesson:** The file-based skill discovery pattern is simple and powerful. SugarCraft should implement something similar.

---

### 2. MCP Extensibility

MCP servers are configured in `crush.json` with three transport types:
- `stdio`: command-line servers
- `http`: HTTP endpoints
- `sse`: Server-Sent Events

Each server can have:
- `disabled: true` to disable entirely
- `disabled_tools: []` to hide specific tools
- `env: {}` for environment variables
- `timeout: 120` for request timeout

**Lesson:** The transport abstraction allows the same integration pattern across different server types. SugarCraft should support stdio and HTTP MCP at minimum.

---

### 3. Hooks System (PreToolUse)

PR #2598 introduced `PreToolUse` hook with pattern matching:
- Match by tool name regex (e.g., `^bash$`)
- Can deny tool call, halt turn, rewrite bash commands
- Compatible with Claude Code hooks format

**Example use case:** Using `rtk` to reduce tokens in common bash commands.

**Lesson:** Hooks should support pattern matching, not just exact names. This allows hooking entire categories of tools.

---

### 4. Context Files

Crush reads AGENTS.md, CRUSH.md, CLAUDE.md, GEMINI.md (and `.local` variants) from the working directory for project-specific instructions.

**Discovery:** `internal/config` discovers context files at startup.

**Lesson:** Context file discovery is a proven pattern for project-specific customization. SugarCraft should support multiple context file variants.

---

## API/UX Complaints

### 1. Configuration Complexity

Issue #2625: JSON-only configuration is hard for non-developers. Provider/model selection lacks visual feedback. Import/export and schema migration are unclear.

**Requested:** Web UI for configuration with templates, validation, and guided setup.

**Lesson:** JSON configuration scales poorly as features grow. SugarCraft should invest in both machine-readable config AND human-friendly config tooling.

---

### 2. Copilot Auth Must Be Repeated After Directory Switch

Issue #2007: OAuth token stored in `crush.json` but switching directories causes re-authentication. Copilot provider gets skipped due to "missing API endpoint" after directory switch.

**Root cause:** Context file loading resets provider state.

**Lesson:** OAuth tokens must be stored globally, not per-directory. Directory context switches must not reset authentication state.

---

### 3. --yolo Mode Has Hard-Coded Command Blocks

Issue #2463: `--yolo` still blocks `ssh`, `scp`, `rsync`, `telnet`. Even "dangerous mode" doesn't allow power users to do legitimate infra workflows.

**Requested:** Configurable blocked command list.

**Status:** Open as of archival.

**Lesson:** Hard-coded allowlists are inflexible. Configurable blocked commands would serve both security and usability.

---

### 4. Crush Run Has No Roles/System Prompt

Discussion #2404: `crush run` (non-interactive mode) can't specify roles or system prompts. This is needed to replace `mods`.

**Maintainer response:** Acknowledged as important. No ETA.

**Lesson:** Non-interactive mode needs parity with interactive mode for system prompt customization.

---

## Migration Problems

### 1. Safe-Command Bypass for File Writes

Issue #2368: `echo "data" > file.txt` bypasses permission (echo is safe-listed). This is a migration problem because users trust the permission system based on interactive behavior, but non-interactive flows (`crush run`) silently bypass file write protections.

---

### 2. Session Compaction Fails for Small Context Models

Issues #472, #727: Compact/summarize fails for models with context <4k tokens. The feature works for large context models but is completely broken for small context local models.

**Lesson:** Auto-compaction must gracefully handle the case where the model can't summarize its own context.

---

### 3. Mods to Crush Migration

Discussion #2194: `mods` sunset drove users to `crush run`. Missing features include:
- `-s, -S, -c, -C` switches
- History (`mods -l`)
- Deterministic behavior (no arbitrary local file access in `crush run`)
- Tool use control (some just want a query, not file modifications)

**Maintainer response:** Some switches implemented (v0.50.0). History not yet. Deterministic/non-local mode requested but not implemented.

**Lesson:** Migration paths need feature parity for the replacee, not just approximate equivalence.

---

## Clever Fixes & Workarounds

### 1. SQLite In-Memory Fix for Sandboxed Environments

Issue #2903: For sandboxes that block SQLite WAL requirements, a workaround was to use in-memory SQLite. This prevents corruption but loses persistence.

**Community workaround:** `nono run ... --allow /tmp` to allow SQLite temp files.

---

### 2. Hotfix for Compaction Hangs

PR #961 was a user-contributed hotfix that:
1. Fixed the undefined `err` variable bug
2. Added a 5-minute timeout to compaction
3. Showed progress updates

This was done without maintainer involvement, showing community investment in the project.

---

### 3. Wrapper Scripts for Blocked Commands

Issue #928: Users created wrapper scripts like `bypass sudo` using `zenity` for GUI password prompts, then aliased `sudo` to the wrapper.

---

### 4. Docker Sandbox for Secure Workflows

Third-party repo `wireless25/crush-sandbox` provides a Docker wrapper for Crush with:
- Read-only config mounts
- Non-root user
- Capability dropping
- Credential scanning with gitleaks
- Per-workspace isolation

This community project solved the sandboxing problem that Crush itself hadn't addressed.

---

### 5. SSH Wrapper for Blocked ssh Command

Issue #2474: A user created an SSH wrapper script that bypasses the hard-coded `ssh` block while maintaining the intended security model.

---

### 6. Ctrl+S Session Reload Hack

Issue #2881: When UI stopped updating after pubsub overflow, users discovered that Ctrl+S (reload session) restored visibility of completed tool outputs. This was a manual workaround for a real bug.

---

## Community Workarounds

### 1. Export Sessions with SQLite Queries

Issue #1965: Users needing chat export wrote external scripts querying `sessions` and `messages` tables directly.

---

### 2. Custom Hooks for Token Reduction

Discussion #1336: A user implemented custom notification hooks that played sounds instead of using system notifications.

---

### 3. Shell Integration for Secret Management

Issue #2334: Workaround for MCP file-based secrets was `(set -a; source .env.crush; crush -y)` to load env vars before running.

---

### 4. MCP Server Per-Project

Users configured MCP servers per-project in `.crush/crush.json` rather than globally, enabling project-specific tool sets.

---

### 5. Background Job Workaround for Long-Running Commands

Commands >1 minute are auto-converted to background jobs. Users discovered using `job_output` to stream partial results is the workaround for long operations.

---

## Maintainer Guidance Patterns

### 1. Layered Permission Model

Maintainers consistently communicated a layered model:
- Default: Ask permission for every tool
- Allow-list: Pre-approve specific tools
- YOLO: Skip all prompts (dangerous)

The message is always: "Be very careful with YOLO mode."

---

### 2. "On the Roadmap" for Big Features

Features like subagents, hooks, HTTP interface, and plan mode were all marked "on the roadmap" with "no ETA" qualifiers. The pattern suggests features were prioritized based on implementation complexity vs. user demand.

---

### 3. Claude Code Format Compatibility as Goal

Maintainers explicitly stated: "What we need in the industry is an open format/specification for features like this, so tooling only has to support that standard." They aimed for Claude Code compatibility to ease migration.

---

### 4. Progressive Feature Flags

Large features (new UI, server-client mode) were shipped behind feature flags (`CRUSH_NEW_UI`, `CRUSH_CLIENT_SERVER`) to get community testing without destabilizing main workflow.

---

### 5. Community Contributions Welcome

Maintainers actively encouraged PRs and provided guidance on architecture. Multiple contributors became repeat participants.

---

## Rejected Ideas Worth Revisiting

### 1. Timeout-Based Compaction

Option 2 for issue #2130 (loop detection) was adding a 10-minute timeout. Maintainer rejected this: "the model can indeed take a long time if you ask it to do a significantly complex work."

**Revisit reason:** Timeout-based circuit breakers are standard engineering practice. A model that takes >10 minutes for a single step is likely in an infinite loop. This rejection may have been premature.

---

### 2. Built-in Sandbox

Issue #1541 requested native sandboxing using bubblewrap (Linux) and seatbelt (Darwin). Maintainer converted to discussion but no implementation occurred pre-archival.

**Revisit reason:** Native OS sandboxing is more secure than Docker. This is a legitimate gap in the security model.

---

### 3. Configurable Blocked Commands

Issue #2474 requested making the hard-coded blocked command list configurable. Maintainer didn't merge this pre-archival.

**Revisit reason:** Power users in trusted environments need the ability to override defaults. The current model protects beginners but blocks advanced use cases.

---

### 4. Strict Per-Command Permission Granularity

Issue #497 requested per-command-plus-flags permission granularity (Claude Code style). This was not fully implemented pre-archival.

**Revisit reason:** Per-command granularity significantly improves security without blocking legitimate workflows. This is a clear improvement opportunity.

---

## Problems Likely Relevant To SugarCraft

### 1. Global Caches Without Eviction

The regex cache unbounded growth issue (#2161) affected any long-running session. SugarCraft's any caching layer (for LSP diagnostics, search results, etc.) needs eviction policies.

**Risk level:** HIGH for any session-based or long-running workflow.

---

### 2. Pubsub Backpressure

The 2-second timeout dropping messages caused UI state desync. Any async event system in SugarCraft must implement proper backpressure.

**Risk level:** MEDIUM for any concurrent UI update system.

---

### 3. Context Window Management

Auto-compaction was fragile—the trigger conditions didn't account for model context size. SugarCraft's AI integration will need robust context budget management.

**Risk level:** HIGH for any LLM integration.

---

### 4. Permission System Complexity

The permission model evolved through multiple iterations and still has gaps (safelist bypass, session-wide grants). A well-designed permission system must be foundational, not incremental.

**Risk level:** CRITICAL for any autonomous tool use.

---

### 5. SQLite WAL Mode in Constrained Environments

SQLite WAL requires temp file access, which sandboxes restrict. Any SQLite usage in SugarCraft must handle this gracefully.

**Risk level:** MEDIUM if SQLite is used with sandboxing.

---

### 6. Provider-Specific Quirks

Tool call serialization differs across providers (empty args for Copilot, Ollama tool differences, Gemini Vertex tool response counts). The fantasy library handles most of these, but edge cases remain.

**Risk level:** HIGH for multi-provider support.

---

### 7. Concurrent Tool Dispatch

The v0.40.0 regression showed that sequential tools blocking the coordinator goroutine prevents parallel tool dispatch. This is a fundamental concurrency bug in agent loop design.

**Risk level:** HIGH for any parallel tool execution.

---

### 8. Atomic Operations on 32-bit Platforms

Struct field ordering for atomic alignment is a subtle cross-platform concern. Any struct with atomic fields must have the atomic field first.

**Risk level:** LOW for PHP (no direct atomic operations), but relevant if FFI is used.

---

### 9. Loop Detection

The absence of loop detection allowed infinite loops to consume resources and context. Any autonomous agent needs explicit loop detection.

**Risk level:** CRITICAL for any autonomous tool use.

---

### 10. Focus Management in TUI

Modal states (project init, oauth) must block input to hidden components. This is a common TUI pitfall.

**Risk level:** MEDIUM for any Bubble Tea-based TUI.

---

## Features SugarCraft Should Consider

### 1. First-Class Permission System

Crush's permission system evolved reactively and still has gaps. SugarCraft should design permission granularity (per-tool, per-invocation, per-pattern) upfront.

**Priority:** CRITICAL

---

### 2. Loop Detection

Loop detection (max steps, repeated tool call detection) is essential for autonomous agents. Implement from day one.

**Priority:** CRITICAL

---

### 3. Context Budget Management

Session compaction/summarization with explicit budget tracking (tokens used, remaining, suggested actions).

**Priority:** HIGH

---

### 4. Hooks System

PreToolUse and other hooks for customization. The Claude Code hooks format is a reasonable standard to adopt.

**Priority:** HIGH

---

### 5. MCP Client

stdio and HTTP transport support for MCP servers. This is the primary extensibility mechanism for AI tools.

**Priority:** HIGH

---

### 6. Agent Skills System

File-based skill discovery (SKILL.md) with global and project-local loading.

**Priority:** MEDIUM

---

### 7. Plan/Build Mode Separation

Explicit read-only planning mode with restricted tool set.

**Priority:** MEDIUM

---

### 8. Subagents

Multi-agent support with per-agent model, tools, and prompts.

**Priority:** MEDIUM (requires solving coordination first)

---

### 9. HTTP/API Interface

Server mode for programmatic agent access without TUI.

**Priority:** MEDIUM

---

### 10. Session Management CLI

`session list/show/delete/rename` for programmatic access.

**Priority:** MEDIUM

---

### 11. TreeSitter Integration

AST-based code understanding for context reduction and safer edits.

**Priority:** LOW (significant effort, consider for future)

---

### 12. Chat Export

Export sessions to Markdown for knowledge retention.

**Priority:** LOW

---

### 13. Web UI for Configuration

Configuration UI for non-technical users.

**Priority:** LOW

---

## Architectural Lessons

### 1. Service-Based Configuration

Crush uses `config.Service` accessed via dependency injection, not global state. This enables testing and multiple configurations.

**Lesson for SugarCraft:** Configuration should be a service, not global state.

---

### 2. Tool Decorator Pattern

`hooked_tool.go` demonstrates the decorator pattern for tool middleware. Tools shouldn't know about hooks; the wrapper handles it.

**Lesson for SugarCraft:** Use decorators for cross-cutting concerns (permissions, logging, hooks).

---

### 3. Workspace Abstraction

PR #2455 introduced `Workspace` interface unifying local and remote modes. This was necessary to add server-client architecture without rewriting the UI.

**Lesson for SugarCraft:** Design for remote operation from the beginning. Abstract workspace/state access behind interfaces.

---

### 4. Event Broker with Backpressure

The pubsub broker needed a 2-second timeout and message dropping to handle overload. Pure push without backpressure causes cascading failures.

**Lesson for SugarCraft:** Any event system must have backpressure handling.

---

### 5. Provider Abstraction Layer

`fantasy` provides provider-agnostic LLM access. This is essential for multi-provider support without per-provider code paths.

**Lesson for SugarCraft:** Abstract LLM access through a provider interface, not direct API calls.

---

### 6. SQLite with sqlc

All SQL in `internal/db/sql/`, generated code in `internal/db/`. Migrations in `internal/db/migrations/`. This keeps SQL visible and versioned.

**Lesson for SugarCraft:** Use sqlc or similar for type-safe SQL queries with migrations.

---

### 7. System Prompts as Templates

System prompts are Go templates (`internal/agent/templates/*.md.tpl`) with runtime data injection. This allows per-agent-type customization.

**Lesson for SugarCraft:** Template-based prompts enable runtime customization without code changes.

---

## Defensive Design Lessons

### 1. Safelist Bypass Is Fatal

The `echo` safelist bypass allowing file writes via `echo "x" > file` shows that "safe" commands are only safe if you analyze the full command line.

**Defensive rule:** Never trust a command-only safelist. Analyze the full command string, including redirects, pipes, and compound operations.

---

### 2. Error Handling Must Not Be Silent

The compaction hanging bug (undefined `err` variable swallowing errors) made debugging impossible. Silent error handling is a security risk because attacks appear to succeed.

**Defensive rule:** All errors must be logged or reported. Silent swallowing is forbidden in agent loops.

---

### 3. Timeout Everything

Agent operations without timeouts can hang indefinitely. Every LLM call, tool execution, and session operation needs a timeout.

**Defensive rule:** Use `context.WithTimeout` everywhere. Never use `context.WithCancel`.

---

### 4. Concurrent Updates Need Synchronization

The v0.40.0 regression showed that concurrent goroutines publishing to the same topic without synchronization causes state desync.

**Defensive rule:** Every shared state access in concurrent code must be synchronized. Use channels or proper locking.

---

### 5. Cache Invalidation Is Non-Obvious

The tool ordering issue (maps are unordered in Go) broke prompt caching without any explicit error. Cache invalidation bugs are silent and catastrophic.

**Defensive rule:** Deterministic iteration order for any data structure used in cache keys. Document non-obvious cache invalidation triggers.

---

### 6. Session-Wide Grants Must Be Intentional

"Allow for session" granting all bash commands (not just the one approved) is dangerous. Permission grants should be as narrow as possible.

**Defensive rule:** "Allow for session" should mean "allow this specific command pattern for this session," not "allow all commands."

---

### 7. YOLO Mode Requires Explicit Opt-In

Even YOLO mode has hard-coded blocks that can't be overridden. Power users need the ability to accept risk intentionally.

**Defensive rule:** Security defaults should be strict, but overrides should be possible for advanced users in trusted environments.

---

### 8. Sandboxing Needs Native Support

Docker and bubblewrap sandboxing are community workarounds for a missing first-class feature. Native OS sandboxing (syscall filtering) is more efficient and portable.

**Defensive rule:** Plan for sandboxing from the beginning. Don't rely on users to implement their own.

---

## Ecosystem Trends

### 1. Claude Code Compatibility as De Facto Standard

Crush maintainers explicitly aimed for Claude Code compatibility for commands, hooks, and skills. This trend suggests the industry is standardizing on Claude Code's patterns.

**Implication:** SugarCraft should consider Claude Code compatibility for its AI integration APIs.

---

### 2. Non-Interactive Mode as First-Class Citizen

`crush run` was actively developed to replace `mods`. The CLI/non-interactive use case is as important as the TUI.

**Implication:** SugarCraft's AI components should work without TUI, supporting both interactive and programmatic access.

---

### 3. Server-Client Architecture for AI Tools

PR #2455 shows the industry trend toward separating agent execution (server) from UI (client). This enables multiple UIs and programmatic access.

**Implication:** SugarCraft should design with a service boundary from the beginning.

---

### 4. Multi-Provider Support

15+ providers with automatic updates via Catwalk. Users expect to switch models without reconfiguring.

**Implication:** Provider abstraction is competitive necessity, not a feature.

---

### 5. Extensibility Through MCP

MCP is the dominant extensibility model. stdio, HTTP, and SSE transports cover most integration scenarios.

**Implication:** MCP client support is essential for any AI tool ecosystem.

---

### 6. Skill Systems as Shared Standards

Agent Skills standard (agentskills.io) enables skill portability across tools. Adoption by multiple tools creates ecosystem network effects.

**Implication:** SugarCraft should support the Agent Skills standard for skill portability.

---

## Strategic Opportunities

### 1. SugarCraft as PHP's Crush Alternative

Crush is sunset and Go-only. PHP developers need an equivalent for AI-assisted coding. SugarCraft's existing TUI library ecosystem is the foundation; adding AI agent capabilities creates a compelling alternative.

**Opportunity:** Become the de facto AI coding assistant for PHP, built on PHP's ecosystem.

---

### 2. Better Permission Model

Crush's permission system was reactive and has gaps. A well-designed permission system from the start (per-tool, per-pattern, per-invocation granularity) would be superior.

**Opportunity:** Design permission system with full granularity upfront.

---

### 3. First-Class Loop Detection

Crush added loop detection reactively. Implementing loop detection as foundational (not afterthought) produces a more robust system.

**Opportunity:** Build loop detection and step limits into the core agent loop.

---

### 4. MCP Ecosystem Integration

MCP is gaining adoption. SugarCraft could become the PHP reference implementation for MCP client/server, bridging PHP tools into the AI ecosystem.

**Opportunity:** Implement MCP client and server in PHP, enabling PHP developers to use and build MCP tools.

---

### 5. PHP-Native Code Intelligence

TreeSitter for PHP would enable AST-aware tools in PHP without requiring a Go runtime. This is a significant differentiation opportunity.

**Opportunity:** Build PHP-native LSP-like code intelligence as a SugarCraft library.

---

## Cross-Ecosystem Pattern Matches

### 1. RooCode/KiloCode TreeSitter + Embeddings

RooCode uses TreeSitter for parsing and embeddings stored in Qdrant for codebase indexing. This is an advanced pattern for large codebase RAG.

**Match for SugarCraft:** Implement AST-based chunking for PHP code RAG.

---

### 2. Claude Code Plan Mode

Plan mode with enforced read-only restrictions is a proven pattern for safe autonomous coding.

**Match for SugarCraft:** Implement plan/build mode separation.

---

### 3. Goose Provider Quirks

Goose had similar tool call issues with Vertex AI as Crush. This suggests provider-specific tool handling is a common challenge.

**Match for SugarCraft:** Expect and test provider-specific edge cases.

---

### 4. Docker MCP Catalog

Crush + Docker integration to dynamically load MCPs from Docker Hub based on conversation context.

**Match for SugarCraft:** Dynamic MCP discovery based on project context.

---

## High ROI Recommendations

### 1. Implement Permission System First

Before any autonomous tool use, implement a permission system with per-tool, per-pattern granularity. This is the foundation for safe AI integration.

**Effort:** HIGH (requires design and implementation)
**Risk reduction:** CRITICAL

---

### 2. Add Loop Detection to Agent Core

Implement max steps limit and repeated tool call detection as foundational agent safeguards before any other agent features.

**Effort:** MEDIUM
**Risk reduction:** CRITICAL

---

### 3. Build MCP Client Infrastructure

MCP is the primary extensibility mechanism for AI tools. Implement stdio and HTTP transport support to enable integration with the broader MCP ecosystem.

**Effort:** HIGH
**Value:** HIGH (opens entire MCP tool ecosystem)

---

### 4. Design Service Boundary Upfront

If SugarCraft adds AI agent capabilities, design with a clean service boundary (agent service vs. UI service) from the beginning. Adding server-client architecture later is expensive (as Crush demonstrated).

**Effort:** MEDIUM (design) + HIGH (implementation)
**Value:** HIGH (enables programmatic access)

---

### 5. Context Budget Management

Implement explicit context tracking (tokens used, remaining, model limits) and session compaction with configurable triggers. This is essential for long-running sessions.

**Effort:** MEDIUM
**Value:** HIGH (enables practical long-term use)

---

### 6. Adopt Agent Skills Standard

Support the agentskills.io standard for skill portability. This enables sharing skills across Crush, SugarCraft, and other compatible tools.

**Effort:** MEDIUM
**Value:** MEDIUM (ecosystem network effects)

---

### 7. Implement Hooks as First-Class Concept

Build hooks system (PreToolUse, etc.) into the core tool execution pipeline. This enables user customization without core modifications.

**Effort:** MEDIUM
**Value:** HIGH (enterprise customization)

---

### 8. Consider TreeSitter for PHP Code Intelligence

AST-based tools (context packing, find-refs, blast-radius analysis) are a significant capability gap. Evaluate whether TreeSitter PHP grammar is sufficient.

**Effort:** HIGH
**Value:** MEDIUM (nice-to-have, significant complexity)

---

## Summary

Charmbracelet Crush was a sophisticated AI coding assistant that explored many of the same problems SugarCraft faces in integrating AI capabilities into a developer tool ecosystem. The project's archival in March 2026 (after active development producing 137 releases) presents SugarCraft with a strategic opportunity: learn from Crush's engineering experiments, avoid its design mistakes, and build a PHP-native AI coding assistant on the foundation of its existing TUI libraries.

The most critical lessons are:
1. **Permission systems must be foundational**, not incremental
2. **Loop detection is non-negotiable** for autonomous agents
3. **Context budget management** requires explicit design
4. **Concurrency bugs** in agent loops cause silent, catastrophic failures
5. **MCP ecosystem integration** is the primary extensibility mechanism
6. **Server-client architecture** should be designed in from the start
7. **Claude Code compatibility** is the de facto industry standard

The sunset of Crush creates a gap in the terminal-based AI coding assistant space. SugarCraft, with its PHP-native ecosystem and TUI library foundation, is positioned to fill that gap for the PHP community—if AI agent capabilities are added with attention to the lessons learned from Crush's 18 months of active development.