<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Raster;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Raster\FontLoader;
use SugarCraft\Vcr\Raster\GdRasterizer;
use SugarCraft\Vcr\Raster\Glyphs;
use SugarCraft\Vcr\Raster\ImagickRasterizer;
use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Cell;
use SugarCraft\Vterm\Cursor;
use SugarCraft\Vterm\Snapshot;
use SugarCraft\Vterm\Theme;

/**
 * VCR-S42 (finding VCR-TUI-006): the two rasterizer backends must agree on
 * what a tape looks like — bold/italic cells select real styled faces on
 * both, a block cursor reveals the cell's glyph in reverse video on both,
 * the cursor footprint follows a wide-cell head on both, and the underline
 * cursor on an inverse cell paints the same channel on both. Before this
 * row, `--backend imagick` silently rendered bold as regular, hid the glyph
 * under a solid rectangle, clipped every wide-cell cursor to one column,
 * and picked the opposite palette channel for inverse-cell cursors.
 */
final class RasterBackendParityTest extends TestCase
{
    private const CELL_W = 8;
    private const CELL_H = 16;

    private FontLoader $fonts;

    protected function setUp(): void
    {
        $this->fonts = new FontLoader();
    }

    public function testGdBoldItalicSelectsItsOwnFace(): void
    {
        $glyphs = new Glyphs(self::CELL_W, self::CELL_H, $this->fonts, 'JetBrainsMono', 14, new Theme());

        $plain = $this->tileBytes($glyphs->tile('A', 7, 0, false, false, false));
        $bold = $this->tileBytes($glyphs->tile('A', 7, 0, true, false, false));
        $italic = $this->tileBytes($glyphs->tile('A', 7, 0, false, true, false));
        $boldItalic = $this->tileBytes($glyphs->tile('A', 7, 0, true, true, false));

        // The italic tile used to BE the bold+italic tile (the weight
        // collapsed): all four style combinations must now paint apart.
        $this->assertNotSame($boldItalic, $italic, 'bold+italic must not collapse to italic');
        $this->assertNotSame($boldItalic, $bold, 'bold+italic must not collapse to bold');
        $this->assertNotSame($bold, $plain, 'bold must reach the raster');
        $this->assertNotSame($italic, $plain, 'italic must reach the raster');
    }

    public function testGdUnderlineCursorSpansAWideHead(): void
    {
        $theme = new Theme();
        $rasterizer = new GdRasterizer(14, 'JetBrainsMono', $theme);
        $head = new Cell('A', 7, 0);
        $grid = new Buffer(2, 1);
        $grid->put(0, 0, $head);
        $grid->put(0, 1, Cell::continuation($head));
        $snapshot = new Snapshot($grid, new Cursor(0, 0, 2, true), 0.0); // shape 2 = underline

        $image = $rasterizer->rasterize($snapshot, self::CELL_W, self::CELL_H, $this->fonts, true);
        $rowY = (int) floor(self::CELL_H * 0.75);
        $secondColumn = $this->gdRgbAt($image, self::CELL_W + 3, $rowY);
        imagedestroy($image);

        // Before the fix the underline stopped at column one and the second
        // column kept the plain tile background.
        $this->assertNotSame(
            $theme->color(0),
            $secondColumn,
            'the underline cursor must cover the whole wide cell',
        );
    }

    public function testImagickBlockCursorRevealsTheGlyphInReverseVideo(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick not loaded');
        }

        $rasterizer = new ImagickRasterizer(14, 'DejaVuSansMono', new Theme());
        $grid = new Buffer(1, 1);
        $grid->put(0, 0, new Cell('A', 7, 0));
        $snapshot = new Snapshot($grid, new Cursor(0, 0, 1, true), 0.0);

        $image = $rasterizer->rasterize($snapshot, self::CELL_W, self::CELL_H, $this->fonts, true);

        // The old solid rectangle hid the character completely: every pixel
        // of the cell was the fill colour. Reverse video paints the glyph
        // (in the background channel) over that fill, so the cell must hold
        // at least two distinct colours.
        $colours = [];
        for ($y = 0; $y < self::CELL_H; $y++) {
            for ($x = 0; $x < self::CELL_W; $x++) {
                $colours[$this->imagickRgbAt($image, $x, $y)] = true;
            }
        }
        $image->clear();

        $this->assertGreaterThanOrEqual(2, count($colours), 'a block cursor must invert the glyph, not bury it');
    }

    public function testImagickUnderlineCursorSpansAWideHead(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick not loaded');
        }

        $theme = new Theme();
        $rasterizer = new ImagickRasterizer(14, 'DejaVuSansMono', $theme);
        $head = new Cell('A', 7, 0);
        $grid = new Buffer(2, 1);
        $grid->put(0, 0, $head);
        $grid->put(0, 1, Cell::continuation($head));
        $snapshot = new Snapshot($grid, new Cursor(0, 0, 2, true), 0.0);

        $image = $rasterizer->rasterize($snapshot, self::CELL_W, self::CELL_H, $this->fonts, true);
        $rowY = (int) floor(self::CELL_H * 0.75);
        $secondColumn = $this->imagickRgbAt($image, self::CELL_W + 3, $rowY);
        $image->clear();

        $this->assertNotSame(
            $theme->color(0),
            $secondColumn,
            'the underline cursor must cover the whole wide cell',
        );
    }

    public function testBackendsAgreeOnTheInverseCellCursorColour(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick not loaded');
        }

        $theme = new Theme();
        $head = new Cell(' ', 7, 1, Cell::ATTR_INVERSE);

        $gd = new GdRasterizer(14, 'JetBrainsMono', $theme);
        $gdGrid = new Buffer(1, 1);
        $gdGrid->put(0, 0, $head);
        $gdImage = $gd->rasterize(
            new Snapshot($gdGrid, new Cursor(0, 0, 2, true), 0.0),
            self::CELL_W,
            self::CELL_H,
            $this->fonts,
            true,
        );
        $gdPixel = $this->gdRgbAt($gdImage, 4, (int) floor(self::CELL_H * 0.75));
        imagedestroy($gdImage);

        $ik = new ImagickRasterizer(14, 'JetBrainsMono', $theme);
        $ikGrid = new Buffer(1, 1);
        $ikGrid->put(0, 0, $head);
        $ikImage = $ik->rasterize(
            new Snapshot($ikGrid, new Cursor(0, 0, 2, true), 0.0),
            self::CELL_W,
            self::CELL_H,
            $this->fonts,
            true,
        );
        $ikPixel = $this->imagickRgbAt($ikImage, 4, (int) floor(self::CELL_H * 0.75));
        $ikImage->clear();

        // GdRasterizer paints the foreground channel on an inverse cell;
        // Imagick used to paint the background one. Same tape, same pixel.
        $this->assertSame($gdPixel, $ikPixel, 'inverse-cell cursor colour must not depend on --backend');
    }

    /**
     * @return int the `0xRRGGBB` of a single composited pixel
     */
    private function gdRgbAt(\GdImage $image, int $x, int $y): int
    {
        return imagecolorat($image, $x, $y) & 0xffffff;
    }

    /**
     * @return int the `0xRRGGBB` of a single composited pixel
     */
    private function imagickRgbAt(\Imagick $image, int $x, int $y): int
    {
        $c = $image->getImagePixelColor($x, $y)->getColor();

        return ((($c['r'] ?? 0) & 0xff) << 16) | ((($c['g'] ?? 0) & 0xff) << 8) | (($c['b'] ?? 0) & 0xff);
    }

    private function tileBytes(\GdImage $tile): string
    {
        ob_start();
        imagepng($tile);
        $bytes = (string) ob_get_clean();
        $path = sys_get_temp_dir() . '/candy-vcr-tile-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($path, $bytes);
        $hash = (string) hash_file('sha1', $path);
        unlink($path);

        return $hash;
    }
}
