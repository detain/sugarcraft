# Caliber Learnings

Accumulated patterns and anti-patterns for candy-serve development.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:osc52]** OSC 52 payloads use base64 encoding for data. When writing clipboard data, encode it first; when reading, the TUI sends base64. The format is `52;{selection};{base64data}` (without the ESC prefix and ST terminator that the terminal emits).

- **[pattern:osc52-selections]** Valid OSC 52 selections are `c` (clipboard), `p` (primary), and `s` (secondary). Not all terminals support all selections. Treat unsupported selections as no-ops.

- **[pattern:http-smart-protocol]** Git smart protocol over HTTP uses specific path patterns: `/repo.git/info/refs?service=git-upload-pack` for ref advertisement and `/repo.git/git-upload-pack` (POST) for pack exchange. The `.git` suffix is required.

- **[pattern:http-smart-protocol-pktline]** Git pkt-line format encodes length as a 4-digit hexadecimal prefix (e.g., `0045` for 69 bytes). A flush packet is `0000`. Never assume null-termination — use explicit length prefixes.

- **[pattern:http-smart-protocol-auth]** Authentication happens at pack-exchange time (POST), not at ref-advertisement time (GET /info/refs). Anonymous GET to info/refs is always allowed for discovery; POST to git-upload-pack/git-receive-pack requires valid auth.

- **[pattern:clipboard-events]** Clipboard state changes are recorded as pending events. Call `pendingEvents()` to drain and reset. This pattern avoids lost events if listeners are slow to process.

- **[pattern:git-daemon-socket]** Git daemon uses PHP `socket_create/bind/listen` for TCP server. Always set `SO_REUSEADDR` before binding to allow quick restart. The socket operates in a main event loop with `socket_select()` for I/O multiplexing.

- **[pattern:git-daemon-pktline]** Git daemon protocol uses length-prefixed pkt-line format: 4-digit hex length prefix (e.g., `0045` = 69 bytes total including length field). Flush packet is `0000`. Data lines use `hash refname` format with `\n` terminator.

- **[pattern:git-daemon-signal-handling]** Daemon mode registers `pcntl_signal()` handlers for SIGTERM/SIGINT/SIGHUP with `pcntl_async_signals(true)`. On signal, sets shutdown flag; main loop checks flag and calls `cleanup()` which closes sockets and removes PID file.

- **[pattern:git-daemon-pid-file]** PID file is written as plain text (just the PID) to the specified path. Created with `mkdir` on the directory first if needed. Removed in `cleanup()` via `unlink`. Default location is `<data_path>/git-daemon.pid`.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

### 2026-06-01 — CancellationToken for best-effort I/O cancellation
Pattern: Use CancellationToken for best-effort I/O cancellation; true preemption requires async rewrite.
Source: step-35 ai/async-adopters

### 2026-07-04 — Dual-mode transports: sync default, React opt-in
Pattern: `GitDaemon::serve()` keeps the blocking `socket_select()` loop as the default; `serveAsync(?LoopInterface)` is the opt-in React path (mirrors candy-pty ReactPump / candy-wish dual-mode). Sync clients are ext-sockets `\Socket` objects, async clients are stream resources from `stream_socket_server()`/`stream_socket_accept()` — protocol code stays single-copy by writing through a transport-agnostic `writeRaw()`. Async read callbacks must capture the client STREAM (not the array index): `closeClient()` splices + reindexes `$clients`.
Anti-pattern: don't `socket_export_stream()` a `\Socket` just to register it with the loop — mixing socket_* reads with a stream wrapper over the same fd risks buffered-data loss; bind a separate stream server for async mode instead.

### 2026-07-04 — Bounded-concurrency LFS batches (honesty note)
Pattern: `LFSHandler::handleBatchAsync()` schedules per-object work through `Support\PromisePool::map()` capped at `$concurrentTransfers`; `handleBatch()` stays the sequential fallback. Per-object storage I/O (`exists()`/`size()`) is synchronous inside its loop tick — the loop buys bounded SCHEDULING (batch spread across ticks, timers/sockets keep firing), not async file I/O. A promise-returning backend can plug into the same pool later. Per-object exceptions become `error: {code: 500}` entries instead of rejecting the batch; missing objects report 404 byte-identically to the sequential path.

### 2026-07-04 — symfony/yaml migration gotchas (7.7)
Pattern: real YAML parsing makes the README-style block-nested config keys WORK (the old hand-rolled parser silently ignored every indented key — a documented bug, tests carried "inline-map workaround" comments). Values starting with a reserved indicator must now be quoted: unquoted `:8080` in a flow map parses as int 8080, unquoted `@every 10m` is a hard ParseException. Config's constructor casts string fields `(string)` so YAML type-inference can't TypeError readonly props.
Anti-pattern: don't assume old-parser quirks were features — verify each fixture against the real parser (`php -r` byte-check first) and adjust tests deliberately with a citation.

### 2026-07-04 — Schedule format for jobs (7.5)
Pattern: `jobs.mirror_pull` accepts the robfig/cron subset soft-serve actually uses: `@every <go-duration>` (`30s`/`10m`/`8h`/`1h30m`) + `@hourly`/`@daily`/`@midnight`/`@weekly`/`@monthly`/`@yearly` aliases, modelled as a plain interval (`Jobs\Schedule`). Full 5-field cron throws `InvalidArgumentException` — better than silently never firing.

### 2026-07-10 — candy-serve ↔ candy-wish SSH overlap: cross-link, defer unification (W10)
Decision: DOCUMENT the overlap, do not re-architect (deferred). candy-serve's `SSH\SSHServer` (a `ForceCommand`-style git-shell gate) and candy-wish's middleware framework both serve commands over the host's OpenSSH daemon, but neither implements the SSH wire protocol and they keep DISTINCT auth models — candy-serve does its own SSH public-key auth (`SSH\Auth` + `User`); candy-wish trusts sshd and allowlists by username + key fingerprint.
Rationale for deferring a rewrite: (a) the null/empty/whitespace-key auth-bypass was already fixed in `SSHServer::authenticate()` with regression coverage; (b) neither lib has a real SSH transport — both need host sshd; (c) `SSHServer` is not wired into the shipped daemon path (`bin/soft-serve` runs `GitDaemon`, not SSH), so rewriting it would be churn on unused code. Cross-linked both READMEs instead.
Future consideration: unify onto a single candy-wish-middleware SSH convention IF the monorepo commits to it.

## Implemented (was deferred pre-1.0)

- **6.1** ✅ 2026-07-04 — ReactPHP accept loop for GitDaemon, dual-mode via `serveAsync()` (blocking `socket_select()` loop unchanged as default).
- **6.2** ✅ 2026-07-04 — Concurrent LFS batch handling via `LFSHandler::handleBatchAsync()` + bounded `PromisePool` (bounded-concurrency beats naive `Promise\all`).
- **7.5** ✅ 2026-07-04 — Mirror-pull background jobs: `Jobs\MirrorPuller` honors `jobs.mirror_pull` (`Jobs\Schedule`), pulls due mirrors (`Repo::withMirrorFrom()`/`isMirror()`) with `git -C <path> fetch --prune <url> '+refs/*:refs/*'` through an injectable exec seam; dual-mode — `attach($loop)` periodic timer for async hosts, `runOnce()` for blocking/cron. Failed pulls still stamp `lastPullAt` so a broken upstream can't hot-loop.
- **7.6** ✅ 2026-07-04 — Stats collection server: `Stats` counter object (getInstance/setInstance like AccessControl, per-object `setStats()` on GitDaemon + HTTP Server, ctor param on LFSHandler) wired into git-daemon accepts/pack ops, HTTP requests/pack ops, and LFS batch/object transfers; `StatsServer` serves the JSON snapshot on `stats.listen_addr` — React-loop only, no blocking mode by design.
- **7.7** ✅ 2026-07-04 — Replaced the hand-rolled YAML subset parser with `symfony/yaml` (^6.4||^7.0, Packagist dep — no path-repo needed). ParseException wrapped in RuntimeException (`config.parse_failed`).
- **7.8** ✅ 2026-07-04 — `Visibility` enum (Public/CollaboratorOnly/Private) on `Repo` replacing the `isPublic`+`private` bool pair; BC kept: readonly `->isPublic` property + `isPrivate()`/`isVisiblePublic()` delegate to the enum, `withPublic()`/`withPrivate()` map onto it (private wins; `withPrivate(false)` on Private resolves CollaboratorOnly — conservative).
- **7.9** ✅ 2026-07-04 — LFS HTTP object routes on the smart-HTTP server: batch (POST), download (GET), upload (PUT), verify (POST) under `/{repo}/info/lfs/objects/…`; `LFSHandler::objectUrl()` now advertises exactly these routes (was a dead `/repos/…` prefix). Enforced: 64-hex OID, `http.max_pack_bytes` cap (413), body-SHA-256 == OID (422), read auth for downloads / write auth for uploads+verify, 404 when `lfs.enabled` is false.
