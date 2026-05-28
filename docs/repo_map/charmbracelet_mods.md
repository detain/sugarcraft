# charmbracelet/mods

## Metadata
- **URL:** https://github.com/charmbracelet/mods
- **Language:** Go
- **Stars:** ~5.3k (as of 2026-01, based on repository metadata)
- **License:** MIT
- **Description:** AI for the command line, built for pipelines. Large Language Model (LLM) based AI tool that ingests command output and formats results in Markdown, JSON, and other text formats. Works with OpenAI, LocalAI, Cohere, Groq, Azure OpenAI, Anthropic, Google Gemini, and Ollama.

> [!NOTE]
> This project was **sunset on March 9, 2026** and archived. The Charm team is focusing on [Crush](https://charm.land/crush) instead. Mods remains open source.

---

## Feature List

- **Multi-API Support:** OpenAI (including Azure AD), Anthropic (Claude), Google (Gemini), Cohere, Groq, LocalAI (local models), Ollama
- **Streaming Responses:** Real-time token streaming with animated loading states
- **Conversation Management:** Save, list, show, continue, and delete conversations with SHA-1 identifiers
- **Custom Roles:** YAML-configurable system prompts (e.g., "shell expert" role that outputs only commands)
- **MCP (Model Context Protocol) Integration:** Connect to MCP servers for tool-augmented LLM interactions (stdio, SSE, HTTP transports)
- **Formatting:** Request markdown or JSON formatted output with glamour terminal rendering
- **Theme Support:** Charm, Catppuccin, Dracula, and Base16 themes for TUI forms
- **Interactive Prompts:** Model/API selection via interactive huh forms
- **Input Pipelines:** Read from stdin, combine stdin with args, truncate stdin to N lines
- **Token Management:** Configurable max-tokens, temperature, topP, topK, stop sequences
- **Error Recovery:** Automatic retry with exponential backoff
- **Caching:** Both SQLite metadata cache and file-based (.gob) conversation cache
- **Shell Completions:** Bash, ZSH, Fish, and PowerShell
- **Configuration:** YAML-based settings with environment variable overrides

---

## Key Classes and Methods

### `main.go` (CLI Entry Point)
- `main()` — Entry point, initializes config, opens DB, executes cobra command
- `handleError(err)` — Formats and prints errors to stderr
- `askInfo()` — Interactive model/API selection using huh forms
- `deleteConversations()`, `deleteConversationOlderThan()` — Conversation deletion
- `listConversations()` — Lists saved conversations with interactive selection
- `saveConversation()` — Persists conversation to cache and SQLite

### `mods.go` (Bubble Tea Model)
- `type Mods struct` — Main TUI model holding state, config, messages, viewport
- `newMods()` — Constructor creating glamour renderer and viewport
- `Init()` — tea.Model initialization, starts cache details lookup
- `Update(msg tea.Msg)` — Handles completionInput, completionOutput, errors, window size, keys
- `View() string` — Renders glam-output with viewport scrolling for long content
- `startCompletionCmd()` — Resolves API/model, builds request, creates appropriate client
- `receiveCompletionStreamCmd()` — Consumes stream chunks, calls tool handlers
- `findCacheOpsDetails()` — Determines read/write IDs for conversation continuation
- `resolveModel()` — Matches CLI model flag to configured API/model pairs

### `config.go` (Configuration)
- `type Config struct` — Main config mapped to YAML settings file
- `type API struct` — API endpoint with name, API key, base URL, models map
- `type Model struct` — Model with name, aliases, max-chars, fallback, thinking-budget
- `ensureConfig()` — Loads/creates config from XDG paths
- `defaultConfig()` — Returns sensible defaults (markdown format, 15s MCP timeout)

### `db.go` (SQLite Conversation Storage)
- `type convoDB struct` — Wraps sqlx.DB
- `type Conversation struct` — {id, title, updated_at, api, model}
- `Save()`, `Delete()`, `Find()`, `FindHEAD()`, `List()`, `ListOlderThan()` — CRUD operations
- `openDB()` — Creates SQLite DB with migrations for conversations table + indexes

### `internal/cache/cache.go`
- `type Cache[T] struct` — Generic file-based cache using gob serialization
- `Read()`, `Write()`, `Delete()` — Cache file operations

### `internal/stream/stream.go`
- `type Stream interface` — `Next()`, `Current()`, `Close()`, `Err()`, `Messages()`, `CallTools()`
- `type Client interface` — `Request(context, proto.Request) Stream`

### `internal/openai/openai.go`
- `type Client struct` — Wraps openai.Client
- `Request()` — Builds ChatCompletionNewParams, returns OpenAI stream
- `type Stream struct` — Implements stream.Stream with SSE streaming

### `internal/anthropic/anthropic.go`
- `type Client struct` — Wraps anthropic.Client
- `Request()` — Builds MessageNewParams, returns Anthropic stream

### `internal/google/google.go`, `internal/cohere/cohere.go`, `internal/ollama/ollama.go`
- Similar pattern: Client + Request + Stream for respective APIs

### `internal/proto/proto.go`
- `type Message struct` — {role, content, tool_calls}
- `type Request struct` — {messages, api, model, tools, temperature, topP, topK, stop, maxTokens, toolCaller}
- `type Chunk struct` — Streaming text content
- `type Conversation []Message` — Conversation as message slice with String()

### `mcp.go` (MCP Integration)
- `enabledMCPs()` — Yields enabled MCP server configs
- `mcpTools()` — Fetches tools from all enabled MCP servers concurrently
- `initMcpClient()` — Creates stdio/SSE/HTTP MCP client
- `toolCall()` — Routes tool calls to appropriate MCP server

### `anim.go`
- `newAnim()` — Creates loading animation model with configurable fanciness level

### `styles.go`
- `type styles struct` — Lipgloss styling for rendering
- `stdoutStyles()`, `stderrStyles()` — Style factories

---

## Notable Algorithms / Named Patterns

- **Exponential Backoff Retry:** `wait = 100ms * 2^retries` for API call retries (mods.go:L271)
- **Token Context Truncation:** When context exceeds max, cuts prompt by `(excess_tokens * 4) + 10` chars (approx 1 token ≈ 4 chars) (mods.go:L686-693)
- **Stream Factory Pattern:** Each API stream has a `factory` func to recreate stream after tool calls complete (openai.go:L102-104)
- **Bubble Tea MVC:** Full Model/Update/View pattern for TUI rendering with viewport scrolling
- **Gob Serialization:** File-based caching using Go's binary encoding (cache.go)
- **Concurrent MCP ToolFetching:** errgroup with mutex protection for parallel server tool listing (mcp.go:L66-92)
- **YAML/Env Config Merging:** XDG config file → YAML unmarshal → env var override (config.go)
- **SHA-1 Conversation IDs:** Human-readable short IDs for conversation lookup (sha.go patterns)

---

## Strengths

- **Excellent Architecture:** Clean separation between `stream.Client` interface and implementations (OpenAI, Anthropic, Google, Cohere, Ollama)
- **Bubble Tea TUI:** Beautiful terminal UI with viewport scrolling, glamour markdown rendering, theme support
- **Comprehensive API Support:** One tool works with 6+ LLM providers via unified interface
- **Pipeline-First Design:** stdin/stdout separation, works seamlessly in Unix pipelines
- **Conversation Persistence:** Both SQLite (metadata) and file cache (full messages) enable true continuity
- **MCP Tool Integration:** First-class MCP server support with stdio/SSE/HTTP transports
- **Error Handling:** User-friendly error messages with suggestions, proper error wrapping
- **Configuration Flexibility:** YAML config + environment variables, sensible defaults
- **Extensible Stream Pattern:** Adding a new API provider only requires implementing the `stream.Client` interface

---

## Weaknesses

- **Sunset Project:** No longer actively maintained (archived March 2026)
- **Limited to Text:** No image/file upload support beyond text
- **Single Model Per Request:** No parallel multi-model querying or model routing
- **No Streaming to File:** When not a TTY, raw output mode lacks glamour formatting
- **MCP Complexity:** Tool calling adds significant complexity; retry/continuation after tool calls is intricate
- **Gob Cache Format:** Not human-readable; conversation cache files can't be edited directly

---

## SugarCraft Mapping

SugarCraft is a PHP monorepo of TUI library ports from the Charmbracelet ecosystem. Here's how mods maps to SugarCraft libs:

| mods Feature | SugarCraft Lib | Notes |
|---|---|---|
| **Bubble Tea (TUI framework)** | `candy-shell` | Core TUI framework - `bubbletea` port |
| **Lipgloss (styling)** | `candy-shine` | Terminal styling library - `lipgloss` port |
| **Viewport scrolling** | `candy-shell` components | Textarea/viewport components |
| **Glamour (markdown rendering)** | `sugar-bits` (rendering) | Markdown/ANSI rendering |
| **Streaming LLM client** | Not yet ported | Could be `sugar-prompt` or new `sugar-llm` |
| **Conversation caching** | Not yet ported | Could be `candy-sprinkles` (caching utilities) |
| **MCP client integration** | Not yet ported | Could be `sugar-mcp` or `honey-mcp` |
| **Interactive forms (huh)** | `candy-shell` input | `huh` form library port |
| **Spinner/Animation** | `candy-shell` spinner | `bubbletea` spinner components |
| **Config/YAML** | `candy-core` | Configuration management patterns |
| **SQLite storage** | Not yet ported | Could leverage existing `sugar-bits` persistence |

### Specific Mapping Candidates:

- **`sugar-prompt`**: A hypothetical LL client port would handle OpenAI/Anthropic API calls with streaming
- **`sugar-charts`**: If mods had data visualization, but currently doesn't
- **`candy-shell`**: The `tea.Model` / `Update` / `View` pattern is fully implemented
- **`honey-bounce`**: Animation/spinner timing patterns for loading states

---

## Analysis

charmbracelet/mods was a genuinely well-designed CLI tool that brought AI capabilities to the Unix command line philosophy. Its core insight was treating LLM input as another pipeline stage—read stdin, prepend a prompt, stream output. This simplicity made it powerful: `echo 'code' | mods 'explain'` just worked.

The architecture was its greatest strength. The `stream.Client` interface abstraction allowed supporting six different LLM providers (OpenAI, Anthropic, Google, Cohere, Groq, Ollama) without duplicating logic. Each provider implemented the same interface, and the main `mods.go` model simply dispatched to whichever client matched. This is textbook interface segregation and could serve as a reference implementation for similar multi-provider SDKs.

The Bubble Tea TUI was equally well-executed. Using `viewport` for long output, `glamour` for markdown rendering, and `huh` for interactive forms created a polished experience rivaling native applications. The attention to detail—auto-scrolling to bottom when new content arrives, proper TTY detection to disable visuals in pipelines, theme support—showed deep understanding of terminal application UX.

The MCP integration demonstrated forward thinking but added substantial complexity. Tool-calling required recreating the stream after each tool execution (hence the `factory` pattern), and the retry logic became intricate. While powerful—imagine `mods` fetching current weather via an MCP server then including it in context—this complexity may have contributed to maintenance burden.

As of March 2026, Charm archived mods to focus on Crush. Their statement indicated most mods functionality exists in `crush run` (non-interactive mode). For SugarCraft, ports of the TUI patterns (Bubble Tea, Lipgloss, Glamour) would provide the foundation for similar CLI AI tools, while a dedicated streaming HTTP client library would handle the LLM communication layer. The monorepo structure—candy-*/sugar-*/honey-* prefixes, immutable models with `with*()` builders, PSR-4 namespacing—closely mirrors how mods organized its internal packages by concern (cache, proto, stream, openai, anthropic, etc.).