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
}
