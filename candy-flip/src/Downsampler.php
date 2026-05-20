<?php

declare(strict_types=1);

namespace SugarCraft\Flip;

/**
 * Downsample a GD image to a target cell grid.
 *
 * Two modes:
 *   - `NEAREST` — pick the center pixel of each cell (fast, low quality)
 *   - `AREA_AVERAGE` — average all pixels in each cell region (higher quality)
 */
final class Downsampler
{
    public const NEAREST      = 'nearest';
    public const AREA_AVERAGE = 'area_average';

    /**
     * @return list<list<array{0:int,1:int,2:int}|null>>
     */
    public static function downsample(
        \GdImage $img,
        int $cellsW,
        int $cellsH,
        string $mode = self::AREA_AVERAGE,
    ): array {
        return $mode === self::NEAREST
            ? self::nearest($img, $cellsW, $cellsH)
            : self::areaAverage($img, $cellsW, $cellsH);
    }

    /**
     * Nearest-neighbor: sample the center pixel of each cell.
     *
     * @return list<list<array{0:int,1:int,2:int}|null>>
     */
    private static function nearest(\GdImage $img, int $cellsW, int $cellsH): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $rows = [];
        for ($cy = 0; $cy < $cellsH; $cy++) {
            $row = [];
            for ($cx = 0; $cx < $cellsW; $cx++) {
                $sx = (int) (($cx + 0.5) * $w / $cellsW);
                $sy = (int) (($cy + 0.5) * $h / $cellsH);
                $rgb = imagecolorat($img, min($w - 1, $sx), min($h - 1, $sy));
                $r = ($rgb >> 16) & 0xff;
                $g = ($rgb >>  8) & 0xff;
                $b = ($rgb)       & 0xff;
                $row[] = [$r, $g, $b];
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Area-averaged downsampling: average all source pixels in each cell region.
     * Produces smoother gradients than nearest-neighbor.
     *
     * @return list<list<array{0:int,1:int,2:int}|null>>
     */
    private static function areaAverage(\GdImage $img, int $cellsW, int $cellsH): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $rows = [];
        for ($cy = 0; $cy < $cellsH; $cy++) {
            $row = [];
            for ($cx = 0; $cx < $cellsW; $cx++) {
                // Source pixel range for this cell (pixel coords, inclusive).
                $x0 = (int) ($cx * $w / $cellsW);
                $x1 = (int) (($cx + 1) * $w / $cellsW) - 1;
                $y0 = (int) ($cy * $h / $cellsH);
                $y1 = (int) (($cy + 1) * $h / $cellsH) - 1;
                $x1 = max($x0, $x1); // Clamp to at least one column.
                $y1 = max($y0, $y1); // Clamp to at least one row.

                $sumR = 0;
                $sumG = 0;
                $sumB = 0;
                $count = 0;
                for ($sy = $y0; $sy <= $y1; $sy++) {
                    for ($sx = $x0; $sx <= $x1; $sx++) {
                        $rgb = imagecolorat($img, $sx, $sy);
                        $sumR += ($rgb >> 16) & 0xff;
                        $sumG += ($rgb >>  8) & 0xff;
                        $sumB += ($rgb)        & 0xff;
                        $count++;
                    }
                }
                if ($count > 0) {
                    $row[] = [
                        (int) round($sumR / $count),
                        (int) round($sumG / $count),
                        (int) round($sumB / $count),
                    ];
                } else {
                    $row[] = [0, 0, 0];
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
