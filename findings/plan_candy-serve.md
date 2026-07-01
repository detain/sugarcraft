---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-serve

## Goal
Fix all 28 findings in candy-serve library: 7 HIGH severity issues (shell injection, path traversal, hardcoded LFS token, empty password bypass, repo init wrong dir, array modification during iteration, temp file leak), 12 MEDIUM severity issues, and 9 LOW severity issues.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Validate git refs with strict regex | Prevents shell injection via crafted ref names like `refs/heads/$(cmd)` | `candy-serve.md:1.1` |
| Add `maxPackBytes` to Config | Config already references it but doesn't define it, causing null-coalesce to always use default | `candy-serve.md:2.4,6.1` |
| Use try/finally for temp file cleanup | Temp files leak when exceptions are thrown in handleUploadPack/handleReceivePack | `candy-serve.md:2.3` |
| Collect stale indices before iterating | array_splice during foreach causes skipped/reprocessed elements | `candy-serve.md:2.2` |
| Validate repo names with strict alphanumeric regex | Path traversal possible via `..` in repo names despite basename() usage | `candy-serve.md:1.2` |
| Deny null-password users in Basic auth | Current logic allows null-password users to authenticate with any password | `candy-serve.md:1.4` |

## Phase 1: HIGH Severity Security Fixes [PENDING]

- [ ] 1.1 **[HIGH] Shell Injection in Git Ref Names** — Validate `$ref` against git ref naming rules (`/^refs\/[a-zA-Z0-9._\/.-]+$/`) before using in shell commands. Affects `src/Git/GitDaemon.php:473` and `src/Git/ReceivePack.php:129`.
- [ ] 1.2 **[HIGH] Path Traversal in Repo Name Handling** — Add strict alphanumeric validation for repo names at `src/SSH/SSHServer.php:97-104` after `basename()` extraction.
- [ ] 1.3 **[HIGH] Hardcoded Bearer Token in LFS Responses** — Replace placeholder "lfs-token" at `src/LFS/LFSHandler.php:200-201,213` with proper per-request authentication.
- [ ] 1.4 **[HIGH] Empty Password Authentication Bypass** — Add null-password check in `src/HttpSmartProtocol/Server.php:566` to deny auth when user password is null.
- [ ] 1.5 **[HIGH] Repo Init() Runs Git in Wrong Directory** — Use `git -C <path>` in `src/Repo.php:145` to ensure bare repo is created at correct location.

## Phase 2: HIGH Severity Bugs [PENDING]

- [ ] 2.1 **[HIGH] Array Modification During Iteration** — Collect stale client indices first, then close in reverse order. Fixes `src/Git/GitDaemon.php:213-223` where `array_splice()` during iteration causes skipped elements.
- [ ] 2.2 **[HIGH] Temp File Leak on Exception** — Wrap temp file usage in try/finally at `src/Git/GitDaemon.php:309-321` to ensure cleanup on exception.
- [ ] 2.3 **[HIGH] HTTP Server Buffers Entire Packfile in Memory** — Implement true streaming with chunked callbacks at `src/HttpSmartProtocol/Server.php:261-275,434` (TODO comment admits this).
- [ ] 2.4 **[HIGH] LFS "Concurrency" is Fully Sequential** — Replace sequential fallback at `src/LFS/LFSHandler.php:153-158` with ReactPHP async I/O for true concurrent transfers.

## Phase 3: MEDIUM Severity Security [PENDING]

- [ ] 3.1 **[MEDIUM] Incomplete SSH Key Validation** — Add minimum length validation per key type at `src/User.php:82-88` (ed25519: 64 chars base64, RSA: 256+).
- [ ] 3.2 **[MEDIUM] SSH Key Verification Never Called** — Actually invoke `User::verifyPublicKey()` at `src/SSH/SSHServer.php:141-158` instead of returning `true`.

## Phase 4: MEDIUM Severity Error Handling & Bugs [PENDING]

- [ ] 4.1 **[MEDIUM] Git Error Output Discarded** — Include git error `$out` in failure responses at `src/Git/GitDaemon.php:496-499` and `src/Git/ReceivePack.php:158-162`.
- [ ] 4.2 **[MEDIUM] Silenced symlink Failure in Repo Init** — Log failed symlink creation at `src/Repo.php:160` instead of silent `@`.
- [ ] 4.3 **[MEDIUM] Non-Functional Config Property** — Add `maxPackBytes` property to `src/Config.php` and wire it from `$http['max_pack_bytes']` config.
- [ ] 4.4 **[MEDIUM] `$maxBytes` Loaded from Config But Never Used in Upload Pack** — Use config value at `src/HttpSmartProtocol/Server.php:260` instead of hardcoded `268435456`.
- [ ] 4.5 **[MEDIUM] Repo Init Doesn't Configure Core Settings** — Add `git config core.sharedRepository umask` at `src/Repo.php:131-164` after git init.

## Phase 5: MEDIUM Severity Memory & Performance [PENDING]

- [ ] 5.1 **[MEDIUM] O(n²) String Concatenation in Packfile Transfer** — Replace `$packData .= $chunk` loops at `src/HttpSmartProtocol/Server.php:261-275,327-338,436-447` and `src/Git/GitDaemon.php:429-451` with `stream_get_contents()`.
- [ ] 5.2 **[MEDIUM] Undisposed File Handle from LocalStorageBackend::read** — Add `readAll(string $oid): string` convenience method at `src/LFS/LocalStorageBackend.php:48-59` with automatic handle cleanup.

## Phase 6: MEDIUM Severity Async/ReactPHP [PENDING]

- [ ] 6.1 **[MEDIUM] GitDaemon Could Use ReactPHP** — Refactor blocking `socket_select()` loop at `src/Git/GitDaemon.php` to use ReactPHP for better concurrency.
- [ ] 6.2 **[MEDIUM] LFS Handler Should Use ReactPHP PromiseAll** — Use ReactPHP's `Deferred`/`Promise\all()` at `src/LFS/LFSHandler.php:128-145` for concurrent batch transfers.

## Phase 7: LOW Severity Issues [PENDING]

- [ ] 7.1 **[LOW] Unnecessary preg_split vs explode** — Replace `preg_split('/\s+/', $line, 2)` with `explode(' ', $line, 2)` at `src/Repo.php:223`.
- [ ] 7.2 **[LOW] Osc52 Event Queue Unbounded Growth** — Add `const MAX_EVENTS = 1000` with oldest-event eviction at `src/Clipboard/Osc52.php:82,101`.
- [ ] 7.3 **[LOW] Missing `maxPackBytes` Property in Config** — Add typed property to `src/Config.php` (same as 4.3, tracked separately for PHP 8.3/8.4 compat).
- [ ] 7.4 **[LOW] Untyped `$proc` Variable** — Add type declarations for `proc_open()` return values across multiple files.
- [ ] 7.5 **[LOW] Mirror/Pull Schedule Not Implemented** — Implement background job system for mirror pulls at `src/Config.php:167`.
- [ ] 7.6 **[LOW] Stats Server Not Implemented** — Implement stats collection server at `src/Config.php:170`.
- [ ] 7.7 **[LOW] Custom YAML Parser Very Limited** — Consider replacing `src/Config.php:216-262` with `symfony/yaml` for full YAML 1.2 support.
- [ ] 7.8 **[LOW] Redundant Visibility State** — Replace two-boolean model at `src/Repo.php:25,37` with a `Visibility` enum (PUBLIC, PRIVATE, COLLABORATOR_ONLY).
- [ ] 7.9 **[MEDIUM] No LFS Object HTTP Handlers** — Implement HTTP routes for LFS object download/upload at `src/LFS/LFSHandler.php:222-225`.

## Phase 8: Testing & Verification [PENDING]

- [ ] 8.1 Verify All Security Fixes — Run `vendor/bin/phpunit` after all security fixes.
- [ ] 8.2 Add Tests for Security-Sensitive Paths — Cover shell injection, path traversal, and auth bypass with new tests.

## Notes

- 2026-06-30: Plan created based on comprehensive audit of candy-serve library. 28 findings total: 7 HIGH, 12 MEDIUM, 9 LOW.
- Shell injection (1.1) is the most critical - must be fixed before any release.
- Path traversal (1.2) and auth bypass (1.4) are equally critical.
- Temp file leak (1.7) and array modification (1.6) cause crashes under load.
- Many MEDIUM issues are "missing features" that existed in upstream soft-serve but weren't ported.
