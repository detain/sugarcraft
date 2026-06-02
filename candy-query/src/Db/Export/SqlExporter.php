<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db\Export;

use SugarCraft\Query\Db\DatabaseInterface;

/**
 * SQL export service using a DatabaseInterface instance.
 *
 * Dumps all tables as CREATE TABLE + INSERT statements.
 * Driver-agnostic, safe SQL (no eval, no password logging).
 * Mirrors charmbracelet/lazysql SQL dump logic.
 */
final class SqlExporter
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Export the entire database to a SQL dump file.
     *
     * Generates CREATE TABLE and INSERT statements for all user tables.
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
                $safeTable = str_replace('`', '``', $table);

                // Get CREATE TABLE statement
                // Note: Table names can't be bound as parameters in SQLite,
                // but str_replace escaping + single-quoted string is safe here
                $createResult = $this->db->query(
                    "SELECT sql FROM sqlite_master WHERE type='table' AND name = '{$safeTable}'",
                );
                $createRow = $createResult[0] ?? null;
                $createSql = $createRow['sql'] ?? null;
                if ($createSql === null) {
                    continue;
                }
                fwrite($handle, $createSql . ";\n\n");

                // Get column names for INSERT
                $columnResult = $this->db->query("PRAGMA table_info(`{$safeTable}`)");
                $columns = [];
                foreach ($columnResult as $row) {
                    if (isset($row['name'])) {
                        $columns[] = (string) $row['name'];
                    }
                }

                // Get all rows and generate INSERT statements
                $rows = $this->db->rows($table);
                foreach ($rows as $row) {
                    $values = array_map(
                        fn($val): string => $val === null
                            ? 'NULL'
                            : "'" . $this->db->quote((string) $val) . "'",
                        array_values($row)
                    );
                    $columnsList = implode(', ', $columns);
                    $valuesList = implode(', ', $values);
                    fwrite($handle, "INSERT INTO `{$table}` ({$columnsList}) VALUES ({$valuesList});\n");
                }
                fwrite($handle, "\n");
            }
        } finally {
            fclose($handle);
        }
    }
}
