<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use SugarCraft\CodeShot\LayoutCalculator;
use SugarCraft\CodeShot\SvgRenderer;
use PHPUnit\Framework\TestCase;

final class LayoutCalculatorTest extends TestCase
{
    public function testEmptyLinesReturnsZeroDimensions(): void
    {
        $result = LayoutCalculator::calculate(
            lines: [],
            lineNumbers: false,
            padding: 0,
            window: false,
            shadow: false,
            cellW: 8.0,
            cellH: 17.0,
        );

        [$maxCols, $gutter, $contentWidth, $contentHeight, $headerHeight, $shadowMargin, $totalW, $totalH] = $result;

        $this->assertSame(0, $maxCols);
        $this->assertSame(0, $gutter);
        $this->assertSame(0.0, $contentWidth);
        $this->assertSame(0.0, $contentHeight);
        $this->assertSame(0, $headerHeight);
        $this->assertSame(0, $shadowMargin);
        $this->assertEqualsWithDelta(0.0, $totalW, 0.001);
        $this->assertEqualsWithDelta(0.0, $totalH, 0.001);
    }

    public function testSingleLineCalculation(): void
    {
        $result = LayoutCalculator::calculate(
            lines: ['hello'],
            lineNumbers: false,
            padding: 0,
            window: false,
            shadow: false,
            cellW: 8.0,
            cellH: 17.0,
        );

        [$maxCols, $gutter, $contentWidth, $contentHeight, $headerHeight, $shadowMargin, $totalW, $totalH] = $result;

        $this->assertSame(5, $maxCols);
        $this->assertSame(0, $gutter);
        $this->assertSame(40.0, $contentWidth);
        $this->assertSame(17.0, $contentHeight);
        $this->assertSame(0, $headerHeight);
        $this->assertSame(0, $shadowMargin);
        $this->assertEqualsWithDelta(40.0, $totalW, 0.001);
        $this->assertEqualsWithDelta(17.0, $totalH, 0.001);
    }

    public function testWithLineNumbersAddsGutter(): void
    {
        $result = LayoutCalculator::calculate(
            lines: ['a', 'b', 'c'],
            lineNumbers: true,
            padding: 0,
            window: false,
            shadow: false,
            cellW: 8.0,
            cellH: 17.0,
        );

        [$maxCols, $gutter, $contentWidth, $contentHeight] = $result;

        $this->assertSame(1, $maxCols);
        // Gutter: max(2, strlen((string) 3)) + 2 = max(2, 1) + 2 = 4
        $this->assertSame(4, $gutter);
        // Content width: (1 + 4) * 8 = 40
        $this->assertSame(40.0, $contentWidth);
    }

    public function testLineNumbersGutterWithManyLines(): void
    {
        $lines = array_fill(0, 100, 'x');
        $result = LayoutCalculator::calculate(
            lines: $lines,
            lineNumbers: true,
            padding: 0,
            window: false,
            shadow: false,
            cellW: 8.0,
            cellH: 17.0,
        );

        [, $gutter] = $result;

        // Gutter: max(2, strlen("100")) + 2 = max(2, 3) + 2 = 5
        $this->assertSame(5, $gutter);
    }

    public function testWithPaddingAddsSpace(): void
    {
        $result = LayoutCalculator::calculate(
            lines: ['test'],
            lineNumbers: false,
            padding: 16,
            window: false,
            shadow: false,
            cellW: 8.0,
            cellH: 17.0,
        );

        [, , , , , , $totalW, $totalH] = $result;

        // Content: 4 * 8 = 32, padding: 16 * 2 = 32, total: 64
        $this->assertEqualsWithDelta(64.0, $totalW, 0.001);
        // Content: 17, padding: 16 * 2 = 32, total: 49
        $this->assertEquals(49, $totalH);
    }

    public function testWithWindowAddsHeaderHeight(): void
    {
        $result = LayoutCalculator::calculate(
            lines: ['test'],
            lineNumbers: false,
            padding: 0,
            window: true,
            shadow: false,
            cellW: 8.0,
            cellH: 17.0,
        );

        [, , , , $headerHeight] = $result;

        $this->assertSame(36, $headerHeight);
    }

    public function testWithShadowAddsMargin(): void
    {
        $result = LayoutCalculator::calculate(
            lines: ['test'],
            lineNumbers: false,
            padding: 0,
            window: false,
            shadow: true,
            cellW: 8.0,
            cellH: 17.0,
        );

        [, , , , , $shadowMargin, $totalW, $totalH] = $result;

        $this->assertSame(32, $shadowMargin);
        // Content: 32, shadow: 32 * 2 = 64, total: 96
        $this->assertEquals(96, $totalW);
        $this->assertEquals(81, $totalH);
    }

    public function testAnsiCodesAreStrippedFromColumnCount(): void
    {
        $result = LayoutCalculator::calculate(
            lines: ["\x1b[1mbold\x1b[0m"],
            lineNumbers: false,
            padding: 0,
            window: false,
            shadow: false,
            cellW: 8.0,
            cellH: 17.0,
        );

        [$maxCols] = $result;

        // Should count 4 visible characters, not 16 raw bytes
        $this->assertSame(4, $maxCols);
    }

    public function testMultipleLinesUsesLongestLine(): void
    {
        $result = LayoutCalculator::calculate(
            lines: ['short', 'medium text', 'very long line here'],
            lineNumbers: false,
            padding: 0,
            window: false,
            shadow: false,
            cellW: 8.0,
            cellH: 17.0,
        );

        [$maxCols] = $result;

        // "very long line here" is 19 characters
        $this->assertSame(19, $maxCols);
    }

    public function testCombinedOptions(): void
    {
        $result = LayoutCalculator::calculate(
            lines: ['content'],
            lineNumbers: true,
            padding: 10,
            window: true,
            shadow: true,
            cellW: 8.0,
            cellH: 17.0,
        );

        [$maxCols, $gutter, $contentWidth, $contentHeight, $headerHeight, $shadowMargin, $totalW, $totalH] = $result;

        $this->assertSame(7, $maxCols);
        // Gutter: max(2, 1) + 2 = 4
        $this->assertSame(4, $gutter);
        // Content: (7 + 4) * 8 = 88
        $this->assertSame(88.0, $contentWidth);
        // Content: 1 * 17 = 17
        $this->assertSame(17.0, $contentHeight);
        // Header: 36
        $this->assertSame(36, $headerHeight);
        // Shadow margin: 32
        $this->assertSame(32, $shadowMargin);
        // Total W: 88 + 10*2 + 32*2 = 88 + 20 + 64 = 172
        $this->assertEquals(172, $totalW);
        // Total H: 17 + 10*2 + 36 + 32*2 = 17 + 20 + 36 + 64 = 137
        $this->assertEquals(137, $totalH);
    }

    public function testChromeFalseCollapsesTheHeaderBandOnAWindowedCanvas(): void
    {
        // WindowStyle::None keeps --window true (frame border etc.) but must
        // remove the 36px title band rather than leave it as an empty strip.
        $with    = LayoutCalculator::calculate(
            lines: ['content'], lineNumbers: false, padding: 10, window: true,
            shadow: false, cellW: 8.0, cellH: 17.0,
        );
        $without = LayoutCalculator::calculate(
            lines: ['content'], lineNumbers: false, padding: 10, window: true,
            shadow: false, cellW: 8.0, cellH: 17.0, chrome: false,
        );

        $this->assertSame(36, $with[4]);
        $this->assertSame(0, $without[4]);
        // Total height is the only thing that moves: same content box.
        $this->assertEquals(36, $with[7] - $without[7]);
        $this->assertSame($with[2], $without[2]);
    }

    public function testTabbedLinesAreBudgetedToPaintedColumns(): void
    {
        // SgrStateHandler expands every tab to the next 8-cell stop, but the
        // budget side used to measure the raw line, where sugar-core Width
        // counts a tab as 4 cells — a tabbed line was under-budgeted and could
        // be clipped by the frame it justified. "\tend;" paints at column 8
        // and occupies 12 cells; it used to be budgeted 8.
        [$maxCols, , $contentWidth] = LayoutCalculator::calculate(
            lines: ["\tend;"], lineNumbers: false, padding: 0, window: false,
            shadow: false, cellW: 8.0, cellH: 17.0,
        );

        $this->assertSame(12, $maxCols);
        $this->assertEquals(96.0, $contentWidth);
    }

    public function testRepeatedTabsWalkTheEightCellStops(): void
    {
        // Each x lands one cell past a stop, so every tab pads the full 7 to
        // the next stop: 1 → 8+1 → 16+1 → 24+1 → 32+8 = 40 painted columns.
        // The old 4-cell tab measure reported 28.
        [$maxCols, , $contentWidth] = LayoutCalculator::calculate(
            lines: ["x\tx\tx\tx\tyyyyyyyy"], lineNumbers: false, padding: 0, window: false,
            shadow: false, cellW: 8.0, cellH: 17.0,
        );

        $this->assertSame(40, $maxCols);
        $this->assertEquals(320.0, $contentWidth);
    }

    public function testTabsBudgetIdenticallyToSpaceAlignment(): void
    {
        // The law the paint side enforces: a tab is space-padding to the next
        // stop, so a tabbed line and its space-expanded twin must agree.
        $tabbed = LayoutCalculator::calculate(
            lines: ["\tend;"], lineNumbers: false, padding: 0, window: false,
            shadow: false, cellW: 8.0, cellH: 17.0,
        );
        $spaced = LayoutCalculator::calculate(
            lines: ['        end;'], lineNumbers: false, padding: 0, window: false,
            shadow: false, cellW: 8.0, cellH: 17.0,
        );

        $this->assertSame($spaced[0], $tabbed[0]);
    }

    /**
     * cs-25 (-045) measured pass-count pin: layout walks every line ONCE.
     * Restoring any second maxCols walk — in the calculator or around it in
     * a renderer — shifts the delta even though the output bytes stay
     * identical, which is exactly why bytes alone cannot pin this.
     */
    public function testCalculateWalksEveryLineExactlyOnce(): void
    {
        $before = LayoutCalculator::paintedColumnScanCount();
        LayoutCalculator::calculate(
            lines: ['aaa', 'bb', 'dddd', 'e', 'ff'],
            lineNumbers: true, padding: 4, window: true,
            shadow: true, cellW: 8.0, cellH: 17.0,
        );
        $this->assertSame(5, LayoutCalculator::paintedColumnScanCount() - $before);
    }

    public function testSvgRenderSharesTheSingleLayoutWalkWithoutReWalking(): void
    {
        $lines = array_fill(0, 40, 'x');
        $before = LayoutCalculator::paintedColumnScanCount();
        (new SvgRenderer())->withLineNumbers(true)->render(implode("\n", $lines));
        // 40 lines, one walk each — paint consumes layout's numbers and only
        // measures the per-segment widths it needs for x advancement.
        $this->assertSame(40, LayoutCalculator::paintedColumnScanCount() - $before);
    }

    /**
     * cs-30 (-057b/c): the @return shape label must match the MEASURED types.
     * Cell sizes are float, so contentWidth/contentHeight/totalW/totalH are
     * doubles; maxCols, gutter, headerHeight and shadowMargin are ints.
     */
    public function testReturnElementTypesMatchTheShapeLabel(): void
    {
        $result = LayoutCalculator::calculate(
            lines: ['ab', 'c'], lineNumbers: true, padding: 4, window: true,
            shadow: true, cellW: 8.0, cellH: 17.0,
        );

        $this->assertSame(
            ['integer', 'integer', 'double', 'double', 'integer', 'integer', 'double', 'double'],
            array_map('gettype', $result),
        );
        // The headline honesty case: contentHeight = 2 lines * 17.0 — a float.
        $this->assertSame(34.0, $result[3]);
    }
}
