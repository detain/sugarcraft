<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * A data row with cells indexed by column key.
 *
 * @param array<string, mixed> $cells
 */
final class Row
{
    public function __construct(
        public readonly array $cells = [],
    ) {}

    /**
     * Get cell value by key.
     */
    public function get(string $key): mixed
    {
        return $this->cells[$key] ?? null;
    }

    /**
     * Check if row has a cell with the given key.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->cells);
    }
}
