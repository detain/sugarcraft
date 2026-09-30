# OpenCode Analysis & CandyCrush Enhancement Report

**Date:** June 3, 2026
**Source:** https://github.com/anomalyco/opencode
**Analysis Scope:** Core packages, LLM integration, UI/TUI, agent architecture, plugin system

---

## Executive Summary

OpenCode is a production-grade AI coding agent built with TypeScript/Effect framework, featuring 30+ LLM provider integrations, multi-agent architecture, event-driven session management, and a sophisticated plugin/hook system. This report identifies features, patterns, and innovations that could enhance CandyCrush—the SugarCraft PHP AI coding assistant TUI.

**Key Finding:** OpenCode's architecture demonstrates mature patterns for agent orchestration, provider abstraction, context management, and extensibility that SugarCrush could adopt to achieve feature parity and beyond.

---

## Part 1: OpenCode Architecture Deep Dive

### 1.1 Multi-Agent System Architecture

OpenCode implements a sophisticated multi-agent system with specialized agents for different tasks:

#### Built-in Agents

| Agent | Mode | Purpose | Permissions |
|-------|------|---------|-------------|
| `build` | primary | Default development agent | Full access |
| `plan` | primary | Read-only analysis | No edits, ask for bash |
| `explore` | subagent | Fast codebase exploration | Read + search + web only |
| `general` | subagent | Complex multi-step research | Denies todowrite |
| `compaction` | hidden | Context summarization | Denies all tools |
| `title` | hidden | Session title generation | Denies all tools |
| `summary` | hidden | PR-style summary generation | Denies all tools |

#### Agent Implementation Pattern

```typescript
// Agent definition with typed permissions
interface AgentInfo {
  id: ID
  model?: ModelV2.Ref
  system?: string           // System prompt override
  description?: string
  mode: "subagent" | "primary" | "all"
  hidden: boolean
  color: string             // UI color coding
  steps?: PositiveInt       // Max steps limit
  permissions: PermissionV2.Ruleset
}
```

**Relevance to CandyCrush:** The agent type system with permission rulesets provides a template for implementing specialized subagents (code reviewer, debugger, architect, tester, devops).

### 1.2 Provider Abstraction (30+ Providers)

OpenCode's LLM package (`/packages/llm`) provides a sophisticated provider architecture:

#### Provider Pattern

```typescript
// Four-axis decomposition
const route = Route.make(
  Protocol.OpenAIChat,    // API semantics (body format, stream parsing)
  Endpoint.openAI(),       // URL construction
  Auth.apiKey("sk-..."),   // Authentication
  Framing.sse(),          // Stream framing (SSE, WS, etc.)
  Transport.http()        // HTTP transport
)
```

#### Supported Providers

| Provider | Protocol | Notes |
|----------|----------|-------|
| OpenAI | chat, responses, websocket | Full featured |
| Anthropic | messages | Claude integration |
| Google Gemini | gemini | Google's model |
| AWS Bedrock | converse | SigV4 auth |
| Azure | openai-chat | API key auth |
| OpenRouter | extends openai-chat | Extra body options |
| Cloudflare | openai-compatible | Workers AI |
| GitHub Copilot | custom | Special protocol |
| OpenAI-Compatible | openai-compatible-chat | DeepSeek, TogetherAI, Groq, etc. |

#### Streaming State Machine

Each protocol implements `stream.step()` for clean streaming:

```typescript
// Protocol streaming interface
interface Protocol {
  body: {
    schema: Schema<LLMRequest>
    from: (request: LLMRequest) => HttpBody
  }
  stream: {
    event: Schema<LLMEvent>        // Parse single event
    step: (state: ParserState, event: UnknownEvent) => [ParserState, LLMEvent[]]
    onHalt: (state: ParserState) => Effect<FinishReason>
  }
}
```

**Relevance to CandyCrush:** The protocol reuse pattern allows DeepSeek, TogetherAI, Groq, etc. to reuse `OpenAIChat.protocol` with different endpoints—enabling multi-provider support without per-provider protocol code.

### 1.3 Event-Driven Session Architecture

OpenCode uses event sourcing for session management:

#### Event System

```typescript
// Define typed events
EventV2.define({
  type: "session.message.added",
  schema: { sessionId: ID, message: Message }
})

// Publish/subscribe pattern
event.publish(definition, data)
event.subscribe(definition)  // Returns Stream<Payload>
event.project(definition, projector)  // Persist to DB
```

#### Session Projectors

Sessions project events to SQLite via Drizzle ORM:
- `SessionProjector` - Session metadata
- `MessageProjector` - Message CRUD
- `EventSequenceProjector` - Ordered event log
- `SyncProjector` - Real-time sync events

**Relevance to CandyCrush:** Event-driven architecture enables reactive UI updates, audit trails, and session replay capabilities.

### 1.4 Tool System Architecture

#### Tool Registry Pattern

```typescript
// Built-in tools with provider filtering
const registry = new ToolRegistry()
registry.register('read', readTool, { provider: ['anthropic', 'openai'] })
registry.register('edit', editTool, { provider: ['anthropic'] })
registry.register('patch', patchTool, { provider: ['openai'] })  // GPT only
```

#### Tool Definition Interface

```typescript
interface Tool<Parameters, Success> {
  description: string
  parameters: Schema<Parameters>
  success: Schema<Success>
  execute(params: Parameters, context: Tool.Context): Effect<ToolResult>
}
```

#### Tool Runtime

The `ToolRuntime` handles multi-step agent loops with:
- Stop conditions (`stopWhen`, `stepCountIs`)
- Usage accumulation across steps
- Provider metadata preservation

**Relevance to CandyCrush:** Provider-specific tool filtering and the tool decorator pattern for hooks/logging.

### 1.5 Permission System

Rule-based access control with wildcard matching:

```typescript
type Rule = {
  action: string     // e.g., "bash", "read", "edit"
  resource: string   // e.g., "*.php", "src/**"
  effect: "allow" | "deny" | "ask"
}

// Permission evaluation
const result = await permission.evaluate({
  action: "bash",
  resource: "git commit",
  context: { sessionId, messageId }
})
```

**Features:**
- Wildcard glob patterns (`*.php`, `src/**`)
- Per-session caching of permission decisions
- Cascading rejection (reject one rejects all pending)
- `once` vs `always` reply modes

**Relevance to CandyCrush:** Robust permission system for safe autonomous tool execution.

### 1.6 Plugin/Hook Architecture

```typescript
// Plugin hook definition
type HookSpec = {
  "catalog.transform": { input: Catalog.Editor, output: {} }
  "tool.execute.before": { input: ToolCall, output: { modified?: ToolCall } }
  "tool.execute.after": { input: ToolCall, output: { modified?: ToolResult } }
  "aisdk.language": { input: { model, sdk }, output: { language?: string } }
}

// Plugin implementation
const plugin = (api, options, meta) => {
  api.hook.register("tool.execute.before", myInterceptor)
  api.route.register({ name: "my-screen", render: () => <MyComponent /> })
  api.slots.register("home_prompt", { render: () => <MyPromptAddon /> })
}
```

**Hook Types:**
- `catalog.transform` - Modify model catalog
- `tool.execute.before/after` - Tool interception
- `aisdk.sdk/language` - Provider customization
- `chat.message` - Message transformation
- `permission.v2.asked/replied` - Permission events

**Relevance to CandyCrush:** Extensibility without core modifications.

### 1.7 Configuration Hierarchy

Three-level config merge with precedence:

1. **Global** - `~/.config/opencode/config.json`
2. **Project** - `<project>/opencode.json`
3. **Local** - `.opencode/config.json`

```json
{
  "agents": {},      // Agent definitions
  "plugins": [],     // Plugin list
  "providers": {},   // Provider configs
  "permission": {},  // Permission rules
  "reference": {}    // Path aliases
}
```

**Relevance to CandyCrush:** Hierarchical config for global defaults → project overrides → local customization.

### 1.8 Context Management

#### Token Accounting

```typescript
class Usage {
  inputTokens: number           // Total input
  outputTokens: number           // Total output
  nonCachedInputTokens: number   // "Fresh" portion
  cacheReadInputTokens: number   // From cache
  cacheWriteInputTokens: number   // Written to cache
  reasoningTokens: number        // Hidden reasoning (subset of output)
}
```

**Invariant:** `nonCached + cacheRead + cacheWrite = inputTokens`

#### Prompt Caching

```typescript
type CachePolicy =
  | "auto"    // Breakpoints at last tool, last system, latest user
  | "none"    // Disable caching
  | {
      breakpoints: {
        at?: "last-tool" | "last-system" | "last-message"
        type?: "ephemeral" | "persistent"
        ttlSeconds?: number
      }
    }
```

**Relevance to CandyCrush:** Proper token accounting and context budget management.

### 1.9 Effect Framework Pattern

OpenCode uses `@effect` for functional programming:

```typescript
// Service definition
class LLMService extends Context.Service<LLMService, Interface>()("LLM") {}

// Layer composition
const AppLayer = Layer.mergeAll(
  Database.layer,
  Auth.layer,
  Config.layer,
  LLM.layer,
  ToolRegistry.layer,
  // ... 50+ services
)

// Runtime usage
const runtime = ManagedRuntime.make(AppLayer)
runtime.runPromise(effect)
```

**Relevance to CandyCrush:** Cleaner async handling with typed errors and composed dependencies.

### 1.10 Filesystem Integration

```typescript
interface FileOperations {
  read(path: string): Effect<string>
  list(path: string): Effect<FileEntry[]>
  find(query: string): Effect<FileEntry[]>    // Fuzzy search
  grep(pattern: string): Effect<GrepResult[]>  // Ripgrep
  isIgnored(path: string): boolean             // Gitignore
}
```

**Relevance to CandyCrush:** Comprehensive file operations with gitignore support.

---

## Part 2: UI/TUI Features

### 2.1 Virtualized Message Timeline

```typescript
// virtua for virtualized rendering
import { createVirtualizer } from "virtua/solid"

const virtualizer = createVirtualizer({
  count: messages.length,
  getScrollElement: () => scrollRef,
  estimateSize: () => 80,
  overscan: 5,
})
```

**Features:**
- Handles 10,000+ messages efficiently
- Auto-scroll with gesture detection
- Shift-mode for loading older messages
- Cached row measurements

**Relevance to CandyCrush:** Handle large conversation histories without memory issues.

### 2.2 Syntax Highlighting Pipeline

```typescript
// Markdown + Shiki pipeline
import { Marked } from "marked"
import { markedShiki } from "@shikijs/markdown"

const md = new Marked({
  renderer: markedShiki({
    theme: "nord",
    transformers: [...],
  }),
})
```

**Features:**
- VS Code-quality syntax highlighting
- Multiple themes (Nord, Dracula, TokyoNight, etc.)
- Line numbers, line highlighting
- Transformer plugins

**Relevance to CandyCrush:** Beautiful code block rendering.

### 2.3 Terminal Integration

```typescript
// Ghostty-web for terminal emulator
import { Terminal } from "ghostty-web"
import { SerializeAddon, FitAddon } from "@xterm/addons"

// WebSocket communication
const ws = new WebSocket(`ws://${host}:${port}/terminal/${sessionId}`)
ws.onmessage = (event) => terminal.onData(event.data)
```

**Features:**
- Full terminal emulator in web UI
- Session persistence via serialization
- Theme synchronization
- Copy/paste, link detection
- 10,000 line scrollback

**Relevance to CandyCrush:** Embedded shell panel for running tests/builds.

### 2.4 File Tree with Git Status

```typescript
// Git change indicators
type ChangeType = "added" | "deleted" | "modified"

// Display with status badges
<div class={changeType === "added" ? "text-green" : changeType === "deleted" ? "text-red" : "text-yellow"}>
  {filename}
  <span>{changeType === "added" ? "A" : changeType === "deleted" ? "D" : "M"}</span>
</div>
```

**Features:**
- Lazy directory loading
- Drag and drop
- Gitignore filtering
- File icons per type

**Relevance to CandyCrush:** Show which files have been modified during the session.

### 2.5 Slash Commands & @Mentions

```typescript
// Slash command parsing
const SLASH_REGEX = /^\/(\w+)(?:\s+(.*))?$/
const AT_AGENT_REGEX = /^@(\w+)/

// Command palette with fuzzy search
const commands = [
  { id: "new-session", label: "New Session", shortcut: "Ctrl+N" },
  { id: "compact", label: "Compact Context" },
  // ...
]
```

**Features:**
- `/` triggers command palette
- `@` triggers agent selector
- Fuzzy search through commands
- Tab completion
- Custom commands via plugin API

**Relevance to CandyCrush:** Quick access to common actions.

### 2.6 Context Items

```typescript
// Attached context to prompts
interface ContextItem {
  type: "file" | "selection" | "comment" | "url"
  path?: string
  content?: string
  selection?: { start: number; end: number }
}

// Display in prompt area
<PromptInput>
  <ContextItem file="src/App.php" selection={[10, 25]} />
  <ContextItem comment="Fix the auth bug" />
  <TextInput>How do I fix the issue?</TextInput>
</PromptInput>
```

**Relevance to CandyCrush:** Attach file comments and context to prompts.

### 2.7 Progress & Streaming Indicators

```typescript
// Animated text reveal
<span class="animate-text-reveal">{partialText}<span class="cursor">█</span></span>

// Token counter progress bar
<div class="progress-bar">
  <div style={{ width: `${(tokens / maxTokens) * 100}%` }} />
  <span>{tokens} / {maxTokens} tokens</span>
</div>
```

**Features:**
- Real-time token streaming
- Token budget visualization
- Agent color-coded progress
- Thinking block animations

**Relevance to CandyCrush:** Show streaming response progress.

### 2.8 Theme System

```typescript
// Theme JSON schema
interface Theme {
  defs: Record<string, string>  // Color definitions
  theme: {
    primary: string
    secondary: string
    accent: string
    error: string
    // ... semantic roles
    dark?: ColorValue
    light?: ColorValue
  }
}

// CSS variable injection
document.documentElement.style.setProperty("--color-primary", theme.primary)
```

**Features:**
- Dark/light mode separation
- 15+ semantic color roles
- ANSI code support
- Nord, Dracula, TokyoNight presets

**Relevance to CandyCrush:** Consistent, swappable theming.

### 2.9 Dialog/Modal System

```typescript
// Stack-based dialog API
api.ui.dialog.confirm({
  title: "Delete Session?",
  message: "This action cannot be undone.",
  actions: [
    { label: "Cancel", value: "cancel" },
    { label: "Delete", value: "delete", variant: "danger" },
  ],
}).then(result => { /* handle */ })

// Built-in types: alert, confirm, prompt, select
```

**Relevance to CandyCrush:** Clean modal system for confirmations and prompts.

### 2.10 Toast Notifications

```typescript
api.ui.toast.show({
  type: "success" | "error" | "info",
  title: "File Saved",
  message: "src/App.php has been updated.",
  duration: 3000,
})
```

**Relevance to CandyCrush:** Non-blocking user feedback.

---

## Part 3: Key Innovations & Patterns

### 3.1 Effect-Based Dependency Injection

```typescript
// Clean service composition
class Database extends Context.Service<Database, Interface>()("Database") {}

const AppLayer = Layer.effect(
  Database,
  Effect.gen(function* () {
    const config = yield* Config.Service
    const driver = yield* DatabaseDriver
    return new Database({ config, driver })
  })
)

// Use in effects
const getUser = Effect.fn("db.getUser")(function* (id: string) {
  const db = yield* Database.Service
  return yield* db.query(`SELECT * FROM users WHERE id = ?`, [id])
})
```

**Benefits:**
- Typed dependencies
- Composable layers
- Scoped cleanup
- Effect-typed errors

### 3.2 Schema-Based Validation

```typescript
import { Schema } from "effect"

// Branded types for type safety
const UserId = Schema.String.pipe(Schema.brand("UserId"))
type UserId = Schema.Type<typeof UserId>

// Request validation
const CreateUser = Schema.Struct({
  name: Schema.String,
  email: Schema.String.pipe(Schema.compose(Schema.Email)),
  age: Schema.Number.pipe(Schema.clamp(0, 150)),
})

// Runtime validation with error messages
const result = Schema.decode(CreateUser)({ name: "Bob", email: "invalid", age: 200 })
// Result: Left([{ message: "Expected Email, got \"invalid\"" }])
```

### 3.3 Streaming State Machine

```typescript
// Protocol streaming implementation
const stream = {
  event: Schema.fromJsonString(LifecycleEvent),
  step: (state: ParserState, event: UnknownEvent): [ParserState, LLMEvent[]] => {
    // State transitions
    switch (state._tag) {
      case "Text":
        if (event.type === "text-delta") {
          return [
            { ...state, buffer: state.buffer + event.text },
            [LLMEvent.textDelta(event.text)],
          ]
        }
        if (event.type === "text-end") {
          return [
            { _tag: "Idle" },
            [LLMEvent.textEnd(state.buffer)],
          ]
        }
    }
    return [state, []]
  },
}
```

### 3.4 Tool Stream Accumulator

```typescript
// Handles partial JSON arguments across streaming deltas
class ToolStream {
  private buffer: string = ""
  private args: Record<string, unknown> = {}
  
  add(chunk: string): void {
    this.buffer += chunk
    try {
      this.args = JSON.parse(this.buffer)
    } catch {
      // Incomplete JSON, wait for more
    }
  }
  
  finalize(): Record<string, unknown> {
    if (!this.isComplete()) throw new Error("Incomplete tool arguments")
    return this.args
  }
}
```

### 3.5 Permission Rule Engine

```typescript
// Wildcard matching
const matches = (pattern: string, resource: string): boolean => {
  if (pattern === "*") return true
  if (pattern === resource) return true
  if (pattern.endsWith("/**")) {
    const prefix = pattern.slice(0, -3)
    return resource.startsWith(prefix)
  }
  if (pattern.includes("*")) {
    const regex = new RegExp("^" + pattern.replace(/\*/g, ".*") + "$")
    return regex.test(resource)
  }
  return false
}

// Permission evaluation with first-match wins
const evaluate = (ruleset: Ruleset, request: Request): Effect<Decision> => {
  for (const rule of ruleset) {
    if (matches(rule.action, request.action) && matches(rule.resource, request.resource)) {
      return Effect.succeed(rule.effect === "allow" ? Decision.Allow : Decision.Deny)
    }
  }
  return Effect.succeed(Decision.Ask)
}
```

### 3.6 Event Sourcing Pattern

```typescript
// Define domain events
const UserCreated = EventV2.define({
  type: "user.created",
  schema: { id: ID, email: Schema.String, createdAt: Schema.DateTime },
})

// Publish events
yield* event.publish(UserCreated, { id: newId, email: "bob@example.com", createdAt: new Date() })

// Subscribe and project to read models
yield* event.project(UserCreated, (event, store) => {
  store.users[event.data.id] = event.data
})

// Replay for consistency
yield* event.replayAll(events)
```

### 3.7 Workspace/Project Isolation

```typescript
// Per-directory instance state with cleanup
class InstanceState {
  readonly cache: ScopedCache  // Auto-cleaned on exit
  readonly services: Map<string, Service>
  
  fork<T>(work: (state: InstanceState) => Effect<T>): Effect<T> {
    return Effect.scope(scope => {
      const child = new InstanceState(scope)
      return work(child)
    })
  }
}
```

### 3.8 Multi-Step Tool Loop

```typescript
const executeToolLoop = (request: LLMRequest): Stream<LLMEvent> => {
  return Stream.unwrap(Effect.gen(function* () {
    const tools = yield* ToolRegistry
    const context = yield* Tool.Context
    
    let messages = request.messages
    let stepCount = 0
    const maxSteps = request.maxSteps ?? 10
    
    while (stepCount < maxSteps) {
      const response = yield* llm.complete({ messages, tools })
      
      if (response.finishReason === "stop") {
        return Stream.make(response.events)
      }
      
      // Execute tools
      for (const toolCall of response.toolCalls) {
        const result = yield* tools.execute(toolCall, context)
        messages = [...messages, assistant(toolCall), user(result)]
      }
      
      stepCount++
    }
    
    return Stream.empty
  }))
}
```

---

## Part 4: Recommended Enhancements for CandyCrush

### 4.1 Critical Priority (Security & Reliability)

#### Loop Detection

**Current state:** None
**Problem:** Infinite tool loops can hang indefinitely

**Implementation:**
```php
final class Chat {
    private const MAX_STEPS = 100;
    private const SLIDING_WINDOW = 10;
    
    private array $toolCallHistory = [];
    private int $totalSteps = 0;
    
    private function handleToolCalls(Message $message): array {
        if ($message->toolCalls === []) return [];
        
        $this->totalSteps++;
        
        // Check max steps
        if ($this->totalSteps > self::MAX_STEPS) {
            throw new \RuntimeException("Max steps exceeded");
        }
        
        // Detect repeated tools (same tool > 5 times in window)
        foreach ($message->toolCalls as $toolCall) {
            $this->toolCallHistory[] = $toolCall->name;
            if (count($this->toolCallHistory) > self::SLIDING_WINDOW) {
                array_shift($this->toolCallHistory);
            }
            
            $recentCount = array_count_values($this->toolCallHistory)[$toolCall->name] ?? 0;
            if ($recentCount > 5) {
                throw new \RuntimeException("Repeated tool call detected: {$toolCall->name}");
            }
        }
        
        return $this->executeTools($message->toolCalls);
    }
}
```

#### Permission System

**Current state:** None
**Problem:** Any tool can execute without restriction

**Implementation:**
```php
interface PermissionRule {
    public function matches(string $action, string $resource): bool;
    public function effect(): 'allow' | 'deny' | 'ask';
}

final class PermissionEngine {
    /** @var PermissionRule[] */
    private array $rules = [];
    
    public function evaluate(string $action, string $resource): 'allow' | 'deny' | 'ask' {
        foreach ($this->rules as $rule) {
            if ($rule->matches($action, $resource)) {
                return $rule->effect();
            }
        }
        return 'ask';  // Default to asking
    }
    
    public function ask(string $action, string $resource): PromiseInterface {
        // Publish permission request event
        // Wait for user response
    }
}
```

### 4.2 High Priority (Core Functionality)

#### Context Budget Management

**Current state:** None
**Problem:** Long conversations hit token limits

**Implementation:**
```php
final class TokenCounter {
    private const DEFAULT_CONTEXT_WINDOW = 200000;
    
    public function countMessages(array $messages): int {
        $total = 0;
        foreach ($messages as $message) {
            // Rough estimation: 4 chars per token
            $total += strlen($message->content) / 4;
            // Add tool call overhead
            $total += count($message->toolCalls) * 50;
        }
        return (int) $total;
    }
    
    public function needsCompaction(array $messages, int $contextWindow): bool {
        return $this->countMessages($messages) > ($contextWindow * 0.8);
    }
}

final class ContextCompactor {
    public function compact(array $messages, int $targetTokens): array {
        // Keep system message
        // Keep last N messages
        // Summarize middle messages via LLM
        // Return compacted history
    }
}
```

#### Streaming UI

**Current state:** Tokens accumulated, rendered after complete
**Problem:** No feedback during long responses

**Implementation:**
```php
// Add partial state to Chat
final class Chat implements Model {
    public function __construct(
        public readonly array $history = [],
        public readonly string $inputBuf = '',
        public readonly bool $inFlight = false,
        public readonly ?string $partialContent = null,  // NEW
        ?Backend $backend = null,
    ) {}
}

// New message type
final class PartialTokenMsg implements Msg {
    public function __construct(public readonly string $token) {}
}

// Update handler
public function update(Msg $msg): array {
    return match (true) {
        $msg instanceof PartialTokenMsg => $this->withPartialContent(
            ($this->partialContent ?? '') . $msg->token
        ),
        // ...
    };
}
```

#### Chat History Persistence

**Current state:** Only UI state persisted
**Problem:** No conversation resumption

**Implementation:**
```php
final class ChatHistory {
    private string $dir;
    
    public function __construct(string $dir = '~/.config/sugarcraft-crush/history') {
        $this->dir = $dir;
    }
    
    public function save(string $sessionId, array $messages): void {
        $path = $this->dir . "/{$sessionId}.jsonl";
        $handle = fopen($path, 'a');
        foreach ($messages as $message) {
            fwrite($handle, json_encode([
                'role' => $message->role->value,
                'content' => $message->content,
                'createdAt' => $message->createdAt,
            ]) . "\n");
        }
        fclose($handle);
    }
    
    public function load(string $sessionId): array {
        $path = $this->dir . "/{$sessionId}.jsonl";
        if (!file_exists($path)) return [];
        
        $messages = [];
        foreach (file($path) as $line) {
            $data = json_decode(trim($line), true);
            $messages[] = new Message(
                role: Role::from($data['role']),
                content: $data['content'],
                createdAt: $data['createdAt'],
            );
        }
        return $messages;
    }
}
```

### 4.3 Medium Priority (Feature Parity)

#### Built-in File Tools

**Current state:** Only viewport tools (filter, sort, goto, select, quit)
**Problem:** No file manipulation

**Implementation:**
```php
final class ReadTool implements Tool {
    public function name(): string { return 'Read'; }
    
    public function execute(array $args): ToolResult {
        $path = $args['path'] ?? '';
        if (!file_exists($path)) {
            return ToolResult::error($this->name(), "File not found: {$path}");
        }
        $content = file_get_contents($path);
        return ToolResult::ok($this->name(), $content);
    }
}

final class WriteTool implements Tool {
    public function name(): string { return 'Write'; }
    
    public function execute(array $args): ToolResult {
        $path = $args['path'] ?? '';
        $content = $args['content'] ?? '';
        
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($path, $content);
        return ToolResult::ok($this->name(), "Written {$path}");
    }
}

final class EditTool implements Tool {
    public function name(): string { return 'Edit'; }
    
    public function execute(array $args): ToolResult {
        $path = $args['path'] ?? '';
        $old = $args['old'] ?? '';
        $new = $args['new'] ?? '';
        
        if (!file_exists($path)) {
            return ToolResult::error($this->name(), "File not found: {$path}");
        }
        
        $content = file_get_contents($path);
        if (strpos($content, $old) === false) {
            return ToolResult::error($this->name(), "Pattern not found");
        }
        
        $newContent = str_replace($old, $new, $content);
        file_put_contents($path, $newContent);
        
        return ToolResult::ok($this->name(), "Edited {$path}");
    }
}
```

#### MCP Additional Transports

**Current state:** stdio only (Claude Code)
**Problem:** Limited MCP server support

**Implementation:**
```php
interface McpTransport {
    public function send(McpMessage): ?McpMessage;
    public function subscribe(callable $callback): void;
    public function close(): void;
}

final class StdioTransport implements McpTransport {
    private $process;
    private $input;
    private $output;
    
    public function send(McpMessage $msg): ?McpMessage { /* ... */ }
    public function subscribe(callable $callback): void { /* ... */ }
    public function close(): void { /* ... */ }
}

final class HttpTransport implements McpTransport {
    private string $baseUrl;
    private string $apiKey;
    
    public function send(McpMessage $msg): ?McpMessage {
        $response = $this->client->post($this->baseUrl, [
            'json' => $msg->toArray(),
            'headers' => ['Authorization' => "Bearer {$this->apiKey}"],
        ]);
        return McpMessage::fromArray(json_decode($response, true));
    }
}

final class SseTransport implements McpTransport {
    // Server-Sent Events transport
}
```

#### Hooks System

**Current state:** None
**Problem:** No interception/customization points

**Implementation:**
```php
interface HookInterface {
    public function name(): string;
    public function beforeTool(ToolCall $call): ?ToolResult;
    public function afterTool(ToolCall $call, ToolResult $result): ToolResult;
}

final class HookRunner {
    /** @var HookInterface[] */
    private array $hooks = [];
    
    public function register(HookInterface $hook): void {
        $this->hooks[] = $hook;
    }
    
    public function beforeTool(ToolCall $call): ?ToolResult {
        foreach ($this->hooks as $hook) {
            $result = $hook->beforeTool($call);
            if ($result !== null) return $result;
        }
        return null;  // Proceed
    }
    
    public function afterTool(ToolCall $call, ToolResult $result): ToolResult {
        foreach ($this->hooks as $hook) {
            $result = $hook->afterTool($call, $result);
        }
        return $result;
    }
}

// Built-in hooks
final class ProtectFilesHook implements HookInterface {
    private array $protected = ['composer.json', 'phpunit.xml'];
    
    public function beforeTool(ToolCall $call): ?ToolResult {
        if ($call->name === 'Write' && in_array($call->args['path'], $this->protected)) {
            return ToolResult::error('Write', "Protected file: {$call->args['path']}");
        }
        return null;
    }
}
```

### 4.4 Lower Priority (Enhancements)

#### Plan/Build Mode

**Current state:** None
**Problem:** No read-only planning mode

**Implementation:**
```php
final class Chat implements Model {
    public function __construct(
        // ...
        public readonly bool $planMode = false,  // NEW
    ) {}
    
    public function withPlanMode(bool $planMode): self {
        return new self(
            history: $this->history,
            inputBuf: $this->inputBuf,
            inFlight: $this->inFlight,
            partialContent: $this->partialContent,
            backend: $this->backend,
            planMode: $planMode,
        );
    }
}

// In tool execution
private function executeTool(ToolCall $call): ToolResult {
    $writeTools = ['Write', 'Edit', 'Delete', 'Bash'];
    
    if ($this->planMode && in_array($call->name, $writeTools)) {
        return ToolResult::error($call->name, "Write tools disabled in plan mode");
    }
    
    // Execute normally
}
```

#### Syntax Highlighting

**Current state:** Plain code blocks via CandyShine
**Problem:** No syntax coloring

**Implementation:**
```php
// Option 1: Shiki wrapper
final class SyntaxHighlighter {
    private $highlighter;
    
    public function __construct() {
        $this->highlighter = new ShikiHighlighter([
            'theme' => 'nord',
        ]);
    }
    
    public function highlight(string $code, string $language): string {
        return $this->highlighter->codeToHtml($code, [
            'theme' => 'nord',
            'lang' => $language,
        ]);
    }
}

// Option 2: Integrate with CandyShine
// Configure CandyShine's code block renderer to use Shiki
```

#### Custom Themes

**Current state:** Hardcoded TokyoNight
**Problem:** No theme switching

**Implementation:**
```php
enum Theme: string {
    case Nord = 'nord';
    case Dracula = 'dracula';
    case TokyoNight = 'tokyo-night';
    case Custom = 'custom';
}

final class ThemeManager {
    public function load(Theme $theme): array {
        return match ($theme) {
            Theme::Nord => $this->nordColors(),
            Theme::Dracula => $this->draculaColors(),
            // ...
        };
    }
    
    public function installCustom(string $path): void {
        $config = json_decode(file_get_contents($path), true);
        $this->validateTheme($config);
        // Store custom theme
    }
}
```

#### CLAUDE.md Loading

**Current state:** None
**Problem:** No project context loading

**Implementation:**
```php
final class ContextLoader {
    private array $paths = [
        'CLAUDE.md',
        '.cody/config.json',
        '.github/copilot-instructions.md',
    ];
    
    public function load(string $cwd): array {
        $contexts = [];
        
        foreach ($this->paths as $path) {
            $fullPath = $cwd . '/' . $path;
            if (file_exists($fullPath)) {
                $contexts[] = file_get_contents($fullPath);
            }
        }
        
        return $contexts;
    }
    
    public function systemPrompt(string $cwd): string {
        $contexts = $this->load($cwd);
        if ($contexts === []) return '';
        
        return "\n\n# Project Context\n\n" . implode("\n\n---\n\n", $contexts);
    }
}
```

### 4.5 Architecture Improvements

#### Provider Abstraction Layer

```php
interface Provider {
    public function name(): string;
    public function supportsStreaming(): bool;
    public function supportsFunctionCalling(): bool;
    public function contextWindow(): int;
    public function complete(CompleteRequest $request): CompleteResponse;
    public function completeStream(CompleteRequest $request): \Generator;
}

interface LanguageModel {
    public function generate(Context $context, Options $options): Response;
    public function stream(Context $context, Options $options): \Generator;
}

final class ProviderManager {
    /** @var Provider[] */
    private array $providers = [];
    
    public function register(Provider $provider): void {
        $this->providers[$provider->name()] = $provider;
    }
    
    public function get(string $name): Provider {
        return $this->providers[$name] ?? throw new \RuntimeException("Unknown provider: {$name}");
    }
}
```

#### Event-Driven Architecture

```php
interface Event {
    public function type(): string;
    public function payload(): array;
}

final class EventBus {
    /** @var callable[] */
    private array $subscribers = [];
    
    public function publish(Event $event): void {
        $type = $event->type();
        foreach ($this->subscribers[$type] ?? [] as $callback) {
            $callback($event);
        }
    }
    
    public function subscribe(string $type, callable $callback): void {
        $this->subscribers[$type][] = $callback;
    }
}

// Define events
final class ToolExecutedEvent implements Event {
    public function __construct(
        public readonly string $toolName,
        public readonly array $args,
        public readonly string $result,
        public readonly float $duration,
    ) {}
    
    public function type(): string { return 'tool.executed'; }
    public function payload(): array { return get_object_vars($this); }
}
```

#### Service-Based Configuration

```php
interface ConfigService {
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function all(): array;
}

final class AppConfig implements ConfigService {
    private array $config;
    
    public function __construct() {
        $this->config = $this->loadHierarchy();
    }
    
    private function loadHierarchy(): array {
        // 1. Global: ~/.config/sugarcraft-crush/config.json
        // 2. Project: ./sugar-crush.json
        // 3. Local: ./.sugar-crush/config.json
        
        $merged = [];
        $merged = array_merge($merged, $this->loadFile('~/.config/sugarcraft-crush/config.json'));
        $merged = array_merge($merged, $this->loadFile('./sugar-crush.json'));
        $merged = array_merge($merged, $this->loadFile('./.sugar-crush/config.json'));
        return $merged;
    }
}
```

---

## Part 5: Strategic Recommendations

### 5.1 Immediate Actions (0-2 months)

1. **Loop Detection** - Add step counting and repeated tool detection to prevent infinite loops
2. **Permission System** - Implement wildcard-based ruleset for tool execution control
3. **Streaming UI** - Render partial tokens as they arrive, not after complete response
4. **Chat History** - Add JSONL-based session persistence

### 5.2 Short-term (2-4 months)

5. **Context Budget** - Token counting and auto-compaction
6. **Built-in Tools** - Register read/write/edit/glob/grep tools
7. **Hook System** - PreToolUse/PostToolUse hooks
8. **Provider Abstraction** - Clean provider interface with built-in HTTP clients

### 5.3 Medium-term (4-8 months)

9. **MCP Enhancement** - HTTP and SSE transports
10. **Subagents** - Specialized reviewer/debugger/architect agents
11. **Plan Mode** - Read-only planning mode with tool restrictions
12. **Server-Client** - REST API mode for headless operation

### 5.4 Long-term (8-12 months)

13. **LSP Integration** - Code intelligence via language servers
14. **TreeSitter** - AST-aware context packing
15. **Multi-agent** - Concurrent subagent orchestration
16. **Workspace Sharing** - SSE-based collaboration

---

## Part 6: Priority Matrix

| Feature | Impact | Complexity | Risk | Priority |
|---------|--------|------------|------|----------|
| Loop Detection | Critical | Medium | Low | P0 |
| Streaming UI | High | Medium | Low | P1 |
| Chat History | High | Medium | Medium | P1 |
| Context Budget | High | Medium | Medium | P1 |
| Permission System | Critical | High | High | P0 |
| Built-in Tools | High | Medium | Low | P1 |
| Hooks System | Medium | Medium | Low | P2 |
| MCP Transports | Medium | Medium | Low | P2 |
| Provider Abstraction | High | High | Medium | P2 |
| Plan Mode | Medium | Medium | Low | P2 |
| Syntax Highlighting | Medium | Medium | Low | P3 |
| Custom Themes | Low | Low | Low | P3 |
| Subagents | Medium | High | High | P3 |
| Server-Client | Medium | High | High | P3 |
| LSP Integration | Low | High | High | P4 |

---

## Conclusion

OpenCode demonstrates mature, production-grade patterns for AI coding assistants that SugarCraft's CandyCrush can leverage. The most critical gaps to address immediately are **loop detection** and **permission systems** — both required for safe autonomous operation.

The upstream charmbracelet/crush's archival in March 2026 creates a strategic opportunity: PHP developers need an equivalent for AI-assisted coding, and CandyCrush is positioned to fill that gap. However, it needs to evolve from a simple chat interface to a true autonomous agent with proper safeguards.

The recommended path forward prioritizes reliability (loop detection, permissions) before extensibility (hooks, MCP), with provider abstraction and context management as enablers for practical long-term use.

---

*Report compiled from analysis of opencode v0.1.x codebase at https://github.com/anomalyco/opencode*
