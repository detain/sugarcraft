<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db\Export;

use SugarCraft\Query\Db\DatabaseInterface;

/**
 * SQL export service using a DatabaseInterface instance.
 *
 * Dumps all tables as INSERT statements.
 * Driver-agnostic, safe SQL (no eval, no password logging).
 * Mirrors charmbracelet/lazysql SQL dump logic.
 *
 * Note: CREATE TABLE generation is omitted because obtaining the full
 * CREATE statement in a driver-neutral way is not feasible. The INSERT
 * data is the primary value of a SQL dump for data portability.
 */
final class SqlExporter
{
    public function __construct(private readonly DatabaseInterface $db)
    {
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
            throw new \RuntimeException("Cannot open file for writing: {$path}");
        }

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

                // Get all rows and generate INSERT statements
                $rows = $this->db->rows($table);
                foreach ($rows as $row) {
                    $values = array_map(
                        fn($val): string => $this->quoteValue($val),
                        array_values($row)
                    );
                    $columnsList = implode(', ', array_map(
                        fn(string $col): string => "`{$col}`",
                        $columns
                    ));
                    $valuesList = implode(', ', $values);
                    fwrite($handle, "INSERT INTO `{$table}` ({$columnsList}) VALUES ({$valuesList});\n");
                }
                fwrite($handle, "\n");
            }
        } finally {
            fclose($handle);
        }
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
        $result = $this->db->query("SELECT * FROM `{$table}` LIMIT 1");
        if ($result !== null && count($result) > 0) {
            return array_keys($result[0]);
        }

        // Table has no rows - cannot determine columns driver-neutrally
        return [];
    }
}