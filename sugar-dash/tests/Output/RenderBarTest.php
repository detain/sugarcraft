<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Output;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Output\RenderBar;

/**
 * Tests for the progress bar renderer.
 */
final class RenderBarTest extends TestCase
{
    public function testRenderZeroWidthReturnsEmpty(): void
    {
        $result = RenderBar::render(0.5, 0);
        $this->assertSame('', $result);
    }

    public function testRenderNegativeWidthReturnsEmpty(): void
    {
        $result = RenderBar::render(0.5, -5);
        $this->assertSame('', $result);
    }

    public function testRenderZeroPercentageIsAllEmpty(): void
    {
        $result = RenderBar::render(0.0, 10);
        $this->assertSame(str_repeat('░', 10), $result);
    }

    public function testRenderFullPercentageIsAllFilled(): void
    {
        $result = RenderBar::render(1.0, 10);
        $this->assertSame(str_repeat('█', 10), $result);
    }

    public function testRenderHalfPercentage(): void
    {
        $result = RenderBar::render(0.5, 10);
        $this->assertSame(str_repeat('█', 5) . str_repeat('░', 5), $result);
    }

    public function testRenderClampsPercentageToOne(): void
    {
        $result = RenderBar::render(1.5, 10);
        $this->assertSame(str_repeat('█', 10), $result);
    }

    public function testRenderClampsPercentageToZero(): void
    {
        $result = RenderBar::render(-0.5, 10);
        $this->assertSame(str_repeat('░', 10), $result);
    }

    public function testRenderCustomCharacters(): void
    {
        $result = RenderBar::render(0.5, 10, '|', '-');
        $this->assertSame(str_repeat('|', 5) . str_repeat('-', 5), $result);
    }

    public function testRenderWithColorAddsAnsiCodes(): void
    {
        $result = RenderBar::render(1.0, 5, '█', '░', Color::hex('#FF0000'));
        // Should contain ANSI escape codes
        $this->assertStringContainsString("\x1b", $result);
        $this->assertSame(5, mb_strlen(preg_replace('/\x1b\[[\d;]+m/', '', $result)));
    }

    public function testRenderWithColorOnlyOnFilled(): void
    {
        $result = RenderBar::render(0.0, 5, '█', '░', Color::hex('#00FF00'));
        // Zero filled — should not contain color codes for filled portion
        // Only empty portion is rendered (no ANSI for empty since emptyColor is null)
        $this->assertSame(str_repeat('░', 5), $result);
    }

    public function testRenderSegmentedZeroWidthReturnsEmpty(): void
    {
        $result = RenderBar::renderSegmented(0.5, 0);
        $this->assertSame('', $result);
    }

    public function testRenderSegmentedReturnsString(): void
    {
        $result = RenderBar::renderSegmented(0.5, 10);
        $this->assertIsString($result);
    }

    public function testRenderSegmentedClampsPercentage(): void
    {
        // percentage 1.5 is clamped to 1.0, which should render all-█
        $result = RenderBar::renderSegmented(1.5, 5);
        // All 5 cells should be filled (blockIndex=8 = █ for every cell)
        $this->assertSame(5, preg_match_all('/█/', $result));
    }

    public function testRenderSegmentedContainsBlockCharacters(): void
    {
        $result = RenderBar::renderSegmented(0.75, 10);
        // Should contain block characters from the set
        $this->assertMatchesRegularExpression('/^[▕▎▍▌▋▊▉█]+$/', $result);
    }

    public function testRenderSegmentedWithColor(): void
    {
        $result = RenderBar::renderSegmented(0.5, 5, Color::hex('#0000FF'));
        $this->assertStringContainsString("\x1b", $result);
    }
}
