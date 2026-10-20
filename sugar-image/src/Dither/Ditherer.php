<?php

declare(strict_types=1);

namespace SugarCraft\Image\Dither;

use SugarCraft\ColorProfile\Color as PaletteColor;
use SugarCraft\ColorProfile\ColorDistance;
use SugarCraft\ColorProfile\PaletteIndex;
use SugarCraft\Core\Util\Color;
use SugarCraft\Image\Dither;
use SugarCraft\Image\Renderer\OrderedTexture;

/**
 * C-06: the shared dithering stage — error diffusion and bias texture lifted
 * out of {@see \SugarCraft\Image\Renderer\SixelRenderer::indexRows()} so the
 * block/character renderers can consume the same math chafa runs in its
 * common `prepare_pixels` pass (chafa-pixops.c), not sixel-only.
 *
 * The RGB walk ({@see indexRows()}) is a byte-identical relocation of the
 * renderer's old private pipeline — rolling window, hole census ordering,
 * flatten, texture bias, B-13 block-centre memo, and the S4.1 serpentine
 * row-parity state (C-08) all moved with it, per the S6.1 carry note
 * (roadmap K folds C-08 into this extraction). SixelRenderer keeps a thin
 * delegate so its reflection-pinned shape survives; equivalence is pinned by
 * tests/Dither/DithererTest.php replaying every pre-refactor arm digest.
 *
 * For scalar renderers (ascii ramps, per-cell luma blocks) the same kernel
 * serves the luma axis through {@see lumaIndexRows()}, which diffuses one
 * float channel by feeding the identical error to all three RGB taps — the
 * channels are independent and fed equal values, so channel 0 IS the
 * single-channel walk, and no second tap table exists to drift.
 *
 * Trust boundary (parse-don't-validate): every construction site is a
 * renderer whose public constructor already refused NaN/negative intensity,
 * out-of-range alpha thresholds and colour budgets. This class takes those
 * values as settled; it validates only facts no caller can pre-judge — image
 * geometry against the grain (C-09).
 */
final class Ditherer
{
    /**
     * C-05: chafa folds this factor into the NOISE texture magnitude —
     * chafa_dither_init()'s NOISE arm calls
     * `chafa_gen_noise_matrix (dither->intensity * 0.1f)`, shrinking the
     * table's ±128-scale offsets to ±12.8 so a full-intensity noise bias
     * stays well inside one palette-bin width. Named here so the constant
     * has a home (NTC-3); C-06 moved it with the magnitude maths.
     */
    private const NOISE_BIAS_SCALE = 0.1;

    /**
     * @param Dither $dither the kernel selection for indexRows(); the texture
     *                       is supplied pre-built because its magnitude
     *                       depends on the renderer's palette depth (the
     *                       BASE_INTENSITY_* ladder is canvas knowledge, not
     *                       dither knowledge — chafa-canvas.c:591-614)
     * @param float|null $intensity diffusion/bias strength, already clamped
     *                              to 0..1 by the caller; null = 1.0 (C-07)
     * @param int $alphaThreshold GD-alpha hole cut-off the caller validated
     * @param Color|null $flattenBackground B-10 flatten target, or null
     * @param bool $serpentine C-08 opt-in snake walk
     */
    public function __construct(
        private readonly Dither $dither = Dither::FloydSteinberg,
        private readonly ?OrderedTexture $texture = null,
        private readonly ?float $intensity = null,
        private readonly int $alphaThreshold = 127,
        private readonly ?Color $flattenBackground = null,
        private readonly bool $serpentine = false,
    ) {
    }

    /**
     * C-05: Noise bias magnitude — chafa's chafa_dither_init() NOISE arm,
     * `chafa_gen_noise_matrix (dither->intensity * 0.1f)`, with the base
     * intensity left at 1.0: chafa-canvas.c (:591-614) applies the depth
     * ladder to ORDERED only, so Noise never sees a maxColors-derived
     * factor.
     */
    public static function noiseMagnitude(?float $intensity): float
    {
        return self::NOISE_BIAS_SCALE * ($intensity ?? 1.0);
    }

    /**
     * B-10: src-over composite of one pixel's RGB toward the flatten
     * background, on GD's inverted alpha scale (0 = opaque, 127 = fully
     * transparent), so background weight is `alpha / 127`.
     *
     * @return array{int,int,int}
     */
    public static function flattenOnto(int $r, int $g, int $b, int $alpha, Color $bg): array
    {
        $t = $alpha / 127.0;

        return [
            (int) round($r + ($bg->r - $r) * $t),
            (int) round($g + ($bg->g - $g) * $t),
            (int) round($b + ($bg->b - $b) * $t),
        ];
    }

    /**
     * Yield the index grid one row at a time, keyed by absolute row index.
     *
     * The single source of truth for pixel→palette-index mapping, shared by the
     * one-shot ({@see \SugarCraft\Image\Renderer\SixelRenderer::render()}) and
     * streaming ({@see \SugarCraft\Image\Renderer\SixelRenderer::encodeBandStream()})
     * paths so the two can never diverge. Every quantisation decision is
     * reproduced exactly as the previous full-canvas grid builders did:
     *
     *   • Euclidean nearest-palette lookup, memoised on a coarse 5-bit RGB cube
     *     (≤32768 entries) — identical key arithmetic to the old code.
     *   • A pixel at or above the alpha threshold (GD alpha 127 at the
     *     default) maps to index 0 and diffuses no error whenever a
     *     background register is reserved (B-10).
     *   • Error-diffusion dithering quantises each pixel, clamps the accumulated
     *     value to 0..255, and propagates the rounding error to unprocessed
     *     neighbours. C-08 (opt-in, default OFF): with `serpentine` armed the
     *     diffusion kernels snake — odd rows walk right→left and the kernel
     *     mirrors horizontally, so taps always land on unvisited pixels.
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
     * @param int $yOrigin C-04: absolute pixel row of this call's row 0 — the
     *                    texture phase is taken on $y + $yOrigin, so encoding
     *                    a band sub-image yields exactly the grid rows the
     *                    full-canvas pass produces (D-02 band-split equality;
     *                    error diffusion is by design NOT sliceable this way,
     *                    which is why the reach window stays stateful above).
     *                    The serpentine flip reads LOCAL $y, not $y +
     *                    $yOrigin — latent phase trap: a per-band indexRows
     *                    call would restart the snake mid-image (safe today,
     *                    every call walks a whole canvas at $yOrigin = 0).
     * @return \Generator<int, list<int>>  row index → grid[row][col] palette index
     */
    public function indexRows(\GdImage $img, array $palette, int $offset, int $yOrigin = 0): \Generator
    {
        $w = imagesx($img);
        $h = imagesy($img);

        // C-04/C-05: Ordered and Noise are bias textures, not error
        // diffusions — the old `$dither !== Dither::None` test would wrongly
        // arm the kernel for them, so the arms are spelled out.
        $hasDiffusion = match ($this->dither) {
            Dither::FloydSteinberg, Dither::Stucki, Dither::Atkinson => true,
            Dither::None, Dither::Ordered, Dither::Noise             => false,
        };

        // The lowest row a single pixel's error can still reach — the window
        // must keep every row awaiting an inbound contribution.
        $reach = match ($this->dither) {
            Dither::FloydSteinberg => 1,
            Dither::Stucki, Dither::Atkinson => 2,
            // Named (not `default`) so a future Dither case that forgets to declare
            // its error-diffusion reach raises UnhandledMatchError here rather than
            // silently getting a 1-row window and corrupting output.
            Dither::None, Dither::Ordered, Dither::Noise => 0,
        };

        // C-06: the texture arrives pre-built — its magnitude is the calling
        // renderer's palette-depth knowledge (BASE_INTENSITY_* ladder).
        $texture = $this->texture;

        // C-07: chafa scales the diffusion error itself — chafa-pixops.c
        // (fs_dither_grain): `(accum - col) * dither->intensity` before the
        // kernel split. With intensity null the factor is exactly 1.0 and
        // float × 1.0 is bit-exact, so the byte-frozen default stream is
        // untouched; chafa's init also clamps diffusion strength to 1.0
        // (MIN(intensity, 1.0)) — the caller's ctor clamp mirrors it.
        $diffusionStrength = $this->intensity ?? 1.0;

        /** @var array<int, list<array{float,float,float}>> $window  absolute row → RGB accumulators */
        $window = [];
        /** @var array<int, list<bool>> $holes  absolute row → transparent-pixel mask */
        $holes = [];

        // Read one row's raw pixels into floating-point accumulators; when a
        // background register is reserved, remember which pixels are holes so
        // the diffusion pass (which carries colour, not transparency) can skip
        // them exactly as the old full-canvas builder did. C-04: the ordered
        // bias is added HERE — before quantisation, after nothing diffuses —
        // mirroring chafa's simple_dither pass running ahead of palette lookup
        // (chafa-pixops.c fs/ordered walk order).
        $loadBase = function (int $y) use ($img, $w, $offset, $texture, $yOrigin, &$window, &$holes): void {
            $row = [];
            $textureY = $y + $yOrigin;
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                $alpha = ($rgb >> 24) & 0xFF;
                // B-10: the hole census is decided FIRST and on the RAW
                // alpha — lowering the threshold widens the hole set, and a
                // hole is never flattened (unpainted pixels carry no colour
                // to the terminal). ORDERING LAW: everything below — the
                // flatten, the texture bias, the accumulator push — runs on
                // the post-decision pixel, so both the sample census and the
                // diffusion stream see transparent-excluded, optionally
                // bg-composited colour.
                $isHole = $offset === 1 && $alpha >= $this->alphaThreshold;
                if ($offset === 1) {
                    $holes[$y][$x] = $isHole;
                }
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                if ($this->flattenBackground !== null && !$isHole && $alpha > 0) {
                    // B-10: semi-transparent (0 < alpha < threshold) → src-over
                    // onto the background BEFORE quantisation/diffusion.
                    [$r, $g, $b] = self::flattenOnto($r, $g, $b, $alpha, $this->flattenBackground);
                }
                $rf = (float) $r;
                $gf = (float) $g;
                $bf = (float) $b;
                if ($texture !== null) {
                    $rf = self::applyTextureMod($rf, $texture->mod($x, $textureY, 0));
                    $gf = self::applyTextureMod($gf, $texture->mod($x, $textureY, 1));
                    $bf = self::applyTextureMod($bf, $texture->mod($x, $textureY, 2));
                }
                $row[] = [$rf, $gf, $bf];
            }
            $window[$y] = $row;
        };

        // Prime the window: rows [0, reach] before any row is quantised.
        for ($y = 0; $y <= $reach && $y < $h; $y++) {
            $loadBase($y);
        }

        $cache = []; // 5-bit RGB cube → nearest colour of the BLOCK CENTRE (B-13), reused across pixels.

        // F-14: nearest lookup now rides the B-04 PaletteIndex. The metric is
        // pinned to Euclidean because its recheck is the same integer-sum
        // comparison the old linear walk made — including lowest-index ties —
        // while the default Cie76 would reorder answers (differentially
        // pinned in SixelStreamTest). fromPalette() memoises per palette
        // byte-identity (md5 + CACHE_LIMIT), so per-frame sugar-video
        // re-encodes that rebuild an identical palette short-circuit the
        // histogram build: the palette-hash short-circuit, across encodes.
        $index = PaletteIndex::fromPalette(
            array_map(static fn(array $rgb): PaletteColor => new PaletteColor($rgb[0], $rgb[1], $rgb[2]), $palette),
            ColorDistance::Euclidean,
        );

        // C-08: serpentine only means anything for a walk that carries
        // error — Ordered's bias is position-taken and None quantises
        // cold, so both render byte-identically snaked or straight.
        $snake = $this->serpentine && $hasDiffusion;

        for ($y = 0; $y < $h; $y++) {
            // Per chafa chafa-pixops.c:327-459 (fs_dither): even rows walk
            // left→right, odd rows right→left, and the kernel mirrors with
            // the direction so every tap still lands on an unprocessed
            // pixel — that parity and mirroring is a faithful port. At the
            // row ends chafa does NOT park error in padding: it FOLDS the
            // boundary taps into the nearest visible cells
            // (chafa-pixops.c:377-405 — the leftmost grain re-adds
            // error_row[1]+1, the rightmost re-adds error_row[1]+x; the +2
            // columns of its padded rows are memset-0 layout slack, not
            // accumulators). SugarCraft drops out-of-bounds taps at the
            // bounds test here — a real, disclosed divergence:
            // conserved-vs-dropped error differs at the row ends.
            $reversed = $snake && (($y & 1) === 1);
            $row = array_fill(0, $w, 0);
            for ($i = 0; $i < $w; $i++) {
                $x = $reversed ? $w - 1 - $i : $i;
                if ($offset === 1 && $holes[$y][$x]) {
                    continue; // hole: index 0, no error — left at the fill value
                }
                // Quantise the accumulated (possibly error-diffused) value.
                [$r, $g, $b] = $window[$y][$x];
                $clampedR = max(0.0, min(255.0, $r));
                $clampedG = max(0.0, min(255.0, $g));
                $clampedB = max(0.0, min(255.0, $b));

                $ri = (int) round($clampedR);
                $gi = (int) round($clampedG);
                $bi = (int) round($clampedB);
                // B-13: key AND lookup input snap together to the block
                // representative — the memo stores the nearest colour of the
                // 8³-block CENTRE, not of whichever pixel happened to warm
                // the entry first. A lookup for any pixel in the block then
                // asks (and caches) the same question, so the chosen index
                // is arrival-order-independent (the pre-fix first-wins 5-bit
                // memo made dither walks position-order-dependent). The key
                // space stays 32³ = 32768 entries per render — bounded by
                // construction.
                $br = ($ri & ~7) | 4;
                $bg = ($gi & ~7) | 4;
                $bb = ($bi & ~7) | 4;
                $key = (($br >> 3) << 10) | (($bg >> 3) << 5) | ($bb >> 3);
                $palIdx = $cache[$key] ??= $index->nearest(new PaletteColor($br, $bg, $bb));

                if ($hasDiffusion) {
                    [$pr, $pg, $pb] = $palette[$palIdx];
                    // Quantization error (original − quantized), intensity-scaled
                    // (C-07, 1.0 by default), diffused onward.
                    // C-08: on a reversed pass every horizontal tap mirrors
                    // (x±1 → x∓1), so error keeps flowing to pixels the walk
                    // has not visited yet.
                    self::diffuseError(
                        $window, $w, $h, $x, $y,
                        ($clampedR - (float) $pr) * $diffusionStrength,
                        ($clampedG - (float) $pg) * $diffusionStrength,
                        ($clampedB - (float) $pb) * $diffusionStrength,
                        $this->dither,
                        $reversed ? -1 : 1,
                    );
                }

                $row[$x] = $offset + $palIdx;
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
     * C-06: per-luma error diffusion for scalar (one-channel) renderers —
     * the ascii ramp threshold and, from S6.2 on, per-cell block luma.
     * Mirrors chafa's structure of dithering the value the picker will
     * threshold (chafa dithers before the symbol/palette choice, never
     * after), which is what C-06's "FS before the LumaRamp threshold"
     * requires.
     *
     * Diffusion rides the exact RGB kernel via {@see diffuseError()} with the
     * identical error on all three channels: the taps are linear and
     * channel-independent, so equal inputs keep the channels equal and
     * channel 0's accumulator IS the scalar walk — one tap table, no copy.
     * The bias texture (if armed) is applied before quantisation, matching
     * the loadBase position in the colour path.
     *
     * @param list<list<float>> $lumaRows row-major luma grid, raw 0..255 scale
     * @param callable(float): array{int, float} $quantize maps a clamped luma
     *              to [index, representative-value-on-the-same-0..255-scale]
     *              — the representative is what the error is measured against
     * @param Dither $dither kernel selection (None/Ordered/Noise carry no
     *                       feedback; the three diffusion arms do)
     * @param float $intensity diffusion strength (already validated/clamped
     *                       by the caller — trust boundary above)
     * @param OrderedTexture|null $texture pre-built bias for Ordered/Noise
     * @param bool $serpentine C-08 snake walk; parity reads LOCAL $y, same
     *                         documented phase behaviour as indexRows()
     * @return \Generator<int, list<int>>  row index → grid[row][col] index
     */
    public static function lumaIndexRows(
        array $lumaRows,
        callable $quantize,
        Dither $dither,
        float $intensity = 1.0,
        ?OrderedTexture $texture = null,
        bool $serpentine = false,
    ): \Generator {
        $h = count($lumaRows);
        if ($h === 0) {
            return;
        }
        $w = count($lumaRows[0]);

        $hasDiffusion = match ($dither) {
            Dither::FloydSteinberg, Dither::Stucki, Dither::Atkinson => true,
            Dither::None, Dither::Ordered, Dither::Noise             => false,
        };

        /** @var array<int, list<array{float,float,float}>> $accum */
        $accum = [];
        foreach ($lumaRows as $y => $row) {
            $line = [];
            foreach ($row as $x => $v) {
                $line[$x] = [$v, $v, $v];
            }
            $accum[$y] = $line;
        }

        // C-08: same rule as the colour path — the snake only means
        // anything when the walk carries error.
        $snake = $serpentine && $hasDiffusion;

        for ($y = 0; $y < $h; $y++) {
            $reversed = $snake && (($y & 1) === 1);
            $row = array_fill(0, $w, 0);
            for ($i = 0; $i < $w; $i++) {
                $x = $reversed ? $w - 1 - $i : $i;
                $value = $accum[$y][$x][0];
                if ($texture !== null) {
                    $value = self::applyTextureMod($value, $texture->mod($x, $y, 0));
                }
                $clamped = max(0.0, min(255.0, $value));
                [$index, $representative] = $quantize($clamped);
                if ($hasDiffusion) {
                    $error = ($clamped - $representative) * $intensity;
                    self::diffuseError(
                        $accum, $w, $h, $x, $y,
                        $error, $error, $error,
                        $dither,
                        $reversed ? -1 : 1,
                    );
                }
                $row[$x] = $index;
            }
            yield $y => $row;
        }
    }

    /**
     * C-04: chafa_gen_mods + dither_pixel_u32 — split the signed texture
     * offset into saturating pos/neg modifiers and add them to one channel.
     * Alpha never passes through here (holes short-circuit above; GD has no
     * per-channel alpha in the accumulator), matching chafa's `for (i=0; i<3)`
     * loop bound.
     */
    private static function applyTextureMod(float $value, int $mod): float
    {
        $pos = max(0, min(255, $mod));
        $neg = max(0, min(255, -$mod));

        $biased = $value + $pos;
        $biased = min($biased, 255.0);
        $biased -= $neg;

        return max($biased, 0.0);
    }

    /**
     * Diffuse quantization error to future (unprocessed) neighbors.
     *
     * Coefficient layouts (current pixel marked ·):
     *
     * Floyd–Steinberg (propagates the whole error):
     *    ·  7/16
     *  3/16 5/16 1/16
     *
     * Stucki (propagates the whole error to 12 neighbors, slightly sharper):
     *            ·     8/42  4/42
     *  2/42  4/42  8/42  4/42  2/42
     *  1/42  2/42  4/42  2/42  1/42
     *
     * Atkinson (Apple; propagates ¾, more contrast/lighter result):
     *    ·  1/8  1/8
     *  1/8  1/8  1/8
     *       1/8
     *
     * @param list<list<array{float,float,float}> $accum
     * @param int $dxSign C-08: +1 on a forward row, -1 on a serpentine
     *                    (reversed) row — the horizontal component of every
     *                    tap mirrors with the walk direction, the analogue
     *                    of chafa swapping its neighbour arguments when
     *                    fs_dither walks right→left (chafa-pixops.c:327-459).
     *                    Default +1 keeps the historical forward kernel and
     *                    every existing caller byte-unchanged.
     */
    public static function diffuseError(
        array &$accum,
        int $w, int $h,
        int $x, int $y,
        float $eR, float $eG, float $eB,
        Dither $dither,
        int $dxSign = 1,
    ): void {
        // Taps are (dx, dy, factor) RELATIVE to the diffusing pixel so the
        // reversed pass mirrors the whole kernel by negating dx.
        $neighbors = match ($dither) {
            // Floyd–Steinberg: 4 neighbors
            Dither::FloydSteinberg => [
                [1,  0, 7.0 / 16.0],
                [-1, 1, 3.0 / 16.0],
                [0,  1, 5.0 / 16.0],
                [1,  1, 1.0 / 16.0],
            ],
            // Stucki: 12 neighbors
            Dither::Stucki => [
                [1,  0, 8.0 / 42.0],
                [2,  0, 4.0 / 42.0],
                [-2, 1, 2.0 / 42.0],
                [-1, 1, 4.0 / 42.0],
                [0,  1, 8.0 / 42.0],
                [1,  1, 4.0 / 42.0],
                [2,  1, 2.0 / 42.0],
                [-2, 2, 1.0 / 42.0],
                [-1, 2, 2.0 / 42.0],
                [0,  2, 4.0 / 42.0],
                [1,  2, 2.0 / 42.0],
                [2,  2, 1.0 / 42.0],
            ],
            // Atkinson: 6 neighbors, only ¾ of error propagates
            Dither::Atkinson => [
                [1,  0, 1.0 / 8.0],
                [2,  0, 1.0 / 8.0],
                [-1, 1, 1.0 / 8.0],
                [0,  1, 1.0 / 8.0],
                [1,  1, 1.0 / 8.0],
                [0,  2, 1.0 / 8.0],
            ],
            default => [],
        };

        foreach ($neighbors as [$dx, $dy, $factor]) {
            $nx = $x + $dx * $dxSign;
            $ny = $y + $dy;
            if ($nx >= 0 && $nx < $w && $ny >= 0 && $ny < $h) {
                $accum[$ny][$nx][0] += $eR * $factor;
                $accum[$ny][$nx][1] += $eG * $factor;
                $accum[$ny][$nx][2] += $eB * $factor;
            }
        }
    }
}
