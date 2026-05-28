# charmbracelet/charm

## Metadata
- **URL:** https://github.com/charmbracelet/charm
- **Language:** Go
- **Stars:** ~2.4k (as of 2024)
- **License:** MIT
- **Description:** A set of tools that makes adding a backend to terminal-based applications fun and easy. Quickly build modern CLI applications without worrying about user accounts, data storage and encryption. The project was sunset in November 2024 with Charm Cloud closing, but code remains open source.

## Feature List

### Core Libraries
1. **Charm KV** — An embeddable, encrypted, cloud-synced key-value store built on BadgerDB. Supports cloud backup, multi-machine syncing, and end-to-end encryption.
2. **Charm FS** — A Go `fs.FS` compatible cloud-based user filesystem with read/write operations, directories, and file metadata.
3. **Charm Crypt** — End-to-end encryption for stored data and on-demand encryption for arbitrary data using SIV mode encryption.
4. **Charm Accounts** — Invisible user account creation and authentication based on SSH keys. No friction-filled auth flows.

### Charm Client
- SSH-based authentication using PKAM (Public Key Authentication Method)
- JWT token generation via SSH session
- Environment variable configuration (`CHARM_HOST`, `CHARM_SSH_PORT`, `CHARM_HTTP_PORT`, etc.)
- Key management (link/unlink machines, backup/restore keys)

### Self-Hosted Server
- Single binary (`charm serve`) runs entire Charm Cloud backend
- SQLite-backed user and key storage
- HTTP and SSH server protocols
- Prometheus metrics support
- TLS support
- File storage with per-user size limits

### CLI Commands
- `charm link` — Link a machine to Charm account
- `charm kv set/get/delete/list/sync/reset` — Key-value store operations
- `charm fs cat/cp/rm/mv/ls/tree` — Filesystem operations
- `charm crypt encrypt/decrypt` — Encryption operations
- `charm backup-keys` / `charm import-keys` — Key management
- `charm serve` — Start self-hosted Charm Cloud server

## Key Classes and Methods

### client.Client (`client/client.go`)
- `NewClient(cfg *Config)` / `NewClientWithDefaults()` — Create authenticated client
- `JWT(aud ...string)` — Generate JWT token for user
- `ID()` — Get user's Charm ID
- `Bio()` / `SetName(name)` — User profile management
- `AuthorizedKeys()` / `AuthorizedKeysWithMetadata()` — List linked keys
- `UnlinkAuthorizedKey(key)` — Remove a linked key
- `AuthedRequest()` / `AuthedJSONRequest()` — Make authenticated HTTP requests

### kv.KV (`kv/kv.go`)
- `Open(cc, name, opt)` / `OpenWithDefaults(name)` — Open KV store
- `Set(key, value)` / `Get(key)` / `Delete(key)` — Key-value operations
- `SetReader(key, io.Reader)` — Set value from reader
- `NewTransaction(update bool)` — Create Badger transaction
- `Sync()` — Sync local DB with cloud
- `Commit(txn, callback)` — Commit transaction and backup
- `View(fn)` — Read-only transaction view
- `Reset()` — Delete local and re-sync from cloud
- `Keys()` — List all keys

### fs.FS (`fs/fs.go`)
- `NewFS()` / `NewFSWithClient(cc)` — Create filesystem instance
- `Open(name)` — Implement `fs.FS`, returns `fs.File`
- `ReadFile(name)` — Read entire file bytes
- `WriteFile(name, src)` — Write file with encryption
- `Remove(name)` — Delete file
- `ReadDir(name)` — List directory entries
- `EncryptPath(path)` / `DecryptPath(path)` — Path encryption

### crypt.Crypt (`crypt/crypt.go`)
- `NewCrypt()` — Create crypt with user's encryption keys
- `NewEncryptedWriter(w)` — Create writer that encrypts data
- `NewDecryptedReader(r)` — Create reader that decrypts data
- `EncryptLookupField(field)` — Deterministic field encryption for lookups
- `DecryptLookupField(field)` — Decrypt lookup field

### server.Server (`server/server.go`)
- `NewServer(cfg *Config)` — Initialize server with config
- `Start()` — Start HTTP, SSH, health, and stats servers
- `Shutdown(ctx)` — Graceful shutdown
- `Close()` — Immediate close of all listeners

### server.HTTPServer (`server/http.go`)
- `NewHTTPServer(cfg)` — Create HTTP server with routes
- `handleGetFile` / `handlePostFile` / `handleDeleteFile` — FS operations
- `handleGetSeq` / `handlePostSeq` — Sequence number for KV sync
- `handleGetUser` / `handlePostUser` — User bio management

### ui.Model (`ui/ui.go`) — Bubble Tea TUI
- `Init()` — Initialize with Charm client
- `Update(msg)` — Handle messages (spinner, keypress, client messages)
- `View()` — Render TUI with menu system

### Command Tree (`cmd/*.go`)
- `KVCmd` with subcommands: `kvSetCmd`, `kvGetCmd`, `kvDeleteCmd`, `kvListCmd`, `kvSyncCmd`, `kvResetCmd`
- `FSCmd` with subcommands: `fsCatCmd`, `fsCopyCmd`, `fsRemoveCmd`, `fsMoveCmd`, `fsListCmd`, `fsTreeCmd`
- `ServeCmd` — Self-host server command
- `LinkCmd`, `KeysCmd`, `BioCmd`, `NameCmd`, `BackupKeysCmd`, `ImportKeysCmd`

### Proto Definitions (`proto/*.go`)
- `Auth` — Auth response with JWT, ID, encryption keys
- `User` — User account structure
- `PublicKey` — SSH public key with SHA
- `Keys` — Response for linked keys query
- `EncryptKey` — Encryption key for E2E encryption
- `FileInfo` — File metadata (Name, Size, Mode, ModTime, IsDir)

## Notable Algorithms / Named Patterns

### Encryption
- **SIV (Syntactically Invalid Vector) mode encryption** — Deterministic authenticated encryption used for lookup fields (path encryption)
- **Scrypt** — Key derivation for encryption keys via `muesli/sasquatch`
- **SSH PKAM Authentication** — Custom SSH authentication method for key-based auth

### Synchronization
- **Sequence-based sync** — KV uses monotonically increasing sequence numbers to order transactions; backup files stored as `name/seq` in FS
- **Streaming backup/restore** — Badger stream API for incremental backup

### File System
- **Path encryption** — Deterministic encryption of path components for privacy on server
- **Multipart upload with content-length** — Custom chunking for encrypted file upload
- **Lazy directory resolution** — `sysFuture` pattern defers directory listing fetch

### TUI Pattern
- **Bubble Tea MVC** — Model/Update/View pattern with `tea.Model` interface
- **State machine menu** — Menu navigation via keyboard (j/k, enter)
- **Child model delegation** — `updateChildren()` dispatches to sub-models (info, keys, linkgen, username)

### Server Architecture
- **Goji mux** — HTTP router with middleware chain
- **JWT Middleware** — Token validation with JWKS endpoint
- **Error handling** — Custom error types with `Unwrap()` for chained errors

## Strengths

1. **Zero-friction authentication** — SSH key-based auth means no passwords, no registration flows. First use automatically creates keys.

2. **End-to-end encryption** — All data encrypted client-side before transmission; server never sees plaintext.

3. **Cloud + local sync** — BadgerDB locally with cloud backup/sync provides offline capability with multi-machine coherence.

4. **Self-hostable** — Single `charm serve` binary with no external dependencies (SQLite, in-memory file store by default).

5. **Elegant Go idioms** — Proper use of interfaces (`fs.FS`, `io.Reader`/`Writer`), context propagation, error wrapping.

6. **Bubble Tea TUI** — Beautiful CLI interface using charmbracelet's own TUI framework (dogfooding).

7. **Modular design** — Clear separation: `client/` for API, `kv/` for KV store, `fs/` for filesystem, `crypt/` for encryption, `server/` for backend.

8. **Environment-driven config** — No config files needed, all settings via env vars with sensible defaults.

9. **Multiple encryption keys** — Support for multiple linked machines with different encryption keys, graceful fallback during decryption.

10. **Streaming operations** — Chunked uploads for large files, streaming backup/restore.

## Weaknesses

1. **Project sunset** — Charm Cloud closed November 2024; project is effectively unmaintained unless forked.

2. **Single cloud dependency** — Default `cloud.charm.sh` is now offline; users must self-host.

3. **SQLite limitation** — Server uses SQLite which may not scale well for high-traffic deployments.

4. **No horizontal scaling** — Single server instance with local file storage; no distributed storage option.

5. **SSH requirement** — Requires SSH port exposure for auth (port 35353 default), problematic in restricted network environments.

6. **Complex sync logic** — Sequence-based sync for KV is complex; backup/restore flow has subtle invariants.

7. **Limited query** — KV store only supports key-value operations, no range queries, prefix scan is only option.

8. **Binary blob storage** — All values are binary bytes; no native type system for JSON/strings.

9. **No access control** — All linked machines have full read/write access to all user data.

10. **Documentation aging** — Most docs reference Charm Cloud which no longer exists; self-hosting docs may be incomplete.

## SugarCraft Mapping

| charmbracelet/charm | SugarCraft Library | Notes |
|--------------------|-------------------|-------|
| `kv/` package | `sugar-bits` | Key-value store; sugar-bits provides BitArray, Table rendering primitives. Not a direct KV port but complementary. |
| `fs/` package | `candy-shine` or `sugar-bits` | Filesystem FS interface; shine has output/styling, bits has data structures. |
| `crypt/` package | (no direct equivalent) | Encryption/crypto utilities not yet ported. |
| `client/` package | `candy-shell` | Shell utilities; client config/env management similar to shell setup. |
| `ui/` package (Bubble Tea) | `candy-sprinkles` | Sprinkles provides Bubble Tea components. The TUI pattern matches. |
| `server/` package | (no direct equivalent) | Server-side components not applicable to SugarCraft TUI libs. |
| `cmd/` package (CLI) | `candy-shell` | Command-line interface patterns; shell provides CLI primitives. |
| Overall architecture | `candy-core` | The Elm-style update/model/view pattern for TUI applications. |

**SugarCraft libs that could be built on this:**
- **Sugar-KV** — A PHP port of the KV package using a local DB (SQLite/PDO alternative to BadgerDB) for persistence with cloud sync capability
- **Sugar-FS** — A PHP `fs.FS` compatible filesystem abstraction with encryption
- **Sugar-Crypt** — E2E encryption utilities using PHP's crypto extensions (OpenSSL SIV mode, scrypt)

**Note:** SugarCraft focuses on TUI component ports (Charmbracelet's `bubbletea`, `lipgloss`, etc.) rather than backend/cloud services. The `charm` project is primarily a backend/cloud infrastructure project. SugarCraft's `candy-*` libs (core, shell, sprinkles) would be the natural home for TUI components if they were part of charm, but charm is not a TUI component library—it's a cloud backend with a TUI client.

## Analysis

The `charmbracelet/charm` project is a cloud infrastructure layer for terminal applications, providing encrypted key-value storage, a cloud filesystem, and SSH-based user authentication. Unlike most Charmbracelet projects which are TUI component libraries (like `bubbletea`, `lipgloss`, `glow`), this project is infrastructure-focused—it could be thought of as "Firebase for CLI apps." The project was sunset in November 2024 when Charmbracelet decided to discontinue their hosted Charm Cloud service, leaving the code as open source for self-hosting.

The architecture is well-designed with clear separation of concerns. The `client/` package handles all authentication and HTTP/SSH communication with the Charm Cloud. The `kv/` package wraps BadgerDB with cloud sync semantics—transactions are committed with sequence numbers and backed up to the FS as encrypted blobs. The `fs/` package implements Go's `fs.FS` interface with encryption, making it feel native to Go programmers. The `crypt/` package provides E2E encryption using SIV mode and scrypt key derivation. The `server/` package is a self-contained Charm Cloud implementation using SQLite for metadata and local file storage for blobs.

From a SugarCraft perspective, this project doesn't map cleanly because SugarCraft ports TUI *component* libraries (the visual, interactive parts), while charm is backend infrastructure. The `ui/` directory does use Bubble Tea and could theoretically be partially ported, but it's tightly coupled to the charm client. The crypto and storage concepts might inspire future SugarCraft libs for encrypted local storage, but there's no direct one-to-one mapping today. The project's main value for SugarCraft would be as a reference for implementing cloud-backed storage in PHP, or as inspiration for a future Sugar-FS or Sugar-KV library that provides similar encrypted, synced storage semantics—but using PHP's native crypto (OpenSSL) rather than Go's libraries.