<?php

declare(strict_types=1);

/**
 * generate-watercolor-brush-bloom-skinny: the ≤40-column variant of
 * generate-watercolor-brush-bloom.php (same design, same paint engine):
 * the script is CONDENSED (SX/SY scale + narrower nib) onto one line on a
 * 40×8 black card, the btop-style core meters shrink to sub-pixel-wide
 * columns at the right, the area graph and stars are re-fitted.
 * Writes logo-watercolor-brush-bloom-skinny-<tc|256|16>-WxH.ansi via
 * skWriteSkinny(); 256/16 colours via spQuantise() (skinny-palette).
 *
 * Original header follows.
 *
 * generate-watercolor-brush-bloom: "candy top" hand-lettered in broad-nib
 * brush script and painted in watercolour on a deckle-edged paper card.
 *
 *  - Letterforms: centre-line paths (Catmull-Rom through key points, slight
 *    italic shear) swept by a BROAD NIB held at a fixed angle, so strokes go
 *    thick and thin like real calligraphy, with pressure tapers at the ends.
 *    The y's descender sweeps back under "cand" as a swash underline.
 *  - Paint: every element is a wash LAYER (mask + pigment field) glazed onto
 *    the black card as luminous ink (thin wash = paler glow, pooled = full
 *    colour). Per layer: wet-in-wet hue drift (noise-warped rainbow),
 *    a darker dried rim at the edges, cauliflower blooms (pale centre, hard
 *    dark ring), pigment granulation, and paper-grain texture.
 *  - Art beyond the wording: candy-top is a btop-style resource monitor, so
 *    a watercolour area graph rising from the bottom edge (painted
 *    trace lines, dashed grid) washes behind the lettering and a column of
 *    segmented per-core meters (green → red) stands at the right; plus
 *    gold sparkle stars and splatter flecks.
 *  - Raster: hi-res paint buffer → 3×3 averaged sub-pixels → best-fit
 *    sextant cells (sextant-image-raster.php); 256/16 quantise first.
 *
 * Usage:
 *   php generate-watercolor-brush-bloom-skinny.php           preview tc/256/16 + verify
 *   php generate-watercolor-brush-bloom-skinny.php --write   write .ansi + logos.jsonl (skWriteSkinny, no duplicate lines)
 *   php generate-watercolor-brush-bloom-skinny.php --png=DIR dump sub-pixel PNG + .ansi previews
 *   php generate-watercolor-brush-bloom-skinny.php --seed=N  other bloom/splatter/grain variant
 *
 * Reuse: GLYPHS (any centre-line script), NIB, PIGMENTS, the layer list in
 * scene(); paintLayer() and the brush sweep are generic.
 */

require_once __DIR__ . '/logo-kit.php';
require_once __DIR__ . '/sextant-canvas.php';
require_once __DIR__ . '/sextant-image-raster.php';
require_once __DIR__ . '/skinny-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'watercolor-brush-bloom-skinny';
const TEXT = 'candy top';
const SEED = 11;

/** Cells; physical units: x 1 = one cell width, y 2 = one cell height. */
const CW = 40;
const CH = 13;
const HS = 3;                 // hi-res samples per sub-pixel side

/** Script metrics (units, y down). */
const BASE = 13.6;            // baseline
const XH = 6.4;               // x-height line
const SLANT = 0.16;           // italic shear: x += (BASE - y) * SLANT
const TEXT_X = 0.9;           // left edge of "c" (world units)
/**
 * Skinny layout: "candy" stacked over "top" (indented so the y's swash and
 * the t's ascender clear each other). [text, world x of the line, world y of
 * glyph y=0, x scale, y scale].
 */
const LINES = [
    ['candy', TEXT_X, -0.25, 0.86, 0.82],
    ['top', 8.4, 11.1, 0.92, 0.80],
];
const WORD_GAP = 3.6;
const KERN = 0.2;

/** Broad nib: width (units), angle (deg), hairline radius, end taper fraction. */
const NIB = 1.85;
const NIB_ANGLE = 38;
const HAIR = 0.34;
const TAPER = 0.16;

/**
 * Glyphs: advance width + strokes; each stroke is key points (letter-local
 * units, y absolute) smoothed with Catmull-Rom.
 */
const GLYPHS = [
    'c' => [6.0, [[[4.9, 8.0], [3.6, 6.5], [1.7, 7.2], [0.8, 10.2], [1.6, 13.3], [3.5, 13.8], [5.6, 12.4]]]],
    'a' => [6.8, [
        [[5.0, 7.5], [3.3, 6.5], [1.4, 7.7], [0.9, 10.7], [2.0, 13.7], [3.8, 12.9], [5.0, 9.6]],
        [[5.3, 6.5], [5.0, 11.4], [5.4, 13.8], [6.8, 12.8]],
    ]],
    'n' => [7.0, [
        [[0.4, 7.6], [1.3, 6.5], [1.3, 13.8]],
        [[1.3, 10.4], [2.5, 7.2], [4.1, 6.5], [5.0, 7.8], [4.9, 11.8], [5.4, 13.8], [6.9, 12.8]],
    ]],
    'd' => [7.0, [
        [[5.0, 7.5], [3.3, 6.5], [1.4, 7.7], [0.9, 10.7], [2.0, 13.7], [3.8, 12.9], [5.0, 9.6]],
        [[5.8, 1.4], [5.1, 11.4], [5.5, 13.8], [6.9, 12.8]],
    ]],
    'y' => [6.6, [
        [[0.3, 7.4], [1.1, 6.5], [1.0, 11.3], [2.3, 13.7], [3.9, 13.2], [5.0, 10.4]],
        // descender swashes back under the word as an underline flourish
        // skinny: a short curl only (the p below carries the swash)
        [[5.2, 6.5], [5.0, 14.4], [4.0, 16.6], [2.2, 17.2], [0.8, 16.5]],
    ]],
    't' => [5.6, [
        [[2.8, 2.4], [2.3, 11.6], [3.0, 13.8], [4.8, 12.7]],
        [[0.2, 6.9], [2.8, 6.5], [5.4, 6.3]],
    ]],
    'o' => [6.6, [[[3.5, 6.5], [1.5, 7.6], [0.9, 10.5], [1.9, 13.5], [3.6, 13.9], [5.2, 12.4], [5.4, 9.0], [4.5, 6.9], [3.1, 6.7], [4.6, 6.3], [6.4, 6.9]]]],
    'p' => [6.6, [
        // skinny: the p's descender takes over the swash, sweeping back under "to"
        [[0.4, 7.5], [1.4, 6.5], [1.2, 16.2], [0.2, 17.4], [-4.0, 17.5], [-10.0, 17.2], [-13.6, 17.5]],
        [[1.3, 8.8], [2.8, 6.8], [4.6, 6.9], [5.6, 9.8], [5.0, 13.0], [3.0, 13.9], [1.4, 12.6]],
    ]],
];

/** Watercolour pigments, swept left→right across the lettering (wet-in-wet). */
const PIGMENTS = ['#E5197F', '#FF4F2E', '#FF9F0F', '#F2C500', '#3DBE3A', '#00A9C2', '#2F64F0', '#8C3BE0', '#E5197F'];

const PAPER = '#070708';     // black card (user: background black); texture is a faint hint only
const DECKLE = 0.32;          // deckle-edge raggedness (units)

/** Wash behaviour. */
const WASH = ['gloss' => 0.0, 'alpha' => 1.0, 'conc' => 0.62, 'rim' => 0.42, 'rimW' => 0.42, 'gran' => 0.13, 'flow' => 0.10, 'blooms' => 7];

/** 16-colour: paper → bright white; pigment hue buckets. */
const PAPER16 = 30;

const DESCRIPTION = 'Skinny 40x13 variant of watercolor-brush-bloom: "candy" stacked over "top", condensed watercolour hand-lettering on black: lowercase "candy top" in slanted broad-nib brush script (thick/thin strokes, tapered ends, the y swashing back as an underline) painted wet-in-wet in a rainbow of transparent pigments with darker dried rims, cauliflower blooms and granulation, glowing on a black card over a translucent watercolour area graph (painted trace line and dashed grid, like the btop net/cpu panels) with five sub-pixel-wide segmented green-to-red per-core meters at the right, sparkle stars and splatter. Sextant mosaic.';
const TAGS = ['skinny', 'watercolor', 'brush-script', 'broad-nib-calligraphy', 'wet-in-wet', 'black-background', 'area-graph', 'core-meters', 'sparkles', 'sextant-mosaic'];

// ================================================================ HI-RES BUFFER

/** Hi-res buffer size in samples. */
function hw(): int
{
    return CW * 2 * HS;
}
function hh(): int
{
    return CH * 3 * HS;
}
/** Sample (ix, iy) → physical units (centre). */
function hx(int $ix): float
{
    return ($ix + 0.5) / (2 * HS);
}
function hy(int $iy): float
{
    return ($iy + 0.5) * 2 / (3 * HS);
}

/** Smooth value noise in [0,1]. */
function vn(float $x, float $y, float $scale, int $seed): float
{
    $x /= $scale;
    $y /= $scale;
    $xi = (int) floor($x);
    $yi = (int) floor($y);
    $fx = $x - $xi;
    $fy = $y - $yi;
    $fx = $fx * $fx * (3 - 2 * $fx);
    $fy = $fy * $fy * (3 - 2 * $fy);
    $a = lkNoise($xi, $yi, $seed);
    $b = lkNoise($xi + 1, $yi, $seed);
    $c = lkNoise($xi, $yi + 1, $seed);
    $d = lkNoise($xi + 1, $yi + 1, $seed);
    return ($a + ($b - $a) * $fx) * (1 - $fy) + ($c + ($d - $c) * $fx) * $fy;
}

/** Segment distance. */
function sd(float $px, float $py, float $x0, float $y0, float $x1, float $y1): float
{
    $dx = $x1 - $x0;
    $dy = $y1 - $y0;
    $l2 = $dx * $dx + $dy * $dy;
    $t = $l2 > 0 ? max(0.0, min(1.0, (($px - $x0) * $dx + ($py - $y0) * $dy) / $l2)) : 0.0;
    return hypot($px - $x0 - $t * $dx, $py - $y0 - $t * $dy);
}

// ================================================================ BRUSH

/**
 * Sweep a broad nib along a smoothed path into a mask.
 * @param array<int, array<int, bool>> $mask hi-res mask, filled in place
 * @param float $nib nib width; $angle nib angle (deg); $taper end taper fraction
 * @param float $reveal 0..1 — paint only the first fraction (animation)
 */
function brush(array &$mask, array $pts, float $nib, float $angle, float $taper, float $reveal = 1.0): void
{
    $path = scCatmull($pts, 14);
    $len = [0.0];
    for ($i = 1; $i < count($path); $i++) {
        $len[$i] = $len[$i - 1] + hypot($path[$i][0] - $path[$i - 1][0], $path[$i][1] - $path[$i - 1][1]);
    }
    $total = end($len) ?: 1.0;
    $a = deg2rad($angle);
    $ux = cos($a);
    $uy = -sin($a);
    $W = hw();
    $H = hh();
    $step = 0.06;
    for ($s = 0.0; $s <= $total * $reveal; $s += $step) {
        // locate s on the polyline
        $i = 1;
        while ($i < count($len) - 1 && $len[$i] < $s) {
            $i++;
        }
        $seg = max(1e-9, $len[$i] - $len[$i - 1]);
        $f = ($s - $len[$i - 1]) / $seg;
        $px = $path[$i - 1][0] + ($path[$i][0] - $path[$i - 1][0]) * $f;
        $py = $path[$i - 1][1] + ($path[$i][1] - $path[$i - 1][1]) * $f;
        $u = $s / $total;
        $press = min(1.0, min($u, 1 - $u) / $taper);
        $press = 0.25 + 0.75 * sin(min(1.0, $press) * M_PI / 2);
        $half = $nib * $press / 2;
        $r = HAIR * (0.6 + 0.4 * $press);
        $x0 = $px - $ux * $half;
        $y0 = $py - $uy * $half;
        $x1 = $px + $ux * $half;
        $y1 = $py + $uy * $half;
        $ixa = max(0, (int) floor((min($x0, $x1) - $r) * 2 * HS));
        $ixb = min($W - 1, (int) ceil((max($x0, $x1) + $r) * 2 * HS));
        $iya = max(0, (int) floor((min($y0, $y1) - $r) * 1.5 * HS));
        $iyb = min($H - 1, (int) ceil((max($y0, $y1) + $r) * 1.5 * HS));
        for ($iy = $iya; $iy <= $iyb; $iy++) {
            for ($ix = $ixa; $ix <= $ixb; $ix++) {
                if (!isset($mask[$iy][$ix]) && sd(hx($ix), hy($iy), $x0, $y0, $x1, $y1) <= $r) {
                    $mask[$iy][$ix] = true;
                }
            }
        }
    }
}

/** Fill an arbitrary region given by a predicate f(x, y) → bool. */
function fillShape(array &$mask, callable $inside, array $bbox): void
{
    [$xa, $ya, $xb, $yb] = $bbox;
    for ($iy = max(0, (int) floor($ya * 1.5 * HS)); $iy <= min(hh() - 1, (int) ceil($yb * 1.5 * HS)); $iy++) {
        for ($ix = max(0, (int) floor($xa * 2 * HS)); $ix <= min(hw() - 1, (int) ceil($xb * 2 * HS)); $ix++) {
            if ($inside(hx($ix), hy($iy))) {
                $mask[$iy][$ix] = true;
            }
        }
    }
}

// ================================================================ PAINT

/**
 * Glaze one wash layer onto the paint buffer (multiply).
 * @param callable(float $x, float $y): array $pigment rgb at a point
 * @param array $opt overrides of WASH
 */
function paintLayer(array &$buf, array $mask, callable $pigment, int $seed, array $opt = []): void
{
    $o = $opt + WASH;
    if (!$mask) {
        return;
    }
    // edge distance (units) within a small window → dried rim
    $rimW = $o['rimW'];
    $wx = (int) ceil($rimW * 2 * HS);
    $wy = (int) ceil($rimW * 1.5 * HS);
    // blooms: seeded inside the mask
    $cells = [];
    foreach ($mask as $iy => $row) {
        foreach ($row as $ix => $_) {
            $cells[] = [$ix, $iy];
        }
    }
    $blooms = [];
    for ($b = 0; $b < $o['blooms']; $b++) {
        [$bx, $by] = $cells[(int) (lkNoise($b, 77, $seed) * count($cells))];
        $blooms[] = [hx($bx), hy($by), 0.8 + 1.1 * lkNoise($b, 78, $seed)];
    }
    foreach ($mask as $iy => $row) {
        foreach ($row as $ix => $_) {
            $x = hx($ix);
            $y = hy($iy);
            $edge = $rimW;
            for ($dy = -$wy; $dy <= $wy; $dy++) {
                for ($dx = -$wx; $dx <= $wx; $dx++) {
                    if (!isset($mask[$iy + $dy][$ix + $dx])) {
                        $e = hypot($dx / (2 * HS), $dy / (1.5 * HS));
                        if ($e < $edge) {
                            $edge = $e;
                        }
                    }
                }
            }
            $conc = $o['conc'];
            $conc += $o['rim'] * (1 - $edge / $rimW) ** 2;                      // dried rim
            $conc += $o['flow'] * (vn($x, $y, 3.2, $seed + 5) - 0.5) * 2;        // uneven flow
            $conc += $o['gran'] * (lkNoise($ix, $iy, $seed + 9) - 0.5) * 2;      // granulation
            foreach ($blooms as [$bx, $by, $br]) {
                $q = hypot($x - $bx, ($y - $by) * 0.8) / $br;
                if ($q < 1.15) {
                    $conc += $q < 1 ? -0.30 * (1 - $q) : 0.0;
                    $conc += exp(-((($q - 1.0) / 0.08) ** 2)) * 0.30;               // hard bloom edge
                }
            }
            $conc = max(0.12, min(1.15, $conc));
            $p = $pigment($x, $y);
            $cur = $buf[$iy][$ix];
            // luminous ink on dark paper: thin wash = paler, glowing tint;
            // pooled pigment (rims, bloom rings) = full saturated colour
            $thin = max(0.0, min(1.0, (1.0 - $conc) / 0.6));
            $col = lkMix($p, [255, 255, 255], 0.16 * $thin);
            $col = lkScale($col, 0.84 + 0.20 * min(1.0, $conc));
            // wet gloss: the upper-left edge of a stroke catches the light
            if ($o['gloss'] > 0) {
                $gx = (int) round(-0.42 * 2 * HS);
                $gy = (int) round(-0.42 * 1.5 * HS);
                if (!isset($mask[$iy + $gy][$ix + $gx]) && $edge > 0.12) {
                    $col = lkMix($col, [255, 255, 255], $o['gloss']);
                }
                if (!isset($mask[$iy - $gy][$ix - $gx]) && $edge > 0.1) {
                    $col = lkScale($col, 0.78);   // lower-right shade side
                }
            }
            $alpha = min(1.0, $o['alpha'] * (0.62 + 0.45 * min(1.0, $conc)));
            $buf[$iy][$ix] = [
                $cur[0] + ($col[0] - $cur[0]) * $alpha,
                $cur[1] + ($col[1] - $cur[1]) * $alpha,
                $cur[2] + ($col[2] - $cur[2]) * $alpha,
            ];
        }
    }
}

// ================================================================ SCENE

/** Glyph strokes laid out in world units, sheared to italic. */
function textStrokes(): array
{
    $out = [];
    foreach (LINES as [$text, $x0, $oy, $sx, $sy]) {
        $x = 0.0;
        foreach (str_split($text) as $ch) {
            [$w, $strokes] = GLYPHS[$ch];
            foreach ($strokes as $st) {
                $out[] = array_map(static fn (array $p): array => [$x0 + ($x + $p[0] + (BASE - $p[1]) * SLANT) * $sx, $oy + $p[1] * $sy], $st);
            }
            $x += $w + KERN;
        }
    }
    return $out;
}

/** Background graph: x span, baselines, peak heights (units), wash alpha. */
const GRAPH = ['x0' => 0.5, 'x1' => 33.4, 'down' => 25.6, 'up' => 0.4, 'hDown' => 6.0, 'hUp' => 3.4, 'alpha' => 0.22, 'line' => 0.65];
/** World y separating the two lines' rainbow slices. */
const HUE_SPLIT = 13.4;
/** Per-core meters at the right: x of first bar, bar width, gap, top, bottom, segment pitch. */
const METERS = ['x' => 35.0, 'w' => 0.5, 'gap' => 0.5, 'n' => 5, 'top' => 2.0, 'bot' => 24.0, 'seg' => 1.3334, 'lit' => 0.6667];
const METER_RAMP = ['#3DBE3A', '#9BD62C', '#F2C500', '#FF9F0F', '#FF4F2E', '#E5197F'];

/** Seeded "traffic" series in 0..1 (smooth, bursty like real net/cpu load). */
function series(float $x, int $seed): float
{
    $v = 0.55 * vn($x, 0, 5.5, $seed) + 0.30 * vn($x, 3, 1.9, $seed + 1) + 0.15 * vn($x, 7, 0.8, $seed + 2);
    return max(0.03, min(1.0, ($v - 0.28) * 1.9));
}

/**
 * Area graph (btop net panel style): load grows up from the bottom edge
 * behind the lettering (add ['up', 'hUp', -1, seed] for a mirrored pair). Translucent wash + brighter
 * painted trace line on each, plus faint dashed grid lines.
 */
function graphs(array &$buf, int $seed, callable $hue, float $extras, bool $flat = false): void
{
    $g = GRAPH;
    $grid = [];
    foreach ([6.0, 12.0, 18.0, 24.0] as $gy) {
        fillShape($grid, static fn ($x, $y) => abs($y - $gy) < 0.2 && fmod($x, 2.0) < 1.0, [$g['x0'], $gy - 0.3, $g['x1'], $gy + 0.3]);
    }
    paintLayer($buf, $grid, static fn () => lkHex('#3A3F66'), $seed + 20, ['alpha' => 0.55 * $extras, 'conc' => 0.5, 'rim' => 0.0, 'gran' => 0.0, 'blooms' => 0]);
    foreach ([['down', 'hDown', 1, $seed + 21]] as [$base, $hk, $dir, $sd]) {
        $yb = $g[$base];
        $h = $g[$hk];
        $top = static fn (float $x): float => $yb - $dir * $h * series($x, $sd);
        $area = [];
        fillShape($area, static function ($x, $y) use ($top, $yb, $dir): bool {
            $t = $top($x);
            return $dir > 0 ? ($y >= $t && $y <= $yb) : ($y <= $t && $y >= $yb);
        }, [$g['x0'], 0, $g['x1'], CH * 2]);
        // pigment pools toward the trace: the wash fades toward the baseline
        // (16 colours cannot do a translucent wash: trace line only)
        $flat || paintLayer($buf, $area, static function ($x, $y) use ($hue, $top, $h): array {
            $f = min(1.0, abs($y - $top($x)) / $h);
            return lkScale($hue($x, $y), 1.0 - 0.55 * $f);
        }, $sd + 100, ['alpha' => $g['alpha'] * $extras, 'conc' => 0.55, 'rim' => 0.25, 'blooms' => 3]);
        $pts = [];
        for ($x = $g['x0']; $x <= $g['x1']; $x += 0.5) {
            $pts[] = [$x, $top($x)];
        }
        $line = [];
        brush($line, $pts, 0.55, 30, 0.02);
        paintLayer($buf, $line, static fn ($x, $y) => lkMix($hue($x, $y), [255, 255, 255], 0.15), $sd + 200, ['alpha' => $g['line'] * $extras, 'conc' => 0.85, 'rim' => 0.1, 'blooms' => 0]);
    }
}

/** btop-style per-core meters: segmented vertical bars, green → red by height. */
function meters(array &$buf, int $seed, float $extras, bool $flat = false): void
{
    $m = METERS;
    $ramp = array_map('lkHex', METER_RAMP);
    $span = $m['bot'] - $m['top'];
    for ($i = 0; $i < $m['n']; $i++) {
        $x0 = $m['x'] + $i * ($m['w'] + $m['gap']);
        $level = 0.3 + 0.68 * lkNoise($i, 5, $seed + 3);
        $y0 = $m['bot'] - $span * $level;
        // dim track for the whole bar, then the lit segments
        $track = [];
        fillShape($track, static fn ($x, $y) => fmod($y - $m['top'], $m['seg']) < $m['lit'], [$x0, $m['top'], $x0 + $m['w'], $m['bot']]);
        // 16 colours: no dim track (lit/track/gap in one cell breaks the fit)
        $flat || paintLayer($buf, $track, static fn () => lkHex('#1C1F36'), $seed + 300 + $i, ['alpha' => 0.7 * $extras, 'conc' => 0.6, 'rim' => 0.0, 'gran' => 0.03, 'blooms' => 0]);
        $lit = [];
        fillShape($lit, static fn ($x, $y) => $y >= $y0 && ($flat || fmod($y - $m['top'], $m['seg']) < $m['lit']), [$x0, $m['top'], $x0 + $m['w'], $m['bot']]);
        paintLayer($buf, $lit, static fn ($x, $y) => lkGradient($ramp, ($m['bot'] - $y) / $span), $seed + 320 + $i, ['alpha' => $extras, 'conc' => 0.9, 'rim' => 0.2, 'gran' => 0.05, 'blooms' => 0]);
    }
}

/** 4-point sparkle star. */
function sparkle(float $x, float $y, float $cx, float $cy, float $r): bool
{
    $dx = abs($x - $cx) / $r;
    $dy = abs($y - $cy) / $r;
    return $dx ** 0.5 + $dy ** 0.5 <= 1.0;
}

const STARS = [[3.8, 17.4, 1.9, '#FFB000'], [30.2, 18.6, 1.0, '#FF4F9A'], [1.6, 22.6, 0.7, '#00A9C2']];

/**
 * Paint the whole card. $reveal: 0..1 progress of the lettering (animation);
 * $extras: draw the toy/stars/splatter.
 * @return list<list<?array>> sub-pixel image
 */
function paint(int $seed, float $reveal = 1.0, float $extras = 1.0, bool $flat = false): array
{
    $W = hw();
    $H = hh();
    $paper = lkHex(PAPER);
    $buf = [];
    for ($iy = 0; $iy < $H; $iy++) {
        for ($ix = 0; $ix < $W; $ix++) {
            $g = 1 + 0.12 * vn(hx($ix), hy($iy), 0.35, $seed + 1) + 0.20 * vn(hx($ix), hy($iy), 3.5, $seed + 2);
            $buf[$iy][$ix] = [$paper[0] * $g, $paper[1] * $g, $paper[2] * $g];
        }
    }
    $rainbow = array_map('lkHex', PIGMENTS);
    $textEnd = 0.0;
    foreach (textStrokes() as $st) {
        foreach ($st as $p) {
            $textEnd = max($textEnd, $p[0]);
        }
    }
    $hue = static function (float $x, float $y) use ($rainbow, $seed, $textEnd): array {
        // skinny: each stacked line carries its own slice of the rainbow
        $t = ($y < HUE_SPLIT || $x > 26.6)   // the y's curl dips below the split
            ? 0.02 + 0.56 * ($x - TEXT_X) / 31.0
            : 0.60 + 0.40 * ($x - 8.4) / 17.0;
        $t += 0.10 * (vn($x, $y, 4.0, $seed + 13) - 0.5);   // wet-in-wet drift
        return lkGradient($rainbow, max(0.0, min(1.0, $t)) * 0.92);
    };

    // system-monitor art (candy-top is a btop-style resource monitor):
    // a mirrored watercolour net/CPU area graph washed behind the lettering,
    // and a column of segmented per-core meters off to the right
    if ($extras > 0) {
        graphs($buf, $seed, $hue, $extras, $flat);
        meters($buf, $seed, $extras, $flat);
        foreach (STARS as $k => [$cx, $cy, $r, $hex]) {
            $m = [];
            fillShape($m, static fn ($x, $y) => sparkle($x, $y, $cx, $cy, $r), [$cx - $r, $cy - $r, $cx + $r, $cy + $r]);
            paintLayer($buf, $m, static fn () => lkHex($hex), $seed + 40 + $k, ['alpha' => $extras, 'conc' => 0.7, 'blooms' => 0, 'rim' => 0.3]);
        }
        // splatter flecks
        $spl = [];
        for ($i = 0, $n = 0; $i < 400 && $n < 9; $i++) {
            $cx = 1.5 + lkNoise($i, 51, $seed) * (CW - 3);
            $cy = 1.0 + lkNoise($i, 52, $seed) * (CH * 2 - 2);
            $near = ($cx > 1.0 && $cx < 33 && $cy > 0.5 && $cy < 15) || ($cx > 7.5 && $cx < 28 && $cy > 10) || $cx > 34;
            if ($near && lkNoise($i, 53, $seed) < 0.75) {
                continue;
            }
            $r = 0.2 + 0.2 * lkNoise($i, 54, $seed) ** 2;
            $spl[] = [$cx, $cy, $r];
            $n++;
        }
        $m = [];
        foreach ($spl as [$cx, $cy, $r]) {
            fillShape($m, static fn ($x, $y) => hypot($x - $cx, ($y - $cy) * 0.9) <= $r, [$cx - $r, $cy - $r, $cx + $r, $cy + $r]);
        }
        paintLayer($buf, $m, $hue, $seed + 60, ['alpha' => $extras, 'conc' => 0.8, 'blooms' => 0, 'rim' => 0.2]);
    }

    // the lettering — one wet wash, so strokes merge where they cross
    $ink = [];
    $strokes = textStrokes();
    $n = count($strokes);
    foreach ($strokes as $k => $st) {
        $r = max(0.0, min(1.0, $reveal * $n - $k));
        if ($r > 0) {
            brush($ink, $st, NIB, NIB_ANGLE, TAPER, $r);
        }
    }
    // soft pigment halo bleeding into the dark card, so the ink glows
    $halo = [];
    foreach ($strokes as $k => $st) {
        $r = max(0.0, min(1.0, $reveal * $n - $k));
        if ($r > 0) {
            brush($halo, $st, NIB + 1.1, NIB_ANGLE, TAPER, $r);
        }
    }
    // black keyline first: the background graph never touches a letter
    paintLayer($buf, $halo, static fn () => [0, 0, 0], $seed + 68, ['alpha' => 0.92, 'conc' => 1.0, 'rim' => 0.0, 'gran' => 0.0, 'flow' => 0.0, 'blooms' => 0]);
    $flat || paintLayer($buf, $halo, $hue, $seed + 69, ['alpha' => 0.22, 'conc' => 0.3, 'rim' => 0.0, 'gran' => 0.05, 'blooms' => 0]);
    paintLayer($buf, $ink, $hue, $seed + 70, ['gloss' => $flat ? 0.0 : 0.42] + ($flat ? ['gran' => 0.0, 'flow' => 0.0] : []));

    // deckle-edged card: outside → transparent
    $img = siSupersample(CW * 2, CH * 3, static fn () => null, 1);
    for ($y = 0; $y < CH * 3; $y++) {
        for ($x = 0; $x < CW * 2; $x++) {
            $acc = [0.0, 0.0, 0.0];
            for ($j = 0; $j < HS; $j++) {
                for ($i = 0; $i < HS; $i++) {
                    $c = $buf[$y * HS + $j][$x * HS + $i];
                    $acc[0] += $c[0];
                    $acc[1] += $c[1];
                    $acc[2] += $c[2];
                }
            }
            $ux = ($x + 0.5) / 2;
            $uy = ($y + 0.5) * 2 / 3;
            $dk = DECKLE * vn($ux * 3.1 + $uy, $uy * 3.1 + $ux, 0.9, $seed + 90);
            $in = $ux > 0.3 + $dk && $ux < CW - 0.3 - $dk && $uy > 0.25 + $dk && $uy < CH * 2 - 0.25 - $dk;
            $img[$y][$x] = $in ? array_map(static fn ($v) => (int) max(0, min(255, round($v / (HS * HS)))), $acc) : null;
        }
    }
    return $img;
}

// ================================================================ DEPTH

/** 16-colour picker: paper stays white; pigment by hue, deep → normal, light → bright. */
function map16(array $c): array
{
    $a = lkAnsi16();
    [$r, $g, $b] = $c;
    $max = max($c);
    $min = min($c);
    if ($max < 90) {
        return $a[PAPER16];
    }
    return $a[lkTo16($c)];
}

function render(string $mode, int $seed, float $reveal = 1.0, float $extras = 1.0): array
{
    $img = paint($seed, $reveal, $extras, $mode === '16');
    $q = match ($mode) {
        'tc' => $img,
        '256' => spQuantise($img, '256'),
        '16' => spQuantise($img, '16'),
    };
    return [siCells($q, $mode !== 'tc'), $img];
}

// ================================================================ CLI

if (realpath($_SERVER['argv'][0] ?? '') !== __FILE__) {
    return;
}
$write = in_array('--write', $argv, true);
$png = null;
$seed = SEED;
foreach ($argv as $a) {
    if (str_starts_with($a, '--png=')) {
        $png = substr($a, 6);
    }
    if (str_starts_with($a, '--seed=')) {
        $seed = (int) substr($a, 7);
    }
}
$dir = dirname(__DIR__);
foreach (['tc', '256', '16'] as $mode) {
    [$cells, $img] = render($mode, $seed);
    $enc = lkEncode($cells, $mode, [], 'nearest');
    [$w, $h] = lkVerify($enc, $mode);
    lkCheckDepth($enc, $mode);
    echo $enc;
    fwrite(STDERR, "[$mode] {$w}x{$h} ok\n");
    if ($png !== null) {
        siImagePng($img, "$png/img-$mode.png");
        file_put_contents("$png/$mode.ansi", $enc);
    }
    if ($write) {
        $r = skWriteSkinny($dir, SLUG, $mode, $enc, DESCRIPTION . " ($mode)", TAGS);
        fwrite(STDERR, "  wrote {$r['file']} (jsonl: {$r['jsonl']})\n");
    }
}
