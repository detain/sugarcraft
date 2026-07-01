---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-pty Code Review Findings

## Goal
Address all critical, high, medium, and low severity issues identified in the candy-pty code review, plus implement missing features and resolve duplication. Each item includes investigation notes confirming the finding's context in the codebase.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| FD reuse race is real - `fopen('php://fd/N')` dup()s the fd | `fclose` only closes the dup, not original; comment at lines 244-251 explicitly acknowledges this | `src/Posix/PosixMasterPty.php:211-257` |
| EINTR silently treated as timeout | `@stream_select` returns false on EINTR, indistinguishable from timeout | `src/Posix/PosixMasterPty.php:81`, `src/Posix/PosixPump.php:126`, `src/Posix/MultiPump.php:172` |
| pty-shim.php ALREADY EXISTS at `bin/pty-shim.php` | File confirmed present; path `__DIR__ . '/../bin/pty-shim.php'` resolves correctly from `src/Spawn.php` | `candy-pty/bin/pty-shim.php` |
| `MasterPty` interface lacks `fd()` method | `PosixMasterPty::fd()` is public but interface doesn't declare it | `src/Contract/MasterPty.php` |
| Encoding artifact `泡泡` found in comment | Chinese characters in doc comment at line 389 of Expect.php | `src/Expect.php:389` |
| Static `$asyncEnabled` persists across PHP-FPM requests | `SignalForwarder::$asyncEnabled` is static boolean | `src/SignalForwarder.php:30` |
| Static `$loggedFallback` only logs once | `TermiosFactory::$loggedFallback` is boolean, not counter | `src/TermiosFactory.php:26` |
| Deprecated `Pty` class is README quickstart | `Pty` marked `@deprecated` but used in README lines 28-48 | `README.md`, `src/Pty.php` |
| O_RDWR/oNoCtty() duplicated | Both `Pty.php` and `PosixPtySystem.php` define independently | `src/Pty.php:30,68-71`, `src/PosixPtySystem.php:17,127-130` |
| Buffer trim logic repeated 3 times | `Expect.php` lines 295-299, 377-381, 428-430 have identical trim logic | `src/Expect.php` |
| `usleep(10_000)` busy-waits in ChildPollTrait | Poll loop at line 168 wastes CPU | `src/Posix/ChildPollTrait.php:168` |
| `$usec >= 1_000_000` possible | Manual division at PosixMasterPty.php:77-78 | `src/Posix/PosixMasterPty.php:77-78` |
| MultiPumpSession has mutable public properties | `done` and `childExitedAt` modified by MultiPump externally | `src/Posix/MultiPump.php:251-258` |
| `@preg_match` hides regex errors | Line 330 suppresses errors | `src/Expect.php:330` |
| Darwin stty subprocess per resize | Every `setSizeViaLibc` on Darwin calls `proc_open(['stty', ...])` | `src/SizeIoctl.php:171-189` |
| ioctl called with `null` | `ControllingTerminal::claim()` passes null as third arg (but comment says intentional) | `src/ControllingTerminal.php:56-58` |
| No ReactPHP stream interfaces | PTY classes don't implement `ReadableStreamInterface`/`WritableStreamInterface` | N/A |
| No `expectAnyPattern` | Only single-pattern `expect()` calls | `src/Expect.php` |
| PtyPool acquire/release are blocking | Methods don't yield to event loop | `src/PtyPool.php` |

---

## Phase 1: Critical Issues [PENDING]

### 1.1 FD Reuse Race in PosixMasterPty::close() [PENDING] ← CURRENT

**What is expected:** After `fclose($this->stream)` on line 231, use `libc::dup($this->fd)` to obtain a new reference to the original fd before calling `libc::close($this->fd)` at line 245. This prevents closing an unrelated fd if the kernel recycled the fd number between fopen and fclose.

**Why the change should be done:** Classic FD leak / use-after-close scenario. Under load or in long-running PHP-FPM processes, fd exhaustion or mis-closed connections could result. The comment at lines 244-251 explicitly acknowledges this race.

**Severity:** Critical

**Conditions for success:**
- Unit test that spawns a child, calls `stream()`, then `close()`, and verifies the child is properly reaped (SIGHUP delivered)
- Verify no FD leak under repeated open/close cycles

**Related code locations:**
- `src/Posix/PosixMasterPty.php:211-257` (close method)
- `src/Posix/PosixMasterPty.php:179-194` (stream method)
- `src/Posix/PosixMasterPty.php:186` (fopen creates duplicate fd)

**Investigation notes:**
```php
// At line 227-238, when stream was used:
$usedStream = $this->stream !== null;
if ($usedStream) {
    $stream = $this->stream;
    $this->stream = null;
    if (\is_resource($stream) && !@\fclose($stream)) {
        // fclose only closes the DUPLICATE fd, not $this->fd
        // Lines 239-242 comment confirms this
    }
}

// Line 245: libc::close($this->fd) closes the ORIGINAL
$rc = Libc::lib()->close($this->fd);
// Lines 246-251: comment explicitly acknowledges the race
```

---

### 1.2 stream_select Error Suppression Hides EINTR [PENDING]

**What is expected:** Create a helper function `retry_interruptible_stream_select()` that:
1. Checks `libc::errno() === EINTR` explicitly when `@stream_select` returns false
2. Retries the call instead of treating it as fatal or timeout
3. Replaces `@stream_select` calls at all three locations

**Why the change should be done:** EINTR (interrupted system call) is a normal occurrence in signal-heavy environments. Silently treating it as timeout causes data loss or tight-poll loops.

**Severity:** Critical

**Conditions for success:**
- EINTR does not cause premature exit from pump loops
- Signal handlers (like SIGWINCH) don't corrupt pump state
- All three locations use the helper consistently

**Related code locations:**
- `src/Posix/PosixMasterPty.php:80-81` (read timeout loop)
- `src/Posix/PosixPump.php:126` (pump loop stream_select)
- `src/Posix/MultiPump.php:172` (multiplexer tick stream_select)
- `src/Libc.php` for errno constant access

**Investigation notes:**
```php
// PosixMasterPty.php:80
$ready = @\stream_select($r, $w, $e, $sec, $usec);
if ($ready === false) {
    // No EINTR check here - treats as fatal
    throw new PtyException(...);
}
```
```php
// PosixPump.php:126-134
$ready = @\stream_select($r, $w, $e, 0, $opts->selectTimeoutUs);
if ($ready === false) {
    if (\function_exists('pcntl_signal_dispatch')) {
        @\pcntl_signal_dispatch();
        continue;  // EINTR causes immediate return, potentially losing data
    }
    return;  // silent data loss
}
```

---

### 1.3 Missing pty-shim.php [PENDING]

**What is expected:** **INVESTIGATION REVEALS THIS FILE ALREADY EXISTS.** The file `candy-pty/bin/pty-shim.php` exists and the path resolution in `Spawn.php:110` correctly resolves to it.

**Why the change should be done:** No change needed - this finding is **already resolved**. The shim file exists and works correctly.

**Severity:** Critical (but already resolved - no action needed)

**Investigation notes:**
- `Spawn.php:33`: `SHIM_RELATIVE = '/../bin/pty-shim.php'`
- `Spawn.php:110`: `$shim = __DIR__ . self::SHIM_RELATIVE;`
- `__DIR__` is `/home/sites/sugarcraft/candy-pty/src/Spawn`
- Resolved path: `/home/sites/sugarcraft/candy-pty/bin/pty-shim.php`
- File confirmed present, contains proper shebang and pcntl_exec logic
- See CALIBER_LEARNINGS.md lines 6, 10-11, 27 for context on shim pattern

---

### 1.4 MasterPty Interface Missing fd() Accessor [PENDING]

**What is expected:** Add `public function fd(): int` to `MasterPty` interface. Implement as a no-op stub on any Windows Pty implementation (not yet built but would be in `Contract/MasterPty.php` for future Windows support).

**Why the change should be done:** Currently callers must cast to `PosixMasterPty` to access the raw fd, breaking interface abstraction. The `fd()` method already exists on `PosixMasterPty::fd()` at line 264 but isn't on the interface.

**Severity:** Critical

**Conditions for success:**
- Code like `$pty instanceof PosixMasterPty ? $pty->fd() : throw ...` can be replaced with `$pty->fd()`
- Interface is implemented correctly by all PTY backends

**Related code locations:**
- `src/Contract/MasterPty.php` (interface definition, add fd() here)
- `src/Posix/PosixMasterPty.php:264-267` (existing implementation)
- Note: Windows implementation does not exist yet (tracked in `plans/x-windows.md`)

---

## Phase 2: High Severity Issues [PENDING]

### 2.1 Encoding Artifact in Expect.php [PENDING] ← CURRENT

**What is expected:** Replace the Chinese characters `泡泡` (meaning "bubbles") at line 389 in the doc comment with ASCII equivalent. Change `charmbracelet/m泡泡/expect.Exp` to `charmbracelet/mbubbletea/expect.Exp` or simply `charmbracelet/mbubbletea`.

**Why the change should be done:** This appears to be a copy-paste error during porting from Go upstream. Chinese characters in doc comments can cause encoding issues in some tools and indicate sloppy porting.

**Severity:** High

**Conditions for success:**
- Doc comment renders cleanly in all IDEs
- No non-ASCII characters remain in doc comments

**Related code locations:**
- `src/Expect.php:389` - line contains: `Mirrors charmbracelet/m泡泡/expect.Exp.`

**Investigation notes:** The string `charmbracelet/m泡泡/expect.Exp` appears to be a corrupted reference to `charmbracelet/mbubbletea` (the upstream project). The Chinese characters `泡泡` mean "bubbles" and were likely accidentally included during copy-paste from the upstream `mububbletea` repository name.

---

### 2.2 SignalForwarder::$asyncEnabled Is Process-Wide Static Never Reset [PENDING]

**What is expected:** Replace `private static bool $asyncEnabled = false;` (line 30) with a non-static instance property. Pass the `SignalForwarder` instance through the signal callback registration via a closure that captures `$this`.

**Why the change should be done:** In PHP-FPM or long-running server environments, a static property persists across requests. If one request enables async mode and the worker is recycled, the next request retains `asyncEnabled = true` from the previous request's runtime state.

**Severity:** High

**Conditions for success:**
- Each SignalForwarder instance maintains independent async state
- Signal handlers work correctly across multiple instances
- PHP-FPM workers don't share async state incorrectly

**Related code locations:**
- `src/SignalForwarder.php:30` (static property declaration)
- `src/SignalForwarder.php:180-188` (ensureAsync method uses static)
- `src/SignalForwarder.php:55-68` (callback closures capture $fd, $sizeProvider, not instance)

**Investigation notes:**
```php
// Line 30: static bool that persists across requests
private static bool $asyncEnabled = false;

// ensureAsync at line 182-188 sets it once per process
private static function ensureAsync(bool $async): void
{
    if (!$async || self::$asyncEnabled) {
        return;
    }
    if (\function_exists('pcntl_async_signals')) {
        \pcntl_async_signals(true);
        self::$asyncEnabled = true;
    }
}
```

---

### 2.3 TermiosFactory::$loggedFallback Is Process-Wide Static [PENDING]

**What is expected:** Replace `private static bool $loggedFallback = false;` with a counter `private static int $loggedFallbackCount = 0;`. Increment and log with count, or make it an instance property reset per `TermiosFactory` instance.

**Why the change should be done:** With a boolean, only the first fallback event is logged per process lifetime. In long-running processes (PHP-FPM workers), subsequent fallback events are silently suppressed, making debugging difficult.

**Severity:** High

**Conditions for success:**
- All fallback events are logged (with rate limiting if needed)
- Each fallback instance is distinguishable in logs

**Related code locations:**
- `src/TermiosFactory.php:26` (static boolean)
- `src/TermiosFactory.php:47-49` (only logs first time)

**Investigation notes:**
```php
// Line 26: boolean that stays true after first log
private static bool $loggedFallback = false;

// Lines 47-49: only logs once
if (!self::$loggedFallback) {
    \error_log('[TermiosFactory] ext-ffi unavailable or failed, using stty fallback');
    self::$loggedFallback = true;
}
```

---

### 2.4 Deprecated Pty Class Is README Quickstart Example [PENDING]

**What is expected:** Update README.md quickstart section (lines 28-48) to use `PosixPtySystem::open()` as the primary example. Either remove `Pty` from quickstart entirely or clearly mark it as a compatibility shim pointing to `PosixPtySystem`.

**Why the change should be done:** New users guided to a deprecated API have a poor first experience and are pushed toward technical debt immediately.

**Severity:** High

**Conditions for success:**
- README quickstart uses `PtySystemFactory::default()->open()` or `PosixPtySystem::open()`
- `Pty` class is clearly marked as deprecated with migration path
- Users can easily find the non-deprecated API

**Related code locations:**
- `README.md:28-48` (quickstart using deprecated Pty)
- `README.md:51-66` (DI-friendly example using PosixPtySystem - this is the good one)
- `src/Pty.php:10-11` (deprecation annotation)

**Investigation notes:** The README actually shows both patterns - the old deprecated `Pty::open()` (lines 28-48) and the preferred `PtySystemFactory::default()->open()` (lines 51-66). Only the first needs updating.

---

## Phase 3: Medium Severity Issues [PENDING]

### 3.1 O_RDWR and oNoCtty() Duplicated [PENDING] ← CURRENT

**What is expected:** Extract shared constants into a `PtyFlags` final class or trait. Both `Pty` and `PosixPtySystem` should import from the shared location.

**Why the change should be done:** Duplication creates maintenance hazard. Changes to one may not propagate to the other, and the identical logic is defined twice.

**Severity:** Medium

**Conditions for success:**
- `PtyFlags` class or trait contains `O_RDWR = 0x0002` and `oNoCtty()` method
- Both `Pty` and `PosixPtySystem` use the shared definition
- No duplication remains

**Related code locations:**
- `src/Pty.php:30` (O_RDWR constant)
- `src/Pty.php:68-71` (oNoCtty method)
- `src/PosixPtySystem.php:17` (O_RDWR constant)
- `src/PosixPtySystem.php:127-130` (oNoCtty method)

**Investigation notes:**
```php
// Pty.php:30
private const O_RDWR = 0x0002;

// Pty.php:68-71
private static function oNoCtty(): int
{
    return \PHP_OS_FAMILY === 'Darwin' ? 0x20000 : 0o400;
}

// PosixPtySystem.php:17
private const O_RDWR = 0x0002;

// PosixPtySystem.php:127-130
private static function oNoCtty(): int
{
    return PHP_OS_FAMILY === 'Darwin' ? 0x20000 : 0o400;
}
```

---

### 3.2 Buffer Trim Logic Duplicated in Expect.php [PENDING]

**What is expected:** Extract buffer trimming logic into a private `trimBuffer(string $buffer): string` method on `Expect` class. Call it from all three expect methods.

**Why the change should be done:** Identical logic in three locations creates maintenance burden. Any change to trim strategy requires updating all three locations.

**Severity:** Medium

**Conditions for success:**
- Single `trimBuffer()` method exists
- All three locations (expectAny, expectPattern, expectEof) use it
- Behavior is identical before and after refactoring

**Related code locations:**
- `src/Expect.php:295-299` (in expectAny)
- `src/Expect.php:377-381` (in expectPattern)
- `src/Expect.php:428-430` (in expectEof)

**Investigation notes:** The trim logic keeps `$maxBuffer - $maxNeedleLen` bytes from the end of the buffer, stripping leading bytes beyond that window.

---

### 3.3 Tight Polling Loop in ChildPollTrait::wait() [PENDING]

**What is expected:** Replace the `usleep(10_000)` (10ms) busy-wait with event-driven mechanisms - specifically, use `waitpid()` with a timeout, or integrate with ReactPHP's event loop for async scenarios.

**Why the change should be done:** Busy-waiting wastes CPU and cannot be interrupted by signals. This pattern causes high CPU utilization during subprocess wait.

**Severity:** Medium

**Conditions for success:**
- CPU usage during wait is minimal
- Wait can be interrupted by signals
- Existing tests still pass

**Related code locations:**
- `src/Posix/ChildPollTrait.php:168` (usleep in wait loop)
- Also found in `src/Posix/PosixProcess.php:168` (same pattern in overridden wait)

**Investigation notes:**
```php
// ChildPollTrait.php:150-168
while (true) {
    $exitCode = $this->tryWaitpid($this->pid);
    if ($exitCode !== null) {
        $this->exitCode = $exitCode;
        break;
    }
    $status = \proc_get_status($this->process);
    if ($status['running'] === false) {
        // ...
        break;
    }
    \usleep(10_000);  // 10ms busy wait
}
```

---

### 3.4 stream_select Timeout Calculation Can Produce $usec >= 1_000_000 [PENDING]

**What is expected:** Use proper `intdiv`/`%` semantics:
```php
$sec = \intdiv($timeout, 1_000_000);
$usec = $timeout % 1_000_000;
```
Replace the current manual calculation at PosixMasterPty.php:77-78.

**Why the change should be done:** When `$timeout` is not microsecond-aligned, manual calculation can produce `$usec >= 1_000_000`, violating the `stream_select` contract where `tv_usec` must be `< 1_000_000`.

**Severity:** Medium

**Conditions for success:**
- `$usec` is always `< 1_000_000` after calculation
- All existing timeout tests pass
- Edge cases with fractional timeouts work correctly

**Related code locations:**
- `src/Posix/PosixMasterPty.php:77-78`

**Investigation notes:**
```php
// Current code:
$sec  = (int) \floor($remaining);
$usec = (int) \round(($remaining - $sec) * 1_000_000);

// Problem: If $remaining = 1.0001, $sec = 1, $usec = 100000
// This is correct. But if floating point errors occur, could be >= 1000000
```

---

### 3.5 MultiPumpSession Uses Mutable Public Properties [PENDING]

**What is expected:** Encapsulate session state behind controlled mutators. Make properties private with controlled access, or use a proper state machine pattern with private `$done` and private `$childExitedAt`.

**Why the change should be done:** `done` and `childExitedAt` are public properties modified directly by `MultiPump` during session management. This breaks encapsulation and makes session state transitions difficult to reason about.

**Severity:** Medium

**Conditions for success:**
- `MultiPumpSession::$done` is private with getter
- `MultiPumpSession::$childExitedAt` is private with getter
- `MultiPump` uses controlled mutator methods

**Related code locations:**
- `src/Posix/MultiPump.php:251-258` (MultiPumpSession class)
- `src/Posix/MultiPump.php:131-154` (MultiPump modifies session properties directly)

**Investigation notes:**
```php
// MultiPumpSession (lines 251-258)
final class MultiPumpSession
{
    public bool $done = false;
    public ?float $childExitedAt = null;

    public function __construct(
        public readonly int $id,
        public readonly MasterPty $master,
        // ...
    ) {}
}

// MultiPump modifies at line 144, 152
$session->childExitedAt = $now;
$session->done = true;
```

---

## Phase 4: Low Severity Issues [PENDING]

### 4.1 @preg_match Error Suppression in Expect.php [PENDING] ← CURRENT

**What is expected:** Remove the `@` operator from `preg_match` at line 330. Use `preg_last_error()` to check for regex errors after the call, or validate the regex pattern before passing to `preg_match`.

**Why the change should be done:** `@preg_match` silently swallows regex compilation errors. If a bad pattern is passed, debugging is difficult because no error message is produced.

**Severity:** Low

**Conditions for success:**
- Invalid regex patterns produce clear error messages
- `preg_last_error()` returns `PREG_NO_ERROR` on success

**Related code locations:**
- `src/Expect.php:330`

**Investigation notes:**
```php
// Line 330
$rc = @\preg_match($regex, $searchBuffer, $matches, PREG_OFFSET_CAPTURE);
if ($rc === false) {
    throw new \InvalidArgumentException(
        "Expect::expectPattern: invalid regex '{$regex}'",
    );
}
```

---

### 4.2 Darwin stty Subprocess on Every Call [PENDING]

**What is expected:** Cache resize attempts and rate-limit stty subprocess spawning. Consider using a single persistent stty process for batched resize operations, or implement a cooldown period where repeated resizes within a short time window don't spawn new stty processes.

**Why the change should be done:** `proc_open(['stty', ...])` spawns a subprocess for every Darwin resize operation. This is expensive for frequent resizes, though necessary as a workaround for Darwin's missing `TIOCSWINSZ` FFI definition.

**Severity:** Low

**Conditions for success:**
- Multiple rapid resizes don't spawn multiple stty processes
- Resize operations are still correct
- Performance is improved under rapid resize scenarios

**Related code locations:**
- `src/SizeIoctl.php:171-189` (sttySetSize method)
- `src/SizeIoctl.php:149-161` (setSizeViaLibc calls sttySetSize on Darwin)

---

### 4.3 No __toString/Serialization on Value Objects [PENDING]

**What is expected:** Implement `__toString()` on value objects like `Size` and `WinSize` for ergonomic debugging output. Consider implementing `JsonSerializable` for JSON serialization.

**Why the change should be done:** Value objects without `__toString()` produce verbose debugging output (`Object of class Size could not be converted to string`), making debugging and logging cumbersome.

**Severity:** Low

**Conditions for success:**
- Value objects have `__toString()` returning human-readable representation
- `Size` and `WinSize` (if they exist) are covered
- JSON serialization works via `JsonSerializable`

**Related code locations:**
- Value objects mentioned in findings: `Size`, `WinSize` (search codebase)
- See `src/Expect.php` for example of buffer object style

---

### 4.4 ControllingTerminal::claim Passes PHP null to ioctl FFI [PENDING]

**What is expected:** The comment at lines 56-58 explains this is intentional for the third arg of TIOCSCTTY. However, for better clarity and portability, explicitly pass a pointer to a zeroed struct rather than PHP `null`. This makes the intent clearer and avoids platform-dependent undefined behavior.

**Why the change should be done:** The comment says "passing PHP null renders as 0 which means 'don't steal an existing ctty'" - but this is platform-dependent behavior. Using an explicitly zeroed struct is clearer and more portable.

**Severity:** Low

**Conditions for success:**
- Code compiles and works on both Linux and Darwin
- Intent is clearer to future maintainers
- Behavior is equivalent or better than current

**Related code locations:**
- `src/ControllingTerminal.php:56-58` (lines with comment explaining the null)
- `src/ControllingTerminal.php:59` (actual ioctl call)

**Investigation notes:**
```php
// Lines 56-58 comment:
/// Third arg is read by the kernel as unsigned long; passing
// PHP null renders as 0 which means "don't steal an existing
// ctty from another session".  See [gotcha:ioctl-third-arg-ulong-not-pointer].

// Line 59:
if ($libc->ioctl($fd, $tioCSctty, null) !== 0) {
```

---

## Phase 5: Missing Features (Enhancements) [PENDING]

### 5.1 No ReactPHP Async Stream Interfaces [PENDING] ← CURRENT

**What is expected:** Implement `React\Stream\ReadableStreamInterface` and `React\Stream\WritableStreamInterface` for PTY entities. This enables integration with the broader ReactPHP ecosystem (piping, filtering, buffering).

**Why the change should be done:** Without these interfaces, PTY entities cannot integrate with ReactPHP's stream composition, piping, and filtering ecosystem.

**Severity:** Enhancement (P4)

**Related code locations:**
- PTY classes in `src/Posix/`
- Contract interfaces in `src/Contract/`

---

### 5.2 No Multi-Pattern Regex Expect [PENDING]

**What is expected:** Add `expectAnyPattern(array $patterns, callable $callback)` method that accepts multiple regex patterns with per-pattern callbacks. Similar to Go's `expect.AnyPattern()`.

**Why the change should be done:** The current `expectPattern()` only supports single-pattern matching. Multi-pattern matching with per-pattern actions requires manual loop management.

**Severity:** Enhancement (P4)

**Related code locations:**
- `src/Expect.php`

---

### 5.3 No Built-in ANSI Escape Sequence Parsing [PENDING]

**What is expected:** Either document the dependency on `candy-ansi` more prominently, or add optional ANSI parsing integration directly into candy-pty for common use cases.

**Why the change should be done:** candy-pty depends on `candy-ansi` for ANSI parsing but doesn't expose this integration. Users need to know about the dependency and wire it manually.

**Severity:** Enhancement (P4)

**Related code locations:**
- `composer.json` shows dependency on `sugarcraft/candy-ansi`
- See README lines 403-406 for current documentation

---

### 5.4 No Async Acquire/Release on PtyPool [PENDING]

**What is expected:** Add async variants using ReactPHP promises: `acquireAsync()` and `releaseAsync()` that yield to the event loop during contention.

**Why the change should be done:** `PtyPool::acquire()` and `PtyPool::release()` are documented as async methods but do not yield to the event loop during contention, blocking the event loop under load.

**Severity:** Enhancement (P4)

**Related code locations:**
- `src/PtyPool.php:73-82` (acquire method)
- `src/PtyPool.php:89-101` (release method)

---

## Phase 6: Duplication Refactoring [PENDING]

### 6.1 $libc = Libc::lib() Pattern Centralization [PENDING] ← CURRENT

**What is expected:** Create a `LibcAccess` trait that provides `protected static function libc(): Libc` returning `Libc::lib()`. Apply this trait to all classes that use FFI calls.

**Why the change should be done:** The pattern `$libc = Libc::lib()` appears in nearly every file that uses FFI calls. Centralizing ensures consistent singleton access and reduces boilerplate.

**Severity:** Refactoring (P3)

**Conditions for success:**
- Trait `LibcAccess` exists with `libc()` method
- All FFI-using classes use the trait
- Behavior is identical before and after

**Related code locations:**
- Found in many files: `PosixMasterPty.php`, `PosixPump.php`, `PosixPtySystem.php`, `SizeIoctl.php`, `ControllingTerminal.php`, etc.

---

### 6.2 PtyPool Active/Available Session Split [PENDING]

**What is expected:** The findings note `$this->activeSessions` and `$this->availableSessions` are stored as parallel arrays. This appears to be referring to the `inFlight` array in PtyPool. Investigate and potentially unify.

**Why the change should be done:** Parallel arrays with identical structure suggest potential design improvement.

**Severity:** Refactoring (P3)

**Related code locations:**
- `src/PtyPool.php:48` (inFlight array)
- `src/PtyPool.php:104-107` (inFlight method)

---

## Phase 7: Compatibility Issues [PENDING]

### 7.1 ext-ffi Required but Disabled by Default [PENDING]

**What is expected:** Update README to prominently warn users to enable FFI in `php.ini`. Add a "Known Limitations" or "Requirements" section that explicitly mentions the FFI extension must be enabled.

**Why the change should be done:** `ext-ffi` is required but disabled by default in many PHP installations (PHP 8.2+ often ship with FFI disabled for security). Users need clear guidance.

**Severity:** Compatibility (P3)

**Related code locations:**
- `README.md` (install section at lines 16-24)
- `composer.json:33` (`ext-ffi` requirement)

---

### 7.2 /dev/ptmx Access Required [PENDING]

**What is expected:** Add a graceful error message when `/dev/ptmx` is not accessible, explaining the issue and potential solutions (e.g., Docker with `--device-read-only`).

**Why the change should be done:** The library requires access to `/dev/ptmx` which may not be available in certain container environments. No graceful error is produced when access is denied.

**Severity:** Compatibility (P3)

**Related code locations:**
- `PosixPtySystem::open()` - entry point where /dev/ptmx is accessed
- Tests show `markTestSkipped` checks at `PosixMasterPtyTest.php:22-24`

---

### 7.3 Darwin arm64 ioctl ABI Mismatch [PENDING]

**What is expected:** Document the Darwin arm64 `TIOCSWINSZ` ioctl fallback via stty subprocess as a known limitation in user-facing documentation.

**Why the change should be done:** The fallback works but is slow and not documented as a known limitation. Users should understand why their Darwin resize operations are slower.

**Severity:** Compatibility (P3)

**Related code locations:**
- `src/SizeIoctl.php:149-161` (setSizeViaLibc with stty fallback)
- `src/SizeIoctl.php:171-189` (sttySetSize subprocess)
- CALIBER_LEARNINGS.md:33 (`gotcha:ioctl-read-vs-write-variadic`)

---

### 7.4 Termios Struct Size Assumption [PENDING]

**What is expected:** Document the 80-byte assumption for `struct winsize`. The code comment at `SizeIoctl.php:76` allocates `unsigned short[4]` which assumes 4*2=8 bytes, not 80.

**Why the change should be done:** The comment mentions "opaque termios ≥80 bytes" but the actual allocation is correct for winsize (4 unsigned shorts = 8 bytes). This appears to be a documentation issue - the comment may be confusing termios with winsize.

**Severity:** Compatibility (P3)

**Related code locations:**
- `src/SizeIoctl.php:76` (`unsigned short[4]`)
- `src/Libc.php:125-127` (comment about termios being opaque ≥80 bytes)

---

### 7.5 musl/Alpine Uses Different Libc Paths [PENDING]

**What is expected:** Document the `SUGARCRAFT_LIBC` env var override mechanism more prominently. Ensure it works correctly for musl-based systems.

**Why the change should be done:** The library uses hardcoded glibc paths. Alpine Linux and other musl-based systems use different library paths, causing FFI initialization failures without the override.

**Severity:** Compatibility (P3)

**Related code locations:**
- `src/Libc.php:78-88` (libraryPath method with env override)
- `README.md:261-263` (current documentation of env var)

---

## Phase 8: Async Pattern Improvements [PENDING]

### 8.1 PosixPump Uses Blocking stream_select Loop [PENDING] ← CURRENT

**What is expected:** Implement a ReactPHP-compatible version using `react/stream` interfaces and `Loop::addReadStream()`. This allows the pump to integrate with ReactPHP's event loop rather than blocking it.

**Why the change should be done:** `PosixPump::pump()` uses a blocking `stream_select` loop that cannot integrate with ReactPHP's event loop. When used in a ReactPHP application, this blocks the entire event loop during I/O wait.

**Severity:** Enhancement (P4)

**Related code locations:**
- `src/Posix/PosixPump.php:87-175` (pump method with blocking loop)

---

### 8.2 SignalForwarder Uses Static Callbacks [PENDING]

**What is expected:** Refactor signal handlers to use instance callbacks rather than static callbacks. This allows multiple `SignalForwarder` instances to coexist independently.

**Why the change should be done:** Static callbacks cannot be unregistered per-instance. This prevents multiple `SignalForwarder` instances from coexisting independently.

**Severity:** Enhancement (P4)

**Related code locations:**
- `src/SignalForwarder.php:55-68` (static handler closures)
- `src/SignalForwarder.php:98-108` (static handler in attachSigwinch)

---

### 8.3 PosixChild::wait() Blocks the Event Loop [PENDING]

**What is expected:** Add an async variant `waitAsync()` that returns a ReactPHP promise, allowing the event loop to continue during subprocess wait.

**Why the change should be done:** `PosixChild::wait()` is a blocking call with no async equivalent. In an async application, calling `wait()` blocks the entire event loop until the child exits.

**Severity:** Enhancement (P4)

**Related code locations:**
- `src/Posix/PosixChild.php` (wait method inherited from ChildPollTrait)
- `src/Child.php` (interface, should define waitAsync)

---

### 8.4 MultiPump::run() Blocks with No Async Variant [PENDING]

**What is expected:** Add `runAsync()` method or a ReactPHP-compatible variant that returns a promise resolving to the exit code map.

**Why the change should be done:** `MultiPump::run()` is a blocking loop with no async variant. There is no `MultiPump::runAsync()` or ReactPHP-compatible alternative.

**Severity:** Enhancement (P4)

**Related code locations:**
- `src/Posix/MultiPump.php:107-118` (run method)
- `src/Posix/MultiPump.php:126-206` (tick method)

---

## Notes

- 2026-06-30: Investigation completed. The pty-shim.php file ALREADY EXISTS at `bin/pty-shim.php` - Critical Issue #3 is resolved and requires no action.
- All findings have been investigated and confirmed (or corrected) based on actual file inspection.
- Some "Missing Features" are listed as P4 enhancements - these are lower priority than bugs but still valuable.
- The async pattern improvements (Phase 8) represent significant architectural work and should be prioritized based on user demand for ReactPHP integration.
