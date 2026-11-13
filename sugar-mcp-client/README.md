# Sugar MCP Client

> Formerly published as `sugarcraft/sugar-mcp` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Mcp\` → `SugarCraft\McpClient\`. <!-- @rename-keep -->

MCP (Model Context Protocol) client core for PHP: a JSON-RPC 2.0 envelope
codec, a newline-delimited JSON (NDJSON) stdio transport with a fully bounded
lifecycle, the initialize/tools protocol layer, and policy-free allow/deny
routing.

Here "client" means the tool-CALLING side: this library is what an AI coding
agent or editor embeds to spawn and talk to an MCP SERVER process. You run it
inside your application; the server is the child it launches.

Requires PHP >= 8.3; the only runtime dependency beyond PHP is
`sugarcraft/sugar-core`. Speaks MCP `2024-11-05`
(<https://modelcontextprotocol.io/specification/2024-11-05>) and refuses a
server that answers the handshake claiming a different revision (a legacy
server that omits the field is allowed to proceed). Later revisions' optional
`annotations` members on tool definitions are parsed and carried verbatim on
purpose — forward-compat with spec `2025-03-26` is deliberate, and this
client consults none of them. The Quick start below
launches the reference server through `npx`, so running it verbatim needs
Node.js on PATH; for a trial with nothing to install or download,
`php examples/echo.php` completes the same round trip (see *Examples*).

Extracted from the [candy-code](https://github.com/sugarcraft/candy-code)
MCP stack, where these classes carry its entire stdio server lifecycle. The
wire algorithms are faithful extractions from that candy-code past, not ports
of a Go implementation; everything product-specific stayed downstream.

## Install

```sh
composer require sugarcraft/sugar-mcp-client
```

## What ships

| Class | Role |
|---|---|
| `McpMessage` | Immutable JSON-RPC 2.0 envelope — request / notification / success / error — with a null-preserving `result` sentinel and the wire-typed id pair `parsePreservingId()` / `withWireId()`, so a reply echoes an id's original JSON type |
| `McpTool` | Tool definition value object with a well-typedness gate (`tryFromArray`) |
| `ArgumentShape` | Restores `{}` vs `[]` in decoded tool-call arguments against the tool's `inputSchema` |
| `McpServer` | Transport contract: `start` / `stop` / `listTools` / `callTool` / `request` / `notify` — lifecycle, tool surface, and the two raw RPC legs; trailing opt-ins are concrete-class API |
| `StdioMcpServer` | Child-process stdio transport: argv-array spawn, explicit spawn directory (`cwd`, default inherit), NDJSON framing, one-clock handshake deadline, tail-bounded stderr capture (also drainable between exchanges via `pumpStderr()`), skipped-definition reporting (`malformedToolDefinitions()` / `malformedToolDefinitionsReport()`), `BoundedShutdown` teardown, `__destruct` back-stop (the SIGKILLed-owner residual is named in *Fork safety*), and the resources/prompts convenience quartet (`listResources` / `readResource` / `listPrompts` / `getPrompt`, sharing callTool's array-or-error shape), plus the kept handshake result (`serverInfo()` / `serverCapabilities()` / `initializeResultDefects()` — survives `stop()` like the other post-mortem evidence) |
| `McpRouter` | Deny-before-allow SERVER narrowing over raw config keys (tool rosters follow servers); empty allow-list means allow-all |
| `RequestIdSequence` | JSON-RPC ids unique across `pcntl_fork()`: plain counters in the owner, `<pid>-<nonce>-<n>` elsewhere; the transport stringifies what it sends, so an outbound request carries `"5"` on the wire, never a bare `5` |
| `ExchangeLock` | Cross-process `flock` for one connection, carrying the shared read buffer and the dead-holder phase marker |

## Quick start

```php
require 'vendor/autoload.php';

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\McpClient\StdioMcpServer;

$server = new StdioMcpServer(
    name: 'everything',
    command: 'npx',
    args: ['-y', '@modelcontextprotocol/server-everything'],
);

$server->start();                    // initialize → notifications/initialized → tools/list
foreach ($server->listTools() as $tool) {
    // description is server-authored prose: sanitize before it reaches a terminal
    echo $tool->name, ': ', Sanitize::untrusted($tool->description), PHP_EOL;
}

$result = $server->callTool('echo', ['message' => 'hi']);
$server->stop();                     // also runs automatically on destruct
```

The example needs Node.js/npx on PATH (it spawns
`npx -y @modelcontextprotocol/server-everything`); any other stdio MCP server
takes the same three constructor arguments — name, command, argv list — and
`examples/echo.php` shows exactly that with a server that needs nothing
installed.

`callTool()` answers with the tool's result envelope — normally
`['content' => [['type' => 'text', 'text' => ...], ...], 'isError' => bool]`
as the server shaped it (a non-array (scalar) result is wrapped into a
one-item `content` list for you) — or, on failure, `['error' => 'why']`. It never
throws for a call that merely failed; see *Failure shapes*.

## Examples

```sh
php examples/echo.php        # full round trip, zero dependencies beyond PHP
```

`examples/echo.php` is self-contained: run plainly it is a client
(initialize → tools/list → tools/call, printed), and under `--serve` it is
the stdio server it spawns as its own child — the same three constructor
arguments the Quick start uses, with nothing fetched from the network.
The suite runs this example headless (`ExamplesTest`), so the promise
cannot rot.

## Trust boundary

**A stdio server entry is arbitrary code execution as the user running this
library.** The config you hand a `StdioMcpServer` names a command and
arguments; `start()` spawns them, and the child speaks whatever protocol it
likes back into your process. Treat every server you connect to the way you
treat a shell command you paste from the internet — the quick start above runs
`npx -y @modelcontextprotocol/server-everything`, which downloads and executes
third-party code on first use.

**The child inherits the FULL parent environment by default.** With `env: []`
(nothing passed) the spawn carries the entire ambient environment: SSH agent
socket paths, cloud-provider credentials, API keys — everything the invoking
process has, not just what the server needs. `PATH` is resolved at exec time
and never verified, so the binary that runs is simply the first match.

**`env:` is additive and can never scrub.** Entries you pass override or add
names; a name you do not mention still rides through from the parent, exactly
as it does with no map at all (`StdioMcpServerTrustBoundaryTest` pins both
halves of that contract). The option is for telling a server where its own
token lives, not for withholding the rest of your environment.

**`envRemove:` drops names declaratively.** `envRemove: ['AWS_SECRET_ACCESS_KEY', 'SSH_AUTH_SOCK']` strips each name from the inherited environment before `env:` overlays land — the precedence chain is inherited → envRemove → env, and the grammar (a list of distinct non-empty names) is validated at construction. `curatedEnv:` is the exclusive full replace: the child sees only the names you list (`[]` means a purged environment); pairing it with `env:`, `envRemove:` or `spawnPlanner:` throws at construction, because an input that would never be read is a lie. Neither knob holds opinions: this library still has no list of which names are secrets, and any list it shipped would be wrong for someone's environment — you name what you drop.

**Use `spawnPlanner` for containment.** The planner receives the server name, the
argv the spawn would exec, and the env map YOU authored via `env:` (empty
when you passed none) — never the merged ambient environment. It returns the
pair that actually spawns: the curated key/value map there is the full
replacement, not a merge, and `null` in its place sends the child out with
the parent's entire ambient environment; combining a planner with either
declarative knob refuses at construction (the planner owns the environment
wholesale). This is exactly what `candy-code`
does: it
ships credential-shaped-name scrubbing on top of this library
(`ProcessContainment`) because the ambient-secrets hazard is real in
production. That remediation is deliberately downstream product policy here —
this library has no list of which names are secrets, and any list it shipped
would be wrong for someone's environment.

The planner is a `\Closure(string $name, array $argv, array $env): array` —
the seam's concrete parameter type is `?\Closure`, so a callable that is not a
Closure is a TypeError rather than an accepted fallback — returning
`[list<string> $argv, ?array $env]`; throwing from it refuses the launch:

```php
$binary = 'npx';
$argv = ['-y', '@modelcontextprotocol/server-everything'];
$curated = ['PATH' => '/usr/local/bin:/usr/bin:/bin'];
$server = new StdioMcpServer(
    name: 'narrow',
    command: $binary,
    args: $argv,
    spawnPlanner: static fn (string $name, array $argv, array $env): array =>
        [$argv, $curated],   // full replacement env — nothing ambient rides
);
```

`cwd:` pins the spawn directory (see *Spawn directory*), and `lockDir:` pins
where the `ExchangeLock` file lives — useful when `TMPDIR` moves or a janitor
sweeps it; the directory must exist, and a lock that cannot be created there
fails `start()` loudly rather than silently sharing less.

**Pre-owner-in-name lock residue is never deleted.** Files from before the
owner-in-name law carry `tempnam()`'s old shape
(`sugar-mcp-client-lock-XXXXXX`) — the name says nothing about an owner, so
`ExchangeLock::sweepStale()` leaves them forever, and on a shared `/tmp` the
residue can include world-readable transcript bytes written at ambient umask
by an older version. That is the accepted cost of the never-delete-unprovable
law (see *Fork safety*), not an oversight; the manual remedy is the
operator's.

**Tool metadata is server output too.** `listTools()` hands back the child's
self-declared names, descriptions, and input schemas. A control-byte gate
refuses malformed tool *names* at parse time, but *descriptions* are
deliberately free-form prose — tabs and newlines are legitimate there, and the
transport scrubs nothing. Treat them like the diagnostics below: render
through `SugarCraft\Core\Util\Sanitize::untrusted()` before any terminal
echo, as the quick start does.

## Spawn directory

The child's working directory is the embedder's decision, not an accident of
where the parent happened to be standing: pass `cwd:` to start the server in a
specific absolute directory (`new StdioMcpServer(name: …, cwd: $home)`), and
the default `null` keeps the historic inherited spawn byte-identical. The move
is `proc_open`'s own cwd argument, so it lands on the child at exec time and
the parent's directory is never touched — and it composes with a spawn
planner: the planner decides *what* runs, `cwd` decides *where*. `candy-code`
uses the seam to run its user-tier MCP servers in the home directory and its
project-tier servers at the project root rather than inheriting whatever
checkout the launch stood in.

## Timeout discipline

- The handshake runs under **one** monotonic (`hrtime`) deadline
  (`DEFAULT_START_TIMEOUT_SECONDS`, 60s, sized for cold `npx` fetches) shared
  across `initialize`, `notifications/initialized` and `tools/list`. A slow
  start is not a silence: `start(onWait: $beat)` gets the same liveness beat
  every poll slice while the handshake runs. The `startTimeoutSeconds:`
  override is a silent-fallback knob: null, zero or a negative value lands on
  the 60s default — a 0 that means 60 — so a bad setting can never disable the
  handshake bound entirely.
- Every handshake leg must succeed, or `start()` stops the child and throws a
  `RuntimeException` (with any captured stderr) rather than reporting "up, 0
  tools": an `initialize` or `tools/list` reply carrying `error` names the
  server's error code and message; a leg that gets no reply, or a
  `notifications/initialized` that cannot be delivered, names that leg. A
  server claiming a different `protocolVersion` than this client speaks
  refuses the start too; one that omits the field proceeds.
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
  pipe EOF. The ~1s shape holds where each poll slice is capped at one second:
  an unbounded call, or a bounded call carrying an `onWait:` beat (inbound
  stderr wakes the poll for a rate-limited probe either way). A bounded call
  with no beat may instead sleep in a single slice across its remaining
  budget — there, a silent helper holding the pipes means the call ends at
  its own `timeoutSeconds`, which is that call's bound.
- A caller that DOES want a bound opts in per call:
  `callTool(name: $name, arguments: $args, timeoutSeconds: 30.0)` abandons the
  wait at the deadline (time queued for the exchange lock counts), sends
  `notifications/cancelled` naming the request, and returns
  `{"error": "Tool call timed out after 30s and was cancelled; the server may
  still finish the work"}`; the connection stays usable and the late reply is
  skipped by id. A `null` or non-finite value leaves the call unbounded; zero
  and a negative are refused at the door — "never" is spelled `null`, never
  by folding.
- `callTool(..., onWait: $beat)` calls `$beat` at least once a second
  for as long as the call waits, so an embedder whose own watchdog measures
  silence can keep a long unbounded call visibly alive. Throttle it yourself;
  it may fire far more often.
- Every socket wait is bounded by `stream_select` polls; stderr is absorbed on
  both wait sets so a full 64KiB kernel pipe can never deadlock the child.
  A `select` that keeps failing (an EINTR storm) is counted on both the read
  and the write side and ends the wait with a named cause.
- `request()` and `notify()` take a `$deadline` as an **absolute monotonic
  instant** — the same `hrtime` basis as `StdioMcpServer::monotonicNow()` —
  not a duration and not wall clock. Build one with
  `StdioMcpServer::deadlineAfter(30.0)` for "30 seconds from now" (it refuses
  a negative bound at its own door); a past instant gives up silently
  (`null`/`false`), and a wall-clock-magnitude value would never fire, so the
  magnitude is refused by throwing an `InvalidArgumentException` instead.
  `callTool(timeoutSeconds:)` remains the relative-duration spelling.
- One frame never outgrows the read buffer: bytes past `MAX_FRAME_BYTES`
  (64 MiB) with no newline are not NDJSON whatever they eventually say, and
  the oversized frame is **dropped, not truncated** — half a frame parses as
  a malformed message and would blame the server for this side's refusal.
  Dropping the buffer *is* the framing reset: the refusal reaches `callTool()`
  as an error payload, the connection stays up, and whatever bytes still sit
  in the pipe resync under the skip law, so the next exchange succeeds or
  fails on its own merits.

## Limits

Every wall is a constant on `StdioMcpServer` and none is configurable — each
is a guard against a pathological server, not a working budget:

| Limit | Value | Why |
|---|---|---|
| Whole pending read buffer | 64 MiB (`MAX_FRAME_BYTES`) | A stream with no newline for 64 MiB is not speaking NDJSON; the buffer is dropped, never truncated (see the bullet above) |
| stderr tail retained | 64 KiB (`MAX_STDERR_BYTES`) | Bounded diagnostic memory; the tail rides every failure payload, flagged `[stderr truncated]` once it overflowed |
| `tools/list` pages | 32 (`MAX_TOOL_PAGES`) | ~3,000 entries at a typical hundred per page, far past any real server; the repeated-cursor refusal catches a lying pager, this catches the fast-lying one before it eats the whole handshake budget |
| Unattributable frames per exchange | 8 (`UNATTRIBUTABLE_FRAMES_BEFORE_REFUSAL`) | Bounds a deadline-less call against a server whose every frame is junk to us; one genuine reply ends the read and clears the count |
| Blank frames per exchange | 1024 (`MAX_BLANK_FRAMES_BEFORE_REFUSAL`) | Newlines are the documented keep-alive shape; 1024 sits far above any honest heartbeat — one burst of frame-sized keep-alive passes, a permanent flood refuses |
| Consecutive `stream_select` failures | 10,000 (`MAX_CONSECUTIVE_SELECT_FAILURES`) | A signal storm makes select fail thousands of times a second (product-measured); the ceiling keeps genuine EINTR retries from becoming a livelock |
| `notifications/cancelled` delivery grace | 2 s (`CANCEL_NOTICE_SECONDS`) | The post-timeout cancellation notice gets this budget, no more |
| Default handshake deadline | 60 s (`DEFAULT_START_TIMEOUT_SECONDS`, public — `startTimeoutSeconds:` overrides it per server) | One monotonic deadline shared by all three `start()` legs, sized for cold `npx` fetches |
| Poll slice with nothing pending | 1 s (`READ_POLL_SECONDS`) | The select timeout that paces the liveness probe and `onWait:` beats |

## Failure shapes

What you get when things go wrong, so you can branch on it knowingly. The
docblock typedefs name each door's vocabulary (defined on the owning class,
consumed by every `@return`):

| Door | Vocabulary | Typedef |
|---|---|---|
| `start()` | throws, naming the leg and cause — no half-open connection | — |
| `callTool()` + the provider quartet | result array or `['error' => …]`; never throws, never null | `FailurePayload` |
| `request()` | reply envelope, or `null` for "no usable reply" (a JSON-RPC **error is not null** — it is a message with `->error`) | `RawRequestResult` |
| `notify()` | `true` once fully on the wire, `false` otherwise | `NotifyAck` |
| `ExchangeLock::store()` / `markPhase()` | `true` when the state landed whole, `false` when it did not | `StoreResult` |

- `start()` **throws** — the connection either comes up with a tool roster or
  does not come up. The exception names the leg and the cause:
  - `Failed to start MCP server: <name> — could not execute <command> (<os
    cause>)` when the spawn itself fails (a missing `npx` says so, with the
    OS-level reason);
  - `… — initialize refused (code): <server message>` / the same for
    `tools/list`, plus the stderr tail when the server wrote one;
  - `… — protocol mismatch: this client speaks X, the server answered Y`;
  - a `tools/list` page that is not shaped like a tool list — a non-array
    `tools`, or a definition malformed enough that the name cannot be
    trusted — SKIPS and COUNTS rather than axing the boot: the poisoned
    shapes never enter the roster, the server's well-formed definitions do,
    and `malformedToolDefinitionsReport()` announces the count and first
    names for whoever launches the server to surface (a legitimately EMPTY
    `tools: []` stays legal). Server-level failures — an error reply, no
    reply, lying pagination — still throw by name and cause.
- `callTool()` **answers, never throws**: a failed call comes back as
  `['error' => ...]` naming its CAUSE — the server's own JSON-RPC code and
  message when it refused
  (`Tool call failed — tools/call refused (-32602): ...`), or the reader's
  reason when no valid answer arrived (`the server is not started`, `the
  server process exited`, `the request could not be written to the server`,
  `the server refused an unparseable frame (error code -32700)`, `no reply
  arrived before the wait ended`, the bounded-timeout text above). Frames a
  JSON-RPC 2.0 server must not send — both `result` and `error` in one reply,
  or neither — are reported, never silently taken as success. The captured
  stderr tail rides along in the message; `stderrTail()` exposes it on its
  own and survives `stop()`, so a post-mortem still has the evidence.
- A server-request handler that throws or answers malformed never crashes the
  caller: the in-flight `callTool()` ends through its error payload, naming
  the handler class and message — never a mislabelled encoding failure and
  never an escaped exception; the server's own request stays unanswered on
  that leg.
- A server that floods the pipe with frames no exchange can attribute (junk,
  id-less pushes, endless blank keep-alives) is refused after a bounded
  number of them instead of holding a deadline-less call forever.
- `request()` returns `null` and `notify()` returns `false` when the write or
  the wait failed (not started, deadline passed, lock lost); neither throws
  for those. Both let `McpMessage::toJson()`'s `InvalidArgumentException`
  through for an unencodable envelope, with nothing written.
- Diagnostic strings embed SERVER-authored bytes after a control-character
  sweep (no ESC/OSC/NUL survives), but they are still UNTRUSTED text: render
  them through `SugarCraft\Core\Util\Sanitize::untrusted()` before they reach a
  terminal, never raw.

## Routing policy

`McpRouter` narrows which SERVERS a caller may see, before any transport
runs. A server's tool roster arrives whole or not at all, so tool narrowing
is server narrowing: tool rosters follow servers. It holds a name-keyed map
of already-built servers and takes deny patterns as config — either a plain
list of glob patterns (every entry denies) or a `pattern => action` map; an
action other than the exact string `"deny"` is ignored, and a malformed
spec (a non-string list entry, or a map value that is neither an action
string nor a spec array carrying one) throws at construction:

```php
use SugarCraft\McpClient\{McpRouter, StdioMcpServer};

$servers = ['fs' => $fsServer, 'scratch' => $scratchServer];
$router = new McpRouter($servers, denyPatterns: ['scratch', 'untrusted_*']);

$visible = $router->resolveAllowedServers();      // deny applies first
$tools = $router->resolveAllowedTools(['fs']);    // SERVER allow-list
```

Trimming ONE server's roster to a named subset is deliberately not this
class's job: that is downstream product policy. In candy-code the agent
presets do it by matching their tool rosters against the sanitised
`mcp__<server>__<tool>` wire names; this library keeps routing policy-free.

The two membership laws are public statics so downstream gates read exactly
this doctrine: `McpRouter::serverDenied($name, $denyPatterns)` and
`McpRouter::serverAllowed($name, $allowList)`. Deny runs before allow; an
empty allow-list means allow-all (distinct from "restrict to nothing"); a
non-string or empty allow entry throws. Note the deliberate asymmetry: deny
patterns are `fnmatch`-matched whole (broad, because a missed denial is a
security hole), while an allow entry only globs when it contains `*`
(strict, because a mis-globbed allowance is worse than a missed one).

Tool-level hints are the same law one level down: `McpTool::$annotations`
(the server's `readOnlyHint`, `destructiveHint`, `idempotentHint`,
`openWorldHint`, `title` claims) is parsed off the wire and carried verbatim,
and neither this client nor `McpRouter` ever consults it — a hint is an
unverified server claim about itself, and enforcing it is the consumer's
policy to write, not a behaviour to inherit.

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
  empty, which `load()` reads as dirty. The lock's factory is `ExchangeLock::new()`
  (`create()` remains as a deprecated alias, scheduled for removal at the
  2.0.0 major); its file is opened at mode `0600`, verified before any
  payload lands, and a filesystem that refuses the mode fails the open
  loudly.
- `callTool()`/`callTool(name:, arguments:)`: the concrete parameter names
  match the `McpServer` interface, so named-argument calls written against
  the contract bind here. The three trailing opt-ins (`onWait:`,
  `timeoutSeconds:`, `onServerMessage:`) exist on `StdioMcpServer` only —
  code typed against the interface cannot pass them.
- What you can READ when a call fails: most of the failure evidence
  above arrives inside a message string. The readable surface is
  `listTools()` (declared on `McpServer`) and, on `StdioMcpServer` only,
  `isUp()`, `stderrTail()`, `malformedToolDefinitions()` /
  `malformedToolDefinitionsReport()`, the kept handshake result
  (`serverInfo()` / `serverCapabilities()`, with `initializeResultDefects()`
  naming any spec-required member the server omitted), plus the
  `pumpStderr()` seam and the `onWait:` liveness beats. Frame-strike counts,
  consecutive `select` failures and swallowed stderr bytes surface only in
  the messages they
  produced — nothing exposes them as counters — so code that must branch on
  failure should key on the signals above rather than parse prose (and the
  text `stderrTail()` and the `serverInfo()` / `serverCapabilities()` maps
  return is server-authored and UNTRUSTED, render it through a sanitizer).

## Wire shape

MCP `params`, `capabilities` and tool `arguments` are JSON objects, and the
official TypeScript and Python SDK servers reject a JSON array in their place.
PHP's `[]` encodes as an array, so `McpMessage::toJson()` emits empty `params`
as `{}`, `initialize` sends `"capabilities":{}`, an argument-less `callTool()`
sends `"arguments":{}`, and `tools/list` omits `params` entirely (as the
reference TS client does). The `capabilities:` constructor argument configures
what that object HOLDS — a configured map rides through the same object cast,
so `['roots' => ['listChanged' => false]]` arrives as a populated JSON object
and the default still lands as `{}` byte-for-byte. Advertise what you will
answer: a capability-gating server sends `roots/list` and
`elicitation/create` requests only to clients that declared the capability —
so the server-request handler described below cannot fire without it.

The same frame carries the client identity, configured with the `clientInfo:`
constructor argument. It must be a non-empty string `name` and a string
`version`; anything else throws an `InvalidArgumentException` at construction,
and the shipped default is the only shape that may stand in for an omission —
an explicit `[]` is a bug, not a reset. The untouched default is resolved to
the real installed package version when the Composer runtime knows it (a
frozen literal would keep announcing an outdated version, release after
release); an explicitly supplied identity is never rewritten.

For an identity that is the default except for one arm, author it piecewise:
`clientName: 'acme-agent'` advertises acme-agent at the resolved installed
version — the omitted arm rides the very same resolution the untouched default
uses, so renaming yourself never requires carrying a version literal (the
disease the full-replace door let its first consumer catch). `clientVersion:`
is the matching escape for an embedder the Composer runtime cannot see — a
phar, a vendored copy. Supplying a split knob alongside `clientInfo:` throws
at construction, one wire value with two masters is a lie the constructor
refuses; so does an empty-string arm, which is not a reset but a blank
identity.

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

## Server requests

MCP is bidirectional: a server may send requests of its own (`ping`,
`roots/list`, `elicitation/create`, `sampling/createMessage`) and
notifications (`notifications/progress`) while a call is in flight.
`readResponse()` answers them inline instead of skipping them: pass
`onServerMessage:` to the constructor for a connection-wide answerer, or to
`callTool()`/`request()` for one exchange. The handler is called as
`($method, $params, $isNotification)`; for a request it returns
`['result' => ...]`, `['error' => ['code' => int, 'message' => string]]`,
or `null` for the built-in default — `ping` is acknowledged with `{}`,
anything else is refused "Method not found" (-32601). Replies preserve the
request id's wire TYPE (an int id comes back an int, never a string);
notifications write nothing back. Two consequences worth knowing:

- answers are written **under the exchange lock**, so a handler that waits
  (an elicitation held open for a human) holds the lock — every other call
  to that server queues behind it. One pipe, one conversation; an answerer
  must never call back into the same server instance.
- the wall clock a request handler spends is **excluded from** a bounded
  call's `timeoutSeconds` budget — waiting on an answer this client owes is
  not the server being slow. `sampling/createMessage` ships no
  answer beyond the default refusal: running a model in the client's name
  is product policy, downstream.

## Fork safety

A started `StdioMcpServer` may be used from `pcntl_fork()`ed children of the
process that started it — one server shared by the whole process tree:

- ids are process-unique (`RequestIdSequence`), so a reply — even the late
  reply of a SIGKILLed child's call — only ever matches the call that sent
  it; a forked child that re-opens the connection cannot silently take over
  the id space while its owner is still live — `claim()` refuses a foreign
  claim against a live owner, and the child keeps minting pid-tagged ids.
  When a foreign process succeeds a claim because the old owner DIED, its
  counter resumes far ahead of the dead owner's window, so a stale reply
  still sitting in the pipe can never alias a new call;
- every exchange runs under a cross-process `flock` (`ExchangeLock`; each
  process opens its own handle, since an inherited one shares the lock).
  **Concurrent calls on one server therefore serialise**; the lock wait
  honours a request's deadline and gives up if the server dies;
- the flip side of serialisation: a holder that stays **alive but hung** —
  waiting on a deadline-less call to a wedged server — wedges every sharer
  for as long as it holds the lock, and `flock` carries no FIFO promise, so a
  waiter can be starved even after the holder releases. Nothing in this
  library can bound another process's work; each waiter bounds **itself**
  with `callTool(..., timeoutSeconds:)` (in candy-code, the per-server
  `"toolTimeout"` entry in `.mcp.json` feeds exactly that knob). Set it if
  you share a server across forked turns or sub-agents;
- stdout bytes read past a response are kept in the lock file, so the next
  exchange starts at a line boundary whichever process runs it;
- a holder killed mid-exchange leaves a phase marker: the next exchange
  terminates a half-written request line with a leading newline and skips the
  fragment of a half-read reply until the first parseable message;
- only the starting process stops the server; `stop()`/destruct in a child
  closes that child's handles only. A child checks liveness with
  `posix_kill($pid, 0)` (it cannot `waitpid` a sibling's child). If the OWNER
  is SIGKILLed while a forked child still holds the connection, nothing here
  reaps the server child — that cleanup is the embedding process manager's
  job (candy-code runs it through its own `Support\ProcessReaper`).
- the lock file's name records its owner
  (`sugar-mcp-client-lock-<pid-namespace>-<pid>-<random>`). An owner that is killed
  before it stops its server leaves the file behind, so every new connection
  first removes the files whose owner pid is gone in this pid namespace and
  whose `flock` nobody holds (`ExchangeLock::sweepStale()`). Files of live
  owners and of other pid namespaces are left alone — and so is the residue
  from before the owner-in-name law: `tempnam()`-shaped
  `sugar-mcp-client-lock-XXXXXX` names carry no owner to probe, so they
  survive every sweep, including any world-readable transcript bytes an older
  version left at ambient umask on a shared `/tmp`. Keeping what cannot be
  proven dead is the law (never unlink another process's live file on a
  guess); the operator's remedy is manual — after confirming nothing holds a
  file open (`lsof <path>` empty), remove the old-shape files by hand.

stderr is not locked — it is diagnostics only.

> **A note on `LspExchangeLock`.** candy-code's LSP transport keeps its own
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
- **Deferred (not planned in this package):** HTTP/SSE transport, server-side
  implementation, and OAuth (PKCE, loopback, dynamic client registration).
  `resources/*` and `prompts/*` traffic rides the shipped convenience quartet
  (`listResources()` / `readResource()` / `listPrompts()` / `getPrompt()`)
  over `StdioMcpServer::request()` — the same array-or-error shape as
  `callTool()` (the product's provider pages rode exactly that before the
  quartet gave the walk a name), and the server's own request/notification
  traffic is answered inline
  — see *Server requests* above. What is deliberately NOT answered for you is
  `sampling/createMessage`: model policy stays downstream.
- Deprecated surfaces (`ExchangeLock::create()`) keep working and announce
  their replacement in the docblock; removal is a major-version change,
  announced in the release notes well before it lands.

## Tests

```sh
cd sugar-mcp-client && composer install && vendor/bin/phpunit
```

The suite spawns real short-lived PHP child servers over stdio
(`tests/Fixtures/`) — bounded, reaped, no network access.

## License

MIT
