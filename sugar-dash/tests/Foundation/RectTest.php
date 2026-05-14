<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Foundation\Rect;

final class RectTest extends TestCase
{
    // ==========================================
    // Construction
    // ==========================================

    public function testConstructorSetsProperties(): void
    {
        $rect = new Rect(1, 2, 5, 6);

        $this->assertSame(1, $rect->minX);
        $this->assertSame(2, $rect->minY);
        $this->assertSame(5, $rect->maxX);
        $this->assertSame(6, $rect->maxY);
    }

    // ==========================================
    // contains()
    // ==========================================

    public function testContainsPointInside(): void
    {
        $rect = new Rect(1, 1, 5, 5);

        $this->assertTrue($rect->contains(3, 3));
        $this->assertTrue($rect->contains(1, 1));
        $this->assertTrue($rect->contains(5, 5));
    }

    public function testContainsPointOnEdge(): void
    {
        $rect = new Rect(1, 1, 5, 5);

        // Points on min edges
        $this->assertTrue($rect->contains(1, 3));
        $this->assertTrue($rect->contains(3, 1));

        // Points on max edges
        $this->assertTrue($rect->contains(5, 3));
        $this->assertTrue($rect->contains(3, 5));
    }

    public function testContainsPointOutside(): void
    {
        $rect = new Rect(1, 1, 5, 5);

        // Outside on each side
        $this->assertFalse($rect->contains(0, 3));
        $this->assertFalse($rect->contains(6, 3));
        $this->assertFalse($rect->contains(3, 0));
        $this->assertFalse($rect->contains(3, 6));

        // Completely outside
        $this->assertFalse($rect->contains(-1, -1));
        $this->assertFalse($rect->contains(10, 10));
    }

    public function testContainsNegativeCoordinates(): void
    {
        $rect = new Rect(-5, -5, -1, -1);

        $this->assertTrue($rect->contains(-3, -3));
        $this->assertTrue($rect->contains(-5, -5));
        $this->assertFalse($rect->contains(0, 0));
        $this->assertFalse($rect->contains(-6, -3));
    }

    // ==========================================
    // intersect()
    // ==========================================

    public function testIntersectOverlapping(): void
    {
        $rect1 = new Rect(0, 0, 5, 5);
        $rect2 = new Rect(3, 3, 8, 8);

        $result = $rect1->intersect($rect2);

        $this->assertNotNull($result);
        $this->assertSame(3, $result->minX);
        $this->assertSame(3, $result->minY);
        $this->assertSame(5, $result->maxX);
        $this->assertSame(5, $result->maxY);
    }

    public function testIntersectNonOverlapping(): void
    {
        $rect1 = new Rect(0, 0, 5, 5);
        $rect2 = new Rect(10, 10, 15, 15);

        $result = $rect1->intersect($rect2);

        $this->assertNull($result);
    }

    public function testIntersectTouchingEdges(): void
    {
        $rect1 = new Rect(0, 0, 5, 5);
        $rect2 = new Rect(6, 0, 10, 5);

        $result = $rect1->intersect($rect2);

        // Touching edges should produce null (minX > maxX)
        $this->assertNull($result);
    }

    public function testIntersectContained(): void
    {
        $rect1 = new Rect(0, 0, 10, 10);
        $rect2 = new Rect(3, 3, 7, 7);

        $result = $rect1->intersect($rect2);

        $this->assertNotNull($result);
        $this->assertSame(3, $result->minX);
        $this->assertSame(3, $result->minY);
        $this->assertSame(7, $result->maxX);
        $this->assertSame(7, $result->maxY);
    }

    // ==========================================
    // dx() / dy()
    // ==========================================

    public function testDxReturnsWidth(): void
    {
        $rect = new Rect(2, 0, 6, 0);

        // width = maxX - minX + 1 = 6 - 2 + 1 = 5
        $this->assertSame(5, $rect->dx());
    }

    public function testDyReturnsHeight(): void
    {
        $rect = new Rect(0, 3, 0, 8);

        // height = maxY - minY + 1 = 8 - 3 + 1 = 6
        $this->assertSame(6, $rect->dy());
    }

    public function testZeroSizeRect(): void
    {
        $rect = new Rect(5, 5, 5, 5);

        // Single cell rectangle
        $this->assertSame(1, $rect->dx());
        $this->assertSame(1, $rect->dy());
    }

    // ==========================================
    // Edge cases
    // ==========================================

    public function testZeroWidthRect(): void
    {
        $rect = new Rect(3, 0, 3, 5);

        // Single column (zero width in grid terms)
        $this->assertSame(1, $rect->dx());
        $this->assertSame(6, $rect->dy());
        $this->assertTrue($rect->contains(3, 2));
        $this->assertFalse($rect->contains(2, 2));
        $this->assertFalse($rect->contains(4, 2));
    }

    public function testZeroHeightRect(): void
    {
        $rect = new Rect(0, 4, 5, 4);

        // Single row (zero height in grid terms)
        $this->assertSame(6, $rect->dx());
        $this->assertSame(1, $rect->dy());
        $this->assertTrue($rect->contains(3, 4));
        $this->assertFalse($rect->contains(3, 3));
        $this->assertFalse($rect->contains(3, 5));
    }
}
