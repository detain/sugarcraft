<?php

declare(strict_types=1);

/**
 * vector-quadrant-raster — flat-colour VECTOR shapes → quadrant-block cells.
 *
 * Design in "square units": x = terminal columns, y = HALF rows, so a unit is
 * roughly square on screen and circles come out round. A canvas of W cols x
 * H rows is W x 2H units. Each quadrant pixel (0.5 col x 1 unit) is
 * supersampled (SSx x SSy), the MOST FREQUENT colour wins (never averaged, so
 * edges stay crisp poster-flat), then qrRaster() picks the best two-colour
 * quadrant glyph per cell.
 *
 * Shapes (plain arrays, so designs are data):
 *   ['rect', x0, y0, x1, y1]
 *   ['ell',  cx, cy, rx, ry]
 *   ['ring', cx, cy, rxOut, ryOut, rxIn, ryIn, a0 = null, a1 = null]
 *        angular span in degrees (0 = +x/right, 90 = DOWN), a0→a1 clockwise,
 *        wraps through 360; null/null = full ring
 *   ['poly', [[x, y], ...]]                 any simple polygon (even-odd)
 *   ['diff', base, cut1, cut2, ...]         in base and in none of the cuts
 *   ['union', s1, s2, ...]
 *   ['move', dx, dy, shape]                 translate
 *
 * Layers (painted in order): ['shape' => S, 'ink' => rgb, 'mode' => M]
 *   mode 'normal'   — ink replaces what is underneath
 *   mode 'overprint'— ink on bare paper stays exact; on top of another ink it
 *                     multiplies (risograph overprint), blended toward the pure
 *                     ink by 'keep' (0..1, default 0.25) so dark inks keep hue
 *   mode 'paper'    — restores the paper colour (knock-out)
 *
 * Usage:
 *   require 'logo-kit.php'; require 'quadrant-raster.php'; require this;
 *   $px    = vqPixels($layers, $wCols, $hRows, $paperRgb);
 *   $cells = qrRaster($px);
 */

function vqInside(array $s, float $x, float $y): bool
{
    switch ($s[0]) {
        case 'rect':
            return $x >= $s[1] && $x < $s[3] && $y >= $s[2] && $y < $s[4];
        case 'ell':
            if ($s[3] <= 0 || $s[4] <= 0) {
                return false;
            }
            $dx = ($x - $s[1]) / $s[3];
            $dy = ($y - $s[2]) / $s[4];
            return $dx * $dx + $dy * $dy <= 1.0;
        case 'ring':
            [, $cx, $cy, $rxo, $ryo, $rxi, $ryi] = $s;
            $a0 = $s[7] ?? null;
            $a1 = $s[8] ?? null;
            $dx = $x - $cx;
            $dy = $y - $cy;
            $o = ($dx / $rxo) ** 2 + ($dy / $ryo) ** 2;
            $i = ($rxi > 0 && $ryi > 0) ? ($dx / $rxi) ** 2 + ($dy / $ryi) ** 2 : 2.0;
            if ($o > 1.0 || $i < 1.0) {
                return false;
            }
            if ($a0 === null) {
                return true;
            }
            $a = rad2deg(atan2($dy / $ryo, $dx / $rxo));
            $a = fmod($a - $a0 + 720.0, 360.0);
            return $a <= fmod($a1 - $a0 + 720.0, 360.0);
        case 'poly':
            $in = false;
            $p = $s[1];
            $n = count($p);
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                [$xi, $yi] = $p[$i];
                [$xj, $yj] = $p[$j];
                if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
                    $in = !$in;
                }
            }
            return $in;
        case 'diff':
            if (!vqInside($s[1], $x, $y)) {
                return false;
            }
            for ($k = 2; $k < count($s); $k++) {
                if (vqInside($s[$k], $x, $y)) {
                    return false;
                }
            }
            return true;
        case 'union':
            for ($k = 1; $k < count($s); $k++) {
                if (vqInside($s[$k], $x, $y)) {
                    return true;
                }
            }
            return false;
        case 'move':
            return vqInside($s[3], $x - $s[1], $y - $s[2]);
    }
    throw new InvalidArgumentException('unknown shape ' . $s[0]);
}

/** Colour of one sample point after painting every layer. */
function vqSample(array $layers, float $x, float $y, array $paper): array
{
    $c = $paper;
    $bare = true;
    foreach ($layers as $L) {
        if (!vqInside($L['shape'], $x, $y)) {
            continue;
        }
        $mode = $L['mode'] ?? 'normal';
        if ($mode === 'paper') {
            $c = $paper;
            $bare = true;
            continue;
        }
        $ink = $L['ink'];
        if ($mode === 'overprint' && !$bare) {
            $mul = [intdiv($c[0] * $ink[0], 255), intdiv($c[1] * $ink[1], 255), intdiv($c[2] * $ink[2], 255)];
            $c = lkMix($mul, $ink, $L['keep'] ?? 0.25);
        } else {
            $c = $ink;
        }
        $bare = false;
    }
    return $c;
}

/**
 * @param list<array> $layers
 * @return list<list<array>> quadrant pixel grid (2W x 2H) of rgb
 */
function vqPixels(array $layers, int $wCols, int $hRows, array $paper, int $ssx = 4, int $ssy = 4): array
{
    $px = [];
    for ($py = 0; $py < 2 * $hRows; $py++) {
        $row = [];
        for ($pxi = 0; $pxi < 2 * $wCols; $pxi++) {
            $votes = [];
            $cols = [];
            for ($sy = 0; $sy < $ssy; $sy++) {
                for ($sx = 0; $sx < $ssx; $sx++) {
                    $x = ($pxi + ($sx + 0.5) / $ssx) * 0.5;
                    $y = $py + ($sy + 0.5) / $ssy;
                    $c = vqSample($layers, $x, $y, $paper);
                    $k = implode(',', $c);
                    $votes[$k] = ($votes[$k] ?? 0) + 1;
                    $cols[$k] = $c;
                }
            }
            arsort($votes);
            $row[] = $cols[array_key_first($votes)];
        }
        $px[] = $row;
    }
    return $px;
}
