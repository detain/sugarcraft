# CandyServe Research: Git Server TUI Tools

**Project:** candy-serve (PHP port of charmbracelet/soft-serve)
**Upstream:** https://github.com/charmbracelet/soft-serve
**Research Date:** 2026-05-13
**Status:** 🟢 v1 ready (public API + tests + docs + demo) per MATCHUPS.md

---

## Executive Summary

Candy-serve is a solid foundation port of soft-serve with SSH Git protocol handlers, access control, user management, and basic repo management. However, the **interactive SSH TUI** — the crown jewel of soft-serve that enables browsing repos/files/commits over SSH — is **not yet implemented**. This is the primary gap to close.

---

## 1. Tool Comparison Matrix

### 1.1 Git Server TUIs (Self-Hosted)

| Tool | Language | SSH TUI | HTTP Git | Git Daemon | LFS | Access Control | Markdown Rendering | Complexity |
|------|----------|---------|----------|------------|-----|----------------|-------------------|------------|
| **soft-serve** | Go | ✅ Full | ✅ | ✅ | ✅ | SSH keys + per-repo | ✅ BubbleTea | Medium |
| **candy-serve** | PHP | ❌ **Missing** | ❌ | ❌ | ✅ Basic | ✅ SSH keys | ❌ (CandyShine exists) | Low |
| **gitea** | Go | ✅ Web UI | ✅ | ❌ | ✅ | Web UI + SSH | ✅ | High |
| **gitolite** | Perl | ❌ CLI only | ❌ | ❌ | ❌ | SSH-based | ❌ | Medium |
| **cgit** | C | ❌ Web only | ❌ | ❌ | ❌ | None | ✅ | Low |
| **shrugs** | Erlang/Rust | ❌ | ✅ | ❌ | ❌ | SSH keys | ❌ | Medium |
| **mygit** | Rust | ❌ Web only | ✅ | ❌ | ❌ | None | ✅ | Low |
| **gitdir** | Go | ❌ | ✅ | ❌ | ❌ | Config file | ❌ | Low |

### 1.2 Git Client TUIs (For Reference)

| Tool | Language | Purpose | Staging | Rebasing | Commit Graph | Branch Mgmt |
|------|----------|---------|---------|----------|--------------|-------------|
| **lazygit** | Go | Local Git work | ✅ Line-level | ✅ Interactive | ✅ | ✅ |
| **gitui** | Rust | Local Git work | ✅ Hunk-level | ✅ | ✅ | ✅ |
| **gitu** | Rust | Local Git (Magit-style) | ✅ Hunk-level | ✅ | ✅ | ✅ |
| **tig** | C | Browse only | Minimal | ❌ | ✅ | Minimal |
| **git-town** | Go | Workflow automation | ❌ | ❌ | ❌ | ✅ |

---

## 2. Feature-by-Feature Analysis

### 2.1 Repository Browsing

**Upstream (soft-serve):**
- TUI shows list of repos with description, last activity
- `repo tree <name>` — print directory tree
- `repo blob <name> <path>` — print file contents with optional syntax highlighting (`-c -l`)
- `repo info <name>` — show branches, tags, collaborators
- `repo branch/delete <name> <branch>` — branch management over SSH

**Candy-serve current state:**
- `Repo::branches()` ✅ returns branch list via `git branch`
- `Repo::tags()` ✅ returns tag list via `git tag`
- `Repo::readFile()` ✅ reads file at commit+path via `git show`
- `Repo::readme()` ✅ tries common README names
- No TUI — only CLI commands in `bin/soft-serve`

**Gap:** Interactive TUI for browsing repos, tree view, file view with syntax highlighting

### 2.2 Access Control

**Upstream (soft-serve):**
```
Permission levels: NONE (0), READ (1), WRITE (2), ADMIN (3)
- Public repos: anyone can read
- Private repos: only collaborators + admins
- allowPush flag for unauthenticated push
- Admin flag for server-wide management
```

**Candy-serve current state:**
```php
// AccessControl.php — FULLY PORTED ✅
ACCESS_NONE = 0, ACCESS_READ = 1, ACCESS_WRITE = 2, ACCESS_ADMIN = 3
canRead(), canWrite(), canAdmin(), canCreateRepos(), allowAnonymousRead()
```

**Gap:** None — access control is complete

### 2.3 SSH Key Management

**Upstream (soft-serve):**
- Users stored in SQLite with public keys
- `user create <username>` — create user
- `user key <username> [key-file]` — add SSH public key
- `user list` — list users
- `user publickey <username>` — show user's public keys
- Admin can import keys from GitHub: `https://github.com/<username>.keys`
- Supports ssh-ed25519, ssh-rsa, ecdsa-sha2-*, sk-ssh-ed25519@openssh.com

**Candy-serve current state:**
```php
// User.php — FULLY PORTED ✅
// - username, isAdmin, isActive, password fields
// - authorizedKeys field (multi-line)
// - addAuthorizedKey(), verifyPublicKey(), authorizedKeysList()
// - generateKeyPair() using ssh-keygen
// - key validation regex for SSH key formats
```

**Gap:** None — SSH key management is complete

### 2.4 Markdown Rendering

**Upstream (soft-serve):**
- Uses BubbleTea's markdown rendering
- Renders README files in TUI with proper formatting
- Links are clickable (OSC 8 hyperlinks)

**Candy-serve current state:**
- CandyShine (markdown → ANSI) exists in monorepo: `candy-shine/`
- `Repo::readme()` returns raw content
- No integration with TUI (TUI doesn't exist)

**Gap:** Markdown rendering not integrated into any browsing UI

**Recommendation:** Integrate CandyShine for README rendering when TUI is built

### 2.5 Clipboard (OSC 52)

**Upstream (soft-serve):**
- Press `c` on highlighted repo to copy clone command
- OSC 52 sequences for clipboard over SSH
- Depends on terminal support

**Candy-serve current state:**
- Not implemented

**Gap:** OSC 52 clipboard copy support

**Implementation approach:** Use `CandyShine` or raw ANSI sequences to send OSC 52

---

## 3. Specific Improvements for Candy-Serve

### 3.1 HIGH PRIORITY — Interactive SSH TUI

This is the **core missing feature**. Without it, candy-serve is just a Git-over-SSH plumbing layer, not a true soft-serve port.

**What soft-serve's TUI does:**
1. **Repo List View** — shows all repos with public/private badge, description
2. **Repo Detail View** — branches, tags, collaborators, clone URL
3. **File Browser** — navigable tree view of repo contents
4. **File Viewer** — shows file content with syntax highlighting (uses `git blob`)
5. **Commit Log** — shows commit history with messages
6. **Help Panel** — keyboard shortcuts

**Architecture needed:**
```
SSH Connection → SSHServer → [TUI Session Manager] → BubbleTea-style TUI
                                    ↓
                            Repo/File/Commit Views
                                    ↓
                            ANSI Renderer (CandyShine)
```

**Dependencies:**
- `candy-core` (BubbleTea port) ✅ exists
- `candy-sprinkles` (styling) ✅ exists  
- `candy-shine` (markdown rendering) ✅ exists

**Implementation effort:** High (several thousand lines)

### 3.2 MEDIUM PRIORITY — HTTP Smart Protocol Server

**What it does:** Enables `git clone https://...` and `git push https://...`

**Current state:** Config exists (`httpListenAddr`), no implementation

**What needs implementing:**
- HTTP server (ReactPHP or PHP built-in)
- `/git-upload-pack` and `/git-receive-pack` endpoints
- Git wire protocol over HTTP
- LFS batch API endpoint already exists in `LFSHandler`

**Implementation effort:** Medium (1-2 weeks)

### 3.3 MEDIUM PRIORITY — Git Daemon (git://)

**What it does:** Enables anonymous `git clone git://...`

**Current state:** Config exists (`gitListenAddr`), no implementation

**What needs implementing:**
- TCP server on port 9418
- Git daemon protocol (dumb protocol)
- `git-daemon-export-ok` file support

**Implementation effort:** Medium

### 3.4 LOW PRIORITY — Real Daemon Mode

**Current state:** `bin/soft-serve serve` prints info but doesn't daemonize

**What needs implementing:**
- Proper process management (pcntl, signals)
- PID file management
- Log rotation
- Graceful shutdown

**Implementation effort:** Low (few hundred lines)

---

## 4. Prioritized Recommendations

### Phase 1: TUI Foundation (4-6 weeks)

| # | Feature | Effort | Impact | Notes |
|---|---------|--------|--------|-------|
| 1 | **SSH TUI Session Manager** | High | Critical | Route SSH to TUI instead of git commands |
| 2 | **Repo List View** | High | Critical | Main entry point of TUI |
| 3 | **File/Tree Viewer** | High | High | Navigate repo contents |
| 4 | **Syntax Highlighting** | Medium | High | Integrate CandyShine |
| 5 | **Commit Log View** | Medium | Medium | Browse commit history |

### Phase 2: Protocol Servers (2-3 weeks)

| # | Feature | Effort | Impact |
|---|---------|--------|--------|
| 6 | **HTTP Git Server** | Medium | High |
| 7 | **Git Daemon** | Medium | Medium |
| 8 | **LFS HTTP Endpoints** | Medium | Medium |

### Phase 3: Polish (1-2 weeks)

| # | Feature | Effort | Impact |
|---|---------|--------|--------|
| 9 | **OSC 52 Clipboard** | Low | Medium |
| 10 | **Markdown README in TUI** | Low | Medium |
| 11 | **Daemon Mode** | Low | Low |

---

## 5. Code Architecture Decisions

### 5.1 TUI Entry Point

The TUI should be a separate entry point callable via SSH forced command:

```php
// bin/soft-serve tui [repo-name]
// or via SSH: ssh user@host soft-serve
```

**Existing infrastructure to leverage:**
- `candy-core` — BubbleTea runtime port
- `candy-sprinkles` — styling system
- `candy-shine` — markdown/syntax rendering

### 5.2 SSH → TUI Routing

Current `SSHServer::handleConnection()` routes to `UploadPack`/`ReceivePack`. Need to add:

```php
// SSHServer.php addition
public function handleTuiConnection(string $username): int
{
    // Start BubbleTea session for this user
    // Route to TUI views based on navigation
}
```

### 5.3 Key Classes to Create

```
src/
  TUI/
    TUISession.php        — manages TUI state for a session
    RepoListView.php      — main repo listing (extends BubbleTea View)
    RepoDetailView.php    — single repo detail
    FileTreeView.php      — file browser
    FileView.php          — file content viewer
    CommitLogView.php     — commit history
    HelpPanel.php         — keyboard help
```

### 5.4 Similar Projects for Reference

**Rust Git Server TUIs:**
- [gitui-org/gitui](https://github.com/gitui-org/gitui) — 21k stars, Rust TUI Git client
- [altsem/gitu](https://github.com/altsem/gitu) — Magit-inspired, 2.7k stars

**Key insight from gitui:** Uses `ratatui` (Rust TUI library) and `git2-rs` (libgit2 bindings). For PHP, we'll use BubbleTea (candy-core) and shell out to `git` commands (already done in Repo.php).

---

## 6. Testing Strategy

**Current test coverage:** Basic PHPUnit tests exist

**For TUI additions:**
1. Snapshot tests for ANSI output
2. Key event simulation for navigation
3. Integration tests with SSH connection simulation

**Reference patterns from existing libs:**
- `sugar-bits/tests/` — snapshot testing for views
- `candy-core/tests/` — BubbleTea component tests

---

## 7. References

### Upstream
- **soft-serve:** https://github.com/charmbracelet/soft-serve
- **soft-serve TUI docs:** https://pkg.go.dev/github.com/charmbracelet/soft-serve

### Similar Tools
- [gitui-org/gitui](https://github.com/gitui-org/gitui) — Rust Git TUI (21k stars)
- [altsem/gitu](https://github.com/altsem/gitu) — Magit-style Rust TUI (2.7k stars)
- [jesseduffield/lazygit](https://github.com/jesseduffield/lazygit) — Go Git TUI (35k stars)
- [git-town](https://git-town.com) — Git workflow automation CLI

### Existing CandyServe Code
- `candy-serve/src/SSH/SSHServer.php` — SSH connection handling
- `candy-serve/src/Repo.php` — Repo model with git operations
- `candy-serve/src/User.php` — User model with SSH keys
- `candy-serve/src/AccessControl.php` — Permission model
- `candy-serve/src/Git/UploadPack.php` — Clone/fetch handler
- `candy-serve/src/Git/ReceivePack.php` — Push handler
- `candy-serve/src/LFS/LFSHandler.php` — LFS batch API

### Existing Monorepo Libraries to Leverage
- `candy-core/` — BubbleTea TUI runtime
- `candy-sprinkles/` — Lipgloss styling port
- `candy-shine/` — Markdown/syntax rendering

---

## 8. Conclusion

Candy-serve has a solid foundation with proper SSH Git protocol handling, access control, and user management. The **single largest missing piece is the interactive SSH TUI** that makes soft-serve unique — the ability to browse repos, view files, and navigate commits directly over SSH.

**Recommended next step:** Implement the TUI session manager and repo list view as the first phase of TUI development, leveraging the existing BubbleTea (candy-core) and styling (candy-sprinkles) infrastructure already in the monorepo.
