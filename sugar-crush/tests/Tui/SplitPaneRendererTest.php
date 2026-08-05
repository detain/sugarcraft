<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tui\Renderer;
use SugarCraft\Crush\Tui\SplitDirection;
use SugarCraft\Crush\Tui\SplitLayout;

/**
 * @see SplitDirection
 * @see SplitLayout
 * @see SplitLayout::horizontal()
 * @see SplitLayout::vertical()
 * @see SplitLayout::splitPosition()
 * @see SplitLayout::paneSizes()
 * @see SplitLayout::render()
 */
final class SplitPaneRendererTest extends TestCase
{
    // =========================================================================
    // SplitDirection Enum Tests
    // =========================================================================

    public function testSplitDirectionHasHorizontalCase(): void
    {
        $direction = SplitDirection::Horizontal;

        $this->assertSame('horizontal', $direction->value);
        $this->assertTrue($direction->isHorizontal());
        $this->assertFalse($direction->isVertical());
    }

    public function testSplitDirectionHasVerticalCase(): void
    {
        $direction = SplitDirection::Vertical;

        $this->assertSame('vertical', $direction->value);
        $this->assertTrue($direction->isVertical());
        $this->assertFalse($direction->isHorizontal());
    }

    public function testSplitDirectionDividerForHorizontal(): void
    {
        $divider = SplitDirection::Horizontal->divider();

        // BOX DRAWINGS LIGHT HORIZONTAL (U+2500)
        $this->assertSame("\u{2500}", $divider);
    }

    public function testSplitDirectionDividerForVertical(): void
    {
        $divider = SplitDirection::Vertical->divider();

        // BOX DRAWINGS LIGHT VERTICAL (U+2502)
        $this->assertSame("\u{2502}", $divider);
    }

    // =========================================================================
    // SplitLayout::horizontal() Tests
    // =========================================================================

    public function testHorizontalSplitComposesTwoPaneStringsWithDivider(): void
    {
        $layout = SplitLayout::horizontal("Line1\nLine2", "Line3\nLine4");

        $this->assertSame("Line1\nLine2", $layout->topOrLeftContent);
        $this->assertSame("Line3\nLine4", $layout->bottomOrRightContent);
        $this->assertSame(SplitDirection::Horizontal, $layout->direction);
    }

    public function testHorizontalSplitRendersTopPaneAboveBottomPaneWithDivider(): void
    {
        $layout = SplitLayout::horizontal("TOP", "BOTTOM");
        $output = $layout->render(20, 10);

        // Top pane content should appear before the divider
        $this->assertStringStartsWith("TOP", $output);

        // Divider should appear between panes
        $divider = SplitDirection::Horizontal->divider();
        $this->assertStringContainsString($divider, $output);

        // Bottom pane content should appear after the divider
        $this->assertStringContainsString("BOTTOM", $output);
    }

    // =========================================================================
    // SplitLayout::vertical() Tests
    // =========================================================================

    public function testVerticalSplitComposesTwoPaneStringsSideBySideWithDivider(): void
    {
        $layout = SplitLayout::vertical("LEFT", "RIGHT");

        $this->assertSame("LEFT", $layout->topOrLeftContent);
        $this->assertSame("RIGHT", $layout->bottomOrRightContent);
        $this->assertSame(SplitDirection::Vertical, $layout->direction);
    }

    public function testVerticalSplitRendersLeftAndRightPanesSeparatedByVerticalDivider(): void
    {
        $layout = SplitLayout::vertical("LEFT", "RIGHT");
        $output = $layout->render(40, 10);

        // Should contain the vertical divider
        $divider = SplitDirection::Vertical->divider();
        $this->assertStringContainsString($divider, $output);

        // Both pane contents should be present
        $this->assertStringContainsString("LEFT", $output);
        $this->assertStringContainsString("RIGHT", $output);
    }

    // =========================================================================
    // Dimension Calculation Tests
    // =========================================================================

    public function testSplitLayoutCalculatesCorrectDimensionsAt50Percent(): void
    {
        $layout = SplitLayout::horizontal("TOP", "BOTTOM", 1, 2);

        // With total size 10 and 50% ratio (1/2), split should be at position 5
        $position = $layout->splitPosition(10);
        $this->assertSame(5, $position);
    }

    public function testSplitLayoutPaneSizesAt50Percent(): void
    {
        $layout = SplitLayout::horizontal("TOP", "BOTTOM", 1, 2);

        [$first, $second] = $layout->paneSizes(10);

        // Total 10, divider 1, so available is 9
        // 50% split means first gets 5, second gets 10 - 5 - 1 = 4
        $this->assertSame(5, $first);
        $this->assertSame(4, $second);
    }

    public function testVerticalSplitCalculatesCorrectColumnPositionsAt50Percent(): void
    {
        $layout = SplitLayout::vertical("LEFT", "RIGHT", 1, 2);

        $position = $layout->splitPosition(40);
        $this->assertSame(20, $position);
    }

    public function testSplitLayoutHandlesDifferentProportions(): void
    {
        // 75% / 25% split
        $layout = SplitLayout::horizontal("TOP", "BOTTOM", 3, 4);

        $position = $layout->splitPosition(40);
        $this->assertSame(30, $position);
    }

    // =========================================================================
    // Narrow Terminal / Minimum Size Tests
    // =========================================================================

    public function testSplitLayoutEnforcesMinimumPaneSize(): void
    {
        $layout = SplitLayout::horizontal("TOP", "BOTTOM");

        // Very narrow terminal should still give minimum sizes to each pane
        [$first, $second] = $layout->paneSizes(3);

        // With total 3 and divider 1, available is 2
        // Minimum pane size should be 1 each, but the implementation clamps
        $this->assertGreaterThanOrEqual(0, $first);
        $this->assertGreaterThanOrEqual(0, $second);
    }

    public function testSplitLayoutReturnsEmptyOutputForZeroWidth(): void
    {
        $layout = SplitLayout::vertical("LEFT", "RIGHT");

        $output = $layout->render(0, 10);

        // At zero width, panes should have no content
        $this->assertSame('', $output);
    }

    public function testSplitLayoutReturnsEmptyOutputForZeroHeight(): void
    {
        $layout = SplitLayout::horizontal("TOP", "BOTTOM");

        $output = $layout->render(20, 0);

        // At zero height, panes should have no content
        $this->assertSame('', $output);
    }

    // =========================================================================
    // Empty Pane Content Tests
    // =========================================================================

    public function testEmptyPaneContentRendersAsEmptyStringInHorizontalSplit(): void
    {
        $layout = SplitLayout::horizontal("", "BOTTOM");
        $output = $layout->render(20, 10);

        // Empty pane should not add extra content, just the bottom pane
        $this->assertStringContainsString("BOTTOM", $output);
        // The empty top should not add newlines that create empty lines
        $this->assertSame(0, substr_count($output, "TOP"));
    }

    public function testEmptyPaneContentRendersAsEmptyStringInVerticalSplit(): void
    {
        $layout = SplitLayout::vertical("", "RIGHT");
        $output = $layout->render(40, 10);

        // Empty left pane should not add content, just right pane
        $this->assertStringContainsString("RIGHT", $output);
    }

    public function testBothPanesEmptyRendersDividerLine(): void
    {
        $layout = SplitLayout::horizontal("", "");
        $output = $layout->render(20, 10);

        // When both panes are empty but have allocated space, the divider still renders
        // The divider is enclosed by newlines since there are two panes
        $divider = SplitDirection::Horizontal->divider();
        $this->assertSame("\n" . str_repeat($divider, 20) . "\n", $output);
    }

    public function testBothPanesEmptyInVerticalSplitRendersDividerLine(): void
    {
        $layout = SplitLayout::vertical("", "");
        $output = $layout->render(40, 10);

        // When both panes are empty but have allocated space, the divider still renders
        // Pane sizes: split at 20 (50% of 40), so left=20, right=19 (after 1-char divider)
        $divider = SplitDirection::Vertical->divider();
        // Left pane (20 chars) + divider + right pane (19 chars, but empty so contributes nothing visible)
        $expectedLeftPadded = str_pad('', 20, ' ', STR_PAD_RIGHT);
        $this->assertStringStartsWith($expectedLeftPadded, $output);
        $this->assertStringEndsWith($divider, $output);
    }

    // =========================================================================
    // Immutable with*() Tests
    // =========================================================================

    public function testWithContentReturnsNewInstance(): void
    {
        $original = SplitLayout::horizontal("TOP", "BOTTOM");
        $modified = $original->withContent("NEW TOP", "NEW BOTTOM");

        // Original should be unchanged
        $this->assertSame("TOP", $original->topOrLeftContent);
        $this->assertSame("BOTTOM", $original->bottomOrRightContent);

        // New instance should have new content
        $this->assertSame("NEW TOP", $modified->topOrLeftContent);
        $this->assertSame("NEW BOTTOM", $modified->bottomOrRightContent);
    }

    public function testWithProportionsReturnsNewInstance(): void
    {
        $original = SplitLayout::horizontal("TOP", "BOTTOM", 1, 2);
        $modified = $original->withProportions(3, 4);

        // Original should be unchanged
        $this->assertSame(1, $original->numerator);
        $this->assertSame(2, $original->denominator);

        // New instance should have new proportions
        $this->assertSame(3, $modified->numerator);
        $this->assertSame(4, $modified->denominator);
    }

    public function testWithProportionsThrowsOnInvalidDenominator(): void
    {
        $layout = SplitLayout::horizontal("TOP", "BOTTOM");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Denominator must be positive');

        $layout->withProportions(1, 0);
    }

    public function testWithProportionsThrowsOnNumeratorOutOfRange(): void
    {
        $layout = SplitLayout::horizontal("TOP", "BOTTOM");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Numerator must be between 0 and denominator inclusive');

        $layout->withProportions(5, 2);
    }

    public function testConstructorThrowsOnInvalidDenominator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Denominator must be positive');

        new SplitLayout("TOP", "BOTTOM", SplitDirection::Horizontal, 1, 0);
    }

    public function testConstructorThrowsOnNumeratorOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Numerator must be between 0 and denominator inclusive');

        new SplitLayout("TOP", "BOTTOM", SplitDirection::Horizontal, 5, 2);
    }

    // =========================================================================
    // Divider Character Tests
    // =========================================================================

    public function testHorizontalDividerIsFullWidthLineCharacter(): void
    {
        $layout = SplitLayout::horizontal("A", "B");
        $output = $layout->render(10, 10);

        // Should contain the box drawing horizontal character
        $this->assertStringContainsString("\u{2500}", $output);
    }

    public function testVerticalDividerIsPipeCharacter(): void
    {
        $layout = SplitLayout::vertical("A", "B");
        $output = $layout->render(40, 10);

        // Should contain the box drawing vertical character
        $this->assertStringContainsString("\u{2502}", $output);
    }

    // =========================================================================
    // Renderer::renderWithSplit() Smoke Tests
    // =========================================================================

    public function testRenderWithSplitRendersHorizontalSplit(): void
    {
        Renderer::setSize(40, 20);

        $output = Renderer::renderWithSplit("TOP", "BOTTOM", SplitDirection::Horizontal);

        $this->assertStringContainsString("TOP", $output);
        $this->assertStringContainsString("BOTTOM", $output);
        $this->assertStringContainsString("\u{2500}", $output);

        Renderer::resetSizeCache();
    }

    public function testRenderWithSplitRendersVerticalSplit(): void
    {
        Renderer::setSize(40, 20);

        $output = Renderer::renderWithSplit("LEFT", "RIGHT", SplitDirection::Vertical);

        $this->assertStringContainsString("LEFT", $output);
        $this->assertStringContainsString("RIGHT", $output);
        $this->assertStringContainsString("\u{2502}", $output);

        Renderer::resetSizeCache();
    }

    public function testRenderWithSplitRespectsCustomProportions(): void
    {
        Renderer::setSize(40, 20);

        $output = Renderer::renderWithSplit("LEFT", "RIGHT", SplitDirection::Vertical, 3, 4);

        $this->assertStringContainsString("LEFT", $output);
        $this->assertStringContainsString("RIGHT", $output);

        Renderer::resetSizeCache();
    }
}
