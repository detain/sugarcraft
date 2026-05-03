<img src=".assets/icon.png" alt="candy-wish" width="160" align="right">

# CandyWish

PHP port of [`charmbracelet/wish`](https://github.com/charmbracelet/wish) — an SSH server middleware framework that lets you build TUIs anyone can `ssh user@host` to run.

## Architecture

CandyWish leans on the host's OpenSSH daemon rather than implementing the SSH wire protocol from scratch. The deployment shape is:

```
[client] ─ssh─▶ [sshd] ─ForceCommand──▶ [php server.php] ──▶ [middleware stack] ──▶ [CandyCore Program]
```

Each connection forks a fresh PHP process under `sshd`. The PHP entry script builds a `Server`, registers middleware, calls `serve()`, and returns when the user disconnects. This trades implementing SSH (key exchange, ciphers, host keys, fail2ban hooks, audit logs) for delegating it to the production-grade implementation already on every server.

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

```php
<?php // /opt/wish/server.php
require '/opt/wish/vendor/autoload.php';

use CandyCore\Wish\Server;
use CandyCore\Wish\Middleware\Logger;
use CandyCore\Wish\Middleware\Auth;
use CandyCore\Wish\Middleware\RateLimit;
use CandyCore\Wish\Middleware\BubbleTea;

Server::new()
    ->use(new Logger('/var/log/wish.jsonl'))
    ->use(new RateLimit('/var/lib/wish/buckets.json', burst: 5, ratePerSec: 0.5))
    ->use(new Auth(users: ['alice', 'bob']))
    ->use(new BubbleTea(fn($session) => new MyApp($session)))
    ->serve();
```

### 3. Connect

```
ssh wishuser@your-host
```

## Middleware

| Middleware    | Purpose                                                                            |
|---------------|------------------------------------------------------------------------------------|
| `Logger`      | One-line JSON event at session start + end, with elapsed time and connection meta. |
| `Auth`        | Username allowlist, public-key fingerprint allowlist (or both).                    |
| `RateLimit`   | Per-IP token-bucket persisted to a JSON state file with `flock(LOCK_EX)`.          |
| `BubbleTea`   | Terminal middleware. Mounts a CandyCore Program for the connected user.            |

You can write your own — implement `CandyCore\Wish\Middleware`:

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
