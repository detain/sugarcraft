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
 Enter to send · Ctrl+P menu · /exit or ^C to quit
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

`SUGARCRUSH_PROVIDER` accepts `openai`, `anthropic`, `claude-code`, `sglang`, `bedrock`, `vertex`, or `custom`. Each reads its own credentials from the environment (e.g. `ANTHROPIC_API_KEY`, AWS ambient creds for Bedrock, `GOOGLE_APPLICATION_CREDENTIALS` for Vertex). When a real provider is active, the binary wires the built-in coding tools (Bash/Read/Edit/Glob/Grep/WebFetch/Doctor/Skill) and the safety hooks automatically.

### Non-interactive (one-shot) mode

`bin/sugarcrush` parses `argv` *before* it constructs a `Program`, so the
scriptable paths never attach to the TTY or enter the alt-screen:

```bash
sugarcrush -p "explain the Width helper"        # one prompt, print, exit
sugarcrush run "explain the Width helper"       # same thing
sugarcrush -p "audit this" --output-format json # machine-readable envelope
sugarcrush --output-format json run "audit this" # `run` works after flags too
sugarcrush --root /path/to/project              # set the project root explicitly
sugarcrush --help                               # prints and exits (never opens the TUI)
```

`--root` also accepts the first positional argument that looks like a path, so
`sugarcrush ../other-project` works. It is what the Bash/Read/Edit/Glob tools
are jailed to and where `CLAUDE.md`/`AGENTS.md` and `.sugar-crush/skills` are
looked for.

**One-shot mode never falls back to the offline echo provider.** If this run
selected a provider — via `$SUGARCRUSH_PROVIDER` or a persisted Ctrl+P "Switch
model" choice — and that provider cannot be constructed (unknown name, missing
credential), `-p`/`run` prints the reason to stderr and exits **2** rather than
returning a canned reply at exit 0. The stderr line names the source it came
from, so a persisted choice sends you to `~/.sugar-crush/config.json` rather
than to a `$SUGARCRUSH_PROVIDER` nothing ever set. The interactive TUI keeps
the opposite, lenient behaviour: it warns and opens an offline session, because
refusing to launch an editor over a missing API key is worse than an offline
one.

| exit | meaning |
| --- | --- |
| `0` | the prompt ran and produced an answer |
| `1` | ran and failed: the backend threw (unreachable host, rejected key, model error), or the answer could not be encoded in the requested format — retrying may help |
| `2` | usage/configuration error, nothing was attempted: no prompt given, unrecognized flag, `--root` naming no directory, a missing `vendor/autoload.php`, or a provider (from `$SUGARCRUSH_PROVIDER` **or** the persisted Ctrl+P choice) that cannot be constructed — retrying will not help |

`2` covers "no prompt given" (`sugarcrush -p`, `sugarcrush run`) deliberately:
the invocation is malformed, no backend is ever selected, and a CI gate that
retries on `1` would otherwise retry it forever.

With `--output-format json`, stdout is always exactly one JSON object: either
`{"result": "..."}` or `{"result": null, "error": {"type": "usage" |
"provider_configuration" | "backend" | "encoding", "message": "...",
"provider": "..."}}` (`provider` present only when a selection is to blame), so
a `| jq` consumer never sees an empty pipe. That holds for the flag and
`--root` usage errors too, which `bin/sugarcrush` catches before the one-shot
path is entered, and for a reply or an error message carrying bytes that are
not valid UTF-8 (they are substituted, not dropped along with the whole
document). `error.type` is not the exit code renamed — `usage` and
`provider_configuration` are both `2`, `backend` and `encoding` are both `1` —
it is how a consumer that kept the code tells apart the two kinds of each.

The single exception is a checkout with no `vendor/autoload.php`: that exits
`2` with an empty stdout, because the class that owns the JSON document shape
is precisely the one that could not be loaded, and hand-rolling a second copy
of the shape in `bin/sugarcrush` to cover it would be the drift that having one
definition prevents. Run `composer install`.

With no provider configured at all, one-shot mode still answers offline from
the `EchoProvider` and exits 0 — nothing was substituted for anything — but
says so on stderr.

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

## Using the TUI

The interactive binary boots a **pane shell** (`App`) that hosts the chat
model, so the menu bar, pane strip, session tabs and the chat transcript are
all one candy-core `Model` tree — not two parallel UIs.

### Keys

| Key | Does |
|-----|------|
| `Enter` | Send |
| `Esc` `Esc` | Cancel the in-flight turn — press **twice** within 0.6s (a single `Esc` is a no-op, which is why the status bar reads `Esc Esc to cancel` while thinking) |
| `Esc` | Close the palette or the session picker |
| `Ctrl+C` | Quit |
| `Ctrl+P` | Command palette (fuzzy, grouped by category, biased by most-recently-used) |
| `Ctrl+O` | Expand/collapse the most recent tool call's output |
| `Ctrl+R` | Session picker (persisted across turns) |
| `Ctrl+A` | Same dispatch as typing `/agents` |
| `Ctrl+W` / `Alt+Backspace` | Delete the previous word |
| `Up` (empty input) | Recall the last message you sent |
| `Page Up` / `Page Down` | Scroll the transcript a screenful |
| `Tab` | Cycle panes |
| `Ctrl+Tab` / `Ctrl+Shift+Tab` | Cycle sessions |
| `F10` | Open the menu bar |
| `y` / `n` / `a` | Answer a permission prompt: once / refuse / always |

`Ctrl+P`, `Ctrl+O`, `Ctrl+A`, `Ctrl+W` and `Ctrl+C` always belong to the chat
content model — the shell never claims them, in any pane, so hosting chat
inside the shell cannot silently steal a binding.

### Mouse

Mouse mode is on by default (`SUGARCRUSH_DISABLE_MOUSE=1` turns it off). Zones
are registered during the render pass, so clicks land on what you see: wheel
scrolls the transcript, clicking a tool call expands/collapses it, clicking a
session tab or a pane label switches to it, clicking a palette/picker row
selects it, and clicking the menu bar opens a menu. Click-vs-drag is
discriminated so a text-selection drag does not fire the zone underneath it.

### Slash commands

`/agents` `/agent` `/bg` `/background` `/fork` `/branch` `/compact` `/mcp`
`/memory` `/rename` `/rewind` `/sessions` `/share` `/theme` `/workflow`
`/exit` (`/quit`) — plus any **file-based custom command** found on disk.
Typing `/` opens a live popup of the matches.

**New session**, **Switch model** and **Open docs** are palette-only actions
(`Ctrl+P`) — they have no slash spelling, so `CommandRegistry` keeps them out
of the `/` popup.

`/bg` really does run the work: it dispatches onto a `BackgroundSupervisor`
that `bin/sugarcrush` constructs per launch, and the result comes back into
the transcript. `/fork` branches the current session.

### What you see while a turn runs

Tool calls stream into the transcript **as they happen** — the forked child
emits lifecycle events rather than buffering until the turn ends — each with a
human-readable description and the command it actually ran, then a
running→done transition. `Edit`/`Write` results render a real unified diff. A
no-op edit reports as a no-op instead of success. Denied and interrupted calls
get their own visual state. Tool results that carry images are labelled and
rendered inline via candy-mosaic. Successful tool bodies are hidden by default
(`Ctrl+O` or a click opens them). Context usage shows as both a token count and
a percentage.

Sessions get a name automatically: after the first exchange a **cheap
small-model backend** (supplied separately from the conversation backend, so
naming never costs a second tool-capable agent turn) generates a title, which
is what `/sessions`, the tab strip and `Ctrl+R` list.

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

The `sglang` type accepts an optional `toolCallParser` key: `'openai'` (the
default — read the server's parsed `tool_calls[]` array) or
`'minimax-xml-fallback'` (same, but when the array is absent, recover MiniMax's
raw `<tool_call>` XML out of the message content). Switch it only if your SGLang
deployment was launched *without* `--tool-call-parser`, which leaves the model's
tool-call XML unparsed in the content. Note this currently applies to the batch
`complete()` path only — the streaming path reassembles tool calls itself and
does not yet consult the setting.

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

- **Tools** — `Tools\BuiltIn\*`: `Bash`, `Read`, `Edit`, `Glob`, `Grep`, `WebFetch`, `Doctor` (a capability probe the model can call to report what this build/deployment actually supports), and `Skill` (level 2 of the progressive-disclosure design below). Implement `Tools\Tool` for your own.
- **Hooks** — `Hooks\*`: pre/post-tool-use guards (allow / deny / **modify** the input). Built-ins: `AuditHook`, `ConfirmRemoveHook`, `ProtectFilesHook`. YAML config and external `ScriptHook` supported.
- **Permission modes** — `Permissions\*`: `PermissionGate` enforces one of six `PermissionMode`s (`default`, `accept-edits`, `plan`, `auto`, `dont-ask`, `bypass-permissions`) per tool call, with a mode-independent rm-rf circuit breaker and a fail-closed `auto` classifier when no `SafetyClassifier` is configured.
- **Skills** — `Skills\*`: frontmatter `SKILL.md` files inject prompt context, matched by keyword/path. Discovered from built-ins (`src/Skills/BuiltIn/`), `~/.sugar-crush/skills`, and `<project>/.sugar-crush/skills` (project wins). Ships 12 built-ins spanning language/framework conventions (`php-best-practices`, `laravel-best-practices`, `symfony-best-practices`), workflow (`testing-strategies`, `api-design`, `explore-codebase`, `worktree-workflow`, `mcp-authoring`, `matchups-sync`) and the original four (`security-audit`, `phpunit-master`, `composer-wizard`). `disable-model-invocation`, `user-invocable`, and `context: fork` frontmatter flags are enforced, not decorative — a fork-context skill runs through `AgentWorkerPool` as an isolated sub-agent. Loading is **progressive**: the system prompt carries only each skill's name + description, and the model pulls the full `SKILL.md` body through the `Skill` tool when it decides one is relevant. Path-scoped skills self-announce — the first time `Read`/`Edit`/`Glob` touches a file a skill's `paths:` covers, that skill is surfaced (once per session, via one shared announce-set across the three tools). Skills authored for other CLIs (Claude Code, opencode) are imported rather than ignored, and the picker shows a provenance badge for where each one came from.
- **Agents** — `Agents\*`: 6 sub-agent presets (coder/reviewer/debugger/architect/tester/devops) with their own model, tools, skills, and a streaming lifecycle, dispatched through `AgentWorkerPool` (`pcntl_fork`-based, with a synchronous fallback + warning when `pcntl` is unavailable).
- **Teams & worktrees** — `Agents\{Team,TeamManager,Teammate,TaskList,Mailbox}`: a lead agent spawns a capped team of teammates that atomically claim `TaskList` tasks (SQLite `flock`-backed, contention-tested) and exchange append-only JSON-lines mailbox messages. `Agents\{WorktreeConfig,WorktreeManager,PathJail}` give each teammate an isolated git worktree (`.worktreeinclude`-aware, swept for staleness) sandboxed by a path jail.
- **Workflows** — `Workflows\*`: `WorkflowBuilder`/`WorkflowRegistry`/`WorkflowEngine` run multi-stage agent pipelines — sequential `stage()`, fan-out `parallel()`, chained `pipeline()`, and task-then-verifier `withVerification()` — defined as PHP DSL files or YAML (`WorkflowRegistry::loadYaml()`). SIGINT/SIGTERM during `run()` captures a real pause file for later resumption at stage granularity. See [`examples/workflows/lint-then-fix.yaml`](examples/workflows/lint-then-fix.yaml) for a runnable YAML example and [`workflows/deep-research.php`](workflows/deep-research.php) for the PHP DSL form.
- **MCP** — `MCP\*`: multi-server client (stdio + HTTP, `.mcp.json`, `${VAR}` interpolation) and stdio/HTTP servers to host your own tools. Per-agent-preset `mcpServers` allowlists are enforced by `McpClient` against `McpRouter`, not just decorative config.
- **Sessions** — `Session\SessionStore`: SQLite (WAL) persistence of sessions/messages/tool-calls with FK-enforced cascade and age-based pruning.
- **Tokens & export** — `Util\TokenTracker` (token + cost accumulation) and `Util\Exporter` (Markdown / JSON / text transcripts).
- **Messages** — typed `Messages\{System,User,Assistant,ToolResult}Message`; `UserMessage` carries file/image attachments; `AssistantMessage` carries tool calls + reasoning.
- **Context files** — `CLAUDE.md`/`AGENTS.md` at the project root are loaded into the system prompt, with `@import` expansion (cycle- and traversal-guarded, and de-duplicated so an imported doc is not injected twice). `Forced` instructions come from user config. An `EnvironmentBlock` (cwd, platform, git state, date) is prepended so the model is not guessing at its surroundings.
- **Permission prompts** — a blocking request/reply flow (`HookResult::ask()` → `PermissionRequestMsg`/`PermissionReplyMsg`) rendered as a Veil modal over the transcript; the answer settles the paused tool call rather than being advisory.

## Architecture

SugarCrush keeps the proven sugar-crush **chassis** (the `Chat` candy-core `Model`, buffer-diff `Renderer`) and runs the ported **engine** behind it. The interactive binary boots the **pane shell** that hosts that chassis:

```
bin/sugarcrush
  └─ Bootstrap::app()
       └─ Program → App (root candy-core Model: menu bar, pane focus, session tabs)
            ├─ Tui\Renderer → ChatPane ─┐
            │                            └→ Renderer (the live buffer-diff chat renderer)
            └─ Chat (Model: input, scrollback, inFlight gate, permissions, zones)
                 └─ Backend  ── EchoBackend / CommandBackend (simple)
                             └─ EngineBackend (agent loop, emits tool-lifecycle events)
                                  └─ Runtime → ProviderInterface  (+ Tools · Hooks · Skills via App)
```

`App` plays two roles that are easy to confuse: it is the **engine's state object** (`Runtime::run(App $app, …)` and `EngineBackend` both take it, carrying tools/hooks/skills) *and* the root TUI `Model` the pane shell renders. `Chat` is untouched by the shell — it is still a standalone `Model` you can run directly with `new Program(new Chat(...))`.

The chassis speaks the root `Message` value object; the engine speaks the typed `Messages\*` hierarchy; `EngineBackend` converts at the seam.

## Limitations

Things that are genuinely not finished, stated plainly rather than left for you to discover:

- **`SglangProvider`'s `toolCallParser` applies to the batch `complete()` path only.** The streaming path reassembles tool calls itself and does not consult the setting.
- **Five shell commands are still inert**: `GroupInputCmd`, `CancelAgentCmd`, `ResumeAgentCmd`, `StopAllAgentsCmd`, `QuitAgentViewCmd`. The first has no counterpart in the live app; the agent four would need to reach into a worker pool the shell does not hold. Their pane/selection half *is* applied — only the action half is missing.
- **Workflow resume granularity is per whole stage.** An interrupted *parallel* sub-stage cannot be resumed with partial credit.
- **`pcntl` is required for real parallelism.** Without it `AgentWorkerPool` falls back to sequential execution and logs a one-time visible warning rather than pretending to fan out.
- **Providers are unit-tested against mocked transports.** No test in this suite makes a live API call, so wire-format drift at a real endpoint is caught by `/doctor` and by using it, not by CI.
- **The `Doctor` tool reports capabilities, it does not repair them.**

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

4,337 tests / 12,587 assertions (0 failures, 0 errors). Coverage spans every subsystem: typed messages + attachments, the 6 built-in tools, all 7 providers (unit-tested with mocked transports — no live calls), the hook framework, permission-mode gating (incl. `pcntl_fork` concurrency stress tests for atomic task claiming), skills discovery + flag enforcement, sub-agents/teams/worktrees, workflow execution (sequential/parallel/pipeline/verification, PHP + YAML loading), the MCP client/servers (incl. per-agent routing enforcement), the SQLite store, token tracking, export, the TUI components, the `Runtime` orchestration (streaming accumulation, tool-result correlation, MODIFY hooks), the shell-out `CommandBackend` / `StreamingCommandBackend`, and the `EngineBackend` agentic loop (incl. the `maxSteps` guard).

A dedicated `tests/Integration/` tier asserts **reachability** rather than behaviour: that the session store, session tabs, background sessions, the skills subsystem, mouse mode, the environment block and root context-file loading are actually reached from `bin/sugarcrush` → `Bootstrap::app()`, not merely implemented somewhere in `src/`. That tier exists because the audit recorded in the monorepo root's `crush_code_update.md` found well-tested subsystems that no real run could ever touch.

See [`CHANGELOG.md`](CHANGELOG.md) for how the suite got here.
