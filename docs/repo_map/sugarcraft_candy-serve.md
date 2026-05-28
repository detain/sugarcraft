# SugarCraft/candy-serve

## Metadata

- **Upstream:** [charmbracelet/soft-serve](https://github.com/charmbracelet/soft-serve)
- **Language:** PHP
- **Stars:** N/A (SugarCraft monorepo library)
- **License:** MIT
- **Package:** `sugarcraft/candy-serve`
- **Namespace:** `SugarCraft\Serve`
- **Status:** 🟢 v1 ready
- **Role:** Self-hostable Git server over SSH (authorized keys), Git daemon, and HTTP smart protocol. Users, repos, access control, optional LFS.

---

## Feature List

### Multi-Protocol Git Server
- **SSH server** (`SSHServer.php`) — SSH public key authentication, forced-command git operations (git-upload-pack / git-receive-pack), on-demand repo creation
- **Git daemon** (`GitDaemon.php`) — Native Git protocol on port 9418, anonymous read-only access, background daemon with PID file and signal handling
- **HTTP Smart Protocol** (`HttpSmartProtocol/Server.php`) — Git-over-HTTP with Basic auth and custom `X-CandyServe-User` header support

### User Management
- **User model** (`User.php`) — SSH public key auth, multiple authorized keys per user, admin flag, active flag, key generation via ssh-keygen
- **Key validation** — Supports ssh-ed25519, ssh-rsa, ecdsa-sha2-*, sk-ssh-ed25519@openssh.com key formats
- **Key matching** — Normalized comparison (whitespace-insensitive) of presented keys vs authorized_keys

### Repository Management
- **Repo model** (`Repo.php`) — Immutable/fluent builder, bare Git repo initialization via `git init --bare`, collaborators list, public/private flags, mirror support
- **On-demand repo creation** — Repos created automatically on first push if user has admin privileges
- **Access control** (`AccessControl.php`) — Four permission levels: ACCESS_NONE, ACCESS_READ, ACCESS_WRITE, ACCESS_ADMIN

### Git Protocol Implementation
- **UploadPack** (`Git/UploadPack.php`) — Refs advertisement, want negotiation, packfile generation via `git pack-objects`
- **ReceivePack** (`Git/ReceivePack.php`) — Refs advertisement with capabilities, push command processing, atomic ref updates via `git update-ref`
- **GitDaemon** (`Git/GitDaemon.php`) — Socket-based server with concurrent connection handling, idle timeout, connection limits, signal handlers

### Git LFS Support
- **LFSHandler** (`LFS/LFSHandler.php`) — Batch API implementation, download/upload operations, concurrent transfer support
- **LocalStorageBackend** (`LFS/LocalStorageBackend.php`) — Filesystem storage using standard LFS path layout: `{oid[0:2]}/{oid[2:4]}/{oid}`
- **LFSStorageBackendInterface** — Pluggable storage backend for custom LFS implementations (S3, GCS, Azure, etc.)

### Configuration
- **Config** (`Config.php`) — YAML config loader with minimal parser, defaults for all settings, path resolution
- **Default ports:** SSH :23231, HTTP :23232, Git daemon :9418, Stats :23233
- **Data layout:** `dataPath/repositories/`, `dataPath/ssh/`, `dataPath/tmp/`

### CLI Tool
- **bin/soft-serve** — `serve`, `init`, `user add|key|list`, `repo list|create|info` commands
- **Daemon mode** — pcntl_fork-based backgrounding, PID file management, graceful shutdown
- **i18n** (`Lang.php`) — Translation facade with namespace 'serve', 13 locales supported

### Clipboard Support
- **Osc52** (`Clipboard/Osc52.php`) — OSC 52 clipboard read/write protocol, clipboard/primary/secondary selections

---

## Key Classes and Methods

### `src/SSH/SSHServer.php`
- `handleConnection($stream, $username, $command): int` — Main entry point for SSH session; authenticates user, parses git command, routes to UploadPack/ReceivePack
- `authenticate($stream, ?User $user): bool` — Public key auth verification; falls back to trust when ssh2 extension unavailable
- `findRepoByPath(string $path): ?Repo` — Looks up repo by exact path or by name in repos directory
- `createRepo(string $name, ?User $user): Repo` — Creates bare repo on-demand if user canCreateRepos()

### `src/Git/GitDaemon.php`
- `serve(string $pidFile = ''): int` — Main daemon loop with socket server, signal handlers, PID file
- `mainLoop()` — Select-based event loop handling incoming connections
- `acceptConnection()` — Accepts connection, sets non-blocking, enforces max connections limit
- `handleClientData(int $idx)` — Parses git command from buffer, routes to UploadPack/ReceivePack
- `sendPack($socket, Repo $repo, array $wants)` — Generates packfile via `git pack-objects --stdout` and streams to socket
- `writePacket($socket, string $data)` — Writes Git pkt-line format (4-byte length prefix + data + newline)

### `src/Git/UploadPack.php`
- `serve(): int` — Reads wants from stdin, sends refs advertisement, generates and outputs packfile
- `advertiseRefs(): string` — Builds multi-line ref advertisement with flush packet
- `readWants(): array` — Parses "want HASH\n" lines from stdin, discards "have" lines until "done"
- `sendPack(array $wants)` — Generates pack via `git pack-objects --stdout` with all want hashes as exclusions

### `src/Git/ReceivePack.php`
- `serve(): int` — Advertises refs, reads push commands, processes each ref update
- `advertiseRefs(array $refs)` — Sends refs with capabilities: report-status|report-status-v2 delete-refs side-band-64k
- `readCommands(): array` — Parses "oldHash newHash ref\n" lines from stdin
- `processPush(array $commands): int` — Validates new hash format, calls `git update-ref` for each command

### `src/HttpSmartProtocol/Server.php`
- `handleRequest($method, $path, $query, $headers, $body): array` — Routes HTTP requests, builds responses with headers/body/status
- `handleInfoRefs(array $headers)` — GET /{repo}/info/refs?service=... — advertises refs with correct Content-Type
- `handleUploadPack(array $headers, string $body)` — POST /{repo}/git-upload-pack — generates packfile for fetch/clone
- `handleReceivePack(array $headers, string $body)` — POST /{repo}/git-receive-pack — processes push
- `buildRefAdvertisement(Repo $repo, string $service): string` — Builds pkt-line encoded ref advertisement
- `getUserFromHeaders(array $headers): ?User` — Extracts user from Basic auth or X-CandyServe-User header

### `src/LFS/LFSHandler.php`
- `handleBatch(array $request): array` — Main LFS batch API handler; returns actions (download/upload hrefs) per object
- `processObjectsConcurrently(string $operation, array $objects): array` — Batch processing with concurrency control
- `handleObject(string $operation, string $oid, int $size): array` — Per-object processing for download/upload

### `src/LFS/LocalStorageBackend.php`
- `objectPath(string $oid): string` — Returns LFS storage path: `{lfsPath}/{oid[0:2]}/{oid[2:4]}/{oid}`
- `write(string $oid, $stream)` — Creates directories as needed, copies stream to file
- Implements `LFSStorageBackendInterface` — exists, size, read, write, delete, path methods

### `src/AccessControl.php`
- `canRead(?User $user, Repo $repo): bool` — Public repos readable by anyone; private repos require collaborator or admin
- `canWrite(?User $user, Repo $repo): bool` — Requires user be admin, or repo allowPush on public, or be collaborator
- `canAdmin(?User $user, Repo $repo): bool` — Admin users only
- `canCreateRepos(?User $user): bool` — Admin users only
- `allowAnonymousRead(): bool` — Always returns true (git protocol allows anonymous read of public repos)

### `src/User.php`
- `addAuthorizedKey(string $pubKey): self` — Validates key format, appends to authorized_keys list
- `verifyPublicKey(string $presentedKey): bool` — Normalizes both keys and compares
- `authorizedKeysList(): array` — Returns array of trimmed non-empty key lines
- `generateKeyPair(): ?array` — Uses ssh-keygen to generate ed25519 key pair (requires ssh2 extension)

### `src/Repo.php`
- `init(): self` — Creates bare git repo, sets description, git-daemon-export-ok, hooks symlink
- `branches(): array` — Returns list of branch names via `git branch`
- `refs(string $prefix = 'refs/heads'): array` — Returns ref=>hash map via `git for-each-ref`
- `readFile(string $commitHash, string $path): ?string` — Returns file content at given commit via `git show`
- `withMirrorFrom(?string $url): self` — Sets upstream mirror URL for pull-based sync
- `addCollaborator(string $username): self` — Immutable collaborator addition

### `src/Config.php`
- `load(string $path): self` — Loads config from YAML file
- `fromDefaults(): self` — Creates config with all defaults (ports, paths, timeouts)
- `parseYaml(string $yaml): array` — Minimal YAML parser: key-value, nested blocks, inline maps/lists, comments
- `reposPath(): string` — Returns `dataPath/repositories/`, creates if missing
- `sshPath(): string` — Returns `dataPath/ssh/`, creates with 0700 perms if missing

### `src/Clipboard/Osc52.php`
- `parse(string $data): ?array` — Parses OSC 52 payload: "selection;base64data" or "selection;?" for read
- `write(string $selection, string $data)` — Stores data (decodes base64), notifies listeners
- `buildReadResponse(string $selection, string $data): string` — Builds OSC 52 response string for clipboard read

### `bin/soft-serve`
- `cmdServe(array $args)` — serve [--config path] [--daemon] [--pid-file], starts servers, lists repos
- `cmdInit(array $args)` — Creates data directory structure and default config.yaml
- `cmdUser(array $args)` — user add|key|list subcommands
- `cmdRepo(array $args)` — repo list|create|info subcommands

---

## Notable Algorithms / Named Patterns

### Git Pkt-Line Encoding
```
writePacket: pack('N', strlen(data)+4) . data . "\n"
flush packet: pack('N', 0)  (4 zero bytes)
```
Used in `GitDaemon::writePacket()`, `UploadPack::writePacket()`, `ReceivePack::writePacket()`, `HttpSmartProtocol\Server::encodePktLines()`.

**Source:** `src/Git/GitDaemon.php:L488-498`, `src/Git/UploadPack.php:L168-175`

### LFS Object Path Sharding
```
objectPath: lfsPath / oid[0:2] / oid[2:4] / oid
```
Standard Git LFS storage layout splitting OID into 3-segment directory hierarchy for filesystem sharding.

**Source:** `src/LFS/LocalStorageBackend.php:L25-28`

### SSH Public Key Normalization
```
normalize: implode(' ', preg_split('/\s+/', trim($key)))
```
Strips all whitespace variability before comparing keys — handles leading/trailing spaces, multiple spaces between fields.

**Source:** `src/User.php:L191-194`

### Access Control Cascade
```
Public repo + not private → canRead returns true (regardless of user)
Private repo → requires collaborator or admin
Admin user → can do anything (read, write, admin, create repos)
```
**Source:** `src/AccessControl.php:L37-74`

### Immutable/Fluent Builder Pattern
```
User::new('alice')->withAdmin(true)->withAuthorizedKeys($keys)
Repo::new('name', '/path')->withPublic(true)->withPrivate(false)
```
Every `with*()` method returns a new instance; internal state never mutated after construction.

**Source:** `src/User.php:L45-70`, `src/Repo.php:L76-121`

### Git Protocol Parsing
```
First line: "git-upload-pack|git-receive-pack /repo-name\n"
Subsequent: "want HASH\n" or "oldHash newHash ref\n" terminated by blank line
```
GitDaemon parses incoming buffer with regex `^(git-upload-pack|git-receive-pack)\s+(\/[^\s\n]+)\s*\n'`

**Source:** `src/Git/GitDaemon.php:L221`

### HTTP Smart Protocol Routing
```
GET  /{repo}/info/refs?service=git-upload-pack  → handleInfoRefs
POST /{repo}/git-upload-pack                   → handleUploadPack
POST /{repo}/git-receive-pack                  → handleReceivePack
```
Path parsing strips leading slash, splits on '/', action is `parts[1]`.

**Source:** `src/HttpSmartProtocol/Server.php:L128-163`

### Concurrent Connection Management
```
socket_select() in mainLoop → acceptConnection() → client added to $clients[]
Enforces gitMaxConnections limit by closing oldest connection
Per-client: non-blocking socket, last_activity timestamp, idle timeout check
```
**Source:** `src/Git/GitDaemon.php:L130-179`

### YAML Config Parsing
```
Minimal parser handles:
- key: value
- key: { nested: value }   (inline map)
- key: [a, b, c]           (inline list)
- Indentation-based nesting (pops stack to correct depth)
- Comments (lines starting with #)
- Quoted strings ("..." and '...')
```
**Source:** `src/Config.php:L216-294`

---

## Implementation Details

### SSH Authentication Flow
1. User connects via SSH with public key
2. `SSHServer::handleConnection()` called with stream, username, forced command
3. If ssh2 extension loaded, libssh2 has already validated key during handshake
4. If no ssh2 extension, trusts connection if user exists in user map
5. Calls `AccessControl::allowAnonymousRead()` for null user (public repos only)

**Source:** `src/SSH/SSHServer.php:L78-158`

### Git Daemon Connection Lifecycle
1. `serve()` creates server socket on gitListenAddr
2. Registers SIGTERM/SIGINT/SIGHUP handlers
3. Main loop: `socket_select()` on server socket, accepts connections
4. Each client: non-blocking, tracked with last_activity timestamp
5. When buffer contains complete request (regex match), routes to handler
6. Handler runs `git pack-objects` or `git update-ref` via exec
7. Socket closed after response

**Source:** `src/Git/GitDaemon.php:L88-116`, `src/Git/GitDaemon.php:L130-268`

### HTTP Smart Protocol Flow
1. GET `/repo.git/info/refs?service=git-upload-pack` → `handleInfoRefs()`
2. Builds ref advertisement, returns with Content-Type: application/x-git-upload-pack-advisory
3. POST `/repo.git/git-upload-pack` with body containing "want HASH\n" lines → `handleUploadPack()`
4. Generates pack via `git pack-objects --stdout wantArgs` and streams back
5. Authentication checked via Basic auth header or X-CandyServe-User custom header

**Source:** `src/HttpSmartProtocol/Server.php:L170-251`

### LFS Batch API
1. Client POSTs batch request with `{"operation": "download"|"upload", "objects": [{"oid": "...", "size": N}, ...]}`
2. `LFSHandler::handleBatch()` checks read/write access per object
3. For download: checks if object exists in storage backend; returns download URL or 404 error
4. For upload: returns upload URL (actual upload goes to separate endpoint in full implementation)
5. Response: `{"transfer": "basic", "objects": [...]}`

**Source:** `src/LFS/LFSHandler.php:L102-120`

### On-Disk Repo Structure
```
dataPath/
├── config.yaml
├── ssh/
│   ├── soft_serve_host        (host private key)
│   └── soft_serve_client       (client key for git-over-ssh)
├── repositories/
│   └── {repo-name}/
│       └── .git/               (bare git repo)
├── lfs/                       (if LFS enabled)
│   └── {oid[0:2]}/{oid[2:4]}/{oid}
├── tmp/
├── hooks/
├── git-daemon.pid             (when running as daemon)
└── candy-serve.db             (SQLite database)
```

### CLI Command Structure
```
soft-serve serve [--config path] [--daemon] [--pid-file file]
soft-serve init [dataPath]
soft-serve user add <username>
soft-serve user key <username> <keyfile|->
soft-serve user list
soft-serve repo list [dataPath]
soft-serve repo create <name> [dataPath]
soft-serve repo info <name> [dataPath]
```

**Source:** `bin/soft-serve`

---

## Strengths

### 1. Complete Multi-Protocol Git Server
- All three standard Git transports implemented: SSH (primary), HTTP Smart, Git Daemon
- Proper Git protocol wire format (pkt-lines) for all three
- Native Git protocol means maximum compatibility with standard git client

### 2. Clean Architecture
- Clear separation: Config, User, Repo, AccessControl are pure domain models
- SSH, Git, HTTP handlers are separate classes with single responsibilities
- Immutable/fluent pattern throughout domain models (no hidden state changes)
- Storage backend interface allows custom LFS backends

### 3. Production-Ready Daemon
- Proper socket-based server with select() for concurrent connections
- Signal handlers (SIGTERM, SIGINT, SIGHUP) for graceful shutdown
- PID file management for systemd/initscript integration
- Connection limits and idle timeouts prevent resource exhaustion

### 4. SSH Key Auth without Passwords
- SSH public key authentication only
- Supports all standard key types (ed25519, RSA, ECDSA, sk-ssh-ed25519)
- Key validation with proper format checking
- Optional on-demand key generation via ssh-keygen

### 5. Flexible Access Control
- Per-repo public/private flag
- Per-repo collaborator list
- Admin flag for server-wide privileges
- Four-level permission system (none/read/write/admin) matches upstream Go implementation

### 6. Well-Tested
- Comprehensive unit tests for all domain models
- Integration tests for GitDaemon, HttpSmartProtocol\Server
- LFS handler and storage backend tests
- Tests use reflection for private method verification

### 7. i18n Support
- 13 locales: en, fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar
- Translation facade with namespace isolation
- All user-facing strings externalized

---

## Weaknesses

### 1. SSH Server Requires External sshd or libssh2
- Current implementation is a library that would be called by an external SSH server
- Doesn't embed its own SSH transport (contrast with upstream Go soft-serve which uses charmbracelet/ssh)
- The `candy-wish` library provides SSH server middleware, but candy-serve would need integration

### 2. Git Operations Shell Out to git Binary
- All Git operations (init, pack-objects, update-ref, show) call `git` via exec()
- No use of pure-Go git library equivalent (like go-git)
- This is standard for Go implementations too (wish git middleware does the same)
- Potential security concern if repo paths aren't properly sanitized

### 3. No Persistent Storage Backend Included
- Config model loaded from YAML, but no actual persistence layer
- User/repo/collab data stored in-memory only during server run
- Upstream Go soft-serve uses SQLite/PostgreSQL
- Would need database integration for production use

### 4. LFS Implementation Partial
- `LFSHandler::handleBatch()` returns action URLs but doesn't implement actual upload/download endpoint
- Comments in code acknowledge "In a full implementation..."
- LocalStorageBackend is complete, but server-side upload handling is TODO

### 5. HTTP Server Is Request/Response Only
- `HttpSmartProtocol\Server::handleRequest()` is a pure request handler
- No actual HTTP server implementation (no socket binding, no routing)
- For actual HTTP serving, would need to be integrated with ReactPHP, Swoole, or similar

### 6. No TUI Implementation
- Upstream Go soft-serve has full Bubble Tea TUI for repo browsing, file viewing, commit history
- This PHP port has no TUI — only CLI commands via bin/soft-serve
- The `candy-wish` SSH middleware could theoretically serve TUI, but candy-serve doesn't use it

### 7. Anonymous Access Has No Auth Layer
- Git daemon allows anonymous access to public repos
- No rate limiting on daemon connections
- No per-IP connection limits (GitDaemon has global limit but not per-IP)

### 8. Mirror/Sync Not Implemented
- `Repo::withMirrorFrom()` exists in domain model
- But actual mirror pull/sync logic (cron-based from config) is not implemented
- Would need job scheduling system to implement

---

## Comparison with Upstream (charmbracelet/soft-serve)

| Feature | Upstream (Go) | CandyServe (PHP) |
|---------|--------------|-----------------|
| **SSH Git access** | ✅ Full SSH server via charmbracelet/ssh | ⚠️ Library only, needs external sshd or candy-wish |
| **HTTP Git access** | ✅ Full HTTP server with gorilla/mux | ⚠️ Request handler only, no HTTP server |
| **Git daemon** | ✅ Full implementation | ✅ Complete implementation |
| **TUI** | ✅ Full Bubble Tea TUI | ❌ None |
| **User management** | ✅ SQLite/PostgreSQL | ⚠️ In-memory only |
| **Access control** | ✅ Full 4-level system | ✅ Full 4-level system |
| **LFS support** | ✅ Complete batch API | ⚠️ Partial (handler exists, upload endpoint TODO) |
| **Hooks** | ✅ pre-receive, update, post-receive | ❌ None |
| **Mirrors** | ✅ Cron-based auto-pull | ⚠️ Model exists, sync not implemented |
| **Webhooks** | ✅ Event system | ❌ None |
| **Prometheus metrics** | ✅ Stats server | ❌ None |

### Key Differences

1. **TUI Absence** — The defining feature of upstream soft-serve (TUI over SSH) is not ported
2. **Persistence** — Upstream uses proper database; candy-serve has no persistence layer
3. **Full-stack vs Library** — Upstream is a complete application; candy-serve is a set of components
4. **SSH Transport** — Upstream embeds its own SSH server; candy-serve would need integration with candy-wish

---

## Comparison with Related Repos

### vs charmbracelet/wish (SSH middleware framework)
- **wish** provides SSH server middleware (auth, logging, rate-limiting, git server, bubble tea over SSH)
- **candy-wish** is SugarCraft's port of wish
- **candy-serve** is a git SERVER implementation, not middleware
- wish/git middleware could power candy-serve's SSH transport

**Source:** `repo_map/charmbracelet_wish.md`

### vs charmbracelet/charm (cloud infrastructure)
- **charm** provides KV store, filesystem, encryption, SSH-based auth
- **candy-serve** provides git server
- No direct overlap — charm is user data infrastructure, candy-serve is git hosting

**Source:** `repo_map/charmbracelet_charm.md`

### vs Other Git Server Solutions
| Solution | Language | Web UI | SSH | HTTP | Git Daemon | LFS | Self-Host |
|----------|----------|--------|-----|------|------------|-----|-----------|
| **Gitea** | Go | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ |
| **Gitolite** | Perl | ❌ | ✅ | ❌ | ❌ | ❌ | ✅ |
| **GitLab** | Ruby | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ |
| **charmbracelet/soft-serve** | Go | SSH TUI | ✅ | ✅ | ✅ | ✅ | ✅ |
| **candy-serve** | PHP | SSH CLI | ⚠️ | ⚠️ | ✅ | ⚠️ | ✅ |

---

## SugarCraft Ecosystem Position

### Dependencies
- **candy-core** — Base runtime (implied by `SugarCraft\Core\I18n\Lang`)
- **candy-wish** — Could provide SSH transport layer for candy-serve
- No other SugarCraft libs required (standalone git server)

### Consumers
- Any application needing self-hosted Git hosting
- Could be used as teaching tool for Git protocol implementation
- Could form basis of GitHub/Gitea alternative in PHP

---

## Test Coverage

**Test files:**
- `tests/ServeTest.php` — Config, User, Repo, AccessControl, LFS storage/backend tests
- `tests/Git/GitDaemonTest.php` — GitDaemon registration, queries, signal handling, access control
- `tests/HttpSmartProtocol/ServerTest.php` — HTTP routing, refs advertisement, content types, auth headers
- `tests/Clipboard/Osc52Test.php` — (referenced in file list)
- `tests/LocalStorageBackendTest.php` — (referenced in file list)
- `tests/LFSHandlerTest.php` — (referenced in file list)
- `tests/RepoTest.php` — (referenced in file list)
- `tests/ConfigTest.php` — (referenced in file list)

**Total:** 8 test files covering domain models, protocols, and handlers.

---

## Files

```
candy-serve/
├── composer.json              Package metadata, sugarcraft/* dependencies
├── phpunit.xml              PHPUnit config with bootstrap, colors, cache
├── README.md                Overview, architecture diagram, usage examples
├── CALIBER_LEARNINGS.md     Project-specific patterns
├── .assets/icon.png         Package icon
├── bin/soft-serve           Entry point CLI
├── src/
│   ├── Config.php           YAML config loading, defaults, path resolution
│   ├── Repo.php             Bare Git repo model, immutable builder, git operations
│   ├── User.php            SSH public key user, key validation/generation
│   ├── AccessControl.php   Permission system (none/read/write/admin)
│   ├── Lang.php            i18n facade (serves namespace)
│   ├── SSH/
│   │   └── SSHServer.php   SSH session handler, git command routing
│   ├── Git/
│   │   ├── GitDaemon.php   Socket server, concurrent connections, signal handling
│   │   ├── UploadPack.php Git-upload-pack (clone/fetch) protocol
│   │   └── ReceivePack.php Git-receive-pack (push) protocol
│   ├── HttpSmartProtocol/
│   │   └── Server.php     HTTP Git smart protocol server
│   ├── Clipboard/
│   │   └── Osc52.php      OSC 52 clipboard protocol handler
│   └── LFS/
│       ├── LFSHandler.php           Batch API, concurrent transfers
│       ├── LocalStorageBackend.php  Filesystem storage (oid sharding)
│       └── LFSStorageBackendInterface.php  Backend contract
├── lang/                   13 locale files (en.php + 12 translations)
├── tests/                  PHPUnit test suite
└── examples/              Config and repo examples
```

---

## Audit Notes

- **SSH transport** — Current implementation is a library meant to be called by external SSH server; actual SSH server integration with candy-wish is TODO
- **HTTP server** — `HttpSmartProtocol\Server` is a request handler, not an actual HTTP server; needs integration with ReactPHP or Swoole for real use
- **LFS** — Handler exists but actual upload/download endpoint handling is partial (acknowledged in code comments)
- **Persistence** — No database layer; all data is in-memory; production use would need SQLite/PDO integration
- **TUI** — No terminal UI implemented (contrast with upstream); only CLI commands via bin/soft-serve
- **Mirrors** — Repo model has mirrorFrom property but cron-based sync is not implemented
