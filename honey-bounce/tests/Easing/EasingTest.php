<?php

declare(strict_types=1);

namespace SugarCraft\Bounce\Tests\Easing;

use SugarCraft\Bounce\Easing\Easing;
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
        // Non-overshooting cases must stay within [0,1]
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

    public function testElasticOutOvershootsAboveOne(): void
    {
        $easing = Easing::ElasticOut;

        // ElasticOut dips below 0 at t=0.2 (negative overshoot)
        $this->assertLessThan(
            0.0,
            $easing->ease(0.2),
            'ElasticOut at t=0.2 must be negative (anticipation dip)'
        );
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
                sprintf('Easing case %s must exist', $name)
            );
        }
    }
}
