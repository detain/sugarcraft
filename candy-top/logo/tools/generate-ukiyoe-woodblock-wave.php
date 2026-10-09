<?php

declare(strict_types=1);

/**
 * generate-ukiyoe-woodblock-wave (v2) — candy-top logo: Hokusai's Great Wave
 * at night, with bubbly candy-coloured CANDY and a graffiti-piece TOP.
 *
 * Geometry lives in SQUARE units: X = cell columns (0..80), Y = half rows
 * (0..20). The scene is sampled on a 160×20 grid (2×2 samples per cell) and
 * packed into quadrant blocks by quadrant-raster.php; glyph cells (sparkles)
 * are overlaid afterwards.
 *
 * Layers, back → front:
 *   night sky (gradient + star specks) → moon with halo → great-wave curl
 *   (Catmull-Rom centre-line + radius → striated bands, foam crest, foam
 *   claws) → rolling swell (depth bands, crest foam) → CANDY (Lato Black,
 *   one candy colour per letter, gloss bevel, dark keyline, soft glow) →
 *   TOP graffiti piece (Lato Black Italic sprites, each letter tilted and
 *   staggered; split two-tone fade fill, glitter, top-left shine bevel,
 *   violet 3D extrusion, black keyline, white outer "cloud" outline, drips,
 *   arrow) → glyph sparkles (✦ ⋆ ·) on foam, letters and sky.
 *
 * Colour depth: every paint call names a ROLE. tc uses the rgb; 256 pins the
 * background/wave roles via ROLE256 (nearest-256 turned the navy sea grey)
 * and maps the vivid letter gradients nearest; 16 uses ROLE16 for everything.
 *
 *   php generate-ukiyoe-woodblock-wave.php              preview + verify
 *   php generate-ukiyoe-woodblock-wave.php --write      write .ansi + logos.jsonl
 *   php generate-ukiyoe-woodblock-wave.php --out=<dir>  write .ansi only (scratch)
 *   php logo-play.php ../logo-ukiyoe-woodblock-wave-anim-tc-80x10.ansi 230
 */

require __DIR__ . '/logo-kit.php';
require __DIR__ . '/quadrant-raster.php';
require __DIR__ . '/glyph-sprite.php';

// ================================================================ DESIGN DATA

const SLUG = 'ukiyoe-woodblock-wave';
const COLS = 80;
const ROWS = 10;
const PW = COLS * 2;
const PH = ROWS * 2;
const SEED = 1831;

const FONT_CANDY = '/usr/share/fonts/truetype/lato/Lato-Black.ttf';
const FONT_TOP = '/usr/share/fonts/truetype/lato/Lato-BlackItalic.ttf';

/** CANDY: one sprite per letter [char, centre X, centre Y, height, rot, condense, colour]. */
const CANDY = [
    ['C', 17.3, 9.9, 10.4, -4, 0.86, '#FF4FA8'],
    ['A', 24.1, 9.5, 10.4, 3, 0.86, '#FF9F2E'],
    ['N', 30.9, 10.0, 10.4, -3, 0.86, '#FFE53B'],
    ['D', 37.7, 9.4, 10.4, 4, 0.86, '#5CF07A'],
    ['Y', 44.3, 9.9, 10.4, -3, 0.86, '#3FD4FF'],
];

/** Halo strength of each CANDY letter's colour around it (0 = off; muddy at 2×2 res). */
const CANDY_GLOW = 0.0;

/** TOP graffiti: [char, centre X, centre Y, height, rot]. */
const TOP = [
    ['T', 53.4, 7.6, 12.2, -7],
    ['O', 62.6, 8.6, 12.2, 5],
    ['P', 71.4, 7.4, 12.2, -5],
];
const TOP_EXTRUDE = [1.3, 1.9];     // 3D extrusion vector (units, down-right)
const TOP_FADE = ['#FFF46B', '#FFB21F', '#FF3D8B', '#C51BD9'];   // top → bottom across the split
const TOP_EXTR = ['#7A33E8', '#2E0E70'];
/** Drips under TOP: [X, length]. */
const DRIPS = [[51.6, 2.4], [56.0, 1.6], [61.0, 2.8], [65.2, 1.5], [70.0, 2.2]];

const PALETTE = [
    'skyTop' => '#03050C', 'skyLow' => '#0B1734', 'star' => '#CFE6FF', 'moon' => '#FFF0BE', 'halo' => '#3A4A6E',
    'key' => '#020409', 'prussian' => '#0C2E66', 'deep' => '#061633', 'mid' => '#1E66C8', 'aqua' => '#33C2F0',
    'stripe' => '#9BEBFF', 'foam' => '#F4FBFF', 'glint' => '#FFFFFF',
    'letterKey' => '#0A0514', 'cloud' => '#FFFFFF', 'shine' => '#FFFFFF', 'glitter' => '#FFFFFF',
];

/** 256 pins for the flat scene roles (letters are vivid → nearest works). */
const ROLE256 = [
    'skyTop' => 16, 'skyLow' => 17, 'star' => 189, 'moon' => 229, 'halo' => 60, 'key' => 16,
    'prussian' => 18, 'deep' => 17, 'mid' => 26, 'aqua' => 39, 'stripe' => 123, 'foam' => 231,
    'glint' => 231, 'letterKey' => 16, 'cloud' => 231, 'shine' => 231, 'glitter' => 231,
    'extr0' => 92, 'extr1' => 54,
];

/** 16-colour role map (fg code; bg = +10). */
const ROLE16 = [
    'skyTop' => 30, 'skyLow' => 30, 'star' => 97, 'moon' => 93, 'halo' => 30, 'key' => 30,
    'prussian' => 34, 'deep' => 34, 'mid' => 94, 'aqua' => 96, 'stripe' => 96, 'foam' => 97, 'glint' => 97,
    'letterKey' => 30, 'cloud' => 97, 'shine' => 97, 'glitter' => 97,
    'candy0' => 95, 'candy1' => 91, 'candy2' => 93, 'candy3' => 92, 'candy4' => 96,
    'candyHi0' => 97, 'candyHi1' => 97, 'candyHi2' => 97, 'candyHi3' => 97, 'candyHi4' => 97,
    'top0' => 93, 'top1' => 33, 'top2' => 95, 'top3' => 35, 'extr0' => 35, 'extr1' => 34, 'glow' => 30,
];

/** Great-wave centre-line [X, Y, radius], Catmull-Rom smoothed. */
const CURL = [
    [-4, 28, 9.0], [0.2, 18.5, 6.6], [3.4, 11.0, 4.7], [7.4, 5.8, 3.3], [11.8, 3.6, 2.7],
    [16.0, 3.9, 2.2], [19.0, 5.9, 1.75], [19.8, 8.6, 1.35], [18.6, 10.6, 1.0], [16.6, 11.1, 0.7],
    [15.0, 10.0, 0.4],
];
const CLAW_FROM = 0.40;
const CLAW_PERIOD = 1.9;
const CLAW_LEN = 1.9;

const SWELL = ['base' => 16.2, 'waves' => [[1.1, 0.29, 0.4], [0.5, 0.71, 2.2]]];

const MOON = [33.5, 2.4, 1.9];      // centre X, Y, radius

/** Sparkle glyph cells [col, row, glyph, role-ish colour hex] (only if the cell suits). */
const SPARKLES = [
    [2, 0, '✦', '#FFFFFF'], [12, 0, '⋆', '#BFE9FF'], [24, 0, '·', '#FFFFFF'], [28, 1, '✦', '#FFF6C8'],
    [40, 0, '⋆', '#FFFFFF'], [44, 1, '·', '#BFE9FF'], [47, 0, '✦', '#FFE9F6'], [77, 0, '⋆', '#FFFFFF'],
    [78, 9, '·', '#FFFFFF'], [21, 3, '⋆', '#FFFFFF'],
];

const DESCRIPTION = "Hokusai's Great Wave at night: a glowing striated blue wave with white foam claws curls in from the left over a black starry sky with a moon; CANDY in bubbly Lato Black, one candy colour per letter (pink, tangerine, lemon, lime, sky) with gloss bevels and soft glow; TOP as a graffiti piece - tilted, staggered italic letters with a yellow→orange / pink→magenta split fade, violet 3D extrusion, black keyline, white cloud outline, drips and glitter; sparkles twinkle across foam, letters and sky (quadrant-block raster)";
const TAGS = ['ukiyoe-woodblock-wave', 'great-wave', 'hokusai-homage', 'night-sky', 'graffiti-lettering', '3d-extrusion', 'glitter-sparkles', 'candy-colours', 'quadrant-blocks', 'ttf-sprites'];

const ANIM_FRAMES = 16;   // play: php logo-play.php <file> 230  (≈3.7 s)

// ================================================================ depth / colour

function depth(?string $set = null): string
{
    static $d = 'tc';
    return $d = $set ?? $d;
}

/** Resolve a painted colour for the current depth by ROLE. */
function P(string $role, ?array $rgb = null): array
{
    if (depth() === '16' && isset(ROLE16[$role])) {
        return lkAnsi16()[ROLE16[$role]];
    }
    $rgb ??= lkHex(PALETTE[$role]);
    return match (depth()) {
        '16' => lkAnsi16()[lkTo16($rgb)],
        '256' => isset(ROLE256[$role]) ? lkXterm256()[ROLE256[$role]] : $rgb,
        default => $rgb,
    };
}

function smooth(float $e0, float $e1, float $x): float
{
    $t = max(0.0, min(1.0, ($x - $e0) / ($e1 - $e0)));
    return $t * $t * (3 - 2 * $t);
}

// ================================================================ geometry

function curlSamples(float $dx): array
{
    static $cache = [];
    $key = sprintf('%.3f', $dx);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $p = CURL;
    $n = count($p);
    $pts = [];
    for ($i = 0; $i < $n - 1; $i++) {
        $p0 = $p[max(0, $i - 1)];
        $p1 = $p[$i];
        $p2 = $p[$i + 1];
        $p3 = $p[min($n - 1, $i + 2)];
        for ($s = 0; $s < 20; $s++) {
            $t = $s / 20;
            $t2 = $t * $t;
            $t3 = $t2 * $t;
            $v = [];
            for ($k = 0; $k < 3; $k++) {
                $v[$k] = 0.5 * (2 * $p1[$k] + (-$p0[$k] + $p2[$k]) * $t
                    + (2 * $p0[$k] - 5 * $p1[$k] + 4 * $p2[$k] - $p3[$k]) * $t2
                    + (-$p0[$k] + 3 * $p1[$k] - 3 * $p2[$k] + $p3[$k]) * $t3);
            }
            $pts[] = [$v[0] + $dx, $v[1], $v[2]];
        }
    }
    $last = end($p);
    $pts[] = [$last[0] + $dx, $last[1], $last[2]];
    $arc = 0.0;
    $out = [];
    $m = count($pts);
    foreach ($pts as $i => $q) {
        $nx = $pts[min($m - 1, $i + 1)];
        $pv = $pts[max(0, $i - 1)];
        $tx = $nx[0] - $pv[0];
        $ty = $nx[1] - $pv[1];
        $l = max(1e-6, hypot($tx, $ty));
        if ($i > 0) {
            $arc += hypot($q[0] - $pts[$i - 1][0], $q[1] - $pts[$i - 1][1]);
        }
        $out[] = [$q[0], $q[1], $q[2], $tx / $l, $ty / $l, $arc];
    }
    return $cache[$key] = $out;
}

/** [d (<0 inside body), s (−1 inner … +1 outer), u (0..1 along), arc]. */
function curlField(float $x, float $y, float $dx): array
{
    $best = [INF, 0.0, 0.0, 0.0];
    $smp = curlSamples($dx);
    $n = count($smp) - 1;
    foreach ($smp as $i => [$cx, $cy, $r, $tx, $ty, $arc]) {
        $ox = $x - $cx;
        $oy = $y - $cy;
        if (abs($ox) > $r + 4 || abs($oy) > $r + 4) {
            continue;
        }
        $d = hypot($ox, $oy) - $r;
        if ($d < $best[0]) {
            $s = ($ox * $ty - $oy * $tx) / max(0.5, $r);
            $best = [$d, max(-1.0, min(1.0, $s)), $i / $n, $arc];
        }
    }
    return $best;
}

function swellSurface(float $x, float $phase): float
{
    $y = SWELL['base'];
    foreach (SWELL['waves'] as [$a, $f, $p]) {
        $y += $a * sin($f * ($x + $phase) + $p);
    }
    return $y;
}

/** Bool grid dilated by an elliptical radius r (units) — 2 samples per unit across. */
function dilate(array $m, float $r): array
{
    $offs = [];
    $rj = (int) ceil($r);
    $ri = (int) ceil($r * 2);
    for ($j = -$rj; $j <= $rj; $j++) {
        for ($i = -$ri; $i <= $ri; $i++) {
            if (($i / 2) ** 2 + $j ** 2 <= $r * $r + 0.01) {
                $offs[] = [$i, $j];
            }
        }
    }
    $out = array_fill(0, PH, array_fill(0, PW, false));
    for ($y = 0; $y < PH; $y++) {
        for ($x = 0; $x < PW; $x++) {
            if (!$m[$y][$x]) {
                continue;
            }
            foreach ($offs as [$i, $j]) {
                if (isset($out[$y + $j][$x + $i])) {
                    $out[$y + $j][$x + $i] = true;
                }
            }
        }
    }
    return $out;
}

function blank(): array
{
    return array_fill(0, PH, array_fill(0, PW, false));
}

/** Per-letter bool masks (coverage ≥ .5) for a sprite list. */
function spriteMasks(string $font, array $letters, bool $withCond): array
{
    $masks = [];
    foreach ($letters as $L) {
        $p = ['x' => $L[1], 'y' => $L[2], 'h' => $L[3], 'rot' => $L[4], 'condense' => $withCond ? $L[5] : 0.94];
        $cov = gsCoverage(gsLoad($font, $L[0]), $p, PW, PH, 2, 2, 3);
        $m = blank();
        for ($y = 0; $y < PH; $y++) {
            for ($x = 0; $x < PW; $x++) {
                $m[$y][$x] = $cov[$y][$x] >= 0.5;
            }
        }
        $masks[] = $m;
    }
    return $masks;
}

/**
 * All letter geometry, memoised: CANDY masks/keyline/glow; TOP per-letter
 * fill, extrusion and keyline; union cloud; drips.
 */
function geometry(): array
{
    static $g = null;
    if ($g !== null) {
        return $g;
    }
    $candy = spriteMasks(FONT_CANDY, CANDY, true);
    $candyAll = blank();
    foreach ($candy as $m) {
        foreach ($m as $y => $row) {
            foreach ($row as $x => $on) {
                $candyAll[$y][$x] = $candyAll[$y][$x] || $on;
            }
        }
    }
    $candyKey = dilate($candyAll, 1.0);
    $candyGlow = dilate($candyAll, 1.5);

    $top = spriteMasks(FONT_TOP, TOP, false);
    [$ex, $ey] = TOP_EXTRUDE;
    $steps = 8;
    $topExtr = [];
    $topKey = [];
    $union = blank();
    foreach ($top as $k => $m) {
        $e = blank();
        $depthT = [];
        for ($y = 0; $y < PH; $y++) {
            for ($x = 0; $x < PW; $x++) {
                if ($m[$y][$x]) {
                    continue;
                }
                for ($s = 1; $s <= $steps; $s++) {
                    $sx = (int) round($x - 2 * $ex * $s / $steps);
                    $sy = (int) round($y - $ey * $s / $steps);
                    if ($m[$sy][$sx] ?? false) {
                        $e[$y][$x] = true;
                        $depthT[$y][$x] = $s / $steps;
                        break;
                    }
                }
            }
        }
        // drips hang from this letter's fill bottom edge.
        foreach (DRIPS as [$dx, $len]) {
            $col = (int) floor($dx * 2);
            for ($y = PH - 1; $y >= 0; $y--) {
                if ($m[$y][$col] ?? false) {
                    for ($j = 1; $j <= (int) ceil($len); $j++) {
                        foreach ([$col, $col + 1] as $c) {
                            if (isset($m[$y + $j][$c]) && !($j === (int) ceil($len) && $c === $col + 1)) {
                                $m[$y + $j][$c] = true;
                            }
                        }
                    }
                    break;
                }
            }
        }
        $top[$k] = $m;
        $both = blank();
        for ($y = 0; $y < PH; $y++) {
            for ($x = 0; $x < PW; $x++) {
                $both[$y][$x] = $m[$y][$x] || $e[$y][$x];
                $union[$y][$x] = $union[$y][$x] || $both[$y][$x];
            }
        }
        $topExtr[$k] = [$e, $depthT];
        $topKey[$k] = dilate($both, 1.0);
    }
    $topKeyAll = dilate($union, 1.0);
    $cloud = dilate($topKeyAll, 1.0);
    return $g = compact('candy', 'candyAll', 'candyKey', 'candyGlow', 'top', 'topExtr', 'topKey', 'cloud', 'union');
}

/** Bevel test: on the fill, but the sample up-left of it is not → lit edge. */
function litEdge(array $m, int $x, int $y): bool
{
    return $m[$y][$x] && (!($m[$y - 1][$x] ?? false) || !($m[$y][$x - 1] ?? false) || !($m[$y][$x - 2] ?? false));
}

function shadeEdge(array $m, int $x, int $y): bool
{
    return $m[$y][$x] && (!($m[$y + 1][$x] ?? false) || !($m[$y][$x + 2] ?? false));
}

// ================================================================ build

/**
 * @param float $t animation progress 0..1 (1 = static logo)
 * @param int $twinkle glitter seed (frame-varying in the anim)
 */
function pixels(float $t = 1.0, int $twinkle = 0): array
{
    $ease = 1 - (1 - smooth(0.0, 0.55, $t)) ** 2;
    $dx = -26 * (1 - $ease);
    $phase = -22 * (1 - $ease);
    $g = geometry();
    $flat = depth() !== 'tc';

    $px = [];
    for ($y = 0; $y < PH; $y++) {
        for ($qx = 0; $qx < PW; $qx++) {
            $cx = ($qx + 0.5) / 2;
            $cy = $y + 0.5;

            // ---- night sky + stars.
            $sky = $flat ? P($cy < 9 ? 'skyTop' : 'skyLow') : lkGradient([PALETTE['skyTop'], PALETTE['skyLow']], smooth(0.0, 15.0, $cy));
            $c = $sky;
            $n = lkNoise($qx, $y, SEED + 7);
            if ($n < 0.022 && $cy < 13) {
                $c = $flat ? P('star') : lkMix($sky, lkHex(PALETTE['star']), 0.35 + 12 * $n * (lkNoise($qx, $y, $twinkle) > 0.5 ? 1 : 0.4));
            }

            // ---- moon + halo.
            [$mx, $my, $mr] = MOON;
            $md = hypot($cx - $mx, $cy - $my);
            if ($md < $mr) {
                $c = $flat ? P('moon') : lkShade(lkHex(PALETTE['moon']), -0.12 * smooth(0.0, $mr, hypot($cx - $mx + 0.6, $cy - $my + 0.6)));
            } elseif ($md < $mr + 2.0 && !$flat) {
                $c = lkMix($c, lkHex(PALETTE['halo']), 0.55 * (1 - ($md - $mr) / 2.0) ** 2);
            }

            // ---- rolling swell, lit from above.
            $surf = swellSurface($cx, $phase);
            $d = $cy - $surf;
            if ($d > 0) {
                $crest = $surf <= swellSurface($cx - 0.5, $phase) && $surf <= swellSurface($cx + 0.5, $phase);
                if ($d < 0.9) {
                    $c = P($crest || $surf < SWELL['base'] - 0.6 ? 'foam' : 'aqua');
                } elseif ($d < 1.9) {
                    $c = P('mid');
                } elseif ($d < 2.8) {
                    $c = P('stripe');
                } else {
                    $c = $flat ? P('prussian') : lkMix(lkHex(PALETTE['prussian']), lkHex(PALETTE['deep']), smooth(2.8, 4.0, $d));
                }
            } elseif ($d > -1.0 && $surf < SWELL['base'] - 0.7 && sin($cx * 4.0) > 0.1 && swellSurface($cx - 0.7, $phase) > $surf) {
                $c = P('foam');
            }

            // ---- the great wave curl.
            [$cd, $s, $u, $arc] = curlField($cx, $cy, $dx);
            if ($cd < 0) {
                $lip = $u > CLAW_FROM - 0.12 && $s > 0.45;
                if ($cd > ($lip ? -1.1 : -0.6) || $u > 0.86) {
                    $c = P($lip || $u > 0.86 ? 'foam' : ($s < -0.3 ? 'stripe' : 'aqua'));
                } else {
                    $v = ($s + 1) / 2;
                    $line = abs(fmod($v * 2.4 + 0.15 * sin($arc * 0.9), 1.0) - 0.5) < 0.11;
                    $c = match (true) {
                        $line => P('stripe'),
                        $v < 0.32 => P('aqua'),
                        $v < 0.6 => P('mid'),
                        default => $flat ? P('prussian') : lkShade(lkHex(PALETTE['prussian']), -0.2 * $v),
                    };
                }
            } elseif ($u > CLAW_FROM && $cd < CLAW_LEN + 0.6 && ($s > -0.1 || $u > 0.8)) {
                $wave = sin(2 * M_PI * $arc / CLAW_PERIOD - $cd * 1.4);
                $len = CLAW_LEN * max(0.0, $wave) * (0.45 + 0.55 * smooth(CLAW_FROM, 0.7, $u));
                if ($cd < $len) {
                    $c = P('foam');
                } elseif ($cd < $len + 0.5 && $wave > 0.25) {
                    $c = P('aqua');
                }
            }
            // glitter on the foam: a few samples flash pure white.
            if ($c === P('foam') && !$flat && lkNoise($qx, $y, SEED + 11 + $twinkle) < 0.18) {
                $c = lkHex(PALETTE['glint']);
            }

            // ---- CANDY: candy colour per letter, gloss bevel, keyline, glow.
            $candyIn = smooth(0.35, 0.7, $t);
            if ($candyIn > 0) {
                $lc = null;
                if (CANDY_GLOW > 0 && $g['candyGlow'][$y][$qx] && !$g['candyKey'][$y][$qx] && !$flat) {
                    // soft halo in the nearest letter's colour.
                    $near = 0;
                    $bd = INF;
                    foreach (CANDY as $k => $L) {
                        $dd = abs($cx - $L[1]);
                        if ($dd < $bd) {
                            $bd = $dd;
                            $near = $k;
                        }
                    }
                    $lc = lkMix($c, lkHex(CANDY[$near][6]), CANDY_GLOW);
                }
                if ($g['candyKey'][$y][$qx]) {
                    $lc = P('letterKey');
                }
                foreach ($g['candy'] as $k => $m) {
                    if (!$m[$y][$qx]) {
                        continue;
                    }
                    $base = lkHex(CANDY[$k][6]);
                    $ly = ($cy - (CANDY[$k][2] - CANDY[$k][3] / 2)) / CANDY[$k][3];
                    if ($flat && depth() === '16') {
                        $lc = P(litEdge($m, $qx, $y) && $ly < 0.5 ? "candyHi$k" : "candy$k");
                    } else {
                        $lc = lkShade($base, 0.22 - 0.5 * $ly);
                        if (!($m[$y - 1][$qx] ?? false)) {
                            $lc = lkMix($lc, [255, 255, 255], 0.6);   // gloss lip on top edges
                        } elseif ($ly < 0.42 && !($m[$y][$qx - 1] ?? false)) {
                            $lc = lkMix($lc, [255, 255, 255], 0.35);
                        } elseif (shadeEdge($m, $qx, $y)) {
                            $lc = lkShade($lc, -0.35);
                        }
                    }
                }
                if ($lc !== null) {
                    $c = $candyIn >= 1 ? $lc : lkMix($c, $lc, $candyIn);
                }
            }

            // ---- TOP graffiti piece: sprayed in left → right.
            $spray = smooth(0.55, 0.95, $t) * 36 + 45;
            if ($t > 0.55 && $cx < $spray) {
                if ($g['cloud'][$y][$qx]) {
                    $c = P('cloud');
                }
                foreach ($g['top'] as $k => $m) {
                    [$e, $dT] = $g['topExtr'][$k];
                    if ($g['topKey'][$k][$y][$qx]) {
                        $c = P('letterKey');
                    }
                    if ($e[$y][$qx]) {
                        $c = $flat && depth() === '16' ? P($dT[$y][$qx] < 0.5 ? 'extr0' : 'extr1')
                            : P($dT[$y][$qx] < 0.5 ? 'extr0' : 'extr1', lkGradient(TOP_EXTR, $dT[$y][$qx]));
                        if (!$flat && ($e[$y - 1][$qx] ?? true) === false) {
                            $c = lkShade($c, 0.25);       // lit top face of the extrusion
                        }
                    }
                    if ($m[$y][$qx]) {
                        // split fade: a zig-zag seam divides the warm top from the hot bottom.
                        $seam = 8.4 + 0.9 * abs(fmod($cx * 0.55, 2.0) - 1.0) - 0.45;
                        $top = $cy < $seam;
                        if ($flat && depth() === '16') {
                            $c = P($top ? ($cy < $seam - 2.5 ? 'top0' : 'top1') : ($cy < $seam + 3 ? 'top2' : 'top3'));
                        } else {
                            $c = $top
                                ? lkGradient([TOP_FADE[0], TOP_FADE[1]], smooth(1.5, $seam, $cy))
                                : lkGradient([TOP_FADE[2], TOP_FADE[3]], smooth($seam, 16.0, $cy));
                        }
                        if (!($m[$y - 1][$qx] ?? false) || !($m[$y][$qx - 1] ?? false)) {
                            $c = $flat ? P('shine') : lkMix($c, [255, 255, 255], 0.6);
                        } elseif (abs(($cx - $cy * 0.5) - (TOP[$k][1] - 6.2)) < 0.45 && $cy < $seam) {
                            $c = P('shine');                // diagonal shine streak
                        } elseif (!$flat && lkNoise($qx, $y, SEED + 21 + $twinkle) < 0.045) {
                            $c = lkMix($c, [255, 255, 255], 0.8);   // glitter flake
                        }
                    }
                }
            }
            $px[$y][$qx] = $c;
        }
    }
    return $px;
}

/** Samples → quadrant cells, then sparkle glyph cells. */
function cells(float $t = 1.0, int $twinkle = 0): array
{
    $px = pixels($t, $twinkle);
    $cells = qrRaster($px);
    if ($t < 0.6) {
        return $cells;
    }
    $sp = SPARKLES;
    // twinkle: alternate frames swap a few glyph sizes.
    foreach ($sp as $i => [$x, $y, $gl, $hex]) {
        if ($twinkle !== 0 && ($i + $twinkle) % 3 === 0) {
            $gl = $gl === '✦' ? '⋆' : ($gl === '⋆' ? '✦' : '·');
        }
        $q = [$px[2 * $y][2 * $x], $px[2 * $y][2 * $x + 1], $px[2 * $y + 1][2 * $x], $px[2 * $y + 1][2 * $x + 1]];
        // keep the cell's dominant colour as bg; skip busy cells (letter edges).
        $uniq = array_unique(array_map(static fn (array $c): string => implode(',', $c), $q));
        if (count($uniq) > 2) {
            continue;
        }
        $bg = $q[0];
        $fg = depth() === 'tc' ? lkHex($hex) : P('glint');
        $cells[$y][$x] = [$gl, $fg, $bg];
    }
    return $cells;
}

function render(string $d, float $t = 1.0, int $twinkle = 0): string
{
    depth($d);
    return lkEncode(cells($t, $twinkle), $d, [], 'nearest');
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$out = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--out=')) {
        $out = substr($a, 6);
    }
}
$dir = $out ?? dirname(__DIR__);
foreach (['tc', '256', '16'] as $d) {
    $static = render($d);
    [$w, $h] = lkVerify($static, $d);
    lkCheckDepth($static, $d, $d);
    echo $static;
    fwrite(STDERR, "[$d] {$w}x{$h} ok\n");
    if (!$write && $out === null) {
        continue;
    }
    $r = lkWriteLogo($dir, SLUG, $d, $static, DESCRIPTION . " ($d)", TAGS, $out === null);
    fwrite(STDERR, "  wrote {$r['file']}\n");
    $frames = [];
    for ($f = 0; $f < ANIM_FRAMES; $f++) {
        $tt = min(1.0, $f / (ANIM_FRAMES - 5));      // last 5 frames: glitter twinkle
        $frames[] = render($d, $tt, $tt >= 1.0 ? $f : 0);
    }
    $r = lkWriteLogo(
        $dir,
        SLUG . '-anim',
        $d,
        lkAnim($frames, $static),
        'Animated ' . DESCRIPTION . ". The wave rolls in, CANDY fades up, TOP is sprayed on left to right, then the glitter and sparkles twinkle; play with tools/logo-play.php <file> 230 (~3.7 s) ($d)",
        [...TAGS, 'animated', 'wave-roll-in', 'twinkle'],
        $out === null,
    );
    fwrite(STDERR, "  wrote {$r['file']}\n");
}
