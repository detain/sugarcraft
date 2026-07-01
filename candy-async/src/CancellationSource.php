<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Owns the mutable cancellation state and exposes a read-only token.
 *
 * Create via CancellationSource::new(). Call cancel() to request cancellation
 * of all consumers holding the token.
 *
 * Mirrors .NET's CancellationTokenSource.
 */
final class CancellationSource implements Cancellable
{
    private CancellationToken $token;

    private bool $cancelled = false;

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

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Requests cancellation. Idempotent \u2014 calling multiple times is a no-op.
     * All callbacks registered on the token fire exactly once, in order.
     */
    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }
        $this->cancelled = true;
        // markCancelled() is @internal but must remain public — CancellationSource
        // is the sole caller and needs access to propagate cancellation into the
        // token. Marking it private would break CancellationSource::cancel().
        // @see CancellationToken::markCancelled()
        $this->token->markCancelled();
    }

    /**
     * @internal
     */
    public function onCancel(callable $callback): void
    {
        $this->token->onCancel($callback);
    }
}
