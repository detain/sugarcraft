<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Calc;

/**
 * Computes cache hit rate as a percentage: hits / (hits + reads) * 100.
 *
 * Used for PostgreSQL buffer cache metrics where we want to show
 * the hit ratio rather than raw counter values.
 *
 * @see Mirrors charmbracelet/lazysql cache hit rate calculation
 */
final class CacheHitRate
{
    public function __construct(
        private readonly string $hitKey,
        private readonly string $readKey,
    ) {}

    /**
     * Compute the cache hit percentage.
     *
     * @param array<string, string> $current Current status variables snapshot
     * @param array<string, string> $previous Previous status variables snapshot (unused)
     * @param float $elapsed Seconds elapsed (unused)
     * @return float Cache hit percentage (0.0 to 100.0)
     */
    public function compute(array $current, array $previous, float $elapsed): float
    {
        $hit = (float) ($current[$this->hitKey] ?? 0);
        $read = (float) ($current[$this->readKey] ?? 0);
        $total = $hit + $read;

        if ($total === 0.0) {
            return 0.0;
        }

        return ($hit / $total) * 100.0;
    }
}
