<?php

declare(strict_types=1);

namespace SugarCraft\Bounce\Tests;

use SugarCraft\Bounce\Spring;
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
    }

    protected function tearDown(): void
    {
        putenv('REDUCE_MOTION');
        putenv('PREFERS_REDUCED_MOTION');
        parent::tearDown();
    }

    public function testNormalMotionAnimatesGradually(): void
    {
        putenv('REDUCE_MOTION=0');

        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        [$pos, $vel] = $spring->update(0.0, 0.0, 100.0);

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
        [$posNormal, $velNormal] = $spring->update(0.0, 0.0, 100.0);

        // Enable reduced motion
        putenv('REDUCE_MOTION=1');

        // Create new spring instance after env change
        $springReduced = new Spring(1.0 / 60.0, 6.0, 1.0);
        [$posReduced, $velReduced] = $springReduced->update(0.0, 0.0, 100.0);

        // Reduced motion should snap immediately
        $this->assertEqualsWithDelta(100.0, $posReduced, 0.001);
        $this->assertEqualsWithDelta(0.0, $velReduced, 0.001);

        // Normal motion should not snap
        $this->assertLessThan(100.0, $posNormal);
    }
}
