<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Raster;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Raster\FontLoader;
use SugarCraft\Vcr\Raster\ImagickRasterizer;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Snapshot;

/**
 * Imagick counterpart to {@see GdRasterizerTruecolorTest}. Skipped cleanly when
 * ext-imagick is unavailable (mirroring {@see ImagickRasterizerCacheTest}). Proves
 * the truecolour override threads into the Imagick tile path and that the tile
 * cache never collides a truecolour cell with a palette cell of the same index.
 */
final class ImagickRasterizerTruecolorTest extends TestCase
{
    private FontLoader $fonts;

    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick not loaded');
        }
        $this->fonts = new FontLoader();
    }

    public function testTruecolourAndPaletteSameIndexDoNotCollideInCache(): void
    {
        $rasterizer = new ImagickRasterizer(14, 'DejaVuSansMono');
        $grid = new Buffer(2, 1);
        $grid->put(0, 0, new Cell(' ', 7, 0));
        $grid->put(0, 1, (new Cell(' ', 7, 0))->withBgTruecolor(0x112233));
        $snapshot = new Snapshot($grid, new Cursor(0, 0, 0, false), 0.0);

        $image = $rasterizer->rasterize($snapshot, 8, 16, $this->fonts, false);
        $image->clear();

        $stats = $rasterizer->cacheStats();
        $this->assertSame(2, $stats['misses'], 'palette-0 and truecolor-over-0 must be separate tiles');
        $this->assertSame(0, $stats['hits']);
    }

    public function testIdenticalPaletteCellsStillShareOneTile(): void
    {
        $rasterizer = new ImagickRasterizer(14, 'DejaVuSansMono');
        $grid = new Buffer(2, 1);
        $grid->put(0, 0, new Cell(' ', 7, 0));
        $grid->put(0, 1, new Cell(' ', 7, 0));
        $snapshot = new Snapshot($grid, new Cursor(0, 0, 0, false), 0.0);

        $image = $rasterizer->rasterize($snapshot, 8, 16, $this->fonts, false);
        $image->clear();

        $stats = $rasterizer->cacheStats();
        $this->assertSame(1, $stats['misses'], 'byte-identical palette cells must hit one tile');
        $this->assertSame(1, $stats['hits']);
    }

    public function testBarCursorHonoursTruecolourForeground(): void
    {
        $rasterizer = new ImagickRasterizer(14, 'DejaVuSansMono');
        $cell = (new Cell(' ', 7, 0))->withFgTruecolor(0xA1B2C3);
        // shape 3 = bar (left column); cursorRgb reads the fg channel (non-inverse).
        $image = $this->paintWithCursor($rasterizer, $cell, 3);
        $bar = $this->rgbAt($image, 0, 8);
        $outside = $this->rgbAt($image, 7, 8);
        $image->clear();

        $this->assertSame(0xA1B2C3, $bar, 'bar cursor painted the exact fg truecolour');
        $this->assertNotSame(0xA1B2C3, $outside, 'the untouched right side shows the palette bg');
    }

    public function testBlockCursorHonoursTruecolourForeground(): void
    {
        $rasterizer = new ImagickRasterizer(14, 'DejaVuSansMono');
        $cell = (new Cell(' ', 7, 0))->withFgTruecolor(0xA1B2C3);
        // shape 1 = block; the cursor rectangle fills the whole cell.
        $image = $this->paintWithCursor($rasterizer, $cell, 1);
        $centre = $this->rgbAt($image, 4, 8);
        $image->clear();

        $this->assertSame(0xA1B2C3, $centre, 'block cursor fill carries the fg truecolour');
    }

    private function paintWithCursor(ImagickRasterizer $rasterizer, Cell $cell, int $shape): \Imagick
    {
        $grid = new Buffer(1, 1);
        $grid->put(0, 0, $cell);
        $snapshot = new Snapshot($grid, new Cursor(0, 0, $shape, true), 0.0);

        return $rasterizer->rasterize($snapshot, 8, 16, $this->fonts, true);
    }

    /**
     * @return int the `0xRRGGBB` of a single composited pixel
     */
    private function rgbAt(\Imagick $image, int $x, int $y): int
    {
        $c = $image->getImagePixelColor($x, $y)->getColor();

        return ((($c['r'] ?? 0) & 0xff) << 16) | ((($c['g'] ?? 0) & 0xff) << 8) | (($c['b'] ?? 0) & 0xff);
    }
}
