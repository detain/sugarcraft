<?php

declare(strict_types=1);

/**
 * generate-topo-contour-relief: CANDY TOP drawn as islands on an atlas relief map.
 *
 * Letters are VECTOR strokes (polylines + elliptical arcs). Each pixel's
 * elevation is its distance to the nearest stroke skeleton, so every letter is
 * a mountain range whose ridge follows the letter's centreline and whose
 * coast follows the stroke edge. The pipeline:
 *   skeleton distance → elevation (regional uplift + seeded noise)
 *   → hypsometric tint → NW hillshade → engraved contour lines
 *   → sea: bathymetric blues by depth, foam at the coast, faint isobaths
 *   → map neatline: alternating ink/paper graduated border.
 * Rendered in ▀ half blocks (1 cell = 2 px).
 *
 * Usage:
 *   php generate-topo-contour-relief.php              preview tc/256/16 to stdout (+verify)
 *   php generate-topo-contour-relief.php --write      write .ansi files + logos.jsonl lines
 *   php generate-topo-contour-relief.php --seed=N     different terrain noise
 *   php generate-topo-contour-relief.php --out=DIR    write into DIR (scratch), no jsonl
 *
 * Reuse: swap STROKES (vector font), HYPSO/SEA palettes, UPLIFT (per-letter
 * height), BANDS (contour interval). Everything else is generic.
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'topo-contour-relief';
const TEXT = 'CANDY TOP';
const SEED = 17;

/** Canvas in pixels (cells = W x H/2). */
const W = 78;
const H = 18;
const LETTER_TOP = 3;        // pixel row where the 12-px letter box starts
const LETTER_H = 12;
const STROKE_R = 1.22;       // stroke half-width in px (coast = skeleton distance R)
const LETTER_GAP = 2;
const WORD_GAP = 4;

/**
 * Vector font. Each letter: [width, list of paths]. A path is either
 * ['L', [x,y], [x,y], ...] (polyline) or ['E', cx, cy, rx, ry, a0, a1]
 * (elliptical arc, degrees, 0 = east, 90 = south since y grows downward).
 * Coordinates are pixel centres inside the letter box (0..width-1, 0..11).
 */
function strokes(): array
{
    $t = 1.15;          // top centreline
    $b = 9.85;          // bottom centreline
    $m = ($t + $b) / 2;
    return [
        'C' => [7, [['E', 3.75, $m, 2.65, 4.35, 42, 318]]],
        'A' => [7, [
            ['L', [1.15, $b], [1.15, 3.6]],
            ['E', 3.5, 3.6, 2.35, 2.45, 180, 360],
            ['L', [5.85, 3.6], [5.85, $b]],
            ['L', [1.15, 6.4], [5.85, 6.4]],
        ]],
        'N' => [8, [['L', [1.15, $b], [1.15, $t], [6.85, $b], [6.85, $t]]]],
        'D' => [7, [
            ['L', [2.6, $b], [1.15, $b], [1.15, $t], [2.6, $t]],
            ['E', 2.6, $m, 3.25, 4.35, -90, 90],
        ]],
        'Y' => [7, [['L', [1.0, $t], [3.5, 5.6], [6.0, $t]], ['L', [3.5, 5.6], [3.5, $b]]]],
        'T' => [7, [['L', [0.9, $t], [6.1, $t]], ['L', [3.5, $t], [3.5, $b]]]],
        'O' => [8, [['E', 3.5, $m, 2.35, 4.35, 0, 360]]],
        'P' => [7, [
            ['L', [1.15, $b], [1.15, $t], [3.4, $t]],
            ['E', 3.4, 3.45, 2.45, 2.3, -90, 90],
            ['L', [3.4, 5.75], [1.15, 5.75]],
        ]],
    ];
}

/** Per-letter peak height (0..1): the foothills of CANDY climb to the summit of TOP. */
const UPLIFT = ['C' => 0.36, 'A' => 0.42, 'N' => 0.49, 'D' => 0.56, 'Y' => 0.64, 'T' => 0.90, 'O' => 1.0, 'P' => 0.93];

/** Hypsometric tints, sea level → summit (atlas colouring). */
const HYPSO = ['#1F7A45', '#3E9F4E', '#86C25A', '#D6D86A', '#F0C35A', '#DE8A3C', '#B35A2E', '#8A4632', '#D8D2CC', '#FFFFFF'];
/** Sea by depth in px from the coast: foam → shallows → deep. */
const SEA = ['#9EDCEB', '#3FA2CF', '#2577B4', '#1A5A97', '#143F75', '#0E2B55'];
const INK = '#2A2119';
const PAPER = '#EFE4C8';
const WAVE_GLYPH = '∼';
const WAVE_DEPTH = 1.6;    // min depth (px) for a wave mark
const WAVE_DENSITY = 0.55;
const BANDS = 6;             // contour interval: elevation 0..1 split into BANDS
const LIGHT = 0.40;          // hillshade strength

/** 16-colour pins (hue bucketing turns the ochres and sea muddy). */
const PINS16 = [];
/** 256 pins: nearest-cube turned the paper neatline pink. */
const PINS256 = ['#EFE4C8' => 230];
/** 16-colour hypsometric roles (one per HYPSO stop) and their contour-line siblings. */
const HYPSO16 = [32, 32, 92, 92, 93, 33, 33, 33, 37, 97];
const DARKER16 = [];       // contour siblings in 16 colours read as noise: off

const DESCRIPTION = 'Atlas relief map: CANDY TOP as vector-stroke islands whose ridges follow each letter skeleton, hypsometric tints rising from green foothills (CANDY) to snow-capped summits (TOP), NW hillshade, engraved contour lines, bathymetric sea with coastal foam, inside a graduated ink/paper map neatline.';
const TAGS = ['topographic', 'hypsometric-tint', 'contour-lines', 'hillshade', 'cartography', 'island-letters', 'map-neatline', 'half-block'];

// ================================================================ GEOMETRY

/** Flatten a letter's paths into segments [[x0,y0,x1,y1], ...] offset by (ox, oy). */
function segments(array $paths, float $ox, float $oy): array
{
    $segs = [];
    foreach ($paths as $p) {
        $pts = [];
        if ($p[0] === 'L') {
            $pts = array_slice($p, 1);
        } else {
            [, $cx, $cy, $rx, $ry, $a0, $a1] = $p;
            $n = (int) max(8, ceil(abs($a1 - $a0) / 8));
            for ($i = 0; $i <= $n; $i++) {
                $a = deg2rad($a0 + ($a1 - $a0) * $i / $n);
                $pts[] = [$cx + $rx * cos($a), $cy + $ry * sin($a)];
            }
        }
        for ($i = 0; $i + 1 < count($pts); $i++) {
            $segs[] = [$pts[$i][0] + $ox, $pts[$i][1] + $oy, $pts[$i + 1][0] + $ox, $pts[$i + 1][1] + $oy];
        }
    }
    return $segs;
}

function segDist(float $px, float $py, array $s): float
{
    [$x0, $y0, $x1, $y1] = $s;
    $dx = $x1 - $x0;
    $dy = $y1 - $y0;
    $l2 = $dx * $dx + $dy * $dy;
    $t = $l2 > 0 ? max(0.0, min(1.0, (($px - $x0) * $dx + ($py - $y0) * $dy) / $l2)) : 0.0;
    return hypot($px - $x0 - $t * $dx, $py - $y0 - $t * $dy);
}

/** Smooth value noise in [0,1] (bilinear over lkNoise lattice). */
function vnoise(float $x, float $y, float $scale, int $seed): float
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

/**
 * Lay the letters out and return [letters], each [char, segs, uplift].
 */
function layout(): array
{
    $font = strokes();
    $total = 0;
    $chars = str_split(TEXT);
    foreach ($chars as $i => $ch) {
        $total += $ch === ' ' ? WORD_GAP : $font[$ch][0];
        if ($ch !== ' ' && isset($chars[$i + 1]) && $chars[$i + 1] !== ' ') {
            $total += LETTER_GAP;
        }
    }
    $x = (W - $total) / 2;
    $letters = [];
    foreach ($chars as $i => $ch) {
        if ($ch === ' ') {
            $x += WORD_GAP;
            continue;
        }
        [$w, $paths] = $font[$ch];
        $letters[] = [$ch, segments($paths, $x, LETTER_TOP), UPLIFT[$ch]];
        $x += $w + (isset($chars[$i + 1]) && $chars[$i + 1] !== ' ' ? LETTER_GAP : 0);
    }
    return $letters;
}

/**
 * Signed field at a point: [elevation 0..1 (land) or <0 (sea depth in px), land?].
 * Elevation = ridge profile (1 - d/R)^0.7 × letter uplift + noise.
 */
function field(array $letters, float $x, float $y, int $seed): array
{
    $best = INF;
    $up = 0.0;
    $height = 0.0;
    foreach ($letters as [, $segs, $u]) {
        foreach ($segs as $s) {
            $d = segDist($x, $y, $s);
            if ($d < $best) {
                $best = $d;
                $up = $u;
            }
            if ($d < STROKE_R) {
                $height = max($height, $u * (0.30 + 0.70 * (1 - $d / STROKE_R) ** 0.8));
            }
        }
    }
    if ($best < STROKE_R) {
        $n = vnoise($x, $y, 2.3, $seed) - 0.5;
        $n2 = vnoise($x, $y, 0.9, $seed + 7) - 0.5;
        return [max(0.02, min(1.0, $height + 0.08 * $n + 0.03 * $n2)), true];
    }
    return [-($best - STROKE_R), false];
}

// ================================================================ RENDER

/**
 * @param float $seaLevel elevation threshold for the reveal animation
 *        (0 = full map; land below it renders as shallow water)
 * @return list<list<?array>> pixel rows
 */
function pixels(int $seed, float $seaLevel = 0.0, string $depthMode = 'tc'): array
{
    static $cache = [];
    $letters = layout();
    if (!isset($cache[$seed])) {
        $f = [];
        for ($y = 0; $y < H; $y++) {
            for ($x = 0; $x < W; $x++) {
                // 3x3 supersample: land coverage softens the coastline
                $cov = 0;
                $sumE = 0.0;
                $centre = field($letters, $x + 0.5, $y + 0.5, $seed);
                foreach ([0.17, 0.5, 0.83] as $sy) {
                    foreach ([0.17, 0.5, 0.83] as $sx) {
                        [$e, $land] = field($letters, $x + $sx, $y + $sy, $seed);
                        if ($land) {
                            $cov++;
                            $sumE = max($sumE, $e);   // ridge-preserving: peak of the pixel
                        }
                    }
                }
                $f[$y][$x] = [$centre[0], $cov / 9, $centre[1] ? $centre[0] : $sumE];
            }
        }
        $cache[$seed] = $f;
    }
    $f = $cache[$seed];
    $isLand = static fn (int $x, int $y): bool => isset($f[$y][$x]) && $f[$y][$x][1] >= 0.45 && $f[$y][$x][2] > $seaLevel;
    $elev = static fn (int $x, int $y): float => isset($f[$y][$x]) ? ($isLand($x, $y) ? $f[$y][$x][2] : min(0.0, $f[$y][$x][0])) : -6.0;
    $band = static fn (float $e): int => (int) floor($e * BANDS);
    $hypso = array_map('lkHex', HYPSO);
    $sea = array_map('lkHex', SEA);
    $is16 = $depthMode === '16';
    $flat = $depthMode === '256';   // cube quantising shaded tints turns them to mud: flat layer tints instead
    $a16 = lkAnsi16();

    $px = [];
    for ($y = 0; $y < H; $y++) {
        for ($x = 0; $x < W; $x++) {
            if ($isLand($x, $y)) {
                $e = $f[$y][$x][2];
                if ($is16) {
                    $px[$y][$x] = $a16[land16($f, $x, $y, $e, $band, $isLand)];
                    continue;
                }
                // layer tints: posterised elevation bands, as on a printed atlas
                $c = lkGradient($hypso, min(1.0, (floor($e * BANDS) + 0.65) / BANDS));
                // hillshade: light from the north-west
                $gx = $elev($x + 1, $y) - $elev($x - 1, $y);
                $gy = $elev($x, $y + 1) - $elev($x, $y - 1);
                $c = lkShade($c, max(-0.45, min(0.4, LIGHT * (-$gx - $gy) * 0.9)) * ($flat ? 0.4 : 1.0));
                // engraved contour: a lower-band neighbour → line on the uphill side
                $b = $band($e);
                $contour = false;
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                    if ($isLand($x + $dx, $y + $dy) && $band($f[$y + $dy][$x + $dx][2]) < $b) {
                        $contour = true;
                    }
                }
                if ($contour) {
                    $c = lkMix($c, lkHex('#4A2E1C'), $e > 0.8 ? 0.22 : 0.28);
                }
                // coast: a partially covered pixel blends into foam
                $cov = $f[$y][$x][1];
                if ($cov < 1) {
                    $c = lkMix($c, $sea[0], (1 - $cov) * 0.35);
                }
                $px[$y][$x] = $c;
                continue;
            }
            // sea (or drowned land during the reveal)
            $depth = -$f[$y][$x][0];
            if ($f[$y][$x][1] >= 0.45) {
                $depth = 0.4 + 2.2 * ($seaLevel - $f[$y][$x][2]);   // submerged peak: shallows
            }
            if ($is16) {
                $px[$y][$x] = $a16[sea16($depth, $f[$y][$x][1])];
                continue;
            }
            $c = lkGradient($sea, min(1.0, ($depth + 0.35) / 3.2));
            $cov = $f[$y][$x][1];
            if ($cov > 0 && $cov < 0.45) {
                $c = lkMix($c, $sea[0], 0.5);     // breaking surf on the coast
            }
            // isobaths: faint light lines at depth 1.5 and 3.2 px
            foreach ([1.5, 3.2] as $iso) {
                if (abs($depth - $iso) < 0.32) {
                    $c = lkMix($c, $sea[1], 0.30);
                }
            }
            // gentle water texture
            if (!$flat) {
                $c = lkShade($c, 0.10 * (vnoise($x * 1.0, $y * 2.2, 3.0, $seed + 3) - 0.5));
            }
            $px[$y][$x] = $c;
        }
    }
    neatline($px, $is16);
    return $px;
}

/**
 * 16-colour land: role-mapped from the elevation band (nearest-colour on the
 * shaded tc tints turned the map into confetti). Contour pixels take the
 * darker sibling of their band colour.
 */
function land16(array $f, int $x, int $y, float $e, callable $band, callable $isLand): int
{
    $q = min(1.0, (floor($e * BANDS) + 0.65) / BANDS);
    $code = HYPSO16[(int) round($q * (count(HYPSO16) - 1))];
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        if ($isLand($x + $dx, $y + $dy) && $band($f[$y + $dy][$x + $dx][2]) < $band($e)) {
            return DARKER16[$code] ?? $code;
        }
    }
    return $code;
}

/** 16-colour sea by depth: surf, shallows, open water. */
function sea16(float $depth, float $cov): int
{
    if ($cov > 0 && $cov < 0.45) {
        return 36;
    }
    return $depth < 0.6 ? 36 : 34;
}

/** Map frame: alternating ink/paper ticks (4 px top/bottom, 1 cell = 2 px on the sides). */
function neatline(array &$px, bool $is16 = false): void
{
    $ink = $is16 ? lkAnsi16()[30] : lkHex(INK);
    $paper = $is16 ? lkAnsi16()[97] : lkHex(PAPER);
    for ($x = 0; $x < W; $x++) {
        $c = intdiv($x + 1, 4) % 2 ? $paper : $ink;
        $px[0][$x] = $c;
        $px[H - 1][$x] = intdiv($x + 3, 4) % 2 ? $paper : $ink;
    }
    for ($y = 1; $y < H - 1; $y++) {
        $c = intdiv($y, 2) % 2 ? $paper : $ink;
        $px[$y][0] = $c;
        $px[$y][W - 1] = intdiv($y + 2, 2) % 2 ? $paper : $ink;
    }
    foreach ([[0, 0], [W - 1, 0], [0, H - 1], [W - 1, H - 1]] as [$x, $y]) {
        $px[$y][$x] = $ink;
    }
}

/**
 * Half-block the pixels, then engrave old-atlas wave marks into open water:
 * a cell whose two pixels are both deep sea (and not frame) may get a
 * seeded '∼' in a lighter blue over the averaged water colour.
 */
function cells(int $seed, float $seaLevel = 0.0, string $depthMode = 'tc'): array
{
    $px = pixels($seed, $seaLevel, $depthMode);
    $cells = lkHalfBlock($px);
    $depth = depthMap($seed);
    foreach ($cells as $cy => $row) {
        foreach ($row as $cx => $cell) {
            if ($cx < 1 || $cx > W - 2 || $cy < 1 || $cy > H / 2 - 2) {
                continue;
            }
            $d1 = $depth[2 * $cy][$cx];
            $d2 = $depth[2 * $cy + 1][$cx];
            if (min($d1, $d2) > WAVE_DEPTH && lkNoise($cx, $cy, $seed + 11) < WAVE_DENSITY) {
                if ($depthMode === '16') {
                    $cells[$cy][$cx] = [WAVE_GLYPH, lkAnsi16()[36], lkAnsi16()[34]];
                    continue;
                }
                $bg = lkMix($px[2 * $cy][$cx], $px[2 * $cy + 1][$cx], 0.5);
                $cells[$cy][$cx] = [WAVE_GLYPH, lkMix($bg, lkHex(SEA[1]), 0.75), $bg];
            }
        }
    }
    return $cells;
}

/** Sea depth (px from coast) per pixel; land = 0. */
function depthMap(int $seed): array
{
    static $m = [];
    if (!isset($m[$seed])) {
        $letters = layout();
        for ($y = 0; $y < H; $y++) {
            for ($x = 0; $x < W; $x++) {
                $m[$seed][$y][$x] = max(0.0, -field($letters, $x + 0.5, $y + 0.5, $seed)[0]);
            }
        }
    }
    return $m[$seed];
}

// ================================================================ DEPTH

/**
 * 256: nearest within the 6x6x6 cube (greys only for near-neutral colours),
 * plain RGB distance. The kit's redmean pick sent the mid/deep sea to grey
 * ramp entries, which reads as dirty patches in the water.
 */
function to256(array $c): int
{
    static $memo = [];
    $k = implode(',', $c);
    if (isset($memo[$k])) {
        return $memo[$k];
    }
    foreach (PINS256 as $hex => $n) {
        if (lkHex($hex) === $c) {
            return $memo[$k] = $n;
        }
    }
    $sat = max($c) - min($c);
    $best = 16;
    $bd = INF;
    foreach (lkXterm256() as $n => $p) {
        if ($n >= 232 && $sat > 24) {
            continue;
        }
        $d = 2 * ($c[0] - $p[0]) ** 2 + 4 * ($c[1] - $p[1]) ** 2 + 3 * ($c[2] - $p[2]) ** 2;
        if ($d < $bd) {
            $bd = $d;
            $best = $n;
        }
    }
    return $memo[$k] = $best;
}

/** Cells → ANSI at a depth (16-colour cells are already exact palette colours). */
function encode(array $cells, string $depth): string
{
    if ($depth === '256') {
        $pal = lkXterm256();
        foreach ($cells as &$row) {
            foreach ($row as &$cell) {
                $cell[1] = $cell[1] === null ? null : $pal[to256($cell[1])];
                $cell[2] = $cell[2] === null ? null : $pal[to256($cell[2])];
            }
        }
        unset($row, $cell);
    }
    return lkEncode($cells, $depth, PINS16, 'nearest');
}

// ================================================================ CLI

$seed = SEED;
$out = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--seed=')) {
        $seed = (int) substr($a, 7);
    }
    if (str_starts_with($a, '--out=')) {
        $out = substr($a, 6);
    }
}
$write = in_array('--write', $argv, true) || $out !== null;
$dir = $out ?? dirname(__DIR__);
foreach (['tc', '256', '16'] as $depth) {
    $enc = encode(cells($seed, 0.0, $depth), $depth);
    [$w, $h] = lkVerify($enc, $depth);
    lkCheckDepth($enc, $depth);
    echo $enc;
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    if ($write) {
        $r = lkWriteLogo($dir, SLUG, $depth, $enc, DESCRIPTION . " ($depth)", TAGS, $out === null);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        // sea-level-falls reveal: summits surface first, coasts spread outward
        $frames = [];
        foreach ([1.02, 0.9, 0.8, 0.7, 0.6, 0.5, 0.42, 0.34, 0.26, 0.18, 0.1, 0.04] as $lvl) {
            $frames[] = encode(cells($seed, $lvl, $depth), $depth);
        }
        $anim = lkAnim($frames, $enc);
        $r = lkWriteLogo($dir, SLUG . '-anim', $depth, $anim, 'Animated: the sea level falls and the CANDY TOP relief map surfaces summit-first (TOP peaks, then the CANDY foothills) before settling on the static map. 13 frames, play with tools/logo-play.php <file> 250 (~3.2 s). ' . "($depth)", [...TAGS, 'animated', 'sea-level-reveal'], $out === null);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
}
