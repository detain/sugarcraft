<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Owns the mutable cancellation state and hands consumers the token view;
 * triggering belongs by convention to this source's cancel().
 *
 * Create via CancellationSource::new(). Call cancel() to request cancellation
 * of all consumers holding the token.
 *
 * See {@see Cancellable} for the family's canonical upstream citation.
 */
final class CancellationSource implements Cancellable
{
    private CancellationToken $token;

    public function __construct()
    {
        $this->token = new CancellationToken($this);
    }

    /**
     * Factory for idiomatic construction.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Returns the read-only token for this source.
     */
    public function token(): CancellationToken
    {
        return $this->token;
    }

    /**
     * The state lives in the token (single source of truth): a cancellation
     * raised through the token's @internal paths is visible here too, so the
     * source and its token can never disagree on isCancelled().
     */
    public function isCancelled(): bool
    {
        return $this->token->isCancelled();
    }

    /**
     * Requests cancellation. Idempotent — calling multiple times is a no-op.
     * All callbacks registered on the token fire exactly once, in order.
     * A throwing callback is logged and never propagates from here.
     */
    public function cancel(): void
    {
        if ($this->token->isCancelled() === true) {
            return;
        }
        $this->token->notifyCancellation();
    }

    /**
     * Register a cancellation callback on this source's token.
     * Part of the Cancellable contract — the contract makes it public.
     * Returns the removal handle; see CancellationToken::onCancel().
     *
     * @param callable(): void $callback
     */
    public function onCancel(callable $callback): Subscription
    {
        return $this->token->onCancel($callback);
    }
}
