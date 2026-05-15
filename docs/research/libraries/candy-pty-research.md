# candy-pty: PTY Library Research

**Date:** 2026-05-13
**Context:** Research for candy-pty PHP FFI PTY library (part of SugarCraft monorepo)
**Upstream:** [charmbracelet/x/xpty](https://github.com/charmbracelet/x/tree/main/xpty)

---

## 1. Existing Implementation Analysis

### 1.1 Current Architecture

**Source:** `/home/sites/sugarcraft/candy-pty/src/`

| Class | Purpose |
|-------|---------|
| `Pty.php` (361 lines) | Facade — `open()`, `spawn()`, `read()`, `write()`, `resize()`, `size()`, `close()` |
| `Spawn.php` (108 lines) | Wires `proc_open()` to slave PTY; manages shim for controlling terminal |
| `Master.php` (24 lines) | Readonly snapshot of master fd + slave path |
| `Child.php` (121 lines) | Child process handle — `wait()`, `exited()`, `exitCode()` |
| `SignalForwarder.php` (144 lines) | Attaches SIGWINCH/SIGCHLD handlers via `pcntl_signal()` |
| `SizeIoctl.php` (107 lines) | Platform-aware TIOCSWINSZ/TIOCGWINSZ constants + pack/unpack |
| `Libc.php` (114 lines) | Lazy FFI singleton for libc symbols |
| `PtyException.php` (14 lines) | Exception type |
| `Lang.php` (31 lines) | i18n facade |

**Key Files:**
- `bin/pty-shim.php` (94 lines) — Controlling terminal claim via `setsid()` + `TIOCSCTTY` + `pcntl_exec()`
- `lang/en.php` — Translation strings

### 1.2 What's Implemented

| Feature | Status |
|---------|--------|
| PTY open (posix_openpt + grantpt + unlockpt + ptsname_r) | ✅ |
| Child spawn via proc_open | ✅ |
| Blocking/non-blocking read with stream_select | ✅ |
| Write to master | ✅ |
| TIOCSWINSZ resize | ✅ |
| TIOCGWINSZ readback | ✅ |
| SIGWINCH forwarding via SignalForwarder | ✅ |
| SIGCHLD reaping hook | ✅ |
| Controlling terminal via shim | ✅ |
| i18n | ✅ |

### 1.3 Known Gaps (from CALIBER_LEARNINGS.md)

> **[gotcha:tiocsctty]** PR2 wires the slave path to `proc_open()`'s `[0,1,2]` descriptor slots — opening `/dev/pts/N` three times — so the kernel hands the child stdio that talks to the master. But the child does NOT claim the slave as its **controlling terminal**... Tests using `/bin/true`, `/bin/false`, `sleep`, `sh -c 'exit N'` are unaffected (no signal-aware logic). Anything interactive (vim, less, bash with line-editing) will mis-handle Ctrl+C. PR7 ships the fix as an opt-in shim.

> **[gotcha:eintr-stream-select]** `stream_select()` returns `false` when interrupted by a signal (EINTR), e.g. when SIGWINCH arrives mid-`read()`. Throwing an exception on the false return aborts a perfectly legitimate timed read.

> **[gotcha:proc_close]** `proc_close()` returns `-1` when the child has already been reaped via a prior `proc_get_status()` call.

---

## 2. Library Research by Language

### 2.1 Go Libraries

#### 2.1.1 charmbracelet/x/xpty (Upstream)

**Repository:** https://github.com/charmbracelet/x
**Module:** `github.com/charmbracelet/x/xpty`
**Latest:** v0.1.3 (2025-09-11)
**Go:** 1.24.0

**Dependencies:**
```
github.com/charmbracelet/x/term v0.2.1
github.com/charmbracelet/x/termios v0.1.1
github.com/creack/pty v1.1.24
golang.org/x/sys v0.36.0
```

**Architecture:**
- Interfaces for cross-platform PTY (`UnixPty`, `ConPty` for Windows)
- Depends on `creack/pty` for Unix PTY operations
- `term` package provides terminal size query utilities
- `termios` package provides termios get/set via `golang.org/x/sys/unix`

**Signal Handling (from xpto source patterns):**
- Uses `signal.Notify(ch, syscall.SIGWINCH)` to listen for window changes
- Separate goroutine reads winsize and calls `pty.SetSize()`

**Key Insight:** xpty is a thin cross-platform wrapper over creack/pty + termios. The real PTY work is done by creack/pty.

**Source:** [xpty package](https://pkg.go.dev/github.com/charmbracelet/x/xpty)

---

#### 2.1.2 creack/pty (Foundational)

**Repository:** https://github.com/creack/pty
**Stars:** ~2K
**Latest:** v1.1.24

**Key Functions:**

```go
// Open — returns (master, slave) file handles
func Open() (master, slave *os.File, err error)

// Start — spawns cmd with PTY, sets controlling terminal via Setsid+Setctty
func Start(cmd *exec.Cmd) (*os.File, error)

// StartWithSize — as above but sets initial winsize
func StartWithSize(cmd *exec.Cmd, ws *Winsize) (*os.File, error)

// StartWithAttrs — full control over SysProcAttr
func StartWithAttrs(c *exec.Cmd, sz *Winsize, attrs *syscall.SysProcAttr) (*os.File, error)

// InheritSize — for SIGWINCH handler (gets size from pty, applies to tty)
func InheritSize(pty, tty *os.File) error

// Getsize / Setsize
func Getsize(t *os.File) (rows, cols int, err error)
func Setsize(t *os.File, ws *Winsize) error
func GetsizeFull(t *os.File) (size *Winsize, err error)
```

**Winsize struct:**
```go
type Winsize struct {
    Rows uint16 // ws_row
    Cols uint16 // ws_col
    X    uint16 // ws_xpixel
    Y    uint16 // ws_ypixel
}
```

**Signal Pattern (from README):**
```go
// Handle pty size.
ch := make(chan os.Signal, 1)
signal.Notify(ch, syscall.SIGWINCH)
go func() {
    for range ch {
        if err := pty.InheritSize(os.Stdin, ptmx); err != nil {
            log.Printf("error resizing pty: %s", err)
        }
    }
}()
ch <- syscall.SIGWINCH // Initial resize.
```

**TIOCSWINSZ Implementation (winsize_unix.go):**
```go
func Setsize(t *os.File, ws *Winsize) error {
    return ioctl(t, syscall.TIOCSWINSZ, uintptr(unsafe.Pointer(ws)))
}
```

**Windows ConPTY Support:** Not in creack/pty — handled separately by charmbracelet/x/conpty

**Source:** [creack/pty docs](https://godoc.org/github.com/creack/pty)

---

#### 2.1.3 google/goterm

**Repository:** https://github.com/google/goterm (archived)
**Status:** Read-only, archived
**Note:** PTY and termios parts are Linux-specific only

**Not recommended for new work** — archived and Linux-only.

---

### 2.2 Rust Libraries

#### 2.2.1 portable-pty (wezterm)

**Repository:** https://github.com/wezterm/wezterm
**Crate:** https://crates.io/crates/portable-pty
**Latest:** v0.9.0 (2025-02-11)
**Downloads:** 5.9M total, 238 reverse dependencies

**Architecture:** Trait-based `PtySystem` allowing runtime selection of implementations.

**Key Traits:**
```rust
trait PtySystem {
    fn openpty(&self, size: PtySize) -> Result<PtyPair>;
    // Returns PtyPair { master: Box<dyn MasterPty>, slave: Box<dyn SlavePty> }
}

trait MasterPty {
    fn resize(&self, size: PtySize) -> Result<()>;
    fn get_size(&self) -> Result<PtySize>;
    fn try_clone_reader(&self) -> Result<Box<dyn AsyncRead + Send>>;
    fn take_writer(&self) -> Result<Box<dyn AsyncWrite + Send>>;
}

trait SlavePty {
    fn spawn_command(&self, cmd: CommandBuilder) -> Result<Box<dyn Child + Send>>;
}

trait Child {
    fn wait(&self) -> Result<ExitStatus>;
    fn try_kill(&self) -> Result<()>;
}
```

**Usage Pattern:**
```rust
let pty_system = native_pty_system();
let mut pair = pty_system.openpty(PtySize {
    rows: 24,
    cols: 80,
    pixel_width: 0,
    pixel_height: 0,
})?;

let mut reader = pair.master.try_clone_reader()?;
let child = pair.slave.spawn_command(CommandBuilder::new("bash"))?;
```

**Dependencies:** `anyhow`, `bitflags`, `downcast-rs`, `filedesc`, `lazy_static`, `libc`, `log`, `nix`, `serial2`, `shared_library`, `shell-words`, `winapi`, `winreg`

**Source:** [portable-pty docs](https://docs.rs/portable-pty/latest/portable_pty/)

---

#### 2.2.2 xpty (Fork of portable-pty)

**Crate:** https://crates.io/crates/xpty
**Latest:** v0.3.6 (2026-03-17)
**Forked from:** portable-pty 0.9.0

**Planned improvements over portable-pty:**
- Async support (tokio/async-std)
- Better Windows ConPTY control
- Improved error types
- Modern Rust idioms (edition 2021+)

**Key difference:** Async-ready from the start, not retrofitted.

**Source:** [xpty crate](https://crates.io/crates/xpty)

---

#### 2.2.3 nix (Rust POSIX wrappers)

**Crate:** https://crates.io/crates/nix
**Used by:** portable-pty for signal handling

**Signal Handling:**
```rust
use nix::sys::signal::{SigAction, Signal, SigHandler, SaFlags, SigSet};
use nix::sys::signal::sigaction;

let action = SigAction::new(
    SigHandler::Handler(|signo| { /* handle */ }),
    SaFlags::empty(),
    SigSet::empty(),
);
unsafe { sigaction(Signal::SIGWINCH, &action)?; }
```

**Signal enum includes:**
```rust
enum Signal {
    // ...
    SIGWINCH = 28,  // Window size changes
    SIGCHLD = 17,   // Child termination
    // ...
}
```

**Source:** [nix signal docs](https://docs.rs/nix/latest/nix/sys/signal/)

---

### 2.3 PHP Approaches

#### 2.3.1 proc_open with "pty" descriptor (Built-in)

**PHP:** Since 8.0, PTY support was re-enabled in proc_open

**History:**
- Originally added in 2004, disabled within a month due to portability issues
- Re-enabled in PHP 8.0 (PR #5525, commit a84cd96e865db795edc72b2799eac5c3c67aadfe)

**Usage:**
```php
$descriptors = [
    0 => ['pty'],
    1 => ['pty'],
    2 => ['pty'],
];
$process = proc_open('command', $descriptors, $pipes);
```

**Limitation:** Uses `openpty()` internally — cannot set initial window size before spawn.

**Bug (still present):** When using `['pty'], ['pty'], ['pty']`, all three stdio descriptors share the SAME PTY master. Data from stdout and stderr can interleave arbitrarily because a PTY has only one output stream.

**Source:** [PHP proc_open PTY PR](https://github.com/php/php-src/pull/5525), [PHP Issue #17983](https://github.com/php/php-src/issues/17983)

---

#### 2.3.2 phpseclib (SSH2 PTY)

**Repository:** https://github.com/phpseclib/phpseclib
**Purpose:** Pure-PHP SSHv2 implementation with PTY support

**API:**
```php
$ssh = new \phpseclib3\Net\SSH2('hostname');
$ssh->login('user', 'password');

$ssh->enablePTY();
$ssh->exec('passwd');
// or
$ssh->write("ls -la\n");
echo $ssh->read();
```

**Note:** Not a local PTY library — operates over SSH connection. Not directly comparable to candy-pty which creates local PTY pairs.

**Source:** [phpseclib SSH2 docs](https://api.phpseclib.com/master/phpseclib3/Net/SSH2.html)

---

#### 2.3.3 php-termios (FFI termios wrapper)

**Repository:** https://github.com/ppelisset/php-termios
**Requires:** PHP 8.1+ with FFI enabled

**API:**
```php
Termios\Termios::tcgetattr($fd);   // Get terminal attributes
Termios\Termios::tcsetattr($fd);   // Set terminal attributes
Termios\Termios::tcflush($fd);     // Flush buffers
```

**Note:** Termios (line discipline, canonical mode, etc.) not PTY creation. Useful for setting raw mode on existing TTYs, not for creating new PTY pairs.

---

#### 2.3.4 linkorb/tty (PHP TTY library)

**Repository:** https://github.com/linkorb/tty
**Features:**
- ttyrec parser
- VT100/ANSI Terminal emulator
- AsciiRenderer for replay

**Note:** Higher-level than candy-pty; does not provide PTY pair creation.

---

#### 2.3.5 php-tui/term

**Repository:** https://github.com/php-tui/term
**Purpose:** Low-level terminal manipulation library heavily inspired by crossterm

**Features:**
- Raw mode
- Terminal size query
- Input event handling
- ANSI escape code parser

**Note:** For terminal I/O manipulation, not PTY spawning.

---

## 3. Cross-Cutting Analysis

### 3.1 PTY Spawning Comparison

| Library | Method | Pre-spawn Size Set | Controlling Terminal |
|---------|--------|-------------------|---------------------|
| candy-pty | `proc_open` + slave path descriptors | ✅ via `TIOCSWINSZ` before spawn | ✅ via shim |
| creack/pty Go | `openpty()` → assign to cmd.Stdin/Out/Err | ✅ via `Setsize()` before `cmd.Start()` | ✅ via `cmd.SysProcAttr.Setsid = true; Setctty = true` |
| portable-pty Rust | `openpty()` → `slave.spawn_command()` | ✅ via `pty_system.openpty(PtySize{...})` | ✅ automatic in `spawn_command()` |
| proc_open pty | `proc_open(['pty'], ...)` | ❌ no control | ❌ no control |

**Key Insight:** creack/pty sets size BEFORE `cmd.Start()` by calling `Setsize(master)` before starting the process. candy-pty calls `resize()` on the master BEFORE `proc_open` which achieves the same thing.

The shim approach in candy-pty for controlling terminal is the correct pattern — creack/pty does the equivalent in Go via `SysProcAttr{Setsid: true, Setctty: true}`.

---

### 3.2 Signal Handling Comparison

| Library | SIGWINCH | SIGCHLD | Async Signal Mode |
|---------|----------|---------|-------------------|
| candy-pty | `SignalForwarder::attachSigwinch()` | `SignalForwarder::attachSigchld()` | `pcntl_async_signals(true)` |
| creack/pty Go | `signal.Notify(ch, SIGWINCH)` goroutine | via `cmd.Wait()` | goroutine-based |
| portable-pty Rust | via `nix::sys::signal` | via `nix::sys::wait` | callback-based |

**candy-pty Pattern (SignalForwarder.php):**
```php
public static function attachSigwinch(Pty $pty, callable $sizeProvider, bool $async = true): bool
{
    $handler = static function (int $signo) use ($pty, $sizeProvider): void {
        if ($pty->isClosed()) return;
        try {
            $size = $sizeProvider();
            $pty->resize((int) $size['cols'], (int) $size['rows']);
        } catch (\Throwable) { /* swallow */ }
    };
    if (!@\pcntl_signal(SIGWINCH, $handler)) return false;
    self::ensureAsync($async);
    return true;
}
```

**creack/pty Pattern (Go):**
```go
ch := make(chan os.Signal, 1)
signal.Notify(ch, syscall.SIGWINCH)
go func() {
    for range ch {
        if err := pty.InheritSize(os.Stdin, ptmx); err != nil {
            log.Printf("error resizing pty: %s", err)
        }
    }
}()
ch <- syscall.SIGWINCH // Initial resize
```

**Key Differences:**
- Go uses a channel + goroutine; PHP uses signal handler + closure
- PHP must wrap handler in try/catch (signal handlers cannot throw)
- PHP has `isClosed()` race guard; Go relies on channel closing

---

### 3.3 Window Resize Comparison

| Library | TIOCSWINSZ | TIOCGWINSZ | SIGWINCH Delivery to Child |
|---------|-----------|-----------|---------------------------|
| candy-pty | ✅ `Pty::resize()` | ✅ `Pty::size()` | ⚠️ Only if child is in own process group (via shim) |
| creack/pty | ✅ `Setsize()` | ✅ `GetsizeFull()` | ✅ via `InheritSize()` in SIGWINCH handler |
| portable-pty | ✅ `MasterPty::resize()` | ✅ `MasterPty::get_size()` | ✅ automatic |

**candy-pty Limitation (from CALIBER_LEARNINGS):**
> **[gotcha:tiocsctty]** ...the child does NOT claim the slave as its **controlling terminal**... Consequence: `Ctrl+C` typed into the master does NOT generate a `SIGINT` for the child...

**However:** With `controllingTerminal: true`, the shim does `setsid()` + `TIOCSCTTY` so the child IS in its own process group and SIGWINCH WOULD reach it IF the kernel sends SIGWINCH to the foreground process group. The resize IS applied to the master via TIOCSWINSZ; the child sees the new size when it does `ioctl(TIOCGWINSZ)` itself.

**creack/pty InheritSize Pattern:**
```go
func InheritSize(pty, tty *os.File) error {
    size, err := GetsizeFull(pty)
    if err != nil { return err }
    return Setsize(tty, size)
}
```

This copies the size from the PTY master to the terminal (stdin). When a terminal resize occurs:
1. Host receives SIGWINCH
2. Handler reads new size from PTY master via `GetsizeFull`
3. Handler writes new size to terminal via `Setsize`
4. The child (reading from terminal) sees the new size

**candy-pty equivalent would need:** A separate `/dev/tty` or stdin reference to forward sizes to — not the PTY itself.

---

### 3.4 Non-Blocking I/O Comparison

| Library | Mechanism | EINTR Handling |
|---------|-----------|---------------|
| candy-pty | `stream_select()` with deadline retry loop | ✅ `pcntl_signal_dispatch()` + retry |
| creack/pty Go | `io.Copy()` or manual `Read()` on `*os.File` | Go runtime handles |
| portable-pty Rust | `AsyncRead` trait + `try_clone_reader()` | async runtime handles |
| proc_open pty | `stream_set_blocking(false)` + `stream_get_contents` | Manual retry |

**candy-pty read() EINTR-safe implementation:**
```php
$deadline = \microtime(true) + $timeout;
while (true) {
    $remaining = $deadline - \microtime(true);
    if ($remaining <= 0) return null;
    $ready = @\stream_select($r, $w, $e, (int)\floor($remaining), $usec);
    if ($ready === false) {
        if (\function_exists('pcntl_signal_dispatch')) {
            @\pcntl_signal_dispatch();
            continue;  // retry
        }
        throw new PtyException(...);
    }
    if ($ready === 0) return null;  // timeout
    break;
}
```

**Source:** `/home/sites/sugarcraft/candy-pty/src/Pty.php:L242-L263`

---

### 3.5 macOS-Specific Issues

**Issue: posix_openpt() returns fd that doesn't work with fcntl O_NONBLOCK**

> Setting non-blocking on a pty... macOS... `posix_openpt()` is broken on macos, the file descriptor it returns isn't useful for much. If you use `openpty()`, that file descriptor works ok.

**Source:** [Apple Developer Forums](https://developer.apple.com/forums/thread/734230)

**Workaround in candy-pty:** Using `proc_open` + slave path descriptors works because proc_open handles the complexity. The master fd is used only for read/write/ioctl, not for fcntl flags directly.

**macOS /dev/tty kqueue limitation:**

> On OSX libuv uses the kqueue interface for polling sockets... But... file descriptors pointing to /dev/tty don't work with kqueue. Ouch... they do work with select(2)!

**Source:** [libuv OSX select trick](https://code.saghul.net/2016/05/libuv-internals-the-osx-select2-trick/)

**Impact:** candy-pty uses `stream_select()` which works on macOS. No issue here.

---

## 4. Identified Improvements

### 4.1 High Priority

#### 4.1.1 Add openpty() as an alternative to posix_openpt + grantpt + unlockpt + ptsname_r

**Rationale:** macOS has known issues with `posix_openpt()`. `openpty()` is a single syscall that handles all the setup portably.

**Implementation:**
```php
// Add to Libc::cdef()
int openpty(int *amaster, int *aslave, char *name, void *termp, void *winp);
```

**Source:** [Python cpython pty module](https://github.com/python/cpython/blob/main/Lib/pty.py) uses `openpty()` for initial open.

**Effort:** Low. Add FFI binding, modify `Pty::open()` to branch on platform.

---

#### 4.1.2 Add direct_waitpid() to avoid 10ms poll loop in Child::wait()

**Rationale:** The 10ms polling loop in `wait()` is CPU-intensive. Adding a `waitpid(pid, 0)` FFI binding would allow true blocking wait.

**Implementation:**
```php
// Add to Libc::cdef()
pid_t waitpid(pid_t pid, int *status, int options);
```

**Source:** Same pattern used in portable-pty Rust (via nix::sys::wait::waitpid)

**Effort:** Low. Add FFI binding, modify `Child::wait()` to use it when pcntl unavailable.

---

#### 4.1.3 Add termios get/set for raw mode on master

**Rationale:** Some use cases need to set raw mode (no echo, no line buffering) on the master side.

**Source:** creack/pty doesn't do this (uses `os.Stdout` directly), but termbox/tcell-style programs need it.

**Implementation:** Add `tcgetattr`/`tcsetattr` FFI bindings, create `Termios` class.

**Effort:** Medium. Requires understanding of termios structure layout on Linux/macOS.

---

### 4.2 Medium Priority

#### 4.2.1 Add size forwarding example that mirrors creack/pty InheritSize pattern

**Rationale:** The current `SignalForwarder::attachSigwinch()` forwards host size to PTY master. But the creack/pty pattern forwards to the child's controlling terminal (`/dev/tty` or stdin). 

**Current candy-pty behavior:** Resize goes to PTY master; child sees new size only when it queries TIOCGWINSZ.

**creack/pty behavior:** Resize goes to PTY master AND to the child's terminal via `InheritSize(pty, tty)`.

**Proposed enhancement:** Add `SignalForwarder::attachSigwinchWithTTY($pty, $sizeProvider, $ttyFd)` variant that also does `ioctl($ttyFd, TIOCSWINSZ, $winsize)`.

**Effort:** Medium. Requires new FFI binding for `/dev/tty` open.

---

#### 4.2.2 Add async I/O support via ReactPHP streams

**Rationale:** For use with event loops (ReactPHP, revolt/event-loop).

**Source:** php-tui/term and other PHP terminal libs use ReactPHP for async I/O.

**Implementation:** Add `Pty::readAsync()` returning a Promise, wrapping the current `read()` with timeout in a coroutine.

**Effort:** Medium. Depends on ReactPHP integration in sugar-wishlist or sugar-bits.

---

#### 4.2.3 Add PtyPair / PtyMaster / PtySlave split (trait-based)

**Rationale:** Rust portable-pty uses traits (`PtySystem`, `MasterPty`, `SlavePty`) allowing different implementations. PHP doesn't have traits as powerful as Rust's, but interfaces can achieve similar polymorphism.

**Use case:** Could support different PTY implementations (fork-based vs proc_open-based) without changing API.

**Effort:** Medium. Refactor `Pty` into interface + implementations. May be over-engineering for PHP.

---

### 4.3 Low Priority (Nice to Have)

#### 4.3.1 Add serial port TTY support

**Source:** portable-pty has `cmdbuilder::serial` module for serial port TTY

**Implementation:** Beyond candy-pty scope — separate `candy-serial` lib.

---

#### 4.3.2 Windows ConPTY support

**Status:** Tracked in `plans/x-windows.md` per README

**Note:** xpty uses `github.com/charmbracelet/x/conpty` for Windows. This is a separate concern.

**Effort:** High — requires Windows API FFI bindings (ConPTY functions like `CreatePseudoConsole`).

---

#### 4.3.3 Add PTS (pseudo-terminal slave) path caching

**Rationale:** `ptsname_r()` is called once at open time, but the slave path never changes. Could cache it in `Master` instead of storing it.

**Current:** `Master` stores both `$fd` and `$slavePath`.

**Effort:** Trivial. Just remove `$slavePath` from `Master` if never needed after `Spawn::proc()`.

---

#### 4.3.4 Process group management utilities

**Rationale:** creack/pty exposes `Setsid` and `Setctty` separately. candy-pty bundles them in the shim.

**Could add:** `Pty::setControllingTerminal(int $fd)` as a public API using TIOCSCTTY ioctl.

**Effort:** Low. Already done in shim; extract to public method.

---

## 5. Recommendations

### 5.1 Immediate (Next PR)

1. **Add `openpty()` FFI binding** — Fix macOS compatibility issue with `posix_openpt()`
   - Effort: Low (1-2 days)
   - Risk: Low (additive, platform-specific branch)

2. **Add `waitpid()` FFI binding** — Eliminate 10ms poll loop in `Child::wait()`
   - Effort: Low (1 day)
   - Risk: Low (additive when pcntl unavailable)

### 5.2 Short-term (1-2 PRs)

3. **Add termios raw mode support** — `Termios` class with `tcgetattr`/`tcsetattr`
   - Effort: Medium (3-5 days)
   - Risk: Medium (termios struct layout differs on Linux/macOS, need verification)

4. **Add size forwarding to `/dev/tty`** — Mirror creack/pty's `InheritSize` pattern
   - Effort: Medium (2-3 days)
   - Risk: Low (additive variant of existing API)

### 5.3 Medium-term

5. **Async I/O via ReactPHP** — For event loop integration
   - Effort: Medium (3-4 days)
   - Risk: Medium (depends on ReactPHP stream implementation)

6. **Interface-based refactor** — For testability and polymorphism
   - Effort: Medium (3-5 days)
   - Risk: Medium (breaking change to internal structure)

---

## 6. Effort Summary Table

| Improvement | Priority | Effort (days) | Risk |
|-------------|----------|---------------|------|
| Add openpty() FFI binding | High | 1-2 | Low |
| Add waitpid() FFI binding | High | 1 | Low |
| Add termios raw mode | Medium | 3-5 | Medium |
| Size forwarding to /dev/tty | Medium | 2-3 | Low |
| Async I/O via ReactPHP | Medium | 3-4 | Medium |
| Interface-based refactor | Medium | 3-5 | Medium |
| Windows ConPTY support | Low | 8+ | High |
| Serial port TTY support | Low | 5+ | Medium |

---

## 7. References

### Upstream Sources

- **charmbracelet/x/xpty:** https://github.com/charmbracelet/x/tree/main/xpty
- **creack/pty:** https://github.com/creack/pty
- **portable-pty:** https://docs.rs/portable-pty/latest/portable_pty/
- **xpty (Rust):** https://crates.io/crates/xpty
- **nix signal:** https://docs.rs/nix/latest/nix/sys/signal/

### PHP PTY History

- **PHP proc_open PTY PR #5525:** https://github.com/php/php-src/pull/5525
- **PHP Issue #17983 (stdout/stderr mixing):** https://github.com/php/php-src/issues/17983
- **PHP Bug #39224:** https://bugs.php.net/bug.php?id=39224

### Key Patterns

- **Python cpython pty module:** https://github.com/python/cpython/blob/main/Lib/pty.py
- **libuv OSX select trick:** https://code.saghul.net/2016/05/libuv-internals-the-osx-select2-trick/
- **TIOCSWINSZ/TIOCGWINSZ man page:** https://man7.org/linux/man-pages/man2/TIOCGWINSZ.2const.html
- **SIGWINCH signal handling example:** https://man7.org/tlpi/code/online/dist/tty/demo_SIGWINCH.c.html

### Local Files (candy-pty)

- `/home/sites/sugarcraft/candy-pty/src/Pty.php` — Main facade
- `/home/sites/sugarcraft/candy-pty/src/Spawn.php` — Child spawn logic
- `/home/sites/sugarcraft/candy-pty/src/SignalForwarder.php` — Signal handling
- `/home/sites/sugarcraft/candy-pty/src/Libc.php` — FFI bindings
- `/home/sites/sugarcraft/candy-pty/bin/pty-shim.php` — Controlling terminal shim
- `/home/sites/sugarcraft/candy-pty/CALIBER_LEARNINGS.md` — Patterns and gotchas
