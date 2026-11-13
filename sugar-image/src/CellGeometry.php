<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * Terminal-cell geometry — the arithmetic of turning a pixel aspect into cells.
 *
 * A terminal cell is not square: in a typical monospace font it is about twice
 * as tall as it is wide. A frame of `$width` cells by `$height` cells therefore
 * displays at aspect `width : height·cellAspect`, NOT `width : height`. Deriving
 * an auto height as `width / sourceAspect` counts cells as square and leaves
 * every auto-sized render displaying at half the source aspect — a square
 * source comes out twice as tall as it is wide. The correct box is
 * `width / (sourceAspect · cellAspect)` cells.
 *
 * This is the single source of truth for that conversion. Every place in the
 * lib that turns an aspect into a cell count goes through it, so the auto-height
 * path, the cover-crop path and the pixel-tier paths cannot drift apart again.
 *
 * Mirrors no upstream — SugarCraft addition. Neither chafa nor x/mosaic
 * exposes cell/pixel arithmetic as a type; upstreams carry cell sizes as
 * plain dimensions and convert inline.
 */
final class CellGeometry
{
    /**
     * Fallback height:width ratio of one terminal cell, used when the font's
     * real metrics are unknown (no probed cell size). Two to one is the
     * conventional monospace ratio and the same approximation the cover
     * scalers have always used.
     */
    public const DEFAULT_CELL_ASPECT = 2.0;

    /**
     * The height:width ratio of one cell, from probed font metrics when the
     * terminal reported them and {@see DEFAULT_CELL_ASPECT} when it did not.
     *
     * A degenerate reported cell (either dimension ≤ 0) falls back rather than
     * producing a zero or negative box.
     */
    public static function cellAspect(?CellSize $cellSize): float
    {
        if ($cellSize === null || $cellSize->cellWidth <= 0 || $cellSize->cellHeight <= 0) {
            return self::DEFAULT_CELL_ASPECT;
        }

        return $cellSize->cellHeight / $cellSize->cellWidth;
    }

    /**
     * Cell rows that display a source of `$sourceAspect` at `$widthCells`
     * without distortion — the inverse of the box the renderer actually paints.
     *
     * Never returns less than one row, matching the floor every renderer has
     * always applied to an auto height.
     *
     * @param int   $widthCells   Render width in terminal cells (must be > 0)
     * @param float $sourceAspect Source width ÷ source height (pixels)
     * @param float $cellAspect   Cell height ÷ cell width, from {@see cellAspect()}
     */
    public static function heightCells(int $widthCells, float $sourceAspect, float $cellAspect = self::DEFAULT_CELL_ASPECT): int
    {
        // A zero or negative aspect can only come from a degenerate source; a
        // zero cell aspect from a bad caller. Either would divide to ±INF, and
        // (int) INF is not a box — clamp to the smallest meaningful positive
        // value instead of propagating a float that truncates unpredictably.
        $safeAspect = $sourceAspect > 0.0 ? $sourceAspect : PHP_FLOAT_EPSILON;
        $safeCell   = $cellAspect > 0.0 ? $cellAspect : PHP_FLOAT_EPSILON;

        $rows = (int) round($widthCells / ($safeAspect * $safeCell));

        return $rows < 1 ? 1 : $rows;
    }

    /**
     * Cell columns that display a source of `$sourceAspect` at `$heightCells`
     * without distortion — the same law read from the other side, for callers
     * that budget by rows.
     */
    public static function widthCells(int $heightCells, float $sourceAspect, float $cellAspect = self::DEFAULT_CELL_ASPECT): int
    {
        $safeAspect = $sourceAspect > 0.0 ? $sourceAspect : PHP_FLOAT_EPSILON;
        $safeCell   = $cellAspect > 0.0 ? $cellAspect : PHP_FLOAT_EPSILON;

        $cols = (int) round($heightCells * $safeAspect * $safeCell);

        return $cols < 1 ? 1 : $cols;
    }
}
