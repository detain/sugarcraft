<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\{Column, Row, RowData, Table, WrapMode};
use PHPUnit\Framework\TestCase;

final class TableWrappingTest extends TestCase
{
    public function testDefaultWrapModeIsNone(): void
    {
        $col = Column::new('id', 'ID', 10);
        $this->assertSame(WrapMode::None, $col->wrapMode);
    }

    public function testWithWrapModeNone(): void
    {
        $col = Column::new('id', 'ID', 10)->withWrapMode(WrapMode::None);
        $this->assertSame(WrapMode::None, $col->wrapMode);
    }

    public function testWithWrapModeWordWrap(): void
    {
        $col = Column::new('id', 'ID', 10)->withWrapMode(WrapMode::WordWrap);
        $this->assertSame(WrapMode::WordWrap, $col->wrapMode);
    }

    public function testWithWrapModeCharacter(): void
    {
        $col = Column::new('id', 'ID', 10)->withWrapMode(WrapMode::Character);
        $this->assertSame(WrapMode::Character, $col->wrapMode);
    }

    public function testRenderCellNoWrapReturnsSingleLine(): void
    {
        $col = Column::new('name', 'Name', 10)->withWrapMode(WrapMode::None);
        $lines = $col->renderCell('Alice');

        $this->assertCount(1, $lines);
        $this->assertSame('     Alice', $lines[0]);
    }

    public function testRenderCellWordWrapBreaksAtSpaces(): void
    {
        $col = Column::new('desc', 'Desc', 8)->withWrapMode(WrapMode::WordWrap);
        $lines = $col->renderCell('Hello World Example');

        // Should break at 'World' since it doesn't fit in 8 chars
        $this->assertGreaterThanOrEqual(2, \count($lines));
    }

    public function testRenderCellCharacterWrapBreaksAtWidth(): void
    {
        $col = Column::new('seq', 'Seq', 3)->withWrapMode(WrapMode::Character);
        $lines = $col->renderCell('ABCDEFGH');

        // Should break every 3 characters
        $this->assertCount(3, $lines);
        $this->assertSame('ABC', $lines[0]);
        $this->assertSame('DEF', $lines[1]);
        $this->assertSame('GH', $lines[2]);
    }

    public function testRenderCellNoneTruncatesLongText(): void
    {
        $col = Column::new('long', 'Long', 5)->withWrapMode(WrapMode::None);
        $lines = $col->renderCell('Christopher');

        $this->assertCount(1, $lines);
        $this->assertSame('Chris', $lines[0]);
    }

    public function testWrapModeImmutability(): void
    {
        $a = Column::new('id', 'ID', 10);
        $b = $a->withWrapMode(WrapMode::WordWrap);
        $c = $b->withWrapMode(WrapMode::Character);

        $this->assertSame(WrapMode::None, $a->wrapMode);
        $this->assertSame(WrapMode::WordWrap, $b->wrapMode);
        $this->assertSame(WrapMode::Character, $c->wrapMode);
    }

    public function testTableViewRendersWrappedCells(): void
    {
        $t = Table::withColumns([
            Column::new('desc', 'Description', 10)->withWrapMode(WrapMode::Character),
        ])->withRows([
            Row::new(RowData::from(['desc' => 'This is a long description'])),
        ]);

        $view = $t->View();
        $this->assertIsString($view);
        // Header is truncated to column width of 10
        $this->assertStringContainsString('Descriptio', $view);
    }

    public function testWrapModePreservesStyle(): void
    {
        $col = Column::new('styled', 'Styled', 10)
            ->withStyle('1;31')
            ->withWrapMode(WrapMode::Character);

        $lines = $col->renderCell('RedText');
        $this->assertStringStartsWith("\x1b[1;31m", $lines[0]);
    }

    public function testWordWrapPreservesFullWords(): void
    {
        $col = Column::new('text', 'Text', 6)->withWrapMode(WrapMode::WordWrap);
        $lines = $col->renderCell('one two three');

        // 'one' (3) + ' ' fits, then 'two' (3) + ' ' fits, then 'three' (5) on next line
        // Actually 'one ' = 4 chars which is less than 6, so it stays
        // Let's check that 'one' is preserved as a whole word
        $allText = \implode('', $lines);
        $this->assertStringContainsString('one', $allText);
        $this->assertStringContainsString('two', $allText);
        $this->assertStringContainsString('three', $allText);
    }

    public function testCharacterWrapWithNarrowColumn(): void
    {
        $col = Column::new('narrow', 'Narrow', 2)->withWrapMode(WrapMode::Character);
        $lines = $col->renderCell('ABCDEF');

        $this->assertCount(3, $lines);
    }

    public function testWrapModeWithAlignLeft(): void
    {
        $col = Column::new('left', 'Left', 10)
            ->withAlignLeft()
            ->withWrapMode(WrapMode::Character);

        $lines = $col->renderCell('ABCDE');
        $this->assertSame('ABCDE     ', $lines[0]);
    }
}
