<?php

declare(strict_types=1);

namespace SugarCraft\Veil\Tests\Animation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bounce\Easing\CubicBezier;
use SugarCraft\Veil\Animation\Fade;

final class FadeTest extends TestCase
{
    public function testApplyReturnsString(): void
    {
        $fade = new Fade();
        $result = $fade->apply('X', 0.5);

        $this->assertIsString($result);
    }

    public function testApplyAtProgressZeroReturnsUnchanged(): void
    {
        $fade = new Fade();
        $result = $fade->apply('X', 0.0);

        $this->assertSame('X', $result);
    }

    public function testApplyAtProgressOneReturnsUnchanged(): void
    {
        $fade = new Fade();
        $result = $fade->apply('X', 1.0);

        $this->assertSame('X', $result);
    }

    public function testApplyAtMidProgressReturnsUnchanged(): void
    {
        $fade = new Fade();
        $result = $fade->apply('X', 0.5);

        // Fade returns foreground unchanged due to terminal limitations
        $this->assertSame('X', $result);
    }

    public function testApplyWithCustomEasing(): void
    {
        $fade = new Fade(CubicBezier::easeIn());
        $result = $fade->apply('X', 0.5);

        $this->assertStringContainsString('X', $result);
    }

    public function testApplyWithMultilineContent(): void
    {
        $fade = new Fade();
        $result = $fade->apply("A\nB\nC", 0.5);

        $this->assertStringContainsString('A', $result);
        $this->assertStringContainsString('B', $result);
        $this->assertStringContainsString('C', $result);
    }

    // ─── opacity() ───────────────────────────────────────────────────────────

    public function testOpacityAtProgressZero(): void
    {
        $fade = new Fade();
        $this->assertSame(0, $fade->opacity(0.0));
    }

    public function testOpacityAtProgressOne(): void
    {
        $fade = new Fade();
        $this->assertSame(100, $fade->opacity(1.0));
    }

    public function testOpacityAtMidProgress(): void
    {
        $fade = new Fade();
        // At mid-progress with default easeInOut, opacity should be somewhere between 0 and 100
        $opacity = $fade->opacity(0.5);
        $this->assertGreaterThan(0, $opacity);
        $this->assertLessThan(100, $opacity);
    }

    public function testOpacityAtNegativeProgressClampsToZero(): void
    {
        $fade = new Fade();
        $this->assertSame(0, $fade->opacity(-0.5));
    }

    public function testOpacityAtOversizedProgressClampsTo100(): void
    {
        $fade = new Fade();
        $this->assertSame(100, $fade->opacity(1.5));
    }

    public function testOpacityWithCustomEasing(): void
    {
        $fade = new Fade(CubicBezier::linear());
        // Linear easing at 0.5 progress gives 50% opacity
        $opacity = $fade->opacity(0.5);
        $this->assertSame(50, $opacity);
    }

    public function testOpacityIsMonotonic(): void
    {
        $fade = new Fade();
        // Opacity should always increase as progress increases
        $prev = 0;
        foreach ([0.0, 0.1, 0.25, 0.5, 0.75, 0.9, 1.0] as $progress) {
            $opacity = $fade->opacity($progress);
            $this->assertGreaterThanOrEqual($prev, $opacity);
            $prev = $opacity;
        }
    }
}
