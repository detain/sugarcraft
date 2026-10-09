<?php

declare(strict_types=1);

/**
 * glyph-sprite — place single TTF glyphs anywhere on a sample grid with
 * their own size, rotation, horizontal condense and centre. Unlike a
 * whole-string TTF fit, each letter is an independent sprite, which is what
 * graffiti / bouncy / tumbling lettering needs (staggered baselines, tilted
 * letters that overlap each other).
 *
 * Geometry is in SQUARE units: a terminal cell is 1 wide, 2 tall. A sample
 * grid with sx samples per cell column and sy samples per cell row has
 * sample (i, j) centred at X = (i + .5) / sx, Y = (j + .5) * 2 / sy.
 *
 *   $s   = gsLoad('/path/font.ttf', 'T');                    // cached raster
 *   $cov = gsCoverage($s, ['x' => 54, 'y' => 9, 'h' => 13, 'rot' => -6,
 *                          'condense' => 0.9], 160, 20, 2, 2, ss: 3);
 *   // $cov[j][i] = 0..1 coverage on a 160×20 quadrant-resolution grid
 *
 * Placement keys: x, y = ink-box centre (units); h = ink height (units);
 * rot = degrees, positive = clockwise on screen; condense = x scale factor.
 */

/** Rasterise one glyph at a large size; returns [img, inkX0, inkY0, inkX1, inkY1]. */
function gsLoad(string $font, string $char, float $size = 220.0): array
{
    static $cache = [];
    $key = "$font|$char|$size";
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $w = (int) ($size * 2.2);
    $h = (int) ($size * 2.0);
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, 0);
    imagettftext($im, $size, 0, (int) ($size * 0.4), (int) ($size * 1.45), 0xFFFFFF, $font, $char);
    $x0 = $w;
    $y0 = $h;
    $x1 = 0;
    $y1 = 0;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ((imagecolorat($im, $x, $y) & 0xFF) > 100) {
                $x0 = min($x0, $x);
                $x1 = max($x1, $x + 1);
                $y0 = min($y0, $y);
                $y1 = max($y1, $y + 1);
            }
        }
    }
    return $cache[$key] = [$im, $x0, $y0, $x1, $y1];
}

/** Coverage (0..1) of a placed sprite at one point in units. */
function gsSample(array $s, array $p, float $X, float $Y): float
{
    [$im, $x0, $y0, $x1, $y1] = $s;
    $k = ($y1 - $y0) / $p['h'];                // image px per unit
    $cond = $p['condense'] ?? 1.0;
    $a = deg2rad(-($p['rot'] ?? 0.0));         // inverse rotation
    $dx = $X - $p['x'];
    $dy = $Y - $p['y'];
    $rx = $dx * cos($a) - $dy * sin($a);
    $ry = $dx * sin($a) + $dy * cos($a);
    $ix = (int) (($x0 + $x1) / 2 + $rx * $k / $cond);
    $iy = (int) (($y0 + $y1) / 2 + $ry * $k);
    if ($ix < 0 || $iy < 0 || $ix >= imagesx($im) || $iy >= imagesy($im)) {
        return 0.0;
    }
    return (imagecolorat($im, $ix, $iy) & 0xFF) / 255;
}

/**
 * Coverage grid for a placed sprite.
 * @param int $sx samples per cell column, $sy samples per cell row
 * @return list<list<float>>
 */
function gsCoverage(array $s, array $p, int $gridW, int $gridH, int $sx, int $sy, int $ss = 3): array
{
    $out = array_fill(0, $gridH, array_fill(0, $gridW, 0.0));
    $uw = 1.0 / $sx;
    $uh = 2.0 / $sy;
    $r = $p['h'] * 0.9 + 2;                    // cheap bounding radius
    for ($j = 0; $j < $gridH; $j++) {
        for ($i = 0; $i < $gridW; $i++) {
            $cx = ($i + 0.5) * $uw;
            $cy = ($j + 0.5) * $uh;
            if (abs($cx - $p['x']) > $r || abs($cy - $p['y']) > $r) {
                continue;
            }
            $sum = 0.0;
            for ($a = 0; $a < $ss; $a++) {
                for ($b = 0; $b < $ss; $b++) {
                    $sum += gsSample($s, $p, ($i + ($b + 0.5) / $ss) * $uw, ($j + ($a + 0.5) / $ss) * $uh);
                }
            }
            $out[$j][$i] = $sum / ($ss * $ss);
        }
    }
    return $out;
}
