<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Chart;

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
}
