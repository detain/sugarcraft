<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Dither;

/**
 * Floyd-Steinberg dithering against a fixed palette.
 *
 * Error diffusion distribution:
 *       *  7/16
 *   3/16 5/16 1/16
 *
 * Produces perceptually superior results to nearest-neighbor or
 * ordered dithering when reducing to a limited palette.
 *
 * @see https://www.wikipedia.org/wiki/Floyd%E2%80%93Steinberg_dithering
 */
final class FloydSteinberg
{
    /**
     * Apply Floyd-Steinberg dithering to a GdImage using the given palette.
     *
     * @param list<array{0:int,1:int,2:int}> $palette  RGB triples (0–255)
     * @return \GdImage  A new dithered image (source is not modified)
     */
    public static function dither(\GdImage $src, array $palette): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);

        // Build per-channel float buffers initialized to 0.
        $errR = array_fill(0, $h, array_fill(0, $w + 2, 0.0));
        $errG = array_fill(0, $h, array_fill(0, $w + 2, 0.0));
        $errB = array_fill(0, $h, array_fill(0, $w + 2, 0.0));

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $oldR = imagecolorat($src, $x, $y);
                $oldR = ($oldR >> 16) & 0xff;
                $oldG = imagecolorat($src, $x, $y);
                $oldG = ($oldG >> 8) & 0xff;
                $oldB = imagecolorat($src, $x, $y);
                $oldB = $oldB & 0xff;

                // Add accumulated error.
                $newR = (int) min(255, max(0, $oldR + (int) round($errR[$y][$x + 1])));
                $newG = (int) min(255, max(0, $oldG + (int) round($errG[$y][$x + 1])));
                $newB = (int) min(255, max(0, $oldB + (int) round($errB[$y][$x + 1])));

                // Find nearest palette color.
                $idx = self::nearestPaletteIndex($newR, $newG, $newB, $palette);
                [$palR, $palG, $palB] = $palette[$idx];

                imagesetpixel($dst, $x, $y, imagecolorallocate($dst, $palR, $palG, $palB));

                // Quantization error.
                $errR[$y][$x + 1] = $newR - $palR;
                $errG[$y][$x + 1] = $newG - $palG;
                $errB[$y][$x + 1] = $newB - $palB;
            }
        }
        return $dst;
    }

    /**
     * Find the index of the nearest palette color by Euclidean distance.
     *
     * @param list<array{0:int,1:int,2:int}> $palette
     */
    private static function nearestPaletteIndex(int $r, int $g, int $b, array $palette): int
    {
        $bestIdx = 0;
        $bestDist = PHP_INT_MAX;
        foreach ($palette as $idx => [$pr, $pg, $pb]) {
            $dr = $r - $pr;
            $dg = $g - $pg;
            $db = $b - $pb;
            $dist = $dr * $dr + $dg * $dg + $db * $db;
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $bestIdx = $idx;
            }
        }
        return $bestIdx;
    }
}
