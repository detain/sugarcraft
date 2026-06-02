<?php

declare(strict_types=1);

namespace SugarCraft\Query\Schema;

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
