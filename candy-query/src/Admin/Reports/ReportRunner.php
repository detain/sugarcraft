<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Reports;

use SugarCraft\Query\Db\DatabaseInterface;

/**
 * Executes performance report queries against the MySQL sys schema.
 *
 * Runs `SELECT * FROM sys.<view> [LIMIT n]` for reports defined in the catalog.
 * Uses prepared statements with backtick-quoted view names for safety.
 * Applies unit formatting to time and byte columns based on column metadata.
 *
 * @see Mirrors mysql-workbench wb_admin_perfschema_reports report execution
 */
final class ReportRunner
{
    private function __construct(
        private readonly DatabaseInterface $db,
        private readonly Catalog $catalog,
        private readonly AvailabilityChecker $availability,
        private readonly int $defaultLimit = 1000,
    ) {}

    /**
     * Factory method to create a new ReportRunner.
     */
    public static function new(
        DatabaseInterface $db,
        Catalog $catalog,
        AvailabilityChecker $availability,
        int $defaultLimit = 1000,
    ): self {
        return new self($db, $catalog, $availability, $defaultLimit);
    }

    /**
     * Execute a report and return formatted results.
     *
     * Runs the query for the specified report view, applies unit formatting
     * to time and byte columns, and returns the results as an array of rows.
     *
     * @param string $viewName The view name to execute (e.g., "x$statement_analysis")
     * @param int|null $limit Maximum rows to return (null uses default)
     * @return ReportResult The execution result with rows and metadata
     * @throws \InvalidArgumentException If the report doesn't exist in the catalog
     * @throws \RuntimeException If the view is not available on this server
     */
    public function run(string $viewName, ?int $limit = null): ReportResult
    {
        $report = $this->catalog->get($viewName);
        if ($report === null) {
            throw new \InvalidArgumentException(
                "Report not found in catalog: " . htmlspecialchars($viewName, ENT_QUOTES, 'UTF-8')
            );
        }

        if (!$this->availability->isViewAvailable($viewName)) {
            throw new \RuntimeException(
                "View not available on this server: " . htmlspecialchars($viewName, ENT_QUOTES, 'UTF-8')
            );
        }

        $limit ??= $this->defaultLimit;

        $query = "SELECT * FROM `sys`.`{$viewName}` LIMIT " . (int) $limit;
        $rows = $this->db->query($query);

        $formattedRows = $this->formatRows($rows, $report);

        return new ReportResult(
            report: $report,
            rows: $formattedRows,
            rowCount: count($formattedRows),
            limited: count($formattedRows) >= $limit,
        );
    }

    /**
     * Execute a report and return raw (unformatted) results.
     *
     * Useful when you need the raw numeric values for sorting or calculation.
     *
     * @param string $viewName The view name to execute
     * @param int|null $limit Maximum rows to return
     * @return ReportResult The execution result with unformatted rows
     */
    public function runRaw(string $viewName, ?int $limit = null): ReportResult
    {
        $report = $this->catalog->get($viewName);
        if ($report === null) {
            throw new \InvalidArgumentException(
                "Report not found in catalog: " . htmlspecialchars($viewName, ENT_QUOTES, 'UTF-8')
            );
        }

        $limit ??= $this->defaultLimit;

        $query = "SELECT * FROM `sys`.`{$viewName}` LIMIT " . (int) $limit;
        $rows = $this->db->query($query);

        return new ReportResult(
            report: $report,
            rows: $rows,
            rowCount: count($rows),
            limited: count($rows) >= $limit,
        );
    }

    /**
     * Check if a report can be executed.
     *
     * @param string $viewName The view name to check
     * @return bool True if the report exists and is available
     */
    public function canRun(string $viewName): bool
    {
        $report = $this->catalog->get($viewName);
        if ($report === null) {
            return false;
        }

        return $this->availability->isViewAvailable($viewName);
    }

    /**
     * Format rows according to column type definitions.
     *
     * @param list<array<string,mixed>> $rows Raw query rows
     * @param ReportDefinition $report The report definition with column types
     * @return list<array<string,mixed>> Formatted rows
     */
    private function formatRows(array $rows, ReportDefinition $report): array
    {
        $timeCols = $report->timeColumns();
        $byteCols = $report->byteColumns();

        if (empty($timeCols) && empty($byteCols)) {
            return $rows;
        }

        $formatted = [];
        foreach ($rows as $row) {
            $formattedRow = [];
            foreach ($row as $colName => $value) {
                if (in_array($colName, $timeCols, true)) {
                    $formattedRow[$colName] = UnitFormatter::formatTime($value);
                } elseif (in_array($colName, $byteCols, true)) {
                    $formattedRow[$colName] = UnitFormatter::formatBytes($value);
                } else {
                    $formattedRow[$colName] = $value;
                }
            }
            $formatted[] = $formattedRow;
        }

        return $formatted;
    }
}
