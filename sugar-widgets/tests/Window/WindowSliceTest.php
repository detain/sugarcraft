<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Window;

use PHPUnit\Framework\TestCase;
use SugarCraft\Widgets\Window\WindowSlice;

final class WindowSliceTest extends TestCase
{
    public function testAccessorsReportTheHalfOpenRange(): void
    {
        $slice = new WindowSlice(3, 8);

        self::assertSame(3, $slice->start());
        self::assertSame(8, $slice->end());
        self::assertSame(5, $slice->length());
    }

    public function testAnEmptySliceHasZeroLength(): void
    {
        $slice = new WindowSlice(4, 4);

        self::assertSame(4, $slice->start());
        self::assertSame(4, $slice->end());
        self::assertSame(0, $slice->length());
    }

    public function testANegativeStartClampsToZero(): void
    {
        $slice = new WindowSlice(-5, 3);

        self::assertSame(0, $slice->start());
        self::assertSame(3, $slice->end());
        self::assertSame(3, $slice->length());
    }

    public function testAnEndBeforeTheStartClampsToTheStart(): void
    {
        $slice = new WindowSlice(6, 2);

        self::assertSame(6, $slice->start());
        self::assertSame(6, $slice->end());
        self::assertSame(0, $slice->length());
    }

    public function testBothBoundsNegativeIsTheEmptySliceAtZero(): void
    {
        $slice = new WindowSlice(-3, -1);

        self::assertSame(0, $slice->start());
        self::assertSame(0, $slice->end());
        self::assertSame(0, $slice->length());
    }

    public function testTheValueCannotBeChangedAfterConstruction(): void
    {
        $slice = new WindowSlice(1, 2);

        // A readonly property refuses a second write even from the class scope.
        $write = \Closure::bind(static function (WindowSlice $s): void {
            $s->start = 9;
        }, null, WindowSlice::class);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('readonly');
        $write($slice);
    }
}
