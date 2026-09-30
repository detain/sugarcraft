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
 * F4 (round 90): Imagick mirror of GdRasterizerContinuationTest — both
 * rasterizers must walk the vt cell grid, not the mb_strwidth table.
 * Background-fill probe, font-independent.
 */
final class ImagickRasterizerContinuationTest extends TestCase
{
    private const CELL_W = 8;
    private const CELL_H = 16;
    private const RI = "\u{1F1E6}";

    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick not loaded');
        }
    }

    public function testGridNarrowCellIsPaintedEvenWhenMbStringWouldCallItWide(): void
    {
        $fixture = $this->snapshot(2, [new Cell(self::RI, 7, 0), new Cell('Z', 7, 1)]);
        $control = $this->snapshot(2, [new Cell(self::RI, 7, 0)]);

        $this->assertBandDiffersFromControl($fixture, $control, 1);
    }

    public function testContinuationTailIsCoveredByTheHeadsWideTileNotPaintedOver(): void
    {
        $head = new Cell('X', 7, 1);
        $fixture = $this->snapshot(2, [$head, Cell::continuation($head)]);
        $control = $this->snapshot(2, []);

        $this->assertBandDiffersFromControl($fixture, $control, 1);
    }

    /** @param list<Cell> $row0 */
    private function snapshot(int $cols, array $row0): Snapshot
    {
        $grid = new Buffer($cols, 1);
        foreach ($row0 as $col => $cell) {
            $grid->put(0, $col, $cell);
        }

        return new Snapshot($grid, new Cursor(0, 0, 0, false), 0.0);
    }

    private function collectBand(\Imagick $image, int $col): string
    {
        $sig = '';
        for ($x = $col * self::CELL_W; $x < ($col + 1) * self::CELL_W; $x++) {
            for ($y = 0; $y < self::CELL_H; $y++) {
                // This ext-imagick build spells the channels r/g/b (probe:
                // getColor() has no 'red' key) — read positionally-safe.
                $c = $image->getImagePixelColor($x, $y)->getColor();
                $sig .= sprintf('%d,%d,%d;', $c['r'], $c['g'], $c['b']);
            }
        }

        return $sig;
    }

    private function assertBandDiffersFromControl(Snapshot $fixture, Snapshot $control, int $col): void
    {
        $fonts = new FontLoader();
        $fixtureImage = (new ImagickRasterizer(14, 'DejaVuSansMono'))->rasterize($fixture, self::CELL_W, self::CELL_H, $fonts);
        $controlImage = (new ImagickRasterizer(14, 'DejaVuSansMono'))->rasterize($control, self::CELL_W, self::CELL_H, $fonts);
        try {
            $this->assertNotSame(
                $this->collectBand($controlImage, $col),
                $this->collectBand($fixtureImage, $col),
                "column band {$col} was never painted — the walker skipped a cell the grid allocated",
            );
        } finally {
            $fixtureImage->clear();
            $controlImage->clear();
        }
    }
}
