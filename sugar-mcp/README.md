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
| `McpServer` | Transport contract: `start` / `stop` / `listTools` / `callTool` |
| `StdioMcpServer` | Child-process stdio transport: argv-array spawn, NDJSON framing, one-clock handshake deadline, tail-bounded stderr capture, `BoundedShutdown` teardown, `__destruct` orphan-proofing |
| `McpRouter` | Deny-before-allow tool/server narrowing over raw config keys; empty allow-list means allow-all |

## Quick start

```php
use SugarCraft\Mcp\StdioMcpServer;

$server = new StdioMcpServer(
    name: 'everything',
    command: 'npx',
    args: ['-y', '@modelcontextprotocol/server-everything'],
);

$server->start();                    // initialize → initialized → tools/list
foreach ($server->listTools() as $tool) {
    echo $tool->name, ': ', $tool->description, PHP_EOL;
}

$result = $server->callTool('echo', ['message' => 'hi']);
$server->stop();                     // also runs automatically on destruct
```

## Timeout discipline (E646)

- The handshake runs under **one** monotonic (`hrtime`) deadline
  (`DEFAULT_START_TIMEOUT_SECONDS`, 60s, sized for cold `npx` fetches) shared
  across `initialize`, `initialized` and `tools/list`.
- `callTool()` carries **no** deadline: a tool call is somebody's real work and
  is bounded by child liveness, never by a wall-clock kill of in-flight work.
- Every socket wait is bounded by `stream_select` polls; stderr is absorbed on
  both wait sets so a full 64KiB kernel pipe can never deadlock the child.

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
