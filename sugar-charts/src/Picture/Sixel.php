<?php

declare(strict_types=1);

namespace CandyCore\Charts\Picture;

use CandyCore\Core\Util\Color;

/**
 * Pure-PHP Sixel encoder. Translates a 2D RGB pixel grid into a
 * `ESC P q ... ESC \` Device Control String suitable for terminals
 * that speak Sixel (xterm, mlterm, foot, iTerm2, WezTerm, Windows
 * Terminal preview).
 *
 * The encoder:
 *
 *  1. Quantises the input pixels to a fixed palette (default 16
 *     entries via a coarse RGB cube).
 *  2. Walks the grid in 6-row stripes. For each colour active in the
 *     stripe, emits `#idx` followed by one sixel byte (`0x3f + 6-bit
 *     mask`) per column.
 *  3. Separates colour passes within a stripe with `$` (carriage
 *     return) and stripes with `-` (line feed).
 *
 * Output is plain ASCII bytes (no Imagick / GD required).
 *
 * For a 320×200 image with 16 colours the output is roughly 30-60 KB
 * — fine over local terminals, somewhat heavy over slow SSH but the
 * encoder doesn't try to do further compression.
 */
final class Sixel
{
    /**
     * Encode `$pixels` (a `[row][col] => Color` grid) into a Sixel
     * escape sequence.
     *
     * @param list<list<Color>> $pixels  rows top→bottom, cols left→right
     * @param int               $paletteSize  cap on the quantised palette
     */
    public static function encode(array $pixels, int $paletteSize = 16): string
    {
        if ($pixels === []) {
            return '';
        }
        $rows = count($pixels);
        $cols = max(array_map('count', $pixels));
        if ($rows <= 0 || $cols <= 0) {
            return '';
        }

        // Build the palette by sampling a uniform RGB cube. Larger
        // palettes don't necessarily look better — most images map
        // to a handful of dominant colours and the quantisation is
        // nearest-neighbour on the cube.
        [$palette, $paletteCount] = self::buildPalette($paletteSize);

        // Quantise every pixel to a palette index.
        $quant = [];
        for ($r = 0; $r < $rows; $r++) {
            $rowQ = [];
            for ($c = 0; $c < $cols; $c++) {
                $px = $pixels[$r][$c] ?? null;
                $rowQ[] = $px === null
                    ? 0
                    : self::nearest($px, $palette, $paletteCount);
            }
            $quant[] = $rowQ;
        }

        $out = "\x1bPq";  // DCS Sixel intro
        // Emit every palette entry as `#idx;2;r;g;b` (HLS=2 for RGB,
        // values in 0-100).
        foreach ($palette as $idx => [$r, $g, $b]) {
            if ($idx >= $paletteCount) {
                break;
            }
            $rp = (int) round($r * 100 / 255);
            $gp = (int) round($g * 100 / 255);
            $bp = (int) round($b * 100 / 255);
            $out .= "#{$idx};2;{$rp};{$gp};{$bp}";
        }

        // Walk in 6-row stripes.
        for ($stripeStart = 0; $stripeStart < $rows; $stripeStart += 6) {
            $stripeEnd = min($stripeStart + 6, $rows);
            // Per stripe: for each colour active in the stripe, emit
            // a colour pass.
            $colours = [];
            for ($r = $stripeStart; $r < $stripeEnd; $r++) {
                foreach ($quant[$r] as $idx) {
                    $colours[$idx] = true;
                }
            }
            $colourList = array_keys($colours);
            sort($colourList);

            $first = true;
            foreach ($colourList as $idx) {
                if (!$first) {
                    $out .= '$';   // CR — overlay colour passes within the same stripe.
                }
                $out .= '#' . $idx;
                for ($c = 0; $c < $cols; $c++) {
                    $bits = 0;
                    for ($k = 0; $k < ($stripeEnd - $stripeStart); $k++) {
                        $row = $stripeStart + $k;
                        if (($quant[$row][$c] ?? -1) === $idx) {
                            $bits |= (1 << $k);
                        }
                    }
                    $out .= chr(0x3f + $bits);
                }
                $first = false;
            }
            if ($stripeEnd < $rows) {
                $out .= '-';   // LF — advance to the next stripe.
            }
        }
        return $out . "\x1b\\";
    }

    /**
     * Build a `$paletteSize`-entry RGB palette by sampling a uniform
     * cube. The first 16 entries cover the standard ANSI palette so
     * small images map cleanly.
     *
     * @return array{0:list<array{0:int,1:int,2:int}>, 1:int}
     */
    private static function buildPalette(int $paletteSize): array
    {
        $paletteSize = max(2, min(256, $paletteSize));
        // Always include the 16 ANSI colours first.
        $palette = [
            [0, 0, 0],     [205, 0, 0],   [0, 205, 0],   [205, 205, 0],
            [0, 0, 238],   [205, 0, 205], [0, 205, 205], [229, 229, 229],
            [127, 127, 127], [255, 0, 0], [0, 255, 0],   [255, 255, 0],
            [92, 92, 255], [255, 0, 255], [0, 255, 255], [255, 255, 255],
        ];
        if ($paletteSize <= 16) {
            $palette = array_slice($palette, 0, $paletteSize);
            return [$palette, count($palette)];
        }
        // Add cube colours up to the cap.
        $extra = $paletteSize - 16;
        $axis  = max(2, (int) ceil(pow($extra, 1 / 3)));
        for ($r = 0; $r < $axis && count($palette) < $paletteSize; $r++) {
            for ($g = 0; $g < $axis && count($palette) < $paletteSize; $g++) {
                for ($b = 0; $b < $axis && count($palette) < $paletteSize; $b++) {
                    $palette[] = [
                        (int) round($r * 255 / max(1, $axis - 1)),
                        (int) round($g * 255 / max(1, $axis - 1)),
                        (int) round($b * 255 / max(1, $axis - 1)),
                    ];
                }
            }
        }
        return [$palette, count($palette)];
    }

    /**
     * Squared-RGB nearest-neighbour. Good enough for indexing.
     *
     * @param list<array{0:int,1:int,2:int}> $palette
     */
    private static function nearest(Color $px, array $palette, int $count): int
    {
        $best = 0;
        $bestDist = PHP_INT_MAX;
        for ($i = 0; $i < $count; $i++) {
            [$r, $g, $b] = $palette[$i];
            $dr = $r - $px->r;
            $dg = $g - $px->g;
            $db = $b - $px->b;
            $d = $dr * $dr + $dg * $dg + $db * $db;
            if ($d < $bestDist) {
                $bestDist = $d;
                $best = $i;
            }
        }
        return $best;
    }
}
