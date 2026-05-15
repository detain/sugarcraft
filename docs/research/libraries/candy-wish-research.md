# SSH Server Middleware Patterns Research: candy-wish

**Date:** 2026-05-13
**Status:** Research Complete
**Library:** candy-wish (SSH server middleware framework)

---

## Executive Summary

candy-wish currently implements middleware composition and session handling that parallels Go's `charmbracelet/wish`, but relies on external sshd for the SSH wire protocol. This research compares patterns across Go, Rust, Python, and PHP SSH implementations to identify improvements for session handling, authentication, channel management, PTY spawning, and middleware composition.

**Key Finding:** Most SSH server libraries separate connection-level concerns (auth, session channels) from application-level middleware. candy-wish's flat middleware stack conflates these. The most significant improvement would be introducing a proper **connection/session分层** (layered) handler pattern.

---

## 1. Current Architecture Analysis

### 1.1 Session Handling

**Current:** `Session::fromEnvironment()` parses SSH connection metadata from `$_SERVER`/`getenv()` - a reasonable approach for ForceCommand deployments.

**Limitations:**
- No access to actual SSH channel information (session ID, reserved bytes)
- No per-channel state tracking
- Cannot handle multiple channels per connection (subsystem forwarding, etc.)

**Source:** `candy-wish/src/Session.php:L35-L91`

```php
final class Session
{
    public function __construct(
        public readonly string $user,
        public readonly string $clientHost,
        public readonly int    $clientPort,
        public readonly string $serverHost,
        public readonly int    $serverPort,
        public readonly string $term,
        public readonly int    $cols,
        public readonly int    $rows,
        public readonly ?string $tty,
        public readonly ?string $command,
        public readonly string $lang,
    ) {}
}
```

### 1.2 Middleware Composition

**Current:** Linear middleware chain with `$next` continuation pattern.

**Source:** `candy-wish/src/Middleware.php:L22-L24`

```php
interface Middleware
{
    public function handle(Session $session, callable $next): void;
}
```

**Issues vs upstream wish:**
- Wish (Go) uses functional middleware: `func(next ssh.Handler) ssh.Handler`
- gliderlabs/ssh uses context propagation: `Context` interface with `SetValue()`/`Value()`
- russh uses async trait methods with session state
- asyncssh uses class-based session handlers with lifecycle hooks

### 1.3 Authentication

**Current:** Simple allowlist-based `Auth` middleware checking users and key fingerprints.

**Source:** `candy-wish/src/Middleware/Auth.php:L60-L74`

```php
public function handle(Session $session, callable $next): void
{
    if ($this->users !== [] && !in_array($session->user, $this->users, true)) {
        $this->reject('user not allowed: ' . $session->user);
        return;
    }
    if ($this->keyFingerprints !== []) {
        $fp = $this->fingerprint();
        if ($fp === null || !in_array($fp, $this->keyFingerprints, true)) {
            $this->reject('key not allowed: ' . ($fp ?? '<missing>'));
            return;
        }
    }
    $next($session);
}
```

**Limitations:**
- No support for password authentication
- No keyboard-interactive (2FA/challenge-response)
- No certificate-based auth
- No auth method negotiation (only what sshd already authenticated)
- No per-auth-method callbacks with context

### 1.4 Transport Layer

**Two transports:**
1. `InProcessTransport` (default): Allocates candy-pty, spawns subprocess
2. `HostSshdTransport` (legacy): Runs middleware inline against sshd's PTY

**Source:** `candy-wish/src/Transport/InProcessTransport.php:L137-L151`

```php
public function run(Session $session, array $stack): void
{
    foreach ($stack as $mw) {
        if (\method_exists($mw, 'setTransport')) {
            $mw->setTransport($this);
        }
    }
    $this->dispatch($session, $stack, 0);
}
```

**Issues:**
- Transport injection via duck-typed `setTransport()` is fragile
- No interface for transports that don't spawn children
- No way to access channel-level events (pty-req, window-change, signals)

---

## 2. External Library Analysis

### 2.1 Go: charmbracelet/wish

**Architecture:** Built on gliderlabs/ssh, provides middleware helpers and sensible defaults.

**Middleware Pattern:**
```go
// wish middleware is a function that wraps the next handler
type Middleware func(next ssh.Handler) ssh.Handler

// Usage
wish.WithMiddleware(
    logging.Middleware(),
    myAppMiddleware,
)
```

**Session Context:** Uses gliderlabs/ssh Context with `ssh.Context` interface:
```go
type Context interface {
    context.Context
    User() string
    RemoteAddr() net.Addr
    SessionID() []byte
    SetValue(key interface{}, value interface{})
    Value(key interface{}) interface{}
    Permissions() *Permissions
}
```

**Source:** [charmbracelet/wish](https://github.com/charmbracelet/wish)

**Key Patterns:**
1. Middleware composes from outside-in (last registered runs first)
2. Context propagation through the handler chain
3. `ssh.Handler` is a simple function: `func(ssh.Session)`
4. Decorator pattern for middleware - each middleware returns a new handler

### 2.2 Go: gliderlabs/ssh

**Architecture:** Lower-level SSH server library that wish builds on.

**Session Handler:**
```go
func DefaultSessionHandler(srv *Server, conn *gossh.ServerConn, newChan gossh.NewChannel, ctx Context) {
    ch, reqs, err := newChan.Accept()
    sess := &session{
        Channel:           ch,
        conn:              conn,
        handler:           srv.Handler,
        ptyCb:             srv.PtyCallback,
        sessReqCb:         srv.SessionRequestCallback,
        subsystemHandlers: srv.SubsystemHandlers,
        ctx:               ctx,
    }
    sess.handleRequests(reqs)
}
```

**Key Features:**
- `Server` struct with callbacks for auth, pty, session requests
- `Context` for request-scoped state
- `PtyCallback`, `SessionRequestCallback`, `SubsystemHandler` hooks
- Channel-level request handling (shell, exec, pty-req, window-change, env, signal, break)

**Source:** [gliderlabs/ssh](https://github.com/gliderlabs/ssh)

### 2.3 Go: Containerssh sshserver

**Architecture:** Layered handler interface with distinct phases.

**Handler Interface (multi-layer):**
```go
type Handler interface {
    OnNetworkConnection(connectionID string, remoteAddr net.Addr) (NetworkConnectionHandler, error)
}

type NetworkConnectionHandler interface {
    OnAuthPassword(connectionID string, user string, password string) (AuthResult, *AuthMetaData, error)
    OnAuthPublicKey(connectionID string, user string, key ssh.PublicKey) (AuthResult, *AuthMetaData, error)
    // ... other auth methods
    OnAuthSuccess(connectionID string, user string, metadata *AuthMetaData) (SSHConnectionHandler, error)
}

type SSHConnectionHandler interface {
    OnSessionChannel(connectionID string, sessionID string, channel ssh.Channel, env map[string]string, command string) (SessionChannelHandler, error)
}

type SessionChannelHandler interface {
    OnExec(connectionID string, sessionID string, command string) error
    OnPty(connectionID string, sessionID string, term string, width uint32, height uint32) error
    OnSignal(connectionID string, sessionID string, signal ssh.Signal) error
    // ...
}
```

**Key Insight:** Separates connection lifecycle into distinct interfaces:
- `NetworkConnectionHandler` - pre-auth, connection-level
- `SSHConnectionHandler` - post-auth, handles channels
- `SessionChannelHandler` - per-channel, handles exec/pty/shell

**Source:** [containerssh/sshserver](https://github.com/containerssh/sshserver)

### 2.4 Rust: russh

**Architecture:** Async tokio-based, trait-based handlers.

**Handler Trait:**
```rust
#[async_trait]
impl server::Handler for Server {
    type Error = russh::Error;

    async fn auth_password(&mut self, user: &str, password: &str) -> Result<server::Auth, Self::Error> {
        info!("credentials: {user}, {password}");
        Ok(server::Auth::Accept)
    }

    async fn auth_publickey(
        &mut self,
        user: &str,
        public_key: &ssh_key::PublicKey,
    ) -> Result<server::Auth, Self::Error> {
        Ok(server::Auth::Accept)
    }

    async fn channel_open_session(
        &mut self,
        channel: Channel<Msg>,
        session: &mut Session,
    ) -> Result<bool, Self::Error> { /* ... */ }

    async fn pty_request(
        &mut self,
        channel: ChannelId,
        term: &str,
        col: u32,
        row: u32,
        xpix: u32,
        ypix: u32,
        session: &mut Session,
    ) -> Result<(), Self::Error> { /* ... */ }

    async fn shell_request(
        &mut self,
        channel: ChannelId,
        session: &mut Session,
    ) -> Result<(), Self::Error> { /* ... */ }
}
```

**Key Features:**
- Async/await with tokio
- Per-connection handler instance (clone for each connection)
- Channel ID for tracking multiple channels
- Session handle for server-initiated messages

**Source:** [russh](https://github.com/warp-tech/russh)

### 2.5 Python: asyncssh

**Architecture:** Async library with class-based handlers.

**Server Pattern:**
```python
class MySSHServer(asyncssh.SSHServer):
    def connection_made(self, conn: asyncssh.SSHServerConnection) -> None:
        peername = conn.get_extra_info('peername')[0]
        print(f'SSH connection received from {peername}.')

    def begin_auth(self, username: str) -> bool:
        return passwords.get(username) != b''

    def password_auth_supported(self) -> bool:
        return True

    def validate_password(self, username: str, password: str) -> bool:
        return bcrypt.checkpw(password.encode('utf-8'), passwords[username])

class MySSHServerSession(asyncssh.SSHServerSession):
    def connection_made(self, chan: asyncssh.SSHServerChannel):
        self._chan = chan

    def session_started(self) -> None:
        self._chan.write('Welcome!\n')

    def data_received(self, data: str, datatype: asyncssh.DataType) -> None:
        # handle input

    def eof_received(self) -> bool:
        self._chan.exit(0)
        return False

    def break_received(self, msec: int) -> bool:
        return self.eof_received()

class MySSHServer(asyncssh.SSHServer):
    def session_requested(self) -> asyncssh.SSHServerSession:
        return MySSHServerSession()
```

**Key Features:**
- Lifecycle hooks: `connection_made`, `connection_lost`, `begin_auth`
- Per-session class with `session_started`, `data_received`, `eof_received`, `break_received`
- PTY support via `process.term_size`, `process.term_type`
- Line editor channel support: `SSHLineEditorChannel`

**Source:** [asyncssh](https://github.com/ronf/asyncssh)

### 2.6 PHP: phpseclib

**Architecture:** Client-side SSH implementation (not a server).

**Note:** phpseclib is primarily a client library, not a server. It provides:
- `SSH2::login()` with multiple auth methods
- `SSH2::exec()` for command execution
- `SSH2::startSubsystem()` for subsystems
- Agent forwarding support

**Key Insight for Server:** phpseclib shows how to implement SSH protocol details in PHP, but does not help with server-side middleware.

---

## 3. Comparative Analysis

### 3.1 Authentication Methods

| Library | Password | PublicKey | Keyboard-Interactive | Certificate | Agent |
|---------|----------|-----------|---------------------|-------------|-------|
| candy-wish | ❌ (relies on sshd) | ✅ allowlist | ❌ | ❌ | ❌ |
| wish (Go) | ✅ callback | ✅ callback | ✅ callback | ❌ | ❌ |
| gliderlabs/ssh | ✅ callback | ✅ callback | ✅ callback | ❌ | ✅ callback |
| russh | ✅ | ✅ | ✅ | ✅ | ✅ |
| asyncssh | ✅ | ✅ | ✅ | ✅ | ✅ |
| ContainerSSH | ✅ | ✅ | ✅ | ✅ | ✅ |

**candy-wish Gap:** All auth methods are handled by sshd. candy-wish can only filter post-auth (user list, key fingerprint). It cannot:
- Implement its own password auth
- Implement 2FA/challenge-response
- Inspect which auth method was used

### 3.2 Middleware Patterns

| Library | Pattern | Context | Channel Events |
|---------|---------|---------|----------------|
| candy-wish | Linear `$next` chain | Session from env | ❌ |
| wish (Go) | Decorator (func wrap) | ssh.Context | ❌ |
| gliderlabs/ssh | Callbacks on Server | Context with SetValue | ✅ |
| russh | Async trait methods | Handler state | ✅ |
| asyncssh | Class lifecycle | Per-session class | ✅ |
| ContainerSSH | Layered interfaces | Explicit params | ✅ |

**Key Insight:** Most libraries separate:
1. **Connection-level** handling (auth, metadata)
2. **Channel-level** handling (exec, pty, subsystem)
3. **Application-level** middleware (logging, rate limiting)

candy-wish conflates 1 and 2 into a flat middleware stack.

### 3.3 Session/Channel State

| Library | Session ID | Channel ID | Per-Channel State |
|---------|------------|------------|-------------------|
| candy-wish | ❌ | ❌ | ❌ |
| gliderlabs/ssh | ✅ ctx.SessionID() | ✅ channel id | ✅ session.ctx |
| russh | ✅ | ✅ | ✅ Handler state |
| asyncssh | ✅ conn.get_session_id() | ✅ chan.id() | ✅ per-session class |

**candy-wish Gap:** No access to SSH session or channel identifiers.

---

## 4. Specific Improvements

### 4.1 High Priority: Context Propagation

**Problem:** Middleware cannot store per-connection state. Auth middleware cannot pass data to downstream middleware.

**Pattern to Add:**
```php
interface Context
{
    public function set(string $key, mixed $value): void;
    public function get(string $key, mixed $default = null): mixed;
    public function has(string $key): bool;
}
```

**Changes:**
1. Add `Context` class with array storage
2. Add `Server::withContext(Context $ctx)` - injected or created
3. Add `Session::withContext(Context $ctx)` - passed through chain
4. Middleware receives both: `handle(Session $session, callable $next, Context $ctx)`

**Source Reference:** gliderlabs/ssh Context pattern

**Effort:** Medium (refactor Middleware interface, update all middleware)

### 4.2 High Priority: Channel Event Middleware

**Problem:** No handling of SSH channel requests (pty-req, window-change, shell, exec, signal, env, break).

**Pattern to Add:**
```php
interface ChannelHandler
{
    public function onPtyRequest(Session $session, string $term, int $cols, int $rows): void;
    public function onWindowChange(Session $session, int $cols, int $rows): void;
    public function onShell(Session $session): void;
    public function onExec(Session $session, string $command): void;
    public function onSignal(Session $session, string $signal): void;
    public function onEnv(Session $session, string $key, string $value): void;
    public function onBreak(Session $session, int $msec): void;
}
```

**Changes:**
1. Add `ChannelHandler` interface
2. Add `Server::withChannelHandler(ChannelHandler $h)`
3. Transport implementations call handler at appropriate times

**Source Reference:** gliderlabs/ssh session.handleRequests()

**Effort:** Medium-High (new interface, transport changes)

### 4.3 Medium Priority: Expanded Auth Middleware

**Problem:** Can only filter by user/key allowlist after sshd auth.

**Patterns to Add:**

**a) Password Auth Callback:**
```php
final class PasswordAuth implements Middleware
{
    public function __construct(
        private readonly callable $validator, // fn(string $user, string $password): bool
    ) {}

    public function handle(Session $session, callable $next): void
    {
        // Would require native SSH server or sshd integration
    }
}
```

**Note:** This requires native SSH server (deferred) or sshd integration via `ForceCommand` with `keyboard-interactive` auth.

**b) 2FA/KDI Support:**
```php
final class KeyboardInteractive implements Middleware
{
    public function __construct(
        private readonly callable $challenge, // fn(string $user, list<string> prompts): list<string>
    ) {}
}
```

**c) Certificate Auth Support:**
```php
final class CertificateAuth implements Middleware
{
    public function __construct(
        private readonly list<string> $allowedCAKeys, // SHA256:... fingerprints
    ) {}
}
```

**Source Reference:** wish WithKeyboardInteractiveAuth, russh auth_keyboard_interactive

**Effort:** Low (Auth middleware additions) - but limited by sshd dependency

### 4.4 Medium Priority: Subsystem Support

**Problem:** No support for SSH subsystems (sftp, rsync, etc.).

**Pattern to Add:**
```php
final class Subsystem implements Middleware
{
    /** @var array<string, callable(Session): void> */
    private array $handlers = [];

    public function register(string $name, callable $handler): void
    {
        $this->handlers[$name] = $handler;
    }

    public function handle(Session $session, callable $next): void
    {
        // Check SSH_ORIGINAL_COMMAND for subsystem name
        // Route to registered handler
    }
}
```

**Source Reference:** gliderlabs/ssh SubsystemHandlers

**Effort:** Low (new middleware class)

### 4.5 Medium Priority: Connection Metadata

**Problem:** Session only has env vars, no SSH protocol metadata.

**Pattern to Add:**
```php
final class Session
{
    // ... existing properties ...

    // NEW: SSH protocol metadata
    public readonly ?string $sessionId;     // SSH session ID
    public readonly ?string $authMethod;    // how user authenticated
    public readonly ?string $keyFingerprint; // if pubkey auth used
    public readonly ?string $clientVersion; // SSH client version string
    public readonly ?string $serverVersion; // SSH server version string
}
```

**Source Reference:** gliderlabs/ssh ssh.ConnMetadata

**Effort:** Low (extend Session class)

### 4.6 Low Priority: Multi-Channel Support

**Problem:** Only one active channel per connection handled.

**Pattern:** Future enhancement when implementing native SSH server.

**Effort:** High (requires native SSH protocol handling)

### 4.7 Low Priority: Async/Await Pattern

**Problem:** Synchronous only; cannot integrate with ReactPHP event loop.

**Pattern to Add:**
```php
interface AsyncMiddleware
{
    public function handleAsync(Session $session, \React\Promise\PromiseInterface $next): \React\Promise\PromiseInterface;
}
```

**Source Reference:** ReactPHP documentation

**Effort:** Medium (new interface, async pump loop)

---

## 5. Implementation Priority Matrix

| Improvement | Impact | Effort | Risk | Priority |
|-------------|--------|--------|------|----------|
| Context Propagation | High | Medium | Low | **P1** |
| Channel Event Middleware | High | Medium-High | Medium | **P1** |
| Expanded Session Metadata | Medium | Low | Low | **P2** |
| Subsystem Support | Medium | Low | Low | **P2** |
| Enhanced Auth Middleware | Medium | Low | Low | **P2** |
| Async Middleware | Medium | Medium | Medium | P3 |
| Multi-Channel | Low | High | High | Deferred |

---

## 6. Recommended Next Steps

### Phase 1: Context and Session Metadata (P1)

1. **Add `Context` class** with thread-safe array storage
2. **Update `Middleware` interface** to include context: `handle(Session, callable, Context)`
3. **Update all existing middleware** to accept and pass through context
4. **Extend `Session`** with SSH protocol metadata (sessionId, authMethod, keyFingerprint, clientVersion, serverVersion)
5. **Update `Server::serve()`** to create or inject context

**Files to modify:**
- `src/Session.php` - add properties, update fromEnvironment
- `src/Context.php` - new file
- `src/Middleware.php` - update interface
- `src/Middleware/Auth.php` - update
- `src/Middleware/Logger.php` - update
- `src/Middleware/RateLimit.php` - update
- `src/Middleware/Keepalive.php` - update
- `src/Middleware/Spawn.php` - update
- `src/Middleware/BubbleTea.php` - update

### Phase 2: Channel Handler Layer (P1)

1. **Add `ChannelHandler` interface** with all SSH channel request types
2. **Update `Transport` interface** to include channel handler
3. **Update `InProcessTransport`** to call channel handler for pty-req, window-change
4. **Update `HostSshdTransport`** similarly
5. **Create `ChannelHandlerMiddleware`** adapter to bridge channel handler to middleware chain

**Files to modify:**
- `src/ChannelHandler.php` - new file
- `src/Transport.php` - add channel handler
- `src/Transport/InProcessTransport.php` - emit channel events
- `src/Transport/HostSshdTransport.php` - emit channel events

### Phase 3: Enhanced Auth Patterns (P2)

1. **Add `PasswordAuth` middleware** - for deployments with sshd password auth
2. **Add `CertificateAuth` middleware** - for CA-based key validation
3. **Add `AuthMethods` middleware** - to constrain which auth methods are accepted

**Files to add/modify:**
- `src/Middleware/PasswordAuth.php` - new
- `src/Middleware/CertificateAuth.php` - new
- `src/Middleware/AuthMethods.php` - new

### Phase 4: Subsystem Support (P2)

1. **Add `Subsystem` middleware** with registered handlers
2. **Add `SftpSubsystem` example** handler

**Files to add:**
- `src/Middleware/Subsystem.php` - new
- `src/Middleware/Subsystem/SftpHandler.php` - new

---

## 7. Deferred: Native SSH Server

**Note:** The current architecture relies on sshd for the SSH wire protocol. A native PHP SSH server would enable:

- Full auth method implementation (password, KDI, certificate)
- Native channel management (multiple channels, port forwarding)
- Agent forwarding
- SFTP server implementation
- Direct X11 forwarding

This is tracked separately under `plans/x-xpty.md` Option A.

---

## 8. References

### Code Sources

- **candy-wish:** `/home/sites/sugarcraft/candy-wish/src/`
- **charmbracelet/wish:** https://github.com/charmbracelet/wish
- **gliderlabs/ssh:** https://github.com/gliderlabs/ssh
- **ContainerSSH:** https://github.com/containerssh/sshserver
- **russh:** https://github.com/warp-tech/russh
- **asyncssh:** https://github.com/ronf/asyncssh
- **phpseclib:** https://github.com/phpseclib/phpseclib

### Documentation

- [wish documentation](https://pkg.go.dev/github.com/charmbracelet/wish)
- [gliderlabs/ssh session handling](https://github.com/gliderlabs/ssh/blob/master/session.go)
- [russh Handler trait](https://docs.rs/russh/latest/russh/server/trait.Handler.html)
- [asyncssh server patterns](https://github.com/ronf/asyncssh/blob/develop/docs/index.md)

---

## Appendix A: Middleware Comparison Matrix

| Feature | candy-wish | wish | gliderlabs | russh | asyncssh |
|---------|------------|------|------------|-------|----------|
| Interface style | Interface | Func decorator | Interface + callbacks | Async trait | Class hierarchy |
| Context propagation | ❌ | ✅ | ✅ | ✅ (handler state) | ✅ (per-session) |
| Auth callbacks | Limited | ✅ | ✅ | ✅ | ✅ |
| Channel events | ❌ | ❌ | ✅ | ✅ | ✅ |
| PTY handling | ✅ (via transport) | ✅ (via Session) | ✅ (session) | ✅ | ✅ |
| Subsystems | ❌ | ❌ | ✅ | ✅ | ✅ |
| Signal handling | Limited | ✅ | ✅ | ✅ | ✅ |
| Agent forwarding | ❌ | ❌ | ✅ | ✅ | ✅ |

---

## Appendix B: Pattern Quick Reference

### Decorator Middleware Pattern (Go wish)
```go
func LoggingMiddleware(next ssh.Handler) ssh.Handler {
    return func(s ssh.Session) {
        log.Printf("user=%s addr=%v", s.User(), s.RemoteAddr())
        next(s)
    }
}
```

### Lifecycle Hook Pattern (asyncssh)
```python
class MySSHServerSession(asyncssh.SSHServerSession):
    def connection_made(self, chan):
        self._chan = chan
    def session_started(self):
        self._chan.write('Welcome!\n')
    def data_received(self, data, datatype):
        self._chan.write(data)
    def eof_received(self):
        self._chan.exit(0)
        return False
```

### Layered Handler Pattern (ContainerSSH)
```go
type Handler interface {
    OnNetworkConnection(id string, addr net.Addr) (NetworkConnectionHandler, error)
}
type NetworkConnectionHandler interface {
    OnAuthPassword(id, user, password string) (AuthResult, error)
    OnAuthSuccess(id, user string) (SSHConnectionHandler, error)
}
type SSHConnectionHandler interface {
    OnSessionChannel(id string, channel ssh.Channel) (SessionChannelHandler, error)
}
```

---

*Research compiled from external library analysis. All code snippets are from library documentation and examples.*