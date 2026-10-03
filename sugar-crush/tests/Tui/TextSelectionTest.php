<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\Tui\TextSelection;
use SugarCraft\Sprinkles\Style;

/**
 * Cell geometry of a mouse text selection: anchoring, clamping, reading-order
 * spans, extraction and the reverse-video repaint.
 *
 * Coordinates are 1-based terminal cells (frame line R is `$lines[R - 1]`),
 * the space candy-mouse's Selection/SelectionRange speak since the rewire
 * onto them dropped the old `[$col - 1, $row - 1]` rebase.
 */
final class TextSelectionTest extends TestCase
{
    /** rowFrom, rowTo, colFrom, colTo — a 3-cell gutter each side of a 10-wide text column. */
    private const REGION = [2, 5, 4, 13];

    public function testAPressOutsideTheRegionAnchorsNothing(): void
    {
        self::assertNull(TextSelection::at(3, 3, self::REGION), 'left gutter');
        self::assertNull(TextSelection::at(14, 3, self::REGION), 'right gutter');
        self::assertNull(TextSelection::at(6, 1, self::REGION), 'above');
        self::assertNull(TextSelection::at(6, 6, self::REGION), 'below');
        self::assertNull(TextSelection::at(6, 3, [5, 2, 4, 13]), 'an empty region');
    }

    public function testAPressInsideAnchorsAnUndraggedUnsettledSelection(): void
    {
        $sel = TextSelection::at(6, 3, self::REGION);

        self::assertNotNull($sel);
        self::assertSame([6, 3, 6, 3], [$sel->anchorCol, $sel->anchorRow, $sel->headCol, $sel->headRow]);
        self::assertFalse($sel->dragging);
        self::assertFalse($sel->settled);
    }

    public function testTheHeadIsClampedIntoTheRegion(): void
    {
        $sel = TextSelection::at(6, 3, self::REGION);
        self::assertNotNull($sel);

        $right = $sel->withHead(41, 4);
        self::assertSame([13, 4], [$right->headCol, $right->headRow], 'past the right edge pins to the last column');

        $above = $sel->withHead(9, -2);
        self::assertSame([4, 2], [$above->headCol, $above->headRow], 'above the region pins to its first cell');

        $below = $sel->withHead(5, 31);
        self::assertSame([13, 5], [$below->headCol, $below->headRow], 'below the region pins to its last cell');
    }

    public function testSpansRunInReadingOrderWhicheverWayTheDragWent(): void
    {
        $forward = TextSelection::at(7, 2, self::REGION)?->withHead(5, 4);
        $backward = TextSelection::at(5, 4, self::REGION)?->withHead(7, 2);
        self::assertNotNull($forward);
        self::assertNotNull($backward);

        foreach ([$forward, $backward] as $sel) {
            self::assertNull($sel->span(1));
            self::assertSame([7, 13], $sel->span(2), 'first row: anchor to the right edge');
            self::assertSame([4, 13], $sel->span(3), 'middle row: whole text column');
            self::assertSame([4, 5], $sel->span(4), 'last row: left edge to the head, inclusive');
            self::assertNull($sel->span(5));
        }

        $sameRow = TextSelection::at(10, 3, self::REGION)?->withHead(6, 3);
        self::assertSame([6, 10], $sameRow?->span(3), 'a leftward drag on one row still spans left to right');
    }

    public function testExtractCopiesTextNotChromeAndKeepsIndentation(): void
    {
        $lines = [
            '╭──────────────╮',
            '│  ' . "\e[1malpha beta\e[0m" . '  │',
            '│      f();    │',
            '│  omega       │',
            '│              │',
            '╰──────────────╯',
        ];
        $sel = TextSelection::at(4, 2, [2, 5, 4, 13])?->withHead(13, 5);
        self::assertNotNull($sel);

        self::assertSame(
            "alpha beta\n    f();\nomega",
            $sel->extract($lines),
            'no border glyphs, no SGR, trailing padding and the trailing blank row trimmed, leading indent kept',
        );
    }

    public function testExtractStartsMidRowAtTheAnchor(): void
    {
        $lines = ['', '│  alpha beta  │'];
        $sel = TextSelection::at(10, 2, [2, 2, 4, 13])?->withHead(13, 2);

        self::assertSame('beta', $sel?->extract($lines));
    }

    public function testHighlightReversesOnlyTheCoveredCellsAndKeepsTheRest(): void
    {
        $lines = ['│  alpha beta  │'];
        $sel = TextSelection::at(4, 1, [1, 1, 4, 13])?->withHead(8, 1);
        self::assertNotNull($sel);

        $style = Style::new()->reverse();
        [$painted] = $sel->highlight($lines, $style);

        self::assertStringContainsString($style->render('alpha'), $painted);
        self::assertSame('│  alpha beta  │', Ansi::strip($painted), 'the repaint never moves or drops a cell');
    }

    public function testHighlightPadsAShortRowSoTheBandIsContinuous(): void
    {
        $lines = ['ab', 'x'];
        $sel = TextSelection::at(1, 1, [1, 2, 1, 4])?->withHead(4, 2);
        self::assertNotNull($sel);

        $style = Style::new()->reverse();
        $painted = $sel->highlight($lines, $style);

        self::assertStringContainsString($style->render('ab  '), $painted[0]);
        self::assertStringContainsString($style->render('x   '), $painted[1]);
    }

    public function testWithersReturnNewInstances(): void
    {
        $sel = TextSelection::at(6, 3, self::REGION);
        self::assertNotNull($sel);

        $dragging = $sel->withDragging();
        $settled = $dragging->withSettled(42);

        self::assertNotSame($sel, $dragging);
        self::assertFalse($sel->dragging);
        self::assertTrue($dragging->dragging);
        self::assertTrue($settled->settled);
        self::assertSame(42, $settled->copiedChars);
        self::assertSame(0, $dragging->withSettled(-5)->copiedChars, 'a negative count is clamped');
    }
}
