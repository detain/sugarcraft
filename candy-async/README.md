# candy-async

Shared async utilities for SugarCraft — cancellation tokens, subscriptions, and AsyncOps helpers built on ReactPHP.

## Overview

`candy-async` provides the foundational async vocabulary used across the SugarCraft TUI ecosystem:

- **Cancellation tokens** — `CancellationSource` / `CancellationToken` / `Cancellable` for coordinated cancellation across async operations
- **Subscriptions** — `Subscription` interface and `Subscriptions::compose()` for managing TEA-style subscription lifecycles
- **Suspension** — `Suspended` value-object for TEA commands paused across update cycles
- **AsyncOps** — static helpers for `withTimeout`, `withDeadline`, `cancellable`, `singleFlight`, `retry`, `debounce`, and `throttle` operations
- **AsyncCache** — promise-library-only async cache-aside: TTL, in-flight loader coalescing, optional serve-stale-on-error

## Quickstart

```php
use SugarCraft\Async\{AsyncOps, CancellationSource, Subscriptions};

$source = CancellationSource::new();

// Attach a cancellation callback
$source->token()->onCancel(fn() => echo "Cancelled!\n");

$source->cancel(); // prints "Cancelled!"

// Timeout wrapper
$loop = \React\EventLoop\Loop::get();
$promise = AsyncOps::withTimeout($loop, $somePromise, 5.0);

// Retry with backoff
$promise = AsyncOps::retry(
    fn() => $httpClient->request('GET', 'https://example.com'),
    attempts: 3,
    baseBackoffSeconds: 0.5,
);

// Debounce rapid calls
$debounced = AsyncOps::debounce(fn($input) => process($input), 0.15);
$debounced('a');
$debounced('b');
$debounced('c'); // only this fires, 150ms after last call
```

## Requirements

- PHP 8.3+
- `react/event-loop: ^1.6`
- `react/promise: ^3.3`

## Installation

```bash
composer require sugarcraft/candy-async
```

## Architecture

### Cancellation

`CancellationSource` owns the mutable cancellation flag. It exposes a read-only `CancellationToken` to consumers. When `cancel()` is called:

1. The flag is flipped (idempotent)
2. All callbacks registered via `onCancel()` fire in registration order, exactly once

This pattern allows cancellation to propagate without the consumer being able to trigger it themselves.

### Subscriptions

`Subscription` is the disposal handle returned by subscribe-style APIs. `Subscriptions::compose()` lets multiple subscriptions be disposed atomically:

```php
$composite = Subscriptions::compose($sub1, $sub2, $sub3);
$composite->unsubscribe(); // disposes all three
```

### Suspension

`Suspended` represents a paused TEA command as data: it carries a `resume`
callable and an optional opaque `state`. The runtime stores the `Suspended` and
calls `resume()` later — when the subscription fires or the model decides to
continue — so effects that span multiple update cycles (animations, debounced
input, async handlers) never execute eagerly:

```php
use SugarCraft\Async\Suspended;

$paused = new Suspended(fn() => $laterCmd, $carryMe);
$cmd = $paused->resume(); // dispatched when the pause ends
```

### AsyncOps

withTimeout, withDeadline, cancellable and retry are stateless helpers. debounce, throttle and singleFlight return stateful closures that retain mutable timer/cooldown/in-flight state. All helpers work via Promise plumbing and `LoopInterface` timers where timers are involved at all:

- `withTimeout` — wraps a promise; rejects with `TimeoutException` after N seconds. The inner operation is NOT cancelled and keeps running to completion. The timeout timer is armed before the inner's settle handlers attach, so an already-settled inner cancels it in the same synchronous pass — no timer is left holding the loop.
- `withDeadline` — the cancelling sibling of `withTimeout`: rejects with `TimeoutException` after N seconds AND `cancel()`s the inner, with an optional `onTimeout` abort hook (fired once, errors swallowed) for the server-side kill the driver's own cancellation cannot perform. A bounded I/O ceiling computed on the remaining budget — not a blanket per-request wall-clock killer. Extracted from the candy-query `QueryTimeout` pattern.
- `cancellable` — binds a promise to a `CancellationToken`: the returned promise rejects immediately with `OperationCancelledException` when the token fires, a late inner result is dropped, and an optional `onCancel` hook aborts the underlying work exactly once. Passing `null` returns the promise untouched. Extracted from the candy-query `CancellableQuery` pattern.
- `singleFlight` — `(keyFn, worker) => coalescing callable`: concurrent calls with the same key share ONE worker invocation and the same promise; the slot frees on settle (success, failure, or synchronous throw), so the next wave re-runs — request coalescing, not caching. Extracted from the phlix `ApiClient::refreshInFlight` pattern.
- `retry` — retries a failed operation up to N times with exponential backoff (the delay doubles per failure; no per-attempt timeout — wrap with `withTimeout` for one). Optional knobs:
  - `token:` — a `CancellationToken`. Cancellation is checked before each attempt and honored mid-backoff: the pending backoff timer is cancelled and the promise rejects immediately with `OperationCancelledException`.
  - `maxTotalSeconds:` — wall-clock budget across all attempts; once exceeded, retry aborts with the last failure even if attempts remain.
  - `jitter:` — each backoff delay is randomized within `[backoff, backoff * (1 + jitter)]`, spreading fleets of clients so retries don't form a thundering herd. `0.0` (default) keeps the exact exponential schedule.
- `debounce` — only the last call within the window fires, after silence
- `throttle` — fires at most once per interval, ignoring excess calls

Both failure modes surface as `RuntimeException` subclasses — `TimeoutException` for deadlines, `OperationCancelledException` for cancellation — so existing `catch (\RuntimeException $e)` sites keep working unchanged.

### AsyncCache

`AsyncCache` is a generic cache-aside primitive extracted from the candy-query `AdminQueryCache` pattern. It is promise-library-only (react/promise v3): it arms **no timers** itself — freshness is computed at read time, and any per-load timeout stays the caller's concern (compose with `withDeadline`).

- `AsyncCache::new(ttlSeconds: 60.0, serveStaleOnError: false)` — entry point; `ttlSeconds` must be positive.
- `fetch(string $key, callable(): PromiseInterface $loader)` — fresh hit resolves immediately; a load already in flight for the key is joined (ONE loader run serves all concurrent callers); otherwise the loader starts a flight whose result is stored and returned. A loader that throws synchronously counts as a failed load.
- `withTtl()` / `withServeStaleOnError()` — return new handles viewing the SAME store (configuration is immutable; the data is shared).
- `peek()` / `has()` / `isFresh()` / `invalidate()` / `clear()` — last-known-value reads for render paths that must never block, TTL probe, and scoped drops. A cached `null` is *present* (`has` true), disambiguated from *absent*.
- `serveStaleOnError` — on a failed load with an existing (expired) entry, the caller resolves with the stale value WITHOUT re-stamping its age, so a blip cannot latch the key and polling keeps retrying every tick (the E718 lesson). A load superseded by `invalidate()`/`clear()` still answers its own callers but is never adopted into the store.

## License

MIT
