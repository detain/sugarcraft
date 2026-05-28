# Second-Stage Ecosystem Intelligence Report: charmbracelet/wish

## 1. Repository Overview

- **URL:** https://github.com/charmbracelet/wish
- **Stars:** 5,233 (as of 2026)
- **Language:** Go
- **License:** MIT
- **Module:** `charm.land/wish/v2`
- **Purpose:** SSH server framework for building TUIs over SSH, with middleware for Bubble Tea integration, Git servers, SCP/SFTP, and more
- **Key Dependency:** `github.com/charmbracelet/ssh` (the underlying SSH implementation)

**Architecture Summary:** Wish is a middleware-centric SSH application framework. The core provides server bootstrapping and session I/O helpers, while all substantive behavior lives in composable middleware packages. The `Middleware` type signature `func(next ssh.Handler) ssh.Handler` enables clean adapter/decorator pattern composition.

---

## 2. Existing SugarCraft Mapping

From `repo_map/charmbracelet_wish.md`:

| Wish Component | SugarCraft Equivalent | Status |
|---|---|---|
| `bubbletea.Middleware` (serve TUI over SSH) | `sugar-bits`, `sugar-prompt` | **Gap:** No SSH transport layer exists |
| `git.Middleware` (git server with hooks) | `candy-core` (process execution) | **Gap:** No git server equivalent |
| `logging.Middleware` (connection logging) | `(none)` | No SSH server library in SugarCraft |
| `ratelimiter.Middleware` (per-IP throttling) | `(none)` | No SSH-level rate limiting |
| `accesscontrol.Middleware` (command allowlist) | `(none)` | No SSH command restriction |
| `recover.Middleware` (panic recovery) | `candy-core` error handling | Partial - worth reviewing |
| `scp.Middleware` (file transfer) | `sugar-bits` file components | **Gap:** SCP is a specific protocol |
| `Cmd` / PTY execution helper | `candy-pty` (FFI/syscall PTY wrappers) | **Opportunity:** ssh-wrapped version |
| `WithAuthorizedKeys` (public key auth) | `SugarCraft\Core\Auth` | Different credential systems |

**Overall Assessment:** Wish = SSH transport + Bubble Tea integration + Git server + middleware toolkit. SugarCraft has no SSH transport equivalent. The closest SugarCraft analogue for TUI-over-transport is `sugar-bits`/`sugar-prompt`, but without an SSH transport layer these cannot be served remotely.

---

## 3. Previously Identified Gaps

From the first-stage analysis (`repo_map/charmbracelet_wish.md`):

1. **No SSH transport in SugarCraft** — The entire TUI-over-SSH concept cannot be replicated without an SSH server implementation
2. **No git server equivalent** — The `git` middleware provides complete git server functionality; SugarCraft has nothing comparable
3. **PTY handling is different** — `candy-pty` wraps terminal I/O, but Wish's `Cmd` wraps this with SSH session awareness
4. **No rate limiting at SSH level** — Wish's `ratelimiter` operates at the SSH connection layer
5. **No command allowlisting** — Wish's `accesscontrol` middleware restricts which shell commands can execute per session
6. **No authorized keys management** — Wish provides `WithAuthorizedKeys()` for OpenSSH-style key allowlists

---

## 4. High-Signal Open Issues

### Issue #506: PTY + Bubble Tea Alt Screen Problem (Oct 2025)
**Severity:** High — Core TUI functionality broken
**Problem:** When a Bubble Tea program under `bubbletea.Middleware` tries to launch an interactive child via `tea.Exec`, the TUI frame stays on screen and child output appears "stacked" below it instead of replacing the UI.
**Technical Root Cause:** `wish.Cmd`'s `SetStdin/SetStdout/SetStderr` methods were no-ops, causing Bubble Tea's alt screen escape sequences to be written to a different output path than the child process.
**Solution in Progress:** PR #522 adds proper stdio field storage and usage in `wish.Cmd`, respecting `SetStdin/SetStdout/SetStderr` set by Bubble Tea's exec flow. Also integrates `x/xpty` for ConPTY support on Windows.
**Direct Risk to SugarCraft:** If SugarCraft ever implements TUI-over-SSH, this same alt-screen-release pattern will be critical. The issue reveals that bridging TUI frameworks with subprocess execution requires careful I/O handle management.

### Issue #488: SSH Proxy/Router ("Nginx for SSH") (Aug 2025)
**Problem:** Request for a Wish-based service that forwards SSH sessions to other SSH servers based on logic beyond just port (e.g., path-based routing).
**Signal:** Users want to use Wish as a reverse proxy for SSH.
**Direct Risk to SugarCraft:** None directly, but the middleware composition pattern used in Wish could enable similar routing if SugarCraft ever adds SSH support.

### Issue #483: Namespace Conflict with Tcl/Tk Wish (Jul 2025)
**Problem:** There is a well-known shell called "wish" from Tcl/Tk, causing confusion.
**Reactions:** 👎 2, 😕 1
**Direct Risk to SugarCraft:** SugarCraft's `candy-wish` port name won't conflict, but the naming decision in PROJECT_NAMES.md should be reviewed.

### Issue #455: Mosh Support (Apr 2025)
**Reactions:** 👍 6
**Problem:** Request to support Mosh (alternative SSH that handles connection interruptions). Mosh uses SSH for auth but provides a different transport.
**Interesting Note:** Commenter suggests `tsshd` as a Go implementation that could be integrated, offering "mosh-like benefits for mobile clients."
**Direct Risk to SugarCraft:** None, but signals demand for resilient SSH connections.

### Issue #405: NoClientAuth Selection (Feb 2025)
**Problem:** Cannot use `NoClientAuth` for some users while using other auth methods for others simultaneously.
**Direct Risk to SugarCraft:** Fine-grained auth composition is a common requirement; SugarCraft's auth layer should support composable auth modes.

### Issue #325: Auth Before Rate Limiter (Aug 2024)
**Reactions:** 👍 1
**Problem:** Rate limiting happens AFTER authentication, defeating the purpose of rate limiting for brute-force protection. The auth handlers are called before middleware in Wish's server struct.
**Maintainer Response:** Acknowledged as a design issue; auth handlers live in a different struct field than middleware, so middleware ordering cannot affect auth timing.
**Direct Risk to SugarCraft:** **Critical pattern.** SugarCraft must ensure rate limiting/countermeasures execute BEFORE authentication to provide brute-force protection. This requires auth to be part of the middleware chain or use connection callbacks, not a pre-middleware handler.

### Issue #303: wish.Command() Output Goes to Server (Aug 2024)
**Problem:** When running bash scripts with `dialog`/`whiptail` via `wish.Command`, output displays on server console instead of client.
**Status:** Maintainer couldn't reproduce; suggests `whiptail` may be "doing something funky."
**Direct Risk to SugarCraft:** PTY handling differences between interactive and non-interactive contexts will affect any SugarCraft process execution.

### Issue #291: Capture Output for wish.Command (Jun 2024)
**Problem:** Cannot capture output from `wish.Command` when using `tea.Exec` — the `StdoutProxy` approach that works with regular `exec.Command` fails.
**Root Cause:** When running on a PTY, the slave IO overrides any custom stdout set via `SetStdout`.
**Maintainer Note:** "because it might run on a pty, in which case it'll be overridden by the slave IO anyway"
**Direct Risk to SugarCraft:** Any subprocess execution wrapper must handle PTY vs non-PTY contexts differently.

### Issue #232: PTY + Bubble Tea on Windows (Jan 2024)
**Assignee:** caarlos0
**Problem:** Keypresses seem ignored on Windows when using PTY with Bubble Tea.
**Suspected Cause:** Somewhere a `go io.Copy` needs to be made cancelable.
**Direct Risk to SugarCraft:** Windows PTY support is notoriously difficult; `candy-pty` FFI wrappers must handle this.

### Issue #207: SCP Windows Issues (Jan 2024)
**Problem:** SCP protocol issues on Windows including "protocol error: expected control record" on downloads and requiring `-O` flag with OpenSSH 9.0.
**Solution:** SFTP support was added to the SCP example via PR #224 (`WithSubsystem`), providing an alternative file transfer method.
**Direct Risk to SugarCraft:** File transfer over SSH requires understanding both SCP and SFTP protocol quirks.

### Issue #456: Padding Background Color Not Applied in Docker (May 2025)
**Problem:** Lip Gloss padding background color renders correctly locally but not in Docker containers.
**Additional Context:** Commenter confirms same issue with colors in Docker images.
**Root Cause:** Likely related to terminal capability detection in containers vs local terminals.
**Direct Risk to SugarCraft:** Containerized TUI rendering requires special handling of terminal capabilities.

---

## 5. Important Closed Issues

### Issue #440: Support tview (Mar 2025) — CLOSED AS DISCUSSION
**Request:** Support `tview` (another TUI framework) in addition to Bubble Tea, or decouple from Bubble Tea entirely using interfaces.
**Outcome:** Converted to discussion; no implementation commitment.
**Signal:** Users want framework-agnostic TUI serving, not just Bubble Tea integration.
**Direct Risk to SugarCraft:** SugarCraft's TUI approach uses its own component library philosophy; if ever adding transport layers, should consider framework-agnostic interfaces.

### Issue #236: huh? middleware? (Feb 2024) — CLOSED COMPLETED
**Request:** Simple way to serve a `huh` form via Wish without manually handling the tea.Model.
**Outcome:** Closed as completed via https://github.com/charmbracelet/huh/pull/216
**Direct Risk to SugarCraft:** Form library integration with TUI-over-SSH is a common pattern.

### Issue #205: Banner Instead of Comment (Jan 2024) — CLOSED COMPLETED
**Request:** MOTD-style banner at session start (not just comment at end).
**Outcome:** Implemented via PR #210 (`WithBanner`/`WithBannerHandler`).
**Direct Risk to SugarCraft:** Session start/end hooks are useful for any interactive service.

### Issue #82: Refresh authorized_keys (Nov 2022) — CLOSED COMPLETED
**Request:** Re-read authorized_keys files without server restart (like OpenSSH).
**Outcome:** Implemented via PR #88.
**Direct Risk to SugarCraft:** Hot-reloading of auth configuration is a production necessity.

### Issue #40: SCP Requires -O Flag with OpenSSH 9.0 (Apr 2022) — CLOSED COMPLETED
**Problem:** OpenSSH 9.0 changed default from SCP to SFTP protocol.
**Solution:** `WithSubsystem` added via PR #224 for SFTP support.
**Signal:** Protocol compatibility requires ongoing maintenance.

### Issue #350: Background Color Queries Printed (Oct 2024) — CLOSED FIXED
**Problem:** Background color queries (`^[]11;rgb:...^G`) printed to terminal instead of being queried.
**Root Cause:** Commit that added PTY query support put PTY slave in wrong mode.
**Direct Risk to SugarCraft:** PTY terminal mode configuration affects color handling.

### Issue #228: Bash Shell Issues in wish-exec (Jan 2024) — CLOSED FIXED
**Problems:**
1. Bash output going to server stdout instead of client
2. `Inappropriate ioctl for device` error when setting PTY manually

**Solution:** PR #229 introduced `wish.Command` and `wish.Cmd` type that properly handles PTY allocation and I/O routing.
**Signal:** Bubble Tea's `ExecProcess` concept needs special handling in SSH context.

### Issue #196: Bubble Tea ExecProcess Within Wish Session (Dec 2023) — CLOSED FIXED
**Problem:** `tea.ExecProcess` breaks when hosted via Wish — terminal becomes unresponsive after subprocess exits.
**Long Discussion:** Multiple iterations debugging PTY ownership conflicts.
**Root Cause:** Both Bubble Tea and Vim try to acquire terminal; exit from subprocess doesn't properly restore terminal state.
**Solution:** PR #197 ("use real pty") allocated a real PTY for sessions, enabling proper subprocess handling.
**Signal:** Terminal state restoration after subprocess execution is complex.

### Issue #96: undefined: wish.Session (Nov 2022) — CLOSED COMPLETED
**Problem:** Examples broke after refactor from `ssh.Session` to `wish.Session`.
**Lesson:** Type re-exports from the main package need proper versioning.
**Direct Risk to SugarCraft:** API consistency matters for developer experience.

### Issue #45: Color Issues Under Systemd (May 2022) — CLOSED FIXED
**Problem:** Colors not displayed when running via systemd service.
**Solution:** PR #197 fixed by using real PTY for color profile detection.
**Workaround Still Cited:** `Environment="CLICOLOR_FORCE=1"` in systemd service file.
**Direct Risk to SugarCraft:** Terminal capability detection in non-interactive contexts requires fallback profiles.

---

## 6. Recurring Pain Points

### PTY State Management
The most recurring theme across multiple issues (#506, #232, #228, #196, #291) is PTY state management. Specific problems:
- PTY allocation must happen before terminal queries
- PTY must be placed in raw mode before querying capabilities
- Subprocess execution requires careful I/O handle routing
- Terminal state must be properly restored after subprocess exit
- Windows ConPTY requires different handling than Unix PTY

### Auth Handler vs Middleware Ordering
Issue #325 reveals that auth handlers execute BEFORE middleware regardless of middleware ordering. This is a fundamental architectural limitation in how Wish handles the SSH server setup. **SugarCraft must design auth as middleware or connection callbacks to enable proper rate limiting before auth.**

### Color Profile Detection
Multiple issues (#350, #45, #456) involve color rendering differences across:
- Local vs Docker containers
- Systemd vs interactive execution
- Different terminal emulators
- PTY vs non-PTY contexts

### Bubble Tea + SSH Subprocess Integration
Issues #506, #291, #228, #196 all relate to the difficulty of running subprocesses (vim, bash, dialog) from within Bubble Tea programs hosted over SSH. The problems stem from:
- Alt screen management
- PTY ownership conflicts
- I/O handle routing (stdout/stderr going to wrong destination)

---

## 7. Frequently Requested Features

### High-Priority Feature Requests

1. **Mosh Support** (👍6) — Connection resilience for unstable networks
2. **SSH Proxy/Router** (Aug 2025) — "Nginx for SSH" forwarding based on logic
3. **Selective NoClientAuth** — Mix NoClientAuth with other auth methods per user
4. **tview Support** — Framework-agnostic TUI serving via interfaces
5. **SFTP Subsystem** — Already implemented via #224, but shows demand for complete file transfer

### Lower-Priority Feature Requests

6. **Banner at Session Start** — Implemented as `WithBanner`/`WithBannerHandler`
7. **Hot-Reloading authorized_keys** — Implemented
8. **Better Windows PTY Support** — Ongoing work, ConPTY integration via x/xpty

---

## 8. Important PRs

### PR #522: fix(cmd): respect SetStdin/SetStdout/SetStderr (Dec 2025) — OPEN
**Status:** Open, approved by aymanbagabas, awaiting merge
**Changes:**
- Added `stdin`, `stdout`, `stderr` fields to `Cmd` struct
- Implemented `SetStdin`, `SetStdout`, `SetStderr` to store values
- Updated `doRun()` to use custom I/O when set, falling back to PTY
- Integrated `x/xpty` for ConPTY support on Windows
- Added test `TestCommandSetStdio`

**Significance:** Fixes the fundamental issue where Bubble Tea exec couldn't properly release alt screen.

### PR #229: feat: add wish.Command and wish.Cmd (Jan 2024) — MERGED
**Changes:**
- Introduced `wish.Cmd` type wrapping `exec.Cmd` with SSH PTY awareness
- `wish.Command()` factory that creates `Cmd` with session PTY as stdio
- Automatic PTY fallback to direct session stdio when no PTY allocated
- Implements `tea.ExecCommand` interface for Bubble Tea integration

**Significance:** Solved the subprocess output routing problem that affected bash, vim, etc.

### PR #197: feat: use real pty (Dec 2023–Jan 2024) — MERGED
**Changes:**
- Allocated real PTY for sessions (not just a PtyWriter hack)
- Color profile detection from PTY
- Pass `lipgloss.Renderer` down to tea.App
- Fixed color issues under systemd

**Significance:** Major improvement enabling real interactive subprocess execution.

### PR #224: feat: WithSubsystem (Jan 2024) — MERGED
**Changes:**
- `WithSubsystem(key string, h ssh.SubsystemHandler)` option
- Enabled SFTP integration via `github.com/pkg/sftp`
- Updated SCP example to provide both SCP and SFTP

**Significance:** Solved OpenSSH 9.0 compatibility by adding SFTP as alternative.

### PR #210: feat: support server banners (Jan 2024) — MERGED
**Changes:**
- `WithBanner(banner string)` option
- `WithBannerHandler(h ssh.BannerHandler)` for dynamic banners
- Implemented in underlying `charmbracelet/ssh` via PR #10

**Significance:** Enables MOTD and ToS acceptance flows.

### PR #132: feat: allow to user a termenv renderer (Apr 2023) — SUPERSEDED
**Status:** Superseded by PR #197
**Purpose:** Allow querying user terminal for color profile instead of using server defaults.
**Significance:** Demonstrates the difficulty of color profile detection in SSH context.

---

## 9. Architectural Changes

### From v0.x to v1.x
- `ssh.Session` re-exported as `wish.Session` (breaking change for some users, issue #96)
- Middleware type signature remained stable: `func(next ssh.Handler) ssh.Handler`
- Auth handlers separated from middleware (causes #325 issue)

### Real PTY Allocation (PR #197)
- Changed from PtyWriter hack to real PTY allocation
- Enabled proper subprocess execution (vim, bash)
- Fixed color profile detection from client terminal
- Required coordinated changes in `charmbracelet/ssh`

### WithSubsystem Addition (PR #224)
- Added SSH subsystem handler option
- Enabled SFTP integration without changing core architecture
- Demonstrates extensibility via options pattern

### Banner Support (PR #210)
- Added server banner options
- Required changes in underlying `charmbracelet/ssh`
- Demonstrates need for coordinated upstream changes

---

## 10. Performance Discussions

### Issue #325: Rate Limiter LRU Cache
The `ratelimiter` uses an LRU cache mapping remote IP → `rate.Limiter` token bucket, bounded to `maxEntries`. **Problem:** Idle connections' limiters stay in memory indefinitely if `maxEntries` is large enough. No time-based eviction.

**Performance Implication:** For a long-running SSH server with many clients, memory could grow unbounded if many unique IPs connect then idle.

### PTY Allocation Overhead
Each SSH session with PTY allocates a pseudo-TTY. On Unix, this involves `openpty()` or similar. The overhead is minimal for typical use, but could be significant for thousands of concurrent sessions.

---

## 11. Extensibility Discussions

### Middleware Composition Pattern
Wish's middleware is composable via the adapter/decorator pattern:
```go
func Middleware(func(next ssh.Handler) ssh.Handler)
```
Middlewares are applied first-to-last; the last registered is outermost (first to execute). This is the "onion model."

**Extensibility Opportunities:**
- Chain any number of middleware
- Middleware can short-circuit, modify request, or pass through
- Middleware can wrap errors and transform responses

### Options Pattern
Server configuration uses functional options:
```go
wish.NewServer(
    wish.WithAddress(addr),
    wish.WithMiddleware(mw1, mw2),
    wish.WithPasswordAuth(handler),
    // ...
)
```

**Limitation:** Auth handlers are NOT middleware and execute before middleware regardless of ordering (#325).

### Subsystem Handlers
SSH subsystems (like SFTP) can be registered via `WithSubsystem`. This enables extending the server beyond just request handlers.

---

## 12. API/UX Complaints

### Issue #325: Auth Before Middleware
"Rate limiting should happen before auth, perhaps via `s.ConnCallback` instead of middleware."
**Complaint:** The API design doesn't make the execution order obvious. Auth is a different kind of handler, not middleware.

### Issue #483: Namespace Conflict
Naming conflict with Tcl/Tk's `wish` shell causes user confusion. Not an API issue but a discoverability problem.

### Issue #96: Breaking Type Changes
The `wish.Session` vs `ssh.Session` confusion after refactoring shows that re-exporting types from different packages can break user code when versions don't align.

---

## 13. Migration Problems

### Issue #45: Color Issues Under Systemd
Users running Wish via systemd services saw different (broken) color rendering. Root cause: systemd services don't have a real TTY, so color profile detection fails.

**Migration Path:** Users needed to either:
1. Force a color profile via `WithColorProfile()`
2. Set `CLICOLOR_FORCE=1` in service environment

### Issue #40: OpenSSH 9.0 SCP Protocol Change
OpenSSH changed default from SCP to SFTP protocol, breaking existing Wish SCP implementations.

**Migration Path:** Add SFTP support via `WithSubsystem` and `github.com/pkg/sftp`.

### Issue #350: PTY Query Regression
A commit adding PTY color queries broke rendering for some users. The fix required putting PTY slave in raw mode before querying.

**Migration Path:** No user migration needed; maintainer fixed in subsequent commit.

---

## 14. Clever Fixes & Workarounds

### Workaround: CLICOLOR_FORCE=1
For color issues under systemd, users discovered that setting `Environment="CLICOLOR_FORCE=1"` in the service file resolves the issue.

### Workaround: exec.Command with Session Stdio
Before `wish.Command` existed, users worked around PTY issues by directly setting `cmd.Stdout = sess` and `cmd.Stderr = sess` instead of using PTY slave.

### Workaround: Opening New PTY for Subprocess
For running vim within Bubble Tea over SSH, the suggested (but "hacky") approach was to open a new PTY and copy I/O between the Bubble Tea PTY and the subprocess PTY. This was later improved by PR #197's real PTY allocation.

### Systemd Service Color Fix
The `CLICOLOR_FORCE=1` workaround (still cited in 2025) suggests that terminal capability detection in non-interactive contexts remains imperfect.

---

## 15. Community Workarounds

### SSH Session as Stdout/Stderr
For capturing output, users set `cmd.Stdout = sess` instead of PTY slave. This works for non-interactive output but breaks interactive programs.

### Using SFTP Instead of SCP
When SCP protocol issues arose with Windows clients, users were pointed to SFTP as an alternative via `WithSubsystem` and `github.com/pkg/sftp`.

### Framework-Agnostic TUI Rendering
Request for `tview` support (#440) suggested decoupling TUI rendering from Bubble Tea via interfaces. This hasn't been implemented but remains a desired pattern.

---

## 16. Maintainer Guidance Patterns

### When Users Report Issues They Can't Reproduce
Maintainers (caarlos0) often say "I cannot repro" and ask for:
- `$TERM` and `$SHELL` values
- Terminal emulator and version
- Whether it works in Docker
- Whether it works with simpler alternatives (nano instead of vim)

### Dependency on charmbracelet/ssh
Many features require coordinated changes in `charmbracelet/ssh`:
- Banner support required https://github.com/charmbracelet/ssh/pull/10
- PTY improvements required https://github.com/charmbracelet/ssh/pull/8

### Encouraging Small, Focused PRs
Maintainers merge small, focused changes rather than large refactors. PR #229 (wish.Command) was merged quickly; large breaking changes like PR #197 took longer.

### Suggesting Upstream Fixes First
When issues are in underlying libraries (termenv, bubbletea), maintainers point users to file issues in those repos first.

---

## 17. Rejected Ideas Worth Revisiting

### Framework-Agnostic TUI Middleware
Issue #440 requested decoupling Wish from Bubble Tea via interfaces. This was closed as a discussion without implementation. **Worth Revisiting for SugarCraft:** If SugarCraft ever adds network/TUI serving, consider framework-agnostic rendering interfaces rather than tight Bubble Tea coupling.

### Mosh Support
Requested in #455. Maintainers didn't commit to implementation. Commenter suggested `tsshd` as potential integration point. **Worth Revisiting:** Mosh provides better connection resilience. SugarCraft could consider this for any future SSH implementation.

### SSH Proxy/Router
Requested in #488. This would make Wish act as an "Nginx for SSH." No commitment from maintainers. **Worth Revisiting:** Could be a valuable addition if SugarCraft ever adds SSH serving.

---

## 18. Problems Likely Relevant To SugarCraft

### 1. PTY State Management
SugarCraft's `candy-pty` will need careful handling of:
- Raw vs cooked terminal modes
- Window resize signaling
- Signal delivery to child processes
- Terminal capability queries (color profile, etc.)

### 2. Middleware Composition
The middleware decorator pattern is portable. SugarCraft could use this for:
- Logging middleware
- Rate limiting middleware
- Error recovery middleware
- Session management middleware

### 3. Auth Handler Ordering
**Critical for SugarCraft:** Auth must be middleware or connection callback to enable rate limiting before auth. This is a fundamental API design lesson from #325.

### 4. Color Profile Detection
SugarCraft TUI components that render colors must handle:
- Different terminal capabilities
- Non-interactive contexts (systemd, Docker)
- Force-fallback when detection fails

### 5. Subprocess I/O Routing
When running subprocesses from TUI programs, must handle:
- Interactive programs need PTY
- Output capture requires redirecting to correct destination
- PTY slave overrides custom stdout/stderr when set

### 6. Containerized Rendering
Docker/container environments may lack full terminal capabilities. SugarCraft should:
- Default to conservative color profile
- Allow forcing color profile via configuration
- Handle missing PTY gracefully

---

## 19. Features SugarCraft Should Consider

### High-Value Features for sugar-wish Port

1. **Middleware-based Auth Composition**
   - Implement auth handlers as middleware that can be composed
   - Enable rate limiting before auth to prevent brute-force
   - Support multiple auth methods (password, public key, etc.)

2. **PTY Management Abstraction**
   - Clean wrapper around terminal I/O
   - Handle raw/cooked mode transitions
   - Window resize event forwarding
   - Signal propagation to child processes

3. **Session Lifecycle Hooks**
   - On connect / on disconnect callbacks
   - Session start/end banners
   - Logging of connection metadata

4. **Rate Limiting with Time-Based Eviction**
   - LRU cache with both entry count AND time-based limits
   - Per-IP or per-user rate limiting
   - Configurable limits and burst sizes

5. **Terminal Capability Detection**
   - Query terminal for color support
   - Handle non-interactive contexts gracefully
   - Allow forced color profile override

### Medium-Value Features

6. **Authorized Keys Hot-Reloading**
   - Watch authorized_keys file for changes
   - Re-read on new auth attempts
   - Optionally use inotify/fsevents for immediate reload

7. **Banner/Message of the Day**
   - Display text at session start
   - Optional acceptance flow (press Y to accept ToS)
   - Dynamic banners via callback

8. **Subsystem Support**
   - SFTP via WithSubsystem
   - Could enable file transfer functionality

### Lower-Value but Interesting

9. **SSH Connection Proxying**
   - Forward sessions to other SSH servers
   - Path or user-based routing

10. **Connection Resilience**
    - Handle temporary disconnections
    - Auto-reconnect capability

---

## 20. Architectural Lessons

### Lesson: Auth vs Middleware Separation
Auth handlers being separate from middleware is a design flaw. They should be middleware or use connection callbacks to enable proper rate limiting. **SugarCraft must design auth as middleware.**

### Lesson: PTY is Complex
PTY handling involves:
- Terminal mode configuration (raw, cooked)
- Window size negotiation
- Signal routing (SIGWINCH, etc.)
- I/O redirection

Any TUI-over-transport implementation must carefully manage PTY lifecycle.

### Lesson: Bubble Tea + Subprocess = Hard Problem
Running interactive subprocesses (vim, bash) from Bubble Tea programs over SSH is fundamentally difficult because:
- Both want to own the terminal
- Alt screen management must coordinate
- PTY ownership must be transferred and restored

### Lesson: Terminal Detection in Containers
Systemd, Docker, and other non-interactive contexts break terminal capability detection. Always provide fallback profiles and configuration overrides.

### Lesson: Middleware Composition is Powerful
The `func(next ssh.Handler) ssh.Handler` pattern enables clean composition. Each middleware can:
- Transform input before passing to next
- Transform output after receiving from next
- Short-circuit and handle directly
- Log, measure, or restrict access

### Lesson: Options Pattern for Configuration
Functional options like `WithAddress()`, `WithMiddleware()` provide:
- Clear, composable configuration
- Sensible defaults (auto host-key generation)
- Optional behavior that doesn't pollute core API

---

## 21. Defensive Design Lessons

### Do: Design Auth as Middleware
Auth must be rate-limitable. In SSH context, this means using `ConnCallback` or making auth a middleware that executes first.

### Do: Handle Missing PTY Gracefully
Not all sessions have PTY allocated. Middleware like `activeterm` rejects these, but Bubble Tea integration should handle the non-PTY case.

### Do: Provide Fallback Color Profiles
When terminal detection fails (containers, systemd), default to a conservative profile and allow forced override via configuration.

### Do: Use Real PTY for Interactive Sessions
PtyWriter hacks don't work for interactive subprocesses. Real PTY allocation enables vim, bash, and other TTY programs to work correctly.

### Don't: Mix Auth and Middleware Execution Contexts
Auth handlers should be conceptually similar to middleware, not a separate execution context. This enables consistent ordering and composition.

### Don't: Assume Terminal Capabilities
Terminals vary widely. Always query capabilities and have fallbacks for missing features.

### Don't: Block on Subprocess Exit Without Cleanup
After subprocess exit, terminal state must be restored (exit raw mode, restore window size). Failure to do this causes the "terminal becomes unresponsive" issue seen in #196.

### Don't: Use Single PTY for Multiple Processes
When Bubble Tea and a subprocess both try to use the same PTY, conflicts arise. PR #197 solved this by allocating separate PTY for subprocess execution.

---

## 22. Ecosystem Trends

### 1. TUI-over-SSH Maturing
The pattern of serving TUIs over SSH (rather than just locally) is becoming more common. Wish is the canonical Go implementation.

### 2. Framework-Agnostic Rendering
Users want TUI serving to work with any TUI framework, not just Bubble Tea. This suggests interfaces over concrete implementations.

### 3. Connection Resilience
Mosh support request (#455) shows demand for SSH alternatives that handle unstable connections. This trend may grow.

### 4. Protocol Compatibility Challenges
OpenSSH 9.0's change from SCP to SFTP default (#40) shows that protocol compatibility requires ongoing maintenance. SFTP as a fallback is now standard.

### 5. Windows PTY Support
ConPTY support via `x/xpty` (PR #522) shows Windows PTY support is being taken seriously. This is increasingly important as Windows development grows.

### 6. Containerized TUI
Issue #456 shows TUIs running in Docker containers is a real use case. Container environments require special handling.

### 7. Security-First Auth
Rate limiting before auth (#325) reflects security-conscious users wanting protection against brute-force attacks.

---

## 23. Strategic Opportunities

### For SugarCraft's candy-wish Port

1. **Design Auth as Middleware from Day One**
   - Avoid the #325 issue by making auth composable middleware
   - Enable rate limiting before any authentication attempt
   - Support multiple auth methods that can be mixed

2. **Create Clean PTY Abstraction**
   - Wrap terminal I/O with proper raw/cooked mode management
   - Handle window resize signaling
   - Provide subprocess execution helpers

3. **Implement Proper Session Lifecycle**
   - On connect / on disconnect hooks
   - Connection metadata logging (IP, TERM, window size)
   - Graceful session termination

4. **Build Middleware Suite**
   - Rate limiting with LRU + time-based eviction
   - Panic recovery with stack trace logging
   - Request/response logging
   - Access control (command allowlist)

5. **Consider Framework-Agnostic Approach**
   - If TUI-over-transport is a goal, design interfaces that can work with any TUI framework
   - Don't hard-code Bubble Tea assumptions

6. **Plan for Containers**
   - Provide fallback color profiles
   - Handle missing PTY gracefully
   - Document systemd/Docker requirements

### Long-Term Opportunities

7. **SSH Proxy/Router Capability**
   - Forwarding SSH sessions based on logic
   - "Nginx for SSH" pattern

8. **SFTP Subsystem**
   - File transfer support via WithSubsystem
   - Integrates with existing file handling in sugar-bits

---

## 24. Cross-Ecosystem Pattern Matches

### Wish Middleware ↔ HTTP Middleware
The `func(next ssh.Handler) ssh.Handler` pattern mirrors Go HTTP middleware. This is a well-understood pattern in the Go ecosystem.

### PTY Handling ↔ Unix Terminal I/O
PTY management in Wish is similar to general Unix terminal programming. The patterns from `candy-pty` will be relevant.

### Bubble Tea Integration ↔ TUI Framework Integration
The challenges of running Bubble Tea programs over SSH (issues #506, #196, #291) will be relevant for any TUI framework ported to run over a transport layer.

### Auth Ordering ↔ Security Layering
The rate-limiting-before-auth issue (#325) is a universal security principle: apply countermeasures as early as possible in the request lifecycle.

### Container Terminal Issues ↔ Non-Interactive Contexts
Color/profile detection failures in Docker (#456, #45) are relevant for any terminal application running in non-standard environments.

---

## 25. High ROI Recommendations

### Immediate (High Impact, Low Effort)

1. **Document PTY Handling Requirements**
   - For any future TUI-over-transport in SugarCraft
   - PTY lifecycle, window resize, signal routing

2. **Add Auth as First-Class Middleware**
   - When designing any auth system for network services
   - Enable rate limiting before auth

3. **Review candy-pty for Container Compatibility**
   - Ensure fallback when PTY not available
   - Document systemd/Docker requirements

### Short-Term (High Impact, Higher Effort)

4. **Design Session Lifecycle Interface**
   - OnConnect, OnDisconnect callbacks
   - Connection metadata logging
   - Clean shutdown handling

5. **Implement Rate Limiting with Time Bounds**
   - LRU cache with max entries AND TTL
   - Prevent memory growth from idle connections

6. **Create Middleware Composition Patterns**
   - Reusable middleware for logging, recovery, rate limiting
   - Follow `func(next Handler) Handler` pattern

### Long-Term (High Impact, High Effort)

7. **Consider TUI Framework Abstraction**
   - If TUI-over-transport is a goal, use interfaces
   - Don't hard-code Bubble Tea assumptions

8. **Plan for SSH Transport Layer (Eventually)**
   - SSH would unlock TUI-over-network for SugarCraft
   - Wish middleware design is excellent reference
   - But auth-as-middleware is prerequisite

9. **Address Windows PTY Support**
   - ConPTY via FFI will be needed eventually
   - x/xpty library is the reference implementation

---

## Appendix: Key Issues Referenced

| Issue # | Title | Status | Key Takeaway |
|---------|-------|--------|--------------|
| #506 | PTY alt screen not released during exec | Open | I/O handle routing critical |
| #488 | SSH proxy/router | Open | Users want SSH forwarding |
| #455 | Mosh support | Open | Connection resilience demand |
| #405 | Selective NoClientAuth | Open | Auth composition needed |
| #325 | Auth before rate limiter | Open | **Critical:** Auth must be middleware |
| #303 | wish.Command output to server | Open | PTY routing issues |
| #291 | Capture output for wish.Command | Open | PTY overrides custom stdout |
| #232 | PTY + Bubble Tea on Windows | Open | io.Copy cancelability |
| #207 | SCP Windows issues | Open | Protocol compatibility |
| #456 | Padding color in Docker | Open | Container terminal issues |
| #440 | Support tview | Closed | Framework abstraction desired |
| #236 | huh middleware | Closed | Form serving pattern |
| #205 | Banner at start | Closed | Session start hooks |
| #82 | Refresh authorized_keys | Closed | Hot-reload auth |
| #40 | SCP with OpenSSH 9.0 | Closed | Protocol changes happen |
| #350 | Color queries printed | Closed | PTY mode matters |
| #228 | Bash shell issues | Closed | PTY allocation fixes |
| #196 | ExecProcess in wish | Closed | Terminal state restoration |
| #96 | undefined wish.Session | Closed | Type versioning matters |
| #45 | Colors under systemd | Closed | Container detection issues |

---

*Report compiled from analysis of GitHub issues, pull requests, and discussions for charmbracelet/wish. Data gathered via web fetching of GitHub. Report written to `repo_map/pr_charmbracelet_wish.md`.*
