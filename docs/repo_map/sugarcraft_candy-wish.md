# SugarCraft/candy-wish

## Metadata
- **URL:** https://github.com/sugarcraft/candy-wish
- **Package:** `sugarcraft/candy-wish`
- **Language:** PHP 8.3+
- **Status:** 🟢 v1 ready
- **Namespace:** `SugarCraft\Wish`
- **Upstream:** [charmbracelet/wish](https://github.com/charmbracelet/wish) (MIT, Go, 5.2k stars)
- **Dependencies:** `sugarcraft/candy-core`, `sugarcraft/candy-pty`, `react/event-loop`, `react/promise-timer`

## Description

CandyWish is a PHP port of `charmbracelet/wish` — an SSH server middleware framework that lets you build TUIs anyone can `ssh user@host` to run. The library provides composable middleware (auth, logging, rate-limiting), pluggable transport strategies (InProcessPTY or HostSshd inline), and deep integration with `candy-pty` for full pseudo-terminal semantics. Unlike the Go original which implements the full SSH wire protocol, CandyWish intentionally delegates SSH to the host OpenSSH daemon and operates as a middleware processor per connection.

---

## Architecture

### Design Philosophy: Delegating SSH to OpenSSH

CandyWish's core architectural decision is to lean on the host's OpenSSH daemon rather than implement the SSH wire protocol from scratch. Each SSH connection forks a fresh PHP process under `sshd` via `ForceCommand`. What that PHP process does depends on the active **Transport**:

```
[SSH client] ──ssh──▶ [sshd] ──ForceCommand──▶ [PHP supervisor process]
                                                │
                     ┌────────────────────────┤
                     │  Transport decides      │
                     │  middleware HOW         │
                     ▼                         ▼
              InProcessTransport         HostSshdTransport
              (PTY supervisor)           (inline middleware)
```

**This is a deliberate tradeoff:** OpenSSH handles cipher negotiation, authentication, host key verification, and the SSH protocol. CandyWish handles application-level concerns: auth middleware, logging, rate limiting, spawning arbitrary commands or mounting SugarCraft Programs. The limitation is that full PHP SSH server capability requires a separate effort (deferred in `plans/x-xpty.md`).

### Directory Structure

```
candy-wish/
├── src/
│   ├── Server.php                    # Entry point — builds & runs middleware stack
│   ├── Middleware.php                # Interface: handle(Context, Session, callable)
│   ├── Session.php                   # Per-connection metadata (user, IP, PTY dims, env)
│   ├── Context.php                   # Immutable context with cancellation/deadline/metadata
│   ├── Transport.php                 # Interface for pluggable transport strategies
│   ├── DeadlineExceededException.php
│   ├── CancellationException.php
│   ├── Lang.php                      # i18n wrapper around SugarCraft\Core\I18n\T
│   ├── Transport/
│   │   ├── InProcessTransport.php   # Default: PTY supervisor + PosixPump + candy-pty
│   │   ├── HostSshdTransport.php      # Legacy: inline middleware on sshd's PTY
│   │   └── ChildSpawner.php           # Interface for transports that can spawn child PTY
│   ├── Middleware/
│   │   ├── Logger.php                 # Connect/disconnect JSON logging to file/stderr
│   │   ├── Auth.php                   # Username + key fingerprint allowlist
│   │   ├── RateLimit.php             # Per-IP token-bucket with flock-persisted JSON state
│   │   ├── Keepalive.php             # SSH-level keepalive via PTY master idle callback
│   │   ├── BubbleTea.php             # Terminal: mounts SugarCraft Program inline (HostSshd only)
│   │   ├── Spawn.php                 # Terminal: spawns child cmd in candy-pty (InProcess only)
│   │   ├── Subsystem.php             # Parses "subsystem <name>", dispatches to SubsystemHandler
│   │   ├── AsyncMiddleware.php       # Abstract base for promise-returning middleware
│   │   └── Auth/
│   │       ├── AuthMethods.php        # Declares auth methods, writes SSH_AUTH_METHODS banner
│   │       ├── PasswordAuth.php       # Validates user+password via callback
│   │       ├── CertificateAuth.php    # Validates X.509 peer cert via callback
│   │       └── KeyboardInteractive.php # RFC 4256 challenge-response auth
│   └── Channel/
│       ├── ChannelMsg.php            # Abstract base for RFC 4254 channel messages
│       ├── ChannelHandler.php        # Interface for handling channel messages
│       ├── DefaultChannelHandler.php # Default impl — tracks PTY state, drives ChildSpawner
│       └── Msg/
│           ├── PtyReqMsg.php         # wantPty, term, cols, rows, widthPx, heightPx
│           ├── WindowChangeMsg.php   # Terminal resize event
│           ├── ShellMsg.php          # Interactive shell request
│           ├── ExecMsg.php           # Command execution request
│           ├── SignalMsg.php         # Signal delivery
│           ├── EnvMsg.php            # Environment variable
│           └── BreakMsg.php         # Break request
├── examples/
│   ├── hello-server.php             # Minimal banner server (HostSshdTransport)
│   └── spawn-*.php                  # Spawn examples
├── lang/
│   └── en.php + 15 locales         # i18n (fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar)
├── tests/
│   ├── Middleware/
│   │   ├── AuthTest.php             # Username + fingerprint allowlist
│   │   ├── RateLimitTest.php         # Token-bucket persistence
│   │   ├── BubbleTeaTest.php        # Factory, transport-compatibility guards
│   │   └── SpawnTest.php             # ChildSpawner injection
│   └── Transport/
│       └── InProcessTransportTest.php # Middleware walk order, short-circuit
└── composer.json
```

---

## Middleware Architecture

### Middleware Interface

```php
interface Middleware
{
    // Returns void (sync) or \React\Promise\PromiseInterface (async).
    // The transport calls ->wait() before continuing the chain.
    public function handle(Context $ctx, Session $session, callable $next);
}
```

The interface mirrors PSR-15 / Express-style middleware. Middleware receives the current `Context`, `Session`, and a `$next` continuation. Each middleware can:
- Inspect / log the session
- Short-circuit by NOT calling `$next`
- Mutate the `Context` (via `withValue()`, `withCancelable()`, etc.)
- Return a `PromiseInterface` for async operations (LDAP, OAuth, database auth)

### Middleware Stack Execution Order

Both transports walk the stack the same way: registration order, with the last middleware being the outermost (first to execute). This is the classic "onion model" — each middleware wraps the next.

```
Server::new()
    ->use($a)   // first registered = innermost
    ->use($b)   // second registered = wraps 'a'
    ->use($c)   // terminal = outermost
```

Execution: `a-pre → b-pre → c-pre → (terminal runs) → c-post → b-post → a-post`

### Provided Middleware

| Middleware | Type | Transport | Purpose |
|---|---|---|---|
| `Logger` | sync | both | JSON one-line events at session start + end with elapsed time |
| `Auth` | sync | both | Username allowlist + SHA256 key fingerprint allowlist |
| `PasswordAuth` | sync | both | User+password validation via callback; reads `SSH_PASSWORD` env |
| `CertificateAuth` | sync | both | X.509 peer cert validation via callback |
| `AuthMethods` | sync | both | Declares auth methods, writes RFC 4252 banner to STDOUT |
| `KeyboardInteractive` | sync | both | RFC 4256 challenge-response; writes prompts to STDOUT, reads from STDIN |
| `RateLimit` | sync | both | Per-IP token-bucket; JSON state file with `flock(LOCK_EX)` |
| `Keepalive` | sync | both | Periodic null-byte writes via PTY master idle callback |
| `Spawn` | terminal | InProcess only | Spawns child cmd via `ChildSpawner`; never calls `$next` |
| `BubbleTea` | terminal | HostSshd only | Mounts SugarCraft Program inline; never calls `$next` |
| `Subsystem` | terminal | both | Parses `subsystem <name>`; dispatches to `SubsystemHandler` |
| `AsyncMiddleware` | abstract | both | Base for promise-returning middleware; handles ReactPHP event loop |

---

## Transport Abstraction

### Transport Interface

```php
interface Transport
{
    public function run(Context $ctx, Session $session, array $stack): void;
}
```

Two concrete implementations ship:

### `InProcessTransport` (default)

The default transport since PR5. Allocates a `candy-pty` master/slave pair via `PtySystemFactory::default()`, spawns the user's cmd as a subprocess with `controllingTerminal: true`, and pumps bytes between the supervisor's STDIN/STDOUT and the PTY master via `PosixPump`.

**Key integration points with candy-pty:**
- `PtySystemFactory::default()` resolves `PosixPtySystem` on Linux/macOS
- `PosixPump::run($master, $stdin, $stdout, $child, $opts)` drives the byte pump
- `SignalForwarder::attachSigwinch()` forwards host PTY resize → `WindowChangeMsg` → `master->resize()`
- SIGHUP → explicit `posix_kill()` teardown sequence (no auto-delivery on Linux master close)
- `PumpOptions::sshDefault()` provides SSH-appropriate timeouts and idle callbacks

**SIGWINCH forwarding** (`InProcessTransport::runChild()` lines 244-259):
```php
$sizeProvider = $this->sizeProvider ?? fn (): array => $this->readHostStdinSize($stdin, $cols, $rows);
$sigwinchAttached = SignalForwarder::attachSigwinch(
    $master,
    function () use ($handler, $session, $master, $sizeProvider): array {
        $size = $sizeProvider();
        $msg = new WindowChangeMsg(cols: $size['cols'], rows: $size['rows']);
        $handler->handleWindowChange($msg, $session);
        $master->resize($size['cols'], $size['rows']);
        return $size;
    },
);
```

**Channel handler** dispatch: `DefaultChannelHandler` receives all channel messages and calls `$spawner->runChild()` on shell/exec requests.

### `HostSshdTransport` (legacy, opt-in)

The pre-PTY-upgrade architecture. Runs the middleware chain inline in the supervisor process, where STDIN/STDOUT are the slave side of sshd's PTY. Use this when:
- You want zero subprocess overhead
- Your terminal middleware is `BubbleTea` (mounts a SugarCraft Program directly)
- You have an existing entry script that reads STDIN/echoes STDOUT directly

**Compatibility note:** `BubbleTea` middleware explicitly detects InProcessTransport via duck-typed `setTransport(ChildSpawner)` injection and throws a migration error, because mounting a Program inline would collide with the InProcessTransport's byte pump.

### Duck-Typed Transport Injection

The `Spawn` middleware and `Keepalive` need access to the PTY master to write data. Rather than coupling directly to `InProcessTransport`, these middleware expose a `setTransport(ChildSpawner $transport)` method. At stack-walk time, `InProcessTransport` calls `setTransport($this)` on any middleware that has the method. `HostSshdTransport` does NOT call `setTransport`, so `Spawn` throws at session time under HostSshd.

---

## Session Management

### Session Metadata

`Session` is a flat, `readonly`-property value object seeded from sshd environment variables (`SSH_CONNECTION`, `SSH_CLIENT`, `USER`, `TERM`, `COLUMNS`, `LINES`, `SSH_TTY`, `SSH_ORIGINAL_COMMAND`, `LANG`):

```php
final class Session
{
    public function __construct(
        public readonly string  $user,
        public readonly string  $clientHost,
        public readonly int      $clientPort,
        public readonly string  $serverHost,
        public readonly int      $serverPort,
        public readonly string  $term,
        public readonly int      $cols,
        public readonly int     $rows,
        public readonly ?string $tty,
        public readonly ?string $command,
        public readonly string   $lang,
        public readonly ?string $sessionId = null,        // populated post-handshake
        public readonly ?string $authMethod = null,         // populated post-handshake
        public readonly ?string $keyFingerprint = null,    // populated post-handshake
        public readonly ?string $clientVersion = null,     // populated post-handshake
        public readonly ?string $serverVersion = null,     // populated post-handshake
    ) {}
}
```

**`fromEnvironment()`** parses `SSH_CONNECTION` (primary) or `SSH_CLIENT` (fallback) for client/server host:port. Defaults are safe placeholders rather than exceptions — the caller decides whether to reject a malformed session.

**`withProtocolMetadata()`** creates a new Session with protocol-level fields populated after the SSH handshake completes.

**`toLogContext()`** returns an array mapping field names to values for JSON logging.

---

## Context Propagation

```php
final class Context
{
    // Immutable tree — each with*() returns a new derived context.
    public static function background(): self;           // root, never done
    public function withValue(string $k, mixed $v): self;
    public function withDeadline(\DateTimeImmutable): self;
    public function withCancelable(): self;             // must call ->cancel() explicitly
    public function cancel(?\Throwable $reason = null): void;
    public function done(): bool;                        // true when cancelled or deadline-exceeded
    public function err(): ?\Throwable;                  // DeadlineExceededException / CancellationException
    public function value(string $k): mixed;             // walks parent chain
}
```

Mirrors Go's `context.Context`. Middleware attach key-value metadata via `withValue()`, downstream middleware retrieve it via `value()`. Cancellation propagates through the chain and short-circuits transport dispatch.

---

## Authentication Patterns

### `Auth` — Username + Key Fingerprint Allowlist

```php
new Auth(
    users: ['alice', 'bob'],                                    // [] = any user
    keyFingerprints: ['SHA256:abc123...', 'SHA256:def456...'],  // [] = skip key check
);
```

Reads fingerprint from `SSH_USER_KEY_FINGERPRINT`, `SSH_USER_AUTH`, or `KEY_FINGERPRINT` (sshd sets one depending on version). Rejects with `Unauthorized. (reason)` on stderr.

### `PasswordAuth` — Callback-Based Password Validation

```php
new PasswordAuth(fn (string $user, string $password) => verify($user, $password));
```

Reads password from `SSH_PASSWORD` env var. Rejects with `Permission denied.` on stderr.

### `CertificateAuth` — X.509 Peer Certificate

```php
new CertificateAuth(
    validate: fn (string $pem, Session $s) => verifyCert($pem, $s),
    required: true,
);
```

Reads cert from `SSL_CLIENT_CERT` (Apache/mod_ssl with `FakeBasicAuth`) or `SSH_CLIENT_CERT` or `CERTIFICATE`.

### `AuthMethods` — Auth Method Declaration + Context Storage

```php
new AuthMethods(['publickey', 'password', 'keyboard-interactive']);
```

Writes RFC 4252-style banner: `SSH_AUTH_METHODS publickey password keyboard-interactive\n` to STDOUT, stores method list in Context under `auth.methods`. Downstream middleware (e.g. custom auth) can read it via `AuthMethods::fromContext($ctx)`.

### `KeyboardInteractive` — RFC 4256 Challenge-Response

Writes prompts to STDOUT, reads newline-delimited responses from STDIN, passes responses to optional validator callback. Stores responses in Context under `auth.ki.responses`.

---

## Rate Limiting Implementation

The `RateLimit` middleware uses a token-bucket algorithm with file-persisted state:

**File format:** `{"ip_addr": {"tokens": float, "last": float}}`

**Algorithm** (`RateLimit::take()`):
1. Open state file with `fopen($path, 'c+')` — creates if missing
2. `flock($fh, LOCK_EX)` — exclusive lock for atomicity across sibling processes
3. `stream_get_contents()` → `json_decode()` → map
4. For entry `$key`: `tokens = min($burst, $tokens + ($now - $last) * $ratePerSec)`
5. If `tokens < 1.0`: reject, write back updated entry
6. Otherwise: `tokens -= 1.0`, write back, accept

**Tuning:** `$burst` controls maximum concurrent tokens per IP; `$ratePerSec` controls refill rate. File locking (`LOCK_EX`) serializes concurrent updates. For high-volume deployments the persistence backend can be swapped for Redis.

---

## PTY Integration (candy-pty)

### Integration Points

| candy-pty component | candy-wish usage |
|---|---|
| `PtySystemFactory::default()` | Resolves platform-appropriate PTY backend |
| `PosixPtySystem::open($cols, $rows)` | Opens master/slave pair in `InProcessTransport` |
| `PosixPump::run()` | Pumps bytes stdin ↔ PTY master in `InProcessTransport` |
| `SignalForwarder::attachSigwinch()` | Forwards SIGWINCH → `WindowChangeMsg` → `master->resize()` |
| `PumpOptions::sshDefault()` | SSH-appropriate pump options (timeouts, idle callback) |

### PTY Lifecycle in InProcessTransport

```
1. $pair = $this->system->open($cols, $rows)       // open master/slave
2. $master = $pair->master(); $slave = $pair->slave();
3. stream_set_blocking($master->stream(), false);   // non-blocking I/O
4. $child = $slave->spawn($cmd, $env, $cols, $rows, controllingTerminal: true);
5. SIGWINCH forwarding attached if pcntl + SIGWINCH available
6. PosixPump::run() pumps bytes until child exits or EOF
7. Teardown: SIGHUP → 200ms grace → SIGKILL → close master → child->wait()
```

**Linux-specific gotcha** (documented in CALIBER_LEARNINGS): closing the PTY master does NOT auto-deliver SIGHUP to the slave-side child on Linux (unlike BSD). Children that ignore STDIN EOF (daemons, `sleep`) keep running. The canonical teardown sequence in `InProcessTransport::runChild()` finally block solves this.

---

## Channel Handler Architecture

### RFC 4254 Channel Messages

Seven message types, each a `final` class extending `ChannelMsg`:

| Message | Fields | Handler method |
|---|---|---|
| `PtyReqMsg` | `wantPty`, `term`, `cols`, `rows`, `widthPx`, `heightPx` | `handlePtyReq` |
| `WindowChangeMsg` | `cols`, `rows`, `widthPx`, `heightPx` | `handleWindowChange` |
| `ShellMsg` | `wantShell` | `handleShell` |
| `ExecMsg` | `command` (raw string) | `handleExec` |
| `SignalMsg` | `signalName` | `handleSignal` |
| `EnvMsg` | `name`, `value` | `handleEnv` |
| `BreakMsg` | — | `handleBreak` |

### DefaultChannelHandler

Tracks per-session channel state (PTY allocation, dimensions, env vars, pending command) and drives the `ChildSpawner`:

```php
handleShell(wantShell=true)  → $spawner->runChild($session, ['/bin/bash', '-l'], $env)
handleExec(command="ls")     → $spawner->runChild($session, $parsed_argv, $env)
handleWindowChange(cols, rows) → updates stored dims + $master->resize()
```

The handler is injectable into `InProcessTransport` via `setChannelHandler()`, enabling custom wiring (e.g. debug handler that just logs all messages).

---

## Comparison with Upstream and Third-Party

### vs. charmbracelet/wish (Go)

| Aspect | Go (wish) | PHP (candy-wish) |
|---|---|---|
| SSH transport | Native Go SSH via `charmbracelet/ssh` | Delegated to OpenSSH via ForceCommand |
| PTY | `gliderlabs/ssh` built-in PTY | `candy-pty` FFI-based |
| Host key generation | Auto-generated ED25519 | No host keys (sshd handles) |
| Git server middleware | Yes (git-receive-pack, etc.) | No — future leaf lib |
| SCP middleware | Yes (full SCP protocol) | No — future leaf lib |
| BubbleTea integration | `bubbletea.Middleware` | `BubbleTea` (HostSshd only) |
| Middleware execution | First-to-last (onion) | Same |
| Async middleware | Goroutine-based | ReactPHP Promise-based |
| Rate limiting | LRU-cached token bucket | flock-persisted token bucket |
| Context propagation | Go `context.Context` | PHP `Context` (mirrors API) |

**Key architectural difference:** Go wish implements the full SSH server in-process. CandyWish depends on sshd. This makes candy-wish much simpler (no KEX, no cipher negotiation, no host key management) but means it can't operate standalone without OpenSSH installed.

### vs. WhispPHP/whisp (Pure PHP SSH Server)

| Aspect | WhispPHP/whisp | SugarCraft/candy-wish |
|---|---|---|
| SSH protocol | Complete in-process implementation | Delegated to OpenSSH |
| Encryption | AES-256-GCM with Curve25519 DH | None (sshd handles) |
| Host keys | Ed25519 + RSA generated | None (sshd handles) |
| Process model | `pcntl_fork()` per connection | One PHP process per connection (forked by sshd) |
| PTY | FFI-based (like candy-pty) | `candy-pty` |
| Auth | Ed25519/RSA key, password, KI | sshd handles; middleware validates sshd's result |
| SFTP/SCP | No | Stub only |
| Middleware | None | Express-style composable pipeline |
| SugarCraft integration | None | Deep (candy-pty, sugar-bits via BubbleTea) |

**Fundamental difference:** Whisp is a complete SSH server application. CandyWish is a middleware framework that expects to run under an existing SSH server. Whisp has no concept of middleware composition, while candy-wish builds entirely around it.

### vs. charmbracelet/promwish (Prometheus Metrics)

Promwish exposes SSH session metrics (created/finished/duration) to Prometheus. CandyWish has no built-in metrics middleware (deferred to `candy-metrics` in the SugarCraft ecosystem). The `SessionMetrics` middleware in `candy-metrics` follows a similar pattern — wrap session lifecycle — but generalizes beyond SSH to any server using `Context`/`Session`.

### vs. charmbracelet/wishlist (SSH Directory)

Wishlist is a consumer of `charmbracelet/wish` — it serves a TUI over SSH that lists configured endpoints. The SugarCraft equivalent of wishlist would use `candy-wish` for the SSH serving layer and `sugar-bits` for the TUI listing. The `sshconfig` parser (`wishlist/sshconfig/parse.go`) is a candidate for a future `candy-sshconfig` leaf lib.

---

## Key Implementation Details

### AsyncMiddleware Base Class

```php
abstract class AsyncMiddleware implements Middleware
{
    // handle() wraps handleAsync() with ReactPHP event loop integration.
    // await() blocks until promise settles (30s timeout).
    abstract protected function handleAsync(
        Context $ctx,
        Session $session,
        callable $next
    ): Promise\PromiseInterface;
}
```

The base handles promise awaited synchronously via `Loop::run()` in a spin loop. This allows LDAP/OAuth/database auth without full async architecture.

### Transport Dispatch

Both transports use identical dispatch logic (lines 349-370 in `InProcessTransport`, lines 36-57 in `HostSshdTransport`):
```php
private function dispatch(Context $ctx, Session $session, array $stack, int $idx): void
{
    if ($idx >= count($stack)) return;
    if ($ctx->done()) return;
    $next = fn (Context $c, Session $s) => $this->dispatch($c, $s, $stack, $idx + 1);
    $wrappedNext = function (Context $c, Session $s) use ($next): void {
        $r = $next($c, $s);
        if ($r instanceof PromiseInterface) $r->wait();
    };
    $result = $stack[$idx]->handle($ctx, $session, $wrappedNext);
    if ($result instanceof PromiseInterface) $result->wait();
}
```

### i18n Pattern

All user-facing error messages route through `Lang::t('namespace.key', [...])` wrapping `SugarCraft\Core\I18n\T`. Strings live in `lang/en.php` (source of truth) and 15 translated locales. This keeps error messages consistent and translatable.

### ChildSpawner Interface

```php
interface ChildSpawner
{
    // Returns exit code.
    public function runChild(Session $session, array $cmd, ?array $env = null): int;
}
```

Currently only `InProcessTransport` implements it. The interface enables `Spawn` middleware to be tested against a fake without needing a real PTY.

---

## Strengths

1. **Middleware composability** — Clean `Middleware::handle()` interface with async support via ReactPHP promises. Easy to add custom middleware (see `Banner` example in `hello-server.php`).

2. **Transport abstraction** — The `InProcessTransport` vs `HostSshdTransport` split elegantly handles the two deployment modes: full PTY subprocess spawning (arbitrary shells, editors) vs. zero-overhead inline program mounting.

3. **Deep candy-pty integration** — Signal forwarding (SIGWINCH → window resize), proper SIGHUP teardown, non-blocking pump with idle callbacks for keepalive. All of candy-pty's capabilities are accessible.

4. **Context propagation** — Mirrors Go's `context.Context` API, enabling cancellation propagation, deadline enforcement, and key-value metadata across the middleware chain.

5. **Channel handler extensibility** — Custom `ChannelHandler` implementation can replace the default PTY/shell wiring entirely. `ChannelHandler` is injectable into `InProcessTransport`.

6. **Clean async story** — `AsyncMiddleware` abstract base with ReactPHP event loop integration provides a straightforward path to async auth back-ends (LDAP, OAuth, database). The 30-second timeout prevents hung connections.

7. **Comprehensive i18n** — 16 locales (en + 15 translations) with dotted-key translation lookups. All user-facing strings are translatable.

8. **Immutable Session** — `readonly` properties, `withProtocolMetadata()` returns a new instance. Session objects can be safely passed through middleware without mutation concerns.

9. **Context-aware auth** — `AuthMethods::fromContext()` lets downstream middleware inspect which auth methods were offered and which succeeded, enabling policy decisions.

10. **Testable architecture** — `ChildSpawner` interface enables unit testing of `Spawn` middleware without PTY. `setTransport()` duck-typing enables testing middleware behavior under each transport.

---

## Weaknesses

1. **Depends on OpenSSH** — No standalone SSH server; requires host sshd configuration. Can't be used in environments where OpenSSH is unavailable or in containers without sshd access.

2. **No SFTP/SCP implementation** — `Subsystem` middleware has a `SftpStub` (demo only, not a real implementation). The full SFTP protocol and SCP implementations are deferred.

3. **No Git server middleware** — Go wish ships a complete git server with on-demand bare repo creation and public-key authorization hooks. Not yet ported.

4. **BubbleTea transport lock-in** — `BubbleTea` only works under `HostSshdTransport`. The new default `InProcessTransport` requires migrating to `Spawn` with a wrapper script for SugarCraft Programs. This dual-mode complexity is a known migration burden.

5. **Rate limiting persistence bottleneck** — `flock(LOCK_EX)` on every connection. Under high concurrency with many IPs, file locking could become a bottleneck. Redis backend option exists as a pattern but isn't implemented.

6. **No host key management** — Since sshd handles SSH, candy-wish has no way to verify client host keys against a known_hosts file. Host key verification is entirely sshd's responsibility.

7. **Single-process-per-connection model** — Each SSH session = one PHP process (forked by sshd). This is fine for most use cases but could be memory-intensive for very large numbers of concurrent sessions.

8. **Keepalive only works with InProcessTransport** — Under `HostSshdTransport`, keepalive relies on sshd's own `ClientAliveInterval` configuration. Middleware can't inject keepalive bytes in HostSshd mode.

9. **Command string parsing is basic** — `DefaultChannelHandler::parseCommandString()` handles basic quoting but doesn't perform full shell parsing. Edge cases with complex shell syntax could misparse.

10. **SIGWINCH forwarding requires pcntl** — If `ext-pcntl` is absent, window resize forwarding silently degrades. No polling fallback is implemented.

---

## SugarCraft Ecosystem Position

### Dependencies

```
candy-wish
├── candy-core    # Core: SugarCraft\Core\I18n\T, event loop patterns
└── candy-pty     # PTY: PosixPump, PosixPtySystem, SignalForwarder, PumpOptions
```

### Dependent Libraries

CandyWish is a leaf-level library in the dependency graph. It is not depended on by other SugarCraft libs — it consumes them.

### Related SugarCraft Libraries

| Library | Relationship |
|---|---|
| `candy-core` | Dependency — provides `Context`, i18n, exception types |
| `candy-pty` | Dependency — PTY syscalls, pump, signal forwarding |
| `sugar-bits` | Potential consumer — BubbleTea middleware mounts sugar-bits Programs over SSH |
| `candy-metrics` | Parallel — `SessionMetrics` middleware pattern mirrors promwish; both are SSH session middleware |
| `candy-sshconfig` | Future — wishlist's `sshconfig.ParseFile()` would be a natural `candy-sshconfig` leaf lib |

---

## Notable Caliber Learnings

From `CALIBER_LEARNINGS.md`:

1. **No `pcntl_fork()` in PHPUnit** — carry opcache/FFI state into fork; tests hang silently. Use `proc_open()` fixture scripts instead.

2. **PTY read distinguishes `null` vs `''`** — `null` = timeout/no data; `''` = EOF. Pump loop must not exit on single empty read.

3. **Linux: closing PTY master does NOT auto-deliver SIGHUP** — explicit `posix_kill(SIGHUP)` required; 200ms grace then SIGKILL. Canonical implementation in `InProcessTransport::runChild()` finally block.

4. **SIGWINCH tests are racy** — poll side-effect file for baseline before mutating + signaling; never use fixed `usleep()` for synchronization.

5. **Context parameter must update ALL middleware + transports simultaneously** — partial updates cause type errors at runtime.

6. **Channel messages dispatched through ChannelHandler** — not handled inline. 7 `ChannelMsg` subclasses + `ChannelHandler` interface; `DefaultChannelHandler` is composition root.

---

## Innovation Points

1. **Transport Abstraction for PTY Semantics** — The `InProcessTransport` / `HostSshdTransport` split is a novel solution to the "I need a controlling terminal but also need zero subprocess overhead" tradeoff. The Go original doesn't face this because its PTY handling is native.

2. **Async Middleware via ReactPHP** — The `AsyncMiddleware` base class provides a clean bridge between synchronous middleware and async authentication back-ends, using ReactPHP's event loop as a blocking-wait engine rather than a full async runtime.

3. **Per-IP Token-Bucket with flock** — Simple, dependency-free rate limiting that works correctly across sibling sshd-spawned PHP processes via `LOCK_EX` on a shared JSON file.

4. **Channel Handler Pattern** — RFC 4254 channel messages dispatched through a `ChannelHandler` interface with 7 message types. Enables custom session wiring without modifying transport code.

5. **candy-pty as a Reusable FFI Layer** — The deep integration with `candy-pty` (PosixPump, SignalForwarder, PtySystemFactory) means candy-wish benefits from all candy-pty improvements without changes.

---

*Report generated from analysis of `candy-wish/` source tree at commit `HEAD`. All implementation references are from the working directory at `/home/sites/sugarcraft/candy-wish/`.*
