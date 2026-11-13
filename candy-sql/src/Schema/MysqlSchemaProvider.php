<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Schema;

use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\Identifier;

/**
 * MySQL schema provider using information_schema queries.
 *
 * Works for MySQL, MariaDB, and Percona variants since they
 * all expose the information_schema tables.
 */
final class MysqlSchemaProvider implements SchemaProviderInterface
{
    private Flavor $flavor = Flavor::MySQL;

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
            . "WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' "
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
        // Value position: table_name is a VARCHAR column, so the comparison must
        // be a string literal (PDO::quote is charset-authoritative), not the
        // identifier grammar.
        $safeTable = $this->db->quote($table);
        $result = $this->db->query(
            "SELECT column_name, column_type, is_nullable, column_key, column_default "
            . "FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() AND table_name = {$safeTable} "
            . "ORDER BY ordinal_position",
        ) ?? [];

        $columns = [];
        foreach ($result as $row) {
            $columns[] = new SchemaColumn(
                cid: 0,
                name: (string) ($row['column_name'] ?? ''),
                type: (string) ($row['column_type'] ?? ''),
                notNull: ($row['is_nullable'] ?? '') === 'NO',
                defaultValue: $row['column_default'] ?? null,
                primaryKey: ($row['column_key'] ?? '') === 'PRI',
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
            "SELECT index_name, column_name, non_unique "
            . "FROM information_schema.statistics "
            . "WHERE table_schema = DATABASE() AND table_name = {$safeTable} "
            . "ORDER BY index_name, seq_in_index",
        ) ?? [];

        $indexMap = [];
        foreach ($result as $row) {
            $idxName = (string) ($row['index_name'] ?? '');
            if (!isset($indexMap[$idxName])) {
                $indexMap[$idxName] = new SchemaIndex(
                    name: $idxName,
                    unique: (int) ($row['non_unique'] ?? 1) === 0,
                    columns: [],
                );
            }

            $existing = $indexMap[$idxName];
            $indexMap[$idxName] = new SchemaIndex(
                name: $existing->name,
                unique: $existing->unique,
                columns: [...$existing->columns, (string) ($row['column_name'] ?? '')],
            );
        }

        return array_values($indexMap);
    }

    /**
     * {@inheritdoc}
     */
    public function foreignKeys(string $table): array
    {
        $safeTable = $this->db->quote($table);
        $result = $this->db->query(
            // key_column_usage carries no referential actions; the NULL
            // literals this arm used to project kept the VO fields permanently
            // empty. referential_constraints holds the real UPDATE_RULE/
            // DELETE_RULE, joined on the constraint's own identity (schema +
            // name), which also filters the rows to genuine FKs.
            "SELECT kcu.constraint_name, kcu.column_name, kcu.referenced_table_name, kcu.referenced_column_name, "
            . "rc.update_rule AS on_update, rc.delete_rule AS on_delete "
            . "FROM information_schema.key_column_usage kcu "
            . "JOIN information_schema.referential_constraints rc "
            . "ON rc.constraint_schema = kcu.constraint_schema AND rc.constraint_name = kcu.constraint_name "
            . "WHERE kcu.table_schema = DATABASE() AND kcu.referenced_table_name IS NOT NULL "
            . "AND kcu.table_name = {$safeTable}",
        ) ?? [];

        $fks = [];
        foreach ($result as $row) {
            $fks[] = new SchemaForeignKey(
                id: 0,
                column: (string) ($row['column_name'] ?? ''),
                foreignTable: (string) ($row['referenced_table_name'] ?? ''),
                foreignColumn: (string) ($row['referenced_column_name'] ?? ''),
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
        $safeTable = Identifier::quote($this->flavor, $table);
        $this->db->exec("DROP TABLE IF EXISTS {$safeTable}");
    }

    /**
     * {@inheritdoc}
     */
    public function withFlavor(Flavor $flavor): self
    {
        $clone = clone $this;
        $clone->flavor = $flavor;
        return $clone;
    }
}
