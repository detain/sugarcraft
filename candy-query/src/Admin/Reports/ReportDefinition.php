<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Reports;

/**
 * Immutable descriptor for a single performance report.
 *
 * Encapsulates the sys schema view name, category, display caption,
 * description, query template, and per-column type/width metadata.
 *
 * @see Mirrors mysql-workbench sys_reports.js ReportDefinition
 */
final readonly class ReportDefinition
{
    /**
     * @param string $name           View name in the sys schema (e.g. "x$statement_analysis")
     * @param string $category       Logical grouping (e.g. "problems", "io", "memory")
     * @param string $caption        Human-readable report title
     * @param string $description    Brief explanation of the report's purpose
     * @param string $query          SQL query template (SELECT * FROM sys.<view>)
     * @param list<ColumnDefinition> $columns Ordered list of column definitions
     */
    public function __construct(
        public string $name,
        public string $category,
        public string $caption,
        public string $description,
        public string $query,
        public array $columns,
    ) {}

    /**
     * Check if a column exists in this report.
     */
    public function hasColumn(string $columnName): bool
    {
        foreach ($this->columns as $column) {
            if ($column->name === $columnName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a column definition by name.
     *
     * @return ColumnDefinition|null The column metadata or null if not found
     */
    public function column(string $columnName): ?ColumnDefinition
    {
        foreach ($this->columns as $column) {
            if ($column->name === $columnName) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Get all column names in order.
     *
     * @return list<string>
     */
    public function columnNames(): array
    {
        return array_map(
            fn(ColumnDefinition $col): string => $col->name,
            $this->columns
        );
    }

    /**
     * Get column names that should be formatted as time (picoseconds).
     *
     * @return list<string>
     */
    public function timeColumns(): array
    {
        return array_values(array_filter(
            $this->columnNames(),
            fn(string $name): bool => $this->column($name)?->type === ColumnType::Time
        ));
    }

    /**
     * Get column names that should be formatted as bytes.
     *
     * @return list<string>
     */
    public function byteColumns(): array
    {
        return array_values(array_filter(
            $this->columnNames(),
            fn(string $name): bool => $this->column($name)?->type === ColumnType::Bytes
        ));
    }
}
