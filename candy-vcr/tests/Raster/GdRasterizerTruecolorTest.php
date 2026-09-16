<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Raster;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Raster\FontLoader;
use SugarCraft\Vcr\Raster\GdRasterizer;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Snapshot;
use SugarCraft\Vt\Theme;

/**
 * Proves the GD rasterizer paints the EXACT 24-bit colour when a cell carries
 * SGR `38;2`/`48;2`, instead of downgrading it to the palette slot — the
 * truecolour-consumption gap left by #1445. A blank (' ') cell renders as a solid
 * background fill, so a single sampled canvas pixel is a deterministic proof the
 * truecolour reached the allocation (ext-gd is loaded in CI, so this runs for real).
 */
final class GdRasterizerTruecolorTest extends TestCase
{
    private const CELL_W = 8;

    private const CELL_H = 16;

    private FontLoader $fonts;

    protected function setUp(): void
    {
        $this->fonts = new FontLoader();
    }

    public function testTruecolourBackgroundReachesTheCanvasPixel(): void
    {
        $theme = Theme::tokyoNight();
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono', $theme);
        // Palette bg 0 on TokyoNight is 0x15161e; the truecolour override is
        // distinct, so a drop-to-palette bug would sample the wrong value.
        $cell = (new Cell(' ', fg: 7, bg: 0))->withBgTruecolor(0x102030);

        $pixel = $this->paintSingleCell($rasterizer, $cell);

        $this->assertSame(0x102030, $pixel, 'truecolour bg must reach the raster');
        $this->assertNotSame($theme->color(0), $pixel, 'must NOT render the palette fallback');
    }

    public function testPaletteCellStillMapsThroughTheme(): void
    {
        $theme = Theme::tokyoNight();
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono', $theme);
        $cell = new Cell(' ', fg: 7, bg: 0);

        $pixel = $this->paintSingleCell($rasterizer, $cell);

        $this->assertSame($theme->color(0), $pixel, 'palette cell colour must be unchanged');
    }

    public function testInverseSwapsTruecolourOntoTheBackground(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono', new Theme());
        // Under ATTR_INVERSE the fg truecolour paints the cell background, so the
        // blank fill must be the fg value — proving the override swaps with the slots.
        $cell = (new Cell(' ', fg: 7, bg: 0, attrs: Cell::ATTR_INVERSE))->withFgTruecolor(0xAABBCC);

        $pixel = $this->paintSingleCell($rasterizer, $cell);

        $this->assertSame(0xAABBCC, $pixel);
    }

    public function testTruecolourAndPaletteSameIndexDoNotCollideInCache(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono', new Theme());
        // Two cells, same palette bg index 0, but only one has a truecolour override
        // → distinct tiles (2 misses), not a false cache hit on the shared index.
        $grid = new Buffer(2, 1);
        $grid->put(0, 0, new Cell(' ', fg: 7, bg: 0));
        $grid->put(0, 1, (new Cell(' ', fg: 7, bg: 0))->withBgTruecolor(0x112233));
        $snapshot = new Snapshot($grid, new Cursor(0, 0, 0, false), 0.0);

        $image = $rasterizer->rasterize($snapshot, self::CELL_W, self::CELL_H, $this->fonts, false);
        imagedestroy($image);

        $stats = $rasterizer->cacheStats();
        $this->assertSame(2, $stats['misses'], 'palette-0 and truecolor-over-0 must be separate tiles');
        $this->assertSame(0, $stats['hits']);
    }

    public function testIdenticalPaletteCellsStillShareOneTile(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono', new Theme());
        $grid = new Buffer(2, 1);
        $grid->put(0, 0, new Cell(' ', fg: 7, bg: 0));
        $grid->put(0, 1, new Cell(' ', fg: 7, bg: 0));
        $snapshot = new Snapshot($grid, new Cursor(0, 0, 0, false), 0.0);

        $image = $rasterizer->rasterize($snapshot, self::CELL_W, self::CELL_H, $this->fonts, false);
        imagedestroy($image);

        $stats = $rasterizer->cacheStats();
        $this->assertSame(1, $stats['misses'], 'byte-identical palette cells must hit one tile');
        $this->assertSame(1, $stats['hits']);
    }

    public function testUnderlineCursorHonoursTruecolourForeground(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono', new Theme());
        $cell = (new Cell(' ', fg: 7, bg: 0))->withFgTruecolor(0xA1B2C3);
        // shape 2 = underline; cursorRgb reads the fg channel on the non-inverse path.
        $image = $this->paintWithCursor($rasterizer, $cell, shape: 2);
        $uy = (int) floor(self::CELL_H * 0.75);
        $band = imagecolorat($image, intdiv(self::CELL_W, 2), $uy) & 0xFFFFFF;
        $above = imagecolorat($image, intdiv(self::CELL_W, 2), 1) & 0xFFFFFF;
        imagedestroy($image);

        $this->assertSame(0xA1B2C3, $band, 'underline cursor painted the exact fg truecolour');
        $this->assertNotSame(0xA1B2C3, $above, 'cell body stayed the palette bg — only the cursor band carries the truecolour');
    }

    public function testBarCursorHonoursTruecolourForeground(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono', new Theme());
        $cell = (new Cell(' ', fg: 7, bg: 0))->withFgTruecolor(0xA1B2C3);
        // shape 3 = bar (left 2px column of the cell).
        $image = $this->paintWithCursor($rasterizer, $cell, shape: 3);
        $bar = imagecolorat($image, 0, intdiv(self::CELL_H, 2)) & 0xFFFFFF;
        $outside = imagecolorat($image, self::CELL_W - 1, intdiv(self::CELL_H, 2)) & 0xFFFFFF;
        imagedestroy($image);

        $this->assertSame(0xA1B2C3, $bar, 'bar cursor painted the exact fg truecolour');
        $this->assertNotSame(0xA1B2C3, $outside, 'the untouched right side shows the palette bg');
    }

    public function testBlockCursorHonoursTruecolourForeground(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono', new Theme());
        $cell = (new Cell(' ', fg: 7, bg: 0))->withFgTruecolor(0xA1B2C3);
        // shape 1 = block: paints a reversed-video tile whose background fill is the
        // cell's fg channel; a blank glyph means the sampled pixel is that fill.
        $image = $this->paintWithCursor($rasterizer, $cell, shape: 1);
        $pixel = imagecolorat($image, intdiv(self::CELL_W, 2), intdiv(self::CELL_H, 2)) & 0xFFFFFF;
        imagedestroy($image);

        $this->assertSame(0xA1B2C3, $pixel, 'block cursor fill carries the fg truecolour');
    }

    private function paintWithCursor(GdRasterizer $rasterizer, Cell $cell, int $shape): \GdImage
    {
        $grid = new Buffer(1, 1);
        $grid->put(0, 0, $cell);
        $snapshot = new Snapshot($grid, new Cursor(0, 0, $shape, true), 0.0);

        return $rasterizer->rasterize($snapshot, self::CELL_W, self::CELL_H, $this->fonts, true);
    }

    private function paintSingleCell(GdRasterizer $rasterizer, Cell $cell): int
    {
        $grid = new Buffer(1, 1);
        $grid->put(0, 0, $cell);
        $snapshot = new Snapshot($grid, new Cursor(0, 0, 0, false), 0.0);

        $image = $rasterizer->rasterize($snapshot, self::CELL_W, self::CELL_H, $this->fonts, false);
        $pixel = imagecolorat($image, intdiv(self::CELL_W, 2), intdiv(self::CELL_H, 2)) & 0xFFFFFF;
        imagedestroy($image);

        return $pixel;
    }
}
