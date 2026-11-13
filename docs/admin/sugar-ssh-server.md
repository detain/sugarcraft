# sugar-ssh-server — Hub Admin Guide

`sugar-ssh-server` is a PHP SSH server middleware framework. It runs as a
daemon invoked by `sshd` via `ForceCommand` — each SSH connection forks a
fresh PHP supervisor process that runs the middleware stack and, under
`InProcessTransport`, spawns a sugar-pty subprocess.

See also the extension-point counterpart: [Developer Guide](../dev/sugar-ssh-server.md).

---

## Operational concerns

### What the process does

Under **InProcessTransport** (default):

1. Reads session metadata from sshd environment variables (`SSH_ORIGINAL_COMMAND`, `SSH_CLIENT`, etc.)

**The exec contract, stated plainly (audit K1 + MAJOR 10).** `ssh host
some-command` does *not* run `some-command` through this library's channel
layer. sshd has already honoured its `ForceCommand` by the time PHP starts, so
the client's request arrives only as the `SSH_ORIGINAL_COMMAND` environment
variable — `Session::$command` is the field that carries it — and stock
`Spawn`/`BubbleTea` stacks ignore that field. If you want the peer's argument to
decide what runs, you are writing a feature, not inheriting one: decode it with
the public `DefaultChannelHandler::decodeCommandString()` (a quoting tokenizer —
no shell, so `;`, `$(…)`, backticks and `*` stay inert argv tokens) and pass it
through the same two gates the channel handler uses (`$allowedExec` exact-path
list, `$execGate` returning exactly `true`). Both are default-closed on purpose:
the program is peer-chosen, so silence must never be permission. Refuse loudly
when either is missing, and remember a refusal notice written to the default
stderr goes to the connecting client, not to your journal.

2. Builds a root `Context` and walks the middleware chain
3. The terminal middleware (`Spawn`) calls `runChild()` on the injected `ChildSpawner`
4. `InProcessTransport` allocates a sugar-pty master/slave pair, spawns the user's command, pumps bytes between the PTY master and STDIN/STDOUT, forwards SIGWINCH on window-change, and on teardown runs an explicit SIGHUP → ~200 ms grace → SIGKILL ladder against the child, the last rung reaching the child's process group so backgrounded descendants fall with it (closing the PTY master delivers no signal on Linux)

Under **HostSshdTransport** (legacy, opt-in):

1. Middleware run inline in the supervisor process
2. Terminal middleware (`BubbleTea`) mounts a SugarCraft `Program` directly on STDIN/STDOUT
3. No subprocess, no PTY

### Exit status

The entry script's exit code is `Server::serve()`'s return value, so the
script must propagate it — `exit($server->serve());`. Anything else throws
the answer away: a script that falls off the end of the file exits `0` no
matter whom the chain refused. Three outcomes, and they mean different
things to the cron entry or CI step reading `$?`:

| Code | Meaning |
|------|---------|
| the child's own code | the chain reached a spawner and a process ran: `0` means that program succeeded, `3` means it did not |
| `Server::EXIT_DENIED` (`129`) | nothing was served: a shipped gate (`Auth`, `RateLimit`, `PasswordAuth`, `CertificateAuth`, `KeyboardInteractive`) refused, or the `Context` was already dead when the walk reached a hop |
| `0` | the walk ran out of middleware without any of the above — an empty or purely observational stack |

`129` is `128 + SIGHUP`, the shell's own convention for "this process did
not finish normally", and it sits outside the range a program can exit
with, so a refusal can never be misread as some other program's `1`. A
PHP **fatal** is `255`, not `129`: an uncaught throw never reaches
`serve()`'s return, which is the honest reading of the
[Failure modes](#failure-modes) table. Both transports report refusals
the same way — the legacy `HostSshdTransport` has no child of its own,
but it shares the walk and its rules (a `BubbleTea` program's own status
still cannot cross the sshd PTY boundary, so on that transport a program
has no code to report).

### Signals handled

| Signal | Behaviour |
|--------|-----------|
| `SIGTERM` / `SIGHUP` | Pump loop exits gracefully; child process gets SIGHUP then, after ~200 ms if still alive, a SIGKILL to its process group (backgrounded descendants are swept with it) |
| `SIGINT` | Reaches the PTY child (controlling terminal semantics); pump loop drains and exits |
| `SIGWINCH` | Forwarded into the PTY master to resize the child |

SIGWINCH has one process-wide handler slot. While a session runs, this stack
holds that slot, and on teardown it restores the **DEFAULT** disposition — not
whatever handler was installed before. An embedding app that owns its own
SIGWINCH handler must therefore re-attach it after each served session; an app
that needs resizes handled across sessions should read the size and call
`resize()` itself rather than letting this stack attach (audit 049).

### Files written

| Path | When | Contents |
|------|------|----------|
| Rate-limit state file (your `RateLimit` constructor's `$statePath`) | on each admitted request | JSON token-bucket map keyed `ip:<host>` / `user:<name>`, entries `{tokens, last, burst, rate}`. The read-decide-publish cycle is serialised by an exclusive `flock(LOCK_EX)` taken on a **stable sidecar** `<state-path>.lock`; the payload itself is republished 0600 via temp-file + atomic rename (`AtomicJsonFile`), which is why the lock cannot ride the payload inode (see below). Buckets that have refilled to burst are pruned on each publish. |
| Logger output (your `Logger` constructor's `$target`, or `$SSH_SERVER_LOG` when you use `Logger::auto()`; falls back to stderr) | on each session | JSONL one-line events (session start/end, user, client address, elapsed seconds). **A file sink is the only one that keeps them:** under `ForceCommand` the child's stderr is the connecting client's extended-data channel, and journald records only what sshd itself writes. `Logger::target()` reports the resolution; files the logger creates are `0600`. |

Neither path is written unless the corresponding middleware is registered.

### Provisioning the writable paths

Both files belong to the user sshd runs the forced command as, and the
usual failure is that nobody created the parent directory with that user
in mind. Do it at provision time, not at first login:

```sh
install -d -m 0750 -o wishuser -g wishuser /var/lib/wish
touch /var/log/wish.jsonl && chown wishuser:adm /var/log/wish.jsonl
```

With systemd, let it own the lifecycle instead — on the unit that
eventually execs the entry script:

```
[Service]
StateDirectory=wish          # /var/lib/wish, created and owned by the service user
LogsDirectory=wish           # /var/log/wish
```

`tmpfiles.d(5)` entries are the equivalent on a host without systemd.
What you are buying is a known mode: `Logger` and `RateLimit` create a
missing parent `0700` and stamp `0600` on a file they create, but they
never change a mode you already set — so an inherited `0644` from a
previous deploy stays `0644` (rate-limit state and session records both
carry usernames and client addresses; check them with `ls -l`).

**What a missing provision looks like.** These two failures are not
symmetric, and both are cheap to catch before you ship:

- `Logger` resolves its sink in the constructor. An unwritable path
  throws `RuntimeException` immediately: the entry script exits 255 and,
  because the child's stderr is the client's extended-data channel, the
  PHP trace is what the connecting user reads. Nothing lands on disk.
- `RateLimit` cannot open its lock file or decode its state → it
  announces once on its `$stderr` stream and applies its posture:
  `fail-open` (the default) admits the connection, so the throttle is
  silently off; `failClosed: true` denies everything, so a full disk
  becomes a lockout. Both notices are path-free by design, which means
  the *operator* needs the injected stream to learn anything at all.

Run a preflight at deploy time (see the README quickstart for the
snippet): `is_writable()` on each directory and existing file, and log
`Logger::target()` on boot so the resolved sink is on the record.

---

## Configuration

### Env vars

| Var | Effect |
|-----|--------|
| `TERM` | Inherited from sshd; passed to the PTY child; respected by `DefaultChannelHandler` |
| `COLUMNS` / `LINES` | Fallback dimensions when sshd doesn't provide them (used by `DefaultChannelHandler`) |
| `SUGARCRAFT_PTY_BACKEND` | Override PTY backend selection (see `sugar-pty` docs) |
| `SSH_SERVER_LOG` | Where `Logger::auto()` appends. Unset, the logger falls back to stderr — which under `ForceCommand` is the connecting client's channel |
| `LANG` | Read into `Session->$lang` when sshd's `AcceptEnv` allows it. Peer-asserted; never consulted to pick the server's message language |

### Language

Sixteen catalogues ship under `sugar-ssh-server/lang/`, and **nothing in
this stack selects among them implicitly**: the one code path that calls
`T::setLocale()` is `Server::withLocale()`, applied at `serve()` entry
only when you arm it. An unarmed server renders every notice — the
throttle's fail-open announcement, the exec refusal, `Unauthorized. (…)`
— from `lang/en.php` regardless of what the connecting user's environment
claims.

Pin the locale once at entry-script start if you want a translated
service, through either door:

```php
$server = Server::new()->withLocale('de'); // re-stamped at every serve()

\SugarCraft\Core\I18n\T::setLocale('de');  // or the manual write, once
```

Do not use `T::detect()` on an internet-facing listener. It reads
`LC_ALL`, `LC_MESSAGES` and `LANG`, and `LANG` only reaches the
`ForceCommand` child when `AcceptEnv` lists it — which means the peer picks
the language your server answers in, right next to the username it also
picked. `tests/Middleware/RateLimitTest.php` pins that a pinned locale
actually changes the notice; `tests/LangRosterTest.php` pins the roster and
the eleven keys that are still English placeholders in all fifteen
translated locales (the list can only shrink).

### CLI flags

`sugar-ssh-server` has no CLI binary — the entry point is a PHP script invoked
by sshd `ForceCommand`. Configuration is entirely through the PHP API:

```php
Server::new()
    ->withTransport(new InProcessTransport())        // or HostSshdTransport
    ->withKeepalive(30)                                // idle NUL into the child's stdin, not an SSH keepalive
    ->use(new Logger('/var/log/sshd-hub.jsonl'))
    ->use(new Auth(users: ['wishuser']))
    ->use(new RateLimit('/var/lib/sshd-hub/buckets.json', burst: 5, ratePerSec: 0.5))
    ->use(new Spawn(fn (Session $s) => ['cmd' => ['/bin/bash', '-l']]))
    ->serve();
```

(`Spawn` takes a factory returning a config array — `['cmd' =>
list<string>, 'env' => array<string,string>]` — not a bare argv list.)

Under sshd `ForceCommand` the script runs as the target account, so `$s->user`
names that account on every connection; per-user separation needs per-user
`Match` blocks or `authorized_keys` command entries.

### Authentication methods

`sugar-ssh-server` ships the authentication middleware below. They are
orthogonal — compose them based on what your deployment needs:

| Middleware | How it authenticates | Env vars read |
|-----------|---------------------|---------------|
| `Auth` | Username allowlist, key-fingerprint allowlist, or the paired `userKeys` map (name => that name's own keys) | `SSH_USER_KEY_FINGERPRINT`, `SSH_USER_AUTH`, `KEY_FINGERPRINT` |
| `PasswordAuth` | Caller-supplied `callable(string $user, string $pw): bool` | `SSH_PASSWORD` |
| `CertificateAuth` | Caller-supplied `callable(string $pemCert, Session $s): bool` | `SSL_CLIENT_CERT`, `SSH_CLIENT_CERT`, `CERTIFICATE` |
| `KeyboardInteractive` | Prompts on STDOUT, one bounded line read back per prompt. RFC 4256 *shaped*, not RFC 4256: nothing here speaks SSH messages, and `echo: false` cannot silence the terminal | — |
| `AuthMethods` | Stores the declared method list in Context under `auth.methods` for downstream inspection. The `SSH_AUTH_METHODS <list>` line on STDOUT is opt-in (`announce: true`), off by default | — |

**`Auth` trust boundary — the fingerprint allowlist is NOT
cryptographic proof.** Per the class docblock in
`src/Middleware/Auth.php`:
the presented key fingerprint is **not verified by this library**. It
is read from sshd-provided environment metadata (`SSH_USER_KEY_FINGERPRINT`,
`KEY_FINGERPRINT`, or the `SSH_USER_AUTH` `ExposeAuthInfo` blob), which
your sshd writes *after it has cryptographically verified the key* —
but anything that can set a process environment (a wrapper, a
misconfigured `AcceptEnv`, a compromised hop) can forge it. The
allowlist only proves the *presented string* matches a known one.
**Do not rely on the allowlist alone as cryptographic proof of key
ownership.** When you need real verification, pass the optional
fourth constructor argument `callable(string $fingerprint, Session
$s): bool $fingerprintValidator` — it runs *in addition to* the
allowlist, so you can verify the key out-of-band (for example against
your CA or an attestation service) and close the forgeability gap.
Rejections sanitize the presented value (ANSI/C0-C1 stripped) before
echoing it to stderr.

**`Auth` pairing — the flat lists are a cross product (audit K4).**
`users` is compared against the requested name only, and
`keyFingerprints` against the presented key only; nothing in either list
says whose key it is. Configuring `users: ['alice','bob']` with
`keyFingerprints: ['<alice-key>','<bob-key>']` therefore admits **bob
presenting alice's key, as alice** — a fact no earlier document stated.
That behaviour is pinned so it cannot change silently
(`AuthTest::testFlatListsAdmitTheCrossProductThisIsTheAuditedShape`).
The cure when you need real binding is the fifth argument, `userKeys`: a
map of name => that name's own fingerprints, checked authoritatively
(name must be a key of the map; the key must be one of the name's).
`[Auth::ANY_KEY]` says honestly "any key for this name" (there is no key
evidence to check, e.g. the host sshd did the crypto). Fingerprint
membership uses `hash_equals()` across every entry rather than an
`in_array()` early-out, so list position is not measurable.

**`PasswordAuth` / `SSH_PASSWORD` caveat.** `PasswordAuth` reads the
password from `SSH_PASSWORD` via `$_SERVER`/`getenv`. sshd **never sets
this variable** — OpenSSH does not hand passwords to forced commands.
To use this middleware you must inject the secret yourself (for
example from a wrapper or PAM exec), which means the password is
visible in that process's `/proc/<pid>/environ`, is inherited by any
child spawned afterwards, and can land in crash dumps. The library
clears the variable immediately after reading it, which stops later
reads in this process and stops a later child from inheriting it - and
is worth stating precisely, because it does **not** rewrite the
kernel's copy of this process's initial environment: a ptrace or a core
dump taken during the walk can still show the bytes (audit MINOR 2). If
that is inside your threat model, do not use the environment: have the
wrapper hand the secret over a pipe and pass it in as a constructor
argument. Presence is checked separately from value - a session whose
environment carried no `SSH_PASSWORD` at all is refused without
consulting the validator, while an explicitly empty one still reaches
it, because only the second is your code's to judge (audit MINOR 6). The fleet trust model for this class
of config/credential-as-code surface is documented in
[docs/TRUST-BOUNDARY.md](../TRUST-BOUNDARY.md).

**`AuthMethods` operational note:** The middleware's real work is one
Context value: `auth.methods`, readable downstream with
`AuthMethods::fromContext($ctx)`. The `SSH_AUTH_METHODS publickey
password keyboard-interactive` line on STDOUT is opt-in (`announce:
true`) and off by default.

It is *not* the RFC 4252 banner and cannot be: that exchange happens
inside `sshd`, which finished authenticating before your forced command
was exec'd (see [What the process does](#what-the-process-does)).
Printing the line under `ForceCommand` puts text in the user's terminal
-- on an interactive session, in the middle of whatever their shell is
about to do, on every connection. `ssh -v` will not show it as a method
list either; it arrives as session output. If you want a message like
that in front of users, configure sshd's own `Banner=` or the MOTD,
which the client renders before authentication and no program has to
maintain. `announce: true` exists for the narrow honest case: a
banner-style stack on `HostSshdTransport` whose *own* protocol is
`SSH_AUTH_METHODS ...`, with a client written to parse it. Say so in
your deployment notes if you turn it on; the Context record does not
need it.

**`KeyboardInteractive` operational note:** This middleware performs blocking STDIN reads during the authentication phase. It writes all prompts to STDOUT, then reads one line per prompt before passing control on. Two limits are real, and three are not:

- **Real bound:** each answer is capped at `KeyboardInteractive::MAX_RESPONSE_BYTES` (4096 bytes). A longer unterminated line refuses the exchange outright -- the validator is never consulted -- rather than growing the process.
- **Real stop condition:** EOF on the stream ends the exchange, and an unfinished exchange is a refusal. A done `Context` aborts the loop between prompts (it raises the context's own error; see [Cooperative cancellation](#cooperative-cancellation)), but it cannot preempt a parked read: callers that need a deadline bound the stream they inject as `$stdin`.
- **Not a deadline:** sshd's `LoginGraceTime` covers the *authentication* dialogue inside `sshd`, not your post-`ForceCommand` child. Once sshd has run your script, nothing upstream is timing your reads. Wrap the entry point in `timeout(1)` if a stalled human must not hold a process forever.
- **Not concealment:** a prompt asking `echo: false` does not clear the terminal's echo flag. The user's keyboard is still echoed by the tty, and the answer lands in their scrollback. The middleware now says so out loud (`keyboardinteractive.input_visible`) once per exchange when any prompt asks for silence. Suppressing echo means `ECHO` off on the descriptor -- `sugar-pty`'s `Contract\Termios` currently offers only `makeRaw()`, which also drops `ICANON` and `ISIG`, so it is not a swap-in. Secret prompts belong behind `Auth` pairing, not behind this form.
- **Not composable with `BubbleTea`:** both consume STDIN/STDOUT. Use it with `Spawn` under `InProcessTransport`.

**Example — composing PasswordAuth + KeyboardInteractive:**

```php
use SugarCraft\SshServer\Middleware\Auth\AuthMethods;
use SugarCraft\SshServer\Middleware\Auth\PasswordAuth;
use SugarCraft\SshServer\Middleware\Auth\KeyboardInteractive;
use SugarCraft\SshServer\Middleware\Spawn;
use SugarCraft\SshServer\Server;
use SugarCraft\SshServer\Session;

Server::new()
    ->use(new AuthMethods(['password', 'keyboard-interactive']))
    ->use(new PasswordAuth(fn ($u, $p) => verifyPassword($u, $p)))
    ->use(new KeyboardInteractive([
        ['prompt' => 'OTP: ', 'echo' => false],
    ], fn ($responses) => verifyTotp($responses[0])))
    ->use(new Spawn(fn (Session $s) => [
        'cmd' => ['/bin/bash', '-l'],
        'env' => ['TERM' => $s->term, 'USER' => $s->user],
    ]))
    ->serve();
```

### Async middleware

Since the E730 restructure, `AsyncMiddleware` is a **pure adapter**:
`handle()` returns your promise untouched and never awaits it itself.
The library's single settle point lives in the transport stack walk
(`src/Transport/DispatchesMiddlewareStack.php` →
`src/Transport/PromiseAwait.php::settle()`), which drives each promise
to settlement before the chain continues. Operational consequences:

- **Timeout ceiling:** `PromiseAwait::settle()` bounds each await with
  a **30-second** default. The ceiling is enforced twice over: the
  timer `settle()` arms for this one await rejects it with
  react/promise-timer's `TimeoutException`, and the wait ends its own
  spin at that decision instead of running the loop until it happens to
  go quiet. The timer belongs to the await — a settled or abandoned
  spin cancels it before handing the loop back, so no stale ceiling can
  fire inside a later one. A middleware that has not settled in time
  raises a `RuntimeException` carrying the `transport.async_timeout`
  message and the chain short-circuits.
- **Who owns the loop:** the settle call *drives* the loop while it
  waits and stops it as soon as the awaited promise is decided. That is
  right for the shipped transports, which build the middleware chain
  synchronously and have no loop running underneath. It also means you
  must not await from inside your own running loop — the `Loop::stop()`
  that ends the await would end your application too. The two
  loop-ownership laws are on the class docblock of
  `src/Transport/PromiseAwait.php`; read them before wiring a custom
  transport.
- **Cooperative cancellation:** the settle entry guard refuses to
  await when the session `Context` is already done — it raises the
  context's error instead of blocking up to the ceiling, so a
  deadline set earlier in the chain (`Context::withDeadline()`) is
  honoured at the next await. Mid-await cancellation stays
  producer-side: only the code holding the resolver can end it
  early. The long-running surfaces poll the same `done()`: the
  in-process pump arms the chain deadline as its own bound and, on
  any expiry or cancel observed at an idle tick, sends the child a
  single `SIGHUP` — the same signal sshd uses at session end — while
  `KeyboardInteractive` aborts its challenge loop at the next prompt
  boundary. Nothing here preempts a blocked read; a context is a
  boundary poll, not a timer thread.
- **Short-circuit on rejection:** if the promise rejects, the
  exception surfaces synchronously at the dispatch point and the
  session is terminated.

### Rate limiting

`RateLimit` (`src/Middleware/RateLimit.php`) keeps per-client-host
token buckets (`burst`, `ratePerSec`); per-username buckets are opt-in
via `$userBurst`/`$userRatePerSec` (a request must get a token from
*both* buckets, and a rejection consumes neither). `penalize()` lets an
auth-failure hook drain buckets faster than connection attempts alone -- but
no shipped gate calls it on its own: the gates know nothing about whose
bucket to blame. `AuthRateLimitBridge` supplies the wiring — wrap the gate
once and each of its refusals charges the offending session's buckets the
bridge penalty. Wire `penalize()` yourself instead only for penalties the
bridge's rule (gate refused => charge) does not describe.

- **Ordering law:** register `RateLimit` **before** any
  authentication middleware, or unauthenticated traffic reaches the
  credential checks unthrottled.
- **Failure posture — fail-open by default:** if the lock cannot be
  taken or the state file cannot be read or decoded, the middleware
  **allows** the request and announces the degradation. This keeps an
  unrelated disk/lock incident from locking everyone out, but it means
  a persistent state failure silently disables throttling. Set
  `$failClosed = true` (fifth constructor argument) to **deny** on
  state errors instead — the right choice for high-exposure hubs.
  The announcement goes to the middleware's `$stderr` stream, which is
  `php://stderr` unless you inject one: under `ForceCommand` that
  descriptor is the SSH **extended-data** channel, so an unauthenticated
  prober can read your fail-open decision. The notices name the failure
  class (lock/decode error) and never the state-file path; point
  `$stderr` at a real operator sink (see the logging section) on any
  internet-facing host.
- The state file is a JSON map keyed `ip:<host>` / `user:<name>`,
  written 0600 via temp-file + atomic rename (`AtomicJsonFile`).
  Mutual exclusion rides a **stable sidecar** — `flock(LOCK_EX)` is
  taken on `<state-path>.lock` and held across the whole
  read-decide-publish cycle — because the payload's own inode is
  *replaced* by every publish: a lock taken on the payload is a lock on
  a file that no longer exists at that path, so each sibling sshd-spawned
  process would serialise with nothing and hand every concurrent connect
  its full burst (six parallel connects against `burst: 3` all got in
  before the sidecar existed; the pin in
  `sugar-ssh-server/tests/Middleware/RateLimitTest.php` holds that
  number down at three). Keep the state file on a **local** filesystem:
  `flock` on NFS is advisory at best. For high-volume deployments where
  one writer per connect is too coarse, swap in a shared-store limiter
  (e.g. Redis-backed) — the sidecar lock is correct, not fast.

### Subsystem

The `Subsystem` middleware handles SSH subsystem requests (RFC 4254 §6.5).
When a client sends `subsystem <name>` as the original command, the
middleware looks up the registered `SubsystemHandler` for `<name>`, invokes
it, and stops the chain — handlers are terminal. Non-subsystem commands
pass through to `$next`.

```php
use SugarCraft\SshServer\Middleware\Subsystem;
use SugarCraft\SshServer\Middleware\Subsystem\SftpStub;

$subsystem = new Subsystem();
$subsystem->register('sftp', new SftpStub());

Server::new()
    ->use($subsystem)  // handles subsystem sftp; others fall through to Spawn
    ->use(new Spawn(fn (Session $s) => ['cmd' => ['/bin/bash', '-l']]))
    ->serve();
```

**Operational note:** `Subsystem` is terminal — it does not call `$next`
when a handler is found. Place it before `Spawn` so non-subsystem requests
continue to the shell. A production SFTP handler would implement
`SubsystemHandler` to speak the SFTP protocol over the session's
stdin/stdout.

---

## Monitoring

- Logger middleware emits a JSONL line on session start (`{ "event": "session.start", "ts": "...", "user": "...", "client_addr": "host:port", "term": "...", "tty": "...", "pty": "COLSxROWS", "command": "..." }`) and session end (same shape plus `{ "event": "session.end", "elapsed_s": N }` — seconds, rounded to 3 dp).
- Choose the sink deliberately. `Logger::auto()` / `new Logger()` append to `$SSH_SERVER_LOG` when the service exports it; with nothing set they write to stderr, and the forced command's stderr is forwarded to *the client*, so those records print into the user's terminal and the operator keeps nothing. Set `Environment=SSH_SERVER_LOG=/var/log/sshd-hub/sessions.jsonl` in the socket-activated service unit, or pass `Logger::toFile($path)` / `Logger::toStream($handle)` explicitly. Log a `Logger::target()` line at boot so the choice is on the record.
- Parse the JSONL with `jq 'select(.event == "session.end") | .elapsed_s'` to graph p95 session durations.
- Rate-limit state file (your configured `$statePath`, e.g. `/var/lib/sshd-hub/buckets.json`) is human-readable JSON — monitor its size to gauge active-bucket count.

### Protocol metadata for audit logging

Read the field names before you build a dashboard on them. There is no
handshake in this stack — the library speaks no SSH — so what
`withProtocolMetadata()` hands over is derived locally by the transport —
only `InProcessTransport::run()` ever calls it, so under `HostSshdTransport`
those three columns are permanently `-` — and
`Session::toLogContext()` has carried it on every `Logger` line since audit
MAJOR 3 (`session`, `auth_method`, `key_fingerprint`, each `-` when not
carried):

| Field | What it actually is | Safe use |
|-------|---------------------|----------|
| `sessionId` | minted per process (`bin2hex(random_bytes(16))`) | tying one login's own lines together |
| `authMethod` | sniffed from `SSH_USER_AUTH` if a wrapper exported it | a hint, never proof of what happened |
| `keyFingerprint` | no producer in this library today | `Auth` pairing instead — see [Authentication methods](#authentication-methods) |
| `clientVersion`, `serverVersion` | read from `SSH_CLIENT_VERSION` / `SSH_SERVER_VERSION`, names stock OpenSSH does not export (audit MAJOR 21) | a column only for a deployment that supplies one out of band |

A dedicated audit sink, if you want one separate from the session log, still
looks like this — the difference is knowing which of its columns are normally
empty and why:

```php
final class AuditLogger implements Middleware
{
    public function handle(Context $ctx, Session $s, callable $next): void
    {
        $audit = [
            'event'         => 'session_start',
            'session_id'    => $s->sessionId,      // this process only
            'auth_method'   => $s->authMethod,     // usually null
            'client_fp'     => $s->keyFingerprint, // always null today
            'client_ver'    => $s->clientVersion,  // usually null
            'server_ver'    => $s->serverVersion,  // usually null
            'user'          => $s->user,
            'client_host'   => $s->clientHost,
        ];
        $this->logger->info(json_encode($audit));
        $next($ctx, $s);
    }
}
```

Do not plan on cross-host correlation. The id is not issued by `sshd`, so it
matches neither the daemon's log lines nor a bastion's record, and it changes
on the next login; a stack that needs one identity across several hosts must
thread it in through middleware (`Context::withValue()`) or an environment
variable it controls.

Auth *outcomes* are not in these lines either. `Logger` writes one
`session.start` and one `session.end` per walk; a gate that refuses mid-chain
stops the chain without emitting an event of its own, and its notice goes to
whichever stream you handed the gate (which, under `ForceCommand`, is the
peer's channel — see [Sinks](#sinks-who-actually-reads-what-you-write) in the
library README). The process-level answer to a refusal is the exit code
(`Server::EXIT_DENIED`, see [Exit status](#exit-status)); a per-event audit
trail of denials is a seam you have to write yourself.

### Healthcheck

```bash
ssh -o BatchMode=yes hubuser@host; echo "status=$?"
```

Do **not** write the probe as `ssh hubuser@host echo ok` and read the output.
`ForceCommand` replaces the command line: the `echo ok` never runs, the entry
script does, and whatever it prints is the entry script's own banner (see
**The exec contract** under [What the process does](#what-the-process-does)).
If you want a command-shaped health probe, you have to implement the reading
yourself - decode `SSH_ORIGINAL_COMMAND` in your own stack and answer it
before anything spawns a shell.

What the exit status actually tells you is worth the column:

| Status | Meaning |
|--------|---------|
| `0` | The chain ran to its terminal. With no middleware that spawns, that means *nothing was served* - see [Exit status](#exit-status). |
| `129` | A gate refused (`Server::EXIT_DENIED`): auth denied, throttle tripped, or the context was already dead. |
| `255` | PHP fatal before `serve()` returned - a missing file, a bad path, an unprovisioned sink. Check the provisioning step above. |
| other | The child's own exit code, if a child ran. |

A liveness check that must not depend on credentials at all should be a
different mechanism (a `sshd`-level port probe, or a `systemd` watchdog on the
service); anything routed through `ForceCommand` is exercising your real
policy, including the throttle.

### Concurrency and back-pressure

Nothing in this library bounds the number of concurrent sessions. Each
connection is a `sshd` child running one PHP process, which is the design
(see **One session per process** in the library README), so the limits that
work are `sshd`'s: `MaxStartups` for unauthenticated pile-ups,
`MaxSessions` per connection, and the usual `User tasksmax` / cgroup caps if a
deployed stack can spawn expensive children. There is no internal queue, no
idle timeout, and no connection counter to poll - a monitoring system that
wants "sessions now" should count the `sshd` children of the forced program.

---

## Failure modes

| Failure | What actually happens |
|---------|---------------------|
| Middleware throws | An uncaught PHP fatal: the trace goes to the child's standard error, which under `ForceCommand` is the connecting **client's** channel, and the process exits `255`. Nothing is logged anywhere unless a `finally` inside your own middleware logged it first. |
| PTY allocation fails | Same shape: the `PtyException` from `PtySystem::open()` escapes `runChild()` uncaught, prints the trace onto the client's stream, exits `255`. |
| Rate-limit exceeded | The throttle writes its `ratelimit.exceeded` line to the stream it was given, marks the session refused, and returns without invoking `$next`. No disconnect packet, no close, no body: the child simply never spawns and the process exits `Server::EXIT_DENIED` (`129`) — the same refusal gate as `Auth` in the table above, not the `0` of the idle-stack rule below. |
| `sshd` misconfigured (no `ForceCommand`) | No session metadata exists, so `Session::fromEnvironment()` fills every slot it can from the supervisor's own environment (`USER`/`LOGNAME`, `TERM` defaulting to `xterm-256color`, zero `COLUMNS`/`LINES`) and the entry script runs as an ordinary command rather than a session. |
| Child process hangs | A context threaded through `Spawn` bounds the pump: the chain deadline is armed as the pump's own wall-clock bound and expires it, and any done context seen at an idle tick hangs the child up once with `SIGHUP` (escalation, if the child ignores it, is the teardown ladder's `SIGHUP` → `SIGKILL`). An **unbounded** run — no context, or a child that survives all of that — waits however long it waits: `timeout(1)` around the entry point, or sshd's own session teardown, remain the outer defense (see [Cooperative cancellation](#cooperative-cancellation)). |

There is no HTTP status code, no response body and no close-on-error on any of
these paths, because there is no protocol stack here to produce one: this
library hands a shell to a child and mirrors bytes. The stack wrapper that
would turn a throw into a logged refusal and a tidy exit does not exist, so
`error logged` in this table would have been a promise nobody keeps.

**Output can be truncated on a non-blocking STDOUT (cross-lib seam).**
`runChild()` flips the PTY master and STDIN to non-blocking because the pump
polls them; it leaves STDOUT blocking on purpose. `sugar-pty`'s
`PosixPump::pumpMasterToStdout()` writes each chunk with one `fwrite()` and
treats a short write as success, so handing the transport a non-blocking
`$stdout` (an async host that took over its own stdio) loses every byte past
the pipe buffer — silently, with no error and no log line. Keep `$stdout`
blocking. The retry-the-tail fix belongs upstream in `sugar-pty`; until it
lands there, a loop-owned stdio is not a supported embedding.

---

## Backup / migration

No persistent state is maintained across requests beyond the two
files above. The logger output is append-only JSONL; the rate-limit
state file is rewritten atomically (temp + rename) after every
admitted request — neither needs anything fancier than `logrotate` or
`truncate -s 0` (the latter only for the log). To migrate to a new
machine, copy both — they are portable JSON — and remember the state
file is owner-only (`0600`) for a reason.
