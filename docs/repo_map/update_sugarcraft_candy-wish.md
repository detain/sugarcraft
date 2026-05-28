# Overview

CandyWish is a PHP port of `charmbracelet/wish` — an SSH server middleware framework that lets developers build TUIs anyone can `ssh user@host` to run. The library provides composable middleware (auth, logging, rate-limiting), pluggable transport strategies (InProcessPTY or HostSshd inline), and deep integration with `candy-pty` for full pseudo-terminal semantics.

**Biggest opportunity areas:**
- Full SFTP subsystem implementation (currently stub only)
- Git server middleware (upstream has complete implementation)
- SCP protocol support
- Metrics middleware integration with `candy-metrics`
- Mosh support for connection resilience

**Biggest missing capabilities:**
- No standalone SSH server (depends on OpenSSH)
- No in-process SFTP/SCP protocols
- No git-receive-pack/git-upload-pack middleware
- No authorized_keys hot-reloading
- No banner/MOTD middleware

# Internal Capability Summary

## Current Architecture

CandyWish uses a deliberate architectural tradeoff: it delegates SSH to the host OpenSSH daemon rather than implementing the SSH wire protocol from scratch. Each SSH connection forks a fresh PHP process under `sshd` via `ForceCommand`.

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

**Two transport strategies:**
- `InProcessTransport` (default): Allocates a `candy-pty` master/slave pair, spawns user's cmd as subprocess with controlling terminal semantics, pumps bytes via `PosixPump`
- `HostSshdTransport` (legacy): Runs middleware chain inline where STDIN/STDOUT are sshd's PTY slave

**Middleware stack execution:** Registration order, last registered is outermost (onion model): `a-pre → b-pre → c-pre → (terminal runs) → c-post → b-post → a-post`

## Current Features

| Component | Status |
|-----------|--------|
| Middleware interface (sync + async via ReactPHP) | ✅ Complete |
| Context propagation (mirrors Go context.Context) | ✅ Complete |
| Session metadata from SSH env vars | ✅ Complete |
| Logger middleware (JSON one-line events) | ✅ Complete |
| Auth middleware (username + key fingerprint allowlist) | ✅ Complete |
| PasswordAuth middleware (callback-based) | ✅ Complete |
| CertificateAuth middleware (X.509 peer cert) | ✅ Complete |
| AuthMethods middleware (RFC 4252 banner) | ✅ Complete |
| KeyboardInteractive middleware (RFC 4256) | ✅ Complete |
| RateLimit middleware (token-bucket, flock-persisted) | ✅ Complete |
| Keepalive middleware (PTY master idle callback) | ✅ Complete |
| Spawn middleware (InProcess only, terminal) | ✅ Complete |
| BubbleTea middleware (HostSshd only, terminal) | ✅ Complete |
| Subsystem middleware (partial SFTP stub) | ⚠️ Stub only |
| Channel handler (7 RFC 4254 message types) | ✅ Complete |
| i18n (16 locales) | ✅ Complete |

## APIS

**Middleware interface:**
```php
interface Middleware {
    public function handle(Context $ctx, Session $session, callable $next);
    // Returns void or \React\Promise\PromiseInterface
}
```

**Transport interface:**
```php
interface Transport {
    public function run(Context $ctx, Session $session, array $stack): void;
}
```

**Server builder:**
```php
Server::new()
    ->use(new Logger('/var/log/wish.jsonl'))
    ->use(new RateLimit('/var/lib/wish/buckets.json', burst: 5, ratePerSec: 0.5))
    ->use(new Auth(users: ['alice', 'bob']))
    ->use(new Spawn(fn (Session $s) => ['cmd' => ['/bin/bash', '-l']]))
    ->serve();
```

## Strengths

1. **Middleware composability** — Clean `Middleware::handle()` interface with async support via ReactPHP promises
2. **Transport abstraction** — `InProcessTransport` vs `HostSshdTransport` elegantly handles two deployment modes
3. **Deep candy-pty integration** — Signal forwarding (SIGWINCH → window resize), proper SIGHUP teardown, non-blocking pump
4. **Context propagation** — Mirrors Go's `context.Context` API enabling cancellation/deadline enforcement
5. **Channel handler extensibility** — Custom `ChannelHandler` implementation injectable into `InProcessTransport`
6. **Clean async story** — `AsyncMiddleware` abstract base provides path to async auth back-ends
7. **Comprehensive i18n** — 16 locales with dotted-key translation lookups
8. **Immutable Session** — `readonly` properties, `withProtocolMetadata()` returns new instance
9. **Testable architecture** — `ChildSpawner` interface enables unit testing without real PTY

## Weaknesses

1. **Depends on OpenSSH** — No standalone SSH server; requires host sshd configuration
2. **No SFTP/SCP implementation** — `Subsystem` middleware has `SftpStub` only (demo, not real implementation)
3. **No Git server middleware** — Go wish ships complete git server; not yet ported
4. **BubbleTea transport lock-in** — `BubbleTea` only works under `HostSshdTransport`; `InProcessTransport` requires `Spawn`
5. **Rate limiting persistence bottleneck** — `flock(LOCK_EX)` on every connection under high concurrency
6. **No host key management** — Since sshd handles SSH, no way to verify client host keys
7. **Single-process-per-connection model** — Each SSH session = one PHP process; memory-intensive for many concurrent
8. **Keepalive only works with InProcessTransport** — `HostSshdTransport` relies on sshd's `ClientAliveInterval`
9. **Command string parsing is basic** — `parseCommandString()` handles basic quoting but not full shell parsing
10. **SIGWINCH forwarding requires pcntl** — If `ext-pcntl` absent, window resize forwarding silently degrades

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority level |
|------|-----------|---------------------------|----------------|
| `charmbracelet/wish` (Go) | Direct upstream | Full SSH server, git middleware, SCP, BubbleTea integration, middleware patterns | Critical |
| `WhispPHP/whisp` (PHP) | Pure PHP SSH | Complete SSH wire protocol, Ed25519/RSA auth, AES-256-GCM encryption, Curve25519 DH | High |
| `charmbracelet/promwish` (Go) | Metrics middleware | SSH session metrics, Prometheus integration, Server lifecycle patterns | Medium |
| `charmbracelet/x` (Go) | PTY abstraction | `xpty` cross-platform PTY, `vt` virtual terminal, `cellbuf` damage tracking | Medium |
| `charmbracelet/charm` (Go) | SSH auth patterns | SSH PKAM auth, JWT via SSH session, self-hosted server patterns | Low |

# Feature Gap Analysis

## Critical

### 1. SFTP Subsystem Implementation
**Description:** The `Subsystem` middleware has only a `SftpStub` demonstrating wiring — no actual SFTP protocol implementation. Go wish ships `WithSubsystem` via `github.com/pkg/sftp`.

**Why it matters:** SFTP is the standard file transfer mechanism over SSH. OpenSSH 9.0 changed default from SCP to SFTP. Without SFTP, candy-wish cannot serve files securely over SSH.

**Source:** `docs/repo_map/charmbracelet_wish.md` (PR #224), `docs/repo_map/pr_charmbracelet_wish.md` (Issue #40)

**Implementation ideas:**
- Port or wrap `github.com/pkg/sftp` PHP equivalent
- Use `phpseclib/phpseclib` for SFTP protocol handling
- Implement `SubsystemHandler` interface for sftp subsystem

**Estimated complexity:** High — requires SSH_FXP protocol implementation

**Expected impact:** High — enables production file transfer use cases

### 2. Git Server Middleware
**Description:** Go wish ships `git-receive-pack` and `git-upload-pack` middleware for serving git repos over SSH with on-demand bare repo creation and public-key authorization.

**Why it matters:** Git hosting over SSH is a major use case for wish. Without it, candy-wish is missing a key differentiator.

**Source:** `docs/repo_map/sugarcraft_candy-wish.md`, `docs/repo_map/charmbracelet_wish.md`

**Implementation ideas:**
- Create `candy-git` leaf library
- Implement `git-receive-pack` and `git-upload-pack` handlers
- Add authorized_keys-based repo authorization

**Estimated complexity:** High — requires git protocol knowledge

**Expected impact:** Medium — niche but valuable for specific use cases

### 3. Authorized Keys Hot-Reloading
**Description:** Go wish (PR #88) refreshes `authorized_keys` files without server restart, like OpenSSH.

**Why it matters:** Production deployments need to add/remove users without restarting the SSH server process.

**Source:** `docs/repo_map/pr_charmbracelet_wish.md` (Issue #82)

**Implementation ideas:**
- Watch authorized_keys via inotify/fsevents
- Cache with TTL, re-read on new auth attempts
- Make `Auth` middleware support callable that returns current allowlist

**Estimated complexity:** Medium

**Expected impact:** High — production deployment requirement

## High Value

### 4. Banner/MOTD Middleware
**Description:** Go wish has `WithBanner`/`WithBannerHandler` for MOTD-style text at session start.

**Why it matters:** Session start banners are standard SSH practice for ToS acceptance, welcome messages, system status.

**Source:** `docs/repo_map/pr_charmbracelet_wish.md` (PR #210, Issue #205)

**Implementation ideas:**
- Create `Banner` middleware that writes to STDOUT before chain continues
- Support both static string and callback for dynamic content
- Support acceptance flow (press Y to accept ToS)

**Estimated complexity:** Low

**Expected impact:** Medium — common SSH server feature

### 5. Rate Limit Before Auth Pattern
**Description:** Issue #325 in Go wish reveals auth handlers execute BEFORE middleware regardless of ordering — rate limiting happens after auth, defeating brute-force protection.

**Why it matters:** Security-critical: rate limiting should execute before authentication to prevent brute-force attacks.

**Source:** `docs/repo_map/pr_charmbracelet_wish.md` (Issue #325)

**Implementation ideas:**
- Document that RateLimit should be first middleware
- Consider connection callback mechanism for truly pre-auth rate limiting
- Add connection-level callbacks separate from request-level middleware

**Estimated complexity:** Medium — may require architectural change

**Expected impact:** High — security improvement

### 6. Recovery/Panic Middleware
**Description:** Go wish has `recover.Middleware` for panic recovery with stack trace logging.

**Why it matters:** Production servers need to handle unexpected errors gracefully without crashing the PHP process.

**Source:** `docs/repo_map/charmbracelet_wish.md`

**Implementation ideas:**
- Create `Recovery` middleware wrapping chain in try/catch
- Log stack trace to configured logger
- Return graceful error message to client

**Estimated complexity:** Low

**Expected impact:** Medium — production robustness

### 7. Access Control Middleware
**Description:** Go wish's `accesscontrol.Middleware` restricts which shell commands can execute per session.

**Why it matters:** Multi-tenant environments need command allowlisting for security.

**Source:** `docs/repo_map/charmbracelet_wish.md`

**Implementation ideas:**
- Create `AccessControl` middleware with command allowlist/denylist
- Support glob patterns and regex for command matching
- Log rejected command attempts

**Estimated complexity:** Medium

**Expected impact:** Medium — security/compliance feature

## Medium Priority

### 8. Metrics Integration with candy-metrics
**Description:** `promwish` exposes SSH session metrics to Prometheus. `candy-metrics` has `SessionMetrics` middleware but not yet integrated with candy-wish.

**Why it matters:** Production deployments need observability — session counts, durations, error rates.

**Source:** `docs/repo_map/charmbracelet_promwish.md`, `docs/repo_map/pr_charmbracelet_promwish.md`

**Implementation ideas:**
- Create `SugarCraft\Wish\Middleware\Metrics` that uses `candy-metrics` SessionMetrics
- Expose `wish.session.connect`, `wish.session.duration`, `wish.session.error` counters
- Add `extraTags` callback for custom labels (user, term, command)

**Estimated complexity:** Low

**Expected impact:** Medium — production observability

### 9. Command String Parsing Improvement
**Description:** `DefaultChannelHandler::parseCommandString()` handles basic quoting but doesn't perform full shell parsing.

**Why it matters:** Complex shell commands with pipes, redirections, or quotes may misparse.

**Source:** `docs/repo_map/sugarcraft_candy-wish.md` (weakness #9)

**Implementation ideas:**
- Use ` Symfony\Component\Console\Input\ArgvInput` for parsing
- Or wrap `pexec` style parser from shell-helper library
- Document limitations and provide workarounds

**Estimated complexity:** Medium

**Expected impact:** Low — edge case for most use cases

### 10. Color Profile / Terminal Capability Detection
**Description:** Go wish has issues with color rendering in Docker/systemd due to terminal capability detection failures.

**Why it matters:** Containerized deployments may lack full terminal capabilities, causing broken color rendering.

**Source:** `docs/repo_map/pr_charmbracelet_wish.md` (Issues #45, #350, #456)

**Implementation ideas:**
- Provide fallback conservative color profile
- Allow forced color profile override via environment
- Document systemd/Docker requirements

**Estimated complexity:** Low

**Expected impact:** Medium — container deployment compatibility

## Low Priority

### 11. Mosh Support
**Description:** Issue #455 requests Mosh support for connection resilience on unstable networks.

**Why it matters:** Mosh provides better handling of connection interruptions — important for mobile clients.

**Source:** `docs/repo_map/pr_charmbracelet_wish.md` (Issue #455)

**Implementation ideas:**
- Research `tsshd` Go implementation as integration point
- Consider long-term if demand grows

**Estimated complexity:** Very High — requires UDP-based protocol

**Expected impact:** Low — niche use case

### 12. SSH Proxy/Router ("Nginx for SSH")
**Description:** Issue #488 requests forwarding SSH sessions to other SSH servers based on logic beyond just port.

**Why it matters:** Users want path-based or user-based SSH routing.

**Source:** `docs/repo_map/pr_charmbracelet_wish.md` (Issue #488)

**Implementation ideas:**
- Create `ProxyMiddleware` for forwarding to backend SSH servers
- Support routing based on user, command, or environment

**Estimated complexity:** High — requires SSH client implementation

**Expected impact:** Low — niche enterprise feature

### 13. Windows PTY Support
**Description:** Go wish integrates `x/xpty` for ConPTY support on Windows.

**Why it matters:** Windows development workflows may need SSH access to Windows servers.

**Source:** `docs/repo_map/pr_charmbracelet_wish.md` (PR #522), `docs/repo_map/charmbracelet_x.md`

**Implementation ideas:**
- Leverage `candy-pty` cross-platform support when available
- Document Windows limitations
- Consider ConPTY FFI integration in candy-pty

**Estimated complexity:** High — Windows-specific

**Expected impact:** Low — development use case

# Algorithm / Performance Opportunities

## Current Approach vs External

### Rate Limiting: flock vs LRU Cache

**Current (candy-wish):** Token-bucket with `flock(LOCK_EX)` on JSON file per IP.

**External (Go wish ratelimiter):** LRU cache with max entries, no time-based eviction.

**Why external may be better:** `flock(LOCK_EX)` serializes concurrent updates across sibling processes. Under high concurrency with many unique IPs, file locking becomes bottleneck. LRU cache avoids serialization but has unbounded memory growth risk.

**Tradeoffs:** candy-wish's file-based approach persists state across process restarts. LRU is faster but ephemeral.

**Applicability:** Consider Redis backend option for high-throughput deployments. Add time-based eviction to prevent memory growth.

### PTY Teardown: Explicit SIGHUP vs Master Close

**Current (candy-wish):** Canonical teardown: explicit `posix_kill(SIGHUP)` → 200ms grace → `SIGKILL` → close master → `child->wait()`.

**Why necessary:** Linux doesn't auto-deliver SIGHUP when PTY master closes (BSD behavior).

**External (Go wish):** `gliderlabs/ssh` handles this natively.

**Lesson:** This is an innovation point — the explicit teardown sequence solves a real Linux gotcha.

### SIGWINCH Forwarding: Polling vs pcntl

**Current (candy-wish):** Uses `SignalForwarder::attachSigwinch()` when pcntl available; silently degrades without it.

**Why external is better:** Go's signal handling is more reliable across platforms.

**Tradeoffs:** PHP's pcntl limitations require fallback handling.

**Applicability:** Document pcntl requirement clearly. Consider polling fallback for non-pcntl environments.

### Duration Metrics: Histogram vs Counter

**Current (candy-metrics SessionMetrics):** Proper histogram with configurable buckets for duration.

**External (promwish):** Counter (cumulative seconds), not histogram — cannot see percentiles.

**Why current is better:** Histograms reveal percentile distributions (p50, p99) which are more actionable than totals.

# Architecture Improvements

## 1. Connection Callback Mechanism

**Problem:** Issue #325 reveals auth handlers execute before middleware in Go wish. Rate limiting should happen before auth for brute-force protection.

**Proposed:** Add `ConnectionCallback` interface separate from middleware:
```php
interface ConnectionCallback {
    public function onConnect(Session $session): void;
    public function onDisconnect(Session $session, int $exitCode): void;
}
```

**Benefits:** Enables truly pre-auth rate limiting, connection logging, metrics at connection level.

## 2. Backend Lifecycle Interface

**Problem:** Go promwish evolved from implicit HTTP server goroutine to explicit `Server` struct with `ListenAndServe()`/`Shutdown()`.

**Proposed:** Add `start()`/`stop()` methods to `Backend` interface in `candy-metrics`:
```php
interface Backend {
    public function start(): void;
    public function stop(?float $timeout = null): void;
}
```

**Benefits:** Explicit lifecycle prevents implicit background process issues.

## 3. BubbleTea Transport Bridge

**Problem:** `BubbleTea` middleware only works with `HostSshdTransport`. `InProcessTransport` requires `Spawn` with wrapper script.

**Proposed:** Create unified `TeaTransport` that bridges BubbleTea programs to PTY subprocess model:
- Mount SugarCraft Program inline under HostSshd
- Wrap Program in PTY subprocess under InProcess

**Benefits:** Eliminates dual-mode complexity; single middleware works across transports.

## 4. Async Auth Handler Integration

**Problem:** Current auth middleware is synchronous. Async back-ends (LDAP, OAuth) need proper async integration.

**Proposed:** Ensure `AsyncMiddleware` base handles auth use cases cleanly. Document LDAP/OAuth integration patterns.

**Benefits:** Enables enterprise authentication back-ends.

# API / Developer Experience Improvements

## 1. Simplified Server Bootstrap

**Current:**
```php
Server::new()
    ->use(new Logger('/var/log/wish.jsonl'))
    ->use(new RateLimit('/var/lib/wish/buckets.json', burst: 5, ratePerSec: 0.5))
    ->use(new Auth(users: ['alice', 'bob']))
    ->use(new Spawn(fn (Session $s) => ['cmd' => ['/bin/bash', '-l']]))
    ->serve();
```

**Improved: Fluent config with sensible defaults:**
```php
Server::new()
    ->withLogging('/var/log/wish.jsonl')
    ->withRateLimit(burst: 5, ratePerSec: 0.5)
    ->withAuth(users: ['alice', 'bob'])
    ->withShell('/bin/bash -l')
    ->serve();
```

## 2. Builder Pattern for Middleware

**Current middleware construction can be verbose.** Consider factory methods:
```php
// Instead of new Auth(users: [...], keyFingerprints: [...])
Auth::allowUsers(['alice', 'bob'])
    ->allowKeyFingerprints(['SHA256:abc...'])
    ->denyUnknownUsers()
    ->create();
```

## 3. Better Error Messages

**Current:** Error messages route through i18n but can be cryptic.

**Proposed:** Add context to error messages:
```php
// Instead of "Unauthorized."
"Auth failed for user '{$s->user}' from {$s->clientHost}: {$reason}"
```

# Documentation / Cookbook Opportunities

## 1. Deployment Guide

**Missing:** Production deployment documentation for:
- systemd service configuration
- Docker container deployment
- nginx reverse proxy for SSH
- Firewall configuration

**Source:** Lessons from `docs/repo_map/pr_charmbracelet_wish.md` (Issues #45, #456)

## 2. Auth Backend Examples

**Missing:** Examples for integrating:
- LDAP authentication
- OAuth/JWT authentication  
- Database-backed user/permission store
- PAM integration

**Source:** `AsyncMiddleware` docstrings mention LDAP/OAuth but no examples

## 3. Multi-Tenant Hosting

**Missing:** Cookbook for shared SSH hosting:
- Per-user virtual directories
- Command restrictions per user
- Quota enforcement
- Resource limits

## 4. Reverse Proxy SSH

**Missing:** How to run candy-wish behind an SSH reverse proxy.

# UX / TUI Improvements

## 1. Better BubbleTea Integration

**Problem:** `BubbleTea` middleware only works with `HostSshdTransport`, limiting use to inline program mounting.

**Source:** `docs/repo_map/pr_charmbracelet_wish.md` (Issues #506, #196, #291) — PTY + BubbleTea subprocess integration is hard

**Solution:** Consider `TeaExec` pattern where BubbleTea Program can spawn subprocesses that properly release alt screen:
```php
// Conceptual
new BubbleTea(fn ($session) => new MyApp($session))
    ->withExec(fn ($cmd) => new TeaExecProcess($cmd));
```

## 2. Terminal Capability Fallbacks

**Problem:** Colors/rendering break in Docker/systemd without proper capability detection.

**Source:** `docs/repo_map/pr_charmbracelet_wish.md` (Issues #45, #350, #456)

**Solution:** 
- Document `CLICOLOR_FORCE=1` workaround
- Provide conservative fallback color profile
- Add environment-based auto-detection

# Testing / Reliability Improvements

## 1. Integration Test Framework

**Current:** PTY tests use `proc_open()` fixture scripts; SIGWINCH tests are racy.

**Improvement:** Create `tests/_fixtures/` with:
- SSH server mock for ForceCommand testing
- PTY fixture scripts for subprocess testing
- Network fixture for connection testing

## 2. Property-Based Testing

**Missing:** Property-based tests for:
- Command string parsing edge cases
- Token-bucket algorithm under concurrent access
- Context propagation invariants

## 3. Chaos Testing

**Missing:** Tests for:
- Network disconnection mid-session
- PTY allocation failure
- Auth timeout scenarios
- Memory exhaustion handling

# Ecosystem / Integration Opportunities

## 1. candy-metrics Integration

**Already planned:** `SessionMetrics` middleware using `candy-metrics`.

**Implementation:** 
```php
use SugarCraft\Wish\Middleware\Metrics;
use SugarCraft\Metrics\Registry;

$registry = new Registry();
Server::new()
    ->use(new Logger('/var/log/wish.jsonl'))
    ->use(new Metrics($registry))
    ->use(new Spawn(fn ($s) => ['cmd' => ['/bin/bash', '-l']]))
    ->serve();
```

## 2. sugar-bits Integration

**Already documented:** `BubbleTea` middleware mounts SugarCraft Programs over SSH.

**Improvement:** Document integration with `sugar-bits` components for rich TUI over SSH.

## 3. candy-sshconfig Parser

**Future opportunity:** Port wishlist's `sshconfig.ParseFile()` for SSH config file parsing.

**Use case:** Parse user's `~/.ssh/config` for host aliases, identity files, etc.

# Notable PRs / Issues / Discussions

## PR #522: Respect SetStdin/SetStdout/SetStderr (Dec 2025)

**Summary:** Go wish fixed PTY alt screen not releasing during BubbleTea exec by properly routing stdio handles.

**Relevance:** When integrating BubbleTea with subprocess execution, I/O handle routing is critical.

**Lessons:**
- PTY slave overrides custom stdout when set
- Need proper stdio field storage and usage
- Both Bubble Tea and subprocess want terminal ownership

**Adaptation:** Ensure `Spawn` middleware properly handles PTY vs non-PTY contexts for subprocess I/O.

## Issue #325: Auth Before Rate Limiter (Aug 2024)

**Summary:** Rate limiting in Go wish happens AFTER authentication because auth handlers live in different struct field than middleware.

**Relevance:** Critical for security. Rate limiting must execute before auth to prevent brute-force.

**Lessons:**
- Auth handlers separate from middleware is architectural flaw
- SugarCraft should ensure auth is middleware OR use connection callbacks
- Document that RateLimit should be first in chain

## Issue #196: Bubble Tea ExecProcess Within Wish Session (Dec 2023)

**Summary:** Terminal became unresponsive after subprocess exits when using BubbleTea over SSH.

**Root cause:** Both Bubble Tea and subprocess try to acquire terminal; exit doesn't properly restore terminal state.

**Solution (PR #197):** Allocated real PTY for sessions instead of PtyWriter hack.

**Relevance:** Terminal state restoration after subprocess execution is complex.

**Lessons:**
- PtyWriter hacks don't work for interactive subprocesses
- Real PTY allocation enables vim, bash, etc.
- Terminal state must be restored (exit raw mode, restore window size)

## Issue #455: Mosh Support (Apr 2025)

**Summary:** Request for Mosh (alternative SSH with better connection resilience).

**Relevance:** None immediate but signals demand for resilient SSH connections.

**Lessons:** Consider long-term if mobile client support becomes important.

## Issue #82: Refresh authorized_keys (Nov 2022)

**Summary:** Go wish implemented hot-reloading of authorized_keys without server restart.

**Relevance:** Production necessity for user management.

**Lessons:** SugarCraft should implement similar pattern via callable auth allowlist or file watching.

# Recommended Roadmap

## Immediate Wins (0-3 months)

1. **Banner middleware** — MOTD-style text at session start
2. **Recovery middleware** — Panic recovery with stack trace logging  
3. **Metrics middleware** — Integrate with `candy-metrics` SessionMetrics
4. **Authorized keys hot-reloading** — Watch file changes, re-read on new auth
5. **Rate limit before auth documentation** — Document RateLimit should be first middleware

## Medium-Term Improvements (3-6 months)

6. **Access control middleware** — Command allowlist/denylist per session
7. **SFTP subsystem** — Real implementation using phpseclib or equivalent
8. **Improved command parsing** — Better shell command parsing
9. **Color profile fallback** — Handle Docker/systemd capability detection failures
10. **Terminal capability queries** — Implement color profile detection

## Major Architectural Upgrades (6-12 months)

11. **Connection callback mechanism** — Pre-auth rate limiting, connection-level hooks
12. **BubbleTea transport bridge** — Unified middleware across both transports
13. **Async auth handler integration** — LDAP/OAuth database auth patterns
14. **Git server middleware** — `candy-git` leaf library
15. **Backend lifecycle interface** — Explicit start/stop for metrics backends

## Experimental Ideas (12+ months)

16. **Mosh support** — UDP-based connection resilience (via tsshd integration?)
17. **SSH proxy/router** — Forward sessions based on logic (enterprise feature)
18. **Windows ConPTY** — Windows PTY support in candy-pty

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|-------------|--------|------------|------|---------------------|
| Banner middleware | Medium | Low | Low | Immediate |
| Recovery middleware | Medium | Low | Low | Immediate |
| Metrics integration | Medium | Low | Low | Immediate |
| Auth hot-reloading | High | Medium | Low | Immediate |
| Rate limit ordering doc | High | Low | None | Immediate |
| Access control | Medium | Medium | Low | Medium-term |
| SFTP implementation | High | High | Medium | Medium-term |
| Command parsing | Low | Medium | Low | Medium-term |
| Color fallbacks | Medium | Low | Low | Medium-term |
| Connection callbacks | High | High | Medium | Major |
| BubbleTea bridge | Medium | High | Medium | Major |
| Async auth | Medium | High | Medium | Major |
| Git middleware | Medium | High | Medium | Major |
| Mosh support | Low | Very High | High | Experimental |
| SSH proxy | Low | High | Medium | Experimental |

# Final Strategic Assessment

CandyWish represents a well-architected port of `charmbracelet/wish` that makes deliberate tradeoffs to deliver a secure, maintainable SSH middleware framework in PHP. The decision to delegate SSH to OpenSSH via `ForceCommand` is sound — it reduces complexity dramatically while leveraging battle-tested cryptographic primitives. The middleware composability pattern is elegant and mirrors PSR-15/Express conventions familiar to PHP developers.

**Key differentiators from upstream:**
- Transport abstraction (`InProcessTransport` vs `HostSshdTransport`) provides deployment flexibility
- ReactPHP-based async middleware enables enterprise auth back-ends
- Deep `candy-pty` integration provides cross-platform PTY support
- Proper histogram-based metrics (vs Go's counter for duration)

**Strategic gaps to address:**

1. **SFTP/SCP gap** — The lack of real SFTP implementation limits production file transfer use cases. This should be the highest priority for medium-term development.

2. **Git middleware gap** — While git server functionality is niche, it represents a key differentiator for wish in the Go ecosystem. A future `candy-git` leaf library would complete the SSH serving story.

3. **Observability gap** — Integration with `candy-metrics` is documented but not implemented. Production deployments need metrics.

4. **Transport complexity** — The dual-transport model (InProcess vs HostSshd) creates complexity for users. A unified `TeaTransport` bridge could simplify this.

**Competitive positioning:**

Against **WhispPHP/whisp** (pure PHP SSH server), candy-wish takes a different approach — delegating to OpenSSH rather than implementing the full SSH wire protocol. This trades standalone capability for reduced complexity and better security (sshd handles cipher negotiation, key exchange, host keys). However, for environments where OpenSSH is unavailable, a future `candy-ssh` leaf library implementing the full SSH protocol (as Whisp does) would complete the portfolio.

**Overall assessment:** CandyWish is production-ready for its designed purpose — building TUIs over SSH with `ForceCommand` deployment. The architecture is sound, the code is well-tested, and the dependency on battle-tested OpenSSH is prudent. The roadmap should focus on filling the SFTP gap, completing observability integration, and simplifying the transport model as the highest-value improvements.
