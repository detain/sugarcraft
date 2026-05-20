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
        $t = Table::withColumns([
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
        $t = Table::withColumns([
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
        $t = Table::withColumns([
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
        $t = Table::withColumns([
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
}
