<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use SugarCraft\Query\Lang;

/**
 * SQLite schema browser — exposes table_info, index_list, and
 * foreign_key_list PRAGMA results as structured data.
 *
 * @see https://www.sqlite.org/pragma.html#pragfunc
 */
final class SchemaBrowser
{
    /**
     * @param list<SchemaTable> $tables
     */
    public function __construct(
        public readonly \PDO $pdo,
        public readonly array $tables = [],
    ) {}

    /**
     * Refresh schema for all user tables (non-sqlite_% tables).
     */
    public function refresh(): self
    {
        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master "
            . "WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' "
            . "ORDER BY name",
        );
        if ($stmt === false) {
            return new self($this->pdo, []);
        }

        $names = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (isset($row['name'])) {
                $names[] = (string) $row['name'];
            }
        }

        $tables = [];
        foreach ($names as $name) {
            $tables[] = $this->loadTable($name);
        }

        return new self($this->pdo, $tables);
    }

    private function loadTable(string $name): SchemaTable
    {
        return new SchemaTable(
            name: $name,
            columns: $this->loadColumns($name),
            indexes: $this->loadIndexes($name),
            foreignKeys: $this->loadForeignKeys($name),
        );
    }

    /**
     * @return list<SchemaColumn>
     */
    private function loadColumns(string $table): array
    {
        $safe = str_replace('"', '""', $table);
        $stmt = $this->pdo->query("PRAGMA table_info(\"{$safe}\")");
        if ($stmt === false) {
            return [];
        }

        $columns = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $columns[] = new SchemaColumn(
                cid: (int) ($row['cid'] ?? 0),
                name: (string) ($row['name'] ?? ''),
                type: (string) ($row['type'] ?? ''),
                notNull: (bool) ($row['notnull'] ?? false),
                defaultValue: $row['dflt_value'] ?? null,
                primaryKey: (bool) ($row['pk'] ?? false),
            );
        }
        return $columns;
    }

    /**
     * @return list<SchemaIndex>
     */
    private function loadIndexes(string $table): array
    {
        $safe = str_replace('"', '""', $table);
        $stmt = $this->pdo->query("PRAGMA index_list(\"{$safe}\")");
        if ($stmt === false) {
            return [];
        }

        $indexes = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $idxName = (string) ($row['name'] ?? '');
            $idxSafe = str_replace('"', '""', $idxName);
            $idxStmt = $this->pdo->query("PRAGMA index_info(\"{$idxSafe}\")");
            $columns = [];
            if ($idxStmt !== false) {
                foreach ($idxStmt->fetchAll(\PDO::FETCH_ASSOC) as $info) {
                    $columns[] = (string) ($info['name'] ?? '');
                }
            }
            $indexes[] = new SchemaIndex(
                name: $idxName,
                unique: (bool) ($row['unique'] ?? false),
                columns: $columns,
            );
        }
        return $indexes;
    }

    /**
     * @return list<SchemaForeignKey>
     */
    private function loadForeignKeys(string $table): array
    {
        $safe = str_replace('"', '""', $table);
        $stmt = $this->pdo->query("PRAGMA foreign_key_list(\"{$safe}\")");
        if ($stmt === false) {
            return [];
        }

        $fks = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $fks[] = new SchemaForeignKey(
                id: (int) ($row['id'] ?? 0),
                column: (string) ($row['from'] ?? ''),
                foreignTable: (string) ($row['table'] ?? ''),
                foreignColumn: (string) ($row['to'] ?? ''),
                onUpdate: (string) ($row['on_update'] ?? ''),
                onDelete: (string) ($row['on_delete'] ?? ''),
            );
        }
        return $fks;
    }

    /**
     * Drop a table and return a refreshed browser.
     *
     * @throws \PDOException
     */
    public function dropTable(string $name): self
    {
        $safe = str_replace('"', '""', $name);
        $this->pdo->exec("DROP TABLE IF EXISTS \"{$safe}\"");
        return $this->refresh();
    }
}

/**
 * @readonly
 */
final class SchemaTable
{
    /**
     * @param list<SchemaColumn> $columns
     * @param list<SchemaIndex> $indexes
     * @param list<SchemaForeignKey> $foreignKeys
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly array $indexes,
        public readonly array $foreignKeys,
    ) {}

    public function column(string $name): ?SchemaColumn
    {
        foreach ($this->columns as $col) {
            if ($col->name === $name) {
                return $col;
            }
        }
        return null;
    }
}

/**
 * @readonly
 */
final class SchemaColumn
{
    public function __construct(
        public readonly int $cid,
        public readonly string $name,
        public readonly string $type,
        public readonly bool $notNull,
        public readonly mixed $defaultValue,
        public readonly bool $primaryKey,
    ) {}
}

/**
 * @readonly
 */
final class SchemaIndex
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $unique,
        public readonly array $columns,
    ) {}
}

/**
 * @readonly
 */
final class SchemaForeignKey
{
    public function __construct(
        public readonly int $id,
        public readonly string $column,
        public readonly string $foreignTable,
        public readonly string $foreignColumn,
        public readonly string $onUpdate,
        public readonly string $onDelete,
    ) {}
}
