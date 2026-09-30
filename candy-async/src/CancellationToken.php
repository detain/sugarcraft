<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Read-only view of a cancellation state.
 *
 * Instances are created by CancellationSource. Consumers receive only the
 * token \u2014 they cannot trigger cancellation, only observe it and register
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
     * @param CancellationSource $source  Minting authority; deliberately NOT retained.
     *
     *     Probed before dropping the former promoted property: no src or test
     *     site ever read it, and the "GC keepalive" reading is dead weight —
     *     cancellation requires a live CancellationSource handle, and any
     *     holder of that handle already keeps the source alive. Retaining it
     *     from the token side only pinned an unreachable source and turned a
     *     refcount teardown into a cycle-GC candidate. The parameter stays as
     *     the construction contract: a token is only ever minted for a source.
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
            // Already cancelled \u2014 fire immediately.
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
