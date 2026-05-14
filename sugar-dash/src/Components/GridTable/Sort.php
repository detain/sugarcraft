<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Sort state and logic for data grid tables.
 *
 * Tracks which column is sorted and the sort direction.
 */
enum SortDirection
{
    case Asc;
    case Desc;
}

final class Sort
{
    public function __construct(
        private readonly ?string $column = null,
        private readonly SortDirection $direction = SortDirection::Asc,
    ) {}

    public static function none(): self
    {
        return new self(column: null, direction: SortDirection::Asc);
    }

    public static function asc(string $column): self
    {
        return new self(column: $column, direction: SortDirection::Asc);
    }

    public static function desc(string $column): self
    {
        return new self(column: $column, direction: SortDirection::Desc);
    }

    public function getColumn(): ?string
    {
        return $this->column;
    }

    public function getDirection(): SortDirection
    {
        return $this->direction;
    }

    public function isSorted(): bool
    {
        return $this->column !== null;
    }

    public function toggleDirection(): self
    {
        return new self(
            column: $this->column,
            direction: $this->direction === SortDirection::Asc
                ? SortDirection::Desc
                : SortDirection::Asc,
        );
    }
}
