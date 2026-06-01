<img src=".assets/icon.png" alt="candy-wish" width="160" align="right">

# CandyWish

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-wish)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-wish)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-wish?label=packagist)](https://packagist.org/packages/sugarcraft/candy-wish)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


PHP port of [`charmbracelet/wish`](https://github.com/charmbracelet/wish) — an SSH server middleware framework that lets you build TUIs anyone can `ssh user@host` to run.
```sh
composer require sugarcraft/candy-wish
```

## Shared foundations

CandyWish uses **candy-palette** for terminal capability probing. Call
`\SugarCraft\Palette\Probe\TerminalProbe::run()` to detect color support,
Sixel, HalfBlock, and other terminal capabilities — do not call `getenv()`
or read terminfo directly. The probe is used by UI components that need to
adapt rendering to the client's feature set.

## Architecture

CandyWish leans on the host's OpenSSH daemon rather than implementing the SSH wire protocol from scratch. Each SSH connection forks a fresh PHP process under `sshd` (via `ForceCommand`). What that PHP process does internally depends on the active **transport**:

### `InProcessTransport` (default)

```
[client] ─ssh─▶ [sshd] ─ForceCommand──▶ [php supervisor] ──▶ [middleware stack]
                                              │                    │
                                              └─pump bytes──┐      └─Spawn middleware
                                                            │              │
                                                            ▼              ▼
                                           [candy-pty master ◀──── slave / inner cmd]
                                                            (bash, vim, custom binary)
```

The supervisor allocates a `candy-pty` master/slave pair, spawns the user's cmd as a subprocess with full controlling-terminal semantics (Ctrl+C → SIGINT, SIGWINCH-driven resize, job control), and pumps bytes between the supervisor's STDIN/STDOUT (= sshd's PTY slave) and the candy-pty master. The terminal middleware is `Spawn`, which produces the cmd from the Session.

### `HostSshdTransport` (legacy, opt-in)

```
[client] ─ssh─▶ [sshd] ─ForceCommand──▶ [php supervisor] ──▶ [middleware stack] ──▶ [SugarCraft Program reading STDIN, writing STDOUT]
```

The pre-PTY-upgrade architecture: middleware run inline in the supervisor, and the terminal middleware (`BubbleTea`) mounts a SugarCraft `Program` directly on the supervisor's STDIN/STDOUT. Pin via `Server::new()->withTransport(new HostSshdTransport())`. Use this if your existing entry script reads STDIN/echoes STDOUT directly without a subprocess.

### Picking a transport

- **`InProcessTransport`** when you want to spawn arbitrary shells (`bash -i`, `zsh`, `fish`), editors (`vim`, `less`), or compiled TUI binaries — anything that needs a controlling terminal. Subprocess overhead per connection (~50-200ms PHP cold start), but full PTY semantics.
- **`HostSshdTransport`** when your TUI is a SugarCraft `Program` and you want zero subprocess overhead, or when you have an inline-STDIN-reading middleware (banner-style). No subprocess, but no controlling-terminal isolation.

## Quickstart

### 1. Configure sshd

Add to `/etc/ssh/sshd_config.d/wish.conf`:

```
Match User wishuser
    ForceCommand /usr/bin/php /opt/wish/server.php
    AllowTcpForwarding no
    PermitTTY yes
    X11Forwarding no
```

Then `systemctl reload sshd`.

### 2. Write the entry script

**InProcessTransport (default) — spawn an interactive shell:**

```php
<?php // /opt/wish/server.php
require '/opt/wish/vendor/autoload.php';

use SugarCraft\Wish\Server;
use SugarCraft\Wish\Middleware\Logger;
use SugarCraft\Wish\Middleware\Auth;
use SugarCraft\Wish\Middleware\RateLimit;
use SugarCraft\Wish\Middleware\Spawn;
use SugarCraft\Wish\Session;

Server::new()
    ->use(new Logger('/var/log/wish.jsonl'))
    ->use(new RateLimit('/var/lib/wish/buckets.json', burst: 5, ratePerSec: 0.5))
    ->use(new Auth(users: ['alice', 'bob']))
    ->use(new Spawn(fn (Session $s) => [
        'cmd' => ['/bin/bash', '-l'],
        'env' => [
            'TERM' => $s->term, 'USER' => $s->user, 'HOME' => "/home/{$s->user}",
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
        ],
    ]))
    ->serve();
```

**HostSshdTransport (legacy) — mount a SugarCraft Program inline:**

```php
<?php // /opt/wish/server.php
require '/opt/wish/vendor/autoload.php';

use SugarCraft\Wish\Server;
use SugarCraft\Wish\Middleware\Logger;
use SugarCraft\Wish\Middleware\Auth;
use SugarCraft\Wish\Middleware\RateLimit;
use SugarCraft\Wish\Middleware\BubbleTea;
use SugarCraft\Wish\Transport\HostSshdTransport;

Server::new()
    ->withTransport(new HostSshdTransport())
    ->use(new Logger('/var/log/wish.jsonl'))
    ->use(new RateLimit('/var/lib/wish/buckets.json', burst: 5, ratePerSec: 0.5))
    ->use(new Auth(users: ['alice', 'bob']))
    ->use(new BubbleTea(fn ($session) => new MyApp($session)))
    ->serve();
```

### 3. Connect

```
ssh wishuser@your-host
```

## Middleware

| Middleware           | Transport       | Purpose                                                                                              |
|----------------------|-----------------|------------------------------------------------------------------------------------------------------|
| `Logger`             | both            | One-line JSON event at session start + end, with elapsed time and connection meta.                  |
| `Auth`               | both            | Username allowlist, public-key fingerprint allowlist (or both).                                       |
| `PasswordAuth`       | both            | Validates user+password against a caller-supplied callback (`SSH_PASSWORD` env var).                 |
| `CertificateAuth`    | both            | Validates X.509 peer certificate (`SSL_CLIENT_CERT` / `SSH_CLIENT_CERT` env vars).                   |
| `AuthMethods`        | both            | Declares accepted auth methods; writes `SSH_AUTH_METHODS` banner to STDOUT; stores list in Context. |
| `KeyboardInteractive`| both            | Challenge-response — writes prompts to STDOUT, reads responses from STDIN (RFC 4256).                |
| `RateLimit`          | both            | Per-IP token-bucket persisted to a JSON state file with `flock(LOCK_EX)`.                            |
| `Keepalive`          | both            | Sends SSH-level keepalive messages at a configurable interval.                                      |
| `Spawn`              | InProcess only  | Terminal — spawns a child cmd in a candy-pty controlled by the supervisor.                          |
| `BubbleTea`          | HostSshd only   | Terminal — mounts a SugarCraft Program inline reading STDIN, writing STDOUT.                         |
| `Subsystem`          | both            | Terminal — parses `subsystem <name>` from `Session::command`, dispatches to a registered `SubsystemHandler`. Non-subsystem requests pass through to `$next`. |
| `AsyncMiddleware`    | both            | Abstract base for middleware that needs async I/O (LDAP, OAuth, database auth) — return a `PromiseInterface` from `handleAsync()`. The transport waits for the promise to settle before continuing the chain. |

All middleware receives a {@see Context} as the first argument, along with
the {@see Session} and a `$next` continuation. Implement `SugarCraft\Wish\Middleware`:

```php
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;

final class HelloBanner implements Middleware
{
    public function handle(Context $ctx, Session $s, callable $next): void
    {
        echo "Welcome, {$s->user}!\n";
        $next($ctx, $s);
    }
}
```

## Async middleware

Middleware `handle()` may return `void` (synchronous) or a
`\React\Promise\PromiseInterface`. The transport waits for the promise
to settle before continuing the chain, enabling async back-ends like
LDAP, OAuth, or database authentication.

Extend `SugarCraft\Wish\Middleware\AsyncMiddleware` to implement async
middleware. Override `handleAsync()` to perform async work and return
a promise; resolve the promise (or let it reject) to control whether
the chain continues.

```php
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware\AsyncMiddleware;
use SugarCraft\Wish\Session;
use React\Promise\PromiseInterface;

final class LdapAuth extends AsyncMiddleware
{
    protected function handleAsync(Context $ctx, Session $session, callable $next): PromiseInterface
    {
        return $this->ldap->verify($session->user)->then(
            fn () => $next($ctx, $session),
            fn (\Throwable $e) => throw new AuthFailedException($e->getMessage()),
        );
    }
}
```

The promise returned by `handleAsync()` resolves when async work is
done and the chain should proceed to `$next`; rejects to short-circuit
the chain. The 30-second timeout is enforced by
`AsyncMiddleware::await()`.

## Session metadata

`Session::fromEnvironment()` reads the standard sshd-supplied environment:

```php
$s->user;        // 'alice'
$s->clientHost;  // '203.0.113.7'
$s->clientPort;  // 54321
$s->term;        // 'xterm-256color'
$s->cols;        // 120
$s->rows;        // 40
$s->tty;         // '/dev/pts/3'   (null when non-interactive)
$s->command;     // SSH_ORIGINAL_COMMAND if set
$s->isInteractive();
$s->toLogContext();
```

After the SSH handshake completes, transports call `withProtocolMetadata()`
to populate protocol-level fields:

```php
$s->sessionId;        // SSH session ID (hex string)
$s->authMethod;       // 'publickey' | 'password' | 'keyboard-interactive' | ...
$s->keyFingerprint;   // SHA256 host-key fingerprint of the connected client
$s->clientVersion;    // SSH client version string (e.g. 'SSH-2.0-OpenSSH_9.0')
$s->serverVersion;    // SSH server version string (e.g. 'SSH-2.0-OpenSSH_9.0')

// Build a new Session with protocol metadata attached
$s = $s->withProtocolMetadata(
    sessionId:       $sessionId,
    authMethod:      $authMethod,
    keyFingerprint:  $keyFingerprint,
    clientVersion:   $clientVersion,
    serverVersion:   $serverVersion,
);
```

## Context propagation

Every request starts with a root {@see Context} created by `Context::background()`.
The context is immutable — each `with*()` method returns a new derived
context that forms a parent chain. Middleware can attach key-value metadata
via `withValue()`, set a deadline via `withDeadline()`, or make the context
cancellable via `withCancelable()`. The terminal middleware (Spawn /
BubbleTea) never call `$next`, short-circuiting the chain.

| Context method | What it does |
|---------------|-------------|
| `Context::background()` | Root context — never done, no values, not cancelable |
| `->withValue(string $k, mixed $v)` | Return a new context with `$k → $v` attached |
| `->withDeadline(\DateTimeImmutable)` | Return a new cancelable context that is done when the deadline passes |
| `->withCancelable()` | Return a new cancelable context (no deadline; must call `->cancel()` explicitly) |
| `->cancel(?\Throwable $reason)` | Mark the context (and all derived contexts) as cancelled |
| `->done()` | Returns `true` when cancelled or deadline-exceeded |
| `->err()` | Returns the cancellation error or `DeadlineExceededException` / `CancellationException` |
| `->value(string $k)` | Walk the parent chain looking for `$k`; returns `null` if not found |

```php
use SugarCraft\Wish\Context;

// Derive a cancelable context with a 30-second deadline
$ctx = Context::background()
    ->withValue('requestId', $uuid)
    ->withDeadline(new \DateTimeImmutable('+30 seconds'));

if ($ctx->done()) {
    throw $ctx->err(); // DeadlineExceededException or CancellationException
}
```

## Exceptions

| Exception | When it's thrown |
|-----------|-----------------|
| `CancellationException` | `Context->cancel()` was called, or `done()` returns true with no deadline |
| `DeadlineExceededException` | The context deadline (`withDeadline()`) has passed |

Both extend `\RuntimeException`.

## ext-ssh2

The PECL `ssh2` extension is optional and used only if you want a middleware that opens *outbound* SSH connections from inside the session (e.g. SFTP file pickers, remote-control agents). Standard server-side use does not require it.

## Channel handler (InProcessTransport)

The `InProcessTransport` dispatches SSH channel-level messages through a
`ChannelHandler` rather than handling them inline. This lets you replace the
default PTY/shell wiring with a custom implementation.

| Class | Purpose |
|-------|---------|
| `ChannelHandler` | Interface — implement to handle pty-req, window-change, shell, exec, signal, env, break |
| `ChannelMsg` | Abstract base for all channel messages (RFC 4254) |
| `DefaultChannelHandler` | Default impl — tracks PTY state, env vars, cols/rows, drives `ChildSpawner` on shell/exec |
| `PtyReqMsg` | `wantPty`, `term`, `cols`, `rows`, `widthPx`, `heightPx` |
| `WindowChangeMsg` | `cols`, `rows`, `widthPx`, `heightPx` |
| `ShellMsg` | `wantShell`, `subsystem` |
| `ExecMsg` | `command` (raw string — parsed by `DefaultChannelHandler::parseCommandString()`) |
| `SignalMsg` | `signalName` |
| `EnvMsg` | `name`, `value` |
| `BreakMsg` | Break request (no fields) |

```php
use SugarCraft\Wish\Channel\ChannelHandler;
use SugarCraft\Wish\Channel\ChannelMsg;
use SugarCraft\Wish\Channel\Msg\PtyReqMsg;
use SugarCraft\Wish\Channel\Msg\WindowChangeMsg;
use SugarCraft\Wish\Channel\Msg\ShellMsg;
use SugarCraft\Wish\Channel\Msg\ExecMsg;
use SugarCraft\Wish\Channel\Msg\SignalMsg;
use SugarCraft\Wish\Channel\Msg\EnvMsg;
use SugarCraft\Wish\Channel\Msg\BreakMsg;
use SugarCraft\Wish\Session;

final class DebugChannelHandler implements ChannelHandler
{
    public function handlePtyReq(PtyReqMsg $msg, Session $session): void
    {
        fwrite(STDERR, "pty-req: wantPty={$msg->wantPty} cols={$msg->cols} rows={$msg->rows}\n");
    }

    public function handleWindowChange(WindowChangeMsg $msg, Session $session): void
    {
        fwrite(STDERR, "window-change: cols={$msg->cols} rows={$msg->rows}\n");
    }

    public function handleShell(ShellMsg $msg, Session $session): void
    {
        fwrite(STDERR, "shell: wantShell={$msg->wantShell}\n");
    }

    public function handleExec(ExecMsg $msg, Session $session): void
    {
        fwrite(STDERR, "exec: {$msg->command}\n");
    }

    public function handleSignal(SignalMsg $msg, Session $session): void
    {
        fwrite(STDERR, "signal: {$msg->signalName}\n");
    }

    public function handleEnv(EnvMsg $msg, Session $session): void
    {
        fwrite(STDERR, "env: {$msg->name}={$msg->value}\n");
    }

    public function handleBreak(BreakMsg $msg, Session $session): void
    {
        fwrite(STDERR, "break\n");
    }
}

// Pass to InProcessTransport
new InProcessTransport($ptySystem, new DebugChannelHandler());
```

## Subsystem middleware (InProcessTransport)

SSH clients can request a named subsystem by sending `subsystem <name>` as
the original command. The `Subsystem` middleware parses this prefix, looks up
a registered handler, invokes it, and stops the chain — subsystem handlers
are terminal by design.

| Class | Purpose |
|-------|---------|
| `Subsystem` | Middleware — parses `subsystem <name>`, dispatches to registered handler |
| `SubsystemHandler` | Interface — implement `handle(Context, Session): void` for a named subsystem |
| `SftpStub` | Example impl — stub demonstrating wiring; not a real SFTP server |

```php
use SugarCraft\Wish\Middleware\Subsystem;
use SugarCraft\Wish\Middleware\Subsystem\SftpStub;

$subsystem = new Subsystem();
$subsystem->register('sftp', new SftpStub());

Server::new()
    ->use(new Logger('/var/log/wish.jsonl'))
    ->use(new Auth(['alice', 'bob']))
    ->use($subsystem)  // handles subsystem sftp; others pass through to Spawn
    ->use(new Spawn(fn (Session $s) => ['cmd' => ['/bin/bash', '-l']]))
    ->serve();
```

A production SFTP implementation would implement `SubsystemHandler` to speak
the SFTP protocol over the session's stdin/stdout after `Subsystem`
extracts the name and dispatches.

## Status

Phase 9+ — with Context propagation + ChannelHandler dispatch. Seven middleware classes, ChannelHandler/ChannelMsg + 7 message classes, 25+ tests / 80+ assertions, ready for v0 deployment.

See [`examples/hello-server.php`](examples/hello-server.php) for a runnable banner-only stack you can ForceCommand against.
