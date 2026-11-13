<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests\Easing;

use SugarCraft\Spring\Easing\Easing;
use PHPUnit\Framework\TestCase;

final class EasingTest extends TestCase
{
    private const FLOAT_TOLERANCE = 0.0001;

    /**
     * @dataProvider allEasingCasesProvider
     */
    public function testBoundaryConditions(Easing $easing): void
    {
        $resultAt0 = $easing->ease(0.0);
        $resultAt1 = $easing->ease(1.0);

        // Use a wider tolerance for boundary conditions due to floating-point precision
        $this->assertEqualsWithDelta(
            0.0,
            $resultAt0,
            0.01,
            sprintf('%s at t=0 must be near 0', $easing->name)
        );

        $this->assertEqualsWithDelta(
            1.0,
            $resultAt1,
            0.01,
            sprintf('%s at t=1 must be near 1', $easing->name)
        );
    }

    public static function allEasingCasesProvider(): iterable
    {
        foreach (Easing::cases() as $case) {
            yield $case->name => [$case];
        }
    }

    public function testLinearReturnsInputUnchanged(): void
    {
        $linear = Easing::Linear;

        $this->assertSame(0.0, $linear->ease(0.0));
        $this->assertSame(0.25, $linear->ease(0.25));
        $this->assertSame(0.5, $linear->ease(0.5));
        $this->assertSame(0.75, $linear->ease(0.75));
        $this->assertSame(1.0, $linear->ease(1.0));
    }

    public function testQuadraticInBoundaryAndMidpoint(): void
    {
        $easing = Easing::QuadraticIn;

        $this->assertEqualsWithDelta(0.0, $easing->ease(0.0), self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(1.0, $easing->ease(1.0), self::FLOAT_TOLERANCE);
        // t=0.5 → 0.5 * 0.5 = 0.25
        $this->assertEqualsWithDelta(0.25, $easing->ease(0.5), self::FLOAT_TOLERANCE);
    }

    public function testQuadraticOutBoundaryAndMidpoint(): void
    {
        $easing = Easing::QuadraticOut;

        $this->assertEqualsWithDelta(0.0, $easing->ease(0.0), self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(1.0, $easing->ease(1.0), self::FLOAT_TOLERANCE);
        // t=0.5 → 0.5 * (2 - 0.5) = 0.5 * 1.5 = 0.75
        $this->assertEqualsWithDelta(0.75, $easing->ease(0.5), self::FLOAT_TOLERANCE);
    }

    /**
     * Note: QuadraticInOut, CubicInOut, BounceInOut, BackInOut are DESIGNED to equal
     * 0.5 at t=0.5 by definition (symmetric midpoint).
     */
    public function testQuadraticInOutAtMidpoint(): void
    {
        $easing = Easing::QuadraticInOut;
        $this->assertEqualsWithDelta(0.5, $easing->ease(0.5), self::FLOAT_TOLERANCE);
    }

    public function testCubicInOutAtMidpoint(): void
    {
        $easing = Easing::CubicInOut;
        $this->assertEqualsWithDelta(0.5, $easing->ease(0.5), self::FLOAT_TOLERANCE);
    }

    public function testBounceInOutAtMidpoint(): void
    {
        $easing = Easing::BounceInOut;
        $this->assertEqualsWithDelta(0.5, $easing->ease(0.5), self::FLOAT_TOLERANCE);
    }

    public function testBackInOutAtMidpoint(): void
    {
        $easing = Easing::BackInOut;
        $this->assertEqualsWithDelta(0.5, $easing->ease(0.5), self::FLOAT_TOLERANCE);
    }

    /**
     * Note: QuadraticInOut, CubicInOut, BounceInOut, BackInOut are DESIGNED to equal
     * 0.5 at t=0.5 by definition (symmetric midpoint). This test verifies that
     * at least one non-symmetric easing differs from linear at t=0.5.
     */
    public function testSomeEasingTypesDifferFromLinearAtMidpoint(): void
    {
        $linearMidpoint = Easing::Linear->ease(0.5);

        $differingEasings = array_filter(
            Easing::cases(),
            fn(Easing $e) => abs($e->ease(0.5) - $linearMidpoint) > self::FLOAT_TOLERANCE
        );

        $this->assertNotEmpty(
            $differingEasings,
            'At least some easing types must differ from linear at t=0.5'
        );
    }

    public function testQuadraticInIsAccelerating(): void
    {
        $easing = Easing::QuadraticIn;

        // QuadraticIn should be below linear (slow start)
        $this->assertLessThan(
            0.25,
            $easing->ease(0.25),
            'QuadraticIn at t=0.25 should be below linear (0.25)'
        );
    }

    public function testQuadraticOutIsDecelerating(): void
    {
        $easing = Easing::QuadraticOut;

        // QuadraticOut should be above linear (fast start)
        $this->assertGreaterThan(
            0.25,
            $easing->ease(0.25),
            'QuadraticOut at t=0.25 should be above linear (0.25)'
        );
    }

    public function testElasticOutDiffersFromLinear(): void
    {
        $easing = Easing::ElasticOut;

        // ElasticOut should differ from linear at several points
        $this->assertNotEquals(
            0.3,
            $easing->ease(0.3),
            'ElasticOut must not be linear at t=0.3'
        );
        $this->assertNotEquals(
            0.4,
            $easing->ease(0.4),
            'ElasticOut must not be linear at t=0.4'
        );

        // ElasticOut typically exceeds linear in early-mid range
        $resultAt05 = $easing->ease(0.5);
        $this->assertGreaterThan(
            0.45,
            $resultAt05,
            'ElasticOut at t=0.5 should be significantly ahead of 0.45'
        );
    }

    public function testBounceOutCharacteristicBounce(): void
    {
        $easing = Easing::BounceOut;

        // BounceOut should have values > linear at some points (the bounce)
        $bounceAt025 = $easing->ease(0.25);
        $bounceAt05 = $easing->ease(0.5);

        // Verify non-linearity
        $this->assertNotEquals(0.25, $bounceAt025);
        $this->assertNotEquals(0.5, $bounceAt05);

        // BounceOut should progress non-linearly with visible bounce effect
        $this->assertGreaterThan(
            0.2,
            $bounceAt025,
            'BounceOut first bounce should be visible'
        );

        $this->assertGreaterThan(
            0.5,
            $bounceAt05,
            'BounceOut mid-bounce should exceed 0.5'
        );
    }

    public function testBackOutDiffersFromLinear(): void
    {
        $easing = Easing::BackOut;

        // BackOut should differ from linear
        $this->assertNotEquals(0.3, $easing->ease(0.3));
        $this->assertNotEquals(0.4, $easing->ease(0.4));

        // At t=0.3, BackOut should exceed linear value of 0.3
        $this->assertGreaterThan(0.3, $easing->ease(0.3));
    }

    public function testCubicInProducesExpectedValues(): void
    {
        $easing = Easing::CubicIn;

        $this->assertEqualsWithDelta(0.0, $easing->ease(0.0), self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(1.0, $easing->ease(1.0), self::FLOAT_TOLERANCE);
        // t=0.5 → 0.5^3 = 0.125
        $this->assertEqualsWithDelta(0.125, $easing->ease(0.5), self::FLOAT_TOLERANCE);
    }

    public function testCubicOutProducesExpectedValues(): void
    {
        $easing = Easing::CubicOut;

        $this->assertEqualsWithDelta(0.0, $easing->ease(0.0), self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(1.0, $easing->ease(1.0), self::FLOAT_TOLERANCE);
        // t=0.5 → 1 - (1-0.5)^3 = 1 - 0.125 = 0.875
        $this->assertEqualsWithDelta(0.875, $easing->ease(0.5), self::FLOAT_TOLERANCE);
    }

    public function testElasticInProducesExpectedShape(): void
    {
        $easing = Easing::ElasticIn;

        // Canonical formula gives ~-0.0005 at t=0, not exactly 0
        $this->assertLessThan(
            0.001,
            abs($easing->ease(0.0)),
            'ElasticIn at t=0 should be near 0'
        );
        $this->assertEqualsWithDelta(1.0, $easing->ease(1.0), self::FLOAT_TOLERANCE);

        // ElasticIn starts slow and accelerates
        $earlyResult = $easing->ease(0.1);
        $this->assertLessThan(
            0.1,
            $earlyResult,
            'ElasticIn at t=0.1 should be below linear'
        );
    }

    public function testElasticInOutDiffersFromLinear(): void
    {
        $easing = Easing::ElasticInOut;

        $this->assertNotEquals(
            0.25,
            $easing->ease(0.25),
            'ElasticInOut must not be linear at t=0.25'
        );
        $this->assertNotEquals(
            0.75,
            $easing->ease(0.75),
            'ElasticInOut must not be linear at t=0.75'
        );
    }

    public function testBounceInProducesExpectedShape(): void
    {
        $easing = Easing::BounceIn;

        $this->assertEqualsWithDelta(0.0, $easing->ease(0.0), self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(1.0, $easing->ease(1.0), self::FLOAT_TOLERANCE);

        // BounceIn should start slow (entering the bounce)
        $earlyResult = $easing->ease(0.1);
        $this->assertLessThan(
            0.1,
            $earlyResult,
            'BounceIn at t=0.1 should be below linear'
        );
    }

    public function testBackInProducesExpectedShape(): void
    {
        $easing = Easing::BackIn;

        $this->assertEqualsWithDelta(0.0, $easing->ease(0.0), self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(1.0, $easing->ease(1.0), self::FLOAT_TOLERANCE);

        // BackIn shows characteristic overshoot behavior - negative values at start
        $this->assertLessThan(
            0.0,
            $easing->ease(0.3),
            'BackIn at t=0.3 should be negative (overshoot)'
        );
    }

    public function testResultIsAlwaysInValidRange(): void
    {
        // Non-overshooting cases must stay within [0, 1]
        $nonOvershootingEasings = [
            Easing::Linear,
            Easing::QuadraticIn,
            Easing::QuadraticOut,
            Easing::QuadraticInOut,
            Easing::CubicIn,
            Easing::CubicOut,
            Easing::CubicInOut,
            Easing::BounceIn,
            Easing::BounceOut,
            Easing::BounceInOut,
        ];

        foreach ($nonOvershootingEasings as $easing) {
            for ($t = 0.0; $t <= 1.0; $t += 0.1) {
                $result = $easing->ease($t);
                $this->assertGreaterThanOrEqual(
                    0.0,
                    $result,
                    sprintf('%s at t=%s must not be negative', $easing->name, $t)
                );
                $this->assertLessThanOrEqual(
                    1.0,
                    $result,
                    sprintf('%s at t=%s must not exceed 1', $easing->name, $t)
                );
            }
        }
    }

    /**
     * M-E (review 1102): the old spelling `sin(13πt/2)·2^(-10t) + t` dropped
     * the phase shift and added t instead of 1 — an "ease-out" that DIPPED
     * NEGATIVE at t=0.2 (-0.0023) while endpoint pins stayed green. The
     * canonical curve returns 1.125 at t=0.2 and never leaves (0, 1.26]
     * after the start. The previous version of this test asserted the BROKEN
     * dip ("must be negative"); flipping it is the point.
     */
    public function testElasticOutOvershootsAboveOne(): void
    {
        $easing = Easing::ElasticOut;

        $this->assertEqualsWithDelta(
            1.125,
            $easing->ease(0.2),
            1e-9,
            'ElasticOut at t=0.2 must return the canonical 2^(-2)·sin(1.25·2π/3)+1 = 1.125'
        );

        // A rubber-band ease-out rings AROUND 1 — it must never dip back
        // below the start anywhere on the sampled curve (the broken form did).
        for ($t = 0.01; $t <= 1.0; $t += 0.01) {
            $this->assertGreaterThan(
                0.0,
                $easing->ease($t),
                sprintf('ElasticOut must stay positive past t=%s', number_format($t, 2))
            );
        }
    }

    public function testElasticCurveEndpointsAreExact(): void
    {
        // Guards removed the faithful-but-imprecise endpoint residues
        // (ElasticIn(0) = -4.88e-4, BackOut(0) = 1.22e-16).
        $this->assertSame(0.0, Easing::ElasticIn->ease(0.0));
        $this->assertSame(1.0, Easing::ElasticIn->ease(1.0));
        $this->assertSame(0.0, Easing::ElasticOut->ease(0.0));
        $this->assertSame(1.0, Easing::ElasticOut->ease(1.0));
        $this->assertSame(0.0, Easing::ElasticInOut->ease(0.0));
        $this->assertSame(1.0, Easing::ElasticInOut->ease(1.0));
        $this->assertSame(0.0, Easing::BackOut->ease(0.0));
        $this->assertSame(1.0, Easing::BackOut->ease(1.0));
    }

    /**
     * M-F (review 1102): the old angular constant M_PI*2.5 left the two
     * halves of ElasticInOut disagreeing by 0.444 across the t=0.5 frame
     * (left limit 0.2778 vs value 0.7222 — a renderer visibly teleported
     * the object). Continuity requires (2π)/4.5: both halves return 0.5.
     */
    public function testElasticInOutIsContinuousAtMidpoint(): void
    {
        $easing = Easing::ElasticInOut;

        $this->assertEqualsWithDelta(0.5, $easing->ease(0.5), 1e-9);

        $left  = $easing->ease(0.5 - 1e-9);
        $right = $easing->ease(0.5 + 1e-9);
        $this->assertLessThan(
            1e-6,
            abs($left - $right),
            'ElasticInOut must not jump across the half-way frame'
        );
    }

    public function testElasticOutPeaksNearCanonicalAmplitude(): void
    {
        // The reference first peak is ~1.0058 at t≈0.18; the broken form's
        // max was 1.0010 with the oscillation smothered — sampling the band
        // pins the real rubber band, not just the endpoints.
        $peak = 0.0;
        for ($t = 0.15; $t <= 0.25; $t += 0.001) {
            $peak = max($peak, Easing::ElasticOut->ease($t));
        }
        $this->assertGreaterThan(1.12, $peak, 'ElasticOut must overshoot past 1.12');
    }

    public function testBackOvershootsOutsideUnitInterval(): void
    {
        $easing = Easing::BackIn;

        // BackIn produces negative values at t=0.3 (overshoot below 0)
        $this->assertLessThan(
            0.0,
            $easing->ease(0.3),
            'BackIn at t=0.3 must be negative (backward overshoot)'
        );
    }

    public function testAllEasingTypesExist(): void
    {
        $expectedCases = [
            'Linear',
            'QuadraticIn', 'QuadraticOut', 'QuadraticInOut',
            'CubicIn', 'CubicOut', 'CubicInOut',
            'ElasticIn', 'ElasticOut', 'ElasticInOut',
            'BounceIn', 'BounceOut', 'BounceInOut',
            'BackIn', 'BackOut', 'BackInOut',
        ];

        $actualCases = array_map(fn(Easing $e) => $e->name, Easing::cases());

        foreach ($expectedCases as $name) {
            $this->assertContains(
                $name,
                $actualCases,
                sprintf('Easing case %s must exist', $name),
            );
        }
    }

    /**
     * SPR-07 (finding 063): the documented non-finite passthrough contract —
     * Easing.php class docblock and README — says NAN returns NAN for EVERY
     * case, ±INF survives the polynomial curves with the sign their leading
     * terms dictate, and flips to NAN inside the sin-modulated Elastic and
     * Back trig. Until now that prose had zero test coverage (the sibling
     * CubicBezier refusal was pinned; this half of the asymmetry was not).
     * Signs are the measured table, pinned exactly so a formula rewrite that
     * changes a sign is a disclosed contract change, not a silent one.
     *
     * @dataProvider nonFinitePassthroughProvider
     */
    public function testDocumentedNonFinitePassthroughContract(
        Easing $case,
        float $expectedAtPlusInf,
        float $expectedAtMinusInf,
    ): void {
        $this->assertNan($case->ease(NAN), "{$case->name} must return NAN for NAN");

        foreach (['+INF' => $expectedAtPlusInf, '-INF' => $expectedAtMinusInf] as $label => $expected) {
            $actual = $case->ease($label === '+INF' ? INF : -INF);
            if (is_nan($expected)) {
                $this->assertNan($actual, "{$case->name} must flip {$label} to NAN inside its trig");
            } else {
                $this->assertSame($expected, $actual, "{$case->name} must carry {$label} through");
            }
        }
    }

    /** @return array<string, array{Easing, float, float}> */
    public static function nonFinitePassthroughProvider(): array
    {
        return [
            //     case                +INF        -INF
            'Linear'           => [Easing::Linear,           INF,  -INF],
            'QuadraticIn'      => [Easing::QuadraticIn,      INF,   INF],
            'QuadraticOut'     => [Easing::QuadraticOut,    -INF,  -INF],
            'QuadraticInOut'   => [Easing::QuadraticInOut,  -INF,   INF],
            'CubicIn'          => [Easing::CubicIn,          INF,  -INF],
            'CubicOut'         => [Easing::CubicOut,         INF,  -INF],
            'CubicInOut'       => [Easing::CubicInOut,       INF,  -INF],
            'ElasticIn'        => [Easing::ElasticIn,       NAN,   NAN],
            'ElasticOut'       => [Easing::ElasticOut,      NAN,   NAN],
            'ElasticInOut'     => [Easing::ElasticInOut,    NAN,   NAN],
            'BounceIn'         => [Easing::BounceIn,        -INF,  -INF],
            'BounceOut'        => [Easing::BounceOut,        INF,   INF],
            'BounceInOut'      => [Easing::BounceInOut,      INF,  -INF],
            'BackIn'           => [Easing::BackIn,          NAN,   NAN],
            'BackOut'          => [Easing::BackOut,         NAN,   NAN],
            'BackInOut'        => [Easing::BackInOut,       NAN,   NAN],
        ];
    }

    /**
     * The two-side contract lives in one class — pin the CONTRAST directly:
     * the enum flows, the bezier refuses.
     */
    public function testNonFinitePolicyIsTheOppositeOfTheBezierRefusal(): void
    {
        $this->assertNan(Easing::CubicIn->ease(NAN), 'enum must flow NAN through');

        try {
            \SugarCraft\Spring\Easing\CubicBezier::cssEase()->ease(NAN);
            $this->fail('CubicBezier must refuse a non-finite t outright');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('t must be finite', $error->getMessage());
        }
    }
}
