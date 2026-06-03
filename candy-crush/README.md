# CandyCrush

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-crush)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-crush)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-crush?label=packagist)](https://packagist.org/packages/sugarcraft/candy-crush)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

TUI AI coding assistant — multi-provider, multi-agent, skill-aware.

```sh
composer require sugarcraft/candy-crush
```

## Overview

CandyCrush is a terminal UI application for AI-assisted coding. It provides a
batteries-included interface for interacting with multiple AI providers
(OpenAI, SGLANG, Claude Code, etc.) with support for custom skills, hooks,
and MCP tool discovery.

## Status

🟢 Step 3.1 complete — OpenAI Provider implementation.
🟢 Step 3.2 complete — SGLANG Provider implementation.
🟢 Step 3.3 complete — Claude Code Provider implementation.
🟢 Step 3.4 complete — AWS Bedrock Provider implementation.
🟢 Step 3.5 complete — Vertex and Custom Providers implementation.
🟢 Step 3.6 complete — ProviderFactory for configuration-driven provider creation.
🟢 Step 4.1 complete — Skill value object with frontmatter parsing.
🟢 Step 4.2 complete — SkillLoader and SkillRegistry implementation.
🟢 Step 4.3 complete — Built-in Skills implementation.
🟢 Step 4.4 complete — Skill Integration (SkillManager + App methods).
🟢 Step 5.1 complete — Hook Interface and Registry implementation.
🟢 Step 5.2 complete — Built-in Hooks implementation.
🟢 Step 5.3 complete — Hook Configuration (YAML loading, ScriptHook, HookManager).
🟢 Step 6.1 complete — Agent value object and AgentDefinition built-in types.

## Step 6.1: Agent Value Object

Step 6.1 implements the `Agent` value object and `AgentDefinition` factory — the foundational types for representing AI agents in CandyCrush.

### Agent Value Object

`Agent` is an immutable data structure representing a configured AI agent:

```php
final readonly class Agent
{
    public function __construct(
        public string $name,
        public string $description,
        public string $prompt,
        public string $model,
        public string $provider,
        public array $tools,
        public array $skillNames,
        public array $hooks,
        public bool $isActive,
    ) {}
}
```

**Key characteristics:**
- `final readonly class` enforces immutability at the type level
- Constructor property promotion exposes all fields as public
- No setters — all modifications create new instances via `with*()` builders

### fromArray() Deserialization

Create an `Agent` from a configuration array:

```php
$agent = Agent::fromArray([
    'name' => 'my-coder',
    'description' => 'A coding assistant',
    'prompt' => 'You are a helpful coder.',
    'model' => 'claude-sonnet-4-6',
    'provider' => 'anthropic',
    'tools' => ['Read', 'Edit', 'Bash'],
    'skills' => ['php-best-practices'],
    'hooks' => ['safe-bash'],
    'is_active' => true,
]);
```

Missing keys use sensible defaults:
- `model` defaults to `'claude-sonnet-4-6'`
- `provider` defaults to `'anthropic'`
- `tools`, `skills`, `hooks` default to `[]`
- `is_active` defaults to `false`

### toArray() Serialization

Serialize an `Agent` back to an array:

```php
$data = $agent->toArray();
// Returns:
// [
//     'name' => 'my-coder',
//     'description' => 'A coding assistant',
//     'prompt' => 'You are a helpful coder.',
//     'model' => 'claude-sonnet-4-6',
//     'provider' => 'anthropic',
//     'tools' => ['Read', 'Edit', 'Bash'],
//     'skills' => ['php-best-practices'],
//     'hooks' => ['safe-bash'],
//     'is_active' => true,
// ]
```

The array format matches the `fromArray()` input format, enabling round-trip serialization.

### Immutable Builders

Use `with*()` methods to create modified copies without mutating the original:

```php
// Rename an agent
$renamedAgent = $agent->withName('new-name');

// Activate/deactivate an agent
$activeAgent = $agent->withActive(true);
$inactiveAgent = $agent->withActive(false);
```

Each builder returns a **new instance** — the original `Agent` is unchanged. This supports functional update patterns in the TEA architecture.

### systemPrompt() Method

Returns the agent's prompt for use as the system message:

```php
$systemPrompt = $agent->systemPrompt();
```

### AgentDefinition Built-in Types

`AgentDefinition` provides factory methods for six common agent types:

| Type | Description | Default Tools | Default Skills |
|------|-------------|---------------|----------------|
| `coder` | General coding assistant | `Read`, `Edit`, `Bash` | — |
| `reviewer` | Code review specialist | `Read`, `Grep`, `Bash(git:*)` | `php-best-practices`, `security-audit` |
| `debugger` | Bug investigation and fixing | `Read`, `Grep`, `Bash` | — |
| `architect` | System design and architecture | `Read`, `Grep`, `Glob` | — |
| `tester` | Test writing and coverage | `Read`, `Bash` | `phpunit-master` |
| `devops` | CI/CD and deployment | `Read`, `Bash`, `Glob` | — |

### Creating Built-in Agents

```php
use SugarCraft\Crush\Agents\AgentDefinition;

// Create a coder agent with default name
$coder = AgentDefinition::coder();

// Create a reviewer with custom name
$reviewer = AgentDefinition::reviewer('security-reviewer');

// Create a tester agent
$tester = AgentDefinition::tester();
```

### Type Constants

The type is available as a string constant:

```php
AgentDefinition::TYPE_CODER     // 'coder'
AgentDefinition::TYPE_REVIEWER  // 'reviewer'
AgentDefinition::TYPE_DEBUGGER  // 'debugger'
AgentDefinition::TYPE_ARCHITECT // 'architect'
AgentDefinition::TYPE_TESTER    // 'tester'
AgentDefinition::TYPE_DEVOPS     // 'devops'
```

### fromType() Factory

Create an agent definition by type string:

```php
$definition = AgentDefinition::fromType('reviewer', 'my-reviewer');

// Returns null for unknown types
$unknown = AgentDefinition::fromType('invalid', 'name');  // null
```

This enables configuration-driven agent creation from YAML or JSON.

### AgentDefinition Structure

```php
final readonly class AgentDefinition
{
    public function __construct(
        public string $type,
        public string $name,
        public string $description,
        public string $prompt,
        public array $defaultTools,
        public array $defaultSkills,
    ) {}
}
```

### Usage Example

```php
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentDefinition;

// Create a definition for a reviewer agent
$def = AgentDefinition::reviewer('code-reviewer');

// Convert to an actual agent with additional config
$agent = Agent::fromArray([
    'name' => $def->name,
    'description' => $def->description,
    'prompt' => $def->prompt,
    'model' => 'claude-sonnet-4-6',
    'provider' => 'anthropic',
    'tools' => $def->defaultTools,
    'skillNames' => $def->defaultSkills,
    'hooks' => [],
    'is_active' => true,
]);

// Use the agent
$systemPrompt = $agent->systemPrompt();

// Modify the agent (immutable)
$inactiveAgent = $agent->withActive(false);
```

### Architecture

```
Agent (value object)
  ├── name, description, prompt, model, provider
  ├── tools, skillNames, hooks
  ├── isActive
  ├── fromArray() — static factory from config
  ├── toArray() — serialize to config format
  ├── withName() — immutable name change
  ├── withActive() — immutable activation toggle
  └── systemPrompt() — returns prompt string

AgentDefinition (factory)
  ├── TYPE_CODER, TYPE_REVIEWER, ...
  ├── coder(), reviewer(), debugger(), ...
  ├── fromType() — type string to definition
  └── new() — constructor with type/name/description/prompt/tools/skills
```

## Step 5.1: Hook Interface and Registry

Step 5.1 implements the hooks system — a pluggable interception layer that allows code to observe and modify tool execution at `PreToolUse` and `PostToolUse` events. Hooks are matched by regex against the tool name and return an action that controls execution flow.

### HookEvent Enum

Events are represented by a PHP 8.1 backed enum:

```php
enum HookEvent: string
{
    case PreToolUse = 'PreToolUse';
    case PostToolUse = 'PostToolUse';
}
```

| Event | When fired |
|-------|------------|
| `PreToolUse` | Before a tool is executed — can allow, deny, or modify the tool input |
| `PostToolUse` | After a tool executes — can allow or deny based on the tool's output |

### HookInterface Contract

All hooks implement `HookInterface`:

```php
interface HookInterface
{
    public function name(): string;
    public function event(): HookEvent;
    public function matcher(): string;
    public function execute(HookContext $context): HookResult;
}
```

| Method | Purpose |
|--------|---------|
| `name()` | Unique identifier for this hook |
| `event()` | Which event this hook subscribes to (`PreToolUse` or `PostToolUse`) |
| `matcher()` | Regex pattern (PCRE) — the hook runs when this matches the tool name |
| `execute(HookContext)` | Performs the hook logic and returns a `HookResult` |

### HookContext

`HookContext` is an immutable data bag passed to every hook:

```php
final readonly class HookContext
{
    public function __construct(
        public string $sessionId,
        public string $toolName,
        public array $toolArgs,
        public string $toolInput,
        public string $toolOutput,
        public string $model,
        public string $provider,
        public string $projectRoot,
    ) {}
}
```

Immutable builders allow hooks to propagate modifications downstream:

```php
// Modify tool input (used when a hook returns HookResult::modify())
$newContext = $context->withToolInput($modifiedInput);

// Modify tool output (used in PostToolUse to record or transform output)
$newContext = $context->withToolOutput($modifiedOutput);
```

### HookResult Actions

`HookResult` communicates the hook's decision:

```php
final readonly class HookResult
{
    public const ALLOW = 'allow';
    public const DENY = 'deny';
    public const MODIFY = 'modify';

    // Factory methods
    public static function allow(string $message = ''): self;
    public static function deny(string $message): self;
    public static function modify(string $newInput, string $message = ''): self;

    // Predicates
    public function isAllowed(): bool;
    public function isDenied(): bool;
    public function isModified(): bool;
}
```

| Action | Meaning | Execution continues? |
|--------|---------|---------------------|
| `ALLOW` | Tool may proceed | Yes — to next matching hook |
| `DENY` | Tool is blocked | No — chain stops, returns DENY immediately |
| `MODIFY` | Tool proceeds with modified input | Yes — context updated, then next hook |

### HookRegistry

`HookRegistry` manages hook registration, lookup, and execution:

```php
final class HookRegistry
{
    public function register(HookInterface $hook): void;
    public function unregister(string $name): void;
    public function get(string $event, string $name): ?HookInterface;
    public function getForEvent(string $event): array;
    public function disable(string $name): void;
    public function enable(string $name): void;
    public function isDisabled(string $name): bool;

    /** @return array<HookInterface> */
    public function findMatches(string $event, string $toolName): array;

    public function executeHooks(string $event, HookContext $context): HookResult;
}
```

### Hook Chain Execution

`executeHooks()` runs all matching hooks in sequence. The chain's behavior depends on the first non-ALLOW result:

```php
public function executeHooks(string $event, HookContext $context): HookResult
{
    $matches = $this->findMatches($event, $context->toolName);
    $firstModifyResult = null;

    foreach ($matches as $hook) {
        $result = $hook->execute($context);

        if ($result->isDenied()) {
            return $result;  // DENY stops the chain immediately
        }

        if ($result->isModified()) {
            // MODIFY updates context and continues
            $context = $context->withToolInput($result->modifiedInput);
            $firstModifyResult ??= $result;  // track first MODIFY
        }
    }

    // If any MODIFY occurred, return the first one; otherwise ALLOW
    return $firstModifyResult ?? HookResult::allow();
}
```

**Chain semantics:**
- **DENY** always stops execution immediately and returns DENY
- **MODIFY** updates the context and continues to the next hook; the first MODIFY result is returned if no DENY occurs
- **ALLOW** continues to the next hook; if all hooks return ALLOW, the final result is ALLOW

### Regex-Based Hook Matching

`findMatches()` uses `preg_match()` with the hook's `matcher()` pattern:

```php
if (preg_match('/' . $hook->matcher() . '/i', $toolName)) {
    $matches[] = $hook;
}
```

The regex is case-insensitive (`/i` flag). Patterns match anywhere in the tool name (implicit `.*` anchors on both sides).

**Example matchers:**

```php
// Match any Bash tool call
'matcher()' => 'Bash'

// Match all Read/Edit/Grep tools
'matcher()' => 'Read|Edit|Grep'

// Match all file-related tools
'matcher()' => 'File|Read|Edit|Write'

// Match any tool name starting with a capital letter followed by lowercase
'matcher()' => '[A-Z][a-z]+'
```

### Example Hook Implementation

A hook that blocks `Bash` tool calls containing `rm -rf`:

```php
final readonly class SafeBashHook implements HookInterface
{
    public function name(): string
    {
        return 'safe-bash';
    }

    public function event(): HookEvent
    {
        return HookEvent::PreToolUse;
    }

    public function matcher(): string
    {
        return 'Bash';
    }

    public function execute(HookContext $context): HookResult
    {
        if (str_contains($context->toolInput, 'rm -rf') ||
            str_contains($context->toolInput, 'rm -rf /')) {
            return HookResult::deny('rm -rf is not allowed');
        }

        if (str_contains($context->toolInput, 'sudo')) {
            return HookResult::modify(
                str_replace('sudo', '', $context->toolInput),
                'sudo removed from command'
            );
        }

        return HookResult::allow();
    }
}
```

### Usage Example

```php
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;

// Create registry
$registry = new HookRegistry();

// Register hooks
$registry->register(new SafeBashHook());
$registry->register(new ReadOnlyHook());

// Build context for PreToolUse event
$context = new HookContext(
    sessionId: $app->sessionId,
    toolName: 'Bash',
    toolArgs: ['command' => 'rm -rf /tmp/cache'],
    toolInput: 'rm -rf /tmp/cache',
    toolOutput: '',
    model: $app->model,
    provider: $app->provider->name(),
    projectRoot: '/path/to/project',
);

// Execute the hook chain
$result = $registry->executeHooks('PreToolUse', $context);

if ($result->isDenied()) {
    echo "Blocked: " . $result->message;
} elseif ($result->isModified()) {
    echo "Modified: " . $result->message;
    // Use $context->toolInput which now has the sanitized value
} else {
    // Proceed with tool execution
}
```

### Architecture

```
HookRegistry
  ├── hooks['PreToolUse']: HookInterface[]
  ├── hooks['PostToolUse']: HookInterface[]
  └── disabled: array<string, bool>

HookInterface (contract)
  ├── name(): string
  ├── event(): HookEvent
  ├── matcher(): string
  └── execute(HookContext): HookResult

HookContext (immutable)
  ├── toolName, toolArgs, toolInput, toolOutput
  ├── sessionId, model, provider, projectRoot
  └── withToolInput(), withToolOutput()

HookResult (immutable)
  ├── ALLOW | DENY | MODIFY
  └── message, modifiedInput
```

### Interaction with TEA Model

Hooks integrate with the `App` state via `activeHooks`:

```php
final readonly class App
{
    public function __construct(
        // ...
        public readonly array $activeHooks,  // HookInterface[]
        // ...
    ) {}
}
```

When the runtime is about to execute a tool, it calls `executeHooks()` with the appropriate `HookContext`. The result controls whether the tool call proceeds and what input it receives.

## Step 5.2: Built-in Hooks

Step 5.2 provides three built-in hook implementations that ship with CandyCrush for common safety and audit scenarios.

### Built-in Hooks Overview

| Hook | Event | Matcher | Purpose |
|------|-------|---------|---------|
| `ProtectFilesHook` | `PreToolUse` | `^(bash\|Edit)$` | Prevents modification of sensitive files |
| `ConfirmRemoveHook` | `PreToolUse` | `^rm$` | Prevents recursive/force `rm` commands |
| `AuditHook` | `PostToolUse` | `.*` | Logs all tool executions to a file |

### ProtectFilesHook

`ProtectFilesHook` prevents modification of files that match protected patterns:

```php
final readonly class ProtectFilesHook implements HookInterface
{
    private const PROTECTED_PATTERNS = [
        '/\.env$/',
        '/composer\.json$/',
        '/composer\.lock$/',
        '/\.git\/config$/',
        '/config\/.*\.php$/',
    ];

    public function name(): string
    {
        return 'protect-files';
    }

    public function event(): HookEvent
    {
        return HookEvent::PreToolUse;
    }

    public function matcher(): string
    {
        return '^(bash|Edit)$';
    }

    public function execute(HookContext $context): HookResult
    {
        $input = $context->toolInput;

        foreach (self::PROTECTED_PATTERNS as $pattern) {
            if (preg_match($pattern, $input)) {
                return HookResult::deny(
                    "This hook prevents modification of protected files matching the given pattern"
                );
            }
        }

        return HookResult::allow();
    }
}
```

**Protected patterns:**
- `\.env$` — Environment files containing secrets
- `composer\.json$` / `composer\.lock$` — Composer dependencies
- `\.git\/config$` — Git repository configuration
- `config\/.*\.php$` — PHP configuration files

The hook uses `preg_match()` to test each pattern against the tool input. If any pattern matches, the operation is denied with a message indicating which pattern was matched.

### ConfirmRemoveHook

`ConfirmRemoveHook` prevents dangerous recursive or force remove operations:

```php
final readonly class ConfirmRemoveHook implements HookInterface
{
    public function name(): string
    {
        return 'confirm-rm';
    }

    public function event(): HookEvent
    {
        return HookEvent::PreToolUse;
    }

    public function matcher(): string
    {
        return '^rm$';
    }

    public function execute(HookContext $context): HookResult
    {
        $input = $context->toolInput;

        // Check for recursive or force remove
        if (preg_match('/rm\s+-[rfRFs]+\s+/i', $input)) {
            return HookResult::deny(
                'This hook prevents recursive/force rm. Use interactive rm instead.'
            );
        }

        return HookResult::allow();
    }
}
```

**Detection logic:**
- Matches tool name `rm` exactly (case-insensitive via `/i` flag in `findMatches()`)
- Checks tool input for `-r`, `-f`, `-R`, `-F` flags in any combination
- Pattern `/rm\s+-[rfRFs]+\s+/i` catches `rm -rf`, `rm -r`, `rm -f /path`, etc.

**Why prevent recursive removes?** Accidental `rm -rf` can delete entire directory trees. The hook requires users to remove files individually or use interactive deletion tools.

### AuditHook

`AuditHook` logs all tool executions for debugging and compliance:

```php
final readonly class AuditHook implements HookInterface
{
    private string $logFile;

    public function __construct(?string $logFile = null)
    {
        $this->logFile = $logFile ?? sys_get_temp_dir() . '/candy-crush-audit.log';
    }

    public function name(): string
    {
        return 'audit';
    }

    public function event(): HookEvent
    {
        return HookEvent::PostToolUse;
    }

    public function matcher(): string
    {
        return '.*';
    }

    public function execute(HookContext $context): HookResult
    {
        $entry = sprintf(
            "[%s] %s %s %s => %s\n",
            date('Y-m-d H:i:s'),
            $context->sessionId,
            $context->toolName,
            $context->toolInput,
            substr($context->toolOutput, 0, 200)
        );

        file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);

        return HookResult::allow();
    }
}
```

**Design decisions:**
- `PostToolUse` event captures both input and output
- Logs to `sys_get_temp_dir() . '/candy-crush-audit.log'` by default
- Output is truncated to 200 characters to prevent huge log entries
- `FILE_APPEND | LOCK_EX` ensures atomic writes in concurrent scenarios
- Constructor accepts optional `$logFile` for testability

**Log entry format:**
```
[2026-06-03 14:32:01] abc123 Bash rm -rf /tmp/cache => File deleted successfully
```

### Registering Built-in Hooks

Register built-in hooks with `HookRegistry`:

```php
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook;
use SugarCraft\Crush\Hooks\BuiltIn\AuditHook;

$registry = new HookRegistry();
$registry->register(new ProtectFilesHook());
$registry->register(new ConfirmRemoveHook());
$registry->register(new AuditHook('/var/log/candy-crush/audit.log'));
```

**Registration order matters:** Hooks execute in registration order. For deny-first semantics, register `ProtectFilesHook` and `ConfirmRemoveHook` before hooks that might modify input.

**Selective enabling:** Use `disable()` to temporarily pause a hook without unregistering:

```php
$registry->disable('audit');  // Pause audit logging
$registry->enable('audit');    // Resume audit logging
```

**Auto-registration consideration:** The `HookRegistry` does not auto-register built-in hooks. Applications that want built-in hooks enabled by default should create a `registerBuiltInHooks()` convenience method:

```php
public function registerBuiltInHooks(HookRegistry $registry): void
{
    $registry->register(new ProtectFilesHook());
    $registry->register(new ConfirmRemoveHook());
    $registry->register(new AuditHook());
}
```

## Step 5.3: Hook Configuration

Step 5.3 adds YAML-based hook configuration loading, external script execution support, and a centralized `HookManager` for lifecycle management.

### HookConfig

`HookConfig` loads and parses YAML hook configuration files:

```php
final class HookConfig
{
    /**
     * @return array<array{event: string, matcher: string, command: string, description: string}>
     */
    public static function loadFromFile(string $path): array;

    /**
     * @return array<array{event: string, matcher: string, command: string, description: string}>
     */
    public static function parse(string $content): array;
}
```

**File loading behavior:**
- `loadFromFile()` returns `[]` if the file does not exist or cannot be read
- `parse()` catches YAML parse exceptions and returns `[]` on failure

**Parsed structure:** Each hook entry contains `event`, `matcher`, `command`, and `description` keys with sensible defaults (`matcher: '.*'`, `event: 'PreToolUse'`).

### ScriptHook

`ScriptHook` executes external scripts as hooks via `proc_open()`:

```php
final readonly class ScriptHook implements HookInterface
{
    public function __construct(
        private string $name,
        private HookEvent $event,
        private string $matcher,
        private string $command,
        private string $description,
    ) {}

    public static function fromConfig(array $config): self;

    public function execute(HookContext $context): HookResult;
}
```

**Environment variables passed to script hooks:**

| Variable | Source | Description |
|----------|--------|-------------|
| `CRUSH_SESSION_ID` | `$context->sessionId` | Current session identifier |
| `CRUSH_TOOL_NAME` | `$context->toolName` | Name of the tool being executed |
| `CRUSH_TOOL_INPUT` | `$context->toolInput` | Tool input/arguments |
| `CRUSH_TOOL_OUTPUT` | `$context->toolOutput` | Tool output (PostToolUse only) |
| `CRUSH_MODEL` | `$context->model` | Current model name |
| `CRUSH_PROVIDER` | `$context->provider` | Current provider name |

**Execution behavior:**
- Exit code 0 → `HookResult::allow()` with trimmed stdout as message
- Non-zero exit → `HookResult::deny()` with trimmed stderr or `"Hook exited with code X"`
- `proc_open` failure → `HookResult::allow()` (fail open)

### HookManager

`HookManager` orchestrates hook loading and lifecycle:

```php
final class HookManager
{
    public function __construct(
        private HookRegistry $registry,
    ) {}

    public function loadFromFile(string $path): void;
    public function registerBuiltIns(): void;
    public function preToolUse(HookContext $context): HookResult;
    public function postToolUse(HookContext $context): HookResult;

    public function applyPreHooks(
        string $toolName,
        string $input,
        HookContext $baseContext,
    ): HookResult;
}
```

**Composition pattern:** `HookManager` delegates to `HookRegistry` rather than duplicating registration logic. It provides convenience methods that construct the appropriate context and invoke the registry.

**Built-in hooks auto-registration:** `registerBuiltIns()` registers three safety hooks:
- `ProtectFilesHook` — prevents modification of sensitive files
- `ConfirmRemoveHook` — blocks recursive/force `rm` commands
- `AuditHook` — logs all tool executions

### Example hooks.yaml Configuration

```yaml
hooks:
  PreToolUse:
    - matcher: '^Bash$'
      command: './hooks/validate-bash.sh'
      description: 'Validate bash commands before execution'

    - matcher: '^(Edit|Write)$'
      command: './hooks/check-git-status.sh'
      description: 'Check git status before file modifications'

  PostToolUse:
    - matcher: '.*'
      command: './hooks/audit-tool.sh'
      description: 'Audit all tool executions'
```

**Configuration structure:**
- Top-level `hooks:` key contains event names as keys
- Each event maps to a list of hook configurations
- `matcher:` is a PCRE regex (case-insensitive, implicitly anchored)
- `command:` is the shell command to execute
- `description:` is optional documentation

### Usage Example

```php
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookRegistry;

// Create manager with registry
$registry = new HookRegistry();
$manager = new HookManager($registry);

// Load hooks from YAML
$manager->loadFromFile('/path/to/hooks.yaml');

// Register built-in safety hooks
$manager->registerBuiltIns();

// Create context for pre-tool execution
$context = new HookContext(
    sessionId: $app->sessionId,
    toolName: 'Bash',
    toolArgs: ['command' => 'rm -rf /tmp/cache'],
    toolInput: 'rm -rf /tmp/cache',
    toolOutput: '',
    model: $app->model,
    provider: $app->provider->name(),
    projectRoot: '/path/to/project',
);

// Execute pre-tool hooks
$result = $manager->preToolUse($context);

if ($result->isDenied()) {
    echo "Blocked: " . $result->message;
}
```

### Architecture

```
HookManager
  ├── HookRegistry (delegation target)
  ├── loadFromFile() → HookConfig::loadFromFile() → ScriptHook::fromConfig()
  └── registerBuiltIns() → BuiltIn\*Hook instances

HookConfig
  ├── loadFromFile(path) → file_get_contents + parse
  └── parse(yaml) → Symfony\Component\Yaml\Yaml::parse()

ScriptHook
  ├── fromConfig(config) → factory method
  └── execute(context) → proc_open + environment variables
```

## Step 3.1: OpenAI Provider

Step 3.1 implements the `OpenAIProvider` — a `ProviderInterface` implementation that translates CandyCrush requests into OpenAI Chat Completions API calls.

### OpenAIProvider Class

```php
final readonly class OpenAIProvider implements ProviderInterface
{
    public function __construct(
        private Client $client,
        private string $defaultModel = 'gpt-4o',
    ) {}
}
```

The provider requires an OpenAI `Client` instance (from the `openai-php/client` package) and optionally accepts a default model name.

### Provider Interface Implementation

The `OpenAIProvider` implements all `ProviderInterface` methods:

| Method | Implementation | Notes |
|--------|----------------|-------|
| `name()` | Returns `'openai'` | Provider identifier |
| `supportsStreaming()` | Returns `true` | OpenAI supports streaming |
| `supportsFunctionCalling()` | Returns `true` | OpenAI supports tool calls |
| `supportsVision()` | Returns `true` | GPT-4o supports image input |
| `supportsJsonSchema()` | Returns `false` | OpenAI uses `additionalKwargs` |
| `contextWindow()` | Varies by model | 128k for GPT-4o, 8k for GPT-4, etc. |
| `costPer1kTokens()` | Model-based pricing | Input vs output differentiation |

### Context Window by Model

```php
public function contextWindow(): int
{
    return match ($this->defaultModel) {
        'gpt-4o' => 128_000,
        'gpt-4-turbo' => 128_000,
        'gpt-4' => 8_192,
        'gpt-3.5-turbo' => 16_385,
        default => 8_192,
    };
}
```

### Pricing by Model

```php
public function costPer1kTokens(string $model, string $direction): float
{
    return match ($model) {
        'gpt-4o' => $direction === 'input' ? 0.005 : 0.015,
        'gpt-4-turbo' => $direction === 'input' ? 0.01 : 0.03,
        'gpt-4' => $direction === 'input' ? 0.03 : 0.06,
        'gpt-3.5-turbo' => $direction === 'input' ? 0.0005 : 0.0015,
        default => 0.01,
    };
}
```

### complete() Method

The `complete()` method handles synchronous chat completions:

```php
public function complete(CompleteRequest $request): CompleteResponse
{
    $params = [
        'model' => $request->model,
        'messages' => $this->formatMessages($request->messages),
        'temperature' => $request->temperature ?? 0.7,
        'max_tokens' => $request->maxTokens ?? 4096,
    ];

    if ($request->tools !== null) {
        $params['tools'] = $this->formatTools($request->tools);
    }

    if ($request->systemPrompt !== null) {
        $params['messages'] = array_merge(
            [['role' => 'system', 'content' => $request->systemPrompt]],
            $params['messages']
        );
    }

    $response = $this->client->chat()->create($params);

    return $this->parseResponse($response);
}
```

**Key behaviors:**
- System prompts are prepended to the messages array
- Tools are formatted as OpenAI function calling schema
- Temperature defaults to 0.7 when not specified
- Max tokens default to 4096 when not specified

### completeStream() Method

The `completeStream()` method handles streaming responses using a generator:

```php
public function completeStream(CompleteRequest $request): \Generator
{
    $params = [
        'model' => $request->model,
        'messages' => $this->formatMessages($request->messages),
        'temperature' => $request->temperature ?? 0.7,
        'max_tokens' => $request->maxTokens ?? 4096,
        'stream' => true,
    ];

    if ($request->tools !== null) {
        $params['tools'] = $this->formatTools($request->tools);
    }

    $stream = $this->client->chat()->createStreamed($params);

    foreach ($stream as $chunk) {
        yield $this->parseChunk($chunk);
    }
}
```

**Streaming design notes:**
- Returns a `\Generator` that yields `CompleteResponse` objects per chunk
- Each chunk contains only the delta content from that iteration
- `tokensUsed` and `costUsd` are always 0 for streaming chunks (usage data only available at stream completion)
- Caller is responsible for accumulating content across chunks

### Message Formatting

The `formatMessages()` method translates CandyCrush `Message` objects to OpenAI format:

```php
private function formatMessages(array $messages): array
{
    return array_map(function (Message $msg) {
        return match (true) {
            $msg instanceof UserMessage => ['role' => 'user', 'content' => $msg->content()],
            $msg instanceof AssistantMessage => array_filter([
                'role' => 'assistant',
                'content' => $msg->content(),
                'tool_calls' => $msg->toolCalls(),
            ]),
            $msg instanceof SystemMessage => ['role' => 'system', 'content' => $msg->content()],
            $msg instanceof ToolResultMessage => [
                'role' => 'tool',
                'tool_call_id' => $msg->toolCallId(),
                'content' => $msg->content(),
            ],
            default => ['role' => 'user', 'content' => $msg->content()],
        };
    }, $messages);
}
```

**Key translation rules:**
- `UserMessage` → `{role: 'user', content: ...}`
- `AssistantMessage` → `{role: 'assistant', content: ..., tool_calls: [...]}` (filtered to remove nulls)
- `SystemMessage` → `{role: 'system', content: ...}`
- `ToolResultMessage` → `{role: 'tool', tool_call_id: ..., content: ...}`

### Tool Formatting

The `formatTools()` method translates CandyCrush `Tool` objects to OpenAI function schema:

```php
private function formatTools(array $tools): array
{
    return array_map(function (Tool $tool) {
        return [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->inputSchema(),
            ],
        ];
    }, $tools);
}
```

### Response Parsing

The `parseResponse()` method extracts data from the OpenAI response:

```php
private function parseResponse(CompletionResponse $response): CompleteResponse
{
    $choices = $response->toArray()['choices'][0] ?? [];
    $message = $choices['message'] ?? [];

    $toolCalls = null;
    if (isset($message['tool_calls'])) {
        $toolCalls = array_map(
            fn($tc) => ToolCall::fromArray([
                'id' => $tc['id'],
                'name' => $tc['function']['name'],
                'arguments' => json_decode($tc['function']['arguments'], true) ?? [],
            ]),
            $message['tool_calls']
        );
    }

    return new CompleteResponse(
        content: $message['content'] ?? '',
        reasoning: null,
        toolCalls: $toolCalls,
        tokensUsed: $response->usage['total_tokens'] ?? 0,
        costUsd: $this->calculateCost($response),
    );
}
```

### Streaming Chunk Parsing

The `parseChunk()` method handles individual streaming chunks:

```php
private function parseChunk(mixed $chunk): CompleteResponse
{
    $delta = $chunk->toArray()['choices'][0]['delta'] ?? [];

    return new CompleteResponse(
        content: $delta['content'] ?? '',
        reasoning: null,
        toolCalls: null,
        tokensUsed: 0,
        costUsd: 0.0,
    );
}
```

**Important**: Streaming chunks return empty `toolCalls`, `tokensUsed`, and `costUsd` because:
- Tool calls in streams arrive incrementally and would require assembly across chunks
- Usage statistics are only available in the final chunk
- Cost calculation requires usage data unavailable per-chunk

### embeddings() Method

```php
public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
{
    $response = $this->client->embeddings()->create([
        'model' => $request->model,
        'input' => $request->input,
    ]);

    return new EmbeddingsResponse(
        embeddings: array_map(
            fn($item) => $item['embedding'],
            $response->toArray()['data'] ?? []
        )
    );
}
```

### Usage Example

```php
use OpenAI\Client;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Messages\UserMessage;

// Create provider
$client = Client::factory(['api_key' => 'sk-...']);
$provider = new OpenAIProvider($client, 'gpt-4o');

// Make a completion request
$request = new CompleteRequest(
    model: 'gpt-4o',
    messages: [new UserMessage('Hello, world!')],
    temperature: 0.7,
);

$response = $provider->complete($request);
echo $response->content;  // AI response
echo $response->tokensUsed;  // Token count
echo $response->costUsd;  // Calculated cost
```

### Provider Selection in App

The `App` state holds a `ProviderInterface`, allowing runtime provider swapping:

```php
final readonly class App
{
    public function __construct(
        public readonly ProviderInterface $provider,
        // ...
    ) {}
}
```

This design enables:
- Single `App` class works with any provider implementation
- Providers are injected at construction time
- Switching providers creates a new `App` instance via `withProvider()`

## Step 3.2: SGLANG Provider

Step 3.2 implements the `SglangProvider` — a `ProviderInterface` implementation that connects to SGLANG endpoints using the OpenAI-compatible API format. SGLANG is a fast serving framework for large language models that exposes an OpenAI-compatible REST API.

### SglangProvider Class

```php
final readonly class SglangProvider implements ProviderInterface
{
    public function __construct(
        private string $baseUrl,
        private string $model,
        private ?string $apiKey,
        private Client $httpClient,
    ) {}

    public static function openAiCompatible(
        string $baseUrl,
        string $model = 'MiniMax-M2.7',
        ?string $apiKey = null,
    ): self {
        // Factory method for OpenAI-compatible endpoints
    }
}
```

The provider requires a base URL pointing to a SGLANG server, a model name, and optionally an API key for authentication.

### Factory Method: openAiCompatible()

```php
public static function openAiCompatible(
    string $baseUrl,
    string $model = 'MiniMax-M2.7',
    ?string $apiKey = null,
): self {
    $headers = [
        'Content-Type' => 'application/json',
    ];

    if ($apiKey !== null) {
        $headers['Authorization'] = 'Bearer ' . $apiKey;
    }

    $client = new Client([
        'base_uri' => $baseUrl,
        'headers' => $headers,
    ]);

    return new self($baseUrl, $model, $apiKey, $client);
}
```

**Key design decisions:**
- Uses `GuzzleHttp\Client` directly rather than the OpenAI SDK, since SGLANG is OpenAI-compatible but not OpenAI-specific
- Default model is `MiniMax-M2.7` (the model's name in SGLANG's configuration)
- API key is optional — SGLANG servers may not require authentication
- `base_uri` ensures all requests are relative to the SGLANG endpoint

### Provider Interface Implementation

| Method | Implementation | Notes |
|--------|----------------|-------|
| `name()` | Returns `'sglang'` | Provider identifier |
| `supportsStreaming()` | Returns `true` | SGLANG supports streaming |
| `supportsFunctionCalling()` | Returns `true` | SGLANG supports tool calls |
| `supportsVision()` | Returns `false` | SGLANG text models only |
| `supportsJsonSchema()` | Returns `false` | SGLANG uses additional_kwargs |
| `contextWindow()` | Returns `128_000` | Varies by model |
| `costPer1kTokens()` | Returns `0.0` | Self-hosted, no cost |

**Self-hosted rationale**: SGLANG is typically deployed for self-hosted models where cost tracking is not applicable. The zero cost is a placeholder that consumers can override if needed.

### complete() Method

The `complete()` method handles synchronous chat completions:

```php
public function complete(CompleteRequest $request): CompleteResponse
{
    $params = [
        'model' => $request->model,
        'messages' => $this->formatMessages($request->messages),
        'temperature' => $request->temperature ?? 0.7,
        'max_tokens' => $request->maxTokens ?? 4096,
    ];

    if ($request->tools !== null) {
        $params['tools'] = $this->formatTools($request->tools);
    }

    try {
        $response = $this->httpClient->post('/chat/completions', [
            'json' => $params,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return $this->parseResponse($data);
    } catch (GuzzleException $e) {
        throw new \RuntimeException('SGLANG request failed: ' . $e->getMessage(), 0, $e);
    }
}
```

**Key behaviors:**
- Posts to `/chat/completions` endpoint (OpenAI-compatible)
- Formats messages and tools identically to OpenAIProvider
- Uses Guzzle's `json` option for automatic JSON encoding
- Wraps `GuzzleException` in `RuntimeException` for provider-agnostic error handling

### completeStream() Method

The `completeStream()` method handles streaming responses using a generator:

```php
public function completeStream(CompleteRequest $request): \Generator
{
    $params = [
        'model' => $request->model,
        'messages' => $this->formatMessages($request->messages),
        'temperature' => $request->temperature ?? 0.7,
        'max_tokens' => $request->maxTokens ?? 4096,
        'stream' => true,
    ];

    if ($request->tools !== null) {
        $params['tools'] = $this->formatTools($request->tools);
    }

    try {
        $response = $this->httpClient->post('/chat/completions', [
            'json' => $params,
            'stream' => true,
        ]);

        $stream = $response->getBody();

        while (!$stream->eof()) {
            $line = $stream->readLine();
            if (str_starts_with($line, 'data: ')) {
                $data = json_decode(substr($line, 6), true);
                if ($data !== null && isset($data['choices'][0]['delta'])) {
                    yield $this->parseChunk($data);
                }
            }
        }
    } catch (GuzzleException $e) {
        throw new \RuntimeException('SGLANG request failed: ' . $e->getMessage(), 0, $e);
    }
}
```

**SSE line parsing**: SGLANG uses Server-Sent Events (SSE) format where each line starts with `data: `. The code:
1. Reads lines from the stream via `readLine()`
2. Filters to lines starting with `data: `
3. Strips the prefix and JSON-decodes the payload
4. Yields only chunks with `choices[0].delta` content

**Why manual parsing?** Unlike the OpenAI SDK's `createStreamed()` which returns an iterator of parsed chunks, raw Guzzle requires manual SSE parsing. This gives full control over the streaming protocol.

### embeddings() Method

```php
public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
{
    try {
        $response = $this->httpClient->post('/embeddings', [
            'json' => [
                'model' => $request->model,
                'input' => $request->input,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return new EmbeddingsResponse(
            embeddings: array_map(
                fn($item) => $item['embedding'],
                $data['data'] ?? []
            )
        );
    } catch (GuzzleException $e) {
        return new EmbeddingsResponse(embeddings: []);
    }
}
```

**Design note**: Embeddings failures return an empty array rather than throwing. This differs from `complete()` which throws on failure. Embeddings are optional functionality — empty results are preferable to exceptions.

### Message Formatting

The `formatMessages()` method uses the same translation logic as OpenAIProvider:

```php
private function formatMessages(array $messages): array
{
    return array_map(function (Message $msg) {
        return match (true) {
            $msg instanceof UserMessage => ['role' => 'user', 'content' => $msg->content()],
            $msg instanceof AssistantMessage => array_filter([
                'role' => 'assistant',
                'content' => $msg->content(),
                'tool_calls' => $msg->toolCalls(),
            ]),
            $msg instanceof SystemMessage => ['role' => 'system', 'content' => $msg->content()],
            $msg instanceof ToolResultMessage => [
                'role' => 'tool',
                'tool_call_id' => $msg->toolCallId(),
                'content' => $msg->content(),
            ],
            default => ['role' => 'user', 'content' => $msg->content()],
        };
    }, $messages);
}
```

### Response Parsing

The `parseResponse()` method extracts data from SGLANG's JSON response:

```php
private function parseResponse(array $data): CompleteResponse
{
    $choice = $data['choices'][0] ?? [];
    $message = $choice['message'] ?? [];

    $toolCalls = null;
    if (isset($message['tool_calls'])) {
        $toolCalls = array_map(
            fn($tc) => ToolCall::fromArray([
                'id' => $tc['id'],
                'name' => $tc['function']['name'],
                'arguments' => is_string($tc['function']['arguments'] ?? '')
                    ? json_decode($tc['function']['arguments'], true) ?? []
                    : ($tc['function']['arguments'] ?? []),
            ]),
            $message['tool_calls']
        );
    }

    return new CompleteResponse(
        content: $message['content'] ?? '',
        reasoning: null,
        toolCalls: $toolCalls,
        tokensUsed: $data['usage']['total_tokens'] ?? 0,
        costUsd: 0.0,
    );
}
```

**Tool call argument handling**: SGLANG may return `arguments` as either a JSON string or already-decoded array. The code handles both cases:

```php
arguments: is_string($tc['function']['arguments'] ?? '')
    ? json_decode($tc['function']['arguments'], true) ?? []
    : ($tc['function']['arguments'] ?? [])
```

### Usage Example

```php
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Messages\UserMessage;

// Create provider pointing to local SGLANG server
$provider = SglangProvider::openAiCompatible(
    baseUrl: 'http://localhost:30000',
    model: 'MiniMax-M2.7',
    apiKey: null,  // No auth for local dev
);

// Make a completion request
$request = new CompleteRequest(
    model: 'MiniMin-M2.7',
    messages: [new UserMessage('Hello, world!')],
    temperature: 0.7,
);

$response = $provider->complete($request);
echo $response->content;  // AI response
```

### SGLANG Server Requirements

SGLANG must be running with OpenAI-compatible endpoints enabled:

```bash
python -m sglang.launch_server \
    --model-path <model> \
    --port 30000 \
    --chat-template chatml
```

The server exposes:
- `POST /chat/completions` — Chat completions (synchronous and streaming)
- `POST /embeddings` — Text embeddings

### Why OpenAI-Compatible?

SGLANG's API is designed to be OpenAI-compatible, meaning:
- Same request/response format as OpenAI Chat Completions API
- Same tool calling schema
- Same streaming format (SSE with `data: ` prefix)

This compatibility allows CandyCrush to support SGLANG with minimal provider-specific code — the message formatting and tool formatting logic is identical to OpenAIProvider.

## Step 3.3: Claude Code Provider

Step 3.3 implements the `ClaudeCodeProvider` — a `ProviderInterface` implementation that wraps the Claude Code CLI (`claude`) as a local AI backend. Unlike the OpenAI and SGLANG providers that make HTTP requests, the Claude Code provider spawns a subprocess and communicates via stdin/stdout.

### ClaudeCodeInvocation Class

The `ClaudeCodeInvocation` class encapsulates the subprocess lifecycle:

```php
final readonly class ClaudeCodeInvocation
{
    public function __construct(
        private string $claudePath = 'claude',
        private string $configDir = '~/.claude',
        private ?string $sessionId = null,
    ) {}
}
```

**Key design decisions:**
- `claudePath` defaults to `'claude'` expecting the CLI to be in PATH
- `configDir` allows specifying a custom Claude configuration directory
- `sessionId` enables session resumption via `--resume` flag

### baseArgs() Method

Builds the base arguments for all Claude Code invocations:

```php
public function baseArgs(): array
{
    $args = ['--output-format', 'json'];

    if ($this->sessionId !== null) {
        $args[] = '--resume';
        $args[] = $this->sessionId;
    }

    return $args;
}
```

**Key behaviors:**
- `--output-format json` is always included for machine-readable output
- `--resume` with session ID enables continuing a prior conversation

### printModeArgs() Method

Builds arguments for headless print mode (`-p`):

```php
public function printModeArgs(string $prompt, array $options = []): array
{
    $args = ['-p', $prompt];
    $args[] = '--output-format';
    $args[] = $options['format'] ?? 'json';

    if ($options['bare'] ?? false) {
        $args[] = '--bare';
    }

    if ($options['allowedTools'] !== null) {
        $args[] = '--allowedTools';
        $args[] = $options['allowedTools'];
    }

    if ($options['systemPrompt'] !== null) {
        $args[] = '--system-prompt';
        $args[] = $options['systemPrompt'];
    }

    // ... additional options: maxBudgetUsd, maxTurns, permissionMode
    return $args;
}
```

**Options supported:**
- `format` — Output format (`json` or `stream-json` for streaming)
- `bare` — Excludes decorative elements from output
- `allowedTools` — Comma-separated list of permitted tools
- `systemPrompt` — System prompt to inject
- `maxBudgetUsd` — Spending limit
- `maxTurns` — Maximum conversation turns
- `permissionMode` — Permission handling mode (`auto`, `bypassPermissions`)
- `continue` — Continue the last agent message

### execute() Method

Spawns the Claude Code process and returns output:

```php
public function execute(array $args, ?callable $onChunk = null): string
{
    $cmd = array_merge([$this->claudePath], $this->baseArgs(), $args);

    $process = proc_open(
        $cmd,
        [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ],
        $pipes,
        null,
        [
            'ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY') ?: '',
            'ANTHROPIC_AUTH_TOKEN' => getenv('ANTHROPIC_AUTH_TOKEN') ?: '',
            'ANTHROPIC_BASE_URL' => getenv('ANTHROPIC_BASE_URL') ?: '',
        ]
    );

    // ... stream handling
}
```

**Process management:**
- Uses `proc_open()` for full control over stdin/stdout/stderr
- Passes Anthropic environment variables to the subprocess
- Supports optional streaming callback for real-time output processing
- Handles exit codes 0 and -1 as success (graceful termination)

### ClaudeCodeProvider Class

```php
final readonly class ClaudeCodeProvider implements ProviderInterface
{
    public function __construct(
        private ClaudeCodeInvocation $invocation,
        private string $defaultModel = 'claude-sonnet-4-6',
    ) {}
}
```

The provider delegates subprocess management to `ClaudeCodeInvocation` and handles response parsing.

### Provider Interface Implementation

| Method | Implementation | Notes |
|--------|----------------|-------|
| `name()` | Returns `'claude-code'` | Provider identifier |
| `supportsStreaming()` | Returns `true` | Claude Code supports streaming |
| `supportsFunctionCalling()` | Returns `true` | Claude Code supports tool calls |
| `supportsVision()` | Returns `false` | CLI doesn't support image input |
| `supportsJsonSchema()` | Returns `true` | Claude Code supports JSON schema |
| `contextWindow()` | Returns `200_000` | All Claude models share 200k context |
| `costPer1kTokens()` | Returns `0.0` | Claude Code handles its own billing |

### complete() Method

Handles synchronous chat completions using print mode:

```php
public function complete(CompleteRequest $request): CompleteResponse
{
    $prompt = $this->buildPrompt($request->messages);

    $options = [
        'format' => 'json',
        'bare' => true,
        'systemPrompt' => $request->systemPrompt,
    ];

    if ($request->tools !== null) {
        $toolNames = array_map(fn($t) => $t->name(), $request->tools);
        $options['allowedTools'] = implode(',', $toolNames);
    }

    $output = $this->invocation->execute(
        $this->invocation->printModeArgs($prompt, $options)
    );

    return $this->parseJsonResponse($output);
}
```

**Key behaviors:**
- Messages are concatenated into a single prompt string
- Tools are passed as comma-separated names via `--allowedTools`
- JSON response is parsed for content, tool calls, and usage

### completeStream() Method

Handles streaming responses via SSE-like newline-delimited JSON:

```php
public function completeStream(CompleteRequest $request): \Generator
{
    // ... setup similar to complete()

    $process = proc_open($cmd, [...], $pipes, ...);

    $buffer = '';
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 8192);
        $buffer .= $chunk;

        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);

            if (str_starts_with($line, 'data: ')) {
                $data = json_decode(substr($line, 6), true);
                if ($data !== null) {
                    yield $this->parseChunk($data);
                }
            }
        }
    }
}
```

**Streaming design notes:**
- Cannot use `yield` inside a closure passed to `execute()`, so streaming opens the process directly
- Buffer accumulates chunks until a newline is found
- Each line starting with `data: ` is parsed as a JSON object
- Text deltas are extracted from `event.delta.type === 'text_delta'`

### buildPrompt() Method

Converts message objects to a plain text prompt:

```php
private function buildPrompt(array $messages): string
{
    $parts = [];

    foreach ($messages as $msg) {
        $parts[] = match (true) {
            $msg instanceof UserMessage => "User: {$msg->content()}",
            $msg instanceof AssistantMessage => "Assistant: {$msg->content()}",
            $msg instanceof SystemMessage => "System: {$msg->content()}",
            $msg instanceof ToolResultMessage => "Tool Result: {$msg->content()}",
            default => "User: {$msg->content()}",
        };
    }

    return implode("\n\n", $parts);
}
```

**Format:** Messages are joined with double newlines, prefixed with their role. This differs from the OpenAI provider which uses structured JSON — the Claude Code CLI expects plain text.

### parseJsonResponse() Method

Parses the JSON output from Claude Code:

```php
private function parseJsonResponse(string $output): CompleteResponse
{
    $data = json_decode($output, true);

    if ($data === null) {
        return new CompleteResponse(
            content: $output,  // Return raw on parse failure
            reasoning: null,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }

    if (isset($data['error'])) {
        $errorMsg = is_string($data['error']) ? $data['error'] : ($data['error']['message'] ?? 'Unknown error');
        return new CompleteResponse(
            content: "[Error: $errorMsg]",
            // ...
        );
    }

    return new CompleteResponse(
        content: $data['result'] ?? $data['content'] ?? '',
        reasoning: $data['reasoning'] ?? null,
        toolCalls: $this->parseToolCalls($data['tool_calls'] ?? []),
        tokensUsed: $data['usage']['total_tokens'] ?? 0,
        costUsd: $data['total_cost_usd'] ?? 0.0,
    );
}
```

**Key behaviors:**
- Parse failure returns raw output as content (graceful degradation)
- Error responses are wrapped in a descriptive content string
- Supports both `result` and `content` keys for main text
- Tool calls are parsed if present

### parseToolCalls() Method

Converts Claude Code tool call format to `ToolCall` objects:

```php
private function parseToolCalls(array $toolCalls): ?array
{
    if (empty($toolCalls)) {
        return null;
    }

    return array_map(function ($tc) {
        return ToolCall::fromArray([
            'id' => $tc['id'] ?? uniqid('tool_'),
            'name' => $tc['name'] ?? $tc['function']['name'] ?? '',
            'arguments' => is_string($tc['arguments'] ?? null)
                ? json_decode($tc['arguments'], true) ?? []
                : ($tc['arguments'] ?? []),
        ]);
    }, $toolCalls);
}
```

**Dual format handling:** Arguments may be a JSON string or already-decoded array, similar to the SGLANG provider pattern.

### embeddings() Method

```php
public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
{
    // Claude Code doesn't directly support embeddings
    return new EmbeddingsResponse(embeddings: []);
}
```

Claude Code CLI is a conversational interface and doesn't provide an embeddings endpoint.

### Usage Example

```php
use SugarCraft\Crush\Providers\ClaudeCodeInvocation;
use SugarCraft\Crush\Providers\ClaudeCodeProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Messages\UserMessage;

// Create invocation (or use defaults)
$invocation = new ClaudeCodeInvocation(
    claudePath: 'claude',
    configDir: '~/.claude',
    sessionId: null,
);

// Create provider
$provider = new ClaudeCodeProvider($invocation);

// Make a completion request
$request = new CompleteRequest(
    model: 'claude-sonnet-4-6',
    messages: [new UserMessage('Hello, world!')],
    temperature: 0.7,
);

$response = $provider->complete($request);
echo $response->content;  // AI response
```

### CLI Wrapper Pattern

The `ClaudeCodeInvocation` class implements a **CLI wrapper pattern** for integrating command-line tools:

```
ProviderInterface (contract)
        │
        ▼
ClaudeCodeProvider (translates requests to prompt format)
        │
        ▼
ClaudeCodeInvocation (spawns subprocess, handles I/O)
        │
        ▼
claude CLI (external executable in PATH)
```

**Why a separate class?**
1. **Separation of concerns** — Invocation logic (process spawning, I/O) is isolated from provider logic (prompt building, response parsing)
2. **Testability** — Mock `ClaudeCodeInvocation` in unit tests without spawning real processes
3. **Reusability** — The invocation wrapper could be used for other CLI tools

**Environment variable propagation:**
```php
[
    'ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY') ?: '',
    'ANTHROPIC_AUTH_TOKEN' => getenv('ANTHROPIC_AUTH_TOKEN') ?: '',
    'ANTHROPIC_BASE_URL' => getenv('ANTHROPIC_BASE_URL') ?: '',
]
```

These are passed to the subprocess so the Claude Code CLI can authenticate and connect to Anthropic's API.

### Process Lifecycle Comparison

| Provider | Transport | Protocol |
|----------|-----------|----------|
| OpenAIProvider | HTTP | OpenAI REST API |
| SglangProvider | HTTP | OpenAI-compatible REST API |
| ClaudeCodeProvider | Subprocess | CLI stdin/stdout |

The Claude Code provider differs fundamentally from HTTP-based providers:
- No HTTP client needed — uses `proc_open()` directly
- Process is spawned per-request (no persistent connection)
- Communication is line-by-line JSON rather than HTTP streaming

## Step 3.4: AWS Bedrock Provider

Step 3.4 implements the `BedrockProvider` — a `ProviderInterface` implementation that connects to AWS Bedrock using the AWS SDK for PHP. Unlike HTTP-based providers that use REST APIs directly, the Bedrock provider uses AWS's official SDK which handles authentication, region routing, and request signing.

### BedrockProvider Class

```php
final readonly class BedrockProvider implements ProviderInterface
{
    private const REGION_US = 'us-east-1';
    private const REGION_EU = 'eu-west-1';

    public function __construct(
        private BedrockClient $client,
        private string $region = self::REGION_US,
        private string $defaultModel = 'anthropic.claude-sonnet-4-6',
    ) {}

    public static function create(string $region = self::REGION_US, ?string $model = null): self
    {
        $client = new BedrockClient([
            'region' => $region,
            'version' => 'latest',
        ]);

        return new self($client, $region, $model ?? 'anthropic.claude-sonnet-4-6');
    }
}
```

The provider requires an AWS `BedrockClient` instance (from the `aws/aws-sdk-php` package) along with region and model configuration.

### Provider Interface Implementation

| Method | Implementation | Notes |
|--------|----------------|-------|
| `name()` | Returns `'bedrock'` | Provider identifier |
| `supportsStreaming()` | Returns `true` | Bedrock supports streaming |
| `supportsFunctionCalling()` | Returns `false` | Depends on model capability |
| `supportsVision()` | Returns `false` | Vision requires separate model |
| `supportsJsonSchema()` | Returns `false` | Bedrock uses native inference config |
| `contextWindow()` | Varies by model | 200k for Claude, 8k for Llama |
| `costPer1kTokens()` | Model-based pricing | Approximate AWS pricing |

### Region Configuration

```php
public function __construct(
    private BedrockClient $client,
    private string $region = self::REGION_US,  // or self::REGION_EU
    private string $defaultModel = 'anthropic.claude-sonnet-4-6',
) {}
```

AWS Bedrock is available in multiple regions. The provider defaults to `us-east-1` but supports `eu-west-1` and other AWS regions.

### Supported Models

```php
public function contextWindow(): int
{
    return match ($this->defaultModel) {
        'anthropic.claude-opus-4-6' => 200_000,
        'anthropic.claude-sonnet-4-6' => 200_000,
        'anthropic.claude-haiku-4-7' => 200_000,
        'meta.llama3-70b-instruct' => 8_192,
        'meta.llama3-8b-instruct' => 8_192,
        default => 8_192,
    };
}
```

**Note**: Context windows vary significantly between providers. Claude models offer 200k context while Llama models offer 8k.

### Pricing by Model

```php
public function costPer1kTokens(string $model, string $direction): float
{
    // Pricing varies by model and region - these are approximations
    return match ($model) {
        'anthropic.claude-opus-4-6' => $direction === 'input' ? 0.015 : 0.075,
        'anthropic.claude-sonnet-4-6' => $direction === 'input' ? 0.003 : 0.015,
        'anthropic.claude-haiku-4-7' => $direction === 'input' ? 0.00025 : 0.00125,
        'meta.llama3-70b-instruct' => $direction === 'input' ? 0.00065 : 0.00275,
        'meta.llama3-8b-instruct' => $direction === 'input' ? 0.00022 : 0.00088,
        default => 0.01,
    };
}
```

**Important**: AWS Bedrock pricing varies by region and can change. These values are approximations for cost estimation.

### complete() Method

The `complete()` method handles synchronous chat completions:

```php
public function complete(CompleteRequest $request): CompleteResponse
{
    $params = [
        'modelId' => $request->model,
        'messages' => $this->formatMessages($request->messages),
    ];

    if ($request->systemPrompt !== null) {
        $params['system'] = [['text' => $request->systemPrompt]];
    }

    if ($request->maxTokens !== null) {
        $params['inferenceConfig'] = [
            'maxTokens' => $request->maxTokens,
            'temperature' => $request->temperature ?? 0.7,
        ];
    }

    try {
        $result = $this->client->invokeModel($params);
        $data = $result->toArray();

        return $this->parseResponse($data);
    } catch (AwsException $e) {
        throw new \RuntimeException('Bedrock completion failed: ' . $e->getMessage(), 0, $e);
    }
}
```

**Key behaviors:**
- Uses `modelId` instead of `model` (AWS parameter naming)
- System prompts are formatted as `system: [[{text: string}]]`
- `inferenceConfig` wraps maxTokens and temperature
- AWS exceptions are wrapped in `RuntimeException` for provider-agnostic error handling

### completeStream() Method

The `completeStream()` method handles streaming responses using a generator:

```php
public function completeStream(CompleteRequest $request): \Generator
{
    $params = [
        'modelId' => $request->model,
        'messages' => $this->formatMessages($request->messages),
        'guardrailConfig' => [],
        'inferenceConfig' => [
            'maxTokens' => $request->maxTokens ?? 4096,
            'temperature' => $request->temperature ?? 0.7,
        ],
    ];

    if ($request->systemPrompt !== null) {
        $params['system'] = [['text' => $request->systemPrompt]];
    }

    try {
        $result = $this->client->invokeModelWithResponseStream($params);
        $stream = $result->get('body');

        foreach ($stream as $chunk) {
            if (isset($chunk['chunk']['bytes'])) {
                $data = json_decode($chunk['chunk']['bytes'], true);
                if ($data !== null) {
                    yield $this->parseChunk($data);
                }
            }
        }
    } catch (AwsException $e) {
        throw new \RuntimeException('Bedrock streaming failed: ' . $e->getMessage(), 0, $e);
    }
}
```

**Streaming design notes:**
- Uses `invokeModelWithResponseStream()` for streaming
- The stream body contains chunks with `chunk.bytes` containing JSON
- Each chunk's JSON is decoded to extract completion deltas
- `tokensUsed` and `costUsd` are always 0 for streaming chunks (usage data only available at stream completion)

### Message Formatting

The `formatMessages()` method translates CandyCrush `Message` objects to Bedrock format:

```php
private function formatMessages(array $messages): array
{
    return array_map(function (Message $msg) {
        $role = match (true) {
            $msg instanceof UserMessage => 'user',
            $msg instanceof AssistantMessage => 'assistant',
            $msg instanceof SystemMessage => 'user', // System wrapped as user
            $msg instanceof ToolResultMessage => 'user',
            default => 'user',
        };

        return [
            'role' => $role,
            'content' => [['text' => $msg->content()]],
        ];
    }, $messages);
}
```

**Key translation rules:**
- Content is always an array of `[{text: string}]` objects
- System messages are wrapped as user messages (Bedrock convention)
- Each message has `role` and `content` fields

**Differences from HTTP-based providers:**
- Content is an array `[{text: string}]` rather than a simple string
- System messages don't use a separate `role: 'system'` — they're wrapped as user messages

### Response Parsing

The `parseResponse()` method extracts data from the Bedrock response:

```php
private function parseResponse(array $data): CompleteResponse
{
    $output = $data['output']['message'] ?? [];
    $content = $output['content'] ?? [];

    return new CompleteResponse(
        content: $content[0]['text'] ?? '',
        reasoning: null,
        toolCalls: null,
        tokensUsed: ($data['usage']['inputTokens'] ?? 0) + ($data['usage']['outputTokens'] ?? 0),
        costUsd: 0.0, // Calculate from usage if needed
    );
}
```

**Response structure differences:**
- Output is nested under `output.message` (AWS structure)
- Content is an array `content[0].text` (Bedrock format)
- Usage uses `inputTokens` and `outputTokens` (not `total_tokens`)

### Streaming Chunk Parsing

The `parseChunk()` method handles individual streaming chunks:

```php
private function parseChunk(array $data): CompleteResponse
{
    if (isset($data['completion'])) {
        return new CompleteResponse(
            content: $data['completion'],
            reasoning: null,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }

    return new CompleteResponse(
        content: '',
        reasoning: null,
        toolCalls: null,
        tokensUsed: 0,
        costUsd: 0.0,
    );
}
```

**Streaming chunk format:**
- Deltas arrive in `data['completion']` field
- Empty chunks may arrive without content
- `tokensUsed` and `costUsd` are always 0 for streaming

### embeddings() Method

```php
public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
{
    // Use Titan or Cohere for embeddings via Bedrock
    return new EmbeddingsResponse(embeddings: []);
}
```

**Design note**: Embeddings via Bedrock require separate model configuration (Titan or Cohere). This stub returns empty results — actual implementation would need model-specific client calls.

### AWS SDK Integration

The provider uses the official AWS SDK for PHP:

```php
use Aws\Bedrock\BedrockClient;
use Aws\Exception\AwsException;

$client = new BedrockClient([
    'region' => 'us-east-1',
    'version' => 'latest',
]);
```

**Benefits of AWS SDK:**
- Automatic request signing (AWS Signature Version 4)
- Credential resolution via IAM roles, environment variables, or config files
- Retry logic and timeout handling
- Region-based endpoint routing

**Authentication**: The SDK automatically finds AWS credentials via:
1. Environment variables (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`)
2. AWS credentials file (`~/.aws/credentials`)
3. IAM role (when running on EC2/ECS/Lambda)

### Usage Example

```php
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Messages\UserMessage;

// Create provider via factory
$provider = BedrockProvider::create('us-east-1', 'anthropic.claude-sonnet-4-6');

// Make a completion request
$request = new CompleteRequest(
    model: 'anthropic.claude-sonnet-4-6',
    messages: [new UserMessage('Hello, world!')],
    temperature: 0.7,
);

$response = $provider->complete($request);
echo $response->content;  // AI response
echo $response->tokensUsed;  // Token count (combined input + output)
```

### Provider Transport Comparison

| Provider | Transport | Protocol |
|----------|-----------|----------|
| OpenAIProvider | HTTP | OpenAI REST API |
| SglangProvider | HTTP | OpenAI-compatible REST API |
| ClaudeCodeProvider | Subprocess | CLI stdin/stdout |
| BedrockProvider | AWS SDK | AWS Bedrock API |

**Bedrock differs from HTTP-based providers:**
- Uses official AWS SDK rather than raw HTTP
- AWS handles authentication and request signing
- Different request/response schema (AWS-native)
- Region-based routing instead of base URL

### How Bedrock Differs from HTTP-Based Providers

1. **Authentication**: AWS SDK handles SigV4 signing automatically; HTTP providers need manual Bearer token or API key headers.

2. **Request Format**:
   - HTTP providers: `{"model": "...", "messages": [...]}`
   - Bedrock: `{"modelId": "...", "messages": [...]}`

3. **Response Format**:
   - HTTP providers: `{"choices": [{"message": {"content": "..."}}]}`
   - Bedrock: `{"output": {"message": {"content": [{"text": "..."}]}}}`

4. **System Prompt**:
   - HTTP providers: `{"role": "system", "content": "..."}`
   - Bedrock: `{"system": [{"text": "..."}]}`

5. **Streaming**:
   - HTTP providers: SSE with `data: ` prefix
   - Bedrock: Binary frame with `chunk.bytes` containing JSON

## Step 3.5: Vertex and Custom Providers

Step 3.5 implements two additional `ProviderInterface` implementations: `VertexProvider` for Google Cloud AI Platform and `CustomProvider` for any OpenAI-compatible endpoint.

### VertexProvider Class

`VertexProvider` connects to Google Cloud's AI Platform using the official Google Cloud AI Platform SDK:

```php
final readonly class VertexProvider implements ProviderInterface
{
    public function __construct(
        private string $projectId,
        private string $location,
        private string $defaultModel,
        private PredictionServiceClient $client,
    ) {}

    public static function create(
        string $projectId,
        string $location = 'us-central1',
        string $model = 'claude-3-sonnet@20240229',
    ): self {
        $client = new PredictionServiceClient([
            'projectId' => $projectId,
        ]);

        return new self($projectId, $location, $model, $client);
    }
}
```

**Google Cloud SDK dependency**: The provider requires `google/cloud-ai-platform` package which provides the `PredictionServiceClient` for interacting with Vertex AI endpoints.

### Provider Interface Implementation

| Method | Implementation | Notes |
|--------|----------------|-------|
| `name()` | Returns `'vertex'` | Provider identifier |
| `supportsStreaming()` | Returns `true` | Vertex supports streaming |
| `supportsFunctionCalling()` | Returns `false` | Vertex AI doesn't support function calling |
| `supportsVision()` | Returns `false` | Vision requires separate model |
| `supportsJsonSchema()` | Returns `false` | Vertex uses native inference config |
| `contextWindow()` | Returns `200_000` | Claude models on Vertex |
| `costPer1kTokens()` | Returns `0.0` | Vertex pricing varies by region |

### complete() Method

The `complete()` method sends requests to the Vertex AI predict endpoint:

```php
public function complete(CompleteRequest $request): CompleteResponse
{
    $params = [
        'endpoint' => "projects/{$this->projectId}/locations/{$this->location}/publishers/anthropic/models/{$request->model}",
        'instances' => [
            [
                'messages' => $this->formatMessages($request->messages),
                'temperature' => $request->temperature ?? 0.7,
                'max_tokens' => $request->maxTokens ?? 4096,
            ],
        ],
    ];

    try {
        $response = $this->client->predict($params);
        $predictions = $response->getPredictions();
        $data = $predictions[0]->getStruct() ?? [];

        return $this->parseResponse($data);
    } catch (\Exception $e) {
        return new CompleteResponse(
            content: '',
            isError: true,
            errorMessage: $e->getMessage(),
        );
    }
}
```

**Key behaviors:**
- Uses Google Cloud's `PredictionServiceClient` for API calls
- Endpoint format: `projects/{project}/locations/{location}/publishers/anthropic/models/{model}`
- Messages are formatted for Vertex AI's expected structure
- Exceptions are caught and returned as error responses

### Message Formatting

The `formatMessages()` method translates CandyCrush messages to Vertex format:

```php
private function formatMessages(array $messages): array
{
    return array_map(function (Message $msg) {
        return [
            'role' => match (true) {
                $msg instanceof UserMessage => 'user',
                $msg instanceof AssistantMessage => 'assistant',
                default => 'user',
            ],
            'content' => $msg->content(),
        ];
    }, $messages);
}
```

**Note**: Vertex AI has limited role support. System messages are not explicitly handled and fall through to the default `'user'` role.

### completeStream() Placeholder

Streaming for Vertex AI is not yet implemented:

```php
public function completeStream(CompleteRequest $request): \Generator
{
    // Vertex streaming implementation placeholder
    // Streaming not yet fully implemented for Vertex AI
    yield new CompleteResponse(
        content: '',
        reasoning: null,
        toolCalls: null,
        tokensUsed: 0,
        costUsd: 0.0
    );
}
```

**Limitation**: This yields a single empty response. Full streaming support requires implementation using Vertex AI's streaming endpoints.

### Usage Example

```php
use SugarCraft\Crush\Providers\VertexProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Messages\UserMessage;

// Create provider via factory
$provider = VertexProvider::create(
    projectId: 'my-gcp-project',
    location: 'us-central1',
    model: 'claude-3-sonnet@20240229',
);

// Make a completion request
$request = new CompleteRequest(
    model: 'claude-3-sonnet@20240229',
    messages: [new UserMessage('Hello, Vertex AI!')],
    temperature: 0.7,
);

$response = $provider->complete($request);
echo $response->content;  // AI response
```

### CustomProvider Class

`CustomProvider` provides a generic OpenAI-compatible provider that can connect to any endpoint supporting the OpenAI Chat Completions API:

```php
final readonly class CustomProvider implements ProviderInterface
{
    public function __construct(
        private string $name,
        private string $baseUrl,
        private string $model,
        private ?string $apiKey,
        private Client $httpClient,
        private bool $supportsStreaming,
        private bool $supportsFunctionCalling,
    ) {}

    public static function openAiCompatible(
        string $name,
        string $baseUrl,
        string $model,
        ?string $apiKey = null,
        bool $supportsStreaming = true,
        bool $supportsFunctionCalling = true,
    ): self {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($apiKey !== null) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $client = new Client([
            'base_uri' => $baseUrl,
            'headers' => $headers,
        ]);

        return new self(
            name: $name,
            baseUrl: $baseUrl,
            model: $model,
            apiKey: $apiKey,
            httpClient: $client,
            supportsStreaming: $supportsStreaming,
            supportsFunctionCalling: $supportsFunctionCalling,
        );
    }
}
```

### Provider Interface Implementation

| Method | Implementation | Notes |
|--------|----------------|-------|
| `name()` | Returns configured name | Dynamic provider identifier |
| `supportsStreaming()` | Returns configured value | Defaults to `true` |
| `supportsFunctionCalling()` | Returns configured value | Defaults to `true` |
| `supportsVision()` | Returns `false` | Text-only provider |
| `supportsJsonSchema()` | Returns `false` | OpenAI-compatible providers use additional properties |
| `contextWindow()` | Returns `128_000` | Typical for OpenAI-compatible endpoints |
| `costPer1kTokens()` | Returns `0.0` | Self-hosted, no cost |

### Factory Method: openAiCompatible()

The `openAiCompatible()` factory creates a provider configured for OpenAI-compatible endpoints:

```php
public static function openAiCompatible(
    string $name,
    string $baseUrl,
    string $model,
    ?string $apiKey = null,
    bool $supportsStreaming = true,
    bool $supportsFunctionCalling = true,
): self
```

**Key design decisions:**
- `name` allows custom provider identification (e.g., `'local-llama'`, `'ollama'`)
- `baseUrl` is the base URI for the API (e.g., `'http://localhost:11434'`)
- `apiKey` is optional — many local endpoints don't require authentication
- `supportsStreaming` and `supportsFunctionCalling` flags allow disabling features not supported by the endpoint

### complete() Method

The `complete()` method handles synchronous chat completions:

```php
public function complete(CompleteRequest $request): CompleteResponse
{
    $params = [
        'model' => $request->model,
        'messages' => $this->formatMessages($request->messages),
        'temperature' => $request->temperature ?? 0.7,
        'max_tokens' => $request->maxTokens ?? 4096,
    ];

    if ($request->tools !== null && $this->supportsFunctionCalling) {
        $params['tools'] = $this->formatTools($request->tools);
    }

    try {
        $response = $this->httpClient->post('/chat/completions', [
            'json' => $params,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $this->parseResponse($data);
    } catch (GuzzleException $e) {
        return new CompleteResponse(
            content: '',
            isError: true,
            errorMessage: $e->getMessage(),
        );
    }
}
```

**Key behaviors:**
- Posts to `/chat/completions` endpoint (OpenAI-compatible)
- Tools are only sent if both provided and function calling is supported
- `GuzzleException` is caught and returned as an error response

### completeStream() Method

The `completeStream()` method handles streaming responses using a generator with proper SSE line parsing:

```php
public function completeStream(CompleteRequest $request): \Generator
{
    if (!$this->supportsStreaming) {
        yield $this->complete($request);
        return;
    }

    $params = [
        'model' => $request->model,
        'messages' => $this->formatMessages($request->messages),
        'temperature' => $request->temperature ?? 0.7,
        'max_tokens' => $request->maxTokens ?? 4096,
        'stream' => true,
    ];

    if ($request->tools !== null && $this->supportsFunctionCalling) {
        $params['tools'] = $this->formatTools($request->tools);
    }

    try {
        $response = $this->httpClient->post('/chat/completions', [
            'json' => $params,
            'stream' => true,
        ]);

        $stream = $response->getBody();
        $buffer = '';

        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            $buffer .= $chunk;

            // Process complete lines in buffer
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);

                $line = trim($line);
                if (str_starts_with($line, 'data: ')) {
                    $data = json_decode(substr($line, 6), true);
                    if ($data === null) {
                        continue;
                    }
                    if (isset($data['choices'][0]['delta'])) {
                        yield $this->parseChunk($data);
                    }
                    if (isset($data['choices'][0]['finish_reason'])) {
                        return;
                    }
                }
            }
        }
    } catch (GuzzleException $e) {
        yield new CompleteResponse(
            content: '',
            isError: true,
            errorMessage: $e->getMessage(),
        );
    }
}
```

**SSE line parsing**: The streaming implementation uses a buffer-based approach:
1. Reads chunks of up to 8192 bytes from the stream
2. Accumulates bytes in a buffer until a newline is found
3. Extracts complete lines and processes SSE `data: ` prefixed lines
4. Parses JSON and yields chunks with `delta.content`
5. Detects stream end via `finish_reason` in the data

### Message Formatting

The `formatMessages()` method handles all message types including tool results:

```php
private function formatMessages(array $messages): array
{
    return array_map(function (Message $msg) {
        return match (true) {
            $msg instanceof UserMessage => ['role' => 'user', 'content' => $msg->content()],
            $msg instanceof AssistantMessage => array_filter([
                'role' => 'assistant',
                'content' => $msg->content(),
                'tool_calls' => $msg->toolCalls(),
            ]),
            $msg instanceof SystemMessage => ['role' => 'system', 'content' => $msg->content()],
            $msg instanceof ToolResultMessage => [
                'role' => 'tool',
                'tool_call_id' => $msg->toolCallId(),
                'content' => $msg->content(),
            ],
            default => ['role' => 'user', 'content' => $msg->content()],
        };
    }, $messages);
}
```

**Tool call handling**: Assistant messages with tool calls use `array_filter()` to remove null values, ensuring clean JSON output to the API.

### Response Parsing

The `parseResponse()` method extracts data from OpenAI-compatible responses:

```php
private function parseResponse(array $data): CompleteResponse
{
    $choice = $data['choices'][0] ?? [];
    $message = $choice['message'] ?? [];

    $toolCalls = null;
    if (isset($message['tool_calls'])) {
        $toolCalls = array_map(
            fn($tc) => ToolCall::fromArray([
                'id' => $tc['id'],
                'name' => $tc['function']['name'],
                'arguments' => is_string($tc['function']['arguments'] ?? '')
                    ? json_decode($tc['function']['arguments'], true) ?? []
                    : ($tc['function']['arguments'] ?? []),
            ]),
            $message['tool_calls']
        );
    }

    return new CompleteResponse(
        content: $message['content'] ?? '',
        reasoning: null,
        toolCalls: $toolCalls,
        tokensUsed: $data['usage']['total_tokens'] ?? 0,
        costUsd: 0.0,
    );
}
```

**Tool call argument handling**: Arguments may be a JSON string or already-decoded array. The code handles both cases defensively.

### embeddings() Method

```php
public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
{
    try {
        $response = $this->httpClient->post('/embeddings', [
            'json' => [
                'model' => $request->model,
                'input' => $request->input,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return new EmbeddingsResponse(
            embeddings: array_map(
                fn($item) => $item['embedding'],
                $data['data'] ?? []
            )
        );
    } catch (GuzzleException $e) {
        return new EmbeddingsResponse(embeddings: []);
    }
}
```

**Error handling**: Returns empty embeddings on failure rather than throwing, consistent with other HTTP-based providers.

### Usage Example

```php
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Messages\UserMessage;

// Create provider for Ollama
$provider = CustomProvider::openAiCompatible(
    name: 'ollama',
    baseUrl: 'http://localhost:11434/v1',
    model: 'llama3',
    apiKey: null,  // No auth for local Ollama
    supportsStreaming: true,
    supportsFunctionCalling: false,
);

// Make a completion request
$request = new CompleteRequest(
    model: 'llama3',
    messages: [new UserMessage('Hello, local Llama!')],
    temperature: 0.7,
);

$response = $provider->complete($request);
echo $response->content;  // AI response
```

### Usage with LM Studio

```php
// Connect to LM Studio running locally
$provider = CustomProvider::openAiCompatible(
    name: 'lm-studio',
    baseUrl: 'http://localhost:1234/v1',
    model: 'my-model',
    apiKey: null,
    supportsStreaming: true,
    supportsFunctionCalling: true,
);
```

### Provider Transport Comparison

| Provider | Transport | Protocol |
|----------|-----------|----------|
| OpenAIProvider | HTTP | OpenAI REST API |
| SglangProvider | HTTP | OpenAI-compatible REST API |
| ClaudeCodeProvider | Subprocess | CLI stdin/stdout |
| BedrockProvider | AWS SDK | AWS Bedrock API |
| VertexProvider | Google Cloud SDK | Google Cloud AI Platform API |
| CustomProvider | HTTP | OpenAI-compatible REST API |

**CustomProvider vs SglangProvider**: Both use OpenAI-compatible APIs, but:
- `SglangProvider` is specialized for SGLANG servers with specific defaults
- `CustomProvider` is a generic wrapper for any OpenAI-compatible endpoint with configurable feature flags

## Step 3.6: ProviderFactory

Step 3.6 implements `ProviderFactory` — a factory class that creates providers from configuration arrays with environment variable resolution support. This enables configuration-driven provider instantiation rather than direct construction.

### ProviderFactory Class

```php
final readonly class ProviderFactory
{
    public function create(array|string $config): ProviderInterface
    public function resolveEnv(?string $value): ?string
    public function availableTypes(): array
    public function defaultConfig(string $type): array
}
```

The factory acts as a central entry point for creating any provider type, handling JSON parsing, environment variable resolution, and validation.

### create() Method

The `create()` method accepts either an array or JSON string configuration:

```php
public function create(array|string $config): ProviderInterface
```

**Config array format:**
```php
$config = [
    'type' => 'openai',
    'apiKey' => '${OPENAI_API_KEY}',
    'organization' => '${OPENAI_ORG_ID}',
    'model' => 'gpt-4o',
];

$provider = $factory->create($config);
```

**JSON string format:**
```php
$json = '{"type": "anthropic", "apiKey": "${ANTHROPIC_API_KEY}"}';
$provider = $factory->create($json);
```

**Processing pipeline:**
1. Parse JSON string to array if needed
2. Validate config structure (must have `type` key)
3. Validate provider type is known
4. Resolve environment variables in all string values
5. Validate required keys for the provider type
6. Instantiate the appropriate provider

**Error handling:**
- `InvalidArgumentException` for missing type, unknown type, or invalid JSON
- `RuntimeException` for missing required configuration keys

### resolveEnv() Environment Variable Resolution

The `resolveEnv()` method resolves `${VAR}` and `${VAR:-default}` patterns:

```php
public function resolveEnv(?string $value): ?string
```

**Supported patterns:**

| Pattern | Behavior |
|---------|----------|
| `${VAR}` | Replace with environment variable value |
| `${VAR:-default}` | Replace with default if VAR is unset or empty |
| `${VAR:-}` | Replace with empty string if VAR is unset or empty |

**Examples:**
```php
$factory->resolveEnv('${OPENAI_API_KEY}');
// Returns 'sk-xxx' if OPENAI_API_KEY is set, '' otherwise

$factory->resolveEnv('${ANTHROPIC_BASE_URL:-https://api.anthropic.com}');
// Returns the env value if set, otherwise 'https://api.anthropic.com'

$factory->resolveEnv('${SGLANG_API_KEY:-}');
// Returns env value if set, otherwise empty string ''
```

**Regex pattern:** `[A-Z_][A-Z0-9_]*` for env var names — restricts to safe characters only.

**Edge cases:**
- Unset env (`getenv()` returns `false`) → returns default or `''`
- Empty env (`''`) → returns default or `''` (shell semantics treat empty as unset for credentials)
- Whitespace-only value → passes through (not treated as empty)

### availableTypes() Listing

Returns all supported provider types:

```php
public function availableTypes(): array
{
    return ['openai', 'anthropic', 'claude-code', 'sglang', 'bedrock', 'vertex', 'custom'];
}
```

**Supported types:**

| Type | Required Keys | Optional Keys |
|------|--------------|--------------|
| `openai` | `apiKey` | `organization`, `model` |
| `anthropic` | `apiKey` | `baseUrl`, `model` |
| `claude-code` | `claudePath` | `model` |
| `sglang` | `baseUrl`, `model` | `apiKey` |
| `bedrock` | `region` | `model` |
| `vertex` | `projectId` | `location`, `model` |
| `custom` | `name`, `baseUrl`, `model` | `apiKey`, `supportsStreaming`, `supportsFunctionCalling` |

### defaultConfig() Usage

Returns sensible default configuration for each provider type:

```php
public function defaultConfig(string $type): array
```

**Example — OpenAI defaults:**
```php
$factory->defaultConfig('openai');
// Returns:
// [
//     'type' => 'openai',
//     'apiKey' => getenv('OPENAI_API_KEY') ?: '',
//     'organization' => getenv('OPENAI_ORG_ID') ?: null,
//     'model' => 'gpt-4o',
// ]
```

**Example — SGLANG defaults:**
```php
$factory->defaultConfig('sglang');
// Returns:
// [
//     'type' => 'sglang',
//     'baseUrl' => 'http://localhost:30000',
//     'model' => 'MiniMax-M2.7',
//     'apiKey' => getenv('SGLANG_API_KEY') ?: null,
// ]
```

**Design note**: Default configs pull values from environment variables where credentials are needed, avoiding hardcoded secrets. Optional fields default to sensible values appropriate for each provider.

### Example: Creating Providers from Environment-Based Config

The factory enables environment-driven configuration:

```php
use SugarCraft\Crush\Providers\ProviderFactory;

// Create factory
$factory = new ProviderFactory();

// Build config from defaults, override with environment
$config = $factory->defaultConfig('openai');
$config['model'] = 'gpt-4o';  // Override default model

// Create provider
$provider = $factory->create($config);

// Use with AppBuilder
$app = (new AppBuilder())
    ->withProvider($provider)
    ->withModel($config['model'])
    ->build();
```

**Environment-based creation from JSON:**
```php
// config.json
// {
//     "type": "anthropic",
//     "apiKey": "${ANTHROPIC_API_KEY}",
//     "model": "claude-sonnet-4-6"
// }

$json = file_get_contents('config.json');
$provider = $factory->create($json);
```

The `${ANTHROPIC_API_KEY}` placeholder is resolved from the environment at creation time.

### TYPE_SCHEMAS Constant

The `TYPE_SCHEMAS` constant defines validation structure for each provider:

```php
private const TYPE_SCHEMAS = [
    'openai' => [
        'required' => ['apiKey'],
        'optional' => ['organization', 'model'],
    ],
    // ...
];
```

**Why a schema constant?**
- Single source of truth for required/optional fields
- Drives both validation (`validateRequiredKeys()`) and documentation
- Adding a new provider type only requires adding to this constant

### Factory Method vs Constructor Injection Comparison

| Aspect | Factory (`ProviderFactory`) | Direct Constructor |
|--------|---------------------------|-------------------|
| Configuration source | Array/JSON (runtime) | Arguments (compile time) |
| Env var resolution | Built-in | Manual |
| Validation | Centralized | Per-constructor |
| Coupling | Loose (only interface) | Tight (specific class) |
| JSON config | Native support | Requires external parsing |

**Use factory when:**
- Provider is configured at runtime (files, env vars, APIs)
- Multiple provider types need centralized creation
- Environment variable resolution is needed

**Use constructor when:**
- Provider is known at compile time
- Testing with mock providers
- Minimal indirection preferred

### Provider Transport Comparison (Updated)

| Provider | Transport | Protocol | Creation |
|----------|-----------|----------|----------|
| OpenAIProvider | HTTP | OpenAI REST API | ProviderFactory |
| SglangProvider | HTTP | OpenAI-compatible REST API | ProviderFactory |
| ClaudeCodeProvider | Subprocess | CLI stdin/stdout | ProviderFactory |
| BedrockProvider | AWS SDK | AWS Bedrock API | ProviderFactory |
| VertexProvider | Google Cloud SDK | Google Cloud AI Platform API | ProviderFactory |
| CustomProvider | HTTP | OpenAI-compatible REST API | ProviderFactory |

**Why ProviderFactory?**
- Uniform API for creating any provider type
- Environment variable resolution across all providers
- Config validation at factory boundary
- Single place to modify if provider creation logic changes

## Step 4.1: Skill Value Object

Step 4.1 implements the `Skill` value object — a data structure representing a loaded skill from a SKILL.md file. Skills provide specialized instructions and workflows that agents can use during execution.

### Skill Class

```php
final readonly class Skill
{
    public function __construct(
        public string $name,
        public string $description,
        public bool $userInvocable,
        public bool $disableModelInvocation,
        public ?string $allowedTools,
        public ?string $disallowedTools,
        public ?string $model,
        public string $effort,
        public string $context,
        public array $paths,
        public string $content,
        public string $sourcePath,
    ) {}
}
```

The `Skill` class uses PHP 8.3 `readonly` with promoted parameters for a clean, immutable value object.

### SKILL.md Frontmatter Specification

Skills are defined in SKILL.md files with YAML frontmatter. The frontmatter uses kebab-case keys:

```yaml
---
name: skill-name
description: Brief description of what this skill does
user-invocable: true        # Whether users can invoke this skill directly
disable-model-invocation: false  # Skip model for this skill's context
allowed-tools: tool1,tool2  # Optional: comma-separated list
disallowed-tools: tool3      # Optional: tools to disable
model: claude-sonnet-4-6    # Optional: specific model to use
effort: medium              # planning|medium|high — estimated complexity
context: thread             # thread|global — how context is accumulated
paths:                      # Optional: file patterns this skill applies to
  - '**/*.php'
  - '**/composer.json'
---
# Skill content (markdown) goes here
```

| Frontmatter Key | Type | Default | Description |
|-----------------|------|---------|-------------|
| `name` | `string` | filename | Skill identifier |
| `description` | `string` | `"Skill: {name}"` | Human-readable description for skill matching |
| `user-invocable` | `bool` | `true` | Whether skill appears in user-facing skill list |
| `disable-model-invocation` | `bool` | `false` | Skip LLM call when this skill is matched |
| `allowed-tools` | `?string` | `null` | Comma-separated allowed tool names |
| `disallowed-tools` | `?string` | `null` | Comma-separated disallowed tool names |
| `model` | `?string` | `null` | Override default model for this skill |
| `effort` | `string` | `"medium"` | Planning level: `planning`, `medium`, or `high` |
| `context` | `string` | `"thread"` | Context accumulation: `thread` (per conversation) or `global` |
| `paths` | `array` | `[]` | Glob patterns for files this skill applies to |

### fromFile() Method

Load a skill from a filesystem path:

```php
public static function fromFile(string $path): self
```

```php
use SugarCraft\Crush\Skills\Skill;

$skill = Skill::fromFile('/path/to/skills/my-skill/SKILL.md');
echo $skill->name;        // "my-skill"
echo $skill->content;     // Raw markdown body after frontmatter
echo $skill->sourcePath;  // "/path/to/skills/my-skill/SKILL.md"
```

**Error handling:** Throws `RuntimeException` if the file cannot be read.

### parse() Method

Parse skill content directly without file I/O:

```php
public static function parse(string $content, string $name, string $sourcePath = ''): self
```

```php
$content = file_get_contents('SKILL.md');
$skill = Skill::parse($content, 'my-skill', 'SKILL.md');
```

The `parse()` method:
1. Extracts YAML frontmatter using regex `/^---\s*\n(.*?)\n---\s*\n/s`
2. Parses frontmatter with `Symfony\Component\Yaml\Yaml::parse()`
3. Returns remaining content as the `content` field

### matchesPrompt() for Skill Selection

Determine if a skill matches a user's prompt:

```php
public function matchesPrompt(string $prompt): bool
```

```php
if ($skill->matchesPrompt('I need to add a new payment gateway')) {
    // This skill handles payment integrations
}
```

**Matching algorithm:**
- Extracts keywords from the skill's `description` (space-separated, lowercase)
- Filters out words with 3 or fewer characters
- Returns `true` if any keyword appears in the prompt (case-insensitive)

**Rationale:** The 3-character minimum avoids matching common articles ("the", "a", "for") while allowing meaningful short terms like "API", "PHP", "SQL" which typically appear in skill names rather than descriptions.

### systemPromptContribution() for LLM Context

Generate the skill content to inject into system prompts:

```php
public function systemPromptContribution(): string
```

```php
$systemPrompt .= $skill->systemPromptContribution();
```

**Output format:**
```
## Skill: {skill-name}

{skill-content}
```

The contribution appends the skill's markdown content under a header, allowing LLMs to incorporate skill instructions into their context.

### toArray() for Serialization

Convert a skill to an array for storage or transmission:

```php
public function toArray(): array
```

```php
$data = $skill->toArray();
// Keys: name, description, user_invokable, disable_model_invocation,
//       allowed_tools, disallowed_tools, model, effort, context, paths, source_path
```

**Note:** The `content` field is intentionally excluded from serialization — it's reconstructable from the source file and can be large.

### withName() for Immutable Updates

Create a modified copy with a different name:

```php
public function withName(string $name): self
```

```php
$renamedSkill = $skill->withName('new-skill-name');
// Returns new instance, original unchanged
```

This follows the immutable value object pattern — `with*()` methods return new instances rather than mutating.

### Example SKILL.md File

```yaml
---
name: payment-gateway
description: Adds a new payment method by extending PaymentMethodBase in include/Api/Billing/Pay/. Implements create() to set redirectUrl/items and return bool, and optionally confirm() for IPN/callback handling. Use when user says 'add payment method', 'integrate payment gateway', 'add payment provider', 'new payment option'. Do NOT use for modifying billing invoice logic, changing payment UI templates, or editing existing gateways unrelated to a new integration.
paths:
  - 'include/Api/Billing/Pay/**/*.php'
  - 'include/templates/**/payment*.tpl'
effort: medium
context: thread
user-invocable: true
disable-model-invocation: false
allowed-tools: Bash,Read,Edit,Grep
---

# Payment Gateway Integration

When adding a new payment provider...

## Steps

1. Create a new class extending `PaymentMethodBase`
2. Implement the `create()` method
3. Add the redirect URL handling
4. Optionally implement `confirm()` for IPN callbacks
```

## Step 4.2: SkillLoader and SkillRegistry

Step 4.2 implements `SkillLoader` and `SkillRegistry` — the loading and registry classes for skills. `SkillLoader` discovers skills from the filesystem across multiple source directories with a priority chain. `SkillRegistry` provides query methods for finding skills by name, prompt, or file path.

### SkillLoader Class

```php
final class SkillLoader
{
    public function loadFromDirectory(string $dir): array
    public function loadUserSkills(): array
    public function loadProjectSkills(string $projectRoot): array
    public function loadBuiltInSkills(): array
    public function loadAll(string $projectRoot = '.'): array
}
```

### loadFromDirectory() Method

The core loading method recursively discovers SKILL.md files:

```php
public function loadFromDirectory(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $skills = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getBasename() === 'SKILL.md' && $file->isFile()) {
            try {
                $skill = Skill::fromFile($file->getPathname());
                $skills[$skill->name] = $skill;
            } catch (\Throwable $e) {
                // Log and skip invalid skills
                error_log("Failed to load skill from {$file->getPathname()}: {$e->getMessage()}");
            }
        }
    }

    return $skills;
}
```

**Key behaviors:**
- Uses `RecursiveDirectoryIterator::SKIP_DOTS` to skip `.` and `..` entries
- Recursively traverses all subdirectories
- Only processes files named `SKILL.md`
- Catches exceptions per-file and logs failures — one bad skill doesn't abort loading
- Returns skills keyed by name for O(1) lookup

### Priority Chain (loadAll)

The `loadAll()` method combines skills from three sources with override precedence:

```php
public function loadAll(string $projectRoot = '.'): array
{
    $skills = [];

    // Built-in first (lowest priority)
    $builtin = $this->loadBuiltInSkills();

    // User skills override builtins
    $user = $this->loadUserSkills();
    $skills = array_merge($builtin, $user);

    // Project skills override both
    $project = $this->loadProjectSkills($projectRoot);
    $skills = array_merge($skills, $project);

    return $skills;
}
```

**Priority order (lowest to highest):**
1. **Built-in skills** — Ships with CandyCrush (`src/Skills/BuiltIn/`)
2. **User skills** — User's personal skills (`~/.candy-crush/skills/`)
3. **Project skills** — Project-specific skills (`<projectRoot>/.candy-crush/skills/`)

**Why array_merge with string keys?** When duplicate keys exist, `array_merge()` keeps the last value. This means project skills with the same name override user skills, which override built-in skills.

### loadUserSkills() and loadProjectSkills()

```php
public function loadUserSkills(): array
{
    $dir = $_SERVER['HOME'] ?? '/root';
    $dir .= '/.candy-crush/skills';

    return $this->loadFromDirectory($dir);
}

public function loadProjectSkills(string $projectRoot): array
{
    $dir = rtrim($projectRoot, '/') . '/.candy-crush/skills';

    return $this->loadFromDirectory($dir);
}
```

**Path conventions:**
- User skills: `~/.candy-crush/skills/`
- Project skills: `<projectRoot>/.candy-crush/skills/`

### loadBuiltInSkills() with Reflection

```php
public function loadBuiltInSkills(): array
{
    $reflection = new \ReflectionClass($this);
    $dir = dirname($reflection->getFileName()) . '/BuiltIn';

    return $this->loadFromDirectory($dir);
}
```

Uses reflection to find the directory containing `SkillLoader.php`, then loads from a sibling `BuiltIn` directory. This keeps built-in skills co-located with the loader code.

### SkillRegistry Class

```php
final class SkillRegistry
{
    public function register(array $skills): void
    public function get(string $name): ?Skill
    public function all(): array
    public function findForPrompt(string $prompt): array
    public function getUserInvocable(): array
    public function getForPaths(array $paths): array
    public function disable(string $name): void
    public function enable(string $name): void
    public function isDisabled(string $name): bool
    public function disableMultiple(array $names): void
    public function names(): array
}
```

### register() and get()

Register skills and retrieve them by name:

```php
public function register(array $skills): void
{
    foreach ($skills as $name => $skill) {
        $this->skills[$name] = $skill;
    }
}

public function get(string $name): ?Skill
{
    if ($this->isDisabled($name)) {
        return null;
    }

    return $this->skills[$name] ?? null;
}
```

**Disabled check:** `get()` returns `null` for disabled skills even if they exist in the registry. This allows skills to be temporarily disabled without being removed.

### all() — Get All Enabled Skills

```php
public function all(): array
{
    return array_filter(
        $this->skills,
        fn($name) => !$this->isDisabled($name),
        ARRAY_FILTER_USE_KEY
    );
}
```

Uses `ARRAY_FILTER_USE_KEY` to filter by skill name, checking `isDisabled()` for each.

### findForPrompt() — Skill Matching

```php
public function findForPrompt(string $prompt): array
{
    $matches = [];

    foreach ($this->all() as $skill) {
        if ($skill->matchesPrompt($prompt)) {
            $matches[] = $skill;
        }
    }

    // Sort by relevance (exact matches first)
    usort($matches, function (Skill $a, Skill $b) use ($prompt) {
        $aMatches = substr_count(strtolower($a->description), strtolower($prompt));
        $bMatches = substr_count(strtolower($b->description), strtolower($prompt));
        return $bMatches <=> $aMatches;
    });

    return $matches;
}
```

**Sorting:** Uses substring count to rank — skills whose descriptions contain the prompt more frequently sort higher. The `<=>` (spaceship) operator provides a 3-way comparison result directly.

### getForPaths() — Path Pattern Matching

```php
public function getForPaths(array $paths): array
{
    $matches = [];

    foreach ($this->all() as $skill) {
        foreach ($skill->paths as $pattern) {
            foreach ($paths as $path) {
                if (fnmatch($pattern, $path)) {
                    $matches[] = $skill;
                    break 2;
                }
            }
        }
    }

    return $matches;
}
```

**fnmatch()** supports glob patterns:
- `*.php` — matches any PHP file in the current directory
- `**/*.php` — matches any PHP file recursively
- `include/**/*.tpl` — matches Smarty templates in include subdirectories

**break 2:** Exits both inner loops once a skill matches — prevents adding the same skill multiple times.

### getUserInvocable() — User-Facing Skills

```php
public function getUserInvocable(): array
{
    return array_values(array_filter(
        $this->all(),
        fn($skill) => $skill->userInvocable
    ));
}
```

Filters skills where `userInvocable` is `true`. Uses `array_values()` to re-index the returned array.

### Disable/Enable Pattern

```php
public function disable(string $name): void
{
    $this->disabledSkills[$name] = true;
}

public function enable(string $name): void
{
    unset($this->disabledSkills[$name]);
}

public function isDisabled(string $name): bool
{
    return isset($this->disabledSkills[$name]);
}

public function disableMultiple(array $names): void
{
    foreach ($names as $name) {
        $this->disable($name);
    }
}
```

Disabled skills use a separate `$disabledSkills` array with `true` values. The `isset()` check is O(1) for lookup.

### Usage Example

```php
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillRegistry;

// Load all skills with priority chain
$loader = new SkillLoader();
$skills = $loader->loadAll('/path/to/project');

// Register with registry
$registry = new SkillRegistry();
$registry->register($skills);

// Query skills
$skill = $registry->get('php-best-practices');                    // By name
$allEnabled = $registry->all();                                   // All enabled
$matching = $registry->findForPrompt('PHP code review');          // By prompt match
$invocable = $registry->getUserInvocable();                       // User-invokable only
$forPaths = $registry->getForPaths(['src/Api/Test.php']);         // By file path

// Disable/enable
$registry->disable('php-best-practices');
$registry->isDisabled('php-best-practices'); // true
$registry->enable('php-best-practices');
```

## Step 4.4: Skill Integration

Step 4.4 integrates skills into the `App` state and introduces `SkillManager` as the central orchestrator for skill loading and runtime management.

### SkillManager Class

`SkillManager` acts as the composition root for the skill system, coordinating `SkillLoader` and `SkillRegistry`:

```php
final class SkillManager
{
    public function __construct(
        private SkillLoader $loader,
        private SkillRegistry $registry,
    ) {}

    public function loadAll(string $projectRoot = '.'): void
    public function getSkillsForTask(string $task): array
    public function getSkillsForPaths(array $paths): array
    public function applyToApp(App $app, array $skillNames): App
    public function enable(App $app, string $skillName): App
    public function disable(App $app, string $skillName): App
    public function getUserInvocable(): array
    public function disableFromConfig(array $disabled): void
}
```

### Loading Skills with loadAll()

The `loadAll()` method discovers and registers skills from all three priority levels:

```php
use SugarCraft\Crush\Skills\SkillManager;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillRegistry;

// Create manager with loader and registry
$manager = new SkillManager(new SkillLoader(), new SkillRegistry());

// Load all skills (built-in + user + project)
$manager->loadAll('/path/to/project');
```

**Loading order:**
1. `SkillLoader::loadBuiltInSkills()` — Built-in skills from `src/Skills/BuiltIn/`
2. `SkillLoader::loadUserSkills()` — User skills from `~/.candy-crush/skills/`
3. `SkillLoader::loadProjectSkills($projectRoot)` — Project skills from `<projectRoot>/.candy-crush/skills/`

Later sources override earlier ones with same name via `array_merge()` string-key behavior.

### Applying Skills to App with applyToApp()

The `applyToApp()` method resolves skill names and attaches them to the `App` state:

```php
// After loading skills...
$manager->loadAll('/path/to/project');

// Apply selected skills to app (returns NEW App instance)
$app = $manager->applyToApp($app, ['php-best-practices', 'security-audit']);
```

**Note:** `applyToApp()` returns a new `App` instance — the original is unchanged (immutable pattern).

### Enabling and Disabling Skills at Runtime

Runtime skill management works through immutable state transitions:

```php
// Enable a skill (returns new App with skill added)
$app = $manager->enable($app, 'phpunit-master');

// Disable a skill (returns new App with skill removed)
$app = $manager->disable($app, 'php-best-practices');

// Check if skill is currently enabled
$isEnabled = in_array('phpunit-master', array_column($app->enabledSkills, 'name'));
```

**Why immutable?** Each enable/disable returns a new `App` instance, preserving the TEA pattern. The original `App` remains valid for rollback, undo, or concurrent session handling.

### App Skill State and Methods

The `App` class maintains two skill-related properties:

```php
final readonly class App
{
    public function __construct(
        // ...
        public readonly array $enabledSkills,       // Skill[] - currently active
        public readonly SkillRegistry $availableSkills, // All loadable skills
        // ...
    ) {}
}
```

#### applySkillsToSystemPrompt()

Combines enabled skills into the base system prompt for LLM context injection:

```php
$basePrompt = 'You are a helpful coding assistant.';

$enrichedPrompt = $app->applySkillsToSystemPrompt($basePrompt);
```

**Output structure:**
```
You are a helpful coding assistant.

## Skill: php-best-practices

[Skill content markdown]

## Skill: security-audit

[Skill content markdown]
```

Each enabled skill's `systemPromptContribution()` is appended in order, allowing the LLM to incorporate specialized instructions.

#### findSkillsForTask()

Queries available skills for those matching a task description:

```php
// Find skills relevant to a PHP testing task
$matchingSkills = $app->findSkillsForTask('write PHPUnit tests for my PHP code');

// Returns array of Skill objects, sorted by relevance
foreach ($matchingSkills as $skill) {
    echo $skill->name;  // 'phpunit-master'
}
```

**Matching algorithm:** Uses keyword overlap between skill `description` and the prompt. Skills whose descriptions contain prompt keywords more frequently rank higher.

### App with*() Skill Methods

Immutable setters for skill state:

```php
// Set enabled skills directly (array of Skill objects)
$app = $app->withEnabledSkills([$skill1, $skill2]);

// Set available skills registry
$app = $app->withAvailableSkills($registry);
```

### SkillManager as Composition Root

`SkillManager` centralizes skill lifecycle management:

```
┌─────────────────────────────────────────────────────────────────────┐
│  SkillManager                                                        │
│  ├── SkillLoader (discovery)                                         │
│  │   ├── loadBuiltInSkills()                                         │
│  │   ├── loadUserSkills()                                            │
│  │   └── loadProjectSkills()                                         │
│  └── SkillRegistry (storage + query)                                 │
│      ├── register()                                                  │
│      ├── get() / all()                                               │
│      ├── findForPrompt() / getForPaths()                             │
│      └── disable() / enable()                                        │
└─────────────────────────────────────────────────────────────────────┘
```

**Benefits:**
- Single entry point for all skill operations
- Loader and registry are internal implementation details
- Consumers interact only with `SkillManager` interface
- Easy to swap implementations (e.g., mock for testing)

### Usage Example

Complete skill workflow from loading to application:

```php
use SugarCraft\Crush\Skills\SkillManager;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\App\App;

// 1. Create skill infrastructure
$loader = new SkillLoader();
$registry = new SkillRegistry();
$manager = new SkillManager($loader, $registry);

// 2. Load all skills from filesystem
$manager->loadAll('/path/to/project');

// 3. Populate App with available skills registry
$app = $app->withAvailableSkills($registry);

// 4. Enable specific skills for this session
$app = $manager->applyToApp($app, ['php-best-practices', 'security-audit']);

// 5. Build system prompt with skill contributions
$systemPrompt = $app->applySkillsToSystemPrompt('You are a helpful assistant.');

// 6. Find additional skills for a specific task
$taskSkills = $app->findSkillsForTask('review my PHP code for SQL injection');
foreach ($taskSkills as $skill) {
    $app = $manager->enable($app, $skill->name);
}

// 7. Runtime disable if needed
$app = $manager->disable($app, 'security-audit');
```

### Integration with Provider Factory

`SkillManager` works with `ProviderFactory` for full application bootstrap:

```php
use SugarCraft\Crush\Providers\ProviderFactory;
use SugarCraft\Crush\Skills\SkillManager;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillRegistry;

// Create provider from config
$factory = new ProviderFactory();
$provider = $factory->create(['type' => 'openai', 'apiKey' => '...']);

// Create app with provider
$app = App::new($provider, 'gpt-4o');

// Load skills
$skillManager = new SkillManager(new SkillLoader(), new SkillRegistry());
$skillManager->loadAll(getcwd());

// Attach available skills
$app = $app->withAvailableSkills($skillManager->getUserInvocable() ? $registry : new SkillRegistry());

// Apply configured skills from config file
$config = json_decode(file_get_contents('candy-crush.json'), true);
if (!empty($config['enabledSkills'])) {
    $app = $skillManager->applyToApp($app, $config['enabledSkills']);
}
```

## Step 2.5: Keyboard Handling System

Step 2.5 implements a centralized keyboard handling system that routes keypresses to appropriate handlers and dispatches commands through the TEA model. The system supports navigation, menu shortcuts, and Ctrl key combinations.

### KeyboardHandler Class

The `KeyboardHandler` is the central dispatcher for all keyboard input:

```php
final class KeyboardHandler
{
    /**
     * Process a keypress and return updated App and optional command.
     *
     * @return array{0: App, 1: ?KeyCmd} [newApp, command]
     */
    public function handle(string $key, App $app): array
}
```

The handler routes input through a priority chain:

1. **Tab** — Cycle pane focus
2. **Arrow/Vim keys** — Navigation within panes
3. **Menu shortcuts** — When a menu is active
4. **Escape** — Close menu and return to Chat
5. **Ctrl+key combinations** — Application commands

### KeyCmd Interface

All keyboard commands implement the `KeyCmd` marker interface:

```php
interface KeyCmd
{
}
```

This empty interface serves as a type marker for the TEA command system. Commands are processed by the runtime after the update cycle completes.

### Available Commands

| Command | Ctrl Key | Purpose |
|---------|----------|---------|
| `NewSessionCmd` | `Ctrl+N` | Start a new chat session |
| `CancelCmd` | `Ctrl+C` | Cancel current operation |
| `GroupInputCmd` | `Ctrl+G` | Enable multi-line input mode |
| `CommandPaletteCmd` | `Ctrl+K` | Open command palette |
| `SourceSkillCmd` | `Ctrl+S` | Apply/source a skill |
| `ProviderSelectCmd` | `Ctrl+P` | Open provider selection |
| `SelectPaneMsg` | `Ctrl+A` | Switch to Agents pane |
| `SelectPaneMsg` | `Ctrl+,` | Switch to Settings pane |

### Complete Key Mapping

| Key(s) | Action | Result |
|--------|--------|--------|
| `Tab` | Cycle pane | `App::withPane(Pane::next())` |
| `↑` / `k` | Navigate up | (delegated to pane) |
| `↓` / `j` | Navigate down | (delegated to pane) |
| `←` / `h` | Navigate left | (delegated to pane) |
| `→` / `l` | Navigate right | (delegated to pane) |
| `Escape` | Close menu | `MenuBar::closeMenu()`, return to Chat |
| `Ctrl+N` | New session | `NewSessionCmd` |
| `Ctrl+C` | Cancel | `CancelCmd` |
| `Ctrl+G` | Group input | `GroupInputCmd` |
| `Ctrl+K` | Command palette | `CommandPaletteCmd` |
| `Ctrl+S` | Source skill | `SourceSkillCmd` |
| `Ctrl+A` | Agents pane | `SelectPaneMsg(Agents)` |
| `Ctrl+P` | Provider select | `ProviderSelectCmd` |
| `Ctrl+,` | Settings pane | `SelectPaneMsg(Settings)` |

### Vim Key Bindings

Navigation supports both arrow keys and vim motion keys:

```php
if (in_array($key, ['up', 'k', 'down', 'j', 'left', 'h', 'right', 'l'], true)) {
    return $this->handleNavigation($key, $app);
}
```

This follows the established convention in the project (see `candy-pty/src/Lang.php`) and accommodates power users familiar with modal editing.

### Ctrl+Key Handling

Ctrl combinations are extracted and dispatched via a match expression:

```php
if (str_starts_with($key, 'ctrl+')) {
    return $this->handleCtrl(substr($key, 5), $app);
}

private function handleCtrl(string $key, App $app): array
{
    return match ($key) {
        'n' => [$app, new NewSessionCmd()],
        'c' => [$app, new CancelCmd()],
        // ...
    };
}
```

### TEA Model Integration

The keyboard handler integrates with the TEA pattern as the input layer:

```
┌─────────────────────────────────────────────────────────────────────┐
│  Terminal Input (keypress)                                          │
└─────────────────────────────────┬───────────────────────────────────┘
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│  KeyboardHandler::handle(key, app)                                   │
│  - Tab → pane cycling                                                │
│  - Arrows/Vim → navigation                                           │
│  - Ctrl+* → command dispatch                                         │
│  - Escape → menu close                                               │
└─────────────────────────────────┬───────────────────────────────────┘
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Returns [newApp, ?KeyCmd]                                           │
│  - newApp: potentially updated application state (e.g., pane change) │
│  - KeyCmd: command to execute (or null for pure state changes)      │
└─────────────────────────────────┬───────────────────────────────────┘
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Runtime processes KeyCmd                                             │
│  - NewSessionCmd → clears messages, resets session                   │
│  - CancelCmd → aborts in-progress operation                         │
│  - GroupInputCmd → enables multi-line input mode                     │
│  - etc.                                                              │
└─────────────────────────────────────────────────────────────────────┘
```

### Command Processing Pattern

Commands follow the marker interface pattern:

```php
final readonly class NewSessionCmd implements KeyCmd
{
}
```

Each command is a `final readonly` class implementing `KeyCmd`. The empty interface is intentional — commands carry no data (the action is the class type itself). This provides:

- **Type safety**: Only `KeyCmd` instances can be returned from `KeyboardHandler`
- **Extensibility**: Add new commands by creating new classes
- **Pattern matching**: `match` on command class for dispatch

### Menu Bar Shortcuts

When a menu is active, keypresses are first routed to `MenuBar::handleKey()`:

```php
$currentMenu = MenuBar::getActiveMenu();
if ($currentMenu > 0) {
    $result = MenuBar::handleKey($key, $currentMenu);
    if ($result[1] !== null) {
        return [$app, $result[1]];
    }
}
```

If the menu handler returns a command, it is propagated to the caller. If the menu handler returns `null` (unhandled key or navigation that didn't produce a menu selection), processing continues.

### Escape Handling

Escape closes any open menu and returns focus to the Chat pane:

```php
if ($key === 'escape') {
    MenuBar::closeMenu();
    return [$app->withPane(Pane::Chat), null];
}
```

This provides a consistent way to dismiss menus and return to the primary interaction mode.

## Step 2.4: Enhanced Menu System

Step 2.4 implements an interactive menu system with full keyboard navigation, integrated with the TEA model for state management.

### Menu Structure

The menu bar provides access to seven application areas:

| Menu | Items |
|------|-------|
| **File** | New Session, Open Session, Save Transcript, Export Chat, ---, Preferences, Quit |
| **Edit** | Copy, Paste, Select All, Clear History |
| **Session** | Continue, New Session, Session History, Attach Context |
| **Provider** | OpenAI, Anthropic, Claude Code, SGLANG, Bedrock, Vertex, ---, Custom... |
| **Skills** | Browse Skills, Enable Skill..., Manage Built-in Skills |
| **Agents** | Create Agent, Manage Agents, Active Agents |
| **Help** | Keyboard Shortcuts, Documentation, About |

The `---` separator denotes menu dividers for logical grouping.

### MenuBar Component

The `MenuBar` class provides both rendering and keyboard handling:

```php
final class MenuBar
{
    private const MENUS = [
        'File' => ['New Session', 'Open Session', ...],
        'Edit' => ['Copy', 'Paste', ...],
        // ...
    ];

    public static function render(App $a): string
    {
        // Renders menu names with active menu highlighted in cyan
        // Active menu: Color::hex('#00ffaa')
        // Inactive menu: Color::hex('#fde68a')
    }

    public static function handleKey(string $key, int $currentMenu): array
    {
        // Returns [newMenuIndex, ?MenuSelectedMsg]
    }
}
```

### Keyboard Handling Flow

The menu system responds to these keys:

| Key | Action | Result |
|-----|--------|--------|
| `←` / `h` | Cycle left | Move to previous menu |
| `→` / `l` | Cycle right | Move to next menu |
| `Enter` / `o` | Select | Dispatch `MenuSelectedMsg` |
| `Escape` / `q` | Close menu | Reset to no active menu |

```php
public static function handleKey(string $key, int $currentMenu): array
{
    return match ($key) {
        'left', 'h' => [self::cycleMenu($currentMenu, -1), null],
        'right', 'l' => [self::cycleMenu($currentMenu, 1), null],
        'enter', 'o' => self::selectMenuItem($currentMenu),
        'escape', 'q' => [self::closeMenu(), null],
        default => [$currentMenu, null],
    };
}
```

**Key bindings follow vim conventions** (`h`/`l` for left/right) alongside arrow keys, accommodating power users familiar with modal editing.

### Menu Cycling

Menu navigation wraps around — going left from the first menu lands on the last, and vice versa:

```php
private static function cycleMenu(int $currentMenu, int $direction): int
{
    $count = count(self::MENUS);
    $new = $currentMenu + $direction;

    if ($new < 1) {
        $new = $count;  // Wrap to last
    }
    if ($new > $count) {
        $new = 1;       // Wrap to first
    }

    return $new;
}
```

### MenuSelectedMsg Integration

Selecting a menu item produces a `MenuSelectedMsg` that flows into the TEA update cycle:

```php
final readonly class MenuSelectedMsg
{
    public function __construct(
        public string $menu,
        public string $item,
    ) {}
}
```

The `App::update()` method handles this message:

```php
public function update(Msg $msg): array
{
    return match (true) {
        // ...
        $msg instanceof MenuSelectedMsg => $this->handleMenuSelection($msg),
        // ...
    };
}
```

### TEA Model Integration

The menu system integrates with the TEA pattern as follows:

```
┌─────────────────────────────────────────────────────────────┐
│  User presses →/l                                           │
└─────────────────────┬───────────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  MenuBar::handleKey('right', 1) → [2, null]                 │
│  Menu index updates, no message dispatched                  │
└─────────────────────┬───────────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  Renderer re-renders with new active menu highlighted       │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  User presses Enter on "File > New Session"                 │
└─────────────────────┬───────────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  MenuBar::handleKey('enter', 1) → [1, MenuSelectedMsg]       │
│  MenuSelectedMsg{message: 'File', item: 'New Session'}      │
└─────────────────────┬───────────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  App::update(MenuSelectedMsg) → handleMenuSelection()       │
│  Returns [newApp, ?Cmd] for side effects                    │
└─────────────────────────────────────────────────────────────┘
```

### Active Menu State

The active menu index is stored as a static property:

```php
private static int $activeMenu = 0;
```

- `0` means no menu is active (menu bar is in "closed" state)
- `1` through `7` correspond to the seven menus

When a menu is active, its name is rendered in cyan (`#00ffaa`); inactive menus render in amber (`#fde68a`). The `closeMenu()` method resets the index to `0`, dismissing any open menu.

### Design Rationale

**Why static state for active menu?**
Unlike `App` (which holds application state), the active menu is a transient UI state that doesn't affect business logic. Using a static property avoids the overhead of threading menu state through the TEA model for what is essentially a presentational concern.

**Why return array from handleKey?**
The `[newMenuIndex, ?MenuSelectedMsg]` return type cleanly separates two concerns:
- Menu index update (UI state change)
- Message dispatch (business logic trigger)

This matches the TEA pattern where `update()` returns `[newModel, command]`.

## TUI Renderer Architecture

Step 2.2 implements a **stateless, composable TUI rendering framework** that assembles multiple panes into a full terminal interface. The renderer follows a pure function pattern — given the same `App` state, it always produces the same output bytes.

### Renderer Class

The `Renderer` class is the composition layer that orchestrates pane rendering:

```php
final class Renderer
{
    public static function render(App $a): string
    {
        $size = self::getTerminalSize();
        $cols = $size['cols'];
        $rows = $size['rows'];

        // Build panes based on focused pane
        $menuBar = MenuBar::render($a);
        $chatPane = ChatPane::render($a, $cols, $rows);
        $inputPane = InputPane::render($a, $cols);
        $statusBar = self::statusBar($a);

        // Side panes
        $leftPane = self::leftSidebar($a, $cols, $rows);
        $rightPane = self::rightSidebar($a, $cols, $rows);

        // Compose: top bar + left + chat + right + input + status
        $top = $menuBar;
        $middle = Layout::joinHorizontal(Position::TOP, $leftPane, $chatPane, $rightPane);
        $bottom = $inputPane . "\n" . $statusBar;

        return $top . "\n" . $middle . "\n" . $bottom;
    }
}
```

### Layout Composition

The renderer uses `sugar-sprinkles` layout primitives to compose panes:

- **`Layout::joinHorizontal(Position::TOP, ...$panes)`** — Arranges panes horizontally, aligning them to the top
- **`Position::TOP`** — Alignment constant from `SugarCraft\Sprinkles\Position`

### Multi-Pane Layout

```
┌──────────────────────────────────────────────────────────────────────┐
│  Menu: [Chat] [Files] [Tools] [Skills] [Agents]   Currently: Chat    │
├────────────────────┬─────────────────────────────────────────────────┤
│                    │                                                 │
│   Files / Context  │            Chat / Messages                      │
│   (file tree,      │   (scrollable message history with             │
│    selected files) │    assistant/user/tool messages)               │
│                    │                                                 │
├────────────────────┼─────────────────────────────────────────────────┤
│   Tools / History  │            Input                               │
│   (tool calls,      │   (multi-line input with markdown preview)    │
│    recent actions) │                                                 │
├────────────────────┴─────────────────────────────────────────────────┤
│  openai | claude-sonnet-4-6 | [Tab] Switch Pane                      │
└──────────────────────────────────────────────────────────────────────┘
```

### Pane Components

Each pane is a standalone static renderer:

| Component | Purpose |
|-----------|---------|
| `MenuBar` | Top menu showing pane tabs and current selection |
| `ChatPane` | Main conversation area with message history |
| `InputPane` | User input area with border styling |
| `FilesPane` | Left sidebar showing loaded context files |
| `ToolsPane` | Left sidebar showing available tools |
| `SkillsPane` | Right sidebar showing enabled skills |
| `AgentsPane` | Right sidebar showing active agents |

### Sidebar Delegation

The renderer delegates sidebar rendering based on the focused pane:

```php
private static function leftSidebar(App $a, int $cols, int $rows): string
{
    $width = (int) floor($cols / 4);
    $width = max(20, $width);

    if ($a->pane === Pane::Files) {
        return FilesPane::render($a, $width, $rows);
    }

    if ($a->pane === Pane::Tools) {
        return ToolsPane::render($a, $width, $rows);
    }

    return FilesPane::render($a, $width, $rows);
}
```

This pattern allows left/right sidebars to show contextually relevant information without needing separate layout regions — the same physical area adapts its content based on focus.

### Terminal Size Detection

The renderer caches terminal dimensions to avoid repeated syscalls:

```php
private static function getTerminalSize(): array
{
    if (self::$terminalSize !== null) {
        return self::$terminalSize;
    }

    try {
        $size = (new Tty(STDOUT))->size();
        if ($size['cols'] > 0 && $size['rows'] > 0) {
            self::$terminalSize = ['rows' => $size['rows'], 'cols' => $size['cols']];
            return self::$terminalSize;
        }
    } catch (\Throwable) {}

    self::$terminalSize = ['rows' => 60, 'cols' => 200];
    return self::$terminalSize;
}
```

**Fallback**: Returns 200x60 when size detection fails (redirected output, non-TTY environment).

### Status Bar

The status bar displays provider context and navigation hints:

```php
private static function statusBar(App $a): string
{
    $provider = Style::new()->foreground(Color::hex('#9ece6a'))->render($a->provider->name());
    $model = Style::new()->foreground(Color::hex('#e0af68'))->render($a->model);

    $status = $a->error
        ? Style::new()->foreground(Color::hex('#f7768e'))->bold()->render('error: ' . $a->error)
        : ($a->status
            ? Style::new()->foreground(Color::hex('#9ece6a'))->render($a->status)
            : '');

    return " $provider | $model | [Tab] Switch Pane | $status";
}
```

The status bar shows:
- Provider name (green)
- Model name (amber)
- Tab navigation hint
- Error or status message (green on success, red+bold on error)

### Pane Interface Contract

All pane components follow the same static render contract:

```php
final class SomePane
{
    public static function render(App $a, int $width, int $rows): string
    {
        // Build lines array
        // Pad to fill height
        // Return composed string
    }
}
```

Parameters vary by pane role:
- **Main panes** (`ChatPane`, `InputPane`): Take `$cols, $rows` for dimension calculations
- **Sidebar panes** (`FilesPane`, `ToolsPane`, `SkillsPane`, `AgentsPane`): Take `$width, $rows` for constrained rendering
- **MenuBar**: Takes only `App` — dimensions handled internally

### Static Stateful Rendering

While the renderer is a **static** class (no instance state), it maintains **cached terminal size** as a static property:

```php
private static ?array $terminalSize = null;
```

This caching is intentional — it avoids repeated `Tty::size()` calls during a single render cycle while maintaining the pure function contract within a session. The cache is resettable via `resetSizeCache()` for testing.

### Integration with TEA Model

The renderer receives the complete `App` state as input:

```php
public static function render(App $a): string
```

This means:
1. **No internal state** — rendering is purely derivable from `App`
2. **Testable** — pass a known `App` and assert on the output string
3. **Composable** — each pane accesses only the `App` fields it needs

## TUI Pane System

CandyCrush implements a multi-pane terminal UI for navigating between different functional areas. The `Pane` enum defines all available panes and their navigation order.

### Pane Enum

```php
enum Pane: string
{
    case Chat     = 'chat';
    case Input    = 'input';
    case Skills   = 'skills';
    case Agents   = 'agents';
    case Files    = 'files';
    case Tools    = 'tools';
    case Settings = 'settings';
    case Help     = 'help';
}
```

### Pane Navigation

Each pane knows its successor in the navigation cycle via `next()`:

```
Chat → Input → Files → Tools → Skills → Agents → Settings → Help → (back to Chat)
```

The `label()` method returns a human-readable name for display:

```php
echo Pane::Chat->label();   // 'Chat'
echo Pane::Skills->label(); // 'Skills'
```

### Integration with TEA Model

The `App` state holds the currently focused pane:

```php
final readonly class App
{
    public function __construct(
        // ...
        public readonly Pane $pane,
        // ...
    ) {}
}
```

Pane changes dispatch a `SelectPaneMsg` message:

```php
final readonly class SelectPaneMsg implements Msg
{
    public function __construct(public Pane $pane) {}
}
```

The `update()` method handles pane transitions:

```php
public function update(Msg $msg): array
{
    return match (true) {
        // ...
        $msg instanceof SelectPaneMsg => [$this->withPane($msg->pane)->withError(null), null],
        // ...
    };
}
```

### Multi-Pane Layout

The TUI is organized into regions:

```
┌──────────────────────────────────────────────────────────────────────┐
│  Menu: [File] [Edit] [Session] [Provider] [Skills] [Agents] [Help] │
├────────────────────┬─────────────────────────────────────────────────┤
│                    │                                                 │
│   Files / Context  │            Chat / Messages                      │
│   (file tree,      │   (scrollable message history with             │
│    selected files) │    assistant/user/tool messages)               │
│                    │                                                 │
├────────────────────┼─────────────────────────────────────────────────┤
│   Tools / History  │            Input                               │
│   (tool calls,      │   (multi-line input with markdown preview)    │
│    recent actions) │                                                 │
├────────────────────┴─────────────────────────────────────────────────┤
│  Provider: Claude Code │ Model: claude-sonnet-4-6 │ Tokens: 1,234   │
│  Skills: php-best-practices │ [Tab] Switch Pane                     │
└──────────────────────────────────────────────────────────────────────┘
```

**Tab** cycles focus through panes in order. The status bar shows current context and hints for navigation.

## Step 2.3: TUI Component Classes

Step 2.3 implemented full `Tui\Components` classes for all seven pane types. Each component is a `final static` renderer with a consistent interface and unified pane focus highlighting.

### Component Overview

| Component | File | Purpose |
|-----------|------|---------|
| `MenuBar` | `MenuBar.php` | Top menu bar with application menus |
| `ChatPane` | `ChatPane.php` | Main conversation area with message history |
| `InputPane` | `InputPane.php` | User input area at bottom |
| `FilesPane` | `FilesPane.php` | Left sidebar showing context files |
| `ToolsPane` | `ToolsPane.php` | Left sidebar showing tool history |
| `SkillsPane` | `SkillsPane.php` | Right sidebar showing enabled skills |
| `AgentsPane` | `AgentsPane.php` | Right sidebar showing active agents |

### Component Interface Contract

All components follow the same static render signature:

```php
final class SomePane
{
    public static function render(App $a, int ...$dims): string
    {
        // Build styled content
        // Apply conditional border color based on focus
        // Return rendered string
    }
}
```

Parameter conventions:
- **Main panes** (`ChatPane`, `InputPane`): Take `$cols` and optionally `$rows` for dimension calculations
- **Sidebar panes** (`FilesPane`, `ToolsPane`, `SkillsPane`, `AgentsPane`): Take `$width` and `$rows` for constrained rendering
- **MenuBar**: Takes only `App` — dimensions derived internally

### Pane Focus Highlighting System

Each component implements a consistent visual feedback pattern for showing which pane has keyboard focus. The border color changes based on whether the pane matches the current focused pane in `App`:

```php
$st = $a->pane === \SugarCraft\Crush\Tui\Pane::SomePane
    ? $st->borderForeground(Color::hex('#00ffaa'))  // Cyan — focused
    : $st->borderForeground(Color::hex('#ff66aa')); // Pink — unfocused
```

**Color semantics**:
- `#00ffaa` (cyan-green): Active pane with keyboard focus
- `#ff66aa` (pink): Inactive pane

This two-color system provides immediate visual feedback about which pane will respond to keyboard input, following the same pattern across all seven components.

### ChatPane

The main conversation area renders message history with role-based color coding:

```php
private static function formatMessage(Message $msg): string
{
    $role = Style::new()->bold()->foreground(Color::hex('#fde68a'))->render($msg->role() . ':');
    $content = Style::new()->foreground(Color::hex('#c5b6dd'))->render($msg->content());
    return "$role $content";
}
```

**Content behavior**:
- Empty state: Shows "Welcome to CandyCrush! Start typing to chat..."
- Message list: Renders each message with yellow role prefix and lavender content
- Width: Dynamically calculated as `max(40, $cols - 80)` to fill remaining space after sidebars

### InputPane

The input area provides a placeholder prompt and adapts width to terminal size:

```php
$placeholder = Style::new()->foreground(Color::hex('#7d6e98'))
    ->render('Type your message... (Enter to send, Ctrl+G for group)');

// Width adapts to terminal, minimum 20 columns
$width = max(20, $cols - 6);
```

### Sidebar Panes (FilesPane, ToolsPane, SkillsPane, AgentsPane)

Sidebar panes share a common structure:
1. Fetch data from `App` state (`$a->contextFiles`, `$a->enabledSkills`, etc.)
2. Display empty state message when data is absent
3. Render list items with consistent styling when data exists
4. Apply focus-highlighted border

**FilesPane** uses emoji file icons:
```php
$lines[] = Style::new()
    ->foreground(Color::hex('#c5b6dd'))
    ->render('📄 ' . basename($file));
```

**SkillsPane** uses bullet markers:
```php
$lines[] = Style::new()
    ->foreground(Color::hex('#c5b6dd'))
    ->render('• ' . $skill);
```

### MenuBar

The menu bar renders a static list of application menus without interactivity (Tab navigation handles pane switching):

```php
$menus = [
    'File' => 'New,Open,Save,Export,Quit',
    'Edit' => 'Copy,Paste,Clear',
    'Session' => 'Continue,New,History',
    'Provider' => 'OpenAI,Claude Code,SGLANG,Bedrock',
    'Skills' => 'Browse,Enable',
    'Agents' => 'Create,Manage',
    'Help' => 'Shortcuts,Docs,About',
];
```

Menu items are rendered in amber (`#fde68a`) bold text, separated by three spaces.

### Common Styling Pattern

All panes use `sugar-sprinkles` primitives for consistent border and padding behavior:

```php
$st = Style::new()
    ->border(Border::rounded()->withTitle(' pane-name '))
    ->padding(0, 1)
    ->width($width);

$st = $a->pane === Pane::X
    ? $st->borderForeground(Color::hex('#00ffaa'))
    : $st->borderForeground(Color::hex('#ff66aa'));

return $st->render($body);
```

The `Border::rounded()->withTitle(' title ')` pattern creates a rounded border with centered title, giving each pane a distinct visual identity.

## AppBuilder Fluent Builder

While `App` uses immutable `with*()` methods for state transitions during the TEA
update cycle, **AppBuilder** provides a fluent interface for constructing `App` instances
from scratch. The two patterns serve different purposes:

| Aspect | `App::with*()` | `AppBuilder` |
|--------|----------------|--------------|
| Purpose | Modify existing state | Construct new instances |
| Context | TEA update cycle | Initial object creation |
| Required Fields | N/A (existing state) | Enforced at `build()` |
| Defaults | N/A | Sensible defaults provided |

### Why Both Patterns?

The TEA pattern requires immutable state transitions — each `update()` call produces
a new `App` instance. The `with*()` methods are perfect for this because they operate
on an already-valid `App` instance.

However, constructing an initial `App` requires gathering all required fields (especially
the `provider`) and optional configurations. AppBuilder provides a cleaner API for this
bootstrapping phase:

```php
// Manual construction — verbose with many arguments
$app = new App(
    provider: $provider,
    model: 'claude-sonnet-4-6',
    messages: [],
    tools: [],
    pane: Pane::Chat,
    error: null,
    status: null,
    sessionId: null,
    contextFiles: [],
    enabledSkills: [],
    activeHooks: [],
);

// AppBuilder — readable, progressive configuration
$app = (new AppBuilder())
    ->withProvider($provider)
    ->withModel('claude-sonnet-4-6')
    ->withPane(Pane::Chat)
    ->withSessionId('abc123')
    ->build();
```

### Fluent Interface

AppBuilder follows the **fluent builder pattern** — each `with*()` method returns
`self` (the same builder instance) after modifying internal state:

```php
public function withModel(string $model): self
{
    $clone = clone $this;
    $clone->model = $model;
    return $clone;
}
```

This enables method chaining:

```php
$app = (new AppBuilder())
    ->withProvider($openAIProvider)
    ->withModel('gpt-4')
    ->withTools([new Read(), new Bash()])
    ->withContextFiles(['/path/to/context.md'])
    ->build();
```

### Validation at Build Time

The `build()` method enforces required fields:

```php
public function build(): App
{
    if ($this->provider === null) {
        throw new \LogicException('provider is required');
    }

    return new App(
        provider: $this->provider,
        // ... all other fields
    );
}
```

This defers validation until the consumer is done configuring the builder, providing
a better user experience than validating on each `with*()` call.

### Default Values

AppBuilder provides sensible defaults that reduce boilerplate:

- `model`: `'claude-sonnet-4-6'`
- `messages`: `[]`
- `tools`: `[]`
- `pane`: `Pane::Chat`
- All nullable fields default to `null`

### Key Insight: Cloning for Immutability

Like `App::mutate()`, AppBuilder uses `clone $this` to ensure each `with*()` call
returns a new builder instance. The original builder remains unchanged:

```php
$builder = new AppBuilder();
$builder2 = $builder->withModel('gpt-4');

echo $builder->model;  // 'claude-sonnet-4-6' — original unchanged
echo $builder2->model; // 'gpt-4'
```

This immutability matters because builders may be shared (e.g., passed to multiple
threads or stored for later use).

## App State Class (TEA Pattern)

CandyCrush implements **The Elm Architecture (TEA)** — a unidirectional data flow pattern
originating from the Elm language. The architecture consists of three core concepts:

1. **Model** — The single source of truth for application state
2. **Update** — A function that transforms the model based on messages
3. **Msg (Messages)** — Immutable descriptions of events that trigger state changes

### The App State Model

The `App` class holds all application state as `readonly` properties:

```php
final class App
{
    public function __construct(
        public readonly ProviderInterface $provider,
        public readonly string $model,
        public readonly array $messages,
        public readonly array $tools,
        public readonly Pane $pane,
        public readonly ?string $error,
        public readonly ?string $status,
        public readonly ?string $sessionId,
        public readonly array $contextFiles,
        public readonly array $enabledSkills,
        public readonly array $activeHooks,
    ) {}
}
```

### Immutable Updates via with*() Builders

State changes return a **new** `App` instance rather than mutating existing state.
This approach provides:

- **Predictability**: No hidden state changes — every update is explicit
- **Debuggability**: State history is preserved; trace any state to its origin
- **Testability**: Each with*() method is independently testable

```php
// Create initial state
$app = App::new($provider, 'gpt-4');

// with*() methods return NEW instances — original unchanged
$app2 = $app->withSessionId('abc123');
$app3 = $app2->withStatus('Processing...');

echo $app->sessionId;  // null — original unchanged
echo $app2->sessionId; // 'abc123'
echo $app3->status;     // 'Processing...'
```

### The update() Method

The `update(Msg $msg): array{self, ?Cmd}` method is the core of the TEA pattern.
It receives a message, transforms the model, and returns a command for side-effects:

```php
public function update(Msg $msg): array
{
    return match (true) {
        $msg instanceof UserInputMsg => $this->handleUserInput($msg),
        $msg instanceof SelectPaneMsg => [$this->withPane($msg->pane)->withError(null), null],
        $msg instanceof ToolResultMsg => $this->handleToolResult($msg),
        $msg instanceof ErrorMsg => [$this->withError($msg->message), null],
        $msg instanceof StatusMsg => [$this->withStatus($msg->message), null],
        default => [$this, null],
    };
}
```

**Returns**: A tuple of `[newApp, command]` where:
- `newApp` is the updated state (or same instance if no changes)
- `command` is a `Cmd` to execute (or `null` for pure state-only updates)

### Message Types (Msg)

Messages are internal immutable DTOs describing user actions or system events:

```php
interface Msg {}  // marker interface

final readonly class UserInputMsg implements Msg
{
    public function __construct(public string $content) {}
}

final readonly class SelectPaneMsg implements Msg
{
    public function __construct(public Pane $pane) {}
}

final readonly class ToolResultMsg implements Msg
{
    public function __construct(
        public string $toolCallId,
        public string $content,
        public bool $isError = false,
    ) {}
}

final readonly class ErrorMsg implements Msg
{
    public function __construct(public string $message) {}
}

final readonly class StatusMsg implements Msg
{
    public function __construct(public string $message) {}
}
```

### Command Types (Cmd)

Commands represent **side-effects** that the runtime must execute:

```php
interface Cmd {}  // marker interface

final readonly class RunCompletionCmd implements Cmd
{
    public function __construct(public Message $userMessage) {}
}

final readonly class CallToolCmd implements Cmd
{
    public function __construct(public string $toolName, public array $args) {}
}
```

The runtime loop processes commands after each update, executing them and
dispatching the result back as a new message.

### Flow Summary

```
┌─────────────────────────────────────────────────────────┐
│  User Input → UserInputMsg                              │
└─────────────────────┬───────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────┐
│  App::update(UserInputMsg) → [newApp, RunCompletionCmd] │
└─────────────────────┬───────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────┐
│  Runtime executes RunCompletionCmd                       │
│  → AI Provider → AI Response                            │
└─────────────────────┬───────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────┐
│  Response → AssistantMessage → ToolResultMsg            │
└─────────────────────┬───────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────┐
│  App::update(ToolResultMsg) → [newApp, ?Cmd]             │
└─────────────────────────────────────────────────────────┘
```

This unidirectional flow ensures that state changes are always traceable
and the application remains predictable even under concurrent operations.

## Provider Interface

The `ProviderInterface` defines the contract for all AI provider implementations.
Each provider translates CandyCrush requests into provider-specific API calls.

### Interface Contract

```php
interface ProviderInterface
{
    public function name(): string;
    public function supportsStreaming(): bool;
    public function supportsFunctionCalling(): bool;
    public function supportsVision(): bool;
    public function supportsJsonSchema(): bool;
    public function contextWindow(): int;
    public function costPer1kTokens(string $model, 'input'|'output'): float;
    public function complete(CompleteRequest $request): CompleteResponse;
    public function completeStream(CompleteRequest $request): \Generator;
    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse;
}
```

### Request/Response DTOs

```php
// Immutable request for chat completions
final readonly class CompleteRequest
{
    public function __construct(
        public string $model,
        public array $messages,
        public ?array $tools = null,
        public ?string $systemPrompt = null,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public ?string $jsonSchema = null,
    ) {}
}

// Immutable response from chat completions
final readonly class CompleteResponse
{
    public function __construct(
        public string $content,
        public ?string $reasoning = null,
        public ?array $toolCalls = null,
        public int $tokensUsed = 0,
        public float $costUsd = 0.0,
    ) {}
}

// Immutable request for embeddings
final readonly class EmbeddingsRequest
{
    public function __construct(
        public string $model,
        public array $input,
    ) {}
}

// Immutable response from embeddings
final readonly class EmbeddingsResponse
{
    public function __construct(
        public array $embeddings,
    ) {}
}
```

## Message Classes

All messages implement the `Message` interface:

```php
interface Message
{
    public function role(): string;
    public function content(): string;
    public function toArray(): array;
}
```

### Available Implementations

| Class | Role | Purpose |
|-------|------|---------|
| `UserMessage` | `user` | User input messages |
| `AssistantMessage` | `assistant` | AI responses with optional tool calls |
| `SystemMessage` | `system` | System prompts |
| `ToolResultMessage` | `tool` | Results from tool executions |

### Usage Example

```php
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;

// Build a conversation
$messages = [
    new SystemMessage('You are a PHP expert.'),
    new UserMessage('How do I implement PSR-12?'),
    new AssistantMessage('PSR-12 requires...'),
    new ToolResultMessage(
        toolCallId: 'tool_123',
        content: 'Analysis complete.',
        isError: false,
    ),
];

// Serialize for API calls
$array = array_map(fn(Message $m) => $m->toArray(), $messages);
```

### Design Notes

- **Immutable**: All message classes are `final readonly` — properties set via constructor, no setters.
- **Serializable**: `toArray()` returns provider-compatible array structure for OpenAI/Anthropic APIs.
- **Extensible**: Add new message types by implementing `Message` interface.

## Tool Interface

The `Tool` interface defines the contract for all executable tools available to the AI.
Tools provide capabilities like file operations, command execution, and web fetching.

### Interface Contract

```php
interface Tool
{
    public function name(): string;
    public function description(): string;
    public function inputSchema(): array;
    public function execute(array $args): ToolResult;
}
```

### name()

Returns the tool identifier, used by the AI to select which tool to invoke.

### description()

Returns a human-readable description of the tool's purpose and behavior.
The AI uses this to determine when to recommend the tool.

### inputSchema()

Returns a JSON Schema structure describing the arguments the tool accepts.
Mirrors the tool schema format used by OpenAI's function calling API.

```php
public function inputSchema(): array => [
    'type' => 'object',
    'properties' => [
        'file_path' => ['type' => 'string', 'description' => 'Path to file to read'],
    ],
    'required' => ['file_path'],
];
```

### execute(array $args): ToolResult

Executes the tool with the provided arguments and returns a `ToolResult`.

## ToolCall Value Object

Represents a request to invoke a tool, providing the tool name and arguments.

```php
final readonly class ToolCall
{
    public function __construct(
        private string $id,
        private string $name,
        private array $arguments,
    ) {}

    public function id(): string => $this->id;
    public function name(): string => $this->name;
    public function arguments(): array => $this->arguments;

    public static function fromArray(array $data): self;
    public function toArray(): array;
}
```

### Properties

| Property | Type | Description |
|----------|------|-------------|
| `id` | `string` | Unique identifier for this tool call |
| `name` | `string` | Name of the tool to invoke |
| `arguments` | `array` | Arguments to pass to the tool |

### Factory Methods

- `fromArray(array $data): self` — Creates a `ToolCall` from an array, typically deserialized from JSON
- `toArray(): array` — Serializes the `ToolCall` to an array structure

## ToolResult Value Object

Represents the result of a tool execution.

```php
final readonly class ToolResult
{
    public function __construct(
        private string $toolCallId,
        private string $content,
        private bool $isError = false,
        private ?int $durationMs = null,
    ) {}

    public function toolCallId(): string => $this->toolCallId;
    public function content(): string => $this->content;
    public function isError(): bool => $this->isError;
    public function durationMs(): ?int => $this->durationMs;

    public function toArray(): array;
}
```

### Properties

| Property | Type | Description |
|----------|------|-------------|
| `toolCallId` | `string` | ID of the tool call that produced this result |
| `content` | `string` | The output content from tool execution |
| `isError` | `bool` | Whether the tool execution failed |
| `durationMs` | `?int` | Execution time in milliseconds (optional) |

## BuiltIn Tools

CandyCrush provides six built-in tools for common operations:

| Tool | Name | Description |
|------|------|-------------|
| `Read` | `Read` | Read contents of a file |
| `Bash` | `Bash` | Execute a bash command |
| `Edit` | `Edit` | Edit a file by replacing text |
| `Grep` | `Grep` | Search for a pattern in files |
| `Glob` | `Glob` | Find files matching a glob pattern |
| `WebFetch` | `WebFetch` | Fetch content from a URL |

### Read

Reads the complete contents of a file.

```php
public function inputSchema(): array => [
    'type' => 'object',
    'properties' => [
        'file_path' => ['type' => 'string', 'description' => 'Path to file to read'],
    ],
    'required' => ['file_path'],
];
```

**Error Handling**: Uses `set_error_handler` to convert PHP errors (permission denied, file not found) into `ToolResult` with `isError: true`.

### Bash

Executes a bash command and returns the output.

```php
public function inputSchema(): array => [
    'type' => 'object',
    'properties' => [
        'command' => ['type' => 'string', 'description' => 'The bash command to execute'],
    ],
    'required' => ['command'],
];
```

**Security**: Uses `escapeshellarg()` to prevent command injection. Commands are executed via `bash -c` to ensure shell syntax interpretation.

### Edit

Performs string replacement within a file.

```php
public function inputSchema(): array => [
    'type' => 'object',
    'properties' => [
        'file_path' => ['type' => 'string', 'description' => 'Path to file to edit'],
        'old_string' => ['type' => 'string', 'description' => 'The text to replace'],
        'new_string' => ['type' => 'string', 'description' => 'The replacement text'],
    ],
    'required' => ['file_path', 'old_string', 'new_string'],
];
```

**Notes**: Uses `str_replace()` which replaces all occurrences. Returns an error if `old_string` is empty or the file does not exist.

### Grep

Searches for a regex pattern within files in a directory.

```php
public function inputSchema(): array => [
    'type' => 'object',
    'properties' => [
        'pattern' => ['type' => 'string', 'description' => 'The regex pattern to search for'],
        'path' => ['type' => 'string', 'description' => 'Directory path to search in'],
        'include' => ['type' => 'string', 'description' => 'File pattern to match (e.g., *.php)'],
    ],
    'required' => ['pattern', 'path'],
];
```

**Security**: Uses `escapeshellarg()` when constructing the grep command.

### Glob

Finds files matching a glob pattern within a directory.

```php
public function inputSchema(): array => [
    'type' => 'object',
    'properties' => [
        'pattern' => ['type' => 'string', 'description' => 'The glob pattern to match (e.g., **/*.php)'],
        'path' => ['type' => 'string', 'description' => 'Base directory path'],
    ],
    'required' => ['pattern', 'path'],
];
```

### WebFetch

Fetches HTML or text content from a URL.

```php
public function inputSchema(): array => [
    'type' => 'object',
    'properties' => [
        'url' => ['type' => 'string', 'description' => 'The URL to fetch'],
    ],
    'required' => ['url'],
];
```

**Validation**: Requires URLs to start with `http://` or `https://`. Uses a 30-second timeout via `stream_context_create()`.
