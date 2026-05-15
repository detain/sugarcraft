# Sugar-Crush AI Coding Assistant TUI Research

**Date:** 2026-05-13
**Upstream:** charmbracelet/crush (Go)
**Project:** sugar-crush (PHP 8.3+ port)
**Research Scope:** Message handling, stream rendering, context management, code highlighting

---

## 1. Executive Summary

Sugar-crush is a well-architected PHP port of charmbracelet/crush with a clean Model-View-Update (MVU) pattern using ReactPHP for async operations. The current implementation covers core chat functionality but lacks features present in upstream and competing implementations.

**Key Findings:**
- **Message Handling:** sugar-crush uses immutable `Message` objects with role-based structure (System/User/Assistant) - similar to Go crush
- **Stream Rendering:** Basic token accumulation via `onToken` callback; no live incremental rendering
- **Context Management:** History passed to backends but no automatic compaction or session persistence
- **Code Highlighting:** Relies on CandyShine (league/commonmark) without syntax highlighting in code blocks

---

## 2. Implementation Analysis: sugar-crush Current State

### 2.1 Source Structure

```
sugar-crush/src/
├── Chat.php                  # Main MVU Model - handles history, input, tool execution
├── Message.php               # Immutable message with role, content, attachments, toolCalls
├── Role.php                  # Enum: System, User, Assistant
├── Renderer.php              # View - renders history + input area
├── AssistantMsg.php          # Internal Msg dispatched when backend completes
├── ToolCall.php              # Represents AI tool invocation request
├── ToolResult.php            # Result from tool execution
├── Attachment.php            # File/image attachments
├── AttachmentType.php        # Enum: File, Image
├── Backend.php               # Interface for LLM adapters
└── Backend/
    ├── CommandBackend.php    # Shells out to external command (stdin/stdout)
    ├── StreamingCommandBackend.php  # Streaming variant with line-by-line token callback
    └── EchoBackend.php       # Test stub
```

### 2.2 Key Implementation Details

**Chat Model (Chat.php:39-343):**
- Three pieces of state: `history` (list<Message>), `inputBuf` (string), `inFlight` (bool)
- Tool execution via `registerTool()` - callbacks stored in `$this->tools`
- Async backend calls return `PromiseInterface` wrapped in `Cmd::promise()`
- `onToken` callback for streaming support

**Message Structure (Message.php:17-128):**
```php
final class Message {
    public function __construct(
        public readonly Role  $role,
        public readonly string $content,
        public readonly int   $createdAt,
        public readonly array $attachments = [],
        public readonly array $toolCalls = [],
    ) {}
}
```

**Backend Interface (Backend.php:28-49):**
```php
interface Backend {
    public function complete(array $history, callable $onToken = null): Message;
    public function completeAsync(array $history, callable $onToken = null): PromiseInterface;
}
```

**Renderer (Renderer.php:34-77):**
- Uses `SugarCraft\Shine\Renderer` (CandyShine) for markdown
- User turns rendered raw, assistant turns rendered as markdown
- Simple box layout with border using CandySprinkles

---

## 3. Comparative Analysis: Other Implementations

### 3.1 Go: charmbracelet/crush (Upstream)

**Architecture (24K stars, 120 contributors):**

| Component | Implementation |
|-----------|---------------|
| TUI Framework | Bubble Tea v2 (MVU) |
| Styling | Lip Gloss v2 |
| Markdown | Glamour v2 |
| Streaming | Real-time token-by-token via Bubble Tea subscriptions |
| Tool System | Built-in: bash, edit, view, grep, glob, fetch, download |
| MCP Support | stdio, http, sse transports |
| LSP Integration | gopls, typescript-language-server, nil |
| Session | SQLite-backed with JSONL files |

**Key Features sugar-crush Lacks:**
- Real-time streaming display with incremental rendering
- LSP-based code intelligence
- MCP server integration
- Session persistence and resumption
- Built-in file editing tools
- Slash commands (/help, /clear, /compact, /commit, etc.)
- Permission system for tool execution
- Auto-loading of CLAUDE.md context files

**Source Reference:** `charmbracelet/crush/internal/ui/` for TUI components

### 3.2 Rust: sigoden/aichat (9.7K stars)

**Architecture:**

| Component | Implementation |
|-----------|---------------|
| TUI Framework | Ratatui + Crossterm |
| Markdown | Custom with syntax highlighting |
| Session | JSONL file persistence |
| Tools | MCP integration |
| Providers | 20+ (OpenAI, Claude, Gemini, Ollama, Groq, etc.) |

**Key Features:**
- Shell assistant mode (natural language → shell commands)
- REPL mode with tab completion
- RAG (Retrieval Augmented Generation)
- Custom themes with code highlighting
- Function calling / AI Tools

### 3.3 Rust: rust-code (0.6.1)

**Architecture:**

| Component | Implementation |
|-----------|---------------|
| TUI Framework | Ratatui |
| Syntax Highlighting | Syntect (Sublime Text grammars) |
| Agent Loop | BAML (Schema-Guided Reasoning) |
| File Search | Nucleo (fuzzy) |
| Session | JSONL persistence |
| Background Tasks | tmux integration |

**Key Features:**
- Typed agent loop powered by BAML
- Fuzzy file search (Ctrl+P)
- Session history (Ctrl+H)
- Git diff/history side channels
- Symbol search
- Background task viewer via tmux
- F1-F12 function key shortcuts

**Source Reference:** `syntect` crate for syntax highlighting

### 3.4 Go: kardolus/chatgpt-cli (1.4K stars)

**Features:**
- Multi-provider (OpenAI, Azure, Perplexity, LLaMA)
- Streaming mode
- Agent mode (ReAct + Plan/Execute)
- MCP tool calls via HTTP/SSE/STDIO
- Budget limits and policy enforcement
- Image/audio I/O

### 3.5 Python: cursor-agent-tools

**Architecture:**
- Async Python with function calling
- Model flexibility (Claude, OpenAI, Ollama)
- Permission system for file operations
- Local model support via Ollama

### 3.6 Rust: clifcode

**Features:**
- Context compaction (3-tier automatic)
- Session persistence
- 3 autonomy modes (suggest, auto-edit, full-auto)
- Cost tracking
- Git integration with commits

### 3.7 Comparison Table

| Feature | sugar-crush | crush | aichat | rust-code |
|---------|-------------|-------|--------|-----------|
| Language | PHP | Go | Rust | Rust |
| TUI Framework | SugarBits | Bubble Tea | Ratatui | Ratatui |
| Markdown | CandyShine | Glamour | Custom | Custom |
| Syntax Highlight | ❌ | ✅ | ✅ | ✅ (syntect) |
| Streaming | Basic | Real-time | Real-time | Real-time |
| Tool System | Plugin | Built-in | MCP | MCP |
| Session Persistence | ❌ | SQLite | JSONL | JSONL |
| LSP Integration | ❌ | ✅ | ❌ | ❌ |
| MCP Support | ❌ | ✅ (3 transports) | ✅ | ✅ (rmcp) |
| Slash Commands | ❌ | ✅ | ❌ | F-keys |

---

## 4. Detailed Feature Analysis

### 4.1 Message Handling

**sugar-crush Current:**
- Immutable `Message` with role, content, timestamp, attachments, toolCalls
- `toWire()` method for backend adapters
- Support for file/image attachments

**Best Practices from Comparative Analysis:**

1. **Structured Content Blocks:** Go crush uses content block types (text, tool_use, tool_result, thinking)
2. **Thinking Blocks:** Claude Code displays thinking process separately (collapsible)
3. **Message Metadata:** Timestamps, token counts, model used

**Recommendation:** Add message metadata tracking (token count, model, timestamps) and consider structured content blocks for tool calls vs text.

### 4.2 Stream Rendering

**sugar-crush Current:**
- `StreamingCommandBackend` reads line-by-line from process stdout
- `onToken` callback accumulates tokens
- Tokens accumulated, then rendered as complete message

**Upstream (crush) Pattern:**
- Bubble Tea subscriptions for real-time updates
- Token received → immediate view update
- No waiting for complete response

**aichat Pattern:**
- Real-time token streaming
- Incremental markdown rendering
- Typing indicator animation

**rust-code Pattern:**
- Streaming with syntect highlighting per-line
- Side panels for tools/git/diff

**Recommendation:** Implement true streaming UI - render tokens as they arrive, not after complete response. This requires:
1. Partial message state in Chat model
2. View updates during streaming
3. Cursor/typing indicator during streaming

### 4.3 Context Management

**sugar-crush Current:**
- Full history passed to backend on each call
- No automatic compaction
- No session persistence

**Best Practices:**

1. **Automatic Compaction:** aichat, clifcode - truncate old messages when context limit approached
2. **Session Persistence:** rust-code, clifcode - JSONL files with session metadata
3. **CLAUDE.md Loading:** crush - auto-load context files from project
4. **3-Tier Context:** clifcode - truncate large results, stub old turns, drop very old messages

**Recommendation:** Implement:
1. Session persistence (JSONL with metadata)
2. Context compaction when approaching token limits
3. CLAUDE.md/project context file loading

### 4.4 Code Highlighting

**sugar-crush Current:**
- Uses CandyShine (league/commonmark) for markdown
- No syntax highlighting in code blocks
- Raw markdown code fences rendered as plain text

**Best Practices:**

1. **Syntect (Rust):** Uses Sublime Text grammar definitions, 24-bit ANSI output, ~23ms load time
2. **Glamour (Go):** Custom styling with code block support
3. **Custom Themes:** aichat supports custom dark/light themes with code highlighting

**PHP Ecosystem Options:**

| Library | Approach | Pros | Cons |
|---------|----------|------|------|
| PHP's built-in | None | - | No highlighting |
| highlight.js (CDN) | HTML injection | Well-maintained | Requires HTML rendering |
| Scrutiny (PHP port of Syntect-like) | ANSI output | Good coverage | Less mature |
| Shiki (PHP) | TextMate grammars | VS Code quality | Weights/complexity |

**Recommendation:** Evaluate `php-scrutinizer/scrutiny` or create ANSI output wrapper that can integrate with existing markdown rendering pipeline. Priority: Medium (enhancement, not blocking).

---

## 5. Prioritized Recommendations

### 5.1 High Priority (Essential for Competitiveness)

| # | Improvement | Description | Effort | Impact |
|---|-------------|-------------|--------|--------|
| 1 | **Session Persistence** | Save/restore chat sessions to JSONL files | Medium (4-6h) | High |
| 2 | **Real-time Streaming UI** | Render tokens as they arrive, not after complete | Medium (4-6h) | High |
| 3 | **Context Compaction** | Auto-truncate when approaching token limits | Medium (3-4h) | High |
| 4 | **Built-in Tools** | File read/write/edit, bash execution | Medium (6-8h) | High |

### 5.2 Medium Priority (Feature Parity)

| # | Improvement | Description | Effort | Impact |
|---|-------------|-------------|--------|--------|
| 5 | **Syntax Highlighting** | Code block highlighting in markdown | Medium (4-5h) | Medium |
| 6 | **Slash Commands** | /help, /clear, /compact, /model, etc. | Small (2-3h) | Medium |
| 7 | **MCP Client** | Model Context Protocol integration | Medium (6-8h) | Medium |
| 8 | **Project Context** | Load CLAUDE.md on startup | Small (2h) | Medium |

### 5.3 Lower Priority (Future Enhancements)

| # | Improvement | Description | Effort | Impact |
|---|-------------|-------------|--------|--------|
| 9 | **LSP Integration** | Code intelligence via language servers | High (12h+) | Medium |
| 10 | **Thinking Blocks** | Display AI reasoning process | Medium (4h) | Low |
| 11 | **Multi-agent Support** | Background sub-agents | High (10h+) | Low |
| 12 | **Custom Themes** | Theme switching, user styles | Small (2-3h) | Low |

---

## 6. Implementation Roadmap

### Phase 1: Session & Streaming (Week 1)
1. Add `SessionManager` class for JSONL persistence
2. Modify `Chat` model to support partial/streaming state
3. Update `Renderer` to show incremental content
4. Add session list/resume commands

### Phase 2: Context Management (Week 2)
1. Implement token counting utility
2. Add context compaction logic to Chat
3. Implement CLAUDE.md loading
4. Add `/compact` slash command

### Phase 3: Tool System (Week 2-3)
1. Add built-in file tools (read, write, edit)
2. Add bash execution tool
3. Implement permission system
4. Add tool result rendering

### Phase 4: Enhancements (Week 3-4)
1. Integrate syntax highlighting
2. Add slash commands
3. MCP client infrastructure
4. Theme support

---

## 7. Technical Debt & Architecture Notes

### 7.1 Current Strengths
- Clean MVU architecture with immutable state
- Async backend via ReactPHP promises
- Plugin backend interface design
- Comprehensive test coverage (ChatTest, RendererTest, Backend tests)

### 7.2 Architectural Considerations
- **Streaming Backend:** `StreamingCommandBackend` uses blocking I/O with `stream_set_blocking(false)`. Consider using ReactPHP Stream for proper async.
- **Renderer:** Static method, creates new `Markdown` instance each call. Consider caching theme.
- **Tool Execution:** Synchronous in MVU update loop. Long-running tools block UI.

### 7.3 PHP-Specific Considerations
- No native async/await (uses ReactPHP)
- No built-in syntax highlighting (need external library or wrapper)
- Unicode handling in `dropLast()` is correct (multi-byte safe)

---

## 8. References

### 8.1 Upstream Sources
- **charmbracelet/crush:** https://github.com/charmbracelet/crush
- **AGENTS.md (UI docs):** https://github.com/charmbracelet/crush/blob/main/internal/ui/AGENTS.md

### 8.2 Rust Implementations
- **aichat:** https://github.com/sigoden/aichat/ (9.7K stars)
- **rust-code:** https://crates.io/crates/rust-code (0.6.1)
- **syntect:** https://github.com/trishume/syntect (syntax highlighting)

### 8.3 Go Tools
- **chatgpt-cli:** https://github.com/kardolus/chatgpt-cli
- **claude-code-go:** https://github.com/tunsuy/claude-code-go

### 8.4 PHP Libraries Used
- **CandyShine:** Markdown rendering (league/commonmark wrapper)
- **CandySprinkles:** Terminal styling (borders, padding)
- **SugarBits:** TUI components
- **ReactPHP:** Async promise handling

---

## 9. Appendix: Key Code Patterns

### A. Backend Interface Pattern

```php
// From Backend.php:28-49
interface Backend {
    public function complete(array $history, callable $onToken = null): Message;
    public function completeAsync(array $history, callable $onToken = null): PromiseInterface;
}
```

### B. Streaming Token Handling

```php
// From StreamingCommandBackend.php:91-100
while (($line = fgets($pipes[1])) !== false) {
    $lineCount++;
    $token = rtrim($line, "\r\n");
    if ($token !== '') {
        $tokens[] = $token;
        if ($onToken !== null) {
            $onToken($token);
        }
    }
}
```

### C. Tool Execution Flow

```php
// From Chat.php:121-159
private function handleToolCalls(Message $message): array {
    $toolResults = [];
    foreach ($message->toolCalls as $toolCall) {
        $result = $this->executeTool($toolCall);
        $toolResults[] = $result;
    }
    // Add messages to history and schedule follow-up
}
```

---

*Research compiled from public GitHub repositories, crates.io, and source analysis.*
*Last updated: 2026-05-13*
