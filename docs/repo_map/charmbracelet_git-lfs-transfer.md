# charmbracelet/git-lfs-transfer

## Metadata
- URL: https://github.com/charmbracelet/git-lfs-transfer
- Language: Go
- Stars: Not publicly available (GitHub API credentials unavailable)
- License: MIT
- Description: A server-side implementation of the Git LFS pure SSH-based protocol proposal. Transfers large files stored in Git over SSH by implementing the protocol described in [git-lfs/git-lfs#ssh_adapter.md](https://github.com/git-lfs/git-lfs/blob/main/docs/proposals/ssh_adapter.md). This is a port from Brian Carlson's `scrutiger` library, rewritten in Go.

## Feature List

- **Pure SSH-based LFS Transfer**: Implements the SSH adapter for Git LFS, enabling efficient transfer of large binary files over SSH without requiring HTTPS endpoints
- **Batch Operations**: Supports batch upload and download operations for multiple LFS objects in a single session
- **Object Verification**: SHA-256 hash verification of uploaded objects via `VerifyingReader` to detect corruption
- **File Locking**: Distributed file locking mechanism using a file-based backend (hash-based lock IDs, owner tracking)
- **Packet Line Protocol**: Implementation of Git's pktline format for communication (flush packets, delimiters, text packets)
- **Parallel Transfer Ready**: Backend architecture supports concurrent operations
- **Dual Operation Modes**: Supports both `upload` and `download` operations as first-class concerns
- **Signal Handling**: Graceful shutdown via signal handling in `Command()`
- **Soft Link Atomicity**: Upload uses temp-file-then-hardlink pattern to ensure atomicity

## Key Classes and Methods

### `transfer.Processor` (transfer/processor.go)
Core protocol processor handling all LFS transfer operations:
- `NewProcessor(handler, backend, logger)` — factory
- `Version()` — negotiate protocol version
- `ReadBatch(op, args)` — parse batch request
- `UploadBatch()` / `DownloadBatch()` — process batch with appropriate action mapping
- `PutObject(oid)` — receive uploaded object with verification
- `GetObject(oid)` — retrieve object for download
- `VerifyObject(oid)` — verify object integrity
- `Lock()` / `Unlock(id)` / `ListLocks(useOwnerID)` — lock management
- `ProcessCommands(op)` — main command dispatch loop with error handling

### `transfer.Pktline` (transfer/pktline.go)
Git packet line protocol handler wrapping `github.com/git-lfs/pktline`:
- `NewPktline(r, w, logger)` — factory
- `SendError(status, message)` — send error response
- `SendStatus(status)` — send status with args/messages/reader
- `ReadPacketListToFlush()` / `ReadPacketListToDelim()` — read until flush/delimiter
- `Reader()` / `Writer()` — get pktline reader/writer

### `local.LocalBackend` (internal/local/backend.go)
Filesystem-based LFS object storage backend:
- `New(lfsPath, umask, timestamp)` — factory
- `Batch(op, pointers, args)` — check object presence, return action (upload/download/noop)
- `Upload(oid, size, r, args)` — atomic upload via temp file + hardlink
- `Download(oid, args)` — open file for reading
- `Verify(oid, size, args)` — size-only verification
- `LockBackend(args)` — return lock backend instance

### `local.localLockBackend` (internal/local/backend.go)
File-based lock manager:
- `Create(path, refname)` — create lock with hash-based ID
- `FromID(id)` / `FromPath(path)` — retrieve lock
- `Unlock(lock)` — remove lock file
- `Range(cursor, limit, func)` — iterate locks with cursor pagination

### `localBackendLock` (internal/local/lock.go)
Individual lock representation:
- `HashFor(path)` — SHA-256 based lock ID
- `Parse(data)` — deserialize lock file
- `AsArguments()` / `AsLockSpec(ownerID)` — format for protocol response
- `Unlock()` — delete lock file

### Supporting Types (transfer/*.go)
- `Pointer` — LFS pointer with OID + Size, validation, relative path computation
- `BatchItem` — pointer + Present flag + optional Args
- `Status` — interface for protocol responses (code, args, messages, reader)
- `VerifyingReader` — wraps `HashingReader`, verifies OID/size at EOF
- `HashingReader` — streaming hash accumulator with size tracking
- `Args` — `map[string]string` key-value pairs with `ParseArgs()` / `ArgsToList()`
- `Logger` — interface for structured logging (`Log(msg, kv...)`)

## Notable Algorithms / Named Patterns

### SHA-256 Object Storage Layout
Objects stored at `{lfsPath}/objects/{oid[0:2]}/{oid[2:4]}/{oid}` — the standard Git LFS directory sharding pattern for filesystem scalability.

### Atomic Upload Pattern
```
tempFile = os.Create(incomplete/{oid}{randomBytes})
io.Copy(tempFile, r)
os.Link(tempFile, destPath)  // hardlink for efficiency
```
Ensures readers never see partial content; cleanup on error.

### Lock ID = SHA-256(version + ":" + path)
Prevents lock ID collisions and provides deterministic naming from path.

### VerifyingReader Hash Verification
At EOF, compares computed SHA-256 and byte count against expected values; returns `ErrCorruptData` on mismatch. Ensures end-to-end integrity.

### Command Dispatch Loop
Pattern matching on `msgs[0]` against protocol commands with exhaustive error mapping to HTTP-like status codes.

## Strengths

- **Clean Interface/Implementation Separation**: `transfer.Backend` interface allows any storage backend; `localBackend` is one implementation
- **Protocol Correctness**: Implements the SSH adapter spec faithfully, including batch semantics and lock ownership tracking
- **Comprehensive Error Handling**: Every error mapped to appropriate status code (400, 404, 409, 405, 500)
- **Test Coverage**: Extensive cmd_test.go with protocol-level integration tests showing exact pktline sequences
- **Minimal Dependencies**: Only essential Go packages plus git-lfs/pktline and go-git
- **Unix/Windows Abstraction**: Platform-specific permission handling in `backend_unix.go` / `backend_windows.go`
- **Graceful Degradation**: `noopLogger` as default logger; `tracerx` integration for debugging

## Weaknesses

- **File-Based Locking Only**: No distributed lock backend (Redis, etcd) — unsuitable for multi-server LFS servers
- **No HTTPS Support**: Pure SSH only; cannot be used with standard Git LFS HTTPS servers
- **Single-User Lock Assumption**: Owner name from file stat, not authenticated principal
- **Limited Error Recovery**: Corruption detected after full read; no chunked verification mid-transfer
- **No Progress Reporting**: No mechanism to report transfer progress back to client
- **No Compression**: LFS objects transferred uncompressed over SSH
- **Shallow Clone**: Git history not preserved in shallow clone used for research

## SugarCraft Mapping

This is an LFS transfer server — it has **no direct equivalent** in SugarCraft, which focuses on TUI component libraries. However, several architectural patterns map to SugarCraft components:

| git-lfs-transfer | SugarCraft | Relationship |
|-----------------|------------|--------------|
| `Pktline` / packet protocol | `candy-core` (buffered I/O) | Both handle framed byte protocols |
| `Processor` command dispatch | `sugar-bits` (event handling) | Message → action routing |
| `VerifyingReader` integrity check | `candy-core` (data integrity) | End-to-end verification |
| `LocalBackend` storage | `sugar-charts` (data backend) | Backend abstraction pattern |
| `LockBackend` / `Lock` | `candy-shine` (state management) | Distributed state coordination |
| Protocol state machine | `sugar-prompt` (readline state) | State-driven command processing |
| File-based lock atomicity | `candy-core` (atomic ops) | Ensuring consistent state |

**Primary mapping**: `candy-core` for buffered I/O and data integrity primitives (VerifyingReader pattern → immutable stream processing). The `transfer.Backend` interface abstraction maps to `sugar-charts` data backend pattern.

**Note**: SugarCraft does not currently have and would need to build `sugar-lfs` or similar for Git LFS SSH transfer functionality.

## Analysis

`git-lfs-transfer` is a focused, well-engineered implementation of the Git LFS SSH transfer protocol proposal. The Charm team ported Brian Carlson's original `scrutiger` from a different language and wrote it in Go with clean separation of concerns. The core abstraction is the `transfer.Backend` interface which handles batch operations, upload/download, and locking — making it theoretically swappable for cloud storage backends.

The protocol implementation is notable for its correctness: it handles the pktline format precisely, implements proper hash verification at EOF, and maintains compatibility with the broader git-lfs ecosystem. The file-based lock implementation using SHA-256 derived IDs with owner tracking is elegant for single-server use but would require significant work for distributed scenarios.

The main limitation is scope — this is specifically an SSH adapter. It cannot speak the HTTPS-based LFS protocol used by GitHub, GitLab, or other major hosts. This makes it primarily useful for self-hosted git servers that want LFS support without running a separate HTTPS API server. The architectural pattern of a frontend protocol processor (`Processor`) with a swappable backend (`Backend interface`) is sound and could accommodate other transport mechanisms.

For SugarCraft, this represents a category of functionality (file transfer protocol) that doesn't directly exist in the TUI library ecosystem. The closest analog would be integrating LFS-style large file handling into a git TUI, but that would be a separate `sugar-lfs` library rather than a core component.
