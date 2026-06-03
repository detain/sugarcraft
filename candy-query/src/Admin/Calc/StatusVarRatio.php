<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Calc;

/**
 * Computes the ratio of two status variables (numerator / denominator).
 *
 * Used for metrics like average row lock time where the formula requires
 * dividing one status variable by another (e.g., Innodb_row_lock_time /
 * Innodb_row_lock_waits).
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard CRawValue ratio
 */
final class StatusVarRatio
{
    public function __construct(
        public readonly string $numerator,
        public readonly string $denominator,
    ) {}

    /**
     * Compute the ratio of the two status variables.
     *
     * @param array<string, string> $current Current status variables snapshot
     * @param array<string, string> $previous Previous status variables snapshot (unused)
     * @param float $elapsed Seconds elapsed (unused)
     * @return float The ratio, or 0.0 if denominator is zero
     */
    public function compute(array $current, array $previous, float $elapsed): float
    {
        $num = (float) ($current[$this->numerator] ?? '0');
        $den = (float) ($current[$this->denominator] ?? '0');

        if ($den === 0.0) {
            return 0.0;
        }

        return $num / $den;
    }
}
