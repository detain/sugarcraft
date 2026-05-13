<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Meter;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class MeterTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testMeterImplementsSizer(): void
    {
        $meter = Meter::new(0.5);
        $this->assertInstanceOf(Sizer::class, $meter);
    }

    public function testMeterImplementsItem(): void
    {
        $meter = Meter::new(0.5);
        $this->assertInstanceOf(Item::class, $meter);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $meter = Meter::new(0.5);
        $rendered = $meter->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsNewlines(): void
    {
        $meter = Meter::new(0.5);
        $rendered = $meter->render();

        // Meter is multi-line
        $this->assertStringContainsString("\n", $rendered);
    }

    public function testRenderContainsMeterCharacters(): void
    {
        $meter = Meter::new(0.5);
        $rendered = $meter->render();

        // Should contain meter-like characters
        $this->assertMatchesRegularExpression('/[█░│─❮·』●]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ratio handling
    // ═══════════════════════════════════════════════════════════════

    public function testZeroRatio(): void
    {
        $meter = Meter::new(0.0);
        $rendered = $meter->render();

        // Should have mostly empty bars
        $this->assertStringContainsString('░', $rendered);
    }

    public function testFullRatio(): void
    {
        $meter = Meter::new(1.0);
        $rendered = $meter->render();

        // Should have mostly filled bars
        $this->assertStringContainsString('█', $rendered);
    }

    public function testHalfRatio(): void
    {
        $meter = Meter::new(0.5);
        $rendered = $meter->render();

        // Should have both filled and empty
        $this->assertStringContainsString('█', $rendered);
        $this->assertStringContainsString('░', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ratio clamping
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeRatioClampedToZero(): void
    {
        $meter = new Meter(-0.5, 10, 5, true, true, true, null, null, null);
        $rendered = $meter->render();

        // Should render without errors
        $this->assertNotSame('', $rendered);
    }

    public function testOverOneRatioClampedToOne(): void
    {
        $meter = new Meter(1.5, 10, 5, true, true, true, null, null, null);
        $rendered = $meter->render();

        // Should render as full meter
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Label display
    // ═══════════════════════════════════════════════════════════════

    public function testShowLabelByDefault(): void
    {
        $meter = Meter::new(0.75);
        $rendered = $meter->render();

        // Should contain "75%"
        $this->assertStringContainsString('75%', $rendered);
    }

    public function testHideLabel(): void
    {
        $meter = Meter::new(0.75)->withShowLabel(false);
        $rendered = $meter->render();

        // Should NOT contain percentage
        $this->assertStringNotContainsString('%', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Needle display
    // ═══════════════════════════════════════════════════════════════

    public function testShowNeedleByDefault(): void
    {
        $meter = Meter::new(0.5);
        $rendered = $meter->render();

        // Should contain needle character
        $this->assertStringContainsString('❮', $rendered);
    }

    public function testHideNeedle(): void
    {
        $meter = Meter::new(0.5)->withShowNeedle(false);
        $rendered = $meter->render();

        // Should still render, just without needle
        $this->assertNotSame('', $rendered);
        $this->assertStringNotContainsString('❮', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Scale display
    // ═══════════════════════════════════════════════════════════════

    public function testShowScaleByDefault(): void
    {
        $meter = Meter::new(0.5);
        $rendered = $meter->render();

        // Should contain scale characters
        $this->assertMatchesRegularExpression('/[·』]/', $rendered);
    }

    public function testHideScale(): void
    {
        $meter = Meter::new(0.5)->withShowScale(false);
        $rendered = $meter->render();

        // Should still render without scale marks
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Dimensions
    // ═══════════════════════════════════════════════════════════════

    public function testDifferentHeights(): void
    {
        $meterSmall = Meter::new(0.5)->withHeight(8);
        $meterLarge = Meter::new(0.5)->withHeight(20);

        $renderedSmall = $meterSmall->render();
        $renderedLarge = $meterLarge->render();

        // Taller meter should produce more output
        $this->assertGreaterThan(
            strlen($renderedSmall),
            strlen($renderedLarge)
        );
    }

    public function testDifferentWidths(): void
    {
        $meterNarrow = Meter::new(0.5)->withWidth(3);
        $meterWide = Meter::new(0.5)->withWidth(7);

        $renderedNarrow = $meterNarrow->render();
        $renderedWide = $meterWide->render();

        // Wider meter should produce more output
        $this->assertGreaterThan(
            strlen($renderedNarrow),
            strlen($renderedWide)
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testMeterColorAddsAnsiCodes(): void
    {
        $meter = Meter::new(0.5)->withMeterColor(Color::ansi(9));
        $rendered = $meter->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testNeedleColorAddsAnsiCodes(): void
    {
        $meter = Meter::new(0.5)->withNeedleColor(Color::ansi(12));
        $rendered = $meter->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testScaleColorAddsAnsiCodes(): void
    {
        $meter = Meter::new(0.5)->withScaleColor(Color::ansi(8));
        $rendered = $meter->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSize(): void
    {
        $meter = Meter::new(0.5);
        [$w, $h] = $meter->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithLabel(): void
    {
        $meterWithLabel = Meter::new(0.5)->withShowLabel(true);
        $meterWithoutLabel = Meter::new(0.5)->withShowLabel(false);

        [$wWith, $hWith] = $meterWithLabel->getInnerSize();
        [$wWithout, $hWithout] = $meterWithoutLabel->getInnerSize();

        // With label should be taller
        $this->assertGreaterThan($hWithout, $hWith);
    }

    public function testGetInnerSizeWithDifferentHeights(): void
    {
        $meterShort = Meter::new(0.5)->withHeight(8);
        $meterTall = Meter::new(0.5)->withHeight(16);

        [$wShort, $hShort] = $meterShort->getInnerSize();
        [$wTall, $hTall] = $meterTall->getInnerSize();

        // Taller meter should have greater height
        $this->assertGreaterThan($hShort, $hTall);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithRatioReturnsNewInstance(): void
    {
        $original = Meter::new(0.25);
        $updated = $original->withRatio(0.75);

        $this->assertNotSame($original, $updated);
    }

    public function testWithHeightReturnsNewInstance(): void
    {
        $original = Meter::new(0.5);
        $updated = $original->withHeight(20);

        $this->assertNotSame($original, $updated);
    }

    public function testWithWidthReturnsNewInstance(): void
    {
        $original = Meter::new(0.5);
        $updated = $original->withWidth(7);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowNeedleReturnsNewInstance(): void
    {
        $original = Meter::new(0.5);
        $updated = $original->withShowNeedle(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowScaleReturnsNewInstance(): void
    {
        $original = Meter::new(0.5);
        $updated = $original->withShowScale(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowLabelReturnsNewInstance(): void
    {
        $original = Meter::new(0.5);
        $updated = $original->withShowLabel(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithMeterColorReturnsNewInstance(): void
    {
        $original = Meter::new(0.5);
        $updated = $original->withMeterColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testWithNeedleColorReturnsNewInstance(): void
    {
        $original = Meter::new(0.5);
        $updated = $original->withNeedleColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testWithScaleColorReturnsNewInstance(): void
    {
        $original = Meter::new(0.5);
        $updated = $original->withScaleColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Meter::new(0.5);
        $resized = $original->setSize(10, 15);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testMinimumHeight(): void
    {
        $meter = Meter::new(0.5)->withHeight(5);
        $rendered = $meter->render();

        // Should still render
        $this->assertNotSame('', $rendered);
    }

    public function testMinimumWidth(): void
    {
        $meter = Meter::new(0.5)->withWidth(3);
        $rendered = $meter->render();

        // Should still render
        $this->assertNotSame('', $rendered);
    }

    public function testHundredPercent(): void
    {
        $meter = Meter::new(1.0)->withShowLabel(true);
        $rendered = $meter->render();

        $this->assertStringContainsString('100%', $rendered);
    }

    public function testZeroPercent(): void
    {
        $meter = Meter::new(0.0)->withShowLabel(true);
        $rendered = $meter->render();

        $this->assertStringContainsString('0%', $rendered);
    }

    public function testExactHalfRatio(): void
    {
        $meter = Meter::new(0.5)->withHeight(12);
        $rendered = $meter->render();

        // Should have both filled and empty bars
        $this->assertStringContainsString('█', $rendered);
        $this->assertStringContainsString('░', $rendered);
    }
}
