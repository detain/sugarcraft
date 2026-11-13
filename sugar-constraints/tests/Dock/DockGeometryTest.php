<?php

declare(strict_types=1);

namespace SugarCraft\Constraints\Tests\Dock;

use PHPUnit\Framework\TestCase;
use SugarCraft\Constraints\Dock\DockGeometry;
use SugarCraft\Constraints\Dock\Side;
use SugarCraft\Constraints\Region;

final class DockGeometryTest extends TestCase
{
    public function testEmptyGeometryIsMarkedEmpty(): void
    {
        $geometry = DockGeometry::new();
        $this->assertTrue($geometry->isEmpty());
        $this->assertSame([], $geometry->regions);
        $this->assertSame([], $geometry->dividerColumns());
        $this->assertNull($geometry->regionFor('chat'));
    }

    public function testRegionForReturnsTheMappedRegion(): void
    {
        $chat = Region::fromSize(10, 4);
        $files = new Region(0, 0, 20, 30);
        $geometry = DockGeometry::new(['chat' => $chat, 'files' => $files]);

        $this->assertSame($chat, $geometry->regionFor('chat'));
        $this->assertSame($files, $geometry->regionFor('files'));
        $this->assertNull($geometry->regionFor('ghost'));
        $this->assertFalse($geometry->isEmpty());
    }

    public function testDividerColumnsAreExposedVerbatim(): void
    {
        $columns = [['x' => 20, 'side' => Side::Left]];
        $geometry = DockGeometry::new(['chat' => Region::fromSize(5, 5)], $columns);

        $this->assertSame($columns, $geometry->dividerColumns);
        $this->assertSame($columns, $geometry->dividerColumns());
    }

    public function testElementWritesThroughTheArrayPropsAreRefused(): void
    {
        $geometry = DockGeometry::new(['chat' => Region::fromSize(5, 5)], [['x' => 5, 'side' => Side::Left]]);

        // The README/docblock freeze claim, pinned at both props: readonly
        // rejects indirect modification, not just reassignment.
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $geometry->regions['chat'] = Region::fromSize(9, 9);
    }

    public function testReadsHandTheArrayOutByValueSoCopiesAreScratchSafe(): void
    {
        $chat = Region::fromSize(5, 5);
        $geometry = DockGeometry::new(['chat' => $chat]);
        $copy = $geometry->regions;
        $copy['chat'] = Region::fromSize(9, 9);

        // Same object identity on the shared result: the array is copied by
        // value, the Region refs ride through untouched.
        $this->assertSame($chat, $geometry->regions['chat'], 'writes to a read-out copy never reach the shared result');
    }

    public function testNonRegionValueIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("region 'chat' must be a Region");
        /** @phpstan-ignore-next-line */
        DockGeometry::new(['chat' => 'not-a-region']);
    }

    public function testMalformedDividerRowIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('divider columns must be');
        DockGeometry::new(['chat' => Region::fromSize(1, 1)], [['x' => 1]]);
    }

    public function testNonIntegerDividerXIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DockGeometry::new([], [['x' => '1', 'side' => Side::Left]]);
    }
}
