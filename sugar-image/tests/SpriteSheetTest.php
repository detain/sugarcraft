<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\DecodedImageCache;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\SpriteSheet;

/**
 * @covers \SugarCraft\Image\SpriteSheet
 */
final class SpriteSheetTest extends TestCase
{
    protected function setUp(): void
    {
        DecodedImageCache::reset();
    }

    /**
     * A 4x2 grid of 3x3 tiles; tile i is filled with the colour (i*10, 0, 0).
     */
    private function sheet(): ImageSource
    {
        $img = imagecreatetruecolor(12, 6);
        for ($i = 0; $i < 8; $i++) {
            $x = ($i % 4) * 3;
            $y = intdiv($i, 4) * 3;
            imagefilledrectangle($img, $x, $y, $x + 2, $y + 2, imagecolorallocate($img, $i * 10, 0, 0));
        }
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return ImageSource::fromString($bytes);
    }

    private function pixelRed(ImageSource $tile): int
    {
        $img = imagecreatefromstring($tile->bytes);
        $rgb = imagecolorat($img, 0, 0);
        imagedestroy($img);

        return ($rgb >> 16) & 0xFF;
    }

    public function testTileIsIndexedRowMajorFromTheTopLeft(): void
    {
        $sprites = SpriteSheet::fromSource($this->sheet(), 4, 2);

        $this->assertSame(8, $sprites->count());
        $this->assertSame(3, $sprites->tileWidth);
        $this->assertSame(3, $sprites->tileHeight);
        foreach (range(0, 7) as $i) {
            $tile = $sprites->tile($i);
            $this->assertSame(3, $tile->width);
            $this->assertSame(3, $tile->height);
            $this->assertSame($i * 10, $this->pixelRed($tile), "tile {$i} is the {$i}th cell");
        }
    }

    public function testEveryTileOfTheSheetCostsOneDecode(): void
    {
        $sprites = SpriteSheet::fromSource($this->sheet(), 4, 2);
        for ($i = 0; $i < $sprites->count(); $i++) {
            $sprites->tile($i);
        }

        $this->assertSame(1, DecodedImageCache::misses(), 'the sheet is decoded once for all eight crops (rank 71)');
    }

    public function testRemainderPixelsBelongToNoTile(): void
    {
        // 13x7 with a 4x2 grid: tiles are 3x3, the last column and row are spare.
        $img = imagecreatetruecolor(13, 7);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        $sprites = SpriteSheet::fromSource(ImageSource::fromString($bytes), 4, 2);
        $this->assertSame(3, $sprites->tileWidth);
        $this->assertSame(3, $sprites->tileHeight);
    }

    public function testOutOfRangeIndexIsRefused(): void
    {
        $sprites = SpriteSheet::fromSource($this->sheet(), 4, 2);

        $this->expectException(\OutOfRangeException::class);
        $sprites->tile(8);
    }

    public function testNegativeIndexIsRefused(): void
    {
        $sprites = SpriteSheet::fromSource($this->sheet(), 4, 2);

        $this->expectException(\OutOfRangeException::class);
        $sprites->tile(-1);
    }

    public function testGridTooSmallForOnePixelPerTileIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SpriteSheet::fromSource($this->sheet(), 13, 1);
    }

    public function testNonPositiveGridIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SpriteSheet::fromSource($this->sheet(), 0, 2);
    }
}
