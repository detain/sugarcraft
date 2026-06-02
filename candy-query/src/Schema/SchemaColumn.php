<?php

declare(strict_types=1);

namespace SugarCraft\Query\Schema;

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
