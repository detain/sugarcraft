# Code Review: candy-async

**Library:** sugarcraft/candy-async  
**Reviewer:** Code Audit  
**Date:** 2026-06-29  
**Files Reviewed:** src/AsyncOps.php, src/CancellationSource.php, src/CancellationToken.php, src/Cancellable.php, src/Subscription.php, src/Subscriptions.php, src/Suspended.php, src/Lang.php, tests/ (5 test files), lang/en.php, composer.json

---

## Summary

`candy-async` provides foundational async utilities for the SugarCraft TUI ecosystem: cancellation tokens (CancellationSource/CancellationToken/Cancellable), subscription management (Subscription/Subscriptions), and AsyncOps helpers (withTimeout, retry, debounce, throttle) built on ReactPHP. The implementation is generally sound with good test coverage, but there are several issues ranging from API design concerns to missing functionality worth addressing before 1.0.

---

## 1. Issues and Problems

### 1.1 CancellationToken::markCancelled is public but marked @internal (HIGH)

**File:** `src/CancellationToken.php:45`

```php
public function markCancelled(): void
```

This method is marked `@internal` but has `public` visibility. Any consumer can call `$token->markCancelled()` directly, bypassing the intended `CancellationSource::cancel()` flow that flips the source's `$cancelled` flag. While the test at `CancellationTokenTest.php:176` verifies idempotency, a consumer could still corrupt the token state.

**Recommendation:** Change visibility to `private` or `protected`. If CancellationSource needs access, consider using a package-internal mechanism or make it a package-private method outside the public API.

### 1.2 AsyncOps::retry uses a shared static neverToken (MEDIUM)

**File:** `src/AsyncOps.php:25`, `src/AsyncOps.php:99`

```php
private static ?CancellationToken $neverToken = null;
$token ??= self::$neverToken ??= CancellationSource::new()->token();
```

A single static `$neverToken` is shared across ALL `retry()` calls that don't provide an explicit token. If any retry operation gets cancelled via this token, it permanently corrupts the shared sentinel. Subsequent retry calls that rely on it would find an already-cancelled token.

**Recommendation:** Remove the shared static cache. Always create a fresh CancellationSource/token per retry call, or require callers to provide an explicit cancellation token.

### 1.3 Subscription interface missing extended methods (LOW)

**File:** `src/Subscription.php`

The `Subscription` interface only defines `unsubscribe()` and `isActive()`, but `Subscriptions::compose()` adds `count()` and `isEmpty()` and `add()` as public methods on the implementation. Callers who type-hint on the interface can't access these useful methods without instanceof checks.

**Recommendation:** Either extend the `Subscription` interface to include these methods, or extract a `SubscriptionGroup` interface that extends `Subscription` for composite subscriptions.

### 1.4 Subscriptions::disposeAll is public but @internal (LOW)

**File:** `src/Subscriptions.php:85`

```php
public function disposeAll(): void
```

Marked `@internal` but has public visibility. This inconsistency between annotation and actual visibility could confuse API consumers.

**Recommendation:** Remove the `@internal` annotation if it's meant to be part of the public API, or change visibility to `private`/`protected`.

### 1.5 lang/en.php is empty — no translations provided (LOW)

**File:** `lang/en.php`

The translation file contains only an empty array with no keys. All `Lang::t()` calls return the namespaced key as a fallback. While this is technically functional, it means the i18n system isn't actually being used.

**Recommendation:** Either populate the translation file with actual strings or remove the Lang class entirely if i18n isn't needed for this library.

### 1.6 AsyncOps::retry doesn't handle synchronous exceptions from the operation callable (MEDIUM)

**File:** `src/AsyncOps.php:118`

```php
return $operation()->then(
```

If `$operation()` throws a synchronous exception before returning a promise, it won't be caught by the retry logic — it will propagate outward as an unhandled exception. The retry logic only handles promise rejections.

**Recommendation:** Wrap the `$operation()` call in a try-catch and convert synchronous exceptions into rejected promises.

---

## 2. Performance Bottlenecks

### 2.1 debounce() cancels and reschedules timer on every call (LOW)

**File:** `src/AsyncOps.php:168-173`

```php
if ($timer !== null) {
    $loop->cancelTimer($timer);
}
$timer = $loop->addTimer($seconds, ...);
```

Each call to the debounced function cancels the existing timer and creates a new one. For very high-frequency calls in tight loops, this could add up. However, this is the standard debounce implementation, so it's likely fine.

### 2.2 retry() recursive chain creates nested Deferreds (LOW)

**File:** `src/AsyncOps.php:130-145`

Each retry attempt creates a new `Deferred` and chains it with a timer. For the maximum 3 attempts, this creates a chain of nested Deferreds. With higher attempt counts or longer backoff chains, this could contribute to call stack depth. The exponential backoff naturally limits the number of retries, so this is a minor concern.

### 2.3 Subscriptions stores array and iterates on unsubscribe (LOW)

**File:** `src/Subscriptions.php:87-90`

```php
foreach ($this->subscriptions as $subscription) {
    $subscription->unsubscribe();
}
```

For a small number of subscriptions (typical case), this is fine. But if someone composes hundreds of subscriptions, unsubscribing could be slow. Consider using a more efficient collection structure if large subscription counts are expected.

---

## 3. Memory Leaks

### 3.1 debounce/throttle returned closures hold references until timer fires (LOW)

**File:** `src/AsyncOps.php:167-175`, `src/AsyncOps.php:194-203`

The returned closures capture `$timer` (debounce) or `$cooldown` (throttle) by reference, and both closures capture the `$loop` and `$fn`. If:
- A caller creates a debounced function but loses all references to it before the timer fires, OR
- The caller loses references but the timer hasn't fired yet

...then the closure and its captured state remain in memory until the timer completes or is cancelled.

**Recommendation:** Document this behavior clearly. Callers should ensure they call the debounced function one final time or explicitly drop references if they want immediate cleanup.

### 3.2 Retry operation closure captured through recursive chain (LOW)

**File:** `src/AsyncOps.php:107-147`

The `$operation` callable is captured in each recursive `retryAttempt()` closure. Once retries complete (success or failure), the promise chain may still hold references to the last attempt's closure. This is standard promise behavior but worth noting for long retry chains.

### 3.3 CancellationToken callbacks array cleared after firing — but token may persist (LOW)

**File:** `src/CancellationToken.php:79`

```php
$this->callbacks = [];
```

Callbacks are cleared after firing, but the `CancellationToken` itself (and its reference to `CancellationSource`) may persist in the application if the token is still referenced elsewhere.

---

## 4. Overly Complex Logic Blocks

### 4.1 retryAttempt recursive implementation is complex (MEDIUM)

**File:** `src/AsyncOps.php:107-147`

The `retryAttempt` method uses a recursive pattern with a Deferred and timer to schedule the next attempt. The logic chains through:

1. Call `$operation()->then(success, failure)`
2. On failure, check cancellation and remaining attempts
3. If retrying, create new Deferred, schedule timer with next `retryAttempt` call
4. Chain the inner promise to the deferred

This is a non-trivial control flow that could be simplified. However, the comment at line 129 says "Schedule the next attempt with a future tick to avoid blocking the loop," which explains why it uses this approach rather than a simple recursive call.

**Recommendation:** Consider extracting the retry logic into a dedicated RetryHandler class that encapsulates the state machine more clearly, or add more inline comments explaining the flow.

### 4.2 withTimeout timer vs promise resolution ordering is subtle (LOW)

**File:** `src/AsyncOps.php:37-75`

The sequence is:
1. Register promise resolve/reject handlers that cancel the timer (lines 50-65)
2. Schedule the timeout timer (line 68)

If the promise resolves before the timeout fires, the timer is cancelled. If both happen in the same event loop tick (promise already resolved when withTimeout is called), the then() callbacks fire synchronously before the timer is ever added to the loop. This is correct but subtle.

**Recommendation:** Add a clarifying comment explaining this ordering.

### 4.3 Subscription self-composition guard is buried (LOW)

**File:** `src/Subscriptions.php:44-47`

```php
if ($subscription === $this) {
    // Guard against self-composition to prevent infinite recursion.
    return;
}
```

This guard prevents a `Subscriptions` instance from being added to itself, avoiding infinite recursion in `unsubscribe()`. It's correct but not obvious at first glance.

**Recommendation:** Add the guard at the top of `add()` with a clear comment explaining why it's necessary.

---

## 5. Security Issues

### 5.1 GitDaemon preg_match could potentially be bypassed (HIGH - in consuming lib, not core)

**File:** `candy-serve/src/Git/GitDaemon.php:245`

```php
if (\preg_match('/^(git-upload-pack|git-receive-pack)\s+(\/[^\s\n]+)\s*\n/', $client['buffer'], $matches)) {
```

This regex is used to parse the git protocol. While it looks correct for valid input, improper handling of malformed input could potentially cause issues in the git command construction at line 412 where `$repoPath` is passed to `escapeshellarg`.

**Status:** The escaping appears correct at line 411-412. This is in the consuming library `candy-serve`, not in `candy-async` itself.

### 5.2 CancellationToken callback exceptions propagate unpredictably (MEDIUM)

**File:** `src/CancellationToken.php:76-78`

```php
foreach ($this->callbacks as $callback) {
    $callback();
}
```

If any `onCancel` callback throws an exception, the remaining callbacks in the list won't be called. Additionally, the exception will propagate up through whatever code called `cancel()`. There's no error handling here.

**Recommendation:** Wrap each callback call in a try-catch and log/report errors rather than letting them propagate and potentially skip remaining callbacks.

### 5.3 No input validation on callback callables (LOW)

The `Cancellable::onCancel` interface says callbacks "must not throw" but there's no enforcement. If a callback does throw, behavior is undefined.

---

## 6. Ways to Improve Code and Usability

### 6.1 Add awaitAll / awaitAny helpers (HIGH)

**Missing feature.** Common async patterns like `Promise::all()` (await all) and `Promise::race()` (await any) are not wrapped in this library. Consumers have to use ReactPHP's promise functions directly.

**Recommendation:** Add static helpers in `AsyncOps`:
- `AsyncOps::awaitAll(PromiseInterface ...$promises): PromiseInterface`
- `AsyncOps::awaitAny(PromiseInterface ...$promises): PromiseInterface`

### 6.2 Add a helper to create timer-based subscriptions (MEDIUM)

**Missing feature.** There's no utility to create a `Subscription` from a `Loop::addPeriodicTimer()`. This is a common pattern in TEA programs.

**Recommendation:** Add a helper like:
```php
AsyncOps::periodicSubscription(
    LoopInterface $loop,
    float $seconds,
    \Closure $produce,
): Subscription
```

### 6.3 Add leading/trailing edge configuration to debounce/throttle (MEDIUM)

**Missing feature.** Most debounce/throttle implementations support leading edge (fire immediately on first call), trailing edge (fire after quiet period), or both. This library only supports trailing edge for debounce and leading edge for throttle.

**Recommendation:** Add an options array parameter:
```php
AsyncOps::debounce(callable $fn, float $seconds, ?LoopInterface $loop = null, bool $leading = false): callable
```

### 6.4 Add a way to flush debounce early (MEDIUM)

**Missing feature.** Sometimes you want to trigger the debounced function immediately without waiting for the quiet period.

**Recommendation:** Return an object or array from debounce that includes both the debounced function and a flush method:
```php
$debounced = AsyncOps::debounceWithFlush($fn, 0.15);
// ... later when you want to flush immediately:
$debounced['flush']();
```

### 6.5 Add interface for AsyncOps to enable mocking (LOW)

**File:** `src/AsyncOps.php:22`

```php
final class AsyncOps
```

The class is `final` with all static methods, making it hard to mock in tests for consumer libraries. Consider extracting an interface.

**Recommendation:** Create an `AsyncOpsInterface` and have `AsyncOps` implement it, or provide a factory method that returns the implementation.

### 6.6 Add jitter to retry backoff (LOW)

**File:** `src/AsyncOps.php:129`

Only exponential backoff is supported. Adding jitter (randomization) to the backoff can prevent thundering herd problems when multiple clients retry simultaneously.

**Recommendation:** Add an optional `$jitter` parameter (0.0-1.0) that adds randomization to the backoff.

### 6.7 CancellationToken could implement PromiseLike from react/promise (LOW)

**File:** `src/CancellationToken.php`

Implementing `React\Promise\PromiseInterface` (or at least `Cancellable` interface recognition) would allow cancellation tokens to be used directly in promise chains.

---

## 7. Missing Features the Library Should Have

1. **`awaitAll` / `awaitAny` helpers** — High priority, common pattern
2. **Timer-based subscription helper** — High priority, common in TEA apps
3. **Leading/trailing edge options for debounce/throttle** — Medium priority
4. **Flush capability for debounce** — Medium priority
5. **Jitter for retry backoff** — Low priority
6. **Streaming/chunking helpers for ReactPHP streams** — Future consideration
7. **Race condition helpers with cancellation** — Future consideration

---

## 8. Basic Duplicated Logic That Could Be Refactored

### 8.1 CancellationSource::onCancel duplicates CancellationToken::onCancel (LOW)

**File:** `src/CancellationSource.php:63-66`

```php
public function onCancel(callable $callback): void
{
    $this->token->onCancel($callback);
}
```

This is a simple delegation, but it means there are two ways to register a cancellation callback: `$source->onCancel()` and `$source->token()->onCancel()`. This is confusing.

**Recommendation:** Document clearly which is the intended public API, or consolidate to a single path.

### 8.2 Subscription counting logic duplicated in Subscriptions (LOW)

**File:** `src/Subscriptions.php:70-78`

```php
public function count(): int
{
    return count($this->subscriptions);
}

public function isEmpty(): bool
{
    return $this->subscriptions === [];
}
```

These are simple array operations, but they could use SPL counting interfaces for consistency.

---

## 9. Compatibility Problems with Other SugarCraft Libs

### 9.1 Naming collision: Async\Subscription vs Core\Subscription (HIGH)

**Files:** `src/Subscription.php` vs `candy-core/src/Subscription.php`

Both `SugarCraft\Async\Subscription` (RxJS-style disposal handle) and `SugarCraft\Core\Subscription` (Elm-style event subscription value object) have the same class name but completely different semantics. This causes confusion when importing both namespaces.

**Status:** Libraries that use both (like `candy-serve` using `SugarCraft\Async\Subscription`) could have import conflicts.

**Recommendation:** Consider renaming:
- `SugarCraft\Async\Subscription` → `SugarCraft\Async\SubscriptionHandle`
- `SugarCraft\Async\Subscriptions` → `SugarCraft\Async\SubscriptionGroup`

Or use distinct naming that clarifies the different semantics.

### 9.2 AsyncOps::retry uses Loop::get() internally regardless of caller's loop (MEDIUM)

**File:** `src/AsyncOps.php:131`

```php
\React\EventLoop\Loop::get()->addTimer(...)
```

The `retry()` function doesn't accept a `LoopInterface` parameter, and the recursive `retryAttempt()` uses `Loop::get()` directly. If a caller passed a different loop to `withTimeout()` but not to `retry()`, there's an inconsistency.

**Status:** This isn't currently a problem because `retry()` doesn't accept a loop parameter, but it could become one if the API is extended.

### 9.3 Dependencies are appropriate (NO ISSUE)

The library correctly depends on:
- `react/event-loop: ^1.6`
- `react/promise: ^3.3`
- `sugarcraft/candy-core: dev-master`

And several libs correctly depend on `candy-async`:
- candy-core (i18n/Lang)
- candy-forms (CancellationSource for async suggestions)
- candy-serve (Subscription/Subscriptions for cleanup)
- sugar-post (CancellationToken, AsyncOps for SMTP)
- sugar-tick (CancellationSource, CancellationToken)
- sugar-prompt, sugar-reel, sugar-query

---

## 10. How Async Patterns Could Be Improved (ReactPHP Ecosystem)

### 10.1 No streaming/chunking support (MEDIUM)

The library focuses on single-promise operations. It doesn't provide helpers for working with ReactPHP's `ReadableStreamInterface` or `WritableStreamInterface`. In a TUI ecosystem, this could be useful for handling terminal input/output streams.

### 10.2 No integration with ReactPHP's deferred Rhein AMQP or other async libraries (LOW)

The library is purely self-contained. It could benefit from integration helpers for common ReactPHP ecosystem packages (e.g., `react/cache`, `react/http-client`, etc.).

### 10.3 Cancellation pattern could adopt Go-style context.Context (MEDIUM)

**File:** `src/CancellationToken.php`

The current implementation mirrors .NET's CancellationTokenSource. Go's `context.Context` adds:
- Deadline/timeout support
- Value storage for request-scoped data
- Automatic propagation through call chains

**Recommendation:** Consider adding `CancellationToken::withDeadline()` and `CancellationToken::withValue()` methods for more powerful cancellation contexts.

### 10.4 No native async iterator/generator support (LOW)

ReactPHP provides `React\Promise\Deferred::resolve($iterator)` for streaming promises. The library doesn't provide helpers for this pattern.

### 10.5 Event loop ownership could be more explicit (LOW)

The CALIBER_LEARNINGS.md correctly notes: "ReactPHP event loop is shared — do not construct multiple loops." But the API could enforce this better by:
- Requiring loop injection in more places
- Documenting loop ownership clearly
- Providing a loop accessor that returns the singleton

---

## Test Coverage Assessment

The library has excellent test coverage:
- **AsyncOpsTest** — 12 tests covering withTimeout, debounce, throttle, retry (all paths including edge cases)
- **CancellationTokenTest** — 12 tests covering source/token behavior, callback ordering, idempotency
- **SubscriptionsTest** — 11 tests covering composition, disposal, edge cases (self-composition guard, empty compose)
- **SuspendedTest** — 5 tests covering resume callable and state
- **LangTest** — 2 tests covering fallback behavior

**Missing test cases:**
- No test for `CancellationSource::new()` returning distinct instances with separate cancellation state (though CancellationTokenTest:139 tests token distinctness)
- No test for `Subscription::count()` / `isEmpty()` from the interface perspective
- No integration tests with actual ReactPHP event loop in complex scenarios

---

## Recommendations Priority Matrix

| Priority | Issue | Effort |
|----------|-------|--------|
| HIGH | Rename Async\Subscription to avoid collision with Core\Subscription | Low |
| HIGH | Add awaitAll/awaitAny helpers | Medium |
| HIGH | Fix CancellationToken::markCancelled visibility | Low |
| HIGH | Fix AsyncOps::retry shared static token issue | Low |
| MEDIUM | Add periodic timer subscription helper | Medium |
| MEDIUM | Wrap $operation() in try-catch in retry | Low |
| MEDIUM | Add leading/trailing edge options | Low |
| MEDIUM | Add flush capability to debounce | Low |
| MEDIUM | Add jitter to retry backoff | Low |
| MEDIUM | Exception handling in onCancel callbacks | Low |
| LOW | Simplify retryAttempt implementation | Medium |
| LOW | Add AsyncOpsInterface for testability | Medium |
| LOW | Add CancellationToken to Promise chain | Medium |
| LOW | Populate lang/en.php translations | Low |
| LOW | Document loop ownership more explicitly | Low |

---

## Conclusion

`candy-async` is a well-structured library with good test coverage and clear separation of concerns. The cancellation token pattern is well-implemented, and the AsyncOps helpers provide useful abstractions on top of ReactPHP. The main concerns are:

1. **API design**: Naming collision between Async\Subscription and Core\Subscription needs resolution before more libraries adopt this
2. **Safety**: The shared static `neverToken` in retry() is a latent bug that should be fixed
3. **Completeness**: Several common async patterns (awaitAll, flushable debounce, timer subscriptions) are missing

The library is production-ready for the patterns it currently supports, but should address the HIGH priority items before a 1.0 release.
