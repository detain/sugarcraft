<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Contract for a component that can be cancelled.
 *
 * This is the family's one canonical upstream citation (siblings point here
 * instead of restating it): the shape mirrors Go's context.Context Done
 * channel, and the source/token split follows .NET's CancellationTokenSource
 * lineage.
 * Callbacks registered via onCancel() fire exactly once when cancel() is called,
 * regardless of how many times cancel() is invoked.
 */
interface Cancellable
{
    /**
     * Returns true if cancel() has been called.
     */
    public function isCancelled(): bool;

    /**
     * Requests cancellation. Idempotent — calling multiple times has no additional effect.
     * All registered onCancel callbacks fire in registration order, exactly once.
     */
    public function cancel(): void;

    /**
     * Register a callback to fire when cancellation is requested.
     * The callback receives no arguments. A throwing callback is logged and
     * never propagated — see CancellationToken::fireCallbacks() for the one
     * hook-failure contract shared across this library. Returns a handle that
     * removes the registration while it is still pending.
     *
     * @param callable(): void $callback
     */
    public function onCancel(callable $callback): Subscription;
}
