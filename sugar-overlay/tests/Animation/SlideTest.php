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
        // easeOut(0.5) = 0.6846 → factor 1 - 0.6846 = 0.3154, width 3 cols
        // → round(3 * 0.3154) = 1 column still out, NEGATIVE on the LEFT.
        $slide = new Slide();
        $result = $slide->apply("ABC", 0.5, Position::TOP, Position::LEFT);

        $this->assertSame(-1, $result['horizontalOffset']);
    }

    public function testSlideFromRightAnchor(): void
    {
        // The RIGHT mirror of the LEFT leg: same magnitude, POSITIVE sign.
        $slide = new Slide();
        $result = $slide->apply("ABC", 0.5, Position::TOP, Position::RIGHT);

        $this->assertSame(1, $result['horizontalOffset']);
    }

    public function testSlideFromBottomAnchor(): void
    {
        // 4 rows, factor 0.3154 → round(4 * 0.3154) = 1 row below: POSITIVE
        // at a BOTTOM anchor, exact not just signed.
        $slide = new Slide();
        $result = $slide->apply("A\nB\nC\nD", 0.5, Position::BOTTOM, Position::LEFT);

        $this->assertSame(1, $result['verticalOffset']);
    }

    public function testSlideFromBottomRightAnchor(): void
    {
        // 4 rows x 4 cols at factor 0.3154 → exactly one step out on BOTH
        // axes, positive for a BOTTOM-RIGHT entry.
        $slide = new Slide();
        $result = $slide->apply("ABCD\nEFGH\nIJKL\nMNOP", 0.5, Position::BOTTOM, Position::RIGHT);

        $this->assertSame(1, $result['verticalOffset']);
        $this->assertSame(1, $result['horizontalOffset']);
    }

    public function testSlideFromTopLeftAnchor(): void
    {
        // Same 4x4 geometry as the BOTTOM-RIGHT leg, negated for a
        // TOP-LEFT entry: one step out on each axis.
        $slide = new Slide();
        $result = $slide->apply("ABCD\nEFGH\nIJKL\nMNOP", 0.5, Position::TOP_LEFT, Position::LEFT);

        $this->assertSame(-1, $result['verticalOffset']);
        $this->assertSame(-1, $result['horizontalOffset']);
    }

    public function testSlideWithCustomEasing(): void
    {
        // easeIn(0.5) = 0.3154 vs easeOut(0.5) = 0.6846 on a 3-col, 1-row
        // travel: factors 0.6846 vs 0.3154 → 2 cols/1 row out vs 1 col/0
        // rows out. The offsets actually differ by curve — pinned as bytes,
        // not as an isInt class.
        $easeIn = (new Slide(CubicBezier::easeIn()))->apply("ABC", 0.5, Position::TOP, Position::LEFT);
        $easeOut = (new Slide(CubicBezier::easeOut()))->apply("ABC", 0.5, Position::TOP, Position::LEFT);

        $this->assertSame(-1, $easeIn['verticalOffset']);
        $this->assertSame(-2, $easeIn['horizontalOffset']);
        $this->assertSame(0, $easeOut['verticalOffset']);
        $this->assertSame(-1, $easeOut['horizontalOffset']);
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
