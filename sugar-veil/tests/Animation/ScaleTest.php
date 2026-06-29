<?php

declare(strict_types=1);

namespace SugarCraft\Veil\Tests\Animation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bounce\Easing\CubicBezier;
use SugarCraft\Veil\Animation\Scale;

final class ScaleTest extends TestCase
{
    public function testApplyReturnsString(): void
    {
        $scale = new Scale();
        $result = $scale->apply('X', 0.5);

        $this->assertIsString($result);
    }

    public function testApplyAtProgressZeroReturnsEmpty(): void
    {
        $scale = new Scale();
        $result = $scale->apply("A\nB\nC", 0.0);

        $this->assertSame('', $result);
    }

    public function testApplyAtProgressOneReturnsFull(): void
    {
        $scale = new Scale();
        $result = $scale->apply("A\nB\nC", 1.0);

        $this->assertSame("A\nB\nC", $result);
    }

    public function testApplyAtMidProgressReturnsSubset(): void
    {
        $scale = new Scale();
        $result = $scale->apply("A\nB\nC\nD\nE", 0.5);
        $lines = \explode("\n", $result);

        // At 50% progress, should show fewer than all 5 lines
        $this->assertLessThan(5, \count($lines));
    }

    public function testApplyCenterExpandsOutward(): void
    {
        $scale = new Scale();

        $result50 = $scale->apply("A\nB\nC\nD\nE", 0.5);
        $result75 = $scale->apply("A\nB\nC\nD\nE", 0.75);

        // More progress = more lines visible
        $this->assertLessThanOrEqual(\count(\explode("\n", $result75)), \count(\explode("\n", $result50)));
    }

    public function testApplyWithCustomEasing(): void
    {
        $scale = new Scale(CubicBezier::easeIn());
        $result = $scale->apply("A\nB\nC", 0.5);

        $this->assertNotEmpty($result);
    }

    public function testApplyEmptyStringReturnsEmpty(): void
    {
        $scale = new Scale();
        $result = $scale->apply('', 0.5);

        $this->assertSame('', $result);
    }

    public function testApplySingleLine(): void
    {
        $scale = new Scale();
        $result = $scale->apply('X', 0.5);

        // Single line should always show at least 1 line at any progress > 0
        $this->assertNotEmpty($result);
    }

    public function testApplyUsesDefaultEasingWhenNoneProvided(): void
    {
        // When Scale is constructed without custom easing,
        // the private easing() method should return CubicBezier::easeOut()
        // This exercises the null-coalescing fallback in easing()
        $scale = new Scale();
        $result1 = $scale->apply("A\nB\nC", 0.3);
        $result2 = $scale->apply("A\nB\nC", 0.7);

        // Different progress values with default easing should produce different results
        $this->assertIsString($result1);
        $this->assertIsString($result2);
        // At 0.3 progress, fewer lines visible than at 0.7 progress
        $this->assertLessThanOrEqual(\count(\explode("\n", $result2)), \count(\explode("\n", $result1)));
    }

    /**
     * Step 12: Assert Scale center-line math at small progress.
     *
     * At Scale.php:64-66, startLine = floor(center - visibleCount/2), so at very
     * small progress (1 visible line) the startLine reveals the CENTER line first.
     * For 5 lines at small progress: startLine = floor(2.5 - 0.5) = 2 (0-indexed,
     * i.e. the 3rd line, which is the center of 5 lines).
     */
    public function testScaleAtSmallProgressShowsCenterLine(): void
    {
        $scale = new Scale();
        // 5-line content: indices 0,1,2,3,4. Center is index 2.
        // At very small progress (e.g. 0.01), only 1 line visible.
        // The startLine math places that 1 line at the center (index 2).
        $result = $scale->apply("A\nB\nC\nD\nE", 0.01);
        $lines = \explode("\n", $result);

        $this->assertCount(1, $lines, 'At very small progress, exactly 1 line should be visible');
        // The first (and only) visible line should be "C" (the center of A,B,C,D,E)
        $this->assertSame('C', $lines[0], 'At tiny progress, the center line should appear first');
    }

    public function testScaleAt50PercentProgressShowsCenteredLines(): void
    {
        $scale = new Scale();
        // 5 lines at 50% progress: easeOut(0.5) = 0.5, visibleCount = max(1, round(2.5)) = 3
        // center = 2.5, startLine = floor(2.5 - 1.5) = 1
        // So lines 1,2,3 are shown (B,C,D in 0-indexed)
        $result = $scale->apply("A\nB\nC\nD\nE", 0.5);
        $lines = \explode("\n", $result);

        $this->assertCount(3, $lines, 'At 50% progress, 3 lines should be visible');
        // Lines should be centered: B, C, D (indices 1, 2, 3)
        $this->assertSame('B', $lines[0]);
        $this->assertSame('C', $lines[1]);
        $this->assertSame('D', $lines[2]);
    }
}
