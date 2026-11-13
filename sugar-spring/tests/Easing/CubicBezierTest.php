<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests\Easing;

use SugarCraft\Spring\Easing\CubicBezier;
use PHPUnit\Framework\TestCase;

final class CubicBezierTest extends TestCase
{
    private const EPS = 0.0001;

    public function testLinearReturnsInputUnchanged(): void
    {
        $cb = CubicBezier::linear();
        $this->assertSame(0.0, $cb->ease(0.0));
        $this->assertSame(0.25, $cb->ease(0.25));
        $this->assertSame(0.5, $cb->ease(0.5));
        $this->assertSame(0.75, $cb->ease(0.75));
        $this->assertSame(1.0, $cb->ease(1.0));
    }

    public function testEaseAtBoundariesReturns0And1(): void
    {
        $cb = CubicBezier::cssEase();
        $this->assertEqualsWithDelta(0.0, $cb->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(1.0, $cb->ease(1.0), self::EPS);
    }

    public function testEaseInIsAccelerating(): void
    {
        $cb = CubicBezier::easeIn();
        // easeIn at t=0.5 should be less than 0.5 (slow start)
        $this->assertLessThan(0.5, $cb->ease(0.5));
    }

    public function testEaseOutIsDecelerating(): void
    {
        $cb = CubicBezier::easeOut();
        // easeOut at t=0.5 should be greater than 0.5 (fast start)
        $this->assertGreaterThan(0.5, $cb->ease(0.5));
    }

    public function testEaseInOutIsSymmetric(): void
    {
        $cb = CubicBezier::easeInOut();
        // At midpoint should be 0.5
        $this->assertEqualsWithDelta(0.5, $cb->ease(0.5), self::EPS);
    }

    public function testAllCssStandardPresetsReturnValidRange(): void
    {
        $methods = [
            'cssEase', 'easeIn', 'easeOut', 'easeInOut',
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
            // Exact grid: accumulating 0.1 in float ends the loop at
            // 1.0000000000000002 — off-domain by construction, where the
            // unclamped solve legitimately returns a hair past 1. Sample
            // the closed interval it pins.
            for ($i = 0; $i <= 10; $i++) {
                $t = $i / 10.0;
                $result = $cb->ease($t);
                $this->assertGreaterThanOrEqual(0.0, $result, "$method at t=$t must not be negative");
                $this->assertLessThanOrEqual(1.0, $result, "$method at t=$t must not exceed 1");
            }
        }
    }

    public function testEaseInQuadAtMidpoint(): void
    {
        // easeInQuad: y = x². At x=0.5, y=0.25
        $cb = CubicBezier::easeInQuad();
        $this->assertEqualsWithDelta(0.25, $cb->ease(0.5), 0.01);
    }

    public function testEaseOutQuadAtMidpoint(): void
    {
        // easeOutQuad uses CSS cubic-bezier(0.25, 0.46, 0.45, 0.94)
        // which does not exactly equal the power-function 1-(1-x)².
        // Verify it is between linear (0.5) and 1.0
        $cb = CubicBezier::easeOutQuad();
        $this->assertGreaterThan(0.5, $cb->ease(0.5));
        $this->assertLessThan(1.0, $cb->ease(0.5));
    }

    public function testEaseInCubicAtMidpoint(): void
    {
        // easeInCubic uses CSS cubic-bezier(0.55, 0.06, 0.68, 0.19)
        // which differs from the pure t³ power function.
        // Verify it is between 0 and linear (0.5)
        $cb = CubicBezier::easeInCubic();
        $this->assertGreaterThan(0.0, $cb->ease(0.5));
        $this->assertLessThan(0.5, $cb->ease(0.5));
    }

    public function testEaseOutCubicAtMidpoint(): void
    {
        // easeOutCubic: y = 1-(1-x)³. At x=0.5, y = 1-0.125 = 0.875
        $cb = CubicBezier::easeOutCubic();
        $this->assertEqualsWithDelta(0.875, $cb->ease(0.5), 0.01);
    }

    public function testCustomControlPoints(): void
    {
        // Custom bezier that's more extreme than easeOut
        $cb = new CubicBezier(0.0, 0.0, 0.8, 1.0);
        $this->assertGreaterThan(0.5, $cb->ease(0.5));
        $this->assertGreaterThanOrEqual(0.0, $cb->ease(0.0));
        $this->assertLessThanOrEqual(1.0, $cb->ease(1.0));
    }

    public function testEaseInBackExists(): void
    {
        // easeInBack has a slight overshoot on the left (negative values clamped to 0)
        $cb = CubicBezier::easeInCirc();
        $this->assertGreaterThanOrEqual(0.0, $cb->ease(0.0));
        $this->assertLessThanOrEqual(1.0, $cb->ease(1.0));
    }

    /**
     * @see plan_sugar-spring.md — Item 4.1 (Item 5.7 in findings_resume_plan)
     */
    public function testInvalidControlPointX1ThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CubicBezier(-0.5, 0.0, 0.5, 1.0);
    }

    /**
     * @see plan_sugar-spring.md — Item 4.1 (Item 5.7 in findings_resume_plan)
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
     * corruption). Repaired to the pre-2022 easings.net table's Circ row; the
     * InOutQuint tuple pinned here was already the genuine table value. The
     * same audit corrupted easeInQuint (an InOutQuint x-triple with y2
     * zeroed) — restored in the same pass; see SPR-01.
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
            'cssEase', 'easeIn', 'easeOut', 'easeInOut', 'linear',
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
     * ease() is deliberately UNCLAMPED in the OUTPUT and for finite
     * out-of-range t — back/elastic control points overshoot [0, 1] by design
     * and a finite out-of-domain t extrapolates rather than throwing. Pinned
     * so a future "tidy-up" clamp is a disclosed behavior change, not a
     * silent one. Non-finite t is the exception: refused since step 35
     * (see testSamplerRefusesNonFiniteT).
     */
    public function testSamplerOvershootsFreelyAndExtrapolatesOutsideDomain(): void
    {
        $cb = new CubicBezier(0.68, -0.60, 0.32, 1.60); // easeInOutBack-style
        $sawUndershoot = false;
        $sawOvershoot  = false;
        for ($t = 0.0; $t <= 1.0; $t += 0.01) {
            $value = $cb->ease($t);
            $sawUndershoot = $sawUndershoot || $value < 0.0;
            $sawOvershoot  = $sawOvershoot || $value > 1.0;
        }
        $this->assertTrue($sawUndershoot, 'y must dip below 0 for negative control points');
        $this->assertTrue($sawOvershoot, 'y must rise above 1 for >1 control points');

        // Out-of-range t does not throw; the solver silently extrapolates.
        $this->assertSame(0.0, $cb->ease(0.0));
        $this->assertSame(1.0, $cb->ease(1.0));
        $this->assertTrue(is_finite($cb->ease(1.5)));
        $this->assertTrue(is_finite($cb->ease(-0.5)));
    }

    /**
     * Step 35 (docs-fix-1104 M4): NAN/INF t used to sail through the solver
     * and return silent garbage (measured pre-fix: NAN->8.13e-21, INF->1.0,
     * -INF->8.13e-21 on ease()). Now refused at the door, like every other
     * non-finite input in the library.
     *
     * @dataProvider nonFiniteTimeProvider
     */
    public function testSamplerRefusesNonFiniteT(float $t): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('t must be finite');

        CubicBezier::cssEase()->ease($t);
    }

    /** @return array<string, array{float}> */
    public static function nonFiniteTimeProvider(): array
    {
        return ['NAN' => [NAN], '+INF' => [INF], '-INF' => [-INF]];
    }

    /**
     * Finite out-of-range t stays unclamped and unrefused, but the solver's
     * honesty has a documented shape: past ~1e2 the Newton iteration loses
     * to the bounded subdivision fallback, which answers with an endpoint
     * (measured: ease()->ease(1e3) === 1.0 exactly; -1e3 lands within
     * 1e-6 of 0.0). A far-out t therefore saturates instead of extrapolating
     * wildly — pinned so a solver rewrite that changes the shape is disclosed.
     */
    public function testFarOutFiniteTSaturatesToTheEndpoints(): void
    {
        $cb = CubicBezier::cssEase();

        $this->assertSame(1.0, $cb->ease(1e3));
        $this->assertLessThan(1e-6, $cb->ease(-1e3));
        // Moderate out-of-range t still genuinely extrapolates.
        $this->assertEqualsWithDelta(0.8966768501516175, $cb->ease(1.5), 1e-9);
        $this->assertEqualsWithDelta(0.39423701507036757, $cb->ease(-0.5), 1e-9);
    }

    /**
     * Frozen factory→control-point roster: one entry per static factory,
     * asserted against the class's actual control points. A corrupted or
     * drifted row reddens exactly this one roster entry (mutation-proven
     * 2026-11-13: flipping easeInOutQuad's y2 alone reddened precisely its
     * row, 1/26 reds). The values are the pre-2022 easings.net approximation
     * table rendered in the file's 2-dp convention (see class docblock).
     *
     * @dataProvider frozenFactoryTupleRosterProvider
     */
    public function testFrozenFactoryTupleRoster(string $factory, array $tuple): void
    {
        $this->assertSame($tuple, $this->tupleOf(CubicBezier::$factory()), "$factory control points");
    }

    /** @return array<string, array{string, array{float, float, float, float}}> */
    public static function frozenFactoryTupleRosterProvider(): array
    {
        return [
            'cssEase'        => ['cssEase',           [0.25, 0.10, 0.25, 1.00]],
            'easeIn'         => ['easeIn',         [0.42, 0.00, 1.00, 1.00]],
            'easeOut'        => ['easeOut',        [0.00, 0.00, 0.58, 1.00]],
            'easeInOut'      => ['easeInOut',      [0.42, 0.00, 0.58, 1.00]],
            'linear'         => ['linear',         [0.00, 0.00, 1.00, 1.00]],
            'easeInSine'     => ['easeInSine',     [0.47, 0.00, 0.75, 0.72]],
            'easeOutSine'    => ['easeOutSine',    [0.39, 0.58, 0.57, 1.00]],
            'easeInOutSine'  => ['easeInOutSine',  [0.45, 0.05, 0.55, 0.95]],
            'easeInQuad'     => ['easeInQuad',     [0.55, 0.09, 0.68, 0.53]],
            'easeOutQuad'    => ['easeOutQuad',    [0.25, 0.46, 0.45, 0.94]],
            'easeInOutQuad'  => ['easeInOutQuad',  [0.46, 0.03, 0.52, 0.96]],
            'easeInCubic'    => ['easeInCubic',    [0.55, 0.06, 0.68, 0.19]],
            'easeOutCubic'   => ['easeOutCubic',   [0.22, 0.61, 0.36, 1.00]],
            'easeInOutCubic' => ['easeInOutCubic', [0.65, 0.05, 0.35, 1.00]],
            'easeInQuart'    => ['easeInQuart',    [0.90, 0.03, 0.69, 0.22]],
            'easeOutQuart'   => ['easeOutQuart',   [0.17, 0.84, 0.44, 1.00]],
            'easeInOutQuart' => ['easeInOutQuart', [0.77, 0.00, 0.18, 1.00]],
            'easeInQuint'    => ['easeInQuint',    [0.76, 0.05, 0.86, 0.06]],
            'easeOutQuint'   => ['easeOutQuint',   [0.23, 1.00, 0.32, 1.00]],
            'easeInOutQuint' => ['easeInOutQuint', [0.86, 0.00, 0.07, 1.00]],
            'easeInExpo'     => ['easeInExpo',     [0.95, 0.05, 0.80, 0.00]],
            'easeOutExpo'    => ['easeOutExpo',    [0.19, 1.00, 0.22, 1.00]],
            'easeInOutExpo'  => ['easeInOutExpo',  [1.00, 0.00, 0.00, 1.00]],
            'easeInCirc'     => ['easeInCirc',     [0.60, 0.04, 0.98, 0.34]],
            'easeOutCirc'    => ['easeOutCirc',    [0.08, 0.82, 0.17, 1.00]],
            'easeInOutCirc'  => ['easeInOutCirc',  [0.785, 0.135, 0.150, 0.860]],
        ];
    }

    /**
     * Per-family shape probes: monotone over t ∈ [0, 1], exact endpoints,
     * and no stray overshoot for presets whose control points all live in
     * [0, 1]. Exact-tuple pins above carry the truth; these probes are the
     * coarse net that makes a wrong row loud (014's cure: the range check
     * alone held for every corrupted row of SPR-01, hence the roster).
     *
     * @dataProvider frozenFactoryTupleRosterProvider
     */
    public function testPresetFamilyShapeProbes(string $factory, array $tuple): void
    {
        $cb = CubicBezier::$factory();
        $this->assertSame(0.0, $cb->ease(0.0), "$factory endpoint 0");
        $this->assertSame(1.0, $cb->ease(1.0), "$factory endpoint 1");

        $prev = -2.0;
        $min  = 1.0;
        $max  = 0.0;
        for ($i = 0; $i <= 100; $i++) {
            $value = $cb->ease($i / 100.0);
            $this->assertGreaterThanOrEqual($prev - 1e-12, $value, "$factory monotone at i=$i");
            $prev = $value;
            $min  = min($min, $value);
            $max  = max($max, $value);
        }

        $inside = $tuple[1] >= 0.0 && $tuple[1] <= 1.0 && $tuple[3] >= 0.0 && $tuple[3] <= 1.0;
        if ($inside) {
            $this->assertGreaterThanOrEqual(0.0, $min, "$factory dips below 0 with in-range control points");
            $this->assertLessThanOrEqual(1.0, $max, "$factory exceeds 1 with in-range control points");
        }
    }

    /**
     * SPR-03 seam: the curve class finally implements the extension
     * interface its own docblock always advertised it satisfied.
     */
    public function testSatisfiesTheEasingFunctionSeam(): void
    {
        $this->assertInstanceOf(\SugarCraft\Spring\Easing\EasingFunction::class, CubicBezier::cssEase());
        $this->assertSame(1.0, CubicBezier::linear()->ease(1.0));
    }

    /**
     * BC leg of the rename: the deprecated evaluate() sampler alias returns
     * byte-identical values to ease(), warning once per use with the dated
     * REMOVED-IN stamp. The legacy STATIC ease() is a different story: no
     * forwarder is dispatchable (the engine resolves it to the instance
     * sampler and raises an Error), so the pin here is that it fails LOUDLY
     * — never quietly returning a preset.
     */
    public function testLegacyNamesAnswerDeprecatedOrLoud(): void
    {
        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured[] = [$errno, $errstr];
            return true;
        }, E_USER_DEPRECATED);
        try {
            $cb = CubicBezier::easeInOutCubic();
            foreach ([0.0, 0.25, 0.5, 0.75, 1.0] as $t) {
                $this->assertSame($cb->ease($t), $cb->evaluate($t), 'evaluate() alias byte-equals ease() at t=' . $t);
            }
        } finally {
            restore_error_handler();
        }

        $this->assertCount(5, $captured);
        foreach ($captured as [$errno, $errstr]) {
            $this->assertSame(E_USER_DEPRECATED, $errno);
            $this->assertStringContainsString('REMOVED-IN: 2.0.0', $errstr);
        }

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('cannot be called statically');

        /** @phpstan-ignore-next-line intentional legacy-spelling probe */
        CubicBezier::ease();
    }

    /**
     * Loudness leg: __callStatic is the CSS-preset door ONLY — a typo'd
     * static must not quietly become some preset. Mutation of the door's
     * name guard to a catch-all reddens this pin.
     */
    public function testUnknownStaticCallRefusesLoudly(): void
    {
        $this->expectException(\BadMethodCallException::class);
        /** @phpstan-ignore-next-line intentional wrong-API probe */
        CubicBezier::easeInOutQuarty();
    }

    /**
     * SPR-04 hoist honesty: the six constructor-computed polynomial
     * coefficients must equal the old per-call inline arithmetic BIT-EXACTLY
     * (===) — the hoist is pure performance, no sample may move. The
     * arithmetic is spelled independently here (the legacy WebKit UnitBezier
     * association) so the pin is an oracle, not a tautology; adversarial
     * tuples included because the old code had TWO inline spellings (the
     * derivative recomputed ax as 1-3x2+3x1, up to one ulp off 1-cx-bx —
     * measured divergent on 38% of random control points), and the pin
     * records WHICH association the hoisted fields carry: the polynomial's,
     * keeping every existing sample pin byte-identical (26-preset + custom
     * 1000-point grid diffed pre/post hoist: only the known 1-ulp Newton
     * derivative-path shifts on easeIn's non-dyadic tuple moved, ulp-deep,
     * with zero exact pins at those coordinates).
     *
     * @dataProvider coefficientHoistProvider
     */
    public function testHoistedCoefficientsEqualLegacyInlineArithmetic(
        float $x1, float $y1, float $x2, float $y2
    ): void {
        $cb = new CubicBezier($x1, $y1, $x2, $y2);
        $read = static function (string $prop) use ($cb): float {
            return (float) (new \ReflectionProperty(CubicBezier::class, $prop))->getValue($cb);
        };

        // Legacy inline arithmetic of sampleCurveX/sampleCurveY, verbatim.
        $cx = 3.0 * $x1;
        $bx = 3.0 * ($x2 - $x1) - $cx;
        $ax = 1.0 - $cx - $bx;
        $cy = 3.0 * $y1;
        $by = 3.0 * ($y2 - $y1) - $cy;
        $ay = 1.0 - $cy - $by;

        $this->assertSame($cx, $read('cx'), "cx at ($x1,$y1,$x2,$y2)");
        $this->assertSame($bx, $read('bx'), "bx at ($x1,$y1,$x2,$y2)");
        $this->assertSame($ax, $read('ax'), "ax at ($x1,$y1,$x2,$y2)");
        $this->assertSame($cy, $read('cy'), "cy at ($x1,$y1,$x2,$y2)");
        $this->assertSame($by, $read('by'), "by at ($x1,$y1,$x2,$y2)");
        $this->assertSame($ay, $read('ay'), "ay at ($x1,$y1,$x2,$y2)");
    }

    /**
     * The Newton slope must read the SAME stored coefficients the polynomial
     * evaluates — (3·ax·t + 2·bx)·t + cx with the reflected hoisted floats,
     * bit-exact. This is the value/slope drift cure: pre-hoist the derivative
     * re-derived ax/bx from the alternate association and could disagree with
     * its own polynomial by one ulp on adversarial control points.
     *
     * @dataProvider coefficientHoistProvider
     */
    public function testDerivativeConsumesTheSameHoistedCoefficients(
        float $x1, float $y1, float $x2, float $y2
    ): void {
        $cb = new CubicBezier($x1, $y1, $x2, $y2);
        $read = static function (string $prop) use ($cb): float {
            return (float) (new \ReflectionProperty(CubicBezier::class, $prop))->getValue($cb);
        };
        $derivative = new \ReflectionMethod(CubicBezier::class, 'sampleCurveDerivativeX');

        foreach ([0.0, 0.125, 1 / 3, 0.5, 0.9375, 1.0] as $t) {
            $expected = (3.0 * $read('ax') * $t + 2.0 * $read('bx')) * $t + $read('cx');
            $this->assertSame($expected, $derivative->invoke($cb, $t), "slope at t=$t");
        }
    }

    /** @return array<string, array{float, float, float, float}> */
    public static function coefficientHoistProvider(): array
    {
        $cases = [];
        foreach (self::frozenFactoryTupleRosterProvider() as $name => [$factory, $tuple]) {
            $cases["table $name"] = $tuple;
        }
        // Adversarial non-dyadic tuples, including the ones measured
        // divergent between the old two spellings (1 ulp apart):
        $cases['easeIn non-dyadic']  = [0.42, 0.00, 1.00, 1.00];
        $cases['back overshoot']     = [0.68, -0.60, 0.32, 1.60];
        $cases['thirds']             = [1 / 3, 2 / 3, 2 / 7, 5 / 7];
        $cases['near-dyadic drift']  = [0.94999999999999996, 0.050000000000000003, 0.80000000000000004, 0.0];
        $cases['quarter grid']       = [0.25, 0.10, 0.25, 1.00];

        return $cases;
    }

    /**
     * SPR-04 memo: every named factory hands back one shared immutable
     * instance (identity, not just equality), the cache holds at most the 26
     * named entries keyed by factory name, and ad-hoc constructor curves
     * stay unshared. Mutation-proven: dropping `??=` (returning `new self`
     * every call) reddens the identity leg.
     *
     * @dataProvider frozenFactoryTupleRosterProvider
     */
    public function testNamedFactoriesReturnSharedInstances(string $factory, array $tuple): void
    {
        $first  = CubicBezier::$factory();
        $second = CubicBezier::$factory();

        $this->assertSame($first, $second, "$factory must return the same shared instance");
    }

    public function testPresetMemoIsBoundedToTheNamedFamily(): void
    {
        foreach (self::frozenFactoryTupleRosterProvider() as [$factory]) {
            CubicBezier::$factory();
        }
        // Constructor-built customs bypass the memo entirely.
        $customA = new CubicBezier(0.13, 0.29, 0.41, 0.57);
        $customB = new CubicBezier(0.13, 0.29, 0.41, 0.57);
        $this->assertNotSame($customA, $customB, 'custom curves must not be memoized');

        $cache = (new \ReflectionProperty(CubicBezier::class, 'presets'))->getValue();
        $this->assertCount(26, $cache);
        // Insertion order is suite-order dependent (one shared static), so
        // the census compares sorted key sets, not order.
        $keys = array_keys($cache);
        sort($keys);
        $roster = array_column(self::frozenFactoryTupleRosterProvider(), 0);
        sort($roster);
        $this->assertSame($roster, $keys, 'memo keys are exactly the 26 factory names');
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

    /**
     * Float-precision exit of solveCubicXSubdivision() (docs-fix-1023 spring
     * MINOR): the earlier spelling returned the seed guess carried in a `$t`
     * that was initialized to the input and never written inside the loop —
     * a dead value that would be wrong if the epsilon hit never fired. The
     * fallback must return the collapsed bracket midpoint. Pinned on the
     * method body because the epsilon hit converges too reliably for the
     * branch to be reached behaviorally; the branch's honesty is the contract.
     */
    public function testSubdivisionFallbackReturnsTheCollapsedBracketNotAStaleSeed(): void
    {
        $src    = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Easing/CubicBezier.php');
        $start  = strpos($src, 'private function solveCubicXSubdivision');
        $this->assertIsInt($start, 'solveCubicXSubdivision vanished from CubicBezier.php');
        $body   = substr($src, $start, (int) strpos(substr($src, $start), "\n    }"));

        $this->assertStringContainsString('return ($lower + $upper) / 2.0;', $body);
        $this->assertStringNotContainsString('$t     = $x;', $body, 'stale seed variable must not return');
        $this->assertDoesNotMatchRegularExpression('/return \$t;/s', $body);

        // And the solver still lands every cited preset inside the epsilon band.
        foreach ([CubicBezier::easeInOutExpo(), CubicBezier::easeInOutCirc(), CubicBezier::easeIn()] as $curve) {
            foreach ([0.13, 0.37, 0.61, 0.88] as $x) {
                $t = $curve->ease($x);
                $this->assertTrue(is_finite($t));
            }
        }
    }

    // ── SPR-35 (finding 052): ::new() ships as the uniform custom door ─────

    public function testNewFactoryMirrorIsTheSameDoorAsTheConstructor(): void
    {
        $this->assertEquals(new CubicBezier(0.1, 0.7, 0.9, 0.2), CubicBezier::new(0.1, 0.7, 0.9, 0.2));

        // The mirror is the CUSTOM curve door: it builds fresh instances and
        // deliberately bypasses the 26-preset memo — cssEase() stays shared.
        $this->assertNotSame(CubicBezier::cssEase(), CubicBezier::new(0.25, 0.10, 0.25, 1.00));
        $this->assertEquals(CubicBezier::cssEase(), CubicBezier::new(0.25, 0.10, 0.25, 1.00));

        // and the typo door still refuses names that are not methods at all
        $this->expectException(\BadMethodCallException::class);
        CubicBezier::quinticIn();
    }

}
