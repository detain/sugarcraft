<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Contract for a component that can be cancelled.
 *
 * Mirrors the cancellation token pattern from .NET and Go's context.
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
     * Requests cancellation. Idempotent \u2014 calling multiple times has no additional effect.
     * All registered onCancel callbacks fire in registration order, exactly once.
     */
    public function cancel(): void;

    /**
     * Register a callback to fire when cancellation is requested.
     * The callback receives no arguments and must not throw.
     *
     * @param callable(): void $callback
     */
    public function onCancel(callable $callback): void;
}
