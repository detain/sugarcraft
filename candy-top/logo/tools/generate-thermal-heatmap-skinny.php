<?php

declare(strict_types=1);

/**
 * generate-thermal-heatmap-skinny — 40-col variant of thermal-heatmap (v4).
 *
 * The SAME scene as generate-thermal-heatmap.php (pastel-thermal bubble
 * CANDY, larger pearlescent TOP with its T over the Y, black background with
 * bloom / isotherm rings / sparkles, btop-style braille temperature graph,
 * camera HUD), but sampled at HALF scale on a sextant sub-pixel grid:
 * the 80×20-px design → 80×30 sub-pixels (2×3 per cell) → 40×10 cells via
 * skRaster()'s best-2-colour sextant fit, plus a 1-row HUD on top (REC tag,
 * braille graph, MAX readout) → 40×11.
 *
 * Technique worth reusing: any SDF/field-based generator can be "skinnied"
 * by evaluating its field at sub-pixel centres (x, (y+0.5)·H/SH) instead of
 * integer pixels and handing the image to skRaster('sextant').
 *
 * Usage:
 *   php generate-thermal-heatmap-skinny.php            preview tc, 256, 16 + verify
 *   php generate-thermal-heatmap-skinny.php --out=DIR  write .ansi into DIR (no jsonl)
 *   php generate-thermal-heatmap-skinny.php --write    write into ../ + one logos.jsonl line each (skWriteSkinny)
 */

require_once __DIR__ . '/skinny-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'thermal-heatmap-skinny';
const W = 80;
const H = 20;
const SW = 80;           // sextant sub-pixel columns (40 cells × 2)
const SH = 30;           // sextant sub-pixel rows (10 cells × 3)
const SY = H / SH;       // design px per sub-pixel row

/**
 * Normalised skeleton font: (u,v) ∈ [0,1]² maps to the stroke-centre box
 * (inset by the pen radius). Polylines [[u,v],...] or ['arc', cu, cv, ru, rv, a0, a1]
 * (degrees, y-down, 0 = right, 90 = down).
 */
const FONT = [
    'C' => [['arc', 0.56, 0.5, 0.56, 0.5, 42, 318]],
    'A' => [[[0, 1], [0, 0.44]], ['arc', 0.5, 0.44, 0.5, 0.44, 180, 360], [[1, 0.44], [1, 1]], [[0, 0.70], [1, 0.70]]],
    'N' => [[[0, 1], [0, 0], [1, 1], [1, 0]]],
    'D' => [[[0, 0], [0, 1]], [[0, 0], [0.3, 0]], [[0, 1], [0.3, 1]], ['arc', 0.3, 0.5, 0.7, 0.5, -90, 90]],
    'Y' => [[[0, 0], [0.5, 0.55]], [[1, 0], [0.5, 0.55]], [[0.5, 0.55], [0.5, 1]]],
    'T' => [[[-0.08, 0], [1.08, 0]], [[0.5, 0], [0.5, 1]]],
    'O' => [['arc', 0.5, 0.5, 0.5, 0.5, 0, 360]],
    'P' => [[[0, 1], [0, 0], [0.42, 0]], ['arc', 0.42, 0.27, 0.58, 0.27, -90, 90], [[0.42, 0.54], [0, 0.54]]],
];

/** Letters: [char, x, y, boxW, boxH, penR, hue-source]. Later entries draw on top. */
const LETTERS = [
    ['C', 0.0, 4.9, 9.6, 14.0, 1.75, '#FF8CC6'],   // bubblegum pink
    ['A', 10.0, 4.9, 9.6, 14.0, 1.75, '#FFB08A'],  // peach
    ['N', 20.0, 4.9, 9.6, 14.0, 1.75, '#FFE27A'],  // butter
    ['D', 30.0, 4.9, 9.6, 14.0, 1.75, '#86F0BE'],  // mint
    ['Y', 40.0, 4.9, 9.6, 14.0, 1.75, '#88D2FF'],  // sky
    ['T', 45.6, 0.9, 11.0, 17.9, 2.05, 'pearl'],
    ['O', 56.9, 0.9, 11.0, 17.9, 2.05, 'pearl'],
    ['P', 68.2, 0.9, 11.0, 17.9, 2.05, 'pearl'],
];

const LIGHT = [-0.55, -0.83];   // up-left
const SEAM = 0.55;              // crease where two CANDY balloons touch
const SHADOW_OFF = [1.2, 1.4];  // T's cast shadow onto Y
const GLITTER = 0.05;          // fraction of letter pixels that glitter
const SEED = 98;

/** Pearl (iridescent) hue wheel sampled by the normal's angle. */
const PEARL = ['#F5B8FF', '#B8C8FF', '#A8F4E8', '#FFF0B0', '#FFC0D8', '#F5B8FF'];

/** Background art. */
/** Braille temperature graph (btop style) under the HUD: cells [x0, x1], row. */
const GRAPH = [6, 27, 0];
const GRAPH_SEED = 7;
const GRAPH_COLORS = ['#5A3A9A', '#9A6AE8', '#FF8CC6', '#FFB08A', '#FFE27A', '#FFFFFF'];
/** Sparkles: [col, row, glyph, hex] cells — kept off the lettering. */
const SPARKLES = [
    [22, 1, '✦', '#FFF4B0'], [19, 2, '·', '#FF9AD5'], [12, 2, '✧', '#9EE8FF'], [4, 2, '·', '#C9A0FF'],
    [16, 2, '⋆', '#B0F0C8'], [0, 3, '✦', '#FFB0D8'], [39, 5, '✧', '#B8C8FF'], [28, 9, '✦', '#9EE8FF'],
    [39, 9, '✦', '#C9A0FF'], [33, 9, '·', '#FFC6E8'], [10, 1, '⋆', '#FFE58A'],
];

/** White star glyphs placed ON the letters' glint spots: [col, row, glyph]. */
const STAR_LETTERS = [5 => '✦', 7 => '✧'];   // letter index => glyph

/** HUD. */
const HUD_GREY = '#8A86A8';
const HUD_TEXT = '#FFD6A0';
const HUD_REC = '#FF5A8A';
const SCALE = ['#2A1840', '#7E4FD0', '#FF8CC6', '#FFB08A', '#FFE27A', '#FFFFFF'];
const MIN_C = 21.0;
const MAX_C = 98.6;

const DESCRIPTION = 'Skinny 40-col thermal-heatmap: the same pastel-thermal scene at half scale on a sextant sub-pixel raster: bubble-letter CANDY in five pastels (pink, peach, butter, mint, sky) with pillow shading and glints, a slightly larger pearlescent TOP whose T overlaps the Y, black background with pastel bloom and sparkles, under a camera HUD row (REC tag, btop-style braille temperature graph in heat colours, MAX readout).';
const TAGS = ['skinny', 'thermal-heatmap', 'sextant-raster', 'pastel-thermal', 'bubble-letters', 'iridescent-pearl', 'glitter-sparkles', 'braille-graph', 'resource-monitor', 'isotherm-contours', 'camera-hud', 'black-background', 'half-scale'];

const ANIM_FRAMES = 18;         // glint sweep + twinkle; play at ≤150 ms → < 3 s

// ================================================================ geometry

function sdfSegment(float $px, float $py, array $a, array $b): float
{
    $dx = $b[0] - $a[0];
    $dy = $b[1] - $a[1];
    $l2 = $dx * $dx + $dy * $dy;
    $h = $l2 == 0.0 ? 0.0 : max(0.0, min(1.0, (($px - $a[0]) * $dx + ($py - $a[1]) * $dy) / $l2));
    return hypot($px - $a[0] - $h * $dx, $py - $a[1] - $h * $dy);
}

/** Glyph → list of absolute-px polylines for a box. */
function glyphGeom(string $ch, float $x, float $y, float $bw, float $bh, float $r): array
{
    $iw = $bw - 2 * $r;
    $ih = $bh - 2 * $r;
    $map = static fn (float $u, float $v): array => [$x + $r + $u * $iw, $y + $r + $v * $ih];
    $lines = [];
    foreach (FONT[$ch] as $s) {
        if (($s[0] ?? null) === 'arc') {
            [, $cu, $cv, $ru, $rv, $a0, $a1] = $s;
            $pts = [];
            for ($i = 0; $i <= 48; $i++) {
                $a = deg2rad($a0 + ($a1 - $a0) * $i / 48);
                $pts[] = $map($cu + $ru * cos($a), $cv + $rv * sin($a));
            }
            $lines[] = $pts;
        } else {
            $lines[] = array_map(static fn ($p) => $map($p[0], $p[1]), $s);
        }
    }
    return $lines;
}

function sdfLines(float $px, float $py, array $lines): float
{
    $d = INF;
    foreach ($lines as $pts) {
        for ($i = 0, $n = count($pts) - 1; $i < $n; $i++) {
            $d = min($d, sdfSegment($px, $py, $pts[$i], $pts[$i + 1]));
        }
    }
    return $d;
}

/**
 * Cached per-pixel geometry: winning letter, depth, lit, nearest-letter
 * distance + hue, crease/shadow flags.
 */
function sceneGeom(): array
{
    static $geom = null;
    if ($geom !== null) {
        return $geom;
    }
    $L = [];
    foreach (LETTERS as [$ch, $x, $y, $bw, $bh, $r, $hue]) {
        $L[] = ['lines' => glyphGeom($ch, $x, $y, $bw, $bh, $r), 'r' => $r, 'hue' => $hue, 'top' => $bh > 15];
    }
    $geom = [];
    for ($y = 0; $y < SH; $y++) {
        for ($x = 0; $x < SW; $x++) {
            $px = $x + 0.5;
            $py = ($y + 0.5) * SY;
            $sd = [];
            foreach ($L as $i => $l) {
                $sd[$i] = sdfLines($px, $py, $l['lines']) - $l['r'];
            }
            $win = null;
            foreach ($sd as $i => $d) {
                if ($d < 0) {
                    $win = $i;              // later letters draw on top
                }
            }
            $nearI = array_keys($sd, min($sd))[0];
            $g = ['win' => $win, 'near' => min($sd), 'nearI' => $nearI, 'crease' => false, 'shadow' => false];
            if ($win !== null) {
                $l = $L[$win];
                $g['depth'] = min(1.0, -$sd[$win] / $l['r']);
                $e = 0.35;
                $gx = sdfLines($px + $e, $py, $l['lines']) - sdfLines($px - $e, $py, $l['lines']);
                $gy = sdfLines($px, $py + $e, $l['lines']) - sdfLines($px, $py - $e, $l['lines']);
                $gl = hypot($gx, $gy);
                $g['lit'] = $gl > 1e-6 ? ($gx * LIGHT[0] + $gy * LIGHT[1]) / $gl : 0.0;
                $g['ang'] = $gl > 1e-6 ? atan2($gy, $gx) : 0.0;
                $others = $sd;
                unset($others[$win]);
                if (!$l['top'] && min($others) < SEAM) {
                    $g['crease'] = true;
                }
                if ($l['top'] && min($others) < 0) {
                    $g['overlapEdge'] = -$sd[$win] < 0.7;   // T's rim where it sits over Y
                }
                // T's soft cast shadow on Y
                if (!$l['top']) {
                    $sx = $px - SHADOW_OFF[0];
                    $sy = $py - SHADOW_OFF[1];
                    foreach ($L as $j => $o) {
                        if ($o['top'] && sdfLines($sx, $sy, $o['lines']) - $o['r'] < 0) {
                            $g['shadow'] = true;
                        }
                    }
                }
            }
            $geom[$y][$x] = $g;
        }
    }
    $geom['letters'] = $L;
    return $geom;
}

// ================================================================ colour

function hueOf(array $l, float $ang): array
{
    if ($l['hue'] !== 'pearl') {
        return lkHex($l['hue']);
    }
    $t = fmod(($ang + M_PI) / (2 * M_PI) + 0.15, 1.0);
    return lkGradient(PEARL, $t);
}

function valueNoise(float $x, float $y, int $seed): float
{
    $x0 = (int) floor($x);
    $y0 = (int) floor($y);
    $s = static fn ($t) => $t * $t * (3 - 2 * $t);
    $fx = $s($x - $x0);
    $fy = $s($y - $y0);
    $a = lkNoise($x0, $y0, $seed);
    $b = lkNoise($x0 + 1, $y0, $seed);
    $c = lkNoise($x0, $y0 + 1, $seed);
    $d = lkNoise($x0 + 1, $y0 + 1, $seed);
    return ($a + ($b - $a) * $fx) * (1 - $fy) + ($c + ($d - $c) * $fx) * $fy;
}

/**
 * Pixel colour for frame parameters: $sweep = x of a diagonal shine band
 * (null = none), $twinkle = glitter seed offset, $phase = wisp drift.
 */
function pixel(int $x, int $y, ?float $sweep, int $twinkle, float $phase): array
{
    $geom = sceneGeom();
    $g = $geom[$y][$x];
    $L = $geom['letters'];
    $black = [0, 0, 0];
    if ($g['win'] !== null) {
        $l = $L[$g['win']];
        $d = $g['depth'];
        $lit = $g['lit'];
        $base = hueOf($l, $g['ang'] + 0.9 * $d);
        $pearl = $l['hue'] === 'pearl';
        $rim = lkShade(lkMix($base, [120, 60, 200], 0.18), $pearl ? -0.22 : -0.38);
        $c = lkMix($rim, lkMix($base, [255, 255, 255], 0.30), min(1.0, $d * 1.6));      // creamy core
        $c = lkShade($c, ($lit < 0 && $pearl ? 0.10 : 0.20) * $lit * (1 - 0.5 * $d));  // pillow
        $glint = max(0.0, $lit) ** 2 * exp(-(($d - 0.5) ** 2) / 0.05);
        $c = lkMix($c, [255, 255, 255], min(0.9, 0.85 * $glint));
        if (lkNoise($x, $y, SEED + 7 + $twinkle) < GLITTER) {                         // glitter
            $c = lkMix($c, [255, 255, 255], 0.75);
        }
        if ($sweep !== null) {
            $band = exp(-((($x - $sweep) + ($y - 10) * 0.6) ** 2) / 3.0);
            $c = lkMix($c, [255, 255, 255], 0.65 * $band);
        }
        if ($g['crease']) {
            $c = lkShade($rim, -0.35);
        }
        if ($g['shadow']) {
            $c = lkShade($c, -0.45);
        }
        if (!empty($g['overlapEdge'])) {
            $c = lkShade($c, -0.25);
        }
        return $c;
    }
    // ---- background (black + art)
    $near = $g['near'];
    $hue = hueOf($L[$g['nearI']], 0.0);
    $c = lkScale($hue, 0.13 * exp(-$near / 0.7));                        // bloom (tight: bg stays black)
    $ring = abs($near - 2.6) < 0.32 && (($x + $y) % 2 === 0);            // dotted isotherm
    if ($ring) {
        $c = lkMix($c, lkScale(lkMix($hue, [190, 150, 255], 0.7), 0.42), 0.9);
    }
    // faint star dust
    if (lkNoise($x, $y, SEED + 3 + $twinkle) < 0.012 && $near > 1.5) {
        $c = lkMix($c, [200, 190, 255], 0.45);
    }
    return $c === $black ? $black : $c;
}

function sceneCells(string $depth, ?float $sweep = null, int $twinkle = 0, float $phase = 0.0, float $readout = 1.0): array
{
    $img = [];
    for ($y = 0; $y < SH; $y++) {
        for ($x = 0; $x < SW; $x++) {
            $c = pixel($x, $y, $sweep, $twinkle, $phase);
            if ($depth === '16' && max($c) < 140) {
                $c = [0, 0, 0];         // dim bloom/rings would turn into grey 16-colour noise
            }
            $img[$y][$x] = $c;
        }
    }
    $scene = skRaster($img, 'sextant', $depth, 'hue');
    $cells = [array_fill(0, W / 2, [' ', null, [0, 0, 0]]), ...$scene];
    hud($cells, $twinkle, $readout);
    return $cells;
}

/** Auto-place one star glyph per chosen letter on its brightest fully-inked cell. */
function starGlints(): array
{
    $geom = sceneGeom();
    $best = [];
    for ($cy = 0; $cy < SH / 3; $cy++) {
        for ($cx = 0; $cx < SW / 2; $cx++) {
            $win = null;
            $score = 0.0;
            $ok = true;
            for ($k = 0; $k < 6 && $ok; $k++) {
                $g = $geom[3 * $cy + intdiv($k, 2)][2 * $cx + $k % 2];
                $win ??= $g['win'];
                $ok = $g['win'] !== null && $g['win'] === $win;
                $score += $ok ? $g['lit'] - abs($g['depth'] - 0.55) : 0;
            }
            if (!$ok || !isset(STAR_LETTERS[$win])) {
                continue;
            }
            if (!isset($best[$win]) || $score > $best[$win][3]) {
                $best[$win] = [$cx, $cy + 1, STAR_LETTERS[$win], $score];
            }
        }
    }
    return array_values($best);
}

/** Seeded temperature trace: random walk warming toward the right (the MAX end). */
function graphSamples(int $n, int $shift): array
{
    $v = [];
    $x = 0.35;
    for ($i = 0; $i < $n + 64; $i++) {
        $trend = 0.12 + 0.62 * ($i / ($n + 63)) ** 1.5;
        $x += (lkNoise($i, 0, GRAPH_SEED) - 0.5) * 0.6 + ($trend - $x) * 0.3;
        if (lkNoise($i, 1, GRAPH_SEED) > 0.93) {
            $x += 0.35;                                  // load spike
        }
        $x = max(0.05, min(1.0, $x));
        $v[] = $x;
    }
    return array_slice($v, 64 - $shift, $n);
}

/** btop-style braille area graph: 2 samples per cell, 4 dot rows, filled from the bottom. */
function brailleGraph(array &$cells, int $shift): void
{
    [$x0, $x1, $row] = GRAPH;
    $n = ($x1 - $x0 + 1) * 2;
    $v = graphSamples($n, $shift);
    $left = [0x40, 0x04, 0x02, 0x01];     // dots 7,3,2,1 bottom → top
    $right = [0x80, 0x20, 0x10, 0x08];    // dots 8,6,5,4
    for ($c = 0; $c <= $x1 - $x0; $c++) {
        $a = $v[2 * $c];
        $b = $v[2 * $c + 1];
        $bits = 0;
        for ($k = 0; $k < max(1, (int) round($a * 4)); $k++) {
            $bits |= $left[$k];
        }
        for ($k = 0; $k < max(1, (int) round($b * 4)); $k++) {
            $bits |= $right[$k];
        }
        lkPut($cells, $x0 + $c, $row, mb_chr(0x2800 + $bits), lkGradient(GRAPH_COLORS, max($a, $b)), [0, 0, 0]);
    }
}

function hud(array &$cells, int $twinkle, float $readout): void
{
    $grey = lkHex(HUD_GREY);
    $txt = lkHex(HUD_TEXT);
    $bk = [0, 0, 0];
    $rows = count($cells);
    $cols = count($cells[0]);
    lkPut($cells, 0, 0, '┌', $grey);
    lkPut($cells, $cols - 1, 0, '┐', $grey);
    lkPut($cells, 0, $rows - 1, '└', $grey);
    lkPut($cells, $cols - 1, $rows - 1, '┘', $grey);
    // HUD row: ● REC  ⣀⣤⣶⣿ temperature graph  MAX 98.6°
    lkPut($cells, 1, 0, '●', $twinkle % 2 === 1 ? lkScale(lkHex(HUD_REC), 0.45) : lkHex(HUD_REC), $bk);
    lkText($cells, 2, 0, 'REC', $grey, $bk);
    lkText($cells, 29, 0, sprintf('MAX%5.1f°', MIN_C + (MAX_C - MIN_C) * $readout), $txt, $bk);
    brailleGraph($cells, $twinkle > 0 ? 64 - (int) round(64 * min(1.0, $readout)) : 0);
    // star glints sitting on the letters (bg = the letter's lower pixel)
    foreach (starGlints() as $i => [$cx, $cy, $glyph]) {
        if ($twinkle > 0 && ($i + $twinkle) % 4 === 0) {
            continue;
        }
        lkPut($cells, $cx, $cy, $glyph, [255, 255, 255]);
    }
    // sparkles (twinkle: alternate glyph sizes per frame)
    foreach (SPARKLES as $i => [$cx, $cy, $glyph, $hex]) {
        if ($twinkle > 0 && ($i + $twinkle) % 3 === 0) {
            $glyph = ['✦' => '✧', '✧' => '·', '⋆' => '✦', '·' => '⋆'][$glyph];
        }
        $cell = $cells[$cy][$cx] ?? null;
        $dark = static fn (?array $c): bool => $c === null || max($c) < 60;
        if ($cell !== null && $dark($cell[1]) && $dark($cell[2])) {
            lkPut($cells, $cx, $cy, $glyph, lkHex($hex), [0, 0, 0]);
        }
    }
}

/** 16-colour pins for HUD/sparkle hexes; everything else goes through hue mode. */
function pins16(): array
{
    return [
        HUD_GREY => 90, HUD_TEXT => 93, HUD_REC => 95,
        '#FFF4B0' => 93, '#FF9AD5' => 95, '#9EE8FF' => 96, '#FFC6E8' => 95, '#C9A0FF' => 94,
        '#FFE58A' => 93, '#B8C8FF' => 94, '#B0F0C8' => 92, '#FFB0D8' => 95,
    ];
}

/** Dim background art falls to black at 16 colours unless clearly lit. */
function encode(array $cells, string $depth): string
{
    if ($depth === '16') {
        // overlay glyphs (HUD, braille graph, sparkles) keep their hue: snap them
        // with hue mode; sextant cells are already snapped by skRaster
        $a16 = lkAnsi16();
        foreach ($cells as &$row) {
            foreach ($row as &$cell) {
                $cp = mb_ord($cell[0] === '' ? ' ' : $cell[0]);
                $isMosaic = ($cp >= 0x1FB00 && $cp <= 0x1FB3B) || in_array($cell[0], ['█', '▌', '▐', ' '], true);
                if (!$isMosaic && $cell[1] !== null) {
                    $cell[1] = $a16[lkTo16($cell[1], pins16(), 'hue')];
                }
            }
        }
        unset($row, $cell);
    }
    return lkEncode($cells, $depth, [], 'nearest');
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$outDir = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--out=')) {
        $outDir = substr($a, 6);
    }
}
$dir = $outDir ?? dirname(__DIR__);
$animDesc = 'Animated ' . DESCRIPTION . ' A diagonal shine sweeps across the letters while glitter and sparkles twinkle, the braille temperature graph scrolls in from the right and the MAX readout climbs; ' . ANIM_FRAMES . ' frames + static, play with tools/logo-play.php at ≤150 ms (<5 s)';
foreach (['tc', '256', '16'] as $depth) {
    $static = encode(sceneCells($depth), $depth);
    [$w, $h] = lkVerify($static, $depth);
    lkCheckDepth($static, $depth, $depth);
    if (!$write && $outDir === null) {
        echo $static;
    }
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    if ($write || $outDir !== null) {
        $frames = [];
        for ($f = 0; $f < ANIM_FRAMES; $f++) {
            $k = $f / (ANIM_FRAMES - 1);
            $frames[] = encode(sceneCells($depth, -12 + 104 * $k, $f + 1, -3.0 * (1 - $k), min(1.0, 0.3 + 0.7 * $k)), $depth);
        }
        $anim = lkAnim($frames, $static);
        $r1 = skWriteSkinny($dir, SLUG, $depth, $static, DESCRIPTION . " ($depth)", TAGS, $write);
        $r2 = skWriteSkinny($dir, SLUG . '-anim', $depth, $anim, $animDesc . " ($depth)", [...TAGS, 'animated', 'shine-sweep', 'twinkle'], $write);
        fwrite(STDERR, "  wrote {$r1['file']}, {$r2['file']}\n");
    }
}
