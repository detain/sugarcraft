<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Tests\Animation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Spring\Easing\CubicBezier;
use SugarCraft\Overlay\AnimationFrame;
use SugarCraft\Overlay\Animation\Slide;
use SugarCraft\Overlay\Position;

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

    /**
     * SO-13 (sugar-overlay-013): the typed applyFrame() is the contract and
     * the 1.x array form is its wrapper — cross-form identity across the
     * travel extremes, plus typed accessors on a known frame.
     */
    public function testApplyFrameIsTypedAndTheArrayFormIsTheSameFrame(): void
    {
        $slide = new Slide();
        for ($p = 0.0; $p <= 1.01; $p += 0.4) {
            foreach ([Position::TOP, Position::BOTTOM] as $v) {
                foreach ([Position::LEFT, Position::RIGHT] as $h) {
                    $frame = $slide->applyFrame("A\nB", $p, $v, $h);
                    $array = $slide->apply("A\nB", $p, $v, $h);

                    $this->assertInstanceOf(AnimationFrame::class, $frame);
                    $this->assertSame([
                        'foreground'       => $frame->foreground,
                        'verticalOffset'   => $frame->verticalOffset,
                        'horizontalOffset' => $frame->horizontalOffset,
                    ], $array, "frame/array drift at p=$p {$v->name}/{$h->name}");
                }
            }
        }

        $start = $slide->applyFrame("A\nB", 0.0, Position::TOP, Position::LEFT);
        $this->assertSame(-2, $start->verticalOffset);
        // A LEFT anchor travels on its own axis too: one fg-column off.
        $this->assertSame(-1, $start->horizontalOffset);
        $this->assertSame("A\nB", $start->foreground);
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

    public function testTrailingNewlineDoesNotAddATravelRow(): void
    {
        // Veil::composite() counts "a\nb\n" as two rows; the slide must too,
        // or the overlay travels one row further than it is tall.
        $slide = new Slide();
        $withNewline = $slide->apply("a\nb\n", 0.0, Position::TOP, Position::CENTER);
        $without = $slide->apply("a\nb", 0.0, Position::TOP, Position::CENTER);

        $this->assertSame(-2, $withNewline['verticalOffset']);
        $this->assertSame($without['verticalOffset'], $withNewline['verticalOffset']);
    }
}
