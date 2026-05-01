<?php

declare(strict_types=1);

namespace CandyCore\Bounce\Tests;

use CandyCore\Bounce\Spring;
use PHPUnit\Framework\TestCase;

final class SpringTest extends TestCase
{
    private const EPS = 1e-9;

    public function testFpsHelper(): void
    {
        $this->assertEqualsWithDelta(1.0 / 60.0, Spring::fps(60), self::EPS);
        $this->assertEqualsWithDelta(1.0 / 30.0, Spring::fps(30), self::EPS);
    }

    public function testFpsRejectsNonPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Spring::fps(0);
    }

    public function testZeroFrequencyIsIdentity(): void
    {
        $s = new Spring(1.0 / 60.0, 0.0, 1.0);
        [$pos, $vel] = $s->update(5.0, 2.0, 100.0);
        $this->assertEqualsWithDelta(5.0, $pos, self::EPS);
        $this->assertEqualsWithDelta(2.0, $vel, self::EPS);
    }

    public function testAtTargetWithZeroVelocityStaysPut(): void
    {
        $s = new Spring(1.0 / 60.0, 6.0, 0.5);
        [$pos, $vel] = $s->update(10.0, 0.0, 10.0);
        $this->assertEqualsWithDelta(10.0, $pos, self::EPS);
        $this->assertEqualsWithDelta(0.0,  $vel, self::EPS);
    }

    public function testCriticallyDampedConverges(): void
    {
        $s = new Spring(1.0 / 60.0, 6.0, 1.0);
        $pos = 0.0;
        $vel = 0.0;
        $target = 100.0;

        // Critical damping: monotonic approach, no overshoot.
        $prev = $pos;
        for ($i = 0; $i < 600; $i++) {
            [$pos, $vel] = $s->update($pos, $vel, $target);
            $this->assertGreaterThanOrEqual($prev - 1e-6, $pos, "regressed at step $i");
            $this->assertLessThanOrEqual($target + 1e-6, $pos, "overshot at step $i");
            $prev = $pos;
        }
        $this->assertEqualsWithDelta(100.0, $pos, 1e-3);
        $this->assertEqualsWithDelta(0.0,   $vel, 1e-3);
    }

    public function testUnderDampedOvershoots(): void
    {
        $s = new Spring(1.0 / 60.0, 12.0, 0.2);
        $pos = 0.0;
        $vel = 0.0;
        $target = 10.0;

        $maxPos = 0.0;
        for ($i = 0; $i < 60; $i++) {
            [$pos, $vel] = $s->update($pos, $vel, $target);
            $maxPos = max($maxPos, $pos);
        }
        $this->assertGreaterThan($target, $maxPos);
    }

    public function testOverDampedConvergesWithoutOvershoot(): void
    {
        $s = new Spring(1.0 / 60.0, 6.0, 2.0);
        $pos = 0.0;
        $vel = 0.0;
        $target = 10.0;

        for ($i = 0; $i < 600; $i++) {
            [$pos, $vel] = $s->update($pos, $vel, $target);
            $this->assertLessThanOrEqual($target + 1e-6, $pos);
        }
        $this->assertEqualsWithDelta(10.0, $pos, 1e-3);
    }

    public function testNegativeDampingClampedToZero(): void
    {
        // Should not throw or NaN; just behaves as undamped (or near it).
        $s = new Spring(1.0 / 60.0, 6.0, -0.5);
        [$pos, $vel] = $s->update(0.0, 0.0, 1.0);
        $this->assertIsFloat($pos);
        $this->assertFalse(is_nan($pos));
        $this->assertFalse(is_nan($vel));
    }

    /**
     * Snapshot test: critically damped spring at frame 30 reaches a
     * deterministic intermediate position. If the underlying math drifts the
     * value should not change without a deliberate update.
     */
    public function testCriticallyDampedSnapshot(): void
    {
        $s = new Spring(1.0 / 60.0, 6.0, 1.0);
        $pos = 0.0;
        $vel = 0.0;
        for ($i = 0; $i < 30; $i++) {
            [$pos, $vel] = $s->update($pos, $vel, 100.0);
        }
        // After 0.5s: 1 - (1+ω*t)*e^(-ω*t) with ω=6, t=0.5 → 1 - 4*e^-3 ≈ 0.800852.
        $this->assertEqualsWithDelta(80.0852, $pos, 0.05);
    }
}
