<?php

declare(strict_types=1);

/**
 * sextant-canvas — standalone Unicode sextant (U+1FB00 "Legacy Computing")
 * mosaic rasteriser for smooth thick strokes at 2×3 sub-pixels per cell.
 *
 * A W×H cell canvas is a (2W)×(3H) sub-pixel grid. Strokes are stamped with
 * an elliptical brush (sub-pixels are taller than wide, so rx > ry gives a
 * round pen on screen). Every inked sub-pixel remembers the NEAREST stroke
 * sample that covered it:
 *   t     — the caller's parameter at that sample (e.g. arc length 0..1),
 *   layer — which stroke/worm it belongs to,
 *   nx,ny — offset from the centre line, normalised by the brush radius
 *           (−1..1 across the stroke) → lets callers do gloss / cylinder
 *           shading by dotting with a light vector,
 *   seq   — the sample order, so animations can reveal the stroke over time.
 *
 * No dependencies; pairs with logo-kit.php (cells → ANSI).
 *
 * API
 *   scNew(wCells, hCells)                         → canvas
 *   scCatmull(pts, stepsPerSeg)                   → smooth points through pts
 *   scArc(cx, cy, rx, ry, a0, a1, steps)          → ellipse points (deg, y-down, 0=right, 90=down)
 *   scArcLen(pts)                                 → cumulative lengths (physical units)
 *   scStroke(&c, pts, rx, ry, layer, t0, t1, &seq)stamp a polyline; t interpolated by arc length
 *   scCells(c, ?maxSeq)                           → [cy][cx] = ['bits'=>int,'n'=>int,'t'=>avg,'shade'=>[nx,ny],'layer'=>id,'seq'=>min]
 *   scGlyph(bits)                                 → sextant / ▌ / ▐ / █ / ' ' for a 6-bit mask
 *   scBits(glyph)                                 → inverse of scGlyph (null if not a mosaic glyph)
 *
 * Bit layout per cell (Unicode sextant numbering):  1 2 / 4 8 / 16 32
 */

const SC_ASPECT = 4 / 3;   // sub-pixel height / width on a 1:2 cell

function scNew(int $wCells, int $hCells): array
{
    return ['cw' => $wCells, 'ch' => $hCells, 'w' => $wCells * 2, 'h' => $hCells * 3, 'px' => []];
}

/** Catmull-Rom spline through the points (endpoints repeated). */
function scCatmull(array $pts, int $steps = 8): array
{
    $n = count($pts);
    if ($n < 3) {
        return $pts;
    }
    $out = [];
    for ($i = 0; $i < $n - 1; $i++) {
        $p0 = $pts[max(0, $i - 1)];
        $p1 = $pts[$i];
        $p2 = $pts[$i + 1];
        $p3 = $pts[min($n - 1, $i + 2)];
        for ($s = 0; $s < $steps; $s++) {
            $t = $s / $steps;
            $t2 = $t * $t;
            $t3 = $t2 * $t;
            $f = static fn (int $k): float => 0.5 * ((2 * $p1[$k]) + (-$p0[$k] + $p2[$k]) * $t
                + (2 * $p0[$k] - 5 * $p1[$k] + 4 * $p2[$k] - $p3[$k]) * $t2
                + (-$p0[$k] + 3 * $p1[$k] - 3 * $p2[$k] + $p3[$k]) * $t3);
            $out[] = [$f(0), $f(1)];
        }
    }
    $out[] = $pts[$n - 1];
    return $out;
}

/** Points along an ellipse arc; angles in degrees, y-down (90 = bottom, -90 = top). */
function scArc(float $cx, float $cy, float $rx, float $ry, float $a0, float $a1, int $steps = 24): array
{
    $pts = [];
    for ($i = 0; $i <= $steps; $i++) {
        $a = deg2rad($a0 + ($a1 - $a0) * $i / $steps);
        $pts[] = [$cx + $rx * cos($a), $cy + $ry * sin($a)];
    }
    return $pts;
}

/** Cumulative arc length in physical units (y scaled by SC_ASPECT). */
function scArcLen(array $pts): array
{
    $len = [0.0];
    for ($i = 1, $n = count($pts); $i < $n; $i++) {
        $len[] = $len[$i - 1] + hypot($pts[$i][0] - $pts[$i - 1][0], ($pts[$i][1] - $pts[$i - 1][1]) * SC_ASPECT);
    }
    return $len;
}

/**
 * Stamp a polyline with an elliptical brush (radii in sub-pixels). The
 * parameter runs t0→t1 by arc length; nearest sample wins per sub-pixel.
 */
function scStroke(array &$c, array $pts, float $rx, float $ry, int $layer, float $t0, float $t1, int &$seq): void
{
    // resample densely so the nearest-sample bookkeeping is smooth
    $dense = [$pts[0]];
    for ($i = 1, $n = count($pts); $i < $n; $i++) {
        [$ax, $ay] = $pts[$i - 1];
        [$bx, $by] = $pts[$i];
        $k = max(1, (int) ceil(hypot($bx - $ax, $by - $ay) * 3));
        for ($j = 1; $j <= $k; $j++) {
            $dense[] = [$ax + ($bx - $ax) * $j / $k, $ay + ($by - $ay) * $j / $k];
        }
    }
    $len = scArcLen($dense);
    $total = max(1e-9, end($len));
    foreach ($dense as $i => [$px, $py]) {
        $t = $t0 + ($t1 - $t0) * $len[$i] / $total;
        $s = $seq++;
        for ($y = (int) floor($py - $ry); $y <= (int) ceil($py + $ry); $y++) {
            for ($x = (int) floor($px - $rx); $x <= (int) ceil($px + $rx); $x++) {
                if ($x < 0 || $y < 0 || $x >= $c['w'] || $y >= $c['h']) {
                    continue;
                }
                $nx = ($x + 0.5 - $px) / $rx;
                $ny = ($y + 0.5 - $py) / $ry;
                $d = $nx * $nx + $ny * $ny;
                if ($d > 1.0) {
                    continue;
                }
                $old = $c['px'][$y][$x] ?? null;
                if ($old === null || $d < $old['d']) {
                    $c['px'][$y][$x] = ['d' => $d, 't' => $t, 'layer' => $layer, 'nx' => $nx, 'ny' => $ny,
                        'seq' => min($s, $old['seq'] ?? PHP_INT_MAX)];
                }
            }
        }
    }
}

/** Collapse sub-pixels to cells. $maxSeq hides sub-pixels first drawn after it (animation). */
function scCells(array $c, ?int $maxSeq = null): array
{
    $cells = [];
    for ($cy = 0; $cy < $c['ch']; $cy++) {
        for ($cx = 0; $cx < $c['cw']; $cx++) {
            $bits = 0;
            $n = 0;
            $t = $nx = $ny = 0.0;
            $layers = [];
            $seq = PHP_INT_MAX;
            for ($dy = 0; $dy < 3; $dy++) {
                for ($dx = 0; $dx < 2; $dx++) {
                    $p = $c['px'][$cy * 3 + $dy][$cx * 2 + $dx] ?? null;
                    if ($p === null || ($maxSeq !== null && $p['seq'] > $maxSeq)) {
                        continue;
                    }
                    $bits |= 1 << ($dy * 2 + $dx);
                    $n++;
                    $t += $p['t'];
                    $nx += $p['nx'];
                    $ny += $p['ny'];
                    $layers[$p['layer']] = ($layers[$p['layer']] ?? 0) + 1;
                    $seq = min($seq, $p['seq']);
                }
            }
            if ($n === 0) {
                $cells[$cy][$cx] = null;
                continue;
            }
            arsort($layers);
            $cells[$cy][$cx] = ['bits' => $bits, 'n' => $n, 't' => $t / $n,
                'shade' => [$nx / $n, $ny / $n], 'layer' => array_key_first($layers), 'seq' => $seq];
        }
    }
    return $cells;
}

/** 6-bit sextant mask → glyph. Unicode omits the pure column/full patterns. */
function scGlyph(int $bits): string
{
    return match ($bits) {
        0 => ' ',
        21 => '▌',
        42 => '▐',
        63 => '█',
        default => mb_chr(0x1FB00 + $bits - 1 - ($bits > 21 ? 1 : 0) - ($bits > 42 ? 1 : 0), 'UTF-8'),
    };
}

function scBits(string $glyph): ?int
{
    static $map = null;
    if ($map === null) {
        $map = [];
        for ($b = 0; $b < 64; $b++) {
            $map[scGlyph($b)] = $b;
        }
    }
    return $map[$glyph] ?? null;
}
