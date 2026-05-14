<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Row data container for data grid tables.
 *
 * Holds the cell values for a single row in the grid.
 */
final class Row
{
    /**
     * @param array<string, mixed> $cells
     */
    public function __construct(
        private readonly array $cells = [],
    ) {}

    public static function new(array $cells = []): self
    {
        return new self(cells: $cells);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCells(): array
    {
        return $this->cells;
    }

    public function getCell(string $key): mixed
    {
        return $this->cells[$key] ?? null;
    }
}
