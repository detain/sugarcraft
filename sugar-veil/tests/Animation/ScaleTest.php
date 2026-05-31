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
}
