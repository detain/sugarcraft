<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Calc;

/**
 * Computes the InnoDB Buffer Pool usage as a percentage.
 *
 * Formula: (total - free) / total * 100
 *
 * Mirrors MySQL Workbench sidebar gauge calculation:
 *   100·(total−free)/total
 *
 * Uses pages_total and pages_free from SHOW GLOBAL STATUS.
 * This is the formula used by MySQL Workbench's monitor sidebar,
 * not the byte-divided-by-page-size formula in the charting.py comments.
 *
 * @see Mirrors mysql-workbench/wba_monitor_be.py computeInnoDBRatio
 */
final class InnoDBBufferPoolUsage
{
    public function __construct(
        private readonly string $totalKey = 'Innodb_buffer_pool_pages_total',
        private readonly string $freeKey = 'Innodb_buffer_pool_pages_free',
    ) {}

    /**
     * Compute the buffer pool usage percentage.
     *
     * @param array<string, string> $current Current status variables snapshot
     * @param array<string, string> $previous Previous status variables snapshot (unused for ratio)
     * @param float $elapsed Seconds elapsed (unused for ratio)
     * @return float Buffer pool usage percentage (0.0 to 100.0)
     */
    public function compute(array $current, array $previous, float $elapsed): float
    {
        $total = (float) ($current[$this->totalKey] ?? 0);
        $free = (float) ($current[$this->freeKey] ?? 0);

        if ($total <= 0) {
            return 0.0;
        }

        $used = $total - $free;
        return ($used / $total) * 100.0;
    }
}
