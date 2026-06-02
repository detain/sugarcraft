<?php

declare(strict_types=1);

namespace SugarCraft\Query\Schema;

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
