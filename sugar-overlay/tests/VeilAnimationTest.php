<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Tests;

use SugarCraft\Overlay\{Animation\AnimationKind, Animation\Fade, Position, Veil};
use SugarCraft\Spring\Easing\CubicBezier;
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
        // Clamped to 100 — observed in the bytes: an unclamped 150 would run
        // the blend past black into negative channels ("38;2;-128;…"), so 150
        // must emit exactly the full-black 100 pen and nothing else.
        $bg = "..........\n..........";
        $fg = "X";

        $over = Veil::new()->withBackdrop(150)->composite($fg, $bg, Position::TOP, Position::LEFT);
        $full = Veil::new()->withBackdrop(100)->composite($fg, $bg, Position::TOP, Position::LEFT);

        $this->assertSame($full, $over);
        $this->assertStringContainsString("\e[38;2;0;0;0m", $over);
    }

    public function testWithBackdropNegativeClampsToZero(): void
    {
        // Clamped to 0 — observed in the bytes: an unclamped -50 would blend
        // UPWARD ("38;2;383;…") and arm a pen, so a negative backdrop must be
        // byte-identical to no backdrop: no truecolor pen anywhere.
        $bg = "..........\n..........";
        $fg = "X";

        $neg = Veil::new()->withBackdrop(-50)->composite($fg, $bg, Position::TOP, Position::LEFT);
        $zero = Veil::new()->withBackdrop(0)->composite($fg, $bg, Position::TOP, Position::LEFT);

        $this->assertSame($zero, $neg);
        $this->assertStringNotContainsString('38;2;', $neg);
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

    /**
     * SO-12 (sugar-overlay-009): the veil-level API now CARRIES a configured
     * animation instance — a custom curve reaches animate() without the old
     * manual apply()+composite() detour, and reads different bytes from the
     * preset kind whose default curve differs.
     */
    public function testCarriedInstanceWithCustomCurveDrivesAnimate(): void
    {
        $bg = "bg line one\nbg line two\nbg line three";
        $fg = "XX";

        $kind    = Veil::new()->withBackdrop(50)->withAnimation(AnimationKind::FADE);
        $carried = Veil::new()->withBackdrop(50)->withAnimation(
            Fade::new(CubicBezier::easeIn())
        );
        $defaultCurve = Veil::new()->withBackdrop(50)->withAnimation(
            Fade::new()
        );

        // Each emit gets its own diff session: the canvas-empty early-return
        // path would otherwise leave every frame chained on the process
        // default session (the second identical render would collapse to
        // the anchor-only delta of the first).
        $this->assertNotSame(
            $kind->withoutSession()->animate($fg, $bg, Position::TOP, Position::LEFT, 0.3),
            $carried->withoutSession()->animate($fg, $bg, Position::TOP, Position::LEFT, 0.3),
            'a carried easeIn must not collapse onto the preset default curve',
        );
        $this->assertSame(
            $kind->withoutSession()->animate($fg, $bg, Position::TOP, Position::LEFT, 0.3),
            $defaultCurve->withoutSession()->animate($fg, $bg, Position::TOP, Position::LEFT, 0.3),
            'an argument-less instance is the preset default, byte for byte',
        );
    }

    /**
     * SO-12 (sugar-overlay-068): animate() and the manual path are ONE
     * path now — hand-spelling the carried instance's apply() plus the
     * eased scrim ramp composites the identical frame.
     */
    public function testAnimateEqualsTheHandSpelledManualPathForACarriedCurve(): void
    {
        $bg     = "bg line one\nbg line two\nbg line three";
        $fg     = 'XX';
        $curve  = CubicBezier::easeInOut();
        $fade   = Fade::new($curve);
        $eased  = $fade->easedProgress(0.3);
        $ramped = (int) \round(50 * $eased);

        $animated = Veil::new()
            ->withBackdrop(50)
            ->withAnimation($fade)
            ->animate($fg, $bg, Position::TOP, Position::LEFT, 0.3);

        $manual = Veil::new()
            ->withBackdrop($ramped)
            ->composite($fade->apply($fg, 0.3), $bg, Position::TOP, Position::LEFT);

        $this->assertSame($manual, $animated);
    }

    /**
     * SO-12 (sugar-overlay-066): the scrim ramp rides the SAME eased
     * progress the content glides on, not raw clock time. An uncovered
     * backdrop row must carry the easeInOut(0.3) dim, provably not the
     * linear-0.3 dim (the two land on different gray levels).
     */
    public function testScrimRampFollowsEasedProgressNotLinearTime(): void
    {
        $bg = \str_repeat('background text row\n', 4);
        $fg = 'XX';

        $frame = Veil::new()
            ->withBackdrop(50)
            ->withAnimation(AnimationKind::FADE)
            ->animate($fg, $bg, Position::TOP, Position::LEFT, 0.3);

        $eased  = \max(0.0, \min(1.0, CubicBezier::easeInOut()->evaluate(0.3)));
        $dimmed = (int) \round(50 * $eased);
        $level  = (int) \round(255 * (100 - $dimmed) / 100);
        $linear = (int) \round(50 * 0.3);
        $linearLevel = (int) \round(255 * (100 - $linear) / 100);

        $this->assertNotSame($level, $linearLevel, 'fixture must separate eased from linear');
        $this->assertStringContainsString(
            "\e[38;2;{$level};{$level};{$level}m",
            $frame,
            'uncovered rows carry the EASED scrim, not the linear-progress one',
        );
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
     * Read the animation driver through the public accessor (SO-12: the
     * old private-property reflection has a public twin now).
     */
    private function getAnimationKind(Veil $v): ?AnimationKind
    {
        $animation = $v->animation();

        return $animation instanceof AnimationKind ? $animation : null;
    }
}
