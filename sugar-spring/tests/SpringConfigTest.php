<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests;

use SugarCraft\Spring\SpringConfig;
use SugarCraft\Spring\Spring;
use PHPUnit\Framework\TestCase;

final class SpringConfigTest extends TestCase
{
    private const EPS = 1e-9;

    public function testDerivesAngularFrequencyFromTensionAndMass(): void
    {
        $config = new SpringConfig(tension: 100.0, friction: 10.0, mass: 1.0);
        $this->assertEqualsWithDelta(10.0, $config->angularFrequency, self::EPS);
    }

    public function testDerivesDampingRatioFromTensionFrictionAndMass(): void
    {
        // ζ = friction / (2 * sqrt(tension * mass))
        // = 10 / (2 * sqrt(100)) = 10 / 20 = 0.5
        $config = new SpringConfig(tension: 100.0, friction: 10.0, mass: 1.0);
        $this->assertEqualsWithDelta(0.5, $config->dampingRatio, self::EPS);
    }

    public function testSpringFactoryReturnsSpringInstance(): void
    {
        $config = new SpringConfig(tension: 100.0, friction: 10.0, mass: 1.0);
        $spring = $config->spring();
        $this->assertInstanceOf(Spring::class, $spring);
    }

    /**
     * SPR-29 (finding 013): the old dt-named tests asserted only
     * instanceof Spring — a factory ignoring its dt argument stayed green.
     * Frame-1 positions are now observed as EXACT bytes: 1/120 vs 1/60
     * differ measurably, so a dropped argument cannot hide.
     */
    public function testSpringHonoursItsDeltaTimeArgumentAtFrameOne(): void
    {
        $config = new SpringConfig(tension: 100.0, friction: 10.0, mass: 1.0);

        [$pos120, $vel120] = $config->spring(1.0 / 120.0)->update(0.0, 0.0, 100.0);
        [$pos60, $vel60] = $config->spring(1.0 / 60.0)->update(0.0, 0.0, 100.0);

        $this->assertSame(0.33758046296570399, $pos120, 'frame 1 at 1/120 s');
        $this->assertSame(79.863087014653175, $vel120, 'frame 1 velocity at 1/120 s');
        $this->assertSame(1.3118325869926366, $pos60, 'frame 1 at 1/60 s');
        $this->assertSame(152.80885700403078, $vel60, 'frame 1 velocity at 1/60 s');
        $this->assertLessThan($pos60, $pos120, 'a half-size frame must carry the spring less far');
    }

    /**
     * SPR-29 (finding 072): springAt60Fps() is exactly spring() with the
     * 1/60 default — the twin door still answers, loudly deprecated, byte-
     * identical to the canonical spelling (ADT-style roster entry for a
     * non-get surface, kept in this file because the src get* census
     * deliberately does not see it).
     */
    public function testSpringAt60FpsIsTheLoudDeprecatedTwinOfTheDefaultDoor(): void
    {
        $config = new SpringConfig(tension: 100.0, friction: 10.0, mass: 1.0);

        $message = null;
        set_error_handler(static function (int $severity, string $text) use (&$message): bool {
            if ($severity === E_USER_DEPRECATED) {
                $message = $text;
                return true;
            }
            return false;
        }, E_USER_DEPRECATED);
        try {
            $viaAlias = $config->springAt60Fps();
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString('REMOVED-IN: 2.0.0', (string) $message);
        $this->assertStringContainsString('use spring()', (string) $message);

        $this->assertSame(
            $config->spring(1.0 / 60.0)->update(0.0, 0.0, 100.0),
            $viaAlias->update(0.0, 0.0, 100.0),
            'the deprecated alias must answer with the exact bytes of the canonical door',
        );

        $doc = (string) (new \ReflectionMethod($config, 'springAt60Fps'))->getDocComment();
        $this->assertStringContainsString('@deprecated', $doc);
    }

    // M-A (review 1102): the old `max(0.0, ·)` clamps manufactured exactly
    // the springs that never settle — ω=0 freezes the state, ζ=0 oscillates
    // forever, and the `while (!$config->spring()->settled(...))` idiom hangs
    // on a VALID-looking config. A spring that cannot converge is illegal at
    // this boundary, not slow. Tests renamed from "Yields*"/"TreatedAs*" to
    // "IsRefused" — flipping them is the point.

    public function testZeroTensionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SpringConfig tension must be > 0');
        new SpringConfig(tension: 0.0, friction: 5.0, mass: 1.0);
    }

    public function testZeroFrictionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SpringConfig friction must be > 0');
        new SpringConfig(tension: 100.0, friction: 0.0, mass: 1.0);
    }

    public function testNegativeTensionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SpringConfig tension must be > 0');
        new SpringConfig(tension: -50.0, friction: 10.0, mass: 1.0);
    }

    /**
     * @dataProvider nonFiniteConfigValueProvider
     */
    public function testNonFiniteInputsAreRefused(string $plane, float $value): void
    {
        // NaN mass used to pass the `mass <= 0` door (NAN <= 0 is false) and
        // leak a permanently unsettled spring into the public ω/ζ props.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SpringConfig inputs must be finite');
        new SpringConfig(
            tension: $plane === 'tension' ? $value : 100.0,
            friction: $plane === 'friction' ? $value : 10.0,
            mass: $plane === 'mass' ? $value : 1.0,
        );
    }

    public static function nonFiniteConfigValueProvider(): iterable
    {
        foreach (['tension', 'friction', 'mass'] as $plane) {
            yield "{$plane} NaN" => [$plane, NAN];
            yield "{$plane} INF" => [$plane, INF];
            yield "{$plane} -INF" => [$plane, -INF];
        }
    }

    public function testOverflowingDerivationIsRefused(): void
    {
        // 1e308 / 1e-308 overflows to INF inside sqrt(tension / mass) even
        // though every INPUT is finite — the derived belt catches it before
        // the poisoned ω/ζ become public readonly properties.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SpringConfig derived a non-converging spring');
        new SpringConfig(tension: 1e308, friction: 1.0, mass: 1e-308);
    }

    public function testUnderflowedRootIsRefusedByTheGuardNotByDivisionByZero(): void
    {
        // Step 34 (docs-fix-1104 M3): tension*mass underflows to 0.0, so the
        // ζ division hits a zero root — PHP 8.3 raises a raw
        // DivisionByZeroError OUTSIDE the derived guard. The IEEE answer is
        // INF damping; the guard must own the refusal (spring.config_derived),
        // never the arithmetic fault.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SpringConfig derived a non-converging spring');

        new SpringConfig(tension: 1e-200, friction: 1.0, mass: 1e-200);
    }

    public function testNumericallyDegenerateDampingIsRefused(): void
    {
        // Step 34 (docs-fix-1104 M3): ω=1e150 and ζ=5e149 are both finite and
        // positive, yet past ζ ≈ 2^26.5 the float sum ζ² − 1 rounds back to
        // ζ², the two over-damped roots collapse to one number, and the baked
        // coefficients freeze the position forever (measured: 600 ticks,
        // pos=0 vel=0 settled=false). A frozen spring is illegal, not slow.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SpringConfig derived a non-converging spring');

        new SpringConfig(tension: 1.0, friction: 1.0, mass: 1e-300);
    }

    public function testHighButRepresentableDampingIsStillAccepted(): void
    {
        // The refuse line is float resolution (ζ² − 1 ≠ ζ²), not plausibility:
        // ζ = 1e7 keeps the two roots distinguishable and must construct.
        $config = new SpringConfig(tension: 1.0, friction: 2.0e7, mass: 1.0);
        $this->assertSame(1.0, $config->angularFrequency);
        $this->assertSame(1.0e7, $config->dampingRatio);
    }

    public function testSpringThreadsReducedMotionOverride(): void
    {
        $config = new SpringConfig(tension: 100.0, friction: 10.0, mass: 1.0);

        $snapPair = $config->spring(1.0 / 60.0, true)->update(0.0, 0.0, 100.0);
        $this->assertSame(
            [100.0, 0.0],
            [$snapPair[0], $snapPair[1]],
            'spring(dt, override: true) must snap despite any host env'
        );
        $animatedPair = $config->spring(1.0 / 60.0, false)->update(0.0, 0.0, 100.0);
        $this->assertNotSame(
            [100.0, 0.0],
            [$animatedPair['position'], $animatedPair['velocity']],
            'spring(override: false) must animate despite any host env'
        );
    }

    public function testSpringFromConfigConverges(): void
    {
        $config = new SpringConfig(tension: 100.0, friction: 10.0, mass: 1.0);
        $spring  = $config->spring();

        $pos = 0.0;
        $vel = 0.0;
        for ($i = 0; $i < 600; $i++) {
            [$pos, $vel] = $spring->update($pos, $vel, 100.0);
        }
        $this->assertEqualsWithDelta(100.0, $pos, 0.1);
    }

    /**
     * @dataProvider invalidMassProvider
     */
    public function testMassLessThanOrEqualToZeroThrowsException(float $mass): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("SpringConfig mass must be > 0; got {$mass}");
        new SpringConfig(tension: 100.0, friction: 10.0, mass: $mass);
    }

    public static function invalidMassProvider(): array
    {
        return [
            'zero mass'     => [0.0],
            'negative mass' => [-1.0],
        ];
    }

    // ── SPR-35 (finding 052): ::new() is the same door as the constructor ──

    public function testNewFactoryMirrorIsTheSameDoorAsTheConstructor(): void
    {
        $direct  = new SpringConfig(100.0, 10.0, 1.0);
        $viaFact = SpringConfig::new(100.0, 10.0, 1.0);

        $this->assertEquals($direct, $viaFact);
        $this->assertSame($direct->angularFrequency, $viaFact->angularFrequency);
        $this->assertSame($direct->dampingRatio, $viaFact->dampingRatio);
    }

}
