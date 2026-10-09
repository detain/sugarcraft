<?php

declare(strict_types=1);

/**
 * generate-licorice-allsorts-skinny — ≤40-col SKINNY variant of generate-licorice-allsorts.php:
 * the same SDF allsorts slabs, scaled down (FACE_H/DEPTH/STROKE) and stacked CANDY over TOP
 * ('/' in LETTERS starts a new centred row). Glyph geometry is expressed as fractions of
 * the face height so it scales.
 *
 * Original header follows.
 *
 * generate-licorice-allsorts — "CANDY TOP" as a tumbled handful of Liquorice
 * Allsorts (v2, a rebuild from scratch of the licorice-allsorts design).
 *
 * Every letter is a thick candy slab drawn with signed-distance shapes (rounded
 * boxes, capsules, ellipses), so the letterforms are smooth and rounded instead of
 * a bitmap font. Each slab can be tilted (LETTERS tilt column); it is 0 here because a
 * rotation aliases into staircase edges at this resolution.
 *  - top face: glossy sugar-coated fondant, lit from top-left (bevel from the SDF
 *    gradient), plus a per-sweet texture: coconut shreds, nonpareil bumps, sugar sparkle
 *  - front side (extruded down): the allsorts sandwich, fondant / liquorice / fondant…
 *    each letter with its own layer recipe
 *  - the O is a liquorice Catherine wheel: a spiral coil around a blue bobble centre,
 *    inside a pink coconut rim
 *
 * The face also gets a short glaze streak. Rasterised on a 2x horizontal sub-pixel grid through quadrant-raster.php
 * (RASTER='quad') or plain half blocks (RASTER='half').
 *
 * Usage:
 *   php generate-licorice-allsorts-skinny.php     preview tc/256/16 to stdout (+ verify)
 *   php generate-licorice-allsorts-skinny.php --write    write the .ansi files + logos.jsonl lines
 *   php generate-licorice-allsorts-skinny.php --only=tc  preview a single depth
 */

require_once __DIR__ . '/logo-kit.php';
require_once __DIR__ . '/quadrant-raster.php';
require_once __DIR__ . '/skinny-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'licorice-allsorts-skinny';
const RASTER = 'quad';      // 'quad' (2x horizontal resolution) | 'half'
const FACE_H = 10.0;        // face height in px (1 px = half a terminal row)
const DEPTH = 3;            // extrusion depth in px: the visible sandwich
const STROKE = 2.1;         // stroke width in px/col units
const GAP = 0.5;            // columns between letters
const WORD_GAP = 3.0;          // unused when the words are stacked
const MARGIN_TOP = 0.0;
const LIGHT = [-0.55, -0.83];   // direction the light comes from (x, y-down)
const BEVEL = 1.0;
const GLOSS_LINE = 2.1;          // lx + 0.55·ly = this → glaze streak
const GLOSS_MAX_Y = 4.0;
const LINE_GAP = 0.0;           // extra px between the stacked rows
const CANVAS_W = 0;            // 0 = widest row              // px width of the pillow bevel

/** Named sweet colours: [highlight, base, shade]. */
const SWEETS = [
    'pink'    => ['#FFD3E8', '#FF8DC1', '#C24F86'],
    'lemon'   => ['#FFF6B0', '#FFD43A', '#C99412'],
    'blue'    => ['#B8E6FF', '#3FA8F5', '#1D63B5'],
    'orange'  => ['#FFD0A0', '#FF8A2A', '#BF4E12'],
    'cream'   => ['#FFFFFF', '#FFF0D6', '#D9BE96'],
    'rasp'    => ['#FFB8C8', '#F0476F', '#A41F45'],
    'lime'    => ['#E2FFB0', '#8EDB4A', '#4E9A22'],
    'violet'  => ['#EBD8FF', '#A974F2', '#6438AE'],
    'liq'     => ['#7A6070', '#2C1F27', '#170F14'],
];

/**
 * Letters: char, width (cols), face sweet, texture, tilt (deg), lift (px),
 * sandwich recipe (sweets for the extruded side, top → bottom, one per px of DEPTH).
 */
const LETTERS = [
    ['C', 7.5,  'pink',   'coconut',  0.0, 0.0, ['pink', 'liq', 'cream', 'liq']],
    ['A', 7.5,  'lemon',  'sugar',   0.0, 0.0, ['lemon', 'liq', 'lemon', 'liq']],
    ['N', 7.5,  'blue',   'bobble',  0.0, 0.0, ['blue', 'blue', 'liq', 'blue']],
    ['D', 7.5,  'orange', 'sugar',   0.0, 0.0, ['orange', 'liq', 'cream', 'liq']],
    ['Y', 7.5,  'violet', 'sugar',    0.0, 0.0, ['violet', 'liq', 'cream', 'liq']],
    ['/', 0.0, null, null, 0.0, 0.0, []],
    ['T', 7.0,  'rasp',   'sugar',   0.0, 0.0, ['rasp', 'liq', 'cream', 'liq']],
    ['O', 8.0,  'wheel',  'wheel',  0.0, 0.0, ['pink', 'liq', 'cream', 'liq']],
    ['P', 7.0,  'lime',   'sugar',   0.0, 0.0, ['lime', 'liq', 'cream', 'liq']],
];

/** The Catherine-wheel O: radii are fractions of the ellipse. */
const WHEEL = ['rim' => 0.78, 'core' => 0.34, 'turns' => 1.0, 'coilFill' => 0.55, 'spiral' => false];

const DESCRIPTION = 'Skinny (≤40 col) stacked CANDY-over-TOP variant of licorice-allsorts. CANDY TOP as a handful of Liquorice Allsorts: smooth rounded SDF letter-slabs (quadrant-block edges) with glossy bevel-lit sugar faces (pink coconut C, lemon A, nonpareil-blue bobble N, orange D, blackcurrant-violet Y, raspberry T, lime P) extruded downward to show each sweet\'s own fondant/liquorice sandwich; the O is a liquorice Catherine wheel coiled round a blue bobble inside a pink coconut rim.';
const TAGS = ['licorice-allsorts', 'skinny', 'stacked-rows', 'layered-candy', 'sdf-letterforms', 'quadrant-blocks', 'extruded-3d', 'bevel-lighting', 'catherine-wheel-o', 'coconut-texture', 'multicolour'];

// 16-colour pins by sweet + role; filled while shading so they cover derived colours.
const ANSI16 = [
    'pink' => [95, 95, 35], 'lemon' => [93, 93, 33], 'blue' => [96, 94, 34], 'orange' => [93, 33, 31],
    'cream' => [97, 97, 37], 'rasp' => [91, 91, 31], 'lime' => [92, 92, 32], 'violet' => [95, 35, 35], 'liq' => [90, 90, 90],
];

// ================================================================ SDF helpers

function sdBox(float $px, float $py, float $x0, float $y0, float $x1, float $y1, array $r): float
{
    // $r = [tl, tr, br, bl]
    $cx = ($x0 + $x1) / 2; $cy = ($y0 + $y1) / 2;
    $hx = ($x1 - $x0) / 2; $hy = ($y1 - $y0) / 2;
    $dx = $px - $cx; $dy = $py - $cy;
    $rr = $dx > 0 ? ($dy > 0 ? $r[2] : $r[1]) : ($dy > 0 ? $r[3] : $r[0]);
    $rr = min($rr, $hx, $hy);
    $qx = abs($dx) - $hx + $rr; $qy = abs($dy) - $hy + $rr;
    return min(max($qx, $qy), 0.0) + sqrt(max($qx, 0) ** 2 + max($qy, 0) ** 2) - $rr;
}

function sdCapsule(float $px, float $py, float $ax, float $ay, float $bx, float $by, float $r): float
{
    $pax = $px - $ax; $pay = $py - $ay; $bax = $bx - $ax; $bay = $by - $ay;
    $h = max(0.0, min(1.0, ($pax * $bax + $pay * $bay) / ($bax * $bax + $bay * $bay)));
    return sqrt(($pax - $bax * $h) ** 2 + ($pay - $bay * $h) ** 2) - $r;
}

function sdEllipse(float $px, float $py, float $cx, float $cy, float $rx, float $ry): float
{
    $u = ($px - $cx) / $rx; $v = ($py - $cy) / $ry;
    return (sqrt($u * $u + $v * $v) - 1.0) * min($rx, $ry);
}

/** Signed distance of a glyph in its local box (0..w, 0..FACE_H). */
function glyphSd(string $ch, float $w, float $x, float $y): float
{
    $s = STROKE; $h = FACE_H;
    $ring = static fn (float $outer, float $inner): float => max($outer, -$inner);
    return match ($ch) {
        'C' => max(
            $ring(sdBox($x, $y, 0, 0, $w, $h, [3.3, 3.3, 3.3, 3.3]), sdBox($x, $y, $s, $s, $w - $s + 0.2, $h - $s, [1.6, 1.6, 1.6, 1.6])),
            -sdBox($x, $y, $w / 2, $h * 0.3, $w + 2, $h * 0.7, [1.0, 0, 0, 1.0]),
        ),
        'A' => min(
            $ring(sdBox($x, $y, 0, 0, $w, $h, [3.3, 3.3, 0.7, 0.7]), sdBox($x, $y, $s, $s, $w - $s, $h + 6, [1.4, 1.4, 0, 0])),
            max(sdBox($x, $y, 0, $h * 0.5, $w, $h * 0.5 + $s - 0.2, [0, 0, 0, 0]), sdBox($x, $y, 0, 0, $w, $h, [3.3, 3.3, 0.7, 0.7])),
        ),
        'N' => min(
            sdBox($x, $y, 0, 0, $s, $h, [1.2, 0.6, 0.7, 0.7]),
            sdBox($x, $y, $w - $s, 0, $w, $h, [0.7, 0.7, 0.7, 0.6]),
            sdCapsule($x, $y, 1.05, 1.05, $w - 1.05, $h - 1.05, 1.05),
        ),
        'D' => $ring(sdBox($x, $y, 0, 0, $w, $h, [0.9, 3.8, 3.8, 0.9]), sdBox($x, $y, $s, $s, $w - $s, $h - $s, [0, 1.5, 1.5, 0])),
        'Y' => min(
            sdCapsule($x, $y, 1.05, 1.05, $w / 2, $h * 0.54, 1.05),
            sdCapsule($x, $y, $w - 1.05, 1.05, $w / 2, $h * 0.54, 1.05),
            sdBox($x, $y, $w / 2 - $s / 2, $h * 0.51, $w / 2 + $s / 2, $h, [0, 0, 0.7, 0.7]),
        ),
        'T' => min(
            sdBox($x, $y, 0, 0, $w, $s, [1.3, 1.3, 1.0, 1.0]),
            sdBox($x, $y, $w / 2 - $s / 2, 0.5, $w / 2 + $s / 2, $h, [0, 0, 0.7, 0.7]),
        ),
        'P' => min(
            sdBox($x, $y, 0, 0, $s, $h, [0.9, 0, 0.7, 0.7]),
            $ring(sdBox($x, $y, 0, 0, $w, $h * 0.66, [0.9, 3.3, 3.3, 0.9]), sdBox($x, $y, $s, $s, $w - $s, $h * 0.66 - $s, [0, 1.3, 1.3, 0])),
        ),
        'O' => sdEllipse($x, $y, $w / 2, $h / 2, $w / 2, $h / 2),
        default => 1e9,
    };
}

// ================================================================ scene

/**
 * Lay out the letters. $drop[i] = extra vertical offset (px, negative = up) for
 * the animation.
 * @return list<array{ch:string,w:float,x0:float,face:?string,tex:?string,tilt:float,lift:float,layers:list<string>,i:int}>
 */
function scene(array $drop = []): array
{
    $rows = [[]];
    foreach (LETTERS as $i => $spec) {
        if ($spec[0] === '/') {
            $rows[] = [];
            continue;
        }
        $rows[count($rows) - 1][] = [$i, $spec];
    }
    $canvas = sceneWidth();
    $out = [];
    foreach ($rows as $r => $row) {
        $rw = rowWidth($row);
        $x = ($canvas - $rw) / 2;
        foreach ($row as [$i, [$ch, $w, $face, $tex, $tilt, $lift, $layers]]) {
            $out[] = ['ch' => $ch, 'w' => $w, 'x0' => $x, 'face' => $face, 'tex' => $tex, 'tilt' => $tilt,
                'lift' => $lift + ($drop[$i] ?? 0.0), 'layers' => $layers, 'i' => $i, 'y0' => $r * lineH()];
            $x += $w + GAP;
        }
    }
    return $out;
}

function lineH(): float
{
    return FACE_H + DEPTH + LINE_GAP;
}

function rowWidth(array $row): float
{
    $w = -GAP;
    foreach ($row as [, $spec]) {
        $w += $spec[1] + GAP;
    }
    return $w;
}

function rowCount(): int
{
    return 1 + count(array_filter(LETTERS, static fn ($l) => $l[0] === '/'));
}

function sceneWidth(): int
{
    if (CANVAS_W > 0) {
        return CANVAS_W;
    }
    $best = 0.0; $w = -GAP;
    foreach (LETTERS as [$ch, $lw]) {
        if ($ch === '/') {
            $best = max($best, $w); $w = -GAP;
            continue;
        }
        $w += $lw + GAP;
    }
    return (int) ceil(max($best, $w));
}

/** Signed distance of letter $L at canvas point (x, y), tilt applied. */
function letterSd(array $L, float $x, float $y): float
{
    $cx = $L['x0'] + $L['w'] / 2;
    $cy = MARGIN_TOP + $L['y0'] + FACE_H / 2 - $L['lift'];
    $a = deg2rad(-$L['tilt']);
    $dx = $x - $cx; $dy = $y - $cy;
    $lx = $dx * cos($a) - $dy * sin($a) + $L['w'] / 2;
    $ly = $dx * sin($a) + $dy * cos($a) + FACE_H / 2;
    return glyphSd($L['ch'], $L['w'], $lx, $ly);
}

/** Local (untilted) coords for texture/wheel lookups. */
function letterLocal(array $L, float $x, float $y): array
{
    $cx = $L['x0'] + $L['w'] / 2;
    $cy = MARGIN_TOP + $L['y0'] + FACE_H / 2 - $L['lift'];
    $a = deg2rad(-$L['tilt']);
    $dx = $x - $cx; $dy = $y - $cy;
    return [$dx * cos($a) - $dy * sin($a) + $L['w'] / 2, $dx * sin($a) + $dy * cos($a) + FACE_H / 2];
}

function sweet(string $name, int $role): array
{
    return lkHex(SWEETS[$name][$role]);
}

$GLOBALS['PINS16'] = [];
function pin(array $c, string $sweet, int $role): array
{
    $GLOBALS['PINS16'][implode(',', $c)] = ANSI16[$sweet][$role];
    return $c;
}

/** Face colour for letter $L at canvas point (x,y) with sd $d (< 0). */
function faceColour(array $L, float $x, float $y, float $d): array
{
    [$lx, $ly] = letterLocal($L, $x, $y);
    $name = $L['face'];
    $role = 1;
    if ($name === 'wheel') {
        [$name, $role, $c] = wheelColour($L, $lx, $ly);
    } else {
        $t = max(0.0, min(1.0, $ly / FACE_H));
        $c = lkGradient([SWEETS[$name][0], SWEETS[$name][1], SWEETS[$name][1], SWEETS[$name][2]], 0.15 + 0.85 * $t);
        $c = texture($L['tex'], $c, $name, $x, $y, $role);
        // glazed shine: a short diagonal streak across the upper-left of the slab
        $streak = abs(($lx + 0.55 * $ly) - GLOSS_LINE);
        if ($ly < GLOSS_MAX_Y && $d < -0.7 && $streak < 0.55) {
            $c = lkMix($c, [255, 255, 255], 0.55 * (1 - $streak / 0.55) + 0.15);
            $role = 0;
        }
    }

    // pillow bevel from the SDF gradient
    $e = max(0.0, min(1.0, 1.0 + $d / BEVEL));
    if ($e > 0) {
        $eps = 0.25;
        $gx = letterSd($L, $x + $eps, $y) - letterSd($L, $x - $eps, $y);
        $gy = letterSd($L, $x, $y + $eps) - letterSd($L, $x, $y - $eps);
        $gl = sqrt($gx * $gx + $gy * $gy) ?: 1.0;
        $lam = ($gx * LIGHT[0] + $gy * LIGHT[1]) / $gl;   // outward normal · towards-light
        if ($lam > 0) {
            $c = lkMix($c, lkMix(sweet($name, 0), [255, 255, 255], 0.5), min(1.0, $lam * $e * 0.85));
            if ($role === 1 && $lam * $e > 0.45) {
                $role = 0;
            }
        } else {
            $c = lkMix($c, lkScale(sweet($name, 2), 0.75), min(1.0, -$lam * $e * 0.9));
            if (-$lam * $e > 0.4) {
                $role = 2;
            }
        }
    }
    return pin($c, $name, $role);
}

function texture(?string $tex, array $c, string $name, float $x, float $y, int &$role): array
{
    $ix = (int) floor($x * 2); $iy = (int) floor($y);
    switch ($tex) {
        case 'coconut':
            // short horizontal shreds of desiccated coconut
            $n = lkNoise(intdiv($ix, 2), $iy, 11);
            if ($n > 0.88) {
                return lkMix($c, [255, 252, 245], 0.55);
            }
            if ($n < 0.08) {
                return lkMix($c, sweet($name, 2), 0.35);
            }
            return $c;
        case 'bobble':
            // nonpareil bumps: lit top-left dot, shaded bottom-right dot
            $n = lkNoise($ix, $iy, 23);
            if ($n > 0.88) {
                return lkMix($c, sweet($name, 0), 0.7);
            }
            if ($n < 0.07) {
                $role = 2;
                return lkMix($c, sweet($name, 2), 0.6);
            }
            return $c;
        case 'sugar':
            $n = lkNoise($ix, $iy, 37);
            if ($n > 0.96) {
                $role = 0;
                return lkMix($c, [255, 255, 255], 0.65);
            }
            return $c;
    }
    return $c;
}

/** @return array{0:string,1:int,2:array} sweet, role, colour for the Catherine-wheel O. */
function wheelColour(array $L, float $lx, float $ly): array
{
    $rx = $L['w'] / 2; $ry = FACE_H / 2;
    $u = ($lx - $rx) / $rx; $v = ($ly - $ry) / $ry;
    $r = sqrt($u * $u + $v * $v);
    $th = atan2($v, $u);
    // dome light: brighter towards the top-left
    $dome = max(-1.0, min(1.0, -($u * 0.55 + $v * 0.83)));
    if ($r > WHEEL['rim']) {
        $c = lkMix(sweet('pink', 1), $dome > 0 ? sweet('pink', 0) : sweet('pink', 2), abs($dome) * 0.8);
        $role = $dome > 0.4 ? 0 : ($dome < -0.4 ? 2 : 1);
        $n = lkNoise((int) floor($lx * 2 + $L['x0'] * 2), (int) floor($ly), 11);
        if ($n > 0.80) {
            $c = lkMix($c, [255, 252, 245], 0.55);
        }
        return ['pink', $role, $c];
    }
    if ($r < WHEEL['core']) {
        $c = lkMix(sweet('blue', 1), sweet('blue', 0), max(0.0, -($u * 0.55 + $v * 0.83) / WHEEL['core']) * 0.9);
        $n = lkNoise((int) floor($lx * 2), (int) floor($ly), 23);
        if ($n > 0.75) {
            $c = lkMix($c, [255, 255, 255], 0.6);
        }
        return ['blue', $n > 0.75 ? 0 : 1, $c];
    }
    $s = ($r - WHEEL['core']) / (WHEEL['rim'] - WHEEL['core']) * WHEEL['turns'] - (WHEEL['spiral'] ? ($th + M_PI) / (2 * M_PI) : 0.0);
    $f = $s - floor($s);
    if ($f < WHEEL['coilFill']) {
        // liquorice coil: cylindrical gloss across the strand
        $across = $f / WHEEL['coilFill'];
        $gloss = max(0.0, 1.0 - abs($across - 0.3) * 4) * (0.5 + 0.5 * max(0.0, $dome));
        $c = lkMix(sweet('liq', 1), sweet('liq', 0), $gloss);
        return ['liq', $gloss > 0.35 ? 0 : 1, $c];
    }
    $c = lkMix(sweet('cream', 1), $dome > 0 ? sweet('cream', 0) : sweet('cream', 2), abs($dome) * 0.6);
    return ['cream', 1, $c];
}

/** Side (extruded sandwich) colour for depth k (1..DEPTH). */
function sideColour(array $L, int $k, float $x, float $sideLight): array
{
    $name = $L['layers'][$k - 1];
    $prev = $L['layers'][$k - 2] ?? null;
    $next = $L['layers'][$k] ?? null;
    $c = sweet($name, 2);
    $role = 2;
    if ($name === 'liq') {
        // glossy top edge on a liquorice layer
        $c = $prev !== 'liq' ? sweet('liq', 0) : sweet('liq', 1);
        $role = $prev !== 'liq' ? 0 : 1;
    } else {
        $c = $prev === null ? lkMix(sweet($name, 1), sweet($name, 2), 0.55) : lkMix(sweet($name, 1), sweet($name, 2), 0.3);
        $role = $prev === null ? 2 : 1;
        if ($next === null) {
            $c = lkScale($c, 0.8);
            $role = 2;
        }
    }
    $c = lkScale($c, $sideLight);
    return pin($c, $name, $role);
}

// ================================================================ rasterise

/** @return list<list<?array>> rgb|null pixels at SX sub-columns per cell */
function pixels(array $drop = []): array
{
    $sx = RASTER === 'quad' ? 2 : 1;
    $W = sceneWidth() * $sx;
    $H = (int) ceil(rowCount() * lineH() - LINE_GAP);
    $H += $H % 2;
    $letters = scene($drop);
    $face = [];   // [y][x] => [letterIdx, d]
    $ss = [0.2, 0.5, 0.8];
    for ($y = 0; $y < $H; $y++) {
        for ($x = 0; $x < $W; $x++) {
            $x0 = $x / $sx; $cxp = ($x + 0.5) / $sx; $cyp = $y + 0.5;
            foreach ($letters as $li => $L) {
                if ($cxp < $L['x0'] - 2 || $cxp > $L['x0'] + $L['w'] + 2) {
                    continue;
                }
                $in = 0;
                foreach ($ss as $a) {
                    foreach ($ss as $b) {
                        if (letterSd($L, $x0 + $a / $sx, $y + $b) <= 0) {
                            $in++;
                        }
                    }
                }
                if ($in >= 5) {
                    $face[$y][$x] = [$li, min(-0.05, letterSd($L, $cxp, $cyp))];
                    break;
                }
            }
        }
    }
    // drop 1-sub-pixel slivers that tilting aliases out of the edges
    for ($pass = 0; $pass < 2; $pass++) {
        foreach ($face as $y => $row) {
            foreach ($row as $x => $_) {
                $lr = isset($face[$y][$x - 1]) || isset($face[$y][$x + 1]);
                $ud = isset($face[$y - 1][$x]) && isset($face[$y + 1][$x]);
                if (!$lr && !$ud) {
                    unset($face[$y][$x]);
                }
            }
        }
    }
    $px = array_fill(0, $H, array_fill(0, $W, null));
    for ($y = 0; $y < $H; $y++) {
        for ($x = 0; $x < $W; $x++) {
            if (isset($face[$y][$x])) {
                [$li, $d] = $face[$y][$x];
                $px[$y][$x] = faceColour($letters[$li], ($x + 0.5) / $sx, $y + 0.5, $d);
                continue;
            }
            // inside counters / tight openings only a thin lip of the side shows, so
            // the letter's holes stay open and readable
            $squeezed = isset($face[$y + 1][$x]) || isset($face[$y + 2][$x]) || isset($face[$y + 3][$x]);
            for ($k = 1; $k <= ($squeezed ? 1 : DEPTH); $k++) {
                if (isset($face[$y - $k][$x])) {
                    $li = $face[$y - $k][$x][0];
                    // side faces turning right of the light get darker; a left-edge
                    // of the slab catches a little light
                    $leftOpen = !isset($face[$y - $k][$x - $sx]) && !isset($face[$y - $k + 1][$x - $sx]);
                    $rightOpen = !isset($face[$y - $k][$x + $sx]);
                    $light = 0.92 + ($leftOpen ? 0.12 : 0.0) - ($rightOpen ? 0.18 : 0.0);
                    $px[$y][$x] = sideColour($letters[$li], $k, $x, $light);
                    break;
                }
            }
        }
    }
    return $px;
}

function cells(array $drop = []): array
{
    $px = pixels($drop);
    return RASTER === 'quad' ? qrRaster($px) : lkHalfBlock($px);
}

// ================================================================ animation

/** Bounce-in: each letter falls from above, staggered left→right. */
function dropOffsets(float $t): array
{
    $out = [];
    foreach (LETTERS as $i => $_) {
        $start = $i * 0.07;
        $p = max(0.0, min(1.0, ($t - $start) / 0.55));
        $out[$i] = $p >= 1.0 ? 0.0 : (FACE_H + DEPTH + 4) * (1.0 - easeOutBounce($p));
    }
    return $out;
}

function easeOutBounce(float $x): float
{
    $n = 7.5625; $d = 2.75;
    if ($x < 1 / $d) { return $n * $x * $x; }
    if ($x < 2 / $d) { $x -= 1.5 / $d; return $n * $x * $x + 0.75; }
    if ($x < 2.5 / $d) { $x -= 2.25 / $d; return $n * $x * $x + 0.9375; }
    $x -= 2.625 / $d;
    return $n * $x * $x + 0.984375;
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$dir = dirname(__DIR__);
$static = cells();
$animFrames = [];
for ($f = 0; $write && $f < 22; $f++) {
    $animFrames[] = cells(dropOffsets($f * 0.06));   // 22 × 60 ms ≈ 1.3 s of motion
}
$pins = $GLOBALS['PINS16'];
$only = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--only=')) {
        $only = substr($a, 7);
    }
}
foreach (['tc', '256', '16'] as $depth) {
    if ($only !== null && $only !== $depth) {
        continue;
    }
    $ansi = lkEncode($static, $depth, $pins);
    [$w, $h] = lkVerify($ansi, $depth);
    lkCheckDepth($ansi, $depth);
    if (!$write) {
        echo $ansi;
    }
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    if ($write) {
        $r = skWriteSkinny($dir, SLUG, $depth, $ansi, DESCRIPTION . " ($depth colours)", [...TAGS, $depth === 'tc' ? 'truecolor' : "ansi-$depth"]);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        $frames = array_map(static fn ($c) => lkEncode($c, $depth, $pins), $animFrames);
        $anim = lkAnim($frames, $ansi);
        $r = skWriteSkinny($dir, SLUG . '-anim', $depth, $anim,
            'Animated skinny licorice-allsorts (CANDY over TOP): the sweets drop in one by one from above and bounce to rest on the static logo (~1.4 s at 60 ms/frame; play with tools/logo-play.php <file> 60). ' . "($depth colours)",
            ['licorice-allsorts', 'skinny', 'animated', 'bounce-drop-in', 'extruded-3d', 'catherine-wheel-o', $depth === 'tc' ? 'truecolor' : "ansi-$depth"]);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
}
