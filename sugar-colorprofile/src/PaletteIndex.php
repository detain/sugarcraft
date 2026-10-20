<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile;

/**
 * B-04: PCA-bucketed accelerator for nearest-color lookup over a fixed palette.
 *
 * Per chafa chafa-color-table.c:142-307 (`ChafaColorTable`): the palette is
 * projected onto the principal axes of its own distribution (PCA via a cyclic
 * Jacobi eigen-decomposition of the 3x3 covariance), entries are sorted by the
 * leading axis, and a lookup binary-searches that axis, then scans outward,
 * pruning candidates whose axis-0 gap already exceeds the best full distance.
 * Chafa keeps only the 2 leading axes and prunes with a heuristic
 * `a+b <= best*17/16` slack — an approximation. This port keeps the FULL 3-axis
 * orthonormal basis, so the projection is a lossless rotation: the axis-0 gap
 * is a true lower bound, and each visited candidate is re-checked with the
 * exact same arithmetic the brute-force {@see NearestColor::closest()} uses
 * (same functions, same operation order). The winner is therefore identical to
 * the brute-force scan, always — including ties, which resolve to the lowest
 * palette index exactly as {@see NearestColor} does (the documented tie rule,
 * same as chafa :248).
 *
 * Supported metrics are exactly the Euclidean-in-a-linear-space family, since
 * only those admit a distance-preserving rotation:
 *  - {@see ColorDistance::Euclidean} — PCA and scan in sRGB 0-255 space;
 *  - {@see ColorDistance::Cie76}     — PCA and scan in L*a*b* (chafa's own
 *    color-table scheme, upgraded to the perceptual space this lib pairs with
 *    memoized Lab palettes, see NearestColor's cost note).
 * {@see ColorDistance::Cie94} and {@see ColorDistance::Cie2000} are refused:
 * their weightings depend on the candidate itself (SL/SC/SH, RT terms), so no
 * fixed basis exists — use {@see NearestColor::closest()} there instead.
 *
 * Construction costs O(n log n) (plus one sRGB->Lab conversion per entry under
 * Cie76); lookups drop from O(n) full-distance computations to a binary search
 * plus a handful of re-checks. Instances are immutable. {@see self::fromPalette()}
 * memoizes per (metric, palette-bytes) identity in a bounded, coarse
 * all-or-nothing cache — rebuilds are cheap, so the cache is a convenience,
 * never a contract (a process churning more than the limit of distinct palettes
 * simply pays rebuilds; it can never get a wrong answer from it).
 */
final class PaletteIndex
{
    /** Relative slack on the pruning bound, absorbing float noise in the rotated coordinates. */
    private const PRUNE_SLACK = 1.000001;

    /** Distinct (metric, palette) identities retained by {@see self::fromPalette()}. */
    private const CACHE_LIMIT = 16;

    /** @var array<string,self> */
    private static array $cache = [];

    /**
     * @param list<Color> $palette original entries, in caller order (index == key)
     * @param list<array{0: float, 1: float, 2: float}> $basis eigenvector columns, descending eigenvalue, sign-normalized
     * @param array{0: float, 1: float, 2: float} $mean palette centroid in the metric space
     * @param list<Color> $sorted palette entries reordered by ascending leading-axis coordinate
     * @param list<array{l: float, a: float, b: float}>|list<Color> $recheck metric-space data aligned with $sorted: Lab triples under Cie76 (fed verbatim to DeltaE::cie76, the oracle's own function), Colors under Euclidean
     * @param list<float> $axis0 leading-axis coordinates aligned with $sorted (ascending)
     * @param list<int> $origIndex original palette index aligned with $sorted
     */
    private function __construct(
        private readonly ColorDistance $metric,
        private readonly array $palette,
        private readonly array $basis,
        private readonly array $mean,
        private readonly array $sorted,
        private readonly array $recheck,
        private readonly array $axis0,
        private readonly array $origIndex,
    ) {
    }

    /**
     * Build (or reuse) an index over $colors under $metric.
     *
     * Cache key: the metric case value, a chr(31) separator, then md5 over the
     * packed RGBA bytes of the palette in order — full value identity, so two
     * structurally equal palettes share one index and any channel difference
     * (alpha included, since palette() hands the entries back) forces a rebuild.
     *
     * @param array<array-key, Color> $colors
     *
     * @throws \InvalidArgumentException on an empty palette, or a metric that
     *                                  admits no distance-preserving rotation
     */
    public static function fromPalette(array $colors, ColorDistance $metric = ColorDistance::Cie76): self
    {
        $list = array_values($colors);
        if ($list === []) {
            throw new \InvalidArgumentException(Lang::t('nearest.empty_palette'));
        }

        $packed = '';
        foreach ($list as $entry) {
            $packed .= chr($entry->r) . chr($entry->g) . chr($entry->b) . chr($entry->a);
        }
        $key = $metric->value . chr(31) . md5($packed);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $index = self::build($list, $metric);
        if (count(self::$cache) >= self::CACHE_LIMIT) {
            self::$cache = [];
        }

        return self::$cache[$key] = $index;
    }

    /**
     * Nearest palette index (lowest index on exact ties).
     */
    public function nearest(Color $color): int
    {
        if ($this->metric === ColorDistance::Euclidean) {
            $query = [(float) $color->r, (float) $color->g, (float) $color->b];
        } else {
            $queryLab = ColorMath::toLab($color->r, $color->g, $color->b);
            $query = [$queryLab['l'], $queryLab['a'], $queryLab['b']];
        }

        $q0 =
            ($query[0] - $this->mean[0]) * $this->basis[0][0] +
            ($query[1] - $this->mean[1]) * $this->basis[1][0] +
            ($query[2] - $this->mean[2]) * $this->basis[2][0];

        $n = count($this->axis0);
        // Insertion point: first sorted slot whose leading coordinate is >= q0.
        $lo = 0;
        $hi = $n;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->axis0[$mid] < $q0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        $left = $lo - 1;
        $right = $lo;
        $bestIdx = -1;
        /** @var int|float $best full distance in the oracle's domain */
        $best = -1;

        while ($left >= 0 || $right < $n) {
            // Visit the candidate closest on the leading axis first; once its
            // axis-0 gap alone exceeds the best full distance, every remaining
            // candidate does too (lossless rotation => axis-0 gap is a true
            // lower bound) — stop. The comparison keeps a relative slack and
            // scans `<=` (never `<`) so exact-tie candidates are never skipped.
            $dl = $left >= 0 ? $q0 - $this->axis0[$left] : \INF;
            $dr = $right < $n ? $this->axis0[$right] - $q0 : \INF;

            if ($dl <= $dr) {
                $delta = $dl;
                $slot = $left;
                $left--;
            } else {
                $delta = $dr;
                $slot = $right;
                $right++;
            }

            if ($bestIdx >= 0) {
                // Bound and best live in the same domain: Euclidean full
                // distances are squared integers (bestRgbIndex arithmetic),
                // so the axis-0 bound squares too; Cie76 distances are sqrt
                // (DeltaE::cie76 arithmetic), so the bound stays linear.
                $bound = $this->metric === ColorDistance::Euclidean ? $delta * $delta : $delta;
                if ($bound > $best * self::PRUNE_SLACK) {
                    break;
                }
            }

            $distance = $this->distanceTo($color, $slot);
            if (
                $bestIdx < 0
                || $distance < $best
                || ($distance == $best && $this->origIndex[$slot] < $bestIdx)
            ) {
                $best = $distance;
                $bestIdx = $this->origIndex[$slot];
            }
        }

        return $bestIdx;
    }

    /**
     * The distance strategy this index was built with.
     */
    public function metric(): ColorDistance
    {
        return $this->metric;
    }

    /**
     * The palette this index was built from, in caller order.
     *
     * @return list<Color>
     */
    public function palette(): array
    {
        return $this->palette;
    }

    public function count(): int
    {
        return count($this->palette);
    }

    /**
     * @param list<Color> $list
     */
    private static function build(array $list, ColorDistance $metric): self
    {
        if ($metric !== ColorDistance::Euclidean && $metric !== ColorDistance::Cie76) {
            throw new \InvalidArgumentException(
                'PaletteIndex requires a distance-preserving metric (Euclidean or Cie76); '
                . $metric->value . ' has no fixed PCA basis - use NearestColor::closest() instead',
            );
        }

        // One metric-space vector per entry; the Cie76 triples come straight
        // out of ColorMath::toLab so the re-check below can hand them to
        // DeltaE::cie76 verbatim — bit-identical to the oracle's values.
        $vectors = [];
        $recheckSource = [];
        foreach ($list as $entry) {
            if ($metric === ColorDistance::Euclidean) {
                $vectors[] = [(float) $entry->r, (float) $entry->g, (float) $entry->b];
                $recheckSource[] = $entry;
            } else {
                $lab = ColorMath::toLab($entry->r, $entry->g, $entry->b);
                $vectors[] = [$lab['l'], $lab['a'], $lab['b']];
                $recheckSource[] = $lab;
            }
        }

        $n = count($vectors);
        $mean = [0.0, 0.0, 0.0];
        foreach ($vectors as $v) {
            $mean[0] += $v[0];
            $mean[1] += $v[1];
            $mean[2] += $v[2];
        }
        $mean = [$mean[0] / $n, $mean[1] / $n, $mean[2] / $n];

        $cov = [[0.0, 0.0, 0.0], [0.0, 0.0, 0.0], [0.0, 0.0, 0.0]];
        foreach ($vectors as $v) {
            $d = [$v[0] - $mean[0], $v[1] - $mean[1], $v[2] - $mean[2]];
            for ($i = 0; $i < 3; $i++) {
                for ($j = $i; $j < 3; $j++) {
                    $cov[$i][$j] += $d[$i] * $d[$j];
                    if ($j !== $i) {
                        $cov[$j][$i] += $d[$i] * $d[$j];
                    }
                }
            }
        }

        [$values, $eigenvectors] = self::jacobi($cov);

        // Order axes by descending eigenvalue; normalize each eigenvector's
        // sign (largest-magnitude component positive) so the leading-axis sort
        // order — and thus tie visitation — is reproducible run to run.
        $order = [0, 1, 2];
        usort($order, static fn(int $x, int $y): int => $values[$y] <=> $values[$x]);
        $basis = [[0.0, 0.0, 0.0], [0.0, 0.0, 0.0], [0.0, 0.0, 0.0]];
        foreach ($order as $rank => $axis) {
            $column = [$eigenvectors[0][$axis], $eigenvectors[1][$axis], $eigenvectors[2][$axis]];
            $pivot = 0;
            if (abs($column[1]) > abs($column[$pivot])) {
                $pivot = 1;
            }
            if (abs($column[2]) > abs($column[$pivot])) {
                $pivot = 2;
            }
            if ($column[$pivot] < 0) {
                $column = [-$column[0], -$column[1], -$column[2]];
            }
            for ($i = 0; $i < 3; $i++) {
                $basis[$i][$rank] = $column[$i];
            }
        }

        // Sort entries by leading coordinate, ties by original index (stable,
        // explicit — the tie rule the brute force would apply anyway).
        $slots = [];
        foreach ($vectors as $i => $v) {
            $d = [$v[0] - $mean[0], $v[1] - $mean[1], $v[2] - $mean[2]];
            $slots[] = [
                $d[0] * $basis[0][0] + $d[1] * $basis[1][0] + $d[2] * $basis[2][0],
                $i,
            ];
        }
        usort($slots, static fn(array $x, array $y): int => [$x[0], $x[1]] <=> [$y[0], $y[1]]);

        $sorted = [];
        $recheck = [];
        $axis0 = [];
        $origIndex = [];
        foreach ($slots as [$c0, $i]) {
            $sorted[] = $list[$i];
            $recheck[] = $recheckSource[$i];
            $axis0[] = $c0;
            $origIndex[] = $i;
        }

        return new self($metric, $list, $basis, $mean, $sorted, $recheck, $axis0, $origIndex);
    }

    /**
     * Full distance to one sorted candidate, computed with the SAME function,
     * inputs and operation order the brute-force oracle uses: integer squared
     * difference per bestRgbIndex() under Euclidean, DeltaE::cie76() over the
     * verbatim toLab() triples under Cie76 (the oracle's betweenLab leg).
     * Tie verdicts are therefore bit-for-bit identical, not merely equal.
     */
    private function distanceTo(Color $color, int $slot): int|float
    {
        if ($this->metric === ColorDistance::Euclidean) {
            /** @var Color $entry */
            $entry = $this->recheck[$slot];
            $dr = $color->r - $entry->r;
            $dg = $color->g - $entry->g;
            $db = $color->b - $entry->b;

            return $dr * $dr + $dg * $dg + $db * $db;
        }

        /** @var array{l: float, a: float, b: float} $queryLab */
        $queryLab = ColorMath::toLab($color->r, $color->g, $color->b);

        /** @var array{l: float, a: float, b: float} $entryLab */
        $entryLab = $this->recheck[$slot];

        return DeltaE::cie76($queryLab, $entryLab);
    }

    /**
     * Cyclic Jacobi eigen-decomposition of a symmetric 3x3 matrix (trivially
     * cheap in PHP; converges in a couple of sweeps for 3 dimensions).
     *
     * @param array<int,array<int,float>> $matrix
     *
     * @return array{0: array<int,float>, 1: array<int,array<int,float>>} diagonal eigenvalues (unaligned) and eigenvector columns
     */
    private static function jacobi(array $matrix): array
    {
        $m = $matrix;
        $v = [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]];

        for ($sweep = 0; $sweep < 32; $sweep++) {
            $off = abs($m[0][1]) + abs($m[0][2]) + abs($m[1][2]);
            if ($off <= 1e-15 * abs($m[0][0] + $m[1][1] + $m[2][2])) {
                break;
            }
            foreach ([[0, 1], [0, 2], [1, 2]] as [$p, $q]) {
                if (abs($m[$p][$q]) < 1e-300) {
                    continue;
                }
                $theta = 0.5 * atan2(2.0 * $m[$p][$q], $m[$p][$p] - $m[$q][$q]);
                $c = cos($theta);
                $s = sin($theta);
                for ($k = 0; $k < 3; $k++) {
                    $mkp = $m[$k][$p];
                    $mkq = $m[$k][$q];
                    $m[$k][$p] = $c * $mkp - $s * $mkq;
                    $m[$k][$q] = $s * $mkp + $c * $mkq;
                }
                for ($k = 0; $k < 3; $k++) {
                    $mpk = $m[$p][$k];
                    $mqk = $m[$q][$k];
                    $m[$p][$k] = $c * $mpk - $s * $mqk;
                    $m[$q][$k] = $s * $mpk + $c * $mqk;
                }
                for ($k = 0; $k < 3; $k++) {
                    $vkp = $v[$k][$p];
                    $vkq = $v[$k][$q];
                    $v[$k][$p] = $c * $vkp - $s * $vkq;
                    $v[$k][$q] = $s * $vkp + $c * $vkq;
                }
            }
        }

        return [[$m[0][0], $m[1][1], $m[2][2]], $v];
    }
}
