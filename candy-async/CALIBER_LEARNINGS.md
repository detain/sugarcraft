# candy-async Caliber Learnings

## Session-learned patterns and gotchas

*(Accumulated by future sessions — not modified at scaffold time)*

- **ReactPHP event loop is shared** — do not construct multiple loops. Pass `Loop::get()` or accept a `LoopInterface` parameter. Creating a new `StreamSelectLoop` singleton inside a library breaks consumers who already own the loop.

- **CancellationToken is read-only from outside** — consumers receive only the token, not the source. Only `CancellationSource::cancel()` can flip the flag. This is intentional: it prevents consumers from accidentally cancelling shared tokens.

- **`onCancel` callbacks fire exactly once** — even if `cancel()` is called multiple times. The implementation uses a `callbacksFired` sentinel to ensure this invariant.

- **AsyncOps::retry uses exponential backoff** — each attempt doubles the backoff. For bounded test fixtures, use small base values (e.g. 0.01s) and bounded attempt counts.

- **retry() imposes NO per-attempt operation timeout** — spacing (backoff) and operation deadline are orthogonal. A slow healthy operation is NOT force-failed by the backoff window. Wrap with withTimeout explicitly if a per-attempt deadline is needed.

- **Suspended is a value-object** — it carries a `resume` callable and optional `state`. The runtime stores it and calls `resume()` later when the subscription fires or the model continues.

- **TimeoutException extends RuntimeException** — not a dedicated subclass of any standard exception hierarchy. Catch via `SugarCraft\Async\TimeoutException` or simply `catch (\RuntimeException $e)`.

- **withTimeout must arm the timer BEFORE attaching settle handlers** — react/promise v3 settles an already-resolved inner synchronously inside `then()`; a handler attached first sees an unassigned timer, skips the cancel, and leaks the full-duration timer onto the shared loop (probe: 0.5s timeout ⇒ 0.500s idle-drain pre-fix, ~0s post-fix).

- **retry() must wire token cancellation into the pending backoff** — a cancel landing mid-backoff has no natural checkpoint: without an `onCancel` abort that cancels the pending timer and rejects the stage deferred, the promise stays pending and holds the loop for the remaining (doubling) delay. The abort is guarded by a per-stage `settled` flag because the token offers no unregister — a stale registration from an already-run stage must never fire on a later cancel().

- **Idle-drain is the timer-lifetime assertion idiom** — `$loop->run()` returns only at zero armed timers/streams, so `microtime` around it bounds any leaked timer directly; keep leaked-timer fixtures short (0.5s) so a regression costs 0.5s, not 30s.

- **CancellationToken does not retain its CancellationSource** — the former promoted `$source` was never read; cancellation needs a live source handle anyway, so a token-side keepalive only pinned unreachable memory and forced cycle-GC (probed before removal, suite green).

- **In-flight release identity doubles as the stale-adoption gate** — `InFlightMap::end(key, flight)` returns false when the slot no longer holds *this* flight, so an arrival displaced mid-flight by `invalidate()`/`clear()` still answers its own callers but can never write a superseded value back into the store. One mechanism, two invariants; the async slot map must be keyed-blind-safe this way or a doomed flight's tail silently frees the CURRENT flight's slot.

- **A failed refresh must not re-stamp the stale entry it served** — with `serveStaleOnError`, resolving the caller from `values[$key]` without touching `storedAt[$key]` is what keeps the key un-latched: the next tick re-probes a blipped upstream instead of waiting out an accidental freshness extension (E718 lesson, carried from candy-query `AdminQueryCache`).

- **withTimeout vs withDeadline is orthogonal-by-design** — timeout answers "how long will the caller wait" (inner runs on), deadline answers "how long may the work take" (inner is `cancel()`ed, plus an optional one-shot `onTimeout` abort hook for the server-side kill the driver cannot perform, e.g. MySQL KILL QUERY). Deadlines are bounded I/O ceilings computed on the remaining budget (E646 law), never blanket wall-clock killers; the inner cancel fires AFTER the caller-facing rejection so a driver whose `cancel()` re-settles the inner cannot leak through the settle guards.

- **Occupying a coalescing gate before the worker can settle needs a Deferred** — react/promise v3 settles an already-resolved promise synchronously inside `then()` (same law as the withTimeout arming rule): `singleFlight` and `AsyncCache::fetch` begin their flight slot *before* invoking the loader/worker and drive resolution through a `Deferred`, so a synchronous worker can never fork a second flight or leave the slot stuck after a synchronous throw. And in test traps, a `$this->fail()` loader must be a bound closure — a `static` closure cannot bind `$this`, and its `Error` would be swallowed into a promise rejection instead of reddening the test.
