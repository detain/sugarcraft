# 14 — sugar-crush server mode (WebSocket) + `sugar-crush-web` multi-session web UI: design report

Feeds steps: O-0, O-1 (= 1.C), O-2a, O-2b, O-2c, O-2d, O-2e, O-2f, O-2g, O-2h, O-3a, O-3b, O-3c, O-4a, O-4b, O-5a, O-5b, O-6a, O-6b, O-6c, O-7, O-8a, O-8b

**What this is.** A design for two features the user asked for:

1. **Server mode.** `sugarcrush serve` runs in the foreground or as a background daemon and starts a WebSocket server.
2. **`sugar-crush-web`.** A separate Vite + Vue package in `/home/sites/sugarcraft/sugar-crush-web` that drives that server and controls several sessions at once.

Paths are relative to `/home/sites/sugarcraft/sugar-crush/` unless they start with `/` or with a lib directory name.

---

## 0. TL;DR — the decisions

| # | Decision | Why (short) |
|---|---|---|
| D1 | **Use ReactPHP-native WebSockets (`react/http` + `react/socket` + `ratchet/rfc6455`), not Workerman.** | Every async seam in sugar-crush already runs on `React\EventLoop\Loop::get()`: `EngineBackend::completeAsync()`, candy-core `Program`, `Cmd::promise`, `Subscriptions`, `RuntimeNoticeSink`. Workerman brings its own loop, a master/worker fork model, global `$argv` parsing and nine signal handlers, and all of those collide with sugar-crush's `pcntl_fork` turn children. **No sugarcraft lib uses Workerman today** (§3.1). |
| D2 | **Use one WebSocket, multiplexed across sessions, carrying JSON-RPC 2.0** (reusing `sugar-mcp`'s `McpMessage` codec), with server→client events as `event` notifications. | Approvals, steering and cancel are bidirectional. A browser can multiplex N sessions over one socket. The repo already has a tested JSON-RPC codec. |
| D3 | **Durable per-session event log with a monotonic `seq`, stored in `session.db`.** Deltas are ephemeral; full values are durable; subscribing with `afterSeq` replays. | This is the pattern opencode v2, OpenHands `/sockets/session` and cline converged on. It survives reconnects and server restarts. |
| D4 | **Build one permission back-channel for the TUI and the server:** make the fork socketpair bidirectional (`ask`/`ask_reply`, plus `steer` and `cancel`). | It fixes Appendix A (the TUI cannot answer an Ask) and gives the server approvals for free. It is the prerequisite for leaving the `bypass-permissions` default. |
| D5 | **Extract a UI-agnostic `SessionHost` from `Chat` (strangler pattern).** `Chat` and the server both become clients of it. | `Chat.php` is a very large TEA model, and candy-core `Program::run()` owns the loop and the terminal (`candy-core/src/Program.php`). N headless Chats cannot share one process without that seam. |
| D6 | **Process model:** a gateway process (WS/HTTP, auth, static UI) hosts `SessionHost`s for **one project root in-process**. Multi-root comes later, through per-root **workspace-host** child processes. | `Cli\Bootstrap` keeps more than 25 `private static` caches and trust sets frozen per process (`Bootstrap.php`), and `RuntimeNoticeSink` is a process-global queue. Mixing roots or concurrent turns in one process mis-attributes state. |
| D7 | **Background mode reuses `BackgroundSupervisor`'s double-fork/`setsid`/0600 IPC/token idioms**, plus a pidfile with a `procStartTime` check and `serve status|stop|logs`. | The patterns are already measured and audited (audit M5) in `src/Sessions/BackgroundSupervisor.php`. |
| D8 | **`sugar-crush-web` = Vite + Vue 3 + TypeScript + Pinia + vue-router**, built to a **committed `dist/`** and shipped as a composer package (`sugarcraft/sugar-crush-web`) with a one-class PHP shim (`Assets::distPath()`). `sugarcrush serve` serves it from the same port. | PHP users get the UI with `composer require` and need no node. CI's lib discovery (composer.json + phpunit.xml) and the splitsh sync then work unchanged. A node CI job guards that `dist/` matches source (same "generated, never hand-edit" pattern as `docs/lib/`). |
| D9 | **Secure defaults:** bind `127.0.0.1`; a mandatory 256-bit token even on loopback; token exchanged for an HttpOnly SameSite=Strict cookie; single-use WS tickets; strict `Origin` + `Host` checks (CSWSH and DNS-rebinding); server sessions default to `default` (ask) mode; `bypass-permissions` refused over the wire unless `--allow-bypass`. | Same lessons as dsh, OpenClaw, goose and nanobot (§2). A server that can answer Asks has no reason to default to bypass. |
| D10 | **Later phases:** TUI-as-client (`sugarcrush attach`) and an **ACP stdio adapter** (`sugarcrush acp`) for Zed and other editors. Both map onto the same `SessionHost` events. | ACP is small: about 8 methods for a usable agent (§6.12). |

**Effort overall:** about 6-9 weeks of focused work across 8 phases (§9). The highest-value early slice is **Phase 1 (bidirectional fork socket + approvals in the TUI)**, which ships value even if the server never lands.

---

## 1. What exists today (the ground we build on)

### 1.1 The live turn pipeline (Appendix A)

```
bin/sugarcrush ─► Program(App) ─► Chat::update() ─► Chat::submit()
   ─► Chat::dispatchTurn()  [checkpoint, title, compaction, hooks]
   ─► Chat::scheduleBackendCompletion()  Cmd::promise + ArrayObject inbox
   ─► EngineBackend::completeAsync()
        pcntl_fork + stream_socket_pair(UNIX, STREAM)
        child: runCompleteInChild() → complete() → runTurn() → Runtime::run() ×maxSteps
        child → parent frames (4-byte BE length + serialize()):  token | reasoning | started | finished
                                                                | subagent | spend_cap | result
   ◄─ parent: Loop::addReadStream($parentSocket) → drainFrames → $onToken/$onEvent/$onReasoning
        120 s idle watchdog re-armed per frame (COMPLETE_TIMEOUT_SECONDS)
        cancel = 0.1 s periodic timer → teardown() SIGKILL
   ◄─ Chat inbox drained by ToolEventPumpMsg subscription; AssistantMsg / BackendToolEventsMsg settle the turn
```

Facts this design depends on:

- **The socket is used one way only.** `writeFrame()` is called only in the child. The parent never writes. The `withPermissionApprover()` doc-comment says, in as many words, that the TUI needs "a request/response protocol on the socket", and that this is why the default mode is `bypass-permissions`.
- **The approver contract is synchronous.** It has the shape `\Closure(ToolCall, HookResult): bool` (`EngineBackend::withPermissionApprover`; `Runtime::settleAsk()`, `src/Runtime.php`). It returns literal `true` to grant. That is ideal for a forked child that can simply block on a socket read.
- **Asks only come from the turn child.** `Runtime::executeConcurrently()` calls `gate()` **before** forking a tool grandchild (`src/Runtime.php`), so any `ask` frame comes from one process. One exception: a parallel **Task** sub-agent runs its own engine loop inside a grandchild (Appendix A), and its Asks have no channel today.
- **Usage arrives only once per turn**, in the `result` frame. There is no per-step usage.
- **Events** are plain value objects in `src/Events/`: `TokenDelta{text}`, `ReasoningDelta{text}`, `ToolStarted{toolCallId, toolName, arguments}`, `ToolFinished{toolCallId, toolName, ToolResult}`, `SubAgentActivity{op, id, name, task, seq, tail}`, `SpendCapBreached`. `encodeEvent()` already flattens them to arrays. Those arrays are the natural seed for the wire schema.
- **`Message` has no stable id** (`src/Message.php`). The wire protocol needs ids (§6.5).
- **Sessions.** `EnhancedSessionStore` (`src/Session/EnhancedSessionStore.php`) is SQLite in WAL mode at `~/.sugar-crush/session.db`. API: create/get/rename/fork/delete/list, `saveTranscript`/`loadTranscript`, checkpoints, `latestResumableSession`. `Chat::persistTranscript()` **rewrites the whole transcript** after every history-changing update (`src/Chat.php`). Two writers on one session would therefore clobber each other (§4.8).
- **Permissions.** `PermissionReply` enum is `once|always|reject` (`src/Permissions/PermissionReply.php`). `PermissionRequestMsg` and `PermissionReplyMsg` already exist (`src/PermissionRequestMsg.php`, `src/PermissionReplyMsg.php`). The Veil y/n/a modal exists but serves only Command backends (`Chat::beginToolCalls()`).

### 1.2 Headless driving today

- **`-p` / `run`.** `Cli\NonInteractive::run()` drives `EngineBackend::complete()` synchronously, with `HeadlessPermissionPrompt` as the approver and a refusal observer (`src/Cli/NonInteractive.php`). Sessions are not saved. This is the existence proof that **`EngineBackend` + `Bootstrap::backend()` run without `Chat`/`Program`**.
- **Background daemons.** `BackgroundSessionRunner::main()` builds a backend with `consolePermissionPrompt: true` and runs one prompt without history (`src/Sessions/BackgroundSessionRunner.php`).
- **candy-core.**
  - `ProgramOptions` has `withoutRenderer`, `input`, `output`, `loop` and `windowSize` (`candy-core/src/ProgramOptions.php`; "Mirrors `WithoutRenderer`").
  - **But `Program::run()` installs SIGINT/SIGWINCH handlers, sets up the terminal and calls `$this->loop->run()` itself .** So you cannot run N headless Programs on one loop without a candy-core change.
  - The private `dispatch()`/`scheduleCmd()`/`drainPending()`/`reconcileWantedSubscriptions()` are exactly the pieces a headless runtime would need.

### 1.3 Daemon and IPC idioms worth reusing (all in `src/Sessions/`)

| Idiom | Where |
|---|---|
| Private 0700 per-uid IPC dir, `lstat`-verified, refuses symlinks | `BackgroundSupervisor::ensurePrivateIpcDir()` |
| 128-bit token written 0600 via `ToolIpcFiles::write`; daemon learns only the **path** (argv is world-readable in `/proc`) | `spawnSession()` |
| argv-array `proc_open` (no `/bin/sh -c`), stdin `/dev/null`, stdout/stderr → sidecar log | `spawnSession()` |
| Bind the listener **after** spawning, so children never inherit it | (measured with `ss -x`) |
| Double-fork + `posix_setsid()` (or `setpgid` fallback), `umask(0o077)` | `buildSessionDaemonCode()` |
| Auth handshake `HELLO:<id>:<pid>:<token>` checked with `hash_equals` | `parseHandshake()`; runner `AUTH_PREFIX`, `authenticate()` |
| pid-reuse defence via `/proc/<pid>/stat` start time | `procStartTime()` |
| Heartbeat (5 s in the daemon, 15 s stall timeout in the supervisor) | Runner `HEARTBEAT_INTERVAL_SECS`; supervisor `HEARTBEAT_TIMEOUT_SECS` |
| Worker supervision loop with `pcntl_waitpid(WNOHANG)`, deadline, `stopWorker` TERM→KILL grace | Runner `supervise()`, `stopWorker()` |
| Re-adoption after a TUI restart (**DORMANT** — no caller) | `BackgroundSupervisor::reconnect()` |

Under the project rule "never remove dormant code — wire it instead", **the server is the natural caller for `reconnect()`** (§4.6).

---

## 2. What the competitors teach (protocol and architecture)

**What we adopt.**

1. **One WS, JSON-RPC-shaped, many sessions.** This is cline's, OpenClaw's and nanobot's shape, and ACP's message grammar. We do **not** follow opencode's REST+SSE, because the user asked for WebSocket and we need bidirectional asks and steering on the same channel.
2. **Durable per-session `seq` with ephemeral deltas.** opencode v2 and OpenHands. The client cursor is the highest gap-free seq (OpenHands). A `snapshot`/`reset` covers cursors older than retention (OpenClaw).
3. **Subscribe first, then replay, then flush buffered live events with dedupe** (cline, dsh, OpenClaw "register listeners before snapshot").
4. **Approvals.** Broadcast, first answer wins, `resolved` broadcast, re-sent on (re)subscribe (cline, goose). Reject cascades and always auto-covers (opencode). Bind each approval to the exact arguments, the rewrite included (OpenClaw).
5. **Backpressure = bounded outbox + coalesce deltas + disconnect on overflow** (OpenHands, nanobot). The agent never blocks on a slow browser.
6. **App-level heartbeat.** Browsers cannot see WS ping/pong, so: an app-level `tick`, a client watchdog at 2× the interval, and close-and-reconnect (OpenClaw, opencode, cline).
7. **Hello negotiation** `{minProtocol, maxProtocol}` → `{protocol, features, limits}` (OpenClaw), with additive capabilities.
8. **Idempotency keys on side-effecting calls** (OpenClaw), so a send that reconnected never runs twice.
9. **Token → cookie exchange, Host/Origin checks, loopback still needs the token** (dsh, OpenClaw). A **single-use short-lived WS ticket** (opencode PTY) as the browser-safe alternative to putting tokens in URLs.
10. **Narration mode** for sidebar or background session observers (OpenClaw). It keeps a 10-pane dashboard cheap.
11. **Parent-PID watchdog and discovery file** for spawned servers (kilo, cline). The TUI-as-client and an editor plugin need them.

---

## 3. WebSocket server options in this monorepo

### 3.1 Reusable pieces in the monorepo

No sugarcraft lib uses Workerman or any WebSocket code today.

| Lib | Detail |
|---|---|
| `sugar-mcp` (`SugarCraft\Mcp`) | `McpMessage` is an immutable **JSON-RPC 2.0 envelope**: `request`/`notification`/`success`/`error`, `parse()`, `toJson()`, null-preserving `result` (`sugar-mcp/src/McpMessage.php`). **Reusable as the wire codec.** `StdioMcpServer` is a client-side stdio transport. |
| `candy-async` | `CancellationToken`, `Subscription`, `AsyncOps` (timeouts, retry, debounce, **throttle**). Useful for delta coalescing and timeouts. |

**Conclusion.** Nothing to reuse for the socket layer itself: add ReactPHP's HTTP/socket stack plus an RFC 6455 codec (D1).

### 3.2 Option A — ReactPHP-native (recommended)

**Stack.**
- `react/socket ^1.16`: `SocketServer`, optional `SecureServer`.
- `react/http ^1.11`: `HttpServer` with a streaming request body, PSR-7, and middleware.
- `ratchet/rfc6455 ^0.4`: a pure, loop-agnostic codec. `ServerNegotiator` + `RequestVerifier` for the upgrade; `MessageBuffer` for framing, masking, fragmentation, ping/pong, close codes and UTF-8 checks. Requires only `psr/http-factory-implementation`, which `guzzlehttp/psr7` already provides through Guzzle, a sugar-crush dependency.

Locally, `ratchet/rfc6455` exists at `/home/sites/mystage/vendor/ratchet/rfc6455`, with `src/Handshake/{ServerNegotiator, RequestVerifier, PermessageDeflateOptions}.php` and `src/Messaging/{MessageBuffer, Frame, CloseFrameChecker}.php`.

**How it plugs in.** react/http lets a request handler return a `101 Switching Protocols` response whose body is a duplex stream (`React\Stream\ThroughStream`/`CompositeStream`). This is the well-known "WebSocketMiddleware" pattern (voryx/WebSocketMiddleware, Ratchet's own `RFC6455` server component). In outline:

```php
$http = new React\Http\HttpServer(
    new Server\Http\HostAndOriginGuard($policy),          // DNS-rebinding + CSWSH
    new Server\Http\AuthMiddleware($tokens),              // cookie / bearer / ticket
    function (ServerRequestInterface $r) use ($ws, $static, $api) {
        return match (true) {
            $r->getUri()->getPath() === '/ws' => $ws->upgrade($r),   // rfc6455 ServerNegotiator → 101 + duplex stream
            str_starts_with($r->getUri()->getPath(), '/api/') => $api->handle($r),
            default => $static->serve($r),                           // sugar-crush-web dist/
        };
    },
);
$http->listen(new React\Socket\SocketServer('127.0.0.1:7420'));   // same Loop::get() the engine uses
```

**Why it is right for sugar-crush:**

1. **One loop.** `EngineBackend::completeAsync()` calls `Loop::get()` and `addReadStream($parentSocket, …)`. The WS connections, the turn sockets, the 120 s idle timers and the 0.1 s cancel pollers all live on the same `stream_select`/ext-uv loop with no bridging.
2. **The fork model stays ours.** react/http forks nothing. Turn children `pcntl_fork` from a single-process server exactly as they do from the TUI today. `EngineBackend::sweepUnreapedChildren()` and the "no SIGCHLD handler anywhere" invariant (`EngineBackend.php`; `src/Agents/AgentWorkerPool.php`) keep holding.
3. **The house test infrastructure applies.** `candy-testing`'s `LoopPin::pinStableClock()` and the `sugar-crush/tests/bootstrap.php` loop hygiene already cover React-loop timers.
4. **Small surface.** About 3 packages, all stable and PHP 8.3 compatible. rfc6455 is a codec, so there is no framework to fight.
5. **Extraction path.** The WS glue (about 400-600 LOC) can later move into a foundation lib, following the precedent that `sugar-diff` and `sugar-mcp` were extracted from sugar-crush. A candidate name is **`candy-wire`** (`SugarCraft\Wire\`, "wire protocol"; two-word rule compliant), once a second consumer appears (candy-serve stats, a candy-query web console).

---

## 4. Server mode design

### 4.1 Process model

```
                     ┌──────────────────────────── sugarcrush serve (gateway, single process, React loop) ───────────────────────────┐
 browser tabs ──WS──►│ Server\Http (react/http)  ── HostAndOriginGuard ── AuthMiddleware ── Router                                │
 sugarcrush attach ─►│   /ws  → Server\Ws\Connection ×N  ──► Protocol\Dispatcher ──► Host\SessionHub                             │
 editor (ACP stdio) ─│   /api/* (health, login, ticket, schema)       /  (sugar-crush-web dist/)                                     │
                     │                                                                                                               │
                     │   Host\SessionHub ── SessionHost[id=a] ── TurnRunner ──fork──► turn child (Runtime loop) ──fork──► tool kids │
                     │                   ── SessionHost[id=b] ── TurnRunner ──fork──► turn child                                     │
                     │                   ── EventLog (session.db: session_events)                                                    │
                     │   Sessions\BackgroundSupervisor (/bg daemons; reconnect() wired at boot)                                      │
                     └───────────────────────────────────────────────────────────────────────────────────────────────────────────────┘
 Phase 7 (multi-root):  gateway ──UNIX socket (same JSON-RPC)──► `sugarcrush serve --workspace-host --root R` (one per root)
```

- **Phase 3-5: one project root per server process.** It defaults to the cwd or `--root`, which is the same root resolution the TUI uses (`bin/sugarcrush`, `Bootstrap::app($args->root)`). There are many sessions, all under that root.
- **Phase 7: multi-root.** The gateway spawns one **workspace-host** child per root. It uses the `proc_open` argv-array idiom from `BackgroundSupervisor::spawnSession()` and binds the UNIX listener after the spawn, with the same token handshake. The workspace host runs the same `SessionHub` and speaks the same JSON-RPC over a UNIX socket.
  - **Why a process per root instead of in-process:**
    - `Bootstrap` holds root-sensitive static state: `$projectRootForSettings`, `$trustedRoots`/`$trustedMcpRoots`/`$trustedCommandRoots`/`$trustedSettingsRoots` (frozen per process by design, Appendix A), `$mcpClients`, `$hookFileEntries`, and the skill and command skip lists.
    - MCP servers start once per launch (`Bootstrap::mcpClient()`).
    - A crashed or leaking root does not take the others down. The gateway can **recycle** an idle workspace host, which bounds long-running PHP memory growth (§9.4).

### 4.2 Headless operation: what is TUI-coupled and what must be extracted

| Concern | Lives in today | TUI-coupled? | Server needs |
|---|---|---|---|
| Submit pipeline: custom-command expansion, `dispatchCommand`, spend-cap refusal, idle and threshold compaction, `UserPromptSubmit`/`SessionStart` hooks | `Chat::submit()` | Yes. Reads `TextArea` input, returns `[Chat, Cmd]` | **Extract** → `Host\TurnController::submit(string $text, SubmitOptions)` |
| Dispatch: 70% reminder, checkpoint, title scheduling | `Chat::dispatchTurn()` | Yes (returns Cmds) | **Extract** → `TurnController::dispatch()`, `Host\TitleService` |
| Backend completion + inbox + generation guard | `Chat::scheduleBackendCompletion()` | Partly. The closure logic is pure; `Cmd::promise` is not | **Extract** → `Host\TurnRunner` (promise-returning, emits `SessionEvent`s directly instead of an `ArrayObject` inbox) |
| Tool-event projection to transcript rows | `Chat.php` (running placeholders → result rows, `toolResultMessage()`) | Yes | **Extract** → `Host\TranscriptProjector` (the same rows Chat persists, plus wire events) |
| Persistence and checkpoints | `Chat::persistTranscript()`; `dispatchTurn` checkpoint | No (store calls) | **Extract** → `Host\TranscriptStore`, with a session **lease** (§4.8) |
| Compaction (LLM parked, heuristic, block) | `Chat.php`, | Mostly pure logic with a Msg plumbing shell | **Extract** → `Host\CompactionService` |
| Spend accounting, token estimate calibration | `Chat::spentUsd()`, `turnEstimateObservation()` | No | **Extract** → `Host\SpendLedger`, `Host\ContextMeter` |
| Prompt queueing mid-turn | `enqueuePrompt`, `releaseQueuedPrompts` | No | **Extract**, and add a `delivery: queue\|steer` field (§6.6) |
| Slash commands | `Chat::dispatchCommand()` + ~25 handlers | Mixed. `/compact`, `/clear`, `/rename`, `/branch`, `/rewind`, `/budget`, `/memory`, `/bg`, `/fork`, `/workflow`, `/websearch`, `/permissions`, `/rules`, `/agents`, `/mcp list` are logic. `/theme`, `/pane`, `/layout`, `/keys`, `/sessions` (picker) and `/exit` are UI | **Extract the logic ones** into `Host\Commands\*` returning `CommandResult{rows[], effects[]}`. UI ones are client-side (the web UI has its own theme and layout) |
| Background sessions pump | `Chat::pumpBackgroundSessions()`, `BackgroundTickMsg` | Msg shell around `BackgroundSupervisor::tick()` | Server owns one `BackgroundSupervisor` and emits `bg.*` events |
| Workflows | `Chat::handleWorkflowCommand()`, Fiber stepped by timer | Fiber driving is loop-based | Reuse: `WorkflowEngine` + a loop-timer stepper in `Host\WorkflowRunner`. A server must never block its loop |
| Runtime notices | `RuntimeNoticeSink` (process-global static queue + turn accounting, `src/Diagnostics/RuntimeNoticeSink.php`) | Global | **Per-session sink.** Make it an instance (`NoticeSink`) passed into each turn's backend. Keep a static facade for the TUI. Otherwise concurrent turns in two sessions steal each other's notices |
| Mouse, selection, click tracker statics | `Chat.php` (`static $clickTracker`, `$pressGesture`, `$textSelection`) | Yes | Not needed headless (they stay TUI-only) |
| Permission modal | `Chat::requestPermission` | Yes | Server: `permission.request` events (§6.7). TUI: same `PermissionAsked` event → Veil modal |

**Recommended path: strangler extraction, not a headless `Chat`.**

- *Alternative considered:* run each session's `Chat` under a new candy-core `ModelRuntime` (Program's dispatch/Cmd/subscription core without terminal and loop ownership), and project wire events by diffing `Chat::$history` after each update.
  - That buys every feature on day one.
  - It is rejected as the *target* because:
    - **diffing history loses tokens** (deltas never land in history);
    - **UI commands** (pickers, theme, palette) put `Chat` into modal states nobody can see;
    - one `Chat` per session carries the whole TUI object graph (renderer state, TextArea, zones);
    - and it entrenches the God object.
  - It **is** useful as a **Phase 0 spike** to validate the event vocabulary. A candy-core `ModelRuntime`/`Program::start()` (non-blocking, no terminal) would also be a good upstream-mirroring addition, since bubbletea has `WithoutRenderer` + `WithInput(nil)`.
- *Target:* the `src/Host/` service layer above. **Chat delegates to the same services**, so the TUI and the server cannot drift. Each extracted service gets the "Chat now calls X" change in the same PR. The suite has strong behavioural tests around `Chat::submit`/compaction, and they become the regression net.

### 4.3 `SessionHost` and `SessionHub` (new, `src/Host/`)

```php
final class SessionHost                       // one per open session; NOT a Model
{
    public function __construct(
        private readonly string $sessionId,
        private readonly WorkspaceContext $ws,   // root, Bootstrap-built backend factory, gate, hooks, skills, memory, agent manager
        private readonly TranscriptStore $store,
        private readonly EventLog $events,       // durable seq log
        private readonly NoticeSink $notices,
    ) {}

    public function snapshot(): SessionSnapshot;                       // transcript rows + status + pending asks + usage
    public function submit(string $text, SubmitOptions $o): TurnTicket; // queue|steer, idempotencyKey
    public function cancel(?string $turnId = null): void;
    public function answerPermission(string $askId, PermissionReply $r, ?string $note, string $clientId): AnswerOutcome;
    public function runCommand(string $name, string $args): CommandResult;
    public function rename(string $name): void;
    public function fork(?int $atCheckpoint = null): string;            // returns new session id
    public function rewind(int $n): void;
    public function setPermissionMode(PermissionMode $m, Principal $who): void;
    public function onEvent(\Closure $listener): Subscription;          // candy-async Subscription
}

final class SessionHub                         // owns hosts, leases, idle eviction
{
    public function open(string $sessionId): SessionHost;   // loads transcript, takes the lease
    public function create(CreateOptions $o): SessionHost;
    public function close(string $sessionId): void;         // releases the lease (keeps the turn if running? see 4.5)
    public function list(int $limit, ?string $cursor): SessionPage;  // EnhancedSessionStore::listSessionsWithMeta()
    public function delete(string $sessionId): void;
}
```

`WorkspaceContext` is built once per root by a new `Bootstrap::workspace(string $root): WorkspaceContext`. It factors the non-UI half of `Bootstrap::chat()`: config, gate, skills, commands, rules, agent manager, MCP, memory and the backend factory. **Note the `/model` bug** in Appendix A: `backendFor()` without `taskManager` drops the Task tool. `WorkspaceContext::backendFor()` must thread the AgentManager, so the server cannot inherit that bug.

### 4.4 Multiple concurrent sessions in one process

- **Each turn already runs in its own `pcntl_fork` child.** The parent side is pure non-blocking I/O on the loop: frame reads, timers. So N concurrent turns in N sessions is N children plus N read streams on one loop. **No new concurrency primitive is needed.**
- **Caps.**
  - `serverMaxConcurrentTurns`, default 4. Excess turns queue with `turn.queued` events.
  - `serverMaxOpenSessions`, default 32. LRU-evict idle hosts: persisted state stays, the in-memory host goes.
  - Parallel tool grandchildren and Task sub-agents multiply this, so the global cap matters. Baseline §2.2: Task batches have **no concurrency cap**. Introduce `AgentPoolConfig::maxConcurrent` for Task while doing this.
- **Fork hygiene in a long-lived parent.** Forking copies the parent's whole heap. A server holding 32 hosts with big transcripts forks a fat child per turn. Copy-on-write makes that cheap until a write, but `serialize()` of the history in the child touches it.
  - **Mitigation:** the child only needs the session's history plus the workspace. Keep hosts lean (transcript rows, not rendered state). Phase 7 workspace hosts are naturally small.
  - Ensure the child closes **every inherited WS client fd and the listening socket** immediately after fork. This is the same class of bug as the "listener inherited by the launcher" finding at `BackgroundSupervisor.php`. Add a `ForkedChild::closeInheritedServerFds()` hook, called first thing in `runCompleteInChild()`, with a registry the server fills.
  - Without it a turn child holds browser sockets open, and the kernel keeps half-dead connections alive after the gateway closes them.
- **Process-global state to fix before concurrency:**
  - `RuntimeNoticeSink` (per-session instance, §4.2);
  - `EngineBackend::$unreapedChildren` (process-global but keyed by pid, so safe);
  - `Bootstrap` statics (the single-root assumption holds in-process; multi-root goes to Phase 7).
  - Re-check `ProviderFactory` and any provider-level static caches when implementing (*not audited here*).

### 4.5 Attach and detach semantics

- **Detaching a client never stops a turn.** Turns belong to the `SessionHost`, not to a connection (cline: "attach/detach without stopping the runtime").
- With **zero subscribers**:
  - a running turn keeps going;
  - Asks wait (no timeout by default; `serverAskTimeoutSeconds` optional, see §6.7);
  - events go to the durable log;
  - deltas are dropped and covered by the durable `message.completed`.
- A **closed** session (`session.close`) with a running turn is refused unless `force: true`, which cancels the turn.
- Idle eviction never evicts a host with a running turn or a pending ask.
- **Presence.** Each connection reports `client.viewing {sessionIds[], foreground}` (kilo's `session.viewed`). The server uses it to:
  - pick delta vs narration mode per subscription (§6.9);
  - show "N viewers" on the session;
  - route desktop notifications in the web UI ("permission needed in session X" when it is not foreground).

### 4.6 Background (daemon) mode

```
sugarcrush serve                 # foreground: logs to stderr, Ctrl-C = graceful stop
sugarcrush serve --detach        # background: double-fork, pidfile, log file; prints URL + pid and exits 0
sugarcrush serve status          # reads ~/.sugar-crush/server/server.json, verifies pid+start time, GET /api/health
sugarcrush serve stop [--force]  # SIGTERM → wait drain-timeout → SIGKILL (stopWorker grace pattern)
sugarcrush serve logs [-f]       # tails ~/.sugar-crush/server/server.log
sugarcrush serve url             # prints the login URL (with a fresh one-time login code, §8.2)
```

- **Daemonize.** Reuse the exact sequence from `BackgroundSupervisor::buildSessionDaemonCode()`: `umask(0o077)` → fork → parent exits → `posix_setsid() >= 0 || posix_setpgid(0,0)` → fork → parent exits. Then close stdio onto `/dev/null` plus the log file (the spawn-site redirection).
  - Factor it into `Support\Daemonize::detach(string $logPath): void` so `BackgroundSupervisor` and the server share one implementation. **This is a move, not a removal.**
- **State directory.** `~/.sugar-crush/server/` with mode 0700, verified with the `ensurePrivateIpcDir()` `lstat` rules. It holds:
  - `server.json`: `{pid, procStartTime, version, protocol:{min,max}, url, host, port, root, startedAt}`, written atomically (`Support\AtomicFileWriter`) with 0600. This doubles as the **discovery file** for `sugarcrush attach` and editor plugins (cline's discovery-record pattern).
  - `server.lock`: the singleton lock.
    - Prefer an **OS lock** (`flock` on an fd held for the process lifetime) over a pidfile alone. The kernel releases it on death, which is cline's insight.
    - Keep `procStartTime()` (`BackgroundSupervisor.php`) for `status` and `stop`, to defeat pid reuse.
    - `Support\TimedFileLock` exists, but it is a *timed* lock for short critical sections. Use plain `flock(LOCK_EX|LOCK_NB)` held open for the singleton.
  - `token`: 0600, the server's long-lived bearer token (§8.1).
  - `server.log`: rotated at 10 MiB × 3.
- **Graceful stop (SIGTERM/SIGINT via `Loop::addSignal`).**
  1. Stop accepting.
  2. Broadcast `server.shutdown {reason, graceSeconds}`.
  3. Let running turns finish for `serverDrainSeconds` (default 30), then cancel. The existing cancellation `teardown()` SIGKILLs the turn child.
  4. Persist all hosts, close MCP clients, release the lock.
  - **Important:** `Loop::addSignal()` (React) uses pcntl and is compatible with the "no SIGCHLD handler" invariant, as long as SIGCHLD is never registered.
- **Parent-PID watchdog.** `--parent-pid <pid>` / `SUGARCRUSH_SERVER_PARENT_PID` makes the server exit when its spawner dies (kilo `KILO_PARENT_PID`). It is needed when the TUI or an editor spawns a private server (Phase 8).
- **Background agents (`/bg`).** The server owns a `BackgroundSupervisor`. At boot it calls **`reconnect()`** (DORMANT today) to re-adopt daemons that outlived a previous server or TUI, which wires a dormant subsystem. Its notifications (`SessionNotificationInterface` `src/Sessions/SessionNotificationInterface.php`) become `bg.*` events.
  - Fix while there: **`/bg` results never land back in chat** and **`/fork` ignores the forked history** (Appendix A). The server's `bg.completed` event plus a `bg.inject {bgId, sessionId}` method can close the first gap.
- **systemd user unit.** Ship `docs/examples/sugarcrush.service` with `Type=simple`, `ExecStart=sugarcrush serve`, `Restart=on-failure`. Foreground mode under systemd needs no daemonize.

### 4.7 Configuration: flags, env vars, settings keys and their doc obligations

**CLI** (`sugarcrush serve [flags]`):

| Flag | Default | Notes |
|---|---|---|
| `--host` | `127.0.0.1` | Non-loopback refused unless `--allow-remote` (§8.3) |
| `--port` | `7420` | `0` = ephemeral, written to `server.json` |
| `--root` | cwd | Existing global flag, reused |
| `--detach` | off | Daemon mode |
| `--allow-remote` | off | Permits non-loopback bind; requires the token, warns loudly without TLS |
| `--allowed-origin` | (repeatable) | Extra `Origin`s beyond same-origin |
| `--web-root` | auto | Directory of the web build; overrides `sugar-crush-web` discovery |
| `--no-web` | off | API/WS only |
| `--allow-bypass` | off | Lets clients set `bypass-permissions` / `dont-ask`... see §8.4 |
| `--parent-pid` | — | Watchdog |
| `--permission-mode` | `default` (in serve) | Existing global flag; **serve's default differs from the TUI's `bypass-permissions`** (§8.4) |

**Env vars** (each must be added to `docs/ENVIRONMENT.md` **tables**: `tests/Config/EnvRosterDriftTest.php` scans every `getenv()` shape in `src/` and fails in both directions):
`SUGARCRUSH_SERVER_HOST`, `SUGARCRUSH_SERVER_PORT`, `SUGARCRUSH_SERVER_TOKEN` (overrides the token file; for containers), `SUGARCRUSH_SERVER_ALLOWED_ORIGINS`, `SUGARCRUSH_SERVER_WEB_ROOT`, `SUGARCRUSH_SERVER_PARENT_PID`, `SUGARCRUSH_SERVER_DIR` (state dir override; tests need it).

**Settings keys** (user tier only; extend `LayeredSettings::LAYERED_KEYS` `src/Config/LayeredSettings.php`, and keep them **out of** `PROJECT_TIER_KEYS`, because a cloned repo must not open a port or widen origins):
`server.host`, `server.port`, `server.allowedOrigins`, `server.maxConcurrentTurns`, `server.maxOpenSessions`, `server.askTimeoutSeconds` (0 = never), `server.drainSeconds`, `server.allowBypass`.
- `ConfigWriteProducerDocumentationDriftTest` pins which keys the app *writes*. If the web settings form writes keys, every new producer must be documented in `docs/SETTINGS.md` (§6.10).

**Other doc obligations (drift-pinned):**
- `ParsedArgs::SUBCOMMANDS` + `Subcommands::SUBCOMMAND_DESCRIPTIONS` (`src/Cli/Subcommands.php`) + completion operands. Then the README subcommand fence: `ReadmeRosterDriftTest::testTheSubcommandFenceNamesEveryVerbTheParserAccepts` (`tests/Config/ReadmeRosterDriftTest.php`).
- New global flags (`--detach` only if global; prefer subcommand-scoped flags) must agree with `Subcommands::OPTIONS`: `ClaimFamiliesDocumentationDriftTest::testTheFlagSynopsisAndArgvParserAgreeInBothDirections`.
- New exit codes (e.g. "port in use" = 3?) go through `NonInteractive`'s constants: `testBothExitCodeTablesAreTheNonInteractiveConstants`.
- If the server adds slash commands (e.g. `/serve status` inside the TUI), `CommandRegistry` + `docs/COMMANDS.md` (`ReadmeRosterDriftTest`). Key bindings (e.g. a TUI "attach to server" chord) go in `KeyBindingRegistry` + README (`tests/Commands/KeyBindingDriftTest.php`).
- **New:** `docs/SERVER.md` with the method and event roster **generated from `Protocol\Dispatcher::methods()` and `Protocol\EventType::cases()`**, plus a `ServerProtocolDocumentationDriftTest` in the house style. That is the cheapest way to keep the web client and the docs honest.
- Every new test file goes into `scripts/parallel-tests-durations.tsv`, and the suite figure is refreshed in `sugar-crush/tests/Config/Support/suite-figure.json` (CLAUDE.md gotcha: unlisted files run in zero shards).

### 4.8 Session persistence, leases, and TUI + server coexistence

- **Persistence.**
  - `SessionHost` writes through `TranscriptStore` → `EnhancedSessionStore::saveTranscript()`, unchanged shape, so the TUI can still open server sessions with `--resume`.
  - Checkpoints as today.
  - **New table** `session_events(session_id TEXT, seq INTEGER, ts INTEGER, type TEXT, payload TEXT, PRIMARY KEY(session_id, seq))`, added through the store's existing schema bootstrap (`EnhancedSessionStore.php`).
  - Retention: a per-session cap (default 20,000 events, or 64 MiB per DB) plus `pruneSessions` cascade. Mind the WAL notes already in the store: never hold a read cursor across an INSERT.
- **Lease** (prevents two writers clobbering a whole-transcript rewrite).
  - Reuse the existing single-writer lock: `src/Session/SessionLock.php` (flock on `<config>/sessions/<id>.lock`), `EnhancedSessionStore::lockSession()`/`sessionLockHolder()`, and Chat's read-only fallback (`Chat::relockedForCurrentSession()`, `SessionLockRetryMsg`). `SessionHost` takes it on open.
  - The TUI opening a server-held session gets a choice: **attach via the server** (Phase 8), or **open read-only**.
- **Message ids.** Add `public readonly ?string $id` to `Message` (minted on construction, e.g. 16-hex random or a ULID), persisted in transcript JSON. Old rows without ids get deterministic ids on load (`sha1(sessionId . index . createdAt)`). Wire ids must be stable across compaction rewrites; index-based ids are not.

### 4.9 The TUI as a client (Phase 8)

- `sugarcrush attach [url|--discover]` reads `server.json` (or a URL plus token), opens a WS, and runs the normal `Program(App)` with a **`Backend\RemoteBackend`** (implements `Backend`, `ObservesReasoning`) plus a `RemoteSessionHost` proxy that implements the same `SessionHost` client surface over JSON-RPC.
- Because Chat already delegates to `Host\*` services after the extraction, the remote variant swaps the service implementations, not Chat.
- **Benefits:**
  - the TUI and browser see the same live session;
  - turns survive closing the terminal;
  - the TUI gains approvals parity automatically.
- **Optional cheap remote-TUI channel:** because candy-core `Program` accepts `input`/`output` streams and `windowSize` (`ProgramOptions.php`), a `/pty` WS endpoint could stream the real TUI into xterm.js (ttyd-style). candy-wish already does "TUI over SSH via sshd". This is low-effort but **not** multi-session-friendly. Keep it as a footnote, not the plan.

---

## 5. The unified permission back-channel (the keystone)

This one mechanism serves the TUI (fixes Appendix A), the server, and later ACP.

### 5.1 Child ↔ parent frames (extends `EngineBackend`'s existing framing)

The frames keep the existing 4-byte BE length + `serialize()` encoding (`writeFrame`, `drainFrames`), and keep unserializing with `allowed_classes => false` (see `encodeEvent()` notes).

| Direction | `kind` | Fields | Meaning |
|---|---|---|---|
| child→parent | `ask` | `askId` (16-hex), `toolCallId`, `tool`, `arguments` (**after** any hook rewrite: `Runtime::asAsked()`), `reason` (`HookResult` message), `source` (`gate`\|`hook:<name>`), `mode`, `suggestions` (`['once','always','reject']`, plus an `always` scope hint e.g. `{tool:'Bash'}`) | Settle an Ask |
| parent→child | `ask_reply` | `askId`, `reply` (`once\|always\|reject`), `note?` (≤2 KiB; on reject it becomes model-visible feedback, as in opencode `CorrectedError`) | Answer |
| parent→child | `steer` | `text`, `steerId` | Inject a user message at the next step boundary (§6.6) |
| parent→child | `cancel_soft` | — | Finish the current tool, then stop at the step boundary (graceful interrupt). Hard cancel stays SIGKILL |
| child→parent | `steer_ack` | `steerId`, `step` | The steer landed in history at step N |
| child→parent | `usage` | `step`, `usage` (array) | Per-step usage, so the UI can update cost live (today usage arrives only in `result`) |
| child→parent | `step` | `step`, `maxSteps` | Step boundary, for progress display |

**Child side.**
- `runCompleteInChild()` attaches an approver: `withPermissionApprover(fn(ToolCall $c, HookResult $ask) => $channel->ask($c, $ask))`.
- `ChildChannel::ask()` writes the `ask` frame, then blocks in a `stream_select` loop on the child socket until it gets the matching `ask_reply`, buffering any `steer` frames that arrive meanwhile.
- **No deadline in the child.** The parent owns policy. The parent's death shows as EOF, which is a refusal (`DenialKind::Unanswered`).
- **`always` scope.** The child records a per-turn allow memo, keyed like `Runtime::taskGrantMemoKey()`. The **parent** records a per-session rule (`SessionPermissionMemo`) that is passed into the next turn's `PermissionGate` as a prepended in-memory `permissionRules` entry. It is never written to settings files by default. "Remember across sessions" is a separate explicit action (§6.7).

**Parent side** (`completeAsync()`):
1. The read callback decodes `ask` → `PermissionAsked` event → `$onEvent`.
2. **Suspend the 120 s idle watchdog while any ask is pending** (`$resetTimeout` at currently re-arms on every frame). Otherwise a human taking two minutes to read a diff kills the turn. Re-arm on `ask_reply` write.
3. Expose a reply handle: `PendingAsk { string $askId; ToolCall $call; …; reply(PermissionReply $r, ?string $note): void }`, which writes the frame to `$parentSocket` (now `stream_set_blocking(false)` + a small write buffer drained with `addWriteStream`).
4. On cancel or teardown, pending asks resolve as `cancelled`. Emit `PermissionResolved{cancelled:true}`.

**Parallel Task sub-agents (grandchildren).** Their gate runs in the grandchild, which has no socket of its own. Writing to the inherited turn-child socket from two processes would interleave frames, which is exactly why `subAgentEmitter` is pid-bound (`EngineBackend.php`).

*Phase 6 fix:* before forking each concurrent job, `Runtime::executeConcurrently()` creates a socketpair per job. The turn child multiplexes it: it relays `ask`/`subagent` frames upward tagged with `origin: <toolCallId>`, and relays `ask_reply` downward.

This also fixes Appendix A "parallel batches show no live dashboard rows". **Until then, grandchild Asks keep failing closed** with a clear reason ("approval from a parallel sub-agent is not yet supported; run it alone or allow it by rule").

### 5.2 TUI consumption

- `Chat::scheduleBackendCompletion()` adds `PermissionAsked` to the inbox.
- `ToolEventPumpMsg` drains it → `PermissionRequestMsg` (exists) → the Veil y/n/a modal (exists; `a` double-confirm).
- `PermissionReplyMsg` → `PendingAsk::reply()`.

Once this ships, the TUI default can move from `bypass-permissions` to `default` or `accept-edits` in a later, deliberate change. Touch `docs/PERMISSIONS.md` and README "Limitations", and note that `TrustKeyDocumentationDriftTest` reads `docs/PERMISSIONS.md`.

### 5.3 Server consumption

`SessionHost` turns `PermissionAsked` into a durable `permission.requested` event and keeps `pendingAsks[askId]`. `permission.respond` → `PendingAsk::reply()`. Multi-client semantics are in §6.7.

---

## 6. Wire protocol (`sugarcrush.v1`)

### 6.1 Transport and framing

- **WS endpoint `/ws`.**
  - The subprotocol **must** be `sugarcrush.v1`. Echo it in the 101, and refuse the upgrade without it (close 1002).
  - Text frames, UTF-8 JSON.
  - `permessage-deflate` **off** by default: latency, CPU, and the BREACH-style concern with secrets plus attacker-influenced text. Revisit later.
- **Envelope = JSON-RPC 2.0** (codec: `SugarCraft\Mcp\McpMessage`, not sugar-crush's own `SugarCraft\Crush\McpMessage` copy; later, lift a protocol-neutral `JsonRpcMessage` into sugar-mcp):
  - request `{"jsonrpc":"2.0","id":"c1-42","method":"session.send","params":{…}}`
  - response `{"jsonrpc":"2.0","id":"c1-42","result":{…}}` | `{"jsonrpc":"2.0","id":"c1-42","error":{"code":-32010,"message":"…","data":{"kind":"busy","retryable":true,"retryAfterMs":500}}}`
  - server→client events = notifications `{"jsonrpc":"2.0","method":"event","params":<EventEnvelope>}`
  - server→client *requests* are **not** used. Approvals are events plus a client request, so any number of clients can see them and one answers. (ACP uses server→client requests; the ACP adapter maps them, §6.12.)
- **Limits.**
  - Client→server frame ≤ 1 MiB (prompts; large attachments come later via HTTP upload).
  - Server→client frame ≤ 4 MiB. Bigger tool outputs are truncated in the event with `truncated:true`, and the full text comes from `tool.output {toolCallId, offset, limit}`. Tool output is already capped at 64 KiB–2 MiB per tool except MCP (uncapped; Appendix A). Cap MCP at the event layer.
  - ≤ 64 in-flight requests per connection.

### 6.2 Handshake and versioning

```
C → S  (WS upgrade, cookie or ?ticket=…)  Sec-WebSocket-Protocol: sugarcrush.v1
C → S  {"id":"h","method":"server.hello","params":{
          "minProtocol":1,"maxProtocol":1,
          "client":{"name":"sugar-crush-web","version":"0.1.0","instanceId":"tab-7f3a"},
          "caps":["deltas","narration","diff.unified","images.inline"],
          "resume":{"<sessionId>":<lastSeq>, …}          // optional: resubscribe after reconnect
       }}
S → C  {"id":"h","result":{
          "protocol":1,"server":{"version":"x.y.z","connectionId":"k9…","root":"/repo","pid":1234},
          "features":{"methods":[…],"events":[…]},       // generated from Dispatcher registry
          "limits":{"maxClientFrameBytes":1048576,"maxServerFrameBytes":4194304,
                    "maxInflight":64,"tickIntervalMs":15000,"maxBufferedBytes":16777216},
          "principal":{"kind":"owner","scopes":["read","write","approve","admin"]},
          "defaults":{"permissionMode":"default","provider":"sglang","model":"…"},
          "resumed":{"<sessionId>":{"fromSeq":…,"throughSeq":…}|{"reset":true}}
       }}
```

- Every request other than `server.hello` before hello completes gets error `-32002 not_initialized` and a close (4002).
- Frames before hello are capped at 64 KiB (OpenClaw).
- The protocol integer is major. Additive changes go through `features`/`caps`. Clients must ignore unknown event types and fields (OpenHands rule).
- **Error codes:**

| Code | Meaning |
|---|---|
| `-32700` | parse error |
| `-32600` | invalid request |
| `-32601` | method not found |
| `-32602` | invalid params |
| `-32001` | unauthorized |
| `-32003` | forbidden (scope) |
| `-32004` | not found |
| `-32009` | conflict, e.g. lease or `already_resolved` |
| `-32010` | busy (retryable) |
| `-32011` | rate limited |
| `-32020` | permission mode refused |
| `-32030` | unsupported in server (UI-only command) |
| `-32099` | internal |

- Errors carry a `data.kind` string. Clients branch on `kind`, never on `message` (OpenClaw).

### 6.3 Method roster (v1)

Every side-effecting method takes an optional `idempotencyKey` (≤64 chars). The server keeps a dedupe map (5 min TTL, 1,000 entries per connection principal, the OpenClaw figures) that returns the original result.

| Namespace | Method | Params → Result | Maps to |
|---|---|---|---|
| server | `server.hello` | §6.2 | — |
| | `server.health` | → `{ok, version, uptimeS, sessionsOpen, turnsRunning, draining}` | — |
| | `server.info` | → providers, models, agents roster, skills, commands, permission modes, tool roster | `Bootstrap::availableProviders()`, `AgentManager`, `SkillRegistry`, `CommandRegistry::all()`, `CommandLoader` |
| | `server.shutdown` | `{drainSeconds?}` (scope admin) | §4.6 |
| client | `client.viewing` | `{sessionIds[], foreground?}` | presence (§4.5) |
| session | `session.list` | `{limit?, cursor?, query?}` → `{items:[SessionSummary], nextCursor}` | `EnhancedSessionStore::listSessionsWithMeta()` |
| | `session.create` | `{name?, provider?, model?, permissionMode?, agent?}` → `SessionSummary` | `SessionHub::create` |
| | `session.get` | `{sessionId}` → `SessionSnapshot` (rows, status, usage, pendingAsks, queue, seq) | `SessionHost::snapshot` |
| | `session.subscribe` | `{sessionId, afterSeq?, mode?: "full"\|"narration"}` → `{fromSeq, throughSeq}` or `{reset:true, snapshot}` | §6.8 |
| | `session.unsubscribe` | `{sessionId}` | |
| | `session.rename` | `{sessionId, name}` | `renameSession` (`EnhancedSessionStore.php`) |
| | `session.fork` | `{sessionId, atCheckpoint?}` → `SessionSummary` | `forkSession` / `/branch` |
| | `session.rewind` | `{sessionId, n}` | `/rewind` logic (`Chat.php`) |
| | `session.clear` | `{sessionId}` | `/clear` |
| | `session.delete` | `{sessionId}` | `deleteSession` |
| | `session.close` | `{sessionId, force?}` | release the session lock |
| | `session.export` | `{sessionId, format:"markdown"\|"json"\|"text"}` → `{content}` | `Util\Exporter` (the `/share` builder). Gives `/share` a working **local** fallback (Appendix A) |
| | `session.setMode` | `{sessionId, permissionMode}` | §8.4 |
| | `session.setModel` | `{sessionId, provider?, model?}` | Fixes `/model` semantics (provider **and** model, with Task preserved, §4.3) |
| turn | `session.send` | `{sessionId, text, delivery?: "queue"\|"steer"\|"interrupt", attachments?[], idempotencyKey}` → `{turnId, admitted:"started"\|"queued"\|"steered", queuePosition?}` | `TurnController::submit` |
| | `session.cancel` | `{sessionId, turnId?, mode?: "hard"\|"soft", clearQueue?}` | Esc-Esc teardown / `cancel_soft` |
| | `session.queue` | `{sessionId}` → queued prompts; `session.dequeue {sessionId, queueId}` | `enqueuePrompt` |
| permission | `permission.respond` | `{sessionId, askId, reply:"once"\|"always"\|"reject", note?, remember?: "session"\|"project"\|"user"}` → `{applied:true}` or error `already_resolved` | §6.7 |
| | `permission.pending` | `{sessionId?}` → `[PermissionRequested]` | rebuild after reconnect |
| | `permission.rules` | → effective rules + source (read-only) | `/permissions` report |
| command | `command.list` | `{sessionId}` → `[{name, description, argumentHint, source, runsIn:"server"\|"client"}]` | `CommandRegistry` + `CommandLoader` |
| | `command.exec` | `{sessionId, name, args, idempotencyKey}` → `CommandResult{rows[], effects[]}` | `Host\Commands\*`; UI-only commands → `-32030` |
| settings | `settings.schema` | → JSON-Schema-ish registry (§6.10) | `Config\Settings\SettingsSchema` (Appendix N) |
| | `settings.get` | `{scope?: "effective"\|"user"\|"project"}` → values + per-key source (`LayeredSettings::projectKeySource()`) | |
| | `settings.set` | `{key, value, scope:"user"\|"project"}` (scope admin; allowlisted keys only, §8.5) | |
| memory | `memory.list/search/add/edit/delete` | mirror `/memory` | `MemoryStore`, `ProjectMemoryWriter` |
| agents | `agents.list` | roster with sources | `AgentManager` |
| | `agents.subtree` | `{sessionId}` → live sub-agent tree | `SubAgentActivity` projection |
| bg | `bg.list`, `bg.spawn {task, agent?}`, `bg.stop {bgId}`, `bg.output {bgId, offset}`, `bg.inject {bgId, sessionId}` | | `BackgroundSupervisor` |
| workflow | `workflow.list`, `workflow.run {name, vars}`, `workflow.pause/resume/status` | | `WorkflowEngine` |
| files | `files.diff {sessionId}` → `git diff` + per-turn edits; `files.read {path}` (root-jailed, read-only, size-capped); `files.changed {sessionId}` | | `EnvironmentBlock` git helpers, `Tools\PathJail` |
| tool | `tool.output {sessionId, toolCallId, offset, limit}` | full output beyond event truncation | transcript rows |
| todo | `todo.get {sessionId}` | **Absent tool today** (Appendix A). Reserve the name; implement with the TodoWrite tool when it lands | — |

### 6.4 Event envelope

```json
{
  "sessionId": "a1b2c3d4e5f60718",        // null for server-scope events
  "seq": 1842,                             // per-session durable seq; omitted on ephemeral events
  "type": "tool.finished",
  "ts": 1790000000123,
  "turnId": "t_9f…",                       // when inside a turn
  "durable": true,
  "data": { … }
}
```

**Ephemeral events never carry `seq`, and a client never advances its cursor on them.** Every ephemeral stream is bracketed by durable start and end events (the OpenHands invariant).

### 6.5 Event catalogue (v1)

Durable (D) events are written to `session_events` before they are broadcast. Ephemeral (E) events are live only.

| Type | D/E | `data` | Source in sugar-crush |
|---|---|---|---|
| `session.created` / `session.updated` / `session.deleted` | D (server scope) | `SessionSummary {id, name, provider, model, permissionMode, createdAt, updatedAt, messageCount, spentUsd, status}` | store ops, title rename |
| `session.status` | D | `{status: "idle"\|"busy"\|"waiting_permission"\|"queued"\|"compacting"\|"error", detail?}` | TurnController |
| `message.created` | D | `{messageId, role, content, createdAt, kind:"user"\|"assistant"\|"system"\|"notice"}` | user prompt rows, notices, compaction summaries |
| `turn.started` | D | `{turnId, messageId (user), provider, model, maxSteps, delivery}` | dispatch |
| `assistant.started` | D | `{turnId, partId}` | first token |
| `assistant.delta` | **E** | `{turnId, partId, offset, text}` | `token` frames. `offset` = byte offset into the part, so duplicates and gaps are detectable |
| `reasoning.delta` | **E** | `{turnId, partId, offset, text}` | `reasoning` frames (empty heartbeats are **not** forwarded) |
| `assistant.completed` | D | `{turnId, partId, messageId, content, reasoning?, lengthStopped, stepsTruncated}` | `result` frame. Closes the delta stream |
| `tool.started` | D | `{turnId, toolCallId, name, description?, arguments}` | `started` frame (`encodeEvent`) |
| `tool.progress` | E | `{toolCallId, tail}` | future (Bash streaming) |
| `tool.finished` | D | `{turnId, toolCallId, name, isError, durationMs, content (≤256 KiB, `truncated`), diff? (unified), image? {protocol, mime, bytesB64 ≤ 2 MiB}\|{path}, denial? {kind: DenialKind, reason}}` | `finished` frame, `DenialKind` |
| `permission.requested` | D | `{askId, turnId, toolCallId, tool, arguments, reason, source, mode, options:["once","always","reject"], alwaysScope}` | §5 |
| `permission.resolved` | D | `{askId, reply, note?, by:{clientId, principal}, cancelled?:bool, cascaded?:bool}` | §6.7 |
| `subagent.started` / `subagent.progress` (E, throttled 1/s) / `subagent.finished` | D/E/D | `{id, parentToolCallId, name, task, tail?, usage?, result?}` | `subagent` frames, `SubAgentActivity` |
| `usage.updated` | D | `{turnId?, step?, usage{totalTokens, inputTokens, outputTokens, cacheReadTokens, reasoningTokens, costUsd}, sessionSpentUsd, contextTokens, contextLimit, contextPct}` | `usage` frames + `Usage::toArray()` + `ContextWindow`/token calibration |
| `spend_cap.breached` | D | `{calls, spent, cap}` | `spend_cap` frame |
| `turn.step` | E | `{turnId, step, maxSteps}` | `step` frame |
| `turn.steered` | D | `{turnId, steerId, step, messageId}` | `steer_ack` |
| `turn.completed` | D | `{turnId, stopReason: "end_turn"\|"max_steps"\|"length"\|"cancelled"\|"spend_cap"\|"error", error?}` | settle |
| `turn.queued` / `turn.dequeued` | D | `{queueId, text, position}` | queue |
| `compaction.started` / `compaction.completed` | D | `{kind:"llm"\|"heuristic"\|"truncate", before, after, savedPct, summaryMessageIds[]}` | `Chat.php` |
| `notice` | D | `{level:"info"\|"warn"\|"error", text, code?}` | `RuntimeNoticeSink` (per session), launch notices |
| `session.titled` | D | `{name}` | `SessionTitledMsg` |
| `prompt.suggestion` | E | `{text}` | `schedulePromptSuggestion` |
| `bg.started` / `bg.output` (E) / `bg.status` / `bg.completed` | D/E/D/D (server scope) | `BackgroundSession::toArray()` | supervisor listener |
| `workflow.*` | D | stage events | `WorkflowEngine` |
| `server.tick` | E (server scope) | `{now, turnsRunning}` | every `tickIntervalMs` |
| `server.shutdown` | E | `{reason, graceSeconds}` | §4.6 |
| `server.overflow` | E | `{sessionId?, dropped, action:"resubscribe"}` | §6.9 |

**Not in the stream: secrets.**
- `settings.*` values for provider `apiKey`-like keys are masked.
- Hook and MCP env are never sent.
- `notice` text is clipped (`RuntimeNoticeSink::clip()` exists).

See §8.6.

### 6.6 Send, queue, steer and interrupt

- `delivery: "queue"` (the default when busy) is today's behaviour (`enqueuePrompt`).
- **`delivery: "steer"`** (new; Appendix A "mid-turn steering ABSENT"):
  1. The parent writes a `steer` frame.
  2. The child's `runTurn()` loop (`EngineBackend.php`) checks `ChildChannel::takeSteers()` at the **top of each step**, appends `UserMessage("[steering] …")` to the in-turn messages, and acknowledges with `steer_ack`.
  3. If the turn finishes before the next step, the steer converts to a queued prompt (`turn.steered` is not emitted; `turn.queued` is).
  - This is the OpenClaw `queueMode: steer` / opencode `delivery: steer` semantics.
- `delivery: "interrupt"` = `cancel_soft`, then send as a new turn.
- The response separates **admission** (`admitted: started|queued|steered`) from the durable `message.created` seq (OpenClaw's `runStarted`/`messageSeq` split).

### 6.7 Permissions over the wire (multi-client semantics)

```
 turn child          SessionHost (parent)                  client A (web)            client B (TUI attach)
     │ ask{askId,…} ───►│ status=waiting_permission (D)          │                         │
     │                  │ permission.requested (D, seq 812) ────►│ shows card              │ shows modal
     │  (blocks)        │                                        │                         │
     │                  │◄─────────── permission.respond{askId,once} ───────────────────── │ (B first)
     │◄─ ask_reply ─────│ permission.resolved{by:B} (D, seq 813) ───►│ card closes         ─►│
     │                  │◄─ permission.respond{askId,reject} ────│ (late)                  │
     │                  │── error -32009 already_resolved ──────►│                         │
```

- **First valid answer wins.** Later answers get `already_resolved` with the winning `resolved` payload in `error.data` (cline, OpenClaw).
- **Reject cascade.** A `reject` with `cascade: true` (the default in the UI's "Reject & stop" button) resolves every other pending ask in the session as rejected, then soft-cancels the turn (opencode behaviour).
- **Always auto-cover.** When `always` adds a session rule, the host re-evaluates other pending asks for the same tool and resolves those now covered (`cascaded: true`).
- **Reconnect.** `session.subscribe` replays durable events after `afterSeq`. Since `permission.requested` is durable, a pending ask is visible after any reconnect, even across a server restart (but a restart kills the child, so it is resolved `cancelled`). `server.hello.resume` + `permission.pending` cover the dashboard view.
- **Binding to exact arguments.** The `ask` frame carries the post-rewrite arguments, and the child grants **only that call**: the reply is matched by `askId`, and `askId` is bound to a hash of `{toolCallId, tool, arguments}`. A client cannot approve a different payload (OpenClaw "immutable plan").
- **Timeouts.** `server.askTimeoutSeconds` (default 0 = wait forever, since the user may be away from the browser). If set, it resolves `reject` with `note: "timed out after N s"`.
- **Scopes.** Answering needs `approve`. For a future read-only viewer token (share a live session read-only), mint tokens with `["read"]` only.
- **Remember.**
  - `remember: "session"` = an in-memory session rule.
  - `"project"`/`"user"` writes a `permissionRules` entry. Permission keys are **user-tier only** today (Appendix A), so `"project"` should be refused until a project-tier permission design exists. `"user"` requires `admin` scope and writes through the same `ConfigWriteProducer` path, which is drift-pinned.

### 6.8 Subscribe, replay and resync

- **Server algorithm** (`session.subscribe {sessionId, afterSeq}`):
  1. Attach a live listener to the host and buffer its events (subscribe-before-snapshot).
  2. If `afterSeq` is null → send `{reset:true, snapshot}` (a `SessionSnapshot` plus `throughSeq`).
  3. Else, if `afterSeq < oldestRetainedSeq` → `{reset:true, snapshot}` (OpenClaw `{kind:"reset"}`).
  4. Else, page `session_events WHERE seq > afterSeq` in batches of 200, yielding to the loop between pages (`Loop::futureTick`), as cline does.
  5. Flush the buffered live events, deduping `seq <= lastSent`.
  6. Go live.
- **Snapshot contents.** Transcript rows (with ids), the in-progress assistant part (`partId` + text so far, so a mid-turn attach paints immediately, as OpenClaw's first-frame snapshot does), pending asks, queue, usage, status and `throughSeq`.
- **Client cursor = highest gap-free seq** (OpenHands `session-seq-cursor`). On a gap that persists more than 2 s, or a gap above 1,024, re-subscribe with the cursor. Ephemeral deltas use `offset`: a delta whose offset ≠ current length is dropped, and the durable `assistant.completed` repairs the text.
- **Server restart.** Seqs continue from `MAX(seq)` per session in the DB. `connectionId` changes, and clients detect that via `hello` and re-subscribe with their cursors.

### 6.9 Backpressure

- Each connection has an **outbox** (`Server\Ws\Outbox`) with byte accounting. React's `ConnectionInterface::write()` returns `false` once the kernel buffer is full; we then stop draining and wait for `drain`.
- **Watermarks** (advertised in hello):
  - **Soft (1 MiB):** coalesce queued `assistant.delta`/`reasoning.delta` for the same `partId` into one frame. This is lossless: concatenate, keep the first offset. Downgrade that connection's non-foreground subscriptions to **narration** mode.
  - **Hard (`maxBufferedBytes`, 16 MiB):** drop pending **ephemeral** events, send `server.overflow`, and if the outbox stays above hard for 10 s, close with **1013** (OpenHands "disconnect is the backpressure", nanobot 10 s send timeout). Durable events are never dropped silently: the client recovers them via replay.
- **The turn never blocks on a client.** Events go host → log → per-connection outbox. The fork socket read path is unaffected.
- **Narration mode** (per subscription, `mode: "narration"`): no deltas. Every ≤2 s a `assistant.narration {partId, tail ≤ 4 KiB}`, plus all durable events. It is used for sidebar tiles and the multi-pane dashboard's unfocused panes (OpenClaw).
- **Inbound limits.**
  - Request rate per connection: 50/s burst 200.
  - Concurrent `session.send` per session: 1 admission at a time (others `busy` or queued).
  - Max subscribers per session: 50 (OpenHands).

### 6.10 Settings over the wire: a schema-driven registry

There is no settings schema today. `LayeredSettings` has key lists (`LAYERED_KEYS`, `PROJECT_TIER_KEYS`, `userTierOnlyKeys()`) and the Settings pane is a read-only readout (Appendix A).

Use Appendix N's **`SettingsSchema`** / `SettingDefinition` (`SugarCraft\Crush\Config\Settings\`) as the single source for the TUI settings editor, `settings.schema`, `docs/SETTINGS.md` generation, and validation. The server needs two extra per-row flags on `SettingDefinition`: `writableRemotely` and `sensitive`.

- `settings.schema` returns JSON-Schema-compatible descriptors plus UI hints `{group, label, help, enum, sensitive, writableRemotely, requiresRestart, tiers, source}`. The web UI renders its settings form purely from this.
- **Remote writes are allowlisted** (`writableRemotely`):
  - **never** `statusLine` (executes a command), `instructions` (globs that pull files into prompts), `trustedProject*`, `permissionMode`/`permissionRules` (except through `permission.respond remember:user` with `admin`), hooks, or MCP config;
  - **yes** for theme, titleModel, summaryModel, maxOutputTokens, maxToolSteps, parallelToolCalls, disabledSkills, disabledRules.
- A drift test asserts that `SettingsSchema` covers exactly `LAYERED_KEYS` + trust keys (consistent with `TrustKeyDocumentationDriftTest` and `ConfigWriteProducerDocumentationDriftTest`).

### 6.11 Versioning policy

- `protocol: 1` = this document's §6.
- **Additive only within a major:** new methods, new event types, new optional fields, all advertised in `features`/`caps`.
- Breaking changes bump the major. The server may serve `[1..2]` for one release window (opencode serves v1 and v2 side by side).
- The web UI is **version-locked** to its server by default: it is served from the same process, and it reloads when `hello.server.version` changes after a reconnect (OpenClaw).
- A JSON Schema for every params, result and event `data` lives at `sugar-crush/docs/protocol/sugarcrush.v1.schema.json`. It is **generated from PHP value objects** (`Protocol\Schema\*`) by a `tools/gen-protocol-schema.php`-style generator with `--check`, and consumed by the web package's codegen (`openapi-typescript`-like `json-schema-to-typescript`). This mirrors OpenClaw's TypeBox → JSON Schema → models pipeline and the monorepo's own `gen-docs.php`/`gen-ansi-readme.php --check` habit.

### 6.12 Should we also speak ACP? Yes, as an adapter (Phase 8)

- **`sugarcrush acp`** is a stdio JSON-RPC 2.0 adapter. It reuses `sugar-mcp`'s NDJSON framing knowledge and maps onto an **in-process** `SessionHub`, or, with `--connect`, onto a running server.
- **Minimum for Zed** (from `zed/crates/agent_servers/src/acp.rs`):

| ACP method | Maps to |
|---|---|
| `initialize` (`protocolVersion:1`, `agentCapabilities{loadSession:true, promptCapabilities{image:false, embeddedContext:true}}`, `authMethods:[]`) | `server.hello` |
| `authenticate` | no-op |
| `session/new {cwd, mcpServers}` | `session.create` (cwd must equal the workspace root, else spawn a workspace host in Phase 7) |
| `session/load` | `session.subscribe {afterSeq:null}` and replay rows as `session/update` |
| `session/prompt` | `session.send` and resolve on `turn.completed` (`stopReason` mapping: end_turn / max_turn_requests←max_steps / max_tokens←length / cancelled / refusal) |
| `session/cancel` | `session.cancel` |
| `session/update` | from events: `agent_message_chunk`←`assistant.delta`, `agent_thought_chunk`←`reasoning.delta`, `tool_call`←`tool.started` (with `kind` from tool name: Read→read, Edit/Write→edit, Bash→execute, Grep/Glob→search, WebFetch→fetch, Task→think\|other), `tool_call_update`←`tool.finished` (status completed/failed, `content:[{type:"diff", path, oldText, newText}]` when a diff exists; this needs old/new text, not just the unified diff, so `ToolResult` must also carry `oldText`/`newText` for Edit/Write, small change in `BuildsUnifiedDiff` callers), `usage_update`←`usage.updated`, `available_commands_update`←`command.list`, `current_mode_update`←permission mode |
| `session/request_permission` (agent→client **request**) | the adapter turns `permission.requested` into this request and maps `allow_once→once`, `allow_always→always`, `reject_*→reject`; `cancelled`→reject + cancel |

- Skip `fs/*` and `terminal/*` (the agent keeps its own tools, as goose and dsh do).
- `session/set_mode` maps to permission modes later.

---

## 7. `sugar-crush-web` (Vite + Vue 3)

### 7.1 Name and placement

- **Rulebook check.** `PROJECT_NAMES.md` §"The naming rule" requires **two words** (sweet word + functional word). `sugar-crush-web` is three. It is still the most legible choice: it declares itself the web face of `sugar-crush` and sorts next to it.
- **Recommendation:** keep `sugar-crush-web/`, package `sugarcraft/sugar-crush-web`, PHP namespace `SugarCraft\CrushWeb\`. Record an explicit **"app satellite: `<app>-<surface>`"** exception in `PROJECT_NAMES.md`, and add the decision-history row the file already keeps for renames.
- Avoid the `Candy-` prefix: this is an app, not a foundation (prefix law: Sugar = components/data/apps).
- **MATCHUPS row:** `| — (first-party) | **SugarCrushWeb** | sugar-crush-web/ | sugarcraft/sugar-crush-web | SugarCraft\CrushWeb | 🟡 | Browser UI for sugar-crush's WebSocket server mode — multi-session dashboard, approvals, settings; Vite + Vue 3; inspired by opencode web / OpenClaw Control UI. |`

### 7.2 Package layout

```
sugar-crush-web/
├── composer.json            # sugarcraft/sugar-crush-web, type "library", PSR-4 SugarCraft\CrushWeb\ → src/
├── phpunit.xml              # makes scripts/affected-libs.php discover it (composer.json + phpunit.xml marker)
├── src/Assets.php           # final class Assets { distPath(): string; manifest(): array; version(): string }
├── tests/AssetsTest.php     # dist/index.html exists, manifest hashes resolve, no source maps / .env leaked
├── dist/                    # COMMITTED build output (generated — never hand-edit; CI checks it matches)
├── package.json             # private:true; scripts: dev, build, typecheck, lint, test, e2e, gen:protocol
├── package-lock.json        # committed (npm ci reproducibility)
├── vite.config.ts           # base:'./', build.outDir 'dist', manifest:true, dev proxy /ws + /api → 127.0.0.1:7420
├── tsconfig.json, env.d.ts, eslint.config.js, .prettierrc
├── index.html
├── README.md, CALIBER_LEARNINGS.md
├── .vhs/ (none — exempt; see 7.9)  · examples/ (none)
├── src-web/                 # keep TS out of PSR-4 src/
│   ├── main.ts, App.vue, router.ts
│   ├── protocol/
│   │   ├── generated.ts     # from sugar-crush/docs/protocol/sugarcrush.v1.schema.json (npm run gen:protocol)
│   │   ├── client.ts        # SugarCrushClient: JSON-RPC over WS, hello, request/response map, idempotency keys
│   │   ├── reconnect.ts     # backoff 0.5s→15s ±30% jitter, tick watchdog 2×tickIntervalMs, resume cursors
│   │   └── cursor.ts        # gap-free seq cursor per session (OpenHands algorithm)
│   ├── stores/              # Pinia
│   │   ├── connection.ts    # status, server info, features, principal, latency
│   │   ├── sessions.ts      # summaries index (list + session.created/updated/deleted)
│   │   ├── session.ts       # factory: useSession(id) → rows, parts, tools, asks, queue, usage, status, cursor
│   │   ├── approvals.ts     # cross-session pending asks (badge counts, notifications)
│   │   ├── layout.ts        # open tabs / tiled panes / focus (persisted in localStorage — per-viewer)
│   │   └── settings.ts      # schema + values
│   ├── components/
│   │   ├── SessionSidebar.vue, SessionTile.vue, PaneGrid.vue, ChatPane.vue
│   │   ├── Transcript.vue (virtualized), MessageMarkdown.vue, ReasoningFold.vue
│   │   ├── ToolCard.vue, DiffView.vue, ImageResult.vue
│   │   ├── PermissionCard.vue, Composer.vue (queue/steer/interrupt), QueueStrip.vue
│   │   ├── StatusBar.vue (tokens %, $ spend/cap, model, mode), SubAgentTree.vue
│   │   ├── BackgroundTasks.vue, WorkflowPanel.vue, MemoryPanel.vue, CommandPalette.vue
│   │   └── SettingsForm.vue (schema-driven), Login.vue, ConnectionBanner.vue
│   ├── views/ Dashboard.vue, SessionView.vue, SettingsView.vue, LoginView.vue
│   └── styles/ tokens.css (themes mirroring src/Theme.php: dark, light, dracula, tokyoNight)
└── e2e/ playwright.config.ts, fixtures/server.ts, *.spec.ts
```

**Libraries (keep the dependency count small):**
- `vue@3`, `vue-router@4`, `pinia`, `@vueuse/core` (reconnect helpers, `useStorage`, notifications);
- `markdown-it` + `dompurify` (sanitise; model output is untrusted, and so is tool output);
- `shiki` (code highlighting, lazy-loaded languages);
- `@tanstack/vue-virtual` (long transcripts);
- diffs rendered from the unified diff in `tool.finished.diff` by a small own component (reuse the line-number semantics of `sugar-diff`/`Tui/DiffGutter.php`), with `diff2html` as an alternative if side-by-side is wanted.
- No UI kit at first: CSS variables plus a few headless primitives. Revisit (e.g. Naive UI, PrimeVue unstyled) only if forms grow.

### 7.3 Build and serve

- **Production:** `npm run build` → `dist/`, which is committed. `sugarcrush serve` locates the web root by:
  1. `--web-root` / `SUGARCRUSH_SERVER_WEB_ROOT`;
  2. else `class_exists(\SugarCraft\CrushWeb\Assets::class) ? Assets::distPath() : null`;
  3. else API-only, with a helpful `/` page ("install sugarcraft/sugar-crush-web").
- sugar-crush's composer.json gets `"suggest": {"sugarcraft/sugar-crush-web": "Browser UI for `sugarcrush serve`"}`. Use suggest rather than require, to keep headless installs lean. Per the CLAUDE.md gotcha, a later `require` bump would be one line: no `repositories[]` entry, verified with `php tools/check-path-repos.php --no-lib-path-repos`.
- **Static serving** (`Server\Http\StaticFiles`):
  - path-jail to the web root (`realpath` prefix check; reuse `Support\ContainedPath`);
  - hashed assets get `Cache-Control: public, max-age=31536000, immutable`; `index.html` gets `no-store`;
  - correct MIME types and a strict CSP: `default-src 'self'; connect-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'`;
  - also `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`.
- **Development:** `npm run dev` (Vite on) proxies `/ws` (with `ws: true`) and `/api` to `127.0.0.1:7420`. The server's Origin allowlist gains `http://localhost:5173` only with `--dev-origin` (or `server.allowedOrigins`); there is no wildcard.

### 7.4 Multi-session UX

```
┌───────────────────────────────────────────────────────────────────────────────────────────────────────┐
│ ◆ SugarCrush  /home/me/proj  ● connected 12ms   sglang · DeepSeek-V4   mode: default   [⌘K] [⚙] [⏻]   │
├──────────────────────┬────────────────────────────────────────────────────────────────────────────────┤
│ SESSIONS   [+ New]   │ [ fix-auth ● ] [ refactor-db ◌ ] [ docs ] [+]        layout: [▣ tabs] [▦ grid] │
│ 🔍 filter…           ├────────────────────────────────────────────────────────────────────────────────┤
│ ● fix-auth      2 ⚠  │ you  ▸ the login test is flaky, find out why                                   │
│   busy · $0.41 · 63% │ 💭 Thought (collapsed)                                                         │
│ ◌ refactor-db        │ ▾ Read  src/Auth/Login.php                            ✔ 12ms                    │
│   queued (1)         │ ▾ Bash  vendor/bin/phpunit --filter Login             ✔ 4.1s  [output ▸]        │
│   docs               │ ▾ Edit  src/Auth/Login.php  (+3 −1)                   ✔                         │
│   idle · 2h ago      │    │ 41   - if ($t > $now) {                                                    │
│ ─ BACKGROUND ─────── │    │ 41   + if ($t >= $now) {                                                   │
│ ▶ bg:lint-sweep  45% │ ┌ PERMISSION ─────────────────────────────────────────────────────────────┐   │
│ ✔ bg:changelog       │ │ Bash wants to run:  git push origin fix/login                           │   │
│ ─ AGENTS ─────────── │ │ reason: default mode asks for write-capable tools                        │   │
│ └ reviewer (Task) ◌  │ │ [Allow once] [Always (this session)] [Reject…] [Reject & stop]          │   │
│                      │ └─────────────────────────────────────────────────────────────────────────┘   │
│                      │ assistant ▸ The flake is an off-by-one in the expiry check…▌                    │
│                      ├────────────────────────────────────────────────────────────────────────────────┤
│                      │ ┌──────────────────────────────────────────────────────────────┐ [Send ▾]      │
│                      │ │ also add a regression test                                   │  queue        │
│                      │ └──────────────────────────────────────────────────────────────┘  steer        │
│                      │ ~41k tok 63% ▕██████▏  $0.41 / $5.00   step 4/8   [■ Stop] [Esc Esc]  interrupt│
└──────────────────────┴────────────────────────────────────────────────────────────────────────────────┘
```

**Grid (tiled) view.** Up to 2×2 by default, 3×3 max. Unfocused tiles subscribe in **narration** mode.

```
┌───────────────────────────────────────────┬───────────────────────────────────────────┐
│ fix-auth ● busy  step 4/8  $0.41   [⤢]    │ refactor-db ◌ queued(1)  $1.10    [⤢]     │
│ … flake is an off-by-one in the expiry…   │ ▾ Edit src/Db/Pool.php ✔                  │
│ ⚠ PERMISSION: Bash git push  [✔][✖][…]    │ … migrating the pool to lazy connect…     │
│ [ type to steer… ]                         │ [ type… ]                                 │
├───────────────────────────────────────────┼───────────────────────────────────────────┤
│ docs ○ idle                        [⤢]    │ ＋ open session / new session              │
│ last: "README updated with serve docs"    │                                           │
└───────────────────────────────────────────┴───────────────────────────────────────────┘
```

**Behaviours.**
- **Attention model** (kilo Agent Manager):
  - a session tile and sidebar row show `⚠ n` for pending asks;
  - the browser tab title shows `(2) SugarCrush`;
  - the Notifications API fires (opt-in) when an ask arrives for a non-foreground session, or a turn completes in the background;
  - a global "Approvals" drawer lists all pending asks across sessions with keyboard answers (`y`/`a`/`n`).
- **Composer:**
  - Enter = send; Shift+Enter = newline.
  - The `[Send ▾]` split button picks queue/steer/interrupt. While a turn runs, the default is **queue**, mirroring the TUI.
  - `/` opens command completion from `command.list` (only server-runnable commands).
  - `@` file mention is a future feature (it needs a `files.search`; the TUI also lacks it, Appendix A).
  - `Esc Esc` = stop.
- **Tool cards:**
  - collapsed by default when successful, mirroring the TUI;
  - header: tool name + model-written `description` + key argument + duration + ✔/✖/◌;
  - body: arguments (JSON), output (monospace, with "load more" via `tool.output` when `truncated`), diff view, inline images (`image.bytesB64` → `blob:` URL);
  - denied tools show `DenialKind` in a distinct style.
- **Reasoning:** `💭 Thought` fold with live text, as in the TUI.
- **Sub-agent tree:** `subagent.*` events build a tree under the Task tool card (name, task, live tail, usage, result). It is also in the sidebar "AGENTS" section.
- **Background tasks:** `bg.*` with progress, a log tail (`bg.output`), stop, and "inject result into session" (`bg.inject`).
- **Status bar:** tokens and context %, spend/cap, model/provider, permission mode (click → `session.setMode`, with bypass gated, §8.4), step k/N, connection state.
- **Settings view:** generated from `settings.schema`. Grouped, each field shows its **source tier** (user/project/default, from `settings.get`). Read-only fields are shown disabled with the reason ("user-tier only" / "not writable remotely").
- **Memory view:** list/search/add/edit/delete with scope tabs.
- **Session ops:** rename inline, fork (from a checkpoint menu), rewind, clear, export (markdown/json download via `session.export`), delete (confirm).
- **Command palette (⌘K):** sessions, commands, settings, theme. It mirrors `PaletteAction` categories.
- **Accessibility:** keyboard-first (j/k move between sessions, `g g` to top, `?` for shortcuts), ARIA live region for streaming text (polite), `prefers-reduced-motion`.
- **Mobile:** sidebar collapses to a drawer; the grid becomes a single column.

### 7.5 State management (Pinia) and the event reducer

- One `SugarCrushClient` per tab (one WS). `useSession(id)` lazily subscribes on mount and unsubscribes after a 30 s grace on unmount. LRU-cap loaded transcripts at 40 (opencode `SESSION_CACHE_LIMIT`).
- **Reducer rules:**
  - Durable events advance `cursor[sessionId]` only when gap-free.
  - `message.created`, `tool.started` and `tool.finished` upsert by id (idempotent on replay).
  - `assistant.delta` appends only when `offset === part.text.length`.
  - `assistant.completed` **replaces** the part text (repairs any lost delta).
  - `permission.resolved` removes from `approvals` and closes cards.
- **Batching.** Queue incoming frames and apply them once per animation frame (`requestAnimationFrame`, about 16 ms) inside a single store mutation. Consecutive deltas for the same part are concatenated first (opencode `FLUSH_FRAME_MS`).
- Draft text per session and layout are kept in `localStorage`, wrapped in try/catch: a per-viewer convenience only. **Never** store the token in `localStorage` (§8.2).

### 7.6 WebSocket client: reconnect, replay, liveness

```
connect():  ticket = POST /api/ws-ticket (cookie)  →  new WebSocket(`/ws?ticket=…`, ['sugarcrush.v1'])
onopen:     hello{resume: cursors}  →  for each open session: session.subscribe{afterSeq: cursor}
watchdog:   any frame resets timer; no frame for 2×tickIntervalMs (30 s) → close(4000) → reconnect
backoff:    0.5s, 1s, 2s, 4s, 8s, 15s cap, ±30% jitter; reset after 60 s stable
pending:    requests in flight at disconnect → rejected with 'disconnected'; side-effecting ones carry
            idempotencyKey and are retried once after reconnect (server dedupes)
visibility: on 'pagehide' close cleanly; on 'pageshow' reconnect (opencode)
version:    hello.server.version ≠ build version → banner "server updated — reload" (auto-reload if idle)
```

### 7.7 Auth flow in the browser

1. `sugarcrush serve` prints `http://127.0.0.1:7420/#login=<one-time code>`. The fragment never reaches server logs or `Referer`.
2. `LoginView` reads the fragment, `POST /api/login {code}` → `Set-Cookie: sc_session=…; HttpOnly; SameSite=Strict; Path=/; (Secure when https)`, then `history.replaceState` to a clean URL (the dsh pattern).
3. Every WS connect first gets a **single-use ticket** (`POST /api/ws-ticket`, cookie-authed, CSRF-protected by SameSite=Strict + Origin check + a custom header `X-SugarCrush: 1`). The ticket lives 30 s and is bound to the cookie session.
4. Non-browser clients (TUI attach, scripts) use `Authorization: Bearer <token>` on the upgrade, or `Sec-WebSocket-Protocol: sugarcrush.v1, sugarcrush.auth.<token>` (the cline trick) when headers are unavailable.

### 7.8 Testing

- **Unit (vitest + @vue/test-utils + happy-dom):**
  - reducer (replay idempotency, gap cursor, delta offsets, completed-replaces);
  - reconnect state machine (fake timers);
  - JSON-RPC client (id correlation, error kinds, idempotency retry);
  - components (ToolCard states, PermissionCard first-wins UI, DiffView).
  - Use `mock-socket` for WS.
- **Type safety:** `vue-tsc --noEmit`. Protocol types are **generated** from the PHP-side schema, so a server change that breaks the client fails typecheck (`npm run gen:protocol && git diff --exit-code src-web/protocol/generated.ts` in CI).
- **E2E (playwright):** boot a real `sugarcrush serve --port 0 --root <tmp git repo> --provider echo`. The offline `EchoProvider` is deterministic and has the full tool set (Appendix A). Scenarios:
  - login;
  - create session, send, see the echo;
  - two tabs on one session (both stream; first approval wins; the other's card closes);
  - kill and restart the server → client reconnects and resumes from its cursor;
  - permission flow under `--permission-mode default` (the echo provider must be able to emit a tool call: add an `EchoProvider` "scripted tool call" mode for tests, e.g. a prompt `!tool Bash {"command":"git status"}`);
  - grid view narration;
  - settings form round-trip.
  - Chromium only in CI, plus Firefox nightly or weekly.
- **PHP side (`sugar-crush/tests/Server/*`, `tests/Host/*`):**
  - a protocol conformance suite using a small PHP WS client (`ratchet/pawl` as require-dev, or a 150-LOC test client on rfc6455);
  - golden JSON for every event type;
  - backpressure (stalled reader → coalesce → overflow → 1013);
  - auth (no token, bad Origin, bad Host, expired ticket, replayed ticket);
  - fork-fd hygiene (turn child does not hold client fds; `/proc/<pid>/fd` assertion);
  - session-lock contention;
  - restart replay.
  - Everything under `LoopPin::pinStableClock()` via `sugar-crush/tests/bootstrap.php`.

### 7.9 How a non-PHP package fits the monorepo

| Concern | Plan |
|---|---|
| Lib discovery | `scripts/affected-libs.php::discover_libs()` picks dirs that have **both** `composer.json` and `phpunit.xml`. The PHP shim + `tests/AssetsTest.php` make `sugar-crush-web` a first-class lib, so it appears in the matrix, coverage, and the **splitsh sync**. `sync-sugarcraft.yml` derives its list from the same script |
| Node CI | New workflow `.github/workflows/web.yml` (paths: `sugar-crush-web/**`, `sugar-crush/docs/protocol/**`): `setup-node@v4` (node 22, npm cache) → `npm ci` → `lint` → `typecheck` → `test` → `build` → **`git diff --exit-code dist/`** (dist matches source) → `gen:protocol` diff check → playwright e2e (needs PHP 8.3 + `php tools/check-path-repos.php --fix --strict-closure` + `composer install` in `sugar-crush`, as ci.yml does). Add the workflow to `FORCE_ALL_FILES`? No: it is path-scoped and needs no force-all |
| Reproducible dist | Pin node and npm versions in `package.json` `engines` + `.nvmrc`. Vite builds are deterministic with content hashes. If the dist diff flakes across platforms, build in CI and have a bot commit, as `vhs: regenerate demo GIFs` commits do (`git log` shows that pattern) |
| `.gitignore` | Add `sugar-crush-web/node_modules/`, `sugar-crush-web/playwright-report/`, `sugar-crush-web/test-results/`. Do **not** ignore `dist/` |
| composer.json metadata | AGENTS.md block: PHP `^8.3`, PHPUnit `^10.5`, `minimum-stability: dev`, `prefer-stable: true`, keywords incl. `"sugarcraft"`, `"crush"`, `"web-ui"`, `"vue"`, homepage, single author Joe Huss role Maintainer, `support.{issues,source,docs}`. `"archive": {"exclude": ["/node_modules", "/src-web", "/e2e", "/package*.json"]}` keeps the Packagist dist small (dist + shim only) |
| Adding-a-lib checklist (AGENTS.md) | `composer.json`, `phpunit.xml`, `README.md`, `CALIBER_LEARNINGS.md`, `src/Assets.php` · root `composer.json` require + repositories · `docs/MATCHUPS.md` row (§7.1) · `PROJECT_NAMES.md` (naming exception) · root `README.md` · `docs/index.html` tile · `docs/_data/sugar-crush-web.{json,body.html}` → `php tools/gen-docs.php` · `media/icons/sugar-crush-web.png` · `codecov.yml` component (PHP shim only; JS coverage optional via a vitest lcov upload under flag `sugar-crush-web-js`) · `scripts/bootstrap-org-repos.sh` DESCRIPTIONS entry + Packagist registration |
| VHS | Exempt (not a terminal program). Use playwright screenshots in the README instead; they can be regenerated by CI as the GIFs are |
| `tools/check-one-type-per-file.php`, `check-path-repos.php` | Trivially satisfied (one class; no `repositories[]` in the lib manifest) |

---

## 8. Security

### 8.1 Threat model

- **The server can run arbitrary code as the user.** Bash, Edit and MCP are reachable through tool calls, so any party that can send `session.send` can execute code.
- Therefore **every** connection is authenticated, and loopback is **not** trusted by itself:
  - other local users can reach 127.0.0.1;
  - malicious web pages can reach it via DNS rebinding or cross-site WS.
- That matches OpenClaw ("loopback does not skip token auth") and rejects cline's local-Origin bypass.

### 8.2 Authentication

- **Owner token.** 32 random bytes (`random_bytes(32)`, hex), created at first `serve` in `~/.sugar-crush/server/token` (0600, private dir verified as in `ensurePrivateIpcDir()`). `SUGARCRUSH_SERVER_TOKEN` overrides it. `sugarcrush serve token --rotate` rotates it.
- **Login code.** A one-time, 120 s, single-use code printed in the URL fragment. It is exchanged for an **HttpOnly, SameSite=Strict** cookie session (server-side session table in memory; default lifetime 7 days, sliding). Codes are stored only as hashes (`hash('sha256')`) and compared with `hash_equals`.
- **WS upgrade** requires one of: a valid ticket (browser), `Authorization: Bearer`, or the `sugarcrush.auth.<token>` subprotocol. Tickets are single-use, 30 s, and bound to the cookie session id.
- **Rate limiting.** Auth failures are throttled per IP: 10/min, then 5-min lockout. Failures are logged without secrets.
- **Never** accept tokens in query strings for anything long-lived (logs, history, Referer). Tickets are the exception (short-lived, single-use), like opencode's PTY ticket.

### 8.3 Network exposure

- Default bind `127.0.0.1` (and `::1`).
- `--allow-remote` is required for any other address, and the server prints a warning box when it is used without TLS.
- **No built-in TLS in v1.** Recommend a reverse proxy (Caddy/nginx) or `tailscale serve`. Document an nginx snippet with `proxy_set_header Upgrade/Connection`, `proxy_read_timeout 3600s` (turns can run for many minutes; see the user rule on LLM timeouts, connect ≠ total) and `X-Forwarded-*`.
- `server.trustedProxies` (CIDRs) governs whether `X-Forwarded-For`/`-Proto` are honoured. That feeds the Secure cookie flag and per-IP rate limits.
- **`Host` header check** (DNS rebinding): accept only `127.0.0.1[:port]`, `localhost[:port]`, `[::1][:port]`, plus `server.allowedHosts`. Everything else → 421/403.
- **`Origin` check on every upgrade and every state-changing HTTP request** (CSWSH/CSRF):
  - Origin must equal the server's own origin or be in `server.allowedOrigins`.
  - **A missing Origin is allowed only for bearer/subprotocol-token auth** (non-browser clients), never for cookie auth.
  - Refuse `Sec-Fetch-Site: cross-site` on cookie-authenticated requests (dsh).
- CORS: none by default (same-origin app). `/api/health` is unauthenticated and returns only `{ok, protocol}`, never the version or root, to avoid fingerprinting.

### 8.4 Permission modes over the wire

- **Server default mode is `default`.** The CLI flag and env override still apply. The TUI's `bypass-permissions` default exists only because the TUI could not answer Asks (Appendix A), and the server can.
- **`bypass-permissions` and `dont-ask` cannot be set by a client** (`session.setMode`, `session.create {permissionMode}`) unless the server was started with `--allow-bypass` or `server.allowBypass: true` (user tier). Even then it requires the `admin` scope, and it emits a durable `notice` + `session.updated` audit record (`by: clientId`).
- `auto` (`SafetyClassifier`, a regex heuristic) is allowed but labelled "heuristic" in the UI.
- The `rm -rf /` circuit breaker, `ProtectFilesHook` and `ConfirmRemoveHook` keep running in every mode (they sit in the hook chain ahead of the gate; Appendix A).
- `trustedProject*` keys remain user-tier and frozen per process. **No method can modify them.** A project the server's user has not trusted gets no hooks, MCP, commands or settings, exactly as in the TUI.

### 8.5 Settings and command surface

- Remote `settings.set` is allowlisted (§6.10). `statusLine`, `instructions`, hooks, MCP config and trust keys are never writable remotely.
- `command.exec` runs custom commands, which may contain `` !`cmd` `` shell forms. Project-tier shell forms still require `trustedProjectCommands` (Appendix A), unchanged.
- `files.read` is root-jailed (`Tools\PathJail`), size-capped (1 MiB), and passes through `ProtectFilesHook`'s secret-file list (refuse `.env`, keys and the like).
- `bg.spawn` and `workflow.run` are equivalent to `session.send` (code execution): scope `write`.

### 8.6 Secrets in events

- **`settings.get` masks** any key marked `sensitive` in `SettingsSchema` (provider `apiKey`, `headers.*Authorization*`) as `"••••last4"`.
- **Provider configs** in `server.info` include names, types and models only, never URLs with credentials. Strip userinfo from `baseUrl`.
- **Tool output** is the model's view of the world and is shown as is (the user owns the box). Add an **optional** outbound `SecretRedactor` for well-known token shapes (`sk-…`, `AKIA…`, `ghp_…`, `xox[bp]-…`, PEM private-key blocks), off by default for the local owner and **on** for any token with only the `read` scope (a shared live view).
- **Logs** (`server.log`) record method names, session ids, sizes and timing, never params, prompts or tool output (opt-in debug flag aside).
- **Error details** returned to clients are clipped (≤300 chars) and sanitized, as OpenClaw does. Exception traces go to the log only.

### 8.7 Process hardening

- Refuse to run `serve` as root unless `--allow-root`.
- Set `umask(0o077)` at server start.
- Turn children drop inherited server fds (§4.4).
- Daemons have stdin `/dev/null` (as now).
- Enforce the request and frame size limits (§6.1/§6.9).
- Use a JSON depth limit (`json_decode` depth 64) and `JSON_THROW_ON_ERROR`.
- **Never** `unserialize()` client data. The fork protocol stays internal: parent↔child over a socketpair only, `allowed_classes => false`.

---

## 9. Implementation plan

### 9.1 Phases

| Phase | Scope | Effort | Ships value alone? |
|---|---|---|---|
| **0 — Spikes** (2-3 d) | (a) react/http + rfc6455 echo server on `Loop::get()` running alongside a live `EngineBackend::completeAsync()` turn; (b) measure fork cost with 30 hosts loaded; (c) prove a turn child can close inherited fds; (d) optional `ModelRuntime` spike for headless Chat event vocabulary | S | — |
| **1 — Bidirectional fork channel + TUI approvals** | `ask`/`ask_reply`/`steer`/`cancel_soft`/`usage`/`step` frames; `ChildChannel`, `PendingAsk`; idle-watchdog suspension; Chat inbox → `PermissionRequestMsg`; session-scoped `always` memo; per-step usage; steering in `runTurn` | **L** (1.5-2 wk) | **Yes.** Fixes Appendix A and #14 |
| **2 — Host extraction** | `Bootstrap::workspace()` → `WorkspaceContext`; `Host\{SessionHub, SessionHost, TurnController, TurnRunner, TranscriptProjector, TranscriptStore, SpendLedger, ContextMeter, CompactionService, TitleService}`; per-session `NoticeSink`; `Message::$id`; `session_events` table + `EventLog`; `SessionLock` reuse; logic slash commands → `Host\Commands\*`; Chat delegates to all of it | **L** (2-3 wk) | Partially (cleaner Chat) |
| **3 — Server + protocol** | `Server\{Server, Http\Router, Http\HostAndOriginGuard, Http\AuthMiddleware, Http\StaticFiles, Http\ApiController, Ws\Upgrade, Ws\Connection, Ws\Outbox, Auth\TokenStore, Auth\LoginCodes, Auth\Tickets}`; `Protocol\{Dispatcher, EventEnvelope, EventType, Methods\*, Schema\*, IdempotencyCache}`; `Cli\Subcommands::serve()` (foreground); env/settings/docs; `docs/SERVER.md` + generated schema + drift test | **L** (2 wk) | Yes (scriptable server) |
| **4 — Daemon + background agents** | `Support\Daemonize` (moved from `BackgroundSupervisor`), `server.json`/lock/log, `serve status\|stop\|logs\|url\|token`, graceful drain, parent-pid watchdog, `BackgroundSupervisor::reconnect()` wired at boot, `bg.*` methods/events, `bg.inject` | M (4-6 d) | Yes |
| **5 — Web MVP** | `sugar-crush-web` scaffold, composer shim, client/reconnect/cursor, sessions sidebar, single-pane chat, markdown/code, tool cards + diffs, approvals, composer (queue/steer/stop), status bar, login, served by `serve`; CI `web.yml`; monorepo checklist | **L** (2 wk) | **Yes (the user's ask)** |
| **6 — Multi-session polish** | tabs + tiled grid + narration mode, cross-session approvals drawer + notifications, sub-agent tree (incl. the grandchild relay from §5.1), background tasks panel, workflows panel, `SettingsSchema`-driven settings form, memory panel, command palette, export, mobile layout | L (2 wk) | Yes |
| **7 — Multi-root workspace hosts** | gateway ↔ `serve --workspace-host` over UNIX sockets; root picker in UI; idle recycle | M-L (1 wk) | Yes |
| **8 — TUI-as-client + ACP** | `sugarcrush attach`, `Backend\RemoteBackend`, remote host proxy, lease handoff; `sugarcrush acp` stdio adapter (+ `ToolResult` old/new text for ACP diffs) | M-L (1-1.5 wk) | Yes |

### 9.2 Files to add or modify (sugar-crush)

**Modify:**
- `src/Backend/EngineBackend.php`
  - Frames: parent write path; `ask`/`steer`/`usage`/`step` kinds in `drainFrames`/`decodeEvent`.
  - `completeAsync()`: approval suspension of `$resetTimeout`; `PendingAsk` handle.
  - `runCompleteInChild()`: approver + `ChildChannel`; `closeInheritedServerFds()`.
  - `runTurn()`: steer intake per step + per-step usage emit.
  - Keep `completeAsyncBlocking()` (no pcntl) working: approvals there call the approver synchronously, so the server must refuse to start without pcntl (`serve` requires `ext-pcntl` + `ext-posix`; check in `doctor`).
- `src/Runtime.php`: nothing structural (approver contract unchanged). Optional: emit `step`.
- `src/Chat.php`: delegate to `Host\*` (phase 2); handle `PermissionAsked` in the pump (phase 1). Remove nothing: dormant `registerTool`/`onToolCall`/`executeAgents` stay (the house rule).
- `src/Cli/Bootstrap.php`: `workspace()`; `openSession()` session-lock check; `backendFor()` threads AgentManager in every path.
- `src/Cli/{ArgvParser,ParsedArgs,Subcommands,Help}.php`: the `serve`, `attach` and `acp` verbs, completion, help.
- `src/Session/EnhancedSessionStore.php`: `session_events` table + API.
- `src/Message.php`: `?string $id`.
- `src/Diagnostics/RuntimeNoticeSink.php`: instance-capable `NoticeSink` + static facade.
- `src/Sessions/BackgroundSupervisor.php`: move the daemonize code to `Support\Daemonize`; a listener → host events.
- `src/Config/LayeredSettings.php`: `server.*` keys.
- `src/Providers/EchoProvider.php`: scripted tool-call mode for e2e.
- `composer.json`: require `react/http ^1.11`, `react/socket ^1.16`, `ratchet/rfc6455 ^0.4`, `ext-pcntl`/`ext-posix` → `suggest` or a runtime check; suggest `sugarcraft/sugar-crush-web`; require-dev `ratchet/pawl` (test WS client).
- Docs: `README.md` (subcommand fence, Limitations), `docs/ENVIRONMENT.md`, `docs/SETTINGS.md`, `docs/PERMISSIONS.md`, `docs/ARCHITECTURE.md`, **new** `docs/SERVER.md`, `docs/protocol/sugarcrush.v1.schema.json`.

**Add:**
- `src/Host/*` (≈14 classes, phase 2)
- `src/Server/**` (≈16 classes)
- `src/Protocol/**` (≈10 + one per method group)
- `src/Backend/{ChildChannel,PendingAsk,RemoteBackend}.php`
- `src/Events/{PermissionAsked,PermissionResolved,StepStarted,UsageUpdated,Steered}.php`
- `src/Support/Daemonize.php`
- `src/Cli/{Serve,Attach,Acp}.php`
- `src/Acp/*` (phase 8)
- tools: a protocol schema generator (`--check`)

All one-type-per-file (`tools/check-one-type-per-file.php`).

**Tests (PHP):**
- `tests/Backend/ForkChannel*Test.php`: ask round-trip, reply-after-cancel, idle timer suspended, steer at step boundary, usage frames, EOF → unanswered.
- `tests/Host/*`: TurnController parity with Chat behaviours. Port the existing Chat tests' assertions, not the tests themselves.
- `tests/Server/*`: conformance, auth, origin, host, backpressure, fd hygiene, replay.
- `tests/Protocol/*`: golden JSON, schema check.
- Drift tests: `ServerProtocolDocumentationDriftTest`, a `SettingsSchema` remote-coverage drift test.
- **Every new test file → `scripts/parallel-tests-durations.tsv`** and a refreshed `suite-figure.json`.
- Under `candy-pty`-style hang risk (forks + sockets), use the `HangWatchdog` pattern for the server tests that fork, and the memory-noted backgrounded pkill watchdog for local runs (`timeout` doesn't stop PTY/FFI hangs).

### 9.3 Effort summary

| Size | Phases |
|---|---|
| S | Phase 0 |
| M | Phase 4; Phase 7 (M-L); Phase 8 (M-L) |
| L | Phases 1, 2, 3, 5, 6 |

Calendar estimate for one engineer: **≈ 7-9 weeks** to Phase 6, **≈ 9-11 weeks** through Phase 8. Phases 1→2→3 are sequential. Phase 5 can start against a Phase 3 stub server with mocked events once §6 is frozen.

### 9.4 Risks and mitigations

| Risk | Detail | Mitigation |
|---|---|---|
| **pcntl + event loop** | A fork happens with the loop's fds (the uv backend fd, listeners, client sockets) registered. The child must not touch the loop. `ForkedChild::exitNow` already avoids destructors and shutdown. ext-uv: a forked child sharing the uv loop fd can corrupt it if it calls into the loop | The child never calls `Loop::*`. It closes inherited server fds first. Run the conformance suite under both `StreamSelectLoop` and ext-uv (the `LoopPin` note in CLAUDE.md). Keep `ParentProcessGuard` |
| **Long-running PHP memory** | Hosts accumulate transcripts; Guzzle and provider objects; event-log caches; closures holding `Chat` graphs; static arrays (`Bootstrap::$launchNotices` etc.) | Bounded caches (LRU hosts, capped notice queues); idle-host eviction; `memory_get_usage()` in `server.health`; workspace-host recycling (phase 7) after N turns or M MiB; a soak test (100 turns, assert RSS slope) |
| **Fork cost with a fat parent** | COW pages touched by `serialize()` in the child | Keep hosts lean. The child gets the history only. Measure in Phase 0; if needed, spawn turn children from a slim "turn zygote" process (fork from a small helper rather than the gateway) |
| **Concurrency on shared state** | Process-global statics (`RuntimeNoticeSink`, `Bootstrap`) | Per-session sinks (phase 2), single root per process (D6) |
| **Two writers on one session** | TUI + server, or two servers | `SessionLock` (§4.8); the singleton lock per state dir |
| **Ask waits forever** | A turn child parked indefinitely holds a process and memory | `server.askTimeoutSeconds` option; a dashboard "waiting since"; `server.health` lists parked turns; `session.cancel` |
| **Slow or malicious clients** | Memory blow-up in outboxes | Watermarks + 1013 close (§6.9); frame and request limits |
| **Protocol drift web↔server** | Two languages | Generated schema + generated TS types + CI diff checks + version-locked UI |
| **Drift-test friction** | The repo pins docs heavily | Plan the doc edits per PR (§4.7); generators over hand-written tables |
| **Bypass default habits** | Users used to the TUI's bypass are surprised by asks in the web UI | Clear first-run banner; one-click "Always (session)"; `--permission-mode` override documented |
| **`completeAsyncBlocking` fallback** | No pcntl means the turn blocks the loop and stalls every session | `serve` refuses to start without pcntl/posix (clear message + `doctor` check) |
| **Windows** | No pcntl/posix | Server unsupported on Windows v1 (document it). WSL works |

### 9.5 Suggested PR bundling (ship-as-you-go, 2-4 items per PR)

1. `sugar-crush: bidirectional fork channel (ask/ask_reply) + idle-timer suspension + per-step usage frames`
2. `sugar-crush: TUI engine-path approvals via Veil modal + session always-memo + steering`
3. `sugar-crush: Message ids + session_events table + per-session NoticeSink`
4. `sugar-crush: Host\SessionHost/TurnController extraction (Chat delegates)` (may need 2 PRs)
5. `sugar-crush: serve (foreground) — HTTP/WS transport, auth, protocol v1 core methods + docs/SERVER.md + drift test`
6. `sugar-crush: serve --detach/status/stop + BackgroundSupervisor::reconnect wiring + bg.* events`
7. `sugar-crush-web: scaffold + client + single-session chat + approvals; serve integration; web.yml`
8. `sugar-crush-web: multi-session grid, narration, approvals drawer, settings schema form`
9. `sugar-crush: workspace hosts (multi-root)`; 10. `sugar-crush: attach + acp`

---

## 10. Open question and checks owed

**Still open (Part V decision 7):** is non-loopback via a reverse proxy enough for v1, or is built-in TLS wanted?

**Checks owed before implementation (O-0 spikes):**

1. **Pin dependency versions.** Verify `ratchet/rfc6455` latest (claimed `^0.4`) and `react/http`/`react/socket` versions with `composer show -a ratchet/rfc6455 react/http react/socket` in a scratch dir. Confirm react/http's 101-upgrade-with-duplex-body pattern against current `react/http` source: grep `101` / `Upgrade` in `vendor/react/http/src/Io/StreamingServer.php`.
2. **Audit provider static caches** (§4.4 says "not audited"): `grep -rn 'static \$' sugar-crush/src/Providers`.
3. **Check headless setup.** Confirm whether candy-core `Program::setupTerminal()` honours `withoutRenderer`/`openTty=false` cleanly (relevant only to the Phase 0 `ModelRuntime` spike): `candy-core/src/Program.php` `setupTerminal`.
4. **Check sequential Task.** Verify that a single (non-batched) Task call runs in the turn child rather than a grandchild (affects §5.1's "single Task can use the back-channel"): `Runtime::executeToolCalls`, segment-of-one handling.
