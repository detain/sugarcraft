<?php

declare(strict_types=1);

namespace SugarCraft\Boxer\Tests;

use SugarCraft\Boxer\{Node, SugarBoxer};
use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Align;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Sprinkles\VAlign;
use PHPUnit\Framework\TestCase;

final class SugarBoxerTest extends TestCase
{
    private SugarBoxer $boxer;

    protected function setUp(): void
    {
        $this->boxer = SugarBoxer::new();
    }

    public function testNewBoxer(): void
    {
        $b = SugarBoxer::new();
        $this->assertInstanceOf(SugarBoxer::class, $b);
    }

    public function testLeafNode(): void
    {
        $n = Node::leaf('hello');
        $this->assertSame(Node::LEAF, $n->kind);
        $this->assertSame('hello', $n->content);
    }

    public function testHorizontalNode(): void
    {
        $n = Node::horizontal(Node::leaf('a'), Node::leaf('b'));
        $this->assertSame(Node::HORIZONTAL, $n->kind);
        $this->assertCount(2, $n->children);
    }

    public function testVerticalNode(): void
    {
        $n = Node::vertical(Node::leaf('top'), Node::leaf('bottom'));
        $this->assertSame(Node::VERTICAL, $n->kind);
        $this->assertCount(2, $n->children);
    }

    public function testNodeWithPadding(): void
    {
        $n = Node::leaf('x')->withPadding(2);
        $this->assertSame(2, $n->padding);
    }

    public function testNodeWithBorder(): void
    {
        $n = Node::leaf('x')->withBorder(false);
        $this->assertFalse($n->border);
    }

    public function testNodeWithSpacing(): void
    {
        $n = Node::horizontal(Node::leaf('a'), Node::leaf('b'))->withSpacing(2);
        $this->assertSame(2, $n->spacing);
    }

    public function testNodeTotalWidth(): void
    {
        $leaf = Node::leaf('hello')->withMinWidth(5);
        $h = Node::horizontal($leaf, Node::leaf('world')->withMinWidth(5))->withBorder(true);
        $this->assertGreaterThan(0, $h->totalWidth());
    }

    public function testNodeTotalHeight(): void
    {
        $v = Node::vertical(
            Node::leaf('a')->withMinHeight(1),
            Node::leaf('b')->withMinHeight(1),
        )->withBorder(true);

        $this->assertGreaterThan(0, $v->totalHeight());
    }

    public function testRenderEmptyLayout(): void
    {
        $layout = Node::leaf('');
        $result = $this->boxer->render($layout, 10, 5);
        $this->assertIsString($result);
    }

    public function testRenderLeafWithBorder(): void
    {
        $layout = Node::leaf('content')->withBorder(true)->withMinWidth(10)->withMinHeight(3);
        $result = $this->boxer->render($layout, 14, 5);

        // Should contain box-drawing chars
        $this->assertStringContainsString('╭', $result);
        $this->assertStringContainsString('╮', $result);
        $this->assertStringContainsString('╰', $result);
        $this->assertStringContainsString('╯', $result);
        $this->assertStringContainsString('content', $result);
    }

    public function testRenderLeafNoBorder(): void
    {
        $layout = Node::leaf('plain')->withBorder(false);
        $result = $this->boxer->render($layout, 10, 3);

        $this->assertStringNotContainsString('╭', $result);
        $this->assertStringContainsString('plain', $result);
    }

    public function testFittingLinePreservesInternalWhitespace(): void
    {
        // A line that fits the width must NOT be word-wrapped (which re-joins on
        // single spaces) — intentional runs of whitespace (column alignment,
        // padded key hints) have to survive verbatim.
        $layout = Node::leaf('a    b    c')->withBorder(false);
        $result = $this->boxer->render($layout, 20, 1);

        $this->assertStringContainsString('a    b    c', $result);
        $this->assertStringNotContainsString('a b c', $result);
    }

    public function testFittingPaddedColumnsArePreserved(): void
    {
        // A table-style row with right-padded columns survives intact.
        $row = '#   Title          Duration';
        $layout = Node::leaf($row)->withBorder(false);
        $result = $this->boxer->render($layout, 40, 1);

        $this->assertStringContainsString($row, $result);
    }

    public function testOverflowingLineStillWraps(): void
    {
        // Lines that do NOT fit must still wrap across rows (regression guard).
        $layout = Node::leaf('alpha beta gamma delta epsilon')->withBorder(false);
        $result = $this->boxer->render($layout, 11, 6);

        // Every word survives the wrap…
        foreach (['alpha', 'beta', 'gamma', 'delta', 'epsilon'] as $word) {
            $this->assertStringContainsString($word, $result);
        }
        // …and the text is split across more than one visual row.
        $rows = array_values(array_filter(
            array_map('rtrim', explode("\n", $result)),
            static fn (string $l): bool => $l !== '',
        ));
        $this->assertGreaterThan(1, count($rows));
    }

    public function testFittingLineExactlyAtWidthIsPreserved(): void
    {
        $line = 'x  y  z'; // width 7
        $layout = Node::leaf($line)->withBorder(false);
        $result = $this->boxer->render($layout, 7, 1);

        $this->assertStringContainsString($line, $result);
    }

    public function testRenderHorizontalTwoPanels(): void
    {
        $layout = Node::horizontal(
            Node::leaf('LEFT')->withMinWidth(5),
            Node::leaf('RIGHT')->withMinWidth(5),
        )->withBorder(true);

        $result = $this->boxer->render($layout, 30, 5);

        $this->assertStringContainsString('LEFT',  $result);
        $this->assertStringContainsString('RIGHT', $result);
        $this->assertStringContainsString('│',     $result); // vertical separator
    }

    public function testRenderVerticalTwoPanels(): void
    {
        $layout = Node::vertical(
            Node::leaf('TOP')->withMinHeight(2),
            Node::leaf('BOTTOM')->withMinHeight(2),
        )->withBorder(true);

        $result = $this->boxer->render($layout, 20, 10);

        $this->assertStringContainsString('TOP',    $result);
        $this->assertStringContainsString('BOTTOM', $result);
        $this->assertStringContainsString('─',      $result); // horizontal separator
    }

    public function testRenderNestedLayout(): void
    {
        $layout = Node::vertical(
            Node::horizontal(
                Node::leaf('A')->withMinWidth(3),
                Node::leaf('B')->withMinWidth(3),
            )->withMinHeight(3),
            Node::leaf('C')->withMinHeight(2),
        )->withBorder(true);

        $result = $this->boxer->render($layout, 20, 10);

        $this->assertStringContainsString('A', $result);
        $this->assertStringContainsString('B', $result);
        $this->assertStringContainsString('C', $result);
    }

    public function testRenderNoBorder(): void
    {
        $layout = Node::noBorder(Node::leaf('nested'));
        $result = $this->boxer->render($layout, 10, 3);

        $this->assertStringContainsString('nested', $result);
    }

    public function testLeafWithPadding(): void
    {
        $layout = Node::leaf('padded')->withPadding(3)->withBorder(true)->withMinWidth(10);
        $result = $this->boxer->render($layout, 20, 5);

        $this->assertStringContainsString('padded', $result);
    }

    public function testRenderMultipleLines(): void
    {
        $multiline = "line1\nline2\nline3";
        $layout = Node::leaf($multiline)->withBorder(true)->withMinWidth(10)->withMinHeight(5);
        $result = $this->boxer->render($layout, 20, 8);

        $this->assertStringContainsString('line1', $result);
        $this->assertStringContainsString('line2', $result);
        $this->assertStringContainsString('line3', $result);
    }

    public function testWithContent(): void
    {
        $n = Node::leaf('')->withContent('updated');
        $this->assertSame('updated', $n->content);
    }

    public function testWithDimensionConstraints(): void
    {
        $n = Node::leaf('x')
            ->withMinWidth(10)
            ->withMaxWidth(50)
            ->withMinHeight(5)
            ->withMaxHeight(20);

        $this->assertSame(10, $n->minWidth);
        $this->assertSame(50, $n->maxWidth);
        $this->assertSame(5,  $n->minHeight);
        $this->assertSame(20, $n->maxHeight);
    }

    public function testNodeWithMargin(): void
    {
        $n = Node::leaf('x')->withMargin(1, 2, 3, 4);
        $this->assertSame([1, 2, 3, 4], $n->margin);
    }

    public function testNodeWithMarginDefaultValues(): void
    {
        $n = Node::leaf('x')->withMargin(1);
        $this->assertSame([1, 1, 1, 1], $n->margin);
    }

    public function testNodeWithMarginZero(): void
    {
        $n = Node::leaf('x')->withMargin(0);
        $this->assertSame([0, 0, 0, 0], $n->margin);
    }

    public function testNodeWithMarginTwoValues(): void
    {
        $n = Node::leaf('x')->withMargin(1, 2);
        $this->assertSame([1, 2, 1, 2], $n->margin);
    }

    public function testNodeWithAlignHCenter(): void
    {
        $n = Node::leaf('x')->withAlignH(Align::Center);
        $this->assertSame(Align::Center, $n->alignH);
    }

    public function testNodeWithAlignHLeft(): void
    {
        $n = Node::leaf('x')->withAlignH(Align::Left);
        $this->assertSame(Align::Left, $n->alignH);
    }

    public function testNodeWithAlignHRight(): void
    {
        $n = Node::leaf('x')->withAlignH(Align::Right);
        $this->assertSame(Align::Right, $n->alignH);
    }

    public function testNodeWithAlignVTop(): void
    {
        $n = Node::leaf('x')->withAlignV(VAlign::Top);
        $this->assertSame(VAlign::Top, $n->alignV);
    }

    public function testNodeWithAlignVCenter(): void
    {
        $n = Node::leaf('x')->withAlignV(VAlign::Middle);
        $this->assertSame(VAlign::Middle, $n->alignV);
    }

    public function testNodeWithAlignVBottom(): void
    {
        $n = Node::leaf('x')->withAlignV(VAlign::Bottom);
        $this->assertSame(VAlign::Bottom, $n->alignV);
    }

    /**
     * Benchmark: diff-based rendering emits fewer bytes than full re-render
     * for small changes between consecutive frames.
     *
     * Frame 1: full output (~100 bytes for a small box)
     * Frame 2: delta output (≤30 bytes for a 1-char change)
     * Frame 3: delta output (≤30 bytes for another 1-char change)
     * Total delta: ≤60 bytes for 2 delta frames (30×2)
     */
    public function testDiffEmissionByteBenchmark(): void
    {
        $boxer = SugarBoxer::new();

        // Frame 1: full render
        $layout1 = Node::leaf('Hello')->withBorder(true)->withMinWidth(10)->withMinHeight(3);
        $out1 = $boxer->render($layout1, 20, 5);
        $bytes1 = \strlen($out1);

        // Frame 2: same layout but content changed by 1 char
        $layout2 = Node::leaf('Hello!')->withBorder(true)->withMinWidth(10)->withMinHeight(3);
        $out2 = $boxer->render($layout2, 20, 5);
        $bytes2 = \strlen($out2);

        // Frame 3: another small change
        $layout3 = Node::leaf('Hello!!')->withBorder(true)->withMinWidth(10)->withMinHeight(3);
        $out3 = $boxer->render($layout3, 20, 5);
        $bytes3 = \strlen($out3);

        // First frame is full output (baseline)
        $this->assertGreaterThan(50, $bytes1, 'Frame 1 should be full output');

        // Subsequent frames should be delta (≤30 bytes per frame for small changes)
        $this->assertLessThanOrEqual(30, $bytes2, 'Frame 2 delta should be ≤30 bytes');
        $this->assertLessThanOrEqual(30, $bytes3, 'Frame 3 delta should be ≤30 bytes');

        // Total delta bytes for 2 frames should be ≤60 (30×2)
        $totalDelta = $bytes2 + $bytes3;
        $this->assertLessThanOrEqual(60, $totalDelta, 'Total delta bytes for 2 frames should be ≤60');
    }

    /**
     * Regression for the FIX-3 deferred-buffer change.
     *
     * The diff-buffer build is now deferred from frame 1 to the first subsequent
     * same-dimension render. Public behaviour must be IDENTICAL to before:
     *  (a) a fresh SugarBoxer's first render() returns the FULL output;
     *  (b) a REUSED instance's second same-dim render() returns a DELTA (not the full
     *      output) for a small change;
     *  (c) a resize (changed dimensions) returns the FULL output again.
     */
    public function testDeferredBufferPreservesFullThenDeltaThenFullOnResize(): void
    {
        $boxer = SugarBoxer::new();

        // (a) Fresh instance, first frame → full output.
        $layout1 = Node::leaf('Hello')->withBorder(true)->withMinWidth(10)->withMinHeight(3);
        $full = $boxer->render($layout1, 20, 5);
        $this->assertStringContainsString('Hello', $full, 'Frame 1 should be the full render output');
        $this->assertGreaterThan(50, \strlen($full), 'Frame 1 should be full output, not a delta');

        // (b) Reused instance, second frame, same dims, small change → delta.
        $layout2 = Node::leaf('Hello!')->withBorder(true)->withMinWidth(10)->withMinHeight(3);
        $delta = $boxer->render($layout2, 20, 5);
        $this->assertStringNotContainsString('╭', $delta, 'Frame 2 should be a delta, not the full frame');
        $this->assertLessThanOrEqual(30, \strlen($delta), 'Frame 2 delta should be small');

        // (c) Resize (taller viewport → changed dimensions) → full output again.
        $fullAgain = $boxer->render($layout2, 20, 7);
        $this->assertStringContainsString('Hello', $fullAgain, 'A resize should re-emit the full output');
        $this->assertGreaterThan(50, \strlen($fullAgain), 'Resize frame should be full output, not a delta');

        // After the resize full frame, a subsequent same-dim render is a delta again.
        $layout3 = Node::leaf('Hello!!')->withBorder(true)->withMinWidth(10)->withMinHeight(3);
        $deltaAfterResize = $boxer->render($layout3, 20, 7);
        $this->assertStringNotContainsString('╭', $deltaAfterResize, 'Post-resize frame 2 should be a delta');
        $this->assertLessThanOrEqual(30, \strlen($deltaAfterResize), 'Post-resize delta should be small');
    }

    /**
     * A FRESH SugarBoxer per render() (exactly what Chrome::frame() does) must always
     * return the full output and must never reach the diff/buffer path — that is the
     * case FIX-3 makes cheap by deferring the buffer build.
     */
    public function testFreshInstancePerRender_alwaysFullOutput(): void
    {
        foreach (['a', 'ab', 'abc'] as $content) {
            $layout = Node::leaf($content)->withBorder(true)->withMinWidth(10)->withMinHeight(3);
            $out = SugarBoxer::new()->render($layout, 20, 5);
            $this->assertStringContainsString($content, $out, 'Fresh-instance render must be full output');
            $this->assertStringContainsString('╭', $out, 'Fresh-instance render must be the full frame');
        }
    }

    /**
     * resetPreviousFrame() must restart the first-frame path so the next render()
     * emits the full output again.
     */
    public function testResetPreviousFrameRestartsFullOutput(): void
    {
        $boxer = SugarBoxer::new();

        $layout1 = Node::leaf('Hello')->withBorder(true)->withMinWidth(10)->withMinHeight(3);
        $boxer->render($layout1, 20, 5);
        $layout2 = Node::leaf('Hello!')->withBorder(true)->withMinWidth(10)->withMinHeight(3);
        $delta = $boxer->render($layout2, 20, 5);
        $this->assertStringNotContainsString('╭', $delta, 'Second frame is a delta before reset');

        $boxer->resetPreviousFrame();

        $layout3 = Node::leaf('Hello!!')->withBorder(true)->withMinWidth(10)->withMinHeight(3);
        $full = $boxer->render($layout3, 20, 5);
        $this->assertStringContainsString('╭', $full, 'After reset, render re-emits full output');
        $this->assertGreaterThan(50, \strlen($full), 'After reset, render is full output, not a delta');
    }

    // -------------------------------------------------------------------------
    // Steps 8-11: Regression & render-behaviour tests
    // -------------------------------------------------------------------------

    public function testWithContentPreservesNodeState(): void
    {
        // Leaf: withContent preserves border, padding, minWidth
        $n = Node::leaf('x')->withBorder(true)->withMinWidth(5)->withPadding(2)->withContent('y');
        $this->assertSame('y', $n->content);
        $this->assertTrue($n->border);
        $this->assertSame(5, $n->minWidth);
        $this->assertSame(2, $n->padding);

        // Horizontal: withContent on a non-leaf preserves kind and children
        $n2 = Node::horizontal(Node::leaf('a'), Node::leaf('b'))->withContent('z');
        $this->assertSame(Node::HORIZONTAL, $n2->kind);
        $this->assertCount(2, $n2->children);
        $this->assertSame('z', $n2->content);
    }

    public function testTitleRendersInTopBorder(): void
    {
        $layout = Node::leaf('content')->withBorder(true)->withTitle('Panel')->withMinWidth(12);
        $result = $this->boxer->render($layout, 20, 5);

        // Title should appear in the FIRST line (top border row)
        $lines = \explode("\n", $result);
        $this->assertStringContainsString('Panel', $lines[0]);
    }

    public function testAlignCenterPadsContent(): void
    {
        $layout = Node::leaf('ABC')->withBorder(false)->withAlignH(Align::Center);
        $result = $this->boxer->render($layout, 10, 3);
        $lines = \explode("\n", $result);

        // In a 10-wide region, "ABC" (width 3) centered has left pad = (10-3)/2 = 3
        // So 'A' should be at column 3 (0-indexed), meaning lines[0][3] === 'A'
        $this->assertSame('A', $lines[0][3] ?? '', 'Centered text should have leading spaces');
        // Column 0 should be a space (left pad)
        $this->assertSame(' ', $lines[0][0] ?? '');
    }

    public function testAlignRightPadsContent(): void
    {
        $layout = Node::leaf('ABC')->withBorder(false)->withAlignH(Align::Right);
        $result = $this->boxer->render($layout, 10, 3);
        $lines = \explode("\n", $result);

        // In a 10-wide region, "ABC" (width 3) right-aligned has left pad = 10-3 = 7
        // So 'A' should be at column 7 (0-indexed)
        $this->assertSame('A', $lines[0][7] ?? '', 'Right-aligned text should start at correct column');
        // Column 0 should be a space (left pad of 7)
        $this->assertSame(' ', $lines[0][0] ?? '');
    }

    public function testAlignVMiddle(): void
    {
        $layout = Node::leaf("L1\nL2\nL3")->withBorder(false)->withAlignV(VAlign::Middle);
        $result = $this->boxer->render($layout, 10, 8);
        $lines = \explode("\n", $result);

        // In height 8 with 3 lines, middle gives topPad = (8-3)/2 = 2
        // So L1 should be at line index 2, not 0
        $this->assertSame('L1', \trim($lines[2] ?? ''));
        $this->assertSame('', \trim($lines[0] ?? ''));
    }

    public function testAlignVBottom(): void
    {
        $layout = Node::leaf("L1\nL2\nL3")->withBorder(false)->withAlignV(VAlign::Bottom);
        $result = $this->boxer->render($layout, 10, 8);
        $lines = \explode("\n", $result);

        // In height 8 with 3 lines, bottom gives topPad = 8-3 = 5
        // So L1 should be at line index 5, not 0
        $this->assertSame('L1', \trim($lines[5] ?? ''));
        $this->assertSame('', \trim($lines[0] ?? ''));
    }

    public function testStyleEmitsSgrAndResets(): void
    {
        // Use Color::ansi(14) = cyan (ANSI 16 index 14 = RGB [0,255,255])
        // Renders to \x1b[36m in basic ANSI mode
        $layout = Node::leaf('test')->withBorder(false)->withStyle(Style::new()->fg(Color::ansi(14)));
        $result = $this->boxer->render($layout, 10, 3);

        // Should contain SGR reset after content
        $this->assertStringContainsString("\x1b[0m", $result, 'Output should contain SGR reset');
    }

    public function testMaxWidthClampsContentWidth(): void
    {
        $layout = Node::leaf('1234567890')->withBorder(false)->withMaxWidth(6);
        $result = $this->boxer->render($layout, 20, 3);
        $lines = \explode("\n", $result);

        // No line should exceed 6 visible content cols
        foreach ($lines as $line) {
            if (\trim($line) === '') continue;
            // Count visible chars (strip ANSI escapes for measurement)
            $stripped = \preg_replace('/\x1b\[[0-9;]*m/', '', $line);
            $visible = \ltrim($stripped);
            $leadingSpaces = \strlen($stripped) - \strlen($visible);
            $this->assertLessThanOrEqual(6, \strlen(\trim($stripped)), 'Content width should be clamped to maxWidth');
        }
    }

    public function testMaxHeightClampsContentHeight(): void
    {
        $multiline = "L1\nL2\nL3\nL4\nL5";
        $layout = Node::leaf($multiline)->withBorder(false)->withMaxHeight(3);
        $result = $this->boxer->render($layout, 20, 10);
        $lines = \explode("\n", $result);

        // Count non-empty lines
        $contentLines = \array_filter(\array_map('trim', $lines), static fn($l) => $l !== '');
        $this->assertLessThanOrEqual(3, \count($contentLines), 'Content height should be clamped to maxHeight');
    }

    public function testNodeWithMarginExplicitZeroSides(): void
    {
        // Explicit zeros on right/bottom/left must be honored when passed as 4 args
        $n = Node::leaf('x')->withMargin(2, 0, 0, 0);
        $this->assertSame([2, 0, 0, 0], $n->margin);

        // 3-arg shorthand: withMargin(0,5) uses CSS shorthand where left falls back to right
        $n2 = Node::leaf('x')->withMargin(0, 5);
        $this->assertSame([0, 5, 0, 5], $n2->margin);
    }

    public function testNumericFieldsCanBeResetToZero(): void
    {
        // padding reset
        $n = Node::leaf('x')->withPadding(3)->withPadding(0);
        $this->assertSame(0, $n->padding);

        // spacing reset
        $n = Node::leaf('x')->withSpacing(2)->withSpacing(0);
        $this->assertSame(0, $n->spacing);

        // minWidth reset
        $n = Node::leaf('x')->withMinWidth(9)->withMinWidth(0);
        $this->assertSame(0, $n->minWidth);

        // flex reset (withGrow -> withFlex(0))
        $n = Node::leaf('x')->withGrow()->withFlex(0);
        $this->assertSame(0, $n->flex);
    }

    public function testManyFixedChildrenNarrowViewportNoVanish(): void
    {
        // Use 5 children (border=false) in width=12 to test distribute overflow.
        // With borderPad=1 and contentSpan=10, 5 equal children each get ~2 cols.
        // My clamp keeps offsets within bounds so the last child always gets space.
        $children = [];
        for ($i = 0; $i < 5; $i++) {
            $children[] = Node::leaf('c' . $i)->withBorder(false);
        }
        $layout = Node::horizontal(...$children);
        $boxer = SugarBoxer::new();

        // Should not throw and should return a valid multi-line string
        $result = $boxer->render($layout, 12, 3);
        $this->assertIsString($result);
        $this->assertGreaterThan(0, \strlen($result));
        $lines = \explode("\n", $result);
        $this->assertSame(3, \count($lines));

        // The last child's content 'c4' should appear (trailing child never vanishes)
        $this->assertStringContainsString('c4', $result);
    }
}
