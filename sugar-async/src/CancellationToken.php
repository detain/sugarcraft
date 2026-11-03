<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Read-only view of a cancellation state.
 *
 * Instances are created by CancellationSource. Consumers receive only the
 * token — they cannot trigger cancellation, only observe it and register
 * callbacks.
 *
 * Mirrors Go's context.Context.Done channel pattern.
 */
final class CancellationToken
{
    private bool $cancelled;

    /** @var array<int, callable(): void>  Callbacks keyed by registration id. */
    private array $callbacks;

    private int $nextCallbackId;

    /** @var bool */
    private bool $callbacksFired;

    /**
     * @param CancellationSource $source  Minting authority; deliberately NOT
     *     retained. Cancellation requires a live source handle on the caller
     *     side, so a token-side reference is dead weight that would only turn
     *     a refcount teardown into a cycle-GC candidate. The parameter stays
     *     as the construction contract: a token is only ever minted for a
     *     source.
     */
    public function __construct(
        CancellationSource $source,
    ) {
        $this->cancelled = false;
        $this->callbacksFired = false;
        $this->nextCallbackId = 0;
        $this->callbacks = [];
    }

    /**
     * Returns true if the source has been cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * @internal  Called by CancellationSource::cancel() to trigger cancellation.
     *            Consumers must use CancellationSource::cancel() — not this method.
     */
    public function notifyCancellation(): void
    {
        if ($this->cancelled === true) {
            return;
        }
        $this->cancelled = true;
        $this->fireCallbacks();
    }

    /**
     * Register a callback to fire when cancellation is requested.
     * Public contract (mirrors Cancellable::onCancel; the README shows it).
     * Fires immediately if the token is already cancelled, otherwise runs at
     * most once, when cancel() happens.
     *
     * The returned handle removes the registration while it is still pending:
     * unsubscribe() before the cancellation drops the callback without
     * running it. Long-lived tokens reused across many short operations
     * MUST unsubscribe at settlement (see AsyncOps::cancellable() and
     * AsyncOps::retry() for the pattern) — otherwise one closure accumulates
     * per operation until the token fires.
     *
     * @param callable(): void $callback
     */
    public function onCancel(callable $callback): Subscription
    {
        if ($this->cancelled) {
            // Already cancelled — fire immediately; the handle is born spent.
            $callback();
            return CallbackSubscription::settled();
        }
        $id = $this->nextCallbackId++;
        $this->callbacks[$id] = $callback;

        return CallbackSubscription::of(function () use ($id): void {
            unset($this->callbacks[$id]);
        });
    }

    /**
     * @internal
     */
    public function fireCallbacks(): void
    {
        if ($this->callbacksFired === true) {
            return;
        }
        // Callbacks fire ONLY as part of a cancellation, so firing IMPLIES
        // cancelled — clearing the list while leaving isCancelled()
        // false would desynchronise every observer on the direct
        // (@internal-misuse) call path and turn late onCancel() registrations
        // into ghosts that would never run. The legitimate flow enters
        // through notifyCancellation(), which has already raised the flag, so
        // external cancel-detection semantics for real cancels are unchanged.
        //
        // The flag and the fired mark are raised BEFORE the loop, so an
        // onCancel() registered by a callback during the fire sees a
        // cancelled token and runs immediately instead of haunting an
        // already-cleared list.
        $this->cancelled = true;
        $this->callbacksFired = true;
        // Cancellation must never fail the canceller: a throwing callback is
        // logged, not propagated. This is the single hook-failure contract
        // shared with Subscriptions::disposeAll() and the AsyncOps abort
        // hooks — teardown ladders (Ctrl-C handlers, destructors, session
        // shutdown) run to completion, and every remaining callback still
        // executes.
        foreach ($this->callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                \error_log('sugar-async: cancellation callback failed: ' . $e->getMessage());
            }
        }
        $this->callbacks = [];
    }
}
