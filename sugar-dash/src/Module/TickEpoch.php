<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Module;

/**
 * Focus-regain epoch counter for modules.
 *
 * Tracks how many times focus has been regained. Useful for modules
 * that need to refresh their content when the dashboard regains focus
 * after being hidden or minimized.
 *
 * Mirrors the Homedash TickEpoch pattern.
 *
 * @readonly
 */
final class TickEpoch
{
    private int $epoch = 0;

    public function __construct(
        private readonly int $initialEpoch = 0,
    ) {
        $this->epoch = $initialEpoch;
    }

    /**
     * Create a new TickEpoch with zero initial value.
     */
    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Get the current epoch value.
     */
    public function value(): int
    {
        return $this->epoch;
    }

    /**
     * Bump the epoch (call when focus is regained).
     */
    public function bump(): self
    {
        return new self($this->epoch + 1);
    }

    /**
     * Check if a received epoch value is stale (lower than current).
     */
    public function isStale(int $received): bool
    {
        return $received < $this->epoch;
    }

    /**
     * Check if focus should be refreshed based on epoch comparison.
     */
    public function shouldRefresh(int $received): bool
    {
        return $received <= $this->epoch;
    }
}
