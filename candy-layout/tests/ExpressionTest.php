<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Layout\Expression;

/**
 * Tests for Expression linear expression class.
 * Mirrors charmbracelet/charm-input library Expression handling.
 */
final class ExpressionTest extends TestCase
{
    public function testZeroFactory(): void
    {
        $expr = Expression::zero();
        $this->assertSame([], $expr->terms);
        $this->assertSame(0.0, $expr->constant);
    }

    public function testConstantFactory(): void
    {
        $expr = Expression::constant(42.0);
        $this->assertSame([], $expr->terms);
        $this->assertSame(42.0, $expr->constant);
    }

    public function testVariableFactory(): void
    {
        $expr = Expression::variable('x', 2.0);
        $this->assertSame(['x' => 2.0], $expr->terms);
        $this->assertSame(0.0, $expr->constant);
    }

    public function testVariableFactoryDefaultCoefficient(): void
    {
        $expr = Expression::variable('y');
        $this->assertSame(['y' => 1.0], $expr->terms);
        $this->assertSame(0.0, $expr->constant);
    }

    public function testPlus(): void
    {
        $a = Expression::variable('x', 2.0);
        $b = Expression::variable('y', 3.0);
        $result = $a->plus($b);

        $this->assertSame(['x' => 2.0, 'y' => 3.0], $result->terms);
        $this->assertSame(0.0, $result->constant);
    }

    public function testPlusWithConstant(): void
    {
        $a = Expression::constant(10.0);
        $b = Expression::constant(5.0);
        $result = $a->plus($b);

        $this->assertSame([], $result->terms);
        $this->assertSame(15.0, $result->constant);
    }

    public function testMinus(): void
    {
        $a = Expression::variable('x', 5.0);
        $b = Expression::variable('y', 3.0);
        $result = $a->minus($b);

        $this->assertSame(['x' => 5.0, 'y' => -3.0], $result->terms);
        $this->assertSame(0.0, $result->constant);
    }

    public function testMinusWithConstant(): void
    {
        $a = Expression::constant(10.0);
        $b = Expression::constant(3.0);
        $result = $a->minus($b);

        $this->assertSame([], $result->terms);
        $this->assertSame(7.0, $result->constant);
    }

    public function testTimes(): void
    {
        $expr = Expression::variable('x', 3.0);
        $expr = $expr->plus(Expression::constant(6.0));

        $result = $expr->times(2.0);

        $this->assertSame(['x' => 6.0], $result->terms);
        $this->assertSame(12.0, $result->constant);
    }

    public function testTimesWithZero(): void
    {
        $expr = Expression::variable('x', 5.0);
        $expr = $expr->plus(Expression::constant(10.0));

        $result = $expr->times(0.0);

        // Terms with 0.0 coefficient still exist (PHP array behavior)
        $this->assertArrayHasKey('x', $result->terms);
        $this->assertSame(0.0, $result->terms['x']);
        $this->assertSame(0.0, $result->constant);
    }

    public function testTimesWithNegativeScalar(): void
    {
        $expr = Expression::variable('x', 2.0);

        $result = $expr->times(-3.0);

        $this->assertSame(['x' => -6.0], $result->terms);
        $this->assertSame(0.0, $result->constant);
    }

    public function testPlusMergesTerms(): void
    {
        $a = Expression::variable('x', 2.0);
        $b = Expression::variable('x', 3.0);
        $result = $a->plus($b);

        $this->assertSame(['x' => 5.0], $result->terms);
    }

    public function testExpressionImmutability(): void
    {
        $original = Expression::variable('x', 2.0);
        $originalConstant = $original->constant;

        $doubled = $original->times(2.0);

        // Original should be unchanged
        $this->assertSame(['x' => 2.0], $original->terms);
        $this->assertSame($originalConstant, $original->constant);

        // New expression should be different
        $this->assertSame(['x' => 4.0], $doubled->terms);
    }
}
