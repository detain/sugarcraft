<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\GridTable;

use SugarCraft\Core\Util\Width;

/**
 * Calculation utilities for grid layout.
 *
 * Every width here is a terminal display-cell count (sugar-core Width), not a
 * code-point count: a CJK ideograph occupies two cells and an emoji two, so
 * measuring with mb_strlen() made those columns too narrow and misaligned the
 * frame.
 *
 * The measure is Width::string's, so it differs from the old code-point rule
 * for wide, zero-width and combining characters, for tabs and for SGR: a CJK
 * ideograph or emoji is two cells, not one; a zero-width or combining mark adds
 * none (e followed by U+0301 is one cell, not two); a tab is charged
 * Width::TAB_WIDTH cells, not one; and SGR sequences cost nothing, not their
 * byte count. Printable ASCII is unchanged.
 *
 * The tab charge is a measurement, not an expansion, and it reaches three
 * places: colMinWidth() (the column width), the pad count GridTable adds with
 * Width::padRight (header, row, frozen and notice rows), and where truncate()
 * cuts. Nothing expands the tab. GridTable writes cell values raw, so a tab that
 * fits still reaches the terminal, which draws it to the next tab stop, not
 * necessarily four cells on. Example: a column labeled "Name" holding "a\tb" is
 * six cells wide with no pad space; the code-point rule made it four with one.
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

        // The minimums overflow the budget: fail toward FIT, not overflow
        // (finding sugar-dashboard-046 - the old branch returned the minimums
        // and the single caller never handled them, so the frame grew past its
        // allocation). Shrink proportionally so the result sums to exactly the
        // budget; one cell per column is the floor.
        if ($totalMin > $availableWidth) {
            return self::shrinkToFit($minWidths, $availableWidth);
        }

        // If we have enough space for minimums, distribute extra space
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
     * Scale minimum widths down until they sum to exactly the budget.
     *
     * Largest-remainder rounding keeps the split proportional; the max(1,…)
     * floor can overshoot after flooring, which is shaved back off the widest
     * columns. A budget below the column count cannot seat one cell per column
     * — every column then gets its single cell and the sum says so.
     *
     * @param array<int, int> $minWidths
     * @return array<int, int>
     */
    private static function shrinkToFit(array $minWidths, int $budget): array
    {
        $numCols = count($minWidths);

        if ($budget <= 0) {
            return $minWidths;
        }

        if ($budget < $numCols) {
            return array_fill(0, $numCols, 1);
        }

        $totalMin = array_sum($minWidths);
        $scaled = [];
        $remainders = [];

        foreach ($minWidths as $i => $w) {
            $exact = $w * $budget / $totalMin;
            $scaled[$i] = max(1, (int) floor($exact));
            $remainders[$i] = $exact - floor($exact);
        }

        $diff = $budget - array_sum($scaled);

        if ($diff > 0) {
            arsort($remainders);
            foreach ($remainders as $i => $_remainder) {
                if ($diff <= 0) {
                    break;
                }
                $scaled[$i]++;
                $diff--;
            }
        } elseif ($diff < 0) {
            $excess = -$diff;
            $order = array_keys($scaled);
            usort($order, fn(int $a, int $b): int => $scaled[$b] <=> $scaled[$a]);
            foreach ($order as $i) {
                if ($excess <= 0) {
                    break;
                }
                $take = min($excess, $scaled[$i] - 1);
                if ($take > 0) {
                    $scaled[$i] -= $take;
                    $excess -= $take;
                }
            }
        }

        return $scaled;
    }

    /**
     * Calculate minimum width for a column based on content.
     *
     * @param list<Row> $rows
     */
    private static function colMinWidth(Column $col, array $rows): int
    {
        $width = Width::string($col->label);

        foreach ($rows as $row) {
            $value = $row->get($col->key);
            if ($value !== null) {
                $str = is_string($value) ? $value : (string) $value;
                $len = Width::string($str);
                if ($len > $width) {
                    $width = $len;
                }
            }
        }

        return $width;
    }

    /**
     * Truncate a string to at most $maxWidth display cells, ending in an
     * ellipsis when it is cut. Never splits a grapheme cluster.
     *
     * Behavior change (P0-A11): a string that is cut loses every escape sequence
     * it carries, not only SGR. Width::clipCells hands the cut to Width::truncate,
     * which runs Ansi::strip over the whole string, so CSI, OSC (OSC 8 hyperlinks
     * included), DCS, SOS, PM and APC sequences and raw C1 bytes all go. Master's
     * mb_substr kept the escapes that fell before the cut and could cut mid-escape,
     * so a clipped GridTable cell or unsorted header label rendered partly styled.
     * Now it renders plain. A string that fits is returned whole, escapes included.
     * Pinned by testSgrBytesAreNotChargedToCells and
     * testAClippedOsc8HyperlinkLosesItsEscapes in DisplayCellWidthTest.
     */
    public static function truncate(string $str, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return '';
        }

        return Width::clipCells($str, $maxWidth, '…');
    }
}
