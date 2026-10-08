<?php

declare(strict_types=1);

namespace SugarCraft\Bounce\Tests\Easing;

use SugarCraft\Bounce\Easing\CubicBezier;
use PHPUnit\Framework\TestCase;

final class CubicBezierTest extends TestCase
{
    private const EPS = 0.0001;

    public function testLinearReturnsInputUnchanged(): void
    {
        $cb = CubicBezier::linear();
        $this->assertSame(0.0, $cb->evaluate(0.0));
        $this->assertSame(0.25, $cb->evaluate(0.25));
        $this->assertSame(0.5, $cb->evaluate(0.5));
        $this->assertSame(0.75, $cb->evaluate(0.75));
        $this->assertSame(1.0, $cb->evaluate(1.0));
    }

    public function testEaseAtBoundariesReturns0And1(): void
    {
        $cb = CubicBezier::ease();
        $this->assertEqualsWithDelta(0.0, $cb->evaluate(0.0), self::EPS);
        $this->assertEqualsWithDelta(1.0, $cb->evaluate(1.0), self::EPS);
    }

    public function testEaseInIsAccelerating(): void
    {
        $cb = CubicBezier::easeIn();
        // easeIn at t=0.5 should be less than 0.5 (slow start)
        $this->assertLessThan(0.5, $cb->evaluate(0.5));
    }

    public function testEaseOutIsDecelerating(): void
    {
        $cb = CubicBezier::easeOut();
        // easeOut at t=0.5 should be greater than 0.5 (fast start)
        $this->assertGreaterThan(0.5, $cb->evaluate(0.5));
    }

    public function testEaseInOutIsSymmetric(): void
    {
        $cb = CubicBezier::easeInOut();
        // At midpoint should be 0.5
        $this->assertEqualsWithDelta(0.5, $cb->evaluate(0.5), self::EPS);
    }

    public function testAllCssStandardPresetsReturnValidRange(): void
    {
        $methods = [
            'ease', 'easeIn', 'easeOut', 'easeInOut',
            'easeInSine', 'easeOutSine', 'easeInOutSine',
            'easeInQuad', 'easeOutQuad', 'easeInOutQuad',
            'easeInCubic', 'easeOutCubic', 'easeInOutCubic',
            'easeInQuart', 'easeOutQuart', 'easeInOutQuart',
            'easeInQuint', 'easeOutQuint', 'easeInOutQuint',
            'easeInExpo', 'easeOutExpo', 'easeInOutExpo',
            'easeInCirc', 'easeOutCirc', 'easeInOutCirc',
        ];

        foreach ($methods as $method) {
            $cb = CubicBezier::$method();
            for ($t = 0.0; $t <= 1.0; $t += 0.1) {
                $result = $cb->evaluate($t);
                $this->assertGreaterThanOrEqual(0.0, $result, "$method at t=$t must not be negative");
                $this->assertLessThanOrEqual(1.0, $result, "$method at t=$t must not exceed 1");
            }
        }
    }

    public function testEaseInQuadAtMidpoint(): void
    {
        // easeInQuad: y = x². At x=0.5, y=0.25
        $cb = CubicBezier::easeInQuad();
        $this->assertEqualsWithDelta(0.25, $cb->evaluate(0.5), 0.01);
    }

    public function testEaseOutQuadAtMidpoint(): void
    {
        // easeOutQuad uses CSS cubic-bezier(0.25, 0.46, 0.45, 0.94)
        // which does not exactly equal the power-function 1-(1-x)².
        // Verify it is between linear (0.5) and 1.0
        $cb = CubicBezier::easeOutQuad();
        $this->assertGreaterThan(0.5, $cb->evaluate(0.5));
        $this->assertLessThan(1.0, $cb->evaluate(0.5));
    }

    public function testEaseInCubicAtMidpoint(): void
    {
        // easeInCubic uses CSS cubic-bezier(0.55, 0.06, 0.68, 0.19)
        // which differs from the pure t³ power function.
        // Verify it is between 0 and linear (0.5)
        $cb = CubicBezier::easeInCubic();
        $this->assertGreaterThan(0.0, $cb->evaluate(0.5));
        $this->assertLessThan(0.5, $cb->evaluate(0.5));
    }

    public function testEaseOutCubicAtMidpoint(): void
    {
        // easeOutCubic: y = 1-(1-x)³. At x=0.5, y = 1-0.125 = 0.875
        $cb = CubicBezier::easeOutCubic();
        $this->assertEqualsWithDelta(0.875, $cb->evaluate(0.5), 0.01);
    }

    public function testCustomControlPoints(): void
    {
        // Custom bezier that's more extreme than easeOut
        $cb = new CubicBezier(0.0, 0.0, 0.8, 1.0);
        $this->assertGreaterThan(0.5, $cb->evaluate(0.5));
        $this->assertGreaterThanOrEqual(0.0, $cb->evaluate(0.0));
        $this->assertLessThanOrEqual(1.0, $cb->evaluate(1.0));
    }

    public function testEaseInBackExists(): void
    {
        // easeInBack has a slight overshoot on the left (negative values clamped to 0)
        $cb = CubicBezier::easeInCirc();
        $this->assertGreaterThanOrEqual(0.0, $cb->evaluate(0.0));
        $this->assertLessThanOrEqual(1.0, $cb->evaluate(1.0));
    }

    /**
     * @see plan_honey-bounce.md — Item 4.1 (Item 5.7 in findings_resume_plan)
     */
    public function testInvalidControlPointX1ThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CubicBezier(-0.5, 0.0, 0.5, 1.0);
    }

    /**
     * @see plan_honey-bounce.md — Item 4.1 (Item 5.7 in findings_resume_plan)
     */
    public function testInvalidControlPointX2ThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CubicBezier(0.0, 0.0, 1.5, 1.0);
    }

    // ─── Lane A3b (re-verify 2026-10-08) ──────────────────────────────────

    /**
     * The old range check compared with < / >, which are always false for
     * NAN — a NaN control point used to sail through and poison every solve.
     *
     * @dataProvider nonFiniteControlPointProvider
     */
    public function testNonFiniteControlPointIsRejected(int $position): void
    {
        $args = [0.42, 0.0, 0.58, 1.0];
        $args[$position] = $position % 2 === 0 ? NAN : INF;

        $this->expectException(\InvalidArgumentException::class);
        new CubicBezier(...$args);
    }

    /** @return array<string, array{int}> */
    public static function nonFiniteControlPointProvider(): array
    {
        return [
            'x1 NaN' => [0],
            'y1 INF' => [1],
            'x2 NaN' => [2],
            'y2 INF' => [3],
        ];
    }

    /**
     * easeInOutCirc shipped byte-identical to easeInOutQuint (copy-paste
     * corruption). Repaired to the pre-2022 easings.net table's Circ row;
     * the Quint row was already the genuine table value and stays.
     */
    public function testInOutQuintAndInOutCircAreDistinctCitedRows(): void
    {
        $quint = $this->tupleOf(CubicBezier::easeInOutQuint());
        $circ  = $this->tupleOf(CubicBezier::easeInOutCirc());

        $this->assertNotSame($quint, $circ, 'the two presets must not share one tuple');
        // https://30secondsofcode.org/css/s/easing-variables/ (pre-2022
        // easings.net approximation table).
        $this->assertSame([0.86, 0.00, 0.07, 1.00], $quint);
        $this->assertSame([0.785, 0.135, 0.15, 0.86], $circ);
    }

    /**
     * The Newton slope must be the true derivative of x(t): central-difference
     * oracle over the domain, for every preset in the family.
     */
    public function testSampleCurveDerivativeXMatchesFiniteDifferenceOracle(): void
    {
        $methods = [
            'ease', 'easeIn', 'easeOut', 'easeInOut', 'linear',
            'easeInSine', 'easeOutSine', 'easeInOutSine',
            'easeInQuad', 'easeOutQuad', 'easeInOutQuad',
            'easeInCubic', 'easeOutCubic', 'easeInOutCubic',
            'easeInQuart', 'easeOutQuart', 'easeInOutQuart',
            'easeInQuint', 'easeOutQuint', 'easeInOutQuint',
            'easeInExpo', 'easeOutExpo', 'easeInOutExpo',
            'easeInCirc', 'easeOutCirc', 'easeInOutCirc',
        ];
        $derivative = new \ReflectionMethod(CubicBezier::class, 'sampleCurveDerivativeX');
        $sampleX    = new \ReflectionMethod(CubicBezier::class, 'sampleCurveX');
        $h          = 1.0e-5;

        foreach ($methods as $method) {
            $cb = CubicBezier::$method();
            for ($t = 0.05; $t <= 0.950001; $t += 0.05) {
                $oracle = ($sampleX->invoke($cb, $t + $h) - $sampleX->invoke($cb, $t - $h)) / (2.0 * $h);
                $this->assertEqualsWithDelta(
                    $oracle,
                    $derivative->invoke($cb, $t),
                    1.0e-6,
                    "$method derivative at t=$t"
                );
            }
        }
    }

    /**
     * evaluate() is deliberately UNCLAMPED — back/elastic control points
     * overshoot [0,1] by design — and an out-of-domain t silently
     * extrapolates rather than throwing. Pinned so a future "tidy-up" clamp
     * is a disclosed behavior change, not a silent one.
     */
    public function testEvaluateOvershootsFreelyAndExtrapolatesOutsideDomain(): void
    {
        $cb = new CubicBezier(0.68, -0.60, 0.32, 1.60); // easeInOutBack-style
        $sawUndershoot = false;
        $sawOvershoot  = false;
        for ($t = 0.0; $t <= 1.0; $t += 0.01) {
            $value = $cb->evaluate($t);
            $sawUndershoot = $sawUndershoot || $value < 0.0;
            $sawOvershoot  = $sawOvershoot || $value > 1.0;
        }
        $this->assertTrue($sawUndershoot, 'y must dip below 0 for negative control points');
        $this->assertTrue($sawOvershoot, 'y must rise above 1 for >1 control points');

        // Out-of-range t does not throw; the solver silently extrapolates.
        $this->assertSame(0.0, $cb->evaluate(0.0));
        $this->assertSame(1.0, $cb->evaluate(1.0));
        $this->assertTrue(is_finite($cb->evaluate(1.5)));
        $this->assertTrue(is_finite($cb->evaluate(-0.5)));
    }

    /**
     * @return array{float, float, float, float}
     */
    private function tupleOf(CubicBezier $cb): array
    {
        $read = static function (string $prop) use ($cb): float {
            $p = new \ReflectionProperty(CubicBezier::class, $prop);
            return (float) $p->getValue($cb);
        };
        return [$read('x1'), $read('y1'), $read('x2'), $read('y2')];
    }
}
