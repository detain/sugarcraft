<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\ProgressRing;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ProgressRingTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testProgressRingImplementsSizer(): void
    {
        $ring = ProgressRing::new(0.5);
        $this->assertInstanceOf(Sizer::class, $ring);
    }

    public function testProgressRingImplementsItem(): void
    {
        $ring = ProgressRing::new(0.5);
        $this->assertInstanceOf(Item::class, $ring);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $ring = ProgressRing::new(0.5);
        $rendered = $ring->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsCircles(): void
    {
        $ring = ProgressRing::new(0.5);
        $rendered = $ring->render();

        // Should contain circle characters
        $this->assertMatchesRegularExpression('/[●○]/', $rendered);
    }

    public function testRenderContainsNewlines(): void
    {
        $ring = ProgressRing::new(0.5);
        $rendered = $ring->render();

        // Ring is multi-line
        $this->assertStringContainsString("\n", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ratio handling
    // ═══════════════════════════════════════════════════════════════

    public function testZeroRatio(): void
    {
        $ring = ProgressRing::new(0.0);
        $rendered = $ring->render();

        // Should have mostly empty circles
        $emptyCount = substr_count($rendered, '○');
        $this->assertGreaterThan(0, $emptyCount);
    }

    public function testFullRatio(): void
    {
        $ring = ProgressRing::new(1.0);
        $rendered = $ring->render();

        // Should have mostly filled circles
        $filledCount = substr_count($rendered, '●');
        $this->assertGreaterThan(0, $filledCount);
    }

    public function testHalfRatio(): void
    {
        $ring = ProgressRing::new(0.5);
        $rendered = $ring->render();

        // Should have both filled and empty
        $this->assertStringContainsString('●', $rendered);
        $this->assertStringContainsString('○', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ratio clamping
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeRatioClampedToZero(): void
    {
        $ring = new ProgressRing(-0.5, 4, true, null, null);
        $rendered = $ring->render();

        // Should render without errors
        $this->assertNotSame('', $rendered);
    }

    public function testOverOneRatioClampedToOne(): void
    {
        $ring = new ProgressRing(1.5, 4, true, null, null);
        $rendered = $ring->render();

        // Should render as full ring
        $emptyCount = substr_count($rendered, '○');
        $this->assertSame(0, $emptyCount);
    }

    // ═══════════════════════════════════════════════════════════════
    // Percentage display
    // ═══════════════════════════════════════════════════════════════

    public function testShowPercentageByDefault(): void
    {
        $ring = ProgressRing::new(0.75);
        $rendered = $ring->render();

        // Should contain "75%"
        $this->assertStringContainsString('75%', $rendered);
    }

    public function testHidePercentage(): void
    {
        $ring = ProgressRing::new(0.75)->withPercentage(false);
        $rendered = $ring->render();

        // Should NOT contain percentage
        $this->assertStringNotContainsString('%', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Radius
    // ═══════════════════════════════════════════════════════════════

    public function testDifferentRadii(): void
    {
        $ringSmall = ProgressRing::new(0.5)->withRadius(2);
        $ringLarge = ProgressRing::new(0.5)->withRadius(6);

        $renderedSmall = $ringSmall->render();
        $renderedLarge = $ringLarge->render();

        // Larger radius should produce more output
        $this->assertGreaterThan(
            strlen($renderedSmall),
            strlen($renderedLarge)
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testFilledColorAddsAnsiCodes(): void
    {
        $ring = ProgressRing::new(0.5)->withFilledColor(Color::ansi(9));
        $rendered = $ring->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $ring = ProgressRing::new(0.5)
            ->withFilledColor(Color::ansi(9))
            ->withEmptyColor(Color::ansi(8));
        $rendered = $ring->render();

        // Should end with reset code
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSize(): void
    {
        $ring = ProgressRing::new(0.5);
        [$w, $h] = $ring->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithPercentage(): void
    {
        $ringWithPct = ProgressRing::new(0.5)->withPercentage(true);
        $ringWithoutPct = ProgressRing::new(0.5)->withPercentage(false);

        [$wWith, $hWith] = $ringWithPct->getInnerSize();
        [$wWithout, $hWithout] = $ringWithoutPct->getInnerSize();

        // With percentage should be taller due to label
        $this->assertGreaterThan($hWithout, $hWith);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithRatioReturnsNewInstance(): void
    {
        $original = ProgressRing::new(0.25);
        $updated = $original->withRatio(0.75);

        $this->assertNotSame($original, $updated);
    }

    public function testWithRadiusReturnsNewInstance(): void
    {
        $original = ProgressRing::new(0.5);
        $updated = $original->withRadius(6);

        $this->assertNotSame($original, $updated);
    }

    public function testWithPercentageReturnsNewInstance(): void
    {
        $original = ProgressRing::new(0.5);
        $updated = $original->withPercentage(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithFilledColorReturnsNewInstance(): void
    {
        $original = ProgressRing::new(0.5);
        $updated = $original->withFilledColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testWithEmptyColorReturnsNewInstance(): void
    {
        $original = ProgressRing::new(0.5);
        $updated = $original->withEmptyColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = ProgressRing::new(0.5);
        $resized = $original->setSize(20, 20);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testMinimumRadius(): void
    {
        $ring = ProgressRing::new(0.5)->withRadius(1);
        $rendered = $ring->render();

        // Should still render
        $this->assertNotSame('', $rendered);
    }

    public function testExactHalfRatio(): void
    {
        $ring = ProgressRing::new(0.5)->withRadius(4);
        $rendered = $ring->render();

        // Should have both filled and empty circles
        $this->assertStringContainsString('●', $rendered);
        $this->assertStringContainsString('○', $rendered);
    }

    public function testHundredPercent(): void
    {
        $ring = ProgressRing::new(1.0)->withPercentage(true);
        $rendered = $ring->render();

        $this->assertStringContainsString('100%', $rendered);
    }

    public function testZeroPercent(): void
    {
        $ring = ProgressRing::new(0.0)->withPercentage(true);
        $rendered = $ring->render();

        $this->assertStringContainsString('0%', $rendered);
    }
}
