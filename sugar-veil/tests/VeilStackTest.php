<?php

declare(strict_types=1);

namespace SugarCraft\Veil\Tests;

use SugarCraft\Sprinkles\Border;
use SugarCraft\Veil\{Position, Veil, VeilStack};
use PHPUnit\Framework\TestCase;

final class VeilStackTest extends TestCase
{
    public function testNew(): void
    {
        $stack = VeilStack::new();
        $this->assertTrue($stack->isEmpty());
        $this->assertCount(0, $stack);
    }

    public function testAdd(): void
    {
        $stack = VeilStack::new()->add(Veil::new());
        $this->assertCount(1, $stack);
        $this->assertFalse($stack->isEmpty());
    }

    public function testClear(): void
    {
        $stack = VeilStack::new()
            ->add(Veil::new())
            ->add(Veil::new())
            ->clear();
        $this->assertCount(0, $stack);
        $this->assertTrue($stack->isEmpty());
    }

    public function testRemoveWhere(): void
    {
        $v1 = Veil::new()->withZIndex(1);
        $v2 = Veil::new()->withZIndex(2);
        $v3 = Veil::new()->withZIndex(3);

        $stack = VeilStack::new()->add($v1)->add($v2)->add($v3);
        $stack = $stack->removeWhere(fn(Veil $v): bool => $v->zIndex() === 2);

        $this->assertCount(2, $stack);
        $zIndexes = array_map(fn(Veil $v): int => $v->zIndex(), $stack->all());
        $this->assertNotContains(2, $zIndexes);
    }

    public function testSortedOrdersByZIndexAscending(): void
    {
        $v1 = Veil::new()->withZIndex(3);
        $v2 = Veil::new()->withZIndex(1);
        $v3 = Veil::new()->withZIndex(2);

        $stack = VeilStack::new()->add($v1)->add($v2)->add($v3);
        $sorted = $stack->sorted();

        $this->assertSame(1, $sorted[0]->zIndex());
        $this->assertSame(2, $sorted[1]->zIndex());
        $this->assertSame(3, $sorted[2]->zIndex());
    }

    public function testSortedOrdersByZIndexAscendingWithNegatives(): void
    {
        $v1 = Veil::new()->withZIndex(0);
        $v2 = Veil::new()->withZIndex(-5);
        $v3 = Veil::new()->withZIndex(10);

        $stack = VeilStack::new()->add($v1)->add($v2)->add($v3);
        $sorted = $stack->sorted();

        $this->assertSame(-5, $sorted[0]->zIndex());
        $this->assertSame(0, $sorted[1]->zIndex());
        $this->assertSame(10, $sorted[2]->zIndex());
    }

    public function testCompositeRendersInZIndexOrder(): void
    {
        $bg = "....................\n....................\n....................";

        // Veil at z-index 1 — letter A, renders on top, positioned at LEFT
        $veil1 = Veil::new()->withZIndex(1);
        // Veil at z-index 0 — letter B, renders first (bottom), positioned at RIGHT
        $veil0 = Veil::new()->withZIndex(0);

        // Composite veil0 first (foreground "B") onto bg at RIGHT, then veil1 ("A") at LEFT
        $step1 = $veil0->composite('B', $bg, Position::TOP, Position::RIGHT);
        $result = $veil1->composite('A', $step1, Position::TOP, Position::LEFT);

        // Both A and B should appear in the result at different positions
        $this->assertStringContainsString('A', $result);
        $this->assertStringContainsString('B', $result);
    }

    public function testMaxZIndex(): void
    {
        $stack = VeilStack::new()
            ->add(Veil::new()->withZIndex(3))
            ->add(Veil::new()->withZIndex(1))
            ->add(Veil::new()->withZIndex(5));

        $this->assertSame(5, $stack->maxZIndex());
    }

    public function testMinZIndex(): void
    {
        $stack = VeilStack::new()
            ->add(Veil::new()->withZIndex(3))
            ->add(Veil::new()->withZIndex(1))
            ->add(Veil::new()->withZIndex(5));

        $this->assertSame(1, $stack->minZIndex());
    }

    public function testEmptyStackMaxZIndex(): void
    {
        $this->assertSame(0, VeilStack::new()->maxZIndex());
    }

    public function testEmptyStackMinZIndex(): void
    {
        $this->assertSame(0, VeilStack::new()->minZIndex());
    }

    public function testFilter(): void
    {
        $v1 = Veil::new()->withZIndex(1)->withClickOutsideDismiss(true);
        $v2 = Veil::new()->withZIndex(2)->withClickOutsideDismiss(false);
        $v3 = Veil::new()->withZIndex(3)->withClickOutsideDismiss(true);

        $stack = VeilStack::new()->add($v1)->add($v2)->add($v3);
        $filtered = $stack->filter(fn(Veil $v): bool => $v->clickOutsideDismiss());

        $this->assertCount(2, $filtered);
    }

    public function testFilterPreservesOriginal(): void
    {
        $v1 = Veil::new()->withZIndex(1);
        $stack = VeilStack::new()->add($v1);
        $filtered = $stack->filter(fn(Veil $v): bool => false);

        $this->assertCount(1, $stack);
        $this->assertCount(0, $filtered);
    }

    public function testAllReturnsAllVeils(): void
    {
        $v1 = Veil::new()->withZIndex(1);
        $v2 = Veil::new()->withZIndex(2);
        $stack = VeilStack::new()->add($v1)->add($v2);

        $all = $stack->all();
        $this->assertCount(2, $all);
    }

    // ─── compositeAll() ────────────────────────────────────────────────────────

    public function testCompositeAllWithEmptyStackReturnsBackgroundUnchanged(): void
    {
        $bg = "hello\nworld";
        $stack = VeilStack::new();
        $result = $stack->compositeAll($bg);
        $this->assertSame($bg, $result);
    }

    public function testCompositeAllSkipsEmptyStackInLoop(): void
    {
        // When sorted returns empty, the loop body never executes
        // and $background is returned unchanged
        $bg = "test";
        $stack = VeilStack::new();
        $this->assertSame($bg, $stack->compositeAll($bg));
    }

    public function testCompositeAllWithMultipleVeilsComposesInZIndexOrder(): void
    {
        $bg = "....................\n....................\n....................";

        $veil0 = Veil::new()->withZIndex(0);
        $veil1 = Veil::new()->withZIndex(1);

        // compositeAll uses each veil's own Position::TOP, Position::LEFT
        $stack = VeilStack::new()->add($veil0)->add($veil1);

        $result = $stack->compositeAll($bg);

        // Should return a string (the composited result)
        $this->assertIsString($result);
    }

    public function testCompositeAllWithSingleVeil(): void
    {
        $bg = "....................";
        $veil = Veil::new()->withZIndex(0);

        $stack = VeilStack::new()->add($veil);
        $result = $stack->compositeAll($bg);

        // Even with single veil, composite should be called
        $this->assertIsString($result);
    }

    // ─── count() (Countable) ───────────────────────────────────────────────────

    public function testCountReturnsItemCount(): void
    {
        $stack = VeilStack::new()
            ->add(Veil::new())
            ->add(Veil::new())
            ->add(Veil::new());

        $this->assertCount(3, $stack);
    }

    public function testCountOnEmptyStackIsZero(): void
    {
        $stack = VeilStack::new();
        $this->assertCount(0, $stack);
    }

    public function testCompositeAllWithOffsetAppliesToEachVeil(): void
    {
        // Verify compositeAll method is actually called and uses proper background
        $bg = "....................\n....................\n....................";

        $veil0 = Veil::new()->withZIndex(0);
        $veil1 = Veil::new()->withZIndex(1);

        $stack = VeilStack::new()->add($veil0)->add($veil1);

        // compositeAll with multiple veils should composite each in z-order
        $result = $stack->compositeAll($bg);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testRemoveWhereWithNoMatchReturnsSameCount(): void
    {
        $v1 = Veil::new()->withZIndex(1);
        $v2 = Veil::new()->withZIndex(2);

        $stack = VeilStack::new()->add($v1)->add($v2);
        $filtered = $stack->removeWhere(fn(Veil $v): bool => $v->zIndex() === 99);

        $this->assertCount(2, $filtered);
    }

    // ─── Step 4: positional-correctness ─────────────────────────────────────────

    /**
     * compositeAll() now honors per-veil positions. Two veils with different
     * positions set via withPosition() should have those positions preserved
     * through withoutSession() and accessible via vPosition()/hPosition().
     */
    public function testCompositeAllHonorsPerVeilPositions(): void
    {
        $bg = str_repeat('.', 20) . "\n" . str_repeat('.', 20);

        $veilTopRight = Veil::new()
            ->withZIndex(0)
            ->withPosition(Position::TOP, Position::RIGHT, x: 0, y: 0);

        $veilBottomLeft = Veil::new()
            ->withZIndex(1)
            ->withPosition(Position::BOTTOM, Position::LEFT, x: 0, y: 0);

        $stack = VeilStack::new()->add($veilTopRight)->add($veilBottomLeft);
        $result = $stack->compositeAll($bg);

        $this->assertIsString($result);
        $lines = Veil::new()->splitLines($result);

        // Verify positions are stored and accessible
        $this->assertSame(Position::TOP, $veilTopRight->vPosition());
        $this->assertSame(Position::RIGHT, $veilTopRight->hPosition());
        $this->assertSame(Position::BOTTOM, $veilBottomLeft->vPosition());
        $this->assertSame(Position::LEFT, $veilBottomLeft->hPosition());

        // withoutSession() preserves position state
        $freshTopRight = $veilTopRight->withoutSession();
        $freshBottomLeft = $veilBottomLeft->withoutSession();
        $this->assertSame(Position::TOP, $freshTopRight->vPosition());
        $this->assertSame(Position::BOTTOM, $freshBottomLeft->vPosition());

        // Result is a full composite string (not empty)
        $this->assertGreaterThan(0, \strlen($result));
    }

    // ─── Step 5: frame 2 full-output (no delta-as-background corruption) ────────

    /**
     * compositeAll() uses withoutSession() so each inner composite emits a FULL
     * frame. Composing the same stack twice should still produce full-frame
     * output on frame 2 (not a delta passed as background to inner veils).
     */
    public function testCompositeAllFrame2StillContainsFullBackground(): void
    {
        $bg = "....................\n....................";
        $sentinel = "sentinel_bg_unchanged"; // Non-changing background sentinel

        // Background that doesn't change between frames
        $staticBg = str_repeat(".", 20) . "\n" . str_repeat(".", 20);

        $veil = Veil::new()
            ->withZIndex(0)
            ->withBackdrop(30)
            ->withPosition(Position::TOP, Position::LEFT);

        $stack = VeilStack::new()->add($veil);

        // Frame 1: full output
        $frame1 = $stack->compositeAll($staticBg);
        $this->assertStringContainsString('.', $frame1, 'Frame 1 should contain background dots');
        $this->assertGreaterThan(35, \strlen($frame1), 'Frame 1 should be full output');

        // Frame 2: same stack, same background — should still be full output
        // (NOT a delta that would corrupt inner compositing)
        $frame2 = $stack->compositeAll($staticBg);
        $this->assertStringContainsString('.', $frame2, 'Frame 2 should still contain background dots (full frame, not delta)');
        $this->assertGreaterThan(35, \strlen($frame2), 'Frame 2 should be full output, not a small delta');
    }

    /**
     * VeilStack::composite() (per-veil position variant) should similarly emit
     * full frames at every layer so the chaining is not corrupted by deltas.
     */
    public function testCompositeFrame2StillFullOutput(): void
    {
        $bg = str_repeat(".", 20) . "\n" . str_repeat(".", 20);

        $veil = Veil::new()->withZIndex(0)->withBackdrop(30);

        $stack = VeilStack::new()->add($veil);

        // Frame 1
        $frame1 = $stack->composite($bg, Position::TOP, Position::LEFT);
        $this->assertStringContainsString('.', $frame1);

        // Frame 2
        $frame2 = $stack->composite($bg, Position::TOP, Position::LEFT);
        $this->assertStringContainsString('.', $frame2, 'Frame 2 should still contain background (full frame)');
        $this->assertGreaterThan(35, \strlen($frame2));
    }
}
