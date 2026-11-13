<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Style\Layout;
use SugarCraft\Style\Position;

/**
 * Finding 031 pins: the join methods now measure every line exactly once
 * and feed the memoised table to the pad/merge loops. The outputs are
 * byte-identical to the re-walking implementation — these literal-byte
 * edges (blank lines, zero-width columns, wide CJK/emoji, SGR-carrying
 * lines, tiled rules) lock that contract against a future regression
 * where a pad loop recomputes from the wrong table slot.
 */
final class LayoutWidthMemoTest extends TestCase
{
    public function testHorizontalPadsFromMemoisedWidthsAcrossEdges(): void
    {
        $left = "a\n\n中";                       // widths 1, 0, 2 → column 2
        $right = "\x1b[31mred\x1b[0m";          // width 3, one line
        $this->assertSame(
            "a \x1b[31mred\x1b[0m\n     \n中   ",
            Layout::joinHorizontal(Position::TOP, $left, $right),
        );
    }

    public function testHorizontalEmptyBlockKeepsZeroWidthColumn(): void
    {
        $this->assertSame(
            "x\ny",
            Layout::joinHorizontal(Position::BOTTOM, '', "x\ny"),
        );
    }

    public function testVerticalCenterUsesMemoisedLineWidths(): void
    {
        // Width 2 overall: 'ab' and '中' (2 cells) sit untouched; the
        // SGR-bold single cell is offset one space at CENTER.
        $this->assertSame(
            "ab\n中\n \x1b[1mB",
            Layout::joinVertical(Position::CENTER, "ab", "中", "\x1b[1mB"),
        );
    }

    public function testVerticalSpacingRowsSpanMemoisedWidth(): void
    {
        $this->assertSame(
            "日\n  \nab",
            Layout::joinVerticalWithSpacing(Position::LEFT, 1, '日', 'ab'),
        );
    }

    public function testVerticalDividerTilesRuleToSharedWidthAndPadsNarrowerBlock(): void
    {
        $this->assertSame(
            "abcd\n────\n中x ",
            Layout::joinVerticalWithDivider(Position::LEFT, '─', 'abcd', '中x'),
        );
    }

    public function testVerticalMixedWidthLinesWithinOneBlockUsePerLineSlots(): void
    {
        // One block, ragged lines: the narrow line must centre from ITS
        // own width, not the block's first/last measured slot. Width 4:
        // 'a' gains left 2 / right 1; 'abcd' is untouched at full width.
        $this->assertSame(
            "  a \nabcd",
            Layout::joinVertical(Position::CENTER, "a\nabcd"),
        );
        $this->assertSame(
            "  a \nabcd\n    \n    \n    ",
            Layout::joinVerticalWithSpacing(Position::CENTER, 2, "a\nabcd", ''),
        );
    }

    public function testEmptyLinesInsideBlocksArePaddedToColumnWidth(): void
    {
        // Interior blank lines must gain full-width blanks exactly as the
        // pre-memoisation padRight did. The '' block contributes a
        // zero-width column, so rows stay cut to 'abc'-width.
        $this->assertSame(
            "abc\n   \n a ",
            Layout::joinHorizontal(Position::TOP, "abc\n\n a", ''),
        );
    }
}
