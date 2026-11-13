<?php

declare(strict_types=1);

namespace SugarCraft\Constraints\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Constraints\Region;

/**
 * SC-08 pins: withOrigin() is the translate-only door (finding 035) — size
 * rides unchanged, and the constructor's boundary guard keeps it loud for
 * negative destinations.
 */
final class RegionTest extends TestCase
{
    public function testWithOriginTranslatesAndPreservesSize(): void
    {
        $moved = Region::fromSize(40, 10)->withOrigin(3, 5);

        $this->assertSame(3, $moved->x);
        $this->assertSame(5, $moved->y);
        $this->assertSame(40, $moved->width);
        $this->assertSame(10, $moved->height);
    }

    public function testWithOriginAllowsZeroAndKeepsImmutableOrigin(): void
    {
        $original = new Region(7, 9, 2, 3);
        $moved = $original->withOrigin(0, 0);

        $this->assertSame(0, $moved->x);
        $this->assertSame(0, $moved->y);
        $this->assertSame(7, $original->x, 'translation returns a new box; the original is untouched');
    }

    public function testNegativeDestinationRefusedAtTheBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Region components must be non-negative');
        Region::fromSize(40, 10)->withOrigin(-1, 0);
    }
}
