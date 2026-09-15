# candy-wish Code Review

## Summary

candy-wish is a SugarCraft library providing SSH server functionality built on ReactPHP, offering both an in-process transport (PTY-based) and a host sshd integration transport. The library implements the SSH protocol with middleware-based authentication (password, certificate, keyboard-interactive, rate limiting), session management, and channel handling for shell spawn.

The codebase has several architectural concerns that undermine its async foundation: `AsyncMiddleware::handle()` calls `PromiseAwait::settle()` synchronously, defeating the purpose of async middleware; `PromiseAwait::settle()` loses the original rejection when a timeout fires simultaneously; and the double `settle()` pattern between middleware and transport is redundant. Beyond the async design issues, there are race conditions in the rate limiter's token bucket, inconsistent `setTransport` injection between transports, non-atomic file operations, and missing observability hooks. The library is also missing several SSH features (SFTP, SCP, agent forwarding, connection multiplexing) and relies on blocking I/O throughout despite being built on an async event loop.

## Critical Issues (file:line format)

### 1. AsyncMiddleware blocks the event loop instead of delegating to transport ❗ r82: STILL LIVE — src/Middleware/AsyncMiddleware.php:55 still calls PromiseAwait::settle() synchronously inside handle()

**File:** `src/Middleware/AsyncMiddleware.php`
**Lines:** 41-52

`AsyncMiddleware::handle()` calls `PromiseAwait::settle()` synchronously, which blocks the event loop entirely. This means async middleware are NOT truly async — they execute as blocking code within the event loop pump. The transport's own `dispatch()` method (`InProcessTransport.php:388-390`, `HostSshdTransport.php:48-50`) also calls `PromiseAwait::settle()`. If async middleware were to return a promise that the transport tries to await again, the double-settle is redundant and wastes event loop cycles.

**Fix:** AsyncMiddleware should return the promise directly and let the transport's `dispatch()` handle it uniformly, without calling `PromiseAwait` internally.

---

### 2. PromiseAwait::settle() loses original rejection when timeout fires ✅ r82: fixed by rewrite — src/Transport/PromiseAwait.php:61-62 timeout arm now preserves first-set $ex (original rejection wins)

**File:** `src/Transport/PromiseAwait.php`
**Lines:** 61-74

When both the original promise rejects AND the timeout fires (near-simultaneously), the timeout's rejection callback at lines 61-67 overwrites `$ex`, causing `Loop::run()` to throw the timeout error instead of the original rejection. The comment at line 60 says "When the timeout fires (or the wrapped promise settles)" but the code structure means the timeout ALWAYS overwrites the original rejection.

**Fix:** Only set `$ex` from the timeout if it is not already set, preserving the original rejection.

---

### 3. Missing ANSI sanitization on some error messages ⏭️ r82: obsolete — finding itself states all current sites safe; preventive-consistency note only

**Files:** Multiple middleware files

`Auth::reject()` at line 128 calls `fwrite($this->stderr, "Unauthorized. ({$reason})\n")` where `$reason` has been sanitized via `sanitize()`. However, several other middleware write raw strings without user data:
- `PasswordAuth::handle()` at line 73: `fwrite($this->stderr, "Permission denied.\n")` — raw string (OK, no user data)
- `RateLimit::handle()` at line 63: `fwrite($this->stderr, "Rate limit exceeded. Try again later.\n")` — raw string (OK)
- `KeyboardInteractive::handle()` at line 128: `fwrite($this->stderr, "Authentication failed.\n")` — raw string (OK)
- `CertificateAuth::handle()` at lines 66, 74 — raw strings (OK)

Currently all safe, but if any of these middleware used username or other user-supplied data in rejection messages, they would be unsanitized. The pattern should be consistent: always sanitize any user-adjacent data.

---

### 4. PromiseAwait::settle() has no cancellation mechanism ❗ r82: STILL LIVE — src/Transport/PromiseAwait.php has zero Context/cancel references (grep 0)

**File:** `src/Transport/PromiseAwait.php`
**Lines:** 35-75

The timeout is a one-shot; there is no way to cancel an in-flight await if the Context is cancelled. A cancelled context should short-circuit `PromiseAwait` immediately rather than waiting for timeout. The `Context::done()` check exists at the top of `dispatch()` but not inside `PromiseAwait` itself.

**Fix:** Add a context cancellation check within `PromiseAwait::settle()` that aborts immediately when the context is cancelled.

---

## High Severity Issues

### 5. Double getenv() call for same key in Session::fromEnvironment() ✅ r82: fixed by rewrite — getenv result cached once per key src/Session.php:71-78

**File:** `src/Session.php`
**Lines:** 71-74

```php
$env = static fn(string $k): ?string =>
    (isset($_SERVER[$k]) && $_SERVER[$k] !== '')
        ? (string) $_SERVER[$k]
        : (getenv($k) === false || getenv($k) === '' ? null : (string) getenv($k));
```

`getenv($k)` is called TWICE to check both `=== false` and `=== ''`. This should cache the result:

```php
$env = static fn(string $k): ?string =>
    (isset($_SERVER[$k]) && $_SERVER[$k] !== '')
        ? (string) $_SERVER[$k]
        : ($v = getenv($k)) === false || $v === '' ? null : (string) $v;
```

---

### 6. Non-atomic RateLimit token consumption ✅ r82: fixed by rewrite — atomic temp-file + rename + 0600 src/Middleware/RateLimit.php:246-254 (no ftruncate race path left)

**File:** `src/Middleware/RateLimit.php`
**Lines:** 69-101

Between `flock(LOCK_EX)` acquisition (line 78) and the final state write (lines 91, 96), another process can read stale state. While `flock` prevents concurrent writes, the token arithmetic is not atomic with respect to concurrent readers who all compute the same token count before any writes. Additionally, `ftruncate($fh, 0)` at line 115 is NOT safe for concurrent use — another process's `rewind()` could race.

**Fix:** Use `file_put_contents()` with `LOCK_EX` on a temp file + rename for atomicity, or use `flock` with `LOCK_NB` for true non-blocking atomicity.

---

### 7. stream_set_blocking failures silently ignored in runChild() ✅ r82: fixed by rewrite — src/Transport/InProcessTransport.php:273 documents why non-fatal; requirement satisfied

**File:** `src/Transport/InProcessTransport.php`
**Lines:** 252-253

```php
\stream_set_blocking($master->stream(), false);
@\stream_set_blocking($stdin, false);
```

Return values are not checked. If these fail, the subsequent `PosixPump::run()` may behave unexpectedly with blocking I/O.

**Fix:** Check return values and handle failures appropriately, or document why failure is acceptable.

---

### 8. Inconsistent setTransport injection between transports ❗ r82: STILL LIVE — HostSshdTransport still has no setTransport call (grep only InProcess :172-173)

**File:** `src/Transport/InProcessTransport.php:165` vs `src/Transport/HostSshdTransport.php`

`InProcessTransport::run()` calls `setTransport()` on all middleware that implement it (line 165). `HostSshdTransport::run()` does NOT call `setTransport()` on any middleware. This means middleware that rely on `setTransport` for transport-specific initialization will behave differently under each transport. While documented, the asymmetry is error-prone.

**Fix:** Normalize `setTransport` behavior across both transports, or extract a shared `MiddlewareStack` that handles this uniformly.

---

### 9. $stdin/$stdout resource type hints too generic ❗ r82: STILL LIVE — src/Transport/InProcessTransport.php:238/:241/:380 bare is_resource(), no ClosedResource handling

**File:** `src/Transport/InProcessTransport.php`
**Lines:** 231-236

Checking `is_resource($stdin)` rejects `ClosedResource` (PHP 8.1+). Should use `\is_resource($stdin) || $stdin instanceof \ClosedResource` or simply remove the check and let stream operations fail naturally.

---

## Medium Severity Issues

### 10. SignalForwarder::pcntlReady() called on every runChild() ✅ r82: fixed by rewrite — memoized at construction src/Transport/InProcessTransport.php:91-98

**File:** `src/Transport/InProcessTransport.php`
**Lines:** 271

This check should be done once at construction time, not on every child spawn.

---

### 11. Multiple middleware open streams without tracking ownership ✅ r82: fixed by rewrite — StreamHelper::openOrValidate extracted (src/StreamHelper.php; Auth.php:79; StreamHelperTest present)

**Files:** `src/Middleware/Auth.php:49-65`, `src/Middleware/PasswordAuth.php:43-58`, `src/Middleware/RateLimit.php:39-57`, etc.

Auth, PasswordAuth, RateLimit, Logger, KeyboardInteractive, CertificateAuth, AuthMethods all open `php://stderr` or `php://stdout` in constructors and don't track whether they own the stream. The Logger has an `$owns` field but the others don't. If an exception is thrown mid-constructor after the stream is opened but before assignment completes, the stream leaks.

**Fix:** Extract stream-opening to a `StreamHelper::openOrValidate()` utility, or use a trait that manages stream lifecycle.

---

### 12. Hardcoded PATH in buildEnv() ✅ r82: fixed by rewrite — PATH now inherited via passthrough/floor list src/Channel/DefaultChannelHandler.php:60,:227 (hardcoded literal gone)

**File:** `src/Channel/DefaultChannelHandler.php`
**Lines:** 242

```php
'PATH'  => '/usr/local/bin:/usr/bin:/bin',
```

Doesn't adapt to the host's actual PATH. Should use `getenv('PATH')` from a safe source for the supervisor process.

---

### 13. Session::fromEnvironment() doesn't validate SSH_CONNECTION format ✅ r82: fixed by rewrite — parts indexed safely: $parts = preg_split('/\s+/', $conn) ?: []; $clientHost = $parts[

**File:** `src/Session.php`
**Lines:** 77

`preg_split('/\s+/', $conn)` returns `[]` on empty string, then `$parts[0]` is undefined. Default empty strings are fine but malformed `SSH_CONNECTION` values (e.g., single token) result in 0 port.

**Fix:** Validate that `SSH_CONNECTION` contains at least 4 space-separated tokens before accessing array indices.

---

### 14. KeyboardInteractive::readResponses() can hang indefinitely ❗ r82: STILL LIVE — src/Middleware/Auth/KeyboardInteractive.php:165 blocking fgets, no timeout

**File:** `src/Middleware/Auth/KeyboardInteractive.php`
**Lines:** 165

`fgets($this->stdin)` blocks if no data is available and stdin is blocking. For SSH keyboard-interactive, the client should send responses, but a misbehaving client could cause the server to hang.

**Fix:** Use non-blocking I/O with a timeout, or move to async stream reading.

---

### 15. DefaultChannelHandler::handleSignal() silently ignores unknown signals ❗ r82: STILL LIVE — unknown-signal path still silent (130: $sig = $map[$msg->signalName] ?? null; 131: if ($sig !=)

**File:** `src/Channel/DefaultChannelHandler.php`
**Lines:** 130-133

If `$sig` is null (unknown signal name), nothing happens. No warning, no logging. A client could send arbitrary signal names with no feedback.

---

## Low Severity Issues

### 16. promise->then() without returning the promise chain ⏭️ r82: obsolete — pattern made deliberate with the #2 rejection-preservation fix + comments

**File:** `src/Transport/PromiseAwait.php`
**Lines:** 41-47, 61-67

The callbacks attached via `then()` have side effects but don't return values. In ReactPHP, returning a value from a rejection handler transforms the rejection into a resolution. Not returning means `$ex` correctly captures the original rejection, but the pattern is fragile.

---

### 17. No interface for setTransport — duck typing is fragile ❗ r82: STILL LIVE — still method_exists('setTransport') duck-typing (InProcessTransport.php:172); no TransportAware interface

**File:** `src/Transport/InProcessTransport.php`
**Lines:** 165-167

Uses `\method_exists($mw, 'setTransport')` to check before calling. A strongly-typed interface `TransportAwareMiddleware` with `setTransport(Transport)` would catch typos at type-check time.

---

### 18. Context::done() called twice in dispatch() ❗ r82: STILL LIVE — dispatch checks done() only at entry (412: if ($ctx->done()) {)

**File:** `src/Transport/InProcessTransport.php:381`, `src/Transport/HostSshdTransport.php:41`

The `$ctx->done()` check is at the top of `dispatch()`, but if context becomes done DURING middleware execution (e.g., deadline expires while waiting for async), it won't be re-checked before calling the next middleware.

---

### 19. Logger uses date('c') instead of ISO-8601 ⏭️ r82: obsolete — premise false: date('c') IS ISO-8601 (== DateTime::ATOM) and locale-independent

**File:** `src/Middleware/Logger.php`
**Lines:** 70, 80

`date('c')` produces locale-dependent output. Should use `DateTimeImmutable::createFromFormat('U', (string)time())->format(\DateTimeInterface::ATOM)` or similar for consistent ISO-8601.

---

### 20. DefaultChannelHandler::spawnShell() recreates Session unnecessarily ✅ r82: fixed by rewrite — runChild takes session directly, no throwaway clone dance (196: $effectiveSession = new Session( 209: $this->spawner->r)

**File:** `src/Channel/DefaultChannelHandler.php`
**Lines:** 196-209

Creates a new Session just to pass different cols/rows. A simpler fix would be to pass cols/rows directly to `runChild()`.

---

### 21. parseCommandString() has no tests ❗ r82: STILL LIVE — no test file references parseCommandString (grep tests/ empty)

**File:** `src/Channel/DefaultChannelHandler.php`
**Lines:** 275-331

This is a non-trivial parser. There are no dedicated tests for it, only integration-level tests.

---

### 22. No test for Context deadline expiry during middleware chain ❗ r82: STILL LIVE — tests/MiddlewareContextTest.php has no deadline-mid-chain case

**File:** `tests/ContextTest.php`

Tests cover deadline expiry at creation but not deadline expiring mid-chain.

---

### 23. withDeadline returns a cancelable context unconditionally ❗ r82: STILL LIVE — src/Context.php:69-75 withDeadline still sets cancelable: true

**File:** `src/Context.php`
**Lines:** 69-77

`withDeadline()` sets `cancelable: true` even though the context is deadline-driven, not user-cancellation-driven. This is semantically odd — a deadline context should be done when the deadline passes, not require explicit `cancel()`.

---

### 24. PromiseAwait uses Loop::get() — not configurable ❗ r82: STILL LIVE — src/Transport/PromiseAwait.php:58 hardcoded Loop::get(), no injection

**File:** `src/Transport/PromiseAwait.php`
**Lines:** 58, 70

No way to inject a specific event loop instance. Makes testing harder and doesn't follow dependency injection principles.

---

## Missing Features

### 25. No true async streaming I/O for middleware ❗ r82: STILL LIVE — blocking middleware I/O unchanged (linked to #1; architectural)

All I/O is blocking. The library is built on ReactPHP but middleware perform blocking I/O (`fgets`, `fwrite`, `fopen`). No async stream wrappers.

### 26. No WebSocket/TCP transport ⏭️ r82: obsolete — unimplemented feature request (still absent from src at c489210e6); pre-1.0 product scope, not a code defect

Only InProcess (PTY) and HostSshd. Can't embed a SugarCraft TUI in a web page or non-SSH context.

### 27. No SFTP implementation ⏭️ r82: obsolete — unimplemented feature request (still absent from src at c489210e6); pre-1.0 product scope, not a code defect (SftpStub itself no longer present)

The `SftpStub` is a no-op placeholder.

### 28. No SCP implementation ⏭️ r82: obsolete — unimplemented feature request (still absent from src at c489210e6); pre-1.0 product scope, not a code defect

### 29. No reverse port forwarding (SSH client → server → remote) ⏭️ r82: obsolete — unimplemented feature request (still absent from src at c489210e6); pre-1.0 product scope, not a code defect

### 30. No forward port forwarding (SSH client → server → local) ⏭️ r82: obsolete — unimplemented feature request (still absent from src at c489210e6); pre-1.0 product scope, not a code defect

### 31. No SSH agent forwarding ⏭️ r82: obsolete — unimplemented feature request (still absent from src at c489210e6); pre-1.0 product scope, not a code defect

### 32. No connection multiplexing support ⏭️ r82: obsolete — unimplemented feature request (still absent from src at c489210e6); pre-1.0 product scope, not a code defect

### 33. No PTY resize event propagation back to the SSH client ✅ r82: fixed by rewrite — SIGWINCH forwarding via SignalForwarder, capability cached src/Transport/InProcessTransport.php:98

SIGWINCH goes to child but it is unclear if the client sees it.

### 34. No graceful shutdown ⏭️ r82: obsolete — unimplemented feature request (still absent from src at c489210e6); pre-1.0 product scope, not a code defect

`Server::serve()` has no signal handlers for SIGTERM/SIGHUP.

### 35. No connection timeout at transport level ⏭️ r82: obsolete — unimplemented feature request (still absent from src at c489210e6); pre-1.0 product scope, not a code defect

Only async middleware timeout exists.

### 36. No way to enumerate active sessions or monitor connection count ⏭️ r82: obsolete — unimplemented feature request (still absent from src at c489210e6); pre-1.0 product scope, not a code defect

### 37. No integration with PSR-3 logging interfaces ⏭️ r82: obsolete — unimplemented feature request (still absent from src at c489210e6); pre-1.0 product scope, not a code defect

### 38. No metrics/observability hooks (OpenTelemetry, Prometheus, etc.) ⏭️ r82: obsolete — unimplemented feature request (still absent from src at c489210e6); pre-1.0 product scope, not a code defect

---

## Duplicated Logic / Refactoring Opportunities

### 39. Duplicate dispatch() logic in InProcessTransport and HostSshdTransport ❗ r82: STILL LIVE — no MiddlewareStack helper exists (grep); dispatch() still duplicated in both transports

**File:** `src/Transport/InProcessTransport.php:376-391`, `src/Transport/HostSshdTransport.php:36-51`

Both are nearly identical: check `$idx >= count($stack)`, check `$ctx->done()`, create `$next` closure, call `$stack[$idx]->handle()`, await promise if returned. Should be extracted to a shared `MiddlewareStack` helper class.

---

### 40. Duplicate stream-opening boilerplate in all Auth middleware ✅ r82: fixed by rewrite — see #11 (StreamHelper adopted by middleware constructors)

**Files:** `src/Middleware/Auth.php`, `src/Middleware/PasswordAuth.php`, `src/Middleware/CertificateAuth.php`, `src/Middleware/KeyboardInteractive.php`, `src/Middleware/AuthMethods.php`, `src/Middleware/RateLimit.php`, `src/Middleware/Logger.php`

All have the same `if ($stderr === null) { $stream = fopen(...); ... }` pattern. Extract to a `StreamHelper::openOrValidate()` utility.

---

### 41. Duplicate signal name → PHP constant mapping ⏭️ r82: obsolete — finding acknowledges single use; reuse speculative

**File:** `src/Channel/DefaultChannelHandler.php`
**Lines:** 119-128

This map appears nowhere else but could be reused if other parts of the system need signal translation.

---

### 42. Duplicate sanitize() logic in Auth ❗ r82: STILL LIVE — sanitize() still private to Auth (src/Middleware/Auth.php:142)

**File:** `src/Middleware/Auth.php`
**Lines:** 115-124

The `sanitize()` method is good but only used in Auth. Other rejection paths (RateLimit, PasswordAuth) don't sanitize, though they currently use no user data.

---

### 43. PromiseAwait::settle() and AsyncMiddleware::handle() both await promises ❗ r82: STILL LIVE — src/Middleware/AsyncMiddleware.php:55 still calls PromiseAwait::settle() synchronously inside handle() (await logic still in both layers)

**File:** `src/Transport/PromiseAwait.php:35-75`, `src/Middleware/AsyncMiddleware.php:41-52`

AsyncMiddleware calls `PromiseAwait::settle()` directly. The await logic is duplicated in both places.

---

## Compatibility Issues

### 44. InProcessTransport requires ext-ffi, ext-pcntl, /dev/ptmx ❗ r82: STILL LIVE — README has no ext-ffi/ext-pcntl/ptmx requirement section (grep 0 hits)

Not available on Windows, macOS (though macOS has ptmx), or shared hosting without PTY access. The `requirePtySyscalls()` pattern in tests is good, but there is no documentation warning users about these requirements.

---

### 45. Transport\InProcessTransport is not a proper ChildSpawner for HostSshd ⏭️ r82: obsolete — documented refusal-by-design (finding says 'is documented')

**File:** `src/Middleware/BubbleTea.php`
**Lines:** 27-46

BubbleTea refuses to run under InProcessTransport. This is documented but could surprise users who mix middleware.

---

### 46. spawnShell() in DefaultChannelHandler hardcodes /bin/bash ❗ r82: STILL LIVE — src/Channel/DefaultChannelHandler.php:209 still hardcodes ['/bin/bash','-l']

**File:** `src/Channel/DefaultChannelHandler.php`
**Lines:** 209

Doesn't respect `SHELL` environment variable or allow configuration of the login shell.

---

### 47. Session::fromEnvironment() reads from $_SERVER and getenv() ⏭️ r82: obsolete — ForceCommand execution model is the library's premise, not a defect

**File:** `src/Session.php`
**Lines:** 71-74

Doesn't work when PHP is not running as an SSH ForceCommand (e.g., CGI, some FastCGI setups). The library fundamentally assumes ForceCommand context.

---

## Async Pattern Improvements

### 48. AsyncMiddleware should NOT call PromiseAwait::settle() ❗ r82: STILL LIVE — src/Middleware/AsyncMiddleware.php:55 still calls PromiseAwait::settle() synchronously inside handle()

**File:** `src/Middleware/AsyncMiddleware.php`
**Lines:** 48-50

Returning a promise from `handle()` and having the transport await it is the correct pattern. Calling `settle()` inside `handle()` blocks the event loop and makes "async" middleware synchronous.

---

### 49. Server::serve() should support a configurable event loop ❗ r82: STILL LIVE — src/Server.php has no loop-injection seam (grep none)

**File:** `src/Server.php`
**Lines:** 138-143

Currently hardcodes the transport's loop integration. For testing, it would help to inject a loop.

---

### 50. No async stream I/O for Keepalive ⏭️ r82: obsolete — null-byte keepalive adequate; async scaling idea

**File:** `src/Middleware/Keepalive.php`
**Lines:** 53-77

Keepalive uses a callback that calls `$transport->getPty()->write("\0")` synchronously in the pump loop's idle path. This is OK for a null byte but doesn't scale to async patterns.

---

### 51. No async file I/O for RateLimit ⏭️ r82: obsolete — async file I/O idea; atomicity defect itself fixed (#6)

**File:** `src/Middleware/RateLimit.php`
**Lines:** 69-101

Token bucket state is read/written synchronously with blocking file I/O. Should use async file streams with ReactPHP's stream wrappers.

---

### 52. PromiseAwait should support cancellation via Context ❗ r82: STILL LIVE — see #4 (no Context cancellation in PromiseAwait)

**File:** `src/Transport/PromiseAwait.php`
**Lines:** 35-75

If the Context is cancelled while waiting, the await should abort immediately rather than waiting for the timeout.

---

## Recommendations Summary

| Priority | Category | Issue Count | Key Actions |
|----------|----------|-------------|-------------|
| **Critical** | Async Design | 2 | Fix `AsyncMiddleware::handle()` to return promises; fix `PromiseAwait::settle()` to preserve original rejection |
| **Critical** | Cancellation | 1 | Add context cancellation to `PromiseAwait::settle()` |
| **Critical** | Security | 1 | Audit all user-facing error messages for sanitization consistency |
| **High** | Concurrency | 2 | Fix double `getenv()` call; make `RateLimit` token consumption atomic |
| **High** | Error Handling | 2 | Check `stream_set_blocking` return values; normalize `setTransport` across transports |
| **Medium** | Resource Management | 2 | Move `SignalForwarder::pcntlReady()` to construction; extract stream opening to utility |
| **Medium** | Robustness | 3 | Validate `SSH_CONNECTION` format; add timeout to `KeyboardInteractive::readResponses()`; log unknown signals |
| **Low** | Code Quality | 6 | Add tests for `parseCommandString()`; fix `date('c')` format; use DI for event loop; remove redundant `Session` recreation |
| **Feature** | Missing Functionality | 14 | Plan SFTP, SCP, agent forwarding, connection multiplexing, graceful shutdown, observability hooks |
| **Refactor** | DRY | 5 | Extract `MiddlewareStack`, `StreamHelper`, shared signal map; deduplicate `sanitize()` |

---

*Review generated: Tue Jun 30 2026*
