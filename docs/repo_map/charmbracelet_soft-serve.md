# charmbracelet/soft-serve

## Metadata
- **URL:** https://github.com/charmbracelet/soft-serve
- **Language:** Go
- **Stars:** ~2.3k (as of 2024)
- **License:** MIT
- **Description:** A tasty, self-hostable Git server for the command line. 🍦 Soft Serve provides an easy-to-navigate TUI available over SSH, supports cloning repos over SSH/HTTP/Git protocol, Git LFS support, and comprehensive access control with SSH public key authentication.

## Feature List

### Core Git Server Features
- **Multi-protocol Git access:** SSH (primary management), HTTP/HTTPS (cloning/pushing), Git daemon protocol (anonymous read-only)
- **Repository management:** Create, delete, rename, hide/unhide repositories via SSH CLI
- **Nested repositories:** Support for hierarchical repo names (e.g., `org/repo`)
- **Repository mirrors:** Import and mirror remote repositories with automatic pull updates via cron
- **Collaborator management:** Per-repository access control with read-only, read-write, and admin access levels

### Authentication & Authorization
- **SSH public key authentication:** No passwords; keys only
- **User management:** Create users, manage public keys, set admin status
- **Access tokens:** HTTP access tokens with optional expiration for automated workflows
- **Anonymous access control:** Configurable anon-access levels (no-access, read-only, read-write, admin-access)
- **Allow/disallow keyless connections:** Require SSH keys for all access

### Git LFS Support
- **Full LFS server implementation:** Batch API, upload/download operations
- **HTTP and SSH LFS backends:** Both protocols supported out of the box
- **LFS locking:** Prevent concurrent writes to LFS-tracked files

### Terminal User Interface (TUI)
- **SSH-accessible TUI:** Browse repos, view files, commits, branches directly in terminal
- **Syntax highlighting:** Code highlighting with chroma lexer integration
- **Interactive navigation:** Bubble Tea-based TUI with keyboard navigation
- **Local browsing:** `soft browse` command for local repository browsing

### Server-Side Hooks
- **Git hooks:** Support for `pre-receive`, `update`, `post-update`, `post-receive`
- **Per-repository hooks:** Stored in repository's `hooks` directory
- **Global hooks:** Executed for all repositories from `SOFT_SERVE_DATA_PATH/hooks`

### Web Features
- **Webhook system:** Repository events (push, collaborator changes, branch/tag operations)
- **Go-get support:** `go get` compatible clone URLs for Go modules hosting

### Operations & Deployment
- **SQLite or PostgreSQL:** Configurable database backend
- **Docker support:** Official Docker image available
- **Systemd integration:** Service units for production deployment
- **Configuration:** YAML config file with environment variable overrides
- **Metrics:** Prometheus-compatible stats server

## Key Classes and Methods

### `cmd/soft/main.go`
- `main()` — Entry point; initializes config, logger, maxprocs, executes root command

### `cmd/soft/serve/server.go`
- `Server` struct — Holds SSH, GitDaemon, HTTPServer, StatsServer, CertLoader, Cron, Backend, DB
- `NewServer(ctx)` — Creates configured server instance
- `Start()` — Launches all enabled servers (SSH, Git daemon, HTTP, Stats) with errgroup
- `Shutdown(ctx)` — Graceful shutdown of all servers

### `pkg/ssh/ssh.go`
- `SSHServer` struct — SSH server implementation wrapping `charmbracelet/ssh`
- `PublicKeyHandler(ctx, pk)` — Handles SSH public key authentication
- `KeyboardInteractiveHandler(ctx, challenge)` — Fallback auth when keyless allowed
- Uses middleware stack: Recovery → BubbleTea → Command → Logging → Auth → Context

### `pkg/ssh/ui.go`
- `UI` struct — Main TUI model using Bubble Tea
- `NewUI(c, initialRepo)` — Creates UI with header, footer, pages (selection/repo)
- `Update(msg)` — Handles tea.WindowSizeMsg, tea.KeyPressMsg, tea.MouseClickMsg, footer toggle, repo selection
- `View()` — Renders using Lipgloss styles and BubbleZone for interactive regions
- `pages[selectionPage]` → `selection.Selection` for repo list
- `pages[repoPage]` → `repo.Repo` with sub-components: Readme, Files, Log, Refs

### `pkg/ssh/cmd/cmd.go`
- `UsageFunc(c)` — Custom Cobra usage template with SSH command prefix
- `checkIfReadable(cmd, args)` — Verifies user has at least read access
- `checkIfAdmin(cmd, args)` — Verifies admin privileges
- `checkIfCollab(cmd, args)` — Verifies read-write access

### `pkg/ssh/cmd/repo.go`
- `repo create <name>` — Creates new repository
- `repo delete <name>` — Deletes repository
- `repo rename <old> <new>` — Renames repository
- `repo collab add <repo> <user> [access]` — Adds collaborator
- `repo tree <repo> [path] [ref]` — Prints repository file tree
- `repo blob <repo> <path> [-c -l]` — Prints file with optional syntax highlighting and line numbers

### `pkg/ssh/cmd/user.go`
- `user create <username> [-k "pubkey"]` — Creates user
- `user add-pubkey <user> <key>` — Adds public key to user
- `pubkey list|add` — User manages own keys
- `set-username <name>` — Change username

### `pkg/backend/backend.go`
- `Backend` struct — Core server backend; manages repos, users, settings, caching, tasks
- `Repositories(ctx)` — Lists all repositories
- `Repository(ctx, name)` — Gets repository by name (with cache)

### `pkg/backend/repo.go`
- `CreateRepository(ctx, name, user, opts)` — Creates bare git repo, writes description, sets up hooks
- `ImportRepository(ctx, name, user, remote, opts)` — Clones remote repo as mirror
- `DeleteRepository(ctx, name)` — Removes repo and associated LFS objects
- `RenameRepository(ctx, oldName, newName)` — Atomic rename via transaction
- `SetMirror/SetPrivate/SetHidden/SetDescription` — Property setters
- `repo` struct (implements `proto.Repository`) — Wraps `git.Repository` + DB model

### `pkg/backend/user.go`
- `CreateUser(ctx, opts)` — Creates user with public keys
- `SetPassword(ctx, user, password)` — Sets user password hash
- `AccessLevelForUser(ctx, repo, user)` — Returns access level (NoAccess, ReadOnly, ReadWrite, Admin)

### `pkg/store/store.go`
- `Store` interface — Combines RepositoryStore, UserStore, CollaboratorStore, SettingStore, LFSStore, AccessTokenStore, WebhookStore

### `pkg/store/repo.go`
- `RepositoryStore` interface — CRUD for repos: GetRepoByName, CreateRepo, DeleteRepoByName, SetRepoNameByName, getters/setters for all properties

### `pkg/store/user.go`
- `UserStore` interface — FindUserByUsername, FindUserByPubKey, CreateUser, etc.

### `pkg/web/server.go`
- `NewRouter(ctx)` — Creates gorilla/mux router with Git, health routes, CORS, compression, recovery middleware

### `pkg/web/git.go` (654 lines)
- Handles Git HTTP protocol: `git-receive-pack`, `git-upload-pack`, `git-upload-archive`
- Implements `GetInfoRefs()`, `ServiceRPC()` for advertised and non-advertised refs
- Uses `go-git` library for git operations

### `pkg/web/git_lfs.go` (985 lines)
- Full LFS batch API implementation
- `BatchHandler()` — Handles LFS batch operations (download/upload/verify)
- `DownloadHandler()` — Individual object download
- `UploadHandler()` — Individual object upload
- `VerifyHandler()` — Post-transfer verification
- `LockHandler()` — LFS locking operations

### `pkg/lfs/pointer.go`
- `ReadPointer(reader)` / `ReadPointerFromBuffer(buf)` — Parses LFS pointer files
- `Pointer.RelativePath()` — Returns SHA-based storage path (first 4 chars as directory hierarchy)
- `GeneratePointer(content)` — Creates pointer for arbitrary content using SHA256

### `pkg/lfs/scanner.go`
- `ScanRepository(ctx, repo, client)` — Scans repo for LFS objects, stores missing in DB
- `StoreRepoMissingLFSObjects(ctx, repo, db, store, client)` — Batch stores missing LFS objects

### `pkg/git/git.go`
- `EnsureWithin(reposDir, repo)` — Security check: prevents path traversal
- `EnsureDefaultBranch(ctx, repoPath)` — Sets default branch to main/master if not set
- `WritePktline(w, v...)` — Encodes git pkt-line format

### `pkg/config/config.go`
- `Config` struct — Holds all settings (name, SSH, HTTP, Git, DB, LFS, Jobs, Stats)
- `DefaultConfig()` — Returns config with defaults
- `Parse()` / `ParseEnv()` — Loads from file then environment

### `pkg/db/database.go`
- `DB` struct — Wrapper around sqlx.DB
- `TransactionContext(ctx, fn)` — Runs function in transaction

### `pkg/ui/common/common.go`
- `Common` struct — Shared context for all UI components: ctx, width, height, styles, keymap, zone manager, logger
- `IsFileMarkdown(content, ext)` — Uses chroma lexers to detect markdown

### `pkg/daemon/daemon.go`
- `GitDaemon` struct — Implements git-daemon protocol
- `ListenAndServe()` — TCP listener for anonymous Git access

## Notable Algorithms / Named Patterns

### LFS Pointer Path Algorithm
```
RelativePath: oid[0:2]/oid[2:4]/oid  (e.g., ab/cd1234... → ab/cd/abcd...stanzas)
```
SHA256 OID is split into 3-segment path for object storage sharding.

**Source:** `pkg/lfs/pointer.go:L106-L112`

### SSH Middleware Stack Pattern
```
RecoveryMiddleware → BubbleTeaMiddleware → CommandMiddleware → LoggingMiddleware → AuthenticationMiddleware → ContextMiddleware
```
Chained via `wish.MiddlewareWithLogger` for composable request handling.

**Source:** `pkg/ssh/ssh.go:L67-84`

### Access Level Cascade
```
NoAccess < ReadOnly < ReadWrite < Admin
```
Access checked via `AccessLevelForUser()` comparing auth level against required threshold.

**Source:** `pkg/access/access.go` and `pkg/backend/user.go`

### Bubble Tea TUI Pattern
```
Model: Init() → Update(Msg) → View() loop
Pages: selectionPage | repoPage with Component interface
Interactive zones via bubblezone.Manager
```
Uses `tea.Batch()` for concurrent commands, `lipgloss.JoinVertical()` for layout composition.

**Source:** `pkg/ssh/ui.go:L134-295`

### Repository Path Sanitization
```
SanitizeRepo: filepath.Join(cfg.DataPath, "repos", name+".git")
ValidateRepo: alphanumeric, hyphens, underscores, slashes; no path traversal
```
Prevents directory traversal attacks.

**Source:** `pkg/backend/repo.go:L710-714` and `pkg/utils/utils.go`

### LFS Object Scanning
```
ScanRepository → go-git Walk → ReadPointer → Skip if stored → Store missing via batch API
```
Walks all refs to find LFS pointers, batch-stores metadata in DB.

**Source:** `pkg/lfs/scanner.go` and `pkg/backend/lfs.go`

### Git pkt-line Encoding
```
pktline.NewEncoder(w).EncodeString(msg) + Flush()
```
Used for git protocol wire format.

**Source:** `pkg/git/git.go:L21-32`

## Strengths

### 1. Clean Architecture
- Clear separation: `cmd/` (CLI), `pkg/backend/` (core logic), `pkg/ssh/` (SSH server), `pkg/web/` (HTTP server), `pkg/store/` (data persistence)
- Protocol interfaces (`proto.Repository`, `proto.User`) enable testability and potential backends
- Backend caching layer reduces DB reads

### 2. Comprehensive Protocol Support
- SSH for interactive management + git operations
- HTTP/HTTPS for git clone/push (with LFS)
- Git daemon for anonymous read-only
- All three protocols share same repository storage

### 3. Excellent TUI with Bubble Tea
- Full-featured terminal UI using `charmbracelet/bubbletea`
- Interactive repo browsing, file viewing with syntax highlighting, commit history
- OSC52 clipboard support for copying clone commands over SSH
- BubbleZone for mouse interaction

### 4. Security-First Design
- SSH public key auth only (no passwords)
- Path traversal prevention via `EnsureWithin()` and `SanitizeRepo()`
- Access level system with proper authorization checks before commands
- Support for `allow-keyless: false` to reject non-key auth

### 5. Production-Ready Operations
- SQLite (development) or PostgreSQL (production) via sqlx
- Graceful shutdown with timeout
- Prometheus metrics (`promauto` counters for auth attempts)
- TLS certificate hot-reload on SIGHUP
- Cron jobs for mirror synchronization

### 6. Developer Experience
- Single binary deployment
- Self-documenting via SSH (every command has `--help`)
- Example hook scripts auto-generated on first run
- Comprehensive configuration with sensible defaults

## Weaknesses

### 1. RSA Key Limitation
- Go's `x/crypto/ssh` doesn't support new SHA-1 RSA keys
- Users with new RSA keys must switch to Ed25519 or ECDSA
- Documented but not fixable until Go crypto/ssh improves

### 2. No Built-in Web UI
- Only SSH TUI for browsing; no web-based repository browser
- Contrast with Gitea, GitLab which offer full web UI
- Could be seen as simplicity vs. feature trade-off

### 3. Limited User Management
- No built-in user registration
- Admin must manually create users and add keys via SSH
- No email verification, password reset, or self-service

### 4. LFS SSH Backend Not Defaulted
- LFS over SSH disabled by default
- Requires additional configuration to enable

### 5. No GitHub/Gitea Migration Tools
- No import from GitHub, GitLab, Gitea
- Must use `git clone` + `git push` manual workflow

### 6. Documentation in Single README
- Despite 831-line README, detailed docs scattered
- No dedicated docs/ folder with structured documentation

## SugarCraft Mapping

Soft Serve is a comprehensive Go application that doesn't map directly to a single SugarCraft library. Instead, its components map to multiple SugarCraft libraries:

| Soft Serve Component | SugarCraft Library | Relevance |
|---------------------|---------------------|-----------|
| **TUI/BubbleTea** | `candy-bubbletea` (if it existed) or `candy-term` | Bubble Tea TUI framework - SugarCraft would need a Bubbletea port for TUI apps |
| **SSH Server** | `candy-pty`, `candy-ssh` (partial) | SSH handling - charmbracelet/ssh wrapper could become `candy-ssh` |
| **Git operations** | `sugar-git` | Git repository operations via `go-git` - could be `sugar-git` |
| **LFS support** | N/A | No SugarCraft equivalent - would be new territory |
| **Lipgloss styling** | `candy-lipgloss` | Terminal styling library - perfect match for `candy-lipgloss` |
| **Keygen** | `candy-keygen` | SSH key generation - could map to `candy-keygen` |
| **HTTP server** | `sugar-http` | HTTP server using gorilla/mux - could map to `sugar-http` |
| **Config management** | `candy-core` (partial) | YAML config parsing - `candy-core` area |
| **Prometheus metrics** | N/A | No SugarCraft equivalent yet |

### Key Mapping Insights

1. **Lipgloss** → `candy-lipgloss` — Direct mapping, terminal styling is core TUI need
2. **Bubble Tea** → TUI framework — Would be a major porting effort but essential
3. **charmbracelet/ssh** → `candy-ssh` — SSH server wrapper, not just client
4. **go-git** → `sugar-git` — Git operations in pure Go
5. **gorilla/mux** → HTTP routing — but SugarCraft should prefer PSR-7/ReactPHP patterns

## Analysis

### Architecture Deep Dive

Soft Serve exemplifies the charmbracelet philosophy: build small, focused tools that do one thing exceptionally well and compose cleanly. The server architecture is deliberately modular with four independent servers (SSH, HTTP, Git daemon, Stats) that can be enabled/disabled independently. Each speaks a different protocol but shares the same Backend, Store, and DB layers, demonstrating clean separation of concerns.

The SSH server is particularly interesting—it uses a middleware stack where each middleware adds a layer of capability: recovery handling, Bubble Tea integration, command routing, logging, authentication, and context setup. This allows features to be composed orthogonally rather than baked into a single monolithic server class.

### TUI Implementation

The Bubble Tea TUI in `pkg/ssh/ui.go` demonstrates idiomatic Bubble Tea usage:
- `UI` is the top-level model holding `pages[]` for different screens
- Pages are themselves `Component` implementations (selection list, repo browser)
- Uses `lipgloss` for styling with consistent theme across components
- `bubblezone` manager tracks interactive regions for mouse support
- Global keybindings for help (`?`), quit, back, footer toggle
- OSC52 clipboard integration for copying clone commands over SSH

The TUI supports browsing repos, viewing files with syntax highlighting (via chroma), commit history, branches, and tags—all over SSH without requiring a web browser.

### Git LFS Integration

Soft Serve implements a complete LFS server with:
- Batch API for efficient multi-object operations
- HTTP transport with proper authentication via `git-lfs-transfer` library
- SSH transport support (though disabled by default)
- LFS pointer parsing with SHA256 OID validation
- Object storage in `data/lfs/{repoID}/objects/{oid[0:2]}/{oid[2:4]}/{oid}` path structure
- Background scanning to identify LFS objects in repositories
- Locking API to prevent concurrent modifications

### Security Model

The security model is refreshingly simple yet comprehensive:
- SSH public key authentication only (no passwords to leak)
- Access levels: NoAccess, ReadOnly, ReadWrite, Admin
- Per-repository collaborator lists
- Anonymous access configurable per-server
- Path traversal prevention in repository path construction
- All sensitive data (tokens, keys) stored in secured data directory

### Comparison to GitHub/Gitea

Unlike GitHub or Gitea, Soft Serve has no:
- Web UI (only SSH TUI)
- Issue tracking, PRs, wikis
- Built-in CI/CD
- Email notifications
- Two-factor authentication

This simplicity is a feature—Soft Serve is a Git server, not a development platform. It's ideal for self-hosting where you want git push/pull access with a minimal, fast, secure server.

### Production Deployment Considerations

The server handles production concerns well:
- Graceful shutdown with configurable timeouts
- Automatic TLS certificate reloading on SIGHUP
- SQLite for development, PostgreSQL for production
- Prometheus metrics for monitoring
- Cron-based mirror synchronization
- LFS object garbage collection potential
- Database migrations on startup
