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

/**
 * F4 (round 90): the rasterizer walk must follow the vt cell-grid contract
 * (wide iff the neighbour is a Cell::continuation() tail; tails paint
 * nothing), not the independent mb_strwidth table, which disagrees with the
 * grid in both directions (regional-indicator pairs here, Unicode
 * reclassifications elsewhere).
 *
 * Discriminators are background-fill probes against a control render of the
 * same geometry — background tiles are painted irrespective of font
 * availability, so these pins are font-independent while still catching
 * "walker skipped this column" (the pre-fix regional-indicator failure:
 * mb says wide, grid says narrow, col 1 never painted) and
 * "head clipped at the last column" regressions.
 */
final class GdRasterizerContinuationTest extends TestCase
{
    private const CELL_W = 8;
    private const CELL_H = 16;
    /** vt-independent: 🇦 — mb_strwidth()==2 on builds whose EAW table predates vt's narrow treatment. */
    private const RI = "\u{1F1E6}";

    private FontLoader $fonts;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd required for the GD rasterizer');
        }
        $this->fonts = new FontLoader();
    }

    public function testGridNarrowCellIsPaintedEvenWhenMbStringWouldCallItWide(): void
    {
        // The regional-indicator divergence: grid holds two independent
        // narrow cells; the pre-fix oracle made 🇦 swallow column 1.
        $fixture = $this->snapshot(2, [new Cell(self::RI, 7, 0), new Cell('Z', 7, 1)]);
        $control = $this->snapshot(2, [new Cell(self::RI, 7, 0)]);

        $this->assertBandDiffersFromControl($fixture, $control, 1);
    }

    public function testLastColumnHeadIsPaintedNarrowInsteadOfBeingClippedAway(): void
    {
        // Pre-fix: isWide && col+1 >= cols => the cell was skipped entirely.
        $fixture = $this->snapshot(1, [new Cell(self::RI, 7, 1)]);
        $control = $this->snapshot(1, []);

        $this->assertBandDiffersFromControl($fixture, $control, 0);
    }

    public function testContinuationTailIsCoveredByTheHeadsWideTileNotPaintedOver(): void
    {
        $head = new Cell('X', 7, 1);
        $fixture = $this->snapshot(2, [$head, Cell::continuation($head)]);
        $control = $this->snapshot(2, []);

        // The head's 2-cell tile must carry its background across the tail.
        $this->assertBandDiffersFromControl($fixture, $control, 1);
    }

    public function testControlGeometryItselfPaintsNothingExtra(): void
    {
        // Vacuity guard: two identical grids must diff to zero, or the
        // probes above could pass on compositing noise alone.
        $a = $this->snapshot(2, [new Cell('a', 7, 0)]);
        $b = $this->snapshot(2, [new Cell('a', 7, 0)]);

        $this->assertSame(
            $this->collectBand($this->render($a), 0),
            $this->collectBand($this->render($b), 0),
        );
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

    private function render(Snapshot $snapshot): \GdImage
    {
        return (new GdRasterizer(14, 'DejaVuSansMono'))->rasterize($snapshot, self::CELL_W, self::CELL_H, $this->fonts);
    }

    /** @return list<int> raw colorat values across one column band */
    private function collectBand(\GdImage $image, int $col): array
    {
        $band = [];
        for ($x = $col * self::CELL_W; $x < ($col + 1) * self::CELL_W; $x++) {
            for ($y = 0; $y < self::CELL_H; $y++) {
                $v = imagecolorat($image, $x, $y); // false only out of bounds; band is in-bounds by construction
                $band[] = is_int($v) ? $v : -1;
            }
        }

        return $band;
    }

    private function assertBandDiffersFromControl(Snapshot $fixture, Snapshot $control, int $col): void
    {
        $fixtureImage = $this->render($fixture);
        $controlImage = $this->render($control);
        try {
            $differs = $this->collectBand($fixtureImage, $col) !== $this->collectBand($controlImage, $col);
        } finally {
            imagedestroy($fixtureImage);
            imagedestroy($controlImage);
        }
        $this->assertTrue($differs, "column band {$col} was never painted — the walker skipped a cell the grid allocated");
    }
}
