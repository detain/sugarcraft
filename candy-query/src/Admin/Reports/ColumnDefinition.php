<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Reports;

/**
 * Immutable metadata for a single report column.
 *
 * Describes the column name, display type (for unit formatting),
 * and default display width for rendering.
 *
 * @see Mirrors mysql-workbench sys_reports.js ColumnDefinition
 */
final readonly class ColumnDefinition
{
    /**
     * @param string      $name  Column name as returned by the SQL query
     * @param ColumnType  $type  Display/formatting type (int, bigint, float, time, bytes, string)
     * @param int         $width Default display width in characters
     */
    public function __construct(
        public string $name,
        public ColumnType $type,
        public int $width,
    ) {}

    /**
     * Whether this column represents a time value (picoseconds) that needs unit formatting.
     */
    public function isTime(): bool
    {
        return $this->type === ColumnType::Time;
    }

    /**
     * Whether this column represents a byte value that needs unit formatting.
     */
    public function isBytes(): bool
    {
        return $this->type === ColumnType::Bytes;
    }

    /**
     * Whether this column represents a numeric value (int, bigint, float).
     */
    public function isNumeric(): bool
    {
        return match ($this->type) {
            ColumnType::Int, ColumnType::Bigint, ColumnType::Float => true,
            default => false,
        };
    }
}
