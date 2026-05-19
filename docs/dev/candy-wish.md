# candy-wish — Developer Guide

`candy-wish` provides extension points for custom middleware, custom
transports, and custom session factories.

---

## Extension points

### Middleware interface

All middleware implement `SugarCraft\Wish\Middleware`:

```php
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;

interface Middleware
{
    /**
     * @param Context  $ctx     Root or derived context for this request
     * @param Session  $session SSH session metadata
     * @param callable $next   Continuation — call with (Context, Session)
     *                           to pass control down the chain; omit to
     *                           short-circuit as a terminal middleware
     */
    public function handle(Context $ctx, Session $session, callable $next): void;
}
```

**Terminal vs passthrough:** Middleware that never calls `$next($ctx, $session)`
is terminal — it ends the chain. `Spawn` and `BubbleTea` are terminal
middleware. `Logger`, `Auth`, `RateLimit`, and `Keepalive` are passthrough.

**Context propagation:** Every call to `$next` passes the same `Context`
instance (contexts are immutable — use `->withValue()` / `->withDeadline()` /
`->withCancelable()` to derive a new context and pass that to `$next`).

```php
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;

final class RequestIdMiddleware implements Middleware
{
    private string $requestId;

    public function __construct(string $requestId)
    {
        $this->requestId = $requestId;
    }

    public function handle(Context $ctx, Session $session, callable $next): void
    {
        // Derive a new context with requestId attached, pass to $next
        $next($ctx->withValue('requestId', $this->requestId), $session);
    }
}
```

### Transport interface

The `Transport` interface plugs into `Server::withTransport()` to change
how the middleware chain is executed:

```php
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;

interface Transport
{
    /**
     * Run the middleware stack against the session.
     *
     * Server::serve() builds a root Context via Context::background()
     * and passes it here. The transport is responsible for walking
     * the stack and calling each middleware's handle() method.
     */
    public function run(Context $ctx, Session $session, array $stack): void;
}
```

**Built-in transports:**

| Transport | When to use |
|-----------|-------------|
| `InProcessTransport` (default) | Spawning shells, editors, or any PTY-backed subprocess |
| `HostSshdTransport` | Mounting a SugarCraft Program inline without subprocess overhead |

**InProcessTransport** also implements `ChildSpawner`, a duck-typed seam
injected into `Spawn` and `Keepalive` middleware at stack-walk time so
those middleware don't need to be transport-aware:

```php
use SugarCraft\Wish\Transport\ChildSpawner;

interface ChildSpawner
{
    public function runChild(Session $session, array $cmd, ?array $env): void;
    public function setKeepaliveCallback(callable $cb): void;
}
```

### ChannelHandler interface

`InProcessTransport` dispatches SSH channel-level messages (RFC 4254:
pty-req, window-change, shell, exec, signal, env, break) to a
`ChannelHandler` rather than handling them inline. Implement the interface
to customise channel-level behaviour — replacing PTY allocation, signal
delivery, shell spawning, etc.

```php
use SugarCraft\Wish\Channel\ChannelHandler;
use SugarCraft\Wish\Channel\Msg\PtyReqMsg;
use SugarCraft\Wish\Channel\Msg\WindowChangeMsg;
use SugarCraft\Wish\Channel\Msg\ShellMsg;
use SugarCraft\Wish\Channel\Msg\ExecMsg;
use SugarCraft\Wish\Channel\Msg\SignalMsg;
use SugarCraft\Wish\Channel\Msg\EnvMsg;
use SugarCraft\Wish\Channel\Msg\BreakMsg;
use SugarCraft\Wish\Session;

interface ChannelHandler
{
    public function handlePtyReq(PtyReqMsg $msg, Session $session): void;
    public function handleWindowChange(WindowChangeMsg $msg, Session $session): void;
    public function handleShell(ShellMsg $msg, Session $session): void;
    public function handleExec(ExecMsg $msg, Session $session): void;
    public function handleSignal(SignalMsg $msg, Session $session): void;
    public function handleEnv(EnvMsg $msg, Session $session): void;
    public function handleBreak(BreakMsg $msg, Session $session): void;
}
```

**Built-in handler:** `DefaultChannelHandler` is the default implementation.
It tracks per-session PTY state (dims, env vars, pending command) and drives
a `ChildSpawner` when a shell or exec request arrives.

Pass a custom handler to `InProcessTransport`:

```php
use SugarCraft\Wish\Transport\InProcessTransport;

$transport = new InProcessTransport($ptySystem, new MyCustomChannelHandler());
Server::new()->withTransport($transport)->serve();
```

The `ChannelMsg` hierarchy:

| Message | Fields | When sent |
|---------|--------|-----------|
| `PtyReqMsg` | `wantPty`, `term`, `cols`, `rows`, `widthPx`, `heightPx` | SSH client requests/releases PTY |
| `WindowChangeMsg` | `cols`, `rows`, `widthPx`, `heightPx` | SSH client resizes terminal |
| `ShellMsg` | `wantShell`, `subsystem` | SSH client requests login shell |
| `ExecMsg` | `command` (raw string) | SSH client runs a command |
| `SignalMsg` | `signalName` | SSH client sends signal to process |
| `EnvMsg` | `name`, `value` | SSH client sets env var |
| `BreakMsg` | — | SSH client sends break |

---

## Backend conventions

### InProcessTransport (default)

- Allocates a `candy-pty` master/slave pair via `PtySystem`
- Spawns the user's command as a subprocess with `controllingTerminal: true`
- Pumps bytes between the supervisor's STDIN/STDOUT and the PTY master
- Forwards SIGWINCH to resize the PTY
- The terminal middleware (`Spawn`) calls `runChild()` on the injected
  `ChildSpawner` to drive the subprocess lifecycle

### HostSshdTransport (legacy)

- Runs all middleware inline in the supervisor process
- Mounts a SugarCraft `Program` directly on the supervisor's STDIN/STDOUT
- No subprocess, no PTY pump
- `BubbleTea` is the terminal middleware; it calls `$program->run()`

### Choosing a transport

| Requirement | Transport |
|-------------|-----------|
| Spawn shells / editors / PTY binaries | `InProcessTransport` (default) |
| Zero subprocess overhead | `HostSshdTransport` |
| Custom PTY backend (e.g., for testing) | `new InProcessTransport($customPtySystem)` |

---

## Context propagation

The root `Context` is created by `Server::serve()` via `Context::background()`
and passed to `Transport::run()`. Each middleware may:

1. **Inspect** — call `$ctx->done()` or `$ctx->err()` to check if the
   request has been cancelled or deadline-exceeded
2. **Derive** — call `$ctx->withValue()`, `$ctx->withDeadline()`, or
   `$ctx->withCancelable()` to create a child context
3. **Short-circuit** — return without calling `$next` to stop the chain

```php
public function handle(Context $ctx, Session $session, callable $next): void
{
    if ($ctx->done()) {
        // Context was cancelled or deadline passed — abort
        return;
    }

    // Derive a context with a request ID and 5-second deadline
    $derived = $ctx
        ->withValue('requestId', $this->generateId())
        ->withDeadline(new \DateTimeImmutable('+5 seconds'));

    $next($derived, $session);
}
```

---

## Testing your extension

**Unit-test a custom middleware** directly by instantiating it and calling
`handle()` with a synthetic `Context` + `Session` + a `$next` spy:

```php
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Session;

$called = false;
$next = function (Context $ctx, Session $s) use (&$called) {
    $called = true;
};

$middleware = new RequestIdMiddleware('req-123');
$middleware->handle(Context::background(), Session::fromEnvironment(), $next);

$this->assertTrue($called);
```

**Integration-test a custom Transport** by passing it to `Server::withTransport()`
and calling `serve()` with a synthetic `Session`:

```php
use SugarCraft\Wish\Server;
use SugarCraft\Wish\Transport\InMemoryTransport; // your test double

$server = Server::new()
    ->withTransport(new InMemoryTransport())
    ->use(new Logger())
    ->use(new MyCustomMiddleware());

$server->serve($mySyntheticSession);
```

---

## Versioning policy

| Surface | Stability |
|---------|-----------|
| `Server`, `Session`, `Context`, `Middleware` | Stable |
| `Transport` interface, `ChildSpawner` interface | Stable |
| `ChannelHandler` interface, `ChannelMsg` base | Stable |
| `CancellationException`, `DeadlineExceededException` | Stable |
| `InProcessTransport`, `HostSshdTransport` internals | `@internal` |
| `Transport::run()` default implementation details | `@internal` |
| Cassette / state file format (RateLimit buckets) | Additive only — new optional fields are backwards-compatible |
