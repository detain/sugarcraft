<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Calc;

/**
 * Computes the rate of change (per second) for a status variable.
 *
 * Uses precompiled closures to avoid eval. Deltas are computed as
 * newValue - oldValue, clamped to zero if negative (counter wrap).
 *
 * @see Mirrors charmbracelet/lazysql RatePerSecond
 */
final class RatePerSecond
{
    /** @var \Closure(array<string,string>, array<string,string>, float): float */
    private readonly \Closure $compute;

    private float $lastValue = 0.0;
    private bool $initialized = false;

    public function __construct(
        public readonly string $key,
    ) {
        $key = $this->key;
        $this->compute = \Closure::fromCallable(
            /** @param array<string,string> $current @param array<string,string> $previous @param float $elapsed @return float */
            static function (array $current, array $previous, float $elapsed) use ($key): float {
                if ($elapsed <= 0) {
                    return 0.0;
                }
                $newVal = $current[$key] ?? null;
                $oldVal = $previous[$key] ?? null;
                if ($newVal === null || $oldVal === null) {
                    return 0.0;
                }
                $newNum = is_numeric($newVal) ? (float) $newVal : null;
                $oldNum = is_numeric($oldVal) ? (float) $oldVal : null;
                if ($newNum === null || $oldNum === null) {
                    return 0.0;
                }
                $delta = $newNum - $oldNum;
                if ($delta < 0) {
                    $delta = 0;
                }
                return $delta / $elapsed;
            },
        );
    }

    /**
     * Compute the rate using precompiled closure.
     *
     * @param array<string, string> $current
     * @param array<string, string> $previous
     * @param float $elapsed
     * @return float
     */
    public function compute(array $current, array $previous, float $elapsed): float
    {
        return ($this->compute)($current, $previous, $elapsed);
    }

    /**
     * Update last known value from a snapshot.
     *
     * @param array<string, string> $snapshot
     */
    public function updateLast(array $snapshot): void
    {
        $val = $snapshot[$this->key] ?? null;
        if ($val !== null && is_numeric($val)) {
            $this->lastValue = (float) $val;
            $this->initialized = true;
        }
    }

    /**
     * Check if this rate has been initialized with a value.
     */
    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * Get the last known value.
     */
    public function lastValue(): float
    {
        return $this->lastValue;
    }
}
