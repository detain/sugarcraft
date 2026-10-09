<?php

declare(strict_types=1);

/**
 * inflated-sdf-letters — "puffed-up" 3D letters from stroke SKELETONS.
 *
 * A letter is a list of centre-line polylines (lines, elliptical arcs) in
 * physical units (x = sub-pixel columns, y scaled so units are square on
 * screen). Distance to the skeleton (smooth-min across strokes, so joins
 * swell like a blown-up balloon) gives a round tube of radius R; the height
 * field h = R·sqrt(1-(d/R)²) gives a surface normal for shading.
 *
 *   $sk = isSkeleton([ ['line', [[x,y], [x,y], ...]], ['arc', cx,cy,rx,ry,a0,a1], ... ]);
 *   $d  = isDist($sk, $x, $y, $k);          // smooth-union distance
 *   [$nx, $ny, $nz] = isNormal($sk, $x, $y, $R, $k);
 *   isShadeFoil($base, $n, $d / $R, $light) // metallic-foil shading helper
 *
 * Arcs: x = cx + rx·cos a, y = cy + ry·sin a (y down), degrees, a0 → a1.
 * Pure functions — no I/O; pair with sextant-image-raster.php (siSupersample)
 * or vector-quadrant-raster.php.
 */

/** Flatten primitive strokes into segment lists. @return list<list<array{0:float,1:float,2:float,3:float}>> */
function isSkeleton(array $strokes): array
{
    $out = [];
    foreach ($strokes as $s) {
        if ($s[0] === 'line') {
            $pts = $s[1];
        } elseif ($s[0] === 'arc') {
            [, $cx, $cy, $rx, $ry, $a0, $a1] = $s;
            $n = max(8, (int) ceil(abs($a1 - $a0) / 8));
            $pts = [];
            for ($i = 0; $i <= $n; $i++) {
                $a = deg2rad($a0 + ($a1 - $a0) * $i / $n);
                $pts[] = [$cx + $rx * cos($a), $cy + $ry * sin($a)];
            }
        } else {
            throw new InvalidArgumentException('stroke ' . $s[0]);
        }
        $segs = [];
        for ($i = 0; $i + 1 < count($pts); $i++) {
            $segs[] = [$pts[$i][0], $pts[$i][1], $pts[$i + 1][0], $pts[$i + 1][1]];
        }
        if (count($pts) === 1) {
            $segs[] = [$pts[0][0], $pts[0][1], $pts[0][0], $pts[0][1]];
        }
        $out[] = $segs;
    }
    return $out;
}

function isSegDist(array $g, float $x, float $y): float
{
    [$ax, $ay, $bx, $by] = $g;
    $dx = $bx - $ax;
    $dy = $by - $ay;
    $l2 = $dx * $dx + $dy * $dy;
    $t = $l2 > 0 ? max(0.0, min(1.0, (($x - $ax) * $dx + ($y - $ay) * $dy) / $l2)) : 0.0;
    $px = $ax + $t * $dx - $x;
    $py = $ay + $t * $dy - $y;
    return sqrt($px * $px + $py * $py);
}

/** Polynomial smooth minimum (k = blend radius; 0 = hard min). */
function isSmin(float $a, float $b, float $k): float
{
    if ($k <= 0) {
        return min($a, $b);
    }
    $h = max($k - abs($a - $b), 0.0) / $k;
    return min($a, $b) - $h * $h * $k * 0.25;
}

/** Smooth-union distance from (x, y) to the skeleton. */
function isDist(array $sk, float $x, float $y, float $k = 1.2): float
{
    $d = INF;
    foreach ($sk as $segs) {
        $s = INF;
        foreach ($segs as $g) {
            $s = min($s, isSegDist($g, $x, $y));
        }
        $d = $d === INF ? $s : isSmin($d, $s, $k);
    }
    return $d;
}

function isHeight(array $sk, float $x, float $y, float $R, float $k): float
{
    $d = isDist($sk, $x, $y, $k) / $R;
    return $d >= 1.0 ? 0.0 : $R * sqrt(1.0 - $d * $d);
}

/** Unit surface normal of the inflated tube (z towards the viewer). */
function isNormal(array $sk, float $x, float $y, float $R, float $k = 1.2, float $e = 0.35): array
{
    $hx = (isHeight($sk, $x + $e, $y, $R, $k) - isHeight($sk, $x - $e, $y, $R, $k)) / (2 * $e);
    $hy = (isHeight($sk, $x, $y + $e, $R, $k) - isHeight($sk, $x, $y - $e, $R, $k)) / (2 * $e);
    $l = sqrt($hx * $hx + $hy * $hy + 1.0);
    return [-$hx / $l, -$hy / $l, 1.0 / $l];
}

function isNorm(array $v): array
{
    $l = sqrt($v[0] ** 2 + $v[1] ** 2 + $v[2] ** 2) ?: 1.0;
    return [$v[0] / $l, $v[1] / $l, $v[2] / $l];
}

/**
 * Mylar/foil balloon shading: dark metallic base, broad environment band,
 * hard white specular, coloured bounce on the far rim, crimped seam at the
 * edge. $edge = d/R (0 centre-line … 1 outline). $alt = second hue that
 * creeps in at grazing angles (iridescence).
 */
function isShadeFoil(array $base, array $n, float $edge, array $light, ?array $alt = null, array $o = []): array
{
    $L = isNorm($light);
    $dif = max(0.0, $n[0] * $L[0] + $n[1] * $L[1] + $n[2] * $L[2]);
    // reflection of the view ray (0,0,1)
    $r = [2 * $n[2] * $n[0], 2 * $n[2] * $n[1], 2 * $n[2] * $n[2] - 1];
    $spec = max(0.0, $r[0] * $L[0] + $r[1] * $L[1] + $r[2] * $L[2]) ** ($o['shine'] ?? 26);
    $graze = 1.0 - $n[2];
    $c = $base;
    if ($alt !== null) {
        $c = lkMix($c, $alt, min(0.55, $graze * ($o['iri'] ?? 0.6)));
    }
    // metallic: lots of contrast — dark core where it faces away, env sky band
    $sky = max(0.0, -$r[1]);                       // reflection pointing up
    $k = ($o['amb'] ?? 0.28) + 0.85 * $dif + 0.35 * $sky;
    $c = [$c[0] * $k, $c[1] * $k, $c[2] * $k];
    // bounce light on the lower-right rim
    $bounce = max(0.0, 0.6 * $n[0] + 0.8 * $n[1]) * $graze;
    $c = lkMix(array_map(static fn ($v) => (int) max(0, min(255, round($v))), $c), lkMix($base, [255, 255, 255], 0.45), min(0.6, $bounce * ($o['bounce'] ?? 0.9)));
    // crimped seam
    if ($edge > ($o['seam'] ?? 0.86)) {
        $c = lkScale($c, $o['seamDark'] ?? 0.62);
    }
    return lkMix($c, [255, 255, 255], min(1.0, $spec * ($o['spec'] ?? 1.15)));
}
