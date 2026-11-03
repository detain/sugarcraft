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
4. `InProcessTransport` allocates a sugar-pty master/slave pair, spawns the user's command, pumps bytes between the PTY master and STDIN/STDOUT, forwards SIGWINCH on window-change, and SIGHUP on EOF

Under **HostSshdTransport** (legacy, opt-in):

1. Middleware run inline in the supervisor process
2. Terminal middleware (`BubbleTea`) mounts a SugarCraft `Program` directly on STDIN/STDOUT
3. No subprocess, no PTY

### Signals handled

| Signal | Behaviour |
|--------|-----------|
| `SIGTERM` / `SIGHUP` | Pump loop exits gracefully; child process gets SIGHUP then SIGKILL after ~200 ms if still alive |
| `SIGINT` | Reaches the PTY child (controlling terminal semantics); pump loop drains and exits |
| `SIGWINCH` | Forwarded into the PTY master to resize the child |

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

### CLI flags

`sugar-ssh-server` has no CLI binary — the entry point is a PHP script invoked
by sshd `ForceCommand`. Configuration is entirely through the PHP API:

```php
Server::new()
    ->withTransport(new InProcessTransport())        // or HostSshdTransport
    ->withKeepalive(30)                                // SSH-level keepalive interval
    ->use(new Logger('/var/log/sshd-hub.jsonl'))
    ->use(new Auth(['alice', 'bob']))
    ->use(new RateLimit('/var/lib/sshd-hub/buckets.json', burst: 5, ratePerSec: 0.5))
    ->use(new Spawn(fn (Session $s) => ['cmd' => ['/bin/bash', '-l']]))
    ->serve();
```

(`Spawn` takes a factory returning a config array — `['cmd' =>
list<string>, 'env' => array<string,string>]` — not a bare argv list.)

### Authentication methods

`sugar-ssh-server` ships the authentication middleware below. They are
orthogonal — compose them based on what your deployment needs:

| Middleware | How it authenticates | Env vars read |
|-----------|---------------------|---------------|
| `Auth` | Username allowlist, key-fingerprint allowlist, or the paired `userKeys` map (name => that name's own keys) | `SSH_USER_KEY_FINGERPRINT`, `SSH_USER_AUTH`, `KEY_FINGERPRINT` |
| `PasswordAuth` | Caller-supplied `callable(string $user, string $pw): bool` | `SSH_PASSWORD` |
| `CertificateAuth` | Caller-supplied `callable(string $pemCert, Session $s): bool` | `SSL_CLIENT_CERT`, `SSH_CLIENT_CERT`, `CERTIFICATE` |
| `KeyboardInteractive` | Challenge-response prompts written to STDOUT, responses read from STDIN (RFC 4256) | — |
| `AuthMethods` | Writes `SSH_AUTH_METHODS <list>` banner to STDOUT; stores list in Context under `auth.methods` for downstream inspection | — |

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
password from `SSH_PASSWORD` via `$_SERVER`/`getenv`
(`src/Middleware/Auth/PasswordAuth.php` :52-54). sshd **never sets
this variable** — OpenSSH does not hand passwords to forced commands.
To use this middleware you must inject the secret yourself (for
example from a wrapper or PAM exec), which means the password is
visible in that process's `/proc/<pid>/environ`, is inherited by any
child spawned afterwards, and can land in crash dumps. The library
clears it immediately after reading (`:59`); the exposure window
before that is yours to minimize. The fleet trust model for this class
of config/credential-as-code surface is documented in
[docs/TRUST-BOUNDARY.md](../TRUST-BOUNDARY.md).

**`AuthMethods` operational note:** This middleware writes one line to STDOUT (`SSH_AUTH_METHODS publickey password keyboard-interactive`) before passing control down the chain. This is the RFC 4252 banner that tells the SSH client which methods are available. It runs once per session, early in the middleware chain, before any credential-checking middleware.

**`KeyboardInteractive` operational note:** This middleware performs blocking STDIN reads during the authentication phase. The prompts and responses are exchange-based (RFC 4256) — the middleware writes all prompts to STDOUT, then reads exactly N lines from STDIN before passing control to the next middleware. It is not suitable for use with `BubbleTea` (which also consumes STDIN/STDOUT) and should only be used with `Spawn` under `InProcessTransport`.

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
  a **30-second** default. The ceiling is enforced twice over:
  `react/promise-timer` rejects the promise, and the wait ends its own
  spin at that decision instead of running the loop until it happens to
  go quiet. A middleware that has not settled in time raises a
  `RuntimeException` carrying the `transport.async_timeout` message
  and the chain short-circuits.
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
  producer-side: only the code holding the resolver can end it early.
- **Short-circuit on rejection:** if the promise rejects, the
  exception surfaces synchronously at the dispatch point and the
  session is terminated.

### Rate limiting

`RateLimit` (`src/Middleware/RateLimit.php`) keeps per-client-host
token buckets (`burst`, `ratePerSec`); per-username buckets are opt-in
via `$userBurst`/`$userRatePerSec` (a request must get a token from
*both* buckets, and a rejection consumes neither). `penalize()` lets an
auth-failure hook drain buckets faster than connection attempts alone.

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

After the SSH handshake, transports populate protocol metadata on the `Session`
via `withProtocolMetadata()`: `sessionId`, `authMethod`, `keyFingerprint`,
`clientVersion`, `serverVersion`. Custom logging middleware can read these
fields and include them in audit logs for security analysis:

```php
final class AuditLogger implements Middleware
{
    public function handle(Context $ctx, Session $s, callable $next): void
    {
        $audit = [
            'event'         => 'session_start',
            'session_id'    => $s->sessionId,
            'auth_method'   => $s->authMethod,
            'client_fp'     => $s->keyFingerprint,
            'client_ver'    => $s->clientVersion,
            'server_ver'    => $s->serverVersion,
            'user'          => $s->user,
            'client_host'   => $s->clientHost,
        ];
        $this->logger->info(json_encode($audit));
        $next($ctx, $s);
    }
}
```

These fields are also useful for correlating across multiple proxy hops
(session ID remains stable across the full SSH session lifetime).

### Healthcheck

```bash
# Probe the hub endpoint (assuming a banner/ping middleware is registered)
ssh -o BatchMode=yes hubuser@host echo ok
```

---

## Failure modes

| Failure | Result |
|---------|--------|
| Middleware throws before terminal | 500 response to SSH client; error logged |
| PTY allocation fails | `RuntimeException` from `PtySystem::open()`; connection closed; error logged |
| Rate-limit exceeded | Connection closed with SSH DISCONNECT; no response body |
| `sshd` misconfigured (no `ForceCommand`) | PHP supervisor sees no session metadata; `Session::fromEnvironment()` returns default/zero values |
| Child process hangs | `InProcessTransport` pump loop waits forever — set a deadline via `Context::withDeadline()` earlier in the chain to force-cleanup |

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
