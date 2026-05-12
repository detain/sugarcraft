<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\NProgress;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class NProgressTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testNProgressImplementsSizer(): void
    {
        $nprogress = NProgress::new(0.5);
        $this->assertInstanceOf(Sizer::class, $nprogress);
    }

    public function testNProgressImplementsItem(): void
    {
        $nprogress = NProgress::new(0.5);
        $this->assertInstanceOf(Item::class, $nprogress);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $nprogress = NProgress::new(0.5);
        $rendered = $nprogress->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsFilledChars(): void
    {
        $nprogress = NProgress::new(0.5);
        $rendered = $nprogress->render();

        $this->assertStringContainsString('█', $rendered);
    }

    public function testRenderContainsEmptyChars(): void
    {
        $nprogress = NProgress::new(0.5);
        $rendered = $nprogress->render();

        $this->assertStringContainsString('░', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ratio handling
    // ═══════════════════════════════════════════════════════════════

    public function testZeroRatio(): void
    {
        $nprogress = NProgress::new(0.0);
        $rendered = $nprogress->render();

        // Should only have empty chars
        $this->assertStringContainsString('░', $rendered);
    }

    public function testFullRatio(): void
    {
        $nprogress = NProgress::new(1.0);
        $rendered = $nprogress->render();

        // Should have filled chars and possibly no empty chars
        $this->assertStringContainsString('█', $rendered);
    }

    public function testRatioClampedAboveOne(): void
    {
        $nprogress = NProgress::new(1.5);
        $rendered = $nprogress->render();

        // Should clamp to 100% - full bar
        $this->assertStringContainsString('█', $rendered);
    }

    public function testRatioClampedBelowZero(): void
    {
        $nprogress = NProgress::new(-0.5);
        $rendered = $nprogress->render();

        // Should clamp to 0% - empty bar
        $this->assertDoesNotMatchRegularExpression('/█/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Loading (indeterminate) state
    // ═══════════════════════════════════════════════════════════════

    public function testLoadingFactory(): void
    {
        $nprogress = NProgress::loading();
        $rendered = $nprogress->render();

        $this->assertNotSame('', $rendered);
    }

    public function testLoadingHasAnimationChars(): void
    {
        $nprogress = NProgress::loading();
        $rendered = $nprogress->render();

        // Should have both filled and empty for indeterminate
        $this->assertStringContainsString('█', $rendered);
        $this->assertStringContainsString('░', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $nprogress = NProgress::new(0.5)
            ->withColor(Color::ansi(9));
        $rendered = $nprogress->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTrackColorAddsAnsiCodes(): void
    {
        $nprogress = NProgress::new(0.5)
            ->withTrackColor(Color::ansi(8));
        $rendered = $nprogress->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $nprogress = NProgress::new(0.5)
            ->withColor(Color::ansi(9))
            ->withTrackColor(Color::ansi(8));
        $rendered = $nprogress->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Percentage display
    // ═══════════════════════════════════════════════════════════════

    public function testShowPercentage(): void
    {
        $nprogress = NProgress::new(0.75)->withShowPercentage(true);
        $rendered = $nprogress->render();

        $this->assertStringContainsString('%', $rendered);
    }

    public function testHidePercentage(): void
    {
        $nprogress = NProgress::new(0.75)->withShowPercentage(false);
        $rendered = $nprogress->render();

        $this->assertStringNotContainsString('%', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = NProgress::new(0.5);
        $resized = $original->setSize(50, 1);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocation(): void
    {
        $nprogress = NProgress::new(0.5)->setSize(60, 1);
        $rendered = $nprogress->render();

        // Should respect allocated width
        $this->assertGreaterThan(50, mb_strlen($rendered, 'UTF-8'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithRatioReturnsNewInstance(): void
    {
        $original = NProgress::new(0.3);
        $updated = $original->withRatio(0.8);

        $this->assertNotSame($original, $updated);
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $original = NProgress::new(0.5);
        $updated = $original->withColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithTrackColorReturnsNewInstance(): void
    {
        $original = NProgress::new(0.5);
        $updated = $original->withTrackColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowPercentageReturnsNewInstance(): void
    {
        $original = NProgress::new(0.5);
        $updated = $original->withShowPercentage(true);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithRatio(): void
    {
        $original = NProgress::new(0.3);
        $original->withRatio(0.8);
        $rendered = $original->render();

        // Original should still have 30% bar
        $this->assertStringContainsString('░', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $nprogress = NProgress::new(0.5);
        [$w, $h] = $nprogress->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithWidthAllocation(): void
    {
        $nprogress = NProgress::new(0.5)->setSize(80, 1);
        [$w, ] = $nprogress->getInnerSize();

        $this->assertGreaterThanOrEqual(80, $w);
    }

    public function testGetInnerSizeWithPercentageShowsExtraWidth(): void
    {
        $nprogress = NProgress::new(0.5)->withShowPercentage(true);
        [$w, ] = $nprogress->getInnerSize();

        // Should include percentage width
        $this->assertGreaterThan(40, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVerySmallRatio(): void
    {
        $nprogress = NProgress::new(0.01);
        $rendered = $nprogress->render();

        // Should still render something
        $this->assertNotSame('', $rendered);
    }

    public function testVeryLargeRatio(): void
    {
        $nprogress = NProgress::new(0.99);
        $rendered = $nprogress->render();

        // Should render mostly filled
        $this->assertStringContainsString('█', $rendered);
    }
}
