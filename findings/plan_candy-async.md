# Implementation Plan: candy-async

**Status:** not-started  
**Phase:** 1  
**Updated:** 2026-06-30  

---

## Goal

Address all findings from the candy-async code review, fixing safety issues (markCancelled visibility, shared static neverToken, synchronous exception handling, callback exception propagation), completing missing features (awaitAll/awaitAny, periodicSubscription, leading/trailing edge options, flush capability), and improving API design (naming collision resolution, Subscription interface extension) before the 1.0 release.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Rename `Subscription` → `SubscriptionHandle` and `Subscriptions` → `SubscriptionGroup` | Avoid naming collision with `SugarCraft\Core\Subscription` (Elm-style value object with completely different semantics) | `ref:candy-async-audit` Section 9.1 |
| Fix `markCancelled` visibility by making it `private` | Marked `@internal` but `public` visibility allows consumers to bypass `CancellationSource::cancel()` flow and corrupt token state | `ref:candy-async-audit` Section 1.1 |
| Remove shared static `neverToken` in retry() | A single static token shared across ALL retry() calls can be permanently corrupted if any retry is cancelled via this token | `ref:candy-async-audit` Section 1.2 |
| Wrap `$operation()` in try-catch in retry() | Synchronous exceptions before promise return are not caught by retry logic — they propagate as unhandled exceptions | `ref:candy-async-audit` Section 1.6 |
| Add `awaitAll` and `awaitAny` helpers | Common async patterns (`Promise::all()`, `Promise::race()`) not wrapped; consumers must use ReactPHP directly | `ref:candy-async-audit` Section 6.1 |
| Add `periodicSubscription` helper | No utility to create Subscription from `Loop::addPeriodicTimer()`; common pattern in TEA programs | `ref:candy-async-audit` Section 6.2 |
| Wrap onCancel callback calls in try-catch | If any callback throws, remaining callbacks won't fire and exception propagates unpredictably | `ref:candy-async-audit` Section 5.2 |
| Add loop parameter to retry() | Currently uses `Loop::get()` directly regardless of caller's loop, causing inconsistency when caller passes different loop to withTimeout() | `ref:candy-async-audit` Section 9.2 |

---

## Phase 1: Critical Safety Fixes [PENDING]

- [ ] **1.1 Fix CancellationToken::markCancelled visibility** ← CURRENT
- [ ] **1.2 Fix AsyncOps::retry shared static neverToken**
- [ ] **1.3 Wrap $operation() in try-catch in retry()**
- [ ] **1.4 Wrap onCancel callbacks in try-catch**

### 1.1 Fix CancellationToken::markCancelled visibility

**What is expected:** Change visibility of `markCancelled()` from `public` to `private`. The `CancellationSource::cancel()` at line 57 calls `$this->token->markCancelled()` directly, which requires the method to be accessible within the same package.

**Why:** The method is marked `@internal` but has `public` visibility. Any consumer can call `$token->markCancelled()` directly, bypassing the intended `CancellationSource::cancel()` flow that flips the source's `$cancelled` flag. While tests verify idempotency, a consumer could corrupt token state.

**Severity:** HIGH

**Conditions for success:**
- `CancellationToken::markCancelled()` is `private`
- `CancellationSource::cancel()` still works (it is in the same package)
- All existing tests pass
- Consumer code that directly calls `markCancelled()` should fail to compile/run

**Related code locations:**
- `candy-async/src/CancellationToken.php:45` — method to change
- `candy-async/src/CancellationSource.php:57` — existing caller
- `candy-async/tests/CancellationTokenTest.php:176-191` — existing idempotency test

**Investigation notes:**
The method is only called by `CancellationSource::cancel()` within the same package. The token constructor receives `private CancellationSource $source`, so it has access. In PHP, `private` visibility within a package (same namespace) allows internal classes to access each other's private members — this is by design in PHP's OOP.

---

### 1.2 Fix AsyncOps::retry shared static neverToken

**What is expected:** Remove the shared static `$neverToken` cache at line 25 and the fallback assignment at line 99. Always create a fresh `CancellationSource`/`CancellationToken` per retry call when no explicit token is provided.

**Why:** A single static `$neverToken` is shared across ALL `retry()` calls that don't provide an explicit token. If any retry operation gets cancelled via this token, it permanently corrupts the shared sentinel. Subsequent retry calls that rely on it would find an already-cancelled token.

**Severity:** HIGH

**Conditions for success:**
- `private static ?CancellationToken $neverToken = null;` is removed
- Line 99 changes from `$token ??= self::$neverToken ??= CancellationSource::new()->token();` to `$token ??= CancellationSource::new()->token();`
- All existing retry tests pass
- Concurrent retry() calls with no explicit token don't interfere with each other

**Related code locations:**
- `candy-async/src/AsyncOps.php:25` — static property definition
- `candy-async/src/AsyncOps.php:99` — static property usage
- `candy-async/tests/AsyncOpsTest.php:163-312` — retry tests

**Investigation notes:**
The comment at line 24 says "Never-cancelled sentinel token shared across retry() calls with no explicit token." This is the problematic design. Each `retry()` call that doesn't pass a token should get its own fresh token that is never cancelled — a simple `CancellationSource::new()->token()` per call achieves this without shared state.

---

### 1.3 Wrap $operation() in try-catch in retry()

**What is expected:** Wrap the `$operation()` call at line 118 in a try-catch block. If the operation throws a synchronous exception, convert it to a rejected promise so the retry logic can handle it.

**Why:** The current code at line 118 calls `$operation()->then(...)` directly. If `$operation()` throws a synchronous exception before returning a promise, it won't be caught by the retry logic — it will propagate outward as an unhandled exception. The retry logic only handles promise rejections.

**Severity:** MEDIUM

**Conditions for success:**
- `AsyncOps::retry()` catches synchronous exceptions from `$operation()`
- Caught exceptions are converted to rejected promises via `reject()`
- Retry logic handles these rejected promises normally
- Test added: operation that throws synchronously on first attempt, succeeds on second

**Related code locations:**
- `candy-async/src/AsyncOps.php:118` — direct `$operation()` call
- `candy-async/src/AsyncOps.php:107-148` — retryAttempt method

**Investigation notes:**
The fix should look like:
```php
$operationPromise;
try {
    $operationPromise = $operation();
} catch (\Throwable $e) {
    $operationPromise = reject($e);
}
return $operationPromise->then(/* ... */);
```

---

### 1.4 Wrap onCancel callbacks in try-catch

**What is expected:** Wrap each `$callback()` call in `fireCallbacks()` (line 77) with a try-catch. If a callback throws, log or store the error and continue calling remaining callbacks rather than propagating the exception and skipping callbacks.

**Why:** If any `onCancel` callback throws an exception, the remaining callbacks in the list won't be called (due to exception propagation). Additionally, the exception will propagate up through whatever code called `cancel()`. There's no error handling currently.

**Severity:** MEDIUM

**Conditions for success:**
- Each callback is wrapped in try-catch
- If a callback throws, remaining callbacks still fire
- Exceptions are not silently swallowed (should be logged or collected)
- Test added: verify all callbacks fire even if one throws

**Related code locations:**
- `candy-async/src/CancellationToken.php:76-78` — callback invocation loop
- `candy-async/src/CancellationToken.php:70-80` — fireCallbacks method
- `candy-async/tests/CancellationTokenTest.php` — existing callback tests

**Investigation notes:**
This is a common pattern issue. The implementation should probably collect exceptions and rethrow after all callbacks have been invoked, or log them. For now, continuing to call remaining callbacks is the priority. A simple approach:

```php
$errors = [];
foreach ($this->callbacks as $callback) {
    try {
        $callback();
    } catch (\Throwable $e) {
        $errors[] = $e;
    }
}
if ($errors !== []) {
    // Could rethrow the first, or log all
    throw $errors[0];
}
```

---

## Phase 2: API Design & Naming [PENDING]

- [ ] **2.1 Rename Async\Subscription to SubscriptionHandle**
- [ ] **2.2 Rename Async\Subscriptions to SubscriptionGroup**
- [ ] **2.3 Extend Subscription interface with count/isEmpty/add**
- [ ] **2.4 Remove @internal from disposeAll or change visibility**

### 2.1 Rename Async\Subscription to SubscriptionHandle

**What is expected:** Rename `SugarCraft\Async\Subscription` interface to `SugarCraft\Async\SubscriptionHandle` to avoid collision with `SugarCraft\Core\Subscription` (Elm-style event subscription value object).

**Why:** Both classes have the same name but completely different semantics:
- `SugarCraft\Async\Subscription` — RxJS-style disposal handle with `unsubscribe()`/`isActive()`
- `SugarCraft\Core\Subscription` — Elm-style subscription value object with `id`/`kind`/`params`/`produce`

Libraries that use both namespaces (like `candy-serve` using `SugarCraft\Async\Subscription`) have import conflicts.

**Severity:** HIGH

**Conditions for success:**
- Class renamed from `Subscription` to `SubscriptionHandle`
- All usages updated across the monorepo
- No import conflicts when using both `SugarCraft\Async\SubscriptionHandle` and `SugarCraft\Core\Subscription`
- All tests pass

**Related code locations:**
- `candy-async/src/Subscription.php` — interface file to rename
- `candy-async/src/Subscriptions.php:16` — implements `Subscription`
- `candy-async/src/Subscriptions.php:42` — `add(Subscription $subscription)`
- `candy-async/tests/SubscriptionsTest.php:8,23,137` — test usages
- `candy-serve/src/Git/GitDaemon.php:7-8` — consumer usage

**Investigation notes:**
Also need to rename `Subscriptions` to `SubscriptionGroup` (see 2.2).

---

### 2.2 Rename Async\Subscriptions to SubscriptionGroup

**What is expected:** Rename `SugarCraft\Async\Subscriptions` to `SugarCraft\Async\SubscriptionGroup` to clarify its role as a group/composite of subscriptions and align with the renamed `SubscriptionHandle`.

**Why:** `Subscriptions` (plural) is a group that holds multiple `SubscriptionHandle` instances. The renaming makes the semantics clearer:
- `SubscriptionHandle` — single disposal handle
- `SubscriptionGroup` — group of handles

**Severity:** HIGH

**Conditions for success:**
- Class renamed from `Subscriptions` to `SubscriptionGroup`
- Static factory `Subscriptions::compose()` becomes `SubscriptionGroup::compose()`
- All usages updated
- All tests pass

**Related code locations:**
- `candy-async/src/Subscriptions.php` — file to rename (and class inside)
- `candy-async/tests/SubscriptionsTest.php:9,21` — test usages
- `candy-serve/src/Git/GitDaemon.php:8` — consumer usage

---

### 2.3 Extend Subscription interface with count/isEmpty/add

**What is expected:** Either extend the `Subscription` (now `SubscriptionHandle`) interface to include `count()`, `isEmpty()`, and `add()` methods, OR extract a `SubscriptionGroup` interface that extends `Subscription` (for composite subscriptions only).

**Why:** `Subscriptions::compose()` adds `count()` and `isEmpty()` and `add()` as public methods on the implementation. Callers who type-hint on the interface can't access these useful methods without instanceof checks.

**Severity:** LOW

**Conditions for success:**
- `SubscriptionHandle` interface includes `count()` and `isEmpty()` (and `add()` only on group)
- OR new `SubscriptionGroup` interface extends `SubscriptionHandle` and adds these methods
- All implementations updated
- Type hints work correctly

**Related code locations:**
- `candy-async/src/Subscription.php` — interface to extend
- `candy-async/src/Subscriptions.php:70-78` — methods on implementation

**Investigation notes:**
The cleaner approach is likely a separate `SubscriptionGroup` interface that extends `Subscription` since not all subscriptions are groups (singular subscriptions don't need `add()`).

---

### 2.4 Remove @internal from disposeAll or change visibility

**What is expected:** Remove the `@internal` annotation from `Subscriptions::disposeAll()` if it's meant to be part of the public API, OR change visibility to `private`/`protected` if it's truly internal.

**Why:** Marked `@internal` but has `public` visibility. This inconsistency between annotation and actual visibility could confuse API consumers.

**Severity:** LOW

**Conditions for success:**
- `@internal` annotation removed if `disposeAll()` is public API
- OR visibility changed to `private`/`protected` if truly internal

**Related code locations:**
- `candy-async/src/Subscriptions.php:80-91` — `disposeAll()` method

---

## Phase 3: Missing Features [PENDING]

- [ ] **3.1 Add awaitAll helper**
- [ ] **3.2 Add awaitAny helper**
- [ ] **3.3 Add periodicSubscription helper**
- [ ] **3.4 Add leading/trailing edge options for debounce/throttle**
- [ ] **3.5 Add flush capability to debounce**

### 3.1 Add awaitAll helper

**What is expected:** Add static method `AsyncOps::awaitAll(PromiseInterface ...$promises): PromiseInterface` that wraps `React\Promise\all()`.

**Why:** Common async pattern — await all promises before continuing. Consumers currently have to use ReactPHP's promise functions directly.

**Severity:** HIGH

**Conditions for success:**
- `AsyncOps::awaitAll()` method added
- Wraps `React\Promise\all()`
- Returns a promise that resolves when all input promises resolve
- Returns a promise that rejects when any input promise rejects
- Tests added

**Related code locations:**
- `candy-async/src/AsyncOps.php` — add method here
- `candy-async/tests/AsyncOpsTest.php` — add tests

---

### 3.2 Add awaitAny helper

**What is expected:** Add static method `AsyncOps::awaitAny(PromiseInterface ...$promises): PromiseInterface` that wraps `React\Promise\race()`.

**Why:** Common async pattern — resolve as soon as any promise resolves. Consumers currently have to use ReactPHP's promise functions directly.

**Severity:** HIGH

**Conditions for success:**
- `AsyncOps::awaitAny()` method added
- Wraps `React\Promise\race()`
- Returns a promise that resolves/rejects when the first input promise resolves/rejects
- Tests added

**Related code locations:**
- `candy-async/src/AsyncOps.php` — add method here
- `candy-async/tests/AsyncOpsTest.php` — add tests

---

### 3.3 Add periodicSubscription helper

**What is expected:** Add static method that creates a `Subscription` from a `Loop::addPeriodicTimer()`:

```php
public static function periodicSubscription(
    LoopInterface $loop,
    float $seconds,
    \Closure $produce,
): Subscription
```

**Why:** No utility to create a `Subscription` from a periodic timer. This is a common pattern in TEA programs where a subscription fires repeatedly.

**Severity:** MEDIUM

**Conditions for success:**
- `AsyncOps::periodicSubscription()` method added
- Returns a `Subscription` that can be unsubscribed
- On unsubscribe, the periodic timer is cancelled
- Tests added

**Related code locations:**
- `candy-async/src/AsyncOps.php` — add method here
- `candy-async/tests/AsyncOpsTest.php` — add tests
- `candy-async/src/Subscription.php` — return type

**Investigation notes:**
The implementation should use a private implementation class or an anonymous class that implements `Subscription` and holds the timer reference. On `unsubscribe()`, cancel the timer.

---

### 3.4 Add leading/trailing edge options for debounce/throttle

**What is expected:** Add an options array parameter to debounce and throttle:

```php
AsyncOps::debounce(
    callable $fn,
    float $seconds,
    ?LoopInterface $loop = null,
    bool $leading = false,
    bool $leadingAndTrailing = false,
): callable
```

**Why:** Most debounce/throttle implementations support:
- Trailing edge (default in this library) — fire after quiet period
- Leading edge — fire immediately on first call
- Both — fire immediately on first call AND after quiet period

This library only supports trailing edge for debounce and leading edge for throttle.

**Severity:** MEDIUM

**Conditions for success:**
- Debounce supports `leading=true` option to fire immediately on first call
- Throttle supports `trailing=true` option to fire after cooldown period
- Tests added for both new modes
- Backward compatible (default behavior unchanged)

**Related code locations:**
- `candy-async/src/AsyncOps.php:159-175` — debounce method
- `candy-async/src/AsyncOps.php:186-204` — throttle method
- `candy-async/tests/AsyncOpsTest.php` — add tests

---

### 3.5 Add flush capability to debounce

**What is expected:** Return an object or array from debounce that includes both the debounced function and a flush method:

```php
public static function debounceWithFlush(
    callable $fn,
    float $seconds,
    ?LoopInterface $loop = null,
): array{callable, callable(): void}
```

**Why:** Sometimes you want to trigger the debounced function immediately without waiting for the quiet period.

**Severity:** MEDIUM

**Conditions for success:**
- `AsyncOps::debounceWithFlush()` method added
- Returns array with `['fn' => debouncedFunction, 'flush' => flushFunction]`
- Calling `flush()` triggers the function immediately if there are pending arguments
- If no arguments are pending, flush is a no-op
- Tests added

**Related code locations:**
- `candy-async/src/AsyncOps.php` — add method here
- `candy-async/tests/AsyncOpsTest.php` — add tests

---

## Phase 4: Improvements & Polish [PENDING]

- [ ] **4.1 Populate lang/en.php translations**
- [ ] **4.2 Add jitter to retry backoff**
- [ ] **4.3 Add AsyncOpsInterface for testability**
- [ ] **4.4 Add CancellationToken to Promise chain**
- [ ] **4.5 Document debounce/throttle memory behavior**
- [ ] **4.6 Document CancellationSource::onCancel delegation**

### 4.1 Populate lang/en.php translations

**What is expected:** Either populate `lang/en.php` with actual translation strings for the 'async' namespace, OR remove the `Lang` class entirely if i18n isn't needed for this library.

**Why:** The translation file contains only an empty array with no keys. All `Lang::t()` calls return the namespaced key as a fallback. While technically functional, it means the i18n system isn't actually being used.

**Severity:** LOW

**Conditions for success:**
- `lang/en.php` has at least some useful translation keys
- OR `Lang` class is removed if not needed
- Tests pass

**Related code locations:**
- `candy-async/lang/en.php` — empty array
- `candy-async/src/Lang.php` — translation facade
- `candy-async/tests/LangTest.php` — existing tests

---

### 4.2 Add jitter to retry backoff

**What is expected:** Add optional `$jitter` parameter (0.0-1.0) to `retry()` that adds randomization to the backoff to prevent thundering herd problems.

```php
public static function retry(
    callable $operation,
    int $attempts = 3,
    float $baseBackoffSeconds = 0.1,
    ?CancellationToken $token = null,
    float $jitter = 0.0,
): PromiseInterface
```

**Why:** Only exponential backoff is supported. Adding jitter can prevent thundering herd problems when multiple clients retry simultaneously.

**Severity:** LOW

**Conditions for success:**
- New `$jitter` parameter added with default 0.0 (no change to existing behavior)
- When jitter > 0, backoff is randomized within range `[backoff * (1 - jitter), backoff * (1 + jitter)]`
- Tests added

**Related code locations:**
- `candy-async/src/AsyncOps.php:86-102` — retry method signature
- `candy-async/src/AsyncOps.php:129-133` — where backoff is used

---

### 4.3 Add AsyncOpsInterface for testability

**What is expected:** Create an `AsyncOpsInterface` and have `AsyncOps` implement it, enabling consumer libraries to mock `AsyncOps` in tests.

**Why:** The class is `final` with all static methods, making it hard to mock in tests for consumer libraries.

**Severity:** LOW

**Conditions for success:**
- `AsyncOpsInterface` created with all public static method signatures
- `AsyncOps` implements `AsyncOpsInterface`
- Tests can mock the interface

**Related code locations:**
- `candy-async/src/AsyncOps.php:22` — final class
- `candy-async/src/AsyncOpsInterface.php` — new file

---

### 4.4 Add CancellationToken to Promise chain

**What is expected:** Consider making `CancellationToken` implement `React\Promise\PromiseInterface` or at least provide a way to use it in promise chains.

**Why:** Implementing the promise interface would allow cancellation tokens to be used directly in promise chains. The `Cancellable` interface recognition could allow tokens to be awaited.

**Severity:** LOW

**Conditions for success:**
- Investigation: Is implementing `PromiseInterface` on `CancellationToken` feasible?
- If yes, implementation added
- If no, documentation added explaining why

**Related code locations:**
- `candy-async/src/CancellationToken.php` — class to potentially modify
- `candy-async/tests/CancellationTokenTest.php` — add tests

---

### 4.5 Document debounce/throttle memory behavior

**What is expected:** Add clear documentation to the debounce and throttle methods explaining the memory retention behavior of the returned closures.

**Why:** The returned closures capture `$timer` (debounce) or `$cooldown` (throttle) by reference. If a caller creates a debounced function but loses all references to it before the timer fires, the closure and its captured state remain in memory until the timer completes or is cancelled.

**Severity:** LOW

**Conditions for success:**
- Docblock comments updated with memory behavior warning
- Callers know to ensure they call the debounced function one final time or explicitly drop references

**Related code locations:**
- `candy-async/src/AsyncOps.php:150-175` — debounce method
- `candy-async/src/AsyncOps.php:177-204` — throttle method

---

### 4.6 Document CancellationSource::onCancel delegation

**What is expected:** Document clearly which is the intended public API for registering cancellation callbacks: `$source->onCancel()` or `$source->token()->onCancel()`.

**Why:** `CancellationSource::onCancel` duplicates `CancellationToken::onCancel`. This means there are two ways to register a cancellation callback, which is confusing.

**Severity:** LOW

**Conditions for success:**
- Clear documentation added to both methods
- One is designated as the recommended public API

**Related code locations:**
- `candy-async/src/CancellationSource.php:63-66` — onCancel delegation
- `candy-async/src/CancellationToken.php:57-65` — onCancel method

---

## Phase 5: Loop Consistency Fix [PENDING]

- [ ] **5.1 Add loop parameter to retry()**

### 5.1 Add loop parameter to retry()

**What is expected:** Add `?LoopInterface $loop = null` parameter to `retry()` method and use it instead of `\React\EventLoop\Loop::get()` in `retryAttempt()`.

**Why:** The `retry()` function doesn't accept a `LoopInterface` parameter, and the recursive `retryAttempt()` uses `Loop::get()` directly. If a caller passed a different loop to `withTimeout()` but not to `retry()`, there's an inconsistency.

**Severity:** MEDIUM

**Conditions for success:**
- `retry()` accepts optional `LoopInterface $loop` parameter
- `retryAttempt()` receives and uses the loop instead of `Loop::get()`
- Default behavior unchanged (uses `Loop::get()` when null)
- Tests pass with explicit loop

**Related code locations:**
- `candy-async/src/AsyncOps.php:86-102` — retry method signature
- `candy-async/src/AsyncOps.php:131` — `Loop::get()->addTimer(...)`

---

## Phase 6: Performance & Memory Documentation [PENDING]

- [ ] **6.1 Document performance characteristics of debounce/retry**
- [ ] **6.2 Document memory behavior of closure captures**

### 6.1 Document performance characteristics of debounce/retry

**What is expected:** Add documentation to relevant methods explaining performance characteristics.

**Why:**
- debounce() cancels and reschedules timer on every call — standard implementation, likely fine
- retry() recursive chain creates nested Deferreds — natural limit from exponential backoff, minor concern
- Subscriptions stores array and iterates on unsubscribe — fine for small counts

**Severity:** LOW

**Conditions for success:**
- Docblocks updated with performance notes where relevant

**Related code locations:**
- `candy-async/src/AsyncOps.php:150-175` — debounce
- `candy-async/src/AsyncOps.php:104-148` — retryAttempt
- `candy-async/src/Subscriptions.php:85-91` — disposeAll

---

### 6.2 Document memory behavior of closure captures

**What is expected:** Document that retry operation closure is captured through the recursive chain, and once retries complete, the promise chain may still hold references.

**Why:** Standard promise behavior but worth noting for long retry chains. Callers should be aware of reference retention.

**Severity:** LOW

**Conditions for success:**
- Documentation added to retry method

**Related code locations:**
- `candy-async/src/AsyncOps.php:104-148` — retryAttempt

---

## Verification

After implementation, verify:

1. All existing tests pass: `cd candy-async && vendor/bin/phpunit`
2. `composer validate` passes (without --strict since dev deps are expected)
3. `tools/check-path-repos.php` runs clean if dependencies changed
4. No import conflicts between `SugarCraft\Async\SubscriptionHandle` and `SugarCraft\Core\Subscription` in consuming libs

---

## Notes

- 2026-06-30: Implementation plan created based on code review findings in `findings/candy-async.md`
- Renaming Async\Subscription to SubscriptionHandle and Subscriptions to SubscriptionGroup requires updates across all consuming libraries
- All HIGH priority items should be completed before 1.0 release
