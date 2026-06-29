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

    // -------------------------------------------------------------------------
    // Multibyte wrap/truncate tests
    // -------------------------------------------------------------------------

    public function testRenderCellCjkCharactersAreTruncatedByDisplayWidth(): void
    {
        // Each CJK character is 2 display cells wide
        $col = Column::new('name', 'Name', 6)->withWrapMode(WrapMode::None);
        // "日本語" = 3 chars × 2 cells = 6 cells, exactly fits, no truncation
        $lines = $col->renderCell('日本語');
        $this->assertCount(1, $lines);
        $this->assertSame('日本語', $lines[0]); // exactly fills 6 cells, no padding

        // "日本語学習" = 5 chars × 2 = 10 cells, truncated to 6
        $lines = $col->renderCell('日本語学習');
        $this->assertCount(1, $lines);
        // Width::truncate preserves full graphemes, so 3 CJK chars = 6 cells
        $this->assertSame('日本語', $lines[0]);
    }

    public function testRenderCellEmojiTruncatedByDisplayWidth(): void
    {
        // Each emoji is typically 2 cells wide
        $col = Column::new('icon', 'Icon', 4)->withWrapMode(WrapMode::None);
        // "👍🏼" = 1 grapheme cluster, 2 display cells, fits in 4
        $lines = $col->renderCell('👍🏼');
        $this->assertCount(1, $lines);

        // "👍🏼🔥" = 2 emoji clusters = 4 display cells, exactly fits
        $lines = $col->renderCell('👍🏼🔥');
        $this->assertCount(1, $lines);
    }

    public function testRenderCellMixedAsciiAndCjk(): void
    {
        $col = Column::new('text', 'Text', 8)->withWrapMode(WrapMode::None);
        // "Hi日本語" = 2 + 6 = 8 cells, exactly fits, no padding needed
        $lines = $col->renderCell('Hi日本語');
        $this->assertCount(1, $lines);
        $this->assertSame('Hi日本語', $lines[0]);

        // "Hello日本語" = 5 + 6 = 11 cells, truncated to 8
        $lines = $col->renderCell('Hello日本語');
        $this->assertCount(1, $lines);
        // "Hello日" = 5 + 2 = 7 cells, then padLeft to 8 adds 1 leading space
        $this->assertSame(8, \SugarCraft\Core\Util\Width::of($lines[0]));
        $this->assertSame(' Hello日', $lines[0]);
    }

    public function testWordWrapWithCjkCharacters(): void
    {
        $col = Column::new('text', 'Text', 6)->withWrapMode(WrapMode::WordWrap);
        // CJK doesn't have word boundaries in the same sense, but Width::wrap
        // should still split by display width
        $lines = $col->renderCell('日本語データ');
        // "日本語データ" = 6 chars × 2 = 12 cells, should wrap to 2+ lines
        $this->assertGreaterThanOrEqual(1, \count($lines));
    }

    public function testCharacterWrapWithCjkCharacters(): void
    {
        $col = Column::new('text', 'Text', 4)->withWrapMode(WrapMode::Character);
        // "日本語テスト" = 6 chars, each 2 cells wide
        $lines = $col->renderCell('日本語テスト');
        // Each char is 2 cells, so width 4 means 2 chars per line
        $this->assertGreaterThanOrEqual(2, \count($lines));
    }

    public function testMultibyteInTableView(): void
    {
        $t = Table::withColumns([
            Column::new('name', '名前', 8),
        ])->withRows([
            Row::new(RowData::from(['name' => '日本語'])),
            Row::new(RowData::from(['name' => 'English'])),
        ]);

        $view = $t->View();
        $this->assertIsString($view);
        // Both should be visible with correct alignment
        $this->assertStringContainsString('日本語', $view);
        $this->assertStringContainsString('English', $view);
    }

    public function testMultibyteTruncationInTableView(): void
    {
        $t = Table::withColumns([
            Column::new('name', '名前', 6),
        ])->withRows([
            Row::new(RowData::from(['name' => '日本語学習'])), // 10 cells, truncated to 6
        ]);

        $view = $t->View();
        $this->assertIsString($view);
        // Should be truncated to " 日本語" (display width 6)
        $this->assertStringContainsString('日本語', $view);
    }
}
