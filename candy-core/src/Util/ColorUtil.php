<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Shared color computation utilities for the SugarCraft monorepo.
 *
 * Provides BT.601 luma calculation and RGB squared distance — used by
 * image renderers (candy-mosaic) for color grouping and comparison.
 */
final class ColorUtil
{
    /**
     * Compute the BT.601 luma of an RGB color.
     *
     * Luma represents the perceived brightness of a color in grayscale.
     * The BT.601 coefficients (77, 150, 29) are weighted to match human
     * visual sensitivity: green appears brighter than red, which appears
     * brighter than blue.
     *
     * @param int $r Red component [0-255]
     * @param int $g Green component [0-255]
     * @param int $b Blue component [0-255]
     */
    public static function luma(int $r, int $g, int $b): int
    {
        return (($r * 77) + ($g * 150) + ($b * 29)) >> 8;
    }

    /**
     * Compute the squared Euclidean distance between two RGB colors.
     *
     * Squared distance avoids the expensive sqrt() call of true Euclidean
     * distance while preserving ordering — if dist(a,b) < dist(c,d) then
     * sqrt(dist(a,b)) < sqrt(dist(c,d)). This is sufficient for nearest-
     * color comparisons in palette quantization.
     *
     * @param array{int,int,int} $a First RGB color
     * @param array{int,int,int} $b Second RGB color
     */
    public static function squaredDistance(array $a, array $b): int
    {
        $dr = $a[0] - $b[0];
        $dg = $a[1] - $b[1];
        $db = $a[2] - $b[2];
        return ($dr * $dr) + ($dg * $dg) + ($db * $db);
    }
}
