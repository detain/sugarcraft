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
    use \SugarCraft\Mosaic\Concerns\RenderValidationTrait;

    /**
     * Sixel background-declaration for register 0: coordinate system 2
     * (RGB) with the `P` (transparent) qualifier instead of colour
     * components — DEC spec, echoed by every sixel decoder that supports
     * transparent backgrounds.
     */
    private const TRANSPARENT_BACKGROUND = '#0;2;P';

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
        // Whole-buffer sink over the same streaming encoder, so the one-shot
        // path and {@see encodeBandStream()} can never drift byte-for-byte.
        $out = '';
        $this->encodeInto($image, $width, $height, static function (string $chunk) use (&$out): void {
            $out .= $chunk;
        });

        return $out;
    }

    /**
     * Stream the Sixel encoding one 6-pixel-tall band at a time.
     *
     * Emits the DCS header + background register + palette ONCE on the first
     * `$write`, then one `$write` per band body (the graphics-newline `-` that
     * joins bands prefixes every band after the first), then the ST terminator
     * on the final `$write`. The concatenation of every `$write` argument is
     * byte-for-byte identical to {@see render()}'s return value — the two share
     * {@see encodeInto()} and the same band emitter.
     *
     * Why this streams and the source decode does not: the median-cut palette
     * must be sampled from the FULL image before any band is emitted (a
     * per-band palette breaks colour consistency across bands), so the GD
     * decode + resize stays whole-image. What previously cost O(pixelW×pixelH) —
     * the fully-materialised index grid, the error-diffusion accumulator, and
     * the complete output string — is now O(pixelW): {@see indexRows()} yields
     * grid rows from a rolling one/two-row lookahead window and each band is
     * handed to `$write` and released. A consumer can start pushing bytes to the
     * TTY while later bands are still quantising.
     *
     * The API is the one recorded in CALIBER_LEARNINGS §Deferred (#17) so a
     * later caller migrates mechanically; the optional `$height` (default null →
     * aspect-derived, exactly like {@see render()}) is the one addition, so a
     * fixed-height video frame need not fall back to `render()`.
     *
     * @param callable(string):void $write  Receives each encoded fragment in order.
     * @param int|null              $height Target height in cells (null = from aspect).
     */
    public function encodeBandStream(ImageSource $image, int $width, callable $write, ?int $height = null): void
    {
        $this->encodeInto($image, $width, $height, $write);
    }

    /**
     * Shared encode core: prepare, resize, quantise, then emit header + bands +
     * terminator through `$write` in render order. Both {@see render()} and
     * {@see encodeBandStream()} funnel here so the byte stream is defined once.
     *
     * @param callable(string):void $write
     */
    private function encodeInto(ImageSource $image, int $width, ?int $height, callable $write): void
    {
        $cellH = $this->prepareRender($image, $width, $height);

        // The cell box maps to a PIXEL canvas (cells × terminal cell size) so the
        // image fills its area; one device-pixel-per-cell would be microscopic.
        $pixelW = max(1, $width * $this->cellWidth);
        $pixelH = max(1, $cellH * $this->cellHeight);

        // Load and resize the image to the pixel canvas. GD raises a PHP warning on
        // unrecognised bytes; the falsy result is the real signal and is turned into
        // a typed exception below, so the warning is suppressed rather than sprayed
        // across a terminal (this library's product is terminal bytes).
        $src = @imagecreatefromstring($image->bytes);
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
        // Carry the source alpha through the resample: with blending off the
        // copy writes pixels verbatim, and only saveAlpha keeps the alpha byte
        // visible to imagecolorat() downstream.
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled(
            $resized, $src,
            0, 0, 0, 0,
            $pixelW, $pixelH,
            imagesx($src), imagesy($src)
        );
        imagedestroy($src);

        $headWritten = false;
        $terminated = false;

        try {
            // Fully-transparent pixels become the background register: when the
            // canvas has any, palette indices shift up by one and register 0 is
            // declared transparent (`#0;2;P`), so uncovered cells show the
            // terminal's own background instead of a painted colour.
            $offset = $this->hasTransparentPixels($resized) ? 1 : 0;

            // The palette only needs a representative sample, not every pixel —
            // median-cut over a capped subset is far cheaper and visually
            // indistinguishable at thumbnail sizes. When a background register
            // is reserved, the colour budget shrinks by one so the highest
            // shifted register stays inside DEC's 0..255 range (#256 aliases
            // onto the transparent background on tolerant decoders).
            $palette = $this->medianCut($this->samplePixels($resized, 4096, $offset), $this->maxColors - $offset);

            // Header, optional transparent background, and the palette are a
            // one-time prefix: a streaming consumer must see them before any band
            // body so the decoder can allocate registers and set the raster.
            $head = Ansi::sixelDcsHeader($pixelW, $pixelH);
            if ($offset === 1) {
                $head .= self::TRANSPARENT_BACKGROUND;
            }
            $head .= $this->emitPalette($palette, $offset);
            $write($head);
            $headWritten = true;

            // Pull index rows lazily and emit a 6-row band per write. The
            // graphics-newline `-` that separates bands PREFIXES every band
            // after the first, so the last band (partial or full) carries no
            // trailing `-` — byte-for-byte matching the original
            // `if ($bandBottom < $pixelH)` join.
            $rowsInBand = [];
            $firstBand = true;
            foreach ($this->indexRows($resized, $palette, $this->dither, $offset) as $gridRow) {
                $rowsInBand[] = $gridRow;
                if (count($rowsInBand) === 6) {
                    $write($this->bandBytes($rowsInBand, $pixelW, $palette, $offset, $firstBand));
                    $rowsInBand = [];
                    $firstBand = false;
                }
            }
            if ($rowsInBand !== []) {
                $write($this->bandBytes($rowsInBand, $pixelW, $palette, $offset, $firstBand));
            }

            $write(Ansi::sixelTerminator());
            $terminated = true;
        } finally {
            // Never strand the terminal inside a DCS: if the header reached the
            // wire but a later band or a consumer `$write` blew up (a closed pipe
            // mid-playback), emit the string terminator anyway so subsequent output
            // is not swallowed as sixel parameters. On the happy path `$terminated`
            // is already true, so this is a no-op and the byte stream is unchanged.
            if ($headWritten && !$terminated) {
                try {
                    $write(Ansi::sixelTerminator());
                } catch (\Throwable) {
                    // The consumer is gone; nothing left to terminate for.
                }
            }
            imagedestroy($resized);
        }
    }

    /**
     * Encode one band's bytes, prefixing the graphics-newline separator unless
     * it is the first band. The band grid is passed 0-based (its own local row
     * indices) so {@see emitBand()} is agnostic to where the band sits in the
     * full canvas.
     *
     * @param list<list<int>>          $bandRows
     * @param list<array{int,int,int}> $palette
     */
    private function bandBytes(array $bandRows, int $pixelW, array $palette, int $offset, bool $firstBand): string
    {
        $body = $this->emitBand($bandRows, 0, count($bandRows), $pixelW, $palette, $offset);

        return ($firstBand ? '' : '-') . $body;
    }

    public function name(): string
    {
        return 'sixel';
    }

    /**
     * Sixel supports transparent backgrounds via the `#0;2;P` background
     * register: fully-transparent pixels are left unpainted and show the
     * terminal's own background. See {@see render()}.
     */
    public function supportsAlpha(): bool
    {
        return true;
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
     * A copy of this renderer using $dither, keeping its colour budget and
     * cell-pixel geometry — so re-tinting a configured Sixel (custom
     * maxColors or real terminal cell sizes) never silently resets them.
     */
    public function withDither(Dither $dither): self
    {
        return new self($dither, $this->maxColors, $this->cellWidth, $this->cellHeight);
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
     * Quick full-canvas scan for any fully-transparent pixel (GD alpha 127).
     *
     * GD resamples alpha by averaging, so only exact-127 pixels are treated
     * as background; partially-blended edge pixels keep their colour. The
     * scan short-circuits on the first hit — opaque images pay one pass of
     * cheap integer tests, and only transparent ones pay the palette shift.
     */
    private function hasTransparentPixels(\GdImage $img): bool
    {
        $w = imagesx($img);
        $h = imagesy($img);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ((imagecolorat($img, $x, $y) >> 24) === 127) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Collect up to $max representative pixels by striding over the image, for
     * building the palette. Sampling instead of reading every pixel keeps
     * median-cut cheap on the larger pixel canvas.
     *
     * @param int $offset 1 when a background register is reserved — fully
     *                    transparent pixels are no colour and must not dilute
     *                    the median-cut buckets.
     * @return list<array{int,int,int}>
     */
    private function samplePixels(\GdImage $img, int $max, int $offset = 0): array
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
                // The canvas is truecolor, so imagecolorat returns a packed
                // 0xAARRGGBB int — extract channels directly rather than paying for
                // an imagecolorsforindex associative-array allocation per pixel.
                $rgb = imagecolorat($img, $x, $y);
                if ($offset === 1 && ($rgb >> 24) === 127) {
                    continue;
                }
                $pixels[] = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
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

        $bucketCount = count($buckets);
        while ($bucketCount < $maxColors) {
            $largestIdx = $this->largestBucketIndex($buckets);
            if ($largestIdx === null) {
                break;
            }
            $split = $this->splitBucket($buckets[$largestIdx]);
            if ($split === null) {
                break;
            }
            array_splice($buckets, $largestIdx, 1, $split);
            $bucketCount = count($buckets);
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

    // ─── Index grid (lazy, rolling-window) ────────────────────────────────────

    /**
     * Yield the index grid one row at a time, keyed by absolute row index.
     *
     * The single source of truth for pixel→palette-index mapping, shared by the
     * one-shot ({@see render()}) and streaming ({@see encodeBandStream()})
     * paths so the two can never diverge. Every quantisation decision is
     * reproduced exactly as the previous full-canvas grid builders did:
     *
     *   • Euclidean nearest-palette lookup, memoised on a coarse 5-bit RGB cube
     *     (≤32768 entries) — identical key arithmetic to the old code.
     *   • A fully-transparent pixel (GD alpha 127) maps to index 0 and diffuses
     *     no error whenever a background register is reserved.
     *   • Error-diffusion dithering quantises each pixel, clamps the accumulated
     *     value to 0..255, and propagates the rounding error to unprocessed
     *     neighbours.
     *
     * The rolling window is what makes it O(pixelW) not O(pixelW×pixelH): error
     * diffusion is strictly causal (it only reaches rows ≥ the current one), so
     * a row's final index is fixed once every earlier row has diffused into it.
     * A row is read into the window only once no earlier row can still touch it,
     * and released the instant it has pushed its own error downward — at most
     * `reach + 1` accumulator rows are alive (1 for Floyd–Steinberg, 2 for
     * Stucki/Atkinson). Loading row `y + reach + 1` right after processing `y`
     * is provably early enough because the FIRST diffuser into that row is
     * `y + 1`, so no contribution is ever written into a not-yet-loaded slot or
     * clobbered by a late base read.
     *
     * @param list<array{int,int,int}> $palette
     * @param int $offset 1 when register 0 is the transparent background —
     *                    opaque pixels index from $offset and holes map to 0.
     * @return \Generator<int, list<int>>  row index → grid[row][col] palette index
     */
    private function indexRows(\GdImage $img, array $palette, Dither $dither, int $offset): \Generator
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $hasDiffusion = $dither !== Dither::None;

        // The lowest row a single pixel's error can still reach — the window
        // must keep every row awaiting an inbound contribution.
        $reach = match ($dither) {
            Dither::FloydSteinberg => 1,
            Dither::Stucki, Dither::Atkinson => 2,
            // Named (not `default`) so a future Dither case that forgets to declare
            // its error-diffusion reach raises UnhandledMatchError here rather than
            // silently getting a 1-row window and corrupting output.
            Dither::None => 0,
        };

        /** @var array<int, list<array{float,float,float}>> $window  absolute row → RGB accumulators */
        $window = [];
        /** @var array<int, list<bool>> $holes  absolute row → transparent-pixel mask */
        $holes = [];

        // Read one row's raw pixels into floating-point accumulators; when a
        // background register is reserved, remember which pixels are holes so
        // the diffusion pass (which carries colour, not transparency) can skip
        // them exactly as the old full-canvas builder did.
        $loadBase = function (int $y) use ($img, $w, $offset, &$window, &$holes): void {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                if ($offset === 1) {
                    $holes[$y][$x] = (($rgb >> 24) & 0xFF) === 127;
                }
                $row[] = [
                    (float) (($rgb >> 16) & 0xFF),
                    (float) (($rgb >> 8) & 0xFF),
                    (float) ($rgb & 0xFF),
                ];
            }
            $window[$y] = $row;
        };

        // Prime the window: rows [0, reach] before any row is quantised.
        for ($y = 0; $y <= $reach && $y < $h; $y++) {
            $loadBase($y);
        }

        $cache = []; // coarse 5-bit RGB cube → palette index, reused across pixels.

        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                if ($offset === 1 && $holes[$y][$x]) {
                    $row[] = 0;
                    continue;
                }
                // Quantise the accumulated (possibly error-diffused) value.
                [$r, $g, $b] = $window[$y][$x];
                $clampedR = max(0.0, min(255.0, $r));
                $clampedG = max(0.0, min(255.0, $g));
                $clampedB = max(0.0, min(255.0, $b));

                $ri = (int) round($clampedR);
                $gi = (int) round($clampedG);
                $bi = (int) round($clampedB);
                $key = (($ri >> 3) << 10) | (($gi >> 3) << 5) | ($bi >> 3);
                $palIdx = $cache[$key] ??= $this->nearestColor($ri, $gi, $bi, $palette);

                if ($hasDiffusion) {
                    [$pr, $pg, $pb] = $palette[$palIdx];
                    // Quantization error (original − quantized), diffused downward.
                    $this->diffuseError(
                        $window, $w, $h, $x, $y,
                        $clampedR - (float) $pr,
                        $clampedG - (float) $pg,
                        $clampedB - (float) $pb,
                        $dither,
                    );
                }

                $row[] = $offset + $palIdx;
            }
            yield $y => $row;

            // Row $y is fully consumed — its error has propagated downward.
            unset($window[$y], $holes[$y]);

            // Pull the new far-edge row into the window. The first row whose
            // error can reach $y + $reach + 1 is $y + 1, so loading it now —
            // before $y + 1 is processed — never overwrites a received
            // contribution.
            $next = $y + $reach + 1;
            if ($next < $h) {
                $loadBase($next);
            }
        }
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

    /**
     * @param list<array{int,int,int}> $palette
     * @param int $offset 1 when register 0 is reserved for the transparent
     *                    background — palette entries then live at 1..N.
     */
    private function emitPalette(array $palette, int $offset = 0): string
    {
        $out = '';
        foreach ($palette as $i => [$r, $g, $b]) {
            $out .= Ansi::sixelColorIntroducer($i + $offset, $r, $g, $b);
        }
        return $out;
    }

    /**
     * Encode one 6-pixel-tall band.
     *
     * @param list<list<int>>          $grid
     * @param list<array{int,int,int}> $palette
     * @param int $offset 1 when register 0 is the transparent background —
     *                    index 0 cells are holes and get no colour pass.
     */
    private function emitBand(
        array $grid,
        int $bandTop,
        int $bandBottom,
        int $width,
        array $palette,
        int $offset = 0,
    ): string {
        // Discover which palette indices appear in this band.
        $activeColors = [];
        for ($row = $bandTop; $row < $bandBottom; $row++) {
            foreach ($grid[$row] ?? [] as $pal) {
                if ($offset === 1 && $pal === 0) {
                    continue;
                }
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
            $out .= $this->emitRleForColor($grid, $palIndex, $bandTop, $bandBottom, $width);
        }

        return $out;
    }

    /**
     * Emit RLE-encoded sixel data for one colour across an entire band width.
     *
     * @param list<list<int>> $grid
     */
    private function emitRleForColor(
        array $grid,
        int $palIndex,
        int $bandTop,
        int $bandBottom,
        int $width,
    ): string {
        $out = '';
        $col = 0;
        $runCount = 0;
        $prevBits = -1;

        while ($col < $width) {
            // The data byte holds ONLY the 6-row bitmask for the active
            // colour (the colour itself was selected above).
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
