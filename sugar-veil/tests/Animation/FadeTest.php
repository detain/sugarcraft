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
}
