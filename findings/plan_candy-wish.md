---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-wish Code Review Fixes

## Goal

Address all critical, high, medium, and low severity issues identified in the candy-wish code review, and create a structured roadmap for missing features and refactoring opportunities.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Fix AsyncMiddleware to return promise directly | Current pattern blocks event loop, defeats async purpose of promise-returning middleware | `findings/candy-wish.md` lines 11-18 |
| Fix PromiseAwait to preserve original rejection | When both original promise rejects AND timeout fires simultaneously, timeout overwrites the real error | `findings/candy-wish.md` lines 22-29 |
| Cache getenv() result in Session::fromEnvironment() | Calling getenv() twice for same key is inefficient and non-idiomatic | `findings/candy-wish.md` lines 60-80 |
| Make RateLimit token consumption atomic | Race condition: concurrent readers compute same token count before any writes occur | `findings/candy-wish.md` lines 83-91 |
| Normalize setTransport behavior across transports | HostSshdTransport never calls setTransport(), causing middleware to behave differently | `findings/candy-wish.md` lines 110-117 |
| Extract dispatch() to shared MiddlewareStack | InProcessTransport and HostSshdTransport have nearly identical dispatch() logic | `findings/candy-wish.md` lines 321-326 |
| Extract stream-opening to StreamHelper utility | All auth middleware duplicate the same stream-opening boilerplate | `findings/candy-wish.md` lines 329-334 |

## Phase 1: Critical Issues [PENDING]

- [ ] **1.1 AsyncMiddleware blocks the event loop** ← CURRENT  
  `src/Middleware/AsyncMiddleware.php:41-52`  
  Fix: Return promise from handleAsync() directly instead of calling PromiseAwait::settle() internally

- [ ] **1.2 PromiseAwait loses original rejection on timeout**  
  `src/Transport/PromiseAwait.php:61-74`  
  Fix: Only set $ex from timeout if original hasn't already errored

- [ ] **1.3 Missing ANSI sanitization on error messages**  
  `src/Middleware/Auth.php:128`, `src/Middleware/PasswordAuth.php:73`, etc.  
  Fix: Document which messages are safe (static) vs which need sanitize()

- [ ] **1.4 PromiseAwait has no cancellation mechanism**  
  `src/Transport/PromiseAwait.php:35-75`  
  Fix: Add Context cancellation check within settle() that aborts immediately

## Phase 2: High Severity Issues [PENDING]

- [ ] **2.1 Double getenv() call in Session::fromEnvironment()**  
  `src/Session.php:71-74`  
  Fix: Cache getenv() result: `($v = getenv($k)) === false || $v === '' ? null : (string) $v`

- [ ] **2.2 Non-atomic RateLimit token consumption**  
  `src/Middleware/RateLimit.php:69-101`  
  Fix: Use file_put_contents() with LOCK_EX on temp file + rename for atomicity

- [ ] **2.3 stream_set_blocking failures silently ignored**  
  `src/Transport/InProcessTransport.php:252-253`  
  Fix: Check return values or document why failure is acceptable

- [ ] **2.4 Inconsistent setTransport injection between transports**  
  `InProcessTransport.php:165` vs `HostSshdTransport.php`  
  Fix: Normalize setTransport behavior across both transports, or extract MiddlewareStack

- [ ] **2.5 $stdin/$stdout resource type hints too generic**  
  `src/Transport/InProcessTransport.php:231-236`  
  Fix: Check `\is_resource($stdin) || $stdin instanceof \ClosedResource`

## Phase 3: Medium Severity Issues [PENDING]

- [ ] **3.1 SignalForwarder::pcntlReady() called on every runChild()**  
  `src/Transport/InProcessTransport.php:271`  
  Fix: Move check to construction time

- [ ] **3.2 Multiple middleware open streams without tracking ownership**  
  `src/Middleware/Auth.php:49-65`, `src/Middleware/PasswordAuth.php:43-58`, etc.  
  Fix: Extract StreamHelper::openOrValidate() utility

- [ ] **3.3 Hardcoded PATH in buildEnv()**  
  `src/Channel/DefaultChannelHandler.php:242`  
  Fix: Use `getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'`

- [ ] **3.4 Session::fromEnvironment() doesn't validate SSH_CONNECTION format**  
  `src/Session.php:77`  
  Fix: Validate at least 4 space-separated tokens before array access

- [ ] **3.5 KeyboardInteractive::readResponses() can hang indefinitely**  
  `src/Middleware/Auth/KeyboardInteractive.php:165`  
  Fix: Use non-blocking I/O with timeout, or move to async stream reading

- [ ] **3.6 DefaultChannelHandler::handleSignal() silently ignores unknown signals**  
  `src/Channel/DefaultChannelHandler.php:130-133`  
  Fix: Log warning for unknown signal names

## Phase 4: Low Severity Issues [PENDING]

- [ ] **4.1 promise->then() without returning the promise chain**  
  `src/Transport/PromiseAwait.php:41-47, 61-67`  
  Fix: Document why callbacks don't return values (intentional side effects)

- [ ] **4.2 No interface for setTransport — duck typing is fragile**  
  `src/Transport/InProcessTransport.php:165-167`  
  Fix: Create TransportAwareMiddleware interface

- [ ] **4.3 Context::done() called twice in dispatch()**  
  `InProcessTransport.php:381`, `HostSshdTransport.php:41`  
  Fix: Document behavior or re-check after async operations

- [ ] **4.4 Logger uses date('c') instead of ISO-8601**  
  `src/Middleware/Logger.php:70, 80`  
  Fix: Use `DateTimeImmutable::createFromFormat('U', (string)time())->format(\DateTimeInterface::ATOM)`

- [ ] **4.5 DefaultChannelHandler::spawnShell() recreates Session unnecessarily**  
  `src/Channel/DefaultChannelHandler.php:196-209`  
  Fix: Pass cols/rows directly to runChild()

- [ ] **4.6 parseCommandString() has no tests**  
  `src/Channel/DefaultChannelHandler.php:275-331`  
  Fix: Add dedicated unit tests

- [ ] **4.7 No test for Context deadline expiry during middleware chain**  
  `tests/ContextTest.php`  
  Fix: Add test for deadline expiring mid-chain

- [ ] **4.8 withDeadline returns a cancelable context unconditionally**  
  `src/Context.php:69-77`  
  Fix: Remove cancelable: true from withDeadline()

- [ ] **4.9 PromiseAwait uses Loop::get() — not configurable**  
  `src/Transport/PromiseAwait.php:58, 70`  
  Fix: Accept optional Loop parameter for testability

## Phase 5: Missing Features [PENDING]

> Note: These are documented for completeness. They are substantial features requiring their own implementation plans.

- [ ] **5.1 No true async streaming I/O for middleware** — All I/O is blocking (fgets, fwrite, fopen)
- [ ] **5.2 No WebSocket/TCP transport** — Only InProcess (PTY) and HostSshd
- [ ] **5.3 No SFTP implementation** — SftpStub is a no-op placeholder
- [ ] **5.4 No SCP implementation**
- [ ] **5.5 No reverse port forwarding (SSH client → server → remote)**
- [ ] **5.6 No forward port forwarding (SSH client → server → local)**
- [ ] **5.7 No SSH agent forwarding**
- [ ] **5.8 No connection multiplexing support**
- [ ] **5.9 No PTY resize event propagation back to SSH client** — SIGWINCH goes to child but client visibility unclear
- [ ] **5.10 No graceful shutdown** — Server::serve() has no SIGTERM/SIGHUP handlers
- [ ] **5.11 No connection timeout at transport level** — Only async middleware timeout exists
- [ ] **5.12 No way to enumerate active sessions or monitor connection count**
- [ ] **5.13 No integration with PSR-3 logging interfaces**
- [ ] **5.14 No metrics/observability hooks (OpenTelemetry, Prometheus, etc.)**

## Phase 6: Refactoring [PENDING]

- [ ] **6.1 Extract shared MiddlewareStack helper**  
  `InProcessTransport.php:376-391`, `HostSshdTransport.php:36-51`  
  Both transports have nearly identical dispatch() logic — extract to shared class

- [ ] **6.2 Extract StreamHelper utility**  
  All auth middleware have duplicate `if ($stderr === null) { fopen(...); }` pattern

- [ ] **6.3 Extract signal name → PHP constant map**  
  `DefaultChannelHandler.php:119-128` — could be a shared constant or utility

- [ ] **6.4 Extract sanitize() to shared utility**  
  `Auth.php:115-124` — sanitize() is good but only used in Auth

- [ ] **6.5 PromiseAwait::settle() and AsyncMiddleware::handle() both await**  
  Resolved by fixing issue 1.1

## Phase 7: Compatibility Issues [PENDING]

- [ ] **7.1 InProcessTransport requires ext-ffi, ext-pcntl, /dev/ptmx**  
  Add documentation warning users about platform requirements

- [ ] **7.2 InProcessTransport is not proper ChildSpawner for HostSshd**  
  `BubbleTea.php:27-46` — documented but could surprise users

- [ ] **7.3 spawnShell() hardcodes /bin/bash**  
  `DefaultChannelHandler.php:209` — should respect SHELL env var

- [ ] **7.4 Session::fromEnvironment() assumes ForceCommand context**  
  `Session.php:71-74` — document this requirement

## Phase 8: Async Pattern Improvements [PENDING]

- [ ] **8.1 AsyncMiddleware should NOT call PromiseAwait::settle()** — Resolved by 1.1
- [ ] **8.2 Server::serve() should support configurable event loop** — `Server.php:138-143`
- [ ] **8.3 No async stream I/O for Keepalive** — `Keepalive.php:53-77`
- [ ] **8.4 No async file I/O for RateLimit** — `RateLimit.php:69-101`
- [ ] **8.5 PromiseAwait should support cancellation via Context** — Resolved by 1.4

## Notes

- 2026-06-30: Plan created based on `findings/candy-wish.md` code review (52 findings total)
- Critical issues (Phase 1) must be fixed before high severity (Phase 2)
- Missing features (Phase 5) and compatibility issues (Phase 7) are documented for future planning
- Refactoring items in Phase 6 should be addressed together for maximum impact
- Phases 5, 6, 7, 8 are lower priority and can be addressed in subsequent iterations
