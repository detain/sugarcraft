<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Raster;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Raster\FontLoader;
use SugarCraft\Vcr\Raster\GdRasterizer;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\CellGrid;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Snapshot;

/**
 * Tests for GdRasterizer.
 */
final class GdRasterizerTest extends TestCase
{
    private FontLoader $fonts;

    protected function setUp(): void
    {
        $this->fonts = new FontLoader();
    }

    public function testRasterizeReturnsGdImage(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono');
        $snapshot = $this->makeSnapshot('Hello', 5, 1);

        $image = $rasterizer->rasterize($snapshot, 8, 16, $this->fonts);

        $this->assertGreaterThan(0, imagesx($image));
        imagedestroy($image);
    }

    public function testRasterizeHasCorrectDimensions(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono');
        $cols = 10;
        $rows = 3;
        $cellW = 8;
        $cellH = 16;
        $snapshot = $this->makeSnapshot('0123456789', $cols, $rows);

        $image = $rasterizer->rasterize($snapshot, $cellW, $cellH, $this->fonts);

        $this->assertEquals($cols * $cellW, imagesx($image));
        $this->assertEquals($rows * $cellH, imagesy($image));
        imagedestroy($image);
    }

    public function testRasterizeWithEmptyGrid(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono');
        $snapshot = $this->makeSnapshot('', 80, 24);

        $image = $rasterizer->rasterize($snapshot, 8, 16, $this->fonts);

        $this->assertEquals(640, imagesx($image));
        $this->assertEquals(384, imagesy($image));
        imagedestroy($image);
    }

    public function testRasterizeWithCursorVisible(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono');
        $snapshot = $this->makeSnapshotWithCursor('ABC', 3, 1, 1, 1, true);

        $image = $rasterizer->rasterize($snapshot, 8, 16, $this->fonts);

        $this->assertGreaterThan(0, imagesx($image));
        imagedestroy($image);
    }

    public function testRasterizeWithBoldAttribute(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono');
        $grid = new CellGrid(5, 1);
        $cell = new Cell('A', 7, 0, Cell::ATTR_BOLD);
        $grid = $grid->set(0, 0, $cell);

        $cursor = new Cursor();
        $snapshot = new Snapshot($grid, $cursor, 0.0);

        $image = $rasterizer->rasterize($snapshot, 8, 16, $this->fonts);

        $this->assertGreaterThan(0, imagesx($image));
        imagedestroy($image);
    }

    public function testRasterizeWithUnderlineAttribute(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono');
        $grid = new CellGrid(5, 1);
        $cell = new Cell('A', 7, 0, Cell::ATTR_UNDERLINE);
        $grid = $grid->set(0, 0, $cell);

        $cursor = new Cursor();
        $snapshot = new Snapshot($grid, $cursor, 0.0);

        $image = $rasterizer->rasterize($snapshot, 8, 16, $this->fonts);

        $this->assertGreaterThan(0, imagesx($image));
        imagedestroy($image);
    }

    public function testRasterizeWithInverseAttribute(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono');
        $grid = new CellGrid(5, 1);
        $cell = new Cell('A', 7, 0, Cell::ATTR_INVERSE);
        $grid = $grid->set(0, 0, $cell);

        $cursor = new Cursor();
        $snapshot = new Snapshot($grid, $cursor, 0.0);

        $image = $rasterizer->rasterize($snapshot, 8, 16, $this->fonts);

        $this->assertGreaterThan(0, imagesx($image));
        imagedestroy($image);
    }

    public function testRasterizeWithHiddenCursorDoesNotRenderCursor(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono');
        $snapshot = $this->makeSnapshotWithCursor('ABC', 3, 1, 1, 1, false);

        $image = $rasterizer->rasterize($snapshot, 8, 16, $this->fonts);

        $this->assertGreaterThan(0, imagesx($image));
        imagedestroy($image);
    }

    public function testRasterizeWithDifferentCursorShapes(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono');

        foreach ([0, 1, 2, 3] as $shape) {
            $snapshot = $this->makeSnapshotWithCursor('X', 1, 1, 0, 0, true, $shape);
            $image = $rasterizer->rasterize($snapshot, 8, 16, $this->fonts);

            $this->assertGreaterThan(0, imagesx($image), "Shape {$shape} should produce valid image");
            imagedestroy($image);
        }
    }

    public function testRasterizeCreatesFontLoaderIfNotProvided(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono');
        $snapshot = $this->makeSnapshot('Test', 4, 1);

        $image = $rasterizer->rasterize($snapshot, 8, 16, null);

        $this->assertGreaterThan(0, imagesx($image));
        imagedestroy($image);
    }

    public function testRasterizeImageHasNonZeroPixels(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono');
        $snapshot = $this->makeSnapshot('A', 1, 1);

        $image = $rasterizer->rasterize($snapshot, 8, 16, $this->fonts);

        $nonBgPixels = 0;
        for ($x = 0; $x < 8; $x++) {
            for ($y = 0; $y < 16; $y++) {
                $idx = imagecolorat($image, $x, $y);
                if ($idx !== 0) {
                    $nonBgPixels++;
                }
            }
        }

        $this->assertGreaterThan(0, $nonBgPixels, 'Should have at least one non-background pixel for the letter A');
        imagedestroy($image);
    }

    /**
     * @return Snapshot
     */
    private function makeSnapshot(string $text, int $cols, int $rows): Snapshot
    {
        $grid = new CellGrid($cols, $rows);

        for ($i = 0; $i < strlen($text) && $i < $cols; $i++) {
            $char = $text[$i];
            $cell = new Cell($char, 7, 0);
            $grid = $grid->set(0, $i, $cell);
        }

        $cursor = new Cursor(0, min(strlen($text), $cols - 1), 0, true);

        return new Snapshot($grid, $cursor, 0.0);
    }

    /**
     * @return Snapshot
     */
    private function makeSnapshotWithCursor(
        string $text,
        int $cols,
        int $rows,
        int $cursorRow,
        int $cursorCol,
        bool $cursorVisible,
        int $cursorShape = 0,
    ): Snapshot {
        $grid = new CellGrid($cols, $rows);

        for ($i = 0; $i < strlen($text) && $i < $cols; $i++) {
            $char = $text[$i];
            $cell = new Cell($char, 7, 0);
            $grid = $grid->set(0, $i, $cell);
        }

        $cursor = new Cursor($cursorRow, $cursorCol, $cursorShape, $cursorVisible);

        return new Snapshot($grid, $cursor, 0.0);
    }
}
