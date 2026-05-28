<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Contract for a subscription handle returned by subscribe()-style APIs.
 *
 * The subscriber calls unsubscribe() to dispose of the subscription.
 * isActive() reports whether the subscription is still live.
 *
 * Mirrors RxJS's Subscription pattern and Node's EventEmitter.removeListener.
 */
interface Subscription
{
    /**
     * Dispose of the subscription. Idempotent \u2014 calling multiple times is a no-op.
     */
    public function unsubscribe(): void;

    /**
     * Returns true if the subscription has not yet been unsubscribed.
     */
    public function isActive(): bool;
}
