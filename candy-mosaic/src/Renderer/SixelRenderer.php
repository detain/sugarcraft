<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Renderer;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Mosaic\Dither;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Lang;

/**
 * Sixel graphics renderer — DEC sixel to terminal.
 *
 * Algorithm (per plan):
 *   1. Resize image to ($width × $effectiveHeight) via GD
 *   2. Quantize: extract pixels → median-cut → max $maxColors palette entries
 *   3. Error-diffusion dithering (optional, per Dither enum)
 *   4. Build index grid: grid[row][col] = palette index
 *   5. For each 6-row band:
 *        For each color used in band: emit color introducer + sixel data
 *        (RLE applied: "!" + count prefix before runs of same sixel byte)
 *
 * The Sixel protocol supports a maximum of 256 colors. Pass
 * {@see maxColors()} to limit the palette when 256-color fallback is
 * desired (e.g. terminals that advertise Sixel but have limited
 * truecolor support).
 */
final class SixelRenderer implements Renderer
{
    /**
     * @param int $cellWidth  Pixel width of a terminal cell — the render() cell
     *                        dimensions are multiplied by this so a sixel poster
     *                        fills its cell box (not one device pixel per cell).
     * @param int $cellHeight Pixel height of a terminal cell.
     */
    public function __construct(
        private readonly Dither $dither = Dither::FloydSteinberg,
        private readonly int $maxColors = 256,
        private readonly int $cellWidth = 10,
        private readonly int $cellHeight = 20,
    ) {
        if ($maxColors < 1 || $maxColors > 256) {
            throw new \InvalidArgumentException(
                Lang::t('sixel.max_colors_out_of_range', ['maxColors' => $maxColors])
            );
        }
    }

    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        if ($width <= 0) {
            throw new \InvalidArgumentException(
                Lang::t('renderer.invalid_width', ['width' => $width])
            );
        }

        if ($height !== null && $height <= 0) {
            throw new \InvalidArgumentException(
                Lang::t('renderer.invalid_height', ['height' => $height])
            );
        }

        $cellH = $height ?? (int) round($width / $image->aspectRatio());
        if ($cellH <= 0) {
            $cellH = 1;
        }

        // The cell box maps to a PIXEL canvas (cells × terminal cell size) so the
        // image fills its area; one device-pixel-per-cell would be microscopic.
        $pixelW = max(1, $width * $this->cellWidth);
        $pixelH = max(1, $cellH * $this->cellHeight);

        // Load and resize the image to the pixel canvas.
        $src = imagecreatefromstring($image->bytes);
        if ($src === false) {
            throw new \RuntimeException(Lang::t('renderer.gd_load_failed'));
        }
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }
        $resized = imagecreatetruecolor($pixelW, $pixelH);
        if ($resized === false) {
            imagedestroy($src);
            throw new \RuntimeException(Lang::t('renderer.gd_resize_failed'));
        }
        imagecopyresampled(
            $resized, $src,
            0, 0, 0, 0,
            $pixelW, $pixelH,
            imagesx($src), imagesy($src)
        );
        imagedestroy($src);

        try {
            // The palette only needs a representative sample, not every pixel —
            // median-cut over a capped subset is far cheaper and visually
            // indistinguishable at thumbnail sizes.
            $palette = $this->medianCut($this->samplePixels($resized, 4096), $this->maxColors);

            // Apply error-diffusion dithering before building the index grid.
            $grid = $this->dither === Dither::None
                ? $this->buildIndexGrid($resized, $palette)
                : $this->ditheredIndexGrid($resized, $palette, $this->dither);

            $out = Ansi::sixelDcsHeader($pixelW, $pixelH);
            $out .= $this->emitPalette($palette);

            for ($bandTop = 0; $bandTop < $pixelH; $bandTop += 6) {
                $bandBottom = min($bandTop + 6, $pixelH);
                $out .= $this->emitBand($grid, $bandTop, $bandBottom, $pixelW, $palette);
                // Graphics newline `-` advances to the next 6-row band (NOT "\n",
                // which a terminal reads as a literal line feed and which breaks
                // the image).
                if ($bandBottom < $pixelH) {
                    $out .= '-';
                }
            }

            $out .= Ansi::sixelTerminator();

            return $out;
        } finally {
            imagedestroy($resized);
        }
    }

    public function name(): string
    {
        return 'sixel';
    }

    public function supportsAlpha(): bool
    {
        return false;
    }

    public function isInline(): bool
    {
        return false;
    }

    /**
     * Sixel has no standard delete mechanism — DECSIXEL does not support
     * removing individual images after emission. Returns the empty string.
     */
    public function delete(string $imageId): string
    {
        return '';
    }

    public function dither(): Dither
    {
        return $this->dither;
    }

    /**
     * The maximum number of colors in the quantized Sixel palette.
     *
     * The Sixel protocol supports at most 256 colors. Values below 256
     * invoke the 256-color fallback — useful for terminals that advertise
     * Sixel but have limited truecolor support.
     */
    public function maxColors(): int
    {
        return $this->maxColors;
    }

    // ─── Pixel extraction ─────────────────────────────────────────────────────

    /**
     * Collect up to $max representative pixels by striding over the image, for
     * building the palette. Sampling instead of reading every pixel keeps
     * median-cut cheap on the larger pixel canvas.
     *
     * @return list<array{int,int,int}>
     */
    private function samplePixels(\GdImage $img, int $max): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $total = $w * $h;
        $step = $total > $max ? (int) ceil($total / $max) : 1;

        $pixels = [];
        $i = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (($i++ % $step) !== 0) {
                    continue;
                }
                $c = imagecolorsforindex($img, imagecolorat($img, $x, $y));
                $pixels[] = [$c['red'], $c['green'], $c['blue']];
            }
        }

        return $pixels;
    }

    // ─── Quantizer ────────────────────────────────────────────────────────────

    /**
     * Median-cut quantizer.
     *
     * @param list<array{int,int,int}> $pixels
     * @return list<array{int,int,int}>  RGB palette, max 256 entries
     */
    private function medianCut(array $pixels, int $maxColors): array
    {
        $maxColors = max(1, min(256, $maxColors));

        if ($pixels === []) {
            return [[0, 0, 0]];
        }

        /** @var list<list<array{int,int,int}>> */
        $buckets = [$pixels];

        while (count($buckets) < $maxColors) {
            $largestIdx = $this->largestBucketIndex($buckets);
            if ($largestIdx === null) {
                break;
            }
            $split = $this->splitBucket($buckets[$largestIdx]);
            if ($split === null) {
                break;
            }
            array_splice($buckets, $largestIdx, 1, $split);
        }

        return array_map(
            fn(array $bucket): array => $this->avgBucket($bucket),
            $buckets
        );
    }

    private function largestBucketIndex(array $buckets): ?int
    {
        $largestIdx   = null;
        $largestRange = -1;

        foreach ($buckets as $idx => $bucket) {
            if (count($bucket) < 2) {
                continue;
            }
            $range = $this->bucketRange($bucket);
            if ($range > $largestRange) {
                $largestRange = $range;
                $largestIdx   = $idx;
            }
        }

        return $largestIdx;
    }

    /**
     * @param list<array{int,int,int}> $bucket
     * @return array{0:list<array{int,int,int}>,1:list<array{int,int,int}>}|null
     */
    private function splitBucket(array $bucket): ?array
    {
        if (count($bucket) < 2) {
            return null;
        }

        [$minR, $maxR] = [$bucket[0][0], $bucket[0][0]];
        [$minG, $maxG] = [$bucket[0][1], $bucket[0][1]];
        [$minB, $maxB] = [$bucket[0][2], $bucket[0][2]];
        foreach ($bucket as $px) {
            if ($px[0] < $minR) { $minR = $px[0]; }
            if ($px[0] > $maxR) { $maxR = $px[0]; }
            if ($px[1] < $minG) { $minG = $px[1]; }
            if ($px[1] > $maxG) { $maxG = $px[1]; }
            if ($px[2] < $minB) { $minB = $px[2]; }
            if ($px[2] > $maxB) { $maxB = $px[2]; }
        }
        $rRange = $maxR - $minR;
        $gRange = $maxG - $minG;
        $bRange = $maxB - $minB;

        if ($rRange === 0 && $gRange === 0 && $bRange === 0) {
            return null;
        }

        $axis = $rRange >= $gRange && $rRange >= $bRange ? 0
            : ($gRange >= $bRange ? 1 : 2);

        usort($bucket, static fn(array $a, array $b): int => $a[$axis] <=> $b[$axis]);
        $mid = (int) floor(count($bucket) / 2);

        $a = array_slice($bucket, 0, $mid);
        $b = array_slice($bucket, $mid);

        return ($a !== [] && $b !== []) ? [$a, $b] : null;
    }

    private function bucketRange(array $bucket): int
    {
        $minR = $maxR = $bucket[0][0];
        $minG = $maxG = $bucket[0][1];
        $minB = $maxB = $bucket[0][2];
        foreach ($bucket as $px) {
            if ($px[0] < $minR) { $minR = $px[0]; }
            if ($px[0] > $maxR) { $maxR = $px[0]; }
            if ($px[1] < $minG) { $minG = $px[1]; }
            if ($px[1] > $maxG) { $maxG = $px[1]; }
            if ($px[2] < $minB) { $minB = $px[2]; }
            if ($px[2] > $maxB) { $maxB = $px[2]; }
        }
        return ($maxR - $minR) + ($maxG - $minG) + ($maxB - $minB);
    }

    /** @param list<array{int,int,int}> $bucket */
    private function avgBucket(array $bucket): array
    {
        $sumR = $sumG = $sumB = 0;
        foreach ($bucket as $px) {
            $sumR += $px[0];
            $sumG += $px[1];
            $sumB += $px[2];
        }
        $n = count($bucket);
        return [
            (int) round($sumR / $n),
            (int) round($sumG / $n),
            (int) round($sumB / $n),
        ];
    }

    // ─── Index grid (no dithering) ────────────────────────────────────────────

    /**
     * Map each pixel to its nearest palette entry by Euclidean RGB distance.
     *
     * @return list<list<int>>  grid[row][col] = palette index
     */
    private function buildIndexGrid(\GdImage $img, array $palette): array
    {
        $w    = imagesx($img);
        $h    = imagesy($img);
        $grid = [];
        $cache = []; // packed RGB → palette index; posters reuse few colours.
        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                $idx = imagecolorat($img, $x, $y);
                $c   = imagecolorsforindex($img, $idx);
                // Key the memo on a coarse 5-bit-per-channel cube so it caps at
                // 32768 entries — nearestColor runs O(distinct cubes), not
                // O(pixels), bounding cost regardless of image size.
                $key = (($c['red'] >> 3) << 10) | (($c['green'] >> 3) << 5) | ($c['blue'] >> 3);
                $row[] = $cache[$key] ??= $this->nearestColor($c['red'], $c['green'], $c['blue'], $palette);
            }
            $grid[] = $row;
        }
        return $grid;
    }

    // ─── Index grid (error-diffusion dithering) ───────────────────────────────

    /**
     * Build an index grid with error-diffusion dithering.
     *
     * Each pixel is quantized to its nearest palette entry, the rounding
     * error is accumulated, and that error is diffused to neighboring
     * unprocessed pixels using Floyd–Steinberg, Stucki, or Atkinson
     * coefficients before they are themselves quantized.
     *
     * @param list<array{int,int,int}> $palette
     */
    private function ditheredIndexGrid(
        \GdImage $img,
        array $palette,
        Dither $dither,
    ): array {
        $w = imagesx($img);
        $h = imagesy($img);

        // Accumulated floating-point pixel values (RGB, may exceed [0,255]
        // during error diffusion — clamped before quantization).
        /** @var list<list<array{float,float,float}> $accum */
        $accum = [];
        for ($y = 0; $y < $h; $y++) {
            $accum[$y] = [];
            for ($x = 0; $x < $w; $x++) {
                $idx = imagecolorat($img, $x, $y);
                $c   = imagecolorsforindex($img, $idx);
                $accum[$y][$x] = [(float) $c['red'], (float) $c['green'], (float) $c['blue']];
            }
        }

        $grid = [];
        $cache = []; // packed rounded RGB → palette index, reused across pixels.

        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                // Quantize the accumulated (possibly error-diffused) value.
                [$r, $g, $b] = $accum[$y][$x];
                $clampedR = max(0.0, min(255.0, $r));
                $clampedG = max(0.0, min(255.0, $g));
                $clampedB = max(0.0, min(255.0, $b));

                $ri = (int) round($clampedR);
                $gi = (int) round($clampedG);
                $bi = (int) round($clampedB);
                // Coarse 5-bit cube key (see buildIndexGrid) caps the memo at
                // 32768 nearest-colour computations across the whole image.
                $key = (($ri >> 3) << 10) | (($gi >> 3) << 5) | ($bi >> 3);
                $palIdx = $cache[$key] ??= $this->nearestColor($ri, $gi, $bi, $palette);
                [$pr, $pg, $pb] = $palette[$palIdx];

                // Quantization error (original − quantized).
                $eR = $clampedR - (float) $pr;
                $eG = $clampedG - (float) $pg;
                $eB = $clampedB - (float) $pb;

                $this->diffuseError($accum, $w, $h, $x, $y, $eR, $eG, $eB, $dither);

                $row[] = $palIdx;
            }
            $grid[] = $row;
        }

        return $grid;
    }

    /**
     * Diffuse quantization error to future (unprocessed) neighbors.
     *
     * Coefficient layouts (current pixel marked ·):
     *
     * Floyd–Steinberg (propagates ¾ of error):
     *    ·  7/16
     *  3/16 5/16 7/16
     *
     * Stucki (propagates to 12 neighbors, slightly sharper):
     *    ·  8/42  4/42
     *  2/42 8/42 4/42 2/42
     *  1/42 2/42 4/42 2/42 1/42
     *
     * Atkinson (Apple; propagates ¾, more contrast/lighter result):
     *    ·  1/8  1/8
     *  1/8  1/8  1/8
     *       1/8
     *
     * @param list<list<array{float,float,float}> $accum
     */
    private function diffuseError(
        array &$accum,
        int $w, int $h,
        int $x, int $y,
        float $eR, float $eG, float $eB,
        Dither $dither,
    ): void {
        $neighbors = match ($dither) {
            // Floyd–Steinberg: 4 neighbors
            Dither::FloydSteinberg => [
                [$x + 1, $y,     7.0 / 16.0],
                [$x - 1, $y + 1, 3.0 / 16.0],
                [$x,     $y + 1, 5.0 / 16.0],
                [$x + 1, $y + 1, 1.0 / 16.0],
            ],
            // Stucki: 12 neighbors
            Dither::Stucki => [
                [$x + 1, $y,     8.0 / 42.0],
                [$x + 2, $y,     4.0 / 42.0],
                [$x - 2, $y + 1, 1.0 / 42.0],
                [$x - 1, $y + 1, 2.0 / 42.0],
                [$x,     $y + 1, 4.0 / 42.0],
                [$x + 1, $y + 1, 2.0 / 42.0],
                [$x + 2, $y + 1, 1.0 / 42.0],
                [$x - 2, $y + 2, 1.0 / 42.0],
                [$x - 1, $y + 2, 2.0 / 42.0],
                [$x,     $y + 2, 4.0 / 42.0],
                [$x + 1, $y + 2, 2.0 / 42.0],
                [$x + 2, $y + 2, 1.0 / 42.0],
            ],
            // Atkinson: 6 neighbors, only ¾ of error propagates
            Dither::Atkinson => [
                [$x + 1, $y,     1.0 / 8.0],
                [$x + 2, $y,     1.0 / 8.0],
                [$x - 1, $y + 1, 1.0 / 8.0],
                [$x,     $y + 1, 1.0 / 8.0],
                [$x + 1, $y + 1, 1.0 / 8.0],
                [$x,     $y + 2, 1.0 / 8.0],
            ],
            default => [],
        };

        foreach ($neighbors as [$nx, $ny, $factor]) {
            if ($nx >= 0 && $nx < $w && $ny >= 0 && $ny < $h) {
                $accum[$ny][$nx][0] += $eR * $factor;
                $accum[$ny][$nx][1] += $eG * $factor;
                $accum[$ny][$nx][2] += $eB * $factor;
            }
        }
    }

    // ─── Palette & nearest-color ──────────────────────────────────────────────

    /** @param list<array{int,int,int}> $palette */
    private function nearestColor(int $r, int $g, int $b, array $palette): int
    {
        $best     = 0;
        $bestDist = PHP_INT_MAX;
        foreach ($palette as $i => $entry) {
            $dr   = $r - $entry[0];
            $dg   = $g - $entry[1];
            $db   = $b - $entry[2];
            $dist = $dr * $dr + $dg * $dg + $db * $db;
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best     = $i;
            }
        }
        return $best;
    }

    // ─── Sixel encoding ───────────────────────────────────────────────────────

    /** @param list<array{int,int,int}> $palette */
    private function emitPalette(array $palette): string
    {
        $out = '';
        foreach ($palette as $i => [$r, $g, $b]) {
            $out .= Ansi::sixelColorIntroducer($i, $r, $g, $b);
        }
        return $out;
    }

    /**
     * Encode one 6-pixel-tall band.
     *
     * @param list<list<int>>          $grid
     * @param list<array{int,int,int}> $palette
     */
    private function emitBand(
        array $grid,
        int $bandTop,
        int $bandBottom,
        int $width,
        array $palette,
    ): string {
        // Discover which palette indices appear in this band.
        $activeColors = [];
        for ($row = $bandTop; $row < $bandBottom; $row++) {
            foreach ($grid[$row] ?? [] as $pal) {
                $activeColors[$pal] = true;
            }
        }

        if ($activeColors === []) {
            return '';
        }

        $out = '';
        $first = true;

        foreach (array_keys($activeColors) as $palIndex) {
            // Each colour pass starts back at the band's left edge. `$` is a
            // graphics carriage-return; the colours overlay on the same 6-row
            // band rather than printing one after another.
            if (!$first) {
                $out .= '$';
            }
            $first = false;

            $out .= Ansi::sixelColorSelect($palIndex);

            $col      = 0;
            $runCount = 0;
            $prevBits = -1;

            while ($col < $width) {
                // The data byte holds ONLY the 6-row bitmask for the active
                // colour (the colour itself was selected above) — the previous
                // `palIndex << 6` corrupted every byte.
                $bits = 0;
                for ($row = $bandTop; $row < $bandBottom; $row++) {
                    if (($grid[$row][$col] ?? 0) === $palIndex) {
                        $bits |= (1 << ($row - $bandTop));
                    }
                }

                if ($bits === $prevBits) {
                    $runCount++;
                } else {
                    if ($runCount > 0) {
                        $out .= $this->emitRle($prevBits, $runCount);
                    }
                    $prevBits = $bits;
                    $runCount = 1;
                }
                $col++;
            }

            if ($runCount > 0) {
                $out .= $this->emitRle($prevBits, $runCount);
            }
        }

        return $out;
    }

    /**
     * One sixel data byte = `bits + 63` (printable range 63-126), run-length
     * encoded as `!count byte` when a column run repeats.
     */
    private function emitRle(int $bits, int $count): string
    {
        $char = chr(($bits & 0x3F) + 63);

        return $count > 1 ? '!' . $count . $char : $char;
    }
}
