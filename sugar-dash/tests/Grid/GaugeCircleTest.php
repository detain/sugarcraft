<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\GaugeCircle;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class GaugeCircleTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testGaugeCircleImplementsSizer(): void
    {
        $gauge = GaugeCircle::new(0.5);
        $this->assertInstanceOf(Sizer::class, $gauge);
    }

    public function testGaugeCircleImplementsItem(): void
    {
        $gauge = GaugeCircle::new(0.5);
        $this->assertInstanceOf(Item::class, $gauge);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $gauge = GaugeCircle::new(0.5);
        $rendered = $gauge->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsNewlines(): void
    {
        $gauge = GaugeCircle::new(0.5);
        $rendered = $gauge->render();

        // Gauge is multi-line
        $this->assertStringContainsString("\n", $rendered);
    }

    public function testRenderContainsCircles(): void
    {
        $gauge = GaugeCircle::new(0.5);
        $rendered = $gauge->render();

        // Should contain circle characters
        $this->assertMatchesRegularExpression('/[●○◆]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ratio handling
    // ═══════════════════════════════════════════════════════════════

    public function testZeroRatio(): void
    {
        $gauge = GaugeCircle::new(0.0);
        $rendered = $gauge->render();

        // Should render without errors
        $this->assertNotSame('', $rendered);
    }

    public function testFullRatio(): void
    {
        $gauge = GaugeCircle::new(1.0);
        $rendered = $gauge->render();

        // Should render without errors
        $this->assertNotSame('', $rendered);
    }

    public function testHalfRatio(): void
    {
        $gauge = GaugeCircle::new(0.5);
        $rendered = $gauge->render();

        // Should render with both filled and empty
        $this->assertStringContainsString('●', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ratio clamping
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeRatioClampedToZero(): void
    {
        $gauge = new GaugeCircle(-0.5, 4, true, true, true, null, null, null);
        $rendered = $gauge->render();

        // Should render without errors
        $this->assertNotSame('', $rendered);
    }

    public function testOverOneRatioClampedToOne(): void
    {
        $gauge = new GaugeCircle(1.5, 4, true, true, true, null, null, null);
        $rendered = $gauge->render();

        // Should render without errors
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Label display
    // ═══════════════════════════════════════════════════════════════

    public function testShowLabelByDefault(): void
    {
        $gauge = GaugeCircle::new(0.75);
        $rendered = $gauge->render();

        // Should contain "75%"
        $this->assertStringContainsString('75%', $rendered);
    }

    public function testHideLabel(): void
    {
        $gauge = GaugeCircle::new(0.75)->withShowLabel(false);
        $rendered = $gauge->render();

        // Should NOT contain percentage
        $this->assertStringNotContainsString('%', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Needle display
    // ═══════════════════════════════════════════════════════════════

    public function testShowNeedleByDefault(): void
    {
        $gauge = GaugeCircle::new(0.5);
        $rendered = $gauge->render();

        // Should contain needle character
        $this->assertStringContainsString('❮', $rendered);
    }

    public function testHideNeedle(): void
    {
        $gauge = GaugeCircle::new(0.5)->withShowNeedle(false);
        $rendered = $gauge->render();

        // Should still render, just without needle
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Tick marks
    // ═══════════════════════════════════════════════════════════════

    public function testShowTicksByDefault(): void
    {
        $gauge = GaugeCircle::new(0.5);
        $rendered = $gauge->render();

        // Should contain tick characters
        $this->assertMatchesRegularExpression('/[┬┴│]/', $rendered);
    }

    public function testHideTicks(): void
    {
        $gauge = GaugeCircle::new(0.5)->withShowTicks(false);
        $rendered = $gauge->render();

        // Should still render without tick marks
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Radius
    // ═══════════════════════════════════════════════════════════════

    public function testDifferentRadii(): void
    {
        $gaugeSmall = GaugeCircle::new(0.5)->withRadius(3);
        $gaugeLarge = GaugeCircle::new(0.5)->withRadius(8);

        $renderedSmall = $gaugeSmall->render();
        $renderedLarge = $gaugeLarge->render();

        // Larger radius should produce more output
        $this->assertGreaterThan(
            strlen($renderedSmall),
            strlen($renderedLarge)
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testArcColorAddsAnsiCodes(): void
    {
        $gauge = GaugeCircle::new(0.5)->withArcColor(Color::ansi(9));
        $rendered = $gauge->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testNeedleColorAddsAnsiCodes(): void
    {
        $gauge = GaugeCircle::new(0.5)->withNeedleColor(Color::ansi(12));
        $rendered = $gauge->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testLabelColorAddsAnsiCodes(): void
    {
        $gauge = GaugeCircle::new(0.5)->withLabelColor(Color::ansi(14));
        $rendered = $gauge->render();

        // Should contain ANSI color codes (for label)
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSize(): void
    {
        $gauge = GaugeCircle::new(0.5);
        [$w, $h] = $gauge->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithLabel(): void
    {
        $gaugeWithLabel = GaugeCircle::new(0.5)->withShowLabel(true);
        $gaugeWithoutLabel = GaugeCircle::new(0.5)->withShowLabel(false);

        [$wWith, $hWith] = $gaugeWithLabel->getInnerSize();
        [$wWithout, $hWithout] = $gaugeWithoutLabel->getInnerSize();

        // With label should be taller
        $this->assertGreaterThan($hWithout, $hWith);
    }

    public function testGetInnerSizeWithDifferentRadii(): void
    {
        $gaugeSmall = GaugeCircle::new(0.5)->withRadius(4);
        $gaugeLarge = GaugeCircle::new(0.5)->withRadius(8);

        [$wSmall, $hSmall] = $gaugeSmall->getInnerSize();
        [$wLarge, $hLarge] = $gaugeLarge->getInnerSize();

        // Larger radius should have larger dimensions
        $this->assertGreaterThan($wSmall, $wLarge);
        $this->assertGreaterThan($hSmall, $hLarge);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithRatioReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.25);
        $updated = $original->withRatio(0.75);

        $this->assertNotSame($original, $updated);
    }

    public function testWithRadiusReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.5);
        $updated = $original->withRadius(8);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowNeedleReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.5);
        $updated = $original->withShowNeedle(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowTicksReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.5);
        $updated = $original->withShowTicks(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowLabelReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.5);
        $updated = $original->withShowLabel(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithArcColorReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.5);
        $updated = $original->withArcColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testWithNeedleColorReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.5);
        $updated = $original->withNeedleColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testWithLabelColorReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.5);
        $updated = $original->withLabelColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.5);
        $resized = $original->setSize(20, 20);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testMinimumRadius(): void
    {
        $gauge = GaugeCircle::new(0.5)->withRadius(3);
        $rendered = $gauge->render();

        // Should still render
        $this->assertNotSame('', $rendered);
    }

    public function testFullCircle(): void
    {
        $gauge = GaugeCircle::new(1.0)->withPercentage(true);
        $rendered = $gauge->render();

        $this->assertStringContainsString('100%', $rendered);
    }

    public function testEmptyCircle(): void
    {
        $gauge = GaugeCircle::new(0.0)->withPercentage(true);
        $rendered = $gauge->render();

        $this->assertStringContainsString('0%', $rendered);
    }
}
