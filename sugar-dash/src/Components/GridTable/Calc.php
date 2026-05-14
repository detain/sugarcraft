<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Calculation utilities for grid layout.
 */
final class Calc
{
    /**
     * Greatest common divisor using Euclidean algorithm.
     */
    public static function gcd(int $a, int $b): int
    {
        if ($a === 0) {
            return $b;
        }

        if ($b === 0) {
            return $a;
        }

        return self::gcd($b % $a, $a);
    }

    /**
     * Calculate column widths to fill available width.
     *
     * @param list<Row> $rows
     * @param list<Column> $columns
     * @return list<int> Calculated widths for each column
     */
    public static function calculateColumnWidths(
        array $rows,
        array $columns,
        int $availableWidth,
    ): array {
        if ($columns === []) {
            return [];
        }

        // Calculate minimum widths based on content
        $minWidths = array_map(
            fn(Column $col): int => self::colMinWidth($col, $rows),
            $columns,
        );

        // Apply column-level minWidth constraints
        foreach ($columns as $i => $col) {
            if ($col->minWidth !== null && $minWidths[$i] < $col->minWidth) {
                $minWidths[$i] = $col->minWidth;
            }
        }

        $totalMin = array_sum($minWidths);
        $numCols = count($columns);

        // If we have enough space for minimums, distribute extra space
        if ($totalMin >= $availableWidth) {
            // Cannot satisfy minimums - return minimums and let caller handle overflow
            return $minWidths;
        }

        $extra = $availableWidth - $totalMin;
        $widths = $minWidths;

        // Distribute extra space evenly using GCD reduction
        if ($extra > 0 && $numCols > 1) {
            // First pass: try to give each column equal extra space
            $baseExtra = (int) floor($extra / $numCols);
            $remainder = $extra - ($baseExtra * $numCols);

            for ($i = 0; $i < $numCols; $i++) {
                $widths[$i] = $minWidths[$i] + $baseExtra;
                if ($i < $remainder) {
                    $widths[$i]++;
                }
            }
        }

        // Apply maxWidth constraints
        foreach ($columns as $i => $col) {
            if ($col->maxWidth !== null && $widths[$i] > $col->maxWidth) {
                $widths[$i] = $col->maxWidth;
            }
        }

        return $widths;
    }

    /**
     * Calculate minimum width for a column based on content.
     *
     * @param list<Row> $rows
     */
    private static function colMinWidth(Column $col, array $rows): int
    {
        $width = mb_strlen($col->label);

        foreach ($rows as $row) {
            $value = $row->get($col->key);
            if ($value !== null) {
                $str = is_string($value) ? $value : (string) $value;
                $len = mb_strlen($str);
                if ($len > $width) {
                    $width = $len;
                }
            }
        }

        return $width;
    }

    /**
     * Truncate string to maximum width.
     */
    public static function truncate(string $str, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return '';
        }

        $len = mb_strlen($str);
        if ($len <= $maxWidth) {
            return $str;
        }

        return mb_substr($str, 0, $maxWidth - 1) . '…';
    }
}
