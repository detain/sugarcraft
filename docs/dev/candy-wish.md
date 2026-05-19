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
### Auth middleware extension points

The `Middleware\Auth\*` classes provide pluggable SSH authentication:

| Class | What it does | Seams for custom logic |
|-------|--------------|-----------------------|
| `PasswordAuth` | Validates `(user, password)` against a callback; reads `SSH_PASSWORD` env var | Inject any `callable(string, string): bool` |
| `CertificateAuth` | Validates X.509 PEM cert from `SSL_CLIENT_CERT` / `SSH_CLIENT_CERT` / `CERTIFICATE` env vars | Inject any `callable(string, Session): bool`; `$required` flag controls behaviour when no cert is present |
| `AuthMethods` | Writes `SSH_AUTH_METHODS <list>` banner to STDOUT and stores the method list in Context under `auth.methods` | `AuthMethods::fromContext($ctx)` lets downstream middleware inspect which method succeeded |
| `KeyboardInteractive` | Sends challenge prompts to STDOUT, reads newline-delimited responses from STDIN (RFC 4256 format) | Inject `callable(list<string>): bool|null` to validate responses |

**AuthMethods usage pattern:**

```php
use SugarCraft\Wish\Middleware\Auth\AuthMethods;
use SugarCraft\Wish\Middleware\Auth\PasswordAuth;
use SugarCraft\Wish\Middleware\Auth\KeyboardInteractive;

$server = Server::new()
    // Declare what the server accepts — runs early, before any credential checks
    ->use(new AuthMethods(['publickey', 'password', 'keyboard-interactive']))
    // AuthMethods stores the list in Context so downstream middleware can read it
    ->use(new PasswordAuth(fn ($user, $pw) => verify($user, $pw)))
    ->use(new KeyboardInteractive([
        ['prompt' => 'OTP Code: ', 'echo' => false],
    ], fn ($responses) => verifyTotp($responses[0])))
    ->use(new Spawn(...));
```

**Note:** `AuthMethods` writes to STDOUT and `KeyboardInteractive` reads from STDIN — these are the only middleware that perform I/O before the terminal middleware. Both are safe under either transport, but be aware that in `InProcessTransport` STDIN is the sshd PTY slave (not the caller's terminal) so this I/O is connection-scoped.

### Subsystem extension point

The `Subsystem` middleware + `SubsystemHandler` interface let you implement
named SSH subsystems (RFC 4254 §6.5 — e.g. `subsystem sftp`):

```php
use SugarCraft\Wish\Middleware\Subsystem;
use SugarCraft\Wish\Middleware\Subsystem\SubsystemHandler;
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Session;

final class SftpSubsystem implements SubsystemHandler
{
    public function handle(Context $ctx, Session $session): void
    {
        // Speak SFTP over $session->tty stdin/stdout
    }
}

$subsystem = new Subsystem();
$subsystem->register('sftp', new SftpSubsystem());

Server::new()
    ->use($subsystem)  // terminal — stops the chain when subsystem request matches
    ->use(new Spawn(fn (Session $s) => ['cmd' => ['/bin/bash', '-l']]))
    ->serve();
```

`Subsystem` is terminal: it calls the registered handler and does **not**
invoke `$next`. Non-subsystem commands (no `subsystem ` prefix, or no handler
registered for the requested name) pass through to `$next` unchanged — so
it can safely sit before `Spawn` in the stack.

| Class | Role |
|-------|------|
| `Subsystem` | Middleware — parses `$session->command`, dispatches to registered handler |
| `SubsystemHandler` | Interface — implement `handle(Context, Session): void` |
| `SftpStub` | Example stub impl — not a real SFTP server |

| Cassette / state file format (RateLimit buckets) | Additive only — new optional fields are backwards-compatible |
