<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Canvas;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Charts\Canvas\Graph;

/**
 * The constant fill rune is measured once per call, not once per cell
 * (sugar-charts-113), and Graph::fillRect now paints through the same
 * hoisted Canvas::fill instead of a per-cell setCell loop. The hoist
 * must be invisible: byte-identical output everywhere.
 */
final class FillHoistTest extends TestCase
{
    public function testFillIsByteIdenticalToPerCellLoop(): void
    {
        foreach (['x', '日', ''] as $rune) {
            $a = new Canvas(9, 3);
            $a->fill(1, 0, 7, 2, $rune);
            $b = new Canvas(9, 3);
            for ($y = 0; $y <= 2; $y++) {
                for ($x = 1; $x <= 7; $x++) {
                    $b->setCell($x, $y, $rune);
                }
            }
            self::assertSame($b->view(), $a->view(), "fill vs per-cell setCell diverge for rune {$rune}");
        }
    }

    public function testFillHandlesSwappedCoordinates(): void
    {
        $a = new Canvas(5, 2);
        $a->fill(4, 1, 0, 0, 'x');
        $b = new Canvas(5, 2);
        $b->fill(0, 0, 4, 1, 'x');
        self::assertSame($b->view(), $a->view());
    }

    public function testFillRectDelegatesByteIdenticallyIncludingClamping(): void
    {
        // Old behavior = per-cell setCell, out-of-range writes silently
        // ignored; new = Canvas::fill clamps the same rect. Both must
        // paint the identical pixels for rects crossing every edge.
        foreach ([[0, 0, 4, 4], [-3, -2, 10, 12], [6, 6, 20, 20], [9, 9, 0, 0]] as [$x0, $y0, $x1, $y1]) {
            $a = new Canvas(6, 6);
            Graph::fillRect($a, $x0, $y0, $x1, $y1, '▒');
            $b = new Canvas(6, 6);
            if ($x0 > $x1) {
                [$x0, $x1] = [$x1, $x0];
            }
            if ($y0 > $y1) {
                [$y0, $y1] = [$y1, $y0];
            }
            for ($y = $y0; $y <= $y1; $y++) {
                for ($x = $x0; $x <= $x1; $x++) {
                    $b->setCell($x, $y, '▒');
                }
            }
            self::assertSame($b->view(), $a->view(), "fillRect delegation changed pixels for rect [{$x0},{$y0},{$x1},{$y1}]");
        }
    }

    public function testFillRectStillRefusesMultiCellRunes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Graph::fillRect(new Canvas(4, 4), 0, 0, 3, 3, 'ab');
    }

    public function testFillOf512SquareStaysUnderHoistedBudget(): void
    {
        // Measured on the gate host (best of 3): hoisted 171ms vs the
        // re-inlined per-cell measurement 256ms. The 200ms budget sits
        // between the two, so a reverted hoist reddens this pin.
        $best = PHP_FLOAT_MAX;
        for ($i = 0; $i < 3; $i++) {
            $canvas = new Canvas(512, 512);
            $start = hrtime(true);
            $canvas->fill(0, 0, 511, 511, 'x');
            $best = min($best, (hrtime(true) - $start) / 1_000_000);
        }
        self::assertLessThan(200.0, $best, "constant-fill must measure once per call; took {$best}ms");
    }
}
