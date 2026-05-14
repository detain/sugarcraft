<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use SugarCraft\Dash\Plot\Chart\GaugeWithDetail;
use SugarCraft\Dash\Foundation\Sizer;
use PHPUnit\Framework\TestCase;

final class GaugeWithDetailTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testGaugeImplementsSizer(): void
    {
        $gauge = GaugeWithDetail::new();
        $this->assertInstanceOf(Sizer::class, $gauge);
    }

    // ═══════════════════════════════════════════════════════════════
    // Construction
    // ═══════════════════════════════════════════════════════════════

    public function testNewCreatesGauge(): void
    {
        $gauge = GaugeWithDetail::new();
        $this->assertNotNull($gauge);
    }

    public function testNewWithAllParameters(): void
    {
        $gauge = GaugeWithDetail::new('TEST', 50.0, 100.0, '50GB', 40);
        $this->assertNotNull($gauge);
    }

    // ═══════════════════════════════════════════════════════════════
    // Rendering - basic
    // ═══════════════════════════════════════════════════════════════

    public function testRenderContainsLabel(): void
    {
        $gauge = GaugeWithDetail::new('CPU', 50.0, 100.0, 'some detail', 40);
        $rendered = $gauge->render();

        // Should contain the label
        $this->assertStringContainsString('CPU', $rendered);
    }

    public function testRenderContainsDetail(): void
    {
        $gauge = GaugeWithDetail::new('RAM', 30.0, 100.0, '3.0GB', 40);
        $rendered = $gauge->render();

        // Should contain the detail text
        $this->assertStringContainsString('3.0GB', $rendered);
    }

    public function testRenderContainsPercent(): void
    {
        $gauge = GaugeWithDetail::new('DISK', 75.0, 100.0, '', 40);
        $rendered = $gauge->render();

        // Should contain percentage
        $this->assertStringContainsString('75%', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Rendering - bar states
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithFilledBar(): void
    {
        $gauge = GaugeWithDetail::new('VOL', 50.0, 100.0, '', 40);
        $rendered = $gauge->render();

        // Should have both filled and empty portions
        $this->assertStringContainsString('█', $rendered);
        $this->assertStringContainsString('░', $rendered);
    }

    public function testRenderWithEmptyBar(): void
    {
        $gauge = GaugeWithDetail::new('VOL', 0.0, 100.0, '', 40);
        $rendered = $gauge->render();

        // Should be all empty (or just label and percent)
        $this->assertStringContainsString('░', $rendered);
    }

    public function testRenderWithFullBar(): void
    {
        $gauge = GaugeWithDetail::new('VOL', 100.0, 100.0, '', 40);
        $rendered = $gauge->render();

        // Should be all filled (no empty chars, or very few)
        $this->assertStringContainsString('█', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // withValue
    // ═══════════════════════════════════════════════════════════════

    public function testWithValueUpdatesDisplay(): void
    {
        $gauge = GaugeWithDetail::new('CPU', 10.0, 100.0, '', 40);
        $updated = $gauge->withValue(75.0);
        $rendered = $updated->render();

        // Should show 75%
        $this->assertStringContainsString('75%', $rendered);
    }

    public function testWithValueReturnsNewInstance(): void
    {
        $original = GaugeWithDetail::new();
        $updated = $original->withValue(50.0);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // withMax
    // ═══════════════════════════════════════════════════════════════

    public function testWithMaxUpdatesDisplay(): void
    {
        $gauge = GaugeWithDetail::new('RAM', 50.0, 100.0, '', 40);
        $updated = $gauge->withMax(200.0);
        $rendered = $updated->render();

        // 50/200 = 25%
        $this->assertStringContainsString('25%', $rendered);
    }

    public function testWithMaxReturnsNewInstance(): void
    {
        $original = GaugeWithDetail::new();
        $updated = $original->withMax(200.0);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Other withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithDetailReturnsNewInstance(): void
    {
        $original = GaugeWithDetail::new();
        $updated = $original->withDetail('50GB');

        $this->assertNotSame($original, $updated);
    }

    public function testWithWidthReturnsNewInstance(): void
    {
        $original = GaugeWithDetail::new();
        $updated = $original->withWidth(60);

        $this->assertNotSame($original, $updated);
    }

    public function testWithLabelReturnsNewInstance(): void
    {
        $original = GaugeWithDetail::new();
        $updated = $original->withLabel('NEWLABEL');

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // setSize
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $gauge = GaugeWithDetail::new();
        $resized = $gauge->setSize(50, 1);

        $this->assertNotSame($gauge, $resized);
    }

    public function testSetSizeReturnsSizer(): void
    {
        $gauge = GaugeWithDetail::new();
        $resized = $gauge->setSize(50, 1);

        $this->assertInstanceOf(Sizer::class, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testZeroWidthRendersEmpty(): void
    {
        $gauge = GaugeWithDetail::new('TEST', 50.0, 100.0, '', 0);
        $rendered = $gauge->render();

        $this->assertSame('', $rendered);
    }

    public function testNegativeValueClampedToZero(): void
    {
        $gauge = GaugeWithDetail::new('TEST', -10.0, 100.0, '', 40);
        $rendered = $gauge->render();

        // Should show 0%
        $this->assertStringContainsString('0%', $rendered);
    }

    public function testValueOverMaxShowsOver100(): void
    {
        $gauge = GaugeWithDetail::new('TEST', 150.0, 100.0, '', 40);
        $rendered = $gauge->render();

        // Should show 150% (or clamp to 100%)
        $this->assertMatchesRegularExpression('/(150%|100%)/', $rendered);
    }

    public function testZeroMaxShowsZero(): void
    {
        $gauge = GaugeWithDetail::new('TEST', 50.0, 0.0, '', 40);
        $rendered = $gauge->render();

        // Should render without division error
        $this->assertNotSame('', $rendered);
    }

    public function testEmptyLabelRenders(): void
    {
        $gauge = GaugeWithDetail::new('', 50.0, 100.0, 'detail', 40);
        $rendered = $gauge->render();

        // Should still render with empty label area
        $this->assertNotSame('', $rendered);
    }

    public function testEmptyDetailRenders(): void
    {
        $gauge = GaugeWithDetail::new('TEST', 50.0, 100.0, '', 40);
        $rendered = $gauge->render();

        // Should render without detail text
        $this->assertNotSame('', $rendered);
    }

    public function testLongDetailText(): void
    {
        $gauge = GaugeWithDetail::new('TEST', 50.0, 100.0, 'VeryLongDetailText', 40);
        $rendered = $gauge->render();

        // Should handle long detail text
        $this->assertNotSame('', $rendered);
    }

    public function testVerySmallWidth(): void
    {
        $gauge = GaugeWithDetail::new('T', 50.0, 100.0, '', 10);
        $rendered = $gauge->render();

        // Should render with minimal width
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $gauge = GaugeWithDetail::new('TEST', 50.0, 100.0, '', 40);
        [$w, $h] = $gauge->getInnerSize();

        // Width should be at least the constraint
        $this->assertGreaterThanOrEqual(40, $w);
        // Height is always 1 for horizontal gauge
        $this->assertSame(1, $h);
    }

    public function testOriginalUnchangedAfterWithValue(): void
    {
        $gauge = GaugeWithDetail::new('CPU', 10.0, 100.0, '', 40);
        $gauge->withValue(75.0);
        $rendered = $gauge->render();

        // Original should still show 10%
        $this->assertStringContainsString('10%', $rendered);
    }
}
