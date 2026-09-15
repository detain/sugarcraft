<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests\Graphics;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Testing\Graphics\PixelGrid;

/**
 * @covers \SugarCraft\Testing\Graphics\PixelGrid
 */
final class PixelGridTest extends TestCase
{
    public function testFromRgbGridReportsDimensionsAndCells(): void
    {
        $grid = PixelGrid::fromRgbGrid([
            [[10, 20, 30], [40, 50, 60]],
            [[70, 80, 90], [100, 110, 120]],
        ]);

        self::assertSame(2, $grid->width());
        self::assertSame(2, $grid->height());
        self::assertSame([10, 20, 30], $grid->pixel(0, 0));
        self::assertSame([100, 110, 120], $grid->pixel(1, 1));
    }

    public function testFromRgbGridRejectsRaggedRows(): void
    {
        // A short row must not become an invisible transparent tail; reject it.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('first row declared');
        PixelGrid::fromRgbGrid([
            [[1, 1, 1], [2, 2, 2], [3, 3, 3]],
            [[4, 4, 4], [5, 5, 5]],
        ]);
    }

    public function testPixelOutsideBoundsIsNull(): void
    {
        $grid = PixelGrid::fromRgbGrid([[[1, 2, 3]]]);

        self::assertNull($grid->pixel(5, 5));
    }

    public function testEmptyGridHasZeroDimensions(): void
    {
        $grid = PixelGrid::fromRgbGrid([]);

        self::assertSame(0, $grid->width());
        self::assertSame(0, $grid->height());
    }

    public function testTransparentGridPaintsNothing(): void
    {
        $grid = PixelGrid::transparent(3, 2);

        self::assertSame(3, $grid->width());
        self::assertSame(2, $grid->height());
        self::assertNull($grid->pixel(0, 0));
        self::assertSame([], $grid->paintedPixels());
    }

    public function testPaintedPixelsSkipsTransparentCells(): void
    {
        $grid = PixelGrid::fromRgbGrid([
            [[1, 1, 1], null],
            [[2, 2, 2], [3, 3, 3]],
        ]);

        self::assertSame([[1, 1, 1], [2, 2, 2], [3, 3, 3]], $grid->paintedPixels());
    }

    public function testUniformColorReturnsSharedTriple(): void
    {
        $grid = PixelGrid::fromRgbGrid([
            [[9, 9, 9], [9, 9, 9]],
            [[9, 9, 9], [9, 9, 9]],
        ]);

        self::assertSame([9, 9, 9], $grid->uniformColor());
    }

    public function testUniformColorIsNullWhenMixedOrTransparent(): void
    {
        $mixed = PixelGrid::fromRgbGrid([[[1, 1, 1], [2, 2, 2]]]);
        self::assertNull($mixed->uniformColor());

        $withGap = PixelGrid::fromRgbGrid([[[1, 1, 1], null]]);
        self::assertNull($withGap->uniformColor());
    }

    public function testToGdImageMaterialisesEveryCell(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('ext-gd is required to materialise a PixelGrid');
        }

        $grid = PixelGrid::fromRgbGrid([[[255, 0, 0], [0, 0, 255]]]);
        $image = $grid->toGdImage();

        self::assertSame(2, imagesx($image));
        self::assertSame(1, imagesy($image));
        $red = imagecolorsforindex($image, imagecolorat($image, 0, 0));
        self::assertSame(255, $red['red']);
    }

    public function testEmptyGridToGdImageStillYieldsAUsableCanvas(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('ext-gd is required to materialise a PixelGrid');
        }

        $image = PixelGrid::transparent(0, 0)->toGdImage();

        self::assertInstanceOf(\GdImage::class, $image);
    }

    public function testToGdImageFailsLoudlyWithoutGd(): void
    {
        if (function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('this guard only fires when ext-gd is absent');
        }

        $this->expectException(RuntimeException::class);
        PixelGrid::transparent(1, 1)->toGdImage();
    }
}
