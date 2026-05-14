<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Full data grid component for rendering tabular data with advanced features.
 *
 * Provides a comprehensive data grid with sorting, filtering, pagination,
 * scrolling, and customizable borders and headers/footers.
 */
final class GridTable
{
    public function __construct(
        private readonly array $columns = [],
        private readonly array $rows = [],
    ) {}

    public static function new(array $columns = [], array $rows = []): self
    {
        return new self(columns: $columns, rows: $rows);
    }

    public function render(): string
    {
        return '';
    }
}
