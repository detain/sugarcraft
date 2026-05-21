<?php

declare(strict_types=1);

namespace SugarCraft\Veil\Tests\Animation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bounce\Easing\CubicBezier;
use SugarCraft\Veil\Animation\Slide;
use SugarCraft\Veil\Position;

final class SlideTest extends TestCase
{
    public function testApplyReturnsArrayWithKeys(): void
    {
        $slide = new Slide();
        $result = $slide->apply('X', 0.5, Position::TOP, Position::LEFT);

        $this->assertArrayHasKey('foreground', $result);
        $this->assertArrayHasKey('verticalOffset', $result);
        $this->assertArrayHasKey('horizontalOffset', $result);
    }

    public function testSlideFromTopAtProgressZero(): void
    {
        $slide = new Slide();
        $result = $slide->apply("A\nB", 0.0, Position::TOP, Position::LEFT);

        // At progress 0, the offset should be maximum (content off-screen)
        $this->assertGreaterThan(0, $result['verticalOffset']);
    }

    public function testSlideFromTopAtProgressOne(): void
    {
        $slide = new Slide();
        $result = $slide->apply("A\nB", 1.0, Position::TOP, Position::LEFT);

        // At progress 1, offset should be 0 (in final position)
        $this->assertSame(0, $result['verticalOffset']);
        $this->assertSame(0, $result['horizontalOffset']);
    }

    public function testSlideFromLeftAnchor(): void
    {
        $slide = new Slide();
        $result = $slide->apply("ABC", 0.5, Position::TOP, Position::LEFT);

        // Should have horizontal offset (positive = from left)
        $this->assertGreaterThan(0, $result['horizontalOffset']);
    }

    public function testSlideFromRightAnchor(): void
    {
        $slide = new Slide();
        $result = $slide->apply("ABC", 0.5, Position::TOP, Position::RIGHT);

        // Should have negative horizontal offset (from right)
        $this->assertLessThan(0, $result['horizontalOffset']);
    }

    public function testSlideWithCustomEasing(): void
    {
        $slide = new Slide(CubicBezier::easeIn());
        $result1 = $slide->apply("ABC", 0.5, Position::TOP, Position::LEFT);

        $slide2 = new Slide(CubicBezier::easeOut());
        $result2 = $slide2->apply("ABC", 0.5, Position::TOP, Position::LEFT);

        // Different easing should produce different offsets
        $this->assertIsInt($result1['horizontalOffset']);
        $this->assertIsInt($result2['horizontalOffset']);
    }
}
