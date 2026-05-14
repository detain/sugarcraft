<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Calculation helpers for data grid tables.
 *
 * Provides utility methods for computing column widths, totals, and averages.
 */
final class Calc
{
    /**
     * Compute optimal column widths based on content.
     *
     * @param array<Column> $columns
     * @param array<Row> $rows
     * @return array<int, int>
     */
    public static function computeColumnWidths(array $columns, array $rows, int $availableWidth = 0): array
    {
        $widths = [];

        foreach ($columns as $index => $column) {
            $widths[$index] = $column->getWidth();
        }

        if ($availableWidth > 0) {
            $totalSpecified = array_sum($widths);
            if ($totalSpecified < $availableWidth) {
                $extra = $availableWidth - $totalSpecified;
                $count = count($widths);
                $perColumn = (int) floor($extra / $count);
                $remainder = $extra - $perColumn * $count;

                for ($i = 0; $i < $count - 1; $i++) {
                    $widths[$i] += $perColumn;
                }
                $widths[$count - 1] += $perColumn + $remainder;
            }
        }

        return $widths;
    }

    /**
     * Calculate sum of a column.
     *
     * @param array<Row> $rows
     */
    public static function sum(array $rows, string $columnKey): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $value = $row->getCell($columnKey);
            if (is_numeric($value)) {
                $total += (float) $value;
            }
        }
        return $total;
    }

    /**
     * Calculate average of a column.
     *
     * @param array<Row> $rows
     */
    public static function avg(array $rows, string $columnKey): float
    {
        if (count($rows) === 0) {
            return 0.0;
        }
        return self::sum($rows, $columnKey) / count($rows);
    }

    /**
     * Find minimum value in a column.
     *
     * @param array<Row> $rows
     */
    public static function min(array $rows, string $columnKey): ?float
    {
        $min = null;
        foreach ($rows as $row) {
            $value = $row->getCell($columnKey);
            if (is_numeric($value)) {
                $v = (float) $value;
                $min = $min === null ? $v : min($min, $v);
            }
        }
        return $min;
    }

    /**
     * Find maximum value in a column.
     *
     * @param array<Row> $rows
     */
    public static function max(array $rows, string $columnKey): ?float
    {
        $max = null;
        foreach ($rows as $row) {
            $value = $row->getCell($columnKey);
            if (is_numeric($value)) {
                $v = (float) $value;
                $max = $max === null ? $v : max($max, $v);
            }
        }
        return $max;
    }

    /**
     * Count non-null values in a column.
     *
     * @param array<Row> $rows
     */
    public static function count(array $rows, string $columnKey): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if ($row->getCell($columnKey) !== null) {
                $count++;
            }
        }
        return $count;
    }
}
