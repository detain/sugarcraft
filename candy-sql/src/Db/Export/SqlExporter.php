<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Db\Export;

use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\Identifier;

/**
 * SQL export service using a DatabaseInterface instance.
 *
 * Dumps all tables as INSERT statements.
 * Driver-agnostic, safe SQL (no eval, no password logging).
 * Mirrors jorgerojas26/lazysql SQL dump logic.
 *
 * Note: CREATE TABLE generation is omitted because obtaining the full
 * CREATE statement in a driver-neutral way is not feasible. The INSERT
 * data is the primary value of a SQL dump for data portability.
 */
final class SqlExporter
{
    /** Rows per window while an export pages a table (SQL-C04). */
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
     * Export the entire database to a SQL dump file.
     *
     * Generates INSERT statements for all tables.
     * Output format: one SQL statement per line with a header comment.
     *
     * @param string $path Path to the output SQL file
     * @throws \RuntimeException If the file can't be opened for writing
     * @throws \PDOException     On database errors
     */
    public function exportSql(string $path): void
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException("The destination '{$path}' cannot be opened for writing: check that the directory exists and is writable");
        }

        $complete = false;
        try {
            fwrite($handle, "-- SugarCraft Database Dump\n");
            fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");

            $tables = $this->db->tables();
            foreach ($tables as $table) {
                // Get column names driver-neutrally via LIMIT 1 query
                $columns = $this->getColumnNames($table);
                if ($columns === []) {
                    continue;
                }

                // SQL-C04 (client-004): the old `rows($table)` stopped at the
                // contract's 100-row default, silently truncating every dump.
                // Page to exhaustion, streaming each row straight to the
                // handle (no full-table buffer); COUNT-belt refuses a short
                // file loudly instead of shipping a partial "complete" dump.
                $expected = $this->countRows($table);
                $written = 0;
                $offset = 0;
                do {
                    $rows = $this->db->rows($table, self::EXPORT_PAGE_SIZE, $offset);
                    $columnsList = implode(', ', array_map(
                        fn(string $col): string => Identifier::quote($this->quoteFlavor(), $col),
                        $columns
                    ));
                    $safeTable = Identifier::quote($this->quoteFlavor(), $table);
                    foreach ($rows as $row) {
                        $values = array_map(
                            fn($val): string => $this->quoteValue($val),
                            array_values($row)
                        );
                        $valuesList = implode(', ', $values);
                        fwrite($handle, "INSERT INTO {$safeTable} ({$columnsList}) VALUES ({$valuesList});\n");
                    }
                    $written += count($rows);
                    $offset += count($rows);
                } while (count($rows) === self::EXPORT_PAGE_SIZE
                    && ($expected === null || $written < $expected));
                if ($expected !== null && $written !== $expected) {
                    throw new \RuntimeException(
                        "SQL dump of {$table} wrote {$written} of {$expected} rows — the table"
                        . " changed mid-dump or the driver ignored the paging window; refusing"
                        . " to present a partial file as complete",
                    );
                }
                fwrite($handle, "\n");
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
     * Null when the dialect/fake cannot answer — the loop then relies on
     * short-page exhaustion alone rather than pretending to verify.
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
     * Quote a value for SQL, handling different types appropriately.
     *
     * - null => NULL (unquoted)
     * - int/float => raw number (no quotes)
     * - string => $db->quote() result (properly quoted)
     *
     * @param mixed $val
     * @return string
     */
    private function quoteValue(mixed $val): string
    {
        if ($val === null) {
            return 'NULL';
        }
        if (is_int($val) || is_float($val)) {
            return (string) $val;
        }
        return $this->db->quote((string) $val);
    }

    /**
     * Get column names for a table using a driver-neutral LIMIT 1 approach.
     *
     * @param string $table Table name
     * @return list<string> Column names, empty if table is empty or doesn't exist
     */
    private function getColumnNames(string $table): array
    {
        // LIMIT 1 returns first row with column keys we can extract
        $safeTable = Identifier::quote($this->quoteFlavor(), $table);
        $result = $this->db->query("SELECT * FROM {$safeTable} LIMIT 1");
        if ($result !== null && count($result) > 0) {
            return array_keys($result[0]);
        }

        // Table has no rows - cannot determine columns driver-neutrally
        return [];
    }
}