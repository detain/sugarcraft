<img src=".assets/icon.png" alt="sugar-crush" width="160" align="right">

# SugarCrush

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-crush)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-crush)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-crush?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-crush)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/chat.gif)

A terminal AI coding agent — PHP port of [`charmbracelet/crush`](https://github.com/charmbracelet/crush). It is a candy-core TEA program (a real `Model`/`Program` render loop with buffer-diffed output and Markdown-rendered replies) wrapped around a full agent engine: **multiple LLM providers**, model-driven **tool calling** gated by **hooks**, prompt-injecting **skills**, **sub-agents**, an **MCP** client/server, and **SQLite** session history.

```
┌─ SugarCrush ───────────────────────────────────────┐
│ user> add a test for the Width helper              │
│                                                    │
│ assistant                                          │
│ I'll read the helper first, then write the test.   │
│   ⚙ Read  src/Util/Width.php                       │
│   ⚙ Edit  tests/Util/WidthTest.php                 │
│ Done — added 4 cases covering the clamp edges.     │
└────────────────────────────────────────────────────┘
┌────────────────────────────────────────────────────┐
│ > run them█                                        │
└────────────────────────────────────────────────────┘
 Enter to send · Esc / ^C to quit
```

> **History:** SugarCrush absorbed the former experimental `candy-crush` port. There is now a single `SugarCraft\Crush` library.

## Run it

```bash
composer install
./bin/sugarcrush
```

With no configuration the binary runs the **offline `EchoProvider`** through the full engine, so it launches with zero network and zero keys. Point it at a real model with environment variables:

```bash
# OpenAI
export SUGARCRUSH_PROVIDER=openai
export OPENAI_API_KEY=sk-...
export SUGARCRUSH_MODEL=gpt-4o          # optional; provider default otherwise
./bin/sugarcrush
```

`SUGARCRUSH_PROVIDER` accepts `openai`, `anthropic`, `claude-code`, `sglang`, `bedrock`, `vertex`, or `custom`. Each reads its own credentials from the environment (e.g. `ANTHROPIC_API_KEY`, AWS ambient creds for Bedrock, `GOOGLE_APPLICATION_CREDENTIALS` for Vertex). When a real provider is active, the binary wires the built-in coding tools (Bash/Read/Edit/Glob/Grep/WebFetch) and the safety hooks automatically.

### Dependency-free shell-out

To avoid PHP SDKs entirely, set `SUGARCRUSH_BACKEND_CMD` to a command that reads JSON history on stdin and writes the reply to stdout:

```bash
export SUGARCRUSH_BACKEND_CMD=~/bin/anthropic.sh
./bin/sugarcrush
```

```bash
#!/usr/bin/env bash
# ~/bin/anthropic.sh — keeps PHP network-dep-free, swap models by editing this file
payload=$(jq -nc --argjson h "$(cat)" '{model:"claude-opus-4-8", max_tokens:4096, messages:$h}')
curl -sN https://api.anthropic.com/v1/messages \
  -H "x-api-key: $ANTHROPIC_API_KEY" -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" -d "$payload" | jq -r '.content[0].text'
```

### Choosing a backend without editing anything

Three ways to get off the offline `EchoProvider`, from quickest to most permanent:

1. **One-off, this run only:** `SUGARCRUSH_PROVIDER=dev-sglang ./bin/sugarcrush` — `dev-sglang` is the project's own dev/test SGLang endpoint (declared in `.sugar-crush/config.dev.json`, checked into the repo), useful for trying a real (if smaller) model with zero API keys.
2. **From inside the TUI:** press **Ctrl+P**, choose **Switch model**, pick any provider from the list (built-in types plus every name declared in `.sugar-crush/config.dev.json`, e.g. `dev-sglang`) — switches immediately, no restart. **Switch theme** works the same way for color themes.
3. **Persisted across restarts:** either of the above choices made via the palette is written to `~/.sugar-crush/config.json` and read back on the next launch — so picking `dev-sglang` once via Ctrl+P means every future `./bin/sugarcrush` (with no env vars set at all) uses it automatically. `$SUGARCRUSH_PROVIDER`/`$SUGARCRUSH_BACKEND_CMD` still take priority over the persisted choice when set, for scripting/CI overrides.

## Providers

`SugarCraft\Crush\Providers\ProviderInterface` is the single LLM abstraction (capability introspection, batch + `\Generator` streaming, function calling, embeddings, per-model cost). Build one directly or from config via `ProviderFactory` (which resolves `${VAR}` / `${VAR:-default}` from the environment):

```php
use SugarCraft\Crush\Providers\ProviderFactory;

$factory  = new ProviderFactory();
$provider = $factory->create(['type' => 'openai', 'apiKey' => '${OPENAI_API_KEY}', 'model' => 'gpt-4o']);
```

| Provider        | Type key      | Notes                                                            |
|-----------------|---------------|------------------------------------------------------------------|
| OpenAI          | `openai`      | `openai-php/client`; function calling, embeddings, cost table    |
| Anthropic       | `anthropic`   | real Messages API (`/v1/messages`, `x-api-key`)                  |
| Claude Code CLI | `claude-code` | drives the `claude` binary headless; native cost; JSON schema    |
| SGLang          | `sglang`      | OpenAI-compatible self-hosted endpoints (Guzzle)                 |
| AWS Bedrock     | `bedrock`     | Converse API via `aws/aws-sdk-php`; per-model pricing            |
| GCP Vertex      | `vertex`      | Anthropic-on-Vertex via an injectable predictor seam             |
| Custom          | `custom`      | any OpenAI-compatible HTTP endpoint                              |
| Echo            | —             | `EchoProvider`: offline, echoes the last turn; default + tests   |

## The agent loop

`EngineBackend` bridges the chat-shell `Backend` seam to the engine. Each user turn runs a **bounded agentic loop**: call the provider through the `Runtime`, execute any tool calls through the hook gate, feed the results back, and repeat until the model answers without calling tools — or a `maxSteps` ceiling is hit.

```php
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Hooks\{HookManager, HookRegistry};
use SugarCraft\Crush\Tools\BuiltIn\{Bash, Read, Edit, Glob, Grep, WebFetch};

$hooks = new HookManager(new HookRegistry());
$hooks->registerBuiltIns();                       // audit + confirm-rm + protect-files

$backend = (EngineBackend::new($provider, 'gpt-4o'))
    ->withTools([new Bash(), new Read(), new Edit(), new Glob(), new Grep(), new WebFetch()])
    ->withHooks($hooks);

(new Program(new Chat(backend: $backend)))->run();
```

## Capabilities

- **Tools** — `Tools\BuiltIn\*`: `Bash`, `Read`, `Edit`, `Glob`, `Grep`, `WebFetch`. Implement `Tools\Tool` for your own.
- **Hooks** — `Hooks\*`: pre/post-tool-use guards (allow / deny / **modify** the input). Built-ins: `AuditHook`, `ConfirmRemoveHook`, `ProtectFilesHook`. YAML config and external `ScriptHook` supported.
- **Permission modes** — `Permissions\*`: `PermissionGate` enforces one of six `PermissionMode`s (`default`, `accept-edits`, `plan`, `auto`, `dont-ask`, `bypass-permissions`) per tool call, with a mode-independent rm-rf circuit breaker and a fail-closed `auto` classifier when no `SafetyClassifier` is configured.
- **Skills** — `Skills\*`: frontmatter `SKILL.md` files inject prompt context, matched by keyword/path. Discovered from built-ins (`src/Skills/BuiltIn/`), `~/.sugar-crush/skills`, and `<project>/.sugar-crush/skills` (project wins). Ships 12 built-ins spanning language/framework conventions (`php-best-practices`, `laravel-best-practices`, `symfony-best-practices`), workflow (`testing-strategies`, `api-design`, `explore-codebase`, `worktree-workflow`, `mcp-authoring`, `matchups-sync`) and the original four (`security-audit`, `phpunit-master`, `composer-wizard`). `disable-model-invocation`, `user-invocable`, and `context: fork` frontmatter flags are enforced, not decorative — a fork-context skill runs through `AgentWorkerPool` as an isolated sub-agent.
- **Agents** — `Agents\*`: 6 sub-agent presets (coder/reviewer/debugger/architect/tester/devops) with their own model, tools, skills, and a streaming lifecycle, dispatched through `AgentWorkerPool` (`pcntl_fork`-based, with a synchronous fallback + warning when `pcntl` is unavailable).
- **Teams & worktrees** — `Agents\{Team,TeamManager,Teammate,TaskList,Mailbox}`: a lead agent spawns a capped team of teammates that atomically claim `TaskList` tasks (SQLite `flock`-backed, contention-tested) and exchange append-only JSON-lines mailbox messages. `Agents\{WorktreeConfig,WorktreeManager,PathJail}` give each teammate an isolated git worktree (`.worktreeinclude`-aware, swept for staleness) sandboxed by a path jail.
- **Workflows** — `Workflows\*`: `WorkflowBuilder`/`WorkflowRegistry`/`WorkflowEngine` run multi-stage agent pipelines — sequential `stage()`, fan-out `parallel()`, chained `pipeline()`, and task-then-verifier `withVerification()` — defined as PHP DSL files or YAML (`WorkflowRegistry::loadYaml()`). SIGINT/SIGTERM during `run()` captures a real pause file for later resumption at stage granularity. See [`examples/workflows/lint-then-fix.yaml`](examples/workflows/lint-then-fix.yaml) for a runnable YAML example and [`workflows/deep-research.php`](workflows/deep-research.php) for the PHP DSL form.
- **MCP** — `MCP\*`: multi-server client (stdio + HTTP, `.mcp.json`, `${VAR}` interpolation) and stdio/HTTP servers to host your own tools. Per-agent-preset `mcpServers` allowlists are enforced by `McpClient` against `McpRouter`, not just decorative config.
- **Sessions** — `Session\SessionStore`: SQLite (WAL) persistence of sessions/messages/tool-calls with FK-enforced cascade and age-based pruning.
- **Tokens & export** — `Util\TokenTracker` (token + cost accumulation) and `Util\Exporter` (Markdown / JSON / text transcripts).
- **Messages** — typed `Messages\{System,User,Assistant,ToolResult}Message`; `UserMessage` carries file/image attachments; `AssistantMessage` carries tool calls + reasoning.

## Architecture

SugarCrush keeps the proven sugar-crush **chassis** (the `Chat` candy-core `Model`, buffer-diff `Renderer`, `bin/sugarcrush`) and runs the ported **engine** behind it:

```
bin/sugarcrush
  └─ Program → Chat (Model: input, scrollback, inFlight gate, buffer-diff view)
       └─ Backend  ── EchoBackend / CommandBackend (simple)
                   └─ EngineBackend (agent loop)
                        └─ Runtime → ProviderInterface  (+ Tools · Hooks · Skills via App)
```

The chassis speaks the root `Message` value object; the engine speaks the typed `Messages\*` hierarchy; `EngineBackend` converts at the seam.

## Custom provider

```php
use SugarCraft\Crush\Providers\{ProviderInterface, CompleteRequest, CompleteResponse, EmbeddingsRequest, EmbeddingsResponse};

final class MyProvider implements ProviderInterface
{
    public function name(): string { return 'mine'; }
    public function supportsStreaming(): bool { return false; }
    public function supportsFunctionCalling(): bool { return true; }
    public function supportsVision(): bool { return false; }
    public function supportsJsonSchema(): bool { return false; }
    public function contextWindow(): int { return 128_000; }
    public function costPer1kTokens(string $model, string $direction): float { return 0.0; }
    public function complete(CompleteRequest $r): CompleteResponse { /* ... */ }
    public function completeStream(CompleteRequest $r): \Generator { /* yield CompleteResponse chunks */ }
    public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { /* ... */ }
}
```

## Tests

```bash
cd sugar-crush && composer install && vendor/bin/phpunit
```

3,159 tests / 8,048 assertions (0 failures, 0 errors). Coverage spans every subsystem: typed messages + attachments, the 6 built-in tools, all 7 providers (unit-tested with mocked transports — no live calls), the hook framework, permission-mode gating (incl. `pcntl_fork` concurrency stress tests for atomic task claiming), skills discovery + flag enforcement, sub-agents/teams/worktrees, workflow execution (sequential/parallel/pipeline/verification, PHP + YAML loading), the MCP client/servers (incl. per-agent routing enforcement), the SQLite store, token tracking, export, the TUI components, the `Runtime` orchestration (streaming accumulation, tool-result correlation, MODIFY hooks), the shell-out `CommandBackend` / `StreamingCommandBackend`, and the `EngineBackend` agentic loop (incl. the `maxSteps` guard). See [`CHANGELOG.md`](CHANGELOG.md) for how the suite got here.
