<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Table header for data grid tables.
 *
 * Renders the header row with column names and sort indicators.
 */
final class Header
{
    public function __construct(
        private readonly array $columns = [],
        private readonly bool $showSortIndicators = true,
        private readonly ?string $sortedColumn = null,
    ) {}

    public static function new(array $columns = [], bool $showSortIndicators = true, ?string $sortedColumn = null): self
    {
        return new self(columns: $columns, showSortIndicators: $showSortIndicators, sortedColumn: $sortedColumn);
    }

    /**
     * @return array<Column>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function hasSortIndicators(): bool
    {
        return $this->showSortIndicators;
    }

    public function getSortedColumn(): ?string
    {
        return $this->sortedColumn;
    }

    public function render(): string
    {
        return '';
    }

    public function withSortedColumn(?string $column): self
    {
        return new self(
            columns: $this->columns,
            showSortIndicators: $this->showSortIndicators,
            sortedColumn: $column,
        );
    }
}
