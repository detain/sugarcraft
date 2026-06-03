<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Calc;

/**
 * Computes the Table Open Cache hit ratio as a percentage.
 *
 * Formula: hits / (hits + misses) * 100
 *
 * Mirrors MySQL Workbench's dashboard expression:
 *   CRawValue("%(Table_open_cache_hits)s/(%(Table_open_cache_hits)s+%(Table_open_cache_misses)s+0.0)")
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard GLOBAL_DASHBOARD_WIDGETS_MYSQL_PRE_80/POST_80 Table Open Cache entry
 */
final class TableOpenCacheHitRate
{
    public function __construct(
        private readonly string $hitKey = 'Table_open_cache_hits',
        private readonly string $missKey = 'Table_open_cache_misses',
    ) {}

    /**
     * Compute the cache hit percentage.
     *
     * @param array<string, string> $current Current status variables snapshot
     * @param array<string, string> $previous Previous status variables snapshot (unused for ratio)
     * @param float $elapsed Seconds elapsed (unused for ratio)
     * @return float Cache hit percentage (0.0 to 100.0)
     */
    public function compute(array $current, array $previous, float $elapsed): float
    {
        $hits = (float) ($current[$this->hitKey] ?? 0);
        $misses = (float) ($current[$this->missKey] ?? 0);
        $total = $hits + $misses;

        if ($total === 0.0) {
            return 0.0;
        }

        return ($hits / $total) * 100.0;
    }
}
