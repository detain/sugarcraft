<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Chart;

use InvalidArgumentException;
use SugarCraft\Charts\Chart\NiceScale;
use PHPUnit\Framework\TestCase;

/**
 * @see NiceScale
 */
final class NiceScaleTest extends TestCase
{
    /**
     * @dataProvider ceilingCases
     */
    public function testCeiling(float $max, float $expected): void
    {
        self::assertSame($expected, NiceScale::ceiling($max));
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function ceilingCases(): iterable
    {
        yield 'zero floors to 100'        => [0.0, 100.0];
        yield 'negative floors to 100'    => [-50.0, 100.0];
        yield 'small int floors to 100'   => [7.0, 100.0];
        yield 'two-digit floors to 100'   => [45.0, 100.0];
        yield 'fractional floors to 100'  => [9.5, 100.0];
        yield 'leading 4 → 5000'          => [4500.0, 5000.0];
        yield 'leading 9 carries → 10000' => [9000.0, 10000.0];
        yield 'five-digit 9 → 100000'     => [95000.0, 100000.0];
        yield 'leading 1 → 2000'          => [1200.0, 2000.0];
        yield 'exact round stays nice'    => [1000.0, 2000.0];
    }

    public function testReturnsFloat(): void
    {
        self::assertIsFloat(NiceScale::ceiling(4500.0));
    }

    public function testFloorConstant(): void
    {
        self::assertSame(100.0, NiceScale::FLOOR);
    }

    /**
     * Audit F2: `(string)(int)$max` saturated at PHP_INT_MAX, so
     * ceiling(1e20) answered 8e18 — BELOW the input, silently clipping
     * the axis. The widened-digit ceiling never under-reports.
     */
    public function testCeilingNeverClipsBeyondIntSaturation(): void
    {
        self::assertSame(2.0e20, NiceScale::ceiling(1.0e20));
        self::assertGreaterThanOrEqual(9.5e18, NiceScale::ceiling(9.5e18));
        self::assertGreaterThanOrEqual(1.0e18, NiceScale::ceiling(1.0e18));
    }

    /**
     * Finite-domain choice (documented in ceiling()): near the double
     * upper bound no representable "nice ceiling" exists, so the
     * function throws instead of saturating to a wrong value.
     */
    public function testCeilingThrowsWhenNoFiniteCeilingExists(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NiceScale::ceiling(1.0e308);
    }

    public function testCeilingStaysFiniteAtTheEdge(): void
    {
        self::assertSame(1.0e308, NiceScale::ceiling(9.99e307));
    }
}
