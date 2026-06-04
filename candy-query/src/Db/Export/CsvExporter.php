<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db\Export;

use SugarCraft\Query\Db\DatabaseInterface;

/**
 * CSV import/export service using a DatabaseInterface instance.
 *
 * Driver-agnostic, safe SQL (no eval, no password logging).
 * Produces RFC-4180 compliant CSV with formula-injection protection.
 * Mirrors charmbracelet/lazysql CSV export logic.
 */
final class CsvExporter
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Import a CSV file into a table.
     *
     * The first row of the CSV must contain column headers matching table columns.
     * Each subsequent row is inserted using safe SQL with proper escaping.
     * Table name is backtick-quoted for safety.
     *
     * @param string $path  Path to the CSV file
     * @param string $table Target table name (backtick-quoted)
     * @throws \RuntimeException If the file doesn't exist or can't be opened
     * @throws \PDOException     On database errors
     */
    public function importCsv(string $path, string $table): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException("CSV file not found: " . htmlspecialchars($path, ENT_QUOTES, 'UTF-8'));
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV file: " . htmlspecialchars($path, ENT_QUOTES, 'UTF-8'));
        }

        try {
            $headers = fgetcsv($handle);
            if ($headers === false || $headers === null) {
                throw new \RuntimeException("Cannot read CSV headers from: " . htmlspecialchars($path, ENT_QUOTES, 'UTF-8'));
            }

            $headers = array_map('trim', $headers);
            $columnCount = count($headers);
            if ($columnCount === 0) {
                throw new \RuntimeException("CSV file has no columns: " . htmlspecialchars($path, ENT_QUOTES, 'UTF-8'));
            }

            // Build column list with backtick quoting (SQLite standard)
            $columnList = implode(',', array_map(
                fn(string $col): string => '`' . str_replace('`', '``', $col) . '`',
                $headers
            ));

            while (($row = fgetcsv($handle)) !== false && $row !== null) {
                // Skip rows with wrong column count
                if (count($row) !== $columnCount) {
                    continue;
                }

                // Build VALUES clause with properly quoted values
                $valuesList = implode(',', array_map(
                    fn($value): string => $value === null
                        ? 'NULL'
                        : $this->db->quote((string) $value),
                    $row
                ));

                $this->db->exec("INSERT INTO `{$table}` ({$columnList}) VALUES ({$valuesList})");
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Export report results to a CSV file.
     *
     * Writes the column headers followed by all data rows as RFC-4180 CSV.
     * Applies formula-injection protection to headers and cell values.
     *
     * @param string $path  Path to the output CSV file
     * @param list<string> $columns Column names (headers)
     * @param list<array<string,mixed>> $rows Result rows to export
     * @throws \RuntimeException If the file can't be opened for writing
     */
    public function exportReportResults(string $path, array $columns, array $rows): void
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file for writing: " . htmlspecialchars($path, ENT_QUOTES, 'UTF-8'));
        }

        try {
            $this->writeCsv($handle, $columns, $rows);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Export report results to a CSV string.
     *
     * Returns the CSV as a string instead of writing to a file.
     * Useful when the caller needs the CSV content in memory.
     *
     * @param list<string> $columns Column names (headers)
     * @param list<array<string,mixed>> $rows Result rows to export
     * @return string RFC-4180 CSV content
     */
    public function exportReportResultsToString(array $columns, array $rows): string
    {
        $handle = fopen('php://memory', 'w');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open memory stream for writing");
        }

        try {
            $this->writeCsv($handle, $columns, $rows);
            rewind($handle);
            return stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Export a table to a CSV file.
     *
     * The first row contains column headers, followed by all table rows.
     * Driver-agnostic: works with any DatabaseInterface implementation.
     * Produces RFC-4180 CSV with formula-injection protection.
     *
     * @param string $path  Path to the output CSV file
     * @param string $table Table name to export
     * @throws \RuntimeException If the table doesn't exist or file can't be opened
     * @throws \PDOException     On database errors
     */
    public function exportCsv(string $path, string $table): void
    {
        // Get column names driver-neutrally
        $columns = $this->getColumnNames($table);

        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file for writing: " . htmlspecialchars($path, ENT_QUOTES, 'UTF-8'));
        }

        try {
            // Fetch all rows
            $rows = $this->db->rows($table);

            $this->writeCsv($handle, $columns, $rows);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Write CSV data to an open file handle.
     *
     * @param resource $handle Open file handle
     * @param list<string> $columns Column names
     * @param list<array<string,mixed>> $rows Data rows
     */
    private function writeCsv($handle, array $columns, array $rows): void
    {
        // Write header row with formula guard
        $headerRow = array_map(
            fn(string $col): string => $this->guardFormula($col),
            $columns
        );
        fputcsv($handle, $headerRow);

        // Write data rows with formula guard
        foreach ($rows as $row) {
            $values = array_map(
                fn(string $col): string => $this->guardFormula((string) ($row[$col] ?? '')),
                $columns
            );
            fputcsv($handle, $values);
        }
    }

    /**
     * Get column names for a table using a driver-neutral approach.
     *
     * @param string $table Table name
     * @return list<string> Column names
     * @throws \RuntimeException If the table doesn't exist
     */
    private function getColumnNames(string $table): array
    {
        // LIMIT 0 query to get column names without fetching rows
        try {
            $result = $this->db->query("SELECT * FROM `{$table}` LIMIT 0");
            if ($result !== null && count($result) > 0) {
                return array_keys($result[0]);
            }
        } catch (\PDOException $e) {
            throw new \RuntimeException("Table not found: " . htmlspecialchars($table, ENT_QUOTES, 'UTF-8'));
        }

        // LIMIT 0 returned empty - try LIMIT 1 to get sample row
        try {
            $sampleResult = $this->db->query("SELECT * FROM `{$table}` LIMIT 1");
            if ($sampleResult !== null && count($sampleResult) > 0) {
                return array_keys($sampleResult[0]);
            }
        } catch (\PDOException $e) {
            throw new \RuntimeException("Table not found: " . htmlspecialchars($table, ENT_QUOTES, 'UTF-8'));
        }

        // Both returned empty - verify table exists
        try {
            $this->db->query("SELECT 1 FROM `{$table}` LIMIT 1");
        } catch (\PDOException $e) {
            throw new \RuntimeException("Table not found: " . htmlspecialchars($table, ENT_QUOTES, 'UTF-8'));
        }

        // Table exists but we couldn't get columns (shouldn't happen for non-empty tables)
        return [];
    }

    /**
     * Apply formula-injection protection to a CSV cell value.
     *
     * After trimming leading spaces, if the value starts with
     * = + - @ or a tab/carriage return, prefix with ' to prevent
     * spreadsheet formula injection.
     *
     * @param string|null $value Cell value
     * @return string Protected value
     */
    private function guardFormula(string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        // Trim only leading spaces (not tabs/carriage returns)
        $trimmed = ltrim($value, ' ');
        if ($trimmed === '') {
            return $value;
        }
        $first = $trimmed[0];
        if ($first === '=' || $first === '+' || $first === '-' || $first === '@') {
            return "'" . $value;
        }
        // Also guard against tab and carriage return at start
        if ($first === "\t" || $first === "\r") {
            return "'" . $value;
        }
        return $value;
    }
}