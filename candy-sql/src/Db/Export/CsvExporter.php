<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Db\Export;

use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\Identifier;

/**
 * CSV import/export service using a DatabaseInterface instance.
 *
 * Driver-agnostic, safe SQL (no eval, no password logging).
 *
 * Exception copy carries file and table names VERBATIM (D-M26, 2026-11-03):
 * htmlspecialchars is web-template armor, and in a CLI message it mangled
 * the very name the user must hunt down ("Bob&#039;s data.csv"). Terminal
 * safety of server- and user-sourced text is the renderer's contract, not
 * this one - see CellValue::sanitize at the paint choke.
 * Produces RFC-4180 compliant CSV with formula-injection protection.
 * Mirrors charmbracelet/lazysql CSV export logic.
 */
final class CsvExporter
{
    /**
     * Rows per window while an export pages a table (SQL-C04).
     *
     * One screenful of fputcsv work per round-trip: small enough that peak
     * memory is two pages, large enough that a million-row table stays a
     * thousand queries rather than a million.
     */
    private const EXPORT_PAGE_SIZE = 500;

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Identifier-quoting flavor for the connection's actual driver.
     *
     * Only the quoting family matters (backtick vs double-quote), so map the
     * driver name directly rather than hardcoding MySQL: emitting backticks
     * against a PostgreSQL connection produces invalid SQL. `serverVersion()` is
     * not used because it returns a worded string ("MySQL version 8.0.33") that
     * Flavor::detectFromDriver would misclassify.
     */
    private function quoteFlavor(): Flavor
    {
        return match ($this->db->driverName()) {
            'mysql' => Flavor::MySQL,
            'pgsql' => Flavor::Postgres,
            default => Flavor::Sqlite,
        };
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
            throw new \RuntimeException("CSV file not found: " . $path);
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("The CSV file '{$path}' cannot be read: check that it exists and is readable");
        }

        // A mid-file failure used to leave the already-inserted rows
        // committed (autocommit, row at a time) — a silently partial import.
        // Stage the whole file in one transaction: any throw rolls the batch
        // back so the table is either fully imported or untouched (mn-18).
        $this->db->exec('BEGIN');
        $imported = false;
        try {
            $headers = fgetcsv($handle);
            if ($headers === false || $headers === null) {
                throw new \RuntimeException("Cannot read CSV headers from: " . $path);
            }

            $headers = array_map('trim', $headers);
            $columnCount = count($headers);
            if ($columnCount === 0) {
                throw new \RuntimeException("CSV file has no columns: " . $path);
            }

            // Build column list with identifier quoting (MySQL backtick standard)
            $columnList = implode(',', array_map(
                fn(string $col): string => Identifier::quote($this->quoteFlavor(), $col),
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

                $this->db->exec("INSERT INTO " . Identifier::quote($this->quoteFlavor(), $table) . " ({$columnList}) VALUES ({$valuesList})");
            }
            $this->db->exec('COMMIT');
            $imported = true;
        } finally {
            if (!$imported) {
                try {
                    $this->db->exec('ROLLBACK');
                } catch (\Throwable) {
                    // A dead connection cannot roll back — report the import
                    // error instead of masking it with the rollback failure.
                }
            }
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
            throw new \RuntimeException("The destination '{$path}' cannot be opened for writing: check that the directory exists and is writable");
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
            throw new \RuntimeException("The export buffer could not be created");
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
            throw new \RuntimeException("The destination '{$path}' cannot be opened for writing: check that the directory exists and is writable");
        }

        $complete = false;
        try {
            // SQL-C04 (client-004/029): the old single `rows($table)` call
            // silently stopped at the contract's 100-row default — a
            // "backup" tool that shipped truncated files. Page until a short
            // page arrives, writing each page straight through to the
            // destination handle so peak memory stays at one page (no
            // full-table buffer, no ≈2× memory-stream copy).
            //
            // Belt (the sketch's COUNT-verify + loud refuse): every shipped
            // dialect honours LIMIT/OFFSET, but a dialect that ignored the
            // window would silently under-write. COUNT first, and refuse the
            // partial file — deleting it, never presenting it as complete —
            // if the pages came up short (table mutated mid-export or a
            // driver dropped the offset).
            $expected = $this->countRows($table);
            $this->writeCsvHeader($handle, $columns);
            $offset = 0;
            $written = 0;
            do {
                $page = $this->db->rows($table, self::EXPORT_PAGE_SIZE, $offset);
                $this->writeCsvRows($handle, $columns, $page);
                $written += count($page);
                $offset += count($page);
            } while (count($page) === self::EXPORT_PAGE_SIZE
                && ($expected === null || $written < $expected));
            if ($expected !== null && $written !== $expected) {
                throw new \RuntimeException(
                    "CSV export of {$table} wrote {$written} of {$expected} rows — the table"
                    . " changed mid-export or the driver ignored the paging window; refusing"
                    . " to present a partial file as complete",
                );
            }
            $complete = true;
        } finally {
            fclose($handle);
            if (!$complete) {
                @unlink($path);
            }
        }
    }

    /**
     * Best-effort COUNT(*) for the SQL-C04 completeness belt.
     *
     * Returns null when the dialect/fake cannot answer (query error, null or
     * empty result) — the paging loop then relies on short-page exhaustion
     * alone instead of pretending to verify.
     */
    private function countRows(string $table): ?int
    {
        $flavor = $this->quoteFlavor();
        $sql = 'SELECT COUNT(*) AS ' . Identifier::quote($flavor, 'export_count')
            . ' FROM ' . Identifier::quote($flavor, $table);
        try {
            $result = $this->db->query($sql);
        } catch (\PDOException) {
            return null;
        }
        if ($result === null || $result === []) {
            return null;
        }
        $row = reset($result);
        $value = is_array($row) ? reset($row) : $row;
        return is_numeric($value) ? (int) $value : null;
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
        $this->writeCsvHeader($handle, $columns);
        $this->writeCsvRows($handle, $columns, $rows);
    }

    /**
     * @param resource $handle
     * @param list<string> $columns
     */
    private function writeCsvHeader($handle, array $columns): void
    {
        // Write header row with formula guard
        $headerRow = array_map(
            fn(string $col): string => $this->guardFormula($col),
            $columns
        );
        fputcsv($handle, $headerRow);
    }

    /**
     * @param resource $handle
     * @param list<string> $columns
     * @param list<array<string,mixed>> $rows
     */
    private function writeCsvRows($handle, array $columns, array $rows): void
    {
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
            $safeTable = Identifier::quote($this->quoteFlavor(), $table);
            $result = $this->db->query("SELECT * FROM {$safeTable} LIMIT 0");
            if ($result !== null && count($result) > 0) {
                return array_keys($result[0]);
            }
        } catch (\PDOException $e) {
            // client-017: a lost connection used to answer "table not found" —
            // carry the driver's own fault message and chain the cause.
            throw new \RuntimeException(
                "Table not found or unreadable: {$table} ({$e->getMessage()})",
                0,
                $e,
            );
        }

        // LIMIT 0 returned empty - try LIMIT 1 to get sample row
        try {
            $safeTable = Identifier::quote($this->quoteFlavor(), $table);
            $sampleResult = $this->db->query("SELECT * FROM {$safeTable} LIMIT 1");
            if ($sampleResult !== null && count($sampleResult) > 0) {
                return array_keys($sampleResult[0]);
            }
        } catch (\PDOException $e) {
            // client-017: a lost connection used to answer "table not found" —
            // carry the driver's own fault message and chain the cause.
            throw new \RuntimeException(
                "Table not found or unreadable: {$table} ({$e->getMessage()})",
                0,
                $e,
            );
        }

        // Both returned empty - verify table exists
        try {
            $safeTable = Identifier::quote($this->quoteFlavor(), $table);
            $this->db->query("SELECT 1 FROM {$safeTable} LIMIT 1");
        } catch (\PDOException $e) {
            // client-017: a lost connection used to answer "table not found" —
            // carry the driver's own fault message and chain the cause.
            throw new \RuntimeException(
                "Table not found or unreadable: {$table} ({$e->getMessage()})",
                0,
                $e,
            );
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