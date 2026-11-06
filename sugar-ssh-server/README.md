<img src=".assets/icon.png" alt="sugar-ssh-server" width="160" align="right">

# SugarSshServer

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-ssh-server)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-ssh-server)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-ssh-server?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-ssh-server)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

> Formerly published as `sugarcraft/candy-wish` (renamed 2026-10). <!-- @rename-keep -->
>
> Namespace moved from `SugarCraft\Wish\` to `SugarCraft\SshServer\`. <!-- @rename-keep -->


sugar-ssh-server — an SSH server middleware framework for PHP 8.3+ that lets you
build TUIs anyone can `ssh user@host` to run.
```sh
composer require sugarcraft/sugar-ssh-server
```

## Runtime requirements

- **PHP 8.3+** — declared in `composer.json`.
- A **POSIX host** (Linux or macOS) — sugar-ssh-server's default transport builds on
  sugar-pty's POSIX PTY layer.
- **OpenSSH `sshd` fronts the wire.** sugar-ssh-server does not implement the SSH
  transport protocol; each connection runs the middleware stack in a fresh PHP
  process spawned by `sshd` via `ForceCommand` (or a `command="..."` line in
  `authorized_keys`). Authentication at the SSH layer (including RFC 4256
  keyboard-interactive over the network) is sshd's job; the bundled auth
  middlewares prompt on the session's own stdio.
- The **default `InProcessTransport`** (PTY supervisor) additionally requires:
  - **`ext-ffi`** — sugar-pty binds libc through FFI to allocate the PTY;
    without it the transport cannot run (and Windows is not a target).
  - **`ext-pcntl`** — forking and reaping the session child. `runChild()`
    raises a `PtyException` when pcntl is missing; SIGWINCH size forwarding
    alone degrades gracefully without it.
  - a usable **`/dev/ptmx`** — PTY allocation fails in restricted containers
    and on many shared hosts.
- The **legacy `HostSshdTransport`** runs the middleware chain inline against
  the PTY sshd already allocated — it needs none of the three above.
- **`ext-ssh2` is not used by this library at all** and the manifest does not
  suggest it (see [ext-ssh2](#ext-ssh2) below).

Tests that exercise the PTY stack gate themselves on these capabilities, so
the suite also passes on hosts without them (the default transport is then
simply not covered).

## Shared foundations

SugarSshServer builds on **sugar-pty** (the PTY supervisor `InProcessTransport`
spawns children through) and **sugar-core** (`Lang` i18n wrapper,
`AtomicJsonFile` persistence). It performs no terminal-capability probing of
its own — `Session::fromEnvironment()` reads SSH environment variables
(`TERM`, `SSH_CONNECTION`, …) purely as session *metadata*, never as a
feature sniff. Should capability probing ever be added, route it through a
terminal probe rather than raw `getenv()`/terminfo reads.

## Architecture

SugarSshServer leans on the host's OpenSSH daemon rather than implementing the SSH wire protocol from scratch. Each SSH connection forks a fresh PHP process under `sshd` (via `ForceCommand`). What that PHP process does internally depends on the active **transport**:

### `InProcessTransport` (default)

```
[client] ─ssh─▶ [sshd] ─ForceCommand──▶ [php supervisor] ──▶ [middleware stack]
                                              │                    │
                                              └─pump bytes──┐      └─Spawn middleware
                                                            │              │
                                                            ▼              ▼
                                           [sugar-pty master ◀──── slave / inner cmd]
                                                            (bash, vim, custom binary)
```

The supervisor allocates a `sugar-pty` master/slave pair, spawns the user's cmd as a subprocess with full controlling-terminal semantics (Ctrl+C → SIGINT, SIGWINCH-driven resize, job control), and pumps bytes between the supervisor's STDIN/STDOUT (= sshd's PTY slave) and the sugar-pty master. The terminal middleware is `Spawn`, which produces the cmd from the Session.

### `HostSshdTransport` (legacy, opt-in)

```
[client] ─ssh─▶ [sshd] ─ForceCommand──▶ [php supervisor] ──▶ [middleware stack] ──▶ [SugarCraft Program reading STDIN, writing STDOUT]
```

The pre-PTY-upgrade architecture: middleware run inline in the supervisor, and the terminal middleware (`BubbleTea`) mounts a SugarCraft `Program` directly on the supervisor's STDIN/STDOUT. Pin via `Server::new()->withTransport(new HostSshdTransport())`. Use this if your existing entry script reads STDIN/echoes STDOUT directly without a subprocess.

### Picking a transport

- **`InProcessTransport`** when you want to spawn arbitrary shells (`bash -i`, `zsh`, `fish`), editors (`vim`, `less`), or compiled TUI binaries — anything that needs a controlling terminal. Subprocess overhead per connection — every session forks a fresh child (the target program or `/bin/bash -l`, not PHP) — but full PTY semantics.
- **`HostSshdTransport`** when your TUI is a SugarCraft `Program` and you want zero subprocess overhead, or when you have an inline-STDIN-reading middleware (banner-style). No subprocess, but no controlling-terminal isolation.

### The byte pump needs a blocking STDOUT

`InProcessTransport::runChild()` flips two of its three streams to
non-blocking — the PTY master and STDIN — because the pump polls them with
`stream_select()`. It deliberately leaves STDOUT alone, and that is not an
oversight: the pump it delegates to (`sugar-pty`'s `PosixPump`) writes
whatever it reads from the master in a single `fwrite()` per chunk and
counts a short write as success. On the blocking pipe a ForceCommand child
inherits from sshd, a full buffer just slows the pump. On a stream you have
flipped non-blocking — an async host that took over its own stdio, for
example — the chunk beyond the pipe buffer is dropped on the floor and the
user sees their terminal output cut off with no error anywhere.

So: keep `$stdout` blocking when you call `runChild()`, and do not add the
missing `stream_set_blocking($stdout, false)` line. Retrying the unwritten
tail is `sugar-pty`'s job (buffering the remainder and re-arming on
writability); the note in `InProcessTransport::runChild()`'s docblock names
the upstream method that needs it. The admin guide records the same
constraint under
[Failure modes](../docs/admin/sugar-ssh-server.md#failure-modes).

## See also — Git serving (candy-git-server)

SugarSshServer is a general SSH middleware/session framework. For serving Git
repositories specifically — a `git://` daemon, the HTTP smart protocol, and a
`ForceCommand`-style SSH git-shell gate — see the sibling
[`candy-git-server`](../candy-git-server). Both libraries run under the host's OpenSSH
daemon; neither implements the SSH wire protocol itself.

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

Then `systemctl reload sshd`, and check that it took effect before you
depend on it:

```bash
ssh -o BatchMode=yes wishuser@host; echo "status=$?"
```

That runs your entry script, not your shell -- which is the point. At this
point in the quickstart the entry script does not exist yet (sections 2 and 3
provision it below), so a correct `Match` block lands on PHP's
"Could not open input file" and reports `255` here; `0` and `129` are what the
same probe answers once `server.php` exists. Any of those three means
`ForceCommand` reached the account (see [Exit
status](../docs/admin/sugar-ssh-server.md#exit-status), where a PHP failure is
likewise `255`, not `129`); a login that drops you
into an interactive shell means the `Match` block never applied, and every
assumption below it about rate limits and pairing is currently unenforced. Do
not write the probe as `ssh wishuser@host echo ok`: the argument is discarded
before PHP starts
([Healthcheck](../docs/admin/sugar-ssh-server.md#healthcheck)).

### 2. Provision the paths the stack writes

Both `Logger` and `RateLimit` write where you point them, and neither can
do it from a directory the forced-command user cannot touch. The example
below writes to `/var/log/wish.jsonl` and `/var/lib/wish/buckets.json`,
so as root:

```sh
install -d -m 0750 -o wishuser -g wishuser /var/lib/wish
install -d -m 0755 -o root -g adm         /var/log   # usually already there
touch /var/log/wish.jsonl && chown wishuser:adm /var/log/wish.jsonl
```

If you prefer not to hand-create anything, let systemd own it — the
service that runs your entry script (or a socket-activated wrapper) gets:

```
[Service]
StateDirectory=wish
LogsDirectory=wish
```

and the paths become `/var/lib/wish/state/buckets.json` and
`/var/log/wish/wish.jsonl`, both pre-created and owned by the service
user. `tmpfiles.d(5)` is the equivalent for a host without systemd.

What happens when you skip this, in the order you will notice it:

- `Logger` resolves its sink in its constructor, so an unwritable path
  throws `RuntimeException` **before** the first session: exit 255, and
  under `ForceCommand` the PHP trace rides the client's channel rather
  than a log line. Loud, but at the wrong recipient — check it at
  deploy time instead (below).
- `RateLimit` creates its directory `0700` and its files `0600` when it
  can. When it cannot, it announces on its `$stderr` stream — which is
  the client-visible channel unless you injected one — and, with the
  default `failClosed: false`, **lets the connection through**: your
  throttle is simply off, and nobody but the prober sees it.

A five-line preflight turns both into a deploy-time failure. Put it at
the top of the entry script (or in a `--preflight` mode you run from a
provisioning check):

```php
foreach (['/var/log/wish.jsonl', '/var/lib/wish/buckets.json'] as $path) {
    $dir = \dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        \fwrite(STDERR, "not writable: {$dir}\n");
        exit(1);
    }
    if (file_exists($path) && !is_writable($path)) {
        \fwrite(STDERR, "not writable: {$path}\n");
        exit(1);
    }
}

$logger = Logger::toFile('/var/log/wish.jsonl');
\fwrite(STDERR, 'sink: ' . $logger->target() . "\n");
```

Note that a *new* file is fine as long as its directory is writable:
`Logger` creates it `0600` and `RateLimit` publishes its state `0600`.
The preflight above only refuses what the process genuinely cannot open.

### 3. Write the entry script

**InProcessTransport (default) — spawn an interactive shell:**

```php
<?php // /opt/wish/server.php
require '/opt/wish/vendor/autoload.php';

use SugarCraft\SshServer\Server;
use SugarCraft\SshServer\Middleware\Logger;
use SugarCraft\SshServer\Middleware\Auth;
use SugarCraft\SshServer\Middleware\RateLimit;
use SugarCraft\SshServer\Middleware\Spawn;
use SugarCraft\SshServer\Session;

exit(Server::new()
    ->use(Logger::toFile('/var/log/wish.jsonl'))
    ->use(new RateLimit('/var/lib/wish/buckets.json', burst: 5, ratePerSec: 0.5))
    ->use(new Auth(users: ['alice', 'bob']))
    ->use(new Spawn(fn (Session $s) => [
        'cmd' => ['/bin/bash', '-l'],
        'env' => [
            'TERM' => $s->term, 'USER' => $s->user, 'HOME' => "/home/{$s->user}",
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
        ],
    ]))
    ->serve());
```

**HostSshdTransport (legacy) — mount a SugarCraft Program inline:**

```php
<?php // /opt/wish/server.php
require '/opt/wish/vendor/autoload.php';

use SugarCraft\SshServer\Server;
use SugarCraft\SshServer\Middleware\Logger;
use SugarCraft\SshServer\Middleware\Auth;
use SugarCraft\SshServer\Middleware\RateLimit;
use SugarCraft\SshServer\Middleware\BubbleTea;
use SugarCraft\SshServer\Transport\HostSshdTransport;

exit(Server::new()
    ->withTransport(new HostSshdTransport())
    ->use(Logger::toFile('/var/log/wish.jsonl'))
    ->use(new RateLimit('/var/lib/wish/buckets.json', burst: 5, ratePerSec: 0.5))
    ->use(new Auth(users: ['alice', 'bob']))
    ->use(new BubbleTea(fn ($session) => new MyApp($session)))
    ->serve());
```

Both examples `exit(...)` on `serve()` deliberately: that return value is
the session's answer -- the child's own exit code, `Server::EXIT_DENIED`
(`129`) when a gate refused and nothing ran, or `0` when the walk finished
with nothing to spawn. A script that calls `serve()` and falls off the end
of the file reports success to whichever cron entry or CI step asked.
[`docs/admin/sugar-ssh-server.md`](../docs/admin/sugar-ssh-server.md#exit-status)
tabulates the three.

### 4. Connect

```
ssh wishuser@your-host
```

## Middleware

| Middleware           | Transport       | Purpose                                                                                              |
|----------------------|-----------------|------------------------------------------------------------------------------------------------------|
| `Logger`             | both            | One-line JSON event at session start + end, with elapsed time and connection meta. Sink matters: `Logger::auto()` (and `new Logger()`) append to `$SSH_SERVER_LOG` when set, else `php://stderr` — which under `ForceCommand` **is the connecting client's extended-data channel**, not journald. Pass a file path (we create it `0600`; an existing file keeps its mode) or `Logger::toStream($handle)` you control. `target()` reports the choice. |
| `Auth`               | both            | Username allowlist, key-fingerprint allowlist, or the paired `userKeys` map (name => that name's keys). **The two flat lists are a cross product, not a pairing:** with `users` `['alice','bob']` and `keyFingerprints` `[alice-key, bob-key]`, bob presenting alice's key is admitted *as alice*. Use `userKeys` when the question is whose key this is (`[Auth::ANY_KEY]` admits the login with-or-without key evidence, that name only). An empty list arms nothing — `new Auth()` admits every session. |
| `PasswordAuth`       | both            | Compares a password from `SSH_PASSWORD` against a caller-supplied callback; a session that delivered no variable at all is refused before the validator is consulted. **Nothing in OpenSSH ever sets that variable**: a wrapper has to supply it out of band, and if you `AcceptEnv` it the value is peer-asserted rather than proven. Absent means denied, with a path-free notice. |
| `CertificateAuth`    | both            | Hands a certificate string from `CertificateAuth::ENV_KEYS` (`SSL_CLIENT_CERT`, `SSH_CLIENT_CERT`, `CERTIFICATE`) to a caller-supplied validator. **Stock OpenSSH exports none of them**, so unless your wrapper maps one in, the gate announces that it checked nothing -- refusing at `required: true`, forwarding at `required: false`, and saying so either way. |
| `AuthMethods`        | both            | Declares accepted auth methods and stores the list in Context (`auth.methods`, readable with `AuthMethods::fromContext()`). The `SSH_AUTH_METHODS` line on STDOUT is **opt-in** (`announce: true`) and off by default -- under `ForceCommand` stdout is the client's data channel, so that line is text in the user's terminal, not a protocol field. |
| `KeyboardInteractive`| both            | Prompts on STDOUT, answers read from STDIN. *Shaped* like RFC 4256, but it is not: no SSH messages exist here. `echo` is a label, not terminal state -- input stays visible (the exchange says so when any prompt asks for silence), each answer is capped at `MAX_RESPONSE_BYTES`, and there is no deadline but the stream you inject. |
| `RateLimit`          | both            | Per-IP (optionally per-username) token bucket persisted to a JSON state file. Writers serialise on a stable `<state-path>.lock` sidecar — never on the payload inode, which the atomic rename replaces — and refilled idle buckets are pruned on every publish. Default policy on a state error is **fail-open**; `penalize()` exists so a failed credential can drain a bucket faster than connection attempts alone, but **nothing in this library calls it** -- wire it into your own auth path. See `docs/admin/sugar-ssh-server.md`. |
| `Keepalive`          | InProcess only  | Installed by `Server::withKeepalive($seconds)`. When the pump has idled past the interval it writes a `\0` byte to the **child's stdin** through the PTY master. It is *not* an SSH-level keepalive — no wire messages exist here — a raw-mode TUI never echoes it, so nothing reaches the client, and under HostSshd it is a no-op: rely on sshd `ClientAliveInterval`. |
| `Spawn`              | InProcess only  | Terminal — spawns a child cmd in a sugar-pty controlled by the supervisor.                          |
| `BubbleTea`          | HostSshd only   | Terminal — mounts a SugarCraft Program inline reading STDIN, writing STDOUT.                         |
| `Subsystem`          | both            | Terminal — parses `subsystem <name>` from `Session::command`, dispatches to a registered `SubsystemHandler`. Non-subsystem requests pass through to `$next`. |
| `AsyncMiddleware`    | both            | Abstract base for middleware that needs async I/O (LDAP, OAuth, database auth) — return a `PromiseInterface` from `handleAsync()`. The transport waits for the promise to settle before continuing the chain. |

All middleware receives a {@see Context} as the first argument, along with
the {@see Session} and a `$next` continuation. Implement `SugarCraft\SshServer\Middleware`:

```php
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Session;

final class HelloBanner implements Middleware
{
    public function handle(Context $ctx, Session $s, callable $next): void
    {
        echo "Welcome, {$s->user}!\n";
        $next($ctx, $s);
    }
}
```

### Constructor options

Every shipped gate takes its knobs at construction, and several defaults are
security-relevant, so the signatures are tabulated once here rather than
inferred from examples. Names in **bold** are the ones that change behaviour
when left alone.

| Middleware | Constructor | Defaults worth knowing |
|---|---|---|
| `Logger` | `Logger::auto()`, `::toFile($path)`, `::toStream($r)`, `::toStderr()`; `new Logger($target)` still works | `auto()` appends to `$`**`SSH_SERVER_LOG`** when set, and to `php://stderr` otherwise -- which under `ForceCommand` is the connecting peer. A file this class creates is `0600`; an existing file keeps its mode. `target()` says which you got. |
| `Auth` | `new Auth($users, $keyFingerprints, $stderr, $fingerprintValidator, $userKeys)` | Empty **`$users` and `$keyFingerprints` arm nothing**: a bare `new Auth()` admits every session. `$userKeys` is the pairing surface (`[Auth::ANY_KEY]` = any key for that name); notices are sanitized and truncated at `Auth::MAX_ECHOED_BYTES` (128). |
| `PasswordAuth` | `new PasswordAuth($validate, $stderr)` | Reads `SSH_PASSWORD`, which nothing in OpenSSH ever sets. A session that delivered no variable is refused **before** `$validate` runs; an explicitly empty one reaches it. |
| `CertificateAuth` | `new CertificateAuth($validate, $required = true, $stderr)` | Probes `CertificateAuth::ENV_KEYS` (`SSL_CLIENT_CERT`, `SSH_CLIENT_CERT`, `CERTIFICATE`); stock OpenSSH exports none. **`$required = false` forwards** -- after announcing that it checked nothing. |
| `AuthMethods` | `new AuthMethods($methods, $stdout, $announce = false)` | Stores the list in `Context`. The `SSH_AUTH_METHODS` stdout line is **off by default**; `announce: true` opts in and owns the placement. |
| `KeyboardInteractive` | `new KeyboardInteractive($challenges, $validate, $name, $instruction, $stdout, $stdin, $stderr)` | **No `$validate` means any answer passes.** Answers capped at `KeyboardInteractive::MAX_RESPONSE_BYTES` (4096); `echo` is a label, not terminal state, and says so when a prompt asks for silence. No deadline but the stream you inject. |
| `RateLimit` | `new RateLimit($statePath, $burst = 5, $ratePerSec = 0.5, $stderr, $failClosed = false, $userBurst = null, $userRatePerSec = null)` | **`$failClosed = false`**: a lock or decode problem lets the connection through with a notice. State serialises on a `<state>.lock` sidecar; idle refilled buckets are pruned; `$userBurst` adds a per-username bucket. |
| `Keepalive` | `new Keepalive($intervalSeconds = 60)` | Idle `\0` into the child's stdin, not an SSH keepalive; no-op under `HostSshdTransport`. Installed for you by `Server::withKeepalive($seconds)`. |
| `Spawn` | `new Spawn($factory)` where `fn (Session): array{cmd: list<string>, env?: array}` returns the child command | Terminal. The factory's `cmd` is argv -- no shell is involved, and nothing here vetoes it. |
| `BubbleTea` | `new BubbleTea($factory)` where `fn (Session): object` builds the `Program` | The returned object must expose a `run()` method — anything else raises `RuntimeException` with the Lang key `bubbletea.bad_factory` when the middleware handles the session. Mounts a `Program` on the supervisor's own stdio, which is only sound under `HostSshdTransport`. Under the default `InProcessTransport` it throws `bubbletea.requires_host_sshd` -- there is no override flag; the alternatives are `Spawn` plus a wrapper script (`examples/run-program.php`) or the legacy transport. |
| `Subsystem` | `new Subsystem()` then `->register($name, $handler)` | Terminal for registered names. An **unregistered** name falls through on purpose, so `subsystem sftp` reaches `Spawn` and lands in a shell unless you register the name (or a rejecting handler). |
| `AsyncMiddleware` | abstract; subclass and implement `protected handleAsync($ctx, $session, $next): PromiseInterface` | The transport's single settle point drives your promise to completion -- never call `PromiseAwait::settle()` yourself, and never await from inside a loop you already run. |

See [Defaults that are open until you close them](#defaults-that-are-open-until-you-close-them)
for the four that most often ship open by accident.

## Defaults that are open until you close them

Four shipped defaults admit traffic. Each is a deliberate choice -- the
alternative is a middleware that locks everyone out when you forget an
argument -- but none of them is a security gate you can rely on by
accident. `tests/SecurityDefaultsTest.php` runs all four against the
table below.

| Middleware | Default that is open | What closes it |
| --- | --- | --- |
| `Auth` | `new Auth()` has no user list and no fingerprint list, so **every session passes**. | Pass `users`, `keyFingerprints`, or the pairing map `userKeys` (see below). |
| `KeyboardInteractive` | No `$validate` callable means **any answer authenticates**; the exchange is a form, not a check. | Pass a validator. There is no built-in PAM/password backend in this library. |
| `RateLimit` | `failClosed` is `false`, so an unreadable or locked state file **lets the connection through** with a notice. | Construct with `failClosed: true` to deny when state cannot be read. |
| `Server` | `serve()` runs exactly the middleware you `use()` -- **no auth is inserted for you** -- and a stack that runs out of middleware without spawning answers `0`. | `->use(new Auth(...))->use(new RateLimit(...))`, then `exit($server->serve())`: a gate that refuses now answers `Server::EXIT_DENIED` (`129`), a child reports its own code, and `0` means there was nothing to run. |

The docblocks on those four classes say the same thing in longer words, and
`docs/admin/sugar-ssh-server.md` describes the deployment posture. If you
skim only this README, skim this table.

## Sinks: who actually reads what you write

There is no server process to log into. The code runs as sshd's
forced command, so the streams it inherits belong to the *session*:
under `ForceCommand`, fd 1 is the client's data channel and fd 2 is
the client's extended-data channel — OpenSSH forwards both to the
person who just connected. Anything written there is user-facing,
on a path that may not even be authenticated yet. journald captures
what sshd itself writes, never what its child writes.

Consequences worth knowing before you deploy:

- `Logger` with no target appends to `$SSH_SERVER_LOG` if you set it
  (`Logger::auto()`), and falls back to stderr otherwise. A live
  panel wants the file branch or `Logger::toFile()` /
  `Logger::toStream()`; `target()` returns which one you got, so a
  boot line can say it out loud. Files this class creates are
  `0600` — the records carry usernames and client addresses.
- `Auth`, `RateLimit`, `KeyboardInteractive` and `CertificateAuth`
  take a `$stderr` stream. Left alone, their notices (including
  "fail-open, allowing") reach the prober. Inject a real operator
  sink there; the messages themselves carry no paths and no
  posture detail by design.
- `AuthMethods` records its declared methods in the Context and
  prints nothing. The optional `SSH_AUTH_METHODS` line (`announce:
  true`) is the one message here that *is* meant for the client —
  though a real client never parses it: `sshd` completed the auth
  exchange before this process started, so the honest surface for
  that message is sshd's own `Banner=`/MOTD, not your forced command.

For the fail-open/fail-closed posture of every gate, see
[Authentication: pairing a key to a name](#authentication-pairing-a-key-to-a-name)
and `docs/admin/sugar-ssh-server.md`.

## What sshd actually hands your middleware

There is no protocol conversation to inspect: the child learns about the
connection from its environment. That list is shorter than people expect,
and who fills each slot changes what you may trust in it.

| Variable | Who sets it | Read by | Trust |
| --- | --- | --- | --- |
| `USER`, `LOGNAME` | sshd, for the target account | `Session::fromEnvironment()` | Good -- sshd writes it for the account it launched; which account to ask for was the client's choice. |
| `SSH_CONNECTION` | sshd: `client-ip client-port server-ip server-port` | `Session::fromEnvironment()` | Good -- socket-derived, not peer-asserted. |
| `SSH_CLIENT` | sshd: `client-ip client-port server-port` (older, 3 fields) | `Session::fromEnvironment()` fallback | Good, but it has no server-IP slot: `fromEnvironment()` counts the fields and leaves `$serverHost` empty rather than storing the port in it. |
| `SSH_ORIGINAL_COMMAND` | sshd, when `ForceCommand` rewrote `command` `ssh host some command` | `Session::$command`, then your decoder | Good -- but see the [exec policy](#exec-policy-the-program-comes-from-the-peer): the program string is peer-chosen. |
| `SSH_TTY` | sshd when a PTY was allocated | `Session::$tty` | Good. |
| `TERM` | sshd, from the client's pty-request | `Session::$term` | Peer-asserted. Shape it before it reaches a terminfo lookup. |
| `LANG`, `LC_*` | only if the client sends them **and** `AcceptEnv` allows it | `Session::$lang` (`LANG` only) | Peer-asserted, for `LANG` alone. `LC_*` reach nothing in this library: they matter solely to sugar-core's `T::detect()`, which nothing here calls. This is how a visitor picks your UI language; see the i18n note below. |
| `COLUMNS`, `LINES` | not by sshd -- the window size lives on the tty (this transport ioctls it, or accepts `withSizeProvider()`) | `Session::$cols/$rows` | Whatever the transport measured -- with one leak: a peer that smuggles them through `AcceptEnv` reaches `Session::fromEnvironment()`, which seeds `declaredSize` from the env value (80/24 defaults). |
| `SSH_USER_AUTH` | sshd **only** with `ExposeAuthInfo` (see `sshd_config(5)` on your release): the authorized_keys line that matched | private `Auth::fingerprint()` | Good -- server-side, since sshd picked the line. |
| `SSH_USER_KEY_FINGERPRINT` | not sshd; a wrapper that parses `SSH_USER_AUTH`, or your own `AuthorizedKeysCommand` | private `Auth::fingerprint()` | Depends entirely on your wrapper. |
| `KEY_FINGERPRINT` | same producers — the shorter alternate name the same gate reads | private `Auth::fingerprint()` | Depends entirely on your wrapper. |
| `SSH_PASSWORD` | **nothing in OpenSSH ever sets it** | `PasswordAuth` | Operator-injected out of band, or peer-asserted through `AcceptEnv` -- never a credential proof. |
| `SSL_CLIENT_CERT`, `SSH_CLIENT_CERT`, `CERTIFICATE` | nothing; the first is Apache mod_ssl, the others are aspirational | `CertificateAuth::ENV_KEYS` | If your wrapper does not export one, the gate sees nothing. |
| `SSH_CLIENT_VERSION`, `SSH_SERVER_VERSION` | nothing in stock OpenSSH | `Session::$clientVersion/$serverVersion` | Usually empty; do not treat them as audit columns. |

Two consequences deserve the front of your mind:

- **Pairing without delivery is a lockout.** `Auth`'s `userKeys` map (and
  a non-empty `keyFingerprints` list) rejects a session that presents no
  fingerprint. With `ExposeAuthInfo` off, no fingerprint arrives, so
  every login is refused. Turn the delivery on -- or keep the key lists
  empty and authenticate by name only -- and prove the shape with a
  `ssh -v` round trip before you enable it on a live account.
- **Peer-asserted slots are input, not fact.** Anything that arrived via
  `AcceptEnv` is a string the client chose. The middleware in this
  library never reads an `AcceptEnv` name as an authority; `Auth` uses
  `hash_equals()` on fingerprints, and the strings that do land are run
  through a private sanitizer (public cap: `Auth::MAX_ECHOED_BYTES`) before they
  are echoed anywhere.

`Session::fromEnvironment()` takes no arguments and reads the process
environment; nothing here offers a language-selection API yet, so
`Session::$lang` is today whatever `LANG` happened to be.

## Authentication: pairing a key to a name

`Auth` takes three allowlist surfaces, and they answer different
questions:

```php
use SugarCraft\SshServer\Middleware\Auth;

// WRONG for a multi-user box: two independent flat lists. Every user
// listed in `users` may log in with ANY key listed in
// `keyFingerprints` — bob with alice's key is admitted AS alice.
new Auth(users: ['alice', 'bob'], keyFingerprints: ['SHA256:aaa', 'SHA256:bbb']);

// RIGHT: the pairing map. Each name owns its own keys, and membership
// is checked with hash_equals() over the whole list.
new Auth(userKeys: [
    'alice' => ['SHA256:aaa'],
    'bob'   => ['SHA256:bbb'],
]);

// A name with no key evidence in this process (the host sshd did the
// crypto and you are only restricting WHO may use the forced command):
new Auth(userKeys: ['alice' => [Auth::ANY_KEY], 'bob' => ['SHA256:bbb']]);
```

With `userKeys` non-empty it is authoritative: a name absent from the
map is refused even if its key appears nowhere, and a key listed under
someone else never admits you. `users` and `keyFingerprints`, when also
supplied, apply as *additional* gates — combining surfaces can only
narrow, never widen.

Every list is opt-in by being non-empty. `new Auth()` — no arguments —
performs no check at all and admits every session; that is the
start-simple default, not a deny-by-default gate. See the admin guide
(`docs/admin/sugar-ssh-server.md`) for the deployment posture.

## Async middleware

Middleware `handle()` may return `void` (synchronous) or a
`\React\Promise\PromiseInterface`. The transport waits for the promise
to settle before continuing the chain, enabling async back-ends like
LDAP, OAuth, or database authentication.

Extend `SugarCraft\SshServer\Middleware\AsyncMiddleware` to implement async
middleware. Override `handleAsync()` to perform async work and return
a promise; resolve the promise (or let it reject) to control whether
the chain continues.

```php
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware\AsyncMiddleware;
use SugarCraft\SshServer\Session;
use React\Promise\PromiseInterface;

final class LdapAuth extends AsyncMiddleware
{
    public function __construct(private readonly \LdapConnection $ldap)
    {
    }

    protected function handleAsync(Context $ctx, Session $session, callable $next): PromiseInterface
    {
        return $this->ldap->verify($session->user)->then(
            fn () => $next($ctx, $session),
            fn (\Throwable $e) => throw new \RuntimeException('auth failed: ' . $e->getMessage(), 0, $e),
        );
    }
}
```

Pass it in when you build the chain: `->use(new LdapAuth($connection))`.

The promise returned by `handleAsync()` resolves when async work is
done and the chain should proceed to `$next`; rejects to short-circuit
the chain. The transport's single settle point
(`Transport\DispatchesMiddlewareStack::dispatch()` →
`PromiseAwait::settle()`) drives the loop until that promise is decided,
then hands control back: the answer, or the 30-second ceiling, ends the
wait — whichever comes first.

**Who owns the loop.** `settle()` calls `run()` and stops it as soon as
the await is decided, so exactly one thing drives the loop per phase.
The transports shipped here build the chain synchronously, so nothing is
running underneath and awaiting is safe. If *you* run an event loop in
the same process, do not call `settle()` (or dispatch a stack containing
async middleware) from inside a loop callback — the stop would end your
loop. Read `PromiseAwait`'s class docblock before wiring a custom
transport.

## Language: nothing selects it for you

Sixteen catalogues ship under `lang/`, and by default **none of them is
selected**. Nothing in this library calls `T::setLocale()`, so every notice —
the throttle's fail-open announcement, the exec refusal, the
`Unauthorized. (…)` line — is rendered from `lang/en.php`. Choosing a
language is a one-line decision at the top of your entry script:

```php
use SugarCraft\Core\I18n\T;

T::setLocale('de'); // once, before building the Server
```

Prefer pinning it per service to detecting it. `T::detect()` reads
`LC_ALL`, `LC_MESSAGES` and `LANG`, and `LANG` only reaches a `ForceCommand`
child when sshd's `AcceptEnv` lists it ([What sshd actually hands your
middleware](#what-sshd-actually-hands-your-middleware)) — so detection lets
the connecting peer choose the language your server answers in, beside the
name it also chose for itself. `Session::$lang` is that same peer-asserted
value: log it, do not obey it.

Coverage is uneven, and the suite keeps the list honest rather than letting
it grow quietly: eleven of the twenty-six keys are still English placeholders
in all fifteen translated locales (`ACCEPTED_UNTRANSLATED` in
`tests/LangRosterTest.php`; curing one means deleting it from the roster).

## Session metadata

`Session::fromEnvironment()` reads the standard sshd-supplied environment:

```php
$s->user;          // 'alice'
$s->clientHost;    // '203.0.113.7'
$s->clientPort;    // 54321
$s->term;          // 'xterm-256color'
$s->cols;          // 120
$s->rows;          // 40
$s->tty;          // '/dev/pts/3'   (null when non-interactive)
$s->command;      // SSH_ORIGINAL_COMMAND if set -- what the peer asked to run,
                  // which ForceCommand ignores unless you decode it
                  // (see the Exec policy section below)
$s->serverHost;   // '198.51.100.2' -- empty on the 3-field SSH_CLIENT fallback,
                  // which carries a server port and no server address
$s->serverPort;   // 22
$s->lang;         // 'en_US.UTF-8'  -- the peer's declared LANG; log it, never
                  // obey it (see [Language](#language-nothing-selects-it-for-you))
$s->isInteractive();
$s->toLogContext();
```

`$s->toLogContext()` is what the bundled `Logger` writes, and it carries nine
fields: the six above plus `session`, `auth_method`
and `key_fingerprint` (a dash stands for "not carried", so a line never loses
the authentication column silently).

There is no handshake for those to come from — this library speaks no SSH — so
`withProtocolMetadata()` is called by the transport on the way *into* the
stack, with values derived locally — and only by `InProcessTransport::run()`:
under `HostSshdTransport` nothing ever calls it, so the `session`,
`auth_method` and `key_fingerprint` columns are permanently `-` there:

```php
$s->sessionId;     // minted per process: bin2hex(random_bytes(16)), not a wire id
$s->authMethod;    // sniffed from SSH_USER_AUTH when a wrapper exported it
$s->keyFingerprint; // no producer in this library today — see the pairing note below
$s->clientVersion; // read from SSH_CLIENT_VERSION: stock OpenSSH exports neither
$s->serverVersion; // ...so both are usually empty and are not audit columns

// Build a new Session with protocol metadata attached
$s = $s->withProtocolMetadata(
    sessionId:       $sessionId,
    authMethod:      $authMethod,
    keyFingerprint:  $keyFingerprint,
    clientVersion:   $clientVersion,
    serverVersion:   $serverVersion,
);
```

Because `sessionId` is minted here, it identifies one process's worth of lines
to each other and nothing more: it will not match a `sshd` log line, a
bastion's record, or the same user's next login. Treat the two version fields
the same way — an empty one is the normal case, and a filled one means *your*
wrapper exported it (see [What sshd actually hands your
middleware](#what-sshd-actually-hands-your-middleware)).

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
use SugarCraft\SshServer\Context;

// Derive a cancelable context with a 30-second deadline
$ctx = Context::background()
    ->withValue('requestId', $uuid)
    ->withDeadline(new \DateTimeImmutable('+30 seconds'));

if ($ctx->done()) {
    throw $ctx->err(); // DeadlineExceededException or CancellationException
}
```

## Embedding this library

Four constraints that are invisible from the class list, learned from the
audit, and worth knowing before you wire this into a long-lived process.

**One session per process.** `Session::fromEnvironment()` reads
`getenv()`/`$_SERVER`, and that is where the peer's identity, the tty and any
injected credential live. Nothing in the stack carries those values through a
parameter, so a second concurrent session inside the same PHP process would
authenticate against the first one's environment. Run one process per
connection -- which is what a `ForceCommand` line gives you for free -- and
do not put this behind a worker pool that reuses a process across peers.

**Geometry arrives from `SIGWINCH`, or from you.** `runChild()` reads the
size once from the stream it is given and then follows `SIGWINCH`. There is
no live-resize call to make: if a resize reaches your process some other way
(a control message, a panel tick), supply a provider with
`withSizeProvider(callable $provider): self` and it will be polled at the
same points the signal path uses. The provider is consulted per pump idle
tick, not cached, so a stale closure is a live bug rather than a frozen one.

**The pump's knobs are not reachable from here.** `runChild()` builds
`PumpOptions::sshDefault()->withOnIdle($onIdle)` internally. sugar-pty's
options carry the real recorder, chunk size and `withPumpDeadline()` -- the
only true bound a pump can honour -- and this transport never exposes them,
so a session that needs one has to fork the pump loop itself. That is why the
admin guide's failure table says a hanging child hangs here, and why the
`Context::withDeadline()` in the section above is a value you carry, not an
enforcement: `done()` is consulted when the stack walks in, never inside the
byte loop.

**`ChildSpawner` is narrower than the class behind it.** The interface
`Spawn` type-hints is:

```php
public function runChild(Session $session, array $cmd, ?array $env = null): int;
```

`InProcessTransport::runChild()` accepts two more arguments -- `$stdin` and
`$stdout` -- and they are what make a testable, stream-injecting middleware
possible. Code holding the interface cannot pass them, so anything that wants
to redirect the child's streams must `instanceof InProcessTransport` and
narrow, or take the concrete class in its own constructor. Widening the
contract is a deliberate no for now: the fake in `SpawnTest` implements the
three-parameter form, and the interface exists so that fake needs no PTY.
`TransportAware` is the other half of the same story -- the transport calls
`setTransport()` on every aware middleware at walk time, before the first
`handle()`, and implementations must tolerate being called twice because a
`Server` object is reused across sessions.

## Exceptions

| Exception | When it's thrown |
|-----------|-----------------|
| `CancellationException` | Never thrown: `Context->cancel()` records it and `err()` hands it back -- after a bare `cancel()`, or whenever `done()` is true with no stored reason |
| `DeadlineExceededException` | The context deadline (`withDeadline()`) has passed |

Both extend `\RuntimeException`.

## ext-ssh2

Nothing in this library uses the PECL `ssh2` extension, and `composer.json`
says so: it declares `ext-ffi` (which sugar-pty needs to allocate the PTY at
all), suggests `ext-pcntl` (which the default transport needs to fork and
reap the session child), and lists no `ext-ssh2` — an earlier revision
suggested it for a "Ssh2 client middleware (forwarding, sftp helpers)" that
was never built. There is no outbound client here, no port forwarding, and no
SFTP server: `SftpStub` is a wiring example, quarantined only by its own docblock, and the
subsystem middleware only routes a named subsystem to whatever handler you
register. [Subsystem middleware](#subsystem-middleware-inprocesstransport)
records what that does and does not buy you.

`ssh2` becomes relevant only if *your* middleware opens an outbound SSH
session from inside one (an SFTP file picker, a remote-control agent). That is
ordinary application code, with its own trust questions: the peer chose the
target, so treat it as input, not as configuration.

## Channel handler (InProcessTransport)

The `InProcessTransport` dispatches SSH channel-level messages through a
`ChannelHandler` rather than handling them inline. This lets you replace the
default PTY/shell wiring with a custom implementation.

**Which messages actually arrive.** The library mints exactly one
channel message itself: `WindowChangeMsg`, from a `SIGWINCH` observed while
pumping. `pty-req`, `shell`, `exec`, `env`, `signal` and `break` are **not fed from the
wire** — this transport sits behind sshd's `ForceCommand`, so sshd has
already made the shell/exec/subsystem choice before your script starts, and the
protocol-level request never reaches PHP. Those six classes exist for embedders
that dispatch them (a test, or your own decoder for a real channel layer), and
`DefaultChannelHandler` is a live object with a dormant input. Do not plan on
`handleExec()` running for a client that typed `ssh host some-command`: that
request reaches you as the `SSH_ORIGINAL_COMMAND` environment variable inside
the forced script, and `Session::$command` is the field that carries it.

| Class | Purpose |
|-------|---------|
| `ChannelHandler` | Interface — implement to handle pty-req, window-change, shell, exec, signal, env, break |
| `ChannelMsg` | Abstract base for all channel messages (RFC 4254) |
| `DefaultChannelHandler` | Default impl — tracks PTY state, env vars, cols/rows, drives `ChildSpawner` on shell/exec; exec is **default-closed** (`$allowedExec` + `$execGate`) |
| `PtyReqMsg` | `wantPty`, `term`, `cols`, `rows`, `widthPx`, `heightPx` |
| `WindowChangeMsg` | `cols`, `rows`, `widthPx`, `heightPx` |
| `ShellMsg` | `wantShell`, `subsystem` |
| `ExecMsg` | `command` (raw string — decoded by the public `DefaultChannelHandler::decodeCommandString()`, the same entry point to use for `SSH_ORIGINAL_COMMAND`) |
| `SignalMsg` | `signalName` |
| `EnvMsg` | `name`, `value` |
| `BreakMsg` | Break request — carries `breakLengthMs` (int, defaults to `0` when the wire frame omits a length) |

### Exec policy: the program comes from the peer

An exec request names the binary the server should run, so `handleExec()`
spawns nothing unless the operator has said otherwise — two conditions, both
required:

```php
$handler = new DefaultChannelHandler(
    spawner: $transport,
    session: $session,
    allowedExec: ['/bin/ls', '/usr/bin/rsync'],   // exact argv[0]; '' = refuse all
    execGate: function (Session $s, array $argv): bool {
        return $s->user === 'backup';             // argument/user policy, optional
    },
    stderr: $noticeSink,                          // default php://stderr = the CLIENT
);
```

`$allowedExec` matches the whole path, never a prefix (`/bin/ls` does not admit
`/bin/lsblk`). `$execGate` must return exactly `true`; a gate that throws, or
returns a truthy non-bool, refuses — a policy that cannot answer has not given
permission. Refusals are announced with one line naming the requested program
and nothing about the allowlist behind it, and the program name is scrubbed of
control characters first (it is peer-chosen text). Note the sink default: under
`ForceCommand` a notice on stderr travels to the connecting client, not to your
journal — see [Sinks: who actually reads what you write](#sinks-who-actually-reads-what-you-write).

There is no shell in this path. `decodeCommandString()` is a quoting tokenizer
(single/double quotes, backslash escapes, empty quoted args preserved), so
`;`, `$(…)`, backticks, `$HOME` and `*` arrive as inert argv tokens rather than
as commands or expansions — the property the allowlist rests on. Empty or
whitespace-only requests decode to `[]` and are not announced at all.

Shell requests are deliberately *not* gated, because they name no program: the
interpreter is the configured `$shell`, then the server-side `SHELL`, then
`FALLBACK_SHELL`, and a client-supplied `SHELL` is stripped as a dangerous var.

The deny-list that makes that sentence true is `DANGEROUS_VARS`, and it is
applied even to names an operator put in `$acceptEnv`: the loader hooks
(`LD_PRELOAD`, `LD_LIBRARY_PATH`, `LD_AUDIT`, and the two macOS spellings
`DYLD_INSERT_LIBRARIES` and `DYLD_LIBRARY_PATH`), the
locale-conversion path (`GCONV_PATH`), the files shells source before running
anything (`BASH_ENV`, `ENV`) and the code bash runs at each prompt
(`PROMPT_COMMAND`), plus `IFS`, `PATH`, `SHELL`, `HOSTALIASES` and
`POSIXLY_CORRECT`. The rule for the list is that a value must not be able to
decide what code the child runs, which library it loads, or where it looks for
one -- that is the server configuring its own child, not the peer describing
its terminal. Two exceptions are honest ones: the child's `PATH` comes from
the daemon (a search-less child cannot find `/bin/sh`), and `HOME` is derived
as `/home/<user>` rather than read from the passwd database, so a deployment
whose homes differ passes `HOME` itself.

Replacing the handler replaces the policy. `ChannelHandler` is an interface, so
a custom implementation of `handleExec()` is bound by nothing here: if you
substitute one, the default-closed question - may this peer run this program? -
becomes yours to answer inside your method.

On a shell request, `DefaultChannelHandler` resolves the login shell in this
order: the explicit `$shell` configured at construction time, then the
server-side `SHELL` environment variable (under sshd that is the authenticated
user's login shell), then `FALLBACK_SHELL` (`/bin/sh`). The spawn is always
`<shell> -l`. A client-supplied `SHELL` env var can never select the
interpreter — `SHELL` is stripped from the child environment as a
dangerous variable, and resolution reads only the server's own configuration.

```php
use SugarCraft\SshServer\Channel\ChannelHandler;
use SugarCraft\SshServer\Channel\ChannelMsg;
use SugarCraft\SshServer\Channel\Msg\PtyReqMsg;
use SugarCraft\SshServer\Channel\Msg\WindowChangeMsg;
use SugarCraft\SshServer\Channel\Msg\ShellMsg;
use SugarCraft\SshServer\Channel\Msg\ExecMsg;
use SugarCraft\SshServer\Channel\Msg\SignalMsg;
use SugarCraft\SshServer\Channel\Msg\EnvMsg;
use SugarCraft\SshServer\Channel\Msg\BreakMsg;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\Transport\InProcessTransport;

final class DebugChannelHandler implements ChannelHandler
{
    private int $cols = 80;
    private int $rows = 24;

    public function cols(): int
    {
        return $this->cols;
    }

    public function rows(): int
    {
        return $this->rows;
    }

    public function handlePtyReq(PtyReqMsg $msg, Session $session): void
    {
        $this->cols = $msg->cols;
        $this->rows = $msg->rows;
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
        // Implementing the interface means implementing the policy: the
        // default-closed allowlist below belongs to DefaultChannelHandler,
        // and nothing inherits it into this specimen.
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

// Hand the handler to InProcessTransport (handler seam is a setter —
// the constructor takes only an optional PtySystem, and defaults it).
$transport = new InProcessTransport();
$transport->setChannelHandler(new DebugChannelHandler());
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
use SugarCraft\SshServer\Middleware\Subsystem;
use SugarCraft\SshServer\Middleware\Subsystem\SftpStub;

$subsystem = new Subsystem();
$subsystem->register('sftp', new SftpStub());

exit(Server::new()
    ->use(Logger::toFile('/var/log/wish.jsonl'))
    ->use(new Auth(['alice', 'bob']))
    ->use($subsystem)  // handles subsystem sftp; others pass through to Spawn
    ->use(new Spawn(fn (Session $s) => ['cmd' => ['/bin/bash', '-l']]))
    ->serve());
```

A production SFTP implementation would implement `SubsystemHandler` to speak
the SFTP protocol over the session's stdin/stdout after `Subsystem`
extracts the name and dispatches.

## Status

🟢 Context propagation and the middleware suite (logging, auth suite,
keepalive, rate limit, spawn, subsystem, async bridge, BubbleTea) are complete,
and the `ChannelHandler`/`ChannelMsg` types plus the 7 message classes ship as
a dispatch API. That API is honest about its input: only `WindowChangeMsg` is
produced by this library today, so the other six messages are dormant unless
your own code dispatches them (see
[Channel handler](#channel-handler-inprocesstransport)). The wire decoder that
would feed them belongs to a transport that speaks the SSH protocol itself, and
this library deliberately does not — see
[Runtime requirements](#runtime-requirements).

Runnable examples:
- [`examples/hello-server.php`](examples/hello-server.php) — banner-only stack you can ForceCommand against.
- [`examples/showcase.php`](examples/showcase.php) — middleware-stack tour.
- [`examples/spawn-bash.php`](examples/spawn-bash.php) — interactive bash through `Spawn`.
- [`examples/spawn-program.php`](examples/spawn-program.php) — arbitrary child program through `Spawn`.
- [`examples/run-program.php`](examples/run-program.php) — the wrapper that pair execs: a `Program` mounted on its own PTY process.

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
