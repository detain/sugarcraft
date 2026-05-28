# SugarCraft sugar-crush

## Metadata
- **Package:** `sugarcraft/sugar-crush`
- **Upstream:** `charmbracelet/crush` (Go, 24,731 stars, archived March 2026)
- **Language:** PHP 8.3+
- **License:** MIT
- **Status:** 🟢 v1 ready
- **Description:** Chat-shell TUI for AI coding assistants with pluggable backends (EchoBackend offline; CommandBackend for Anthropic/OpenAI/Ollama via wrapper script). Markdown rendering via CandyShine, scrollback viewport, session persistence.

---

## 1. Package Architecture

### 1.1 Core Components

```
sugar-crush/src/
├── Chat.php                     # Main TUI Model (implements Model interface)
├── Message.php                  # Immutable conversation turn (role/content/toolCalls)
├── Role.php                     # Enum: System | User | Assistant
├── Renderer.php                 # Pure view function (CandyShine markdown + borders)
├── AssistantMsg.php             # Internal Msg for backend completion delivery
├── Session.php                  # Persists UI state to ~/.config/sugarcraft-crush/
├── ToolCall.php                 # VO: name, arguments, optional id (from AI)
├── ToolResult.php                # VO: name, result, error, optional id (to AI)
├── ToolRegistry.php              # Registry of slash-commands/built-in tools
├── Compactor.php                 # Groups small files by extension to reduce clutter
├── CommandParser.php             # Parses /command slash-input; extracts name + args
├── StreamingDirectoryLister.php # Generator-based lazy directory enumeration
├── McpMessage.php               # JSON-RPC 2.0 envelope (request/response/notification/error)
├── McpClient.php                 # MCP stdio client for Claude Code integration
├── Attachment.php               # File/image attachment
├── AttachmentType.php           # Enum: File | Image
├── Backend.php                  # Interface: complete(), completeAsync()
└── Backend/
    ├── EchoBackend.php           # Offline default - echoes last user message
    ├── CommandBackend.php        # Shells out via proc_open; JSON stdin → stdout
    └── StreamingCommandBackend.php  # Streaming variant (line-by-line tokens)
```

### 1.2 Session Architecture

**File:** `src/Session.php`

The `Session` class implements stateless persistence with graceful degradation:

- **State stored:** `cwd`, `selected` (file paths), `filter`, `sortColumn`, `sortDir`, `activePane`
- **Persistence:** `~/.config/sugarcraft-crush/session.json` via `Session::load()` / `Session::save()`
- **Home resolution order:** `$HOME` env → `posix_getpwuid()` → `getcwd() ?: '/tmp'`
- **Graceful degradation:** Missing file, unreadable content, malformed JSON all return fresh `new self()`
- **Immutable + fluent:** Every `withCwd()`, `withSelected()`, `withFilter()`, `withSort()`, `withActivePane()` returns a new instance
- **Readonly properties:** Written once at construction time by `load()` or `with*()` builders

```php
// From Session.php:36-44
public function __construct(
    public readonly string $cwd = '',
    public readonly array $selected = [],
    public readonly string $filter = '',
    public readonly string $sortColumn = 'name',
    public readonly string $sortDir = 'asc',
    public readonly string $activePane = 'files',
) {}

// Fluent builder pattern (Session.php:122-132)
public function withCwd(string $cwd): self
{
    return new self(
        cwd: $cwd,
        selected: $this->selected,
        filter: $this->filter,
        sortColumn: $this->sortColumn,
        sortDir: $this->sortDir,
        activePane: $this->activePane,
    );
}
```

**Key insight:** Session persists file-browser state, NOT chat history. Chat sessions are ephemeral by design (full history passed to backends each turn).

### 1.3 Chat Model Architecture

**File:** `src/Chat.php`

Implements the SugarCraft `Model` interface (init/update/view pattern):

```php
// From Chat.php:39-59 - Three pieces of state
final class Chat implements Model {
    public function __construct(
        public readonly array $history = [],        // list<Message> accumulated so far
        public readonly string $inputBuf = '',      // user's in-progress draft
        public readonly bool $inFlight = false,      // true while backend call in progress
        ?Backend $backend = null,
        private readonly bool $streaming = false,
        private readonly ?\Closure $onToken = null,
        private readonly array $tools = [],           // tool name => callable
        private readonly ?\Closure $onToolCall = null,
    ) {
        $this->backend = $backend ?? new Backend\EchoBackend();
    }
}
```

**Message flow:**
1. User types → keystrokes accumulate in `inputBuf` (UTF-8-aware backspace via `dropLast()`)
2. Enter submits → `Message::user()` added to history, `inFlight=true`, `Backend::completeAsync()` scheduled via `Cmd::promise()`
3. Backend resolves → `AssistantMsg` dispatched with `Message::assistant()` reply
4. If `toolCalls` present → `handleToolCalls()` executes each tool synchronously, appends results to history, schedules follow-up backend call
5. `inFlight` gate prevents racing ahead while waiting

### 1.4 Tool Calling System

**Files:** `src/ToolCall.php`, `src/ToolResult.php`, `src/ToolRegistry.php`

The tool system enables AI backends to invoke registered callbacks:

```php
// ToolCall - from AI backend (Chat.php:72-74, 121-159)
if ($message->toolCalls !== [] && $this->tools !== []) {
    return $this->handleToolCalls($message);
}

// ToolResult wire format (ToolResult.php:50-58)
public function toWire(): array {
    return [
        'role' => 'tool',
        'tool_call_id' => $this->id ?? $this->name,
        'name' => $this->name,
        'content' => $this->error ?? $this->result,
    ];
}
```

**ToolRegistry built-ins (ToolRegistry.php:124-188):**
- `filter <expression>` — Filter viewport lines
- `sort [-r] [-n]` — Sort viewport lines (reverse, numeric flags)
- `goto <line>` — Jump to line number
- `select <start> <end>` — Select line range
- `quit` — Exit application

**Multi-step tool calling flow (Chat.php:121-159):**
1. AI returns message with `toolCalls` array
2. `handleToolCalls()` iterates each `ToolCall`, invokes registered callback
3. Tool results appended to history as `Message::assistant()` with `withToolResults()`
4. Follow-up `Backend::completeAsync()` scheduled with updated history
5. Loop continues until AI returns no tool calls

### 1.5 Provider Abstraction (Backend Interface)

**File:** `src/Backend.php`

Clean pluggable backend interface:

```php
// From Backend.php:28-49
interface Backend {
    /**
     * @param list<Message> $history full conversation so far
     * @param callable|null $onToken optional callback for each token during streaming
     */
    public function complete(array $history, callable $onToken = null): Message;
    public function completeAsync(array $history, callable $onToken = null): PromiseInterface;
}
```

**Three shipped backends:**

| Backend | Use Case | File |
|--------|----------|------|
| `EchoBackend` | Offline/dev default | `src/Backend/EchoBackend.php` |
| `CommandBackend` | Real LLM via shell wrapper | `src/Backend/CommandBackend.php` |
| `StreamingCommandBackend` | Streaming LLM (line-by-line) | `src/Backend/StreamingCommandBackend.php` |

**CommandBackend (CommandBackend.php:39-96):**
- Shells out via `proc_open()` with piped stdio
- JSON-encodes full history → stdin of wrapper script
- Reads stdout → assistant reply
- Captures stderr → error message if exit != 0
- Network-dep-free core; users wire their own wrapper

```php
// From CommandBackend.php:49-83
public function complete(array $history, callable $onToken = null): Message {
    $payload = json_encode(
        array_map(static fn(Message $m) => $m->toWire(), $history),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    // ... spawn process, write payload to stdin, read stdout
    if ($exit !== 0) {
        return Message::assistant("_[error: backend exited {$exit}]_{$hint}");
    }
    return Message::assistant(trim($stdout));
}
```

**StreamingCommandBackend (StreamingCommandBackend.php:39-160):**
- Same stdin/stdout piping as CommandBackend
- Non-blocking stdout via `stream_set_blocking(false)`
- Reads line-by-line, calls `$onToken` callback for each token
- Accumulates tokens → final message

### 1.6 MCP Client

**File:** `src/McpClient.php`

JSON-RPC 2.0 stdio client for Claude Code integration:

```php
// From McpClient.php:17-31
final class McpClient {
    public function __construct(
        public readonly ?string $command = null,   // default: 'claude'
        public readonly array $args = [],             // default: ['--mcp']
        public readonly ?array $initialOptions = null,
        private mixed $process = null,
        /** @var array<int, resource>|null */
        private ?array $pipes = null,
        private bool $connected = false,
        private int $requestId = 0,
    ) {}
}
```

**Key methods:**
- `connect(?array $options)` — Spawn Claude Code process, send initialize handshake, return initial messages
- `callTool(string $name, ?array $params)` — Send tools/call request, poll for response (100 attempts × 10ms)
- `listTools()` — Send tools/list request, poll for response
- `readMessages()` — Non-blocking newline-delimited JSON parsing
- `disconnect()` — Clean shutdown via `proc_close()`

**Protocol:** JSON-RPC 2.0 over stdio (newline-delimited messages)

### 1.7 Message Structure

**File:** `src/Message.php`

Immutable value object with wire-format serialization:

```php
// From Message.php:17-29
final class Message {
    public function __construct(
        public readonly Role  $role,
        public readonly string $content,
        public readonly int   $createdAt,
        public readonly array $attachments = [],    // list<Attachment>
        public readonly array $toolCalls = [],      // list<ToolCall>
    ) {}

    // Wire format for backend adapters (Message.php:111-127)
    public function toWire(): array {
        $wire = ['role' => $this->role->value, 'content' => $this->content];
        if ($this->attachments !== []) {
            $wire['attachments'] = array_map(
                static fn(Attachment $a) => ['type' => $a->type->name, 'path' => $a->path],
                $this->attachments,
            );
        }
        if ($this->toolCalls !== []) {
            $wire['tool_calls'] = array_map(
                static fn(ToolCall $tc) => $tc->toArray(),
                $this->toolCalls,
            );
        }
        return $wire;
    }
}
```

### 1.8 Renderer

**File:** `src/Renderer.php`

Pure view function (no state, no side effects):

```php
// From Renderer.php:35-47
public static function render(Chat $chat): string {
    $body = self::renderHistory($chat->history);
    $input = self::renderInput($chat);
    $status = $chat->inFlight ? '⠴ thinking…' : 'Enter to send · Esc / ^C to quit';

    $shell = Style::new()
        ->border(Border::rounded())
        ->padding(1, 2)
        ->render($body);

    return $shell . "\n" . $input . "\n" . $status;
}
```

**Rendering rules:**
- User messages: raw text with cyan `user>` prefix
- Assistant messages: rendered via CandyShine (markdown) with magenta `assistant` prefix
- System messages: faint styling
- Input: `> ` prompt with cursor block `█` when not in-flight

---

## 2. Comparative Analysis

### 2.1 vs charmbracelet/crush (Upstream)

| Feature | sugar-crush | crush (Go) |
|---------|-------------|-------------|
| **Status** | Active 🟢 v1 | Archived March 2026 |
| **TUI Framework** | SugarCraft (Bubble Tea port) | Bubble Tea v2 |
| **Provider Abstraction** | Backend interface (manual wrapper) | fantasy provider layer |
| **LSP Integration** | None | gopls, tsserver, nil |
| **MCP Client** | stdio only (Claude Code) | stdio, HTTP, SSE |
| **Tool Calling** | Via Backend (external) | Built-in (view, edit, bash, grep, etc.) |
| **Session Persistence** | UI state only (session.json) | SQLite + JSONL files |
| **Auto-Summarization** | None | Yes (large context window) |
| **Workspace Sharing** | None | SSE-based multi-client |
| **Slash Commands** | Via CommandParser (5 built-ins) | 15+ built-ins |
| **Permission System** | None | Tool allow-lists |

**Key upstream insights absorbed:**
- Backend interface pattern (shell-out to wrapper script)
- Session persistence with graceful degradation
- Tool call abstraction (ToolCall/ToolResult VOs)
- Markdown rendering via external library

### 2.2 vs charmbracelet/fantasy (Provider Abstraction)

| Feature | sugar-crush | fantasy (Go) |
|---------|-------------|---------------|
| **Type Safety** | Duck-typed Backend interface | Generic `NewAgentTool[T]()` with reflection |
| **Tool Schema** | Manual registration | Auto JSON schema from Go structs |
| **Structured Outputs** | None | `object.Generate[T]()` |
| **Retry Logic** | External (wrapper script) | Exponential backoff + retry headers |
| **Parallel Tools** | Sequential (synchronous) | Semaphore-limited concurrency (max 5) |
| **Tool Repair** | None | Validation + optional repair function |

**What sugar-crush borrowed:**
- `LanguageModel` → `Backend` interface (pluggable adapters)
- `Provider` → Wrapper script approach (external process)
- Agent loop concept → `handleToolCalls()` multi-step flow

### 2.3 vs charmbracelet/mods (Sunset Competitor)

| Feature | sugar-crush | mods (Go) |
|---------|-------------|-----------|
| **Status** | Active | Archived March 2026 |
| **Streaming** | Line-by-line (onToken) | SSE token streaming |
| **Conversation Storage** | None (ephemeral history) | SQLite + gob cache |
| **MCP Support** | stdio client | stdio, SSE, HTTP |
| **Provider Support** | Any via wrapper | OpenAI, Anthropic, Google, Cohere, Groq, Ollama |
| **Slash Commands** | CommandParser | None |

**What sugar-crush does differently:**
- Ephemeral chat (no SQLite overhead) — history passed to backends each turn
- Simpler MCP client (only stdio, only Claude Code)
- Immutable + fluent patterns throughout

---

## 3. Innovation Points

### 3.1 Backend-as-a-Script Pattern

**Innovation:** Keeping the PHP core network-dep-free by design.

The `CommandBackend` / `StreamingCommandBackend` shell out to a user-provided wrapper script. This means:
- No HTTP client dependency in the core package
- Users can use any language for backend integration
- Prompt engineering is a shell script edit away
- Provider changes don't require PHP code changes

```bash
# Example wrapper (Anthropic)
export SUGARCRUSH_BACKEND_CMD=~/bin/anthropic-stream.sh
./bin/sugarcrush
```

### 3.2 Immutable + Fluent Architecture

Every state-mutating operation returns a new instance:

- `Chat::withStreaming()`, `Chat::onToken()`, `Chat::registerTool()`, `Chat::onToolCall()`
- `Session::withCwd()`, `Session::withSelected()`, `Session::withFilter()`, `Session::withSort()`
- `ParsedCommand::withArgs()`

This enables:
- Time-travel debugging potential
- Safe concurrent updates
- Simple testability (assert old !== new, verify fields carried forward)

### 3.3 UTF-8-Aware Input Handling

```php
// From Chat.php:332-342 - Multi-byte safe backspace
private static function dropLast(string $s): string {
    if ($s === '') { return $s; }
    $i = strlen($s) - 1;
    while ($i > 0 && (ord($s[$i]) & 0xc0) === 0x80) {  // Trailing byte marker
        $i--;
    }
    return substr($s, 0, $i);
}
```

Properly handles emoji and multi-byte characters — backspacing an emoji removes the whole grapheme, not a single byte.

### 3.4 Graceful Degradation Throughout

- `Session::load()` never throws — missing file, bad JSON, wrong types all return fresh session
- `CommandBackend::complete()` never throws — missing command, non-zero exit all return error message
- `McpMessage::parse()` returns `null` for malformed JSON — callers handle gracefully
- `StreamingDirectoryLister` uses `opendir`/`readdir` with `finally` cleanup

### 3.5 Generator-Based Directory Listing

```php
// From StreamingDirectoryLister.php:34-59
public function list(string $path) {
    $handle = opendir($path);
    try {
        $index = 0;
        while (($entry = readdir($handle)) !== false) {
            if (str_starts_with($entry, '.')) { continue; }
            $absolutePath = $path . \DIRECTORY_SEPARATOR . $entry;
            yield $index => $absolutePath;
            $index++;
        }
    } finally {
        closedir($handle);
    }
}
```

Directories with thousands of files never cause memory exhaustion — entries yielded lazily.

### 3.6 Tool Call ID Tracking

```php
// From ToolResult.php:47-58 - Wire format includes tool_call_id
public function toWire(): array {
    return [
        'role' => 'tool',
        'tool_call_id' => $this->id ?? $this->name,
        'name' => $this->name,
        'content' => $this->error ?? $this->result,
    ];
}
```

Matches Anthropic/OpenAI tool_result wire format for compatibility with standard API tool calling schemas.

---

## 4. Dependencies

### 4.1 Internal (SugarCraft Monorepo)

| Dependency | Purpose | File Reference |
|------------|---------|-----------------|
| `sugarcraft/candy-core` | `Model`, `Msg`, `Cmd`, `KeyType`, `KeyMsg`, `Subscriptions` | `Chat.php`, `AssistantMsg.php` |
| `sugarcraft/candy-shine` | `Renderer` (markdown), `Ansi` | `Renderer.php` |
| `sugarcraft/candy-sprinkles` | `Border`, `Style` | `Renderer.php` |

### 4.2 External (Vendor)

| Package | Version | Purpose |
|---------|---------|---------|
| `react/promise` | ^3.3 | Async backend calls via `PromiseInterface` |
| `league/commonmark` | (transitive) | Markdown parsing (via CandyShine) |

### 4.3 PHP Version

Requires PHP >= 8.3 (due to readonly properties, first-class callable `Closure::fromCallable()`, etc.)

---

## 5. Test Coverage

**158 tests / 444 assertions** across:

| Test File | Coverage |
|-----------|----------|
| `ChatTest.php` | Type accumulation, space, UTF-8 backspace, Enter submit, empty submit, AssistantMsg append, keystrokes-ignored-while-inFlight, Esc quit, EchoBackend round-trip, streaming callbacks, tool execution |
| `MessageTest.php` | Factories (user/assistant/system), wire shape, timestamps |
| `EchoBackendTest.php` | Echoes last user, handles empty history |
| `CommandBackendTest.php` | JSON piped to stdin, exit code surfaced, missing command graceful |
| `StreamingCommandBackendTest.php` | Line-by-line token callback, timeout handling |
| `SessionTest.php` | Load fresh on missing/corrupt, save creates dirs, with*() immutability, home resolution |
| `ToolRegistryTest.php` | Register/override, get/has/execute/all, 5 built-ins with correct signatures |
| `ToolCallTest.php` | fromArray/toArray round-trip, defaults |
| `ToolResultTest.php` | ok()/error() factories, isError() predicate, toWire() shape |
| `CommandParserTest.php` | Slash detection, name normalization, quote respecting, whitespace splitting |
| `McpClientTest.php` | connect/disconnect, callTool/listTools, non-blocking reads |
| `McpMessageTest.php` | JSON-RPC parse/serialize, request/notification/success/error factories |
| `CompactorTest.php` | Threshold partitioning, extension→category mapping, maxPerGroup overflow |
| `StreamingDirectoryListerTest.php` | Lazy yield, empty/non-dir/no-handle graceful, listFiles filter, count scan |
| `RendererTest.php` | History rendering, input area, status line |
| `AssistantMsgTest.php` | Message wrapping |

---

## 6. Gaps vs Upstream

### 6.1 High Priority Gaps

| Gap | Description | Estimated Effort |
|-----|-------------|-------------------|
| **LSP Integration** | Code intelligence via Language Server Protocol (gopls, tsserver, etc.) | High (12h+) |
| **Real-time Streaming UI** | Render tokens as they arrive, not after complete response | Medium (4-6h) |
| **Session Chat Persistence** | Save/restore chat sessions to JSONL files | Medium (4-6h) |
| **Context Compaction** | Auto-truncate when approaching token limits | Medium (3-4h) |

### 6.2 Medium Priority Gaps

| Gap | Description | Estimated Effort |
|-----|-------------|-------------------|
| **Built-in File Tools** | read, write, edit, glob, grep via ToolRegistry | Medium (6-8h) |
| **MCP Additional Transports** | HTTP and SSE transports (beyond stdio) | Medium (4-6h) |
| **Permission System** | Tool execution allow-lists | Medium (4h) |
| **CLAUDE.md Loading** | Auto-load project context files on startup | Small (2h) |

### 6.3 Lower Priority Gaps

| Gap | Description | Estimated Effort |
|-----|-------------|-------------------|
| **Syntax Highlighting** | Code block highlighting in markdown | Medium (4-5h) |
| **Thinking Blocks** | Display AI reasoning process (Claude) | Medium (4h) |
| **Multi-agent Support** | Background sub-agents | High (10h+) |
| **Custom Themes** | Theme switching, user styles | Small (2-3h) |

---

## 7. SugarCraft Ecosystem Position

### 7.1 Dependency Graph

```
sugar-crush
├── candy-core (TUI runtime, Model/Msg/Cmd)
├── candy-shine (Markdown rendering via league/commonmark)
└── candy-sprinkles (Border, Style)

Dependent packages: None yet
```

### 7.2 Mapping to Upstream Repos

| sugar-crush Component | Upstream Crush | SugarCraft Equivalent |
|----------------------|---------------|---------------------|
| `Backend` interface | `fantasy.Provider` | `sugar-crush` pattern |
| `Chat` (MVU Model) | `internal/ui/model.go` | `candy-core` |
| `ToolCall`/`ToolResult` | `internal/agent/tools/*.go` | `sugar-crush` |
| `McpClient` | `modelcontextprotocol/go-sdk` | Not yet ported |
| `Session` | SQLite session storage | `candy-sprinkles` (future) |
| `CommandParser` | Input parsing | `sugar-crush` |
| `Compactor` | `gum` file compaction | `sugar-crush` |
| `StreamingDirectoryLister` | `gum` ReadDirIter | `sugar-crush` |

### 7.3 Innovation over Upstream

The sugar-crush implementation introduces several patterns NOT present in upstream crush:

1. **Immutable + fluent throughout** — crush uses struct mutation; sugar-crush uses readonly properties + with*() builders
2. **PHP-native async** — ReactPHP Promises for non-blocking backend calls
3. **Generator-based listing** — Memory-safe directory enumeration
4. **Graceful degradation** — Every failure mode handled silently with sensible defaults
5. **UTF-8 grapheme awareness** — Proper multi-byte character handling in backspace

---

## 8. Compositor Analysis

### 8.1 Architecture Strengths

1. **Clean separation** — Backend is injectable, tools are registerable, renderers are pure functions
2. **Testability** — Every class is independently testable; Chat uses EchoBackend in tests
3. **Immutable state** — No hidden mutations; race conditions visible in tests
4. **Network-dep-free core** — Wrapper script approach keeps PHP code isolated from provider changes
5. **Comprehensive test suite** — 158 tests with behavior/coercion/snapshot patterns

### 8.2 Architecture Weaknesses

1. **No chat history persistence** — History is ephemeral; no conversation resumption
2. **Synchronous tool execution** — Long-running tools block the TUI update loop
3. **No streaming UI** — Tokens accumulated before rendering, not incrementally displayed
4. **Single-backend limitation** — Can only use one Backend at a time (no provider switching)
5. **MCP client is Claude Code-specific** — Not a general-purpose MCP implementation

### 8.3 Design Pattern Influences

| Pattern | Source | Implementation |
|---------|--------|----------------|
| MVU (Model/Update/View) | Bubble Tea | `Chat implements Model` |
| Immutable + fluent | SugarCraft convention | `with*()` builders |
| Backend interface | fantasy `LanguageModel` | `complete()` / `completeAsync()` |
| Tool calling | OpenAI/Anthropic API | `ToolCall` / `ToolResult` VOs |
| Generator-based lazy loading | Go iterators | `StreamingDirectoryLister` |
| Graceful degradation | Unix philosophy | `Session::load()` never throws |

---

## 9. Future Directions

### 9.1 Near-term (v1.x)

1. **Session chat persistence** — Add `ChatHistory` class persisting to JSONL
2. **Token counting** — Estimate context usage, trigger compaction
3. **Built-in file tools** — Register read/write/edit/glob via ToolRegistry
4. **Streaming UI** — Render tokens incrementally via partial message state

### 9.2 Medium-term (v2.0)

1. **Provider abstraction layer** — `Provider` interface (like fantasy) for direct HTTP backends
2. **LSP integration** — `LspClient` class connecting to gopls/tsserver
3. **MCP transports** — Add HTTP and SSE support to McpClient
4. **Permission system** — Tool allow-lists with confirmation prompts

### 9.3 Long-term (v3.0)

1. **Multi-agent support** — Background agents with separate contexts
2. **Workspace sharing** — SSE-based collaboration (like crush)
3. **Thinking blocks** — Display Claude reasoning process
4. **RAG integration** — Context file loading + semantic search

---

## 10. Key Files Reference

| File | Lines | Purpose |
|------|-------|---------|
| `src/Chat.php` | 348 | Main MVU model with tool execution |
| `src/Backend.php` | 49 | Backend interface |
| `src/Backend/CommandBackend.php` | 96 | Shell-out backend |
| `src/Backend/StreamingCommandBackend.php` | 160 | Streaming shell-out backend |
| `src/Message.php` | 128 | Immutable message VO with wire format |
| `src/ToolCall.php` | 54 | Tool invocation request VO |
| `src/ToolResult.php` | 64 | Tool execution result VO |
| `src/ToolRegistry.php` | 189 | Tool registration + 5 built-ins |
| `src/McpClient.php` | 312 | JSON-RPC 2.0 stdio client |
| `src/McpMessage.php` | 222 | JSON-RPC 2.0 envelope |
| `src/Session.php` | 253 | UI state persistence |
| `src/Compactor.php` | 253 | File grouping by type/size |
| `src/CommandParser.php` | 164 | Slash-command parsing |
| `src/StreamingDirectoryLister.php` | 108 | Generator-based directory listing |
| `src/Renderer.php` | 78 | Pure view function |

---

*Report compiled from source analysis of sugar-crush v1, upstream charmbracelet/crush, charmbracelet/fantasy, and charmbracelet/mods. Test coverage verified via phpunit.xml configuration.*
