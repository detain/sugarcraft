# sugar-async

> Formerly published as `sugarcraft/candy-async` (renamed 2026-10). <!-- @rename-keep -->

Shared async utilities for SugarCraft — cancellation tokens, subscriptions, disposable timer handles, fan-in combinators, and a bounded cache-aside store — built on ReactPHP.

## Overview

`sugar-async` provides the foundational async vocabulary used across the SugarCraft TUI ecosystem:

- **Cancellation tokens** — `CancellationSource` / `CancellationToken` / `Cancellable` for coordinated cancellation across async operations
- **Subscriptions** — `Subscription` handles from `onCancel()` / `debounce()` / `throttle()` and `Subscriptions::compose()` for managing lifecycles in Model–Update–View apps
- **Suspension** — `Suspended` value-object for commands paused across update cycles
- **AsyncOps** — static helpers: `withTimeout`, `withDeadline`, `cancellable`, `singleFlight`, `retry`, `retryBounded`, `nap`, `reconnect`, `drain`, `all`, `race`, `pendingNotice`, `boundWait`, `debounce`, `throttle`
- **AsyncCache** — promise-library-only async cache-aside: monotonic TTL, in-flight loader coalescing, optional serve-stale-on-error, optional entry bound with introspection

### Why async in a terminal app

A TUI cannot block: blocking is a frozen screen. Every render tick needs the
event loop free, so slow work (HTTP, DNS, subprocesses, deferred queries) must
run as promises that settle *between* frames. This library is the shared
vocabulary for that pattern — timeouts you can see as values, cancellation you
can hand to a worker, and coalescing so an impatient user mashing a key causes
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
- `sugarcraft/sugar-core` (shared `Lang` base class; this lib's message catalogue is English-literals-by-design, see LangTest)

## Installation

```bash
composer require sugarcraft/sugar-async
```

The library requires `sugarcraft/sugar-core` under a `dev-master` constraint —
this package ships on the monorepo's dev track. A fresh project on default
`minimum-stability: stable` may therefore fail to resolve the require; add
`"minimum-stability": "dev"` with `"prefer-stable": true` to the consuming root
project (or pin an explicit dev constraint on the sibling packages) so composer
may pick the branch.

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
  surprise the canceller. "Logged" means `error_log()` by default: under the
  default PHP configuration the line lands on stderr, and a TUI tearing down
  mid-frame may see a stray interleaved line corrupt the in-progress render —
  so `AsyncOps::setLogSink($callable)` exists to redirect those notices into
  the host's own diagnostics instead, and `setLogSink(null)` restores the
  default. The hook-failure sites are the cancellation callbacks
  (`CancellationToken`), the `retry` `onRetry:` and `pendingNotice` hooks
  (`AsyncOps`), and the disposal pass behind
  `Subscriptions::unsubscribe()`.
  `CancellationToken::none()` is a shared never-cancelled
  sentinel for "no token here" plumbing: its `onCancel()` hands back an
  already-spent handle and nothing is ever retained.
- Late registration never misses: calling `onCancel()` on an already-cancelled
  token fires the callback immediately — synchronously, before the call
  returns — and hands back an already-spent handle, so nothing is retained for
  a cancellation that has already happened. A callback that throws on this
  immediate path is logged through the same seam as `cancel()` — registration
  cannot surface what cancellation contains.

### Cancellation cookbook

Unregistration exists and is the discipline every long-lived consumer needs:
`onCancel()` hands back a `Subscription`, and `AsyncOps::cancellable()` is the
extract-and-dispose idiom the library's own wrappers use.

```php
use SugarCraft\Async\{AsyncOps, CancellationSource};
use React\Promise\Deferred;

$source = CancellationSource::new();
$token = $source->token();
$fired = [];

// Drop-the-registration: keep the handle onCancel() returns and
// unsubscribe() before the token fires — the hook never runs, and the
// token never accumulates a closure it will never call.
$dropped = $token->onCancel(static function () use (&$fired): void {
    $fired[] = 'dropped-hook';
});
$dropped->unsubscribe();

// Extract-and-dispose: cancellable() ties a promise's lifetime to the
// token; a cancel() on the source rejects the guard with an
// OperationCancelledException carrying the label.
$work = (new Deferred())->promise();
$guarded = AsyncOps::cancellable($work, $token, null, 'checkout');
$guarded->then(null, static function (\Throwable $e) use (&$fired): void {
    $fired[] = $e::class;
});

$token->onCancel(static function () use (&$fired): void {
    $fired[] = 'kept-hook';
});
$source->cancel();

// The dropped registration never ran; the kept hook and the guard's
// rejection did.
echo implode("\n", $fired), "\n";
```

Cancellation is not an error. Happy paths resolve; catch cancellation as its own
arm, not as a failed request:

```php
use SugarCraft\Async\{AsyncOps, CancellationSource, OperationCancelledException};
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

Without ext-pcntl the mapping is the same: route your host's interrupt event to
`$source->cancel()`.

## Subscriptions

`Subscription` is the disposal handle returned by `onCancel()`, `debounce()`,
and `throttle()`.
`Subscriptions::compose()` disposes multiple subscriptions atomically;
`Subscriptions::of()` mints one from any cleanup closure, so hand-owned
resources join the composite without a producer that hands out handles:

```php
use SugarCraft\Async\{CancellationSource, Subscriptions};

$source = CancellationSource::new();

// Two real handles: a token registration and a self-minted resource owner.
$composite = Subscriptions::compose(
    $source->token()->onCancel(static function (): void {
        echo "worker told to stop\n";
    }),
    Subscriptions::of(static function (): void {
        echo "socket closed\n"; // stand-in for fclose($socket) / removeListener(...)
    }),
);

$composite->unsubscribe(); // cleanup runs once; the registration is dropped
$source->cancel();         // the dropped callback no longer runs — the leak guard
```

For handles that arrive over time the composite doubles as a running
tracker: `Subscriptions::new()` starts empty, `add()` collects each handle as
a producer hands it out, and one final `unsubscribe()` disposes everything
collected. An `add()` onto an already-unsubscribed composite disposes the
incoming member immediately — the tracker never keeps a subscription for a
disposal that already happened.

## Suspension

`Suspended` represents a paused command as data: it carries a `resume` callable
and an optional opaque `state`. It is a container, not a scheduler: nothing in
this library stores the handle or resumes it for you. A hosting runtime *may*
adopt it as a storable command value — keep the `Suspended`, call `resume()`
later when the subscription fires or the model decides to continue, and dispatch
what comes back — so effects that span multiple update cycles (animations,
debounced input, async handlers) never execute eagerly. `resume()` **returns** the paused Cmd closure (or
`null` if there is nothing to resume); dispatching it is the caller's job. The
handle is **fire-once by law**: the resumed latch flips on the first call, so a
second `resume()` always returns `null` — even when the first call handed back a
live closure that was never dispatched. A handle shared across two schedulers
can therefore never double-fire its command. In code:

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

`withTimeout`, `withDeadline`, `cancellable`, `retry`, `all`, `race`,
`pendingNotice` and `boundWait` are stateless helpers; `debounce` and `throttle` return **invokable
`Subscription` handles** that retain timer/cooldown state; `singleFlight` returns
a stateful coalescing closure. Where a helper takes a `LoopInterface`, passing
`null` binds the shared `Loop::get()`.

Abandonment triage — what each wrapper does to the inner operation when the
wait ends, and what happens when the caller cancels the wrapper:

| Wrapper | When the wait ends on its own | When the caller cancels the wrapper |
|---|---|---|
| `withTimeout` | The inner runs on untouched — the timeout rejects the wait and ends nothing else. | The wrapper releases the timer and rejects with `OperationCancelledException`; the inner keeps running. |
| `withDeadline` | At expiry the wrapper cancels the inner and fires the `onTimeout` hook best-effort, then rejects with `TimeoutException`. | The wrapper runs the same full teardown: the inner is cancelled, the hook fires best-effort, and the wait rejects with `OperationCancelledException`. |
| `cancellable` | Whichever side settles first wins; a late inner result after the token fires is dropped. | The wrapper fires the `onCancel` hook and the inner's `cancel()` best-effort, unsubscribes its token registration, and rejects with `OperationCancelledException`. |
| `retry` | A token fired between attempts stops the cycle with `OperationCancelledException`; a token fired during an attempt cancels the in-flight attempt and — when the driver ignores that abort — rejects immediately instead of waiting for it. | The wrapper stops the cycle, cancels the last dispatched attempt best-effort, releases every backoff timer, and drops its registration on the caller's token. |
| `singleFlight` | One flight per key settles for every joined caller. | The join is not abandonable by design — wrap the join in `cancellable()` when the wait itself must be abandonable. |
| `debounce` / `throttle` | A superseded or suppressed call resolves `null` — nothing failed; with `trailing: true` the last suppressed call still fires. | `unsubscribe()` drops the pending burst and any queued trailing call, each resolving `null`. |
| `all` / `race` | The fan-in mirrors its members: `all()` waits for every one, `race()` settles with the first to finish; members are never cancelled. | The fan-in has no cancel path of its own — cancel the member promises directly. |
| `pendingNotice` | The notice fires after N seconds and the wait continues — a slow operation is announced, never interrupted. | The wrapper releases the notice timer and rejects with `OperationCancelledException`. |
| `boundWait` | At expiry the wrapper rejects with a `TimeoutException` whose message names `(still pending)` while the inner keeps running. | The wrapper releases the wait timer and rejects with `OperationCancelledException`. |

- `withTimeout(promise, seconds, loop:, label:)` — rejects with `TimeoutException`
  after N seconds. The inner operation is NOT cancelled and keeps running to
  completion. The timeout timer is armed before the inner's settle handlers attach,
  so an already-settled inner cancels it in the same synchronous pass — no timer is
  left holding the loop. Cancelling the *wrapper* abandons the wait: the timer is
  released and the wrapper rejects with `OperationCancelledException` (the inner
  keeps running — this is a wait-abandonment, not an operation cancel).
- `withDeadline(promise, seconds, loop:, onTimeout:, label:)` — the variant that
  stops the work: rejects after N seconds AND `cancel()`s the inner, with an optional
  `onTimeout` abort hook (fired once, errors swallowed) for server-side kills the
  driver's own cancellation cannot perform. Best-effort in the literal sense: a
  throwing hook and a throwing inner `cancel()` are both caught silently, so a
  failed server-side kill is *unobservable* here — the rejection is the timeout,
  never a receipt that the abort worked. A bounded I/O ceiling — not a blanket
  per-request wall-clock killer.
  Both wrappers take the promise first and the loop as a trailing optional
  parameter (`loop:` omitted → shared `Loop::get()`). The historical
  loop-first positional form still transposes with an `E_USER_DEPRECATED`
  notice for one more release — loop-first support is REMOVED-IN 2.0.0.
- `cancellable(promise, token:, onCancel:, label:)` — the returned promise rejects
  immediately with `OperationCancelledException` when the token fires, a late inner
  result is dropped, and an optional `onCancel` hook fires at most once as a
  best-effort abort of the underlying work — likewise silently fault-tolerant: if
  the hook or the inner `cancel()` throws, nothing propagates and you cannot tell
  the abort failed. Passing `null` for `token:` returns the inner promise
  UNWRAPPED — no registration, no overhead. The wrapper unsubscribes its own
  token registration the moment the operation settles OR is abandoned, so a hung
  driver cannot accumulate dead closures on a long-lived token.
- `singleFlight(keyFn, worker)` — coalescing callable: concurrent calls with the
  same key share ONE worker invocation and the same promise; the slot frees on
  settle (success, failure, or synchronous throw). The partition is the key
  string alone — the flight is not scoped by caller: any concurrent call whose
  `keyFn` yields the same key joins the same flight, whoever made it. A worker
  that consults per-caller authorization (identity, permission set) MUST fold
  that identity into the key, or a lower-privilege caller rides a
  higher-privilege caller's fetch. The shared flight is deliberately
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
    logged via `error_log()` — stderr by default, see the callback note above —
    and never propagated.
- `debounce(fn, seconds, ?loop:, source:)` → `Debounced` — only the last call in a
  burst fires. Each call returns a promise: the scheduled call resolves with the
  result (rejects if the work throws), superseded calls resolve `null`. `flush()`
  returns that PromiseInterface and runs the pending burst immediately — give it
  the same `->then(null, fn($e) => …)` rejection handler any promise here needs
  (see the unhandled-rejection note below), or a rejecting burst dies silently on
  the discard pile; `unsubscribe()` drops it (its promise
  resolves `null`) and spends the handle — later calls answer `null` without
  arming timers. A `source:` cancels the handle alongside its pending burst.
- `throttle(fn, seconds, ?loop:, trailing: false)` → `Throttled` — fires at most
  once per window. Suppressed calls resolve `null` (they are visible in the
  promise chain, not silently ignored). With `trailing: true` the LAST suppressed
  call fires at window end (earlier suppressed ones stay superseded to `null`),
  and that trailing fire consumes a fresh window — the once-per-window cap holds
  at every edge, not only at the first. `flush()` runs a queued trailing call
  immediately and ends the cooldown (its outcome arrives on the suppressed
  call's own promise, so flush itself returns void); `unsubscribe()` drops any
  queued trailing call — its promise resolves `null`.
- `all(promises)` / `race(promises)` — fan-in with keys preserved: `all` resolves
  a keyed list (`[]` resolves immediately) and rejects with the first error;
  `race` settles with the first outcome — on empty input it THROWS
  `InvalidArgumentException` synchronously at the call site (no promise is ever
  produced to carry a rejection; a race nobody entered has no answer to give).
- `pendingNotice(promise, seconds, onPending, loop:)` — pure notice combinator:
  if the promise is still pending after N seconds the REQUIRED advisory hook
  runs once ("Still waiting on login…") and the wait continues; the inner is
  never cancelled by this helper, and a hook that throws is logged, never
  propagated.
- `boundWait(promise, seconds, loop:, label:)` — bounded wait that stops the
  waiting, not the work: if the promise is still pending after N seconds the
  derived promise rejects with a `TimeoutException` naming the abandonment
  ("… (still pending)") while the inner keeps running — bounded-wait
  semantics for user-paced actions: the wait lifts at N seconds, the inner is
  never killed — this library puts no blanket wall-clock timeout on
  operations whose pace belongs to the user.
  The deprecated `onPending()` shim delegates to this pair (hook →
  `pendingNotice()`, no hook → `boundWait()`) and leaves in 2.0.

Both failure modes surface as `RuntimeException` subclasses — `TimeoutException`
for timeouts (with structured `seconds()` / `label()` fields),
`OperationCancelledException` for cancellation — so existing
`catch (\RuntimeException $e)` sites keep working unchanged.

Exception messages are **not sanitized**: a `label:` you pass is woven verbatim
into the rejection text (`Operation "<label>" timed out …`), and a budget
exhaustion in `retry` re-quotes the last inner failure's `getMessage()` in full.
The resulting message is trusted data only — never paint it into a frame,
terminal title, or log line an attacker-controlled input could reach without
passing it through your display sanitizer first (`Sanitize::untrusted()` in
`sugar-core`, or an equivalent for your surface).

### Backoff, bounded retry, reconnect and pipe draining

`Backoff::new($baseMs, $factor, $capMs)` is an immutable wait schedule (ceiling,
optional full jitter via `withJitter()`, `serverHint()`, `withAttempts()`).
`AsyncOps` forwards to the engines behind it:

- `retryBounded(operation, shouldRetry, budgetMs, backoff)` → `Retry::bounded()`;
  blocking, result-driven, every wait clamped to the remaining budget.
- `nap(owedMicros)` → `Retry::nap()`; a sleep that is re-armed after EINTR.
- `reconnect(dial, backoff, token:, loop:, onRetry:)` → `Retry::reconnect()`;
  redials until success or cancellation; success ends the cycle.
- `drain(pipes, deadline, maxBytes)` → `ChildPump::drain()`; one select loop over
  all pipes, returning a `DrainResult`.

```php
use SugarCraft\Async\{AsyncOps, Backoff};

$tries = 0;
$result = AsyncOps::retryBounded(
    static function () use (&$tries): int { return ++$tries; },
    static fn (int $n): bool => $n < 3,
    budgetMs: 1000,
    backoff: Backoff::new(1.0, 1.0)->withoutJitter(),
);
echo $result, "\n";
```

### Argument guards

Bad arguments are refused **synchronously at the call site** with an
`InvalidArgumentException` — no promise is ever produced to carry the error, so
these throws escape a bare `->then()` chain. Verified guard set:

- `withTimeout`, `withDeadline`, `pendingNotice`, `boundWait`, `debounce`, and
  `throttle` refuse
  a seconds value that is not positive and finite.
- `retry` refuses `attempts < 1`, a non-positive-finite `baseBackoffSeconds`, a
  non-positive-finite `maxTotalSeconds` (when set), and a negative or
  non-finite `jitter:`.
- `race` refuses empty input; `singleFlight` refuses a key function that
  returns `''`.
- `AsyncCache` refuses a non-positive-finite `ttlSeconds` (constructor and
  `withTtl()`), an empty key passed to `fetch()`, and a `maxEntries` below 1
  (when set).

One deliberate exception: a loader that returns a non-promise is NOT a
synchronous throw — it lands as a rejected `fetch()` promise (the message names
the returned type), the flight slot is freed, and the next call retries. A
loader that throws synchronously takes the same failed-load path.

```php
use SugarCraft\Async\AsyncOps;
use function React\Promise\resolve;

$promise = AsyncOps::withTimeout(
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
  returned. The key is the only partition: the cache does not know *which*
  caller asked, so a load started for one caller answers every caller that
  shares the key. If the backing load is per-caller authorized, fold the caller
  identity into the key. The loader MUST return a `PromiseInterface` — anything
  else fails the load with an explicit `InvalidArgumentException` naming the
  returned type; a loader that throws synchronously counts as a failed load.
  Either way the flight slot is freed, so the next call retries.
- `withTtl()` / `withServeStaleOnError()` / `withMaxEntries()` — return new handles
  viewing the SAME store (configuration is immutable; the data is shared).
- `peek()` / `has()` / `isFresh()` / `invalidate()` / `clear()` — last-known-value
  reads for render paths that must never block, TTL probe, and scoped drops. A
  cached `null` is *present* (`has` true), disambiguated from *absent*.
- `count()` / `keys()` / `isLoaded($key)` / `ageSeconds($key)` / `maxEntries()` /
  `ttlSeconds()` / `servesStaleOnError()` — introspection: stored-entry census
  (fresh and stale alike), stored keys in insertion order, in-flight-or-fresh
  loading indicator for spinners, monotonic age of a stored value (`null` when
  never stored), and the three configuration reads (entry cap, TTL, stale
  policy).
- `serveStaleOnError` — on a failed load with an existing (expired) entry, the
  caller resolves with the stale value WITHOUT re-stamping its age, so a blip
  cannot latch the key and polling keeps retrying every tick. Read that as
  *freshness suspended, never extended*: the answer's real-world validity is
  bounded by the last successful load, and a failing authority does not renew
  it. Wrong trade for data whose freshness IS the authorization — permission
  tables, revocation lists, session stores — where serving yesterday's grant
  fails open; keep the flag off there and let the load reject. A load superseded
  by `invalidate()`/`clear()` still answers its own callers but is never adopted.

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

## Caveats

- **State does not survive `pcntl_fork`.** Every stateful surface in this
  library is per-process: a `singleFlight` closure's in-flight map, an
  `AsyncCache` store and its coalesced flights, a `Debounced` handle's pending
  burst, a `Throttled` handle's cooldown and queued trailing call, and a
  `CancellationSource`'s registered callbacks. Sharing one of these across a
  fork boundary means settles in one process never propagate to the other —
  the parent's flight slot leaks waiting on a child's completion, and a
  callback registered before the fork fires in whichever process cancels.
  Mint these objects after forking, in the process that will use them.

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
- **`composer` resolves an old `candy-async`.** The package was renamed to
  `sugarcraft/sugar-async` in the 2026-10 rename; `class_alias` shims were never
  shipped — update use statements.
- Issues: <https://github.com/detain/sugarcraft/issues>

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
