<?php

declare(strict_types=1);

namespace SugarCraft\Query\Schema;

use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\DatabaseInterface;

/**
 * SQLite schema provider using PRAGMA queries.
 *
 * @see https://www.sqlite.org/pragma.html#pragfunc
 */
final class SqliteSchemaProvider implements SchemaProviderInterface
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
            "SELECT name FROM sqlite_master "
            . "WHERE type = 'table' AND name NOT LIKE 'sqlite_%' "
            . "ORDER BY name",
        );

        $names = [];
        foreach ($result as $row) {
            if (isset($row['name'])) {
                $names[] = (string) $row['name'];
            }
        }
        return $names;
    }

    /**
     * {@inheritdoc}
     */
    public function columns(string $table): array
    {
        $safeTable = $this->safeIdent($table);
        $result = $this->db->query("PRAGMA table_info(\"{$safeTable}\")");

        $columns = [];
        foreach ($result as $row) {
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
     * {@inheritdoc}
     */
    public function indexes(string $table): array
    {
        $safeTable = $this->safeIdent($table);
        $result = $this->db->query("PRAGMA index_list(\"{$safeTable}\")");

        $indexes = [];
        foreach ($result as $row) {
            $idxName = (string) ($row['name'] ?? '');
            $safeIdx = $this->safeIdent($idxName);
            $idxInfo = $this->db->query("PRAGMA index_info(\"{$safeIdx}\")");

            $columns = [];
            foreach ($idxInfo as $info) {
                $columns[] = (string) ($info['name'] ?? '');
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
     * {@inheritdoc}
     */
    public function foreignKeys(string $table): array
    {
        $safeTable = $this->safeIdent($table);
        $result = $this->db->query("PRAGMA foreign_key_list(\"{$safeTable}\")");

        $fks = [];
        foreach ($result as $row) {
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
     * {@inheritdoc}
     */
    public function dropTable(string $table): void
    {
        $safeTable = $this->safeIdent($table);
        $this->db->exec("DROP TABLE IF EXISTS \"{$safeTable}\"");
    }

    /**
     * {@inheritdoc}
     */
    public function withFlavor(Flavor $flavor): self
    {
        return $this;
    }

    /**
     * Escape identifier for safe SQL inclusion.
     */
    private function safeIdent(string $name): string
    {
        return str_replace('"', '""', $name);
    }
}
