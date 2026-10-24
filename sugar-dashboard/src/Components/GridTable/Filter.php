<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\GridTable;

/**
 * Filter state and logic for data grid tables.
 *
 * Tracks active filters per column.
 */
final class Filter
{
    public function __construct(
        private readonly array $filters = [],
    ) {}

    public static function none(): self
    {
        return new self(filters: []);
    }

    /**
     * @param array<string, string> $filters Map of column key to filter value
     */
    public static function fromArray(array $filters): self
    {
        return new self(filters: $filters);
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return $this->filters;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::filters()} instead. REMOVED-IN: 2.0.0.
     */
    public function getFilters(): array { return $this->filters(); }

    public function filter(string $column): ?string
    {
        return $this->filters[$column] ?? null;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::filter()} instead. REMOVED-IN: 2.0.0.
     */
    public function getFilter(string $column): ?string { return $this->filter($column); }

    public function hasFilter(string $column): bool
    {
        return isset($this->filters[$column]) && $this->filters[$column] !== '';
    }

    public function isActive(): bool
    {
        return count($this->filters) > 0;
    }

    public function withFilter(string $column, string $value): self
    {
        $filters = $this->filters;
        $filters[$column] = $value;
        return new self(filters: $filters);
    }

    public function withoutFilter(string $column): self
    {
        $filters = $this->filters;
        unset($filters[$column]);
        return new self(filters: $filters);
    }

    public function clear(): self
    {
        return new self(filters: []);
    }
}
