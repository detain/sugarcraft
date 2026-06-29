<?php

declare(strict_types=1);

namespace SugarCraft\Query\Schema;

use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\Identifier;

/**
 * PostgreSQL schema provider using information_schema and pg_catalog.
 */
final class PostgresSchemaProvider implements SchemaProviderInterface
{
    /**
     * @param DatabaseInterface $db
     */
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function tables(): array
    {
        $result = $this->db->query(
            "SELECT table_name FROM information_schema.tables "
            . "WHERE table_schema = 'public' AND table_type = 'BASE TABLE' "
            . "ORDER BY table_name",
        ) ?? [];

        $names = [];
        foreach ($result as $row) {
            if (isset($row['table_name'])) {
                $names[] = (string) $row['table_name'];
            }
        }
        return $names;
    }

    /**
     * {@inheritdoc}
     */
    public function columns(string $table): array
    {
        $safeTable = $this->db->quote($table);
        $result = $this->db->query(
            "SELECT column_name, data_type, is_nullable, column_default, ordinal_position "
            . "FROM information_schema.columns "
            . "WHERE table_schema = 'public' AND table_name = {$safeTable} "
            . "ORDER BY ordinal_position",
        ) ?? [];

        $columns = [];
        foreach ($result as $row) {
            $columns[] = new SchemaColumn(
                cid: (int) ($row['ordinal_position'] ?? 0) - 1,
                name: (string) ($row['column_name'] ?? ''),
                type: (string) ($row['data_type'] ?? ''),
                notNull: ($row['is_nullable'] ?? '') === 'NO',
                defaultValue: $row['column_default'] ?? null,
                primaryKey: false,
            );
        }
        return $columns;
    }

    /**
     * {@inheritdoc}
     */
    public function indexes(string $table): array
    {
        $safeTable = $this->db->quote($table);
        $result = $this->db->query(
            "SELECT indexname, indexdef FROM pg_indexes "
            . "WHERE schemaname = 'public' AND tablename = {$safeTable} "
            . "ORDER BY indexname",
        ) ?? [];

        $indexes = [];
        foreach ($result as $row) {
            $def = (string) ($row['indexdef'] ?? '');
            $columns = $this->extractPostgresIndexColumns($def);

            $indexes[] = new SchemaIndex(
                name: (string) ($row['indexname'] ?? ''),
                unique: str_contains($def, 'UNIQUE'),
                columns: $columns,
            );
        }
        return $indexes;
    }

    /**
     * {@inheritdoc}
     */
    public function foreignKeys(string $table): array
    {
        $safeTable = $this->db->quote($table);
        $result = $this->db->query(
            "SELECT conname AS constraint_name, "
            . "conrelid::regclass AS table_name, "
            . "confrelid::regclass AS foreign_table_name, "
            . "unnest(conkey) AS column_id, "
            . "unnest(confkey) AS foreign_column_id "
            . "FROM pg_constraint "
            . "WHERE contype = 'f' AND conrelid = {$safeTable}::regclass",
        ) ?? [];

        $fks = [];
        foreach ($result as $row) {
            $fks[] = new SchemaForeignKey(
                id: 0,
                column: (string) ($row['column_id'] ?? ''),
                foreignTable: (string) ($row['foreign_table_name'] ?? ''),
                foreignColumn: (string) ($row['foreign_column_id'] ?? ''),
                onUpdate: '',
                onDelete: '',
            );
        }
        return $fks;
    }

    /**
     * {@inheritdoc}
     */
    public function dropTable(string $table): void
    {
        $safeTable = Identifier::quote(Flavor::Postgres, $table);
        $this->db->exec("DROP TABLE IF EXISTS {$safeTable}");
    }

    /**
     * {@inheritdoc}
     */
    public function withFlavor(Flavor $flavor): self
    {
        return $this;
    }

    /**
     * Extract column names from PostgreSQL index definition.
     */
    private function extractPostgresIndexColumns(string $indexdef): array
    {
        // Index def format: "CREATE [UNIQUE] INDEX name ON table USING btree (column1, column2)"
        if (preg_match('/\((.+)\)$/', $indexdef, $matches) === 1) {
            $cols = explode(',', $matches[1]);
            return array_map('trim', $cols);
        }
        return [];
    }
}
