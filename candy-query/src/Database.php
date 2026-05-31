<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Lang;

/**
 * Thin SQLite wrapper implementing DatabaseInterface.
 *
 * Everything else in this app talks to a {@see Database} (an interface
 * in spirit, sealed concrete class in practice — now implements
 * DatabaseInterface so any impl can be substituted).
 *
 * The wrapper is split out so the Model can be tested against an
 * in-memory `:memory:` PDO with no fixture files on disk.
 */
final class Database implements DatabaseInterface
{
    public function __construct(public readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public static function open(string $path): self
    {
        if ($path !== ':memory:' && !is_file($path)) {
            throw new \RuntimeException(Lang::t('database.no_file', ['path' => $path]));
        }
        return new self(new \PDO('sqlite:' . $path));
    }

    /** @return list<string> */
    public function tables(): array
    {
        $rows = $this->pdo->query(
            "SELECT name FROM sqlite_master "
            . "WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' "
            . "ORDER BY name",
        );
        if ($rows === false) return [];
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['name'])) {
                $out[] = (string) $row['name'];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $table, int $limit = 100): array
    {
        $sql = sprintf('SELECT * FROM "%s" LIMIT %d', str_replace('"', '""', $table), $limit);
        $stmt = $this->pdo->query($sql);
        return $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function query(string $sql): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        if ($stmt->columnCount() > 0) {
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        return [['affected' => $stmt->rowCount()]];
    }

    public function lastInsertId(): string|int
    {
        return $this->pdo->lastInsertId();
    }

    public function quote(string $value): string
    {
        return $this->pdo->quote($value);
    }

    public function exec(string $sql): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    /**
     * Import a CSV file into a table.
     *
     * The first row of the CSV must contain column headers matching table columns.
     * Each subsequent row is inserted into the table using prepared statements.
     *
     * @param string $path  Path to the CSV file
     * @param string $table Target table name
     * @throws \RuntimeException If the file doesn't exist or can't be opened
     * @throws \PDOException     On database errors
     */
    public function importCsv(string $path, string $table): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException("CSV file not found: {$path}");
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV file: {$path}");
        }

        try {
            $headers = fgetcsv($handle);
            if ($headers === false || $headers === null) {
                throw new \RuntimeException("Cannot read CSV headers from: {$path}");
            }

            $headers = array_map('trim', $headers);
            $columnCount = count($headers);
            if ($columnCount === 0) {
                throw new \RuntimeException("CSV file has no columns: {$path}");
            }

            $placeholders = implode(',', array_fill(0, $columnCount, '?'));
            $columnList = implode(',', array_map(
                fn(string $col): string => '"' . str_replace('"', '""', $col) . '"',
                $headers
            ));
            $sql = "INSERT INTO \"{$table}\" ({$columnList}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);

            while (($row = fgetcsv($handle)) !== false && $row !== null) {
                $stmt->execute($row);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Export a table to a CSV file.
     *
     * The first row contains column headers, followed by all table rows.
     *
     * @param string $path  Path to the output CSV file
     * @param string $table Table name to export
     * @throws \RuntimeException If the table doesn't exist or file can't be opened
     * @throws \PDOException     On database errors
     */
    public function exportCsv(string $path, string $table): void
    {
        try {
            $colResult = $this->pdo->query("PRAGMA table_info(\"{$table}\")");
            $columns = $colResult !== false
                ? array_column($colResult->fetchAll(\PDO::FETCH_ASSOC), 'name')
                : [];
            if (count($columns) === 0) {
                // Verify table exists by running a simple query
                $this->pdo->query("SELECT 1 FROM \"{$table}\" LIMIT 1");
            }
        } catch (\PDOException $e) {
            throw new \RuntimeException("Table not found: {$table}");
        }

        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file for writing: {$path}");
        }

        try {
            fputcsv($handle, $columns);

            $rows = $this->pdo->query("SELECT * FROM \"{$table}\"");
            if ($rows === false) {
                throw new \RuntimeException("Cannot query table: {$table}");
            }

            foreach ($rows->fetchAll(\PDO::FETCH_NUM) as $row) {
                fputcsv($handle, $row);
            }
        } finally {
            fclose($handle);
        }
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

            $tables = $this->tables();
            foreach ($tables as $table) {
                $safeTable = str_replace('"', '""', $table);
                $createResult = $this->pdo->query(
                    "SELECT sql FROM sqlite_master WHERE type='table' AND name = '{$safeTable}'",
                );
                $createRow = $createResult?->fetch(\PDO::FETCH_ASSOC);
                $createSql = $createRow['sql'] ?? null;
                if ($createSql === null) {
                    continue;
                }
                fwrite($handle, $createSql . ";\n\n");

                $rows = $this->pdo->query("SELECT * FROM \"{$safeTable}\"");
                if ($rows === false) {
                    continue;
                }

                $columnResult = $this->pdo->query("PRAGMA table_info(\"{$safeTable}\")");
                $columns = $columnResult !== false
                    ? array_column($columnResult->fetchAll(\PDO::FETCH_ASSOC), 'name')
                    : [];

                foreach ($rows->fetchAll(\PDO::FETCH_NUM) as $row) {
                    $values = array_map(
                        fn($val): string => $val === null
                            ? 'NULL'
                            : "'" . str_replace("'", "''", (string) $val) . "'",
                        $row
                    );
                    $columnsList = implode(', ', $columns);
                    $valuesList = implode(', ', $values);
                    fwrite($handle, "INSERT INTO \"{$table}\" ({$columnsList}) VALUES ({$valuesList});\n");
                }
                fwrite($handle, "\n");
            }
        } finally {
            fclose($handle);
        }
    }
}
