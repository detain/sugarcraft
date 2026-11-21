<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Loop-free rate gate: {@see allow()} answers true at most once per interval.
 *
 * The first call is always allowed. A call is allowed again once at least
 * $seconds have elapsed since the last allowed call; calls inside the window
 * answer false and do not move the window, so a steady stream of denied calls
 * cannot starve the next allowance. Mirrors the inline timestamp check candy-code's
 * EngineBackend heartbeat used, without arming a timer.
 *
 * The clock is injectable so a test (or a caller on a frozen loop clock) can
 * drive the window without sleeping. Unlike the immutable value objects in
 * this library, a gate is mutable by design: its entire purpose is to remember
 * the last allowance.
 */
final class RateGate
{
    private bool $opened = false;
    private float $lastAllowedAt = 0.0;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private function __construct(
        private readonly float $seconds,
        \Closure $clock,
    ) {
        $this->clock = $clock;
    }

    /**
     * @param float $seconds  Minimum spacing between allowances (positive and finite)
     * @param null|callable(): float $clock  Monotonic-seconds clock; defaults to {@see Clock::monotonicSeconds()}
     */
    public static function new(float $seconds, ?callable $clock = null): self
    {
        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Rate gate seconds must be a positive finite number');
        }
        $clock = $clock === null
            ? Clock::monotonicSeconds(...)
            : \Closure::fromCallable($clock);

        return new self($seconds, $clock);
    }

    /** The configured spacing, in seconds. */
    public function seconds(): float
    {
        return $this->seconds;
    }

    /**
     * Take the allowance if the window is open.
     *
     * @return bool  True when this call opens the gate for the caller
     */
    public function allow(): bool
    {
        $now = ($this->clock)();
        if ($this->opened && $now - $this->lastAllowedAt < $this->seconds) {
            return false;
        }
        $this->opened = true;
        $this->lastAllowedAt = $now;

        return true;
    }

    /** Forget the last allowance: the next {@see allow()} is answered true. */
    public function reset(): void
    {
        $this->opened = false;
        $this->lastAllowedAt = 0.0;
    }
}
