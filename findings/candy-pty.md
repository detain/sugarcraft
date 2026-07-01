# candy-pty Code Review

## Summary

candy-pty is a PTY (pseudo-terminal) wrapper library ported from charmbracelet/mbubbletea, providing both blocking and async PTY operations. The library uses FFI to interface with libc system calls for terminal control, supports both POSIX and Windows platforms, and integrates with ReactPHP for async pump/expect patterns.

Approximately 40 source files were reviewed across the core PTY implementation, spawn subprocess handling, expect/pattern matching, signal forwarding, and async pump components.

**Overall Assessment:** The library contains several correctness issues around FD lifecycle management and async integration gaps. The FD reuse race in `PosixMasterPty::close()` is the most serious correctness bug. The missing pty-shim.php and incomplete async patterns are significant operational and architectural concerns.

---

## Critical Issues (file:line format)

### 1. FD Reuse Race in PosixMasterPty::close()
**File:** `src/Posix/PosixMasterPty.php:211-257`

When `stream()` is called, `fopen('php://fd/N')` creates a duplicate file descriptor referencing the same underlying kernel file description. When `fclose()` is called on the stream, it closes only the duplicate FD—the original `$this->fd` remains open. The subsequent call to `libc::close($this->fd)` then closes the original.

The danger: if another `open()` call recycles the FD number between the `fopen` and `fclose`, the code could close an unrelated file descriptor. The comment at lines 244-251 explicitly acknowledges this: "When we went through the stream path, our libc fd may have been recycled by an unrelated open()".

This is a classic FD leak / use-after-close scenario. Under load or in long-running PHP-FPM processes, fd exhaustion or mis-closed connections could result.

**Recommendation:** After `fclose($this->stream)`, use `libc::dup($this->fd)` to obtain a new reference to the original fd before closing it via `libc::close()`. Alternatively, track the fd's reference count manually or avoid the `php://fd/N` stream path entirely when `$this->fd` is the only reference.

---

### 2. stream_select Error Suppression Hides EINTR
**Files:**
- `src/Posix/PosixMasterPty.php:81`
- `src/Posix/PosixPump.php:126`
- `src/Posix/MultiPump.php:172`

`@stream_select(...)` silently returns `false` on interrupt (EINTR), causing the call to behave identically to a timeout. In `PosixPump`, the EINTR causes an immediate return from `pump()` at line 129, potentially losing data that arrived on the PTY fd during the interrupted window.

`MultiPump::run()` at lines 174-178 silently treats all errors (including EINTR) as a return of 0, which is indistinguishable from a timeout, causing repeated tight-poll loops.

**Recommendation:** Check for EINTR explicitly using `libc::errno() === EINTR` and retry `stream_select` rather than treating it as a fatal error or silent timeout. Extract a helper like `retry_interruptible_stream_select()` used consistently across all three call sites.

---

### 3. Missing pty-shim.php
**Files:**
- `src/Spawn.php:33`
- `src/Spawn.php:110`

`SHIM_RELATIVE = '/../bin/pty-shim.php'` is referenced but no such file exists in the repository. This shim is required for `controllingTerminal:true` mode to function. Without it, any spawn with `controllingTerminal: true` will fail at runtime.

**Recommendation:** Either commit the missing `bin/pty-shim.php` shim script to the repository, or remove the `controllingTerminal` option from `SpawnOptions` and mark it as unsupported until the shim is implemented.

---

### 4. MasterPty Interface Missing fd() Accessor
**File:** `src/Contract/MasterPty.php`

The `MasterPty` interface does not expose an `fd()` method, but `PosixMasterPty::fd()` at line 264 is public. Callers needing the raw fd must cast to `PosixMasterPty`, breaking interface abstraction:

```php
$fd = $pty instanceof PosixMasterPty ? $pty->fd() : throw new RuntimeException('fd not accessible');
```

**Recommendation:** Add `public function fd(): int` to `MasterPty` interface. Implement it on `Win Pty` as a no-op stub (Windows doesn't use fd-based I/O) so the interface remains platform-agnostic.

---

## High Severity Issues

### 1. Encoding Artifact in Expect.php
**File:** `src/Expect/Expect.php:389`

String contains `"charmbracelet/m泡泡/expect.Exp"` — the characters `泡泡` (Chinese for "bubbles") appear corrupted or unintentionally inserted. This likely originated from a copy-paste error during porting from the Go upstream `charmbracelet/mbubbletea`.

**Recommendation:** Replace with ASCII-only path `charmbracelet/mbubbletea/expect.Exp`.

---

### 2. SignalForwarder::$asyncEnabled Is Process-Wide Static Never Reset
**File:** `src/SignalForwarder.php:23`

`public static bool $asyncEnabled = false;` is a static property. In PHP-FPM or long-running server environments, this value persists across requests. If one request enables async mode and then the worker is recycled, the next request in that worker will retain `asyncEnabled = true` from the previous request's runtime state.

**Recommendation:** Replace with a non-static instance property on `SignalForwarder`. Pass the instance through the signal callback registration rather than relying on static state.

---

### 3. TermiosFactory::$loggedFallback Is Process-Wide Static
**File:** `src/Termios/TermiosFactory.php:19`

`private static bool $loggedFallback = false;` only logs the first fallback message per process lifetime. Subsequent fallback events are silently suppressed, making debugging difficult in long-running processes where only the first fallback instance is visible.

**Recommendation:** Use a counter instead of a boolean, or make `$loggedFallback` an instance property reset per `TermiosFactory` instance.

---

### 4. Deprecated Pty Class Is README Quickstart Example
**Files:**
- `src/Pty.php`
- `README.md`

The `Pty` class is marked `@deprecated` but is the primary quickstart example in `README.md`. New users are guided to use a deprecated API, creating a poor first experience and accelerating technical debt.

**Recommendation:** Update README.md to use `PosixPtySystem::open()` as the quickstart example, and either remove `Pty` entirely or clearly mark it as a compatibility shim pointing to the replacement.

---

## Medium Severity Issues

### 1. O_RDWR and oNoCtty() Duplicated in Pty.php and PosixPtySystem.php
**Files:**
- `src/Pty.php:30,68-71`
- `src/PosixPtySystem.php:17,127-130`

Both `Pty::O_RDWR`/`Pty::oNoCtty()` and `PosixPtySystem::O_RDWR`/`PosixPtySystem::oNoCtty()` define the same constants and helper methods independently. This duplication creates a maintenance hazard where changes to one may not propagate to the other.

**Recommendation:** Extract shared constants into a `PtyFlags` final class or trait. Both `Pty` and `PosixPtySystem` should import from the shared location.

---

### 2. Buffer Trim Logic Duplicated in Expect.php
**Files:**
- `src/Expect/Expect.php:295-299`
- `src/Expect/Expect.php:377-381`
- `src/Expect/Expect.php:428-430`

Buffer trimming (stripping leading/trailing whitespace) is repeated in `expectAny()`, `expectPattern()`, and `expectEof()` with identical logic. Any change to the trim strategy requires updating three locations.

**Recommendation:** Extract to a private `trimBuffer(string $buffer): string` method and call it from all three locations.

---

### 3. Tight Polling Loop in ChildPollTrait::wait()
**File:** `src/ChildPollTrait.php`

`usleep(10_000)` (10ms) in a busy-wait polling loop wastes CPU and cannot be interrupted by signals. This pattern appears in a context where it may cause high CPU utilization during subprocess wait.

**Recommendation:** Replace with event-driven mechanisms (signals, stream_select) rather than busy-waiting.

---

### 4. stream_select Timeout Calculation Can Produce $usec >= 1_000_000
**File:** `src/Posix/PosixMasterPty.php:77-78`

```php
$sec = (int) ($timeout / 1_000_000);
$usec = $timeout - $sec * 1_000_000;
```

When `$timeout` is not aligned to microseconds, this can produce `$usec >= 1_000_000`, which violates the stream_select contract where `tv_usec` must be `< 1_000_000`. While PHP may normalize this, it is unreliable behavior.

**Recommendation:** Use `divMod` semantics: `$sec = intdiv($timeout, 1_000_000); $usec = $timeout % 1_000_000;`

---

### 5. MultiPumpSession Uses Mutable Public Properties
**File:** `src/Posix/MultiPump.php`

`MultiPumpSession` has public properties (`id`, `pty`, `running`) that are modified directly by `MultiPump` during session management. This breaks encapsulation and makes it difficult to reason about session state transitions.

**Recommendation:** Encapsulate session state behind a `Session` interface with controlled mutators. Avoid public property mutation from external classes.

---

## Low Severity Issues

### 1. @preg_match Error Suppression in Expect.php
**File:** `src/Expect/Expect.php:330`

`@preg_match(...)` suppresses regex compilation errors. If a bad pattern is passed, the error is silently swallowed and the function returns `false` without explanation. This makes debugging difficult.

**Recommendation:** Validate regex before passing to `preg_match` or use `preg_match($pattern, $subject)` directly and handle errors via `preg_last_error()`.

---

### 2. Darwin Resize via stty Subprocess on Every Call
**File:** `src/Termios/Darwin/SizeIoctl.php:sttySetSize`

`proc_open(['stty', ...])` spawns a subprocess for every Darwin resize operation. While necessary as a workaround for Darwin's missing `TIOCSWINSZ` FFI definition, this is expensive for frequent resizes.

**Recommendation:** Cache resize attempts and rate-limit stty subprocess spawning. Consider using a single persistent stty process for batched resize operations.

---

### 3. No __toString/Serialization on Value Objects
**Files:** Various value objects (`Size`, `WinSize`, etc.)

Value objects like `Size` and `WinSize` lack `__toString()` or `JsonSerializable` implementations, making debugging and logging verbose.

**Recommendation:** Implement `__toString()` on all value objects for ergonomic debugging output.

---

### 4. ControllingTerminal::claim Passes PHP null to ioctl FFI
**File:** `src/Posix/ControllingTerminal.php:56`

```php
return ioctl($this->fd, $request, null);
```

Passing `null` to ioctl is platform-dependent undefined behavior. On some platforms it may zero out the structure or leave it uninitialized, causing subtle portability issues.

**Recommendation:** Explicitly pass a pointer to a zeroed struct rather than `null`. Use a properly-sized native C struct via FFI.

---

## Missing Features

### 1. No ReactPHP Async Stream Interfaces for PTY
The library does not implement `React\Stream\ReadableStreamInterface` or `React\Stream\WritableStreamInterface` for PTY entities. This prevents integration with the broader ReactPHP ecosystem (piping, filtering, buffering).

---

### 2. No Multi-Pattern Regex Expect (expectAnyPattern)
Unlike the Go upstream `expect.AnyPattern()`, the PHP port only supports single-pattern `expect()` calls. Multi-pattern matching with per-pattern actions requires manual loop management.

---

### 3. No Built-in ANSI Escape Sequence Parsing
The library depends on external `candy-ansi` for ANSI parsing, but `candy-pty` itself provides no built-in escape sequence handling. This creates a hard dependency chain for common use cases.

---

### 4. No Way to Get Raw FD from MasterPty Without Casting
As noted in Critical Issue #4, the `MasterPty` interface does not expose the raw fd, forcing callers to cast to `PosixMasterPty` to access it.

---

### 5. No Async Acquire/Release on PtyPool
`PtyPool::acquire()` and `PtyPool::release()` are documented as async methods but do not yield to the event loop during contention. Under load, this can block the event loop.

---

### 6. Pty Shim File Missing from Repository
As noted in Critical Issue #3, `bin/pty-shim.php` is referenced but not present, blocking `controllingTerminal:true` functionality.

---

## Duplicated Logic / Refactoring Opportunities

### 1. O_RDWR and oNoCtty() Duplication
**Locations:** `src/Pty.php:30,68-71` and `src/PosixPtySystem.php:17,127-130`

Both files define `O_RDWR` constant and `oNoCtty()` method independently. See Medium Issue #1.

---

### 2. Buffer Trimming Duplication
**Locations:** `src/Expect/Expect.php:295-299, 377-381, 428-430`

Identical buffer-trim logic repeated in three expect methods. See Medium Issue #2.

---

### 3. $libc = Libc::lib() Pattern Repeated Throughout
The pattern `$libc = Libc::lib()` appears in nearly every file that uses FFI calls. This could be centralized in a trait or base class:

```php
trait LibcAccess {
    protected static function libc(): Libc {
        return Libc::lib();
    }
}
```

This would reduce boilerplate and ensure consistent singleton access across all classes.

---

### 4. PtyPool Active/Available Session Split
**File:** `src/PtyPool.php`

`$this->activeSessions` and `$this->availableSessions` are stored as parallel arrays with identical structure. These should be unified into a single structured collection with state tracking.

---

## Compatibility Issues

### 1. ext-ffi Required but Disabled by Default
`ext-ffi` is required for FFI calls but is disabled by default in many PHP installations (PHP 8.2+ often ship with FFI disabled for security). The README does not prominently warn users to enable FFI in `php.ini`.

---

### 2. /dev/ptmx Access Required
The library requires access to `/dev/ptmx` which may not be available in certain container environments (e.g., some Docker setups with `--device-read-only` or minimal container images). No graceful error is produced when access is denied.

---

### 3. Darwin arm64 ioctl ABI Mismatch
Darwin arm64 uses a different ioctl ABI for `TIOCSWINSZ`/`TIOCGWINSZ`, handled via stty subprocess fallback. This fallback works but is slow and not documented as a known limitation in user-facing documentation.

---

### 4. Termios Struct Size Assumption
**File:** `src/Termios/TermiosFactory.php`

Termios struct size is assumed to be 80 bytes:

```php
$termios = FFI::new('struct winsize');
```

This assumption may not hold across platforms (Linux vs. BSD vs. macOS vs. musl).

---

### 5. musl/Alpine Uses Different Libc Paths
**File:** `src/Libc.php`

The library uses hardcoded glibc paths in some fallbacks. Alpine Linux and other musl-based systems use different library paths, which may cause FFI initialization failures on those platforms.

---

## Async Pattern Improvements

### 1. PosixPump Uses Blocking stream_select Loop
**File:** `src/Posix/PosixPump.php`

`PosixPump::pump()` uses a blocking `stream_select` loop that cannot integrate with ReactPHP's event loop. When used in a ReactPHP application, this blocks the entire event loop during I/O wait.

**Recommendation:** Implement a ReactPHP-compatible version using `react/stream` interfaces and `Loop::addReadStream()`.

---

### 2. No react/stream ReadableStreamInterface/WritableStreamInterface
As noted in Missing Features #1, the PTY classes do not implement ReactPHP's stream interfaces, preventing standard piping and stream composition.

---

### 3. SignalForwarder Uses Static Callbacks
**File:** `src/SignalForwarder.php`

Signal handlers use static callbacks that cannot be unregistered per-instance. This prevents multiple `SignalForwarder` instances from coexisting independently, and prevents cleanup without process termination.

---

### 4. PosixChild::wait() Blocks the Event Loop
**File:** `src/Posix/PosixChild.php`

`PosixChild::wait()` is a blocking call with no async equivalent. In an async application, calling `wait()` will block the entire event loop until the child exits.

---

### 5. MultiPump::run() Blocks with No Async Variant
**File:** `src/Posix/MultiPump.php`

`MultiPump::run()` is a blocking loop with no async variant. There is no `MultiPump::runAsync()` or ReactPHP-compatible alternative.

---

## Recommendations Summary (Priority Table)

| Priority | Severity | Issue | Location | Recommendation |
|----------|----------|-------|----------|----------------|
| P0 | Critical | FD reuse race in close() | `src/Posix/PosixMasterPty.php:211-257` | Use `libc::dup()` before `fclose()`, or avoid `php://fd/N` stream path when fd may be recycled |
| P0 | Critical | EINTR hidden by @ suppression | `src/Posix/PosixMasterPty.php:81`, `PosixPump.php:126`, `MultiPump.php:172` | Check `libc::errno() === EINTR` explicitly, retry stream_select on interrupt |
| P0 | Critical | Missing pty-shim.php | `src/Spawn.php:33,110` | Commit missing `bin/pty-shim.php` or disable `controllingTerminal` option |
| P0 | Critical | MasterPty interface missing fd() | `src/Contract/MasterPty.php` | Add `fd(): int` to interface; stub on Win Pty |
| P1 | High | Encoding artifact (Chinese chars) | `src/Expect/Expect.php:389` | Replace `泡泡` with `bubbletea` (ASCII) |
| P1 | High | Static $asyncEnabled persists across requests | `src/SignalForwarder.php:23` | Make non-static instance property; pass instance to signal callbacks |
| P1 | High | Static $loggedFallback logs only once | `src/Termios/TermiosFactory.php:19` | Use counter or instance property instead of boolean |
| P1 | High | Deprecated Pty is README quickstart | `README.md`, `src/Pty.php` | Update README to `PosixPtySystem::open()`; deprecate or remove Pty class |
| P2 | Medium | O_RDWR/oNoCtty duplicated | `src/Pty.php`, `src/PosixPtySystem.php` | Extract shared constants to `PtyFlags` trait or class |
| P2 | Medium | Buffer trim logic duplicated | `src/Expect/Expect.php:295-299,377-381,428-430` | Extract `trimBuffer()` private method |
| P2 | Medium | Tight polling loop usleep(10_000) | `src/ChildPollTrait.php` | Replace with event-driven mechanisms (signals, stream_select) |
| P2 | Medium | stream_select timeout overflow | `src/Posix/PosixMasterPty.php:77-78` | Use `intdiv`/`%` for proper division instead of manual calculation |
| P2 | Medium | MultiPumpSession public properties | `src/Posix/MultiPump.php` | Encapsulate session state behind controlled mutators |
| P3 | Low | @preg_match suppresses errors | `src/Expect/Expect.php:330` | Remove `@`; use `preg_last_error()` to check for issues |
| P3 | Low | Darwin stty subprocess per resize | `src/Termios/Darwin/SizeIoctl.php` | Rate-limit and cache stty subprocess spawning |
| P3 | Low | No __toString on value objects | Various value objects | Implement `__toString()` for debugging ergonomics |
| P3 | Low | ioctl called with null | `src/Posix/ControllingTerminal.php:56` | Pass pointer to zeroed struct instead of null |
| P4 | Enhancement | No ReactPHP stream interfaces | PTY classes | Implement ReadableStreamInterface/WritableStreamInterface |
| P4 | Enhancement | No expectAnyPattern | `src/Expect/Expect.php` | Add multi-pattern regex matching with per-pattern callbacks |
| P4 | Enhancement | No ANSI parsing built-in | N/A | Document dependency on `candy-ansi` or add parsing integration |
| P4 | Enhancement | PtyPool acquire/release blocks | `src/PtyPool.php` | Add async variants using ReactPHP promises |
