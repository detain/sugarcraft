# candy-async Caliber Learnings

## Session-learned patterns and gotchas

*(Accumulated by future sessions — not modified at scaffold time)*

- **ReactPHP event loop is shared** — do not construct multiple loops. Pass `Loop::get()` or accept a `LoopInterface` parameter. Creating a new `StreamSelectLoop` singleton inside a library breaks consumers who already own the loop.

- **CancellationToken is read-only from outside** — consumers receive only the token, not the source. Only `CancellationSource::cancel()` can flip the flag. This is intentional: it prevents consumers from accidentally cancelling shared tokens.

- **`onCancel` callbacks fire exactly once** — even if `cancel()` is called multiple times. The implementation uses a `callbacksFired` sentinel to ensure this invariant.

- **AsyncOps::retry uses exponential backoff** — each attempt doubles the backoff. For bounded test fixtures, use small base values (e.g. 0.01s) and bounded attempt counts.

- **retry() imposes NO per-attempt operation timeout** — spacing (backoff) and operation deadline are orthogonal. A slow healthy operation is NOT force-failed by the backoff window. Wrap with withTimeout explicitly if a per-attempt deadline is needed.

- **Suspended is a value-object** — it carries a `resume` callable and optional `state`. The runtime stores it and calls `resume()` later when the subscription fires or the model continues.

- **TimeoutException extends RuntimeException** — not a dedicated subclass of any standard exception hierarchy. Catch via `AsyncOps\TimeoutException` or simply `catch (\RuntimeException $e)`.
