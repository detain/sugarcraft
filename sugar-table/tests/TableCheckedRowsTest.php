<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Table\{Column, Row, RowData, Table, WrapMode};

/** OG-5 / ST-38: multi-row selection set (checked rows) with an optional checkbox gutter. */
final class TableCheckedRowsTest extends TestCase
{
    /** @var list<Row> */
    private array $rows;

    private function table(): Table
    {
        $this->rows = [];
        foreach (['alpha', 'beta', 'gamma'] as $v) {
            $this->rows[] = Row::new(RowData::from(['a' => $v]));
        }

        return Table::fromColumns([Column::new('a', 'A', 6)])->withRows($this->rows);
    }

    private function plain(Table $t): string
    {
        return (string) \preg_replace('/\e\[[0-9;]*m/', '', $t->view());
    }

    public function testToggleChecksTheCursorRowAndIsIndependentOfIt(): void
    {
        $t = $this->table()->toggleChecked()->selectNext()->toggleChecked()->toggleChecked(2);
        $this->assertSame($this->rows, $t->checkedRows());
        $t = $t->toggleChecked(2);
        $this->assertSame([$this->rows[0], $this->rows[1]], $t->checkedRows());
        $this->assertSame(1, $t->selectedIndex(), 'the cursor did not move');
        $this->assertTrue($t->checked($this->rows[0]));
        $this->assertFalse($t->checked($this->rows[2]));
    }

    public function testToggleTwiceUnchecks(): void
    {
        $t = $this->table();
        $this->assertSame([], $t->toggleChecked()->toggleChecked()->checkedRows());
    }

    public function testAllCheckedAndAllUnchecked(): void
    {
        $t = $this->table()->withAllRowsChecked();
        $this->assertCount(3, $t->checkedRows());
        $this->assertSame([], $t->withAllRowsUnchecked()->checkedRows());
        $empty = $this->table();
        $this->assertSame($empty, $empty->withAllRowsUnchecked());
    }

    public function testCheckedRowsFollowViewOrderAndFilter(): void
    {
        $t = $this->table()->withAllRowsChecked()->filter('a', 'a')->sortBy('a', false);
        $this->assertSame([$this->rows[2], $this->rows[1], $this->rows[0]], $t->checkedRows());
        $this->assertSame([$this->rows[2]], $t->filter('a', 'gam')->checkedRows());
    }

    public function testWithRowsPrunesStaleIdentities(): void
    {
        $t = $this->table()->withAllRowsChecked();
        $this->assertSame([$this->rows[1]], $t->withRows([$this->rows[1]])->checkedRows());
        $this->assertSame([], $t->withRows([Row::new(RowData::from(['a' => 'new']))])->checkedRows());
    }

    public function testBadIndexAndForeignRowThrow(): void
    {
        $t = $this->table();
        try {
            $t->toggleChecked(9);
            $this->fail('expected OutOfBounds');
        } catch (\OutOfBoundsException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        $t->withRowChecked(Row::new(RowData::from(['a' => 'x'])));
    }

    public function testEmptyViewToggleIsANoOp(): void
    {
        $t = Table::fromColumns([Column::new('a', 'A', 6)]);
        $this->assertSame([], $t->toggleChecked()->checkedRows());
    }

    public function testNoGutterByDefault(): void
    {
        $this->assertStringNotContainsString('[x]', $this->plain($this->table()->toggleChecked()));
        $this->assertSame($this->table()->view(), $this->table()->withCheckMarks()->withoutCheckMarks()->view());
    }

    public function testCheckboxGutterRendersMarks(): void
    {
        $t = $this->table()->withSelectable(false)->withCheckMarks()->toggleChecked(1);
        $this->assertSame(
            ['┌──────────┐', '│         A│', '│──────────│', '│[ ]  alpha│', '│[x]   beta│', '│[ ]  gamma│', '└──────────┘'],
            \explode("\n", $this->plain($t)),
        );
        $lines = \explode("\n", $this->plain($t));
        $this->assertStringContainsString('[x]', $lines[4]);
        $this->assertStringContainsString('[ ]', $lines[3]);
        $this->assertSame(1, \count(\array_unique(\array_map('mb_strlen', $lines))), 'every line is the same width');
    }

    public function testCustomMarksAndMultilineAndExpanded(): void
    {
        $t = Table::fromColumns([Column::new('a', 'A', 4)->withWrapMode(WrapMode::Character)])
            ->withRows([Row::new(RowData::from(['a' => 'abcdefgh'])), Row::new(RowData::from(['a' => 'z']))])
            ->withSelectable(false)->withMultilineMode(true)->withCheckMarks('○', '●')->toggleChecked(0);
        $lines = \explode("\n", $this->plain($t));
        $this->assertSame(1, \count(\array_unique(\array_map('mb_strlen', $lines))));
        $this->assertStringContainsString('●', $lines[3]);
        $this->assertStringNotContainsString('●', $lines[4], 'continuation line carries a blank gutter');
        $this->assertStringContainsString('○', $this->plain($t));
        $expanded = $t->withMultilineMode(false)->toggleExpanded(0);
        $this->assertSame(1, \count(\array_unique(\array_map('mb_strlen', \explode("\n", $this->plain($expanded))))));
    }
}
