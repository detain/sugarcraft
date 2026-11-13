<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Subscription handle backed by a single removal closure.
 *
 * The library's disposable currency: CancellationToken::onCancel() and
 * Subscriptions::of() hand these out so a registration can be dropped before
 * it fires. The closure runs at most once, from unsubscribe(); a handle for
 * something that already ran (an immediate-fire on an already-cancelled
 * token, or a fired callback list) is born inactive.
 *
 * Mirrors RxJS's Subscription and Node's EventEmitter.removeListener — the
 * removal half, without the emitter.
 */
final class CallbackSubscription implements Subscription
{
    private bool $active;

    /**
     * @param \Closure(): void $onDispose  Removal action, run once.
     */
    private function __construct(
        private readonly \Closure $onDispose,
        bool $active,
    ) {
        $this->active = $active;
    }

    /**
     * A live handle whose registration still awaits disposal.
     *
     * @param \Closure(): void $onDispose
     */
    public static function of(\Closure $onDispose): self
    {
        return new self($onDispose, true);
    }

    /**
     * A spent handle: what the caller gets when the callback has already
     * fired immediately, so the subscription never lived here. Unsubscribing
     * is a no-op, and isActive() reports false from birth.
     */
    public static function settled(): self
    {
        return new self(static function (): void {
        }, false);
    }

    public function unsubscribe(): void
    {
        if ($this->active !== true) {
            return;
        }
        $this->active = false;
        ($this->onDispose)();
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
