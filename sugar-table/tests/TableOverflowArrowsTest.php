<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Table\{Column, Row, RowData, Table};

/** OG-5 / ST-70: withMaxTotalWidth window, scrollLeft/Right, header overflow arrows. */
final class TableOverflowArrowsTest extends TestCase
{
    private function table(): Table
    {
        return Table::fromColumns([
            Column::new('a', 'AAAA', 6),
            Column::new('b', 'BBBB', 6),
            Column::new('c', 'CCCC', 6),
            Column::new('d', 'DDDD', 6),
        ])->withRows([Row::new(RowData::from(['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4']))])->withSelectable(false);
    }

    private function plain(Table $t): string
    {
        return (string) \preg_replace('/\e\[[0-9;]*m/', '', $t->view());
    }

    public function testMaxTotalWidthWindowsColumns(): void
    {
        $view = $this->plain($this->table()->withMaxTotalWidth(14));
        $this->assertStringContainsString('AAAA', $view);
        $this->assertStringContainsString('BBBB', $view);
        $this->assertStringNotContainsString('CCCC', $view);
        $this->assertStringNotContainsString('DDDD', $view);
    }

    public function testAlwaysKeepsOneScrollableColumn(): void
    {
        $this->assertStringContainsString('AAAA', $this->plain($this->table()->withMaxTotalWidth(1)));
    }

    public function testRightArrowMarksOverflowOnly(): void
    {
        $t = $this->table()->withMaxTotalWidth(14)->withOverflowArrows();
        $this->assertStringContainsString('»', $this->plain($t));
        $this->assertStringNotContainsString('»', $this->plain($this->table()->withMaxTotalWidth(14)));
        $this->assertStringNotContainsString('»', $this->plain($this->table()->withOverflowArrows()));
    }

    public function testScrollRightShowsLaterColumnsAndLeftArrow(): void
    {
        $t = $this->table()->withMaxTotalWidth(14)->withOverflowArrows()->scrollRight()->scrollRight();
        $view = $this->plain($t);
        $this->assertSame(2, $t->withMaxTotalWidth(14)->scrollRight()->scrollRight()->scrollX());
        $this->assertStringContainsString('«', $view);
        $this->assertStringContainsString('DDDD', $view);
        $this->assertStringNotContainsString('»', $view, 'last column reached: nothing overflows right');
    }

    public function testScrollRightStopsWhenNothingOverflows(): void
    {
        $t = $this->table()->withMaxTotalWidth(14)->scrollRight()->scrollRight()->scrollRight();
        $this->assertSame(2, $t->scrollX());
    }

    public function testScrollLeftClampsAtZero(): void
    {
        $this->assertSame(0, $this->table()->scrollLeft()->scrollX());
        $this->assertSame(1, $this->table()->withScrollX(2)->scrollLeft()->scrollX());
    }

    public function testFrozenColumnsStayInsideTheWindow(): void
    {
        $view = $this->plain($this->table()->withFrozenCols([0])->withScrollX(2)->withMaxTotalWidth(14));
        $this->assertStringContainsString('AAAA', $view);
        $this->assertStringContainsString('DDDD', $view);
        $this->assertStringNotContainsString('BBBB', $view);
    }
}
