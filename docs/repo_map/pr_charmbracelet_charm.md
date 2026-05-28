# Second-Stage Ecosystem Intelligence Report: charmbracelet/charm

## 1. Repository Overview

**charmbracelet/charm** was a cloud infrastructure layer for terminal applications, providing encrypted key-value storage, a cloud filesystem, and SSH-based user authentication. It was **archived on March 6, 2025** and officially **sunset on November 29, 2024** when Charm Cloud services were discontinued.

| Attribute | Value |
|-----------|-------|
| URL | https://github.com/charmbracelet/charm |
| Language | Go |
| Stars | ~2.5k |
| License | MIT |
| Status | Archived (read-only) |
| Archive Date | March 6, 2025 |
| Closed Issues | ~100+ |
| Closed PRs | 243 |
| Open Issues | 0 (all closed or migrated) |
| Open PRs | 0 |

**Core Components:**
- **Charm KV** — Encrypted, cloud-synced key-value store (BadgerDB)
- **Charm FS** — Cloud filesystem with E2E encryption
- **Charm Crypt** — SIV-mode encryption utilities
- **Charm Client** — SSH-based auth with JWT generation
- **Self-hosted Server** — Single-binary `charm serve` with SQLite

---

## 2. Existing SugarCraft Mapping

From `repo_map/charmbracelet_charm.md`:

| charm Component | SugarCraft Library | Notes |
|----------------|-------------------|-------|
| `kv/` package | `sugar-bits` | KV store, not direct port |
| `fs/` package | `candy-shine` or `sugar-bits` | Filesystem FS interface |
| `crypt/` package | (none) | No encryption port yet |
| `client/` package | `candy-shell` | Shell/env config management |
| `ui/` (Bubble Tea) | `candy-sprinkles` | TUI component patterns |
| `server/` package | (none) | Server-side, not applicable |
| `cmd/` package | `candy-shell` | CLI primitives |
| Overall architecture | `candy-core` | Elm-style MVC TUI pattern |

**Identified Opportunities:**
- **Sugar-KV** — PHP port of encrypted KV store
- **Sugar-FS** — PHP `fs.FS`-compatible filesystem with encryption
- **Sugar-Crypt** — E2E encryption using PHP's OpenSSL

---

## 3. Previously Identified Gaps

From `repo_map/charmbracelet_charm.md`:

1. **Project sunset** — Charm Cloud closed Nov 2024
2. **Single cloud dependency** — Default `cloud.charm.sh` is offline
3. **SQLite limitation** — Server may not scale for high-traffic
4. **No horizontal scaling** — Single server, local file storage
5. **SSH requirement** — Port 35353 exposure problematic
6. **Complex sync logic** — Sequence-based KV sync has subtle invariants
7. **Limited query** — KV only supports key-value, no range queries
8. **Binary blob storage** — No native type system
9. **No access control** — All linked machines have full access
10. **Documentation aging** — Self-hosting docs incomplete

---

## 4. High-Signal Open Issues

**Status: All issues are now closed.** The repository was archived with 0 open issues. However, the closed issues reveal critical signals.

### Most Impactful Closed Issues:

| Issue | Title | Impact | Resolution |
|-------|-------|--------|------------|
| #281 | jwt is invalid after #255 | **Critical** — Self-host auth broken for all users after dependency update | Fixed in PR #286 |
| #254 | crypto cannot be upgraded due to dot-import from caarlos0/sshmarshal | **Critical** — Crypto deps effectively frozen, security vulnerabilities accumulate | Fixed in PR #255 |
| #253 | auth0/go-jwt-middleware/v2@v2.0.1 cannot be upgraded | **High** — JWT security issues couldn't be patched | Fixed in PR #255 |
| #252 | square/go-jose deprecated | **High** — Deprecated crypto library with known weaknesses | Fixed in PR #255 |

**Signal:** The dependency upgrade chain was fragile. JWT validation had a type mismatch bug that broke self-hosted servers. The crypto package had dot-import conflicts that prevented upgrades for months, creating a security debt.

---

## 5. Important Closed Issues

### Bug Issues (Closed):

| Issue | Title | Root Cause | Impact |
|-------|-------|------------|--------|
| #281 | jwt is invalid after #255 | JWKS validation using wrong type (RS256 vs HS256) | Self-hosted auth completely broken |
| #254 | crypto cannot upgrade (dot-import conflict) | caarlos0/sshmarshal dot-import conflicts with golang.org/x/crypto/ssh | Crypto deps frozen |
| #70 | `charm kv get` breaks terminal with binary data | Binary output not handled, escape codes written to terminal | Terminal corruption |
| #69 | `charm kv get` adds extra newline | Output formatting bug | Data corruption for binary |
| #91 | backup-keys doesn't work | Backup functionality broken | Data loss risk |
| #93 | ui/keygen not generating keys for host | Key generation failure | Auth broken |
| #5 | Import keys to wrong directory | Path resolution bug | Key loss |
| #4 | Windows backup creates blank tarballs | Recursive directory handling bug | Backup failures on Windows |

### Feature Enhancement Issues (Closed):

| Issue | Title | Request | Outcome |
|-------|-------|--------|---------|
| #295 | How to use News feature | Document undocumented feature | Not planned (skipped) |
| #293 | Any Plan for New Release | Feature release inquiry | Not planned (skipped) |
| #291 | Add direct Stat() method for File | FS enhancement | Not planned (skipped) |
| #288 | What about Message Queue | MQ feature request | Not planned (skipped) |
| #287 | Add Password-Protect for Self-Host | Security enhancement | Not planned (skipped) |
| #283 | Charm embeddable chat | Extensibility request | Not planned (skipped) |
| #151 | feat: show charm info location | UX improvement | Completed |
| #116 | Add Melt Support for Backups | Backup enhancement | Completed |
| #110 | Show Current Host in UI | UX improvement | Completed |

**Signal:** Nearly all feature requests during the sunset period were marked "Not planned (skipped)." The project was effectively in maintenance mode before archival.

---

## 6. Recurring Pain Points

### From Issues and Discussions:

1. **Self-hosting complexity** — Users struggled with TLS setup. Discussion #112 shows confusion about reverse proxy configurations, Let's Encrypt, and SSH port handling.

2. **Dependency lock-in** — The crypto package used dot-imports that created version conflicts, preventing security updates for months.

3. **JWT/auth fragility** — A single type mismatch in JWT validation broke all self-hosted servers (issue #281).

4. **Binary data handling** — KV get command didn't properly handle binary data, corrupting terminals and adding extra newlines.

5. **Windows compatibility** — Multiple issues with tarball creation, path separators, and backup recursive operations on Windows.

6. **Key import/export confusion** — Users couldn't reliably migrate keys between installations.

7. **SSH port restrictions** — Port 35353 is often blocked in corporate/network environments, making the auth mechanism unusable.

### From Sunset Announcement (Discussion #297):

- Users relied on Charm Cloud for quick sharing of SSH public keys, clipboard sharing
- Community offered to maintain forks but lacked coordination
- Alternatives mentioned: pipe.pico.sh, patchbay.pub
- No community takeover materialized

---

## 7. Frequently Requested Features

### From Discussions and Issues:

| Feature | Discussion | Signal |
|---------|-----------|--------|
| **KV TTL support** (#111) | Expiration time for keys | Strong demand for cache/session-like semantics |
| **Turn off FS encryption** (#159) | Option to disable E2E encryption for server-accessible files | Flexibility vs security tradeoff |
| **Signature support** (#114) | Sign arbitrary data with identity, verify signatures | Keybase/saltpack-style use cases |
| **Key choices and TPMs** (#113) | P256 + TPM 2.0 integration for hardware-bound keys | Enterprise security demand |
| **Password-protect self-host** (#287) | Auth for self-hosted server access | Multi-user security |
| **Message Queue** (#288) | Asynchronous messaging between users | Collaboration features |
| **Embeddable chat** (#283) | Chat as a component in other apps | Extensibility |
| **Direct Stat() method** (#291) | File metadata without full read | Performance |

### Highest Value Rejected Features (Worth Revisiting):

1. **KV TTL** — Validates SugarCraft needs cache expiration semantics
2. **Signature/verification API** — Strong use case for decentralized identity
3. **TPM 2.0 integration** — Hardware key binding is increasingly important
4. **Optional encryption mode** — Performance vs security configuration

---

## 8. Important PRs

| PR | Title | Significance |
|----|-------|--------------|
| #286 | fix: jwks validation using the wrong type | Critical fix for self-host auth |
| #255 | fix: update keygen, crypto, go-jose, go-jwt-middleware | Unfroze dependency chain |
| #307 | docs(readme): update sunset notice | Official sunset communication |
| #310 | ci: sync dependabot config | Maintenance PR |
| #315-325 | Various dependency bumps | Keep dependencies current |

**Most Critical PR:** #286 — JWKS validation type mismatch caused all self-hosted servers to fail authentication after upgrading dependencies. Shows the fragility of the auth system.

---

## 9. Architectural Changes

### Notable Architectural Evolution:

1. **Default key type shift** — RSA → Ed25519 (Discussion #113)
   - RSA was original default, switched to Ed25519 for better security/performance

2. **JWT library migration** — square/go-jose → golang-jwt/jwt/v4 (PR #255)
   - go-jose was deprecated; migration was blocked by dot-import conflicts

3. **Keygen library update** (PR #255)
   - Updates to charmbracelet/keygen to fix compatibility

4. **SSH authentication method** — PKAM (Public Key Authentication Method)
   - Custom SSH auth method, not standard public key auth

### Architectural Weaknesses Exposed:

1. **Fragile auth path** — JWT validation had hardcoded assumptions about key types
2. **Dot-import anti-pattern** — Caused dependency lock-in
3. **No migration path** — Sunsetting showed no thought given to data portability
4. **Single-server design** — No clustering or horizontal scaling

---

## 10. Performance Discussions

No explicit performance issues in the tracker. Implicit signals:

1. **SQLite at scale** — Single-server SQLite backend would hit limits with many users
2. **Sequence-based sync** — Monotonically increasing sequence numbers for KV ordering
3. **BadgerDB streaming** — Used for incremental backup, works but not optimal
4. **Path encryption** — Deterministic encryption of path components may leak patterns

**Performance Lessons:**
- KV store needed range queries but only had prefix scan
- FS operations needed Stat() without full read (requested in #291)
- Binary data handling broke terminal (issue #70)

---

## 11. Extensibility Discussions

### Extensibility Requests:

1. **Embeddable chat** (#283) — Users wanted to embed Charm chat in other apps
2. **Signature support** (#114) — Sign/verify arbitrary data with identity
3. **Plugin system** — No explicit plugin architecture; users wanted to extend

### Limitations Found:

1. **Tight coupling** — Client, KV, FS, and Crypt are separate packages but tightly bound in practice
2. **No clean API surface** — Internal types leaked (e.g., proto definitions)
3. **Server not designed for extension** — Single binary, no plugin hooks
4. **Hardcoded Charm Cloud** — Default endpoint embedded in client

### SugarCraft Opportunity:
A cleaner PHP-based encrypted storage library could provide better separation of concerns with a clean extension API.

---

## 12. API/UX Complaints

### API Complaints:

1. **Binary data output** — `kv get` corrupts terminal with binary data (issue #70)
2. **Extra newlines** — `kv get` adds unwanted newline (issue #69)
3. **No type system** — All values are `[]byte`, no JSON/strings
4. **Limited query** — KV only supports key-value, no range queries

### UX Complaints:

1. **Self-hosting docs unclear** (Discussion #112) — TLS setup confusing, reverse proxy configuration unclear
2. **Import/export confusing** — Keys imported to wrong location (issue #5)
3. **Backup confusing** — Help dialogue unclear (issue #115)
4. **No info on data location** — Users couldn't find where data was stored (issue #151)

### SugarCraft Lessons:

1. **Always escape binary output** — Never write raw bytes to terminals
2. **Type systems matter** — PHP's type system should be leveraged
3. **Documentation is product** — Self-hosting docs made or broke adoption
4. **User feedback loops** — Clear data location/status information

---

## 13. Migration Problems

### Known Migration Issues:

1. **Key migration** — Import keys to wrong directory (issue #5), override existing keys (issue #129)
2. **No portable backup format** — Backup/restore was complex and error-prone
3. **JWT invalidation** — After upgrading, existing JWT tokens became invalid (issue #281)
4. **Deprecation without migration** — go-jose deprecated, no smooth upgrade path

### Sunset Migrating Lessons:

1. **No community handoff** — When Charm Cloud closed, no smooth transition
2. **Alternative ecosystem emerged** — pipe.pico.sh, patchbay.pub filled niche
3. **88 forks** — Repository has 88 forks, indicating community interest but no unified effort

### For SugarCraft:

1. **Plan for data portability from day one**
2. **Provide clear migration paths for API changes**
3. **Document backup/restore thoroughly**
4. **Consider community governance if project is successful

---

## 14. Clever Fixes & Workarounds

### Community Workarounds Found:

1. **TLS termination at load balancer** (Discussion #112)
   - Use AWS NLB for SSH (layer 4) + ALB for HTTPS (layer 7)
   - TLS terminated at LB, plain HTTP to Charm instance

2. **Docker + Traefik + Let's Encrypt** (Discussion #112)
   - Reverse proxy setup for self-hosting
   - SSL termination at Traefik, plain HTTP to Charm

3. **Multiple key fallback** — Multiple linked machines with different encryption keys
   - Graceful fallback when decrypting with different keys

4. **Sequence-based sync** — Monotonically increasing sequence numbers
   - Backup files stored as `name/seq` in FS for ordering

### Internal Fixes:

1. **PR #255** — Fixed dot-import conflict by removing caarlos0/sshmarshal dependency
2. **PR #286** — Fixed JWKS validation type (HS256 vs RS256 mismatch)
3. **Various backup fixes** — Windows tarball handling

---

## 15. Community Workarounds

### User-Discovered Patterns:

1. **SSH key sharing** — Used Charm Cloud for sharing SSH keys when setting up new machines
2. **Clipboard replacement** — Quick text sharing between machines via Charm
3. **Reverse proxy TLS** — Nginx/Apache/Traefik with Let's Encrypt for TLS
4. **Multi-machine sync** — Used KV for multi-machine state synchronization

### Workarounds for Broken Features:

1. **Fresh install for auth issues** — When JWT failed, users deleted local data and re-linked
2. **Windows path workaround** — Used WSL for problematic path operations
3. **Manual backup** — Direct file copy instead of `charm backup-keys`

---

## 16. Maintainer Guidance Patterns

### Maintainer Responses:

1. **Sunset announcement** (Discussion #297) — Clear timeline, self-hosting instructions, alternatives offered
2. **TLS setup guidance** (Discussion #112) — Detailed explanation of architecture, reverse proxy patterns
3. **Key type clarification** (Discussion #113) — Explained Ed25519 default, RSA concerns addressed
4. **Quick bug fixes** — PRs merged fast for critical issues (#286 merged same day as reported)

### Guidance Style:

1. **Detailed technical explanations** — Maintainers explained internal architecture
2. **Alternative suggestions** — When features couldn't be implemented, alternatives offered
3. **Community involvement** — Invited PRs for docs improvements

### For SugarCraft Maintainers:

1. **Document everything** — TLS confusion could have been avoided with better docs
2. **Respond quickly to auth issues** — Charm's response to #281 was fast (same day)
3. **Provide clear sunset plans** — Charm's announcement was well-received despite being end-of-life

---

## 17. Rejected Ideas Worth Revisiting

### Features Requested But Not Implemented:

1. **KV TTL support** (#111) — Valid use case, rejected likely due to BadgerDB limitations
2. **Turn off FS encryption** (#159) — Security vs flexibility tradeoff, never addressed
3. **Password-protect self-host** (#287) — Multi-user security, never implemented
4. **Message Queue** (#288) — Collaboration feature, scope creep
5. **Embeddable chat** (#283) — Extensibility beyond original scope
6. **TPM 2.0 integration** (#113) — Hardware keys, complex platform-specific work

### These represent legitimate needs that SugarCraft could address:

| Feature | SugarCraft Opportunity | Difficulty |
|---------|----------------------|------------|
| KV TTL | Implement expiration in PHP-based KV | Medium |
| Optional encryption | Configurable E2E vs server-side encryption | Medium |
| Multi-user auth | Add password/permission system to self-host | High |
| TPM integration | Hardware key binding (future) | High |

---

## 18. Problems Likely Relevant To SugarCraft

### Direct Risk Areas:

1. **Encrypted storage implementation** — Charm's crypto had subtle bugs; SugarCraft must get this right
   - Dot-import conflicts (PR #254)
   - JWT type confusion (PR #286)
   - Key derivation (Scrypt) complexity

2. **KV store design** — Charm's sequence-based sync was complex
   - SugarCraft's KV needs simpler semantics for PHP
   - TTL support was requested multiple times

3. **SSH-based auth** — Port restrictions, TLS confusion
   - SugarCraft may need similar auth patterns
   - Environment variable configuration was good

4. **Binary data handling** — Charm broke terminals with binary output
   - SugarCraft TUI components must handle binary gracefully

### Indirect Risk Areas:

1. **Dependency management** — Charm's deps became security liabilities
   - SugarCraft should avoid dot-imports and tight coupling
   - Use interface-based designs for flexibility

2. **Self-hosting complexity** — Charm's docs were insufficient
   - SugarCraft should invest heavily in self-hosting docs

3. **Community handoff** — Charm had no plan for community maintenance
   - SugarCraft should consider governance early

---

## 19. Features SugarCraft Should Consider

### High-Value Features from Charm Ecosystem:

| Feature | Evidence | SugarCraft Approach |
|---------|---------|-------------------|
| **KV TTL/expiration** | #111 (multiple requests) | Implement optional expiration |
| **Clean binary storage API** | #70, #69 (terminal corruption) | Always encode/escape binary |
| **Optional encryption** | #159 (turn off FS encryption) | Configurable encryption modes |
| **Signature/verification** | #114 (saltpack, keybase use cases) | Sign/verify operations |
| **TPM/hardware keys** | #113 (P256, TPM 2.0) | Future: P-256 + TPM binding |
| **Multi-user permissions** | #287 (password-protect server) | User/role system |
| **Better self-hosting docs** | #112 (TLS confusion) | Comprehensive self-hosting guide |

### SugarCraft-Specific Opportunities:

1. **PHP-native crypto** — Use PHP 8's crypto extensions instead of Go
2. **PDO/SQLite backend** — More portable than BadgerDB for PHP
3. **Async patterns** — ReactPHP for non-blocking operations
4. **Type-safe KV** — JSON values with typed get/set

---

## 20. Architectural Lessons

### From Charm's Successes:

1. **Zero-friction auth** — SSH key-based, no passwords/registration
2. **E2E encryption** — Server never sees plaintext
3. **Cloud + local sync** — Offline capability with multi-machine coherence
4. **Single binary deploy** — No external dependencies for self-host
5. **Environment-driven config** — No config files, all env vars

### From Charm's Failures:

1. **Dependency dot-imports** — Caused upgrade lock-in for months
2. **No horizontal scaling** — Single server, single SQLite
3. **Tight coupling** — Client and server tightly bound
4. **No migration path** — Sunsetting had no graceful handoff
5. **Fragile auth** — Type mismatch broke all self-hosted servers
6. **Insufficient self-hosting docs** — Users confused about TLS

### For SugarCraft:

```
DO:
- Use interface-based dependency injection
- Document every configuration option
- Provide clear error messages
- Plan for horizontal scaling
- Separate client and server concerns cleanly

DON'T:
- Use dot-imports or tight coupling
- Embed default endpoints in client
- Skip migration planning
- Defer docs until after launch
```

---

## 21. Defensive Design Lessons

### Security Defensive Lessons:

1. **Crypto agility** — Charm's crypto was tied to specific implementations; SugarCraft should abstract crypto behind interfaces for algorithm flexibility

2. **JWT validation paranoia** — The JWKS validation bug (issue #281) used wrong type; SugarCraft should:
   - Explicitly specify expected algorithm types
   - Validate all JWT claims before use
   - Provide migration path for algorithm changes

3. **Key derivation complexity** — Scrypt + SIV + SSH marshal; simpler is more secure:
   - Use well-audited crypto libraries
   - Minimize custom crypto implementations
   - Provide clear documentation of crypto choices

4. **Dot-import anti-pattern** — Created upgrade deadlock:
   - Never use dot-imports
   - Explicit imports only
   - Test dependency upgrades in CI

### Operational Defensive Lessons:

1. **Backup integrity** — Charm's backup had bugs on Windows:
   - Test on all target platforms
   - Verify backup/restore round-trips
   - Provide checksums for verification

2. **Binary data boundaries** — Terminal corruption (issue #70):
   - Always encode binary data for display
   - Use base64 or hex encoding outside TTY
   - Never assume output is text

3. **Error message quality** — Users confused about TLS:
   - Provide actionable error messages
   - Include configuration examples in errors
   - Link to documentation

---

## 22. Ecosystem Trends

### From Charm's Lifecycle:

1. **CLI cloud storage growing** — Charm was ahead of its time
2. **Zero-friction auth demand** — SSH key-based auth appeals to developers
3. **E2E encryption expectation** — Users increasingly demand E2E
4. **Self-hosting desire** — Users want control, not lock-in
5. **Extensibility expectations** — Users want embeddable components

### Adjacent Ecosystem Signals:

1. **pipe.pico.sh** — Emerged as Charm alternative for SSH-based sharing
2. **patchbay.pub** — Decentralized alternative
3. **88 forks of Charm** — Indicates community interest, lack of governance
4. **TPM 2.0 becoming standard** — Hardware key support increasingly expected
5. **Passwordless auth trends** — WebAuthn, passkeys, SSH keys

### For SugarCraft Strategic Planning:

| Trend | SugarCraft Position | Action |
|-------|-------------------|--------|
| CLI cloud storage | Implement Sugar-KV for PHP | High priority |
| E2E encryption | Sugar-Crypt library | High priority |
| Zero-friction auth | Leverage PHP crypto | Medium |
| Self-hosting | Provide single-binary option | Medium |
| Extensibility | Clean plugin API | Low-Medium |

---

## 23. Strategic Opportunities

### Market Opportunities:

1. **PHP encrypted storage** — No mature PHP equivalent to Charm KV
   - Gap: Local-first, E2E encrypted, sync-capable KV
   - SugarCraft could own this space

2. **Self-hostable infrastructure** — Charm showed demand, no good PHP option
   - Gap: PHP-based encrypted storage server
   - SugarCraft could provide Sugar-Server

3. **Type-safe KV with TTL** — Charm's KV lacked TTL and type system
   - Gap: PHP-native KV with JSON types and expiration
   - SugarCraft could implement Sugar-KV properly

### Competitive Advantages over Charm:

1. **PHP ecosystem integration** — Native PHP, no Go dependency
2. **Modern PHP crypto** — PHP 8's sodium and OpenSSL
3. **Simpler dependency management** — No dot-imports, clean interfaces
4. **Better documentation culture** — Learned from Charm's mistakes
5. **Planned governance** — Community handoff from day one

### Threat Avoidance:

1. **Don't embed default endpoints** — Make endpoints configurable
2. **Don't use fragile auth** — Use well-tested JWT libraries
3. **Don't skip docs** — Invest in self-hosting documentation
4. **Don't use exotic crypto** — Stick to audited implementations

---

## 24. Cross-Ecosystem Pattern Matches

### Patterns Observed in Charm That SugarCraft Should Match:

| Pattern | Charm Implementation | SugarCraft Should Do |
|---------|--------------------|-----------------------|
| Factory methods | `Theme::ansi()`, `Spinner::line()` | Match upstream naming |
| Immutable + fluent | `with*()` returns new instance | Implement `with*()` builders |
| Zero-config defaults | Env vars with sensible defaults | Auto-detect config |
| Error wrapping | `Unwrap()` for chained errors | PSR-7/18 compliance |
| Interface segregation | `fs.FS`, `io.Reader` | Implement standard interfaces |

### Anti-Patterns to Avoid:

| Anti-Pattern | Charm Example | SugarCraft Alternative |
|-------------|---------------|----------------------|
| Dot-imports | caarlos0/sshmarshal | Explicit package imports |
| Hardcoded defaults | `cloud.charm.sh` | Configurable endpoints |
| Type confusion | JWKS validation bug | Explicit type annotations |
| Binary terminal output | Issue #70 | Always encode binary |

---

## 25. High ROI Recommendations

### Immediate (High Impact, Low Effort):

1. **Document self-hosting from day one** — Charm's biggest failure was unclear self-hosting docs
   - Create comprehensive self-hosting guide
   - Document every env var with examples
   - Provide Docker/docker-compose examples

2. **Use interface-based crypto** — Charm's dot-import caused months of upgrade deadlock
   - Define `CryptoService` interface
   - Implement with `sodium` extension
   - Avoid tight coupling

3. **Implement binary safety** — Always encode/escape binary output
   - Base64 for API responses
   - Hex for CLI output
   - Never write raw bytes to terminal

4. **Add TTL support to KV design** — Multiple users requested this
   - Design TTL into initial KV schema
   - Use PHP's `+interval` syntax for expiration

### Medium-Term (High Impact, Medium Effort):

5. **Provide signature/verification API** — Saltpack-style use cases
   - `Crypt::sign(data)` / `Crypt::verify(signature)`
   - Attach signatures to messages
   - Support multiple key types

6. **Implement clean self-host server** — PHP-based alternative to `charm serve`
   - Single binary (PHP runtime packed)
   - SQLite for metadata
   - File storage for blobs

7. **Add hardware key support** — TPM/P-256 for enterprise
   - PHP extension for TPM
   - P-256 curve support
   - Hardware-bound key derivation

### Strategic (High Impact, High Effort):

8. **Create Sugar-KV library** — PHP port of encrypted KV
   - Local SQLite storage
   - Optional cloud sync
   - E2E encryption
   - TTL support

9. **Build Sugar-FS library** — PHP filesystem with encryption
   - Implement `FilesystemIterator` interface
   - Path encryption
   - File metadata support

10. **Design community governance** — Avoid Charm's handoff failure
    - Define contribution guidelines
    - Document release process
    - Plan for sun-setting scenarios

---

## Summary

Charmbracelet/charm was a well-architected but ultimately sunset project that provides invaluable lessons for SugarCraft. Its core innovation—zero-friction SSH-based authentication with E2E encrypted storage—remains compelling. The project's failure modes (dependency lock-in, fragile JWT validation, inadequate docs, no community handoff plan) are as instructive as its successes.

**Key Takeaways for SugarCraft:**

1. **Crypto is fragile** — Avoid dot-imports, use explicit interfaces, test thoroughly
2. **Docs are product** — Invest in self-hosting documentation from day one
3. **Plan for sunset** — Define community governance and migration paths
4. **User feedback validates** — TTL, signatures, TPM support are real demands
5. **Simpler is safer** — Minimize custom crypto, use audited implementations
6. **Binary safety always** — Never assume output is text in CLI tools
7. **Interface-based design** — Enable replacement and testing

SugarCraft has the opportunity to create a cleaner, more maintainable PHP-based encrypted storage ecosystem, learning from Charm's journey from launch through sunset to archive.
