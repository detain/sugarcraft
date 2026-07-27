<?php

declare(strict_types=1);

namespace SugarCraft\Bounce\Tests;

use SugarCraft\Bounce\Point;
use SugarCraft\Bounce\Vector;
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
}
