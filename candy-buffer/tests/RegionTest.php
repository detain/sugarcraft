<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Position;
use SugarCraft\Buffer\Region;

/**
 * @covers \SugarCraft\Buffer\Region
 */
final class RegionTest extends TestCase
{
    public function testNew(): void
    {
        $origin = Position::new(3, 5);
        $region = Region::new($origin, 10, 20);

        $this->assertSame($origin, $region->origin());
        $this->assertSame(10, $region->width());
        $this->assertSame(20, $region->height());
    }

    public function testNewViaConstructor(): void
    {
        $origin = Position::new(1, 2);
        $region = new Region($origin, 5, 7);

        $this->assertSame($origin, $region->origin());
        $this->assertSame(5, $region->width());
        $this->assertSame(7, $region->height());
    }

    public function testRight(): void
    {
        // Origin at col=3, width=10 → right = 3 + 10 - 1 = 12
        $region = Region::new(Position::new(3, 0), 10, 5);
        $this->assertSame(12, $region->right());

        // Edge case: width=1 → right equals origin col
        $region = Region::new(Position::new(7, 0), 1, 1);
        $this->assertSame(7, $region->right());
    }

    public function testBottom(): void
    {
        // Origin at row=5, height=20 → bottom = 5 + 20 - 1 = 24
        $region = Region::new(Position::new(0, 5), 10, 20);
        $this->assertSame(24, $region->bottom());

        // Edge case: height=1 → bottom equals origin row
        $region = Region::new(Position::new(0, 9), 1, 1);
        $this->assertSame(9, $region->bottom());
    }

    public function testContainsInside(): void
    {
        $region = Region::new(Position::new(2, 3), 5, 4);

        // Inside region
        $this->assertTrue($region->contains(2, 3)); // top-left corner
        $this->assertTrue($region->contains(6, 6)); // bottom-right corner
        $this->assertTrue($region->contains(4, 4)); // middle
    }

    public function testContainsOnBoundary(): void
    {
        $region = Region::new(Position::new(2, 3), 5, 4);

        // On right and bottom edges (inclusive)
        $this->assertTrue($region->contains(6, 3)); // right edge, top row
        $this->assertTrue($region->contains(2, 6)); // bottom edge, left col
        $this->assertTrue($region->contains(6, 6)); // bottom-right corner
    }

    public function testContainsOutside(): void
    {
        $region = Region::new(Position::new(2, 3), 5, 4);

        // Outside region
        $this->assertFalse($region->contains(1, 3));  // left of region
        $this->assertFalse($region->contains(7, 3));  // right of region
        $this->assertFalse($region->contains(2, 2));  // above region
        $this->assertFalse($region->contains(2, 7));  // below region
        $this->assertFalse($region->contains(7, 6)); // diagonal outside
    }

    public function testContainsOriginOnly(): void
    {
        // Single cell region
        $region = Region::new(Position::new(0, 0), 1, 1);

        $this->assertTrue($region->contains(0, 0));
        $this->assertFalse($region->contains(1, 0));
        $this->assertFalse($region->contains(0, 1));
    }

    public function testNegativeWidthThrows(): void
    {
        // Regression: a negative width used to be silently accepted and then
        // quietly produced no fill/copy output, masking the caller's bug.
        $this->expectException(\InvalidArgumentException::class);

        Region::new(Position::new(0, 0), -1, 5);
    }

    public function testNegativeHeightThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Region(Position::new(2, 2), 5, -3);
    }

    public function testNegativeOriginStillAllowed(): void
    {
        // Only dimensions are guarded — a negative origin (used by
        // Buffer::withRegion for clipped blits) remains valid.
        $region = new Region(Position::new(-1, -1), 2, 2);

        $this->assertSame(-1, $region->origin()->col);
        $this->assertSame(2, $region->width());
    }

    public function testZeroAreaRegionIsAllowed(): void
    {
        // Zero-area (0×N / N×0) is an explicit, documented no-op — allowed.
        $zeroWidth = Region::new(Position::new(0, 0), 0, 5);
        $this->assertSame(0, $zeroWidth->width());
        $this->assertSame(5, $zeroWidth->height());

        $zeroHeight = new Region(Position::new(0, 0), 5, 0);
        $this->assertSame(5, $zeroHeight->width());
        $this->assertSame(0, $zeroHeight->height());
    }
}
