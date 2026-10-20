<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * B-01: Pairwise Nearest Neighbour (PNN) palette quantizer.
 *
 * Mirrors chafa `chafa/internal/chafa-palette.c:616` (`pnn_palette`) and its
 * helpers — the algorithm chafa itself credits to nQuant by Mark Tyler,
 * Dmitry Groshev and Miller Cy Chan (chafa-palette.c:390-395, with the
 * literature pointers DOI:10.1117/1.1412423 / DOI:10.1117/1.1604396 kept
 * there). Samples are histogrammed into one bin per 4-bit-per-channel colour
 * cell (the -w ladder in {@see self::bitsForQuality()} selects 3, 4 or 5
 * bits), bins are averaged to float means, and the two bins with the lowest
 * merge error are merged repeatedly until the requested colour count is
 * reached. The merge error is the BT.601 luminance/chrominance decomposition
 * of the population-weighted colour distance — NOT a DIN99d inverse: per
 * PC-12 the error is computed with the plain `pnn_coeffs` rows verbatim.
 *
 * Deliberate deviations from the C (each deterministic, none visual):
 *  • selection is a plain rescan for the lowest-error live bin instead of
 *    chafa's binary heap — the plan blesses this at ≤4k bins (PC-12), and
 *    the sample cap upstream (SixelRenderer::SAMPLE_CAP = 4096) bounds the
 *    live bin count at 4096 even on the 5-bit rung. Ties resolve to the
 *    lowest bin index; chafa's insertion-built heap agrees in practice.
 *  • arithmetic is double, not C float. Distances and merge weights are
 *    well below 2^53, so comparisons are order-exact.
 *  • no density retry pass: chafa re-samples at step 1 when fewer than 256
 *    opaque pixels landed in bins (chafa-palette.c:637-644); the caller
 *    hands us an already-strided list, so the guard lives there instead
 *    (fewer samples than colours ⇒ median-cut, see SixelRenderer).
 *  • no per-bin saturation at PNN_BIN_MAX_SAMPLES (2^24) — unreachable at a
 *    4096-sample cap.
 *  • cbrt is corrected to a true floor cube root: C's `(gint)cbrtf` sees a
 *    correctly-rounded single-precision value, while PHP `**(1/3)` drifts
 *    below perfect cubes (64**(1/3) = 3.9999…). The correction loop makes
 *    rung -1 agree with chafa.
 *
 * Determinism: no randomness anywhere; the histogram sums are integers
 * (order-free) and every scan walks numeric bin order, so the palette is a
 * pure function of the sample SET, not of sample arrival order.
 */
final class Quantizer
{
    /** Lowest rung of chafa's -w quality ladder (chafa-palette.c:63-76). */
    public const MIN_QUALITY = 1;

    /** Highest rung of chafa's -w quality ladder. */
    public const MAX_QUALITY = 9;

    /**
     * Quality rung → bits of each channel kept for the histogram bin index,
     * verbatim from the `quality_params` rows for -w1..-w9 (the n_samples
     * ceilings of those rows are NOT ported — the sampler cap is the
     * renderer's, and the plan pins it at 4096 across the ladder).
     */
    private const QUALITY_BITS = [
        1 => 3, 2 => 3,
        3 => 4, 4 => 4, 5 => 4,
        6 => 5, 7 => 5, 8 => 5, 9 => 5,
    ];

    /** PnnBin.mtm sentinel for an absorbed (dead) bin — chafa's G_MAXUINT16. */
    private const DEAD = PHP_INT_MAX;

    /** Merge-weight split between plain RGB error and the transform errors (chafa-palette.c:401 RATIO). */
    private const RATIO = 0.5;

    /**
     * BT.601 rows used by find_nearest's error (chafa-palette.c:403-408
     * `pnn_coeffs`): Y, Cb, Cr. The Y row doubles as the default RGB weight
     * vector, which is why the weights below read like it.
     */
    private const COEFFS = [
        [0.299, 0.587, 0.114],
        [-0.14713, -0.28886, 0.436],
        [0.615, -0.51499, -0.10001],
    ];

    /** chafa-palette.c:398-400 RED/GREEN/BLUE_WEIGHT_32f. */
    private const RGB_WEIGHTS = [0.299, 0.587, 0.114];

    private function __construct()
    {
        // Static-only utility; the class exists to own the algorithm and its constants.
    }

    /**
     * The histogram resolution chafa's -w rung selects.
     *
     * @throws \InvalidArgumentException outside the 1-9 ladder
     */
    public static function bitsForQuality(int $quality): int
    {
        $bits = self::QUALITY_BITS[$quality] ?? null;
        if ($bits === null) {
            throw new \InvalidArgumentException(
                Lang::t('quantizer.quality_out_of_range', ['quality' => $quality])
            );
        }

        return $bits;
    }

    /**
     * Build a palette from raw RGB samples.
     *
     * @param list<array{int,int,int}> $samples opaque samples (holes are the
     *                                          caller's to drop, matching
     *                                          chafa's alpha_threshold pass)
     * @param int $nCols    requested colour count, clamped 1..256 like
     *                     median-cut; the result may be SHORTER (one bin per
     *                     occupied colour cell is the ceiling)
     * @param int $bitsPerCh histogram bits per channel, 3..5 (chafa asserts
     *                      exactly this range)
     *
     * @return list<array{int,int,int}>
     *
     * @throws \InvalidArgumentException on a bits-per-channel outside 3..5
     */
    public static function build(array $samples, int $nCols = 255, int $bitsPerCh = 4): array
    {
        if ($bitsPerCh < 3 || $bitsPerCh > 5) {
            throw new \InvalidArgumentException(
                Lang::t('quantizer.bits_per_channel_out_of_range', ['bitsPerCh' => $bitsPerCh])
            );
        }
        $nCols = max(1, min(256, $nCols));

        // Median-cut parity: no colour to spend — a single black register.
        if ($samples === []) {
            return [[0, 0, 0]];
        }

        $bins = self::histogram($samples, $bitsPerCh);
        $n = count($bins);

        // ─── Weights and count quantization (chafa-palette.c:668-690) ────
        $quanRt = 1;
        if ($nCols < 16) {
            $quanRt = -1;
        }
        $weight = min(0.9, $nCols / $n);
        $rgbw = self::RGB_WEIGHTS;
        // chafa-palette.c:673 guards the weight flip with `rgbw.g < 1.0 &&
        // rgbw.g >= coeffs[0].g`; both legs are structurally true at the
        // initial (0.299, 0.587, 0.114) vector, so the block is kept in its
        // chafa shape — a future perceptual weights knob would move them.
        if ($weight < 0.03 && $rgbw[1] < 1.0 && $rgbw[1] >= self::COEFFS[0][1]) {
            $rgbw = [1.0, 1.0, 1.0];
            if ($nCols >= 64) {
                $quanRt = 0;
            }
        }
        if ($quanRt > 0 && $nCols < 64) {
            $quanRt = 2;
        }

        // Doubly-linked list over the compacted bins; index 0 is BOTH a real
        // bin and the list terminator chafa's walks stop at, so bin 0 can
        // never be absorbed by a successor walk and the last bin has no
        // candidates (error stays INF and it is never selected — see the
        // rescan note in the class docblock).
        for ($j = 0; $j < $n; $j++) {
            $bins[$j]['count'] = self::quanfn($bins[$j]['count'], $quanRt);
            $bins[$j]['next'] = $j + 1 === $n ? 0 : $j + 1;
            $bins[$j]['prev'] = $j === 0 ? 0 : $j - 1;
            $bins[$j]['err'] = \INF;
            $bins[$j]['nearest'] = 0;
            $bins[$j]['tm'] = 0;
            $bins[$j]['mtm'] = 0;
        }

        // ─── Initial nearest-neighbour pass (quadratic, read-only inputs) ───
        for ($j = 0; $j < $n; $j++) {
            self::findNearest($j, $bins, $rgbw);
        }

        // ─── Merge until the colour budget is met ───
        $extbins = $n - $nCols;
        $i = 0;
        while ($i < $extbins) {
            $b1 = -1;
            $best = \INF;
            foreach ($bins as $j => $tb) {
                if ($tb['mtm'] === self::DEAD) {
                    continue; // chafa pops the dead root; a rescan never picks it
                }
                if ($tb['err'] < $best) {
                    $best = $tb['err'];
                    $b1 = $j;
                }
            }
            if ($b1 < 0) {
                break; // unreachable while a merge is owed; fail soft like chafa's partial export
            }

            $tb = $bins[$b1];
            if ($tb['tm'] >= $tb['mtm'] && $bins[$tb['nearest']]['mtm'] <= $tb['tm']) {
                $nb = $bins[$tb['nearest']];
                $n1 = $tb['count'];
                $n2 = $nb['count'];
                $d = 1.0 / ($n1 + $n2);
                // chafa_vec3f32_round is rintf (half-to-even) per component.
                $bins[$b1]['r'] = round($n1 * $tb['r'] + $n2 * $nb['r'], 0, \PHP_ROUND_HALF_EVEN) * $d;
                $bins[$b1]['g'] = round($n1 * $tb['g'] + $n2 * $nb['g'], 0, \PHP_ROUND_HALF_EVEN) * $d;
                $bins[$b1]['b'] = round($n1 * $tb['b'] + $n2 * $nb['b'], 0, \PHP_ROUND_HALF_EVEN) * $d;
                $bins[$b1]['count'] = $n1 + $n2;
                $bins[$b1]['mtm'] = ++$i;

                $bins[$nb['prev']]['next'] = $nb['next'];
                $bins[$nb['next']]['prev'] = $nb['prev'];
                $bins[$tb['nearest']]['mtm'] = self::DEAD;
                continue;
            }

            // Stale entry: its nearest moved or vanished since the last find
            // — recompute and stamp, then re-scan (chafa re-sifts instead).
            self::findNearest($b1, $bins, $rgbw);
            $bins[$b1]['tm'] = $i;
        }

        // ─── Export: walk the surviving chain from bin 0 ───
        $palette = [];
        $i = 0;
        do {
            $palette[] = [
                (int) ($bins[$i]['r'] + 0.5),
                (int) ($bins[$i]['g'] + 0.5),
                (int) ($bins[$i]['b'] + 0.5),
            ];
            $i = $bins[$i]['next'];
        } while ($i !== 0);

        return $palette;
    }

    /**
     * chafa `color_to_index` + the averaging pass: one bin per occupied
     * 4-bit cell, means and integer sample counts, compacted to ascending
     * bin-index order (the order chafa's in-place compaction produces).
     *
     * @param list<array{int,int,int}> $samples
     * @return list<array{count: float, r: float, g: float, b: float, err: float, nearest: int, next: int, prev: int, tm: int, mtm: int}>
     */
    private static function histogram(array $samples, int $bitsPerCh): array
    {
        $shift = 8 - $bitsPerCh;
        /** @var array<int, array{0: int, 1: int, 2: int, 3: int}> $cells */
        $cells = [];
        foreach ($samples as [$r, $g, $b]) {
            $idx = (($r >> $shift) << ($bitsPerCh * 2))
                | (($g >> $shift) << $bitsPerCh)
                | ($b >> $shift);
            if (!isset($cells[$idx])) {
                $cells[$idx] = [0, 0, 0, 0];
            }
            $cells[$idx][0]++;
            $cells[$idx][1] += $r;
            $cells[$idx][2] += $g;
            $cells[$idx][3] += $b;
        }
        ksort($cells, \SORT_NUMERIC);

        $bins = [];
        foreach ($cells as $sums) {
            $count = (float) $sums[0];
            $bins[] = [
                'count'   => $count,
                'r'       => $sums[1] / $count,
                'g'       => $sums[2] / $count,
                'b'       => $sums[3] / $count,
                'err'     => \INF,
                'nearest' => 0,
                'next'    => 0,
                'prev'    => 0,
                'tm'      => 0,
                'mtm'     => 0,
            ];
        }

        return $bins;
    }

    /**
     * chafa `find_nearest` (chafa-palette.c:455-503): walk the successor
     * chain of $index and take the candidate with the lowest population-
     * weighted PNN merge error. The chain stops at index 0 (the
     * terminator), so candidates are strictly the bins AFTER $index in
     * list order — the linearisation the PNN literature describes.
     *
     * @param array<int, array<string, mixed>> $bins
     * @param array{0: float, 1: float, 2: float} $rgbw
     */
    private static function findNearest(int $index, array &$bins, array $rgbw): void
    {
        $bin1 = $bins[$index];
        $err = \INF;
        $nearest = 0;

        for ($i = $bin1['next']; $i !== 0; $i = $bins[$i]['next']) {
            $n2 = $bins[$i]['count'];
            $nerr2 = ($bin1['count'] * $n2) / ($bin1['count'] + $n2);
            if ($nerr2 >= $err) {
                continue;
            }

            $dr = $bins[$i]['r'] - $bin1['r'];
            $dg = $bins[$i]['g'] - $bin1['g'];
            $db = $bins[$i]['b'] - $bin1['b'];

            $nerr = $nerr2 * (1 - self::RATIO)
                * ($rgbw[0] * $dr * $dr + $rgbw[1] * $dg * $dg + $rgbw[2] * $db * $db);
            if ($nerr >= $err) {
                continue;
            }

            for ($j = 0; $j < 3; $j++) {
                $c = self::COEFFS[$j];
                $nerr += $nerr2 * self::RATIO
                    * ($c[0] * $dr * $dr + $c[1] * $dg * $dg + $c[2] * $db * $db);
                if ($nerr >= $err) {
                    break;
                }
            }

            if ($nerr < $err) {
                $err = $nerr;
                $nearest = $i;
            }
        }

        $bins[$index]['err'] = $err;
        $bins[$index]['nearest'] = $nearest;
    }

    /**
     * chafa `quanfn` (chafa-palette.c:442-453): population shaping applied
     * to bin counts before merging. Rungs: >1 truncating sqrt, 1 float
     * sqrt, 0 identity, <0 truncating cbrt (with the floor-correction
     * documented in the class docblock).
     */
    private static function quanfn(float $count, int $quanRt): float
    {
        if ($quanRt > 1) {
            return (float) (int) sqrt($count);
        }
        if ($quanRt === 1) {
            return sqrt($count);
        }
        if ($quanRt < 0) {
            $k = (int) ($count ** (1 / 3));
            while (($k + 1) ** 3 <= $count) {
                $k++;
            }
            while ($k ** 3 > $count) {
                $k--;
            }

            return (float) $k;
        }

        return $count;
    }
}
