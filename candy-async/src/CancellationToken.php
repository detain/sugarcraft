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

    public function __construct(
        private CancellationSource $source,
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
        if ($this->cancelled) {
            return;
        }
        $this->cancelled = true;
        $this->fireCallbacks();
    }

    /**
     * @internal
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
        if ($this->callbacksFired) {
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
