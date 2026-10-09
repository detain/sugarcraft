<?php

declare(strict_types=1);

/**
 * quadrant-raster — pixel grid → cell grid at 2x2 pixels per cell, using the
 * quadrant block glyphs (▘▝▀▖▌▞▛▗▚▐▜▄▙▟█). That is twice the horizontal
 * resolution of half blocks, so rounded corners, diagonals and 1.5-col strokes
 * survive.
 *
 * Each cell can only carry two colours (fg + bg). For each cell the rasteriser
 * tries every (fg, bg) pair from the colours actually present in its four
 * pixels, maps each pixel to the nearer of the two, and keeps the pair with the
 * lowest error. Colours stay crisp (it never averages) and the dominant
 * shapes win. null = transparent pixel; it can only be the bg.
 *
 * Usage:
 *   require __DIR__ . '/logo-kit.php';        // for lkDist
 *   require __DIR__ . '/quadrant-raster.php';
 *   $cells = qrRaster($px);                   // $px: rows of rgb|null, any w/h
 *   $cov   = qrCoverage($mask);               // bool grid → per-cell 0..4 counts
 */

/** Bit order TL=1, TR=2, BL=4, BR=8 → glyph. */
const QR_GLYPHS = [' ', '▘', '▝', '▀', '▖', '▌', '▞', '▛', '▗', '▚', '▐', '▜', '▄', '▙', '▟', '█'];

function qrPxDist(?array $a, ?array $b): float
{
    if ($a === null || $b === null) {
        return $a === $b ? 0.0 : 1e12;
    }
    return lkDist($a, $b);
}

/**
 * @param list<list<?array>> $px rgb|null pixels; odd sizes are padded with null
 * @return list<list<array{0:string,1:?array,2:?array}>>
 */
function qrRaster(array $px): array
{
    $h = count($px);
    $w = count($px[0]);
    $cells = [];
    for ($cy = 0; $cy < $h; $cy += 2) {
        $row = [];
        for ($cx = 0; $cx < $w; $cx += 2) {
            $q = [
                $px[$cy][$cx] ?? null, $px[$cy][$cx + 1] ?? null,
                $px[$cy + 1][$cx] ?? null, $px[$cy + 1][$cx + 1] ?? null,
            ];
            $row[] = qrCell($q);
        }
        $cells[] = $row;
    }
    return $cells;
}

/** @param array{0:?array,1:?array,2:?array,3:?array} $q TL,TR,BL,BR */
function qrCell(array $q): array
{
    $uniq = [];
    foreach ($q as $c) {
        $uniq[$c === null ? 'n' : implode(',', $c)] = $c;
    }
    $uniq = array_values($uniq);
    if (count($uniq) === 1) {
        return $uniq[0] === null ? [' ', null, null] : ['█', $uniq[0], null];
    }
    $best = null;
    $bestErr = INF;
    foreach ($uniq as $fg) {
        if ($fg === null) {
            continue;
        }
        foreach ($uniq as $bg) {
            if ($bg === $fg) {
                continue;
            }
            $bits = 0;
            $err = 0.0;
            foreach ($q as $i => $c) {
                $df = qrPxDist($c, $fg);
                $db = qrPxDist($c, $bg);
                if ($c === null) {
                    // transparent pixels must land on a null bg
                    $err += $bg === null ? 0.0 : 1e9;
                    continue;
                }
                if ($df <= $db) {
                    $bits |= 1 << $i;
                    $err += $df;
                } else {
                    $err += $db;
                }
            }
            if ($err < $bestErr) {
                $bestErr = $err;
                $best = [$bits, $fg, $bg];
            }
        }
    }
    [$bits, $fg, $bg] = $best;
    if ($bits === 15) {
        return ['█', $fg, $bg];
    }
    if ($bits === 0) {
        return [' ', null, $bg];
    }
    return [QR_GLYPHS[$bits], $fg, $bg];
}

/**
 * Per-cell count (0..4) of true pixels in a bool grid.
 * @param list<list<bool>> $mask
 * @return list<list<int>>
 */
function qrCoverage(array $mask): array
{
    $out = [];
    for ($cy = 0; $cy < count($mask); $cy += 2) {
        $row = [];
        for ($cx = 0; $cx < count($mask[0]); $cx += 2) {
            $row[] = (int) ($mask[$cy][$cx] ?? false) + (int) ($mask[$cy][$cx + 1] ?? false)
                + (int) ($mask[$cy + 1][$cx] ?? false) + (int) ($mask[$cy + 1][$cx + 1] ?? false);
        }
        $out[] = $row;
    }
    return $out;
}
