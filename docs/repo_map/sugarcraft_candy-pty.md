# SugarCraft/candy-pty — Innovation & Comparison Report

## Metadata

| Field | Value |
|-------|-------|
| **Package** | `sugarcraft/candy-pty` |
| **Namespace** | `SugarCraft\Pty` |
| **Subdir** | `candy-pty/` |
| **Upstream** | [charmbracelet/x/xpty](https://github.com/charmbracelet/x/tree/main/xpty) + [creack/pty](https://github.com/creack/pty) |
| **Status** | 🟢 v1 ready |
| **PHP** | `^8.3` |
| **FFI** | `ext-ffi` (required), `ext-pcntl` (optional, for signal handling) |
| **Platforms** | Linux (glibc/musl), macOS (Darwin/xnu) |
| **Dependencies** | `sugarcraft/candy-core` (dev: `candy-vt`, `candy-vcr`) |

---

## 1. Architecture Overview

### 1.1 Contract Interfaces (src/Contract/)

The package defines seven clean PSR-12 interfaces in `SugarCraft\Pty\Contract\`:

| Interface | File | Purpose |
|----------|------|---------|
| `PtySystem` | `PtySystem.php` | Factory for opening PTY pairs; reports platform capabilities |
| `PtyPair` | `PtyPair.php` | Holds `master()` + `slave()` handles |
| `MasterPty` | `MasterPty.php` | Host-facing: read/write/resize/size/stream/close/isClosed |
| `SlavePty` | `SlavePty.php` | Child-facing: `path()` + `spawn()` with controlling-terminal flag |
| `Child` | `Child.php` | PTY-attached process: pid/exited()/wait()/exitCode()/kill() |
| `Process` | `Process.php` | Non-PTY process: same as Child + stdoutBytes/stderrBytes |
| `Pump` | `Pump.php` | Byte pump contract with run(master, stdin, stdout, child?) |
| `Termios` | `Termios.php` | Terminal attributes: current()/makeRaw()/apply()/restore()/isAtty() |

### 1.2 Implementation Classes (src/Posix/)

| Class | File | Purpose |
|-------|------|---------|
| `PosixPtySystem` | `PosixPtySystem.php` | Opens PTY pairs via `posix_openpt` + `grantpt`/`unlockpt`/`ptsname_r` quartet or `openpty` on Darwin |
| `PosixPtyPair` | `PosixPtyPair.php` | Simple PtyPair holding master + slave path |
| `PosixMasterPty` | `PosixMasterPty.php` | Master fd + stream wrapper + TIOCSWINSZ resize + FD_CLOEXEC management |
| `PosixSlavePty` | `PosixSlavePty.php` | Spawns child via `proc_open` with optional controlling-terminal shim |
| `PosixChild` | `PosixChild.php` | PTY child with signal delivery via `posix_kill` |
| `PosixProcess` | `PosixProcess.php` | Non-PTY child with captured stdout/stderr pipes; drains in wait loop |
| `PosixPump` | `PosixPump.php` | stream_select-based byte pump with EOF grace + SIGWINCH keepalive |
| `PosixTermios` | `PosixTermios.php` | FFI tcgetattr/tcsetattr/cfmakeraw; treats struct as opaque (≥80 bytes) |
| `SttyTermios` | `SttyTermios.php` | Shell-out fallback via `stty -F /dev/fd/N` when FFI unavailable |
| `MultiPump` | `MultiPump.php` | Multiplexes N master→stdout pumps from single stream_select |
| `ChildPollTrait` | `ChildPollTrait.php` | Shared waitpid FFI fast-path + proc_get_status fallback lifecycle |

### 1.3 Supporting Infrastructure

| Class | File | Purpose |
|-------|------|---------|
| `Libc` | `Libc.php` | Lazy FFI singleton for libc; separate libutil handle for `openpty` on Linux |
| `TermiosFactory` | `TermiosFactory.php` | Tries PosixTermios first; falls back to SttyTermios on Throwable |
| `SizeIoctl` | `SizeIoctl.php` | Platform-aware TIOCSWINSZ/TIOCGWINSZ constants; Darwin stty fallback for setSize |
| `SignalForwarder` | `SignalForwarder.php` | SIGWINCH/SIGCHLD handlers with async/sync modes; no-throw callbacks |
| `PtySystemFactory` | `PtySystemFactory.php` | DI-friendly entry point; respects `SUGARCRAFT_PTY_BACKEND` env |
| `PtyPool` | `PtyPool.php` | Bounded-concurrency wrapper; hard cap on in-flight PTY pairs |
| `ControllingTerminal` | `ControllingTerminal.php` | `setsid()` + `ioctl(TIOCSCTTY, 0)` claim sequence |
| `Expect` | `Expect.php` | pexpect-style fluent driver: expect/sendLine/sendPattern over MasterPty |
| `PumpOptions` | `PumpOptions.php` | Immutable value object for pump config with with*() fluent builders |

### 1.4 Legacy Facades (src/, @deprecated)

| Class | File | Status |
|-------|------|--------|
| `Pty` | `Pty.php` | @deprecated v0.x; wraps PosixMasterPty + Master snapshot |
| `Spawn` | `Spawn.php` | @deprecated v0.x; use `PosixSlavePty::spawn()` |
| `Child` | `Child.php` | @deprecated v0.x; alias for `PosixChild` |

---

## 2. FFI Implementation Analysis

### 2.1 Libc Singleton (src/Libc.php)

The `Libc::lib()` method is the central FFI entry point:

```php
final class Libc {
    private static ?\FFI $ffi = null;
    private static ?\FFI $ffiUtil = null;  // Linux only, for openpty

    public static function lib(): \FFI { /* lazy load, cached per-process */ }
    public static function libutil(): \FFI { /* Linux libutil.so.1 for openpty */ }
    public static function cdef(): string { /* platform-branched cdef block */ }
}
```

**cdef symbols declared** (`Libc::cdef()`):
- `setsid()`, `posix_openpt()`, `grantpt()`, `unlockpt()`, `ptsname_r()`
- `waitpid()` — for sub-ms exit detection in ChildPollTrait
- `close()`, `open()`, `ioctl()` — variadic-compatible signature
- `fcntl()` — fixed-int-arg form for F_SETFD/FD_CLOEXEC only
- `tcgetattr()`, `tcsetattr()`, `cfmakeraw()`, `cfgetospeed()`, `cfsetospeed()`, `cfgetispeed()`, `cfsetispeed()`

**Platform branching:**
- Darwin: `openpty` included in main libc cdef
- Linux: `openpty` loaded separately from `libutil.so.1` via `libutil()`

**Key gotcha** (documented in CALIBER_LEARNINGS.md):
> `FFI::cdef()` resolves ALL declared symbols eagerly — adding a symbol present only on newer OS versions breaks load on older targets. `tcsetwinsize`/`tcgetwinsize` (POSIX 2024) must NOT be declared; macOS 15 libSystem doesn't export them despite docs.

### 2.2 Termios Opaque Buffer Pattern (src/Posix/PosixTermios.php)

struct termios layout differs across platforms:
- glibc/musl: ~60 bytes
- Darwin: 72 bytes

**Solution:** Treat struct as opaque `char[80]` buffer and only call:
- `tcgetattr(fd, &buf)` — snapshot current attrs
- `cfmakeraw(&buf)` — produce raw-mode copy
- `tcsetattr(fd, when, &buf)` — apply changes
- `cfgetospeed/cfsetospeed/cfgetispeed/cfsetispeed` — only speed fields

```php
final class PosixTermios implements Termios {
    private const BUFSIZE = 80; // covers all target platforms
    private \FFI\CData $buf;
    // withCData() clones instance with new buffer + memcpy for immutable snapshots
}
```

**Fallback:** `SttyTermios` shells out to `stty -F /dev/fd/<fd>` (Linux) or `stty -f /dev/fd/<fd>` (macOS) when FFI is unavailable or throws.

### 2.3 Darwin-Specific ABI Workaround (src/SizeIoctl.php)

On macOS arm64, the variadic `ioctl()` ABI mismatch causes `TIOCSWINSZ` to fail silently (struct pointer lands in wrong register). The solution:

```php
public static function setSizeViaLibc(\FFI $libc, int $fd, \FFI\CData $ws): int {
    $rc = $libc->ioctl($fd, self::setRequest(), $ws);
    if ($rc !== 0 && \PHP_OS_FAMILY === 'Darwin') {
        // Darwin arm64 variadic ABI mismatch — fallback to stty
        $sttyRc = self::sttySetSize($fd, (int) $ws[0], (int) $ws[1]);
        if ($sttyRc === 0) { return 0; }
    }
    return $rc;
}
```

Note: `TIOCGWINSZ` read direction works despite the same ABI issue — empirically the kernel tolerates it.

---

## 3. Signal Handling Patterns

### 3.1 SignalForwarder (src/SignalForwarder.php)

Three attachment methods:
- `attachSigwinch(MasterPty, sizeProvider)` — pipes host TTY resize → `$master->resize()`
- `attachSigwinchToFd(fd, sizeProvider, onResize)` — raw fd variant for non-PTY TTY handles
- `attachSigchld(reaper)` — SIGCHLD delivery for waitpid-based reaping

**Critical design rule** (CALIBER_LEARNINGS.md pattern):
> Every signal callback is wrapped in `try { } catch (\Throwable) { }`. PHP signal handlers run between opcodes — a thrown exception unwinds across arbitrary user code with a corrupt stack.

```php
$handler = static function (int $signo) use ($master, $sizeProvider): void {
    if ($master->isClosed()) { return; } // guard against torn session
    try {
        $size = $sizeProvider();
        $master->resize((int) $size['cols'], (int) $size['rows']);
    } catch (\Throwable) { /* signal handlers must not throw */ }
};
```

### 3.2 Async Mode

`SignalForwarder` calls `pcntl_async_signals(true)` lazily on first handler install. Callers with their own event loops pass `async: false` to keep dispatch synchronous.

### 3.3 SIGWINCH Self-Delivery Testing Pattern

CALIBER_LEARNINGS.md documents a smoke-test technique:
```php
posix_kill(posix_getpid(), SIGWINCH); // deliver SIGWINCH to self
```
Combined with closure-bound mutable size state, tests can verify resize handler correctness without a real terminal.

---

## 4. Platform Compatibility

### 4.1 POSIX Platforms (Linux + macOS)

| Platform | PTY Opening | Termios | Resize | Notes |
|----------|-------------|---------|--------|-------|
| Linux (glibc/musl) | `posix_openpt` + quartet OR `openpty` from libutil | FFI tcgetattr/tcsetattr | `ioctl(TIOCSWINSZ)` | `SUGARCRAFT_LIBC` env override for musl |
| macOS (Darwin/xnu) | `openpty` first, falls back to quartet | FFI tcgetattr/tcsetattr | `ioctl` fails on arm64 → stty fallback | Anchor slave fd to prevent winsize zeroing |

### 4.2 macOS Anchor Slave fd Pattern

macOS xnu zeroes PTY winsize whenever kernel-side slave fd count drops to 0. The anchor pattern:
```php
// In PosixPtySystem::open() on Darwin only:
$slaveFd = $libc->open($slavePath, O_RDWR | O_NOCTTY);
$master->attachAnchorSlaveFd($slaveFd);
$libc->fcntl($slaveFd, F_SETFD, FD_CLOEXEC);
```
This anchor holds the kernel's slave count ≥ 1 between `open()` and `proc_open()`, preserving resize.

### 4.3 Windows (Not Yet Implemented)

MATCHUPS.md TODO comment:
> When a Windows ConPTY row is added to this table, uncomment... `| TBD (ConPTY backend) | **CandyPty.Windows** ...`

`UnsupportedPlatformException::forPosixOnly()` is thrown by `PtySystemFactory::forPlatform()` for any non-POSIX platform. Exception message points to GitHub issue for upvoting.

---

## 5. Performance Characteristics

### 5.1 PTY Open Latency

Measured at 0.05 ms per `PosixPtySystem::open()` with libc handle cached — well under the 5ms threshold that would justify handle-reuse pooling. `PtyPool` therefore focuses on session bookkeeping rather than syscall caching.

### 5.2 ChildPollTrait waitpid Fast Path

`ChildPollTrait::tryWaitpid()` uses FFI `waitpid(pid, &status, WNOHANG)` as a sub-millisecond exit-detection fast path before falling back to `proc_get_status()` polling:

```php
// WNOHANG=1 means non-blocking — returns pid if exited, 0 if still running
$result = $libc->waitpid($pid, \FFI::addr($statusArray[0]), self::WNOHANG);
```

Exit code convention: normally-exited → `(status >> 8) & 0xFF`; signal-terminated → `128 + (status & 0x7F)`.

### 5.3 Pump Efficiency

`PosixPump` uses `stream_select()` with configurable timeout (default 50,000 µs = 50 ms). On idle tick, fires `onIdle` callback (keepalive) and checks for SIGWINCH-triggered resize. No busy-waiting; CPU near zero on idle.

### 5.4 Non-Blocking Pipe Captures

`PosixProcess` sets stdout/stderr pipes non-blocking at spawn:
```php
stream_set_blocking($stdoutPipe, false);
stream_set_blocking($stderrPipe, false);
```
Wait loop drains per-iteration to prevent deadlocks on full kernel pipe buffer.

---

## 6. DI / Factory Patterns

### 6.1 PtySystemFactory (src/PtySystemFactory.php)

```php
final class PtySystemFactory {
    public static function default(): PtySystem { /* respects SUGARCRAFT_PTY_BACKEND */ }
    public static function forPlatform(string $platform): PtySystem {
        return match ($platform) {
            'Linux', 'Darwin', 'BSD', 'Solaris' => new PosixPtySystem(),
            default => throw UnsupportedPlatformException::forPosixOnly($platform),
        };
    }
}
```

`SUGARCRAFT_PTY_BACKEND` values:
- `posix-ffi` (default on POSIX)
- `sidecar` / `pecl` — throws `forDeferredBackend()` (reserved for phase 12)
- `auto` / `''` / unset — same as default

### 6.2 TermiosFactory (src/TermiosFactory.php)

```php
final class TermiosFactory {
    public static function open(int $fd): Termios { /* PosixTermios → SttyTermios fallback */ }
    public static function which(int $fd): string { /* 'PosixTermios' or 'SttyTermios' */ }
}
```

Respects `SUGARCRAFT_TERMIOS=stty` env override. Logs fallback once via `error_log`.

### 6.3 PumpOptions Immutable Builder

Every option has a `with*()` method returning a cloned instance with one changed field:
```php
$pumpOpts = (new PumpOptions())
    ->withChunkBytes(8192)
    ->withOnSigwinch(fn(int $cols, int $rows) => $this->resize($cols, $rows))
    ->withKeepalive(fn() => $this->tick());
```

---

## 7. Key Implementation Patterns

### 7.1 TIOCSCTTY Controlling Terminal Claim

To get Ctrl+C → SIGINT delivery, child must own the slave as its controlling terminal. PHP can't do this inline (proc_open doesn't setsid), so a shim pattern is used:

**bin/pty-shim.php** — thin PHP script that:
1. Loads vendor autoload from multiple candidate paths (works in any project layout)
2. Calls `ControllingTerminal::claim(0)` — `setsid()` + `ioctl(0, TIOCSCTTY, 0)`
3. `pcntl_exec($cmd, $argv)` — replaces PHP process image with actual command

**Spawn wrapping** (`Spawn::wrapInShim()`):
```php
return [PHP_BINARY, $shim, ...$cmd];
```

Platform constants:
- Linux: `0x540E`
- Darwin: `0x20007461`

**Key gotcha:** `ioctl()` third arg is `unsigned long` not pointer — passing PHP `null` through `void *` FFI param renders as 0, which the kernel interprets as "don't steal existing ctty". Correct.

### 7.2 FD_CLOEXEC on Master

Every `posix_openpt` master fd gets `FD_CLOEXEC` set before `proc_open`:
```php
$libc->fcntl($masterFd, self::F_SETFD, self::FD_CLOEXEC);
```
Without this, the child inherits the master fd, keeping kernel refcount > 0 after parent close, preventing `tty_hangup()` from firing (no SIGHUP for session leader).

### 7.3 fopen('php://fd/N') Dup Semantics

`PosixMasterPty::stream()` does:
```php
$stream = \fopen('php://fd/' . $this->fd, 'r+b');
```
`fopen('php://fd/N')` **dup()s** the fd (php-src plain_wrapper.c) — `fclose($stream)` only closes the duplicate. The original fd from `posix_openpt` must be closed explicitly via `libc::close()`. Failure to do so keeps the kernel's master-side refcount alive and blocks `tty_hangup()`.

### 7.4 EOF Grace + VEOF Write

When stdin EOF arrives, the pump:
1. Writes `VEOF` (`\x04`, Ctrl+D) to master
2. Starts `stdinEofGraceSec` (default 300ms) timer
3. If child exits in window → returns child's exit code
4. If timer expires → returns -1, caller force-closes master (SIGHUP via lost-ctty)

### 7.5 Recorder Tap in Pump

`PosixPump` has an optional `Recorder` tee (wired for candy-vcr):
- stdin bytes recorded via `recordInputBytes()` after write to master (post-write so cassette is consistent with what child saw)
- output bytes recorded via `recordOutput()` before write to stdout (pre-write so partial writes don't over-record)

Null check overhead is zero when recorder is unset.

---

## 8. Comparison Against Mapped Repos

### 8.1 charmbracelet/x/xpty (Go)

| Aspect | upstream (Go) | candy-pty (PHP) |
|--------|-------------|-----------------|
| Platform detection | `unix.SelectPTYSystem()` | `PHP_OS_FAMILY` constants |
| PTY pair open | `unix.Open()` | `posix_openpt` + quartet OR `openpty` |
| Termios | `termios.TCSets`/`TCGets` | FFI `tcsetattr`/`tcgetattr` |
| Raw mode | `termios.MakeRaw()` | FFI `cfmakeraw()` |
| Winsize | `unix.IOCtlWinsize` | `ioctl(TIOCSWINSZ)` with stty fallback on Darwin |
| Controlling terminal | `pty.Start()` with `SysProcAttr.Setctty` | `bin/pty-shim.php` shim + `pcntl_exec` |
| Child lifecycle | `cmd.Wait()` with `SysProcAttr` | `proc_get_status()` + `waitpid` FFI |
| Signal forwarding | `signal.Notify` goroutine | `pcntl_signal` handlers |
| ConPTY support | Built-in for Windows | `UnsupportedPlatformException` (v2 sidecar) |

**Observations:**
- The Go implementation has native ConPTY on Windows; PHP has none
- Go's `os/exec` integration is deeper; PHP's `proc_open` is more limited
- The shim startup cost (~5-50ms) is a PHP-specific trade-off
- FFI `waitpid` fast path achieves Go-level sub-ms exit detection

### 8.2 WhispPHP/whisp (PHP SSH Server)

| Feature | Whisp | candy-pty |
|---------|-------|-----------|
| PTY creation | `open()` via FFI | `PosixPtySystem::open()` via FFI |
| Window size | `setWindowSize()` ioctl | `MasterPty::resize()` via `SizeIoctl` |
| Termios | `getTermios()`/`setTermios()` via FFI | `TermiosFactory::open()` → FFI or stty |
| Controlling terminal | `setControllingTerminal()` | `ControllingTerminal::claim()` |
| Signal handling | Custom handlers for SIGHUP/SIGUSR2 | `SignalForwarder` with async/sync |
| Exit detection | `pcntl_waitpid()` | `ChildPollTrait::tryWaitpid()` + `proc_get_status` |

**Key difference:** Whisp is a complete SSH server application; candy-pty is a reusable PTY primitive library. Whisp's PTY handling is concrete and application-specific; candy-pty's is interface-driven and swappable.

### 8.3 php-tui/php-tui

No PTY management — this is a widget rendering library. Not directly comparable. The `Backend` interface abstraction in php-tui is architecturally similar to the `PtySystem` factory pattern in candy-pty (both provide platform-appropriate concrete implementations through a factory).

---

## 9. Windows ConPTY Opportunity

### 9.1 Current State

MATCHUPS.md line 82-85:
```markdown
<!--
TODO(leftover-rollout step 01.01): When a Windows ConPTY row is added
to this table, uncomment the row below and point it at the Windows plan.
| TBD (ConPTY backend) | **CandyPty.Windows** | `candy-pty/` | `sugarcraft/candy-pty` | `SugarCraft\Pty\Windows` | 🔴 | Windows ConPTY backend — tracked in plans/x-windows.md |
-->
```

### 9.2 ConPTY API Surface (from charmbracelet/x/x/pkg/conpty)

The Go ConPTY implementation uses:
- `NewConPTYSystem()` — factory returning `PtySystem`
- `conpty.AttachToProcess(process)` — attach to existing process
- `conpty.GetWindowSize()` — query dimensions
- `conpty.SetWindowSize()` — resize
- `conpty.ResizeTerminal()` — resize wrapper

ConPTY requires:
- Windows 10 1809+ (`Microsoft.Windows.SDK.Contracts` NuGet)
- `CreatePseudoConsole()` / `ClosePseudoConsole()` API
- PHP FFI bindings to `kernel32.dll`

### 9.3 SugarCraft ConPTY Integration Points

```php
// WindowsConPTYSystem would implement PtySystem
final class WindowsConPTYSystem implements PtySystem {
    public function open(int $cols = 80, int $rows = 24): PtyPair {
        // CreatePseudoConsole(..., &hPC)
        // Spawn with PseudoConsole flag
    }
}

// WindowsConPTYPair / WindowsMasterPty would implement MasterPty
// WindowsConPTYChild would implement Child
```

**Key challenges:**
1. PHP FFI to Win32 API (`kernel32.dll`) — different from libc FFI
2. ConPTY process spawning requires `PROC_THREAD_ATTRIBUTE_PSEUDOCONSOLE` in `STARTUPINFOEX`
3. Chocolatey's libvterm + ConPTY event loop integration with ReactPHP
4. Windows API calling convention differences (stdcall vs cdecl)

---

## 10. Test Coverage Analysis

### 10.1 Test Structure

```
tests/
  Contract/InterfacesTest.php         — contract instanceof checks
  Examples/SpawnBashExampleTest.php  — integration smoke test
  IoTest.php                          — master read/write round-trip
  LibcTest.php                        — FFI load + symbol availability
  Libc/OpenptyTest.php                — Darwin openpty availability
  Posix/
    ChildPollTraitStub.php            — trait consumer for testing
    ChildPollTraitTest.php            — tryWaitpid fallback logic
    ChildPollWaitpidTest.php          — WNOHANG exit detection
    MultiPumpTest.php                 — N:1 multiplexing
    PosixChildTest.php                — child lifecycle
    PosixMasterPtyTest.php            — open/read/write/size/resize
    PosixProcessTest.php               — non-PTY process lifecycle
    PosixPumpEofGraceTest.php         — EOF grace window
    PosixPumpFlushDeadlineTest.php   — post-exit flush
    PosixPumpIdleVsSigwinchTest.php   — idle vs resize callbacks
    PosixPumpKeepaliveTest.php        — keepalive callback
    PosixPumpTest.php                 — basic pump correctness
    PosixPtySystemTest.php            — platform detection
    PosixTermiosTest.php             — raw mode + restore
    SttyTermiosTest.php              — fallback path
  PumpOptionsTest.php                — value object defaults
  PtyPoolReactLoopTest.php           — ReactPHP integration
  ResizeTest.php                    — TIOCSWINSZ round-trip
  SizeIoctlQueryTest.php            — winsize query
  SignalForwarderReactLoopTest.php  — async signal + React event loop
  SignalForwarderTest.php           — sync signal handling
  SpawnProcTest.php                — controlling-terminal effects
  SpawnTest.php                    — basic spawn
  TermiosFactoryTest.php            — FFI → stty fallback
```

### 10.2 Notable Test Patterns

**SIGWINCH self-delivery smoke test** (ResizeTest.php):
```php
posix_kill(posix_getpid(), SIGWINCH); // verify handler fires
```

**EOF grace test** (PosixPumpEofGraceTest.php):
- Child doesn't exit → pump returns -1, caller force-closes → SIGHUP
- Child exits within grace window → pump returns child's exit code

**Controlling-terminal smoke test** (SpawnTest.php):
- `setsid` check: pid == sid for shim-spawned child
- SIGINT delivery: `/bin/sleep 10` + write `\x03` + wait → elapsed < 2s

---

## 11. Innovation Points

### 11.1 PHP-Native FFI Approach

candy-pty demonstrates a mature, production-grade approach to FFI in PHP 8.3+:
- Lazy-loading singleton with caching
- Separate FFI handles for libc vs libutil
- `SUGARCRAFT_LIBC` env override for musl/Alpine
- Opaque struct handling without layout assumptions
- Platform-branched cdef strings
- Graceful fallback to shell-out when FFI unavailable

### 11.2 Immutable + Fluent Configuration

`PumpOptions` and `Termios` follow the immutable value object pattern with `with*()` builders — each mutation returns a new instance. This enables safe sharing across concurrent contexts without defensive cloning.

### 11.3 Per-Feature cdef Symbol Addition

CALIBER_LEARNINGS.md documents:
> cdef symbol is added per PR rather than a maximalist all-of-termios block at PR1 so any cdef syntax error surfaces against a small surface, easier to bisect.

This incremental symbol approach prevents FFI load failures from breaking the entire library.

### 11.4 PID 0 / sid 0 Placeholder for Missing syscalls

`ControllingTerminal::claim()` uses PHP `null` for the `ioctl()` third argument when claiming ctty. The null pointer renders as unsigned long `0` (not a pointer-to-zero), which the kernel interprets correctly as "don't steal existing ctty".

### 11.5 Pump Recorder Tap Pattern

The pump integration with `SugarCraft\Core\Recorder` (for candy-vcr session recording) uses a null-guarded tap that adds zero overhead when recording is disabled — every call site is `if ($opts->recorder !== null) { $opts->recorder->recordOutput($bytes); }`.

---

## 12. Dependencies & Integration

### 12.1 Internal Dependencies

- `sugarcraft/candy-core` — Only required dependency. Provides `SugarCraft\Core\I18n\Lang` for `Lang::t()`, `SugarCraft\Core\Recorder` for pump tap.

### 12.2 Consumer Libraries

| Library | Usage |
|---------|-------|
| `candy-wish` | `InProcessTransport` uses `PosixPump` + `PosixPtySystem` for SSH sessions |
| `candy-vcr` | Session recording via `Recorder` tap in `PosixPump` |
| `candy-shell` | PTY spawning for interactive shell sessions |

### 12.3 Optional Extensions

| Extension | Purpose | Fallback |
|-----------|---------|----------|
| `ext-ffi` | Required | Throws `PtyException` at FFI load |
| `ext-pcntl` | Signal handlers | Polling via `proc_get_status` |
| `react/event-loop` | Async signal integration | `SignalForwarder::pcntlReady()` false |

---

## 13. File Inventory

```
candy-pty/
  bin/
    pty-shim.php                 # controlling-terminal shim (setsid + TIOCSCTTY + pcntl_exec)
  docs/
    CONCEPTS.md                 # architecture notes
  examples/
    multi-pump.php             # MultiPump demo (two shells tee'd to stdout)
    pump-output.php            # PosixPump usage demo
    resize-forwarding.php     # SignalForwarder + SIGWINCH demo
    spawn-bash.php             # basic PTY spawn + drain
  src/
    Contract/
      Child.php               # PTY child handle interface
      MasterPty.php             # master PTY contract (+ SIGTERM/SIGKILL/SIGINT constants)
      Process.php              # non-PTY process contract (+ stdoutBytes/stderrBytes)
      Pump.php                 # byte pump contract
      PtyPair.php              # master+slave pair contract
      PtySystem.php            # PTY system factory contract
      SlavePty.php              # slave PTY contract
      Termios.php              # terminal attributes contract
    Exception/
      ExpectEofException.php   # expect EOF before match
      ExpectTimeoutException.php
      UnsupportedPlatformException.php  # Windows / deferred backend
    Posix/
      ChildPollTrait.php      # shared waitpid/proc_get_status lifecycle (+ tryWaitpid FFI fast path)
      MultiPump.php            # N:1 stream_select multiplexer
      MultiPumpSession.php    # internal session record for MultiPump
      PosixChild.php           # PTY child implementation
      PosixMasterPty.php      # master fd + stream wrapper + resize/size/close
      PosixProcess.php        # non-PTY process with captured pipes + drain-in-wait
      PosixPump.php           # stream_select byte pump + EOF grace + keepalive + sigwinch
      PosixPtyPair.php        # simple master+slave holder
      PosixPtySystem.php      # PTY pair opener (openpty on Darwin, quartet on Linux)
      PosixSlavePty.php       # slave path + spawn wrapper
      PosixTermios.php       # FFI termios (opaque struct, cfmakeraw/tcgetattr/tcsetattr)
      SttyTermios.php        # stty shell-out fallback
    ControllingTerminal.php   # setsid() + ioctl(TIOCSCTTY, 0) claim sequence
    Expect.php                # pexpect-style fluent driver over MasterPty
    Lang.php                  # i18n wrapper extending SugarCraft\Core\I18n\Lang
    Libc.php                  # lazy FFI singleton for libc + libutil (openpty on Linux)
    Master.php                # readonly snapshot (fd + slavePath) — value object
    Pty.php                   # @deprecated legacy Pty facade
    PtyException.php          # base exception
    PtyPool.php               # bounded-concurrency PTY pair pool
    PtySystemFactory.php     # DI-friendly backend resolution
    PumpOptions.php           # immutable pump config value object + with*() builders
    SizeIoctl.php             # platform-aware TIOCSWINSZ/TIOCGWINSZ constants + Darwin stty fallback
    SignalForwarder.php       # SIGWINCH/SIGCHLD handlers with async/sync modes
    Spawn.php                  # @deprecated legacy spawn facade
    TermiosFactory.php       # PosixTermios → SttyTermios fallback factory
  tests/ (see §10)
  composer.json
  phpunit.xml
  CALIBER_LEARNINGS.md
  README.md
```

---

## 14. Summary Assessment

### 14.1 Strengths

1. **Clean Contract-Driven Architecture** — seven well-defined interfaces make the implementation replaceable and testable
2. **Mature FFI Patterns** — lazy loading, caching, fallback, platform branching, opaque struct handling all correctly implemented
3. **Sub-Millisecond Exit Detection** — `waitpid` FFI fast path avoids proc_get_status polling overhead
4. **Comprehensive Signal Handling** — async/sync modes, no-throw callbacks, self-delivery test patterns
5. **Immutable + Fluent Configuration** — `PumpOptions` and `Termios` are safe for concurrent use
6. **Polished Edge Cases** — macOS anchor fd, Darwin arm64 stty fallback, stdin EOF grace window, VEOF write
7. **Recorder Integration** — null-guarded tap enables candy-vcr without pump overhead
8. **Well-Documented Gotchas** — CALIBER_LEARNINGS.md captures every hard-won lesson

### 14.2 Limitations

1. **No Windows ConPTY** — reserved for v2 sidecar; `UnsupportedPlatformException` thrown
2. **FFI Required** — no pure-PHP fallback for core PTY operations (only termios has stty fallback)
3. **Controlling Terminal Shim Cost** — ~5-50ms PHP boot overhead makes it opt-in only
4. **No Platform-Adaptive Wait** — BSD/Solaris treated as POSIX but may have subtle differences
5. **Single stream_select Loop** — MultiPump multiplexes master→stdout only; stdin→master is caller's responsibility

### 14.3 Comparison Verdict

candy-pty is a **faithful, well-engineered PHP port** of charmbracelet/x/xpty. It matches the upstream architecture closely while adapting to PHP's FFI limitations and process model. The shim-based approach for controlling terminal is a pragmatic PHP-specific solution. The biggest gap is Windows ConPTY support, which is explicitly deferred to v2.

The library demonstrates idiomatic PHP 8.3+ patterns: readonly properties, constructor property promotion, fluent builders, FFI singleton with fallback, and package-private traits for shared lifecycle management. The test suite is thorough and the CALIBER_LEARNINGS.md provides invaluable institutional knowledge for future maintainers.

---

*Report generated: 2026-05-27*
*Analyst: code search + source analysis*
*Source files: 47 src files, 29 test files, 4 examples*
