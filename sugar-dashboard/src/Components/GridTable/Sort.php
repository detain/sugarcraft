<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\GridTable;

/**
 * Sort state and logic for data grid tables.
 *
 * Tracks which column is sorted and the sort direction ({@see SortDirection},
 * declared once, in its own file).
 */
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

    public function column(): ?string
    {
        return $this->column;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::column()} instead. REMOVED-IN: 2.0.0.
     */
    public function getColumn(): ?string { return $this->column(); }

    public function direction(): SortDirection
    {
        return $this->direction;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::direction()} instead. REMOVED-IN: 2.0.0.
     */
    public function getDirection(): SortDirection { return $this->direction(); }

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
