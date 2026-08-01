<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\LayoutCalculator;
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
}
