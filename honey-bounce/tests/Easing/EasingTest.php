<?php

declare(strict_types=1);

namespace SugarCraft\Bounce\Tests\Easing;

use SugarCraft\Bounce\Easing\Easing;
use PHPUnit\Framework\TestCase;

final class EasingTest extends TestCase
{
    private const EPS = 1e-9;

    public function testLinearIsIdentity(): void
    {
        $e = Easing::Linear;
        $this->assertEqualsWithDelta(0.0, $e->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(0.5, $e->ease(0.5), self::EPS);
        $this->assertEqualsWithDelta(1.0, $e->ease(1.0), self::EPS);
    }

    public function testQuadraticIn(): void
    {
        $e = Easing::QuadraticIn;
        $this->assertEqualsWithDelta(0.0, $e->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(0.25, $e->ease(0.5), self::EPS);
        $this->assertEqualsWithDelta(1.0, $e->ease(1.0), self::EPS);
    }

    public function testQuadraticOut(): void
    {
        $e = Easing::QuadraticOut;
        $this->assertEqualsWithDelta(0.0, $e->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(0.75, $e->ease(0.5), self::EPS);
        $this->assertEqualsWithDelta(1.0, $e->ease(1.0), self::EPS);
    }

    public function testQuadraticInOut(): void
    {
        $e = Easing::QuadraticInOut;
        $this->assertEqualsWithDelta(0.0, $e->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(0.5, $e->ease(0.5), self::EPS);
        $this->assertEqualsWithDelta(1.0, $e->ease(1.0), self::EPS);
    }

    public function testCubicIn(): void
    {
        $e = Easing::CubicIn;
        $this->assertEqualsWithDelta(0.0, $e->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(0.125, $e->ease(0.5), self::EPS);
        $this->assertEqualsWithDelta(1.0, $e->ease(1.0), self::EPS);
    }

    public function testCubicOut(): void
    {
        $e = Easing::CubicOut;
        $this->assertEqualsWithDelta(0.0, $e->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(0.875, $e->ease(0.5), self::EPS);
        $this->assertEqualsWithDelta(1.0, $e->ease(1.0), self::EPS);
    }

    public function testCubicInOut(): void
    {
        $e = Easing::CubicInOut;
        $this->assertEqualsWithDelta(0.0, $e->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(0.5, $e->ease(0.5), self::EPS);
        $this->assertEqualsWithDelta(1.0, $e->ease(1.0), self::EPS);
    }

    public function testElasticInAtZeroAndOne(): void
    {
        $e = Easing::ElasticIn;
        $this->assertEqualsWithDelta(0.0, $e->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(0.0, $e->ease(1.0), self::EPS);
    }

    public function testElasticOutEndpoints(): void
    {
        $e = Easing::ElasticOut;
        // ElasticOut starts at 0.0 and overshoots to settle at 1.0
        $this->assertEqualsWithDelta(0.0, $e->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(1.0, $e->ease(1.0), self::EPS);
    }

    public function testElasticInOutEndpoints(): void
    {
        $e = Easing::ElasticInOut;
        $this->assertEqualsWithDelta(0.0, $e->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(1.0, $e->ease(1.0), self::EPS);
    }

    public function testBounceInAtZeroAndOne(): void
    {
        $e = Easing::BounceIn;
        $this->assertEqualsWithDelta(0.0, $e->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(1.0, $e->ease(1.0), self::EPS);
    }

    public function testBounceOutAtZeroAndOne(): void
    {
        $e = Easing::BounceOut;
        $this->assertEqualsWithDelta(0.0, $e->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(1.0, $e->ease(1.0), self::EPS);
    }

    public function testBounceInOutAtZeroAndOne(): void
    {
        $e = Easing::BounceInOut;
        $this->assertEqualsWithDelta(0.0, $e->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(1.0, $e->ease(1.0), self::EPS);
    }

    public function testBackInAtZeroAndOne(): void
    {
        $e = Easing::BackIn;
        $this->assertEqualsWithDelta(0.0, $e->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(1.0, $e->ease(1.0), self::EPS);
    }

    public function testBackOutAtZeroAndOne(): void
    {
        $e = Easing::BackOut;
        $this->assertEqualsWithDelta(0.0, $e->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(1.0, $e->ease(1.0), self::EPS);
    }

    public function testBackInOutAtZeroAndOne(): void
    {
        $e = Easing::BackInOut;
        $this->assertEqualsWithDelta(0.0, $e->ease(0.0), self::EPS);
        $this->assertEqualsWithDelta(1.0, $e->ease(1.0), self::EPS);
    }

    public function testAllEasingCasesReturnFloat(): void
    {
        $cases = Easing::cases();
        foreach ($cases as $case) {
            $result = $case->ease(0.5);
            $this->assertIsFloat($result, "{$case->name}->ease(0.5) should return float");
        }
    }

    public function testEasingOutputForInCasesStartsSlow(): void
    {
        // In easings should have slope < 1 near t=0
        $inCases = [
            Easing::QuadraticIn,
            Easing::CubicIn,
        ];
        foreach ($inCases as $e) {
            $delta = $e->ease(0.1) - $e->ease(0.0);
            $this->assertLessThan(0.15, $delta, "{$e->name} should start slow");
        }
    }

    public function testEasingOutputForOutCasesStartsFast(): void
    {
        // Out easings should have slope > 1 near t=0
        $outCases = [
            Easing::QuadraticOut,
            Easing::CubicOut,
        ];
        foreach ($outCases as $e) {
            $delta = $e->ease(0.1) - $e->ease(0.0);
            $this->assertGreaterThan(0.15, $delta, "{$e->name} should start fast");
        }
    }
}
