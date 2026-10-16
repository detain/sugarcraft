# charmbracelet/wish

## Metadata

- **URL:** https://github.com/charmbracelet/wish
- **Language:** Go
- **Stars:** 5,233
- **License:** MIT
- **Module:** `charm.land/wish/v2`

## Description

Wish is an SSH server library with sensible defaults and a collection of middlewares that makes building SSH apps really easy. It is built on top of `gliderlabs/ssh` (now `github.com/charmbracelet/ssh`) and provides a clean abstraction for writing SSH server applications — no touching of `openssh-server` is required. Wish renders TUIs directly over SSH connections and supports secure communication with user identification via SSH keys.

## Feature List

- **SSH Server Core** — `NewServer()` with auto-generated ED25519 host keys, address binding, version/banner configuration
- **Middleware Pipeline** — Composable middleware chain (first-to-last execution order), analogous to HTTP framework middlewares
- **Bubble Tea Integration** — Serve any Bubble Tea TUI application over SSH with native PTY, window resize, and input/output piping per session
- **Git Server Middleware** — Full git server support via `git-receive-pack` / `git-upload-pack` / `git-upload-archive`, with on-demand repo creation and public-key authorization hooks
- **Logging Middleware** — Connect/disconnect logging with remote address, command, TERM, window dimensions, auth method
- **Access Control Middleware** — Restrict which shell commands are allowed per session
- **Active Terminal Middleware** — Require a PTY to be attached (reject non-interactive connections)
- **Rate Limiting Middleware** — Per-IP LRU-cached token-bucket rate limiters
- **Panic Recovery Middleware** — Recover from panics in handlers with stack trace logging
- **SCP File Transfer Middleware** — Full SCP protocol implementation for server↔client file copy with recursive directory support
- **Elapsed Time Middleware** — Log session duration
- **Comment Middleware** — Print footer text at end of session
- **Command Execution Helper** — `Cmd` struct wrapping `exec.Cmd` with automatic PTY wiring
- **Authorized Keys Auth** — `WithAuthorizedKeys()` to allowlist users via OpenSSH `authorized_keys` files
- **Certificate Authority Auth** — `WithTrustedUserCAKeys()` for SSH certificate-based authorization
- **Public Key / Password / Keyboard-Interactive Auth** — Configurable auth handlers

## Key Classes and Methods

### Core (`wish.go`)

- `Middleware func(next ssh.Handler) ssh.Handler` — The middleware type signature
- `NewServer(ops ...ssh.Option) (*ssh.Server, error)` — Creates default server with auto host-key generation and sensible defaults
- `Fatal(s ssh.Session, v ...interface{})` — Print to STDERR and exit 1
- `Fatalf(s ssh.Session, f string, v ...interface{})` — Formatted fatal
- `Fatalln(s ssh.Session, v ...interface{})` — Fatal with newline
- `Error(s ssh.Session, v ...interface{})` / `Errorf()` / `Errorln()` — Print to STDERR
- `Print(s ssh.Session, v ...interface{})` / `Printf()` / `Println()` / `WriteString()` — Print to STDOUT

### Options (`options.go`)

- `WithAddress(addr string) ssh.Option` — Set listen address
- `WithVersion(version string) ssh.Option` — Set SSH protocol version string
- `WithBanner(banner string) ssh.Option` — Set server banner
- `WithBannerHandler(h ssh.BannerHandler) ssh.Option` — Dynamic banner via handler
- `WithMiddleware(mw ...Middleware) ssh.Option` — Attach middleware pipeline to server
- `WithHostKeyPath(path string) ssh.Option` — Load/generate ED25519 host key from file
- `WithHostKeyPEM(pem []byte) ssh.Option` — Set host key from PEM bytes
- `WithAuthorizedKeys(path string) ssh.Option` — OpenSSH authorized_keys allowlist
- `WithTrustedUserCAKeys(path string) ssh.Option` — SSH certificate CA authorization
- `WithPublicKeyAuth(h ssh.PublicKeyHandler) ssh.Option` — Public key auth handler
- `WithPasswordAuth(p ssh.PasswordHandler) ssh.Option` — Password auth handler
- `WithKeyboardInteractiveAuth(h ssh.KeyboardInteractiveHandler) ssh.Option` — Keyboard-interactive auth
- `WithIdleTimeout(d time.Duration) ssh.Option` — Idle connection timeout
- `WithMaxTimeout(d time.Duration) ssh.Option` — Absolute connection timeout
- `WithSubsystem(key string, h ssh.SubsystemHandler) ssh.Option` — Subsystem handler (e.g. sftp)

### Command Execution (`cmd.go`, `cmd_unix.go`, `cmd_windows.go`)

- `type Cmd struct{ sess ssh.Session, cmd *exec.Cmd }` — Wraps exec.Cmd with SSH PTY
- `Command(s ssh.Session, name string, args ...string) *Cmd` — Create command with session PTY as stdio
- `CommandContext(ctx context.Context, s ssh.Session, name string, args ...string) *Cmd` — With context
- `Cmd.Run()` — Execute with PTY fallback to direct session stdio
- `Cmd.SetEnv()`, `Cmd.Environ()`, `Cmd.SetDir()` — Command environment/directory helpers
- Implements `tea.ExecCommand` interface for Bubble Tea integration

### Middleware Packages

#### `bubbletea/tea.go`

- `type Handler func(sess ssh.Session) (tea.Model, []tea.ProgramOption)` — User-defined TUI factory
- `type ProgramHandler func(sess ssh.Session) *tea.Program` — Low-level program factory
- `Middleware(handler Handler) wish.Middleware` — Attach Bubble Tea app to SSH
- `MiddlewareWithProgramHandler(handler ProgramHandler) wish.Middleware` — For custom program access
- `MakeOptions(sess ssh.Session) []tea.ProgramOption` — Input/output options for SSH session

#### `git/git.go`

- `type AccessLevel int` — `NoAccess`, `ReadOnlyAccess`, `ReadWriteAccess`, `AdminAccess`
- `type Hooks interface` — `AuthRepo(repo string, key ssh.PublicKey) AccessLevel`, `Push()`, `Fetch()`
- `Middleware(repoDir string, gh Hooks) wish.Middleware` — Full git server
- `EnsureRepo(dir, repo string) error` — Create bare repo on demand
- `Fatal(s ssh.Session, v ...interface{})` — Git pkt-line format error reporting

#### `logging/logging.go`

- `Middleware() wish.Middleware` — Basic printf-style logging
- `MiddlewareWithLogger(logger Logger) wish.Middleware` — Custom logger
- `StructuredMiddleware() wish.Middleware` — Structured key-value logging via charm.land/log
- `StructuredMiddlewareWithLogger(logger *log.Logger, level log.Level) wish.Middleware`

#### `accesscontrol/accesscontrol.go`

- `Middleware(cmds ...string) wish.Middleware` — Whitelist allowed commands; reject others

#### `activeterm/activeterm.go`

- `Middleware() wish.Middleware` — Reject sessions without active PTY

#### `ratelimiter/ratelimiter.go`

- `type RateLimiter interface` — `Allow(s ssh.Session) error`
- `Middleware(limiter RateLimiter) wish.Middleware`
- `NewRateLimiter(r rate.Limit, burst int, maxEntries int) RateLimiter` — LRU-cached per-IP token bucket
- `ErrRateLimitExceeded` — Error returned on limit exceeded

#### `recover/recover.go`

- `Middleware(mw ...wish.Middleware) wish.Middleware` — Panic recovery with stack trace logging
- `MiddlewareWithLogger(logger Logger, mw ...wish.Middleware) wish.Middleware`

#### `scp/` (full SCP protocol implementation)

- `type Handler interface{ CopyToClientHandler, CopyFromClientHandler }`
- `Middleware(rh CopyToClientHandler, wh CopyFromClientHandler) wish.Middleware`
- `type Op byte` — `OpCopyToClient` ('f'), `OpCopyFromClient` ('t')
- `GetInfo(cmd []string) Info` — Parse scp command flags
- `FileEntry`, `DirEntry`, `RootEntry` — Protocol entry types with SCP wire format serialization

#### `elapsed/elapsed.go`

- `Middleware() wish.Middleware` — Logs session duration after completion
- `MiddlewareWithFormat(format string) wish.Middleware`

#### `comment/comment.go`

- `Middleware(comment string) wish.Middleware` — Prints trailing text after session

## Notable Algorithms / Named Patterns

- **Middleware Pipeline Composition** — Middlewares are composed first-to-last; the last registered middleware is the outermost (first to execute). This mirrors the "洋葱模型" / "onion model" where each middleware wraps the next in the chain, with execution happening inside-out then result unwinding.
- **LRU Rate Limiting** — `ratelimiter` uses an LRU cache mapping remote IP → `rate.Limiter` token bucket, bounded to `maxEntries` to prevent memory unbounded growth.
- **PTY Fallback Pattern** — `Cmd.Run()` checks `sess.Pty()` — if no PTY is allocated, falls back to using the SSH session directly as stdio. This allows the same code to work in both interactive and non-interactive contexts.
- **Git Protocol pkt-line Formatting** — The `git` middleware uses SSH's stdout for git protocol messages, which uses length-prefixed ASCII hex pkt-lines (`000ahello\n`).
- **On-demand Bare Repo Creation** — `EnsureRepo()` creates bare git repositories on first push, mirroring `git init --bare`.
- **Bubble Tea SSH Window Resize Goroutine** — The bubbletea middleware spawns a goroutine per session that listens on the `windowChanges` channel and sends `tea.WindowSizeMsg` to the program on resize events.
- **SCP Protocol State Machine** — The SCP middleware parses `-f`/`-t`/`-r` flags and manages `C` (file), `D` (directory), `T` (timestamp), `E` (end) protocol entries over the wire.

## Strengths

- **Excellent ergonomics** — One-line `wish.NewServer()` with auto host-key generation and sensible defaults makes getting an SSH server running extremely fast
- **Middleware composability** — Clean middleware type `func(next ssh.Handler) ssh.Handler` enables easy stacking and reuse; middleware chaining order is intuitive
- **Native Bubble Tea integration** — Seamless SSH + TUI marriage: each SSH session gets its own `tea.Program`, PTY is auto-wired, window resize events are forwarded natively
- **Real-world tested** — Powers [Soft Serve](https://github.com/charmbracelet/soft-serve), a production self-hosted Git server, proving the design at scale
- **No external dependencies on OpenSSH** — Complete Go-native SSH implementation via `charmbracelet/ssh`, no need to install or configure `openssh-server`
- **Comprehensive auth options** — Public key, password, keyboard-interactive, authorized_keys files, and SSH certificate CA — covers all common SSH auth scenarios
- **Git server built-in** — The `git` middleware provides a complete git server with on-demand repo creation and public-key auth hooks, saving enormous amounts of boilerplate
- **Well-structured packages** — Each middleware is a self-contained Go package with clear interfaces and comprehensive tests
- **Context propagation** — `ssh.Session.Context()` is properly propagated to `exec.CommandContext` and goroutines, enabling proper cancellation

## Weaknesses

- **Go only** — No non-Go port exists or is feasible (goroutine-based async, direct syscall PTY handling)
- **Single-threaded per-connection model** — Each SSH session runs in a goroutine; large numbers of concurrent sessions could have memory/CPU overhead compared to event-driven models
- **PTY dependency for some middleware** — `bubbletea.Middleware` and `activeterm.Middleware` require PTY allocation; non-interactive sessions (e.g. Git, SCP-only servers) need to be carefully ordered in the middleware chain
- **Rate limiter LRU cache unbounded by time** — The `ratelimiter` cache only evicts on access-order; idle connections' limiters stay in memory forever if `maxEntries` is large enough
- **Git middleware spawns external `git` binary** — `gitRun()` executes the system `git` binary rather than using pure-Go git library for all operations (though it uses `go-git` for repo init)
- **No built-in session persistence or reconnection** — SSH sessions are ephemeral; Wish doesn't attempt to provide multiplexed or resumable sessions
- **Error handling in middleware chains is fire-and-forget** — Errors returned from handlers propagate but there's no standardized error response format across middlewares
- **Documentation scattered** — The `UPGRADE_GUIDE_V2.md` is detailed but there is no consolidated API reference beyond GoDoc

## SugarCraft Mapping

Wish is an SSH server framework — it doesn't map 1:1 to any single SugarCraft library since SugarCraft focuses on TUI component ports (Bubble Tea → PHP) rather than network servers. However, there are indirect relationships:

| Wish Component | SugarCraft Equivalent | Notes |
|---|---|---|
| `bubbletea.Middleware` (serve TUI over SSH) | `sugar-bits`, `sugar-forms` | PHP TUI libraries would need SSH transport layer to achieve same result. SugarCraft TUIs currently run in terminal only. |
| `git.Middleware` (git server with hooks) | `candy-core` (process execution) | Git operations could be wired through SugarCraft's shell/process abstractions, but no direct git server equivalent. |
| `logging.Middleware` (connection logging) | `(none)` | No SSH server library in SugarCraft — no logging middleware equivalent. |
| `ratelimiter.Middleware` (per-IP throttling) | `(none)` | No SSH-level rate limiting; could be implemented per-request in API layer but not equivalent. |
| `accesscontrol.Middleware` (command allowlist) | `(none)` | No SSH command restriction equivalent in SugarCraft. |
| `recover.Middleware` (panic recovery) | `candy-core` error handling | Core may have panic recovery patterns; worth reviewing. |
| `scp.Middleware` (file transfer) | `sugar-bits` file components | Not equivalent — SCP is a specific protocol, not just file I/O. |
| `Cmd` / PTY execution helper | `candy-pty` (FFI/syscall PTY wrappers) | `candy-pty` wraps terminal I/O; `Cmd` is the SSH-wrapped version of the same concept. |
| `WithAuthorizedKeys` (public key auth) | `SugarCraft\Core\Auth` | Auth by SSH key vs password — different credential systems but same conceptual job. |

**Overall:** Wish = SSH transport + Bubble Tea integration + Git server + middleware toolkit. The closest SugarCraft analogue for the *TUI-over-transport* concept is `sugar-bits` / `sugar-forms`, but without an SSH transport layer these can't be served remotely. If SugarCraft ever adds a network/TUI bridge, `wish/bubbletea` would be the canonical upstream reference.

## Analysis

**charmbracelet/wish** is a focused, well-designed SSH application framework that demonstrates the Charm team's talent for turning complex protocol interactions into delightful developer experiences. The core insight is that SSH is not just a shell protocol — it is a transport layer for interactive applications, file transfer (git, scp), and remote execution. Wish leans fully into this, providing first-class middleware support for serving TUIs over SSH (via Bubble Tea), running Git servers, and logging/filtering connections.

The architecture is notably clean: the core `wish.go` provides only server bootstrapping (`NewServer`) and session I/O helpers (`Fatal`, `Print`, etc.), while all substantive behavior lives in composable middleware packages. This keeps the API surface small and predictable. The `Middleware` type signature `func(next ssh.Handler) ssh.Handler` is a textbook example of the adapter/decorator pattern, and the first-to-last execution ordering is both intuitive and well-documented.

The `bubbletea` middleware is arguably the crown jewel — it solves the genuinely hard problem of bridging SSH PTY semantics (window resizes, terminal modes, signal delivery) with Bubble Tea's concurrency model (goroutines, channels, `tea.Program`). Each SSH session spawns its own `tea.Program` in a goroutine, with a dedicated window-resize forwarder, and proper cleanup via `program.Kill()` and context cancellation on session end. This level of care suggests the authors have dealt with real production edge cases.

The main limitation of Wish is that it is Go-only and fills a niche (SSH servers) that has no natural PHP equivalent. SugarCraft's mission is porting Charm's TUI ecosystem to PHP — not building SSH infrastructure. However, the middleware composition pattern and the idea of bridging a transport layer (SSH) to a TUI framework (Bubble Tea) are conceptually transferable. If SugarCraft ever adds an async I/O layer (e.g., ReactPHP-based SSH server), the Wish middleware design would be an excellent reference for how to structure middleware in that context.

---

*Report generated from analysis of commit `HEAD` (depth=1 clone). All code references are from the cloned repository at `/tmp/repo_map/charmbracelet_wish`.*
