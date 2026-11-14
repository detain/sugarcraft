<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\GridTable;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\GridTable\BorderRegion;

/**
 * D-143: BorderRegion is the selector GridTable's border painter branches
 * on. The roster itself is the contract — a removed or renamed case is a
 * silent dead arm in every border loop.
 */
final class BorderRegionTest extends TestCase
{
    public function testRosterIsTheFourPaintedRegionsInOrder(): void
    {
        self::assertSame(
            ['Outer', 'Header', 'Inner', 'Footer'],
            array_map(static fn(BorderRegion $region): string => $region->name, BorderRegion::cases()),
        );
    }

    public function testCasesAreDistinctValues(): void
    {
        $seen = [];
        foreach (BorderRegion::cases() as $case) {
            self::assertArrayNotHasKey($case->name, $seen, 'duplicate region case ' . $case->name);
            $seen[$case->name] = true;
        }

        self::assertCount(4, $seen);
    }
}
