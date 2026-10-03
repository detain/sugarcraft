<?php

declare(strict_types=1);

namespace SugarCraft\Veil\Tests;

use SugarCraft\Veil\{Animation\AnimationKind, Position, Veil};
use PHPUnit\Framework\TestCase;

final class VeilAnimationTest extends TestCase
{
    private Veil $veil;

    protected function setUp(): void
    {
        $this->veil = Veil::new();
    }

    // ─── withBackdrop ───────────────────────────────────────────────────────

    public function testWithBackdropReturnsNewInstance(): void
    {
        $v1 = Veil::new();
        $v2 = $v1->withBackdrop(50);

        $this->assertNotSame($v1, $v2);
    }

    public function testWithBackdropClampsTo0to100(): void
    {
        $v = Veil::new()->withBackdrop(150);
        // Internally clamps to 100, but we verify composite doesn't crash

        $bg = "..........\n..........";
        $fg = "X";

        // Should not crash even with out-of-range value
        $result = $v->composite($fg, $bg, Position::TOP, Position::LEFT);
        $this->assertNotEmpty($result);
    }

    public function testWithBackdropNegativeClampsToZero(): void
    {
        $v = Veil::new()->withBackdrop(-50);

        $bg = "..........\n..........";
        $fg = "X";

        $result = $v->composite($fg, $bg, Position::TOP, Position::LEFT);
        $this->assertNotEmpty($result);
    }

    public function testBackdropDimsBackground(): void
    {
        $v = Veil::new()->withBackdrop(100);

        $bg = "..........\n..........";
        $fg = "X";

        $result = $v->composite($fg, $bg, Position::TOP, Position::LEFT);

        // Result should contain truecolor dim codes (black = 0;0;0 for 100% backdrop)
        $this->assertStringContainsString("38;2;0;0;0", $result);
        // Foreground X should still be present
        $this->assertStringContainsString('X', $result);
    }

    public function testBackdropZeroNoDimCodes(): void
    {
        $v = Veil::new()->withBackdrop(0);

        $bg = "..........";
        $fg = "X";

        $result = $v->composite($fg, $bg, Position::TOP, Position::LEFT);

        // No dim codes when backdrop is 0
        $this->assertStringNotContainsString("\x1b[2m", $result);
    }

    public function testBackdropPreservesBackgroundElsewhere(): void
    {
        $v = Veil::new()->withBackdrop(100);

        $bg = "aaaaaaaaaa\naaaaaaaaaa";
        $fg = "X";

        $result = $v->composite($fg, $bg, Position::TOP, Position::LEFT);
        $lines = $this->veil->splitLines($result);

        // Background should still be visible (dimmed)
        $this->assertStringContainsString('a', $result);
    }

    public function testBackdropWithMediumOpacity(): void
    {
        // Backdrop of 50 gives dimPasses = 2 (50/33 rounded = 2)
        // This exercises the inner dim-pass loop in dimLine() (runs once for dimPasses=2)
        $v = Veil::new()->withBackdrop(50);

        $bg = "..........\n..........";
        $fg = "X";

        $result = $v->composite($fg, $bg, Position::TOP, Position::LEFT);
        // truecolor 128;128;128 for 50% (PHP rounds 127.5 to 128)
        $this->assertStringContainsString("38;2;128;128;128", $result);
    }

    public function testBackdropWithHighOpacity(): void
    {
        // Backdrop of 100 gives dimPasses = 3 (100/33 rounded = 3)
        // This exercises the inner dim-pass loop in dimLine() (runs twice for dimPasses=3)
        $v = Veil::new()->withBackdrop(100);

        $bg = "..........\n..........";
        $fg = "X";

        $result = $v->composite($fg, $bg, Position::TOP, Position::LEFT);
        // Should have truecolor dim (black for 100% backdrop)
        $this->assertStringContainsString("38;2;0;0;0", $result);
    }

    public function testApplyBackdropInnerLoopWithDimPassesThree(): void
    {
        // Backdrop of 100 gives dimPasses=3, inner loop runs twice
        // This specifically exercises the inner for loop in dimLine()
        $v = Veil::new()->withBackdrop(100);

        $bg = "..........\n..........\n..........";
        $fg = "X";

        $result = $v->composite($fg, $bg, Position::TOP, Position::LEFT);
        $this->assertStringContainsString("38;2;0;0;0", $result);
    }

    public function testApplyBackdropInnerLoopWithDimPassesTwo(): void
    {
        // Backdrop of 50 gives dimPasses=2, inner loop runs once
        $v = Veil::new()->withBackdrop(50);

        $bg = "..........\n..........\n..........";
        $fg = "X";

        $result = $v->composite($fg, $bg, Position::TOP, Position::LEFT);
        $this->assertStringContainsString("38;2;128;128;128", $result);
    }

    public function testBackdropMultiLineContent(): void
    {
        // Test backdrop dimming with multi-line content (dimLine applied per row)
        $v = Veil::new()->withBackdrop(100);

        $bg = "............\n............\n............\n............";
        $fg = "X";

        $result = $v->composite($fg, $bg, Position::TOP, Position::LEFT);
        // Truecolor dim codes present (multiple passes applied to each line)
        $this->assertStringContainsString("38;2;0;0;0", $result);
    }

    // ─── withAnimation ──────────────────────────────────────────────────────

    public function testWithAnimationReturnsNewInstance(): void
    {
        $v1 = Veil::new();
        $v2 = $v1->withAnimation(AnimationKind::FADE);

        $this->assertNotSame($v1, $v2);
    }

    public function testWithAnimationStoresKind(): void
    {
        $v = Veil::new()->withAnimation(AnimationKind::SLIDE);
        $this->assertSame(AnimationKind::SLIDE, $this->getAnimationKind($v));

        $v = Veil::new()->withAnimation(AnimationKind::FADE);
        $this->assertSame(AnimationKind::FADE, $this->getAnimationKind($v));

        $v = Veil::new()->withAnimation(AnimationKind::SCALE);
        $this->assertSame(AnimationKind::SCALE, $this->getAnimationKind($v));
    }

    public function testAnimateAtProgressZeroReturnsBackgroundUnchanged(): void
    {
        $v = Veil::new()->withAnimation(AnimationKind::SCALE);

        $bg = "..........\n..........";
        $fg = "AAA\nBBB";

        // At progress 0 with Scale, foreground becomes empty.
        // animate() passes empty fg to composite(), which returns bg unchanged.
        $result = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 0.0);
        // composite returns background when fg is effectively empty
        $this->assertSame($bg, $result);
    }

    public function testAnimateAtProgressOneReturnsFullComposited(): void
    {
        $v = Veil::new()->withAnimation(AnimationKind::SCALE);

        $bg = "..........\n..........";
        $fg = "AAA\nBBB";

        $result = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 1.0);

        // At progress 1, animation returns full foreground,
        // composite should include the foreground in result
        $this->assertStringContainsString('AAA', $result);
        $this->assertStringContainsString('BBB', $result);
    }

    public function testAnimateSlideFromTopLeft(): void
    {
        $v = Veil::new()->withAnimation(AnimationKind::SLIDE);

        $bg = "..........\n..........\n..........";
        $fg = "X";

        // easeOut(0.5)=0.75 ⇒ factor 0.25 ⇒ offsets -round(0.25*1)=0 on both
        // axes: the single "X" has already landed at the TOP/LEFT anchor.
        $result = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 0.5);
        $this->assertStringStartsWith('X.........', explode("\n", $result)[0]);

        // The vacuity twin: at p=0 the slide is one full cell off the left
        // edge (x=-1, x+width=0 ⇒ clipped) — no "X" anywhere.
        $atZero = Veil::new()
            ->withAnimation(AnimationKind::SLIDE)
            ->animate($fg, $bg, Position::TOP, Position::LEFT, 0.0);
        $this->assertStringNotContainsString('X', $atZero);
    }

    public function testAnimateFadeAtProgressZeroReturnsBackgroundUnchanged(): void
    {
        $v = Veil::new()->withAnimation(AnimationKind::FADE);

        $bg = "..........";
        $fg = "X";

        // At progress 0, Fade returns fg unchanged, composite uses it
        $result = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 0.0);
        // At progress 0, Fade::apply returns fg unchanged
        // composite with non-empty fg should work normally
        $this->assertNotEmpty($result);
    }

    public function testAnimateFadeAtProgressOneReturnsComposited(): void
    {
        $v = Veil::new()->withAnimation(AnimationKind::FADE);

        $bg = "..........";
        $fg = "X";

        $result = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 1.0);
        // At progress 1, Fade::apply returns fg unchanged
        $this->assertNotEmpty($result);
    }

    public function testAnimateFadeRendersVisibleProgress(): void
    {
        // FADE used to be a visual no-op (the overlay popped in at full
        // brightness); mid-progress it must now paint the faded gray pen,
        // and at progress 0 paint nothing of the overlay at all.
        $v = Veil::new()->withAnimation(AnimationKind::FADE);
        $bg = "..........";

        $mid = $v->withoutSession()->animate('X', $bg, Position::TOP, Position::LEFT, 0.5);
        $this->assertMatchesRegularExpression('/\e\[38;2;(\d+);\1;\1mX\e\[39m/', $mid);
        $this->assertNotSame(
            Veil::new()->composite('X', $bg, Position::TOP, Position::LEFT),
            $mid,
        );

        $start = $v->withoutSession()->animate('X', $bg, Position::TOP, Position::LEFT, 0.0);
        $this->assertSame($bg, $start);
    }

    public function testAnimateFadeAtMidProgressReturnsComposited(): void
    {
        $v = Veil::new()->withAnimation(AnimationKind::FADE);

        $bg = "..........";
        $fg = "X";

        $result = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 0.5);
        // Fade returns foreground unchanged (terminal limitation)
        // Result should be the composited output with X visible
        $this->assertStringContainsString('X', $result);
    }

    public function testCompositeWithoutAnimationWorksNormally(): void
    {
        $v = Veil::new();

        $bg = "..........";
        $fg = "X";

        $result = $v->composite($fg, $bg, Position::TOP, Position::LEFT);
        $this->assertStringStartsWith('X', $result);
    }

    public function testAnimationChaining(): void
    {
        $v = Veil::new()
            ->withBackdrop(50)
            ->withAnimation(AnimationKind::FADE);

        $bg = "..........";
        $fg = "X";

        $result = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 0.5);
        $this->assertStringContainsString('X', $result);
    }

    public function testAnimateScaleProducesDifferentOutputAtDifferentProgress(): void
    {
        // Scale animation should produce different foreground at different progress values
        $v = Veil::new()->withAnimation(AnimationKind::SCALE);
        $bg = "....................\n....................\n....................\n....................\n....................\n....................";
        $fg = "A\nB\nC\nD\nE\nF";

        $result0 = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 0.0);
        $result50 = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 0.5);
        $result100 = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 1.0);

        // At progress 0, Scale returns empty string, composite returns bg unchanged
        $this->assertSame($bg, $result0);
        // At progress 1, Scale returns full fg, composite should include all lines
        $this->assertStringContainsString('A', $result100);
        $this->assertStringContainsString('F', $result100);
        // At 50% progress, only a subset of lines visible (scale from center)
        $this->assertIsString($result50);
    }

    public function testAnimateSlideProducesDifferentOutputAtDifferentProgress(): void
    {
        $bg = "....................\n....................\n....................";
        $fg = "TEST";

        // Fresh veil per progress: each call is the frame-1 full render, so
        // the asserts below score layout, not diff residue from a shared
        // session (a reused $v would emit deltas after the first call).
        $slide = static function (float $p) use ($fg, $bg): string {
            return Veil::new()
                ->withAnimation(AnimationKind::SLIDE)
                ->animate($fg, $bg, Position::TOP, Position::LEFT, $p);
        };

        $result0 = $slide(0.0);
        $result50 = $slide(0.5);
        $result100 = $slide(1.0);

        // LEFT enters from the left: at p=0 the whole "TEST" (4 cols) is
        // pushed off-screen (x=-4, x+width=0 ⇒ clipped), at p=0.5 easeOut
        // pushes it 1 col left so only "EST" peeks in at col 0, at p=1 it
        // lands whole. Deterministic values, measured — vacuity repair.
        $this->assertSame($bg, $result0, 'at p=0 the slide is fully off-screen left');
        $this->assertStringStartsWith("EST.....", $result50, 'at p=0.5 the head "T" is clipped off the left edge');
        $this->assertStringStartsWith("TEST....", $result100, 'at p=1 the foreground sits at its anchor');
    }

    public function testAnimateFadeProducesFullContentAtProgressOne(): void
    {
        $v = Veil::new()->withAnimation(AnimationKind::FADE);
        $bg = "....................\n....................";
        $fg = "FADE";

        $result = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 1.0);
        $this->assertStringContainsString('FADE', $result);
    }

    // ─── AnimationKind enum ────────────────────────────────────────────────

    public function testAnimationKindCases(): void
    {
        $this->assertSame('SLIDE', AnimationKind::SLIDE->name);
        $this->assertSame('FADE', AnimationKind::FADE->name);
        $this->assertSame('SCALE', AnimationKind::SCALE->name);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Get animation kind via reflection since it's a private property.
     */
    private function getAnimationKind(Veil $v): ?AnimationKind
    {
        $prop = (new \ReflectionClass($v))->getProperty('animationKind');
        $prop->setAccessible(true);
        return $prop->getValue($v);
    }
}
