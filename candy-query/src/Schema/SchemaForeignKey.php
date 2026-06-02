<?php

declare(strict_types=1);

namespace SugarCraft\Query\Schema;

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
