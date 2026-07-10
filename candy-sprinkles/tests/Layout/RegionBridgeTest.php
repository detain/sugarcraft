<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Position;
use SugarCraft\Buffer\Region as BufferRegion;
use SugarCraft\Layout\Region as LayoutRegion;
use SugarCraft\Sprinkles\Layout\RegionBridge;

final class RegionBridgeTest extends TestCase
{
    /**
     * @return array<string, array{int, int, int, int}>
     */
    public static function regions(): array
    {
        return [
            'origin zero-size' => [0, 0, 0, 0],
            'origin sized'     => [0, 0, 10, 4],
            'offset sized'     => [3, 7, 20, 12],
            'offset zero-size' => [5, 9, 0, 0],
            'wide row'         => [1, 1, 80, 1],
            'tall column'      => [2, 3, 1, 24],
        ];
    }

    /**
     * @dataProvider regions
     */
    public function testToBufferMapsFieldsOneToOne(int $x, int $y, int $w, int $h): void
    {
        $layout = new LayoutRegion($x, $y, $w, $h);
        $buffer = RegionBridge::toBuffer($layout);

        $this->assertInstanceOf(BufferRegion::class, $buffer);
        $this->assertSame($x, $buffer->origin()->col(), 'x → col');
        $this->assertSame($y, $buffer->origin()->row(), 'y → row');
        $this->assertSame($w, $buffer->width(), 'width');
        $this->assertSame($h, $buffer->height(), 'height');
    }

    /**
     * @dataProvider regions
     */
    public function testToLayoutMapsFieldsOneToOne(int $x, int $y, int $w, int $h): void
    {
        $buffer = new BufferRegion(new Position($x, $y), $w, $h);
        $layout = RegionBridge::toLayout($buffer);

        $this->assertInstanceOf(LayoutRegion::class, $layout);
        $this->assertSame($x, $layout->x, 'col → x');
        $this->assertSame($y, $layout->y, 'row → y');
        $this->assertSame($w, $layout->width, 'width');
        $this->assertSame($h, $layout->height, 'height');
    }

    /**
     * @dataProvider regions
     */
    public function testRoundTripLayoutToBufferToLayout(int $x, int $y, int $w, int $h): void
    {
        $original = new LayoutRegion($x, $y, $w, $h);
        $roundTrip = RegionBridge::toLayout(RegionBridge::toBuffer($original));

        $this->assertSame($original->x, $roundTrip->x);
        $this->assertSame($original->y, $roundTrip->y);
        $this->assertSame($original->width, $roundTrip->width);
        $this->assertSame($original->height, $roundTrip->height);
    }

    /**
     * @dataProvider regions
     */
    public function testRoundTripBufferToLayoutToBuffer(int $x, int $y, int $w, int $h): void
    {
        $original = new BufferRegion(new Position($x, $y), $w, $h);
        $roundTrip = RegionBridge::toBuffer(RegionBridge::toLayout($original));

        $this->assertSame($original->origin()->col(), $roundTrip->origin()->col());
        $this->assertSame($original->origin()->row(), $roundTrip->origin()->row());
        $this->assertSame($original->width(), $roundTrip->width());
        $this->assertSame($original->height(), $roundTrip->height());
    }

    /**
     * A buffer Region with a negative origin cannot be represented as a
     * candy-layout Region; the target constructor's own guard must reject it
     * rather than the bridge silently clamping or accepting it.
     */
    public function testToLayoutRejectsNegativeOrigin(): void
    {
        $buffer = new BufferRegion(new Position(-1, -1), 4, 4);

        $this->expectException(\InvalidArgumentException::class);
        RegionBridge::toLayout($buffer);
    }

    /**
     * The bridge itself must not be instantiable — it is a static-only
     * converter with a private constructor.
     */
    public function testConstructorIsPrivate(): void
    {
        $ctor = new \ReflectionMethod(RegionBridge::class, '__construct');
        $this->assertTrue($ctor->isPrivate());
    }
}
