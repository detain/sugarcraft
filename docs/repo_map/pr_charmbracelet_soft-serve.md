# Second-Stage Ecosystem Intelligence Report: charmbracelet/soft-serve

## Repository Overview

**charmbracelet/soft-serve** is a self-hostable Git server for the command line built in Go (~7k stars). It provides:
- SSH-accessible TUI for repository browsing
- Multi-protocol Git access (SSH, HTTP, Git daemon)
- Git LFS support with both HTTP and SSH backends
- Per-repository access control with SSH public key authentication
- Repository mirroring and webhook support

## Existing SugarCraft Mapping

The first-stage analysis established these mappings:

| Soft Serve Component | SugarCraft Library | Relevance |
|--------------------|--------------------|-----------|
| TUI/BubbleTea | `candy-bubbletea` | Would need Bubbletea port for TUI apps |
| SSH Server | `candy-pty`, `candy-ssh` | SSH handling via charmbracelet/ssh wrapper |
| Git operations | `sugar-git` | Git repository operations via go-git |
| LFS support | N/A | No SugarCraft equivalent |
| Lipgloss styling | `candy-lipgloss` | Terminal styling - perfect match |
| HTTP server | `sugar-http` | HTTP server using gorilla/mux - PSR-7 preferred |

## Previously Identified Gaps

From the first-stage analysis:
- No built-in web UI (only SSH TUI)
- No user registration system
- RSA key limitations due to Go's x/crypto/ssh
- No GitHub/Gitea migration tools
- LFS SSH backend not defaulted
- Limited documentation structure

---

## High-Signal Open Issues

### 1. Read-only, public-only web interface (Issue #756) — 8 👍 reactions
**Signal**: High community demand for web UI. A contributor (Amolith) has already built a prototype using Pico CSS and shared a screen recording. Maintainers requested "a slightly different implementation."

**Strategic Implication**: This is a major opportunity. SugarCraft could differentiate with a well-designed web UI, but the charmbracelet team's caution suggests this is complex territory.

### 2. SSH identity changes on restart when `ssh.key_path` is set (Issue #779)
**Signal**: Host key directory not created before key generation. Bug causes key to change on every restart, breaking known_hosts entries.

**Direct Risk to SugarCraft**: If `candy-ssh` wraps similar behavior, SSH key persistence issues would affect `candy-serve`.

### 3. Config could support anon_access and allow_keyless settings (Issue #758)
**Signal**: Request for scriptable/automated anonymous git server setup. Needed for local development of tools like Argo CD.

**Feature Opportunity**: SugarCraft could provide first-class support for authenticated-only vs anonymous access patterns.

### 4. Access tokens don't provide authentication (Issue #800)
**Signal**: JWT tokens not providing expected authentication in HTTP context.

**Direct Risk to SugarCraft**: Token/authentication handling is likely a shared pattern that would affect any HTTP git server implementation.

---

## Important Closed Issues

### 1. Authentication bypass vulnerability (v0.6.2) — CVE-2023-43809
**Severity**: Critical. Public key authentication bypass when `allow-keyless` is true. Allowed attackers to impersonate any user by offering the victim's public key during SSH handshake before authenticating with their own key.

**Root Cause**: User context stored during "offer" phase in `PublicKeyHandler` and not properly validated in `AuthenticationMiddleware`.

**Fix Pattern**: Added explicit verification that the key used to establish connection is the same key used for authentication. Middleware reordering (AuthenticationMiddleware before ContextMiddleware).

**Defensive Lesson**: Never trust user context set during auth offer phase; verify after auth completes.

### 2. LFS locks authorization bypass (v0.11.2)
**Severity**: High. Missing authorization check in LFS lock deletion allowed any user to delete other users' locks.

**Fix**: Added proper authorization checks before lock deletion operations.

### 3. SSRF via unvalidated LFS endpoint (GHSA-3fvx-xrxq-8jvv)
**Severity**: High. Authenticated SSH users could force server to make HTTP requests to internal/private IP addresses via `--lfs-endpoint` URL during repo import.

**Root Cause**: Webhook SSRF protection (added in v0.11.1) was not applied to LFS HTTP client. LFS import path used `http.DefaultClient` with no filtering.

**Key Finding**: "The cleanest fix would be to extract them to a shared internal package" — SSRF protection was not applied consistently across codebase.

### 4. Arbitrary file writing through SSH API (GHSA-33pr-m977-5w97)
**Severity**: High. SSH API allowed writing to arbitrary paths.

### 5. Critical auth bypass (v0.11.3)
**Description**: Authentication bypass that allows any malicious actor to gain access as any user.

---

## Recurring Pain Points

### 1. SSH Key Management Complexity
**Issues**: #432, #303, #497, #740, #750, #48, #389, #451, #63

**Pattern**: Users struggle with:
- SSH key ordering (ssh-agent tries keys sequentially)
- `IdentitiesOnly` flag requirement
- Key matching across multiple keys in agent
- Creating users with keys (`user create -k` had parsing bugs)
- `allow-keyless` interactions with public key auth

**Community Workaround**: Users must use `ssh -i key -o IdentitiesOnly=yes` to force specific key usage.

### 2. Private/Hidden Repository Visibility
**Issue**: #303 — Private repositories only visible when SSH key is in `config.yaml` under `initial_admin_keys`, even when the key was added to the admin user.

**Root Cause**: Visibility check used wrong key source.

### 3. Port Binding Silently Fails
**Issue**: #805 — Bind errors on privileged ports are silently swallowed; server appears started but SSH/HTTP never bind.

**Signal**: Production deployments fail silently when ports are blocked.

### 4. Mirror Sync Timeout Issues
**Issue**: #803 — Mirror sync killed by 1-minute default timeout, leaving repos in broken state.

**Issue**: #777 — Mirroring large repos (NixOS/nixpkgs) pegs CPU at 100% for extended time.

**Root Cause**: Default timeout too short; no progress indicators; no synchronization between cron jobs.

### 5. Orphaned Zombie Processes
**Issue**: #797 — Git processes become defunct (zombie) when running in Docker with mirrored repos.

**Signal**: Context cancellation doesn't properly clean up child git processes.

---

## Frequently Requested Features

### 1. Server Toggle Configuration (Implemented in v0.11.0)
**Request**: Config option to enable/disable git, http, and stats servers individually.

**Value**: Users don't need Prometheus/stats for development setups.

### 2. Raw File Content via HTTP (Issue #456)
**Request**: New endpoint `GET /{repo}/raw/{ref}/{filepath}` for raw file access.

**Status**: Implemented in v0.11.x.

### 3. S3 Storage for LFS (Issue #479)
**Request**: Configure S3 or S3-compatible storage for LFS objects.

**Status**: Open — users resort to FUSE mounting hacks.

**Strategic Opportunity**: SugarCraft could provide pluggable storage backends.

### 4. Push Mirrors
**Request**: Automatically mirror repos to remote URLs on push.

**Status**: Implemented in recent versions (push mirrors feature).

### 5. Web UI
**Request**: #756, #530 — Read-only, public-only web interface for viewing repos.

**Status**: Community prototype exists but not merged.

### 6. Global Hooks Post-Receive Not Working
**Issue**: Multiple users report global hooks not firing.

**Signal**: Hook implementation has edge cases.

---

## Important PRs

### 1. v2 UI Stack Upgrade (PR #678)
**Significance**: Major UI refactor upgrading to Bubble Tea v2, Lipgloss v2, Glamour v2, and Wish v2.

**Changes**: +397/-493 lines across 73 files.

### 2. Security Fix PRs (Multiple by dvrd)
**Pattern**: Community contributor dvrd has been systematically fixing security issues:
- Path traversal prevention
- SQL injection prevention
- Plaintext password storage fix
- User deletion race condition fix
- Connection pool limits
- JWT validation

**Intelligence**: These fixes show a systematic security audit was performed, revealing 50+ issues across rounds 55-59.

### 3. Mirror Sync Fixes (PRs #821, #21)
**Fix**: Disable gc.auto and remove timeout to prevent CPU peg on large repos.

### 4. LFS SSRF Security Fix (v0.11.4+)
**Fix**: Added LFS SSRF security checks; handle DNS resolution properly in SSRF protection.

### 5. Authentication Bypass Fix (v0.11.3)
**Critical**: Fixed authentication bypass allowing any user access.

---

## Architectural Changes

### Middleware Stack Evolution
**Original**: RecoveryMiddleware → BubbleTeaMiddleware → CommandMiddleware → LoggingMiddleware → AuthMiddleware → ContextMiddleware

**After auth bypass fix**: RecoveryMiddleware → BubbleTeaMiddleware → CommandMiddleware → **AuthenticationMiddleware** → LoggingMiddleware → **ContextMiddleware**

**Lesson**: Authentication must be verified AFTER all auth methods complete, not during offer phase.

### v2 Stack Migration
- Migrated from `github.com/charmbracelet/log` to `github.com/charmbracelet/log/v2`
- Upgraded to go-git v5.16.2
- Forked bubblezone with patches

---

## Performance Discussions

### Large Repository Mirroring
**Problem**: Mirroring NixOS/nixpkgs pegs CPU for hours.

**Solution Attempts**:
- Disable gc.auto
- Remove timeout limits
- Add job synchronization to prevent overlapping runs

**Root Cause**: `git rev-list --objects --all` is O(n) on object count and runs single-threaded.

### Connection Limits
**Problem**: DoS via connection exhaustion (issue #865).

**Solution**: Add `SetMaxOpenConns`, `SetMaxIdleConns`, `SetConnMaxLifetime` configuration.

### Large Push/Import Failures
**Issue**: #348 — Pushing/importing large repos fails after 90sec-5min with "Connection closed by remote host."

**Root Cause**: Proxy timeouts in deployment environments (fly.io) killing long-running connections.

---

## Extensibility Discussions

### Plugin System Requests
**Issue**: #49 — CI/CD features request suggested plugins like Terraform/Packer for job scheduling.

**Maintainer Response**: "Don't integrate any particular CI/CD system into soft-serve binary itself" — instead produce plugins.

**Lesson**: Soft Serve maintains simplicity by not baking in CI/CD.

### Gitea Integration (Issue #536)
**Request**: Use soft-serve as alternative interface to Gitea repos with same credentials.

**Alternative Workaround**: Mount Gitea's `/repos` folder (but loses push/pull and privacy controls).

### Issues System (Recent)
**Development**: New issue management system added (Apr 2026) with labels, milestones, HTTP REST API.

**Signal**: Project is expanding beyond pure git hosting.

---

## API/UX Complaints

### Config Reposity Confusion
**Issue**: #38 — `host` and `port` in config.yaml only affect clone URL display, not actual binding.

**User Frustration**: Expected config to control server binding; led to confusion.

**Lesson**: SugarCraft should ensure config options clearly describe their effect.

### TUI Color Scheme Customization
**Discussion**: Users want to customize TUI colors (Discussion #csoto-3).

**Current**: No theming support.

### Help Text Discoverability
**Pattern**: Users don't discover `?` for help in TUI.

### Repo Defaults
**Request**: Default settings like `private=true` and `branch=main` per repo.

---

## Migration Problems

### Docker Deployment Complexity
**Issue**: #512 — Users struggle with Docker + domain + reverse proxy setup for SSH.

**Root Cause**: Multi-protocol nature (SSH, HTTP, Git) requires different proxy configurations.

**Documentation Gap**: No clear guide for NPM (Nginx Proxy Manager) Layer 4 TCP routing.

### Initial Admin Key Setup
**Issue**: #432, #497 — After installing via apt, admin user not created even with `SOFT_SERVE_INITIAL_ADMIN_KEYS`.

**Pattern**: Environment variable not respected during apt package installation.

### SSH Key Algorithm Limitations
**Issue**: #48 — RSA SHA-2 keys not supported due to Go crypto/ssh limitations.

**Status**: Still not fully resolved; users must use Ed25519 or old SHA-1 RSA keys.

**Workaround**: Document key requirements clearly and provide key generation guidance.

---

## Clever Fixes & Workarounds

### SSH Key Ordering Fix
**Workaround**: Add initial admin key to ssh-agent before other keys:
```bash
eval $(ssh-agent -s)
ssh-add ~/.ssh/id_ed25519  # admin key first
ssh -p 23231 localhost user list
```

### Empty Repo README Spinner Fix
**Issue**: #522 — README tab shows spinner on empty repos.

**Fix Pattern**: Check `len(bs) == 0` before calling HEAD() to avoid error cascades.

### Mirror Job Synchronization
**Workaround**: Check if previously-started copy of same job is still running before starting new one.

---

## Community Workarounds

### Anonymous Access Setup
**Request**: Script-able anonymous git server for dev tooling.

**Current Workaround**: Custom Docker image with entry-point.sh handling anon_access and allow_keyless config.

### Argo CD Integration
**Use Case**: Local git repository for Argo CD to track.

**Workaround**: Stand up read-write repo accessible without auth for development.

### Multi-Key SSH Config
**Workaround**: Use `-o IdentitiesOnly=yes` and specific key files:
```bash
ssh -i ~/.ssh/admin_key -o IdentitiesOnly=yes git.server.com
```

---

## Maintainer Guidance Patterns

### Security Issues
1. **Immediate patch release** for critical issues (v0.11.3 for auth bypass)
2. **CVE coordination** with GitHub Advisory Database
3. **Detailed security advisories** explaining vulnerability and workaround

### Feature Rejections
1. **CI/CD integration**: "Don't integrate any particular CI/CD system into soft-serve binary itself"
2. **Gitea integration**: Suggest mounting repos folder as alternative
3. **Web UI**: Cautious acceptance, requested different implementation approach

### Feature Acceptances
1. **Webhooks**: Shipped in v0.5.0
2. **Mirror sync**: Shipped with cron scheduler
3. **LFS support**: Shipped in v0.5.0
4. **Server toggle config**: Shipped in v0.11.0

### Coding Standards
- Middleware order matters critically for security
- Context must be properly cleaned up on cancellation
- DNS rebinding requires IP pinning at dial time

---

## Rejected Ideas Worth Revisiting

### 1. Full Web UI
**Reason for caution**: Maintainers asked for "different implementation" than community prototype.

**Opportunity**: SugarCraft could pioneer a better approach.

### 2. Direct CI/CD Integration
**Maintainer position**: Keep binary simple, produce plugins instead.

### 3. Gitea Compatibility Layer
**Not pursued**: Too complex, better alternatives exist.

### 4. GitHub/Gitea Import Tools
**Never implemented**: Users must use `git clone && git push` manually.

**Strategic Gap**: Migration tooling is a real pain point.

---

## Problems Likely Relevant To SugarCraft

### 1. SSH Authentication Middleware Ordering
**Direct Risk**: If `candy-ssh` wraps similar patterns, auth bypass vulnerabilities could exist.

**Lesson**: Verify key fingerprint AFTER auth completes, not during offer.

### 2. SSRF Protection Consistency
**Direct Risk**: If `sugar-http` serves git with LFS, SSRF protection must apply consistently across all HTTP endpoints.

**Lesson**: Extract shared validation to common package, apply everywhere.

### 3. SQL Injection in Dynamic Queries
**Direct Risk**: Repository name parameters in SQL queries could be exploited.

**Lesson**: Parameterize all queries; audit with SQL injection focus.

### 4. Path Traversal in Repository Names
**Direct Risk**: Repository names with `../` could escape repos directory.

**Lesson**: `SanitizeRepo()` must reject any path sequences.

### 5. Mirror Job Synchronization
**Direct Risk**: If `candy-serve` implements mirrors, overlapping cron jobs could cause corruption.

**Lesson**: Implement job locking/mutex before starting sync.

### 6. Token/JWT Authentication
**Direct Risk**: Issues #800 show token auth has edge cases.

**Lesson**: Thoroughly test token expiration, issuer validation, audience checks.

### 7. LFS Pointer Scanning Performance
**Direct Risk**: `git rev-list --objects --all` on large repos is expensive.

**Lesson**: Consider pagination or streaming for large repo operations.

---

## Features SugarCraft Should Consider

### 1. Pluggable Storage Backends
**Signal**: Issue #479 requests S3 for LFS; no such support exists.

**Approach**: Define `StorageBackend` interface; ship local filesystem default; enable S3, GCS, Azure Blob extensions.

### 2. Better Migration Tools
**Signal**: No GitHub/Gitea import tools; manual clone/push workflow painful.

**Approach**: Build import commands that handle authentication, rate limiting, and LFS correctly.

### 3. First-Class Anonymous Access Configuration
**Signal**: Issue #758 requests scriptable anon access for dev tooling.

**Approach**: Support `SOFT_SERVE_ANON_ACCESS` and `SOFT_SERVE_DEFAULT_REPO` env vars for one-command dev setup.

### 4. Token-Based HTTP Auth
**Signal**: Issue #800 exposes token auth gaps.

**Approach**: Ensure HTTP token auth is consistently implemented; support JWT with proper validation.

### 5. Progress Indicators for Long Operations
**Signal**: Large repo push/import has no progress feedback; users think connection died.

**Approach**: Implement progress tracking for clone/push/mirror operations.

### 6. Mirror Job Coordination
**Signal**: Overlapping mirror syncs cause problems.

**Approach**: Implement job mutex/dispatcher pattern; prevent concurrent same-repo operations.

### 7. Connection Health Checks
**Signal**: Bind failures are silent (#805).

**Approach**: Explicitly verify server started, report bind errors, provide health endpoint.

---

## Architectural Lessons

### 1. Security Middleware Must Be Final
Authentication middleware should run AFTER context setup but verify credentials AFTER all auth methods complete. Context from auth "offer" phase is not trustworthy.

### 2. SSRF Protection Requires Dual Layer
- URL validation at input time
- Custom HTTP transport with IP validation at dial time
- DNS rebinding protection by pinning resolved IP

### 3. Job Systems Need Coordination
- Default timeout too short for large repos
- No mutual exclusion leads to overlapping runs
- Progress reporting prevents user frustration

### 4. Config Should Match User Expectations
`host` and `port` affecting display but not binding confused users. SugarCraft should name config fields clearly.

### 5. Database Migrations Need Care
SQLite for dev, PostgreSQL for production means migration testing must cover both.

---

## Defensive Design Lessons

### 1. Never Trust Unverified Auth Context
```go
// AFTER auth bypass fix:
// Verify the key used IS the key that authenticated
pk := s.PublicKey()
if pk != nil {
    fp := perms.Extensions["pubkey-fp"]
    if fp != gossh.FingerprintSHA256(pk) {
        // Key mismatch - attack detected
        wish.Fatalln(s, ErrPermissionDenied)
    }
}
```

### 2. Parameterize All Database Queries
Never use string concatenation with user input in SQL.

### 3. Sanitize All Repository Names
Reject `../` sequences, leading slashes, and non-printable characters.

### 4. Validate URLs Before HTTP Requests
Apply SSRF protection to all outbound HTTP, not just webhooks.

### 5. Handle Context Cancellation Properly
Ensure child processes are killed when context is cancelled; prevent zombie processes.

### 6. Implement Job Mutex Patterns
```go
// Before starting mirror sync:
// Check if job already running for this repo
// If running, skip or wait
// Use distributed lock if clustered
```

---

## Ecosystem Trends

### 1. Security Audits as Community Effort
Contributor dvrd performed systematic security review rounds (55-59), finding 50+ issues. Shows importance of external security researchers.

### 2. Incremental Feature Expansion
- v0.4.0: Complete UI overhaul, mouse support
- v0.5.0: Webhooks, LFS support
- v0.6.0: LFS endpoint flag
- v0.11.x: Issues system, push mirrors, security hardening

### 3. Bubble Tea v2 Migration
All charmbracelet projects migrating to v2 stack simultaneously.

### 4. Issues as First-Class Citizens
New issue system added (Apr 2026) suggests project expanding scope beyond pure git hosting.

### 5. Community Contributions Focus on DX
- Better help text
- Copy shortcuts
- Progress indicators
- Color customization requests

---

## Strategic Opportunities

### 1. Differentiate with Web UI
Soft Serve maintainers are cautious about web UI. SugarCraft could pioneer an excellent web-based repo browser.

### 2. Better Dev Container Story
Issue #758 shows demand for one-command local git server for dev tooling. SugarCraft could provide excellent PHP-focused equivalent.

### 3. Security First Architecture
Apply lessons from soft-serve's security issues. SugarCraft should:
- Perform security audit before 1.0
- Implement SSRF protection consistently
- Parameterize all queries
- Verify auth context after auth completes

### 4. Migration Tooling
Build what soft-serve won't: GitHub/Gitea import with LFS support.

### 5. Pluggable Storage
S3/LFS support would differentiate from soft-serve's filesystem-only approach.

---

## Cross-Ecosystem Pattern Matches

### GitHub/Gitea vs Soft Serve
| Feature | GitHub/Gitea | Soft Serve | SugarCraft Opportunity |
|---------|-------------|------------|----------------------|
| Web UI | Full | None | Pioneer |
| User Registration | Self-service | Admin-only | Consider |
| Migration Tools | Built-in | Manual | Build it |
| LFS Storage | Paid | Local only | S3 backend |
| Issues/PRs | Full | New | Issues only? |
| CI/CD | Actions | Hooks only | Maybe? |

### Security Pattern Parallels
- GitHub: SSRF in web hooks
- GitLab: Arbitrary file write via LFS
- Gitea: Auth bypass vulnerabilities
- Soft Serve: All of these (fixed)

---

## High ROI Recommendations

### Immediate (Before 1.0)

1. **Security Audit**
   - Parameterized queries everywhere
   - SSRF protection for all HTTP
   - Auth context verification
   - Path traversal prevention

2. **Configuration Clarity**
   - Document what each config affects
   - Separate display vs operational config

3. **Mirror Job Safety**
   - Implement job mutex
   - Increase default timeout
   - Add progress reporting

### Medium-term

4. **S3 Storage Backend**
   - High demand signal
   - Differentiates from soft-serve

5. **Migration Tools**
   - GitHub import with LFS
   - Gitea compatibility

6. **Web UI Foundation**
   - Build incrementally
   - Start read-only, expand later

### Long-term

7. **Plugin System**
   - CI/CD integration via plugins
   - Storage backend plugins

8. **Self-Service Registration**
   - Email verification
   - Password reset
   - Key management UI

---

## Conclusion

charmbracelet/soft-serve provides an excellent reference for building a command-line-first Git server. Its security journey (from multiple critical vulnerabilities to systematic hardening via community audit) demonstrates both the challenges of secure git hosting and the value of external security research.

SugarCraft's `candy-serve` port can learn from:
- Security middleware ordering lessons
- SSRF protection consistency
- Mirror job coordination
- SSH auth complexity

The ecosystem signals point to demand for:
- Better dev tooling integration
- Web UI (under-served by soft-serve)
- S3/LFS storage
- Migration tools

These represent clear differentiation opportunities for SugarCraft.
