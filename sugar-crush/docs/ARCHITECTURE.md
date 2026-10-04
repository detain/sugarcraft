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
      │      --help --version, seven subcommands, -p one-shot
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
                                         ├─ Tools\*              17 built-ins + MCP bridges
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
purpose (E686: the figure rotted within rounds). `--help`, `--version` and the seven
subcommands (`doctor`, `models`, `session list|delete`, `mcp list`,
`serve`, `attach`, `completion bash|zsh|fish`) are answered **before** `Program`, `Bootstrap::app()`
or `NonInteractive` is reached, because every one of them but `attach` is a question about
the *install* rather than a turn of conversation: they must answer on a machine
with no provider, no API key and no TTY. `serve` is the one that does not exit:
it runs the WebSocket server ([SERVER.md](SERVER.md)) on the same ReactPHP loop
the engine's forked turns use, and still never constructs `Program`. `attach`
is the one that does: it connects to a running server first, and only then
builds the usual `Bootstrap::app()` and `Program` over that server's session,
its turns sent there by `Backend\RemoteBackend`.

`doctor` is the sharpest case — it diagnoses an install that may be broken, so it
must not require the thing it is diagnosing. Each of its ten probes catches its
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
palette, session tabs, the permission prompt, and the dispatch arms for 37
built-in slash commands.

`Chat` is **standalone-runnable**. Every collaborator is optional and degrades to
a "<thing> not configured" message rather than throwing —
candy-core's `Program` has no try/catch around its synchronous `update()`
dispatch, so an exception there propagates out of the event loop entirely,
skipping terminal teardown and leaving the real terminal in raw/alt-screen state.
That is why `/agents`, `/workflow` and `/memory` all answer politely on an
unwired `Chat`.

**A turn runs in `Host\TurnRunner`, and its tool events become rows in
`Host\TranscriptProjector`.** `Chat::scheduleBackendCompletion()` reads the
fields a dispatch needs and wraps `TurnRunner::start()` in a `Cmd`; the runner
wires the backend for that one dispatch (spend cap, compactor config, session
id, the session's "always" grants), hands the backend its four callbacks, and
settles the promise into the `AssistantMsg` or `BackendToolEventsMsg` `Chat`
folds. The callbacks still queue onto the `Chat`'s live inbox, so the live pump
keeps the backend's event order. Both of `Chat`'s folds — the live pump and the
settled queue — build their rows through the projector: the running
placeholder, the newest-first replace by call id, and the finished-row shape.
A host without a screen therefore writes exactly the rows the TUI writes. Both
services are reached through `Host\WorkspaceContext::service()`; without a
registered runner, `Chat` uses the one keyed to its own inbox
(`TurnRunner::of()`), so no constructor state was added.

The runner is also the first writer of the session's event log
(`Host\EventLog`). Each dispatch is bracketed by a durable `turn.started` and
`turn.completed`. Between them, each fold reports what it did — tool start and
finish, the permission question and its answer, a delegated run's start and
finish, step usage, a spend-cap stop — and the settle reports
`assistant.completed`. Every row is named by the id its save keeps
(`Host\TranscriptStore::identify()`). Durable events are written before any
`TurnRunner::listen()` listener hears them. Streaming deltas are only heard,
never logged. A failed write or a throwing listener drops the event, never the
turn.

**What a submitted line becomes is `Host\TurnController`'s.** Whether a line
typed mid-turn is steered into the running turn, queued, or refused; what a
`!cmd` and its refusal read; a command file's expansion (the `/name:arg`
spelling, the project-tier shell refusal, the gate, the fork that takes a
shell form off the thread); the two turn hooks (their context, gate-first
order, notes and refusals, and the fork for a script hook); the prompt's
mentions; the inline 85%/95% tier's synchronous heuristic; and the dispatch's
bookkeeping — the `/rewind` checkpoint of the state before the prompt, the
picker's turn count, and the durable `message.created` that names the prompt
row ahead of `turn.started` — are its logic. `Chat::submit()`,
`::dispatchTurn()`, `::dispatchTurnHooks()` and `::releaseQueuedPrompts()`
keep the Msg plumbing (which `mutate()` a route commits, which `Cmd` it
returns, when a parked submission re-enters `submit()`) and call it through
`Host\WorkspaceContext::service()`; without a registered controller, a fresh
one, since it is stateless.

**`Host\SessionHost` drives a session without a screen.** It admits a
submission through the same controller, runs the turn through the same
`TurnRunner`, folds its events through the same `TranscriptProjector` and saves
through the same `TranscriptStore`, so a server and the TUI cannot disagree
about what a prompt becomes or what the event log says about it.
`submit(string, SubmitOptions): TurnTicket` answers with the admission
(`started`, `queued`, `steered`, `pending` behind forked hooks or an
expansion, `handled` for a command it ran, or `refused` with the sentence the
TUI would write), `snapshot()` gives a client the rows, status, queue and the
log seq to follow from, `cancel()` heals running tool rows exactly as Esc Esc
does, and `pump()` folds the turn's live events on the host's own tick. Slash
commands run through the same `Host\Commands` bodies the TUI's handlers
delegate to: each spec file in `builtin-commands/` names its `hostCommand`,
whose `run()` answers with `CommandResult{rows[], effects[]}` that `Chat` and
the host each apply in their own terms. `runCommand(name, args)` is the wire
door; a screen-only command (`/theme`, `/pane`, the pickers) or one whose logic
is still in `Chat` answers `CommandResult::clientOnly()` (`-32030`), and `!cmd`
runs off the loop, holding the session as it does in the TUI. What it does not
do yet: the 85% tier compacts with the heuristic only — the model-written
route is the TUI's. `Host\SessionHub`
owns the open hosts of one workspace: opening a session loads its transcript
and takes the same `SessionLock` a TUI takes, so a session another process
holds is refused, and only an idle host is ever evicted past the open-session
cap.

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
| child → parent | `result` | the settled reply, usage and flags, the reply's `stepId`, the turn's `transcript` rows and the session's `contextLedger` as the turn left it (absent when the turn was handed none); always the last frame |
| child → parent | `ask` | `askId`, `toolCallId`, `tool`, `arguments` (after any hook rewrite), `reason`, `source`, `mode`, `suggestions`, `alwaysScope` |
| parent → child | `ask_reply` | `askId`, `reply` (`once`/`always`/`reject`), `note` (≤ 2 KiB) |
| child → parent | `step` | `step`, `maxSteps`, `context` (the step's `ContextPressure` as an array); written before each provider call |
| child → parent | `usage` | `step`, `usage` (that response's), `turnUsage` (the turn's running total); written as each response is billed |
| parent → child | `cancel_soft` | — : stop at the next step boundary, once the step's tools have finished |
| parent → child | `steer` | `steerId`, `text`: a message the user sent mid-turn (`CancellationToken::steer()`), drained at the next step boundary by `Backend\SocketSteerInbox` and appended as a `[steering] …` user row; once one is waiting, the step's unstarted sequential calls are answered `Skipped to process an incoming message.` |
| child → parent | `steer_ack` | `steerId`, `step`: where the steer landed (`CancellationToken::acknowledgedSteers()`) |
| parent → child | `cancel_tool` | `callId`: stop that running call now (`CancellationToken::cancelTool()`); sent ahead of the same Escape's `cancel_soft` |

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
  frames on the turn's socket; its questions take the ask relay below instead.
- Plain `completeAsync()` never attaches the channel's approver. Its asks
  settle in the child exactly as before, through the attached approver or the
  fail-closed no-approver refusal. Without ext-pcntl, an interactive question
  can only be answered synchronously, inside the `$onEvent` call that delivers
  it.
- Every forked turn carries the channel for the per-step frames. The turn
  loop's `$onStep` writes `step` (an `Events\StepStarted`) and `usage` (an
  `Events\UsageUpdated`), and the parent hands them to `completeAsync()`'s /
  `completeInteractive()`'s `$onStep`. Chat shows the step, the step's context
  figure once it is over budget, and the turn's spend so far on the status bar.
- **Soft cancel.** `CancellationToken::cancelSoft()` asks for a stop at the next
  step boundary: the parent's cancel poll writes one `cancel_soft`, and the
  loop, which asks `ChildChannel::softCancelRequested()` after each step's
  tools have settled, ends the turn there as a deliberate exit — no
  step-ceiling notice, no summary request — and the turn settles with its
  reply. In the TUI the first Escape of a turn that reports steps is the soft
  cancel; the next Escape, or a second one inside the double-press window, is
  still the hard cancel that kills the whole tree.
- **Tool cancel (1.C-4b).** The same first Escape also names every call that is
  running (`CancellationToken::cancelTool()`), and the parent's cancel poll
  writes one `cancel_tool{callId}` per call. In the child,
  `ChildChannel::takeToolCancels()` feeds `Support\ToolCancelRequests`, which
  answers only in the turn child. The concurrent reap loop kills that member's
  process tree (or never starts a member still queued for a delegation slot)
  and settles it as `Cancelled by the user (Esc) while it was running.`, with a
  relayed run closed as `cancelled`; its siblings carry on. A lone `Task` stops
  itself at its sub-agent's next tool start or provider step
  (`Support\ToolCallCancelled`), cancelled and resumable. A sequential tool run
  in the turn child itself has no stop point and runs to its end, after which
  the soft cancel ends the turn.

**The `subagent` frame (version 2).** `Events\SubAgentActivity::toArray()` is
the one wire shape for a delegated run's beats; the fork frame, the relay
below and later the server's `agent.*` events all carry it, and
`SubAgentActivity::fromArray()` is its one validator. A frame without `v` is
a version 1 frame and decodes with the version 2 fields at their defaults.

- `op` is `queued`, `started`, `progress` or `finished`. A `Task` member held
  back by the delegation cap gets a `queued` beat under a placeholder id; its
  own `started` beat, naming the same `parentCallId`, replaces it.
- `parentCallId` is the parent's `Task` tool-call id. `description` is that
  call's `description` argument.
- `items` holds what the run did since its last frame: `tool_started` (with a
  one-line `Agents\Live\ToolSummary`), `tool_finished` (`ok`, `ms`),
  `thinking` (a marker, no text), `text` and `inbox`.
  `Agents\Live\SubAgentActivityBuffer` coalesces them in the process running
  the sub-agent: at most 4 frames a second, 32 items and 8 KiB of items per
  frame. Older text goes first, then older tool events.
- `stats` holds `step`, `maxSteps`, `tools`, `tokensIn`, `tokensOut`,
  `costUsd` and `startedAt`.
- `finished` carries the real `outcome` (`complete`, `failed`, `cancelled` or
  `empty`), its `error` and the `resumeId` a later `Task` call resumes it by.
  `AgentManager::projectRemoteSubAgent()` settles a failed run FAILED and a
  cancelled one STOPPED, where every run used to settle COMPLETE.
- The version 1 fields stay: `tail` and the running totals still feed the
  Agents pane.

**Sub-agent activity relay.** A lone `Task` call runs inside the turn child,
so its `subagent` beats go straight onto the turn socket. Several `Task` calls
in one step run as a concurrent group: each member is forked again by
`Runtime::executeConcurrently()`, and the emitter `EngineBackend::turnTools()`
binds is pinned to the turn child's pid, so it drops anything a grandchild
writes. The relay carries those beats instead:

- Before forking a member whose tool implements `Tools\StreamsActivity`, the
  turn child opens a unix datagram pair (`Support\SubAgentActivityRelay`). The
  grandchild rebinds the tool with `withActivitySink()` onto a
  `Tools\DatagramActivitySink`, one `serialize()`d beat per datagram, written
  non-blocking and dropped rather than stall the run. The pid pin stays, so a
  grandchild can never write the turn socket by accident.
- The turn child's reap loop waits on every member's relay with
  `stream_select()` (bounded by the same 2 ms poll), decodes each datagram with
  `allowed_classes => false` and replays it through the original emitter, so it
  reaches the parent as an ordinary `subagent` frame.
- A member's relay is drained after its process exits and before its
  `ToolFinished` is released, so the `finished` beat always lands first. If the
  process exited without one (killed, a fatal error, a dropped datagram), the
  turn child sends a `finished` for it, so the row does not stay "running". Its
  outcome comes from the member's result: `failed` when there is none or it is
  an error, `complete` otherwise.
- Every relay is closed in `executeConcurrently()`'s `finally` block. No
  process is added, only file descriptors.

**Permission ask relay (1.C-5).** The same grandchild cannot put a question up
the turn socket either. When the turn has an approver and a member's tool
implements `Tools\RelaysPermissionAsks` (`TaskTool`), the turn child opens a
unix stream pair (`Support\PermissionAskRelay`, the engine's length-prefixed
framing) before the fork:

- The grandchild rebinds the tool with `withPermissionApprover()`, which
  rebinds its engine's approver, and closes its inherited copy of the turn
  socket (`ChildChannel::releaseInherited()`). Each ask goes down as an `ask`
  frame (`askId`, the call, the question, who asked), and the grandchild
  blocks for the matching `ask_reply`. EOF means nobody answered.
- The reap loop reads every member's relay and puts each question to the turn
  child's own approver, the `ChildChannel` on a TUI turn, so it reaches the
  modal as an ordinary `ask` frame. The verdict goes back exactly as settled:
  `once`, `always`, `reject` with its feedback, or unanswered. Questions are
  settled one at a time, and the channel's turn-wide `always` memo covers
  siblings.

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
`summariseStoppedTurn()` makes one more request — the turn's last request plus
a user message saying not to call tools and asking for done / remaining / next
— so the turn ends in an answer. It goes through
`Context\Compaction\StepSummarizer::summaryStep()`, the one summary request the
engine makes: the step's tools stay advertised, so the request's whole prefix is
one the provider has cached (a tool-less request changed the tool block and
re-prefilled the turn), and they can never run, because the reply is taken the
moment `run()` yields the assistant message — before it dispatches a call — and
any calls it asked for anyway are dropped from it.

The loop's ordinary exit — a step with no tool results — has one more check in
front of it. A reply with no text is not an answer: a reasoning-only reply gets
one nudge (a `UserMessage` asking for an answer or a tool call, appended after
it), and a fully empty reply is re-requested up to twice with nothing appended.
Each extra call needs a step left and must clear the spend cap; once they run
out the turn ends as before, so a delegated `Task` still reports "ended without
a final report".
(E686 tranche-8: every line-number anchor this paragraph carried had rotted
within rounds — the page's own rule is to cite symbols by name, never by line.)

**Every step measures its own request before sending it.** Chat's context
tiers judge the conversation once, at submit, and a turn then grows by every
tool result it reads, so the request that would overflow is one no tier ever
saw. `Runtime::run()` takes an `$onRequest` observer that sees the assembled
`CompleteRequest` (system prompt, tool list, messages) just before the provider
call, and `runTurn()` measures it there as a `Context\ContextPressure`: on the
first step a script-weighted estimate of the whole request, system prompt and
tool schemas included (`TokenEstimate::ofToolSchemas()`); on every later step
the previous response's prompt as the provider counted it, plus an estimate of
only the rows the step added. The budget is `Context\ContextBudget`: the smaller
of 80% of the window and the window less `maxOutputTokens` less a reserve for
tool output (64k, capped at a fifth of the window), and the automatic
compaction tier's absolute cap (`CompactorConfig::$backgroundCompactionTokens`)
when one is configured. Every step is measured, with or without an observer;
the figure and its verdict ride the step's `Events\StepStarted` to `runTurn()`'s
`$onStep` observer (`completeTranscript()` takes one too, so a `Task` sub-agent
is measured — and relieved — the same way).

**An over-budget request is pruned before it is sent.** The observer cannot
change the request, so while relief remains it refuses it: it throws
`Context\Pruning\StepOverBudget`, which `run()` lets out before any provider call
or yield, and `runTurn()` rebuilds the step over a relieved
`Context\Pruning\ContextLedger`. The ledger records what is taken out of the
model's view; the rows themselves are never rewritten. `Runtime::buildMessages()`
projects every request through it (`Context\Pruning\ContextProjector`, then
`HistorySanitizer`), so an App with an empty ledger sends exactly what it always
did. The relief is `Context\Pruning\EmergencyPrune`, one batch of six
strategies. Four are path-keyed, matching calls by tool and canonical arguments
(`Context\Pruning\CanonicalArguments` — the `description` argument, key order
and `./` spellings never split a key): a call a newer identical call answered
again, or a `Read` a newer whole-file `Read` of the file covers
(`Strategies\DuplicateCallStrategy`); a `Read` older than a successful `Edit` or
`Write` of the same file, outside the last two user turns
(`Strategies\StaleReadStrategy`); the `content` argument of a `Write` a later
write or whole-file read of the file supersedes
(`Strategies\SupersededWriteInputStrategy`); and the arguments of a call that
failed four or more user turns ago, all but its main one
(`Strategies\ErroredInputStrategy`). Then the age rule
(`Strategies\ToolOutputAgeStrategy` — the last two user turns and the newest 40k
tokens of older tool output stay, `Task`, `Skill`, `Edit` and `Write` output is
never touched, older output becomes a placeholder) and the superseded
`<turn-context>` rows (`Strategies\SupersededTurnContextStrategy` — every one but
the newest). A call two rules name is pruned once, by the first. It is made only
when it frees at least 20k estimated tokens, because a prune rewrites bytes the
provider has cached: the ledger changes rarely and in bulk, never a little each
step.

**A step still over budget is summarised.** When the prune is not enough —
typically one long turn, whose output the age rule protects as the newest user
turn — `Context\Compaction\StepSummarizer::summarise()` asks for a summary of
everything the model has already been sent, through `summaryStep()` (the turn's
own model unless `SUGARCRUSH_SUMMARY_MODEL` names another).
The cut snaps back to the step that opens the unsent tail (`cutIndex()`), so no
call is separated from its result and that tail — the last step's calls and
results, the new state row, a mid-turn message — goes out verbatim. The summary
becomes a `Context\Pruning\CompressionBlock` written by the harness: the
projector replaces every row before that step with one user-role row,
`[Conversation summary b1 — …]` followed by the summary, and a later block
consumes an earlier one. A range under 10k estimated tokens, a failed call, an
empty reply or a summary no smaller than its source makes no block, and the
step goes out as it stands; past the spend cap no summary is asked for. Each
relief is tried at most once per step.

The ledger is the session's, not the turn's (roadmap 2.2-2). `Host\TurnRunner`
keeps it between turns and stores it beside the transcript
(`EnhancedSessionStore::saveContextLedger()`, table `context_ledgers`, deleted
with the session, copied by a branch, snapshotted by each checkpoint and put
back by `/rewind` and `/redo`). Each dispatch first forgets what names a row the
history no longer has (`ContextLedger::syncAgainstHistory()`), hands it to the
turn (`EngineBackend::withContextLedger()`, seeded onto the turn's App), and the
reply hands back the ledger the turn ended with (`Message::$contextLedger`, the
`result` frame's `contextLedger` key on the forked path), so the next turn's
first request is projected exactly as this turn's last one was. A delegated
sub-agent never inherits it. Chat's compaction applies the same age rule to the
exchanges it condenses (`ContextCompactor::removeToolResults()`, which never
touches the preserved tail).

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
sections — twelve slots, though a session that qualifies none of the optional
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
5. `SymbolMapBlock` — a `<symbol-map>`: the definitions the rest of the
   workspace references most, ranked by PageRank (roadmap 5.5-5), captured
   once per session by `EngineBackend` before a turn's fork; the slot appears
   only when the session holds a capture;
6. `<user-rules>` — the user-tier rule files `RuleLoader` returns, each in its
   own fence behind the authority preamble;
7. `<project-instructions>` documents — `CLAUDE.md` / `AGENTS.md`, with
   `@import`s expanded, via `InstructionFileLoader`, escaped by `PromptFence`;
8. the repository's own project-tier rules from `RuleLoader`, same
   `<project-instructions>` fence as the documents immediately above them;
9. `MemoryBlock` — an index of the notes, one line each: `user`-scope first
   (at most 4 / 1 KB), then `project`-scope, fenced `<project-memory>`, then
   the standing memory instructions;
10. explicitly enabled skills' full bodies;
11. `SkillMatcher::listForPrompt()` — name + description for every discovered
    auto-invocable skill, each line badged with its tier (`[built-in]`,
    `[user]`, `[project]`), fenced `<available-skills>` behind its preamble;
12. `EnvironmentBlock` LAST — its static half: cwd, git-repo flag, platform,
    OS, PHP, model, date, memoized per session. The git status, log and diffs
    left the system prompt at step 1.A-1 for the `<turn-context>` row below.

Item 11 is what makes the `Skill` tool worth having: without the listing, the
model has no reason to call it, and a populated registry would still be
un-triggerable.

Items 3 to 5 are the derived-fact half and items 6 through 9 the
authored-convention half, which is why the maps sit where they do: it is the
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

The **symbol-level** map is built the way Aider's repo map is, by one
pipeline (`RepoMap\RepoMapBuilder`) with two consumers: the `RepoMap` tool
(`Tools\BuiltIn\RepoMapTool`, roadmap 5.5-4) builds a fresh one on demand,
focused on the files and identifiers the model names, and item 5 above carries
an unfocused one per session. It lists the files
`git ls-files` reports (so `.gitignore` decides what is code; symlinks are
skipped and every path is re-resolved through `PathJail`), extracts
definitions and references — PHP with `RepoMap\PhpSymbolExtractor` (the
engine's own tokenizer, no binary) and every other language with
`RepoMap\CtagsSymbolExtractor` when Universal Ctags built with `+json` is
installed — caches them per project in `RepoMap\TagCache` under
`~/.sugar-crush/cache/repomap/`, ranks files with `RepoMap\SymbolGraph`'s
PageRank (biased toward the `focus_files` and `mentioned_idents` the model
passes) and renders the outline to the caller's token budget with
`RepoMap\RepoMapRenderer`. The ctags child runs with `--options=NONE`, so a
checkout's `.ctags.d/` cannot steer it, and through the same bounded spawn
path every tool child uses; `doctor` reports whether it is available.

`TagCache` stores one row per file, stamped with the producer that tagged it
(`TagCache::PHP_PRODUCER`, or `CtagsSymbolExtractor::cacheStamp()` — the
extractor's rule version plus the ctags binary's version banner), so a ctags
upgrade or a changed reference scan re-extracts exactly the files it produced.
`SymbolGraph` holds the graph aggregated per identifier rather than as Aider's
edge multigraph (≈570,000 edges on this package, ≈340,000 distinct file
pairs) and gives the same ranks to the bit. The prompt's `SymbolMapBlock`
also caches its finished map in the same database, keyed by every mapped
file's path, mtime, size and producer, so an unchanged tree costs a
`git ls-files` and a stat per file; it gives up — no map that session, the
work kept for the next — past 3,000 code files, past a 4-second tokenizing
budget, or without memory headroom, and `SUGARCRUSH_DISABLE_SYMBOL_MAP`
turns it off.

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
  calls touched, the memory notes recalled for the latest user message
  (`Runtime::memoryRecall()`, ranked once per turn in the parent; see
  MEMORY.md "Recall") and, from 60%, the context-window share, and
  `EngineBackend::runTurn()` persists it into the history at the top of each
  step, user-role, only when its bytes differ from the latest such row there,
  so each step's request is a byte prefix of the next; it returns to Chat in
  the turn's transcript as a hidden row.
- **In-place notices** — `SglangProvider` and `CustomProvider` keep one leading
  `system` row (the prompt plus any history system rows ahead of the first
  conversation row) and render every later history system row where it
  happened, as a user-role `<system-notice>` row, instead of hoisting it into
  message 0.
- **Per-session memo** — the PerSession layers (static `<env>`, repo map,
  memory, the standing instruction slab) are memoised per session through
  `Context\SessionPromptMemo`, frozen until the session is forgotten
  (`/clear`, compaction, another session). `EngineBackend` holds it, primes it
  in the parent before each turn's fork, and detects those refresh points from
  the history it is handed.

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

Every delegation that ran is resumable — a finished report, a step-capped
run, or a failure part-way: its typed transcript is saved on disk
(`Agents\SuspendedDelegations`, capped by age and count; memory would not
survive, since every turn and every parallel `Task` runs in a fork) and the
result names a `resume` id that continues the same conversation through
`EngineBackend::completeTranscript()`, so a follow-up reaches the agent that
did the work. A step-capped run is returned as a report, not a refusal: the
engine's no-tools summary (see the agentic loop below), with the `resume` id
appended. A failed run's refusal also carries the last 12 KB of what it
produced, fenced as sub-agent output.

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

`src/Tools/BuiltIn/` holds **seventeen** concrete `Tool` classes: <!-- tools:class-list:begin -->`Bash`, `Compress`, `Doctor`, `Edit`, `Glob`, `Grep`, `LspTool`, `MemoryTool`, `Prune`, `Read`, `RepoMapTool`, `SkillTool`, `TaskTool`, `Todo`, `WebFetch`, `WebSearch`, `Write`<!-- tools:class-list:end -->. `Bootstrap::tools()` ships all seventeen —
`Task` last, gated on the launch holding an `AgentManager` — plus one
`McpToolBridge` per advertised MCP tool.

Domain matters here: **seventeen is the count of *wired* tools, not of *usable*
ones.** `LspTool` is reachable on every launch but answers every call with a "no
language server configured" error until the user lists a server under the `lsp`
setting. A figure saying "seventeen working tools" would be the wrong claim.

Those servers are started once, at launch, by `LSP\LspLauncher` through
`Bootstrap::lspClient()` (memoised per process and root, stopped at exit like
the MCP servers), and shared by every forked turn — `LSP\LspConnection` is
fork-safe. `LSP\LspClient` subscribes to their `publishDiagnostics`, so the
same client answers `LspTool`, gives `Read` its outline of a file too long for
one page, and drives the post-edit diagnostics hook
([`HOOKS.md`](HOOKS.md#post-edit-diagnostics)).

The directory is the list. `Tools\Catalog\ToolCatalog` globs
`src/Tools/BuiltIn/`, and every concrete `Tool` there carries a `#[BuiltInTool]`
attribute naming its wire name, its permission class (read-only, write-capable,
ask or no-ask) and its wire position, plus a `fromCatalog()` factory that picks
what it needs from the launch's shared `ToolBuildContext`.
`Bootstrap::unfilteredTools()` builds whatever the catalog finds, the permission
gate and `ProtectFilesHook` classify by the declared class, and
`tools/gen-tool-docs.php` regenerates the tool roster, the class lists and the
counts on these pages. A tool class with no declaration is a hard error, not a
silent skip (`ToolCatalogTest::testAnUndeclaredToolIsRefusedNotSkipped()`), and
`BinSugarcrushWiringTest::testBootstrapToolsShipsAWriteToolAndTheWholeBuiltInSet()`
checks the launch set against the directory: that is the defect that once left `Write` written, tested, named in
the README, and unreachable from any real run. `Task` is the one exception the
catalog classifies but does not build, because `Bootstrap::tools()` appends it
after the `allowedTools`/`disabledTools` filter.

`Grep`, `Glob`, `Read`, `Edit` and `Write` resolve through `Tools\PathJail` and
refuse a path outside the root — with one read-only exception, the saved tool
output described below ([PERMISSIONS](PERMISSIONS.md#the-workspace-jails-one-exception-saved-tool-output)).
**`Bash` is deliberately not jailed** — which is
why `BashEscapeDenyHook` exists as an opt-in heuristic, and why it says in its
own source that it is not a security boundary.

**Over-budget output is saved, not discarded.** A capped tool (`Tools\Concerns\TruncatesOutput`)
whose result is cut saves everything it captured to `Support\ToolOutputSpill`
first, keeps a head and a tail of a single-stream result, and ends with a
pointer: the path, and "Read it with offset/limit, or Grep that path". The
store is a per-user `0700` directory under the system temp dir with `0600`
files (`Support\PrivateRetainedDir`, the same discipline as the hook overflow
store), seven-day retention swept lazily by the first spill of a process.
`Runtime::settle()` then moves the file into the session's `s-<session>/`
directory and applies a second, **window-scaled** cap: a result still larger
than 30% of the model's context window is saved and replaced by a head+tail
preview — the net for tools with no cap of their own (`WebSearch`, `doctor`)
and for caps chosen for a far larger window than
the current model's. `Task` and `Skill` results are exempt: they are the answer.

**Old output can be pruned from a request, never from the history.** When a
step's request is over its budget (see the agentic loop above), tool output
outside the protected window is sent as a one-line placeholder naming the tool
and its main argument — `file_path`, `path`, `command`, `pattern`, `url`,
`query`, then the first string argument —
`[Read src/Tools/Bash.php — output pruned to save context; re-run the tool if you need it]`
(`Context\Pruning\PrunedOutputPlaceholder`). The result keeps its call id and its
error flag, so every call still has its answer, and the model re-runs the call
if it needs the output again. A superseded write's `content`, and the arguments
of a call that failed long ago, are pruned from the call instead
(`Context\Pruning\PrunedInputPlaceholder`): the call keeps its id, its name,
its keys and its main argument, and its result is sent as it was.

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

A block that names `fallbackModels` comes back from `ProviderFactory::create()`
wrapped in a `FallbackProvider` (roadmap 5.13b): it answers as the provider it
wraps — name, capabilities, the served-model and prompt-cache seams — and on a
transient or context-overflow failure (the shared `TransientFailure` /
`ContextOverflow` verdicts) re-sends the request to the block's next model,
built lazily from the same block, before the first streamed chunk only. A
transient switch is pinned for a minute and reported as the served model, which
the turn child's result frame carries home like SGLang's discovery; every switch
raises one `RuntimeNoticeSink` notice.

**No blanket total-request timeout is applied to a provider call**, anywhere. A
completion can legitimately run for tens of minutes.
`SUGARCRUSH_CONNECT_TIMEOUT` (15s) bounds the connect phase only.

---

## Sessions and state

| Directory | Class | Holds |
|---|---|---|
| `~/.sugar-crush/session.db` | `Session\EnhancedSessionStore` (PDO/SQLite) | transcripts, checkpoints, titles |
| `~/.sugar-crush/session.db` | `Session\EnhancedSessionStore` (`context_ledgers` table) | each session's context ledger: what earlier turns pruned or summarised out of the model's view, and the refs its tool results keep |
| `~/.sugar-crush/session.db` | `Session\EnhancedSessionStore` (`session_meta.tasks`) | each session's todo list as its `Todo` tool last wrote it, saved by `Host\TranscriptStore::saveTodos()` |
| `~/.sugar-crush/memory/` | `Memory\MemoryStore` | markdown + frontmatter, per scope |
| `~/.sugar-crush/memory/.compaction-journal-<key>.jsonl` | `Memory\CompactionJournal` | every model-written compaction summary, one JSON line each, per project (`shared` without a root) |
| `~/.sugar-crush/teams/` | `Agents\TeamManager` | team state |
| `~/.sugar-crush/subagents/` | `Agents\Live\SubAgentTranscriptLog` | one JSONL transcript per delegated run, `<session>/<agent>.jsonl` |
| `~/.sugar-crush/mailboxes/` | `Agents\Live\AgentInbox` | messages to a running delegated run, `<session>/<agent>/inbox.jsonl`, read at its step boundaries; a message from the user carries the launch key's HMAC |
| `<workflowsPath>/.running/` | `Workflows\WorkflowEngine` | pause files |
| `<tmp>/sugar_crush_bg_<uid>_index/` | `Sessions\BackgroundSupervisor` | one record per running background session (`/bg`, `/fork`, background `Task`), so a restart re-adopts its daemon and the host adopts one its turn child spawned |

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

**`Host\TranscriptStore` owns the transcript on disk.** Saving (debounced or
immediate), loading with the crash repairs (a "running" placeholder comes back
as an interrupted row), the single-writer `SessionLock`, and each row's
identity all live there, and `Chat` delegates to it. `Chat` reaches it through
the workspace's service locator (`Host\WorkspaceContext::service()`), so a host
without a screen saves and resumes a transcript exactly as the TUI does. A row
minted in this process carries no id until the session is reloaded, but
`TranscriptStore::identityOf()` answers the id its first save gave it, and
`TranscriptStore::identify()` allocates one ahead of that save. The store keys
that memory by `Message::rowKey()`, a token every wither carries forward, so a
placeholder finished by `withToolResults()` or a row stamped with its step keeps
its id instead of spending a new ref on every save.

**`Host\EventLog` is the durable event log** a host writes before it
broadcasts, in a `session_events` table in the same `session.db` (`session_id`,
`seq`, `ts` in milliseconds, `type`, a JSON `payload`). `seq` is monotonic per
session and never reused: it is `MAX(seq) + 1` inside the store's
`BEGIN IMMEDIATE` transaction, so it continues across a restart. The log keeps
the newest 20,000 events per session (`EventLog::DEFAULT_RETAIN`), and
`EventLog::isReplayable()` tells a reconnecting reader whether it can replay
from its cursor or must resync from a snapshot. Deleting or pruning a session
takes its events with it, and a `/branch` fork starts its own log at seq 1.

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

**A finished `Task` sub-agent becomes a `subagent` child session.** The
process that runs the sub-agent (the turn child, or a parallel member's
grandchild) appends its whole conversation to
`~/.sugar-crush/subagents/<session>/<agent>.jsonl`
(`Agents\Live\SubAgentTranscriptLog`, `0700`/`0600`, one `flock`ed line per
item, a tool result clipped to 16 KB) and names the file on its `started` and
`finished` frames. Only the parent writes SQLite: when the `finished` frame
arrives, `AgentManager::projectRemoteSubAgent()` creates the child row
(`kind='subagent'`, `parent_id`, the Task call id as `parent_call_id`, named
`<description> (@<agent>)`), saves the transcript `Agents\Live\AgentTranscriptTail`
reads back from the log, records how the run ended, and stamps the child
session id on the frame. A resumed run continues the same log and re-saves the
same child.

**Checkpoints snapshot the files too.** Each turn's checkpoint row also records
a snapshot of the project's files, taken by `Workspace\WorkspaceCheckpointer`
under the key `EnhancedSessionStore::CHECKPOINT_WORKSPACE_KEY`.
`Chat::dispatchTurn()` saves the row in `update()`, but the snapshot runs at
the head of the turn's own Cmd, after the frame is painted and before the
turn can fork and write a file, because `git stash create` on a large
repository is not something `update()` may wait for. Only a `Chat` given an
explicit project root takes one, and every launch passes one. In a git work
tree the snapshot is a stash-shaped commit: `git stash create` for the
tracked files, plus a commit of the untracked files built in a scratch
`GIT_INDEX_FILE`. It is pinned at `refs/sugar-crush/checkpoints/<session>/<n>`,
so `git gc` keeps it and `git stash list` never shows it, and the user's
index, stash list and branch are not touched. Outside a work tree the
snapshot goes into a `Workspace\ShadowRepo`, a private git directory under
the `checkpoints/` directory beside `session.db`, with the project as its
work tree. Untracked files over 2 MiB, build and dependency directories,
media, archives, binaries, databases, logs and `.env*` files are left out.
The home directory, any directory above it, and `~/Desktop`, `~/Documents`
and `~/Downloads` are refused. Every git child goes through
`Workspace\GitRunner`, which runs on the bounded `runCaptured()` spawn, with
the inherited `GIT_DIR` family unset and signing and hooks switched off. A
refusal or failure is stored in the row with its reason and never fails the
turn; the first refusal or failure per directory and reason is announced
once through `RuntimeNoticeSink::warn()`. A capture that runs out of its
15-second budget switches snapshots off for that directory for the rest of the
process. **A ref lives as long as its row does.** Pruning past the per-session
cap and deleting or retention-pruning a session drop the refs of the rows they
remove, after the write commits. `/branch` pins its own copy under the new
session's id. **A rewind does not delete rows.**
`EnhancedSessionStore::restoreCheckpoint()` marks the rows it steps over in the
`checkpoints.undone` column, and the first time it records the state it left as
one more row on top. Those rows are the redo stack: live readers no longer see
them, `redoCheckpoint()` walks back up them for `/redo`, and the next
`saveCheckpoint()` deletes them with their refs and message bodies.
`WorkspaceCheckpointer::restore()`, `changes()` and `trees()` are the
primitives `/rewind --files|--both`, `/undo`, `/redo` and `/diff`
(`Workspace\CheckpointDiff`) are built on. A restore is refused once HEAD has
moved since the checkpoint; a diff is not. Files the snapshot never recorded
are never deleted. A shadow repository's unreachable objects are collected by
`Workspace\ShadowGarbageCollector`: at most once a day per shadow, only past
1,000 loose objects, in the foreground under a 10-second budget.

`Sessions\Background*` runs a task in a detached session (`/bg`, `/fork`) with
its own runner and supervisor, and so does a background `Task`: the turn's
forked child spawns the daemon, the index record names the host as its owner,
and the host's supervisor adopts it on its next poll
(`BackgroundSupervisor::adoptHandedOff()`); the daemon runs the agent through
`TaskTool`, and `Host\TurnRunner` lists what is still running in an
`<active-subagents>` row (`Sessions\ActiveSubagentsBlock`). `Context\ContextCompactor` +
`IdleCompactionPolicy` drive `/compact` and automatic compaction against
`ContextWindow`.

**`Host\CompactionService` owns compaction logic.** What a compaction
condenses, the summarization request and the parse of its reply, the row
layout, and every notice the tiers write (the park notice, the 85% report,
the 95% refusal and rescue, the thrash breaker) live there. It is stateless
and registered on the workspace, so a headless host compacts exactly as the
TUI does. `Chat` keeps the Msg plumbing: it parks a turn behind the
summarization (`inFlight`, the `pendingCompactionId` latch, the cancellation
token), applies the landing, and dispatches the parked turn.

**Compaction hides rows, it does not delete them.** Every compaction route
(`/compact` on the heuristic, `/compact` landing a model's summaries, the
automatic 85% tier) lays its result out through
`Host\CompactionService::withCompactedRowsHidden()`. The rows it condensed
stay in the history where they were, flagged `uiOnly` so the model no longer
reads them. What the model reads in their place (`[summary]` lines, file
stubs) goes in with `userVisible` false. One
`Host\CompactionService::COMPACTION_BOUNDARY` notice sits between them and the
preserved rows. So the scrollback and the saved transcript keep the
conversation as it happened, with its ids and step ids. `Renderer` paints the
boundary as a rule and dims the labels of the turns above the newest one.
The compaction reports count agent-visible rows, because the history as a
whole never shrinks. The 95% intra-exchange rescue is the exception: it still
shortens the oversized row in place (`Host\CompactionService::messageWithContent()`,
every other field kept).

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
`react/promise`, and `react/http`, `react/socket` and `ratchet/rfc6455` for
`sugarcrush serve`'s HTTP + WebSocket transport), fifteen SugarCraft siblings: `candy-core` (TEA runtime,
`Program`, `Model`, `Cmd`), `candy-forms`, `candy-sprinkles` (styles),
`candy-shine`, `candy-fuzzy`, `sugar-veil`, `sugar-mcp` (stdio MCP transport),
`sugar-diff` (the settings editor's save preview),
`sugar-toast` (the toast a settings save reports through),
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
   `App::dispatchSkill()`.
   (`ForeignMemoryImporter` was on this list until P7.S6 wired it behind
   `/memory import`, and `src/LSP/` until step 3.F gave it a launcher.)
2. **Absence is a no-op; present-but-unusable is a refusal.** Applied to
   `config.json`, `hooks.yaml`, `.mcp.json`, `--config`, `--root`, and every
   `SUGARCRUSH_*` variable that carries policy.
3. **One resolution per question.** `HomeDirectory` for `~`, `ContainedPath` for
   containment, `HookConfig::pattern()` for matcher delimiters,
   `Bootstrap::mcpConfigDecision()` for the MCP verdict. Two implementations of
   one rule is how the two answers drift apart, and each of those classes exists
   because they had.
4. **A count carries its domain.** "Seventeen tools" means wired built-ins.
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
