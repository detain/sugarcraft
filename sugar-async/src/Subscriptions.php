<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Manages multiple subscriptions as a single atomic unit.
 *
 * Composes many subscriptions into one handle so they can be disposed
 * with a single unsubscribe() call.
 *
 * NOTE the name collision: the sugar-core Model–Update–View runtime keeps
 * its own @internal `SugarCraft\Core\Subscriptions` and does NOT depend on
 * this library. This class is the standalone, public composition handle for
 * async consumers.
 *
 * Mirrors RxJS's Subscription group pattern.
 */
final class Subscriptions implements Subscription, \Countable
{
    /** @var list<Subscription> */
    private array $subscriptions = [];

    private bool $unsubscribed = false;

    /**
     * An empty composite, ready to accumulate via add().
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Mint a standalone disposal handle from a cleanup closure — the public
     * entry point for consumers who need a Subscription without a producer
     * that hands one out (timers, listeners, temp-file owners). The closure
     * runs at most once, from unsubscribe().
     *
     * @param \Closure(): void $onUnsubscribe
     */
    public static function of(\Closure $onUnsubscribe): Subscription
    {
        return CallbackSubscription::of($onUnsubscribe);
    }

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
     * Register a subscription on this composite as things arrive.
     *
     * The public accumulating seam: hold a composite minted by new(),
     * add() handles as producers hand them out, and one final
     * unsubscribe() disposes every member, including those registered
     * after construction. Two edges are part of the contract: adding to
     * an already-unsubscribed composite disposes the incoming
     * subscription immediately (nothing is retained for a disposal that
     * already happened), and adding the composite to itself is ignored
     * (no self-composition recursion).
     */
    public function add(Subscription $subscription): void
    {
        if ($subscription === $this) {
            // Guard against self-composition to prevent infinite recursion.
            return;
        }
        if ($this->unsubscribed === true) {
            // Already disposed — dispose the new subscription immediately.
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

    public function count(): int
    {
        return count($this->subscriptions);
    }

    public function isEmpty(): bool
    {
        return $this->subscriptions === [];
    }

    /**
     * Dispose all underlying subscriptions.
     *
     * Disposal must never break a teardown ladder: every subscription is
     * disposed, and a throwing unsubscribe() on one is logged, not
     * propagated. This is the same hook-failure contract as
     * CancellationToken::fireCallbacks() and the AsyncOps abort hooks.
     *
     * @internal
     */
    public function disposeAll(): void
    {
        // Snapshot then clear up-front so the list is empty even if a
        // subscription's unsubscribe() throws.
        $subscriptions = $this->subscriptions;
        $this->subscriptions = [];

        foreach ($subscriptions as $subscription) {
            try {
                $subscription->unsubscribe();
            } catch (\Throwable $e) {
                \error_log('sugar-async: subscription disposal failed: ' . $e->getMessage());
            }
        }
    }
}
