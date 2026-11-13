<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot;

/**
 * The single tab-stop rule shared by the paint side and the budget side.
 *
 * {@see SgrStateHandler} advances cells while parsing a line (it paints the
 * pad), and {@see LayoutCalculator::paintedColumns()} walks the same
 * arithmetic over the raw stripped text (it budgets the frame). Both hands
 * now call {@see pad()} from this one home — the historic twin-walker note
 * (PHP-CS-07: "the two constants MUST stay equal") collapses into a
 * definition that cannot drift.
 *
 * 8 cells mirrors xterm's default stops. sugar-core's Width::TAB_WIDTH is 4
 * and is deliberately not used here: the paint side already pads to 8-cell
 * stops, so the budget side must charge the same.
 *
 * @internal
 */
final class TabStops
{
    /** Cells between tab stops (xterm default). */
    public const CELL_COUNT = 8;

    /**
     * Cells a tab advances from a zero-based painted column.
     *
     * The answer is always in 1..CELL_COUNT: a tab on an exact stop advances
     * the full width (xterm behaviour, pinned by AnsiParserTest and
     * LayoutCalculatorTest from both sides of the pair).
     */
    public static function pad(int $column): int
    {
        return self::CELL_COUNT - ($column % self::CELL_COUNT);
    }
}
