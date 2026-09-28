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
 */
final class TextSelectionTest extends TestCase
{
    /** rowFrom, rowTo, colFrom, colTo — a 3-cell gutter each side of a 10-wide text column. */
    private const REGION = [1, 4, 3, 12];

    public function testAPressOutsideTheRegionAnchorsNothing(): void
    {
        self::assertNull(TextSelection::at(2, 2, self::REGION), 'left gutter');
        self::assertNull(TextSelection::at(13, 2, self::REGION), 'right gutter');
        self::assertNull(TextSelection::at(5, 0, self::REGION), 'above');
        self::assertNull(TextSelection::at(5, 5, self::REGION), 'below');
        self::assertNull(TextSelection::at(5, 2, [4, 1, 3, 12]), 'an empty region');
    }

    public function testAPressInsideAnchorsAnUndraggedUnsettledSelection(): void
    {
        $sel = TextSelection::at(5, 2, self::REGION);

        self::assertNotNull($sel);
        self::assertSame([5, 2, 5, 2], [$sel->anchorCol, $sel->anchorRow, $sel->headCol, $sel->headRow]);
        self::assertFalse($sel->dragging);
        self::assertFalse($sel->settled);
    }

    public function testTheHeadIsClampedIntoTheRegion(): void
    {
        $sel = TextSelection::at(5, 2, self::REGION);
        self::assertNotNull($sel);

        $right = $sel->withHead(40, 3);
        self::assertSame([12, 3], [$right->headCol, $right->headRow], 'past the right edge pins to the last column');

        $above = $sel->withHead(8, -3);
        self::assertSame([3, 1], [$above->headCol, $above->headRow], 'above the region pins to its first cell');

        $below = $sel->withHead(4, 30);
        self::assertSame([12, 4], [$below->headCol, $below->headRow], 'below the region pins to its last cell');
    }

    public function testSpansRunInReadingOrderWhicheverWayTheDragWent(): void
    {
        $forward = TextSelection::at(6, 1, self::REGION)?->withHead(4, 3);
        $backward = TextSelection::at(4, 3, self::REGION)?->withHead(6, 1);
        self::assertNotNull($forward);
        self::assertNotNull($backward);

        foreach ([$forward, $backward] as $sel) {
            self::assertNull($sel->span(0));
            self::assertSame([6, 12], $sel->span(1), 'first row: anchor to the right edge');
            self::assertSame([3, 12], $sel->span(2), 'middle row: whole text column');
            self::assertSame([3, 4], $sel->span(3), 'last row: left edge to the head, inclusive');
            self::assertNull($sel->span(4));
        }

        $sameRow = TextSelection::at(9, 2, self::REGION)?->withHead(5, 2);
        self::assertSame([5, 9], $sameRow?->span(2), 'a leftward drag on one row still spans left to right');
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
        $sel = TextSelection::at(3, 1, [1, 4, 3, 12])?->withHead(12, 4);
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
        $sel = TextSelection::at(9, 1, [1, 1, 3, 12])?->withHead(12, 1);

        self::assertSame('beta', $sel?->extract($lines));
    }

    public function testHighlightReversesOnlyTheCoveredCellsAndKeepsTheRest(): void
    {
        $lines = ['│  alpha beta  │'];
        $sel = TextSelection::at(3, 0, [0, 0, 3, 12])?->withHead(7, 0);
        self::assertNotNull($sel);

        $style = Style::new()->reverse();
        [$painted] = $sel->highlight($lines, $style);

        self::assertStringContainsString($style->render('alpha'), $painted);
        self::assertSame('│  alpha beta  │', Ansi::strip($painted), 'the repaint never moves or drops a cell');
    }

    public function testHighlightPadsAShortRowSoTheBandIsContinuous(): void
    {
        $lines = ['ab', 'x'];
        $sel = TextSelection::at(0, 0, [0, 1, 0, 3])?->withHead(3, 1);
        self::assertNotNull($sel);

        $style = Style::new()->reverse();
        $painted = $sel->highlight($lines, $style);

        self::assertStringContainsString($style->render('ab  '), $painted[0]);
        self::assertStringContainsString($style->render('x   '), $painted[1]);
    }

    public function testWithersReturnNewInstances(): void
    {
        $sel = TextSelection::at(5, 2, self::REGION);
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
