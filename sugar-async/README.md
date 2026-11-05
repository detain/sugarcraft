# sugar-async

> Formerly published as `sugarcraft/candy-async` (renamed 2026-10). <!-- @rename-keep -->

Shared async utilities for SugarCraft — cancellation tokens, subscriptions, disposable timer handles, fan-in combinators, and an async cache-aside — built on ReactPHP.

## Overview

`sugar-async` provides the foundational async vocabulary used across the SugarCraft TUI ecosystem:

- **Cancellation tokens** — `CancellationSource` / `CancellationToken` / `Cancellable` for coordinated cancellation across async operations
- **Subscriptions** — `Subscription` handles from `onCancel()` / `debounce()` / `throttle()` and `Subscriptions::compose()` for managing lifecycles in Model–Update–View apps
- **Suspension** — `Suspended` value-object for commands paused across update cycles
- **AsyncOps** — static helpers: `withTimeout`, `withDeadline`, `cancellable`, `singleFlight`, `retry`, `all`, `race`, `onPending`, `debounce`, `throttle`
- **AsyncCache** — promise-library-only async cache-aside: monotonic TTL, in-flight loader coalescing, optional serve-stale-on-error, optional entry bound with introspection

### Why async in a terminal app

A TUI cannot block: blocking is a frozen screen. Every render tick needs the
event loop free, so slow work (HTTP, DNS, subprocesses, deferred queries) must
run as promises that settle *between* frames. This library is the shared
vocabulary for that pattern — timeouts you can see as values, cancellation you
can hand to a worker, and coalescing so a impatient user mashing a key causes
exactly one request.

### When NOT to use it

- Plain CLI scripts that run once and exit — a synchronous `usleep` loop is simpler.
- CPU-bound work: a promise never moves computation off the current thread; a hung
  blocking call inside a "deferred" still blocks the loop.
- Anywhere `react/event-loop` is not the driving loop (an external scheduler such as
  Revolt) — every timer here is armed on a `LoopInterface`.

## Requirements

- PHP 8.3+
- `react/event-loop: ^1.6`
- `react/promise: ^3.3`
- `sugarcraft/sugar-core` (shared exception-message formatting)

## Installation

```bash
composer require sugarcraft/sugar-async
```

## Cancellation

`CancellationSource` owns the mutable cancellation flag. It exposes a read-only
`CancellationToken` to consumers. When `cancel()` is called:

1. The flag is flipped (idempotent)
2. All callbacks registered via `onCancel()` fire in registration order, exactly once

This pattern propagates cancellation from the owner. The boundary is
trigger-restricted by convention, not by visibility: `notifyCancellation()` and
`fireCallbacks()` carry only an `@internal` marker and are public, so a consumer
holding the token *can* fire it — do not hand tokens to untrusted code. Two
further guarantees:

- `onCancel()` returns a `Subscription`: unsubscribe before the token fires and the
  callback never runs; the library's own wrappers (`cancellable`, `retry`) drop
  their registrations the moment the operation settles, so long-lived tokens do not
  accumulate closures.
- A callback that throws is **logged, never propagated** — teardown paths cannot
  surprise the canceller. `CancellationToken::none()` is a shared never-cancelled
  sentinel for "no token here" plumbing: its `onCancel()` hands back an
  already-spent handle and nothing is ever retained.

Cancellation is not an error. Happy paths resolve; catch cancellation as its own
arm, not as a failed request:

```php
use SugarCraft\Async\{AsyncOps, CancellationSource, OperationCancelledException, TimeoutException};
use React\Promise\Deferred;

$source = CancellationSource::new();
// A genuinely pending operation: the guard stays unsettled until the token fires.
$guarded = AsyncOps::cancellable((new Deferred())->promise(), $source->token());
$source->cancel();

$guarded->then(
    static function (mixed $value): void {
        echo "resolved: ", var_export($value, true), "\n";
    },
    static function (\Throwable $e): void {
        // The cancel arm is ordinary control flow, not a failure report.
        echo $e instanceof OperationCancelledException ? "cancelled cleanly\n" : "real failure: {$e->getMessage()}\n";
    },
);
```

In a TUI, `Ctrl-C` maps to this: flip the token, do not print a stack trace. A
sketch with `pcntl_signal`:

```php
use SugarCraft\Async\CancellationSource;

$source = CancellationSource::new();

// In a loop-driving program with ext-pcntl, map Ctrl-C to the token:
//   pcntl_async_signals(true);
//   pcntl_signal(SIGINT, static function () use ($source): void { $source->cancel(); });
// A SIGINT-triggered cancel() is ordinary teardown, not an error report:
// downstream catch arms see OperationCancelledException, never a stack trace.
echo $source->token()->isCancelled() ? "cancelled\n" : "awaiting Ctrl-C\n";
```

## Subscriptions

`Subscription` is the disposal handle returned by subscribe-style APIs.
`Subscriptions::compose()` lets multiple subscriptions be disposed atomically:

```php
use SugarCraft\Async\Subscriptions;

$composite = Subscriptions::compose(
    Subscriptions::new(), // leaf no-op handle, stand-in for real subscriptions below
    Subscriptions::new(),
);
$composite->unsubscribe(); // disposes all members
```

## Suspension

`Suspended` represents a paused command as data: it carries a `resume` callable
and an optional opaque `state`. The runtime stores the `Suspended` and calls
`resume()` later — when the subscription fires or the model decides to continue —
so effects that span multiple update cycles (animations, debounced input, async
handlers) never execute eagerly. `suspended.resume()` **returns** the paused Cmd closure (or `null` if there is
nothing to resume); dispatching it is the caller's job:

```php
use SugarCraft\Async\Suspended;

$suspended = new Suspended(
    static fn (): \Closure => static fn (): string => 'continue-msg',
    'carry-me',
);
$cmd = $suspended->resume();          // ?Closure — the paused command as data
$msg = $cmd !== null ? $cmd() : null; // 'continue-msg' when dispatched
echo $msg, "\n";
```

## AsyncOps

`withTimeout`, `withDeadline`, `cancellable`, `retry`, `all`, `race`, and
`onPending` are stateless helpers; `debounce` and `throttle` return **invokable
`Subscription` handles** that retain timer/cooldown state; `singleFlight` returns
a stateful coalescing closure. Where a helper takes a `LoopInterface`, passing
`null` binds the shared `Loop::get()`.

- `withTimeout(?loop, promise, seconds, label:)` — rejects with `TimeoutException`
  after N seconds. The inner operation is NOT cancelled and keeps running to
  completion. The timeout timer is armed before the inner's settle handlers attach,
  so an already-settled inner cancels it in the same synchronous pass — no timer is
  left holding the loop. Cancelling the *wrapper* abandons the wait: the timer is
  released and the wrapper rejects with `OperationCancelledException` (the inner
  keeps running — this is a wait-abandonment, not an operation cancel).
- `withDeadline(?loop, promise, seconds, onTimeout:, label:)` — the cancelling
  sibling: rejects after N seconds AND `cancel()`s the inner, with an optional
  `onTimeout` abort hook (fired once, errors swallowed) for server-side kills the
  driver's own cancellation cannot perform. A bounded I/O ceiling — not a blanket
  per-request wall-clock killer.
- `cancellable(promise, token, onCancel:)` — the returned promise rejects
  immediately with `OperationCancelledException` when the token fires, a late inner
  result is dropped, and an optional `onCancel` hook aborts the underlying work
  exactly once. The wrapper unsubscribes its own token registration at settlement.
- `singleFlight(keyFn, worker)` — coalescing callable: concurrent calls with the
  same key share ONE worker invocation and the same promise; the slot frees on
  settle (success, failure, or synchronous throw). The shared flight is deliberately
  not caller-abandonable — wrap the returned promise with `AsyncOps::cancellable()`
  to give callers an exit without stranding followers.
- `retry(operation, attempts: 3, baseBackoffSeconds: 0.1, token:, maxTotalSeconds:, jitter: 0.0, ?loop:, now:, onRetry:)`
  — runs the operation up to `attempts` times **in total** with exponential backoff
  (delay doubles per failure; no per-attempt timeout — wrap with `withTimeout`).
  - `token:` — cancellation is checked before each attempt and honored mid-backoff:
    the pending backoff timer is cancelled and the promise rejects with
    `OperationCancelledException`. Abandoning the returned promise stops the cycle
    the same way (the internal stop source fires; the last dispatched attempt is
    cancelled best-effort).
  - `maxTotalSeconds:` — monotonic budget across all attempts. A backoff that would
    overrun the budget is clamped to the remaining time; when the budget is spent,
    retry rejects with a distinct `TimeoutException` ("Retry budget of N exhausted
    after attempt M …") whose `getPrevious()` carries the last failure.
  - `jitter:` — each delay is randomized within `[backoff, backoff × (1 + jitter)]`,
    so fleets of clients do not retry in lockstep (thundering herd). `0.0`
    (default) keeps the exact exponential schedule.
  - `onRetry:` — advisory progress hook `(int $retryNumber, float $delaySeconds)`
    fired when each backoff is scheduled (spinner copy, metrics). Hook errors are
    logged, never propagated.
- `debounce(fn, seconds, ?loop:, source:)` → `Debounced` — only the last call in a
  burst fires. Each call returns a promise: the scheduled call resolves with the
  result (rejects if the work throws), superseded calls resolve `null`. `flush()`
  runs the pending burst immediately; `unsubscribe()` drops it (its promise
  resolves `null`) and spends the handle — later calls answer `null` without
  arming timers. A `source:` cancels the handle alongside its pending burst.
- `throttle(fn, seconds, ?loop:, trailing: false)` → `Throttled` — fires at most
  once per window. Suppressed calls resolve `null` (they are visible in the
  promise chain, not silently ignored). With `trailing: true` the LAST suppressed
  call fires at window end (earlier suppressed ones stay superseded to `null`).
  `unsubscribe()` cancels any queued trailing call.
- `all(promises)` / `race(promises)` — fan-in with keys preserved: `all` resolves
  a keyed list (`[]` resolves immediately) and rejects with the first error;
  `race` settles with the first outcome — on empty input it THROWS
  `InvalidArgumentException` synchronously at the call site (no promise is ever
  produced to carry a rejection; a race nobody entered has no answer to give).
- `onPending(promise, seconds, onPending:, ?loop:, label:)` — pure notice
  combinator: if the promise is still pending after N seconds the advisory hook
  runs once ("Still waiting on login…"); the inner is never cancelled by this
  helper. Without a hook the derived promise rejects with a `TimeoutException`
  naming the abandonment ("… (still pending)") while the inner keeps running —
  bounded-wait semantics for user-paced actions, honoring the no-blanket-timeout
  law.

Both failure modes surface as `RuntimeException` subclasses — `TimeoutException`
for timeouts (with structured `seconds()` / `label()` fields),
`OperationCancelledException` for cancellation — so existing
`catch (\RuntimeException $e)` sites keep working unchanged.

```php
use SugarCraft\Async\AsyncOps;
use function React\Promise\resolve;

$promise = AsyncOps::withTimeout(
    loop: null,                      // shared Loop::get()
    promise: resolve('fast'),        // already settled: no timer is left armed
    seconds: 5.0,
    label: 'login',
);
$promise->then(static fn (mixed $v): string => (string) $v);
```

## AsyncCache

`AsyncCache` is a generic cache-aside primitive. It arms **no timers** itself —
freshness is judged against a monotonic clock (`hrtime`) at read time, and any
per-load timeout stays the caller's concern (compose with `withDeadline`).

- `AsyncCache::new(ttlSeconds: 60.0, serveStaleOnError: false, clock: null, maxEntries: null)`
  — entry point; `ttlSeconds` must be a positive finite number; `clock` is a
  monotonic-seconds seam for tests (no sleeping at TTL boundaries); `maxEntries`
  caps stored values — beyond it, adoption evicts the LEAST-recently-stored entry.
  Expired entries are NOT eagerly reaped without a cap: memory follows caller key
  discipline (`invalidate()`/`clear()`).
- `fetch(string $key, callable $loader)` — fresh hit resolves immediately; a load
  already in flight for the key is joined (ONE loader run serves all concurrent
  callers); otherwise the loader starts a flight whose result is stored and
  returned. The loader MUST return a `PromiseInterface` — anything else fails the
  load with an explicit `InvalidArgumentException` naming the returned type; a
  loader that throws synchronously counts as a failed load. Either way the flight
  slot is freed, so the next call retries.
- `withTtl()` / `withServeStaleOnError()` / `withMaxEntries()` — return new handles
  viewing the SAME store (configuration is immutable; the data is shared).
- `peek()` / `has()` / `isFresh()` / `invalidate()` / `clear()` — last-known-value
  reads for render paths that must never block, TTL probe, and scoped drops. A
  cached `null` is *present* (`has` true), disambiguated from *absent*.
- `count()` / `keys()` / `isLoaded($key)` / `ageSeconds($key)` / `maxEntries()` —
  introspection: stored-entry census (fresh and stale alike), in-flight-or-fresh
  loading indicator for spinners, monotonic age of a stored value (`null` when
  never stored).
- `serveStaleOnError` — on a failed load with an existing (expired) entry, the
  caller resolves with the stale value WITHOUT re-stamping its age, so a blip
  cannot latch the key and polling keeps retrying every tick. A load superseded by
  `invalidate()`/`clear()` still answers its own callers but is never adopted.

```php
use SugarCraft\Async\AsyncCache;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

final class FakeClock
{
    public function __construct(private float $now = 1000.0)
    {
    }

    public function __invoke(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}

$clock = new FakeClock();
$cache = AsyncCache::new(ttlSeconds: 10.0, clock: $clock);

// A real loader would hit the network; resolve() keeps the demo loop-free.
$cache->fetch('weather', static fn (): PromiseInterface => resolve('sunny'))->then(
    static function (mixed $value): void {
        echo "loaded: $value\n";
    },
);
$cache->fetch('weather', static fn (): PromiseInterface => resolve('ignored'))->then(
    static function (mixed $value): void {
        echo "served from cache: $value\n";   // fresh hit: loader not re-run
    },
);
```

## Testing with AsyncOps

The suite runs on `StreamSelectLoop` and never sleeps past a test bound. Two
laws learned the hard way:

- **Arm-time clock staleness.** Under ext-uv, deadlines are computed against a
  clock refreshed once per loop iteration: a timer armed after a long synchronous
  idle is already overdue. `sugar-testing`'s `LoopPin::pinStableClock()` (called
  from `tests/bootstrap.php`) keeps that impossible; if you add a `retry`-shaped
  test that arms its first timer after synchronous setup, keep the idle below
  the armed bound (or the pin below `bound − largest later-armed delay`).
- **`ProgramSimulator` does not drive timers.** It pumps stdin only; promises
  waiting on `Loop::get()` never settle under it. For async TUI flows, run a real
  loop with a guard stop-timer, or resolve fixtures synchronously:

```php
use function React\Promise\resolve;

$loop = new \React\EventLoop\StreamSelectLoop();
$guard = $loop->addTimer(1.0, static function () use ($loop): void {
    $loop->stop();
});
resolve('x')->then(static function () use ($loop, $guard): void {
    $loop->cancelTimer($guard);
    $loop->stop();
});
$loop->run();
```

## Troubleshooting

- **A rejected promise exits 0 with no message.** ReactPHP does not print
  unhandled rejections. Attach a rejection handler (`.then(null, ...)`) or an
  `otherwise()` arm in examples and tests, always.
- **A timeout that never fires.** The loop must actually run: `Loop::run()` (or
  your host program's loop) drives every timer this library arms.
- **`Composer` resolves an old `candy-async`.** The package was renamed to
  `sugarcraft/sugar-async` in the 2026-10 rename; `class_alias` shims were never
  shipped — update use statements.
- Issues: <https://github.com/detain/sugarcraft/issues>

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
