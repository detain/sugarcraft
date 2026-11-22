<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\Toast\Position;
use SugarCraft\Toast\Toast;
use SugarCraft\Toast\ToastType;

/**
 * Toast::compositeInto(): the toast layer painted into a host's cell buffer,
 * on the cells view() paints for the same canvas.
 */
final class ToastCompositeIntoTest extends TestCase
{
    private static function blankRows(int $w, int $h): string
    {
        return \implode("\n", \array_fill(0, $h, \str_repeat(' ', $w)));
    }

    public function testItPaintsTheSameFrameViewPaintsForTheCanvas(): void
    {
        foreach ([Position::TopRight, Position::BottomLeft, Position::MiddleCenter] as $position) {
            $toast = Toast::new(30)->withPosition($position)
                ->alert(ToastType::Error, 'Disk full')
                ->alert(ToastType::Info, 'Saved');

            $this->assertSame(
                $toast->view(self::blankRows(60, 12), 60, 12),
                $toast->compositeInto(Buffer::new(60, 12))->toAnsi(),
                $position->name,
            );
        }
    }

    public function testItWritesIntoTheGivenBufferAndKeepsItsCells(): void
    {
        $host = Buffer::new(40, 6)->withCellAt(0, 5, Cell::new('#'));
        $out = Toast::new(20)->withPosition(Position::TopRight)->alert(ToastType::Info, 'hi')->compositeInto($host);

        $this->assertNotSame($host, $out);
        $this->assertSame([40, 6], [$out->width(), $out->height()]);
        $this->assertSame('#', $out->cellAt(0, 5)->rune(), 'host cells outside the box survive');
        $this->assertNotSame(' ', $out->cellAt(39, 0)->rune(), 'the box is painted at the top-right corner');
        $this->assertSame(' ', $host->cellAt(39, 0)->rune(), 'the host buffer itself is untouched');
    }

    public function testAnEmptyToastReturnsTheBufferUnchanged(): void
    {
        $host = Buffer::new(10, 3);

        $this->assertSame($host, Toast::new()->compositeInto($host));
        $this->assertSame($host, Toast::new()->alert(ToastType::Info, 'x')->dismiss()->compositeInto($host));
    }

    public function testABoxTallerThanTheBufferIsClipped(): void
    {
        $out = Toast::new(10)->alert(ToastType::Info, \str_repeat('word ', 20))->compositeInto(Buffer::new(12, 2));

        $this->assertSame([12, 2], [$out->width(), $out->height()]);
    }

    public function testTheViewportBoundsApply(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Toast::new()->alert(ToastType::Info, 'x')->compositeInto(Buffer::new(Toast::MAX_VIEWPORT_DIMENSION + 1, 1));
    }
}
