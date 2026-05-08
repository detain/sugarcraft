<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Renderer;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Lang;

/**
 * Sixel graphics renderer — DEC sixel to terminal.
 *
 * Algorithm (per plan):
 *   1. Resize image to ($width × $effectiveHeight) via GD
 *   2. Quantize: extract pixels → median-cut → max 256 palette entries
 *   3. Build index grid: grid[row][col] = palette index
 *   4. For each 6-row band:
 *        For each color used in band: emit color introducer + sixel data
 *        (RLE applied: "!" + count prefix before runs of same sixel byte)
 */
final class SixelRenderer implements Renderer
{
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

        $effectiveHeight = $height ?? (int) round($width / $image->aspectRatio());
        if ($effectiveHeight <= 0) {
            $effectiveHeight = 1;
        }

        // Load and resize the image.
        $src = imagecreatefromstring($image->bytes);
        if ($src === false) {
            throw new \RuntimeException(Lang::t('renderer.gd_load_failed'));
        }
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }
        $resized = imagecreatetruecolor($width, $effectiveHeight);
        if ($resized === false) {
            imagedestroy($src);
            throw new \RuntimeException(Lang::t('renderer.gd_resize_failed'));
        }
        imagecopyresampled(
            $resized, $src,
            0, 0, 0, 0,
            $width, $effectiveHeight,
            imagesx($src), imagesy($src)
        );
        imagedestroy($src);

        try {
            $pixels  = $this->extractPixels($resized);
            $palette = $this->medianCut($pixels, 256);
            $grid    = $this->buildIndexGrid($resized, $palette);

            $out = Ansi::sixelDcsHeader($width, $effectiveHeight);
            $out .= $this->emitPalette($palette);

            for ($bandTop = 0; $bandTop < $effectiveHeight; $bandTop += 6) {
                $bandBottom = min($bandTop + 6, $effectiveHeight);
                $out .= $this->emitBand($grid, $bandTop, $bandBottom, $width, $palette);
                if ($bandBottom < $effectiveHeight) {
                    $out .= "\n";
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

    // ─── Pixel extraction ─────────────────────────────────────────────────────

    /** @return list<array{int,int,int}> */
    private function extractPixels(\GdImage $img): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $pixels = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $idx = imagecolorat($img, $x, $y);
                $c   = imagecolorsforindex($img, $idx);
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
                break; // no splittable bucket (all have < 2 pixels or range = 0)
            }
            $split = $this->splitBucket($buckets[$largestIdx]);
            if ($split === null) {
                break; // bucket could not be split (all same color)
            }
            // Replace the original bucket with its two halves.
            array_splice($buckets, $largestIdx, 1, $split);
        }

        return array_map(
            fn(array $bucket): array => $this->avgBucket($bucket),
            $buckets
        );
    }

    /**
     * Find the index of the bucket with the largest colour range.
     * Returns null when no bucket can be split (all have < 2 pixels).
     *
     * @param list<list<array{int,int,int}>> $buckets
     */
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
     * Split a single bucket into two halves along the axis with
     * greatest colour range. Returns null when the bucket cannot be
     * split (fewer than 2 pixels, or all pixels are the same colour).
     *
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

        // Already uniform — no point splitting.
        if ($rRange === 0 && $gRange === 0 && $bRange === 0) {
            return null;
        }

        $axis   = $rRange >= $gRange && $rRange >= $bRange ? 0
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
        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                $idx = imagecolorat($img, $x, $y);
                $c   = imagecolorsforindex($img, $idx);
                $row[] = $this->nearestColor($c['red'], $c['green'], $c['blue'], $palette);
            }
            $grid[] = $row;
        }
        return $grid;
    }

    /** @param list<array{int,int,int}> $palette */
    private function nearestColor(int $r, int $g, int $b, array $palette): int
    {
        $best    = 0;
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

        foreach (array_keys($activeColors) as $palIndex) {
            // DECGCR selects a declared palette entry for subsequent sixel data.
            $out .= Ansi::sixelColorSelect($palIndex);

            // Emit sixel columns for this color only.
            // Bitmask for each column: bit 0 = top row of band.
            // Sixel byte = (palIndex << 6) | bitmask
            $sixelBase = $palIndex << 6;

            $col   = 0;
            $runCount = 0;
            $prevByte  = -1;

            while ($col < $width) {
                $bitmask = 0;
                for ($row = $bandTop; $row < $bandBottom; $row++) {
                    if (($grid[$row][$col] ?? 0) === $palIndex) {
                        $bitmask |= (1 << ($row - $bandTop));
                    }
                }
                $sixelByte = $sixelBase | $bitmask;

                if ($sixelByte === $prevByte) {
                    $runCount++;
                } else {
                    if ($runCount > 0) {
                        $out .= $this->emitRle($prevByte, $runCount);
                    }
                    $prevByte  = $sixelByte;
                    $runCount  = 1;
                }
                $col++;
            }

            if ($runCount > 0) {
                $out .= $this->emitRle($prevByte, $runCount);
            }
        }

        return $out;
    }

    private function emitRle(int $sixelByte, int $count): string
    {
        // Sixel bytes must be printable ASCII 63-126 (0x3F-0x7E).
        // We already OR'd the index into bits 6-7, so mask and clamp.
        $ascii = ($sixelByte >= 0 && $sixelByte < 128)
            ? max(63, min(126, $sixelByte))
            : 63;
        $char = chr($ascii);

        return $count > 1 ? "!$count$char" : $char;
    }
}
