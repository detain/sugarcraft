<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plot\Chart\Sparkline;
use SugarCraft\Dash\Plot\Chart\GaugeWithDetail;

/**
 * Snapshot-style tests for Plot chart components.
 * Verifies that renderers produce non-empty output without errors.
 */
final class PlotSnapshotTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Sparkline
    // ═══════════════════════════════════════════════════════════════

    /**
     * Test: Sparkline with data renders without error.
     */
    public function testSparklineRendersWithoutError(): void
    {
        $sparkline = Sparkline::new(40)
            ->pushAll([1.0, 2.0, 3.0, 2.0, 1.0]);

        $output = $sparkline->render();

        $this->assertNotEmpty($output);
        $this->assertIsString($output);
    }

    /**
     * Test: Sparkline with setSize returns new instance.
     */
    public function testSparklineSetSize(): void
    {
        $sparkline = Sparkline::new(20)
            ->pushAll([1.0, 2.0, 3.0, 2.0, 1.0]);

        $resized = $sparkline->setSize(40, 10);

        // Should be a different instance
        $this->assertNotSame($sparkline, $resized);

        // Original should be unchanged
        $this->assertNotEmpty($resized->render());
    }

    /**
     * Test: Sparkline produces block characters.
     */
    public function testSparklineProducesBlockCharacters(): void
    {
        $sparkline = Sparkline::new(10)
            ->pushAll([10.0, 30.0, 50.0, 70.0, 90.0]);

        $rendered = $sparkline->render();

        // Should contain block characters (Unicode 2580-259F)
        $this->assertMatchesRegularExpression('/[▁▂▃▄▅▆▇█]/', $rendered);
    }

    /**
     * Test: Sparkline with fill mode.
     */
    public function testSparklineWithFill(): void
    {
        $sparkline = Sparkline::new(10)
            ->pushAll([10.0, 30.0, 50.0, 70.0, 90.0])
            ->withFill(true);

        $rendered = $sparkline->render();

        $this->assertNotEmpty($rendered);
    }

    /**
     * Test: Sparkline with data points.
     */
    public function testSparklineWithDataPoints(): void
    {
        $sparkline = Sparkline::new(10)
            ->pushAll([10.0, 30.0, 50.0, 70.0, 90.0])
            ->withDataPoints(true);

        $rendered = $sparkline->render();

        $this->assertNotEmpty($rendered);
    }

    /**
     * Test: Empty Sparkline renders as empty string.
     */
    public function testSparklineEmptyRendersEmpty(): void
    {
        $sparkline = Sparkline::new(10);

        $this->assertSame('', $sparkline->render());
    }

    /**
     * Test: Sparkline handles negative values.
     */
    public function testSparklineHandlesNegativeValues(): void
    {
        $sparkline = Sparkline::new(10)
            ->pushAll([-10.0, -5.0, 0.0, 5.0, 10.0]);

        $rendered = $sparkline->render();

        $this->assertNotEmpty($rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // GaugeWithDetail
    // ═══════════════════════════════════════════════════════════════

    /**
     * Test: GaugeWithDetail renders without error.
     */
    public function testGaugeWithDetailRendersWithoutError(): void
    {
        $gauge = GaugeWithDetail::new('CPU', 75.0, 100.0, '75GB', 40);
        $output = $gauge->render();

        $this->assertNotEmpty($output);
        $this->assertIsString($output);
    }

    /**
     * Test: GaugeWithDetail contains label.
     */
    public function testGaugeWithDetailContainsLabel(): void
    {
        $gauge = GaugeWithDetail::new('CPU', 75.0, 100.0, '75GB', 40);
        $output = $gauge->render();

        $this->assertStringContainsString('CPU', $output);
    }

    /**
     * Test: GaugeWithDetail with setSize.
     */
    public function testGaugeWithDetailSetSize(): void
    {
        $gauge = GaugeWithDetail::new('CPU', 75.0, 100.0, '75GB', 40);
        $resized = $gauge->setSize(60, 6);

        // Should be a different instance
        $this->assertNotSame($gauge, $resized);

        $output = $resized->render();
        $this->assertNotEmpty($output);
    }

    /**
     * Test: GaugeWithDetail at 0% renders correctly.
     */
    public function testGaugeWithDetailZeroPercent(): void
    {
        $gauge = GaugeWithDetail::new('ZERO', 0.0, 100.0, '0GB', 40);
        $output = $gauge->render();

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('ZERO', $output);
    }

    /**
     * Test: GaugeWithDetail at 100% renders correctly.
     */
    public function testGaugeWithDetailFullPercent(): void
    {
        $gauge = GaugeWithDetail::new('FULL', 100.0, 100.0, '100GB', 40);
        $output = $gauge->render();

        $this->assertNotEmpty($output);
    }

    /**
     * Test: GaugeWithDetail with small width.
     */
    public function testGaugeWithDetailSmallWidth(): void
    {
        $gauge = GaugeWithDetail::new('X', 50.0, 100.0, '5', 10);
        $output = $gauge->render();

        $this->assertNotEmpty($output);
    }

    /**
     * Test: GaugeWithDetail with large detail text.
     */
    public function testGaugeWithDetailLargeDetail(): void
    {
        $gauge = GaugeWithDetail::new('DISK', 75.0, 100.0, 'verylongdetailtext', 60);
        $output = $gauge->render();

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('DISK', $output);
    }

    /**
     * Test: GaugeWithDetail with zero max clamps to 0.
     */
    public function testGaugeWithDetailZeroMax(): void
    {
        $gauge = GaugeWithDetail::new('ZERO', 50.0, 0.0, 'N/A', 40);
        $output = $gauge->render();

        // Should handle zero max without division by zero
        $this->assertNotEmpty($output);
    }
}
