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

    /** @var list<callable(): void> */
    private array $callbacks;

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
    public function acceptCancellationSource(): void
    {
        if ($this->cancelled === true) {
            return;
        }
        $this->cancelled = true;
        $this->fireCallbacks();
    }

    /**
     * Register a callback to fire when cancellation is requested.
     * Public contract (mirrors Cancellable::onCancel; the README quickstart
     * relies on it). Fires immediately if the token is already cancelled,
     * otherwise runs at most once, when cancel() happens.
     *
     * @param callable(): void $callback
     */
    public function onCancel(callable $callback): void
    {
        if ($this->cancelled) {
            // Already cancelled — fire immediately.
            $callback();
            return;
        }
        $this->callbacks[] = $callback;
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
        // through acceptCancellationSource(), which has already raised the
        // flag, so external cancel-detection semantics for real cancels
        // are unchanged.
        $this->cancelled = true;
        $this->callbacksFired = true;
        $errors = [];
        foreach ($this->callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
        }
        $this->callbacks = [];
        if ($errors !== []) {
            throw $errors[0];
        }
    }
}
