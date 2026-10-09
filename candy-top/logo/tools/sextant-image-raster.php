<?php

declare(strict_types=1);

/**
 * sextant-image-raster — turn a full-colour PIXEL IMAGE into sextant cells.
 *
 * Paint any scene at (2W)×(3H) sub-pixels (one rgb|null per sub-pixel), then
 * siCells() picks, for every 2×3 cell, the sextant mask + fg/bg colour pair
 * that minimises squared colour error (exhaustive over the 32 partitions).
 * null = transparent: a cell with transparent sub-pixels keeps bg null and
 * inks only the opaque ones (fg = their mean).
 *
 * For 16/256 output, quantise the sub-pixels FIRST (siQuantise) so every
 * cell's pair is two real palette colours — far cleaner than fitting in
 * truecolour and downgrading the averages afterwards.
 *
 * Also: siSupersample() — evaluate a colour function at N×N samples per
 * sub-pixel and average (anti-aliased shapes from analytic functions).
 *
 * Requires sextant-canvas.php (scGlyph) + logo-kit.php (cell format, lkTo256).
 * Cells: [glyph, fg rgb|null, bg rgb|null] — feed straight to lkEncode().
 */

require_once __DIR__ . '/logo-kit.php';
require_once __DIR__ . '/sextant-canvas.php';

/**
 * @param callable(float $x, float $y): ?array $fn colour at sub-pixel coords
 *        (continuous, 0..2W × 0..3H); null = transparent
 * @return list<list<?array>> [py][px]
 */
function siSupersample(int $pxW, int $pxH, callable $fn, int $ss = 3): array
{
    $img = [];
    for ($y = 0; $y < $pxH; $y++) {
        for ($x = 0; $x < $pxW; $x++) {
            $acc = [0.0, 0.0, 0.0];
            $n = 0;
            for ($j = 0; $j < $ss; $j++) {
                for ($i = 0; $i < $ss; $i++) {
                    $c = $fn($x + ($i + 0.5) / $ss, $y + ($j + 0.5) / $ss);
                    if ($c !== null) {
                        $acc[0] += $c[0];
                        $acc[1] += $c[1];
                        $acc[2] += $c[2];
                        $n++;
                    }
                }
            }
            $img[$y][$x] = $n * 2 < $ss * $ss ? null
                : [(int) round($acc[0] / $n), (int) round($acc[1] / $n), (int) round($acc[2] / $n)];
        }
    }
    return $img;
}

/**
 * Map every sub-pixel to a palette: '256' (xterm, redmean nearest) or '16'
 * (callable or nearest over lkAnsi16()). Optional 4×4 Bayer dither strength
 * (0..1, in 0-255 units ×32) smooths gradients at low depth.
 * @param ?callable(array $rgb, int $x, int $y): array $map16 custom 16-colour picker
 */
function siQuantise(array $img, string $depth, float $dither = 0.0, ?callable $map16 = null): array
{
    if ($depth === 'tc') {
        return $img;
    }
    static $bayer = [[0, 8, 2, 10], [12, 4, 14, 6], [3, 11, 1, 9], [15, 7, 13, 5]];
    $xt = lkXterm256();
    $a16 = lkAnsi16();
    foreach ($img as $y => $row) {
        foreach ($row as $x => $c) {
            if ($c === null) {
                continue;
            }
            if ($dither > 0) {
                $d = ($bayer[$y & 3][$x & 3] / 16 - 0.5) * 32 * $dither;
                $c = array_map(static fn ($v) => max(0, min(255, (int) round($v + $d))), $c);
            }
            if ($depth === '256') {
                $img[$y][$x] = $xt[lkTo256($c)];
            } elseif ($map16 !== null) {
                $img[$y][$x] = $map16($c, $x, $y);
            } else {
                $best = null;
                $bd = INF;
                foreach ($a16 as $p) {
                    $dd = lkDist($c, $p);
                    if ($dd < $bd) {
                        $bd = $dd;
                        $best = $p;
                    }
                }
                $img[$y][$x] = $best;
            }
        }
    }
    return $img;
}

/**
 * Fit each 2×3 block to the best sextant + 2 colours.
 * $snap: when true (quantised images) the pair is snapped to colours that
 * actually occur in the block, so no off-palette averages appear.
 * @return list<list<array>> cells
 */
function siCells(array $img, bool $snap = false): array
{
    $pxH = count($img);
    $pxW = count($img[0]);
    $cells = [];
    for ($cy = 0; $cy * 3 < $pxH; $cy++) {
        for ($cx = 0; $cx * 2 < $pxW; $cx++) {
            $sub = [];
            for ($k = 0; $k < 6; $k++) {
                $sub[$k] = $img[$cy * 3 + intdiv($k, 2)][$cx * 2 + $k % 2] ?? null;
            }
            $cells[$cy][] = siFit($sub, $snap);
        }
    }
    return $cells;
}

/** @param list<?array> $sub six sub-pixels in sextant bit order (1 2 / 4 8 / 16 32) */
function siFit(array $sub, bool $snap): array
{
    $opaque = 0;
    foreach ($sub as $k => $c) {
        if ($c !== null) {
            $opaque |= 1 << $k;
        }
    }
    if ($opaque === 0) {
        return [' ', null, null];
    }
    if ($opaque !== 63) {
        $m = siMean(array_filter($sub), $snap);
        return [scGlyph($opaque), $m, null];
    }
    $best = null;
    $bestErr = INF;
    for ($mask = 0; $mask < 32; $mask++) {   // bit 5 fixed to bg side: covers all partitions
        $a = $b = [];
        foreach ($sub as $k => $c) {
            if ($mask & (1 << $k)) {
                $a[] = $c;
            } else {
                $b[] = $c;
            }
        }
        $ma = $a ? siMean($a, $snap) : null;
        $mb = siMean($b, $snap);
        $err = 0.0;
        foreach ($a as $c) {
            $err += siErr($c, $ma);
        }
        foreach ($b as $c) {
            $err += siErr($c, $mb);
        }
        if ($err < $bestErr - 1e-6) {
            $bestErr = $err;
            $best = [$mask, $ma, $mb];
        }
    }
    [$mask, $fg, $bg] = $best;
    if ($mask === 0) {
        return [' ', null, $bg];
    }
    return [scGlyph($mask), $fg, $bg];
}

function siErr(array $a, array $b): float
{
    $r = ($a[0] + $b[0]) / 2;
    $dr = $a[0] - $b[0];
    $dg = $a[1] - $b[1];
    $db = $a[2] - $b[2];
    return (2 + $r / 256) * $dr * $dr + 4 * $dg * $dg + (2 + (255 - $r) / 256) * $db * $db;
}

/** Mean colour, or (snap) the member colour minimising error to the rest. */
function siMean(array $cs, bool $snap): array
{
    $cs = array_values($cs);
    if ($snap) {
        $best = $cs[0];
        $be = INF;
        foreach ($cs as $cand) {
            $e = 0.0;
            foreach ($cs as $c) {
                $e += siErr($c, $cand);
            }
            if ($e < $be) {
                $be = $e;
                $best = $cand;
            }
        }
        return $best;
    }
    $n = count($cs);
    $s = [0, 0, 0];
    foreach ($cs as $c) {
        $s[0] += $c[0];
        $s[1] += $c[1];
        $s[2] += $c[2];
    }
    return [(int) round($s[0] / $n), (int) round($s[1] / $n), (int) round($s[2] / $n)];
}

/** Write a sub-pixel image straight to PNG (scale× per sub-pixel, 1:1.5 aspect kept). */
function siImagePng(array $img, string $out, int $scale = 6): void
{
    $h = count($img);
    $w = count($img[0]);
    $im = imagecreatetruecolor($w * $scale, (int) ($h * $scale * 4 / 3));
    $sy = $scale * 4 / 3;
    foreach ($img as $y => $row) {
        foreach ($row as $x => $c) {
            $c ??= [26, 27, 38];
            $col = imagecolorallocate($im, $c[0], $c[1], $c[2]);
            imagefilledrectangle($im, $x * $scale, (int) ($y * $sy), ($x + 1) * $scale - 1, (int) (($y + 1) * $sy) - 1, $col);
        }
    }
    imagepng($im, $out);
}
