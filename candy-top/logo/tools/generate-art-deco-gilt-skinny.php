<?php

declare(strict_types=1);

/**
 * generate-art-deco-gilt-skinny — 40-col SKINNY variant of art-deco-gilt (v4).
 *
 * Same design, re-laid-out for ≤ 40 columns by STACKING instead of squashing:
 *  - row 0 / last row: btop panel frame (tabs auto-drop to fit: ¹cpu ²mem ³net,
 *    - 2000ms +, ▼/▲ MiB/s footers);
 *  - rows 1-4: CANDY in URW Gothic Demi across the full width, every letter a
 *    different glossy candy colour with a gilt rim (emboldened by 1 sub-pixel
 *    so the 4-row letters keep 2-px strokes);
 *  - rows 5-9: the mini cpu box (braille cpu graph + ■ meters) on the left,
 *    TOP in tall Nimbus Sans Narrow streamline metal (gold→coral→pink→violet)
 *    with three speed lines trailing off the P;
 *  - a dim braille cpu area graph in the free black cells around TOP, fading
 *    to black at the sides (the upload graph of the full-size logo is dropped:
 *    at this size it only speckled the CANDY letters).
 * Animation (-anim): graphs scroll left and the meters fill, ending on the
 * static frame.
 *
 * Built from the shared skinny tools: skinny-type-fit (stfEmbolden),
 * skinny-btop-parts (frame, graphs, meters, mini box), skinny-palette
 * (hue-locked 256 / hue-band 16), skinny-raster-kit (skWriteSkinny: verified
 * write + single-append logos.jsonl line).
 *
 * Usage:
 *   php generate-art-deco-gilt-skinny.php              preview tc/256/16 (+ verify)
 *   php generate-art-deco-gilt-skinny.php --write      write .ansi files (+anim) + logos.jsonl lines
 *   php generate-art-deco-gilt-skinny.php --help
 */

require_once __DIR__ . '/logo-kit.php';
require_once __DIR__ . '/ttf-coverage.php';
require_once __DIR__ . '/sextant-image-raster.php';
require_once __DIR__ . '/skinny-raster-kit.php';
require_once __DIR__ . '/skinny-type-fit.php';
require_once __DIR__ . '/skinny-btop-parts.php';
require_once __DIR__ . '/skinny-palette.php';

// ================================================================ DESIGN DATA

const SLUG = 'art-deco-gilt-skinny';
const COLS = 40;
const ROWS = 11;
const PW = COLS * 2;
const PH = ROWS * 3;

const FONT_CANDY = '/usr/share/fonts/opentype/urw-base35/URWGothic-Demi.otf';
const FONT_TOP = '/usr/share/fonts/opentype/urw-base35/NimbusSansNarrow-Bold.otf';

/** Ink boxes in CELLS [x0, y0, x1, y1) — converted to sub-pixels below. */
const CELLBOX_CANDY = [1.5, 1.2, 38.5, 5.0];
const CANDY_FIT = ['maxCondense' => 0.7, 'tracking' => 55.0, 'ss' => 4];
const CANDY_BOLD = 0.0;
const CELLBOX_TOP = [15.5, 5.2, 32.0, 10.0];
const TOP_FIT = ['maxCondense' => 0.6, 'tracking' => 12.0, 'ss' => 4];
const TOP_BOLD = 0.0;

const CANDY_HUES = ['#FF3D9A', '#FF8C1A', '#FFE135', '#3DF28A', '#2EC8FF'];
const CANDY_RIM = '#E0A030';
const CANDY_RIM_DARK = '#7A4510';
const RIM_R = 1.0;
const TOP_SWEEP = ['#FFD54A', '#FFC23A', '#FF6B6B', '#FF4FA0', '#B36BFF'];
const SPEED_LINES = [0.40, 0.60, 0.80];
const HALO = 1.5;

/** Mini cpu box [x0, y0, x1, y1] cells inclusive; meters [gradient name, fill]. */
const MINI = [1, 5, 13, 9];
const MINI_LINE = '#77ca9b';
const METERS = [['cpu', 0.70], ['used', 0.45]];

/** Background graphs: [x0, y0, x1, y1, fromTop, gradient, seed, brightness]. */
const BG_GRAPHS = [
    [14, 5, COLS - 2, 9, false, 'cpu', 7, 0.75],   // the full-size upload graph above is dropped: at 4 rows it only speckled CANDY
];
const EDGE_FADE = 5.0;

const FRAME = [
    'top' => null,   // sbTabs(['cpu', 'mem', 'net', 'proc']) — set in frameSpec()
    'right' => [['┐', 'line'], ['-', 'hi'], [' 2000ms ', 'title'], ['+', 'hi'], ['┌─╮', 'line']],
    'botLeft' => [['╰─┘', 'line'], ['▼', 'down'], [' 48.2 MiB/s', 'fg'], ['└', 'line']],
    'botRight' => [['┘', 'line'], ['▲', 'up'], [' 3.1 MiB/s', 'fg'], ['└─╯', 'line']],
];

const ANIM_FRAMES = 16;

/** 16-colour mapping for the letters (skinny-palette opts). */
const PAL = ['black' => 0.30];

const DESCRIPTION = 'Skinny 40-col art-deco-gilt: CANDY TOP as a compact btop-style monitor panel on black. CANDY (geometric Deco URW Gothic, each letter a different glossy candy colour with a gilt rim) runs across the top; below it a mini cpu box (braille graph + ■ gradient meters) sits beside a tall narrow streamline-metal TOP (gold-coral-pink-violet sweep, trailing speed lines); smooth letters at sextant resolution, a dim braille cpu graph fading to black, btop frame with ¹cpu ²mem ³net tabs, - 2000ms +, ▼/▲ MiB/s.';
const TAGS = ['skinny', 'art-deco', 'btop-style', 'resource-monitor', 'braille-graphs', 'gradient-meters', 'black-background', 'ttf-sextant-letters', 'glossy-candy-letters', 'streamline-metal', 'stacked-layout'];

// ================================================================ LETTERS

function subBox(array $cellBox): array
{
    return [(int) round($cellBox[0] * 2), (int) round($cellBox[1] * 3), (int) round($cellBox[2] * 2), (int) round($cellBox[3] * 3)];
}

function geo(): array
{
    static $g = null;
    if ($g !== null) {
        return $g;
    }
    $candy = tcRender(FONT_CANDY, 'CANDY', 2, 3, PW, PH, subBox(CELLBOX_CANDY), CANDY_FIT);
    $candy['cov'] = stfEmbolden($candy['cov'], CANDY_BOLD, $candy['letter']);
    $top = tcRender(FONT_TOP, 'TOP', 2, 3, PW, PH, subBox(CELLBOX_TOP), TOP_FIT);
    $top['cov'] = stfEmbolden($top['cov'], TOP_BOLD, $top['letter']);
    $rim = tcDilate($candy['cov'], RIM_R, 1.5);
    $g = ['candy' => $candy, 'top' => $top, 'rim' => $rim];
    $b = $top['box'];
    $g['speed'] = [];
    foreach (SPEED_LINES as $i => $v) {
        $ly = (int) round($b[1] + $v * ($b[3] - $b[1]));
        $x0 = (int) BOX0($top, $ly);
        $g['speed'][$ly] = [$x0, (3.0 + 1.0 * $i) * 2];
    }
    $ink = [];
    for ($y = 0; $y < PH; $y++) {
        for ($x = 0; $x < PW; $x++) {
            $ink[$y][$x] = max($rim[$y][$x], $top['cov'][$y][$x] >= 0.3 ? 1.0 : 0.0);
        }
    }
    foreach ($g['speed'] as $ly => [$x0, $len]) {
        for ($x = $x0 + 2; $x < min(PW - 3, $x0 + (int) $len); $x++) {
            $ink[$ly][$x] = 1.0;
        }
    }
    $g['occ'] = tcDilate($ink, HALO, 1.5);
    return $g;
}

/** Right-most inked sub-pixel of TOP on row y (where a speed line starts). */
function BOX0(array $top, int $y): int
{
    $last = (int) (CELLBOX_TOP[0] * 2);
    foreach ($top['cov'][$y] as $x => $c) {
        if ($c > 0.3) {
            $last = $x;
        }
    }
    return $last;
}

function letterPx(int $x, int $y): array
{
    $g = geo();
    $cc = $g['candy']['cov'][$y][$x];
    if ($cc > 0.02 || $g['rim'][$y][$x] >= 0.5) {
        $v = $g['candy']['v'][$y][$x];
        $base = $g['rim'][$y][$x] >= 0.5
            ? lkMix(lkHex(CANDY_RIM), lkHex(CANDY_RIM_DARK), max(0.0, min(1.0, $v)))
            : [0, 0, 0];
        if ($cc <= 0.02) {
            return $base;
        }
        $hue = lkHex(CANDY_HUES[max(0, $g['candy']['letter'][$y][$x])]);
        $c = lkMix($hue, [255, 255, 255], 0.30 * max(0.0, 1 - $v / 0.4) ** 2);
        $c = lkShade($c, -0.25 * max(0.0, $v - 0.5) / 0.5);
        return lkMix($base, $c, min(1.0, $cc * 1.15));
    }
    $tc = $g['top']['cov'][$y][$x];
    if ($tc > 0.02) {
        $u = $g['top']['u'][$y][$x];
        $v = $g['top']['v'][$y][$x];
        $c = lkGradient(TOP_SWEEP, max(0.0, min(1.0, $u)));
        $c = match (true) {
            $v < 0.10 => lkMix($c, [255, 255, 255], 0.35),
            $v < 0.45 => lkMix($c, [255, 255, 255], 0.15 * (0.45 - $v) / 0.35),
            default => lkShade($c, -0.18 * ($v - 0.45) / 0.55),
        };
        return lkMix([0, 0, 0], $c, min(1.0, $tc * 1.15));
    }
    if (isset($g['speed'][$y])) {
        [$x0, $len] = $g['speed'][$y];
        $i = array_search($y, array_keys($g['speed']), true);
        if ($x > $x0 + 1 && $x - $x0 < $len && $x < PW - 3) {
            return lkScale(lkGradient(TOP_SWEEP, 0.7 + 0.1 * $i), 1 - ($x - $x0) / $len);
        }
    }
    return [0, 0, 0];
}

function occupied(int $cx, int $cy): bool
{
    $occ = geo()['occ'];
    for ($k = 0; $k < 6; $k++) {
        if (($occ[$cy * 3 + intdiv($k, 2)][$cx * 2 + $k % 2] ?? 0) >= 0.5) {
            return true;
        }
    }
    return false;
}

// ================================================================ COMPOSE

function inMini(int $cx, int $cy): bool
{
    return $cx >= MINI[0] && $cx <= MINI[2] && $cy >= MINI[1] && $cy <= MINI[3];
}

/** Inside TOP's ink bounding box (keeps graph dots out of the O / P counters). */
function inTopInk(int $cx, int $cy): bool
{
    $b = geo()['top']['box'];
    return $cx * 2 + 1 >= $b[0] && $cx * 2 <= $b[2] && $cy * 3 + 2 >= $b[1] && $cy * 3 <= $b[3];
}

/** @return array{0: array, 1: array} [cells, pins16] for step (ANIM_FRAMES-1 = static). */
function compose(string $depth, int $step): array
{
    static $letters = [];
    if (!isset($letters[$depth])) {
        $img = [];
        for ($y = 0; $y < PH; $y++) {
            for ($x = 0; $x < PW; $x++) {
                $img[$y][$x] = letterPx($x, $y);
            }
        }
        $letters[$depth] = siCells(spQuantise($img, $depth, PAL), $depth !== 'tc');
    }
    $cells = $letters[$depth];
    $pins = ['0,0,0' => 30];
    $shift = ANIM_FRAMES - 1 - $step;
    $n = COLS * 2 + ANIM_FRAMES * 2 + 8;
    // background graphs in every free cell
    foreach (BG_GRAPHS as [$x0, $y0, $x1, $y1, $fromTop, $grad, $seed, $bright]) {
        sbGraph($cells, $pins, $x0, $y0, $x1, $y1, sbSeries($n, $seed), sbGrad($grad), [
            'fromTop' => $fromTop, 'bright' => $bright, 'fade' => EDGE_FADE, 'canvasW' => COLS,
            'shift' => $x0 * 2 + (ANIM_FRAMES - 1 - $shift) * 2,
            'skip' => static fn (int $cx, int $cy): bool => inMini($cx, $cy) || occupied($cx, $cy) || inTopInk($cx, $cy),
        ]);
    }
    // mini cpu box: tab, braille graph row(s), meters
    [$bx0, $by0, $bx1, $by1] = MINI;
    $line = lkHex(MINI_LINE);
    sbBox($cells, $pins, $bx0, $by0, $bx1, $by1, $line, 92);
    sbRuns($cells, $pins, $bx0 + 1, $by0, [['┐', MINI_LINE], ['¹', 'hi'], ['cpu', 'title'], ['┌', MINI_LINE]]);
    $iw = $bx1 - $bx0 - 1;
    $graphRows = ($by1 - $by0 - 1) - count(METERS);
    if ($graphRows > 0) {
        sbGraph($cells, $pins, $bx0 + 1, $by0 + 1, $bx1 - 1, $by0 + $graphRows, sbSeries($n, 23, 0.25, 0.8, 1), sbGrad('cpu'), [
            'shift' => (ANIM_FRAMES - 1 - $shift) * 2,
        ]);
    }
    $grow = min(1.0, ($step + 1) / (ANIM_FRAMES * 0.7));
    foreach (METERS as $m => [$grad, $fill]) {
        sbMeter($cells, $pins, $bx0 + 1, $by0 + 1 + $graphRows + $m, $iw, $fill * $grow, sbGrad($grad));
    }
    // outer btop frame
    $spec = FRAME;
    $spec['top'] = sbTabs(['cpu', 'mem', 'net', 'proc']);
    sbFrame($cells, $pins, COLS, ROWS, $spec);
    if ($depth === '256') {
        [$cells] = spCells($cells, '256');
    }
    return [$cells, $pins];
}

function encodeStep(string $depth, int $step): string
{
    [$cells, $pins] = compose($depth, $step);
    return lkEncode($cells, $depth, $depth === '16' ? $pins : [], 'nearest');
}

// ================================================================ CLI

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    preg_match('#/\*\*(.*?)\*/#s', file_get_contents(__FILE__), $m);
    echo preg_replace('/^ \* ?/m', '', $m[1]), "\n";
    exit(0);
}
$write = in_array('--write', $argv, true);
$dir = dirname(__DIR__);
foreach (['tc', '256', '16'] as $depth) {
    $static = encodeStep($depth, ANIM_FRAMES - 1);
    [$w, $h] = lkVerify($static, $depth);
    lkCheckDepth($static, $depth, $depth);
    echo $static;
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    if ($write) {
        $r = skWriteSkinny($dir, SLUG, $depth, $static, DESCRIPTION . " ($depth)", TAGS);
        fwrite(STDERR, "  wrote {$r['file']} (jsonl: {$r['jsonl']})\n");
        $fs = [];
        for ($f = 0; $f < ANIM_FRAMES - 1; $f++) {
            $fs[] = encodeStep($depth, $f);
        }
        $r = skWriteSkinny($dir, SLUG . '-anim', $depth, lkAnim($fs, $static), 'Animated ' . DESCRIPTION . ' The braille graphs scroll left like btop and the meters fill (' . ANIM_FRAMES . " frames), ending on the static logo; play with tools/logo-play.php <file> 80. ($depth)", [...TAGS, 'scrolling-graphs', 'animated']);
        fwrite(STDERR, "  wrote {$r['file']} (jsonl: {$r['jsonl']})\n");
    }
}
