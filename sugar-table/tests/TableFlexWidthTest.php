<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\{Column, ColumnWidth, Row, RowData, Table};
use PHPUnit\Framework\TestCase;

/**
 * Pins for the weighted Flex split (audit MAJOR 15).
 *
 * Before the fix, Column::withFlexibleWidth($share) was stored, fluent-
 * carried, and never read: every Flex column took an EQUAL share, so a 3:1
 * pair rendered 20/20. The weight now drives the split, while tables with no
 * configured weights must keep the old equal widths byte-for-byte (default
 * compatibility — every slot counts as a single share).
 */
final class TableFlexWidthTest extends TestCase
{
    private function flexTable(Column ...$columns): Table
    {
        return Table::fromColumns($columns)
            ->withRows([Row::new(RowData::from(['a' => 'x', 'b' => 'y']))]);
    }

    public function testUnweightedFlexColumnsStillSplitEqually(): void
    {
        $t = $this->flexTable(
            Column::new('a', 'A', 0)->withColumnWidth(ColumnWidth::Flex),
            Column::new('b', 'B', 0)->withColumnWidth(ColumnWidth::Flex),
        );

        // 41 total - 1 inter-column border = 40 -> 20/20, same as the old equal split.
        $this->assertSame([20, 20], $t->computeColumnWidths(41));
    }

    public function testWeightsDriveTheSplit(): void
    {
        $t = $this->flexTable(
            Column::new('a', 'A', 0)->withColumnWidth(ColumnWidth::Flex)->withFlexibleWidth(3),
            Column::new('b', 'B', 0)->withColumnWidth(ColumnWidth::Flex),
        );

        // shares 3 + 1 over 40 -> 30/10: the weight is honored, the sum exact.
        $widths = $t->computeColumnWidths(41);
        $this->assertSame([30, 10], $widths);
        $this->assertSame(40, \array_sum($widths));
    }

    public function testWeightedFlexSharesSpaceWithContentComparedDynamic(): void
    {
        $t = $this->flexTable(
            Column::new('a', 'A', 0)->withColumnWidth(ColumnWidth::Dynamic),
            Column::new('b', 'B', 0)->withColumnWidth(ColumnWidth::Flex)->withFlexibleWidth(3),
        );

        // 4 total shares over 40: the unit share is 10. The Dynamic column
        // keeps the max(content, unit) rule; the weighted Flex takes 3 units.
        $widths = $t->computeColumnWidths(41);
        $this->assertSame(30, $widths[1]);
        $this->assertGreaterThanOrEqual(10, $widths[0]);
    }

    public function testSlackDistributionStillKeepsPinnedWidthExact(): void
    {
        // 41 - 1 = 40 across shares 3:1 lands exactly, but a width that does
        // not divide (42 -> 41 across 4 shares = 10.25) must still hand the
        // rounding slack to the LAST Flex column so the row reaches the frame.
        $t = $this->flexTable(
            Column::new('a', 'A', 0)->withColumnWidth(ColumnWidth::Flex)->withFlexibleWidth(3),
            Column::new('b', 'B', 0)->withColumnWidth(ColumnWidth::Flex),
        )->withWidth(42);

        $widths = $t->computeColumnWidths(42);
        $this->assertSame(42, \array_sum($widths) + 1);
    }

    /**
     * ST-11 pin: hiding a flex column must drop it from the solve basis. The
     * finding's probe — three equal Flex columns, middle one hidden — used to
     * keep border reserve, shares and slack on the ALL-columns basis while
     * the frame laid out two survivors: every rendered line fell short of
     * the pinned width, breaking the documented "flex absorbs all available
     * width" invariant and right-edge selection reach.
     */
    public function testHiddenFlexColumnDoesNotConsumeWidth(): void
    {
        $t = $this->flexTable(
            Column::new('a', 'A', 0)->withColumnWidth(ColumnWidth::Flex),
            Column::new('b', 'B', 0)->withColumnWidth(ColumnWidth::Flex),
            Column::new('c', 'C', 0)->withColumnWidth(ColumnWidth::Flex),
        )->withHiddenCols([1])->withWidth(41);

        $widths = $t->computeColumnWidths(41);

        // 41 minus the ONE visible-column border = 40 split 20/20; the hidden
        // slot stays a neutral placeholder carrying its declared width.
        $this->assertSame(20, $widths[0]);
        $this->assertSame(20, $widths[2]);
        $this->assertSame(0, $widths[1]);

        // The visible basis sums exactly to the pinned width — the frame
        // reaches the right edge instead of rendering a third short.
        $this->assertSame(41, $widths[0] + $widths[2] + 1);
    }
}
