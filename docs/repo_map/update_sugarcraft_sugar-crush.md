# Overview

**sugar-crush** is SugarCraft's port of `charmbracelet/crush` — a chat-shell TUI for AI coding assistants with pluggable backends, markdown rendering via CandyShine, scrollback viewport, and session persistence. It is 🟢 v1 ready with 158 tests / 444 assertions.

**Biggest opportunity areas:**
1. **Streaming UI** — Real-time token rendering as they arrive (not after complete response)
2. **Chat history persistence** — JSONL-based session storage for conversation resumption
3. **Context compaction** — Auto-truncation when approaching token limits
4. **Built-in file tools** — read/write/edit/glob/grep via ToolRegistry
5. **MCP ecosystem integration** — HTTP/SSE transports beyond stdio-only Claude Code

**Biggest missing capabilities:**
1. **No LSP/code intelligence integration** — No gopls, tsserver equivalent
2. **No real multi-step agentic loops** — Tool calling is sequential, not parallel
3. **No permission system** — Tool execution has no allow-list/confirmation prompts
4. **No syntax highlighting** — Code blocks render without coloring
5. **No plan/build mode separation** — Claude Code's shift-tab equivalent

---

# Internal Capability Summary

## Current Architecture

```
sugar-crush/src/
├── Chat.php                     # Main TUI Model (implements Model interface)
├── Message.php                  # Immutable conversation turn (role/content/toolCalls)
├── Role.php                     # Enum: System | User | Assistant
├── Renderer.php                 # Pure view function (CandyShine markdown + borders)
├── AssistantMsg.php             # Internal Msg for backend completion delivery
├── Session.php                  # Persists UI state to ~/.config/sugarcraft-crush/
├── ToolCall.php                 # VO: name, arguments, optional id
├── ToolResult.php               # VO: name, result, error, optional id
├── ToolRegistry.php             # Registry of slash-commands/built-in tools (5 built-ins)
├── Compactor.php                # Groups small files by extension to reduce clutter
├── CommandParser.php            # Parses /command slash-input
├── StreamingDirectoryLister.php # Generator-based lazy directory enumeration
├── McpMessage.php              # JSON-RPC 2.0 envelope
├── McpClient.php                # MCP stdio client for Claude Code integration
├── Attachment.php              # File/image attachment
├── AttachmentType.php          # Enum: File | Image
├── Backend.php                  # Interface: complete(), completeAsync()
└── Backend/
    ├── EchoBackend.php         # Offline default
    ├── CommandBackend.php      # Shell-out via proc_open
    └── StreamingCommandBackend.php  # Streaming variant
```

## Current Features

| Feature | Status |
|---------|--------|
| MVU architecture (Model/Update/View) | ✅ Full |
| Immutable + fluent with*() builders | ✅ Full |
| Backend interface (pluggable providers) | ✅ Full |
| CommandBackend (shell-out wrapper) | ✅ Full |
| StreamingCommandBackend (line-by-line) | ✅ Full |
| Tool calling (multi-step flow) | ✅ Full |
| ToolRegistry (5 built-ins: filter/sort/goto/select/quit) | ✅ Full |
| UTF-8 grapheme-aware backspace | ✅ Full |
| Graceful degradation throughout | ✅ Full |
| Generator-based directory listing | ✅ Full |
| MCP client (stdio, Claude Code only) | ✅ Full |
| Session persistence (UI state only) | ✅ Full |
| Markdown rendering via CandyShine | ✅ Full |
| ReactPHP async support | ✅ Full |
| 158 tests / 444 assertions | ✅ Full |

## APIs

**Backend Interface:**
```php
interface Backend {
    public function complete(array $history, callable $onToken = null): Message;
    public function completeAsync(array $history, callable $onToken = null): PromiseInterface;
}
```

**Tool System:**
```php
// ToolCall - from AI backend
final class ToolCall {
    public readonly string $name;
    public readonly array $arguments;
    public readonly ?string $id;
}

// ToolResult - to AI backend
final class ToolResult {
    public static function ok(string $name, string $result, ?string $id = null): self;
    public static function error(string $name, string $error, ?string $id = null): self;
    public function toWire(): array; // ['role' => 'tool', 'tool_call_id' => $id, 'name' => $name, 'content' => $result]
}
```

## Rendering Systems

- **Renderer.php** — Pure view function using CandyShine for markdown + candy-sprinkles for borders/styling
- User messages: raw text with cyan `user>` prefix
- Assistant messages: rendered via CandyShine with magenta `assistant` prefix
- Input: `> ` prompt with cursor block `█` when not in-flight

## Extension Systems

- **Backend interface** — Pluggable adapter pattern for LLM providers
- **ToolRegistry** — Register custom tools with name, signature, execute handler
- **McpClient** — Extensible via stdio transport (only Claude Code currently)

## Strengths

1. **Clean pluggable architecture** — Backend is injectable, tools are registerable, renderers are pure
2. **Immutable + fluent throughout** — No hidden mutations, race conditions visible in tests
3. **Network-dep-free core** — Wrapper script approach keeps PHP isolated from provider changes
4. **UTF-8 grapheme awareness** — Proper multi-byte handling in backspace
5. **Graceful degradation** — Every failure mode handled silently with sensible defaults
6. **Generator-based directory listing** — Memory-safe even for huge directories
7. **Comprehensive test suite** — 158 tests covering behavior, coercion, and snapshot patterns

## Weaknesses

1. **No chat history persistence** — History is ephemeral; no conversation resumption
2. **Synchronous tool execution** — Long-running tools block the TUI update loop
3. **No streaming UI** — Tokens accumulated before rendering, not incrementally displayed
4. **Single-backend limitation** — Cannot use multiple providers simultaneously
5. **MCP client is Claude Code-specific** — Not a general-purpose MCP implementation
6. **No permission system** — Tool execution has no confirmation or allow-list
7. **No context compaction** — Long conversations will hit token limits

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|--------------------------|----------|
| `charmbracelet/crush` (upstream) | Critical | Full AI coding assistant architecture, agent loop, tool execution | P0 |
| `charmbracelet/fantasy` | Critical | Provider abstraction, multi-step agentic loops, structured outputs, retry logic | P0 |
| `charmbracelet/bubbletea` | Critical | Elm architecture, TUI patterns, mouse/keyboard handling | P0 |
| `textualize/textual` | High | CSS-based styling, reactive state, command palette, 40+ widgets | P1 |
| `php-tui/php-tui` | High | Same language, widget system, Cassowary layout, buffer diffing | P1 |
| `charmbracelet/glow` | Medium | Markdown rendering, file discovery, fuzzy filtering | P2 |
| `charmbracelet/gum` | Medium | CLI TUI utilities, fuzzy filtering, spinner patterns | P2 |
| `charmbracelet/log` | Low | Structured logging, context propagation, multiple formatters | P3 |
| `lrstanley/bubblezone` | Medium | Zone-based mouse tracking, zero-width ANSI markers | P2 |
| `charmbracelet/harmonica` | Low | Spring physics animations | P3 |

---

# Feature Gap Analysis

## Critical Priority

### 1. Loop Detection
**Title:** Infinite loop protection in agent tool execution

**Description:** The upstream crush had a critical bug (#2130, fixed by PR #2214) where agents could get stuck in infinite loops calling the same tool repeatedly. The fix required adding both a step counter and repeated-tool-call detection (>5 times in last 10 steps) plus a max steps limit.

**Why it matters:** Without loop detection, autonomous tool execution can hang indefinitely, consume API quota, and produce nonsensical outputs. This is non-negotiable for any autonomous agent.

**Source repo:** `docs/repo_map/pr_charmbracelet_crush.md` — Issue #2130, PR #2214

**Implementation ideas:**
- Add `maxSteps` parameter to Chat model
- Track tool call frequency: `array<string, int>` with sliding window of last 10 calls
- If same tool called >5 times in window → raise `LoopDetectedMsg`
- Expose configuration via `$SUGARCRUSH_MAX_STEPS` env var

**Estimated complexity:** Medium (4-6h)

**Expected impact:** Critical — enables reliable autonomous operation

### 2. Permission System
**Title:** Per-tool execution allow-lists with confirmation prompts

**Description:** The upstream crush had a broken safelist bypass vulnerability (#2368) where commands like `echo "data" > file.txt` bypassed permission prompts because `echo` was on the safe-list. Session-wide grants (#497) were also dangerous — granting "Allow for session" approved ALL bash commands, not just the one requested.

**Why it matters:** Autonomous tool use without a robust permission system is a security catastrophe. The permission model must analyze full command lines, not just leading command words.

**Source repo:** `docs/repo_map/pr_charmbracelet_crush.md` — Issues #2368, #497

**Implementation ideas:**
- Pattern-based matching (regex) for tool names, not just exact names
- Per-invocation granularity, not session-wide
- Tool allow-list + confirmation prompt + cache for session
- Serialized permission requests via mutex to prevent concurrent prompts

**Estimated complexity:** High (8-12h)

**Expected impact:** Critical — enables safe autonomous operation

### 3. Context Budget Management
**Title:** Token counting and auto-compaction for long conversations

**Description:** The upstream crush had multiple compaction bugs: undefined `err` variable silently swallowing errors (#472), no timeout on compaction, compaction triggering when context was nearly full but model too small for summarization (#727). Auto-compaction must account for model context window size.

**Why it matters:** Long-running AI conversations will hit token limits. Without explicit budget tracking and compaction, the tool becomes unusable for extended sessions.

**Source repo:** `docs/repo_map/pr_charmbracelet_crush.md` — Issues #472, #727

**Implementation ideas:**
- Add `TokenCounter` service estimating context usage
- Track: tokens used, remaining, model limits
- Compaction trigger: configurable threshold (e.g., 80% of context window)
- Use LLM to generate summary, replace history with summary + original message count
- Timeout on compaction (5 min default)

**Estimated complexity:** Medium (6-8h)

**Expected impact:** High — enables practical long-term use

## High Value

### 4. Streaming UI
**Title:** Real-time token rendering as they arrive

**Description:** Currently tokens are accumulated before rendering. The StreamingCommandBackend receives tokens line-by-line via `$onToken` callback, but the UI only updates after the complete response. Users see no feedback while waiting.

**Why it matters:** Streaming feedback is crucial for UX — users know the model is working. Without it, the TUI appears frozen during long responses.

**Source repo:** `docs/repo_map/pr_charmbracelet_crush.md` — PR analysis shows streaming was a key feature

**Implementation ideas:**
- Add partial message state to Chat: `?string $partialContent`
- `StreamingCommandBackend::completeAsync()` dispatches `PartialTokenMsg` as tokens arrive
- Chat's `update()` handles `PartialTokenMsg` to update `partialContent`
- Renderer shows partial content with trailing cursor

**Estimated complexity:** Medium (4-6h)

**Expected impact:** High — major UX improvement

### 5. Chat History Persistence
**Title:** JSONL-based session storage for conversation resumption

**Description:** Session currently persists only UI state (cwd, selected files, filter, sort). Chat history is ephemeral — passed to backends each turn but never saved. Users cannot resume conversations.

**Why it matters:** Conversation resumption is essential for practical use — users may need to continue a session days later.

**Source repo:** `docs/repo_map/pr_charmbracelet_crush.md` — PR #2373 (CLI session management), Issue #1965 (chat export)

**Implementation ideas:**
- Add `ChatHistory` class persisting to `~/.config/sugarcraft-crush/history/<session-id>.jsonl`
- Each line: JSON-encoded Message with metadata (timestamp, model used)
- `ChatHistory::load(sessionId)` / `ChatHistory::save(sessionId, messages)`
- Session listing CLI: `sugarcrush session list/show/last`

**Estimated complexity:** Medium (4-6h)

**Expected impact:** High — enables practical long-term use

### 6. MCP Additional Transports
**Title:** HTTP and SSE transport support beyond stdio

**Description:** Currently McpClient only supports stdio transport (Claude Code). MCP spec supports stdio, HTTP, and SSE transports.

**Why it matters:** MCP is the primary extensibility mechanism for AI tools. Supporting HTTP/SSE enables integration with more MCP servers.

**Source repo:** `docs/repo_map/pr_charmbracelet_crush.md` — MCP transports discussion

**Implementation ideas:**
- Add `McpTransport` interface with `send(Message): ?Message`
- Implement `StdioTransport`, `HttpTransport`, `SseTransport`
- Update `McpClient` to accept transport via constructor
- Support per-server transport configuration

**Estimated complexity:** Medium (4-6h)

**Expected impact:** Medium — opens MCP ecosystem

### 7. Hooks System
**Title:** PreToolUse and other hooks for customization

**Description:** The upstream crush implemented hooks (PR #2598) for PreToolUse with pattern matching. This enables permission checks and arbitrary custom logic without modifying tool implementations.

**Why it matters:** Hooks enable enterprise customization and Claude Code format compatibility without core modifications.

**Source repo:** `docs/repo_map/pr_charmbracelet_crush.md` — PR #2598, Discussion #1336

**Implementation ideas:**
- Add `HookRunner` class with `before/after` hook arrays
- Hook signature: `function(ToolCall): ?ToolResult` (return null = proceed)
- Pattern matching on tool names via regex
- Support for hook files in `~/.config/sugarcraft-crush/hooks/`

**Estimated complexity:** Medium (4-6h)

**Expected impact:** Medium — enables enterprise customization

### 8. Built-in File Tools
**Title:** read/write/edit/glob/grep via ToolRegistry

**Description:** Upstream crush had comprehensive built-in tools: view, edit, bash, grep, etc. sugar-crush has only 5 viewport-related tools (filter, sort, goto, select, quit).

**Why it matters:** A coding assistant needs file manipulation tools. These should be registerable via ToolRegistry.

**Source repo:** `docs/repo_map/pr_charmbracelet_crush.md` — tool system analysis

**Implementation ideas:**
- Add `ReadTool`, `WriteTool`, `EditTool`, `GlobTool`, `GrepTool` classes
- Register via `Chat::registerTool()` in constructor
- Each tool validates paths, handles errors gracefully
- File content passed as tool result string

**Estimated complexity:** Medium (6-8h)

**Expected impact:** High — core functionality for coding assistant

## Medium Priority

### 9. Plan/Build Mode Separation
**Title:** Explicit read-only planning mode

**Description:** Claude Code's shift-tab pattern provides plan mode with enforced read-only restrictions. Upstream crush requested this (issue #1734, 28 reactions) and implemented it (PR #2019).

**Why it matters:** Plan mode is essential for safety-critical workflows — users want to preview changes before autonomous execution.

**Implementation ideas:**
- Add `Chat::withPlanMode(bool)` and `Chat::planMode: bool` state
- When `planMode=true`, tool calls to write tools are blocked with user prompt
- Separate system prompt variant for plan mode
- UI indicator showing plan vs build mode

**Estimated complexity:** Medium (4-6h)

### 10. MCP Client — General Purpose
**Title:** Make MCP client not Claude Code-specific

**Description:** Currently `McpClient::forClaudeCode()` is the only documented factory. The client should be a general-purpose MCP client.

**Why it matters:** MCP is a standard protocol. A general-purpose client enables integration with any MCP-compatible server.

**Implementation ideas:**
- Generic `McpClient::forServer(command, args, transport)` factory
- Remove Claude Code-specific assumptions
- Support `initialize`, `tools/call`, `tools/list` standard methods
- Document JSON-RPC 2.0 message format

**Estimated complexity:** Medium (4-6h)

### 11. Syntax Highlighting
**Title:** Code block highlighting in markdown

**Description:** Currently code blocks render without coloring. The upstream uses glamour for syntax highlighting.

**Why it matters:** Syntax highlighting dramatically improves readability of code snippets.

**Implementation ideas:**
- Integrate `candy-highlight` (if exists) or syntax highlighting library
- Configure via CandyShine options
- Support common languages

**Estimated complexity:** Medium (4-5h)

### 12. CLAUDE.md Loading
**Title:** Auto-load project context files on startup

**Description:** The upstream crush reads AGENTS.md, CRUSH.md, CLAUDE.md, GEMINI.md from the working directory for project-specific instructions.

**Why it matters:** Context files are a standard pattern for providing project-specific instructions to AI coding assistants.

**Implementation ideas:**
- Add `ContextLoader` class to discover context files
- Search: `./CLAUDE.md`, `./.cody/`, `~/.config/sugarcraft-crush/`
- Merge context into system prompt on startup
- Configurable via `SUGARCRUSH_CONTEXT_FILES` env var

**Estimated complexity:** Small (2h)

## Low Priority

### 13. Thinking Blocks
**Title:** Display AI reasoning process (Claude)

**Description:** Claude's thinking blocks are not currently rendered. Upstream crush had this as a future direction.

**Implementation ideas:**
- Detect `thinking` content type from backend
- Render in collapsible/expandable format
- Option to toggle display via config

**Estimated complexity:** Medium (4h)

### 14. Custom Themes
**Title:** Theme switching, user styles

**Description:** No theme system exists currently. Upstream crush used glamour themes (auto/dark/light/pink/notty/dracula/TokyoNight).

**Implementation ideas:**
- Add theme enum with common themes
- Pass theme to CandyShine renderer
- Support custom JSON stylesheets

**Estimated complexity:** Small (2-3h)

### 15. Subagents
**Title:** Background sub-agents with separate contexts

**Description:** Upstream crush had partial subagent support (PR #914) with configurable models per agent type, but full multi-agent was not achieved.

**Implementation ideas:**
- Add `Agent` class with own model, tools, prompts
- Coordinator pattern for agent management
- Inter-agent messaging

**Estimated complexity:** High (10h+)

---

# Algorithm / Performance Opportunities

## Current Approach: Sequential Tool Execution

**Current implementation:**
```php
// Chat.php:121-159
if ($message->toolCalls !== [] && $this->tools !== []) {
    return $this->handleToolCalls($message);
}
```

Tools execute sequentially, blocking the TUI update loop.

## External Approach: Parallel Tool Execution with Semaphore

**Source:** `docs/repo_map/charmbracelet_fantasy.md` — Parallel tool execution with semaphore limiting (max 5 concurrent)

```go
// fantasy/agent.go:1540-1575
parallelSem := make(chan struct{}, 5)  // Max 5 concurrent
// Parallel tools acquire semaphore, sequential use mutex
```

**Why external is better:** Sequential tools can create bottlenecks — a long-running `read` tool blocks all subsequent tools even if they're independent.

**Tradeoffs:** Parallel execution requires careful synchronization and can increase complexity. For simple use cases, sequential is easier to reason about.

**Applicability:** High — would improve responsiveness for tools with I/O operations

## Current Approach: No Caching

**Current implementation:** No caching layer for regex patterns, glob results, or LSP diagnostics.

## External Approach: Cached Compilation with Eviction

**Source:** `docs/repo_map/pr_charmbracelet_crush.md` — PR #2161 fixed unbounded cache growth; PR #2199 fixed gitignore regex compilation caching

```go
// gitignore matching dropped from 80% → ~2% of CPU with native glob + caching
```

**Why external is better:** Unbounded caches cause memory growth; lack of caching causes CPU spikes.

**Tradeoffs:** Cache invalidation is subtle — non-deterministic ordering of tool descriptions breaks cache keys.

**Applicability:** High — any long-running session benefits from caching

## Current Approach: No Request Debouncing

**Source:** `docs/repo_map/pr_charmbracelet_crush.md` — Issue #1455: 27,441 wait events during text rendering with no input debouncing

**Why external is better:** Each keystroke can trigger file system operations without debouncing, causing performance issues at scale.

**Tradeoffs:** Debouncing adds latency for small inputs; acceptable trade-off for large files.

**Applicability:** Medium — only matters with large files

---

# Architecture Improvements

## 1. Event Broker with Backpressure

**Problem:** The upstream crush had a pubsub broker with 2-second timeout that dropped messages, causing UI state desync when overloaded (v0.40.0 regression).

**Solution:** Any async event system must implement proper backpressure.

```php
// Event broker with timeout and per-event-type subscription
final class EventBroker {
    private array $subscribers = [];
    private int $timeoutMs = 2000;
    
    public function publish(ToolResultEvent $event): void {
        // Check subscription before publishing
        // Timeout with fallback behavior
        // Log dropped events, don't silently swallow
    }
}
```

**Reference:** `docs/repo_map/pr_charmbracelet_crush.md` — "Event Broker with Backpressure" architectural lesson

## 2. Service-Based Configuration

**Problem:** Current config is env-var driven without a clear service abstraction.

**Solution:** Configuration should be a service accessed via dependency injection.

```php
final class ConfigService {
    public function __construct(
        private readonly string $maxSteps,
        private readonly string $contextThreshold,
        private readonly bool $planMode,
    ) {}
    
    public static function fromEnvironment(): self { ... }
}
```

**Reference:** `docs/repo_map/pr_charmbracelet_crush.md` — "Service-Based Configuration" architectural lesson

## 3. Tool Decorator Pattern

**Problem:** Tools currently have no middleware support (hooks, logging, etc.).

**Solution:** Use decorator pattern for tool middleware.

```php
final class HookedTool implements Tool {
    public function __construct(
        private readonly Tool $inner,
        private readonly HookRunner $hooks,
    ) {}
    
    public function execute(ToolCall $call): ToolResult {
        $hookedResult = $this->hooks->before($call);
        if ($hookedResult !== null) return $hookedResult;
        $result = $this->inner->execute($call);
        return $this->hooks->after($call, $result);
    }
}
```

**Reference:** `docs/repo_map/pr_charmbracelet_crush.md` — "Tool Decorator Pattern"

## 4. Design for Remote Operation

**Problem:** sugar-crush is tightly coupled to TUI.

**Solution:** Abstract workspace/state access behind interfaces from the beginning.

```php
interface WorkspaceAccess {
    public function readFile(string $path): string;
    public function writeFile(string $path, string $content): void;
    public function listDirectory(string $path): iterable;
}

final class Chat {
    public function __construct(
        private readonly WorkspaceAccess $workspace,
        // ...
    ) {}
}
```

**Reference:** `docs/repo_map/pr_charmbracelet_crush.md` — PR #2455 introduced Workspace abstraction

---

# API / Developer Experience Improvements

## 1. Provider Abstraction Layer

**Problem:** Backend is a simple interface; no built-in HTTP client or provider SDK integration.

**Solution:** Build a `Provider` interface like fantasy's:

```php
interface Provider {
    public function name(): string;
    public function languageModel(string $modelId): LanguageModel;
}

interface LanguageModel {
    public function generate(Context $context, Options $options): Response;
    public function stream(Context $context, Options $options): \Generator<ResponseDelta>;
}
```

**Reference:** `docs/repo_map/charmbracelet_fantasy.md` — Provider/LanguageModel interfaces

## 2. JSON Schema Generation for Tools

**Problem:** Tools must manually define their JSON schema.

**Solution:** Auto-generate schema from PHP type hints:

```php
final class ToolSchemaGenerator {
    public static function generate(callable $fn): array {
        // Reflect on parameter types, generate JSON schema
    }
}
```

**Reference:** `docs/repo_map/charmbracelet_fantasy.md` — schema.Generate() from Go types

## 3. Structured Outputs

**Problem:** No support for type-safe object generation.

**Solution:** Implement `object.Generate[T]()` pattern:

```php
final class StructuredOutput {
    public static function generate(
        LanguageModel $model,
        Context $context,
        string $schema,
    ): object {
        // Generate JSON conforming to schema
        // Return typed object
    }
}
```

**Reference:** `docs/repo_map/charmbracelet_fantasy.md` — object.Generate[T]()

## 4. Retry Logic with Backoff

**Problem:** Backend failures have no retry logic.

**Solution:** Implement exponential backoff respecting retry headers:

```php
final class RetryOptions {
    public int $maxRetries = 2;
    public int $initialDelayMs = 2000;
    public float $backoffFactor = 2.0;
}

final class BackendAdapter {
    public function completeWithRetry(
        array $history,
        RetryOptions $options = null,
    ): Message {
        // Retry with exponential backoff
        // Respect Retry-After header from provider
    }
}
```

**Reference:** `docs/repo_map/charmbracelet_fantasy.md` — RetryWithExponentialBackoffRespectingRetryHeaders

---

# Documentation / Cookbook Opportunities

## 1. Backend Adapter Examples

**Create a custom backend for different providers:**
- OpenAI-compatible API
- Anthropic Direct
- Ollama local
- LM Studio

**Reference:** `docs/repo_map/charmbracelet_fantasy.md` — Multi-provider support

## 2. Tool Development Guide

**Register custom tools:**
- File system tools (read, write, edit, glob, grep)
- Shell command execution
- Git operations
- Web search

**Reference:** `docs/repo_map/pr_charmbracelet_crush.md` — "Tool Self-Documentation" pattern

## 3. MCP Server Integration

**Connect to MCP servers:**
- File management MCP
- Database MCP
- Cloud provider MCPs
- Custom MCP servers

## 4. Hook Recipes

**Common hook patterns:**
- Permission prompting
- Command logging
- Token budget tracking
- Custom tool filtering

---

# UX / TUI Improvements

## 1. Real-time Token Display

**Problem:** Users see no feedback while waiting for AI response.

**Solution:** Implement partial token rendering as described in Feature Gap Analysis.

## 2. Status Indicators

**Problem:** No indication of token usage, context budget, or tool execution progress.

**Solution:** Add status bar with:
- Token count / context limit
- Active tool indicator (spinner)
- Model name
- Connection status

## 3. Command Palette

**Problem:** Slash commands are the only command interface.

**Solution:** Add fuzzy-searchable command palette (like VS Code Ctrl+Shift+P):

```php
// CmdPalette style
// / to open
// Fuzzy search commands
// Execute on Enter
```

**Reference:** `docs/repo_map/textualize_textual.md` — Command Palette

## 4. Inline Tool Preview

**Problem:** Users must approve tools without seeing what will be executed.

**Solution:** Show expanded command preview before execution:
```
Tool: bash
Command: git diff --staged
─────────────────────────────────
Confirm [Enter]  Cancel [Esc]
```

## 5. Session Browser

**Problem:** No UI for browsing/resuming past sessions.

**Solution:** Add session list TUI:
- List sessions with timestamps
- Show message count, last model used
- Resume or delete actions
- Search/filter sessions

---

# Testing / Reliability Improvements

## 1. VCR-Based Integration Tests

**Problem:** No integration tests with real provider APIs.

**Solution:** Record provider interactions and replay in tests:

```php
// Same pattern as fantasy's VCR-based tests
final class BackendIntegrationTest {
    public function testAnthropicCompletion(): void {
        $cassette = __DIR__ . '/cassettes/anthropic_echo.json';
        // Record or replay API calls
    }
}
```

**Reference:** `docs/repo_map/charmbracelet_fantasy.md` — "Testing Infrastructure: VCR-based integration tests"

## 2. Tool Call Property-Based Testing

**Problem:** Limited test coverage for edge cases in tool arguments.

**Solution:** Use property-based testing for tool schemas:

```php
public function testToolSchemaGeneration(): void {
    // Generate random valid inputs for tool parameters
    // Verify round-trip: schema → validate → execute → result
}
```

## 3. Snapshot Testing for Renderer

**Problem:** No automated visual regression testing.

**Solution:** Snapshot tests for SGR byte output:

```php
public function testRendererOutput(): void {
    $chat = new Chat(/* ... */);
    $output = Renderer::render($chat);
    $this->assertSameSnapshot('chat_basic', $output);
}
```

**Reference:** `docs/repo_map/pr_charmbracelet_crush.md` — Testing approach

## 4. Chaos Testing for Tool Execution

**Problem:** No testing for failure modes (timeouts, network errors, etc.).

**Solution:** Inject failures in tests:

```php
public function testToolTimeoutHandling(): void {
    $this->toolRegistry->register('slow', fn() => usleep(10000000));
    // Assert graceful timeout handling
}
```

---

# Ecosystem / Integration Opportunities

## 1. MCP Client/Server in PHP

**Problem:** No native PHP MCP implementation.

**Solution:** Implement MCP client and server in PHP:

```php
// Client
final class McpClient {
    public function listTools(): array;
    public function callTool(string $name, array $params): ToolResult;
}

// Server (for building MCP tools in PHP)
final class McpServer {
    public function registerTool(Tool $tool): void;
    public function run(): void;  // stdio server loop
}
```

**Reference:** `docs/repo_map/pr_charmbracelet_crush.md` — "MCP Ecosystem Integration" strategic opportunity

## 2. Agent Skills Standard

**Problem:** No skill discovery system like Claude Code's SKILL.md.

**Solution:** Implement file-based skill discovery:

```php
// ~/.config/sugarcraft-crush/skills/
// ├── read-file/SKILL.md
// └── git-commands/SKILL.md
```

**Reference:** `docs/repo_map/pr_charmbracelet_crush.md` — "Agent Skills (SKILL.md)"

## 3. TreeSitter Integration

**Problem:** No AST-aware code understanding.

**Solution:** Consider TreeSitter PHP grammar for:
- Context packing (feed only relevant AST nodes)
- AST-aware find-refs/go-to-def
- Safer edits with blast-radius analysis

**Reference:** `docs/repo_map/pr_charmbracelet_crush.md` — "TreeSitter Integration" discussion

## 4. HTTP/API Interface

**Problem:** sugar-crush is TUI-coupled; no programmatic access.

**Solution:** Add server mode:

```bash
sugarcrush server  # REST API over Unix socket
curl -X POST http://localhost:8080/chat -d '{"message": "hello"}'
```

**Reference:** `docs/repo_map/pr_charmbracelet_crush.md` — PR #2455 server-client architecture

---

# Notable PRs / Issues / Discussions

## 1. PR #2455 — Server-Client Architecture (18,070 additions)

**Summary:** Refactored crush from a monolithic TUI into a server/client architecture with REST API.

**Relevance:** This is the most significant architectural change in crush's lifetime, enabling programmatic access and multiple UIs.

**Lessons learned:** Converting a monolithic app to client/server after the fact is expensive. SugarCraft should design with a service boundary from the beginning.

**Source:** `docs/repo_map/pr_charmbracelet_crush.md`

## 2. Issue #2130 — Infinite Tool Call Loops (FIXED)

**Summary:** Agent got into infinite loops. Fixed by PR #2214 with loop detection (same tool called >5 times in last 10 steps) and max steps limit.

**Relevance:** Loop detection is non-negotiable for autonomous agents.

**Lessons learned:** The absence of loop detection allowed infinite loops to consume resources and context. This must be built in from day one.

**Source:** `docs/repo_map/pr_charmbracelet_crush.md`

## 3. Issue #2368 — Safelist Bypass (SECURITY CRITICAL)

**Summary:** `echo "data" > file.txt` bypassed permission prompts because `echo` was on the safe-list.

**Relevance:** "Safe" commands are only safe if you analyze the full command line.

**Lessons learned:** Never trust a command-only safelist. Analyze the full command string, including redirects, pipes, and compound operations.

**Source:** `docs/repo_map/pr_charmbracelet_crush.md`

## 4. v0.40.0 Performance Regression

**Summary:** Multiple issues opened within days: "slow", "high CPU", "context clearing".

**Root causes:**
- Global `sync.Mutex` replacing lock-free `csync.Map`
- Tool result handling from up to 5 concurrent goroutines without synchronization
- Sequential tools blocking ALL dispatch (including parallel ones)

**Relevance:** Concurrency bugs in agent loops cause "invisible" failures.

**Lessons learned:** Every shared state access in concurrent code must be synchronized.

**Source:** `docs/repo_map/pr_charmbracelet_crush.md`

## 5. PR #2199 — Gitignore Pattern Matching Caused 80% CPU

**Summary:** `sabhiram/go-gitignore` (which compiles each pattern to regex) dominated CPU in large monorepos.

**Fix:** Replaced with `go-git/go-git` native glob matching plus two-level caching. Gitignore matching dropped from 80% → ~2% of CPU.

**Lessons learned:** For large codebases, every regex compilation must be cached and every directory walk must be gitignore-filtered. Native glob is faster than regex-based matching.

**Source:** `docs/repo_map/pr_charmbracelet_crush.md`

---

# Recommended Roadmap

## Immediate Wins (0-2 months)

### 1. Loop Detection (P0)
- Add `maxSteps` parameter
- Track tool call frequency with sliding window
- Block repeated tool calls

### 2. Streaming UI (P1)
- Add partial message state to Chat
- Handle `PartialTokenMsg` in update loop
- Show partial content with trailing cursor

### 3. Chat History Persistence (P1)
- Add `ChatHistory` class
- Persist to JSONL files
- Session listing/showing CLI

### 4. Context Budget Management (P1)
- Add `TokenCounter` service
- Track tokens used/remaining
- Implement compaction trigger

## Medium-term Improvements (2-6 months)

### 5. Built-in File Tools
- Register read/write/edit/glob/grep
- Validate paths, handle errors gracefully

### 6. Hooks System
- Add `HookRunner` class
- Support PreToolUse and PostToolUse hooks
- Pattern matching on tool names

### 7. Permission System
- Pattern-based matching for tool names
- Per-invocation granularity
- Confirmation prompts with caching

### 8. MCP Additional Transports
- HTTP and SSE transport support
- General-purpose `McpClient`

## Major Architectural Upgrades (6-12 months)

### 9. Provider Abstraction Layer
- `Provider` interface
- Built-in HTTP backends for OpenAI/Anthropic/Ollama
- Retry logic with exponential backoff

### 10. Server-Client Architecture
- REST API over Unix socket
- Headless operation mode
- Multiple UI clients

### 11. Subagents
- `Agent` class with own model/tools/prompts
- Coordinator for multi-agent management
- Inter-agent messaging

## Experimental Ideas

### 12. TreeSitter Integration
- AST-aware code understanding
- Context packing with relevant nodes
- Blast-radius analysis for edits

### 13. RAG Integration
- Context file loading + semantic search
- Embeddings stored in vector DB
- AST-based chunking for PHP code

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|-------------|--------|------------|------|----------|
| Loop Detection | Critical | Medium | Low | P0 |
| Streaming UI | High | Medium | Low | P1 |
| Chat History Persistence | High | Medium | Medium | P1 |
| Context Budget Management | High | Medium | Medium | P1 |
| Built-in File Tools | High | Medium | Low | P1 |
| Permission System | Critical | High | High | P0 |
| Hooks System | Medium | Medium | Low | P2 |
| MCP Additional Transports | Medium | Medium | Low | P2 |
| Provider Abstraction | High | High | Medium | P2 |
| Plan/Build Mode | Medium | Medium | Low | P2 |
| Server-Client Architecture | Medium | High | High | P3 |
| Subagents | Medium | High | High | P3 |
| TreeSitter Integration | Low | High | High | P4 |
| Syntax Highlighting | Low | Medium | Low | P4 |

---

# Final Strategic Assessment

**sugar-crush** is a well-architected PHP port of charmbracelet/crush that successfully captures the core TUI patterns for AI coding assistants. Its strengths — immutable + fluent architecture, pluggable backends, graceful degradation, comprehensive test suite — provide a solid foundation for further development.

The most critical gaps are **loop detection** and **permission systems** — both required for safe autonomous tool use. These should be implemented before any public release that enables autonomous operation.

The upstream crush's archival in March 2026 creates a strategic opportunity: PHP developers need an equivalent for AI-assisted coding, and sugar-crush is positioned to fill that gap. However, the full vision requires:

1. **AI agent infrastructure** — Beyond simple tool calling, a true agent loop with step management, context compaction, and multi-step reasoning
2. **MCP ecosystem integration** — stdio, HTTP, and SSE transports to connect to the growing MCP tool ecosystem
3. **Provider abstraction** — Clean interfaces for multiple LLM backends without shell-out wrappers
4. **Permission system** — Robust, designed upfront, not reactively

The most important lessons from the upstream crush's 18 months of active development are:
- **Permission systems must be foundational**, not incremental
- **Loop detection is non-negotiable** for autonomous agents
- **Context budget management** requires explicit design
- **Concurrency bugs** in agent loops cause silent, catastrophic failures
- **Server-client architecture** should be designed in from the start

sugar-crush should prioritize immediate reliability improvements (loop detection, streaming UI, chat persistence) before medium-term extensibility features (hooks, MCP transports, provider abstraction). The PHP ecosystem needs a credible AI coding assistant, and sugar-crush is the foundation for that — but it needs to become a true agent before it can fulfill that promise.
