# Architecture

SugarCrush is a terminal coding agent built on the SugarCraft TUI stack. This
page is a map: what each layer owns, which seam separates it from the next, and
where the layering is load-bearing rather than decorative.

Read it when something makes no sense at all — a figure that cannot be right, a
subsystem that is documented and does nothing, a stderr line inside a frame.

---

## The one-screen version

```
bin/sugarcrush                argv → pre-flight → dispatch
      │
      ├─ ArgvParser / Help / NonInteractive / Subcommands
      │      --help --version, five subcommands, -p one-shot
      │
      └─ Cli\Bootstrap              ALL wiring lives here
             │
             └─ App\App             THE root TEA Model handed to Program,
                    │                and the engine state object Runtime takes
                    │  hosts
                    └─ Chat         a TEA Model too — hosted, not the root
                           │  Backend seam:  complete(history): Message
                           └─ Backend\EngineBackend
                                  │
                                  └─ Runtime        the agentic loop
                                         ├─ Providers\*          the model call
                                         ├─ Tools\*              11 built-ins + MCP bridges
                                         ├─ Hooks\*              the PreToolUse chain
                                         └─ Permissions\*        the gate, last in that chain
```

Everything from `Chat` up renders; everything below it does work. Note the split
runs *through* `App` rather than above it — `App` is on both sides of that line,
which is the whole of the next warning.

---

## `bin/sugarcrush` — pre-flight before anything attaches to the terminal

219 lines, and the order in it is deliberate. `--help`, `--version` and the five
subcommands (`doctor`, `models`, `session list|delete`, `mcp list`,
`completion bash|zsh|fish`) are answered **before** `Program`, `Bootstrap::app()`
or `NonInteractive` is reached, because every one of them is a question about
the *install* rather than a turn of conversation: they must answer on a machine
with no provider, no API key and no TTY.

`doctor` is the sharpest case — it diagnoses an install that may be broken, so it
must not require the thing it is diagnosing. Each of its nine probes catches its
own throws, so an unreadable `config.json` becomes a reported line rather than
taking the report down.

The pre-flight order is: autoload → `--help` → `--version` → usage errors →
unknown flags → `--root` validation → `--config` validation →
`Bootstrap::useConfigPath()` → subcommands → `-p` one-shot → the TUI. Each
placement is load-bearing: `--config` must be validated *and registered* before
`doctor` runs, or `sugarcrush --config x.json doctor` would report the policy
from the discovered config.

A `PermissionConfigException` from anywhere inside the dispatch becomes exit 2
plus (under `--output-format json`) one error document — not a PHP fatal painted
over the terminal.

---

## `Cli\Bootstrap` — the wiring, all of it

One class, 4,253 lines and 70 methods, every one of them static, and it is large on
purpose:
every backend, tool, session store, memory store, instruction loader, hook
manager, permission gate, skill registry, agent roster, workflow engine and MCP
client is constructed here, so a test can exercise the wiring without shelling
out to `bin/sugarcrush` and blocking on `Program::run()`.

Two consequences worth knowing:

- **`Bootstrap` is where trust decisions live**, because it is the only layer
  that knows which *directory* a file came out of. The three `trustedProject*`
  gates, the `$HOME` anchors and the containment checks are all here or reached
  from here.
- **`Bootstrap` is where the launch refuses.** Anything "configured but
  unusable" throws `PermissionConfigException` from construction, before the
  alt screen exists — which is also why its warnings go to stderr at
  construction time and are latched once per process.

Refusals are collected rather than only printed:
`Bootstrap::projectTierRefusals()` (directories a repository chose that this
launch declined to read) and `Bootstrap::skillSkips()` (per-file skips) are
pull-based seams for a doctor report or a debug pane, with
`reportProjectTierRefusals()` putting one bounded line in front of the user.

---

## `Chat` — a TEA model, and the one that is not the root

`src/Chat.php` is `final class Chat implements Model` in candy-core's TEA shape:
`init()`, `update(Msg): [Model, ?Cmd]`, `view()`. Side effects are `Cmd`s and
never happen in `view()`.

**It is not the ROOT model, and this page said it was.** `bin/sugarcrush` hands
`Bootstrap::app()` to `new Program(...)`, and `App` is itself
`final class App implements Model` (`src/App/App.php`). Two classes implement
`Model`; exactly one of them is the root, and it is not this one. The wrong
version of this sentence is not a harmless imprecision — see the warning under
[`App` hosts `Chat`](#app-hosts-chat), which records a revert it already caused.

It is the largest file in the package — well past ten thousand lines; run
`wc -l src/Chat.php` rather than trusting a figure here, because the one this
sentence used to carry ("10,381 lines, measured on this checkout") was stale by
the time anyone read it — because it owns every interactive surface: the input widget, the transcript, the "/" popup, the Ctrl+P
palette, session tabs, the permission prompt, and the dispatch arms for 22
built-in slash commands.

`Chat` is **standalone-runnable**. Every collaborator is optional and degrades to
a "<thing> not configured" message rather than throwing —
candy-core's `Program` has no try/catch around its synchronous `update()`
dispatch, so an exception there propagates out of the event loop entirely,
skipping terminal teardown and leaving the real terminal in raw/alt-screen state.
That is why `/agents`, `/workflow` and `/memory` all answer politely on an
unwired `Chat`.

### `App` hosts `Chat`

> **⚠️ `App` WEARS TWO HATS — DO NOT "RETIRE" IT.** This warning is the reason
> this page exists, and until now the page did not carry it.
>
> `SugarCraft\Crush\App\App` is **the live engine state object** —
> `Runtime::run(App $app, …)` (`src/Runtime.php:154`) and `EngineBackend` both
> take it, and it carries the tools, hooks and skills — **and**, since the
> pane-shell migration, **the root TUI `Model`** (`src/App/App.php`,
> `final class App implements Model`). Both hats are live. Any plan document
> describing "the dead `App`/`Tui\Renderer` system" is wrong about the first
> half. What was genuinely unreachable was the **pane layer** (`Tui\Renderer`
> and its `Tui\Components\*`), and the migration **wired that up rather than
> deleting it**.
>
> This is not hypothetical. Reading `App` as dead caused a real
> revert-then-restore in this repository; the incident is recorded in
> `CALIBER_LEARNINGS.md` under the heading this box is named after. The
> misreading is easy to arrive at honestly — `App` looks like a pane shell, and
> a pane shell looks retirable — which is exactly why the warning has to sit
> beside the description rather than in a learnings file nobody greps.

`bin/sugarcrush` runs `Bootstrap::app()`, not `Bootstrap::chat()`. `App`
(`src/App/App.php`) is the pane shell — menu bar, pane focus, agent-view keys,
the session tab strip — and it *hosts* the `Chat` model rather than
reimplementing it. The `Chat` is taken whole from `Bootstrap::chat()`, because it
already carries the seeded session row, the title backend, the memory store and
the guard chain; seeding it twice would create a second session row per launch.

`App` copies no state out of the hosted chat except `withSessionId()`, which is
read back off it rather than re-derived, so the two cannot disagree. Its Tools
and Skills panes hold **display** copies; the engine's authoritative tool list
and skill registry live inside the hosted chat's backend.

This is also where a real hazard lives: `App` carries skill methods
(`applySkillsToSystemPrompt()`, `dispatchSkill()`) that **no production caller
reaches**, so `context: fork` and a skill's `model:` are honoured there and
nowhere the CLI goes. See [`SKILLS.md`](SKILLS.md#the-context-field-is-not-live-on-the-cli-path).

---

## The `Backend` seam

`src/Backend.php` is a two-method interface — `complete(history, ?onToken,
?onEvent): Message` and an async variant — and it is the whole contract between
the UI and the agent:

| Implementation | Role |
|---|---|
| `EchoBackend` | offline default; makes the TUI runnable with no provider |
| `EngineBackend` | the real one: `Runtime` + provider + tools + hooks + skills |
| `CommandBackend` | `SUGARCRUSH_BACKEND_CMD` — stdout **is** the answer |
| `StreamingCommandBackend` | `SUGARCRUSH_BACKEND_CMD_STREAM` — one token per line |

`$onEvent` exists because the returned `Message` is a single opaque final
answer: an agentic backend runs several rounds of tool calls behind it, and
without the callback none of them are observable by the caller at all. That is
what makes a tool call visible as running-then-done in the transcript.

### `EngineBackend` forks

A turn runs in a **forked child** writing length-prefixed frames back over a
socket, which is what keeps the TUI's event loop free. Details that matter:

- The idle ceiling is **per frame**, not per turn: every frame the child streams
  resets it, so a turn making visible progress stays alive indefinitely while a
  genuinely hung provider still dies. A single wall-clock timer for the whole
  fork used to SIGKILL legitimate multi-step tool work mid-flight.
- A frame is capped at 64 MiB, because a frame legitimately carries raw image
  bytes but a corrupt header must not make the parent buffer an arbitrary length.
- Child reaping is a bounded 100 ms `WNOHANG` poll, with escaped PIDs tracked and
  swept at the top of the next turn. A blanket `pcntl_waitpid(-1, …)` would be
  actively harmful: `Chat::executeToolsParallel()` and
  `BackgroundSessionRunner` both wait on their *own* PIDs in the same process.

---

## `Runtime` — the agentic loop

`Runtime::run()` is a generator, and it resolves **exactly one** assistant turn
plus that turn's tool calls: call the model, execute the tool calls it returned
through the hook gate, yield the results. It has no step counter — `maxSteps`
appears nowhere in `src/Runtime.php` except one doc-comment at line 1433, and
`Runtime::__construct` (lines 101-107) takes no such parameter.

**The multi-step ceiling belongs to the caller, not to `Runtime`.**
`private readonly int $maxSteps = 8` is a constructor parameter of
`src/Backend/EngineBackend.php` (line 126), and the bound it arms is
`for ($step = 0; $step < $this->maxSteps; $step++)` at `EngineBackend.php:462`
— the loop that feeds each turn's tool results back and re-runs the `Runtime`
until the model answers without tools. `EngineBackend::withMaxSteps()` clamps
its argument with `max(1, $maxSteps)`, so the ceiling can be raised or lowered
but never set to zero, which would make a turn produce nothing at all.

The two type worlds meet at the `EngineBackend` seam: the chassis works in the
root `Message`/`ToolCall` value objects, the engine in the typed
`Messages\*`/`Tools\*` hierarchy, and lossless adapters live on the chassis side
only so no dependency cycle is created.

### The system prompt, in assembly order

`Runtime::buildSystemPrompt()` appends, in this order:

1. the base instructions (a heredoc, each clause naming the code that makes it
   true *and* the limit past which it stops being true);
2. `EnvironmentBlock` — cwd, model, git state, date; memoized per `Runtime`
   because `render()` shells out to git and the prompt is rebuilt once per step;
3. `<project-instructions>` documents — `CLAUDE.md` / `AGENTS.md`, with
   `@import`s expanded, via `InstructionFileLoader`;
4. `MemoryBlock` — `project`-scope memory entries only;
5. explicitly enabled skills' full bodies;
6. `SkillMatcher::listForPrompt()` — name + description for every discovered
   auto-invocable skill.

Item 6 is what makes the `Skill` tool worth having: without the listing, the
model has no reason to call it, and a populated registry would still be
un-triggerable.

Note the prompt-caching consequence stated in `MemoryBlock`'s own source:
`EnvironmentBlock::render()` polls `git status --porcelain` and sits **ahead** of
everything else, so the first edit of a session voids the cacheable prefix for
everything downstream of it anyway.

### Parallel tool dispatch

Read-only tools marked `Tools\ParallelSafe` are fanned out over **one forked
child per call**, bounded by `SUGARCRUSH_PARALLEL_TOOL_DEADLINE` (90s) and
disable-able with `SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS`.

Two visible consequences: `PostToolUse` hooks all fire *after* every member of
the group is forked, so a hook mutating shared state is no longer observable by a
later sibling ([`HOOKS.md`](HOOKS.md#one-caveat-on-posttooluse-under-concurrency));
and a tool that mutates per-call state cannot be parallel-safe unless it also
implements `Tools\CarriesSessionState`, because a child's writes are invisible to
the parent. `LspTool` is the worked example of a tool that is deliberately *not*
parallel-safe.

---

## The gate chain

One chain, two live pipelines. `Runtime::gate()` (engine/provider path) and
`Chat::gateToolCall()` (Chat's own registered tools) both gate on the
`PreToolUse` hook chain, which is why the six-mode `PermissionGate` rides in as
a hook (`PermissionGateHook`) rather than being called separately: it reaches
both with no new dispatch machinery, and inherits the ASK plumbing they already
implement — a blocking prompt on Chat's side, a fail-closed denial on Runtime's.

```
ProtectFilesHook → ConfirmRemoveHook → AuditHook → [hooks.yaml] → PermissionGateHook
```

`HookRegistry::executeHooks()` re-scans the whole chain against a MODIFY
rewrite, so a hook that rewrites `Bash{command:"ls"}` into
`Bash{command:"rm -rf /"}` is re-evaluated rather than slipping past the gate
behind it. See [`PERMISSIONS.md`](PERMISSIONS.md) and [`HOOKS.md`](HOOKS.md).

---

## Tools

`src/Tools/BuiltIn/` holds **eleven** concrete `Tool` classes: `Bash`,
`Doctor`, `Edit`, `Glob`, `Grep`, `LspTool`, `Read`, `SkillTool`, `WebFetch`,
`WebSearch`, `Write`. `Bootstrap::tools()` lists all eleven and appends one
`McpToolBridge` per advertised MCP tool.

Domain matters here: **eleven is the count of *wired* tools, not of *usable*
ones.** `LspTool` is reachable and answers every call with a "no language server
configured" error, because nothing in `src/` reads a server command. A figure
saying "eleven working tools" would be the wrong claim.

The array and the directory are two hand-maintained halves. They agree because a
test globs the directory —
`BinSugarcrushWiringTest::testBootstrapToolsShipsAWriteToolAndTheWholeBuiltInSet()`
— not because anything derives one from the other. That mechanism exists because
`Write` was once written, tested, named in the README, and unreachable from any
real run. If you add a tool class, the thing that tells you to wire it is a red
test.

`Grep`, `Glob`, `Read`, `Edit` and `Write` resolve through `Tools\PathJail` and
refuse a path outside the root. **`Bash` is deliberately not jailed** — which is
why `BashEscapeDenyHook` exists as an opt-in heuristic, and why it says in its
own source that it is not a security boundary.

---

## Providers

`ProviderFactory::availableTypes()` (line 311) returns **seven** selectable
names, and each builds a different class. Measured by constructing every one of
them on this tree:

| `type` | Class actually built |
|---|---|
| `openai` | `OpenAIProvider` |
| `anthropic` | **`CustomProvider`**, named `anthropic` |
| `claude-code` | `ClaudeCodeProvider` (over `ClaudeCodeInvocation`) |
| `sglang` | `SglangProvider` |
| `bedrock` | `BedrockProvider` |
| `vertex` | `VertexProvider` |
| `custom` | `CustomProvider` |

Two rows are easy to get wrong, so they are worth stating flatly.
`anthropic` does **not** go through `ClaudeCodeProvider`:
`ProviderFactory::createAnthropic()` (lines 564-595) builds a Guzzle client
carrying `x-api-key` + `anthropic-version` — the Messages API authenticates with
those, not with a bearer token — and hands it to a `CustomProvider`. And
`claude-code` is a **separate, seventh** provider, not an implementation detail
of `anthropic`: `createClaudeCode()` at line 601 returns the real
`ClaudeCodeProvider`, and `php bin/sugarcrush models` prints
`claude-code claude-sonnet-4-6` as its own row.

**`echo` is not one of the seven.** `$factory->create(['type' => 'echo'])`
raises `Unknown provider type: echo`. `EchoProvider` is nonetheless live, from a
different direction: `Cli\Bootstrap::provider()` (line 1292) returns
`new EchoProvider()` whenever the run selected no provider, or the selected one
threw while being constructed. So "echo" in the status bar is a degradation
path, not a configuration you can ask for by name.

`ProviderFactory` reads vendor credential variables and expands `${VAR}` /
`${VAR:-default}` placeholders in provider config values.

`ToolCallParser/` exists because not every OpenAI-compatible endpoint emits tool
calls the same way. Three strategies: `openai` (read the server's parsed
`tool_calls[]`), `minimax-xml-fallback` and `dsml`, the last two recovering a
model's native tool-call syntax from the message content when the server was
launched without a `--tool-call-parser` flag. Both fallbacks delegate to
`openai` whenever `tool_calls[]` is present. With no name configured the
strategy is derived from the model — `dsml` for the DeepSeek-V4 family. Only
`SglangProvider` consults any of this, on its streaming path as well as its
batch one; `CustomProvider` and `OpenAIProvider` take no `toolCallParser` at
all.

**No blanket total-request timeout is applied to a provider call**, anywhere. A
completion can legitimately run for tens of minutes.
`SUGARCRUSH_CONNECT_TIMEOUT` (15s) bounds the connect phase only.

---

## Sessions and state

| Directory | Class | Holds |
|---|---|---|
| `~/.sugar-crush/session.db` | `Session\EnhancedSessionStore` (PDO/SQLite) | transcripts, checkpoints, titles |
| `~/.sugar-crush/memory/` | `Memory\MemoryStore` | markdown + frontmatter, per scope |
| `~/.sugar-crush/teams/` | `Agents\TeamManager` | team state |
| `<workflowsPath>/.running/` | `Workflows\WorkflowEngine` | pause files |

`Sessions\Background*` runs a task in a detached session (`/bg`, `/fork`) with
its own runner and supervisor. `Context\ContextCompactor` +
`IdleCompactionPolicy` drive `/compact` and automatic compaction against
`ContextWindow`.

---

## The TUI layer

`src/Tui/` holds the presentation: `Renderer` (also `src/Renderer.php` for the
chat transcript), `Pane`/`SplitLayout`/`MultiplexerSplitPane`,
`SessionTabs`, `AgentOutputPane`/`AgentStatusBar`/`AgentViewPane`,
`KeyboardHandler`, `DiffGutter`, `StallDetector`, `TerminalBackground`.

Two invariants the layer depends on, both of which have been broken before:

- **The frame must clip to the terminal height, not merely pad to it.** An
  unbounded frame plus candy-core's absolute `cursorTo()` clamping is what
  produced the status-bar collision.
- **Never over-wide lines.** The diff renderer paints one line per row.

`WindowSizeMsg` is the size truth. Mouse tracking is on by default and has two
escape hatches (`SUGARCRUSH_DISABLE_MOUSE`, `SUGARCRUSH_DISABLE_MOUSE_CLICKS`)
because a terminal's own selection behaviour may be worth more to you.

**stdout belongs to the TUI.** That single fact explains a family of decisions
across the codebase: quiet-by-default skill skips, `@`-silenced hook config
reads, once-per-process latched warnings, and construction-time-only notices. A
stderr line written after the alt screen is up lands inside a frame the renderer
believes it owns.

---

## Dependencies

PHP `^8.3`. Beyond the SDKs (`openai-php/client`, `guzzlehttp/guzzle`,
`aws/aws-sdk-php`, `google/cloud-ai-platform`, `symfony/yaml`,
`react/promise`), ten SugarCraft siblings: `candy-core` (TEA runtime,
`Program`, `Model`, `Cmd`), `candy-forms`, `candy-sprinkles` (styles),
`candy-shine`, `candy-fuzzy`, `sugar-veil`, `candy-mosaic`, `candy-mouse`,
`candy-focus`, `candy-kit`.

`ext-sqlite3` is declared and **called by nothing in `src/`** — the session
store uses PDO. `doctor` probes `pdo_sqlite` for exactly that reason.

---

## Recurring shapes in this codebase

Four patterns worth recognising, because they explain otherwise-odd code:

1. **Built but unwired.** Several subsystems were finished, tested and reachable
   from nothing. Each one that has been found is now either wired or documented
   as a seam — never deleted. Live examples of the seam form: `SkillDiscovery`,
   `ForeignMemoryImporter`, `App::dispatchSkill()`, `LspTool`'s missing server
   config.
2. **Absence is a no-op; present-but-unusable is a refusal.** Applied to
   `config.json`, `hooks.yaml`, `.mcp.json`, `--config`, `--root`, and every
   `SUGARCRUSH_*` variable that carries policy.
3. **One resolution per question.** `HomeDirectory` for `~`, `ContainedPath` for
   containment, `HookConfig::pattern()` for matcher delimiters,
   `Bootstrap::mcpConfigDecision()` for the MCP verdict. Two implementations of
   one rule is how the two answers drift apart, and each of those classes exists
   because they had.
4. **A count carries its domain.** "Eleven tools" means wired built-ins.
   "Twelve skills" means directories under `src/Skills/BuiltIn/` that load.
   "Nine probes" means `doctor`. Numbers in this codebase's comments are
   written next to the thing they were measured on, and several of them are
   derived by a test rather than typed.

## See also

- [`ENVIRONMENT.md`](ENVIRONMENT.md) · [`PERMISSIONS.md`](PERMISSIONS.md) ·
  [`HOOKS.md`](HOOKS.md) · [`MCP.md`](MCP.md) · [`SKILLS.md`](SKILLS.md) ·
  [`AGENTS_AUTHORING.md`](AGENTS_AUTHORING.md) ·
  [`WORKFLOWS.md`](WORKFLOWS.md) · [`MEMORY.md`](MEMORY.md) ·
  [`COMMANDS.md`](COMMANDS.md) · [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md)
