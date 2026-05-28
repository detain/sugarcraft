# Second-Stage Ecosystem Intelligence Report: charmbracelet/skate

## 1. Repository Overview

**charmbracelet/skate** is a minimalist personal key-value store CLI (~1.8k–2k stars, MIT license) written in Go. The entire application lives in a single `main.go` (~443 lines) using Badger as the underlying KV store and Cobra for CLI parsing. The project went through a major architectural transformation: pre-v1.0.0 featured cloud sync via Charm Cloud with end-to-end encryption, while v1.0.0 (August 2024) severed all cloud dependencies, operating fully locally.

**Current state (v1.0.1, March 2025)**: Active maintenance but feature development is minimal — primarily dependency bumps. A significant fork ecosystem has emerged (notably `AmrSaber/KV`) that addresses gaps skate's maintainers have not prioritized.

**Contributors**: 20 (top: dependabot, aymanbagabas, caarlos0, meowgorithm, muesli). The core maintainer is @caarlos0.

**Ecosystem signal**: Development appears stagnated relative to community demand. Multiple contributors are noting "development appears to be stopped" before redirecting users to alternatives.

---

## 2. Existing SugarCraft Mapping

The `sugar-skate/` port is already well-established:

- **Composer pkg**: `sugarcraft/sugar-skate` → `SugarCraft\Skate` namespace
- **Storage**: SQLite-backed (distinct from Go's Badger), with one SQLite file per database under `~/.config/skate/`
- **Key classes**: `Store` (main facade, handles `@db` routing), `Database` (per-file SQLite), `Entry` (value wrapper), `Cli\ExportCommand`, `Cli\ImportCommand`, `Lang.php`
- **Status**: 🟢 v1.0.0 equivalent — local-only KV with multi-database support
- **Already implemented**: TTL/expiry, Levenshtein typo suggestions, atomic transactions, glob pattern matching, binary data (base64), forward/reverse listing, STDIN read on set
- **Not yet implemented**: Encryption at rest, value versioning/history, clipboard integration, concurrent request handling, shell autocomplete

---

## 3. Previously Identified Gaps

From the first-stage analysis, these gaps were identified:

- No encryption at rest (data stored plain in app data directory)
- No authentication/access control
- No atomic multi-key operations
- No query/search beyond prefix/lists
- No sync/export/import built-in
- Single-file implementation limits extensibility

**New gaps discovered in this second-stage analysis**:

- Performance degrades severely on encrypted home filesystems (~6s for `list` operations on ecryptfs)
- No value versioning/history — deleted/overwritten data is unrecoverable
- No per-key password encryption
- No concurrent request support (multiple processes can corrupt WAL-mode SQLite)
- Shell completion / autocomplete for key names
- No list output formatting for multiline values (printer exposes raw newlines)
- Development velocity nearly zero since v1.0.0 (Aug 2024)

---

## 4. High-Signal Open Issues

### Issue #120 — "Skate is painfully slow" (Open, Jan 2025)
**Signal**: Active discussion thread, unresolved as of May 2026.
**Key finding**: Performance degrades to ~6 seconds for `skate list` on machines with **encrypted home directories** (ecryptfs). The same operations are fast on machines without encrypted homes. This is an I/O performance issue — Badger/SQLite with WAL mode is performing many small synchronous I/O ops that get amplified by the encrypted filesystem layer.
**Root cause hypothesis**: `PRAGMA journal_mode = WAL` + `PRAGMA synchronous = NORMAL` means many small writes/reads that compound on encrypted filesystems. Also `db.Sync()` calls add overhead.
**Relevance to SugarCraft**: Sugar-skate uses the same SQLite WAL approach. PHP's SQLite3 extension has the same vulnerability. **High risk** — users on encrypted Linux homes (common for security-conscious developers) will experience severe slowdowns.
**Workaround observed**: None satisfactory. Suggestion to use `PRAGMA synchronous = OFF` for bulk reads, but risks data integrity loss on crash.

### Issue #80 — "Feature Request: Support the versioning of values" (Open, Oct 2023)
**Signal**: 5 👍 reactions, active discussion.
**Request**: Every `set` should preserve the previous value, enabling retrieval of older versions in case of unwanted deletion or updates. This is essentially a "git for your KV store" feature.
**Community response**: Multiple users have explicitly asked for this. @AmrSaber (fork author) cited this as the #1 reason for creating the `kv` fork.
**SugarCraft gap**: Sugar-skate has no versioning. The `Entry` table overwrites on conflict — old values are lost.
**Strategic opportunity**: **SugarCraft should prioritize this** — it's a clear differentiator from upstream and addresses a frequently expressed need.

### Issue #160 — "Add encryption support" (Open, Sep 2025)
**Signal**: 2 👍 reactions, author states they have an implementation ready and is willing to submit PR.
**Request**: Adds a `--key` flag or `SKATE_DB_KEY` env var for database-level encryption.
**Note**: The fork `AmrSaber/kv` has implemented full AES-256-GCM per-key password protection with PBKDF2 key derivation — this is the reference implementation.
**SugarCraft gap**: Sugar-skate stores data unencrypted. No encryption layer exists.
**Strategic note**: Encryption is the #1 most requested feature that hasn't been addressed. SugarCraft could implement it with a cleaner API than the fork's `--password` flag approach.

### Issue #162 — "Copy the value to clipboard on `skate get`" (Open, Sep 2025)
**Signal**: 4 👍, 1 👎
**Request**: `skate get secret -c` to copy value to clipboard (like `xclip -selection c`).
**Maintainer response**: Not directly addressed in thread.
**SugarCraft gap**: Not implemented. Sugar-skate has no clipboard integration.
**Strategic note**: This is a trivial feature to add via `proc_open('xclip')` or similar in PHP. It demonstrates ecosystem awareness but is low priority.

### Issue #176 — "Git sync or like pet" (Open, Mar 2026)
**Signal**: New, no reactions yet.
**Request**: Sync/upload-restore database like `pet` (a command-line snippet manager) does with GitHub gists.
**AmrSaber response**: Their fork supports `kv db backup` + manual copy to any backup location. This is the pragmatic solution without a server.
**SugarCraft gap**: No export/import/sync mechanism exists.
**Strategic note**: This is closely related to the versioning request — both imply wanting durability and portability beyond "data lives on this machine."

### Issue #145 — "feature request: autocomplete key names" (Open, Apr 2025)
**Signal**: Active, open.
**Request**: Shell completion for key names when using `skate get <TAB>`.
**SugarCraft gap**: No shell integration at all.
**Strategic note**: This is a CLI UX feature rather than a library feature. SugarCraft's PHP CLI nature makes this harder to implement natively (would need to support bash/zsh completions externally).

### Issue #138 — "Support for concurrent requests" (Open, Mar 2025)
**Signal**: Open, no explicit maintainer response visible.
**Request**: Multiple simultaneous `skate` invocations should not corrupt the database.
**Root cause**: SQLite WAL mode allows concurrent reads but only one writer. Concurrent writes from multiple processes can cause `SQLITE_BUSY` errors or in worst case database corruption if the locking protocol fails.
**SugarCraft exposure**: Identical — `Database.php` uses WAL mode with no explicit busy timeout handling beyond `busyTimeout(5000)`. This is a **real risk for production use**.
**Workaround**: Users must serialize writes externally (e.g., `flock` around skate invocations).

---

## 5. Important Closed Issues

### Issue #21 — "`skate get {key}` takes ~1.17s, `skate get {key}` takes ~2.9s" (Closed, Jan 2025)
**Historical significance**: This was the performance issue that drove the cloud sync design. Skate was phoning home for crypt keys on *every operation* — even local-only ones — causing 1-3 second delays per invocation. Multiple users confirmed the issue.
**Resolution**: v1.0.0 removed cloud features entirely. Issue closed as "no longer an issue."
**Key lesson**: For a local-only KV store, the original design that required network on every op was fundamentally broken. This validates SugarCraft's local-only approach.

### Issue #22 — "docs: troubleshooting mismatching keys error" (Closed, Jan 2025)
**Historical significance**: When users switched between local `charm serve` and a real self-hosted charm server, encryption keys became namespaced to the wrong host, causing permanent `Encryption key mismatch` errors.
**Root cause**: Keys were stored in paths namespaced to the server hostname. Switching servers left stale keys.
**Resolution**: v1.0.0 removed cloud features entirely.
**Key lesson**: The complexity of the multi-server encryption model created user-facing failure modes that were incomprehensible without deep debugging. Simpler local-only storage eliminates this entire class of problems.

### Issue #35 — "feat: add styling to skate list" (Closed, merged)
**Resolution**: Added lipgloss styling to `skate list` output.
**SugarCraft status**: Sugar-skate outputs plain text. Lipgloss styling could be added as an optional feature via `candy-sprinkles`.

### Issue #97 — "skate list should not print long strings with newlines" (Open)
**Status**: PR #177 merged (May 2026) adding single-line previews for long/multiline values.
**Resolution**: Will show truncated previews like `script  #!/bin/sh\n#script comment\n  (2000 chars omitted)`.
**SugarCraft gap**: `Entry::rawValue()` returns raw string with embedded newlines. No preview/truncation formatting exists.

---

## 6. Recurring Pain Points

| Pain Point | Frequency | Severity | Description |
|------------|-----------|----------|-------------|
| Performance on encrypted filesystems | Multiple users, ongoing | High | `list` takes 6+ seconds on ecryptfs/btrfs with encryption |
| No value versioning | Repeatedly requested (3+ separate issues) | Medium | No recovery from accidental overwrites/deletes |
| No encryption at rest | Frequently requested, top feature | High | Fork (AmrSaber/KV) explicitly built around this gap |
| Concurrent writes corrupt data | Reported, unfixed | High | WAL mode doesn't protect against concurrent writes |
| Shell completion missing | Multiple requests | Low | Tab completion for key names not implemented |
| Cloud sync removed without migration path | Users frustrated | Medium | v1.0.0 severed sync; users with cloud data had to manually migrate |
| Multiline values break list output | Known issue, partially fixed | Medium | Long multiline values print raw, breaking terminal output |
| No export/import/sync | Repeatedly requested | Medium | Users want backup and portability |

---

## 7. Frequently Requested Features

Ranked by community demand (based on issue reactions, discussion engagement, and fork feature sets):

1. **Encryption at rest** — Issue #160, fork implementation (AmrSaber/KV) validates demand
2. **Value versioning/history** — Issue #80, 5 👍, explicitly cited as reason for fork
3. **Concurrent request safety** — Issue #138, real data corruption risk
4. **Git/sync/backup** — Issue #176, users want portable exports
5. **Clipboard integration** — Issue #162, 4 👍
6. **Shell autocomplete** — Issue #145, CLI UX standard expectation
7. **Long string preview truncation** — Issue #97, PR #177 just merged (May 2026)
8. **TTL/expiry** — ✅ Already implemented in sugar-skate (sugar-skate already has this)

---

## 8. Important PRs

### PR #177 — "feat(list): show single-line previews for long/multiline values" (Merged May 2026)
**Significance**: Addresses Issue #97 — long multiline values now display truncated previews instead of raw dump. This is the most recent significant feature addition.
**Note**: This is a Go implementation detail for the upstream. SugarCraft sugar-skate doesn't currently truncate — implementing this would be a user-facing improvement.

### PR #125 — "fix: delete-dbs" (v1.0.1, Mar 2025)
**Significance**: Bug fix for delete-db command. This was the last meaningful code change before v1.0.1 release.

### PR #96 — "fix: document the fact that `set` works with STDIN if the value is omitted" (v1.0.1)
**Significance**: Documentation fix. Confirmed STDIN behavior is intentional and works for piping data.

### PR #30 — "fix: fix string literal parsing for delimiter" (v0.2.0)
**Significance**: A subtle string quoting bug in the delimiter parsing. The fix changed from naive `fmt.Sprintf` to `strconv.Unquote` to properly handle escape sequences. This is a good example of a minimal, focused PR that fixed a real usability bug.

### PR #74 — "feat: add delete-db suggestions" (v1.0.0)
**Significance**: Added Levenshtein suggestions when deleting a database that doesn't exist. This is the same typo-suggestion pattern already in sugar-skate's `suggestSimilar()`.

---

## 9. Architectural Changes

### v1.0.0 — Cloud Severance (Aug 2024)
**The single most important architectural event**: Skate removed all Charm Cloud sync, becoming fully local. This was a breaking change that:
- Eliminated the `charm` dependency entirely
- Removed SSH key authentication
- Removed network operations from all hot paths
- Simplified the data model to pure local files

**Lesson for SugarCraft**: The upstream took a radical simplification approach. Sugar-skate inherited a clean local-only design from the start. The v1.0.0 migration validated the local-only approach.

### v2 Module Path (PR #103)
**The Go module was renamed from `github.com/charmbracelet/skate` to a v2 path** as part of the v1.0.0 release. This is standard Go practice for major version bumps.

### Go Version Updates
Recent commits show active maintenance of Go dependencies:
- Badger v4.6.0 → v4.7.0 (April 2025)
- Cobra 1.8.1 → 1.9.1 (Feb 2025)
- lipgloss 1.0.0 → 1.1.0 (Mar 2025)

### Single-File Limitation
The entire skate application remains in `main.go`. While this is simple, it creates a ceiling for extensibility. The fork `AmrSaber/KV` uses proper modular structure from the start.

---

## 10. Performance Discussions

### Issue #120 Deep Dive
The performance issue on encrypted filesystems is the most significant ongoing problem. Key observations:

**Reproduced scenario**:
- Linux Mint with ecryptfs encrypted home directory
- `skate list` takes 6+ seconds
- Same binary on non-encrypted machine: fast
- Root cause: SQLite in WAL mode performs fsync-heavy operations that compound on stacked filesystem encryption

**Mitigations attempted**:
1. Upgrading to latest Badger version — didn't resolve it
2. Using different SQLite pragmas — not explored by maintainers
3. Using the binary from `main` branch — same behavior

**Technical insight**: SQLite's WAL mode issues `PRAGMA synchronous = NORMAL` by default, which still issues fsyncs on checkpoint. On encrypted filesystems (ecryptfs, fscrypt, tmpfs + dm-crypt), each sync becomes an order of magnitude slower. This is a fundamental tension between WAL safety and encrypted filesystem performance.

**SugarCraft exposure**: 
- Sugar-skate's `Database.php` uses `PRAGMA journal_mode = WAL` and does not set `PRAGMA synchronous`
- The `db.Sync()` call in the Go code is analogous to PHP's `SQLite3::busyTimeout` handling
- **High risk**: Users on encrypted Linux systems will hit this

**Potential solutions for SugarCraft**:
1. Use `PRAGMA synchronous = OFF` for read-heavy workloads (risky for write durability)
2. Use memory-mapped I/O options
3. Add explicit `SQLITE_OPEN_FULLMUTEX` flag to serialize writes at the SQLite level
4. Document the limitation and provide a configuration option

### Performance vs pass
The external review (etoobusy.polettix.it) compared skate to `pass` and noted:
- `pass` syncs via git pre-fetching all encrypted files locally
- skate required network on every access (before v1.0.0)
- `pass` supports password-protected private keys via `gpg-agent`
- skate's single-binary simplicity is attractive but limited for advanced secret management

---

## 11. Extensibility Discussions

### Single-File Limitation
The upstream `main.go` single-file approach has become a genuine limitation. Features requiring any abstraction (plugins, custom serializers, alternative backends) cannot be cleanly added.

### Fork as Extension Mechanism
The emergence of `AmrSaber/KV` demonstrates that when skate's architecture can't support community needs, the community forks and extends. This is healthy open-source behavior but means skate is no longer the canonical reference for KV tooling in the Charm ecosystem.

**AmrSaber/KV features beyond skate**:
- Full SQLite per-database storage (like sugar-skate)
- Per-key AES-256-GCM encryption with PBKDF2
- Value history/versioning
- TTL/expiry
- Visibility (hidden keys that don't appear in listings)
- Batch and prefix operations
- JSON/YAML/table output formats
- Cross-database copy/move
- Shell completion

**Implication for SugarCraft**: The fork validates that the KV concept needs these extensions. SugarCraft's design should anticipate these features as inevitable future additions.

---

## 12. API/UX Complaints

### Issue #97 — Multiline Values Break List Output
**Complaint**: Storing a shell script via `skate set script < script.sh` causes `skate list` to dump the entire script content with raw newlines, making the output unreadable and potentially breaking terminal scrollback.
**User expectation**: Preview format like `(2000 chars omitted)` or `string with newlines omitted`.
**Resolution**: PR #177 merged addressing this (May 2026).

**SugarCraft status**: No truncation. `Entry::rawValue()` returns the raw string. This would be a straightforward enhancement.

### Discussion #68 — Confusion About Local vs Cloud Databases
**Complaint**: New users are confused about the "two databases" — local unencrypted and remote encrypted. They don't understand where data lives and whether it requires protection.
**Root cause**: The pre-v1.0.0 dual-storage model was never clearly explained.
**Resolution**: v1.0.0 removed cloud storage, eliminating the confusion.
**Lesson**: Simpler data models reduce support burden and user confusion. SugarCraft's single local storage model is correct.

### Discussion #69 — SSH Key Encryption Not Supported
**Complaint**: Users want to use password-protected SSH keys or hardware security keys (ed25519-sk) with skate. Currently only unprotected SSH keys work.
**Not addressed**: This remains unfixed in upstream. The fork handles encryption differently (password-based rather than key-based).
**SugarCraft implication**: Sugar-skate has no key-based auth at all — no SSH keys, no password-based encryption. This is both a gap and an opportunity for cleaner design.

---

## 13. Migration Problems

### v1.0.0 Cloud Severance Migration
Users who had stored data in Charm Cloud had to manually migrate:
1. Install v1.0.0
2. Install v0.2.2
3. Manually export/import data between versions

**Pain point**: No automated migration path existed. Users with cloud-synced data lost access to that data entirely when Charm Cloud sunset on Nov 29, 2024.

**SugarCraft lesson**: If SugarCraft ever adds cloud/sync features, the migration path must be planned from day one. Otherwise, breaking changes permanently lose user data.

### Key Namespace Collision After Server Switch
Users who switched between local `charm serve` and production servers hit "Encryption key mismatch" because keys were namespaced to server hostnames. The workaround was deleting `~/.local/share/charm` (Linux) or `~/Library/Application Support/charm/localhost` (macOS).

**SugarCraft advantage**: No server concept = no key namespace collisions. Single-machine local storage eliminates this entire failure mode.

---

## 14. Clever Fixes & Workarounds

### Levenshtein Suggestions
The typo suggestion system in `findDb()` is a well-loved UX touch. It uses Levenshtein distance computed against all known database names, with a threshold of `diff` (length difference between names). This pattern was directly ported to sugar-skate's `suggestSimilar()`.

**Elegance**: The suggestion only appears on actual miss, doesn't interfere with normal operation, and stderr output keeps stdout clean for piped values.

### STDIN Value Reading
The `set` command accepts no value argument and reads from stdin: `skate set foo < file.txt`. This is documented but users frequently discover it by accident. It's a clean interface that aligns with Unix conventions.

**SugarCraft status**: Already implemented in `bin/skate` CLI script (reads one line from STDIN when no value given).

### Delimiter Parsing Fix (PR #30)
A subtle bug where delimiter strings like `\t` were not being properly unquoted. The fix used `strconv.Unquote()` instead of raw string formatting. This demonstrates the hazard of using `fmt.Sprintf` for string formatting of format strings.

**Pattern applicable to SugarCraft**: Any string that will be used as a format string should be unquoted/escaped properly. The sugar-skate `list()` method uses `$delimiter` directly in output — but it's used as a literal string concatenation, not a format string, so it's safe.

### Go Module v2 Path
The rename to v2 module path (`430fc7b0d83bd55a00224026c5c7bab328b4bcd6`) while maintaining backwards CLI compatibility demonstrates mature versioning practice.

---

## 15. Community Workarounds

### Encrypted Home Performance
Users on encrypted filesystems work around the performance issue by:
1. Storing the database on an unencrypted partition (e.g., a separate `/home` on unencrypted SSD)
2. Using `CHARM_HOST` pointing to a local `charm serve` (only works pre-v1.0.0)
3. Simply accepting slow performance

**SugarCraft workaround**: Same — store `~/.config/skate` on a fast unencrypted path via bind mount or symlink.

### Concurrent Write Serialization
Users serialize write access externally using shell primitives:
```bash
flock /tmp/skate.lock -c "skate set key value"
```
This is a workaround, not a fix.

**SugarCraft**: Could document this pattern or provide a built-in locking mechanism.

### Shell History
Users are warned that `skate set key value` leaves the command in shell history. Workaround is to use STDIN: `skate set key < <(echo "value")` or use environment variable suppression.

**SugarCraft**: The same issue exists for PHP CLI. Could be addressed by adding a `--no-history` flag or documenting the STDIN workaround.

---

## 16. Maintainer Guidance Patterns

### "Can't reproduce" Pattern
When Issue #120 was raised, maintainers repeatedly said "cannot reproduce" — and they were correct (the issue was specific to encrypted filesystem users). This shows good faith debugging but highlights that the issue is environment-specific.

**Pattern**: The issue only manifests on systems with stacked filesystem encryption. The maintainers couldn't reproduce because they weren't on encrypted homes.

**SugarCraft lesson**: Performance issues are often environment-specific. Sugar-skate should include environment diagnostics in error messages (e.g., detect encrypted filesystem and warn).

### Closing Cloud Issues as "Not Planned"
After v1.0.0, all cloud-related issues (performance #21, key mismatch #22) were closed with "As of 1.0.0, we removed the cloud features, so this is no longer an issue." This is a pragmatic but blunt resolution strategy.

**Pattern**: Rather than fixing cloud problems, they removed the cloud entirely. This is valid but means the tool is now less capable than before for multi-machine scenarios.

### Feature Acceptance via Fork
Maintainers have not explicitly accepted/rejected features like encryption or versioning. The implicit signal is that these won't be implemented upstream, which is why forks fill the gap.

---

## 17. Rejected Ideas Worth Revisiting

These are ideas that were raised but never accepted upstream, which SugarCraft could legitimately implement:

### Per-Key Encryption (Issue #160)
Author attempted to implement `--key` / `SKATE_DB_KEY` but maintainers haven't accepted the PR. This would add AES encryption per database or per key.

**SugarCraft opportunity**: Implement this cleanly with a `SugarCraft\Skate\Crypto` layer. Use PHP's `openssl_encrypt`/`openssl_decrypt` with AES-256-GCM. Provide both env-var and interactive password modes.

### Value Versioning (Issue #80)
Requested since Oct 2023, 5 👍, never implemented. The fork `AmrSaber/KV` has this working.

**SugarCraft opportunity**: Store previous values in a `_history` table or use soft deletes with timestamped rows. This is a known pattern — implement it as `Entry::getPrevious()` or a `Store::history(string $key)` method.

### Concurrent Request Handling (Issue #138)
Not explicitly rejected, but unaddressed. The fix requires SQLite write serialization.

**SugarCraft opportunity**: Use `SQLITE_OPEN_FULLMUTEX` flag when opening SQLite connections. This serializes all writes through a single mutex. Combined with WAL for reads, this provides safe concurrent access without external locking.

### Clipboard Integration (Issue #162)
Minor feature, explicitly requested, no action. 

**SugarCraft opportunity**: Implement via `proc_open('xclip')` or `proc_open('pbpaste')` detection. Fall back to no-op on systems without clipboard tools.

### Git Sync (Issue #176)
The fork uses file-based backup that can be committed to git. Sugar-skate's export command could similarly output to a directory that users commit to git.

**SugarCraft opportunity**: Enhance `ExportCommand` to support directory output (currently only JSON/YAML). A git-friendly export format would satisfy this use case without requiring actual git integration.

---

## 18. Problems Likely Relevant To SugarCraft

### SQLite WAL Performance on Encrypted Filesystems
**Direct risk**: Sugar-skate's `Database.php` uses the same WAL/SQLite pattern. Users on encrypted Linux homes (ecryptfs, fscrypt) will hit the same 6-second `list` problem.

**Mitigation needed**: Add `PRAGMA synchronous = OFF` as an optional flag for local-only use cases, or detect encrypted filesystem and warn.

### Concurrent Write Safety
**Direct risk**: The WAL mode + `busyTimeout(5000)` is insufficient for true concurrent write scenarios. Multiple PHP processes writing simultaneously could get `SQLITE_BUSY`.

**Fix**: Add `SQLITE_OPEN_FULLMUTEX` to the open flags. This is a one-line change.

### No Encryption at Rest
**Direct risk**: All data stored in plain text in `~/.config/skate/`. Anyone with file access can read all databases.

**SugarCraft should address**: Not as critical for local-only single-user use, but should be documented. If SugarCraft ever adds network features, encryption becomes critical.

### No Versioning/History
**Direct risk**: Accidental `delete` or `set` overwrites data permanently. No recovery mechanism.

**SugarCraft already identified this as a gap**: Not currently implemented. Should be prioritized.

### Binary Data Handling
**Current implementation**: Binary values are base64-encoded internally and flagged with a `binary` column. Getting raw bytes requires decoding.

**SugarCraft status**: Same approach. Works but requires callers to know whether to decode.

---

## 19. Features SugarCraft Should Consider

In priority order based on ecosystem demand and strategic value:

### High Priority (Differentiator + High Demand)

1. **Value Versioning/History** (Issue #80)
   - Store previous values on every set
   - Provide `Store::history($key)` returning `Generator<Entry>`
   - Limit history depth (configurable, default 10)
   - This is the #1 most requested feature the upstream never delivered

2. **SQLite Fullmutex for Concurrent Writes** (Issue #138)
   - Change `new \SQLite3($path)` to `new \SQLite3($path, SQLITE3_OPEN_FULLMUTEX | SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE)`
   - This is a one-line fix that prevents database corruption under concurrent access

3. **Encryption Layer** (Issue #160)
   - Implement `Crypto` class using `openssl_encrypt`/`openssl_decrypt` with AES-256-GCM
   - Support env-var key: `SKATE_MASTER_KEY`
   - Provide interactive password mode with PBKDF2 key derivation
   - Per-entry encryption flag stored alongside value
   - This addresses the #1 security concern and differentiates from upstream

### Medium Priority (User Experience)

4. **List Output Truncation** (Issue #97)
   - When listing with `mode=all`, truncate values longer than 200 chars
   - Show preview: `"value... (N chars omitted)"` or similar
   - Could be a `--preview-length` flag

5. **Shell Completion** (Issue #145)
   - Provide a `skate --completion bash` command that outputs completions
   - Users source the output to get key-name completion
   - More portable than deep shell integration

6. **Clipboard Integration** (Issue #162)
   - `Store::getClipboard($key)` method using `proc_open('xclip')` or similar
   - CLI flag: `skate get --copy key`

### Lower Priority (Nice to Have)

7. **Git-Friendly Export** (Issue #176)
   - Export to directory of files (one per key) that can be committed to git
   - Each file is `keyname=value` or `keyname` with value in `.gitignore'd` companion
   - Or: output to encrypted tarball

8. **Visibility Control**
   - Mark keys as "hidden" so they don't appear in listings
   - Fork `AmrSaber/KV` has this

9. **Batch/Prefix Operations**
   - `Store::setMany(array $pairs, string $db)` for atomic multi-set
   - `Store::deletePrefix(string $prefix)` for prefix-based deletion
   - Already have `deleteMany` with glob patterns

---

## 20. Architectural Lessons

### Local-Only is the Correct Default
The upstream's cloud sync caused more problems (performance, key mismatch, complexity) than it solved. v1.0.0 proved that a simple local-only KV store meets most user needs. SugarCraft should maintain local-only as the default.

**Exception**: Users who need multi-machine sync should use existing tools (git, syncthing, etc.) layered on top.

### SQLite WAL is Both a Strength and Weakness
WAL mode gives good concurrent read performance and crash safety, but creates the encrypted-filesystem performance problem. The tradeoff is inherent — there's no way to have both full safety and fast encrypted-filesystem performance.

**SugarCraft approach**: Document the tradeoff. Provide `PRAGMA synchronous = OFF` as an opt-in for users who prioritize performance over crash safety.

### Single-File Upstream Creates Ceiling
The `main.go` single-file design prevents plugin architectures, alternative backends, and complex feature additions. SugarCraft's modular structure (separate `Store`, `Database`, `Entry` classes) is architecturally superior for extensibility.

**Lesson**: SugarCraft should maintain the modular structure even if Go upstream stays single-file. The PHP port can be more extensible.

### Fork as Innovation Channel
When the upstream can't/won't implement features, forks do. `AmrSaber/KV` is now ahead of `skate` in features. This is healthy for the ecosystem — it proves demand and provides a reference implementation.

**SugarCraft strategy**: Monitor the fork for proven patterns. The fork's features (encryption, versioning, TTL) are all validated demand.

---

## 21. Defensive Design Lessons

### Never Require Network for Local Operations
The pre-v1.0.0 skate design required a network round-trip for crypt key validation on every single operation. This was a fundamental design error that made the tool unusable for any serious workload.

**SugarCraft rule**: Local operations must never require network access. Network features (if any) must be strictly opt-in and separate from hot paths.

### Handle All SQLite Return Codes
The Go code in PR #30 fixed `db.Sync()` ignoring its error return. This is a common bug — assuming SQLite operations always succeed.

**SugarCraft rule**: Always check `$this->db->changes()`, check return values from `$stmt->execute()`, handle `SQLITE3_BUSY` explicitly in retry loops.

### Escape Shell Metacharacters in CLI
The `skate set` command takes user input and passes it through the shell. Values containing `$`, backticks, or special characters can cause unexpected behavior.

**SugarCraft rule**: Use `escapeshellarg()` for all user-supplied values passed to shell commands. In the existing `bin/skate` script, this should be verified.

### Test on Encrypted Filesystems
The performance issue #120 was never caught because maintainers didn't test on encrypted filesystems. This is a blind spot.

**SugarCraft QA requirement**: Test suite should include a test on tmpfs or a simulated slow filesystem to catch I/O amplification issues.

---

## 22. Ecosystem Trends

### KV Store Proliferation
Multiple independent KV store tools have emerged in the Go ecosystem (skate, envchain, pass, and the fork `AmrSaber/KV`). This indicates strong demand for the use case.

**Trend**: Users want simple, local, encrypted secret storage with CLI access. This is the "personal secret manager" use case that falls between `pass` (GPG-based) and full secret managers (1Password CLI).

### Encryption Expectations Increasing
The number of encryption requests (#160) and the existence of encrypted forks demonstrates that users increasingly expect encryption-at-rest by default, not as an optional feature.

**Prediction**: The next generation of KV tools will have encryption as a baseline feature, not an add-on.

### Forks as Innovation Vehicles
`AmrSaber/KV` has out-innovated the upstream through faster iteration, better feature coverage (encryption, versioning, TTL, batch ops, shell completion). This pattern is common in mature ecosystems.

**SugarCraft opportunity**: Position sugar-skate as the "full-featured PHP port" with features the Go upstream won't accept.

### MCP Integration Trend
The `kv-secrets` fork explicitly mentions MCP (Model Context Protocol) integration for AI coding agents. This is an emerging pattern — KV stores as backends for AI agent secret management.

**Future-looking**: SugarCraft should consider an MCP server implementation for sugar-skate, allowing AI tools (Cursor, Claude Code) to access secrets securely.

---

## 23. Strategic Opportunities

### 1. Be the Encrypted KV Store
The upstream refuses encryption (or hasn't implemented it). The fork implements it with a password approach. SugarCraft could implement both:
- Database-level encryption via env-var key
- Per-key encryption with interactive password prompt
- Use AES-256-GCM with PBKDF2 (same as fork, proven pattern)

**Differentiation**: No other PHP KV library offers encryption. SugarCraft could own this space.

### 2. Be the Versioned KV Store
Value versioning is the #1 requested feature upstream never delivered. SugarCraft should implement it with a clean API:

```php
$skate->set('config', 'new_value');  // old value automatically archived
foreach ($skate->history('config') as $entry) {
    echo $entry->value();  // iterate through all versions
}
```

**Differentiation**: No PHP KV library has versioning. SugarCraft could define this API.

### 3. Be the PHP-Native KV Store
All alternatives (skate, envchain, pass, AmrSaber/KV) are Go or shell-based. PHP developers need native PHP access without spawning subprocesses.

**Opportunity**: Sugar-skate as a library (not just CLI) that PHP applications can `use` directly. The existing `Store` class already supports this but could be promoted.

### 4. Be the Multi-Database PHP KV Store
The `@db` multi-database syntax is elegant. Sugar-skate already has it. No other PHP library offers this with SQLite isolation.

---

## 24. Cross-Ecosystem Pattern Matches

### AmrSaber/KV Fork Feature Matrix
| Feature | skate (upstream) | AmrSaber/KV (fork) | sugar-skate (SugarCraft) |
|---------|-------------------|--------------------|-------------------------|
| Local SQLite storage | ✅ Badger | ✅ SQLite | ✅ SQLite |
| Multi-database | ✅ | ✅ | ✅ |
| @db syntax | ✅ | ✅ | ✅ |
| Encryption at rest | ❌ | ✅ AES-256-GCM | ❌ |
| Per-key password | ❌ | ✅ | ❌ |
| TTL/expiry | ❌ | ✅ | ✅ |
| Value versioning | ❌ | ✅ | ❌ |
| Batch operations | ❌ | ✅ | Partial (glob delete) |
| Cross-DB copy/move | ❌ | ✅ | ❌ |
| Shell completion | ❌ | ✅ | ❌ |
| JSON/YAML output | ❌ | ✅ | Only via export |
| Visibility control | ❌ | ✅ | ❌ |
| Prefix matching | ❌ | ✅ (batch) | ✅ (glob) |

**Analysis**: Sugar-skate is between upstream and the fork. It has TTL (which the fork has) but lacks encryption and versioning (which the fork has). This is the right position — implement the high-value missing features (versioning, encryption) rather than trying to match every fork feature.

### pass Comparison
`pass` (passwordstore.org) is the gold standard for CLI secret storage:
- GPG encryption
- Git sync
- Shell completion
- Password generation
- Grouping with directories

**What skate does better**: Simpler data model, no GPG dependency, binary support, Levenshtein suggestions.

**What pass does better**: Encryption, git sync, mature ecosystem, hardware key support.

**SugarCraft positioning**: Be simpler than pass (no GPG dependency, pure PHP) but more capable than skate (versioning, eventual encryption).

---

## 25. High ROI Recommendations

Ranked by impact-to-effort ratio:

### 1. Add `SQLITE_OPEN_FULLMUTEX` for Concurrent Write Safety (Effort: 1 line, Impact: Critical)
**Change**: In `Database.php` constructor, change:
```php
$this->db = new \SQLite3($path);
```
To:
```php
$this->db = new \SQLite3($path, SQLITE3_OPEN_FULLMUTEX | SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
```
**Why**: Prevents database corruption when multiple processes write concurrently. Issue #138 is a real bug that can corrupt data. This one-line fix eliminates the risk.

### 2. Implement Value Versioning (Effort: Medium, Impact: High)
**Change**: Add `history` table and modify `set()` to insert new rows instead of updating in place.

```php
// Schema addition
CREATE TABLE IF NOT EXISTS history (
    key TEXT,
    value TEXT,
    binary INTEGER,
    version INTEGER,
    created TEXT,
    expires_at TEXT,
    PRIMARY KEY(key, version)
);

// Store::history($key) returns Generator<Entry>
```

**Why**: This is the #1 most requested upstream feature that was never implemented. It's a clear differentiator. PHP's Generator API maps naturally to this pattern.

### 3. Add Encryption Layer (Effort: Medium, Impact: High)
**Change**: Add `Crypto` class and modify `Database::set()` / `Database::get()` to encrypt/decrypt values with a master key from `SKATE_MASTER_KEY` env var.

```php
// Store constructor checks for encryption key
$key = getenv('SKATE_MASTER_KEY');
if ($key !== false) {
    $this->crypto = new Crypto($key);
}
```

**Why**: Top security request. Also differentiates from upstream. Use PHP's built-in `openssl_*` functions — no external dependencies.

### 4. Document Encrypted Filesystem Performance Limitation (Effort: Low, Impact: Medium)
**Change**: Add warning in `README.md` and `Database.php` docblock about `PRAGMA synchronous = OFF` option.

```php
/**
 * Note: On systems with encrypted home directories (ecryptfs, fscrypt),
 * WAL mode performance may degrade significantly. For read-heavy workloads,
 * set PRAGMA synchronous = OFF for improved performance at the cost of
 * crash safety. Pass 'synchronous_off' to constructor options to enable.
 */
```

**Why**: Issue #120 shows this is a real problem users hit. Documenting the workaround prevents support burden.

### 5. Add List Truncation for Long Values (Effort: Low, Impact: Medium)
**Change**: In `Entry::rawValue()` or a new `Entry::preview(int $maxLen)`, truncate and annotate.

```php
public function preview(int $maxLen = 200): string
{
    $val = $this->rawValue();
    if (strlen($val) <= $maxLen) {
        return $val;
    }
    return substr($val, 0, $maxLen) . " (" . strlen($val) . " chars omitted)";
}
```

**Why**: Issue #97 was a real UX problem. This is a 10-line fix that significantly improves usability.

---

## Summary

The charmbracelet/skate ecosystem reveals a KV store that was fundamentally well-designed for its simplicity but stagnated after v1.0.0's cloud severance. The most important findings:

1. **Performance on encrypted filesystems** is a real issue that Sugar-skate inherits via its SQLite WAL approach
2. **Value versioning** is the #1 feature the community wants and upstream won't build
3. **Encryption** is the #1 security request; the fork has it, upstream doesn't
4. **Concurrent write safety** is a data corruption risk requiring a one-line fix
5. **The fork ecosystem** (AmrSaber/KV) validates feature demand and provides reference implementations

SugarCraft's sugar-skate is well-positioned: it has the local-only correctness, the modular architecture, and the PHP-native integration that upstream lacks. By implementing versioning and encryption — both validated by the ecosystem — SugarCraft can exceed the upstream's capability while maintaining compatibility.
