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
