<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Manages multiple subscriptions as a single atomic unit.
 *
 * Used in the TEA model's subscriptions() lifecycle \u2014 when the
 * model returns many subscriptions, they are composed into one handle
 * so the runtime can dispose them all with a single unsubscribe() call.
 *
 * Mirrors RxJS's Subscription group pattern.
 */
final class Subscriptions implements Subscription
{
    /** @var list<Subscription> */
    private array $subscriptions = [];

    private bool $unsubscribed = false;

    /**
     * Compose multiple subscriptions into a single atomic handle.
     *
     * @param Subscription ...$subscriptions
     */
    public static function compose(Subscription ...$subscriptions): self
    {
        $composer = new self();
        foreach ($subscriptions as $sub) {
            $composer->add($sub);
        }
        return $composer;
    }

    /**
     * Add a subscription to this composite.
     *
     * @internal
     */
    public function add(Subscription $subscription): void
    {
        if ($this->unsubscribed) {
            // Already disposed \u2014 dispose the new subscription immediately.
            $subscription->unsubscribe();
            return;
        }
        $this->subscriptions[] = $subscription;
    }

    public function unsubscribe(): void
    {
        if ($this->unsubscribed) {
            return;
        }
        $this->unsubscribed = true;
        $this->disposeAll();
    }

    public function isActive(): bool
    {
        return !$this->unsubscribed;
    }

    /**
     * Dispose all underlying subscriptions.
     *
     * @internal
     */
    public function disposeAll(): void
    {
        foreach ($this->subscriptions as $subscription) {
            $subscription->unsubscribe();
        }
        $this->subscriptions = [];
    }
}
