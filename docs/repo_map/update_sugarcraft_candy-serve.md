# Overview

**candy-serve** is a PHP port of `charmbracelet/soft-serve`, implementing a self-hostable Git server over SSH, HTTP Smart Protocol, and Git Daemon. It provides multi-protocol Git access with public key authentication, access control, and optional LFS support. The package positions itself as a complete Git server solution for the command line.

**Biggest opportunity areas:** TUI implementation (the defining upstream feature), persistent storage backend, hooks system, Prometheus metrics integration, mirror/sync automation, and webhook event system.

**Biggest missing capabilities:** No terminal UI (only CLI), no actual database persistence (in-memory only), LFS is partial (handler exists but upload endpoint is TODO), no server-side hooks, no webhooks, no metrics/stats endpoint, and mirror sync not implemented.

---

# Internal Capability Summary

## Current Architecture

```
candy-serve/
├── src/
│   ├── Config.php                    YAML config loader with minimal parser
│   ├── Repo.php                      Bare Git repo model, immutable builder
│   ├── User.php                      SSH public key auth, key validation
│   ├── AccessControl.php             4-level permission system
│   ├── Lang.php                       i18n facade
│   ├── SSH/SSHServer.php             SSH session handler (library only, not embedded server)
│   ├── Git/GitDaemon.php             Socket server with select(), PID file, signals
│   ├── Git/UploadPack.php            git-upload-pack protocol
│   ├── Git/ReceivePack.php           git-receive-pack protocol
│   ├── HttpSmartProtocol/Server.php  HTTP smart protocol request handler
│   ├── LFS/LFSHandler.php            Batch API, concurrent transfers
│   ├── LFS/LocalStorageBackend.php   Filesystem storage with oid sharding
│   ├── LFS/LFSStorageBackendInterface.php
│   └── Clipboard/Osc52.php           OSC 52 clipboard protocol
├── bin/soft-serve                     Entry point CLI
└── tests/                            PHPUnit test suite
```

## Current Features

### Multi-Protocol Git Server
- **SSH**: Library-only (`SSHServer.php`), requires external sshd or `candy-wish` integration for actual serving
- **Git Daemon**: Complete socket-based implementation with concurrent connections, signal handling, PID file management (`docs/repo_map/sugarcraft_candy-serve.md`)
- **HTTP Smart Protocol**: Request handler only, no HTTP server integration (`docs/repo_map/sugarcraft_candy-serve.md`)

### User Management
- SSH public key authentication with multiple keys per user
- Supports ssh-ed25519, ssh-rsa, ecdsa-sha2-*, sk-ssh-ed25519@openssh.com
- Key normalization for whitespace-insensitive comparison (`docs/repo_map/sugarcraft_candy-serve.md`)
- Admin flag, active flag, on-demand key generation via ssh-keygen

### Repository Management
- Immutable/fluent `Repo` builder with `with*()` methods
- Bare Git repo initialization via `git init --bare`
- Collaborators list, public/private flags, mirror support (model only)
- On-demand repo creation on first push for admin users
- Four permission levels: ACCESS_NONE, ACCESS_READ, ACCESS_WRITE, ACCESS_ADMIN (`docs/repo_map/sugarcraft_candy-serve.md`)

### Git Protocol Implementation
- **UploadPack**: Refs advertisement, want negotiation, packfile generation via `git pack-objects`
- **ReceivePack**: Refs advertisement with capabilities, atomic ref updates via `git update-ref`
- **GitDaemon**: Select-based event loop, concurrent connection handling, idle timeout, connection limits

### Git LFS Support
- **LFSHandler**: Batch API implementation, concurrent transfer support
- **LocalStorageBackend**: Standard LFS path layout `{oid[0:2]}/{oid[2:4]}/{oid}`
- **LFSStorageBackendInterface**: Pluggable storage backend contract
- **Critical gap**: Handler exists but actual upload/download endpoint not implemented (`docs/repo_map/sugarcraft_candy-serve.md`)

### Configuration
- YAML config loader with minimal parser
- Default ports: SSH :23231, HTTP :23232, Git daemon :9418, Stats :23233
- Data layout: `dataPath/repositories/`, `dataPath/ssh/`, `dataPath/tmp/`

### CLI Tool
- `serve`, `init`, `user add|key|list`, `repo list|create|info` commands
- Daemon mode with pcntl_fork-based backgrounding, PID file management, graceful shutdown
- i18n with 13 locales via `Lang.php`

### Clipboard Support
- OSC 52 clipboard read/write protocol
- Supports clipboard/primary/secondary selections

## Strengths

1. **Complete multi-protocol implementation**: All three Git transports (SSH, HTTP Smart, Git Daemon) properly implemented with correct wire formats
2. **Clean domain modeling**: `Config`, `User`, `Repo`, `AccessControl` are pure domain models with single responsibilities
3. **Immutable/fluent pattern**: Every `with*()` method returns a new instance; no hidden state mutations
4. **Production-ready daemon**: Proper socket-based server with select() for concurrent connections, signal handlers, PID file management
5. **Well-tested**: Comprehensive unit tests for domain models, integration tests for GitDaemon, HttpSmartProtocol\Server
6. **i18n support**: 13 locales with namespace-isolated translation facade

## Weaknesses

1. **SSH Server is a library, not a server**: `SSHServer` is meant to be called by an external SSH server; doesn't embed its own SSH transport
2. **HTTP Server is a request handler, not a server**: `HttpSmartProtocol\Server::handleRequest()` needs ReactPHP/Swoole integration for actual HTTP serving
3. **No persistent storage**: No database layer; all data is in-memory only during server run
4. **LFS is partial**: `LFSHandler` returns action URLs but actual upload/download endpoint is TODO
5. **No TUI**: Upstream Go soft-serve has full Bubble Tea TUI for repo browsing, file viewing, commit history; candy-serve has only CLI commands
6. **Shell out to git binary**: All Git operations call `git` via exec(); no pure-PHP git library
7. **Anonymous access has no rate limiting**: Git daemon allows anonymous access with no per-IP connection limits
8. **Mirror/sync not implemented**: `Repo::withMirrorFrom()` exists but cron-based sync is not implemented

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|---------------------------|----------|
| `charmbracelet/soft-serve` | 🔴 Direct upstream | TUI over SSH, hooks, webhooks, Prometheus metrics, mirror sync, full SSH/HTTP server | Critical |
| `charmbracelet/wish` | 🟡 SSH middleware | Middleware composition, git server middleware, authorized keys auth, rate limiting | High |
| `charmbracelet/git-lfs-transfer` | 🟡 LFS protocol | Complete LFS SSH transfer, atomic uploads, file locking, pktline protocol | High |
| `charmbracelet/promwish` | 🟡 Metrics | Prometheus metrics middleware, session tracking, graceful shutdown | Medium |
| `charmbracelet/charm` | 🟡 User auth | SSH PKAM auth, JWT generation, SQLite persistence | Medium |
| `textualize/textual` | 🟡 TUI patterns | CSS-like styling, reactive state, message pump, widget system | High |
| `ratatui/ratatui` | 🟢 TUI reference | Buffer diffing, constraint-based layout, widget traits, stateful widgets | Medium |
| `charmbracelet/bubbletea` | 🟢 TUI reference | Elm architecture, command pattern, subscriptions, cell-based rendering | High |

---

# Feature Gap Analysis

## Critical Priority

### 1. TUI Implementation (The Defining Upstream Feature)
**Description:** Upstream soft-serve's crown jewel is its full Bubble Tea TUI accessible over SSH. Users can browse repos, view files with syntax highlighting, and explore commit history — all through an interactive terminal UI. candy-serve has zero TUI implementation.

**Why it matters:** The TUI is soft-serve's primary differentiator. Without it, candy-serve is just another Git server, not the "mighty, self-hostable Git server for the command line" that charmbracelet built.

**Source:** `docs/repo_map/charmbracelet_bubbletea.md`, `docs/repo_map/sugarcraft_candy-serve.md`

**Implementation ideas:**
- Port the upstream Go TUI to PHP using `candy-core` (Bubble Tea equivalent)
- Implement `RepoView` component for browsing files and commits
- Add syntax highlighting via `candy-shine` (Glamour port)
- Use `candy-zone` for mouse interactions in the TUI
- Bridge TUI over SSH using `candy-wish` (if/when it supports serving TUIs)

**Estimated complexity:** Very High (requires implementing a full Bubble Tea-equivalent TUI framework in PHP)

**Expected impact:** Transforms candy-serve from "Git server library" to "Git server application"

### 2. Persistence Layer (Database Backend)
**Description:** candy-serve has no persistence — user/repo/collab data exists only in-memory. Upstream uses SQLite/PostgreSQL. Production use requires data surviving server restarts.

**Why it matters:** Without persistence, every server restart loses all user registrations, repository metadata, and collaborator assignments. This is unacceptable for any production deployment.

**Source:** `docs/repo_map/sugarcraft_candy-serve.md`

**Implementation ideas:**
- Implement SQLite persistence using PDO
- Create `UserRepository`, `RepoRepository`, `CollaboratorRepository` classes
- Use the same adapter pattern as `LFSStorageBackendInterface`
- Add migration system for schema updates

**Estimated complexity:** High

**Expected impact:** Enables production deployment

### 3. Embedded SSH Server Integration
**Description:** The `SSHServer` class is a library that needs to be called by an external SSH server. For a self-contained Git server, it needs its own SSH transport.

**Why it matters:** Current implementation requires external sshd or manual integration with `candy-wish`. A self-contained solution is needed.

**Source:** `docs/repo_map/charmbracelet_wish.md`, `docs/repo_map/sugarcraft_candy-serve.md`

**Implementation ideas:**
- Integrate with `candy-wish` (SugarCraft's port of charmbracelet/wish) for SSH transport
- Use libssh2 PHP extension for native SSH server capability
- Implement the same middleware composition pattern as wish

**Estimated complexity:** High

**Expected impact:** Enables standalone SSH Git server without external dependencies

## High Value

### 4. HTTP Server Integration
**Description:** `HttpSmartProtocol\Server::handleRequest()` is a pure request handler with no actual HTTP server. Needs integration with ReactPHP or Swoole.

**Why it matters:** HTTP is the most accessible Git transport for clients behind firewalls that block SSH.

**Source:** `docs/repo_map/sugarcraft_candy-serve.md`

**Implementation ideas:**
- Integrate with ReactPHP's HTTP server component
- Create a `HttpServer` class that wraps `HttpSmartProtocol\Server`
- Add TLS support using ReactPHP's TLS component

**Estimated complexity:** High

**Expected impact:** Enables HTTP Git access for all clients

### 5. LFS Upload/Download Endpoint
**Description:** `LFSHandler::handleBatch()` returns action URLs but doesn't implement the actual upload/download handling endpoint.

**Why it matters:** Full LFS support requires both the batch API (complete) and the actual transfer endpoints (TODO).

**Source:** `docs/repo_map/charmbracelet_git-lfs-transfer.md`

**Implementation ideas:**
- Implement `POST /lfsobjects/` upload endpoint following git-lfs-transfer spec
- Add `GET /lfsobjects/` download endpoint
- Implement `VerifyingReader` pattern for SHA-256 verification
- Use atomic upload pattern (temp file + hardlink)

**Estimated complexity:** Medium

**Expected impact:** Complete LFS support matching upstream capability

### 6. Server-Side Hooks System
**Description:** Upstream implements pre-receive, update, and post-receive hooks. candy-serve has no hook system.

**Why it matters:** Hooks enable integration with CI systems, enforcement of commit policies, notifications, and custom logic on push events.

**Source:** `docs/repo_map/sugarcraft_candy-serve.md` (comparison table shows upstream has hooks, candy-serve doesn't)

**Implementation ideas:**
- Implement hook interface: `preReceive(Repo, User, []commands)`, `update(Repo, User, cmd)`, `postReceive(Repo, User, []commands)`
- Add hook directory support (like standard Git hooks)
- Support both script and PHP callable hooks

**Estimated complexity:** Medium

**Expected impact:** Enables CI integration and custom push policies

### 7. Webhook Event System
**Description:** Upstream soft-serve has an event system for webhooks. candy-serve has no webhooks.

**Why it matters:** Webhooks enable external systems (Slack notifications, CI triggers, backup systems) to react to Git events.

**Source:** `docs/repo_map/sugarcraft_candy-serve.md` (comparison table shows upstream has webhooks, candy-serve doesn't)

**Implementation ideas:**
- Create `WebhookEvent` types: `push`, `tag`, `user_create`, `repo_create`
- Implement webhook delivery with retries
- Add signature verification (HMAC-SHA256)
- Support configurable delivery targets and event filters

**Estimated complexity:** Medium

**Expected impact:** Enables external system integration

## Medium Priority

### 8. Prometheus Metrics Endpoint
**Description:** The config shows a `statsListenAddr` on port 23233 but no metrics implementation. Upstream has Prometheus metrics.

**Why it matters:** Observability is critical for production deployments. Metrics enable monitoring of session counts, repo access patterns, and server health.

**Source:** `docs/repo_map/charmbracelet_promwish.md`, `docs/repo_map/sugarcraft_candy-serve.md` (shows stats port in config but no implementation)

**Implementation ideas:**
- Follow `promwish` pattern: `wish_sessions_created_total`, `wish_sessions_finished_total`, `wish_sessions_duration_seconds`
- Expose `/metrics` endpoint in Prometheus format
- Add `candy-metrics` integration for session metrics middleware
- Add custom metrics: repo_operations_total, connection_count, lfs_transfer_bytes

**Estimated complexity:** Medium

**Expected impact:** Production observability

### 9. Mirror/Sync Automation
**Description:** `Repo::withMirrorFrom()` model exists but cron-based sync is not implemented.

**Why it matters:** Mirroring enables backup to external Git servers (GitHub, GitLab) and keeping forks synchronized.

**Source:** `docs/repo_map/charmbracelet_soft-serve-action.md`, `docs/repo_map/sugarcraft_candy-serve.md`

**Implementation ideas:**
- Implement mirror job scheduler using cron expression from config (`mirrorPullSchedule`)
- Add `git fetch --mirror` and `git push --mirror` operations
- Create `MirrorManager` to track and execute mirror jobs
- Add webhook trigger for mirror pushes

**Estimated complexity:** Medium

**Expected impact:** Enables automated repository backup/mirroring

### 10. Rate Limiting for Anonymous Access
**Description:** Git daemon allows anonymous access with no per-IP connection limiting (only global `gitMaxConnections`).

**Why it matters:** Prevents denial-of-service from uncontrolled anonymous access.

**Source:** `docs/repo_map/sugarcraft_candy-serve.md` (weaknesses section)

**Implementation ideas:**
- Add per-IP connection tracking
- Implement LRU cache for per-IP rate limiters
- Add configurable rate limits in config.yaml

**Estimated complexity:** Low

**Expected impact:** Improved security for anonymous access

## Low Priority

### 11. Command Palette
**Description:** Textual's command palette with fuzzy search is highly ergonomic. No equivalent in candy-serve TUI (when implemented).

**Source:** `docs/repo_map/textualize_textual.md`

**Implementation ideas:**
- Implement `CommandPalette` component using `candy-kit`
- Add fuzzy search with scoring
- Support custom command providers

**Estimated complexity:** Medium

**Expected impact:** Improved CLI/TUI ergonomics

### 12. SSH Certificate Authority Auth
**Description:** wish supports `WithTrustedUserCAKeys()` for SSH certificate-based authorization. candy-serve only supports direct public key auth.

**Source:** `docs/repo_map/charmbracelet_wish.md`

**Implementation ideas:**
- Add SSH CA key verification
- Implement certificate validation logic
- Add CA key management to CLI

**Estimated complexity:** Medium

**Expected impact:** Enterprise SSH key management

---

# Algorithm / Performance Opportunities

## Current vs External Approaches

### Git Protocol Implementation
**Current:** Shell out to `git pack-objects`, `git update-ref`, `git init --bare` via `exec()`/`popen()`. Standard approach even in Go implementations.

**External (git-lfs-transfer):** Also uses external git binary but implements proper streaming with `io.Copy` and SHA-256 verification via `VerifyingReader`.

**Why external is better:** The `VerifyingReader` pattern in `git-lfs-transfer` computes hash incrementally during transfer and verifies at EOF. candy-serve's LFS implementation lacks this integrity check.

**Tradeoffs:** Pure-Go git library (go-git) would eliminate shell dependency but is significantly more complex to implement. Current approach is pragmatic.

**Applicability:** Medium - could add `VerifyingReader`-style integrity checking for LFS transfers.

### Concurrent Connection Management
**Current:** `socket_select()` in main loop with linear scan for idle timeout cleanup (`docs/repo_map/sugarcraft_candy-serve.md`).

**External (wish):** Goroutine-per-session model. Each session runs in its own goroutine with context propagation.

**Why external is better:** Go's concurrency model is more robust for handling many concurrent connections. PHP's process-based model is more memory-intensive.

**Tradeoffs:** PHP's single-threaded event loop is memory-efficient but can't leverage multi-core. For moderate connection counts (< 100), current approach is fine.

**Applicability:** Low for now - current approach is adequate for expected load.

### Buffer Diffs / Render Optimization
**Current:** No rendering system (no TUI implemented).

**External (ratatui):** Immediate-mode rendering with buffer diffing. Only changed cells are written to terminal using cell-based representation.

**Why external is better:** Buffer diffing minimizes terminal I/O and eliminates flicker with synchronized output mode (ANSI 2026).

**Tradeoffs:** PHP port would require significant work to implement cell-based buffer with diffing.

**Applicability:** High for future TUI work.

### Layout Algorithm
**Current:** No layout system (no TUI implemented).

**External (ratatui, textual):** Ratatui uses Cassowary constraint solver. Textual uses CSS flexbox/grid layout algorithms.

**Why external is better:** Constraint-based layouts automatically adapt to terminal size changes without explicit resize handling.

**Tradeoffs:** CSS layout is familiar to web developers; constraint solvers are more powerful but complex.

**Applicability:** High for future TUI work.

---

# Architecture Improvements

## 1. Persistence Adapter Pattern
Currently all domain models (`User`, `Repo`) exist only in memory. Implement repository pattern with pluggable backends:

```php
interface UserRepository {
    public function findByUsername(string $username): ?User;
    public function findByKey(string $key): ?User;
    public function save(User $user): void;
    public function delete(string $username): void;
}
```

**Benefit:** Enables SQLite, PostgreSQL, or any storage backend without changing domain logic.

**Reference:** `docs/repo_map/charmbracelet_charm.md` (SQLite server implementation)

## 2. Middleware Composition for SSH
The `wish` middleware pattern (`func(next ssh.Handler) ssh.Handler`) could inspire a cleaner request pipeline:

```php
interface SSHMiddleware {
    public function handle(callable $next): callable;
}
```

**Benefit:** Cleaner separation of concerns (auth, logging, rate-limiting, git-handling).

**Reference:** `docs/repo_map/charmbracelet_wish.md` (middleware documentation)

## 3. Event System for Hooks
Implement observer pattern for Git events:

```php
interface EventListener {
    public function onPush(PushEvent $event): void;
    public function onRepoCreate(RepoCreateEvent $event): void;
}
```

**Benefit:** Enables webhooks, logging, and custom extensions without modifying core Git handling code.

**Reference:** `docs/repo_map/sugarcraft_candy-serve.md` (comparison shows upstream has event system)

---

# API / Developer Experience Improvements

## 1. Fluent Builder Completeness
Current `Repo` and `User` builders are good but lack:
- `Repo::new()` should accept name only and derive path from config
- Validation in builders (e.g., repo name format, key format)

**Reference:** `docs/repo_map/sugarcraft_candy-serve.md` (noted as strength but can improve)

## 2. Error Messages
Improve error messages with actionable content:

Current: `"Repository not found: {$repoName}\n"`
Better: `"Repository '{$repoName}' not found. Did you mean one of: " . implode(', ', $suggestions)`

**Reference:** `docs/repo_map/charmbracelet_bubbletea.md` (excellent error handling in Go)

## 3. Configuration Validation
Add config validation on load:

```php
public static function load(string $path): self {
    $config = new self($data, $dataPath);
    $config->validate(); // throws if invalid
    return $config;
}
```

**Reference:** Standard best practice

## 4. PHP 8 Typed Properties
All classes already use `declare(strict_types=1)`. Consider PHP 8.4 explicit property types:

```php
public readonly class Config {
    public function __construct(
        public readonly string $name,
        public readonly string $sshListenAddr,
        // ...
    ) {}
}
```

---

# Documentation / Cookbook Opportunities

## 1. Complete Deployment Guide
Missing topics:
- systemd service file for production deployment
- nginx reverse proxy configuration for HTTP
- firewall configuration (ports 23231, 23232, 9418)
- Let's Encrypt TLS for HTTP
- Backup strategies for repositories and user data

## 2. Architecture Decision Records
Document key decisions:
- Why shell out to git binary instead of pure PHP implementation
- Why multi-protocol design (SSH, HTTP, Git Daemon)
- Access control model (four permission levels)

## 3. API Documentation
Current `bin/soft-serve` CLI commands lack man pages. Add:
- `--help` for all commands
- Markdown conversion to man page format
- Online docs at docs.sugarcraft.com

## 4. Cookbook Examples
Add example configurations for:
- Single-user deployment
- Small team deployment
- GitHub Actions mirror sync (leveraging `charmbracelet/soft-serve-action` concepts)
- LFS setup with S3 backend

---

# UX / TUI Improvements

## 1. Interactive Setup Wizard
Add `soft-serve init --wizard` that interactively:
- Prompts for server name
- Generates SSH host keys
- Creates initial admin user with key
- Sets up basic config.yaml

**Reference:** `docs/repo_map/charmbracelet_soft-serve-action.md` (action handles GitHub integration simply)

## 2. TUI Dashboard (Future)
When TUI is implemented, follow upstream patterns:
- Repo list with clone URLs
- File browser with syntax highlighting
- Commit history viewer
- User/repo management panels

**Reference:** `docs/repo_map/charmbracelet_bubbletea.md`, `docs/repo_map/textualize_textual.md`

## 3. Progress Indicators
Add progress for long operations:
- Pack generation progress
- Mirror sync progress
- LFS upload/download progress with byte counter

**Reference:** `docs/repo_map/charmbracelet_bubbletea.md` (progress bar integration)

## 4. Colored Output
Add `--color=auto|always|never` to CLI. Use ANSI colors for:
- Success/error status
- Repo list formatting
- User listing with admin badge

---

# Testing / Reliability Improvements

## 1. Property-Based Testing
Add Ergonomic PHP or similar for property-based tests on:
- Key normalization
- pkt-line encoding/decoding
- YAML config parsing

## 2. Protocol-Level Integration Tests
Add tests that simulate actual Git client sessions:
- `git clone ssh://...`
- `git push --force`
- `git lfs install && git lfs push`

**Reference:** `docs/repo_map/charmbracelet_git-lfs-transfer.md` (has excellent protocol-level tests)

## 3. Fuzz Testing
Add fuzz tests for:
- HTTP request parsing
- pkt-line decoding
- Config file parsing

## 4. Chaos Testing
Test reliability under:
- Network interruption during push
- Disk full during pack generation
- Concurrent pushes to same ref

---

# Ecosystem / Integration Opportunities

## 1. GitHub Actions Integration
Implement something like `charmbracelet/soft-serve-action` for GitHub → candy-serve sync:
- Repository mirroring on push
- Automatic repo creation
- SSH key management for CI

**Reference:** `docs/repo_map/charmbracelet_soft-serve-action.md`

## 2. GitLab Integration
Similar sync capability for GitLab repositories.

## 3. Web Dashboard
Add optional PHP web dashboard for:
- User management UI
- Repository statistics
- Access control management

**Note:** This would be a separate package (`sugar-serve-admin`?) leveraging existing `candy-shell`, `sugar-bits`, etc.

## 4. Prometheus Metrics Export
Integrate with `candy-metrics` for Prometheus scraping:

```yaml
# Prometheus scrape config
scrape_configs:
  - job_name: 'candy-serve'
    static_configs:
      - targets: ['localhost:23233']
```

**Reference:** `docs/repo_map/charmbracelet_promwish.md`

## 5. Slack/Discord Notifications
Implement webhook-driven notifications:
- Push summaries to Slack
- New user registration alerts
- Mirror sync status

---

# Notable PRs / Issues / Discussions

## From `charmbracelet/soft-serve-action` (repo sync action)

**Summary:** Simple GitHub Action for syncing GitHub repos to Soft Serve servers.

**Relevance:** Demonstrates the mirror/sync automation that candy-serve lacks. The action shows three key steps: mirror clone, SSH key setup, and push to Soft Serve.

**Lessons learned:**
- SSH agent socket pattern is essential for multi-step SSH operations
- `git push --mirror` is cleaner for full sync but `--prune --force --all --follow-tags` is better for incremental
- Known hosts accumulation via `ssh-keyscan` is needed for host verification

**Potential adaptations for candy-serve:**
- Create similar GitHub Action for candy-serve
- Add SSH key scanning and agent setup
- Support both mirror and incremental sync modes

## From `charmbracelet/git-lfs-transfer`

**Summary:** Complete LFS SSH transfer implementation with atomic uploads and file locking.

**Relevance:** Shows what complete LFS implementation looks like. candy-serve's LFS is partial.

**Lessons learned:**
- `VerifyingReader` pattern for end-to-end integrity verification is essential
- Atomic upload via temp file + hardlink prevents partial reads
- Lock ID = SHA-256(version + ":" + path) provides deterministic naming

**Potential adaptations for candy-serve:**
- Implement `VerifyingReader` for LFS upload verification
- Add lock backend with proper lock ID generation
- Implement `Batch()` operation matching the protocol spec

## From `charmbracelet/promwish`

**Summary:** Prometheus metrics middleware for SSH sessions.

**Relevance:** candy-serve has a stats port configured but no metrics implementation.

**Lessons learned:**
- Session duration as histogram with buckets (not counter) is more useful
- Command label extraction allows per-command metrics
- Graceful shutdown pattern for metrics server

**Potential adaptations for candy-serve:**
- Implement `wish_sessions_created_total`, `wish_sessions_finished_total`, `wish_sessions_duration_seconds` metrics
- Add histogram buckets for duration (0.1, 0.5, 1, 5, 10 seconds)
- Expose `/metrics` endpoint on stats port

## From `textualize/textual`

**Summary:** Modern Python TUI framework with CSS styling, reactive state, and extensive widget library.

**Relevance:** TUI patterns and styling approaches for when candy-serve TUI is implemented.

**Lessons learned:**
- CSS-based styling (TCSS) brings web developer familiarity to terminal UI
- Message pump with bubbling/propagation handles complex UI interactions
- Spatial map for hit testing enables efficient mouse routing
- 40+ built-in widgets covers most UI needs

**Potential adaptations for candy-serve:**
- Implement CSS-like styling system using `candy-sprinkles`
- Use reactive state pattern for TUI updates
- Build spatial map for mouse event routing

---

# Recommended Roadmap

## Immediate Wins (0-3 months)

1. **SQLite Persistence Layer**
   - Implement `UserRepository`, `RepoRepository` with SQLite backend
   - Add database migrations
   - Update CLI to persist changes

2. **Complete LFS Upload/Download Endpoints**
   - Implement upload endpoint following git-lfs-transfer spec
   - Add `VerifyingReader` for integrity checking
   - Complete download endpoint

3. **HTTP Server Integration**
   - Integrate `HttpSmartProtocol\Server` with ReactPHP
   - Add TLS support
   - Document deployment with nginx reverse proxy

## Medium-Term Improvements (3-6 months)

4. **Prometheus Metrics**
   - Implement metrics endpoint on stats port
   - Add session connection/duration metrics
   - Create dashboard for visualization

5. **Server-Side Hooks**
   - Implement hook interface
   - Add pre-receive, update, post-receive support
   - Support both script and PHP callable hooks

6. **Webhook Event System**
   - Create event types for push, tag, user_create, repo_create
   - Implement delivery with retries and HMAC signatures
   - Add event filtering configuration

## Major Architectural Upgrades (6-12 months)

7. **Embedded SSH Server**
   - Integrate with `candy-wish` for SSH transport
   - Implement middleware composition
   - Add rate limiting per-IP

8. **TUI Implementation**
   - Port upstream Go TUI to PHP using `candy-core`
   - Implement repo browser, file viewer, commit history
   - Add syntax highlighting via `candy-shine`

9. **Mirror/Sync Automation**
   - Implement cron-based mirror jobs
   - Add `git fetch --mirror` and `git push --mirror`
   - Create `MirrorManager` with configurable schedules

## Experimental Ideas

10. **Web Dashboard**
    - PHP web UI for user/repo management
    - Repository statistics and graphs
    - Access using existing SugarCraft components

11. **Command Palette**
    - Fuzzy search interface for repo/user navigation
    - Extensible command providers

12. **SSH Certificate Authority**
    - Enterprise key management
    - CA key verification

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|-------------|--------|------------|------|----------|
| SQLite Persistence | Critical | High | Low | **P0** |
| Embedded SSH Server | Critical | High | Medium | **P0** |
| TUI Implementation | Critical | Very High | High | **P1** |
| LFS Upload/Download | High | Medium | Low | **P1** |
| HTTP Server Integration | High | High | Low | **P1** |
| Prometheus Metrics | High | Medium | Low | **P2** |
| Server-Side Hooks | High | Medium | Low | **P2** |
| Webhook Event System | High | Medium | Low | **P2** |
| Mirror/Sync Automation | Medium | Medium | Low | **P2** |
| Rate Limiting | Medium | Low | Low | **P3** |
| Command Palette | Medium | Medium | Low | **P3** |
| SSH Certificate Auth | Low | Medium | Medium | **P4** |

---

# Final Strategic Assessment

**candy-serve** represents a solid foundation for a self-hostable Git server in PHP, successfully porting the core Git protocol implementations from the upstream Go project. The multi-protocol approach (SSH, HTTP Smart, Git Daemon) provides excellent client compatibility, and the clean domain model (User, Repo, AccessControl) is well-designed.

However, the package has significant gaps compared to its upstream:

1. **The TUI is missing** — this is the defining feature that made soft-serve special. Without it, candy-serve is just another Git server library, not the "mighty" terminal UI experience charmbracelet built.

2. **Persistence is missing** — production deployments require data surviving restarts. The in-memory-only design limits candy-serve to development/testing use cases.

3. **Integration points are incomplete** — hooks, webhooks, metrics, and mirror automation are all absent.

The immediate priority should be adding **SQLite persistence** and **HTTP server integration** to make candy-serve a viable production option. The **TUI implementation** should be the long-term goal, leveraging `candy-core` and other SugarCraft TUI libraries.

The **LFS implementation** is close to complete — only the upload/download endpoints are missing, which is a well-defined scope following the `git-lfs-transfer` spec.

**Integration with `candy-wish`** (when available) would unlock the embedded SSH server capability, making candy-serve a self-contained solution rather than a library requiring external SSH infrastructure.

Overall, candy-serve is a **foundation with significant upside**. The architectural decisions (immutable domain models, clean separation of concerns, i18n support) are sound. The gaps are well-understood and have clear remediation paths based on the external reference implementations analyzed in this report.
