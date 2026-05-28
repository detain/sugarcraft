# Overview

`candy-pty` is a mature, production-grade PHP port of `charmbracelet/x/xpty` providing pseudo-terminal (PTY) primitive operations for spawning and managing child processes with terminal I/O, window resize forwarding, and signal handling. It targets Linux and macOS via FFI into libc, demonstrating idiomatic PHP 8.3+ patterns (readonly properties, constructor promotion, fluent builders, RAII-style resource management). The library serves as a foundational primitive consumed by `candy-wish` (SSH transport), `candy-vcr` (session recording), and `candy-shell` (interactive shell sessions).

**Biggest opportunity areas:** Windows ConPTY backend (v2), pecl-sidecar backend for FFI-free deployments, pecl-uv async integration, comprehensive BSD/Solaris testing.

**Biggest missing capabilities:** No Windows ConPTY support, no pecl extension fallback, no async/await concurrency model (Go-style goroutines), no worker-thread support, no foreground job control beyond basic SIGINT.

---

# Internal Capability Summary

## Current Architecture

The library is organized as a **contract interface layer** (`src/Contract/`) providing pure PHP signatures implemented by a **POSIX implementation layer** (`src/Posix/`) using FFI syscalls, with supporting infrastructure in `src/`.

```
Contract Interfaces (7):
  PtySystem      → factory for opening PTY pairs
  PtyPair        → master() + slave() handle container
  MasterPty      → read/write/resize/size/stream/close/isClosed
  SlavePty       → path() + spawn() with controlling-terminal flag
  Child          → pid/exited()/wait()/exitCode()/kill() for PTY children
  Process        → non-PTY process with stdoutBytes/stderrBytes
  Pump           → byte pump contract with run(master, stdin, stdout, child?)

POSIX Implementations:
  PosixPtySystem  → posix_openpt + grantpt/unlockpt/ptsname_r quartet OR openpty (Darwin-first)
  PosixPtyPair     → simple master fd + slave path holder
  PosixMasterPty    → master fd + php://fd/N stream wrapper + TIOCSWINSZ resize
  PosixSlavePty    → proc_open wrapper with optional controlling-terminal shim
  PosixChild       → waitpid FFI fast-path + proc_get_status fallback lifecycle
  PosixProcess     → non-PTY child with captured stdout/stderr pipes
  PosixPump        → stream_select byte pump + EOF grace + keepalive + SIGWINCH
  PosixTermios     → FFI tcgetattr/tcsetattr/cfmakeraw (opaque 80-byte buffer)
  SttyTermios      → stty shell-out fallback when FFI unavailable
  MultiPump        → N:1 stream_select multiplexer for split-pane viewers

Supporting Infrastructure:
  Libc                  → lazy FFI singleton for libc + libutil (openpty on Linux)
  TermiosFactory         → PosixTermios → SttyTermios fallback factory
  SizeIoctl             → platform-aware TIOCSWINSZ/TIOCGWINSZ + Darwin stty fallback
  SignalForwarder        → SIGWINCH/SIGCHLD handlers (async/sync modes)
  PtySystemFactory      → DI-friendly backend resolution + SUGARCRAFT_PTY_BACKEND env
  PtyPool               → bounded-concurrency PTY pair wrapper
  ControllingTerminal    → setsid() + ioctl(TIOCSCTTY, 0) claim sequence
  Expect                → pexpect-style fluent driver (expect/sendLine/sendPattern)
  PumpOptions           → immutable pump config value object + with*() builders
  Lang                  → i18n wrapper extending SugarCraft\Core\I18n\Lang
```

## Current Features

| Feature | Status | Implementation |
|---------|--------|----------------|
| Open PTY pair (Linux) | ✅ | `posix_openpt` + quartet via FFI |
| Open PTY pair (macOS) | ✅ | `openpty` first, falls back to quartet |
| Master read/write | ✅ | `php://fd/N` stream wrapper + `MasterPty::read/write` |
| Window resize (TIOCSWINSZ) | ✅ | `ioctl` with Darwin arm64 stty fallback |
| Termios raw mode | ✅ | FFI `cfmakeraw` + `tcsetattr` with stty fallback |
| Child spawn on slave | ✅ | `proc_open` with optional `bin/pty-shim.php` shim |
| Controlling terminal (TIOCSCTTY) | ✅ | opt-in shim via `controllingTerminal: true` |
| SIGWINCH forwarding | ✅ | `SignalForwarder::attachSigwinch()` |
| Exit code retrieval | ✅ | `waitpid` FFI fast-path + `proc_get_status` fallback |
| Signal injection (SIGINT/TERM/KILL) | ✅ | `posix_kill` via FFI |
| EOF / VEOF handling | ✅ | PosixPump writes VEOF on stdin EOF + grace window |
| Non-blocking I/O | ✅ | `setBlocking(false)` + `stream_select` |
| Byte pump abstraction | ✅ | `PosixPump::run()` with EOF grace + keepalive |
| Multi-pump (N:1 select) | ✅ | `MultiPump` demuxes multiple PTY sessions |
| Expect/pexpect driver | ✅ | `Expect` fluent API for pattern matching |
| Recorder tap (candy-vcr) | ✅ | null-guarded `Recorder` tap in pump loop |
| Async signal handling | ✅ | `pcntl_async_signals(true)` lazy initialization |
| Process (non-PTY) | ✅ | `PosixProcess` with captured stdout/stderr |
| PTY pool (bounded concurrency) | ✅ | `PtyPool` hard cap on in-flight pairs |

## Rendering Systems

Not applicable — `candy-pty` is a terminal I/O primitive library, not a rendering library. The PTY pair is a byte pipe; rendering is handled by consuming libraries (e.g., `candy-vt` for virtual terminal emulation).

## Extension Systems

- **Backend selection:** `SUGARCRAFT_PTY_BACKEND` env var (`posix-ffi` / `sidecar` / `pecl`)
- **Termios backend:** `SUGARCRAFT_TERMIOS` env var (`posix-ffi` / `stty`)
- **Libc override:** `SUGARCRAFT_LIBC` env var for musl/Alpine/custom sysroots
- **Recorder tap:** Optional `SugarCraft\Core\Recorder` injection via `PumpOptions`
- **Signal handler modes:** `async: true/false` controls `pcntl_async_signals()` lazy init

## Strengths

1. **Clean contract-driven architecture** — seven well-defined interfaces make the implementation swappable and testable
2. **Mature FFI patterns** — lazy loading, caching, fallback, platform branching, opaque struct handling all correctly implemented
3. **Sub-millisecond exit detection** — `waitpid` FFI fast-path avoids `proc_get_status` polling overhead
4. **Comprehensive signal handling** — async/sync modes, no-throw callbacks, self-delivery test patterns
5. **Immutable + fluent configuration** — `PumpOptions` and `Termios` are safe for concurrent use
6. **Polished edge cases** — macOS anchor fd, Darwin arm64 stty fallback, stdin EOF grace window, VEOF write
7. **Recorder integration** — null-guarded tap enables `candy-vcr` without pump overhead
8. **Well-documented gotchas** — `CALIBER_LEARNINGS.md` captures every hard-won lesson
9. **DI-friendly** — `PtySystemFactory` enables test doubles without touching libc
10. **Per-feature cdef symbol addition** — FFI symbols added incrementally per PR for bisectability

## Weaknesses

1. **No Windows ConPTY** — `UnsupportedPlatformException` thrown on non-POSIX; reserved for v2 sidecar
2. **FFI required for core operations** — no pure-PHP fallback for PTY pair opening (only termios has stty fallback)
3. **Controlling terminal shim cost** — ~5–50ms PHP boot overhead makes it opt-in only
4. **BSD/Solaris treated as POSIX** — may have subtle differences not yet surfaced
5. **Single stream_select loop** — `MultiPump` multiplexes master→stdout only; stdin→master is caller's responsibility
6. **No foreground job control** — only basic SIGINT/SIGTERM/SIGKILL injection; job control signals not exposed
7. **No async concurrency model** — Go-style goroutines or PHP fibers not utilized; single-threaded pump loop
8. **No pecl extension fallback** — pecl/uv or pecl/phpterm for FFI-free deployment not yet implemented

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|---------------------------|----------|
| `charmbracelet/x/xpty` | 🔴 Direct upstream | Go native PTY with ConPTY on Windows, goroutine-based async | Critical |
| `creack/pty` (Go) | 🟡 Core PTY | Production-grade Go PTY used in many projects; ConPTY support | High |
| `portable-pty` (Rust) | 🟡 Core PTY | Rust PTY with ConPTY, clean trait-based architecture | High |
| `WhispPHP/whisp` | 🟡 Same ecosystem | PHP FFI-based PTY, multi-backend fallback patterns, SSH server | High |
| `charmbracelet/wish` | 🟡 Consumer | SSH server middleware with PTY wiring; bubbletea integration | Medium |
| `charmbracelet/bubbletea` | 🟢 Indirect | TUI framework using x/vt + x/vcr; Elm architecture | Medium |
| `charmbracelet/vhs` | 🟢 Consumer | Terminal recorder using creack/pty for live recording mode | Low |
| `charmbracelet/soft-serve` | 🟢 Consumer | Git server over SSH (uses wish + bubbletea); demonstrates real-world PTY use | Low |
| `php-tui/php-tui` | 🟢 Related | PHP TUI framework; RAII patterns for terminal state, extension points | Medium |
| `node-pty` (Node.js) | 🟡 Cross-ecosystem | Node.js PTY with ConPTY support; worker thread model | Medium |

---

# Feature Gap Analysis

## Critical Priority

### 1. Windows ConPTY Backend

**Title:** Windows ConPTY support for cross-platform PTY parity

**Description:** The upstream `charmbracelet/x/xpty` has native ConPTY support on Windows 10 1809+. `candy-pty` throws `UnsupportedPlatformException` on non-POSIX platforms, blocking `candy-wish` SSH server and other PTY-consuming libraries from running on Windows.

**Why it matters:** Without ConPTY, no interactive terminal application in the SugarCraft ecosystem can run on Windows. This is a significant gap for developer adoption.

**Source repo:** `charmbracelet/x/xpty` (Go, ConPTY in `pkg/conpty/`)

**Source discussion:** MATCHUPS.md line 82-85 documents the TODO; `plans/x-windows.md` tracks the design.

**Implementation ideas:**
1. Create `WindowsConPTYSystem implements PtySystem` with FFI to `kernel32.dll`
2. Use `CreatePseudoConsole()` / `ClosePseudoConsole()` API
3. Require `PROC_THREAD_ATTRIBUTE_PSEUDOCONSOLE` in `STARTUPINFOEX`
4. `WindowsConPTYPair` / `WindowsMasterPty` / `WindowsChild` implementing existing contracts
5. Windows FFI calling convention (stdcall vs cdecl) differs from POSIX — needs separate `LibcWin32` singleton

**Estimated complexity:** High — requires deep Win32 FFI knowledge, process spawning differences, ConPTY event loop integration with ReactPHP

**Expected impact:** High — enables full SugarCraft TUI ecosystem on Windows

### 2. Process Resource State Validation

**Title:** Validate process resources before I/O operations to prevent crashes on zombie/zombified states

**Description:** WhispPHP/whisp has multiple commits around process cleanup — validating resources before use, handling zombie states. `candy-pty`'s `PosixChild` and `PosixProcess` call I/O operations without checking if the underlying process resource is still valid.

**Why it matters:** PHP resources can become invalid (zombified processes) without immediate notification. A `write()` to a master fd whose child has already been reaped can cause undefined behavior or SIGPIPE.

**Source repo:** `WhispPHP/whisp` (PHP, Issue #18 "Check process is still a resource before attempting to close")

**Source PR/discussion:** Commit history shows "Check process is still a resource before attempting to close" pattern

**Implementation ideas:**
1. Add `isValid(): bool` method to `Child` contract
2. Implement `PosixChild::isValid()` using `posix_kill($pid, 0)` — returns false + ESRCH when pid no longer exists
3. Guard all `MasterPty::write()` / `read()` calls with validity check
4. Consider `ProcessGoneException` thrown on invalid operations

**Estimated complexity:** Low — single method addition + guards

**Expected impact:** Medium — prevents crashes in edge cases with fast-exiting children

## High Priority

### 3. PECL Extension Backend (FFI-free fallback)

**Title:** pecl-phpterm or pecl-uv backend for environments where FFI is unavailable

**Description:** Some hosting environments disable FFI (`ffi.enable=0`), preventing `candy-pty` from loading. A PECL extension providing the same PTY interface would enable use in restricted environments.

**Why it matters:** Shared hosting, hardened containers, and some PHP-as-a-Service platforms disable FFI for security reasons. Without a fallback, `candy-pty` is unusable in these environments.

**Source repo:** `charmbracelet/x/xpty` (upstream has sidecar architecture hint)

**Source discussion:** MATCHUPS.md documents pecl sidecar deferred to phase 12

**Implementation ideas:**
1. Define `PtySystemSidecar extends PtySystem` throwing `forDeferredBackend('sidecar')` for v1
2. Research existing PECL PTY extensions (phpterm, uv)
3. Design FFI-free API surface that mirrors `PosixPtySystem`
4. Consider `sugarcraft/pecl-pty` separate package vs. in-tree pecl backend

**Estimated complexity:** High — requires C extension development, API design, release workflow

**Expected impact:** Medium — enables use in FFI-disabled environments

### 4. Multi-Backend Crypto/Termios Detection

**Title:** Implement proper multi-backend detection following Whisp's AES-GCM pattern

**Description:** `WhispPHP/whisp` PR #3 demonstrates multi-backend fallback: check sodium OR openssl for AES-256-GCM. `candy-pty`'s FFI layer similarly needs multi-backend detection for termios operations (FFI vs. stty fallback is already implemented, but the fallback decision could be smarter).

**Why it matters:** Cross-platform crypto/terminal detection must check multiple backends. On some systems, multiple backends are available and the best choice may differ.

**Source repo:** `WhispPHP/whisp` (PHP SSH server, PR #3 multi-backend crypto)

**Source PR:** imakeinternet's AES-256-GCM OpenSSL fallback PR

**Implementation ideas:**
1. Extend `TermiosFactory` to detect and rank backends: FFI > stty > expect-style
2. Add `TermiosFactory::detect()` method that probes FFI availability first
3. Consider platform-specific default ranking (Darwin FFI works for tcgetattr but not always tcsetwinsize)
4. Add instrumentation/logging when falling back to help diagnose issues

**Estimated complexity:** Low — refinement of existing fallback logic

**Expected impact:** Medium — better diagnostics and potentially smarter backend selection

### 5. Async Concurrency Model Integration

**Title:** Deeper ReactPHP event loop integration for concurrent PTY sessions

**Description:** Bubble Tea (Go) uses goroutines for concurrent command execution. PHP uses ReactPHP's event loop. `candy-pty` has `attachSigwinchToFd()` for raw fd signal handling, but deeper async integration would enable more efficient concurrent session management.

**Why it matters:** Current `MultiPump` handles N:1 multiplexing from a single `stream_select`, but concurrent PTY sessions (e.g., parallel test harnesses) need better async primitives.

**Source repo:** `charmbracelet/bubbletea` (Go, goroutine-based concurrency)

**Source discussion:** `charmbracelet/wish` bubbletea middleware spawns goroutine per session for window resize forwarding

**Implementation ideas:**
1. Add `PtySystem::openAsync()` returning a `React\Promise\PromiseInterface`
2. Implement `React\EventLoop\LoopAccessInterface` on `PosixPump` for native loop integration
3. Add `PosixPump::runAsync()` returning promise that resolves on child exit
4. Consider `Fiber`-based concurrency for PHP 8.1+ Fibers

**Estimated complexity:** Medium — requires ReactPHP promise design + Fiber integration

**Expected impact:** Medium — better concurrent session handling

## Medium Priority

### 6. RAII Terminal State Restoration

**Title:** Add destructor-based cleanup for raw TTY mode changes

**Description:** `php-tui/php-tui` Issue #233 documents `stream_set_blocking(STDIN, false)` left unrestored after php-tui invocation — parent process's STDIN remains in non-blocking state. `candy-pty` has similar risks with `setBlocking()` and termios changes.

**Why it matters:** Raw TTY mode changes must be explicitly restored via RAII/destructor pattern. Without it, parent processes can be left in corrupted terminal state.

**Source repo:** `php-tui/php-tui` (PHP TUI, Issue #233 STDIN restoration)

**Source issue:** "stream_set_blocking(STDIN, false) left unrestored"

**Implementation ideas:**
1. Create `PosixMasterPty` destructor that calls `setBlocking(true)` if blocking was changed
2. Add `MasterPtyState` snapshot object capturing original blocking mode and termios
3. `MasterPty::withRawMode()` / `restore()` for explicit state save/restore
4. Document RAII semantics in docblock

**Estimated complexity:** Low — single destructor addition + state capture

**Expected impact:** Medium — prevents terminal corruption in parent processes

### 7. Connection ID / Session Logging

**Title:** Prefix all logs with connection identifier for multi-session debugging

**Description:** `WhispPHP/whisp` logs "prepend all connection logs with connectionId" — all PTY sessions should have identifiable IDs for debugging concurrent connections.

**Why it matters:** Multi-pump scenarios (split-pane viewers, parallel test harnesses) generate interleaved logs. Without session IDs, debugging is difficult.

**Source repo:** `WhispPHP/whisp` (PHP SSH server, commit "prepend all connection logs with connectionId")

**Source PR/discussion:** Whisp commit history

**Implementation ideas:**
1. Add `SessionId` property to `PosixMasterPty` / `PosixChild`
2. Auto-generate UUID or incrementing counter in `PosixPtySystem::open()`
3. Add `withSessionId(string $id)` for explicit IDs
4. `SignalForwarder` callbacks could receive session ID for logging

**Estimated complexity:** Low — property addition + propagation

**Expected impact:** Low-Medium — easier debugging of concurrent sessions

### 8. Inactivity Timeout with Proper Time Arithmetic

**Title:** Add configurable inactivity timeout with correct time unit handling

**Description:** `WhispPHP/whisp` had a bug where "60000 milliseconds was interpreted as 60000 minutes" — time arithmetic issues. `candy-pty` has `stdinEofGraceSec` but no general inactivity timeout.

**Why it matters:** SSH sessions and long-running processes need inactivity timeouts to prevent resource exhaustion from abandoned connections.

**Source repo:** `WhispPHP/whisp` (PHP SSH server, "Fix inactive disconnect not working on exact multiples of 60")

**Source issue:** Commit history "Fix inactive disconnect using months instead of minutes"

**Implementation ideas:**
1. Add `withInactivityTimeout(float $seconds)` to `PumpOptions`
2. Track last I/O timestamp; reset on any read/write
3. On idle tick, check elapsed since last I/O; if > timeout, call `onTimeout()`
4. Use `hrtime(true)` for sub-millisecond precision

**Estimated complexity:** Low — callback + tracking logic

**Expected impact:** Medium — useful for SSH session management

## Low Priority

### 9. Self-Rendering Widgets / PTY View Component

**Title:** Allow PTY sessions to be embedded as a view component in larger TUI

**Description:** Bubble Tea's `View` struct provides rich terminal features. `candy-pty` could provide a `PtyView` component that wraps a PTY session as a renderable element with its own resize handling and output buffering.

**Why it matters:** Composable TUI applications need PTY sessions as components, not just standalone processes.

**Source repo:** `charmbracelet/bubbletea` (Go, `View` struct with component composition)

**Source discussion:** SugarCraft mapping to `candy-core` / `sugar-bits`

**Implementation ideas:**
1. Create `PtyView extends View` with embedded `PosixPtyPair`
2. `PtyView::render(Buffer $buffer, Area $area)` piping PTY output into buffer cells
3. `PtyView::handleResize()` chaining `SignalForwarder`
4. Consider `candy-vt` integration for escape sequence interpretation

**Estimated complexity:** High — depends on `candy-vt` and `candy-core` rendering APIs

**Expected impact:** Low — niche use case until full TUI framework exists

### 10. Foreground Job Control Signals

**Title:** Expose SIGTSTP (Ctrl+Z), SIGCONT, and job control signals

**Description:** The upstream Go PTY libraries expose full job control (SIGSTOP, SIGTSTP, SIGCONT). `candy-pty` only exposes basic SIGINT/SIGTERM/SIGKILL.

**Why it matters:** Interactive shells and editors need full job control for suspend/resume functionality.

**Source repo:** `charmbracelet/x/xpty` (Go, signal.Notify for all signals)

**Source discussion:** `charmbracelet_bubbletea.md` "Signal Handling: Graceful SIGINT/SIGTERM handling"

**Implementation ideas:**
1. Add constants for `SIGTSTP`, `SIGCONT`, `SIGSTOP` to `Child` contract
2. `Child::kill(SIGTSTP)` suspends the process
3. `Child::resume()` sends SIGCONT
4. `PosixChild::signal(int $signo)` generic method

**Estimated complexity:** Low — already supported via `posix_kill`, just need to expose

**Expected impact:** Low — used by interactive applications but not core PTY primitive

---

# Algorithm / Performance Opportunities

## Current Approach vs External Approach

### Waitpid Fast Path (Already Optimal)

**Current:** `ChildPollTrait::tryWaitpid()` uses FFI `waitpid(pid, &status, WNOHANG)` as sub-ms exit-detection fast path before falling back to `proc_get_status()` polling.

**External (Go):** `charmbracelet/x/xpty` uses native `cmd.Wait()` which internally uses `waitmsg` syscall — Go runtime handles this efficiently.

**Why external is better:** Go's runtime manages process lifecycle natively; no polling needed. PHP's proc_get_status polling is a fallback for when FFI waitpid returns 0 (child still running).

**Tradeoffs:** The FFI waitpid fast path achieves near-Go-level exit detection. No change needed.

### Pump Loop Efficiency (Already Good)

**Current:** `PosixPump` uses `stream_select()` with configurable timeout (default 50ms). On idle tick, fires `onIdle` callback and checks for SIGWINCH-triggered resize. No busy-waiting; CPU near zero on idle.

**External (node-pty):** Uses libuv worker thread for async I/O — callbacks fire immediately on data, no polling interval.

**Why external is better:** libuv's edge-triggered approach has lower latency for high-throughput scenarios.

**Tradeoffs:** PHP's `stream_select` polling interval is a reasonable trade-off for simplicity. Lower interval = more CPU; higher interval = more latency. The 50ms default is a reasonable middle ground.

### Non-Blocking Pipe Captures (Already Implemented)

**Current:** `PosixProcess` sets stdout/stderr pipes non-blocking at spawn and drains per-iteration to prevent deadlocks on full kernel pipe buffer.

**External (Whisp):** Same pattern — commit "don't deadlock on full pipe buffer."

**Why external is better:** Equivalent approaches.

**Tradeoffs:** None — already correctly implemented.

---

# Architecture Improvements

## 1. Backend Plugin Architecture

**Current:** Backend selection via `SUGARCRAFT_PTY_BACKEND` env var with hardcoded `match` statement in `PtySystemFactory::forPlatform()`.

**Proposed:** Plugin architecture enabling runtime registration of backends:
```php
interface PtySystemProvider {
    public function supports(): bool; // probe capability
    public function create(): PtySystem;
}

PtySystemFactory::register('posix-ffi', new PosixPtySystemProvider());
PtySystemFactory::register('sidecar', new SidecarPtySystemProvider()); // throws forDeferredBackend in v1
PtySystemFactory::register('conpty', new WindowsConPTYSystemProvider()); // v2
```

**Benefit:** Extensible without modifying core factory logic; enables third-party PTY backends.

## 2. Separable PtyPool for Concurrent Session Management

**Current:** `PtyPool` is a simple bounded-concurrency wrapper. No session tracking or cleanup callbacks.

**Proposed:** Enhance `PtyPool` with:
- Session metadata (creation time, last activity, session ID)
- Automatic cleanup callbacks on exit
- Resource limit enforcement (memory, CPU per session)

**Benefit:** Better resource management for long-running multi-session applications.

## 3. Async/Await Bridge via Fibers

**Current:** All async operations use callbacks. `PosixPump::run()` blocks until child exits or pump is stopped.

**Proposed:** Add `Fiber`-based async/await bridge for PHP 8.1+:
```php
$child = Fiber::suspend(new StartPty($command));
// Main event loop handles I/O
Fiber::resume($child, new PtyData($bytes));
```

**Benefit:** More natural async code for PHP developers, better integration with modern async PHP.

---

# API / Developer Experience Improvements

## 1. Unified Exception Hierarchy

**Current:** `PtyException` base, `UnsupportedPlatformException`, `ExpectEofException`, `ExpectTimeoutException` — inconsistent hierarchy.

**Proposed:** Refine exception hierarchy:
```
PtyException (base)
├── PtySystemException (factory/initialization failures)
│   ├── UnsupportedPlatformException
│   └── BackendUnavailableException (forDeferredBackend)
├── PtyOperationException (runtime I/O failures)
│   ├── PtyWriteException
│   ├── PtyReadException
│   └── PtyResizeException
├── PtyProcessException (child lifecycle failures)
│   ├── ChildExitException
│   └── ChildSignalException (128 + signal)
├── PtyExpectException (pattern matching failures)
    ├── ExpectTimeoutException
    └── ExpectEofException
```

**Benefit:** Catch-specific exceptions; better error handling for consumers.

## 2. Named Constructors for Common Patterns

**Current:** `PosixPump::run($master, STDIN, STDOUT, $child, $opts)` with many arguments.

**Proposed:** Add named constructors for common patterns:
```php
// Basic shell session
$pump = PosixPump::shell(
    command: ['/bin/bash', '-i'],
    cols: 80, rows: 24,
    env: ['TERM' => 'xterm-256color']
);

// With recorder
$pump = PosixPump::recorded(
    shell: ['/bin/bash', '-i'],
    recorder: $recorder
);

// With resize forwarding
$pump = PosixPump::withResizeForwarding(
    command: ['/bin/bash', '-i'],
    ttySizeProvider: fn() => Tty::size()
);
```

**Benefit:** Reduced boilerplate for common use cases.

## 3. Better IDE Autocomplete for Contract Returns

**Current:** `PtySystemFactory::default()` returns `PtySystem` interface — IDE doesn't know which concrete class.

**Proposed:** Add `@return` annotations with concrete types:
```php
/**
 * @return PosixPtySystem|WindowsConPTYSystem|SidecarPtySystem
 */
public static function default(): PtySystem
```

**Benefit:** Better IDE support for method chaining on returned instances.

---

# Documentation / Cookbook Opportunities

## 1. PTY Session Recording Cookbook

**Topic:** Record and replay PTY sessions for testing and demo

**Content:**
- Basic recording with `Recorder`
- Playback with `Vcr\Player`
- Custom serialization formats
- Handling resize events in recordings
- Edge cases (fast typing, simultaneous output)

**Source:** `candy-pty/CALIBER_LEARNINGS.md` "recorder-header-defaults" pattern

## 2. SSH Server Integration Guide

**Topic:** Use `candy-pty` to build an SSH server

**Content:**
- Wiring `PosixPump` into SSH transport
- Session lifecycle management
- PTY + SSH key auth integration
- Connection timeout handling

**Source:** `WhispPHP/whisp` patterns, `charmbracelet_wish.md` middleware composition

## 3. Multi-Pump Dashboard Tutorial

**Topic:** Build a split-pane terminal viewer with multiple PTY sessions

**Content:**
- `MultiPump` usage for N:1 demux
- Per-session prefix tagging
- Dynamic session add/remove
- Quit-on-all-exit pattern

**Source:** `candy-pty/examples/multi-pump.php`

## 4. expect-Style Automation

**Topic:** Automate interactive CLI programs with `Expect`

**Content:**
- Pattern matching with timeouts
- Sendline and sendraw
- Multiplex expect across multiple sessions
- Error handling for timeout/eof

**Source:** `candy-pty/src/Expect.php` API, pexpect documentation

---

# UX / TUI Improvements

## 1. PTY Session Lifecycle Events

**Current:** `PumpOptions` has `onChildExit` callback but no session lifecycle events.

**Proposed:** Add lifecycle callbacks:
```php
$pumpOpts = (new PumpOptions())
    ->withOnSessionStart(fn(SessionId $id) => $this->log("Session started: $id"))
    ->withOnSessionEnd(fn(SessionId $id, int $exitCode) => $this->log("Session ended: $id = $exitCode"))
    ->withOnIdle(fn() => $this->tick())
    ->withOnResize(fn(int $cols, int $rows) => $this->resize($cols, $rows));
```

**Benefit:** Better observability for session management.

## 2. Better Error Messages for Missing Extensions

**Current:** `ext-ffi` required exception is generic.

**Proposed:** Guide users to install missing extensions:
```php
if (!extension_loaded('ffi')) {
    throw new PtySystemException(
        'ext-ffi is required for candy-pty. ' .
        'Install with: apt install php-ffi  OR  pecl install ffi  OR  enable in php.ini'
    );
}
```

**Source:** `php-tui/php-tui` Issue #230 IntlChar guidance pattern

**Benefit:** Reduces support burden; users can self-diagnose.

## 3. Interactive Shell Detection Helper

**Title:** `Shell::isInteractive()` utility to detect if running in an interactive shell

**Content:**
- Check `isatty(STDOUT)` + `isatty(STDIN)`
- Detect `TERM` environment variable
- Determine appropriate `TERM` value if missing

**Benefit:** Consumers can adjust behavior based on terminal type.

---

# Testing / Reliability Improvements

## 1. Property-Based Testing with PhpStorm

**Current:** Hand-written test cases for edge cases.

**Proposed:** Add property-based testing for edge cases:
```php
/**
 * @property int $cols 0-10000
 * @property int $rows 0-10000
 */
class SizePropertyTest extends PHPUnit\Framework\TestCase
{
    public function testResizeAcceptsAnyValidDimensions(): void
    {
        // Shrink from 10000x10000 to 1x1
        // Expand from 1x1 to 10000x10000
        // Verify no crash, errno handled
    }
}
```

**Benefit:** Catches edge cases human-written tests miss.

## 2. FFI Symbol Availability Test Matrix

**Current:** FFI symbols tested on single CI run.

**Proposed:** Test matrix across OS versions:
- Linux: glibc 2.31+ (Ubuntu 20.04), musl 1.2+ (Alpine)
- macOS: macOS 12 (Monterey), macOS 13 (Ventura), macOS 14 (Sonoma), macOS 15 (Sequoia)
- Darwin arch64 vs x86_64

**Benefit:** Ensures FFI cdef symbols actually exist on target platforms; caught macOS 15 `tcsetwinsize` missing.

## 3. Chaos Testing for Signal Handler Reliability

**Current:** `SignalForwarder` tests use self-delivery `posix_kill(posix_getpid(), SIGWINCH)`.

**Proposed:** Add chaos testing:
- Rapid SIGWINCH delivery (10 signals in 1ms)
- SIGWINCH during close/resize race
- SIGCHLD during active I/O
- Nested signal handler invocation

**Benefit:** Validates signal handler robustness under stress.

## 4. Cross-Platform CI Signal Handling Tests

**Current:** Some signal tests skipped in GitHub Actions containers.

**Proposed:** Document CI limitations and provide workarounds:
```php
// Skip in containerized CI; run on native runners
$isCiContainer = getenv('CI') && PHP_OS_FAMILY === 'Linux'
    && !file_exists('/dev/tty');
if ($isCiContainer) {
    $this->markTestSkipped('Signal handling unreliable in CI containers');
}
```

**Source:** `WhispPHP_whisp.md` "GitHub Actions containers don't properly handle signals"

---

# Ecosystem / Integration Opportunities

## 1. candy-wish Integration (SSH Server)

**Current:** `candy-wish` uses `InProcessTransport` which wraps `PosixPump`.

**Proposed:** First-class `candy-pty` integration in `candy-wish`:
- `WishTransport` interface implemented by `PosixPumpTransport`
- PTY session per SSH channel
- Window resize forwarding via `SignalForwarder`
- Session recording via `Recorder` tap

**Source:** `charmbracelet_wish.md` bubbletea middleware pattern

## 2. candy-shell Integration (Interactive Shell)

**Current:** `candy-shell` uses PTY spawning for interactive shell sessions.

**Proposed:** Deeper integration:
- Shell detection and `TERM` auto-configuration
- `candy-shell` command pipeline over PTY
- Ctrl+C / Ctrl+Z signal forwarding
- History navigation support

**Source:** MATCHUPS.md "candy-shell" consumer listing

## 3. VSCode Debug Protocol Support

**Title:** Support VSCode's debug protocol over PTY

**Description:** VSCode's terminal-based debugging communicates over a PTY. Adding `DAP` (Debug Adapter Protocol) message parsing would enable VSCode PHP debugging with `candy-pty`.

**Source:** `charmbracelet_bubbletea.md` "tea.Exec" for running external commands

**Implementation ideas:**
1. Add `DebugAdapterTransport` wrapping `PosixPump`
2. Parse DAP JSON messages
3. Forward breakpoints, variable inspection

**Benefit:** Enables VSCode PHP debugging without xdebug extension

---

# Notable PRs / Issues / Discussions

## 1. WhispPHP/whisp: AES-256-GCM OpenSSL Fallback (PR #3)

**Summary:** On Apple Silicon, `sodium_crypto_aead_aes256gcm_is_available()` randomly returns false even though AES-GCM is supported. PR #3 adds OpenSSL fallback.

**Relevance:** Multi-backend fallback pattern applicable to FFI. `candy-pty` already has FFI → stty fallback, but multi-backend FFI approach could apply to future Windows ConPTY FFI.

**Lesson:** Cross-platform detection must check multiple backends and fail gracefully. Don't trust a single detection function.

**Potential adaptation:** For Windows ConPTY, probe both `kernel32.dll` ConPTY API and fallback to `winpty` if unavailable.

## 2. WhispPHP/whisp: Inactivity Timeout Arithmetic Bugs

**Summary:** 60000ms interpreted as 60000 minutes due to wrong time unit. Multiple commits to fix.

**Relevance:** `candy-pty` has time-based parameters (`stdinEofGraceSec`, `selectTimeoutUs`, `flushDeadlineSec`). Single-unit confusion could cause similar bugs.

**Lesson:** Use explicit time unit suffixes in variable names (`$timeoutSec`, `$deadlineMs`) and prefer `DateTimeImmutable` for arithmetic.

**Potential adaptation:** Review all time parameters for unit clarity. Consider `Duration` value object with explicit units.

## 3. php-tui/php-tui: stream_set_blocking STDIN Restoration (Issue #233)

**Summary:** `stream_set_blocking(STDIN, false)` left unrestored after php-tui invocation, breaking parent processes.

**Relevance:** `candy-pty` has `setBlocking()` method. Same issue applies if parent process calls `setBlocking(false)` and child process exits without restoration.

**Lesson:** RAII pattern for terminal state changes. Always restore in destructor or use scope guards.

**Potential adaptation:** Add `MasterPty::__destruct()` that restores original blocking mode.

## 4. php-tui/php-tui: IntlChar Class Not Found (Issue #230)

**Summary:** `BrailleGrid` uses `IntlChar` requiring `ext-intl`. Users without the extension get fatal errors.

**Relevance:** `candy-pty` requires `ext-ffi`. Users on environments with FFI disabled cannot use the library.

**Lesson:** Provide clear error messages guiding users to install missing dependencies.

**Potential adaptation:** Enhance `PtyException` messages with installation instructions for `ext-ffi`.

## 5. charmbracelet/x/xpty: macOS conpty Support

**Summary:** Upstream Go implementation has ConPTY support on Windows via `conpty.AttachToProcess()` and `conpty.ResizeTerminal()`.

**Relevance:** `candy-pty` has no Windows support. This is the canonical reference for ConPTY API design.

**Lesson:** ConPTY API surface is small: `CreatePseudoConsole()`, `ClosePseudoConsole()`, `ResizeTerminal()`. The challenge is Win32 FFI, not the API design itself.

**Potential adaptation:** Mirror Go API design when implementing `WindowsConPTYSystem`.

## 6. charmbracelet/bubbletea: Signal Handling (tea.ExecProcess)

**Summary:** `tea.ExecProcess` runs external commands (vim, shells) while pausing the TUI. Bubbletea handles SIGINT/SIGTERM gracefully and restores terminal state.

**Relevance:** `candy-pty` has `Process` and `Child` for running external commands. Similar signal handling needed for embedded shells.

**Lesson:** Bubbletea's `ExecProcess` pattern: pause TUI rendering, run command, restore TUI state on completion. `candy-pty` needs similar "exec and return" semantics.

**Potential adaptation:** Add `PosixChild::exec()` method that suspends TUI, runs command, resumes on completion.

## 7. charmbracelet/vhs: Live Recording Mode

**Summary:** VHS uses `creack/pty` for live tape recording — captures terminal sessions as `.tape` files.

**Relevance:** `candy-vcr` provides session recording. VHS's approach to encoding escape sequences as tape commands is a potential extension.

**Lesson:** Recording raw bytes is useful, but encoding as reproducible tape commands enables CI replay and editing.

**Potential adaptation:** Consider `Vcr\Format\TapeFormat` as an alternative to raw byte recording.

---

# Recommended Roadmap

## Immediate Wins (0-3 months)

1. **Process resource validation** — Add `isValid()` to `Child` contract; guard I/O operations
2. **RAII cleanup for blocking mode** — `MasterPty::__destruct()` restores original blocking state
3. **Better missing-extension error messages** — Guide users to install `ext-ffi`
4. **Inactivity timeout** — Add `withInactivityTimeout()` to `PumpOptions`
5. **Connection ID logging** — Add `SessionId` to `PosixMasterPty` for multi-session debugging
6. **BSD/Solaris testing** — Add CI coverage for platform differences

## Medium-Term Improvements (3-9 months)

7. **Backend plugin architecture** — Enable runtime backend registration
8. **Async/Fiber bridge** — Add `Fiber`-based async pump
9. **Session lifecycle events** — `onSessionStart`, `onSessionEnd` callbacks
10. **Enhanced PtyPool** — Session metadata, automatic cleanup, resource limits
11. **Connection ID / session logging** — Prefix logs with session identifiers
12. **PECL extension investigation** — Research phpterm or uv for FFI-free backend

## Major Architectural Upgrades (9-18 months)

13. **Windows ConPTY backend** — Full ConPTY support for Windows 10 1809+
14. **pecl-sidecar backend** — FFI-free deployment option
15. **PTY View component** — Embed PTY sessions in larger TUI applications
16. **Debug Adapter Protocol support** — VSCode PHP debugging over PTY

## Experimental Ideas (18+ months)

17. **Multi-backend FFI layer** — Probe multiple FFI loading strategies
18. **expect-style automation library** — Full pexpect port using `Expect`
19. **PTY-based filesystem** — FUSE-style filesystem over PTY for testing
20. **SSH key generation** — `candy-keygen` using `candy-pty` for entropy collection

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|-------------|--------|------------|------|---------------------|
| Windows ConPTY backend | High | High | High | P2 (medium-term) |
| Process resource validation | Medium | Low | Low | P1 (immediate) |
| RAII cleanup for blocking mode | Medium | Low | Low | P1 (immediate) |
| Better missing-extension errors | Medium | Low | Low | P1 (immediate) |
| Inactivity timeout | Medium | Low | Low | P1 (immediate) |
| Session ID logging | Low-Medium | Low | Low | P1 (immediate) |
| BSD/Solaris testing | Medium | Medium | Low | P1 (immediate) |
| Backend plugin architecture | Medium | Medium | Medium | P2 (medium-term) |
| Async/Fiber bridge | Medium | Medium | Medium | P2 (medium-term) |
| Session lifecycle events | Medium | Low | Low | P2 (medium-term) |
| Enhanced PtyPool | Medium | Medium | Low | P2 (medium-term) |
| PECL extension investigation | Medium | High | High | P3 (research) |
| PTY View component | Low | High | Medium | P3 (experimental) |
| DAP support | Low | High | High | P3 (experimental) |
| pecl-sidecar backend | Medium | High | High | P3 (deferred) |

---

# Final Strategic Assessment

`candy-pty` is a **mature, well-engineered PHP library** that successfully ports the `charmbracelet/x/xpty` PTY primitive to PHP with idiomatic patterns. Its architecture is sound: clean contract interfaces, FFI singleton with fallback, comprehensive signal handling, and thorough edge case handling documented in CALIBER_LEARNINGS.md.

**Strategic position:** `candy-pty` is a foundational primitive for the SugarCraft ecosystem — consumed by SSH transport (`candy-wish`), session recording (`candy-vcr`), and interactive shell (`candy-shell`). Its quality directly impacts the quality of these downstream libraries.

**Critical gaps:**
1. **Windows ConPTY** is the most significant gap — it blocks the entire ecosystem from Windows users. This should be a medium-term priority despite its complexity, as it unlocks a major platform.
2. **Process resource validation** is an immediate correctness issue — zombie processes can cause crashes in edge cases.
3. **FFI-free fallback** via pecl extension would enable use in restricted hosting environments — a significant adoption blocker.

**Competitive analysis:** Compared to `WhispPHP/whisp` (PHP SSH server with FFI PTY), `candy-pty` has a cleaner architecture (contract-driven vs. application-specific) and better separation of concerns. Compared to `charmbracelet/x/xpty` (Go upstream), `candy-pty` lacks Windows support and async concurrency, but achieves equivalent POSIX functionality.

**Recommendations:**
1. **Prioritize correctness improvements** (resource validation, RAII cleanup) in the immediate term — these prevent real-world crashes.
2. **Invest in Windows ConPTY** in the medium term — it's the highest-impact missing feature.
3. **Document the architecture** better with architecture decision records (ADRs) — the CALIBER_LEARNINGS.md is excellent but could be supplemented with formal ADRs for major decisions.
4. **Expand CI coverage** for BSD/Solaris and Darwin arm64 — platform coverage gaps could surface at user sites not reproducible in CI.

The library demonstrates that PHP 8.3+ FFI can achieve production-grade system programming. The biggest opportunity is translating this success to Windows, where the PHP ecosystem currently has no PTY solution.

---

*Report generated: 2026-05-27*
*Analyst: package-analysis subagent*
*Source files: candy-pty mapping (docs/repo_map/sugarcraft_candy-pty.md), README (candy-pty/README.md), CALIBER_LEARNINGS (candy-pty/CALIBER_LEARNINGS.md), 4 external repo mappings (WhispPHP_whisp, charmbracelet_wish, php-tui_php-tui, charmbracelet_bubbletea, charmbracelet_vhs, charmbracelet_soft-serve)*
