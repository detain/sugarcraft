<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Table\{Column, Row, RowData, Table, WrapMode};

/**
 * OG-3 / ST-20: withViewportHeight() counts ROWS; renderedLineCount() makes
 * the resulting height queryable and withMaxLines() budgets by lines.
 */
final class TableLineBudgetTest extends TestCase
{
    /** Each row wraps to 2 lines (11 chars into a 6-wide cell). */
    private function wrapped(int $rows = 5): Table
    {
        $data = [];
        for ($i = 0; $i < $rows; $i++) {
            $data[] = Row::new(RowData::from(['d' => \sprintf('%02dABCDEFGHI', $i)]));
        }

        return Table::fromColumns([Column::new('d', 'D', 6)->withWrapMode(WrapMode::Character)])
            ->withRows($data)
            ->withMultilineMode(true)
            ->withSelectable(false);
    }

    private function lines(Table $t): int
    {
        return \substr_count($t->view(), "\n") + 1;
    }

    public function testRenderedLineCountMatchesViewLines(): void
    {
        $t = $this->wrapped()->withViewportHeight(2);
        $this->assertSame($this->lines($t), $t->renderedLineCount());
        // viewport counts rows: 2 rows * 2 wrapped lines + border/header chrome.
        $this->assertGreaterThan(2 + 4, $t->renderedLineCount());
    }

    public function testRenderedLineCountIsZeroWithoutColumns(): void
    {
        $this->assertSame(0, Table::fromColumns([])->renderedLineCount());
    }

    public function testMaxLinesTruncatesWholeRows(): void
    {
        $unbounded = $this->wrapped();
        $rowLines = 2; // 11-char value at width 6 wraps to two lines

        $budget = $unbounded->withMaxLines($rowLines * 2);
        $view = $budget->view();
        $this->assertStringContainsString('00', $view);
        $this->assertStringContainsString('01', $view);
        $this->assertStringNotContainsString('02A', $view);
        $this->assertSame(0, $unbounded->maxLines());
        $this->assertSame($rowLines * 2, $budget->maxLines());
    }

    public function testMaxLinesAlwaysKeepsOneRow(): void
    {
        $view = $this->wrapped()->withMaxLines(1)->view();
        $this->assertStringContainsString('00A', $view);
        $this->assertStringNotContainsString('01A', $view);
    }

    public function testMaxLinesKeepsCursorRowOnScreen(): void
    {
        $t = $this->wrapped()->withSelectable(true)->withMaxLines(1)->withSelectedIndex(3);
        $view = $t->view();
        $this->assertStringContainsString('03A', $view);
    }

    public function testNegativeMaxLinesClampsToOff(): void
    {
        $this->assertSame(0, $this->wrapped()->withMaxLines(-4)->maxLines());
    }
}
