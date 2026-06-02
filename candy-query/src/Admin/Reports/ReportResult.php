<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Reports;

/**
 * Immutable result of executing a performance report.
 *
 * Contains the report definition, the result rows (formatted or raw),
 * the row count, and whether the result was limited by the LIMIT clause.
 *
 * @see ReportRunner
 */
final readonly class ReportResult
{
    /**
     * @param ReportDefinition $report The report that was executed
     * @param list<array<string,mixed>> $rows The result rows (formatted or raw)
     * @param int $rowCount Number of rows returned
     * @param bool $limited True if the result was limited by LIMIT clause
     */
    public function __construct(
        public ReportDefinition $report,
        public array $rows,
        public int $rowCount,
        public bool $limited,
    ) {}

    /**
     * Get the column names from the report definition.
     *
     * @return list<string>
     */
    public function columnNames(): array
    {
        return $this->report->columnNames();
    }

    /**
     * Get time column names that need unit formatting.
     *
     * @return list<string>
     */
    public function timeColumns(): array
    {
        return $this->report->timeColumns();
    }

    /**
     * Get byte column names that need unit formatting.
     *
     * @return list<string>
     */
    public function byteColumns(): array
    {
        return $this->report->byteColumns();
    }

    /**
     * Get the first row, or null if there are no rows.
     *
     * @return array<string,mixed>|null
     */
    public function firstRow(): ?array
    {
        return $this->rows[0] ?? null;
    }

    /**
     * Check if the result is empty.
     */
    public function isEmpty(): bool
    {
        return $this->rowCount === 0;
    }
}
