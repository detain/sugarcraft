<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\Column;
use SugarCraft\Table\WrapMode;
use PHPUnit\Framework\TestCase;

final class ColumnTest extends TestCase
{
    public function testNew(): void
    {
        $col = Column::new('id', 'ID', 10);
        $this->assertSame('id', $col->key);
        $this->assertSame('ID', $col->title);
        $this->assertSame(10, $col->width);
        $this->assertSame(0, $col->flexibleWidth);
        $this->assertSame(0, $col->maxWidth);
        $this->assertFalse($col->filterable);
        $this->assertFalse($col->alignLeft);
        $this->assertSame('', $col->style);
    }

    public function testWithFlexibleWidth(): void
    {
        $col = Column::new('name', 'Name', 20);
        $col2 = $col->withFlexibleWidth(1);

        $this->assertSame(0, $col->flexibleWidth);
        $this->assertSame(1, $col2->flexibleWidth);
        $this->assertSame('name', $col2->key);
        $this->assertSame('Name', $col2->title);
        $this->assertSame(20, $col2->width);
    }

    public function testWithMaxWidth(): void
    {
        $col = Column::new('desc', 'Description', 50);
        $col2 = $col->withMaxWidth(30);

        $this->assertSame(0, $col->maxWidth);
        $this->assertSame(30, $col2->maxWidth);
        $this->assertSame('desc', $col2->key);
        $this->assertSame('Description', $col2->title);
    }

    public function testWithFilterable(): void
    {
        $col = Column::new('email', 'Email', 30);
        $col2 = $col->withFilterable();
        $col3 = $col2->withFilterable(false);

        $this->assertFalse($col->filterable);
        $this->assertTrue($col2->filterable);
        $this->assertFalse($col3->filterable);
    }

    public function testWithAlignLeft(): void
    {
        $col = Column::new('name', 'Name', 20);
        $col2 = $col->withAlignLeft();
        $col3 = $col2->withAlignLeft(false);

        $this->assertFalse($col->alignLeft);
        $this->assertTrue($col2->alignLeft);
        $this->assertFalse($col3->alignLeft);
    }

    public function testWithStyle(): void
    {
        $col = Column::new('status', 'Status', 15);
        $col2 = $col->withStyle('1;31');

        $this->assertSame('', $col->style);
        $this->assertSame('1;31', $col2->style);
        $this->assertSame('status', $col2->key);
    }

    public function testRenderHeaderDefaultWidth(): void
    {
        $col = Column::new('id', 'ID', 5);
        $header = $col->renderHeader();

        // Default alignment is right-align
        $this->assertSame('   ID', $header);
    }

    public function testRenderHeaderWithTotalWidth(): void
    {
        $col = Column::new('name', 'Name', 10);
        $header = $col->renderHeader(15);

        // Default alignment is right-align, title padded to 15 chars
        $this->assertSame('           Name', $header);
    }

    public function testRenderHeaderTruncatesTitle(): void
    {
        $col = Column::new('desc', 'Description', 5);
        $header = $col->renderHeader();

        $this->assertSame('Descr', $header);
    }

    public function testRenderHeaderAlignLeft(): void
    {
        $col = Column::new('name', 'Name', 10)->withAlignLeft();
        $header = $col->renderHeader();

        $this->assertSame('Name      ', $header);
    }

    public function testRenderCellScalarValue(): void
    {
        $col = Column::new('count', 'Count', 8);
        $cell = $col->renderCell(42);

        $this->assertSame('      42', $cell[0]);
    }

    public function testRenderCellStringValue(): void
    {
        $col = Column::new('name', 'Name', 10);
        $cell = $col->renderCell('Alice');

        $this->assertSame('     Alice', $cell[0]);
    }

    public function testRenderCellWithCustomWidth(): void
    {
        $col = Column::new('val', 'Val', 5);
        $cell = $col->renderCell(123, 10);

        $this->assertSame('       123', $cell[0]);
    }

    public function testRenderCellTruncatesLongValue(): void
    {
        $col = Column::new('name', 'Name', 5);
        $cell = $col->renderCell('Christopher');

        // Default alignment is right-align, truncates from end
        $this->assertSame('Chris', $cell[0]);
    }

    public function testRenderCellWithObjectHavingToString(): void
    {
        $col = Column::new('obj', 'Obj', 10);
        $obj = new class {
            public function __toString(): string
            {
                return 'TestObject';
            }
        };
        $cell = $col->renderCell($obj);

        // 'TestObject' is exactly 10 chars, no padding needed
        $this->assertSame('TestObject', $cell[0]);
    }

    public function testRenderCellWithStyle(): void
    {
        $col = Column::new('status', 'Status', 10)->withStyle('1;32');
        $cell = $col->renderCell('Active');

        $this->assertStringStartsWith("\x1b[1;32m", $cell[0]);
        $this->assertStringEndsWith("\x1b[0m", $cell[0]);
        $this->assertStringContainsString('Active', $cell[0]);
    }

    public function testRenderCellEmptyForNonScalarWithoutToString(): void
    {
        $col = Column::new('arr', 'Arr', 10);
        $cell = $col->renderCell(['nested', 'array']);

        $this->assertSame('          ', $cell[0]);
    }

    public function testImmutabilityWithMethods(): void
    {
        $col = Column::new('id', 'ID', 5);
        $col2 = $col->withFlexibleWidth(1);
        $col3 = $col->withMaxWidth(20);
        $col4 = $col->withFilterable(true);
        $col5 = $col->withAlignLeft(true);
        $col6 = $col->withStyle('1');

        $this->assertNotSame($col, $col2);
        $this->assertNotSame($col, $col3);
        $this->assertNotSame($col, $col4);
        $this->assertNotSame($col, $col5);
        $this->assertNotSame($col, $col6);

        $this->assertSame(0, $col->flexibleWidth);
        $this->assertSame(0, $col->maxWidth);
        $this->assertFalse($col->filterable);
        $this->assertFalse($col->alignLeft);
        $this->assertSame('', $col->style);
    }

    // -------------------------------------------------------------------------
    // Multibyte / wide-character tests (Step 8)
    // -------------------------------------------------------------------------

    public function testRenderHeaderCjkCharactersTruncatedByDisplayWidth(): void
    {
        // "名前" = 2 chars × 2 cells = 4 cells, width is 6, so 2-space pad on left
        $col = Column::new('name', '名前', 6);
        $header = $col->renderHeader();
        $this->assertSame('  名前', $header); // right-aligned, 2-space pad + 4-cell CJK = 6

        // "日本語学習" = 5 chars × 2 = 10 cells, truncated to 6 (3 chars)
        $col2 = Column::new('long', '日本語学習', 6);
        $header2 = $col2->renderHeader();
        // Width::truncate preserves grapheme clusters, so we get 3 CJK chars = 6 cells
        $this->assertSame(6, \SugarCraft\Core\Util\Width::of($header2));
        $this->assertTrue(\mb_check_encoding($header2, 'UTF-8'));
    }

    public function testRenderHeaderEmojiTruncatedByDisplayWidth(): void
    {
        // "👍🏼" = 1 emoji cluster = 2 cells, fits in width 4
        $col = Column::new('icon', '👍🏼', 4);
        $header = $col->renderHeader();
        $this->assertSame(4, \SugarCraft\Core\Util\Width::of($header));

        // "👍🏼🔥" = 2 emoji clusters = 4 cells, exactly fits in width 4
        $col2 = Column::new('icons', '👍🏼🔥', 4);
        $header2 = $col2->renderHeader();
        $this->assertTrue(\mb_check_encoding($header2, 'UTF-8'));
    }

    public function testRenderCellCjkNoneWrapTruncatesByDisplayWidth(): void
    {
        // Each CJK char occupies 2 display cells
        $col = Column::new('name', 'Name', 6)->withWrapMode(WrapMode::None);
        // "日本語" = 3 × 2 = 6 cells, exactly fits
        $lines = $col->renderCell('日本語');
        $this->assertCount(1, $lines);
        $this->assertSame('日本語', $lines[0]);

        // "日本語データ" = 6 chars × 2 = 12 cells, truncated to 6 (3 chars)
        $lines = $col->renderCell('日本語データ');
        $this->assertCount(1, $lines);
        $this->assertSame(6, \SugarCraft\Core\Util\Width::of($lines[0]));
        $this->assertTrue(\mb_check_encoding($lines[0], 'UTF-8'));
    }

    public function testRenderCellCjkWordWrapRespectsDisplayWidth(): void
    {
        $col = Column::new('text', 'Text', 6)->withWrapMode(WrapMode::WordWrap);
        // "日本語データ" = 6 chars × 2 = 12 cells total, must wrap
        $lines = $col->renderCell('日本語データ');
        $this->assertGreaterThanOrEqual(2, \count($lines));
        // Every line should have valid UTF-8
        foreach ($lines as $line) {
            $this->assertTrue(\mb_check_encoding($line, 'UTF-8'));
            $this->assertLessThanOrEqual(6, \SugarCraft\Core\Util\Width::of($line));
        }
    }

    public function testRenderCellCjkCharacterWrapRespectsDisplayWidth(): void
    {
        $col = Column::new('text', 'Text', 4)->withWrapMode(WrapMode::Character);
        // "日本語テスト" = 6 chars, each 2 cells wide, width 4 means 2 chars per line
        $lines = $col->renderCell('日本語テスト');
        $this->assertGreaterThanOrEqual(3, \count($lines));
        foreach ($lines as $line) {
            $this->assertTrue(\mb_check_encoding($line, 'UTF-8'));
            $this->assertLessThanOrEqual(4, \SugarCraft\Core\Util\Width::of($line));
        }
    }

    public function testRenderCellEmojiDisplayWidthCountsAsTwo(): void
    {
        // Verify that a single wide emoji like 🔥 (2 cells) fits correctly in a
        // 4-cell column without being split or corrupted.
        $col = Column::new('icon', 'Icon', 4)->withWrapMode(WrapMode::None);
        $lines = $col->renderCell('🔥');
        $this->assertCount(1, $lines);
        // The emoji should appear intact (not split mid-codepoint)
        $this->assertTrue(\mb_check_encoding($lines[0], 'UTF-8'));
        // Display width should be 4 (padded to column width)
        $this->assertSame(4, \SugarCraft\Core\Util\Width::of($lines[0]));
    }

    public function testRenderCellMixedAsciiAndCjkDisplayWidth(): void
    {
        $col = Column::new('text', 'Text', 8)->withWrapMode(WrapMode::None);
        // "Hi日本語" = 2 + 6 = 8 cells, exactly fits
        $lines = $col->renderCell('Hi日本語');
        $this->assertCount(1, $lines);
        $this->assertSame(8, \SugarCraft\Core\Util\Width::of($lines[0]));

        // "Hello日本語" = 5 + 6 = 11 cells, truncated to 8
        $lines = $col->renderCell('Hello日本語');
        $this->assertCount(1, $lines);
        $this->assertSame(8, \SugarCraft\Core\Util\Width::of($lines[0]));
    }

    public function testRenderCellAccentedLatinDisplayWidth(): void
    {
        $col = Column::new('name', 'Name', 10)->withWrapMode(WrapMode::None);
        // Accented chars like é, ñ, ü are typically 1 cell wide
        $lines = $col->renderCell('Andréáñez');
        $this->assertCount(1, $lines);
        $this->assertTrue(\mb_check_encoding($lines[0], 'UTF-8'));
        // Should fit without truncation (9 chars, 9 cells)
        $this->assertLessThanOrEqual(10, \SugarCraft\Core\Util\Width::of($lines[0]));
    }
}