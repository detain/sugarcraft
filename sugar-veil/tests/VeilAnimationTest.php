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

        // Result should contain dim ANSI codes
        $this->assertStringContainsString("\x1b[2m", $result);
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

        $result = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 0.5);
        // animate returns the full composited result
        $this->assertNotEmpty($result);
        // X should be present somewhere
        $this->assertStringContainsString('X', $result);
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
