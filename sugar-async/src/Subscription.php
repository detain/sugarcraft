<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Contract for a subscription handle returned by onCancel(), debounce(), and
 * throttle().
 *
 * The subscriber calls unsubscribe() to dispose of the subscription.
 * isActive() reports whether the subscription is still live.
 *
 * Cancelling is not an error: unsubscribe() disposes quietly, and a handle
 * whose awaited work was dropped resolves with a null outcome rather than
 * rejecting — this is the cancel-is-not-an-error rule the debounce and
 * throttle handles inherit.
 *
 * Mirrors RxJS's Subscription pattern and Node's EventEmitter.removeListener.
 */
interface Subscription
{
    /**
     * Dispose of the subscription. Idempotent — calling multiple times is a no-op.
     */
    public function unsubscribe(): void;

    /**
     * Returns true if the subscription has not yet been unsubscribed.
     */
    public function isActive(): bool;
}
