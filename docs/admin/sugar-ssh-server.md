# sugar-ssh-server — Hub Admin Guide

`sugar-ssh-server` is a PHP SSH server middleware framework. It runs as a
daemon invoked by `sshd` via `ForceCommand` — each SSH connection forks a
fresh PHP supervisor process that runs the middleware stack and, under
`InProcessTransport`, spawns a sugar-pty subprocess.

---

## Operational concerns

### What the process does

Under **InProcessTransport** (default):

1. Reads session metadata from sshd environment variables (`SSH_ORIGINAL_COMMAND`, `SSH_CLIENT`, etc.)
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
| Rate-limit state file (your `RateLimit` constructor's `$statePath`) | on each admitted request | JSON token-bucket map, serialised with `flock(LOCK_EX)` across the sibling sshd-spawned processes, rewritten 0600 via temp-file + atomic rename (`AtomicJsonFile`) |
| Logger output (your `Logger` constructor's `$target`; defaults to stderr) | on each session | JSONL one-line events (session start/end, user, client address, elapsed seconds) |

Neither path is written unless the corresponding middleware is registered.

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
| `Auth` | Username allowlist + SHA256 key fingerprint allowlist | `SSH_USER_KEY_FINGERPRINT`, `SSH_USER_AUTH`, `KEY_FINGERPRINT` |
| `PasswordAuth` | Caller-supplied `callable(string $user, string $pw): bool` | `SSH_PASSWORD` |
| `CertificateAuth` | Caller-supplied `callable(string $pemCert, Session $s): bool` | `SSL_CLIENT_CERT`, `SSH_CLIENT_CERT`, `CERTIFICATE` |
| `KeyboardInteractive` | Challenge-response prompts written to STDOUT, responses read from STDIN (RFC 4256) | — |
| `AuthMethods` | Writes `SSH_AUTH_METHODS <list>` banner to STDOUT; stores list in Context under `auth.methods` for downstream inspection | — |

**`Auth` trust boundary — the fingerprint allowlist is NOT
cryptographic proof.** Per `src/Middleware/Auth.php` (docblock :36-49):
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
use SugarCraft\SshServer\Middleware\Auth\PasswordAuth;
use SugarCraft\SshServer\Middleware\Auth\KeyboardInteractive;
use SugarCraft\SshServer\Middleware\Auth\AuthMethods;

Server::new()
    ->use(new AuthMethods(['password', 'keyboard-interactive']))
    ->use(new PasswordAuth(fn ($u, $p) => verifyPassword($u, $p)))
    ->use(new KeyboardInteractive([
        ['prompt' => 'OTP: ', 'echo' => false],
    ], fn ($responses) => verifyTotp($responses[0])))
    ->use(new Spawn(...))
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
  a **30-second** default (enforced through `react/promise-timer` on
  the event loop — the loop is driven until settlement, it is not a
  busy spin). A middleware that has not settled in time raises a
  `RuntimeException` carrying the `transport.async_timeout` message
  and the chain short-circuits.
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
- **Failure posture — fail-open by default:** if the state file
  cannot be read or locked, the middleware **allows** the request and
  logs the degradation to stderr. This keeps an unrelated disk/lock
  incident from locking everyone out, but it means a persistent state
  failure silently disables throttling. Set `$failClosed = true`
  (fifth constructor argument) to **deny** on state errors instead —
  the right choice for high-exposure hubs.
- The state file is a JSON map keyed `ip:<host>` / `user:<name>`,
  written 0600 via temp-file + atomic rename under `flock(LOCK_EX)` so
  sibling sshd-spawned processes share it safely. For high-volume
  deployments, swap in a shared-store limiter (e.g. Redis-backed) —
  the flock-per-request model is the documented ceiling.

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

---

## Backup / migration

No persistent state is maintained across requests beyond the two
files above. The logger output is append-only JSONL; the rate-limit
state file is rewritten atomically (temp + rename) after every
admitted request — neither needs anything fancier than `logrotate` or
`truncate -s 0` (the latter only for the log). To migrate to a new
machine, copy both — they are portable JSON — and remember the state
file is owner-only (`0600`) for a reason.
