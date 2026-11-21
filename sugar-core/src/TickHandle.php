<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Cancellable handle for a one-shot tick armed by {@see Cmd::cancellableTick()}.
 *
 * The handle works before and after the Program arms the timer:
 *  - cancel() before the Cmd is dispatched: the timer is never armed and
 *    the produce closure never runs;
 *  - cancel() while the timer is pending: the loop timer is cancelled;
 *  - cancel() after the tick fired: a no-op.
 *
 * Mirrors the cancel half of charmbracelet/bubbletea.Tick (its returned
 * timer is discarded by Go callers; a PHP model needs an explicit handle).
 */
final class TickHandle
{
    private bool $cancelled = false;

    /** @var (\Closure(): void)|null disarms the loop timer while one is pending */
    private ?\Closure $disarm = null;

    /** @param \Closure():?Msg $produce */
    public function __construct(
        private readonly float $seconds,
        private readonly \Closure $produce,
    ) {
    }

    /**
     * The Cmd to return from update(). Return it once: a handle covers one
     * tick, and a cancelled handle stays cancelled, so build a new handle to
     * tick again.
     */
    public function cmd(): \Closure
    {
        return fn (): Msg => new TickRequest($this->seconds, $this->produce, $this);
    }

    /** Stop the tick. Idempotent; safe before, during and after the wait. */
    public function cancel(): void
    {
        $this->cancelled = true;
        if ($this->disarm !== null) {
            $disarm = $this->disarm;
            $this->disarm = null;
            $disarm();
        }
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Program-internal: record how to disarm the loop timer once it is
     * armed. Not part of the model-facing surface.
     *
     * @internal
     * @param \Closure(): void $disarm
     */
    public function attach(\Closure $disarm): void
    {
        if ($this->cancelled) {
            $disarm();
            return;
        }
        $this->disarm = $disarm;
    }
}
