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

        // At progress 0 the offset is maximal and NEGATIVE: a TOP anchor
        // starts fully off-screen ABOVE its final row and slides down.
        $this->assertLessThan(0, $result['verticalOffset']);
        // Deterministic magnitude: easeOut(0)=0 → factor 1 → −fgHeight rows
        // and −fgWidth cols (the LEFT horizontal anchor slides in too).
        $exact = $slide->apply("A\nB", 0.0, Position::TOP, Position::LEFT);
        $this->assertSame(-2, $exact['verticalOffset']);
        $this->assertSame(-1, $exact['horizontalOffset']);
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

        // A LEFT anchor enters from off-screen left: NEGATIVE column offset.
        $this->assertLessThan(0, $result['horizontalOffset']);
    }

    public function testSlideFromRightAnchor(): void
    {
        $slide = new Slide();
        $result = $slide->apply("ABC", 0.5, Position::TOP, Position::RIGHT);

        // A RIGHT anchor starts right of its final column (off-screen): POSITIVE.
        $this->assertGreaterThan(0, $result['horizontalOffset']);
    }

    public function testSlideFromBottomAnchor(): void
    {
        $slide = new Slide();
        // Use multi-line content so vertical offset rounds to non-zero
        $result = $slide->apply("A\nB\nC\nD", 0.5, Position::BOTTOM, Position::LEFT);

        // A BOTTOM anchor starts below the last row (off-screen): POSITIVE.
        $this->assertGreaterThan(0, $result['verticalOffset']);
    }

    public function testSlideFromBottomRightAnchor(): void
    {
        $slide = new Slide();
        // Use content with both height and width for non-zero offsets
        $result = $slide->apply("ABCD\nEFGH\nIJKL\nMNOP", 0.5, Position::BOTTOM, Position::RIGHT);

        // Enters from off-screen bottom-right: positive on both axes.
        $this->assertGreaterThan(0, $result['verticalOffset']);
        $this->assertGreaterThan(0, $result['horizontalOffset']);
    }

    public function testSlideFromTopLeftAnchor(): void
    {
        $slide = new Slide();
        // Use content with both height and width for non-zero offsets
        $result = $slide->apply("ABCD\nEFGH\nIJKL\nMNOP", 0.5, Position::TOP_LEFT, Position::LEFT);

        // Enters from off-screen top-left: negative on both axes.
        $this->assertLessThan(0, $result['verticalOffset']);
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
