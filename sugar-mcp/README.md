# Sugar MCP

MCP (Model Context Protocol) client core for PHP: a JSON-RPC 2.0 envelope
codec, a newline-delimited stdio transport with a fully bounded lifecycle,
the initialize/tools protocol layer, and policy-free allow/deny routing.

Extracted from the [sugar-crush](https://github.com/sugarcraft/sugar-crush)
MCP stack, where these classes run a ~4.9k-LOC production subsystem. The wire
algorithms are faithful ports; everything product-specific stayed downstream.

## Install

```sh
composer require sugarcraft/sugar-mcp
```

## What ships (Phase 1)

| Class | Role |
|---|---|
| `McpMessage` | Immutable JSON-RPC 2.0 envelope — request / notification / success / error — with a null-preserving `result` sentinel |
| `McpTool` | Tool definition value object with a well-typedness gate (`tryFromArray`) |
| `ArgumentShape` | Restores `{}` vs `[]` in decoded tool-call arguments against the tool's `inputSchema` |
| `McpServer` | Transport contract: `start` / `stop` / `listTools` / `callTool` |
| `StdioMcpServer` | Child-process stdio transport: argv-array spawn, NDJSON framing, one-clock handshake deadline, tail-bounded stderr capture, `BoundedShutdown` teardown, `__destruct` orphan-proofing |
| `McpRouter` | Deny-before-allow tool/server narrowing over raw config keys; empty allow-list means allow-all |
| `RequestIdSequence` | JSON-RPC ids unique across `pcntl_fork()`: plain counters in the owner, `<pid>-<nonce>-<n>` elsewhere |
| `ExchangeLock` | Cross-process `flock` for one connection, carrying the shared read buffer and the dead-holder phase marker |

## Quick start

```php
use SugarCraft\Mcp\StdioMcpServer;

$server = new StdioMcpServer(
    name: 'everything',
    command: 'npx',
    args: ['-y', '@modelcontextprotocol/server-everything'],
);

$server->start();                    // initialize → notifications/initialized → tools/list
foreach ($server->listTools() as $tool) {
    echo $tool->name, ': ', $tool->description, PHP_EOL;
}

$result = $server->callTool('echo', ['message' => 'hi']);
$server->stop();                     // also runs automatically on destruct
```

## Timeout discipline (E646)

- The handshake runs under **one** monotonic (`hrtime`) deadline
  (`DEFAULT_START_TIMEOUT_SECONDS`, 60s, sized for cold `npx` fetches) shared
  across `initialize`, `notifications/initialized` and `tools/list`.
- Every handshake leg must succeed, or `start()` stops the child and throws a
  `RuntimeException` (with any captured stderr) rather than reporting "up, 0
  tools": an `initialize` or `tools/list` reply carrying `error` names the
  server's error code and message; a leg that gets no reply, or a
  `notifications/initialized` that cannot be delivered, names that leg.
- `callTool()` carries **no** deadline by default: a tool call is somebody's real work and
  is bounded by child liveness, never by a wall-clock kill of in-flight work.
  Liveness is checked whenever a poll leaves the pipe idle — at most once a
  second, and also while stderr alone is chatty — on both the read and the
  write wait, so a server that dies while a forked helper still holds its
  stdin/stdout open ends the call within about a second instead of waiting for
  the helper to exit, even when that helper keeps logging to the inherited
  stderr. Whatever the server wrote before dying is still drained and read.
  The bound holds from a `pcntl_fork()`ed caller as well: a server the owner
  has not reaped yet is a zombie, which signal 0 reports as alive, so where
  `/proc` exists the probe reads `/proc/<pid>/stat` and treats a zombie, or a
  pid whose start time no longer matches the server's (a reused pid), as dead.
  Without `/proc` (macOS, BSD) a forked caller falls back to signal 0 and to
  pipe EOF.
- A caller that DOES want a bound opts in per call:
  `callTool($name, $args, timeoutSeconds: 30.0)` abandons the wait at the
  deadline (time queued for the exchange lock counts), sends
  `notifications/cancelled` naming the request, and returns
  `{"error": "Tool call timed out after 30s …"}`; the connection stays usable
  and the late reply is skipped by id. Null, zero, a negative or a non-finite
  value leaves the call unbounded.
- `callTool($name, $args, onWait: $beat)` calls `$beat` at least once a second
  for as long as the call waits, so an embedder whose own watchdog measures
  silence can keep a long unbounded call visibly alive. Throttle it yourself;
  it may fire far more often.
- Every socket wait is bounded by `stream_select` polls; stderr is absorbed on
  both wait sets so a full 64KiB kernel pipe can never deadlock the child.

## Failure reporting

- `McpMessage::toJson()` throws `InvalidArgumentException` for an envelope
  JSON cannot carry (INF/NAN — a model's `1e999` decodes to `INF` — or
  invalid UTF-8) instead of emitting a blank line no server would answer.
  `request()` and `notify()` let it through, with nothing written;
  `callTool()` turns it into an `{"error": ...}` payload, per the `McpServer`
  contract.
- `notify()` returns `bool`: whether the whole line went out.
- A spawn planner must return its command as a non-empty argv list of
  strings; a shell string would make the server a grandchild of `/bin/sh`
  that `stop()` cannot reach, so it is refused up front.
- `ExchangeLock::store()`/`markPhase()` return `false` when a state write
  fails (full or read-only temp filesystem); the exchange then fails rather
  than running without its recovery marker, and a failed store leaves the file
  empty, which reads as dirty. The lock's factory is `ExchangeLock::new()`
  (`create()` remains as a deprecated alias).

## Wire shape

MCP `params`, `capabilities` and tool `arguments` are JSON objects, and the
official TypeScript and Python SDK servers reject a JSON array in their place.
PHP's `[]` encodes as an array, so `McpMessage::toJson()` emits empty `params`
as `{}`, `initialize` sends `"capabilities":{}`, an argument-less `callTool()`
sends `"arguments":{}`, and `tools/list` omits `params` entirely (as the
reference TS client does).

Tool arguments are shaped one level further: a model's `{"filter":{}}`
decodes (assoc) to `['filter' => []]`, so `callTool()` walks the arguments
against the tool's advertised `inputSchema` (`ArgumentShape::conform()`) and
sends every empty array the schema types as an object — through nested
`properties`, `additionalProperties`/`patternProperties` maps, array `items`,
`anyOf`/`oneOf`/`allOf`, `["object","null"]` unions and local `$ref`s — as
`{}`. An empty array the schema types as an array, or whose position the
schema does not type, stays `[]`; a `\stdClass` you pass is kept as-is. A
nested empty map inside your own `request()` params still has to be spelled
`new \stdClass()`.

## Fork safety

A started `StdioMcpServer` may be used from `pcntl_fork()`ed children of the
process that started it — one server shared by the whole process tree:

- ids are process-unique (`RequestIdSequence`), so a reply — even the late
  reply of a SIGKILLed child's call — only ever matches the call that sent
  it; a forked child that re-opens the connection cannot silently take over
  the id space while its owner is still live — `claim()` refuses a foreign
  claim against a live owner, and the child keeps minting pid-tagged ids;
- every exchange runs under a cross-process `flock` (`ExchangeLock`; each
  process opens its own handle, since an inherited one shares the lock).
  **Concurrent calls on one server therefore serialise**; the lock wait
  honours a request's deadline and gives up if the server dies;
- the flip side of serialisation: a holder that stays **alive but hung** —
  waiting on a deadline-less call to a wedged server — wedges every sharer
  for as long as it holds the lock, and `flock` carries no FIFO promise, so a
  waiter can be starved even after the holder releases. Nothing in this
  library can bound another process's work; each waiter bounds **itself**
  with `callTool(..., timeoutSeconds:)` (in sugar-crush, the per-server
  `"toolTimeout"` entry in `.mcp.json` feeds exactly that knob). Set it if
  you share a server across forked turns or sub-agents;
- stdout bytes read past a response are kept in the lock file, so the next
  exchange starts at a line boundary whichever process runs it;
- a holder killed mid-exchange leaves a phase marker: the next exchange
  terminates a half-written request with a leading newline and skips the
  fragment of a half-read reply until the first parseable message;
- only the starting process stops the server; `stop()`/destruct in a child
  closes that child's handles only. A child checks liveness with
  `posix_kill($pid, 0)` (it cannot `waitpid` a sibling's child).
- the lock file's name records its owner
  (`sugar-mcp-lock-<pid-namespace>-<pid>-<random>`). An owner that is killed
  before it stops its server leaves the file behind, so every new connection
  first removes the files whose owner pid is gone in this pid namespace and
  whose `flock` nobody holds (`ExchangeLock::sweepStale()`). Files of live
  owners, of other pid namespaces, and pre-existing `tempnam()`-named files
  are left alone.

stderr is not locked — it is diagnostics only.

> **A note on `LspExchangeLock`.** sugar-crush's LSP transport keeps its own
> close-looking twin of `ExchangeLock` (`src/LSP/LspExchangeLock.php`). That
> duplication is deliberate, not a missed reuse: the LSP side needs phase
> state carried as an atomic write-to-temp + `rename()` (`store()`), so a
> holder SIGKILLed mid-store leaves the previous file whole. Neither
> behaviour is a defect in this library's lock; each file locks what its
> transport needs.

## Deliberate exclusions

- **Downstream (product policy, not protocol):** contained/detached spawning
  (setsid wrapping, PATH pre-checks, curated environments) — wrap `proc_open`
  policy at the embedder.
- **Phase 2:** HTTP transport, server-side implementation, OAuth (PKCE,
  loopback, dynamic client registration), and `resources/*` / `prompts/*`
  conveniences — `StdioMcpServer::request()` already reaches them.

## Tests

```sh
cd sugar-mcp && composer install && vendor/bin/phpunit
```

The suite spawns real short-lived PHP child servers over stdio
(`tests/Fixtures/`) — bounded, reaped, no network access.

## Assets

`media/icons/sugar-mcp.png` is a shared monorepo placeholder (currently
byte-identical to sugar-diff's icon) pending a dedicated glyph.

## License

MIT
