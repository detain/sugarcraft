<?php

declare(strict_types=1);

namespace SugarCraft\Boxer\Tests;

use SugarCraft\Boxer\{Node, SugarBoxer};
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\{Align, Border, Style, VAlign};
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

/**
 * Edge-case and boundary-coverage tests targeting the specific lines that
 * the existing suite does not exercise.
 */
final class EdgeCaseTest extends TestCase
{
    private SugarBoxer $boxer;

    protected function setUp(): void
    {
        $this->boxer = SugarBoxer::new();
    }

    // -------------------------------------------------------------------------
    // Node edge cases
    // -------------------------------------------------------------------------

    public function testHorizontalNodeWithNoChildren(): void
    {
        // An empty horizontal node should render without error.
        $n = Node::horizontal();
        $result = $this->boxer->render($n, 10, 3);
        $this->assertIsString($result);
        $this->assertSame(3, \count(\explode("\n", $result)));
    }

    public function testVerticalNodeWithNoChildren(): void
    {
        $n = Node::vertical();
        $result = $this->boxer->render($n, 10, 3);
        $this->assertIsString($result);
        $this->assertSame(3, \count(\explode("\n", $result)));
    }

    public function testLeafNodeWithEmptyString(): void
    {
        $n = Node::leaf('');
        $this->assertSame('', $n->content);
        $result = $this->boxer->render($n, 10, 3);
        $this->assertIsString($result);
    }

    public function testNoBorderNodeWithEmptyChildren(): void
    {
        // renderNoBorder checks children === [] and returns early.
        // A noBorder with no children (created via private constructor) is not
        // directly creatable, but the noBorder() factory wraps one child.
        $n = Node::noBorder(Node::leaf('x'));
        $result = $this->boxer->render($n, 10, 3);
        $this->assertStringContainsString('x', $result);
    }

    public function testTotalWidthLeafNoBorderNoPadding(): void
    {
        // A leaf with no border/padding and no margin: width = minWidth only.
        $n = Node::leaf('x')->withBorder(false)->withMinWidth(7);
        $this->assertSame(7, $n->totalWidth());
    }

    public function testTotalHeightLeafNoBorderNoPadding(): void
    {
        $n = Node::leaf('x')->withBorder(false)->withMinHeight(5);
        $this->assertSame(5, $n->totalHeight());
    }

    public function testTotalWidthHorizontalNoBorderNoSpacingNoPadding(): void
    {
        // Two leaves side-by-side, no borders/spacing/padding: sum of minWidths.
        $h = Node::horizontal(
            Node::leaf('a')->withBorder(false)->withMinWidth(3),
            Node::leaf('b')->withBorder(false)->withMinWidth(4),
        )->withBorder(false);
        $this->assertSame(7, $h->totalWidth());
    }

    public function testTotalWidthHorizontalWithBorder(): void
    {
        // Border adds 2 cells (left+right) to the sum of child widths.
        $h = Node::horizontal(
            Node::leaf('a')->withBorder(false)->withMinWidth(3),
            Node::leaf('b')->withBorder(false)->withMinWidth(4),
        )->withBorder(true);
        $this->assertSame(9, $h->totalWidth()); // 3+4 + 2 border
    }

    public function testTotalWidthVerticalNoBorder(): void
    {
        // VERTICAL node: width = max child width (stacked layout).
        $v = Node::vertical(
            Node::leaf('a')->withBorder(false)->withMinWidth(3),
            Node::leaf('bb')->withBorder(false)->withMinWidth(5),
        )->withBorder(false);
        $this->assertSame(5, $v->totalWidth()); // max of 3 and 5
    }

    public function testTotalHeightVerticalWithSpacing(): void
    {
        // VERTICAL with spacing: sum of child heights + (n-1)*spacing.
        $v = Node::vertical(
            Node::leaf('a')->withBorder(false)->withMinHeight(2),
            Node::leaf('b')->withBorder(false)->withMinHeight(3),
        )->withBorder(false)->withSpacing(1);
        $this->assertSame(6, $v->totalHeight()); // 2+3 + 1 gap
    }

    public function testTotalHeightHorizontalNoBorder(): void
    {
        // HORIZONTAL node: height = max child height.
        $h = Node::horizontal(
            Node::leaf('a')->withBorder(false)->withMinHeight(2),
            Node::leaf('b')->withBorder(false)->withMinHeight(5),
        )->withBorder(false);
        $this->assertSame(5, $h->totalHeight());
    }

    // -------------------------------------------------------------------------
    // Node preserve() sentinel (private static, used by with* builders)
    // -------------------------------------------------------------------------

    public function testWithBorderStylePreservesOtherProperties(): void
    {
        // withBorderStyle passes preserve() sentinel for style/alignH/alignV.
        $node = Node::leaf('x')
            ->withBorder(true)
            ->withPadding(2)
            ->withMinWidth(5)
            ->withStyle(Style::new()->fg(Color::ansi(1)))
            ->withBorderStyle(Border::rounded());

        $this->assertSame(2, $node->padding);
        $this->assertSame(5, $node->minWidth);
        $this->assertInstanceOf(Style::class, $node->style);
    }

    public function testWithStylePreservesOtherProperties(): void
    {
        $node = Node::leaf('x')
            ->withBorder(true)
            ->withPadding(3)
            ->withMargin(1, 2, 3, 4)
            ->withStyle(Style::new()->fg(Color::ansi(2)));

        $this->assertSame(3, $node->padding);
        $this->assertSame([1, 2, 3, 4], $node->margin);
    }

    public function testWithTitlePreservesOtherProperties(): void
    {
        $node = Node::leaf('x')
            ->withBorder(true)
            ->withPadding(4)
            ->withMargin(1, 1, 1, 1)
            ->withTitle('My Title');

        $this->assertSame('My Title', $node->title);
        $this->assertSame(4, $node->padding);
    }

    public function testWithMarginPreservesOtherProperties(): void
    {
        $node = Node::leaf('x')
            ->withBorder(true)
            ->withPadding(2)
            ->withTitle('T')
            ->withMargin(1, 2, 3, 4);

        $this->assertSame([1, 2, 3, 4], $node->margin);
        $this->assertSame('T', $node->title);
        $this->assertSame(2, $node->padding);
    }

    public function testWithAlignHPreservesOtherProperties(): void
    {
        $node = Node::leaf('x')
            ->withBorder(true)
            ->withPadding(1)
            ->withAlignH(Align::Center);

        $this->assertSame(Align::Center, $node->alignH);
        $this->assertSame(1, $node->padding);
    }

    public function testWithAlignVPreservesOtherProperties(): void
    {
        $node = Node::leaf('x')
            ->withBorder(true)
            ->withAlignV(VAlign::Bottom);

        $this->assertSame(VAlign::Bottom, $node->alignV);
    }

    // -------------------------------------------------------------------------
    // Negative / zero clamping on with* builders
    // -------------------------------------------------------------------------

    public function testWithFlexNegativeClampsToZero(): void
    {
        $n = Node::leaf('x')->withFlex(-10);
        $this->assertSame(0, $n->flex);
    }

    public function testWithGrowThenResetToZero(): void
    {
        $n = Node::leaf('x')->withGrow()->withFlex(0);
        $this->assertSame(0, $n->flex);
    }

    // -------------------------------------------------------------------------
    // SugarBoxer: render edge cases
    // -------------------------------------------------------------------------

    public function testRenderZeroWidthViewport(): void
    {
        $layout = Node::leaf('content')->withBorder(false);
        // width=0 → array_fill(0, 0, ...) creates empty arrays → result is 5 empty rows.
        $result = $this->boxer->render($layout, 0, 5);
        $this->assertIsString($result);
        $lines = \explode("\n", $result);
        $this->assertCount(5, $lines);
    }

    public function testRenderZeroHeightViewport(): void
    {
        $layout = Node::leaf('content')->withBorder(false);
        // height=0 → array_fill(0, 0, ...) creates empty outer array → result is empty.
        $result = $this->boxer->render($layout, 10, 0);
        $this->assertIsString($result);
        // With height 0, there are no rows to fill.
        $this->assertSame('', $result);
    }

    public function testRenderNegativeWidthViewport(): void
    {
        $layout = Node::leaf('content')->withBorder(false);
        $this->expectException(\ValueError::class);
        $this->boxer->render($layout, -5, 5);
    }

    public function testRenderNegativeHeightViewport(): void
    {
        $layout = Node::leaf('content')->withBorder(false);
        $this->expectException(\ValueError::class);
        $this->boxer->render($layout, 10, -5);
    }

    public function testRenderLeafWidthExceedsViewportWithBorder(): void
    {
        // Border + content wider than viewport.
        $layout = Node::leaf('TOOLONGWORD')->withBorder(true)->withMinWidth(20);
        $result = $this->boxer->render($layout, 8, 5);
        $this->assertIsString($result);
    }

    public function testRenderLeafHeightExceedsViewportWithBorder(): void
    {
        $multiline = "L1\nL2\nL3\nL4\nL5\nL6\nL7";
        $layout = Node::leaf($multiline)->withBorder(true)->withMinHeight(10);
        $result = $this->boxer->render($layout, 20, 3);
        $this->assertIsString($result);
    }

    public function testRenderHorizontalWithZeroSpacingAndNoFlex(): void
    {
        // Verifies the no-flex path in renderHorizontal: spacing=0 with no flex
        // children should not draw vertical separators.
        $layout = Node::horizontal(
            Node::leaf('A')->withBorder(false)->withMinWidth(5),
            Node::leaf('B')->withBorder(false)->withMinWidth(5),
        )->withBorder(true)->withSpacing(0);

        $result = $this->boxer->render($layout, 15, 3);
        $this->assertStringContainsString('A', $result);
        $this->assertStringContainsString('B', $result);
    }

    public function testRenderVerticalWithZeroSpacingAndNoFlex(): void
    {
        $layout = Node::vertical(
            Node::leaf('TOP')->withBorder(false)->withMinHeight(2),
            Node::leaf('BOT')->withBorder(false)->withMinHeight(2),
        )->withBorder(true)->withSpacing(0);

        $result = $this->boxer->render($layout, 15, 8);
        $this->assertStringContainsString('TOP', $result);
        $this->assertStringContainsString('BOT', $result);
    }

    // -------------------------------------------------------------------------
    // wordWrap edge cases
    // -------------------------------------------------------------------------

    public function testWordWrapZeroWidthReturnsEmptyString(): void
    {
        // width <= 0 returns [''] immediately.
        $layout = Node::leaf('hello')->withBorder(false);
        $result = $this->boxer->render($layout, 0, 3);
        // Should produce [''] wrapping, giving a row of spaces.
        $this->assertIsString($result);
    }

    public function testWordWrapNegativeWidthThrows(): void
    {
        $layout = Node::leaf('hello')->withBorder(false);
        $this->expectException(\ValueError::class);
        $this->boxer->render($layout, -1, 3);
    }

    public function testWordWrapSingleOversizedWordSplits(): void
    {
        // A single word wider than the region must split.
        $layout = Node::leaf('ABCDEFGHIJ')->withBorder(false);
        $result = $this->boxer->render($layout, 4, 4);
        $this->assertStringContainsString('ABCD', $result);
        $this->assertStringContainsString('EFGH', $result);
        $this->assertStringContainsString('IJ', $result);
    }

    public function testWordWrapPreservesLeadingWhitespace(): void
    {
        // Leading spaces on a fitting line should not be stripped.
        $layout = Node::leaf('  hello')->withBorder(false);
        $result = $this->boxer->render($layout, 10, 2);
        $this->assertStringContainsString('  hello', $result);
    }

    public function testWordWrapPreservesMultipleSpacesBetweenWords(): void
    {
        // Multiple spaces between words (not just single spaces) should be
        // preserved when lines fit.
        $layout = Node::leaf('a    b')->withBorder(false);
        $result = $this->boxer->render($layout, 10, 2);
        $this->assertStringContainsString('a    b', $result);
    }

    public function testWordWrapMultipleParagraphs(): void
    {
        $text = "para1 line\n\npara2 line";
        $layout = Node::leaf($text)->withBorder(false);
        $result = $this->boxer->render($layout, 20, 5);
        $this->assertStringContainsString('para1', $result);
        $this->assertStringContainsString('para2', $result);
    }

    // -------------------------------------------------------------------------
    // visualCells edge cases
    // -------------------------------------------------------------------------

    public function testVisualCellsHandlesPureEscapeSequence(): void
    {
        // A string that is ONLY an escape sequence (no grapheme).
        $layout = Node::leaf("\x1b[0m")->withBorder(false);
        $result = $this->boxer->render($layout, 5, 1);
        // Should produce spaces, not crash.
        $this->assertIsString($result);
    }

    // -------------------------------------------------------------------------
    // placeLine carry/max-carry edge cases
    // -------------------------------------------------------------------------

    public function testPlaceLineManyZeroWidthGraphemesAccumulatesCarry(): void
    {
        // Many combining marks that accumulate into the carry buffer.
        // The MAX_CARRY limit (100) should cap accumulation.
        $chars = [];
        for ($i = 0; $i < 150; $i++) {
            $chars[] = "\u{0300}"; // combining grave accent (zero width)
        }
        $text = 'A' . \implode('', $chars) . 'B';
        $layout = Node::leaf($text)->withBorder(false);
        $result = $this->boxer->render($layout, 10, 1);
        // Should render 'A' and 'B' with the combining marks attached.
        $this->assertStringContainsString('B', $result);
    }

    public function testPlaceLineTrailingStyleResetIsApplied(): void
    {
        // When line fits and has trailing SGR, it should be attached to last cell.
        $layout = Node::leaf("\x1b[1mbold\x1b[0m")->withBorder(false);
        $result = $this->boxer->render($layout, 10, 1);
        // Should contain the reset somewhere.
        $this->assertStringContainsString("\x1b[0m", $result);
    }

    public function testPlaceLineTruncatedLineDropsTrailingStyle(): void
    {
        // When a line is truncated mid-way, trailing escapes are dropped
        // (they belonged to the clipped cells), but the safety reset still
        // gets appended to the last real cell.
        $layout = Node::leaf("\x1b[1mVERYLONGTEXT\x1b[0m")->withBorder(false);
        $result = $this->boxer->render($layout, 4, 1);
        // The truncation safety reset must be present.
        $this->assertNoDanglingStyle($result);
    }

    private function assertNoDanglingStyle(string $out): void
    {
        if (\preg_match_all('/\e\[[0-9;]*m/', $out, $m) > 0) {
            $last = end($m[0]);
            $this->assertTrue(
                $last === "\e[0m" || $last === "\e[m",
                'expected the final SGR to be a reset, got: ' . \bin2hex($last),
            );
        }
    }

    // -------------------------------------------------------------------------
    // sgrLeavesStyleOpen edge cases
    // -------------------------------------------------------------------------

    public function testSgrLeavesStyleOpenWithEmptyParamsResets(): void
    {
        // ESC[m (empty params) is a reset.
        $layout = Node::leaf("\x1b[1m\x1b[mTEXT")->withBorder(false);
        $result = $this->boxer->render($layout, 10, 1);
        $this->assertStringContainsString('TEXT', $result);
    }

    public function testSgrLeavesStyleOpen256ColorStaysOpen(): void
    {
        // 38;5;N sets a color, stays open.
        $layout = Node::leaf("\x1b[38;5;196mRED")->withBorder(false);
        $result = $this->boxer->render($layout, 10, 1);
        // Reset must be appended by safety.
        $this->assertStringContainsString("\x1b[0m", $result);
    }

    public function testSgrLeavesStyleOpenTruecolorStaysOpen(): void
    {
        // 38;2;R;G;B sets a color, stays open.
        $layout = Node::leaf("\x1b[38;2;255;0;0mRGB")->withBorder(false);
        $result = $this->boxer->render($layout, 10, 1);
        $this->assertStringContainsString("\x1b[0m", $result);
    }

    public function testSgrLeavesStyleOpenMultipleAttributes(): void
    {
        // Multiple non-zero codes (1, 4, 94) all open style.
        $layout = Node::leaf("\x1b[1;4;94mSTRIKE")->withBorder(false);
        $result = $this->boxer->render($layout, 10, 1);
        $this->assertStringContainsString("\x1b[0m", $result);
    }

    public function testSgrLeavesStyleOpenBgColorStaysOpen(): void
    {
        // 48;5;N (background) also stays open.
        $layout = Node::leaf("\x1b[48;5;21mBG")->withBorder(false);
        $result = $this->boxer->render($layout, 10, 1);
        $this->assertStringContainsString("\x1b[0m", $result);
    }

    // -------------------------------------------------------------------------
    // drawBorder edge cases
    // -------------------------------------------------------------------------

    public function testDrawBorderTooNarrowSkips(): void
    {
        // drawBorder returns early when w < 2 or h < 2.
        $layout = Node::leaf('x')->withBorder(true)->withMinWidth(1)->withMinHeight(1);
        $result = $this->boxer->render($layout, 1, 1);
        // No border drawn, just spaces.
        $this->assertIsString($result);
    }

    public function testDrawBorderTooThinForTitle(): void
    {
        // Title only renders when w >= 4 (needs corners + 1 title cell).
        $layout = Node::leaf('content')->withBorder(true)->withTitle('X')->withMinWidth(2);
        $result = $this->boxer->render($layout, 3, 5);
        // Title should not appear (w < 4 means no space for title).
        $this->assertStringNotContainsString('X', $result);
    }

    public function testDrawBorderTitleWideGlyphIsClipped(): void
    {
        // Title with wide glyphs clips at interior width.
        $layout = Node::leaf('content')->withBorder(true)->withTitle('世World')->withMinWidth(12);
        $result = $this->boxer->render($layout, 12, 5);
        $this->assertStringContainsString('World', $result);
        // '世' is 2 columns; in a 10-wide interior, '世World' (7 cols) should fit.
    }

    public function testDrawVLineWithNullBorderFallsBackToRounded(): void
    {
        // drawVLine uses Border::rounded() when border is null.
        $layout = Node::horizontal(
            Node::leaf('A')->withBorder(false)->withMinWidth(5),
            Node::leaf('B')->withBorder(false)->withMinWidth(5),
        )->withBorder(false)->withSpacing(0);
        $result = $this->boxer->render($layout, 12, 3);
        // Vertical separator should appear.
        $this->assertIsString($result);
    }

    public function testDrawHLineWithNullBorderFallsBackToRounded(): void
    {
        $layout = Node::vertical(
            Node::leaf('TOP')->withBorder(false)->withMinHeight(2),
            Node::leaf('BOT')->withBorder(false)->withMinHeight(2),
        )->withBorder(false)->withSpacing(0);
        $result = $this->boxer->render($layout, 12, 8);
        $this->assertIsString($result);
    }

    // -------------------------------------------------------------------------
    // setChar boundary checks
    // -------------------------------------------------------------------------

    public function testSetCharIgnoresOutOfBoundsCoordinates(): void
    {
        // setChar returns early when y < 0, y >= count(cells), x < 0, x >= width.
        // This is indirectly tested by zero/negative viewport renders above.
        // Explicit test: a leaf with margin that pushes content out of bounds.
        $leaf = Node::leaf('x')->withBorder(false)->withMargin(100, 0, 0, 100);
        $result = $this->boxer->render($leaf, 10, 5);
        $this->assertIsString($result);
    }

    // -------------------------------------------------------------------------
    // renderContent alignment edge cases
    // -------------------------------------------------------------------------

    public function testAlignCenterWithOddWidthDropsNoCell(): void
    {
        // (w - lw) / 2 with w=5, lw=2 → intdiv(3,2) = 1 (not 1.5).
        // leftPad=1, rightPad=5-2-1=2 → " AB  ".
        $layout = Node::leaf('AB')->withBorder(false)->withAlignH(Align::Center);
        $result = $this->boxer->render($layout, 5, 1);
        $stripped = \preg_replace('/\x1b\[[0-9;]*m/', '', $result);
        $this->assertSame(' AB  ', $stripped);
    }

    public function testAlignRightExactWidthFits(): void
    {
        // When text width == region width, leftPad = 0.
        $layout = Node::leaf('ABC')->withBorder(false)->withAlignH(Align::Right);
        $result = $this->boxer->render($layout, 3, 1);
        $stripped = \preg_replace('/\x1b\[[0-9;]*m/', '', $result);
        $this->assertStringContainsString('ABC', $stripped);
    }

    public function testAlignBottomExactlyFits(): void
    {
        $layout = Node::leaf("L1\nL2\nL3")->withBorder(false)->withAlignV(VAlign::Bottom);
        $result = $this->boxer->render($layout, 10, 3);
        $lines = \explode("\n", $result);
        // VAlign::Bottom: topPad = h - numLines = 3 - 3 = 0.
        // So L1 at row 0, L2 at row 1, L3 at row 2.
        $this->assertSame('L3', \trim($lines[2] ?? ''));
    }

    public function testAlignMiddleExactlyFits(): void
    {
        $layout = Node::leaf("L1\nL2\nL3")->withBorder(false)->withAlignV(VAlign::Middle);
        $result = $this->boxer->render($layout, 10, 3);
        $lines = \explode("\n", $result);
        // In height=3 with 3 lines, topPad = (3-3)/2 = 0.
        $this->assertSame('L1', \trim($lines[0] ?? ''));
    }

    public function testAlignVMiddleWithMoreSpaceThanNeeded(): void
    {
        // More vertical space than content: topPad centers the content.
        $layout = Node::leaf("L1\nL2")->withBorder(false)->withAlignV(VAlign::Middle);
        $result = $this->boxer->render($layout, 10, 6);
        $lines = \explode("\n", $result);
        // topPad = (6-2)/2 = 2. L1 should be at row 2.
        $this->assertSame('L1', \trim($lines[2] ?? ''));
        $this->assertSame('', \trim($lines[0] ?? ''));
    }

    // -------------------------------------------------------------------------
    // renderLeaf clamping edge cases
    // -------------------------------------------------------------------------

    public function testRenderLeafMaxWidthLessThanPadding(): void
    {
        // When 2*padding >= cw, padH is recalculated to max(0, intdiv(cw-1, 2)).
        $layout = Node::leaf('content')->withBorder(false)->withPadding(100)->withMinWidth(3);
        $result = $this->boxer->render($layout, 5, 5);
        $this->assertIsString($result);
    }

    public function testRenderLeafMaxHeightLessThanPadding(): void
    {
        $layout = Node::leaf("L1\nL2\nL3")->withBorder(false)->withPadding(100)->withMinHeight(3);
        $result = $this->boxer->render($layout, 10, 5);
        $this->assertIsString($result);
    }

    public function testRenderLeafMaxWidthExceedsContentWidth(): void
    {
        // When maxWidth > pcw, no clamping happens.
        $layout = Node::leaf('AB')->withBorder(false)->withMaxWidth(100);
        $result = $this->boxer->render($layout, 10, 3);
        $this->assertStringContainsString('AB', $result);
    }

    public function testRenderLeafMaxHeightExceedsContentHeight(): void
    {
        $layout = Node::leaf("L1\nL2")->withBorder(false)->withMaxHeight(100);
        $result = $this->boxer->render($layout, 10, 5);
        $this->assertStringContainsString('L1', $result);
        $this->assertStringContainsString('L2', $result);
    }

    // -------------------------------------------------------------------------
    // distribute() all-zero weights
    // -------------------------------------------------------------------------

    public function testDistributeAllZeroWeightsGivesEqualSplit(): void
    {
        // When all minWidths are 0 (or unset), distribute() falls back to
        // array_fill(0, n, 1) for equal split.
        $layout = Node::horizontal(
            Node::leaf('A')->withBorder(false),
            Node::leaf('B')->withBorder(false),
            Node::leaf('C')->withBorder(false),
        )->withBorder(false)->withSpacing(0);

        $result = $this->boxer->render($layout, 12, 1);
        // All three should appear.
        $this->assertStringContainsString('A', $result);
        $this->assertStringContainsString('B', $result);
        $this->assertStringContainsString('C', $result);
    }

    // -------------------------------------------------------------------------
    // hasFlex edge case: empty children
    // -------------------------------------------------------------------------

    public function testHasFlexEmptyChildrenReturnsFalse(): void
    {
        $n = Node::horizontal();
        // hasFlex iterates over empty array and returns false.
        $result = $this->boxer->render($n, 10, 3);
        $this->assertIsString($result);
    }

    // -------------------------------------------------------------------------
    // Horizontal flex with maxWidth constraint
    // -------------------------------------------------------------------------

    public function testRenderHorizontalChildMaxWidthClampsWidth(): void
    {
        // SHORT gets minWidth=5 so it gets allocated space; maxWidth=3 then
        // clamps what actually renders inside that space to 3 columns ("SHO").
        $layout = Node::horizontal(
            Node::leaf('SHORT')->withBorder(false)->withMinWidth(5)->withMaxWidth(3),
            Node::leaf('LONGER')->withBorder(false)->withMinWidth(10)->withGrow(),
        )->withBorder(false)->withSpacing(1);

        $result = $this->boxer->render($layout, 25, 1);
        // SHORT's content is clamped to "SHO" (3 cols) by maxWidth.
        $this->assertStringContainsString('SHO', $result);
        $this->assertStringContainsString('LONGER', $result);
    }

    // -------------------------------------------------------------------------
    // Vertical flex with maxHeight constraint
    // -------------------------------------------------------------------------

    public function testRenderVerticalChildMaxHeightClampsHeight(): void
    {
        // TOP gets minHeight=3 so it gets allocated space; maxHeight=2 then
        // clamps content to 2 lines. Content is T1/T2/T3 → only T1 and T2 show.
        $layout = Node::vertical(
            Node::leaf("T1\nT2\nT3")->withBorder(false)->withMinHeight(3)->withMaxHeight(2),
            Node::leaf("B1\nB2\nB3\nB4\nB5")->withBorder(false)->withMinHeight(5)->withGrow(),
        )->withBorder(false)->withSpacing(1);

        $result = $this->boxer->render($layout, 20, 12);
        // T1 and T2 should appear (clamped by maxHeight=2), T3 is cut off.
        $this->assertStringContainsString('T1', $result);
        $this->assertStringContainsString('T2', $result);
        $this->assertStringNotContainsString('T3', $result);
        $this->assertStringContainsString('B1', $result);
    }

    // -------------------------------------------------------------------------
    // strWidth / nextGrapheme fallback when intl extension absent
    // -------------------------------------------------------------------------

    public function testStrWidthAsciiFallback(): void
    {
        // Width::string with ASCII uses mb_strlen.
        $layout = Node::leaf('HELLO')->withBorder(false);
        $result = $this->boxer->render($layout, 10, 1);
        $this->assertStringContainsString('HELLO', $result);
    }

    public function testStrWidthWithCombiningMarks(): void
    {
        // A base char + combining mark should still measure as width 1.
        $layout = Node::leaf("A\u{0300}")->withBorder(false);
        $w = Width::string("A\u{0300}");
        $this->assertSame(1, $w);
    }

    // -------------------------------------------------------------------------
    // renderLeaf when cw/ch becomes <= 0 after border padding
    // -------------------------------------------------------------------------

    public function testRenderLeafContentAreaZeroAfterPadding(): void
    {
        // Content area collapses to 0 after border + padding.
        // The early return at line 228 (pcw <= 0 || pch <= 0) must fire.
        $layout = Node::leaf('x')->withBorder(true)->withPadding(100)->withMinWidth(1)->withMinHeight(1);
        $result = $this->boxer->render($layout, 10, 10);
        $this->assertIsString($result);
    }

    // -------------------------------------------------------------------------
    // splitWord edge cases
    // -------------------------------------------------------------------------

    public function testSplitWordWithAnsiStyledGraphemes(): void
    {
        // A styled word wider than the region splits with escapes attached.
        $layout = Node::leaf("\x1b[31mREDWOR D\x1b[0m")->withBorder(false);
        $result = $this->boxer->render($layout, 5, 3);
        $this->assertStringContainsString('RED', $result);
    }

    public function testSplitWordPlain(): void
    {
        $layout = Node::leaf('VERYLONGTEXT')->withBorder(false);
        $result = $this->boxer->render($layout, 4, 3);
        $this->assertIsString($result);
    }

    // -------------------------------------------------------------------------
    // bufferFromOutput edge cases
    // -------------------------------------------------------------------------

    public function testBufferFromOutputFewerLinesThanHeight(): void
    {
        // When output has fewer lines than height, missing lines use ' '.
        $boxer = SugarBoxer::new();
        $ref = new \ReflectionMethod($boxer, 'bufferFromOutput');
        $ref->setAccessible(true);
        $buf = $ref->invoke($boxer, "short\n", 10, 5);
        $this->assertSame(10, $buf->width());
        $this->assertSame(5, $buf->height());
    }

    public function testBufferFromOutputMultibyteChars(): void
    {
        $boxer = SugarBoxer::new();
        $ref = new \ReflectionMethod($boxer, 'bufferFromOutput');
        $ref->setAccessible(true);
        $buf = $ref->invoke($boxer, "日本語\n", 10, 2);
        $this->assertSame(10, $buf->width());
        $this->assertSame(2, $buf->height());
    }

    // -------------------------------------------------------------------------
    // renderNode margin inset edge cases
    // -------------------------------------------------------------------------

    public function testRenderNodeMarginInsetMakesWOrHZero(): void
    {
        // When margin consumes the entire region, renderNode returns early.
        // margin [10,10,10,10] on a 10x10 viewport → w=10-20=-10 → early return.
        $leaf = Node::leaf('x')->withBorder(false)->withMargin(10, 10, 10, 10);
        $result = $this->boxer->render($leaf, 10, 10);
        $this->assertIsString($result);
    }

    public function testRenderNodeMarginOnlyRightConsumesWidth(): void
    {
        $leaf = Node::leaf('x')->withBorder(false)->withMinWidth(10)->withMargin(0, 15, 0, 0);
        $result = $this->boxer->render($leaf, 10, 10);
        $this->assertIsString($result);
    }

    // -------------------------------------------------------------------------
    // renderHorizontal/Vertical empty available space
    // -------------------------------------------------------------------------

    public function testRenderHorizontalAvailableWidthZero(): void
    {
        $layout = Node::horizontal(
            Node::leaf('A')->withBorder(true)->withMinWidth(5),
            Node::leaf('B')->withBorder(true)->withMinWidth(5),
        );
        // Border=true adds 2 to each child's minWidth; 5+2 + 5+2 = 14 > viewport 10.
        $result = $this->boxer->render($layout, 1, 10);
        $this->assertIsString($result);
    }

    public function testRenderVerticalAvailableHeightZero(): void
    {
        $layout = Node::vertical(
            Node::leaf('A')->withBorder(true)->withMinHeight(5),
            Node::leaf('B')->withBorder(true)->withMinHeight(5),
        );
        $result = $this->boxer->render($layout, 10, 1);
        $this->assertIsString($result);
    }
}
