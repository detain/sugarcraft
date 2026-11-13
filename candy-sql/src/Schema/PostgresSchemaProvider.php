<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Schema;

use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\Identifier;

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

        // SQL-O03: the cell editor addresses rows by primary key, and this
        // provider used to hard-code primaryKey=false for every column. The
        // key columns come from the PRIMARY KEY constraint's key usage. It runs
        // BEFORE the column listing so the columns query stays the last
        // statement emitted (the golden-SQL pins capture the final query).
        $pkRows = $this->db->query(
            "SELECT kcu.column_name FROM information_schema.table_constraints tc "
            . "JOIN information_schema.key_column_usage kcu "
            . "ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema AND kcu.table_name = tc.table_name "
            . "WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = 'public' AND tc.table_name = {$safeTable}",
        ) ?? [];
        $pk = [];
        foreach ($pkRows as $row) {
            if (isset($row['column_name'])) {
                $pk[(string) $row['column_name']] = true;
            }
        }

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
                primaryKey: isset($pk[(string) ($row['column_name'] ?? '')]),
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
            // conkey/confkey are pg_constraint attnum vectors; joined through
            // pg_attribute (pairing the two unnests WITH ORDINALITY so composite
            // FK legs stay aligned) they answer COLUMN NAMES like every sibling
            // provider — the raw attnums rendered as "2"/"1" next to name-returning
            // MySQL/SQLite rows. confupdtype/confdeltype are the single-letter
            // referential actions mapped to the SQL keywords the VO displays.
            "SELECT con.conname AS constraint_name, "
            . "con.confrelid::regclass AS foreign_table_name, "
            . "a.attname AS column_name, "
            . "fa.attname AS foreign_column_name, "
            . "CASE con.confupdtype WHEN 'a' THEN 'NO ACTION' WHEN 'r' THEN 'RESTRICT' WHEN 'c' THEN 'CASCADE' WHEN 'n' THEN 'SET NULL' WHEN 'd' THEN 'SET DEFAULT' END AS on_update, "
            . "CASE con.confdeltype WHEN 'a' THEN 'NO ACTION' WHEN 'r' THEN 'RESTRICT' WHEN 'c' THEN 'CASCADE' WHEN 'n' THEN 'SET NULL' WHEN 'd' THEN 'SET DEFAULT' END AS on_delete "
            . "FROM pg_constraint con "
            . "JOIN unnest(con.conkey, con.confkey) WITH ORDINALITY AS cols(local_attnum, ref_attnum, ord) ON true "
            . "JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = cols.local_attnum "
            . "JOIN pg_attribute fa ON fa.attrelid = con.confrelid AND fa.attnum = cols.ref_attnum "
            . "WHERE con.contype = 'f' AND con.conrelid = {$safeTable}::regclass",
        ) ?? [];

        $fks = [];
        foreach ($result as $row) {
            $fks[] = new SchemaForeignKey(
                id: 0,
                column: (string) ($row['column_name'] ?? ''),
                foreignTable: (string) ($row['foreign_table_name'] ?? ''),
                foreignColumn: (string) ($row['foreign_column_name'] ?? ''),
                onUpdate: (string) ($row['on_update'] ?? ''),
                onDelete: (string) ($row['on_delete'] ?? ''),
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
