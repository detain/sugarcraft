<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Picture;

use SugarCraft\Core\Util\Color;

/**
 * Pure-PHP Sixel encoder (antecedent: the Sixel DCS grammar, ISO 8613-6;
 * serves ntcharts' `picture` Sixel-fallback role — the encoder
 * implementation is lib-local). Translates a 2D RGB pixel grid into a
 * `ESC P q ... ESC \` Device Control String suitable for terminals
 * that speak Sixel. Wire support: xterm (only --enable-sixel builds),
 * mlterm, foot, iTerm2, WezTerm, Windows Terminal (preview-gated since
 * 1.18). {@see Picture::detect()} auto-routes mlterm/foot/WezTerm/Windows
 * Terminal (plus iTerm2/kitty to their native protocols); bare xterm has
 * no reliable env signal and needs an explicit withProtocol().
 *
 * The encoder:
 *
 *  1. Quantises the input pixels to a fixed palette (default 16
 *     entries via a coarse RGB cube).
 *  2. Walks the grid in 6-row stripes. For each color active in the
 *     stripe, emits `#idx` followed by one sixel byte (`0x3f + 6-bit
 *     mask`) per column.
 *  3. Separates color passes within a stripe with `$` (carriage
 *     return) and stripes with `-` (line feed).
 *
 * Output is plain ASCII bytes (no Imagick / GD required).
 *
 * Encoded size scales with pixel count and color passes: the shipped
 * 16×6 / 16-color demo encodes to a little under a third of a
 * kilobyte, while a 320×200 image runs into the tens of kilobytes —
 * fine over local terminals, heavy over slow SSH, and the encoder does
 * no further compression.
 */
final class Sixel
{
    /**
     * Encode `$pixels` (a `[row][col] => Color` grid) into a Sixel
     * escape sequence.
     *
     * @param list<list<Color>> $pixels  rows top→bottom, cols left→right
     * @param int               $paletteSize  cap on the quantized palette
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
        // to a handful of dominant colors and the quantization is
        // nearest-neighbour on the cube.
        [$palette, $paletteCount] = self::buildPalette($paletteSize);

        // Quantize every pixel to a palette index. The nearest() answer is
        // a pure function of (rgb, palette) and the palette is fixed for
        // this call, so an RGB-keyed memo collapses repeated colors to one
        // scan — real images are color-heavy.
        $quant = [];
        $nearestMemo = [];
        for ($r = 0; $r < $rows; $r++) {
            $rowQ = [];
            for ($c = 0; $c < $cols; $c++) {
                $px = $pixels[$r][$c] ?? null;
                if ($px === null) {
                    $rowQ[] = 0;
                    continue;
                }
                $rgbKey = ($px->r << 16) | ($px->g << 8) | $px->b;
                if (!isset($nearestMemo[$rgbKey])) {
                    $nearestMemo[$rgbKey] = self::nearest($px, $palette, $paletteCount);
                }
                $rowQ[] = $nearestMemo[$rgbKey];
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
            // Per stripe: ONE pass over the columns accumulating each
            // color's bit masks, then emit the color passes. The old shape
            // re-walked every column and re-scanned the six rows per color
            // — O(rows × cols × K), multi-second for a 320×200@256 image
            // and 16× even at the default palette (sugar-charts-038). The
            // emitted bytes are identical; the cost is O(rows × cols) to
            // build plus the emission walk itself.
            $masks = [];
            for ($r = $stripeStart; $r < $stripeEnd; $r++) {
                $bit  = 1 << ($r - $stripeStart);
                $rowQ = $quant[$r];
                for ($c = 0; $c < $cols; $c++) {
                    $masks[$rowQ[$c]][$c] = ($masks[$rowQ[$c]][$c] ?? 0) | $bit;
                }
            }
            $colorList = array_keys($masks);
            sort($colorList);

            $first = true;
            foreach ($colorList as $idx) {
                if (!$first) {
                    $out .= '$';   // CR — overlay color passes within the same stripe.
                }
                $out .= '#' . $idx;
                for ($c = 0; $c < $cols; $c++) {
                    $out .= chr(0x3f + ($masks[$idx][$c] ?? 0));
                }
                $first = false;
            }
            // LF after EVERY stripe, final one included (sugar-charts-116):
            // mlterm-variants and older xterm park the cursor on the final
            // LF; emitters that ignore it are unaffected. No raster-geometry
            // (DECSTR) announce is sent — the payload carries no `P1;P2;P3`
            // header and terminals place the image by the stripe count.
            $out .= '-';
        }
        return $out . "\x1b\\";
    }

    /**
     * Build a `$paletteSize`-entry RGB palette by sampling a uniform
     * cube. Caps at or below 16 draw luminance-spread rungs from the ANSI
     * set (black through bright white, sugar-charts-117); larger caps append
     * cube colors after the full ANSI sixteen.
     *
     * @return array{0:list<array{0:int,1:int,2:int}>, 1:int}
     */
    private static function buildPalette(int $paletteSize): array
    {
        $paletteSize = max(2, min(256, $paletteSize));
        // Always include the 16 ANSI colors first.
        $palette = [
            [0, 0, 0],     [205, 0, 0],   [0, 205, 0],   [205, 205, 0],
            [0, 0, 238],   [205, 0, 205], [0, 205, 205], [229, 229, 229],
            [127, 127, 127], [255, 0, 0], [0, 255, 0],   [255, 255, 0],
            [92, 92, 255], [255, 0, 255], [0, 255, 255], [255, 255, 255],
        ];
        if ($paletteSize <= 16) {
            // Luminance-spread subset of ANSI-16 (sugar-charts-117): the old
            // prefix slice handed small caps black→dark colors only —
            // every bright and white collapsed. Evenly spaced rungs keep
            // both ends (black, bright white) at any size.
            $spread = [];
            $last   = count($palette) - 1;
            for ($i = 0; $i < $paletteSize; $i++) {
                $spread[] = $palette[(int) round($i * $last / ($paletteSize - 1))];
            }
            return [$spread, count($spread)];
        }
        // Add cube colors up to the cap.
        $extra = $paletteSize - 16;
        $axis  = max(2, (int) ceil(pow($extra, 1 / 3)));
        $paletteCount = count($palette);
        for ($r = 0; $r < $axis && $paletteCount < $paletteSize; $r++) {
            for ($g = 0; $g < $axis && $paletteCount < $paletteSize; $g++) {
                for ($b = 0; $b < $axis && $paletteCount < $paletteSize; $b++) {
                    $palette[] = [
                        (int) round($r * 255 / max(1, $axis - 1)),
                        (int) round($g * 255 / max(1, $axis - 1)),
                        (int) round($b * 255 / max(1, $axis - 1)),
                    ];
                    $paletteCount++;
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
