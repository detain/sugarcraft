<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests;

use SugarCraft\Spring\Point;
use SugarCraft\Spring\Vector;
use PHPUnit\Framework\TestCase;

final class PointTest extends TestCase
{
    private const EPS = 1e-9;

    public function testConstructionWithDefaults(): void
    {
        $p = new Point(1.0, 2.0);
        $this->assertSame(1.0, $p->x);
        $this->assertSame(2.0, $p->y);
        $this->assertSame(0.0, $p->z);
    }

    public function testConstructionWithAllThreeComponents(): void
    {
        $p = new Point(1.0, 2.0, 3.0);
        $this->assertSame(1.0, $p->x);
        $this->assertSame(2.0, $p->y);
        $this->assertSame(3.0, $p->z);
    }

    public function testZeroFactory(): void
    {
        $p = Point::zero();
        $this->assertSame(0.0, $p->x);
        $this->assertSame(0.0, $p->y);
        $this->assertSame(0.0, $p->z);
    }

    public function testAddVector(): void
    {
        $p = new Point(1.0, 2.0, 3.0);
        $v = new Vector(4.0, 5.0, 6.0);
        $result = $p->add($v);

        $this->assertSame(5.0, $result->x);
        $this->assertSame(7.0, $result->y);
        $this->assertSame(9.0, $result->z);
    }

    public function testAddVectorDoesNotMutatePoint(): void
    {
        $p = new Point(1.0, 2.0, 3.0);
        $v = new Vector(4.0, 5.0, 6.0);
        $p->add($v);

        $this->assertSame(1.0, $p->x);
        $this->assertSame(2.0, $p->y);
        $this->assertSame(3.0, $p->z);
    }

    public function testAddVectorWithDefaultZ(): void
    {
        $p = new Point(1.0, 2.0);
        $v = new Vector(4.0, 5.0);
        $result = $p->add($v);

        $this->assertSame(5.0, $result->x);
        $this->assertSame(7.0, $result->y);
        $this->assertSame(0.0, $result->z);
    }

    public function testDistanceToSelfIsZero(): void
    {
        $p = new Point(5.0, 7.0, 9.0);
        $this->assertSame(0.0, $p->distance($p));
    }

    public function testDistanceToOtherPoint(): void
    {
        $a = new Point(0.0, 0.0, 0.0);
        $b = new Point(3.0, 4.0, 0.0);
        // 3-4-5 triangle distance
        $this->assertEqualsWithDelta(5.0, $a->distance($b), self::EPS);
    }

    public function testDistanceWith3D(): void
    {
        $a = new Point(0.0, 0.0, 0.0);
        $b = new Point(1.0, 2.0, 2.0);
        // sqrt(1 + 4 + 4) = sqrt(9) = 3.0
        $this->assertEqualsWithDelta(3.0, $a->distance($b), self::EPS);
    }

    public function testDistanceIsSymmetric(): void
    {
        $a = new Point(1.0, 2.0, 3.0);
        $b = new Point(4.0, 6.0, 8.0);
        $this->assertEqualsWithDelta($a->distance($b), $b->distance($a), self::EPS);
    }

    public function testDeltaIsTheToMinusFromComponentwiseDifference(): void
    {
        $a = new Point(1.0, 2.0, 3.0);
        $b = new Point(4.0, 6.0, -1.0);

        $delta = $a->delta($b);

        $this->assertInstanceOf(Vector::class, $delta);
        $this->assertSame(3.0, $delta->x);   // 4 - 1
        $this->assertSame(4.0, $delta->y);   // 6 - 2
        $this->assertSame(-4.0, $delta->z);  // -1 - 3
    }

    public function testAddOwnDeltaLandsExactlyOnTheTargetPoint(): void
    {
        $a = new Point(-7.5, 2.25, 0.0);
        $b = new Point(11.0, -3.75, 6.5);

        $landed = $a->add($a->delta($b));

        $this->assertSame($b->x, $landed->x);
        $this->assertSame($b->y, $landed->y);
        $this->assertSame($b->z, $landed->z);
    }

    public function testDeltaLengthEqualsDistance(): void
    {
        $a = new Point(0.0, 0.0, 0.0);
        $b = new Point(3.0, 4.0, 12.0); // length 13

        $this->assertEqualsWithDelta($a->distance($b), $a->delta($b)->length(), self::EPS);
        // The motion primitive composes in both directions: b->a is the exact
        // negation of a->b, and the round trip returns the origin.
        $back = $b->delta($a);
        $this->assertSame(-3.0, $back->x);
        $this->assertEqualsWithDelta(0.0, $a->add($a->delta($b))->add($b->delta($a))->distance($a), self::EPS);
    }

    public function testToString(): void
    {
        $p = new Point(1.5, 2.5, 3.5);
        $str = $p->__toString();

        $this->assertStringContainsString('1.5', $str);
        $this->assertStringContainsString('2.5', $str);
        $this->assertStringContainsString('3.5', $str);
        $this->assertStringContainsString('Point', $str);
    }

    public function testJsonSerialize(): void
    {
        $p = new Point(1.0, 2.0, 3.0);
        $arr = $p->jsonSerialize();

        $this->assertSame([1.0, 2.0, 3.0], $arr);
    }

    public function testImmutabilityWithAddOperation(): void
    {
        $p = new Point(1.0, 2.0, 3.0);
        $originalX = $p->x;
        $originalY = $p->y;
        $originalZ = $p->z;

        $p->add(new Vector(10.0, 10.0, 10.0));

        $this->assertSame($originalX, $p->x);
        $this->assertSame($originalY, $p->y);
        $this->assertSame($originalZ, $p->z);
    }

    /**
     * @dataProvider nonFiniteComponentProvider
     */
    public function testConstructorRefusesNonFiniteComponents(string $axis, float $value): void
    {
        // Same trust boundary, symmetric coverage with VectorTest's grid
        // (finding 064: Point tested one leg where Vector tested three —
        // a dropped z-check would have sailed through here).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Point components must be finite');
        new Point(
            $axis === 'x' ? $value : 1.0,
            $axis === 'y' ? $value : 2.0,
            $axis === 'z' ? $value : 3.0,
        );
    }

    public static function nonFiniteComponentProvider(): iterable
    {
        foreach (['x', 'y', 'z'] as $axis) {
            yield "{$axis} NaN"  => [$axis, NAN];
            yield "{$axis} INF"  => [$axis, INF];
            yield "{$axis} -INF" => [$axis, -INF];
        }
    }

    // ── SPR-24 (finding 074): fromArray — the missing half of the wire ─────

    public function testFromArrayRoundTripsTheSerializedWire(): void
    {
        $point = new Point(1.5, -2.25, 3.0);
        $wire  = json_decode(json_encode($point), true);

        $rebuilt = Point::fromArray($wire);
        $this->assertEquals($point, $rebuilt);
        $this->assertSame([1.5, -2.25, 3.0], $rebuilt->jsonSerialize());
    }

    public function testFromArrayWidensIntegerComponents(): void
    {
        $rebuilt = Point::fromArray([1, 2, 3]);
        $this->assertSame([1.0, 2.0, 3.0], $rebuilt->jsonSerialize());
    }

    /** @dataProvider malformedWireProvider */
    public function testFromArrayRefusesMalformedWires(array $wire, string $needle): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($needle);
        Point::fromArray($wire);
    }

    /** @return iterable<string, array{array, string}> */
    public static function malformedWireProvider(): iterable
    {
        yield 'empty'          => [[], 'expects the positional'];
        yield 'two entries'    => [[1.0, 2.0], 'expects the positional'];
        yield 'keyed map'      => [['x' => 1.0, 'y' => 2.0, 'z' => 3.0], 'expects the positional'];
        yield 'string member'  => [['1.5', 0.0, 0.0], 'needs int or float'];
        yield 'bool member'    => [[false, 0.0, 0.0], 'needs int or float'];
    }

    public function testFromArrayDefersFinitenessToTheConstructorDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Point components must be finite');
        Point::fromArray([0.0, NAN, 0.0]);
    }
}
