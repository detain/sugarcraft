<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Heatmap;

use SugarCraft\Charts\Heatmap\HeatPoint;
use PHPUnit\Framework\TestCase;

/**
 * @see HeatPoint
 */
final class HeatPointTest extends TestCase
{
    public function testConstructWithAllFields(): void
    {
        $point = new HeatPoint(2, 3, 0.75);

        self::assertSame(2, $point->x);
        self::assertSame(3, $point->y);
        self::assertSame(0.75, $point->value);
    }

    public function testIntegerCoordinates(): void
    {
        $point = new HeatPoint(0, 0, 1.0);
        self::assertSame(0, $point->x);
        self::assertSame(0, $point->y);
    }

    public function testNegativeCoordinateIsAccepted(): void
    {
        $point = new HeatPoint(-1, -1, 0.5);
        self::assertSame(-1, $point->x);
        self::assertSame(-1, $point->y);
    }

    /**
     * @dataProvider finiteValueProvider
     */
    public function testFiniteValuesAreAccepted(float $value): void
    {
        $point = new HeatPoint(0, 0, $value);
        self::assertSame($value, $point->value);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function finiteValueProvider(): iterable
    {
        yield 'positive'       => [1.0];
        yield 'negative'       => [-0.5];
        yield 'zero'           => [0.0];
        yield 'small fraction' => [0.001];
        yield 'large value'    => [1e10];
    }

    public function testRejectsNaNValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('NaN');
        new HeatPoint(0, 0, NAN);
    }

    public function testRejectsPositiveInfinity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('INF');
        new HeatPoint(0, 0, INF);
    }

    public function testRejectsNegativeInfinity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('-INF');
        new HeatPoint(0, 0, -INF);
    }

    public function testOfIntFactoryConvertsIntToFloat(): void
    {
        $point = HeatPoint::ofInt(1, 2, 99);

        self::assertSame(1, $point->x);
        self::assertSame(2, $point->y);
        self::assertSame(99.0, $point->value);
        self::assertIsFloat($point->value);
    }

    public function testOfIntWithNegativeInt(): void
    {
        $point = HeatPoint::ofInt(5, 6, -10);
        self::assertSame(-10.0, $point->value);
    }

    public function testOfIntWithZero(): void
    {
        $point = HeatPoint::ofInt(0, 0, 0);
        self::assertSame(0.0, $point->value);
    }

    public function testOfIntAndConstructorProduceEquivalentObjects(): void
    {
        $a = HeatPoint::ofInt(3, 4, 50);
        $b = new HeatPoint(3, 4, 50.0);

        self::assertSame($a->x, $b->x);
        self::assertSame($a->y, $b->y);
        self::assertSame($a->value, $b->value);
    }
}
