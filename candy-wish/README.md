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

| Middleware    | Transport       | Purpose                                                                            |
|---------------|-----------------|------------------------------------------------------------------------------------|
| `Logger`      | both            | One-line JSON event at session start + end, with elapsed time and connection meta. |
| `Auth`        | both            | Username allowlist, public-key fingerprint allowlist (or both).                    |
| `RateLimit`   | both            | Per-IP token-bucket persisted to a JSON state file with `flock(LOCK_EX)`.          |
| `Spawn`       | InProcess only  | Terminal — spawns a child cmd in a candy-pty controlled by the supervisor.         |
| `BubbleTea`   | HostSshd only   | Terminal — mounts a SugarCraft Program inline reading STDIN, writing STDOUT.       |

You can write your own — implement `SugarCraft\Wish\Middleware`:

```php
final class HelloBanner implements Middleware
{
    public function handle(Session $s, callable $next): void
    {
        echo "Welcome, {$s->user}!\n";
        $next($s);
    }
}
```

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

## ext-ssh2

The PECL `ssh2` extension is optional and used only if you want a middleware that opens *outbound* SSH connections from inside the session (e.g. SFTP file pickers, remote-control agents). Standard server-side use does not require it.

## Status

Phase 9+ — first cut. Five middleware classes, 19 tests / 65 assertions, ready for v0 deployment.

See [`examples/hello-server.php`](examples/hello-server.php) for a runnable banner-only stack you can ForceCommand against.
