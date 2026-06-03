<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db\Export;

use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Core\Util\Width;

/**
 * CSV import/export service using a DatabaseInterface instance.
 *
 * Driver-agnostic, safe SQL (no eval, no password logging).
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
     * Writes the column headers followed by all data rows.
     * Uses Width utility for proper column alignment.
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
            $maxWidths = array_fill_keys($columns, 0);
            foreach ($columns as $col) {
                $maxWidths[$col] = Width::string($col);
            }
            foreach ($rows as $row) {
                foreach ($columns as $col) {
                    $value = (string) ($row[$col] ?? '');
                    $maxWidths[$col] = max($maxWidths[$col], Width::string($value));
                }
            }

            $headerRow = implode(',', array_map(
                fn(string $col): string => Width::padRight($col, $maxWidths[$col]),
                $columns
            ));
            fwrite($handle, $headerRow . "\n");

            foreach ($rows as $row) {
                $values = array_map(
                    fn(string $col): string => Width::padRight((string) ($row[$col] ?? ''), $maxWidths[$col]),
                    $columns
                );
                fwrite($handle, implode(',', $values) . "\n");
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Export a table to a CSV file with column-width aware output.
     *
     * The first row contains column headers, followed by all table rows.
     * Uses Width utility for proper column alignment.
     *
     * @param string $path  Path to the output CSV file
     * @param string $table Table name to export
     * @throws \RuntimeException If the table doesn't exist or file can't be opened
     * @throws \PDOException     On database errors
     */
    public function exportCsv(string $path, string $table): void
    {
        // Get column info - driver-specific query but we need it for structure
        $colResult = $this->db->query("PRAGMA table_info(`{$table}`)");
        $columns = [];
        foreach ($colResult as $row) {
            if (isset($row['name'])) {
                $columns[] = (string) $row['name'];
            }
        }

        if (count($columns) === 0) {
            // Fallback: try to query the table to verify it exists
            try {
                $this->db->query("SELECT 1 FROM `{$table}` LIMIT 1");
            } catch (\PDOException) {
                throw new \RuntimeException("Table not found: " . htmlspecialchars($table, ENT_QUOTES, 'UTF-8'));
            }
        }

        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file for writing: " . htmlspecialchars($path, ENT_QUOTES, 'UTF-8'));
        }

        try {
            // Fetch all rows first to calculate column widths
            $rows = $this->db->rows($table);

            // Calculate max width for each column (including headers)
            $maxWidths = array_fill_keys($columns, 0);
            foreach ($columns as $col) {
                $maxWidths[$col] = Width::string($col);
            }
            foreach ($rows as $row) {
                foreach ($columns as $col) {
                    $value = (string) ($row[$col] ?? '');
                    $maxWidths[$col] = max($maxWidths[$col], Width::string($value));
                }
            }

            // Write header row with column-width alignment
            $headerRow = implode(',', array_map(
                fn(string $col): string => Width::padRight($col, $maxWidths[$col]),
                $columns
            ));
            fwrite($handle, $headerRow . "\n");

            // Write data rows with column-width alignment
            foreach ($rows as $row) {
                $values = array_map(
                    fn(string $col): string => Width::padRight((string) ($row[$col] ?? ''), $maxWidths[$col]),
                    $columns
                );
                fwrite($handle, implode(',', $values) . "\n");
            }
        } finally {
            fclose($handle);
        }
    }
}
