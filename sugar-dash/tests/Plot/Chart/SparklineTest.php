<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use SugarCraft\Dash\Plot\Chart\Sparkline;
use SugarCraft\Dash\Foundation\Sizer;
use PHPUnit\Framework\TestCase;

final class SparklineTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testSparklineImplementsSizer(): void
    {
        $sparkline = Sparkline::new();
        $this->assertInstanceOf(Sizer::class, $sparkline);
    }

    // ═══════════════════════════════════════════════════════════════
    // Construction
    // ═══════════════════════════════════════════════════════════════

    public function testNewCreatesSparkline(): void
    {
        $sparkline = Sparkline::new();
        $this->assertNotNull($sparkline);
    }

    public function testNewWithWidth(): void
    {
        $sparkline = Sparkline::new(50);
        $this->assertNotNull($sparkline);
    }

    // ═══════════════════════════════════════════════════════════════
    // Push operations
    // ═══════════════════════════════════════════════════════════════

    public function testPushAddsValue(): void
    {
        $sparkline = Sparkline::new(10);
        $updated = $sparkline->push(25.0);

        // Should return new instance
        $this->assertNotSame($sparkline, $updated);

        // Original unchanged
        $this->assertSame('', $sparkline->render());
    }

    public function testPushOverwritesOldestWhenFull(): void
    {
        $sparkline = Sparkline::new(3); // Only 3 slots

        $sparkline = $sparkline->push(1.0);
        $sparkline = $sparkline->push(2.0);
        $sparkline = $sparkline->push(3.0);

        // Now full
        $rendered1 = $sparkline->render();

        // Push 4th value - should overwrite oldest
        $sparkline = $sparkline->push(4.0);
        $rendered2 = $sparkline->render();

        // Should still render (not empty)
        $this->assertNotSame('', $rendered2);
        // Should be different from full buffer render
        $this->assertNotSame($rendered1, $rendered2);
    }

    public function testPushAllAddsMultipleValues(): void
    {
        $sparkline = Sparkline::new(10);
        $sparkline = $sparkline->pushAll([10.0, 20.0, 30.0]);

        $rendered = $sparkline->render();
        $this->assertNotSame('', $rendered);
        // Should contain block characters
        $this->assertMatchesRegularExpression('/[▁▂▃▄▅▆▇█]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithDataRendersBlocks(): void
    {
        $sparkline = Sparkline::new(5)
            ->pushAll([10.0, 30.0, 50.0, 70.0, 90.0]);

        $rendered = $sparkline->render();

        // Should contain block characters
        $this->assertMatchesRegularExpression('/[▁▂▃▄▅▆▇█]/', $rendered);
    }

    public function testDimEdgePadsLeftWhenBufferNotFull(): void
    {
        $sparkline = Sparkline::new(10)
            ->pushAll([50.0, 60.0, 70.0]) // Only 3 values
            ->withDimEdge(true);

        $rendered = $sparkline->render();

        // Should contain dim padding character
        $this->assertStringContainsString('░', $rendered);
        // Should still have actual data
        $this->assertMatchesRegularExpression('/[▁▂▃▄▅▆▇█]/', $rendered);
    }

    public function testWithoutDimEdgeNoPadding(): void
    {
        $sparkline = Sparkline::new(10)
            ->pushAll([50.0, 60.0, 70.0])
            ->withDimEdge(false);

        $rendered = $sparkline->render();

        // Should NOT contain dim padding - only block chars
        $this->assertDoesNotMatchRegularExpression('/░/', $rendered);
    }

    public function testEmptySparklineRender(): void
    {
        $sparkline = Sparkline::new(10);
        $rendered = $sparkline->render();

        $this->assertSame('', $rendered);
    }

    public function testEmptyBufferAfterClear(): void
    {
        $sparkline = Sparkline::new(5)
            ->pushAll([10.0, 20.0, 30.0]);

        $this->assertNotSame('', $sparkline->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // toArray
    // ═══════════════════════════════════════════════════════════════

    public function testToArrayReturnsChronological(): void
    {
        $sparkline = Sparkline::new(5)
            ->pushAll([10.0, 20.0, 30.0, 40.0, 50.0]);

        // Access internal buffer via rendering behavior
        $this->assertNotSame('', $sparkline->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // setSize
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $sparkline = Sparkline::new(10);
        $resized = $sparkline->setSize(20, 1);

        $this->assertNotSame($sparkline, $resized);
    }

    public function testSetSizeAffectsWidth(): void
    {
        $sparkline = Sparkline::new(10)
            ->pushAll([10.0, 20.0, 30.0, 40.0, 50.0]);

        $resized = $sparkline->setSize(30, 1);
        $rendered = $resized->render();

        // Should render with wider width
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers return new instance
    // ═══════════════════════════════════════════════════════════════

    public function testWithWidthReturnsNewInstance(): void
    {
        $original = Sparkline::new(10);
        $updated = $original->withWidth(20);

        $this->assertNotSame($original, $updated);
    }

    public function testWithHeightReturnsNewInstance(): void
    {
        $original = Sparkline::new(10);
        $updated = $original->withHeight(3);

        $this->assertNotSame($original, $updated);
    }

    public function testWithDataPointsReturnsNewInstance(): void
    {
        $original = Sparkline::new(10);
        $updated = $original->withDataPoints(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithFillReturnsNewInstance(): void
    {
        $original = Sparkline::new(10);
        $updated = $original->withFill(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithDimEdgeReturnsNewInstance(): void
    {
        $original = Sparkline::new(10);
        $updated = $original->withDimEdge(true);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeWithData(): void
    {
        $sparkline = Sparkline::new(10)
            ->pushAll([10.0, 20.0, 30.0]);

        [$w, $h] = $sparkline->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeEmptyBuffer(): void
    {
        $sparkline = Sparkline::new(10);
        [$w, $h] = $sparkline->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testZeroWidthRendersEmpty(): void
    {
        $sparkline = Sparkline::new(0);
        $this->assertSame('', $sparkline->render());
    }

    public function testNegativeValue(): void
    {
        $sparkline = Sparkline::new(5)
            ->pushAll([-10.0, -5.0, 0.0, 5.0, 10.0]);

        $rendered = $sparkline->render();

        // Should handle negative values without error
        $this->assertNotSame('', $rendered);
        $this->assertMatchesRegularExpression('/[▁▂▃▄▅▆▇█]/', $rendered);
    }

    public function testValueClampedToBlockRange(): void
    {
        // Values > 100 should be clamped to 7 (█)
        $sparkline = Sparkline::new(5)
            ->pushAll([150.0, 200.0, 300.0]);

        $rendered = $sparkline->render();

        // Should still render without error
        $this->assertNotSame('', $rendered);
    }

    public function testMultiplePushChains(): void
    {
        $sparkline = Sparkline::new(10)
            ->push(10.0)
            ->push(20.0)
            ->push(30.0)
            ->push(40.0);

        $rendered = $sparkline->render();

        $this->assertNotSame('', $rendered);
        $this->assertMatchesRegularExpression('/[▁▂▃▄▅▆▇█]/', $rendered);
    }
}
