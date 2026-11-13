<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests;

use SugarCraft\Spring\Easing\Easing;
use SugarCraft\Spring\Point;
use SugarCraft\Spring\Projectile;
use SugarCraft\Spring\Spring;
use SugarCraft\Spring\SpringChain;
use SugarCraft\Spring\SpringCollection;
use SugarCraft\Spring\Vector;
use PHPUnit\Framework\TestCase;

/**
 * @group parallel-unsafe
 *
 * These tests modify global process environment via putenv() and cannot run
 * in parallel (e.g., with Paratest). The putenv() calls affect the entire
 * process state, not just the current test context.
 */
final class ReducedMotionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure reduced motion is disabled by default
        putenv('REDUCE_MOTION=0');
        putenv('PREFERS_REDUCED_MOTION=0');
        // SPR-09: the env probe is memoized process-wide; re-read it against
        // the env this test is about to establish.
        Spring::resetReducedMotionProbe();
    }

    protected function tearDown(): void
    {
        putenv('REDUCE_MOTION');
        putenv('PREFERS_REDUCED_MOTION');
        Spring::resetReducedMotionProbe();
        parent::tearDown();
    }

    public function testNormalMotionAnimatesGradually(): void
    {
        putenv('REDUCE_MOTION=0');

        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        [$pos] = $spring->update(0.0, 0.0, 100.0);

        // With normal motion, the position should NOT instantly be at target
        // (unless this happens to be the exact frame it converges)
        $this->assertLessThan(100.0, $pos);
    }

    public function testReducedMotionSnapsToTarget(): void
    {
        putenv('REDUCE_MOTION=1');

        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        [$pos, $vel] = $spring->update(0.0, 0.0, 100.0);

        // With reduced motion, should snap to target immediately
        $this->assertEqualsWithDelta(100.0, $pos, 0.001);
        $this->assertEqualsWithDelta(0.0, $vel, 0.001);
    }

    public function testReducedMotionWithPrefersReducedMotionEnv(): void
    {
        putenv('REDUCE_MOTION=');
        putenv('PREFERS_REDUCED_MOTION=1');

        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        [$pos, $vel] = $spring->update(0.0, 0.0, 100.0);

        $this->assertEqualsWithDelta(100.0, $pos, 0.001);
        $this->assertEqualsWithDelta(0.0, $vel, 0.001);
    }

    public function testReducedMotionEmptyStringNotEnabled(): void
    {
        putenv('REDUCE_MOTION=');

        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        [$pos, $vel] = $spring->update(0.0, 0.0, 100.0);

        // Empty string should not enable reduced motion
        $this->assertLessThan(100.0, $pos);
    }

    public function testReducedMotionZeroStringNotEnabled(): void
    {
        putenv('REDUCE_MOTION=0');

        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        [$pos, $vel] = $spring->update(0.0, 0.0, 100.0);

        // '0' should not enable reduced motion
        $this->assertLessThan(100.0, $pos);
    }

    public function testReducedMotionFromEnvOverridesDefault(): void
    {
        // Default (no env vars) - spring should animate normally
        putenv('REDUCE_MOTION');
        putenv('PREFERS_REDUCED_MOTION');

        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        [$posNormal] = $spring->update(0.0, 0.0, 100.0);

        // Enable reduced motion
        putenv('REDUCE_MOTION=1');
        Spring::resetReducedMotionProbe(); // the memo, not the env, is what the probe just froze

        // Create new spring instance after env change
        $springReduced = new Spring(1.0 / 60.0, 6.0, 1.0);
        [$posReduced, $velReduced] = $springReduced->update(0.0, 0.0, 100.0);

        // Reduced motion should snap immediately
        $this->assertEqualsWithDelta(100.0, $posReduced, 0.001);
        $this->assertEqualsWithDelta(0.0, $velReduced, 0.001);

        // Normal motion should not snap
        $this->assertLessThan(100.0, $posNormal);
    }

    // ── M-B proof pins (review 1102): composition honors the signal ─────────
    //
    // The README used to claim SpringChain/Projectile/Easing "advance
    // regardless — gate those yourself". Measured: every composition of
    // springs inherits the snap because each stage/member IS a Spring. The
    // code was right; the contract was wrong. These pins lock the truthful
    // behavior so neither side can drift again (qa: this was untested).

    public function testChainSnapsEveryStageUnderReducedMotion(): void
    {
        putenv('REDUCE_MOTION=1');

        $stages = [
            [new Spring(1.0 / 60.0, 6.0, 1.0), 0.0, 0.0, 100.0],
            [new Spring(1.0 / 60.0, 6.0, 1.0), 0.0, 0.0, 50.0],
        ];
        $chain = SpringChain::new($stages);

        // Snap ticks alternate with hand-off ticks: every stage costs one tick
        // to snap and one more to hand off, so n stages complete on tick 2n
        // and carry their final positions from tick 2n-1 onward. A future
        // same-tick hand-off "optimisation" is a disclosed behaviour change,
        // not a silent one.
        $halfway = null;
        $ticks   = 0;
        while (!$chain->isComplete() && $ticks < 20) {
            $chain = $chain->tick();
            $ticks++;
            if ($ticks === 2 * count($stages) - 1) {
                $halfway = $chain->currentPositions();
            }
        }

        $this->assertTrue($chain->isComplete(), 'a reduced-motion chain must complete in a few ticks, not hundreds');
        $this->assertSame(2 * count($stages), $ticks, 'reduced-motion chain contract: n stages complete on tick 2n');
        $this->assertSame([100.0, 50.0], $halfway, 'final positions are in place after 2n-1 ticks');
        $this->assertSame([100.0, 50.0], $chain->currentPositions());
    }

    public function testCollectionSnapsAllMembersInOneTick(): void
    {
        putenv('REDUCE_MOTION=1');

        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $collection = (new SpringCollection())
            ->withSpring('a', $spring, 0.0, 0.0, 40.0)
            ->withSpring('b', $spring, 0.0, 0.0, 90.0);

        $snapped = $collection->tick();

        $this->assertSame(['a' => 40.0, 'b' => 90.0], $snapped->all());
        $this->assertTrue($snapped->settled());
    }

    public function testProjectileAndEasingDeliberatelyIgnoreTheSignal(): void
    {
        // The honest half of the scope sentence, now pinned the other way:
        // these are pure functions with no target to snap TO, so the app
        // gates them (see README "Reduced motion").
        putenv('REDUCE_MOTION=1');

        $projectile = Projectile::new(
            deltaTime: 1.0 / 60.0,
            position: Point::zero(),
            velocity: new Vector(5.0, 10.0),
            acceleration: Projectile::gravity(),
        );
        $after = $projectile->update();

        $this->assertGreaterThan(0.0, abs($after->position()->x));
        $this->assertLessThan(1.0, Easing::CubicOut->ease(0.5));
    }

    public function testPolarityExplicitNegativesMeanOffOtherValuesMeanOn(): void
    {
        // Minor 2 (review 1102): the canonical colorprofile Probe — owned
        // fleet-wide, NOT by spring — reads 0/false/no/off (case-insensitive)
        // as "not reduced" and any other non-empty value as reduced. Pinned
        // here so a fleet-wide Probe change is loud in THIS suite too.
        foreach (['false', 'no', 'off', '0', 'FALSE'] as $off) {
            putenv("REDUCE_MOTION={$off}");
            Spring::resetReducedMotionProbe(); // per-leg: the polarity table is read live
            $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
            [$pos] = $spring->update(0.0, 0.0, 100.0);
            $this->assertLessThan(100.0, $pos, "REDUCE_MOTION={$off} must NOT snap");
        }

        foreach (['1', 'yes', 'anything'] as $on) {
            putenv("REDUCE_MOTION={$on}");
            Spring::resetReducedMotionProbe();
            $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
            [$pos] = $spring->update(0.0, 0.0, 100.0);
            $this->assertSame(100.0, $pos, "REDUCE_MOTION={$on} must snap");
        }
    }

    /**
     * SPR-09 (finding 007, ruling (a)): the env verdict is memoized on first
     * probe — an env flip AFTER the process has already asked is invisible
     * until the documented reset seam runs, the memo is process-wide (not
     * per-spring), and the constructor override still beats both memo states
     * in each direction without touching the probe.
     */
    public function testEnvProbeIsMemoizedUntilTheDocumentedResetSeamRuns(): void
    {
        putenv('REDUCE_MOTION=0');
        Spring::resetReducedMotionProbe();

        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        [$normalPos] = $spring->update(0.0, 0.0, 100.0);
        $this->assertLessThan(100.0, $normalPos, 'first probe reads the env (off)');

        putenv('REDUCE_MOTION=1');
        [$frozenPos] = $spring->update(0.0, 0.0, 100.0);
        $this->assertLessThan(100.0, $frozenPos, 'env flip after the first probe stays invisible — the memo is the contract');

        $freshSpring = new Spring(1.0 / 60.0, 6.0, 1.0);
        [$freshPos] = $freshSpring->update(0.0, 0.0, 100.0);
        $this->assertLessThan(100.0, $freshPos, 'the memo is process-wide, not per-spring');

        // The override is evaluated per call and beats the memo in BOTH
        // directions: true snaps while the memoized answer is off...
        [$overrideSnaps] = (new Spring(1.0 / 60.0, 6.0, 1.0, true))->update(0.0, 0.0, 100.0);
        $this->assertSame(100.0, $overrideSnaps, 'override true wins over the memoized off');

        Spring::resetReducedMotionProbe();
        [$snappedPos] = $spring->update(0.0, 0.0, 100.0);
        $this->assertSame(100.0, $snappedPos, 'the seam re-reads the env');

        // ...and false animates while the re-read memo is on.
        [$overrideRefuses] = (new Spring(1.0 / 60.0, 6.0, 1.0, false))->update(0.0, 0.0, 100.0);
        $this->assertLessThan(100.0, $overrideRefuses, 'override false wins over the memoized on');
    }
}
