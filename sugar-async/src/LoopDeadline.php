<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * One-shot deadline armed on an event loop: $onFire runs once when the budget
 * elapses unless {@see disarm()} released it first.
 *
 * The loop-driven sibling of {@see RateGate} for callers that need the deadline
 * to fire on its own (a TUI kill, a stalled render). It is a handle, not a
 * promise: it reports {@see fired()} so the owner can tell a timeout from a
 * normal completion after the fact. Mirrors the timer-and-flag pair
 * candy-scriptui's TimeoutGuard carried.
 *
 * A caller that wants no limit skips arming the deadline; a zero or negative
 * budget is refused rather than silently treated as "never".
 */
final class LoopDeadline
{
    private bool $fired = false;
    private ?TimerInterface $timer = null;

    private function __construct(
        private readonly LoopInterface $loop,
    ) {
    }

    /**
     * @param float $seconds  Budget before $onFire runs (positive and finite)
     * @param \Closure(): void $onFire  Runs once, on the loop, when the deadline elapses
     */
    public static function arm(LoopInterface $loop, float $seconds, \Closure $onFire): self
    {
        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Loop deadline seconds must be a positive finite number');
        }
        $deadline = new self($loop);
        $deadline->timer = $loop->addTimer($seconds, static function () use ($deadline, $onFire): void {
            $deadline->fired = true;
            // The timer has spent itself; a later disarm() has nothing to cancel.
            $deadline->timer = null;
            $onFire();
        });

        return $deadline;
    }

    /** True once the budget has elapsed and $onFire has run. */
    public function fired(): bool
    {
        return $this->fired;
    }

    /** True while the deadline is armed and has not yet fired. */
    public function isArmed(): bool
    {
        return $this->timer !== null;
    }

    /** Release the deadline so $onFire never runs. Idempotent; a no-op after firing. */
    public function disarm(): void
    {
        if ($this->timer !== null) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }
    }
}
