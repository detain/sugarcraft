# CandyCrush - AI Coding Assistant

> TUI AI coding assistant for SugarCraft — multi-provider, multi-agent, skill-aware

## Overview

CandyCrush is a full-screen terminal AI coding assistant that integrates with:
- **OpenAI** via `openai-php/client`
- **Claude Code CLI** (headless mode, `-p` flag, streaming)
- **SGLANG endpoints** (MiniMax-M2.7, custom compatible endpoints)
- **Any OpenAI-compatible API** (custom providers)
- **Anthropic** (direct)
- **AWS Bedrock**, **Google Vertex**, **Azure Foundry**

Inspired by and rivaling [charmbracelet/crush](https://github.com/charmbracelet/crush), CandyCrush adds:
- First-class PHP ecosystem support (Composer, Laravel, Symfony, PSR standards)
- SKILL.md-based skills system (mirroring Claude Code's conventions)
- MCP (Model Context Protocol) integration
- Subagent/Agent system
- Full multi-pane TUI with menu system
- Built-in hooks system (PreToolUse, PostToolUse)

---

## Comparison: Crush vs CandyCrush

| Feature | Crush | CandyCrush |
|---------|-------|------------|
| PHP/Laravel support | ❌ | ✅ First-class |
| PSR-12 code review | ❌ | ✅ Built-in |
| OpenAI PHP SDK | ❌ | ✅ `openai-php/client` |
| Claude Code CLI | ❌ | ✅ Native integration |
| SGLANG/MiniMax support | ❌ | ✅ OpenAI-compatible |
| Skills (SKILL.md) | ✅ | ✅ + PHP-focused templates |
| MCP servers | ✅ | ✅ + PHP tooling MCP |
| Hooks (PreToolUse) | ✅ | ✅ + PHP-focused hooks |
| Multi-pane TUI | ✅ | ✅ (see candy-query) |
| Menu system | Basic | Full menu bar |
| Agent system | ✅ | ✅ + PHP agent types |
| Server/Client mode | ✅ | Planned |
| Bedrock/Vertex | ✅ | ✅ |
| Hyper integration | ✅ | ❌ (not applicable) |

---

## Architecture

```
candy-crush/
├── src/
│   ├── App/                    # Application state + TEA model
│   │   ├── App.php             # Main application state (immutable)
│   │   └── AppBuilder.php      # Fluent builder
│   ├── Tui/
│   │   ├── Pane.php            # Enum: Chat | Messages | Input | Skills | Settings | Agents
│   │   ├── Renderer.php        # Multi-pane TUI renderer
│   │   ├── BorderFrame.php     # Terminal border/frame utilities
│   │   └── Components/         # Reusable TUI components
│   │       ├── ChatPane.php
│   │       ├── MessageBubble.php
│   │       ├── SkillList.php
│   │       ├── AgentPanel.php
│   │       ├── ProviderStatus.php
│   │       └── MenuBar.php
│   ├── Providers/
│   │   ├── ProviderInterface.php
│   │   ├── OpenAIProvider.php       # openai-php/client wrapper
│   │   ├── ClaudeCodeProvider.php   # Claude Code CLI wrapper
│   │   ├── SglangProvider.php       # SGLANG OpenAI-compatible
│   │   ├── AnthropicProvider.php    # Direct Anthropic API
│   │   ├── BedrockProvider.php      # AWS Bedrock
│   │   ├── VertexProvider.php        # Google Vertex AI
│   │   ├── AzureProvider.php         # Azure Foundry
│   │   └── CustomProvider.php       # Generic OpenAI-compatible
│   ├── Skills/
│   │   ├── Skill.php           # Skill value object
│   │   ├── SkillLoader.php     # Loads .SKILL.md files
│   │   ├── SkillRegistry.php   # Global skill registry
│   │   └── BuiltIn/            # Built-in skills
│   │       ├── PhpBestPractices/
│   │       ├── LaravelExpert/
│   │       ├── PsrCompliant/
│   │       └── PhpUnitMaster/
│   ├── Agents/
│   │   ├── Agent.php           # Agent value object
│   │   ├── AgentManager.php    # Manages subagents
│   │   └── Types/              # Agent type definitions
│   ├── Hooks/
│   │   ├── HookInterface.php
│   │   ├── HookRegistry.php
│   │   ├── PreToolUseHook.php
│   │   └── PostToolUseHook.php
│   ├── MCP/
│   │   ├── McpClient.php       # MCP client wrapper
│   │   ├── McpServerManager.php
│   │   └── Tools/              # MCP tool definitions
│   ├── Messages/
│   │   ├── Message.php         # Immutable message
│   │   ├── UserMessage.php
│   │   ├── AssistantMessage.php
│   │   ├── SystemMessage.php
│   │   └── ToolResultMessage.php
│   ├── Tools/
│   │   ├── Tool.php            # Base tool interface
│   │   ├── ToolResult.php
│   │   ├── BuiltIn/            # Built-in tools
│   │   │   ├── Bash.php
│   │   │   ├── Read.php
│   │   │   ├── Edit.php
│   │   │   ├── Grep.php
│   │   │   ├── Glob.php
│   │   │   └── WebFetch.php
│   │   └── ToolCall.php
│   ├── Config/
│   │   ├── Config.php          # Application config
│   │   ├── ProviderConfig.php  # Per-provider config
│   │   └── hooks.yaml          # Hooks configuration
│   ├── CLI/
│   │   ├── NewSessionCommand.php
│   │   ├── ContinueCommand.php
│   │   ├── ResumeCommand.php
│   │   └── CliInvocation.php   # Invokes Claude Code CLI
│   └── Util/
│       ├── StreamParser.php    # Parses streaming output
│       └── JsonSchema.php      # Structured output schema
├── tests/
├── examples/
├── .skills/                   # User/project skills
│   └── SKILL.md
├── bin/
│   └── candy-crush            # Entry point
├── composer.json
└── phpunit.xml
```

---

## Providers

### Provider Interface

```php
interface ProviderInterface
{
    public function name(): string;

    public function supportsStreaming(): bool;

    public function supportsFunctionCalling(): bool;

    public function supportsVision(): bool;

    public function supports_json_schema(): bool;

    public function contextWindow(): int;

    public function costPer1kTokens(string $model, 'input'|'output'): float;

    public function complete(CompleteRequest $request): CompleteResponse;

    public function completeStream(CompleteRequest $request): \Generator;

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse;
}
```

### OpenAI Provider

Uses `openai-php/client`:

```php
use OpenAI\Client;
use SugarCraft\Crush\Providers\OpenAIProvider;

$client = OpenAI::client($_ENV['OPENAI_API_KEY']);
$provider = new OpenAIProvider($client);
```

### SGLANG Provider (OpenAI-Compatible)

```php
use SugarCraft\Crush\Providers\SglangProvider;

$provider = SglangProvider::openAiCompatible(
    baseUrl: 'http://localhost:8080/v1',
    apiKey: 'local',  // or null
    model: 'MiniMax-M2.7',
);
```

### Claude Code Provider

Wraps Claude Code CLI in headless (`-p`) mode:

```php
use SugarCraft\Crush\Providers\ClaudeCodeProvider;
use SugarCraft\Crush\Providers\ClaudeCodeInvocation;

$invocation = new ClaudeCodeInvocation(
    claudePath: '/usr/local/bin/claude',  // or 'claude' from PATH
    configDir: '~/.claude',               // Claude Code config
);

$provider = new ClaudeCodeProvider($invocation);
```

**Claude Code CLI Integration Details:**

| Feature | Implementation |
|---------|---------------|
| **Headless mode** | `claude -p "prompt" --output-format stream-json --include-partial-messages` |
| **Streaming** | `--output-format stream-json` + parse SSE-like events |
| **Tool permission** | `--allowedTools "Read,Bash,Edit,Grep"` |
| **Session continue** | `--continue` flag with `--resume <session-id>` |
| **Background agent** | `--bg` flag, capture session ID |
| **Structured output** | `--json-schema` + `--output-format json` |
| **Bare mode (fast)** | `--bare` flag, skips hooks/skills/MCP discovery |
| **Environment** | `ANTHROPIC_API_KEY`, `ANTHROPIC_BASE_URL` |

### Custom Provider (Generic OpenAI-Compatible)

```php
use SugarCraft\Crush\Providers\CustomProvider;

$provider = CustomProvider::openAiCompatible(
    name: 'My SGLANG',
    baseUrl: 'http://my-sglang:8080/v1',
    apiKey: fn() => file_get_contents('/run/secrets/sglang_key'),
    model: 'minimax/MiniMax-M2.7',
    supportsStreaming: true,
    supportsFunctionCalling: true,
);
```

---

## Skills System

CandyCrush supports SKILL.md files following Claude Code's convention, with PHP-specific enhancements.

### SKILL.md Format

```markdown
---
description: Reviews PHP code for PSR-12 violations and best practices. Use when reviewing PRs or writing PHP.
user-invocable: true
disable-model-invocation: false
allowed-tools: "Read,Grep,Bash"
effort: high
context: fork
paths:
  - "**/*.php"
---
# PHP Code Review Skill

When reviewing PHP code, check for:
1. PSR-12 compliance (namespace, braces, imports)
2. Type safety (strict_types, return types, nullable)
3. Security (SQL injection, XSS, CSRF)
4. Error handling (exceptions, try-catch)
5. Performance (eager vs lazy loading, caching)
```

### Skill Frontmatter Reference

| Field | Type | Purpose |
|-------|------|---------|
| `description` | string | When to invoke this skill (required) |
| `user-invocable` | bool | Show in `/` menu (default: true) |
| `disable-model-invocation` | bool | Only user can invoke via `/name` |
| `allowed-tools` | string | Auto-approved tools when skill active |
| `disallowed-tools` | string | Tools disabled while skill active |
| `model` | string | Override model when skill active |
| `effort` | string | Effort level (low/medium/high/xhigh/max) |
| `context` | string | `fork` = subagent, `thread` = same context |
| `paths` | string[] | Glob patterns for auto-activation |

### Skill Locations

| Scope | Path | Applies To |
|-------|------|-----------|
| User | `~/.candy-crush/skills/<name>/SKILL.md` | All projects |
| Project | `.candy-crush/skills/<name>/SKILL.md` | This project |
| Built-in | `src/Skills/BuiltIn/<name>/SKILL.md` | All projects |

### Loading Skills

```php
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillRegistry;

$loader = new SkillLoader();
$registry = new SkillRegistry();

// Load user skills
$registry->register($loader->loadUserSkills());

// Load project skills
$registry->register($loader->loadProjectSkills('.'));

// Load built-in skills
$registry->register($loader->loadBuiltInSkills());

// Find skills matching a prompt
$matching = $registry->findForPrompt('Review this PHP code for bugs');
```

### Built-in Skills

| Skill | Description |
|-------|-------------|
| `php-best-practices` | PSR-12, type safety, SOLID principles |
| `laravel-expert` | Laravel conventions, Eloquent, Blade |
| `symfony-expert` | Symfony conventions, DI, services |
| `phpunit-master` | PHPUnit 10 best practices, mocking |
| `security-audit` | OWASP Top 10, common vulnerabilities |
| `psr-compliance` | Auto-fix PSR-12 violations |
| `composer-wizard` | Dependency management, composer.json |
| `git-assist` | Git workflows, commit messages |

---

## MCP (Model Context Protocol) Integration

### MCP Server Configuration (`.mcp.json` or `candy-crush.mcp.json`)

```json
{
  "mcpServers": {
    "filesystem": {
      "type": "stdio",
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-filesystem"],
      "env": {
        "ROOT": "${HOME}/projects"
      }
    },
    "github": {
      "type": "http",
      "url": "https://api.githubcopilot.com/mcp/",
      "headers": {
        "Authorization": "Bearer ${GITHUB_TOKEN}"
      }
    },
    "php-tools": {
      "type": "stdio",
      "command": "php",
      "args": ["${CANDY_CRUSH_ROOT}/mcp-servers/php-tools.php"]
    }
  }
}
```

### PHP Tools MCP Server

A dedicated MCP server for PHP development:

```php
// mcp-servers/php-tools.php
use SugarCraft\Crush\MCP\PHPToolsServer;

// Tools provided:
// - phpstan_analyze(path)
// - composer_validate(path)
// - phpunit_run(path, testClass?)
// - psr_format(path)
// - class_ancestors(className, file)
// - function_location(functionName, file?)
// - find_usages(symbol, file)

$server = new PHPToolsServer();
$server->run();
```

### Using MCP in Code

```php
use SugarCraft\Crush\MCP\McpClient;

$client = new McpClient('.mcp.json');

$client->startServers();

// List available tools
$tools = $client->listTools();
foreach ($tools as $tool) {
    echo $tool->name() . ': ' . $tool->description();
}

// Call a tool
$result = $client->callTool('phpstan_analyze', [
    'path' => '/path/to/project',
    'level' => 5,
]);

// Stop servers
$client->stopServers();
```

---

## Hooks System

CandyCrush implements a hooks system similar to Crush for intercepting and modifying tool calls.

### Hook Configuration (`hooks.yaml`)

```yaml
hooks:
  PreToolUse:
    - matcher: "^bash$"
      command: ".candy-crush/hooks/protect-files.sh"
      description: "Protect critical files from modification"

    - matcher: "^rm$"
      command: ".candy-crush/hooks/confirm-rm.sh"
      description: "Confirm destructive file operations"

    - matcher: ".*"
      command: ".candy-crush/hooks/log-all.sh"
      description: "Log all tool calls for audit"

  PostToolUse:
    - matcher: "^bash$"
      command: ".candy-crush/hooks/audit-bash.sh"
      description: "Audit bash tool results"
```

### Hook Interface

```php
interface HookInterface
{
    public function name(): string;

    public function event(): 'PreToolUse'|'PostToolUse';

    public function matcher(): string;  // Regex pattern

    public function execute(HookContext $context): HookResult;
}

class HookContext
{
    public string $sessionId;
    public string $toolName;
    public array $toolArgs;
    public string $toolInput;       // For PreToolUse
    public string $toolOutput;      // For PostToolUse
    public string $model;
    public string $provider;
}
```

### Built-in Hooks

| Hook | Description |
|------|-------------|
| `protect-files` | Prevents modification of config files |
| `confirm-rm` | Prompts for confirmation before `rm -rf` |
| `audit-bash` | Logs all bash commands to audit file |
| `auto-format` | Auto-formats code after Edit tool |
| `php-lint` | Runs `php -l` after Edit on .php files |

---

## Agent System

### Agent Definition

```json
{
  "name": "php-reviewer",
  "description": "Specialized PHP code reviewer",
  "prompt": "You are a PHP expert specializing in code review. Check for PSR-12 compliance, type safety, security issues, and performance problems.",
  "model": "claude-sonnet-4-6",
  "provider": "anthropic",
  "tools": ["Read", "Grep", "Bash(git:*)"],
  "hooks": ["php-lint"],
  "skills": ["php-best-practices", "security-audit"]
}
```

### Agent Types

| Type | Description |
|------|-------------|
| `coder` | General coding assistance |
| `reviewer` | Code review specialist |
| `debugger` | Bug investigation and fixing |
| `architect` | System design and architecture |
| `tester` | Test writing and coverage |
| `devops` | CI/CD, deployment, infrastructure |

### Subagent Management

```php
use SugarCraft\Crush\Agents\AgentManager;

$manager = new AgentManager();

// Create a reviewer subagent
$reviewer = $manager->create('php-reviewer', [
    'model' => 'claude-sonnet-4-6',
    'tools' => ['Read', 'Grep', 'Bash(git:*)'],
    'context' => 'fork',  // Runs in separate context
]);

// Send task to subagent
$result = $reviewer->task('Review the authentication module for security issues');

// Get subagent results
$output = $result->output();
$toolsUsed = $result->toolsUsed();
```

---

## TUI Architecture

CandyCrush uses the same multi-pane architecture as candy-query but adapted for AI chat.

### Pane Enum

```php
enum Pane: string
{
    case Chat     = 'chat';      // Main chat/message view
    case Input    = 'input';     // Message input area
    case Skills   = 'skills';    // Skill browser/selector
    case Agents   = 'agents';    // Agent management
    case Files    = 'files';     // File tree / context
    case Tools    = 'tools';     // Tool call history
    case Settings = 'settings';  // Configuration
    case Help     = 'help';      // Help/shortcuts
}
```

### Multi-Pane Layout

```
┌──────────────────────────────────────────────────────────────────────┐
│  Menu: [File] [Edit] [Session] [Provider] [Skills] [Agents] [Help] │
├────────────────────┬─────────────────────────────────────────────────┤
│                    │                                                 │
│   Files / Context  │            Chat / Messages                      │
│   (file tree,      │   (scrollable message history with             │
│    selected files) │    assistant/user/tool messages)                 │
│                    │                                                 │
│                    │                                                 │
├────────────────────┼─────────────────────────────────────────────────┤
│   Tools / History  │            Input                                │
│   (tool calls,     │   (multi-line input with markdown preview)       │
│    recent actions) │                                                 │
├────────────────────┴─────────────────────────────────────────────────┤
│  Provider: Claude Code │ Model: claude-sonnet-4-6 │ Tokens: 1,234   │
│  Skills: php-best-practices, security-audit │ [Tab] Switch Pane    │
└──────────────────────────────────────────────────────────────────────┘
```

### Keyboard Shortcuts

| Key | Action |
|-----|--------|
| `Tab` | Cycle through panes |
| `Ctrl+N` | New session |
| `Ctrl+C` | Cancel current operation |
| `Ctrl+G` | Toggle group (multi-line input) |
| `Ctrl+K` | Open command palette |
| `Ctrl+S` | Source/apply skill |
| `Ctrl+A` | Open agent panel |
| `Ctrl+P` | Provider selector |
| `Ctrl+,` | Settings |
| `↑/↓` or `j/k` | Navigate lists |
| `Enter` | Send message / select |
| `Esc` | Cancel / go back |
| `q` | Quit |

### Menu System

**Top Menu Bar:**

| Menu | Items |
|------|-------|
| **File** | New Session, Open Session, Save Transcript, Export Chat, Preferences, Quit |
| **Edit** | Copy, Paste, Select All, Clear History |
| **Session** | Continue, New Session, Session History, Attach Context |
| **Provider** | OpenAI, Anthropic, Claude Code, SGLANG, Bedrock, Vertex, Custom... |
| **Skills** | Browse Skills, Enable Skill..., Manage Built-in Skills |
| **Agents** | Create Agent, Manage Agents, Active Agents |
| **Help** | Keyboard Shortcuts, Documentation, About |

### Rendering

Uses sugar-sprinkles for styling:
- `Border::rounded()` with title in border
- `Color::hex()` for TokyoNight theme colors
- `Style` fluent builder for foreground, background, bold, etc.
- `Layout::joinHorizontal()` for side-by-side panes

---

## Configuration

### Global Config (`~/.candy-crush/config.json`)

```json
{
  "provider": "claude-code",
  "model": "claude-sonnet-4-6",
  "temperature": 0.7,
  "max_tokens": 4096,
  "tools": ["Read", "Edit", "Bash", "Grep", "Glob", "WebFetch"],
  "allowed_tools": [],
  "hooks": {
    "enabled": true,
    "dir": "~/.candy-crush/hooks"
  },
  "skills": {
    "dir": "~/.candy-crush/skills",
    "disabled": []
  },
  "mcp": {
    "config": "~/.candy-crush/.mcp.json"
  },
  "agents": {
    "dir": "~/.candy-crush/agents"
  },
  "claude_code": {
    "path": "claude",
    "config_dir": "~/.claude"
  },
  "providers": {
    "openai": {
      "api_key": "${OPENAI_API_KEY}",
      "organization": null
    },
    "anthropic": {
      "api_key": "${ANTHROPIC_API_KEY}"
    },
    "sglang": {
      "base_url": "http://localhost:8080/v1",
      "api_key": "local",
      "model": "MiniMax-M2.7"
    }
  },
  "env": {
    "EDITOR": "${EDITOR:-vim}"
  }
}
```

### Project Config (`.candy-crush.json`)

```json
{
  "provider": "sglang",
  "model": "MiniMax-M2.7",
  "skills": ["php-best-practices"],
  "tools": ["Read", "Bash"],
  "allowed_tools": ["Bash(git:*,npm:*,composer:*)"],
  "context": {
    "dirs": ["src", "tests"],
    "exclude": ["vendor", "node_modules", ".git"]
  }
}
```

---

## CLI Commands

### Entry Point (`bin/candy-crush`)

```bash
# Interactive mode
candy-crush

# New session with prompt
candy-crush "Explain this function"

# Headless mode (scripted)
candy-crush -p "Review auth module" --allowedTools "Read,Grep"

# Continue last session
candy-crush -c

# Resume specific session
candy-crush -r <session-id>

# With specific provider/model
candy-crush --provider openai --model gpt-4o "Explain this code"

# With custom SGLANG endpoint
candy-crush --provider sglang --model MiniMax-M2.7 --base-url http://localhost:8080/v1

# With Claude Code
candy-crush --provider claude-code --model claude-sonnet-4-6 "Find bugs"

# Output as JSON
candy-crush -p "Summarize" --output-format json

# Verbose (show all API calls)
candy-crush -p "Test" --verbose
```

### Flags Reference

| Flag | Description |
|------|-------------|
| `-p`, `--print` | Print response and exit (headless) |
| `-c`, `--continue` | Continue last session |
| `-r`, `--resume <id>` | Resume specific session |
| `--provider <name>` | Set provider (openai, anthropic, claude-code, sglang, bedrock, vertex, custom) |
| `--model <name>` | Set model |
| `--base-url <url>` | Set base URL for custom providers |
| `--allowed-tools <list>` | Comma-separated tool whitelist |
| `--tools <list>` | Available tools (default: all) |
| `--output-format text\|json\|stream-json` | Output format |
| `--verbose` | Verbose output |
| `--bare` | Skip hooks/skills/MCP (fast mode) |
| `--session-id <uuid>` | Use specific session ID |
| `--no-session-persistence` | Don't save session |
| `--max-budget-usd <amount>` | Max API spend |
| `--max-turns <n>` | Max agent turns |
| `--permission-mode acceptEdits\|dontAsk\|bypassPermissions` | Permission mode |
| `--system-prompt <text>` | Override system prompt |
| `--append-system-prompt <text>` | Append to system prompt |
| `--mcp-config <path>` | MCP config file path |

---

## Session Persistence

Sessions stored in SQLite:

```sql
CREATE TABLE sessions (
    id TEXT PRIMARY KEY,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    provider TEXT NOT NULL,
    model TEXT NOT NULL,
    system_prompt TEXT,
    metadata TEXT  -- JSON
);

CREATE TABLE messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT NOT NULL,
    role TEXT NOT NULL,  -- user, assistant, system, tool
    content TEXT NOT NULL,
    tool_calls TEXT,     -- JSON
    tool_results TEXT,   -- JSON
    model TEXT,
    tokens_used INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id)
);

CREATE TABLE tool_calls (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT NOT NULL,
    message_id INTEGER NOT NULL,
    tool_name TEXT NOT NULL,
    tool_args TEXT NOT NULL,  -- JSON
    tool_result TEXT,
    duration_ms INTEGER,
    success INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id),
    FOREIGN KEY (message_id) REFERENCES messages(id)
);
```

---

## Implementation Phases

### Phase 1: Core Foundation
- [ ] Project scaffold with composer.json, phpunit.xml
- [ ] Provider interface and basic providers (OpenAI, Anthropic)
- [ ] Message and Tool classes
- [ ] Basic App state (immutable, with* builders)
- [ ] AppBuilder

### Phase 2: TUI
- [ ] Pane enum and Renderer framework
- [ ] Multi-pane layout (Chat, Input, Files, Tools)
- [ ] Menu bar system
- [ ] Keyboard handling (bubbling, pane focus)
- [ ] Basic styling (TokyoNight colors)

### Phase 3: Provider Integration
- [ ] OpenAI provider (`openai-php/client`)
- [ ] SGLANG provider (OpenAI-compatible)
- [ ] Claude Code provider (CLI wrapper)
- [ ] Bedrock provider
- [ ] Vertex provider

### Phase 4: Skills System
- [ ] Skill value object and loader
- [ ] Skill registry
- [ ] Built-in PHP-focused skills
- [ ] Skill auto-discovery and matching

### Phase 5: Hooks System
- [ ] Hook interface and registry
- [ ] PreToolUse and PostToolUse hooks
- [ ] Built-in hooks (protect-files, confirm-rm, etc.)
- [ ] Hook configuration YAML parsing

### Phase 6: Agents
- [ ] Agent value object
- [ ] Agent manager
- [ ] Built-in agent types
- [ ] Subagent execution

### Phase 7: MCP Integration
- [ ] MCP client wrapper
- [ ] MCP server manager
- [ ] PHP tools MCP server
- [ ] MCP tool discovery

### Phase 8: Polish
- [ ] Streaming output rendering
- [ ] Session persistence (SQLite)
- [ ] Provider status indicators
- [ ] Token budget tracking
- [ ] Export/import functionality

---

## Dependencies

```json
{
  "require": {
    "php": "^8.3",
    "openai/openai": "^1.57",
    "guzzlehttp/guzzle": "^7.9",
    "anthropic/anthropic-sdk-php": "^0.10",
    "react/event-loop": "^3.1",
    "react/stream": "^1.4",
    "sugarcraft/candy-core": "*",
    "sugarcraft/candy-sprinkles": "*",
    "sugarcraft/candy-forms": "*",
    "sugarcraft/sugar-table": "*",
    "sugarcraft/sugar-toast": "*",
    "sugarcraft/candy-pty": "*"
  },
  "require-dev": {
    "phpunit/phpunit": "^10.5"
  }
}
```

---

## References

- [Charmbracelet/Crush](https://github.com/charmbracelet/crush) - Inspiration
- [Claude Code CLI](https://code.claude.com/docs/en/cli-reference)
- [Claude Code Headless](https://code.claude.com/docs/en/headless)
- [Claude Code Skills](https://code.claude.com/docs/en/skills.md)
- [Claude Code MCP](https://code.claude.com/docs/en/mcp)
- [Cranot/Claude Code Guide](https://github.com/Cranot/claude-code-guide)
- [OpenAI PHP Client](https://github.com/openai-php/client)
- [Candy-Query TUI](https://github.com/detain/sugarcraft/tree/master/candy-query) - Multi-pane TUI reference
- [SGLANG](https://github.com/sgl-project/sglang) - OpenAI-compatible endpoint
- [MiniMax-M2.7](https://huggingface.co/MiniMaxAI/MiniMax-M2.7) - Model

---

## Terminology

| Term | Definition |
|------|------------|
| **Provider** | Backend service providing LLM access (OpenAI, Anthropic, Claude Code, SGLANG, etc.) |
| **Model** | Specific LLM variant (claude-sonnet-4-6, gpt-4o, MiniMax-M2.7) |
| **Tool** | Executable capability exposed to the model (Read, Edit, Bash, etc.) |
| **Skill** | A SKILL.md file defining specialized behavior for specific tasks |
| **Hook** | Script that fires before/after tool calls to intercept/modify behavior |
| **Agent** | A configured sub-system with its own model, tools, and skills |
| **Session** | A persistent conversation with message history |
| **Pane** | A region of the TUI dedicated to specific functionality |
| **MCP** | Model Context Protocol - standardized way to expose tools to LLMs |

---

## RESUME CHECKPOINT (June 3, 2026 - After Phase 6.1)

### Completed Phases

| Phase | Status | Steps | Notes |
|-------|--------|-------|-------|
| Phase 1: Core Foundation | ✅ Complete | 1.1-1.5 | Scaffold, ProviderInterface, Tools, App state, AppBuilder |
| Phase 2: TUI | ✅ Complete | 2.1-2.5 | Pane enum, Renderer, Components, Menu system, Keyboard handling |
| Phase 3: Provider Integration | ✅ Complete | 3.1-3.6 | OpenAI, SGLANG, Claude Code, Bedrock, Vertex, Custom, ProviderFactory |
| Phase 4: Skills System | ✅ Complete | 4.1-4.4 | Skill, SkillLoader, SkillRegistry, BuiltIn skills, SkillManager |
| Phase 5: Hooks System | ✅ Complete | 5.1-5.3 | HookInterface, HookContext, HookResult, HookRegistry, BuiltIn hooks, HookConfig, HookManager |
| Phase 6: Agents | 🔄 In Progress | 6.1 ✅ | Agent, AgentDefinition - Step 6.2 next |

### Next Step: Step 6.2 - Agent Manager

**File:** `.candy-crush-plan/steps/6.2_agent_manager.md`

Step 6.2 implements AgentManager for managing subagents.

### What Was Committed (June 3, 2026)

```
feat(candy-crush): Phase 4-6 completion - Skills, Hooks, Agents systems

Phase 4 (Skills System):
- Skill value object with frontmatter parsing
- SkillLoader and SkillRegistry with priority chain
- 4 built-in skills (php-best-practices, security-audit, phpunit-master, composer-wizard)
- SkillManager integration with App

Phase 5 (Hooks System):
- HookInterface, HookContext, HookResult, HookEvent enum
- HookRegistry with regex-based matching
- 3 built-in hooks (ProtectFilesHook, ConfirmRemoveHook, AuditHook)
- HookConfig YAML loading, ScriptHook, HookManager

Phase 6 (Agents):
- Agent value object with immutable builders
- AgentDefinition with 6 built-in types

Tests: 250+ tests across all new systems
Docs: README.md and CALIBER_LEARNINGS.md updated
```

### How to Resume

1. Read `.candy-crush-plan/supervisor/SUPERVISOR.md` for supervisor instructions
2. Read `.candy-crush-plan/updates.md` for detailed progress notes
3. Read `.candy-crush-plan/steps/6.2_agent_manager.md` for next step
4. Start Supervisor with: Spawn Coder agent using step file path

### Project Structure (Current)

```
candy-crush/
├── src/
│   ├── Agents/           # ✅ Agent.php, AgentDefinition.php
│   ├── App/              # ✅ App.php (skill integration added)
│   ├── Hooks/            # ✅ Full hooks system
│   │   └── BuiltIn/      # ✅ ProtectFilesHook, ConfirmRemoveHook, AuditHook
│   ├── Providers/        # ✅ All 7 providers + ProviderFactory
│   ├── Skills/           # ✅ Full skills system
│   │   └── BuiltIn/      # ✅ 4 built-in skills
│   ├── Tui/              # ✅ Components, Renderer, Pane, Menu
│   ├── Tools/            # ✅ Tool.php, ToolCall.php, ToolResult.php
│   ├── Messages/         # ✅ Messages system
│   └── Config/           # 📋 To be implemented
├── tests/                # ✅ 250+ tests
└── examples/             # 📋 To be implemented
```

Legend: ✅ = Implemented | 📋 = Not yet implemented
