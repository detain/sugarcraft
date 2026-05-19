# candy-wish — Hub Admin Guide

`candy-wish` is a PHP SSH server middleware framework. It runs as a
daemon invoked by `sshd` via `ForceCommand` — each SSH connection forks a
fresh PHP supervisor process that runs the middleware stack and, under
`InProcessTransport`, spawns a candy-pty subprocess.

---

## Operational concerns

### What the process does

Under **InProcessTransport** (default):

1. Reads session metadata from sshd environment variables (`SSH_ORIGINAL_COMMAND`, `SSH_CLIENT`, etc.)
2. Builds a root `Context` and walks the middleware chain
3. The terminal middleware (`Spawn`) calls `runChild()` on the injected `ChildSpawner`
4. `InProcessTransport` allocates a candy-pty master/slave pair, spawns the user's command, pumps bytes between the PTY master and STDIN/STDOUT, forwards SIGWINCH on window-change, and SIGHUP on EOF

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
| Rate-limit state file (`--state-path`) | on each request | JSON token-bucket state, locked with `flock(LOCK_EX)` |
| Logger output (`--log-path`) | on each session | JSONL one-line events (session start/end, user, IP, duration) |

Neither path is written unless the corresponding middleware is registered.

---

## Configuration

### Env vars

| Var | Effect |
|-----|--------|
| `TERM` | Inherited from sshd; passed to the PTY child; respected by `DefaultChannelHandler` |
| `COLUMNS` / `LINES` | Fallback dimensions when sshd doesn't provide them (used by `DefaultChannelHandler`) |
| `SUGARCRAFT_PTY_BACKEND` | Override PTY backend selection (see `candy-pty` docs) |

### CLI flags

`candy-wish` has no CLI binary — the entry point is a PHP script invoked
by sshd `ForceCommand`. Configuration is entirely through the PHP API:

```php
Server::new()
    ->withTransport(new InProcessTransport())        // or HostSshdTransport
    ->withKeepalive(30)                                // SSH-level keepalive interval
    ->use(new Logger('/var/log/wish.jsonl'))
    ->use(new Auth(['alice', 'bob']))
    ->use(new RateLimit('/var/lib/wish/buckets.json', burst: 5, ratePerSec: 0.5))
    ->use(new Spawn(fn (Session $s) => ['/bin/bash', '-l']))
    ->serve();
```

### Authentication methods

`candy-wish` ships four authentication middleware. They are orthogonal —
compose them based on what your deployment needs:

| Middleware | How it authenticates | Env vars read |
|-----------|---------------------|---------------|
| `Auth` | Username allowlist + SHA256 key fingerprint allowlist | `SSH_USER_KEY_FINGERPRINT`, `SSH_USER_AUTH`, `KEY_FINGERPRINT` |
| `PasswordAuth` | Caller-supplied `callable(string $user, string $pw): bool` | `SSH_PASSWORD` |
| `CertificateAuth` | Caller-supplied `callable(string $pemCert, Session $s): bool` | `SSL_CLIENT_CERT`, `SSH_CLIENT_CERT`, `CERTIFICATE` |
| `KeyboardInteractive` | Challenge-response prompts written to STDOUT, responses read from STDIN (RFC 4256) | — |
| `AuthMethods` | Writes `SSH_AUTH_METHODS <list>` banner to STDOUT; stores list in Context under `auth.methods` for downstream inspection | — |

**`AuthMethods` operational note:** This middleware writes one line to STDOUT (`SSH_AUTH_METHODS publickey password keyboard-interactive`) before passing control down the chain. This is the RFC 4252 banner that tells the SSH client which methods are available. It runs once per session, early in the middleware chain, before any credential-checking middleware.

**`KeyboardInteractive` operational note:** This middleware performs blocking STDIN reads during the authentication phase. The prompts and responses are exchange-based (RFC 4256) — the middleware writes all prompts to STDOUT, then reads exactly N lines from STDIN before passing control to the next middleware. It is not suitable for use with `BubbleTea` (which also consumes STDIN/STDOUT) and should only be used with `Spawn` under `InProcessTransport`.

**Example — composing PasswordAuth + KeyboardInteractive:**

```php
use SugarCraft\Wish\Middleware\Auth\PasswordAuth;
use SugarCraft\Wish\Middleware\Auth\KeyboardInteractive;
use SugarCraft\Wish\Middleware\Auth\AuthMethods;

Server::new()
    ->use(new AuthMethods(['password', 'keyboard-interactive']))
    ->use(new PasswordAuth(fn ($u, $p) => verifyPassword($u, $p)))
    ->use(new KeyboardInteractive([
        ['prompt' => 'OTP: ', 'echo' => false],
    ], fn ($responses) => verifyTotp($responses[0])))
    ->use(new Spawn(...))
    ->serve();
```

---

## Monitoring

- Logger middleware emits a JSONL line on session start (`{ "event": "start", "user": "...", "clientHost": "...", "duration_ms": N }`) and session end (`{ "event": "end", ... }`).
- Parse the JSONL with `jq 'select(.event == "end") | .duration_ms'` to graph p95 session durations.
- Rate-limit state file (`/var/lib/wish/buckets.json`) is human-readable JSON — monitor its size to gauge active-bucket count.

### Healthcheck

```bash
# Probe the wish endpoint (assuming a banner/ping middleware is registered)
ssh -o BatchMode=yes wishuser@host echo ok
```

---

## Failure modes

| Failure | Result |
|---------|--------|
| Middleware throws before terminal | 500 response to SSH client; error logged |
| PTY allocation fails | `RuntimeException` from `PtySystem::open()`; connection closed; error logged |
| Rate-limit exceeded | Connection closed with SSH DISCONNECT; no response body |
| `sshd` misconfigured (no `ForceCommand`) | PHP supervisor sees no session metadata; `Session::fromEnvironment()` returns default/zero values |
| Child process hangs | `InProcessTransport` pump loop waits forever — set a deadline via `Context::withDeadline()` upstream to force-cleanup |

---

## Backup / migration

No persistent state is maintained across requests. The only state files
(rate-limit buckets, logger output) are append-only and can be rotated
with `logrotate` or `truncate -s 0`. To migrate to a new machine, copy
the logger and rate-limit state files — they are portable JSON.
