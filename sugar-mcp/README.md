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
- An `initialize` reply carrying `error` is a start failure: `start()` stops
  the child and throws a `RuntimeException` naming the server's error code and
  message (plus any captured stderr), rather than reporting "up, 0 tools".
- `callTool()` carries **no** deadline: a tool call is somebody's real work and
  is bounded by child liveness, never by a wall-clock kill of in-flight work.
- Every socket wait is bounded by `stream_select` polls; stderr is absorbed on
  both wait sets so a full 64KiB kernel pipe can never deadlock the child.

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
  reply of a SIGKILLed child's call — only ever matches the call that sent it;
- every exchange runs under a cross-process `flock` (`ExchangeLock`; each
  process opens its own handle, since an inherited one shares the lock).
  **Concurrent calls on one server therefore serialise**; the lock wait
  honours a request's deadline and gives up if the server dies;
- stdout bytes read past a response are kept in the lock file, so the next
  exchange starts at a line boundary whichever process runs it;
- a holder killed mid-exchange leaves a phase marker: the next exchange
  terminates a half-written request with a leading newline and skips the
  fragment of a half-read reply until the first parseable message;
- only the starting process stops the server; `stop()`/destruct in a child
  closes that child's handles only. A child checks liveness with
  `posix_kill($pid, 0)` (it cannot `waitpid` a sibling's child).

stderr is not locked — it is diagnostics only.

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
