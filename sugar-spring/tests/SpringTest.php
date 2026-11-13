<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests;

use SugarCraft\Spring\Spring;
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

    public function testZeroFrequencyIsRefused(): void
    {
        // M-A: a spring that cannot oscillate never settles — the old silent
        // identity branch turned every `while (!$spring->settled(...))` loop
        // into an infinite frame loop.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('angularFrequency must be >= 1e-9');

        new Spring(1.0 / 60.0, 0.0, 1.0);
    }

    public function testTinyFrequencyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Spring(1.0 / 60.0, 1e-12, 1.0);
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

    public function testNegativeDampingIsRefused(): void
    {
        // M-A: negative damping is the divergence case (exponential blow-up);
        // the old max(0.0, ·) clamp folded it into the never-settling pure
        // oscillator instead of refusing it.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dampingRatio must be > 0');

        new Spring(1.0 / 60.0, 6.0, -0.5);
    }

    public function testZeroDampingIsRefused(): void
    {
        // ζ=0 is the perpetual oscillator: never settles, so the boundary
        // refuses it rather than shipping the hang.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dampingRatio must be > 0');

        new Spring(1.0 / 60.0, 6.0, 0.0);
    }

    public function testSettledTrueAtRest(): void
    {
        // Exactly at target with zero velocity.
        $this->assertTrue(Spring::settled(10.0, 0.0, 10.0));
        // Just inside the default threshold (1e-4) on both axes.
        $this->assertTrue(Spring::settled(10.00005, 0.00005, 10.0));
    }

    public function testSettledFalseInMotion(): void
    {
        // Both displacement and velocity clearly past threshold.
        $this->assertFalse(Spring::settled(5.0, 2.0, 10.0));
        // Velocity alone past threshold.
        $this->assertFalse(Spring::settled(10.0, 0.5, 10.0));
        // Displacement alone past threshold.
        $this->assertFalse(Spring::settled(9.0, 0.0, 10.0));
    }

    public function testSettledRespectsCustomThreshold(): void
    {
        // 3e-4 displacement: settled under a looser 5e-4 band (SpringChain's),
        // but not under the default 1e-4 band.
        $this->assertFalse(Spring::settled(10.0003, 0.0, 10.0));
        $this->assertTrue(Spring::settled(10.0003, 0.0, 10.0, 0.0005));
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

    // ─── Construction fail-fast (re-verified 2026-10-08) ───────────────────

    /**
     * INF dampingRatio used to flow into the over-damped branch where
     * 0·INF → NAN coefficients, poisoning EVERY later update() with NAN
     * frames after the constructor — far from its cause.
     */
    public function testInfiniteDampingRatioThrowsAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Spring(1.0 / 60.0, 6.0, INF);
    }

    public function testNanAngularFrequencyThrowsAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Spring(1.0 / 60.0, NAN, 1.0);
    }

    public function testInfiniteDeltaTimeThrowsAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Spring(INF, 6.0, 1.0);
    }

    /**
     * Negative dt integrates the oscillator backwards; exp() diverges.
     */
    public function testNegativeDeltaTimeThrowsAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Spring(-1.0 / 60.0, 6.0, 1.0);
    }

    public function testZeroDeltaTimeThrowsAtConstruction(): void
    {
        // Step 33 (docs-fix-1104 M2): dt=0 produced identity coefficients —
        // a frozen spring whose documented `while (!settled(...))` loop never
        // ends. The door is exactly positive now, like every other door on
        // this constructor.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('deltaTime must be > 0');

        new Spring(0.0, 6.0, 1.0);
    }

    public function testSubEpsilonPositiveDeltaTimeIsAccepted(): void
    {
        // The refusal is non-positive, NOT smaller-than-some-floor: a small
        // positive dt is slow rather than illegal. At 1e-8 (below any frame
        // scale, near where float cancellation starts swallowing the step —
        // 1e-9 already rounds to a per-frame identity) the spring still
        // constructs and still advances the state.
        $s = new Spring(1e-8, 6.0, 1.0);
        [$pos] = $s->update(0.0, 0.0, 100.0);
        $this->assertGreaterThan(0.0, $pos);
    }

    // ─── Review-1102 M-A: per-frame data plane ─────────────────────────────

    /**
     * A NaN/INF frame value poisoned `settled()` forever — the whole
     * `while (!$chain->isComplete())` idiom spun on it. update() is the data
     * plane the ctor never saw, so it guards its own three inputs.
     */
    public function testUpdateRejectsNonFiniteState(): void
    {
        $s = new Spring(1.0 / 60.0, 6.0, 1.0);

        foreach ([
            [NAN, 0.0, 100.0],
            [0.0, NAN, 100.0],
            [0.0, 0.0, NAN],
            [INF, 0.0, 100.0],
            [0.0, 0.0, INF],
        ] as [$pos, $vel, $target]) {
            try {
                $s->update($pos, $vel, $target);
                $this->fail('update() accepted a non-finite frame value');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('must be finite', $e->getMessage());
            }
        }
    }

    /**
     * The data-plane guard runs BEFORE the reduced-motion snap branch: the
     * snap path returns the caller's target verbatim, so NaN/INF would ride
     * the "instant" branch unguarded otherwise.
     */
    public function testReducedMotionSnapStillRefusesNonFiniteTarget(): void
    {
        $s = new Spring(1.0 / 60.0, 6.0, 1.0, reducedMotionOverride: true);

        $this->expectException(\InvalidArgumentException::class);
        $s->update(0.0, 0.0, NAN);
    }

    /**
     * Large-but-finite parameters can overflow the closed-form coefficients
     * (ζ² terms blow up around ω ≈ 1e155); the post-branch belt refuses them
     * instead of minting a NaN-coefficient spring.
     */
    public function testOverflowingCoefficientsAreRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('overflowed to non-finite');

        // senior M3's exact scenario: ζ² overflows to INF inside the
        // over-damped branch while every INPUT passes is_finite.
        new Spring(1.0 / 60.0, 1e155, 1e155);
    }

    public function testFpsRejectsNonFiniteRates(): void
    {
        foreach ([INF, NAN] as $bad) {
            try {
                Spring::fps($bad);
                $this->fail('fps() accepted a non-finite rate');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('fps must be > 0', $e->getMessage());
            }
        }
    }

    /**
     * SPR-10 (finding 012): finite and positive is not sufficient — a rate
     * whose RECIPROCAL overflows used to walk out of the door as INF (the
     * frozen step the docblock promises callers never hold). fps(1e-310)
     * was probe-confirmed live. Boundary pair pinned: 5e-324 (largest
     * positive sub-normal) refuses, PHP_FLOAT_MIN (reciprocal ≈ 4.49e307,
     * still finite) answers.
     */
    public function testFpsRefusesRatesWhoseReciprocalOverflows(): void
    {
        foreach ([1e-310, 1e-320, 5e-324] as $tiny) {
            try {
                Spring::fps($tiny);
                $this->fail('fps() handed out a non-finite frame time for ' . var_export($tiny, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('large enough', $e->getMessage());
            }
        }

        $this->assertSame(1.0 / PHP_FLOAT_MIN, Spring::fps(PHP_FLOAT_MIN));
    }

    /**
     * settled() is a pure predicate: non-finite inputs answer "not settled"
     * (safe direction) rather than throwing — but the update() guard above
     * means real callers cannot reach the predicate with poisoned values.
     */
    public function testSettledAnswersFalseForNonFiniteInputs(): void
    {
        $this->assertFalse(Spring::settled(NAN, 0.0, 0.0));
        $this->assertFalse(Spring::settled(0.0, INF, 0.0));
    }

    public function testFromPresetThreadsReducedMotionOverride(): void
    {
        // The ctor-only override was unreachable on the recommended
        // fromPreset()/spring() path; it is now an optional parameter.
        $calm = Spring::fromPreset(
            \SugarCraft\Spring\SpringPreset::Stiff,
            1.0 / 60.0,
            reducedMotionOverride: true,
        );
        $this->assertSame([42.0, 0.0], $calm->update(0.0, 0.0, 42.0));

        $moving = Spring::fromPreset(
            \SugarCraft\Spring\SpringPreset::Stiff,
            1.0 / 60.0,
            reducedMotionOverride: false,
        );
        $this->assertNotSame([42.0, 0.0], $moving->update(0.0, 0.0, 42.0));
    }
}
