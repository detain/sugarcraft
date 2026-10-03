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
                                         ├─ Tools\*              12 built-ins + MCP bridges
                                         ├─ Hooks\*              the PreToolUse chain
                                         └─ Permissions\*        the gate, last in that chain
```

Everything from `Chat` up renders; everything below it does work. Note the split
runs *through* `App` rather than above it — `App` is on both sides of that line,
which is the whole of the next warning.

---

## `bin/sugarcrush` — pre-flight before anything attaches to the terminal

The order in it is deliberate, and its size is whatever `wc -l bin/sugarcrush`
says today — this sentence used to carry a line count and quotes none on
purpose (E686: the figure rotted within rounds). `--help`, `--version` and the five
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
unknown flags → leftover operands (a bare directory becomes the root, anything
else is refused) → `--root` validation → `--config` validation →
`Bootstrap::useConfigPath()` → subcommands → `-p` one-shot → the TUI. Each
placement is load-bearing: `--config` must be validated *and registered* before
`doctor` runs, or `sugarcrush --config x.json doctor` would report the policy
from the discovered config.

A `PermissionConfigException` from anywhere inside the dispatch becomes exit 2
plus (under `--output-format json`) one error document — not a PHP fatal painted
over the terminal.

---

## `Cli\Bootstrap` — the wiring, all of it

One class, thousands of lines, every one of its methods static (E686: the size and
method-count figures this sentence carried had both rotted; the all-static
property is the load-bearing claim and is pinned live), and it is large on
purpose:
every backend, tool, session store, memory store, instruction loader, hook
manager, permission gate, skill registry, agent roster, workflow engine and MCP
client is constructed here, so a test can exercise the wiring without shelling
out to `bin/sugarcrush` and blocking on `Program::run()`.

Two consequences worth knowing:

- **`Bootstrap` is where trust decisions live**, because it is the only layer
  that knows which *directory* a file came out of. The four `trustedProject*`
  gates, the `$HOME` anchors and the containment checks are all here or reached
  from here — see [`PERMISSIONS.md`](PERMISSIONS.md) for the four, one of
  which (`trustedProjectSettings`) is defined on `LayeredSettings` rather than
  on `Bootstrap`.
- **`Bootstrap` is where the launch refuses.** Anything "configured but
  unusable" throws `PermissionConfigException` from construction, before the
  alt screen exists — which is also why its warnings go to stderr at
  construction time and are latched once per process. The warnings that name a
  capability the session LOST also get a system row in the transcript, because
  the alt screen paints over stderr 0.47s later; see
  `Bootstrap::warnPermissionConfigInTranscript()`.

**`Bootstrap::chat()` is two halves.** `Bootstrap::workspace()` builds the
half that is not a screen — config, gate, skills, command loader, the `/rules`
set, the agent manager and `Task` pool, the backend and its provider-switch
factory, memory, hooks, the workflow engine and the runtime-notice inbox — as
one `Host\WorkspaceContext`. `chat()` calls it first, then opens the session and
builds the `Chat`, which holds the workspace too. That is the first step of the
headless-host extraction (server mode): a host without a TUI builds the same
workspace, so the two cannot drift. Two rules come with it:

- `WorkspaceContext::backendFor()` always threads the agent manager and the
  `/rules` set, so a provider switch keeps `Task` on every path.
- Later `Host\*` services register on the workspace (`withService()`) and
  `Chat` reads them through `WorkspaceContext::service()`, so moving a service
  out of `Chat` never adds a constructor parameter back.

The runtime-notice inbox is per session as well. `Diagnostics\NoticeSink` holds
the queue and the cross-fork transport. `RuntimeNoticeSink` keeps its static
surface as a facade over whichever sink is current — the process sink in a TUI,
or the one a host selects with `RuntimeNoticeSink::using()`. A forked turn child
pins the sink it was forked under (`RuntimeNoticeSink::enterForkedChild()`) and
never reads an inbox, so two sessions in one process cannot see each other's
warnings.

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
palette, session tabs, the permission prompt, and the dispatch arms for 25
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
> `Runtime::run(App $app, …)` (`src/Runtime.php`) and `EngineBackend` both
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
  fork used to SIGKILL legitimate multi-step tool work mid-flight. Tool waits
  beat too, at most once a second: a parallel group while it polls its
  children, and a call run alone when its tool implements `AcceptsHeartbeat`
  (`Bash`, `Grep` and every `mcp__*` bridge), so a long build or MCP call is
  measured by its own `timeout`, not killed as a hung turn at the ceiling.
- A frame is capped at 64 MiB, because a frame legitimately carries raw image
  bytes but a corrupt header must not make the parent buffer an arbitrary length.
- Child reaping is a bounded 100 ms `WNOHANG` poll, with escaped PIDs tracked and
  swept at the top of the next turn. A blanket `pcntl_waitpid(-1, …)` would be
  actively harmful: `Chat::executeToolsParallel()` and
  `BackgroundSessionRunner` both wait on their *own* PIDs in the same process.

The socket carries frames **both ways**. The child streams the turn up; on a
turn started with `EngineBackend::completeInteractive()` (the
`Backend\InteractiveTurn` capability) it can also put a permission question to
the parent and block until the answer comes back down. Every frame is a 4-byte
big-endian length plus a `serialize()`d array, decoded with
`allowed_classes => false`:

| Direction | `kind` | Carries |
|---|---|---|
| child → parent | `token`, `reasoning` | assistant text / thinking deltas (an empty `reasoning` is a heartbeat) |
| child → parent | `started`, `finished`, `subagent`, `spend_cap` | tool and sub-agent events, in turn order |
| child → parent | `result` | the settled reply, usage and flags, the reply's `stepId` and the turn's `transcript` rows; always the last frame |
| child → parent | `ask` | `askId`, `toolCallId`, `tool`, `arguments` (after any hook rewrite), `reason`, `source`, `mode`, `suggestions`, `alwaysScope` |
| parent → child | `ask_reply` | `askId`, `reply` (`once`/`always`/`reject`), `note` (≤ 2 KiB) |
| parent → child | `steer`, `cancel_soft`, `cancel_tool` | reserved: parsed and buffered by the child, not sent yet |
| child → parent | `steer_ack`, `usage`, `step` | reserved: writable by the child, not sent yet |

- `askId` is the first 16 hex digits of a hash over `{toolCallId, tool, args}`.
  The parent hands each question to `$onEvent` as an `Events\PermissionAsked`
  carrying a `Backend\PendingAsk`; `PendingAsk::reply()` writes the
  `ask_reply` through a non-blocking write buffer. Each question settles once
  and is reported as `Events\PermissionResolved`.
- **The idle ceiling is paused while any question is open** and re-armed when
  the last one is answered: a child waiting on a person is silent by design.
- The child has no deadline of its own. A cancel or teardown settles every
  open question `cancelled`, and a reply after that is a no-op. EOF while the
  child waits is an unanswered question, so the call is refused.
- `always` is offered only when the permission gate alone asked. The child then
  remembers it for the same call for the rest of the turn.
- `Backend\ChildChannel` is bound to the turn child's pid. A parallel Task
  grandchild that inherits it is refused rather than allowed to interleave
  frames on the turn's socket.
- Plain `completeAsync()` never attaches the channel. Its asks settle in the
  child exactly as before, through the attached approver or the fail-closed
  no-approver refusal. Without ext-pcntl, an interactive question can only be
  answered synchronously, inside the `$onEvent` call that delivers it.

---

## `Runtime` — the agentic loop

`Runtime::run()` is a generator, and it resolves **exactly one** assistant turn
plus that turn's tool calls: call the model, execute the tool calls it returned
through the hook gate, yield the results. It has no step counter — every
`maxSteps` mention inside `src/Runtime.php` sits in a doc-comment, and
`Runtime::__construct` takes no such parameter.

**The multi-step ceiling belongs to the caller, not to `Runtime`.**
`private readonly int $maxSteps = 1000` is a constructor parameter of
`src/Backend/EngineBackend.php`, and the bound it arms is the loop
`for ($step = 0; $step < $this->maxSteps; $step++)`
— the loop that feeds each turn's tool results back and re-runs the `Runtime`
until the model answers without tools. `EngineBackend::withMaxSteps()` clamps
its argument with `max(1, $maxSteps)`, so the ceiling can be raised or lowered
but never set to zero, which would make a turn produce nothing at all.

Two brakes ship with that ceiling, both in `EngineBackend` and neither in
`Runtime`. The **repeat-call loop guard** (`Backend\ToolCallLoopGuard`) keeps a
per-turn ledger keyed on tool name + canonical arguments + a hash of the
result, and rides the hook chain as a fresh `RepeatCallGuardHook`
(`PreToolUse`, refuses) / `RepeatCallCountHook` (`PostToolUse`, counts and
warns) pair registered on a per-turn copy of the manager: the 3rd identical
call gets a warning appended to its result, the 5th is refused, the 8th ends
the turn. When the budget runs out, or the guard ends the turn,
`summariseStoppedTurn()` makes one more `Runtime::run()` with
`App::withTools([])` and a user message asking for done / remaining / next, so
the turn ends in an answer.

The loop's ordinary exit — a step with no tool results — has one more check in
front of it. A reply with no text is not an answer: a reasoning-only reply gets
one nudge (a `UserMessage` asking for an answer or a tool call, appended after
it), and a fully empty reply is re-requested up to twice with nothing appended.
Each extra call needs a step left and must clear the spend cap; once they run
out the turn ends as before, so a delegated `Task` still reports "ended without
a final report".
(E686 tranche-8: every line-number anchor this paragraph carried had rotted
within rounds — the page's own rule is to cite symbols by name, never by line.)

The two type worlds meet at the `EngineBackend` seam: the chassis works in the
root `Message`/`ToolCall` value objects, the engine in the typed
`Messages\*`/`Tools\*` hierarchy, and lossless adapters live on the chassis side
only so no dependency cycle is created.

**A finished turn is replayed as it happened.** `runTurn()` returns the reply
with `Message::$turnTranscript`: the rows the turn added, one step at a time.
Each step's assistant row carries its tool calls, its narration and its
reasoning; each tool result follows under its call id; a harness-written prompt
(the reasoning-only nudge, the summary request) rides along. Every row carries a
`stepId` that is fresh per turn. When the last step is a tool-free answer, the
reply itself is that step and carries its `stepId`. On the forked path the rows
cross the `result` frame as `Message::jsonSerialize()` arrays; past a quarter of
the frame cap, the tool output stays behind and a marker crosses instead, since
the `finished` frames already carried it. Chat's settle arm calls
`Message::settleTurnTranscript()`. It matches each result to the tool row the
live events drew, by call id inside the turn's window, and stamps that row with
its step. The step's assistant row goes in just before it with `userVisible`
false, so the transcript the user reads does not change. When the reply is not
the last step (a spend-cap or step-ceiling stop), the reply becomes `uiOnly`, so
its words are not sent twice. `EngineBackend::toTypedMessages()` then rebuilds
each step as an `AssistantMessage` with its calls, followed by one
`ToolResultMessage` per result. It follows Zed's rules: an empty result is sent
as `<Tool returned an empty string>`, and a call nothing answers as
`Tool canceled by user`. A row without a `stepId`, such as one from an older
transcript, still replays as prose. `Messages\HistorySanitizer` renames a call
id that an earlier step of an older transcript already used, and moves the
results that answer it, so each result stays paired with its own call.

### The system prompt, in assembly order

`Runtime::systemPromptSections()` returns the prompt as an ordered list of
sections — eleven slots, though a session that qualifies none of the optional
ones assembles fewer — and `buildSystemPrompt()` folds that list into the
string the model receives. The order of record, each layer named as the code
names it:

1. the base instructions (a heredoc, each clause naming the code that makes it
   true *and* the limit past which it stops being true);
2. `MaximsSection` — the `core.maxims` voice layer, unfenced like the base
   because its bytes are a class constant with no untrusted input in them;
3. the tool-guidance layer — one fragment per wired tool that implements
   `PromptGuidance`, ordered by `name()`; the slot appears only when at least
   one fragment is non-empty;
4. `RepoMapBlock` — a `<repo-map>` of the workspace's Composer sub-packages and
   its PSR-4 source directories, memoized per session;
5. `<user-rules>` — the user-tier rule files `RuleLoader` returns, each in its
   own fence behind the authority preamble;
6. `<project-instructions>` documents — `CLAUDE.md` / `AGENTS.md`, with
   `@import`s expanded, via `InstructionFileLoader`, escaped by `PromptFence`;
7. the repository's own project-tier rules from `RuleLoader`, same
   `<project-instructions>` fence as the documents immediately above them;
8. `MemoryBlock` — `user`-scope notes first (at most 4 / 1 KB), then
   `project`-scope entries, fenced `<project-memory>`;
9. explicitly enabled skills' full bodies;
10. `SkillMatcher::listForPrompt()` — name + description for every discovered
    auto-invocable skill, each line badged with its tier (`[built-in]`,
    `[user]`, `[project]`), fenced `<available-skills>` behind its preamble;
11. `EnvironmentBlock` LAST — its static half: cwd, git-repo flag, platform,
    OS, PHP, model, date, memoized per session. The git status, log and diffs
    left the system prompt at step 1.A-1 for the `<turn-context>` row below.

Item 10 is what makes the `Skill` tool worth having: without the listing, the
model has no reason to call it, and a populated registry would still be
un-triggerable.

Items 3 and 4 are the derived-fact half and items 5 through 8 the
authored-convention half, which is why the map sits where it does: it is the
same kind of thing the base is — fact derived from the repository, not
convention an author wrote down — every line in it is a path the model resolves
against the working directory the env block names, and the conventions after it
talk about both. `RepoMapBlock` is deliberately generic — it reads
`composer.json` files, not this repository's `docs/MATCHUPS.md`. It maps a flat
Composer workspace (this repository's shape), a single library, and a
`packages/*` monorepo whose root manifest declares those packages as path
repositories. Its floor is that it never *searches* for manifests: it opens the
root's immediate children and the directories the root manifest names, so a
nested layout that does not declare itself is not found. Its own docblock
records that decision and what it costs.

The ordering is a caching decision, not a stylistic one, and it is the P3.S1
invariant recorded in `Runtime::buildSystemPrompt()` and restated in
`MemoryBlock`'s own source: sections run stable-first, by mutation frequency,
and the env block sits **last**. It sat last because it polled
`git status --porcelain` on every call, and any earlier position would have
voided the cacheable prefix of every layer behind it from the first edit of a
session. Step 1.A-1 removed the volatility instead: the system prompt is now
byte-identical across the steps of a session, and what changes while the agent
works travels outside it.

- **`<turn-context>`** — `Runtime::turnContext()` renders the git section
  (`EnvironmentBlock::renderVolatile()`), the files the agent's Edit/Write
  calls touched and, from 60%, the context-window share, and `Runtime::run()`
  appends it as the request's LAST row, user-role, only when its bytes differ
  from the latest such row in the history. Persisting it into the history is
  step 1.A-2.
- **In-place notices** — `SglangProvider` and `CustomProvider` keep one leading
  `system` row (the prompt plus any history system rows ahead of the first
  conversation row) and render every later history system row where it
  happened, as a user-role `<system-notice>` row, instead of hoisting it into
  message 0.
- **Per-session memo** — the PerSession layers (static `<env>`, repo map,
  memory, the standing instruction slab) are memoised per session through
  `Context\SessionPromptMemo`, frozen until the session is forgotten
  (`/clear`, compaction, another session).

The rationale behind these placement decisions, and the one freshness policy
for `CLAUDE.md` and the other standing layers, is written up in
[`PROMPT_ENGINEERING.md`](PROMPT_ENGINEERING.md).

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

`Task` is the one mutating tool that opts in, because concurrent delegation is
what it is for: several `Task` calls in one message run side by side, each a
full agentic run through the calling turn's own engine (see
`Tools\DelegatesToEngine`). It is also `Tools\ExemptFromParallelDeadline`, so the
90s deadline kills its seconds-scale siblings but not it; it bounds itself by
the preset's `maxTurns` and abandons its run once the turn that forked it is
gone. While a group is outstanding the parent keeps writing heartbeat frames,
so the turn's own idle ceiling does not kill a long delegation either.

Delegations are the one member a group caps: at most
`AgentPoolConfig::$maxConcurrent` (5 by default) `ExemptFromParallelDeadline`
members are alive at once, and the rest wait queued and are forked in provider
order as running ones exit (`Runtime::executeConcurrently()`). A queue costs a
delegation nothing because the group deadline never kills it; seconds-scale
siblings are never queued, so they keep the whole deadline for themselves.

A delegation that ends without a report — step cap reached, or a failure
part-way — is resumable: its typed transcript is saved on disk
(`Agents\SuspendedDelegations`; memory would not survive, since every turn and
every parallel `Task` runs in a fork) and the refusal names a `resume` id that
continues the same conversation through `EngineBackend::completeTranscript()`.
A step-capped run is the one that is no longer a refusal: the engine's no-tools
summary (see the agentic loop below) is returned as its report, with the
`resume` id appended.

---

## The gate chain

One chain, two live pipelines. `Runtime::gate()` (engine/provider path) and
`Chat::gateToolCall()` (Chat's own registered tools) both gate on the
`PreToolUse` hook chain, which is why the six-mode `PermissionGate` rides in as
a hook (`PermissionGateHook`) rather than being called separately: it reaches
both with no new dispatch machinery, and inherits the ASK plumbing they already
implement — a blocking prompt on Chat's side, and on Runtime's whatever approver
its caller attached. The console callers attach one (`-p` one-shot and the
background-session daemon, both via `Cli\HeadlessPermissionPrompt`); every other
caller, the TUI's own engine path included, attaches none and gets a fail-closed
denial. See [`PERMISSIONS.md`](PERMISSIONS.md#ask-needs-somewhere-to-ask).

```
ProtectFilesHook → ConfirmRemoveHook → AuditHook → [hooks.yaml] → PermissionGateHook
```

`HookRegistry::executeHooks()` re-scans the whole chain against a MODIFY
rewrite, so a hook that rewrites `Bash{command:"ls"}` into
`Bash{command:"rm -rf /"}` is re-evaluated rather than slipping past the gate
behind it. See [`PERMISSIONS.md`](PERMISSIONS.md) and [`HOOKS.md`](HOOKS.md).

---

## Tools

`src/Tools/BuiltIn/` holds **twelve** concrete `Tool` classes: `Bash`,
`Doctor`, `Edit`, `Glob`, `Grep`, `LspTool`, `Read`, `SkillTool`, `TaskTool`,
`WebFetch`, `WebSearch`, `Write`. `Bootstrap::tools()` ships all twelve —
`Task` last, gated on the launch holding an `AgentManager` — plus one
`McpToolBridge` per advertised MCP tool.

Domain matters here: **twelve is the count of *wired* tools, not of *usable*
ones.** `LspTool` is reachable and answers every call with a "no language server
configured" error, because nothing in `src/` reads a server command. A figure
saying "twelve working tools" would be the wrong claim.

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

`ProviderFactory::availableTypes()` returns **seven** selectable
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
`ProviderFactory::createAnthropic()` builds a Guzzle client
carrying `x-api-key` + `anthropic-version` — the Messages API authenticates with
those, not with a bearer token — and hands it to a `CustomProvider`. And
`claude-code` is a **separate, seventh** provider, not an implementation detail
of `anthropic`: `createClaudeCode()` returns the real
`ClaudeCodeProvider`, and `php bin/sugarcrush models` prints
`claude-code claude-sonnet-4-6` as its own row.

**`echo` is not one of the seven.** `$factory->create(['type' => 'echo'])`
raises `Unknown provider type: echo`. `EchoProvider` is nonetheless live, from a
different direction: `Cli\Bootstrap::provider()` returns
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

**Transcript rows have an identity.** `EnhancedSessionStore::saveTranscript()`
writes transcript schema version 2: every row has an id, `m_<session>_<ref>`, and a
ref, a short number that is monotonic per session and never reused, from
`Support\MessageIdAllocator`. The state also records `nextRef`, the high-water
mark, so a ref dropped by compaction or `/rewind` is never given out again. A row
that already carries its identity keeps it. For a row that does not, the identity
goes into the state's `identities` map instead of the row's bytes, so the row
still shares its checkpoint blob. `loadTranscript()` folds the map back in. It
migrates a version-1 transcript, or the checkpoint a pre-transcript session
resumes from, in order (refs 1, 2, 3, …), and the first save after the resume
persists those refs. `Message` also carries `stepId`, the engine step a row came
from, and `userVisible`, which hides a row from the transcript without hiding it
from the model (the twin of `uiOnly`). Legacy rows have no `stepId`.

Each `sessions` row carries a `Session\SessionKind` (`main`, `branch`,
`subagent`, `background`) and an optional `parent_id`: `/branch` records the
session it forked from, and child rows record the session that spawned them.
Only `main` and `branch` rows reach the tab strip, the default list and
`--continue`; archived rows (`archived_at`) are hidden too. The rest are
reached through their parent (`childrenOf()`) or a `Session\SessionQuery`
passed to `listSessionsFiltered()`, which returns typed `Session\SessionRow`
values. Deleting a session takes its sub-agent children with it and detaches
its branches. Retention spares named and pinned rows. `title_source`
(`Session\TitleSource`) records whether a name came from the user or the
auto-titler, and the titler never overwrites a user's name. The columns are
added to an older database the next time it is opened.

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

`SplitLayout`/`MultiplexerSplitPane` are no longer a standalone seam:
`Tui\Renderer::renderView()` splits its content band whenever
`AgentManager::liveOutputs()` is non-empty and the terminal is at least 80
columns, putting a `Components\AgentSplitColumn` of live-agent tiles to the
right of the shell band. Activation is data-driven — no flag, no config key —
because `liveOutputs()` reports exactly the agents with a non-terminal
sub-agent that has produced text, so it is empty both before work starts and
after it finishes.

**It is wired, and it is now visible** — but it took two fixes, not one, and
the first was documented here as the whole story. The only production producer
of that data is a workflow's parallel stage, and that stage was invisible for
two independent reasons:

- **No frame.** `Chat::workflowRun()` called `WorkflowEngine::run()`
  synchronously from inside `update()`, so candy-core's render tick could not
  fire between the keystroke and the last stage. It now hands the run to a
  `\Fiber` that a periodic timer on the ReactPHP loop steps, suspending at
  `AgentWorkerPool::idle()`. (The `stream_select()` this paragraph used to
  blame runs in the forked CHILD; it never blocked the parent.)
- **Nothing to paint.** Even with a frame, the map was empty for the whole of
  a run: on the pool path `SubAgent::$output` had exactly one writer,
  `AgentManager::drain()`, which settles the final text at the same instant it
  makes the status terminal. A pool-executed sub-agent was therefore always
  either running-and-silent or finished, and `liveOutputs()`'s liveness filter
  could never see one. Forked workers now publish each streamed chunk to a
  file in the pool's IPC directory and `AgentWorkerPool::pumpProgress()`
  mirrors it onto the SubAgent once per poll.

Fixing only the first would have produced a pane that painted promptly and
blank, which is why the second is worth stating separately.

⚠️ This paragraph used to close by citing "issue #79". No open issue ever
tracked any of it — detain/sugarcraft #79 is a MERGED pull request titled
"Phase 9+: CandyMetrics — telemetry primitives + CandyWish middleware".

The live path is exercised by `tests/Workflows/WorkflowLivePaneTest.php`; the
compositor in isolation by `tests/Tui/AgentSplitCompositorTest.php` and by an
embedder driving `AgentManager` directly. What is still NOT live: the three
dispatch paths that do not fork — an injected `ExecutorInterface`, a build
without `pcntl`, and a failed `fork()` — call `execute()` synchronously in the
parent and publish no progress, so they block the loop and leave the pane
blank for their duration.

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
`react/promise`), thirteen SugarCraft siblings: `candy-core` (TEA runtime,
`Program`, `Model`, `Cmd`), `candy-forms`, `candy-sprinkles` (styles),
`candy-shine`, `candy-fuzzy`, `sugar-veil`, `sugar-mcp` (stdio MCP transport),
`candy-mosaic`, `candy-mouse`,
`candy-layout` (dock geometry), `candy-focus`, `candy-kit`, `candy-pty`
(the pseudo-terminal interactive tool output is captured through).

`ext-sqlite3` is declared, and `src/` constructs it in exactly one place:
`Agents\TaskList`'s task database. The session store reaches SQLite through
**PDO**, which is why `doctor` probes `pdo_sqlite` rather than the extension.

---

## Recurring shapes in this codebase

Four patterns worth recognising, because they explain otherwise-odd code:

1. **Built but unwired.** Several subsystems were finished, tested and reachable
   from nothing. Each one that has been found is now either wired or documented
   as a seam — never deleted. Live examples of the seam form: `SkillDiscovery`,
   `App::dispatchSkill()`, `LspTool`'s missing server config.
   (`ForeignMemoryImporter` was on this list until P7.S6 wired it behind
   `/memory import`.)
2. **Absence is a no-op; present-but-unusable is a refusal.** Applied to
   `config.json`, `hooks.yaml`, `.mcp.json`, `--config`, `--root`, and every
   `SUGARCRUSH_*` variable that carries policy.
3. **One resolution per question.** `HomeDirectory` for `~`, `ContainedPath` for
   containment, `HookConfig::pattern()` for matcher delimiters,
   `Bootstrap::mcpConfigDecision()` for the MCP verdict. Two implementations of
   one rule is how the two answers drift apart, and each of those classes exists
   because they had.
4. **A count carries its domain.** "Twelve tools" means wired built-ins.
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
