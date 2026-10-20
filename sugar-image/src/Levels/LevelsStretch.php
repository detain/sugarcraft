<?php

declare(strict_types=1);

namespace SugarCraft\Image\Levels;

/**
 * B-15: chafa's `prepare_pixels` auto-levels pass — histogram-driven
 * per-channel stretch plus a saturation lift, ported to GD in place.
 *
 * Mirrors chafa/internal/chafa-pixops.c (1.19): `fill_histogram`-style
 * pass 1 (optionally boosting as it walks), {@see self::calcBounds()}
 * from `histogram_calc_bounds`, and the channel remap of
 * `normalize_rgb`/`normalize_ch`. The math is ported literally — the
 * 2048-bin *fast intensity* space, the fixed-point crop, the
 * truncate-toward-zero divisions — so a low-contrast image "punches"
 * the same way `chafa --preprocessing=1` punches it, bin for bin.
 *
 * Two deliberate departures from upstream, both recorded here:
 *
 * - **Saturation weights.** chafa's `boost_saturation_rgb` computes
 *   `P = sqrt(r²·.299 + g²·.587 + b²·.144)` — the `.144` is a typo in
 *   upstream (`.114` was the intended Rec.601 blue weight, and the
 *   survey NTC-1 rules that the port uses the Rec.709 luminance
 *   weights `.2126/.7152/.0722` instead, matching this repository's
 *   own convention in sugar-colorprofile `Palette.php`). The lift
 *   shape — `ch = P + (ch − P) · 2`, clamped — is otherwise verbatim.
 * - **Gating.** chafa gates the boost to the FIXED_16/FIXED_8 rungs
 *   and the normalize pass to FIXED_16/FIXED_8/FGBG, because there the
 *   preprocessing rides automatically in front of a *fixed* pen set.
 *   Here auto-levels is an explicit user opt-in (`withAutoLevels`), so
 *   both stages run on every rung once armed — including 256-colour
 *   adaptive renders, where chafa stays silent.
 *
 * Alpha: chafa's histogram gate is `ch[3] > 127` on straight-alpha
 * bytes; GD inverts the scale (0 opaque, 127 transparent). Pixels the
 * renderer treats as holes — alpha `>= $alphaThreshold` — are excluded
 * from the histogram and left byte-untouched by both passes. That is
 * the "loadBase alpha-exclusion" of the plan: colours that are never
 * painted must neither move the bounds nor be moved by them. (chafa's
 * background-carry of fully transparent pixels has no analogue here:
 * holes carry no colour to the terminal at all.)
 *
 * @see \SugarCraft\Image\Renderer\SixelRenderer::withAutoLevels()
 */
final class LevelsStretch
{
    /** Fixed-point multiplier, chafa `FIXED_MULT`. */
    private const FIXED_MULT = 4096;

    /** Histogram bins — 8× the channel range, chafa `INTENSITY_MAX`. */
    private const INTENSITY_MAX = 2048;

    /** Crop percentages, chafa `INDEXED_*_CROP_PCT` (16C/8C/else). */
    private const CROP_PCT_16 = 5;

    private const CROP_PCT_8 = 10;

    private const CROP_PCT_DEFAULT = 20;

    /** Rec.709 luminance weights — see class docblock for the chafa typo. */
    private const REC709_R = 0.2126;

    private const REC709_G = 0.7152;

    private const REC709_B = 0.0722;

    /** The saturation lift multiplier, chafa's inline `* 2`. */
    private const BOOST_SCALE = 2.0;

    private function __construct()
    {
    }

    /**
     * Raw truecolour word for imagesetpixel — with blending off GD takes
     * the packed (alpha<<24)|rgb verbatim (its signature has no separate
     * alpha argument), which is how holes keep both bytes and identity.
     */
    private static function rgba(int $alpha, int $r, int $g, int $b): int
    {
        return ($alpha << 24) | ($r << 16) | ($g << 8) | $b;
    }

    /**
     * Apply the full pass-1 → bounds → pass-2 sequence to $img in place.
     *
     * @param \GdImage $img            truecolour canvas (alpha visible)
     * @param int      $maxColors      renderer rung, selects the crop percentage
     * @param int      $alphaThreshold hole boundary in GD scale (0..127 opaque side)
     */
    public static function stretch(\GdImage $img, int $maxColors, int $alphaThreshold = 127): void
    {
        $histogram = [];
        $samples = 0;
        self::collect($img, $histogram, $samples, $alphaThreshold);
        if ($samples === 0) {
            return;
        }

        [$min, $max] = self::calcBounds($histogram, $samples, self::cropPct($maxColors));
        self::normalize($img, $min, $max, $alphaThreshold);
    }

    /**
     * Saturation lift for one pixel — public because it is a usable
     * colour primitive on its own (and the testable core of the B-15
     * math). Faithful to `boost_saturation_rgb` with the corrected
     * weights: `P = sqrt(Σ w·c²)`, then `ch = P + (ch − P)·2` truncated
     * to int and clamped, exactly as the C assignment did.
     *
     * @param array{int,int,int} $rgb
     * @return array{int,int,int}
     */
    public static function boost(array $rgb): array
    {
        [$r, $g, $b] = $rgb;
        $p = \sqrt($r * $r * self::REC709_R + $g * $g * self::REC709_G + $b * $b * self::REC709_B);
        $out = [];
        foreach ([$r, $g, $b] as $ch) {
            $lifted = $p + (($ch - $p) * self::BOOST_SCALE);
            // C stored into a gint before CLAMP: truncate toward zero.
            $out[] = max(0, min(255, (int) $lifted));
        }

        return [$out[0], $out[1], $out[2]];
    }

    /**
     * `rgb_to_intensity_fast` — weighted sum to 8× so the bin index
     * divides back by shifting. Sums to 2040 max, inside the bin count.
     */
    public static function intensity(int $r, int $g, int $b): int
    {
        return $r * 3 + $g * 4 + $b;
    }

    /**
     * Crop percentage for the renderer rung — chafa's palette switch:
     * FIXED_16 → 5 %, FIXED_8 → 10 %, everything else → 20 %.
     */
    public static function cropPct(int $maxColors): int
    {
        return match ($maxColors) {
            16 => self::CROP_PCT_16,
            8 => self::CROP_PCT_8,
            default => self::CROP_PCT_DEFAULT,
        };
    }

    /**
     * Pass 1 walk: saturate every opaque pixel through the lift (in
     * place, so pass 2 and the palette census both see the boosted
     * canvas — chafa's ordering), accumulating the fast-intensity
     * histogram over opaque pixels only. Holes are skipped entirely.
     *
     * @param array<int,int> $histogram out-param, bin => count
     */
    private static function collect(\GdImage $img, array &$histogram, int &$samples, int $alphaThreshold): void
    {
        $w = \imagesx($img);
        $h = \imagesy($img);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $at = \imagecolorat($img, $x, $y);
                $alpha = $at >> 24;
                if ($alpha >= $alphaThreshold) {
                    continue; // hole: no colour reaches the terminal
                }

                $rgb = self::boost([($at >> 16) & 0xFF, ($at >> 8) & 0xFF, $at & 0xFF]);
                \imageSetPixel($img, $x, $y, self::rgba($alpha, $rgb[0], $rgb[1], $rgb[2]));
                $bin = self::intensity($rgb[0], $rgb[1], $rgb[2]);
                $histogram[$bin] = ($histogram[$bin] ?? 0) + 1;
                $samples++;
            }
        }
    }

    /**
     * `histogram_calc_bounds` verbatim: discard `crop_pct` of the
     * sampled mass off each tail with the C integer arithmetic
     * (`(n * ((crop*1024)/100)) / 1024` — a crop of 0 % for small
     * canvases falls out of the same expression). The scans break on
     * `t <= 0`, so with an empty-ish crop the lower bound lands on bin
     * 0 and the upper on the last bin — a no-op stretch, same as C.
     *
     * @param array<int,int> $histogram
     * @return array{int,int}
     */
    private static function calcBounds(array $histogram, int $samples, int $cropPct): array
    {
        $pixelsCrop = intdiv($samples * intdiv($cropPct * 1024, 100), 1024);

        for ($i = 0, $t = $pixelsCrop; $i < self::INTENSITY_MAX; $i++) {
            $t -= $histogram[$i] ?? 0;
            if ($t <= 0) {
                break;
            }
        }
        $min = $i;

        for ($i = self::INTENSITY_MAX - 1, $t = $pixelsCrop; $i >= 0; $i--) {
            $t -= $histogram[$i] ?? 0;
            if ($t <= 0) {
                break;
            }
        }
        $max = $i;

        return [$min, $max];
    }

    /**
     * Pass 2: per-channel stretch from [min/8, 255] onto [0, 255] with
     * chafa's integer arithmetic. `min == max` bails first (the guard
     * that keeps a flat canvas flat). Holes are never rewritten, so
     * their untouched bytes also keep `hasTransparentPixels` — and the
     * background-register decision built on it — stable across the
     * levels pass.
     */
    private static function normalize(\GdImage $img, int $min, int $max, int $alphaThreshold): void
    {
        if ($min === $max) {
            return;
        }

        $factor = intdiv((self::INTENSITY_MAX - 1) * self::FIXED_MULT, $max - $min);
        $base = intdiv($min, 8);

        $w = \imagesx($img);
        $h = \imagesy($img);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $at = \imagecolorat($img, $x, $y);
                $alpha = $at >> 24;
                if ($alpha >= $alphaThreshold) {
                    continue;
                }

                \imageSetPixel($img, $x, $y, self::rgba(
                    $alpha,
                    self::normalizeCh(($at >> 16) & 0xFF, $base, $factor),
                    self::normalizeCh(($at >> 8) & 0xFF, $base, $factor),
                    self::normalizeCh($at & 0xFF, $base, $factor),
                ));
            }
        }
    }

    /**
     * `normalize_ch` — subtract the floor, scale by the fixed-point
     * factor, truncate-toward-zero through FIXED_MULT, clamp. PHP's
     * intdiv truncates like the C `/`, so negative intermediates land
     * on the same byte.
     */
    private static function normalizeCh(int $v, int $min, int $factor): int
    {
        $vt = $v - $min;
        $vt *= $factor;
        $vt = intdiv($vt, self::FIXED_MULT);

        return max(0, min(255, $vt));
    }
}
