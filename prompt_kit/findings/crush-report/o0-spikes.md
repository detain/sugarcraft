# O-0 spikes: server mode on the shared loop

Appendix O §9.1 phase 0 and the §10 checks owed. Every figure below was measured on 2026-10-03, on PHP 8.3.6 NTS (pcntl, posix, ffi, ext-uv), Linux 6.8 and 48 cores, against sugar-crush at `fix/w1-j` (= master `6796704e3`). The spike scripts were throwaway: a scratch Composer project loads sugar-crush's `vendor/autoload.php` first, then its own. Nothing here is committed code.

## Verdicts

| Spike | Verdict | Feeds |
|---|---|---|
| (a) react/http + rfc6455 echo server beside a live `completeAsync()` turn | **Works on both loops.** No dropped frames and no stall attributable to the server. | O-3a |
| (b) Fork cost with 30 loaded hosts | **Acceptable.** `pcntl_fork()` cost grows with parent RSS (page tables). Child COW work does not grow with idle hosts. No turn zygote is needed at these sizes. | O-2*, O-3a |
| (c) Turn child and inherited server fds | **Real hazard, fixable.** A child holding the listener keeps the port bound after the server closes it, and it accepts connections into a backlog nobody reads. Closing them in the child with libc `close(2)` through FFI fixes both. | O-3a (`ForkedChild::closeInheritedServerFds()`) |
| (d) candy-core `Program` as a headless model runtime | **Not usable as is, and not adopted in W1.** `run()` owns the loop, and one program's quit stops the shared loop for every session and the server. | Recorded below. No candy-core change this wave. |

## Dependency pins (check 1)

- Latest versions are `ratchet/rfc6455` v0.4.1 (`^0.4` confirmed), `react/http` v1.11.1, `react/socket` v1.17.0 and `ratchet/pawl` v0.4.3 (test client). `ratchet/rfc6455` needs `psr/http-factory-implementation`, which the `guzzlehttp/psr7` 2.13.1 already installed satisfies (`GuzzleHttp\Psr7\HttpFactory`).
- **`react/http` (1.11.1, 1.x-dev and 3.x-dev) requires `psr/http-message ^1.0`.** sugar-crush and the root lock resolve `psr/http-message` **2.0** today.
  - A dry-run `composer update` of sugar-crush's manifest plus `react/http ^1.11`, `react/socket ^1.16`, `ratchet/rfc6455 ^0.4` and `ratchet/pawl ^0.4` (dev) **resolves**, but by downgrading `psr/http-message` 2.0 → 1.1.
  - Every current consumer accepts `^1.1`: aws-sdk, google/auth, guzzle psr7, openai-php, psr/http-client and psr/http-factory.
  - No SugarCraft lib *implements* a PSR-7 interface. sugar-crush only type-hints `ResponseInterface` / `StreamInterface` / `RequestInterface` (`MCP/HttpMcpServer.php`, `Providers/SglangServerInfo.php`, `Providers/Concerns/HttpClientDefaults.php`), and those are signature-compatible across 1.1 and 2.0.
  - The O-3a / O-5a `composer.json` commit will therefore show the downgrade in the root lock. That is expected, not a regression.
- **The upgrade pattern is confirmed** in `react/http` `src/Io/StreamingServer.php`:
  - a 101 response whose body is an `HttpBodyStream` over a `WritableStreamInterface` gets `Connection: upgrade`;
  - the connection is then piped into the body's input.
  - So the handler returns `new React\Http\Message\Response(101, $negotiatorHeaders, new CompositeStream($toClient, $fromClient))`.
  - `ServerNegotiator::handshake()` returns a Guzzle response; copy its headers. Do not return it directly.
  - The incoming `ThroughStream` feeds `MessageBuffer::onData()`, and `MessageBuffer`'s `$sender` writes to the outgoing stream. With `expectMask: true`, the close and ping control frames are answered in `$onControl`.

## (a) Echo server beside a live turn

**Setup:**
- An `HttpServer` with the 101 glue above, on `SocketServer('127.0.0.1:0')`, on `Loop::get()`.
- A `ratchet/pawl` client in the same process sends a timestamp every 50 ms, and the server echoes it.
- In parallel, `EngineBackend::new(ScriptedProvider[Bash "sleep 2" → "done"])->completeAsync()` forks a real turn child, which runs a real Bash tool.
- A 10 ms periodic probe records the largest loop gap.

| Loop | Turn result / wall | Echoes (sent = echoed) | RTT p50 / max | Max loop gap |
|---|---|---|---|---|
| StreamSelectLoop (3 runs) | `done` / 2.06–2.14 s | 47–48 / 47–48 | 0.51–0.54 ms / 1.1–45 ms | 18–46 ms |
| ExtUvLoop (3 runs) | `done` / 2.07–2.10 s | 47 / 47 | 0.57–0.59 ms / 1.2–1.5 ms | 16–19 ms |

- **The worst gap is the parent's synchronous half of `completeAsync()`.** That is the fork plus setup (see (b): about 13 ms in a bare process). It is not the server: (b) measures the same gap with no server at all.
- The single 45 ms RTT outlier on StreamSelect was a first-run warm-up and did not repeat.
- **Nothing needed bridging.** Turn sockets, the 120 s idle timer, cancel pollers and WS connections share one loop, under both backends. The `LoopPin::pinStableClock()` note still applies to tests.

## (b) Fork cost with N hosts

Each "host" is a transcript of `Message` objects held in the parent. Per configuration there were 20 raw `pcntl_fork()` calls (child SIGKILLs itself) and 20 `completeAsync()` turns on a 21-message history (ScriptedProvider `ok`). Child minor faults come from `getrusage(RUSAGE_CHILDREN)` deltas.

| Hosts × msgs × bytes | Parent RSS | `pcntl_fork()` p50 / max | Turn p50 / max | Parent loop gap max | Child minor faults / turn |
|---|---|---|---|---|---|
| 0 | 59 MB | 2.3 / 3.9 ms | 53.8 / 77.9 ms | 13.1 ms | 2,100 (~8 MB) |
| 30 × 200 × 2 KB | 75 MB | 2.8 / 4.0 ms | 53.8 / 67.7 ms | 13.7 ms | 2,055 |
| 30 × 1000 × 4 KB | 304 MB | 6.8 / 8.8 ms | 65.4 / 94.1 ms | 21.1 ms | 2,062 |

- **Fork cost is page-table copy.** It is about 1.5 ms per 100 MB of parent RSS. It stays synchronous in the gateway, so it adds directly to every session's loop latency.
- **COW is flat.** The turn child touches about 8 MB whatever the parent holds, because `ForkedChild::exitNow()` skips destructors and GC. A child that triggered a GC run *would* walk every host. Keep `exitNow` and do not call `gc_collect_cycles()` in the child.
- **Recommendation:**
  - No turn zygote for v1.
  - Bound host memory instead (§9.4 LRU and idle eviction) and report `memory_get_usage()` in `server.health`.
  - Revisit the zygote if parent RSS routinely exceeds ~1 GB, where fork would cost ~20 ms per turn.

## (c) Inherited server descriptors

**Census.** During spike (a), while the turn child sat in Bash `sleep 2`, its `/proc/<pid>/fd` held:
- StreamSelect: the **listener**, the **accepted WS connection**, the in-process WS client's socket and the frame socket;
- ext-uv: the same four, plus the loop's `eventpoll`, `eventfd`, two `io_uring` and four `pipe` descriptors (this ext-uv build uses io_uring).

`proc_open()` children inherit the listeners too: PHP sets no `SOCK_CLOEXEC` on `stream_socket_server` sockets.

**Hazard test.** Fork a child that holds everything for 3 s. The parent then closes the accepted connection and the listener.

| Child | Peer EOF after parent `Connection::close()` | Re-bind the port after `SocketServer::close()` | New client connects to the closed server |
|---|---|---|---|
| keeps fds (today) | 0.3–0.4 ms (react sends `shutdown(SHUT_RDWR)`, so EOF is not delayed) | **fails: Address already in use** | **yes: lands in a backlog nobody accepts (client hangs)** |
| closes fds first | 0.4–0.7 ms | ok | refused |

Results are identical under StreamSelect and ext-uv.

**What the hazard means:**
- **Client disconnects are safe.** React's `Connection::handleClose()` calls `stream_socket_shutdown`.
- **Listeners are not safe.** `serve stop`/restart during a long turn, or `serve` restarting on the same port, fails or black-holes clients until every turn child (and every tool it exec'd) exits.

**The fix that worked.** In the child, before anything else:
1. Close each inherited server descriptor with `FFI::cdef('int close(int fd);')->close($fd)`. PHP has no fd-number close, and `php://fd/N` dups.
2. Find the fd numbers by dev+ino match, the same technique as `ProcessContainment::descriptorNumber()`.

**Design for O-3a:**
- **Close registered server fds only, not a blanket sweep.**
  - The turn child legitimately uses inherited **pipes** (stdio MCP servers started in the parent) and possibly Guzzle/curl sockets.
  - A blanket close of `socket:`/`pipe:` would break both.
  - The server registers its listener and each accepted connection, and `ForkedChild::closeInheritedServerFds()` closes exactly those.
- Closing the ext-uv `eventpoll`/`eventfd`/`io_uring` descriptors in the child is safe, because the child never touches the loop. It is optional. The child must never call `Loop::*`, which is already true.
- Also mark the listener `FD_CLOEXEC` (`ProcessContainment::closeOnExec()`) at bind time, so exec'd tool processes never inherit it, even from the gateway.
- **No FFI fallback.** If `ffi.enable` is off, `serve` should refuse to start or warn, as it does for missing pcntl. Otherwise the listener leak returns silently.
- Test (`tests/Server/ForkFdHygieneTest.php`): fork a child holding a registered listener, close the server, and assert the port re-binds and a late connect is refused.

## (d) Headless `Program`

**Setup.** A trivial model whose `init()` is `Cmd::tick(t, QuitMsg)`. It runs under `ProgramOptions(withoutRenderer: true, withoutSignalHandler: true, input: socketpair, output: php://memory, windowSize, environment)`, with a 50 ms server heartbeat on the same loop.

**Findings:**
- **The program still writes terminal escapes.** With default options, `setupTerminal()`/`teardownTerminal()` write `ESC[?25l ESC[?2027h ESC[?2027l ESC[?25h` even with `withoutRenderer`. Only `hideCursor: false` and `unicodeMode: false` silence them. `Tty::enableRawMode()` also runs on whatever input it is given.
- **`run()` owns the loop.** It calls `$loop->run()`, so a second session's `run()` from a loop callback nests `run()`.
- **One session's quit stops the whole server.** `Program::dispatch(QuitMsg)` calls `$loop->stop()` on the **shared** loop. Measured with program A (quit at 0.3 s) and program B (quit at 0.6 s, started nested at 0.1 s):
  - at 311 ms, B's `run()`, A's `run()` **and the outer server `Loop::run()`** all returned;
  - B was killed 290 ms early, and the server stopped.

**Recommendation:**
- **Do not adopt in W1.** Keep the strangler `src/Host/` extraction (§4.2) as the target. It does not need candy-core.
- If a later wave wants the event-vocabulary harness, the candy-core addition is `Program::start(): void` + `Program::stop(): void`:
  - `start` runs init, dispatches the initial size/env/profile and reconciles subscriptions, but never calls `$loop->run()`;
  - `stop` cancels this program's timers and streams.
  - Under `start()`, `QuitMsg`/`InterruptMsg` must stop *the program* (cancel its own timers and read stream), never `$loop->stop()`.
  - Terminal setup and teardown must be skipped entirely when `withoutRenderer` is set and no tty is open.
- That is a cross-lib candy-core PR with its own tests. It is not a sugar-crush-only change.

## Other checks owed (§10)

- **2. Provider static caches.** `src/Providers/` declares **no static properties** (only static methods: `TransientFailure`, `SseData`, `SglangServerInfo`, `ClaudeCodeProvider` helpers). Concurrent sessions in one gateway share no provider state through statics. The process-global state to isolate is `Bootstrap`'s statics and `RuntimeNoticeSink` (§4.4, O-2a).
- **3. Headless terminal setup.** Answered by (d): `setupTerminal()` is not clean headless. It honours `withoutRenderer` only for frames, not for mode escapes or raw mode.
- **4. Sequential Task.** A single Task call runs **in the turn child**. `Runtime::executeToolCalls` splits calls with `segments()`, a segment of one goes to `executeSequentially()`, and `TaskTool` calls `completeTranscript()` synchronously with no fork of its own. Only a batch of ≥2 parallel-safe calls reaches `executeConcurrently()` and forks grandchildren. So §5.1's claim holds: a single Task can use the turn child's back-channel directly, and only batched Tasks need the grandchild relay.
