<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout\Tile;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Layout\Tile\Constraint;
use SugarCraft\Dash\Layout\Tile\Resolver;

/**
 * Golden ratio tests for the Resolver linear layout algorithm.
 * Mirrors tealeaves/tealayout_resolve.go algorithm.
 *
 * These tests verify the 5-phase algorithm:
 * 1. Apply minSizeFit from hinters
 * 2. Resolve Fixed and Fit children
 * 3. Distribute remaining to Flex with clamping loop
 * 4. Check optional children — remove any below MinSize
 * 5. Retry from Phase 1 if any were removed
 */
final class ResolverGoldenTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Phase 2: Fixed constraints
    // ═══════════════════════════════════════════════════════════════

    /**
     * Test: all fixed, sum equals available.
     * Phase 2 should pass through fixed sizes unchanged.
     */
    public function testResolveLinearAllFixed(): void
    {
        $constraints = [
            Constraint::fixed(10),
            Constraint::fixed(20),
            Constraint::fixed(30),
        ];

        $sizes = Resolver::resolveLinear(60, $constraints, 0, null, true);

        $this->assertSame([10, 20, 30], $sizes);
    }

    /**
     * Test: all fixed with gaps — gaps reduce available space for tiles.
     */
    public function testResolveLinearAllFixedWithGaps(): void
    {
        $constraints = [
            Constraint::fixed(10),
            Constraint::fixed(20),
            Constraint::fixed(30),
        ];

        // 3 tiles = 2 gaps of 5 each = 10 total gap
        // 100 - 10 = 90 for tiles, but fixed totals only 60
        $sizes = Resolver::resolveLinear(100, $constraints, 5, null, true);

        // Fixed values pass through unchanged even when extra space available
        $this->assertSame([10, 20, 30], $sizes);
    }

    // ═══════════════════════════════════════════════════════════════
    // Phase 3: Flex constraints
    // ═══════════════════════════════════════════════════════════════

    /**
     * Test: all flex with equal weight, verify sum ≤ available and no zero drift.
     * Phase 3 distributes remaining space equally among flex children.
     */
    public function testResolveLinearAllFlex(): void
    {
        $constraints = [
            Constraint::flex(1.0),
            Constraint::flex(1.0),
            Constraint::flex(1.0),
        ];

        $sizes = Resolver::resolveLinear(60, $constraints, 0, null, true);

        // All equal weight = 20 each, sum = 60
        $this->assertSame(60, array_sum($sizes));
        // No zero drift - each should get positive allocation
        $this->assertGreaterThan(0, $sizes[0]);
        $this->assertGreaterThan(0, $sizes[1]);
        $this->assertGreaterThan(0, $sizes[2]);
    }

    /**
     * Test: flex with gaps — gap reduces space before flex distribution.
     */
    public function testResolveLinearFlexWithGap(): void
    {
        $constraints = [
            Constraint::flex(1.0),
            Constraint::flex(1.0),
        ];

        // gap=5, available=60, so 55 remaining for 2 flex children = 27.5 → 27/28
        $sizes = Resolver::resolveLinear(60, $constraints, 5, null, true);

        // Sum of sizes + gap should equal available
        $this->assertLessThanOrEqual(60, array_sum($sizes) + 5);
    }

    /**
     * Test: weighted flex distribution.
     * 1:3 ratio should give approximately 25:75 split.
     */
    public function testResolveLinearWeightedFlex(): void
    {
        $constraints = [
            Constraint::flex(1.0),
            Constraint::flex(3.0),
        ];

        $sizes = Resolver::resolveLinear(100, $constraints, 0, null, true);

        $this->assertEquals(25, $sizes[0]);
        $this->assertEquals(75, $sizes[1]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Mixed fixed and flex
    // ═══════════════════════════════════════════════════════════════

    /**
     * Test: mixed fixed and flex.
     * Fixed takes priority, flex gets remaining space.
     */
    public function testResolveLinearMixedFixedAndFlex(): void
    {
        $constraints = [
            Constraint::fixed(10),
            Constraint::flex(1.0),
            Constraint::flex(1.0),
        ];

        // 10 fixed, 50 remaining for 2 flex = 25 each
        $sizes = Resolver::resolveLinear(60, $constraints, 0, null, true);

        $this->assertSame([10, 25, 25], $sizes);
    }

    /**
     * Test: mixed with multiple fixed tiles.
     */
    public function testResolveLinearMixedMultipleFixed(): void
    {
        $constraints = [
            Constraint::fixed(10),
            Constraint::flex(1.0),
            Constraint::fixed(20),
            Constraint::flex(1.0),
        ];

        // 30 fixed, 70 remaining for 2 flex = 35 each
        $sizes = Resolver::resolveLinear(100, $constraints, 0, null, true);

        $this->assertSame([10, 35, 20, 35], $sizes);
    }

    // ═══════════════════════════════════════════════════════════════
    // Phase 4: Optional child removal
    // ═══════════════════════════════════════════════════════════════

    /**
     * Test: optional child removal — flex child below minSize gets removed.
     * Phase 4 removes optional children that can't meet their minSize,
     * then retries from Phase 1.
     */
    public function testResolveLinearOptionalRemoval(): void
    {
        $constraints = [
            Constraint::flex(1.0)->withOptional(true)->withMinSize(40),
            Constraint::flex(1.0),
        ];

        // First child needs 40 min but only gets ~25 — should be removed
        $sizes = Resolver::resolveLinear(50, $constraints, 0, null, true);

        // Second child gets all 50 (first was removed)
        $this->assertSame([0, 50], $sizes);
    }

    /**
     * Test: optional child NOT removed when it can meet minSize.
     */
    public function testResolveLinearOptionalNotRemoved(): void
    {
        $constraints = [
            Constraint::flex(1.0)->withOptional(true)->withMinSize(20),
            Constraint::flex(1.0),
        ];

        // First child gets 25 which is >= 20 minSize, so it stays
        $sizes = Resolver::resolveLinear(50, $constraints, 0, null, true);

        $this->assertEquals(25, $sizes[0]);
        $this->assertEquals(25, $sizes[1]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Phase 3: Cumulative rounding
    // ═══════════════════════════════════════════════════════════════

    /**
     * Test: cumulative rounding — sum of returned sizes must never exceed available.
     * This is a key invariant: the algorithm must not allocate more than available.
     */
    public function testResolveLinearCumulativeRoundingNoExceed(): void
    {
        $constraints = [
            Constraint::flex(1.0),
            Constraint::flex(1.0),
            Constraint::flex(1.0),
            Constraint::flex(1.0),
            Constraint::flex(1.0),
        ];

        $sizes = Resolver::resolveLinear(99, $constraints, 0, null, true);

        // Key invariant: sum of sizes + gaps must not exceed available
        $this->assertLessThanOrEqual(99, array_sum($sizes));

        // Verify all sizes are non-negative
        foreach ($sizes as $size) {
            $this->assertGreaterThanOrEqual(0, $size);
        }
    }

    /**
     * Test: edge case with odd available space.
     */
    public function testResolveLinearOddAvailable(): void
    {
        $constraints = [
            Constraint::flex(1.0),
            Constraint::flex(1.0),
        ];

        $sizes = Resolver::resolveLinear(101, $constraints, 0, null, true);

        // Sum should not exceed available
        $this->assertLessThanOrEqual(101, array_sum($sizes));
        // Both should be positive
        $this->assertGreaterThan(0, $sizes[0]);
        $this->assertGreaterThan(0, $sizes[1]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    /**
     * Test: zero available space.
     * Should return all zeros.
     */
    public function testResolveLinearZeroAvailable(): void
    {
        $constraints = [
            Constraint::flex(1.0),
            Constraint::flex(1.0),
        ];

        $sizes = Resolver::resolveLinear(0, $constraints, 0, null, true);

        $this->assertSame([0, 0], $sizes);
    }

    /**
     * Test: empty constraints.
     * Should return empty array.
     */
    public function testResolveLinearEmptyConstraints(): void
    {
        $sizes = Resolver::resolveLinear(100, [], 0, null, true);

        $this->assertSame([], $sizes);
    }

    /**
     * Test: minSize is respected on flex.
     * Flex child with minSize 30 should not go below that.
     */
    public function testResolveLinearMinSizeRespected(): void
    {
        $constraints = [
            Constraint::flex(1.0)->withMinSize(30),
            Constraint::flex(1.0)->withMinSize(30),
        ];

        $sizes = Resolver::resolveLinear(40, $constraints, 0, null, true);

        // Each should get at least 30
        $this->assertGreaterThanOrEqual(30, $sizes[0]);
        $this->assertGreaterThanOrEqual(30, $sizes[1]);
    }

    /**
     * Test: maxSize clamps flex children.
     */
    public function testResolveLinearMaxSizeClamped(): void
    {
        $constraints = [
            Constraint::flex(1.0)->withMaxSize(30),
            Constraint::flex(1.0),
        ];

        $sizes = Resolver::resolveLinear(100, $constraints, 0, null, true);

        // First flex clamped to 30, second gets remaining 70
        $this->assertSame(30, $sizes[0]);
        $this->assertSame(70, $sizes[1]);
    }

    /**
     * Test: single flex child gets all remaining space.
     */
    public function testResolveLinearSingleFlex(): void
    {
        $constraints = [
            Constraint::flex(1.0),
        ];

        $sizes = Resolver::resolveLinear(100, $constraints, 0, null, true);

        $this->assertSame([100], $sizes);
    }

    /**
     * Test: flex with very small available space.
     */
    public function testResolveLinearFlexVerySmallSpace(): void
    {
        $constraints = [
            Constraint::flex(1.0),
            Constraint::flex(1.0),
        ];

        $sizes = Resolver::resolveLinear(1, $constraints, 0, null, true);

        // Both should be non-negative, sum should not exceed 1
        $this->assertGreaterThanOrEqual(0, $sizes[0]);
        $this->assertGreaterThanOrEqual(0, $sizes[1]);
        $this->assertLessThanOrEqual(1, array_sum($sizes));
    }
}
