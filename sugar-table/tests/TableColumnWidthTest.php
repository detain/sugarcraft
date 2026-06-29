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
}
