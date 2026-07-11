<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\{Column, ColumnWidth, Row, RowData, Table};
use PHPUnit\Framework\TestCase;

final class TableColumnWidthTest extends TestCase
{
    public function testDefaultColumnWidthIsFixed(): void
    {
        $col = Column::new('id', 'ID', 10);
        $this->assertSame(ColumnWidth::Fixed, $col->columnWidth);
    }

    public function testWithColumnWidthFixed(): void
    {
        $col = Column::new('id', 'ID', 10)->withColumnWidth(ColumnWidth::Fixed);
        $this->assertSame(ColumnWidth::Fixed, $col->columnWidth);
    }

    public function testWithColumnWidthPercent(): void
    {
        $col = Column::new('id', 'ID', 10)->withColumnWidth(ColumnWidth::Percent, 25.0);
        $this->assertSame(ColumnWidth::Percent, $col->columnWidth);
        $this->assertSame(25.0, $col->percentValue);
    }

    public function testWithColumnWidthDynamic(): void
    {
        $col = Column::new('id', 'ID', 10)->withColumnWidth(ColumnWidth::Dynamic);
        $this->assertSame(ColumnWidth::Dynamic, $col->columnWidth);
    }

    public function testWithColumnWidthContent(): void
    {
        $col = Column::new('id', 'ID', 10)->withColumnWidth(ColumnWidth::Content);
        $this->assertSame(ColumnWidth::Content, $col->columnWidth);
    }

    public function testComputeColumnWidthsAllFixed(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5),
            Column::new('name', 'Name', 20),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice'])),
        ]);

        $widths = $t->computeColumnWidths(50);
        $this->assertSame([5, 20], $widths);
    }

    public function testComputeColumnWidthsWithPercent(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5)->withColumnWidth(ColumnWidth::Percent, 20.0),
            Column::new('name', 'Name', 20),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice'])),
        ]);

        $widths = $t->computeColumnWidths(100);
        // 20% of 100 = 20, plus 2 for borders
        $this->assertGreaterThanOrEqual(20, $widths[0]);
        $this->assertSame(20, $widths[1]);
    }

    public function testComputeColumnWidthsDynamicUsesContent(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 3)->withColumnWidth(ColumnWidth::Dynamic),
            Column::new('name', 'Name', 3),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'AliceLongName'])),
        ]);

        $widths = $t->computeColumnWidths(50);
        // ID column should use content width (1) or flex width, whichever is larger
        $this->assertGreaterThanOrEqual(1, $widths[0]);
    }

    public function testComputeColumnWidthsContentUsesExactContent(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 3)->withColumnWidth(ColumnWidth::Content),
            Column::new('name', 'Name', 20),
        ])->withRows([
            Row::new(RowData::from(['id' => '12345', 'name' => 'Alice'])),
        ]);

        $widths = $t->computeColumnWidths(50);
        // ID column should use exact content width (5) since it's Content mode
        $this->assertSame(5, $widths[0]);
    }

    public function testColumnWidthImmutability(): void
    {
        $a = Column::new('id', 'ID', 10);
        $b = $a->withColumnWidth(ColumnWidth::Percent, 30.0);
        $c = $b->withColumnWidth(ColumnWidth::Dynamic);

        $this->assertSame(ColumnWidth::Fixed, $a->columnWidth);
        $this->assertSame(ColumnWidth::Percent, $b->columnWidth);
        $this->assertSame(30.0, $b->percentValue);
        $this->assertSame(ColumnWidth::Dynamic, $c->columnWidth);
    }

    public function testDefaultPercentValueIsZero(): void
    {
        $col = Column::new('id', 'ID', 10);
        $this->assertSame(0.0, $col->percentValue);
    }

    public function testColumnWidthPreservesPercentValueWithOtherMethods(): void
    {
        $col = Column::new('id', 'ID', 10)
            ->withColumnWidth(ColumnWidth::Percent, 33.3)
            ->withStyle('1');

        $this->assertSame(ColumnWidth::Percent, $col->columnWidth);
        $this->assertSame(33.3, $col->percentValue);
        $this->assertSame('1', $col->style);
    }

    /**
     * Verify that Dynamic columns render with content-based widths.
     *
     * The column is defined with width=3 but content is "tiny" (4 chars).
     * Dynamic should use max(contentWidth, flexWidth), so it should be at least 4.
     */
    public function testDynamicColumnRendersWithContentWidth(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 3)->withColumnWidth(ColumnWidth::Dynamic),
        ])->withRows([
            Row::new(RowData::from(['id' => 'tiny'])),
        ]);

        $widths = $t->computeColumnWidths(50);
        // Content "tiny" is 4 chars, Dynamic should use max(content, flex) = max(4, ~47)
        $this->assertGreaterThanOrEqual(4, $widths[0]);

        // Render and verify it contains the content properly
        $view = $t->View();
        $this->assertStringContainsString('tiny', $view);
    }

    /**
     * Verify that Content columns render with exact content width.
     *
     * The column is defined with width=3 but content is "short" (5 chars).
     * Content mode should use exact content width (5), not the defined width.
     */
    public function testContentColumnRendersWithExactContentWidth(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 3)->withColumnWidth(ColumnWidth::Content),
        ])->withRows([
            Row::new(RowData::from(['id' => 'short'])),
        ]);

        $widths = $t->computeColumnWidths(50);
        // Content "short" is 5 chars, Content mode should use exactly 5
        $this->assertSame(5, $widths[0]);

        // Render and verify it contains the content properly
        $view = $t->View();
        $this->assertStringContainsString('short', $view);
    }

    /**
     * Verify that Percent columns render with calculated percentages.
     */
    public function testPercentColumnRendersWithCalculatedWidth(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 10)->withColumnWidth(ColumnWidth::Percent, 25.0),
            Column::new('name', 'Name', 10),
        ])->withRows([
            Row::new(RowData::from(['id' => 'X', 'name' => 'Y'])),
        ]);

        $widths = $t->computeColumnWidths(80);
        // 25% of 80 = 20 (minus borders handled by computeColumnWidths)
        $this->assertGreaterThanOrEqual(19, $widths[0]);
    }

    /**
     * Verify that mixed column types all render correctly.
     */
    public function testMixedColumnWidthTypesRender(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5)->withColumnWidth(ColumnWidth::Fixed),
            Column::new('name', 'Name', 3)->withColumnWidth(ColumnWidth::Dynamic),
            Column::new('city', 'City', 10)->withColumnWidth(ColumnWidth::Percent, 30.0),
        ])->withRows([
            Row::new(RowData::from(['id' => '1', 'name' => 'Alice', 'city' => 'NYC'])),
        ]);

        $widths = $t->computeColumnWidths(80);
        $this->assertCount(3, $widths);

        // Fixed should be 5
        $this->assertSame(5, $widths[0]);

        // Dynamic should be at least content width (5 for "Alice")
        $this->assertGreaterThanOrEqual(5, $widths[1]);

        // Percent should be calculated
        $this->assertGreaterThan(0, $widths[2]);

        // Render should work
        $view = $t->View();
        $this->assertStringContainsString('ID', $view);
        $this->assertStringContainsString('Alice', $view);
        $this->assertStringContainsString('NYC', $view);
    }

    /**
     * Verify that the computed widths are actually used during rendering,
     * not the original column->width values.
     */
    public function testRenderUsesComputedWidthsNotColumnWidth(): void
    {
        // Column defined with width=5 but Content mode with longer content
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5)->withColumnWidth(ColumnWidth::Content),
        ])->withRows([
            Row::new(RowData::from(['id' => 'ThisIsLonger'])),
        ]);

        // Compute widths should give exact content width (12)
        $widths = $t->computeColumnWidths(50);
        $this->assertSame(12, $widths[0]);

        // Render should succeed without truncation issues
        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString('ThisIsLonger', $view);
    }

    /**
     * Verify that a table narrower than its content doesn't crash.
     *
     * When tableWidth is smaller than needed for content, the computed widths
     * should still be >= the content width (using max(content, flex)), and
     * rendering should succeed without errors.
     */
    public function testNarrowTableDoesNotCrash(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 5)->withColumnWidth(ColumnWidth::Content),
        ])->withRows([
            Row::new(RowData::from(['id' => 'ThisContentIsVeryLong'])),
        ]);

        // Table width of 5 is much smaller than content length (19)
        $widths = $t->computeColumnWidths(5);
        // Content column should still compute to at least the content width
        $this->assertGreaterThanOrEqual(19, $widths[0]);

        // Rendering should succeed (may be wider than requested, but shouldn't crash)
        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString('ThisContentIsVeryLong', $view);
    }

    /**
     * Verify that Dynamic columns respect min-width even in narrow tables.
     *
     * A Dynamic column with short content in a narrow table should still
     * render at least the minimum flex width, not less.
     */
    public function testDynamicColumnMinimumWidthInNarrowTable(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 3)->withColumnWidth(ColumnWidth::Dynamic),
        ])->withRows([
            Row::new(RowData::from(['id' => 'X'])),
        ]);

        // Even with a tiny table, Dynamic should use at least content width (1)
        $widths = $t->computeColumnWidths(10);
        $this->assertGreaterThanOrEqual(1, $widths[0]);

        $view = $t->View();
        $this->assertIsString($view);
        $this->assertStringContainsString('X', $view);
    }

    /**
     * Verify that Percent columns handle narrow tables gracefully.
     *
     * A 50% column in a 10-char wide table should get at least 5 chars,
     * and rendering should work.
     */
    public function testPercentColumnInNarrowTable(): void
    {
        $t = Table::fromColumns([
            Column::new('id', 'ID', 10)->withColumnWidth(ColumnWidth::Percent, 50.0),
            Column::new('name', 'Name', 10),
        ])->withRows([
            Row::new(RowData::from(['id' => 'X', 'name' => 'Y'])),
        ]);

        $widths = $t->computeColumnWidths(10);
        $this->assertCount(2, $widths);
        // 50% of 10 = 5, but flex allocation may adjust
        $this->assertGreaterThanOrEqual(1, $widths[0]);

        $view = $t->View();
        $this->assertIsString($view);
    }

    // -------------------------------------------------------------------------
    // computeTotalWidth() fixed-point convergence
    // -------------------------------------------------------------------------

    /** Invoke the private auto-sizing total-width solver. */
    private function computeTotalWidthOf(Table $t): int
    {
        $m = new \ReflectionMethod(Table::class, 'computeTotalWidth');
        $m->setAccessible(true);
        return $m->invoke($t);
    }

    /**
     * A Percent column sizes itself as a fraction of the total width, but the
     * total is the sum of the resolved column widths — a mutual dependency a
     * single pass cannot resolve. computeTotalWidth() must iterate to a fixed
     * point so the reported total equals what the columns actually render to.
     *
     * Regression guard: the pre-fix single pass reported 32 for this table
     * while the columns rendered a 43-wide span. Reverting the fixed-point loop
     * makes the self-consistency assertion below fail (32 is not a fixed point:
     * computeColumnWidths(32) sums back to 38, not 32).
     */
    public function testComputeTotalWidthConvergesOnMixedPercentAndDynamic(): void
    {
        $t = Table::fromColumns([
            Column::new('pct', 'PCT', 10)->withColumnWidth(ColumnWidth::Percent, 50.0),
            Column::new('dyn', 'DYN', 10)->withColumnWidth(ColumnWidth::Dynamic),
        ])->withRows([
            Row::new(RowData::from(['pct' => 'x', 'dyn' => 'ThisIsTwentyCharsLong'])),
        ]);

        $total = $this->computeTotalWidthOf($t);

        // Converged, self-consistent fixed point: resolving the columns at the
        // reported total sums back to that same total (+ inter-column borders).
        $borders = \count($t->Columns()) - 1;
        $resolved = \array_sum($t->computeColumnWidths($total)) + $borders;
        $this->assertSame($total, $resolved, 'reported total must be a fixed point of the width solve');

        // Pin the exact converged value (Percent 50% ⇒ 21, Dynamic content ⇒ 21,
        // + 1 border = 43). The old non-converged single pass returned 32.
        $this->assertSame(43, $total);
    }

    /**
     * An over-subscribed Percent set (columns summing to > 100%) has no fixed
     * point, so the solver can never converge. The iteration bound must still
     * make computeTotalWidth() terminate and clamp to a finite width rather
     * than spin forever.
     */
    public function testComputeTotalWidthTerminatesWhenPercentOverSubscribed(): void
    {
        $t = Table::fromColumns([
            Column::new('p1', 'P1', 8)->withColumnWidth(ColumnWidth::Percent, 60.0),
            Column::new('p2', 'P2', 8)->withColumnWidth(ColumnWidth::Percent, 60.0),
            Column::new('con', 'CON', 4)->withColumnWidth(ColumnWidth::Content),
        ])->withRows([
            Row::new(RowData::from(['p1' => 'a', 'p2' => 'b', 'con' => 'TenCharsss'])),
        ]);

        // Reaching this assertion at all proves the bounded loop terminated.
        $total = $this->computeTotalWidthOf($t);
        $this->assertGreaterThan(0, $total);
        $this->assertLessThan(100000, $total, 'clamp bound must keep the width finite');

        // And it still renders without error.
        $this->assertIsString($t->View());
    }
}
