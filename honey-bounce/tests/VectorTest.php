<?php

declare(strict_types=1);

namespace SugarCraft\Bounce\Tests;

use SugarCraft\Bounce\Vector;
use PHPUnit\Framework\TestCase;

final class VectorTest extends TestCase
{
    private const EPS = 1e-9;

    public function testConstructionWithDefaults(): void
    {
        $v = new Vector(1.0, 2.0);
        $this->assertSame(1.0, $v->x);
        $this->assertSame(2.0, $v->y);
        $this->assertSame(0.0, $v->z);
    }

    public function testConstructionWithAllThreeComponents(): void
    {
        $v = new Vector(1.0, 2.0, 3.0);
        $this->assertSame(1.0, $v->x);
        $this->assertSame(2.0, $v->y);
        $this->assertSame(3.0, $v->z);
    }

    public function testZeroFactory(): void
    {
        $v = Vector::zero();
        $this->assertSame(0.0, $v->x);
        $this->assertSame(0.0, $v->y);
        $this->assertSame(0.0, $v->z);
    }

    public function testAdd(): void
    {
        $a = new Vector(1.0, 2.0, 3.0);
        $b = new Vector(4.0, 5.0, 6.0);
        $result = $a->add($b);

        $this->assertSame(5.0, $result->x);
        $this->assertSame(7.0, $result->y);
        $this->assertSame(9.0, $result->z);
    }

    public function testAddDoesNotMutateOriginal(): void
    {
        $a = new Vector(1.0, 2.0, 3.0);
        $b = new Vector(4.0, 5.0, 6.0);
        $a->add($b);

        $this->assertSame(1.0, $a->x);
        $this->assertSame(2.0, $a->y);
        $this->assertSame(3.0, $a->z);
    }

    public function testSub(): void
    {
        $a = new Vector(5.0, 7.0, 9.0);
        $b = new Vector(1.0, 2.0, 3.0);
        $result = $a->sub($b);

        $this->assertSame(4.0, $result->x);
        $this->assertSame(5.0, $result->y);
        $this->assertSame(6.0, $result->z);
    }

    public function testSubDoesNotMutateOriginal(): void
    {
        $a = new Vector(5.0, 7.0, 9.0);
        $b = new Vector(1.0, 2.0, 3.0);
        $a->sub($b);

        $this->assertSame(5.0, $a->x);
        $this->assertSame(7.0, $a->y);
        $this->assertSame(9.0, $a->z);
    }

    public function testScale(): void
    {
        $v = new Vector(2.0, 3.0, 4.0);
        $result = $v->scale(2.5);

        $this->assertSame(5.0, $result->x);
        $this->assertSame(7.5, $result->y);
        $this->assertSame(10.0, $result->z);
    }

    public function testScaleDoesNotMutateOriginal(): void
    {
        $v = new Vector(2.0, 3.0, 4.0);
        $v->scale(2.5);

        $this->assertSame(2.0, $v->x);
        $this->assertSame(3.0, $v->y);
        $this->assertSame(4.0, $v->z);
    }

    public function testLength(): void
    {
        // 3-4-5 triangle in 2D: length should be 5.0
        $v = new Vector(3.0, 4.0);
        $this->assertEqualsWithDelta(5.0, $v->length(), self::EPS);
    }

    public function testLengthWith3D(): void
    {
        // sqrt(1^2 + 2^2 + 2^2) = sqrt(9) = 3.0
        $v = new Vector(1.0, 2.0, 2.0);
        $this->assertEqualsWithDelta(3.0, $v->length(), self::EPS);
    }

    public function testLengthSquared(): void
    {
        $v = new Vector(3.0, 4.0);
        $this->assertSame(25.0, $v->lengthSquared());
    }

    public function testDot(): void
    {
        $a = new Vector(1.0, 2.0, 3.0);
        $b = new Vector(4.0, 5.0, 6.0);
        // 1*4 + 2*5 + 3*6 = 4 + 10 + 18 = 32
        $this->assertSame(32.0, $a->dot($b));
    }

    public function testDotWith2DVectors(): void
    {
        $a = new Vector(3.0, 4.0);
        $b = new Vector(2.0, 1.0);
        // 3*2 + 4*1 = 6 + 4 = 10
        $this->assertSame(10.0, $a->dot($b));
    }

    public function testCross(): void
    {
        // Standard basis vectors: i x j = k
        $i = new Vector(1.0, 0.0, 0.0);
        $j = new Vector(0.0, 1.0, 0.0);
        $k = $i->cross($j);

        $this->assertSame(0.0, $k->x);
        $this->assertSame(0.0, $k->y);
        $this->assertSame(1.0, $k->z);
    }

    public function testCrossReversedGivesNegative(): void
    {
        $i = new Vector(1.0, 0.0, 0.0);
        $j = new Vector(0.0, 1.0, 0.0);
        $k1 = $i->cross($j);
        $k2 = $j->cross($i);

        $this->assertSame(-$k1->x, $k2->x);
        $this->assertSame(-$k1->y, $k2->y);
        $this->assertSame(-$k1->z, $k2->z);
    }

    public function testCrossDoesNotMutateOriginal(): void
    {
        $a = new Vector(1.0, 0.0, 0.0);
        $b = new Vector(0.0, 1.0, 0.0);
        $a->cross($b);

        $this->assertSame(1.0, $a->x);
        $this->assertSame(0.0, $a->y);
        $this->assertSame(0.0, $a->z);
    }

    public function testNormalize(): void
    {
        $v = new Vector(3.0, 4.0, 0.0);
        $unit = $v->normalize();

        $this->assertEqualsWithDelta(0.6, $unit->x, self::EPS);
        $this->assertEqualsWithDelta(0.8, $unit->y, self::EPS);
        $this->assertSame(0.0, $unit->z);
        $this->assertEqualsWithDelta(1.0, $unit->length(), self::EPS);
    }

    public function testNormalizeThrowsOnZeroVector(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot normalize a zero-length vector');
        Vector::zero()->normalize();
    }

    public function testLerpAtTZeroReturnsOriginal(): void
    {
        $a = new Vector(1.0, 2.0, 3.0);
        $b = new Vector(4.0, 5.0, 6.0);
        $result = $a->lerp($b, 0.0);

        $this->assertSame($a->x, $result->x);
        $this->assertSame($a->y, $result->y);
        $this->assertSame($a->z, $result->z);
    }

    public function testLerpAtTOneReturnsOther(): void
    {
        $a = new Vector(1.0, 2.0, 3.0);
        $b = new Vector(4.0, 5.0, 6.0);
        $result = $a->lerp($b, 1.0);

        $this->assertSame($b->x, $result->x);
        $this->assertSame($b->y, $result->y);
        $this->assertSame($b->z, $result->z);
    }

    public function testLerpAtMidpoint(): void
    {
        $a = new Vector(0.0, 0.0, 0.0);
        $b = new Vector(10.0, 20.0, 30.0);
        $result = $a->lerp($b, 0.5);

        $this->assertSame(5.0, $result->x);
        $this->assertSame(10.0, $result->y);
        $this->assertSame(15.0, $result->z);
    }

    public function testToString(): void
    {
        $v = new Vector(1.5, 2.5, 3.5);
        $str = $v->__toString();

        $this->assertStringContainsString('1.5', $str);
        $this->assertStringContainsString('2.5', $str);
        $this->assertStringContainsString('3.5', $str);
        $this->assertStringContainsString('Vector', $str);
    }

    public function testJsonSerialize(): void
    {
        $v = new Vector(1.0, 2.0, 3.0);
        $arr = $v->jsonSerialize();

        $this->assertSame([1.0, 2.0, 3.0], $arr);
    }

    public function testImmutabilityWithManyOperations(): void
    {
        $v = new Vector(1.0, 2.0, 3.0);
        $originalX = $v->x;
        $originalY = $v->y;
        $originalZ = $v->z;

        $v->add(new Vector(1.0, 1.0, 1.0));
        $v->sub(new Vector(1.0, 1.0, 1.0));
        $v->scale(2.0);
        $v->normalize();
        $v->lerp(new Vector(10.0, 10.0, 10.0), 0.5);

        $this->assertSame($originalX, $v->x);
        $this->assertSame($originalY, $v->y);
        $this->assertSame($originalZ, $v->z);
    }
}
