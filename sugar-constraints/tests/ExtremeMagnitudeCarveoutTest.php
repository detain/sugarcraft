<?php

declare(strict_types=1);

namespace SugarCraft\Constraints\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Constraints\Constraint\Constraint;
use SugarCraft\Constraints\Direction;
use SugarCraft\Constraints\GreedySolver;
use SugarCraft\Constraints\Region;

/**
 * Doc-promise pin (SC-16): the README Degradation section's extreme-magnitude
 * carve-out (carve-out 4), pinned as DISCLOSED-BEHAVIOUR, not as a guard —
 * the documented promise is precisely that this shape throws a MISLEADING
 * `Region components must be non-negative` error instead of guarding:
 *
 *   "when spans or sizes large enough that `span × n` / `span × numerator`
 *   products overflow int64 (unguarded) — resolved sizes ride IEEE-754
 *   rounding, and the final cast on overflow throws a misleading
 *   negative-width InvalidArgumentException at Region construction.
 *   Documented, not guarded."
 *
 * Both pins below construct the overflow shape at a near-`PHP_INT_MAX` span:
 * the proportional pass casts the out-of-range float result to int (wrapping
 * negative on the common 64-bit two's-complement path) and the Region guard
 * rejects it with the negative width spelled out in the message. The exact
 * wrapped value is platform-dependent BY DOCUMENTATION (IEEE-754 rounding);
 * these pins hold the Linux x86_64 CI shape. The point of the test is the
 * exception TYPE + negative-width family of the message — a future change
 * that either silently clamps (breaking the "documented, not guarded"
 * contract) or throws some unrelated error must re-visit this doc.
 *
 * Targeted runs: vendor/bin/phpunit --filter ExtremeMagnitudeCarveoutTest
 */
final class ExtremeMagnitudeCarveoutTest extends TestCase
{
    public function testPercentageProductOverflowThrowsMisleadingNegativeWidth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Region components must be non-negative; got x=0, y=0, width=-9223372036854775808, height=1'
        );

        GreedySolver::new()->solve(
            Region::fromSize(PHP_INT_MAX - 1, 1),
            Direction::Horizontal,
            [Constraint::percentage(100), Constraint::fill(1)],
        );
    }

    public function testRatioProductOverflowThrowsMisleadingNegativeWidth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Region components must be non-negative; got x=0, y=0, width=-4611686018427387904, height=1'
        );

        GreedySolver::new()->solve(
            Region::fromSize(PHP_INT_MAX - 1, 1),
            Direction::Horizontal,
            [Constraint::ratio(3, 2)],
        );
    }
}
