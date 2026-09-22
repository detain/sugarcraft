<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Tests\Dock;

use PHPUnit\Framework\TestCase;
use SugarCraft\Layout\Dock\DockGeometry;
use SugarCraft\Layout\Dock\Side;
use SugarCraft\Layout\Region;

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
