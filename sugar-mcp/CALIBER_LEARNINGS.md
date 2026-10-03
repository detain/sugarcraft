# CALIBER_LEARNINGS — sugar-mcp

## Patterns

- **`result` must be `mixed` + a `resultSet` sentinel.** MCP tools legitimately
  return `null`/`false`/`0`/`""`. Narrowing `result` to `?array` made a scalar
  reply a TypeError at envelope construction, and TypeError escaped the
  RuntimeException catch — one bad server killed every registered server.
  `array_key_exists('result', $wire)` is the "success vs error" discriminator.
- **`isset()` and not `array_key_exists()` in the tool well-typedness gate.**
  fromArray() reads fields with `??`, so absent and explicit-null are the same
  population; the gate must not reject `{"name":"write","description":null}`
  (real-world traffic) while still rejecting wrong types.
- **proc_open in argv-ARRAY form, never a shell string.** With a shell the MCP
  server is a grandchild; stop() signals /bin/sh and the server survives
  orphaned (measured in the product).
- **Close pipes BEFORE signalling in stop().** On stdin EOF a well-behaved
  server exits on its own: measured 1.05s → 0.010s and exit status 9 → 0.
- **Teardown via candy-core `Util\Proc\BoundedShutdown`** — TERM → poll → KILL →
  poll → proc_close with exit-code harvest from the status poll (proc_close
  reports -1 after a reaped exit). `groupId()` must be computed while the child
  is alive. Signals are integer literals (15/9), never pcntl constants.
- **One wall-clock deadline across the whole handshake.** Per-read timeouts are
  starvable by a server that streams notifications forever (measured rc=124);
  one deadline checked at each leg bounds the exchange as a unit.
- **stderr is absorbed on both wait sets, tail-kept at 64KiB.** The kernel pipe
  holds ~64KiB; a server whose stderr nobody reads blocks on its own write
  before answering on stdout — a deadlock that looks like an unresponsive
  server. At EOF, mark the pipe closed for this instance: an EOF pipe reads as
  permanently ready and would spin every later wait.
- **stream_select failure is not fatal.** EINTR returns false; a live child
  gets bounded retries, and a counter (10000, ~2800 storm fails/s measured)
  caps signal-storm livelock.
- **fwrite() === false is the dead-child detector on the write pipe;
  feof() on a write pipe is NOT** (measured). A 0-byte write only means the
  pipe is momentarily full.
- **Closed resources must never enter a stream_select set** — ValueError that
  no `@` can silence. The is_resource guards in readLine are load-bearing
  control flow.
- **Oversized-frame refusal DROPS the buffer, never truncates.** Half a frame
  parses as a malformed message and blames the server for this side's cap.
- **stderr diagnostics are read BEFORE stop()** — stop() clears the capture.
- **Deny-before-allow ordering in the router** makes the operator's deny list
  the hard boundary a preset's explicit allow cannot out-vote. Empty allow-list
  = allow-all (distinct from restrict-to-nothing); blank/non-string allow-list
  entries throw — refuse rather than read a rule that matches nothing.
- **Deny specs carry two legal spellings** — `pattern => 'deny'` (the
  sugar-crush config shape, `McpClient::setDenyPatterns(array<string,string>)`)
  and `pattern => ['action' => 'deny']` — normalised once in the constructor,
  unrecognised shapes throw THERE. Reading a bare string as a spec array made
  every incoming deny rule inert (`'deny'['action'] ?? ''` is `''`); an inert
  deny is a WIDENED boundary, the same failure the allow side refuses loudly.
- **`__destruct` → stop()** is the library-level orphan guarantee; sugar-crush
  had process-lifetime as the implicit backstop, a library cannot assume it.

## Boundaries vs. the product (sugar-crush)

- ProcessContainment (setsid-wrapped detached spawn, PATH pre-check, curated
  env) stays downstream — product policy about WHO may be spawned, not the
  wire protocol. Library default: plain argv spawn, `env === []` inherits,
  otherwise `array_merge(getenv(), $overrides)`. Embedders that need the wrap
  inject it through the `$spawnPlanner` ctor seam — a closure
  `($name, $argv, $env) → [command, env|null]` whose return is handed to
  proc_open verbatim after shape validation, and which may throw to refuse a
  launch before any child exists (that is where crush's PATH pre-check lives).
- `$clientInfo` is the other ctor seam: the initialize-handshake identity,
  parsed fail-fast at construction (non-empty string `name` + string
  `version`); default advertises sugar-mcp, sugar-crush's adapter sends its
  own product identity through it.
- Router takes a plain `list<string>` allow-list; sugar-crush's AgentPreset
  extraction (`$preset->mcpServers`) stays downstream.
- MAX_FRAME_BYTES restates the product family's 64MiB cap as a library-local
  constant (pinned != MAX_STDERR_BYTES to catch a collapse edit).
- pumpStderr() is a caller-pumped seam, never loop-mounted (E537: pipe reads
  are destructive — two readers on one pipe steal each other's bytes).
- HTTP transport, server side, and the OAuth stack are Phase 2.

## Failure-path rules (crush_libs.md audit, 2026-10-03)

- **Never `(string) json_encode(...)` on the wire.** `false` becomes a blank
  line every server ignores, and a deadline-less `callTool()` then waits for a
  reply forever. `toJson()` uses `JSON_THROW_ON_ERROR` and rethrows
  `InvalidArgumentException`; `callTool()` converts it to `{"error": ...}`.
- **Every handshake leg gets the same gate.** `tools/list` error / no reply
  is a start failure, exactly like `initialize` — "up, 0 tools" is the worst
  failure shape on a long-running TUI.
- **Pipe EOF is not a liveness signal.** A helper forked by the server keeps
  the stdout write end open; `readLine()` asks `serverIsRunning()` whenever a
  poll leaves stdout idle — NOT only when the whole select times out, because
  a helper that also inherited stderr and logs faster than a poll never lets
  it time out. The stderr-only pass is rate-limited to one probe per
  READ_POLL_SECONDS of stdout silence. `writeLine()` carries the same check
  (a helper holding stdin keeps a full pipe from ever breaking), and a dead
  child's verdict first drains what stdout already holds (zero-timeout), so a
  reply written just before exit is not dropped. Pinned in both a silent and a
  `--chatty` fixture shape.
- **Signal 0 says a zombie is alive.** From a `pcntl_fork()`ed caller the
  liveness probe is `posix_kill($pid, 0)`, and a server the owner has not
  reaped yet (sugar-crush's parent sits in `waitpid()` on the turn, never in
  `proc_get_status()`) is a zombie that passes it. The non-owner branch reads
  `/proc/<pid>/stat` too: state `Z`/`X` is dead, and a start time other than
  the one recorded at `start()` is a reused pid, also dead. Owner-only tests
  cannot see this; `testAForkedCallerTreatsAnUnreapedDeadServerAsDead` forks
  the caller and keeps the owner in a pid-specific `waitpid()`.
- **Lock state writes are checked.** `store()`/`markPhase()` return bool, an
  unrecorded marker fails the exchange, and an empty lock file loads as
  `PHASE_READING` (dirty), never clean.
