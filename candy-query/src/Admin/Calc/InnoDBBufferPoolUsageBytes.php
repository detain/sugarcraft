<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Calc;

/**
 * Computes the InnoDB Buffer Pool usage as a percentage using byte-based values.
 *
 * Formula (per Appendix A):
 *   (Innodb_buffer_pool_bytes_data / Innodb_page_size) / (Innodb_buffer_pool_pages_total + 0.0) * 100
 *
 * This is the formula specified by MySQL Workbench's DBLevelMeter for the
 * InnoDB buffer pool sidebar gauge, contrasting with the simpler
 * (total−free)/total page-count approximation.
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard GLOBAL_DASHBOARD_WIDGETS_INNODB buffer pool usage
 */
final class InnoDBBufferPoolUsageBytes
{
    public function __construct(
        private readonly string $bytesDataKey = 'Innodb_buffer_pool_bytes_data',
        private readonly string $pageSizeKey = 'Innodb_page_size',
        private readonly string $pagesTotalKey = 'Innodb_buffer_pool_pages_total',
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
        $bytesData = (float) ($current[$this->bytesDataKey] ?? 0);
        $pageSize = (float) ($current[$this->pageSizeKey] ?? 0);
        $pagesTotal = (float) ($current[$this->pagesTotalKey] ?? 0);

        if ($pageSize <= 0 || $pagesTotal <= 0) {
            return 0.0;
        }

        $usedPages = $bytesData / $pageSize;
        return ($usedPages / $pagesTotal) * 100.0;
    }
}
