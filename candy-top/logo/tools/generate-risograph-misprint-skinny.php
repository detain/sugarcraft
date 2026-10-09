<?php

declare(strict_types=1);

/**
 * generate-risograph-misprint-skinny — the ≤40-col variant of risograph-misprint.
 *
 * Same design (Bookman Demi Italic sunset key plate, teal→violet misprint
 * extrusion with black keyline, btop-style CPU graph panel) restacked for a
 * 40×10 grid: "candy" across the top, "top" bottom-left, the cpu panel
 * bottom-right. Lines are rendered separately with tcRender() and merged
 * (textLines()); everything else is the original pipeline. Writes through
 * skWriteSkinny() (skinny-kit). Usage: php generate-risograph-misprint-skinny.php
 * [--write | --out=<dir>]   (static only; no anim for the skinny cut).
 *
 * --- original header ---
 * generate-risograph-misprint (v2) — fluoro riso inks on black stock.
 *
 * "candy top" set in URW Bookman Demi Italic (a 70s poster face), printed as
 * a key plate plus a deliberately mis-registered colour plate, swept into a
 * solid teal → violet 70s extrusion down-right and separated from the key by
 * a black keyline (counters are kept clear so the letters stay open), so the
 * misprint reads as crisp retro lettering instead of noise. The key plate is a fluoro sunset gradient (lemon →
 * tangerine → hot pink) . Off to the side, the "top"
 * in candy-top (the process monitor): a btop-style rounded panel with a CPU
 * history area graph in the same sunset gradient, a bright trace and a
 * mis-registered teal ghost plate. Plus four-point sparkles, a registration
 * mark and an ink-swatch strip. (v2's spinning-top toy + sun code was
 * removed; the sun stays behind SUN_ON = false.)
 *
 * Rendering: tcRender() (tools/ttf-coverage.php) rasterises the TTF at
 * sextant resolution (2×3 sub-pixels per cell, anti-aliased); the scene is
 * painted per sub-pixel; siCells() (tools/sextant-image-raster.php) fits each
 * cell to the best sextant glyph + fg/bg pair. For 256/16 the sub-pixels are
 * quantised FIRST (siQuantise) so cells use real palette colours.
 *
 * Usage:
 *   php generate-risograph-misprint.php               preview tc/256/16 to stdout
 *   php generate-risograph-misprint.php --write       write .ansi + logos.jsonl
 *   php generate-risograph-misprint.php --out=<dir>   write .ansi only (scratch)
 *   php generate-risograph-misprint.php --no-anim
 */

require_once __DIR__ . '/logo-kit.php';
require_once __DIR__ . '/sextant-image-raster.php';
require_once __DIR__ . '/ttf-coverage.php';
require_once __DIR__ . '/skinny-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'risograph-misprint-skinny';
const TEXT = 'candy top';
const FONT = '/usr/share/fonts/opentype/urw-base35/URWBookman-DemiItalic.otf';

const COLS = 40;
const ART_ROWS = 10;
const SW = COLS * 2;             // sextant sub-pixels
const SH = ART_ROWS * 3;

/** Ink box of the key plate (sub-pixels); the extrusion sweeps down-right from it. */
const TEXT_BOX = [0, 0, 80, 30];  // whole grid (u/v come from each line's own box)
/** Stacked lines: [text, sub-pixel ink box x0,y0,x1,y1]. */
const LINES = [
    ['candy', [1, 0, 74, 14]],
    ['top', [2, 14, 42, 30]],
];
/**
 * The mis-registered colour plate, printed as a solid extrusion: the key mask
 * swept STEPS sub-pixels down-right, coloured near → far (teal → violet).
 */
const EXTRUDE = ['dx' => 1, 'dy' => 1, 'steps' => 4, 'near' => '#19E0D8', 'far' => '#5A22E8', 'shadeBottom' => 0.25];
const KEYLINE = 1.0;             // black gap (sub-px) around each plate in front

/** Key plate: vertical sunset (v 0..1), nudged cooler along the line (u). */
const FILL_STOPS = ['#FFF6A0', '#FFC93C', '#FF8A2A', '#FF4F7B', '#FF2FA8'];
const FILL_COOL = '#C04BFF';     // mixed in toward the right end
const FILL_COOL_MAX = 0.28;
const GLOSS = '#FFFFFF';
const GLOSS_AMT = 0.0;           // gloss lip off: at sextant size it reads as a muddy band
const EDGE = [0.3, 0.7];         // coverage smoothstep window (crisper edges)

/**
 * The "top" in candy-top is the process monitor: a btop-style CPU history
 * graph printed as a riso key plate (same extrusion + keyline as the
 * letters), rising over the sunset. Units: cell = 1 wide × 2 tall; the art
 * is COLS × ART_ROWS*2 units. Samples are evenly spaced load values 0..1,
 * joined by straight segments (btop graphs are jagged, not smoothed).
 */
const GRAPH = [
    'x0' => 24.0, 'x1' => 39.0, 'base' => 18.0, 'height' => 4.0, 'top' => 14.0,   // inside the panel frame
    'samples' => [0.20, 0.34, 0.27, 0.50, 0.40, 0.62, 0.47, 0.72, 0.88, 0.63, 0.54, 0.79, 0.68],
    'trace' => '#FFFBE0', 'traceW' => 0.45,
    'stripe' => 1.0, 'stripeDim' => 0.82,
    'ghost' => '#19E0D8', 'ghostDim' => 0.55, 'ghostDx' => 1, 'ghostDy' => -1,
    'grid' => '#221A40', 'gridLevels' => [0.5], 'gridCols' => 0.0,
    'frame' => '#4B3F7A', 'frameCols' => [23, 39], 'frameRows' => [6, 9],
    'label' => 'cpu', 'labelColor' => '#19E0D8', 'valueColor' => '#FFF6A0',
];
const BG_TOP = '#16102A';
const BG_BOTTOM = '#07050D';
const BG_256 = 233;              // flat stock colour at 256

/** Halftone sun: centre/radius in UNITS (cell = 1 wide × 2 tall). */
const SUN_ON = false;            // v2's sunset sun backed the spinning top; the graph panel replaces both
const SUN = ['cx' => 68.0, 'cy' => 9.0, 'r' => 8.6, 'period' => 0.0, 'angle' => 45.0,
    'top' => '#FFB13C', 'bottom' => '#FF2F86', 'dim' => 0.72, 'bars' => [11.4, 13.1, 14.6, 15.9, 17.0]];
/** Halftone ground: a dot field fading up from the bottom edge. */
const GROUND = ['from' => 1.0, 'colour' => '#2A1450', 'period' => 1.5, 'angle' => 0.0];
const SPARKLES = [[77, 2, 0.9], [36, 1, 0.6]];
const SPARKLE = '#FFF1B8';

const MARK = '┼';
const MARK_COLOR = '#5B5470';
const COLOPHON = [               // [text, hex]
    ['riso', '#FF4F7B'], [' · ', '#5B5470'], ['fluoro on black', '#14D2E0'],
    [' · ', '#5B5470'], ['system monitor', '#FFC93C'],
];
const COLOPHON_RIGHT = '';
const SWATCHES = [];

const DESCRIPTION = 'Skinny (≤40 col) cut of risograph-misprint: fluoro riso on black stock, "candy" stacked over "top" in Bookman Demi Italic with the lemon→tangerine→hot-pink sunset key plate and the mis-registered teal-to-violet 70s extrusion (black keyline, counters open); bottom-right a btop-style ╭┐cpu┌┐68%┌╮ panel with a sunset CPU area graph, bright trace and teal ghost plate; sparkles and a registration mark; anti-aliased TTF in sextant blocks.';
const TAGS = ['skinny', 'stacked', 'risograph', 'misregistration', 'fluoro-inks', 'retro-70s', 'bookman-italic', 'offset-plates', 'retro-extrusion', 'btop-panel', 'cpu-graph', 'sextant-blocks', 'ttf-raster', 'dark-stock'];

// ================================================================ BUILD

function text(): array
{
    static $r = null;
    if ($r !== null) {
        return $r;
    }
    // Merge per-line renders: coverage = max, u/v from the line that owns the pixel.
    foreach (LINES as $i => [$txt, $box]) {
        $l = tcRender(FONT, $txt, 2, 3, SW, SH, $box, ['ss' => 4, 'maxCondense' => 0.7]);
        fwrite(STDERR, sprintf("[line %s] condense %.2f\n", $txt, $l['condense']));
        if ($r === null) {
            $r = $l;
            continue;
        }
        foreach ($l['cov'] as $y => $row) {
            foreach ($row as $x => $c) {
                if ($c > $r['cov'][$y][$x]) {
                    $r['cov'][$y][$x] = $c;
                    $r['u'][$y][$x] = $l['u'][$y][$x];
                    $r['v'][$y][$x] = $l['v'][$y][$x];
                }
            }
        }
    }
    return $r;
}

/** Top edge (units) of the graph at x, or null outside the panel. */
function graphTop(float $ux): ?float
{
    $g = GRAPH;
    if ($ux < $g['x0'] || $ux > $g['x1']) {
        return null;
    }
    $n = count($g['samples']) - 1;
    $f = ($ux - $g['x0']) / ($g['x1'] - $g['x0']) * $n;
    $i = min($n - 1, (int) floor($f));
    $v = $g['samples'][$i] + ($g['samples'][$i + 1] - $g['samples'][$i]) * ($f - $i);
    return $g['base'] - $v * $g['height'];
}

/** Graph area colour at a unit point (null outside), revealed left → right up to $reveal. */
function graphPaint(float $ux, float $uy, float $reveal): ?array
{
    $g = GRAPH;
    if ($ux > $g['x0'] + ($g['x1'] - $g['x0']) * $reveal) {
        return null;
    }
    $top = graphTop($ux);
    if ($top === null || $uy < $top || $uy > $g['base']) {
        return null;
    }
    $h = ($g['base'] - $uy) / $g['height'];
    $c = lkGradient(array_reverse(FILL_STOPS), min(1.0, $h * 1.2));
    if ($uy - $top < $g['traceW']) {
        return lkHex($g['trace']);
    }
    if (fmod($ux - $g['x0'], $g['stripe']) >= $g['stripe'] / 2) {
        $c = lkScale($c, $g['stripeDim']);
    }
    return $c;
}

/** Key-art coverage (one mask for the colour plate), and which owns each pixel. */
function art(): array
{
    static $a = null;
    if ($a !== null) {
        return $a;
    }
    $tx = text();
    $cov = $tx['cov'];
    $kind = array_fill(0, SH, array_fill(0, SW, 'text'));
    // Sharpen anti-aliasing: a soft 50% edge makes sextant cells average to mud.
    foreach ($cov as $y => $row) {
        foreach ($row as $x => $c) {
            $k = max(0.0, min(1.0, ($c - EDGE[0]) / (EDGE[1] - EDGE[0])));
            $cov[$y][$x] = $k * $k * (3 - 2 * $k);
        }
    }
    return $a = ['cov' => $cov, 'kind' => $kind, 'u' => $tx['u'], 'v' => $tx['v']];
}

function covAt(array $cov, float $x, float $y): float
{
    return $cov[(int) floor($y)][(int) floor($x)] ?? 0.0;
}

/** Counters of the key art (enclosed background): plates are kept out of them for legibility. */
function holes(): array
{
    static $h = null;
    if ($h !== null) {
        return $h;
    }
    $cov = keyMask();                     // dilated: hairline-broken counters still close
    $seen = array_fill(0, SH, array_fill(0, SW, false));
    $stack = [];
    for ($x = 0; $x < SW; $x++) {
        $stack[] = [$x, 0];
        $stack[] = [$x, SH - 1];
    }
    for ($y = 0; $y < SH; $y++) {
        $stack[] = [0, $y];
        $stack[] = [SW - 1, $y];
    }
    while ($stack) {
        [$x, $y] = array_pop($stack);
        if ($x < 0 || $y < 0 || $x >= SW || $y >= SH || $seen[$y][$x] || $cov[$y][$x] >= 0.5) {
            continue;
        }
        $seen[$y][$x] = true;
        array_push($stack, [$x + 1, $y], [$x - 1, $y], [$x, $y + 1], [$x, $y - 1]);
    }
    $h = [];
    for ($y = 0; $y < SH; $y++) {
        for ($x = 0; $x < SW; $x++) {
            $h[$y][$x] = !$seen[$y][$x] && $cov[$y][$x] < 0.5;
        }
    }
    return $h;
}

/** Dilated (keyline) mask cache. */
function keyMask(): array
{
    static $m = null;
    return $m ??= tcDilate(art()['cov'], KEYLINE + 0.5, 1.5);
}

/** Clustered-dot halftone coverage at unit point (ux,uy), density d. */
function dot(float $ux, float $uy, float $d, float $period, float $angle): float
{
    if ($d <= 0) {
        return 0.0;
    }
    $a = deg2rad($angle);
    $u = ($ux * cos($a) + $uy * sin($a)) / $period;
    $v = (-$ux * sin($a) + $uy * cos($a)) / $period;
    $fu = $u - floor($u) - 0.5;
    $fv = $v - floor($v) - 0.5;
    $r = sqrt(min(1.0, $d) / M_PI) * 1.05;
    $dist = sqrt($fu * $fu + $fv * $fv);
    return max(0.0, min(1.0, ($r - $dist) * 6 + 0.5));   // soft edge → anti-aliased dots
}

/**
 * Paint the scene at one sub-pixel. $t: animation state
 *   'sun' 0..1 ink density, 'offset' [dx,dy] extra misregistration of the colour plate,
 *   'depth' 0..1 extrusion depth, 'key' 0..1 key plate visibility.
 */
function paint(int $x, int $y, array $t): array
{
    $ux = ($x + 0.5) / 2;           // units
    $uy = ($y + 0.5) * 2 / 3;
    $c = lkMix(lkHex(BG_TOP), lkHex(BG_BOTTOM), $y / (SH - 1));

    // Halftone ground.
    $g = GROUND['from'] >= 1.0 ? 0.0 : ($y / (SH - 1) - GROUND['from']) / (1 - GROUND['from']);
    if ($g > 0) {
        $c = lkMix($c, lkHex(GROUND['colour']), dot($ux, $uy, $g * 0.7 * $t['sun'], GROUND['period'], GROUND['angle']));
    }
    // Halftone sun.
    $dx = $ux - SUN['cx'];
    $dy = $uy - SUN['cy'];
    $r = SUN_ON ? sqrt($dx * $dx + $dy * $dy) / SUN['r'] : 2.0;
    if ($r < 1.0) {
        $cut = false;
        foreach (SUN['bars'] as $i => $by) {
            if (abs($uy - $by) < 0.18 + 0.1 * $i) {
                $cut = true;
            }
        }
        if (!$cut) {
            $vt = ($uy - (SUN['cy'] - SUN['r'])) / (2 * SUN['r']);
            $ink = lkScale(lkGradient([SUN['top'], SUN['bottom']], $vt), SUN['dim']);
            if (SUN['period'] > 0) {
                $d = (0.25 + 0.6 * $vt) * (1 - $r ** 6) * $t['sun'];
                $c = lkMix($c, $ink, dot($ux, $uy, $d, SUN['period'], SUN['angle']));
            } else {                                  // solid sun, anti-aliased rim
                $c = lkMix($c, $ink, min(1.0, (1 - $r) * SUN['r'] * 2) * $t['sun']);
            }
        }
    }
    // Sparkles: thin 4-point stars.
    foreach (SPARKLES as [$sx, $sy, $s]) {
        $ax = abs($x - $sx);
        $ay = abs($y - $sy);
        if (($ax === 0 && $ay <= 1) || ($ay === 0 && $ax <= 2)) {
            $c = lkMix($c, lkHex(SPARKLE), ($ax + $ay === 0 ? 1.0 : 0.55) * $s * $t['sun']);
        }
    }

    // Graph panel: grid, a mis-registered teal ghost plate, then the area.
    $g = GRAPH;
    if ($ux >= $g['x0'] && $ux <= $g['x1'] && $uy >= $g['top'] && $uy <= $g['base']) {
        $on = false;
        foreach ($g['gridLevels'] as $lv) {
            if (abs($uy - ($g['base'] - $lv * $g['height'])) < 0.2) {
                $on = true;
            }
        }
        if ($g['gridCols'] > 0 && fmod($ux - $g['x0'], $g['gridCols']) < 0.3) {
            $on = true;
        }
        if ($on) {
            $c = lkMix($c, lkHex($g['grid']), $t['sun']);
        }
        $gh = graphPaint($ux - $g['ghostDx'] / 2, $uy - $g['ghostDy'] * 2 / 3, $t['graph']);
        if ($gh !== null) {
            $c = lkScale(lkHex($g['ghost']), $g['ghostDim']);
        }
        $ar = graphPaint($ux, $uy, $t['graph']);
        if ($ar !== null) {
            $c = $ar;
        }
    }

    $tx = art();
    $cov = $tx['cov'];
    $key = keyMask();
    // Extrusion far → near, then the keyline knocks it back to black around
    // the key plate. Kept out of counters so the letters stay open.
    [$ex, $ey] = $t['offset'];
    $steps = (int) round(EXTRUDE['steps'] * $t['depth']);
    if (!holes()[$y][$x] && covAt($key, $x, $y) <= 0) {
        for ($k = $steps; $k >= 1; $k--) {
            $px = $x - $ex - EXTRUDE['dx'] * $k;
            $py = $y - $ey - EXTRUDE['dy'] * $k;
            $a = covAt($cov, $px, $py);
            if ($a > 0) {
                $col = lkMix(lkHex(EXTRUDE['near']), lkHex(EXTRUDE['far']), ($k - 1) / max(1, EXTRUDE['steps'] - 1));
                $col = lkShade($col, -EXTRUDE['shadeBottom'] * max(0.0, min(1.0, $py / SH)));
                $c = lkMix($c, $col, $a);
            }
        }
    }
    // Key plate.
    $a = covAt($cov, $x, $y) * $t['key'];
    if ($a > 0) {
        {
            $u = max(0.0, min(1.0, $tx['u'][$y][$x]));
            $v = max(0.0, min(1.0, $tx['v'][$y][$x]));
            $fill = lkGradient(FILL_STOPS, $v);
            $fill = lkMix($fill, lkHex(FILL_COOL), FILL_COOL_MAX * $u * $u * $v);
            // gloss lip on top edges only
            if (covAt($cov, $x, $y - 1) < 0.35 && covAt($cov, $x, $y) > 0.5) {
                $fill = lkMix($fill, lkHex(GLOSS), GLOSS_AMT);
            }
        }
        $c = lkMix($c, $fill, $a);
    }
    return $c;
}

function scene(array $t): array
{
    $img = [];
    for ($y = 0; $y < SH; $y++) {
        for ($x = 0; $x < SW; $x++) {
            $img[$y][$x] = paint($x, $y, $t);
        }
    }
    return $img;
}

/** 16-colour picker: dark bg → black, dim warm (the sun) → red, otherwise hue-preserving. */
function map16(array $c): array
{
    $a = lkAnsi16();
    $max = max($c);
    if ($max < 70) {
        return $a[30];
    }
    $code = lkTo16($c);
    if ($max < 200 && in_array($code, [33, 93, 91], true)) {
        return $a[31];
    }
    return $a[$code];
}

/** 256 pre-pass: the near-black stock collapses to one flat xterm grey (nearest-256 bands it). */
function pre256(array $img): array
{
    $flat = lkXterm256()[BG_256];
    foreach ($img as $y => $row) {
        foreach ($row as $x => $c) {
            if (max($c) < 48) {
                $img[$y][$x] = $flat;
            }
        }
    }
    return $img;
}

/** Sub-pixel image → cells at depth, then frame (marks + colophon). */
function cellsFor(array $img, string $depth): array
{
    $q = siQuantise($depth === '256' ? pre256($img) : $img, $depth, 0.0, map16(...));
    $cells = siCells($q, $depth !== 'tc');
    $bg = $depth === 'tc' ? lkHex(BG_BOTTOM) : siQuantise($depth === '256' ? pre256([[lkHex(BG_BOTTOM)]]) : [[lkHex(BG_BOTTOM)]], $depth, 0.0, map16(...))[0][0];
    $fix = static fn (string $hex): array => $depth === 'tc' ? lkHex($hex) : siQuantise([[lkHex($hex)]], $depth, 0.0, map16(...))[0][0];
    $mark = $fix(MARK_COLOR);
    lkPut($cells, 0, 0, MARK, $mark, null);
    // Overlays skip cells the print already inks, so the 'y' tail can cross the panel border.
    $dark = static function (int $cx, int $cy) use ($img): bool {
        for ($k = 0; $k < 6; $k++) {
            if (max($img[$cy * 3 + intdiv($k, 2)][$cx * 2 + $k % 2] ?? [0, 0, 0]) > 60) {
                return false;
            }
        }
        return true;
    };
    $put = static function (int $x, int $y, string $g, array $fg) use (&$cells, $dark): void {
        if ($dark($x, $y)) {
            lkPut($cells, $x, $y, $g, $fg, null);
        }
    };
    $text = static function (int $x, int $y, string $t, array $fg) use ($put): void {
        foreach (mb_str_split($t) as $i => $ch) {
            $put($x + $i, $y, $ch, $fg);
        }
    };
    // btop-style rounded panel frame with title + current value on the top border.
    [$fx0, $fx1] = GRAPH['frameCols'];
    [$fy0, $fy1] = GRAPH['frameRows'];
    $fc = $fix(GRAPH['frame']);
    for ($x = $fx0 + 1; $x < $fx1; $x++) {
        $put($x, $fy0, '─', $fc);
        $put($x, $fy1, '─', $fc);
    }
    for ($y = $fy0 + 1; $y < $fy1; $y++) {
        $put($fx0, $y, '│', $fc);
        $put($fx1, $y, '│', $fc);
    }
    $put($fx0, $fy0, '╭', $fc);
    $put($fx1, $fy0, '╮', $fc);
    $put($fx0, $fy1, '╰', $fc);
    $put($fx1, $fy1, '╯', $fc);
    $put($fx0 + 1, $fy0, '┐', $fc);
    $text($fx0 + 2, $fy0, GRAPH['label'], $fix(GRAPH['labelColor']));
    $put($fx0 + 2 + mb_strlen(GRAPH['label']), $fy0, '┌', $fc);
    $val = (int) round(GRAPH['samples'][count(GRAPH['samples']) - 1] * 100) . '%';
    $vx = $fx1 - 2 - mb_strlen($val);
    $put($vx - 1, $fy0, '┐', $fc);
    $text($vx, $fy0, $val, $fix(GRAPH['valueColor']));
    $put($vx + mb_strlen($val), $fy0, '┌', $fc);
    // Ink-swatch strip + press number, bottom-left, only on empty stock.
    $y = ART_ROWS - 1;
    $empty = static function (int $cx) use ($img, $y): bool {
        for ($k = 0; $k < 6; $k++) {
            if (max($img[$y * 3 + intdiv($k, 2)][$cx * 2 + $k % 2]) > 60) {
                return false;
            }
        }
        return true;
    };
    $x = 1;
    foreach (SWATCHES as $hex) {
        if ($empty($x)) {
            lkPut($cells, $x, $y, '▬', $fix($hex), $bg);
        }
        $x++;
    }
    $x++;
    foreach (mb_str_split(COLOPHON_RIGHT) as $ch) {
        if ($empty($x)) {
            lkPut($cells, $x, $y, $ch, $mark, $bg);
        }
        $x++;
    }
    return $cells;
}

// ================================================================ CLI

$opts = getopt('', ['write', 'out:', 'no-anim']);
$write = isset($opts['write']);
$outDir = $opts['out'] ?? ($write ? dirname(__DIR__) : null);
$anim = false;                   // skinny cut ships static only

$final = ['sun' => 1.0, 'offset' => [0, 0], 'depth' => 1.0, 'key' => 1.0, 'graph' => 1.0];
$staticImg = scene($final);
// Animation: the sun prints in, then the key plate lands and the two colour
// colour plate slides in from way off-register to its final extrusion.
$plan = [];
if ($anim) {
    // the CPU graph sweeps in left → right through the whole run
    $plan[] = ['sun' => 0.3, 'offset' => [0, 0], 'depth' => 0.0, 'key' => 0.0, 'graph' => 0.1];
    $plan[] = ['sun' => 0.65, 'offset' => [0, 0], 'depth' => 0.0, 'key' => 0.0, 'graph' => 0.22];
    $plan[] = ['sun' => 1.0, 'offset' => [0, 0], 'depth' => 0.0, 'key' => 1.0, 'graph' => 0.34];
    // the colour plate arrives badly off-register, then snaps into its extrusion
    foreach ([[-26, -5, 0.46], [-14, -3, 0.58], [-6, -1, 0.7], [-2, 0, 0.82], [1, 0, 0.92]] as [$a, $b, $gv]) {
        $plan[] = ['sun' => 1.0, 'offset' => [$a, $b], 'depth' => 1.0, 'key' => 1.0, 'graph' => $gv];
    }
}
$frameImgs = array_map(scene(...), $plan);

foreach (['tc', '256', '16'] as $depth) {
    $static = lkEncode(cellsFor($staticImg, $depth), $depth, [], 'nearest');
    [$w, $h] = lkVerify($static, $depth);
    lkCheckDepth($static, $depth, $depth);
    if ($outDir === null) {
        echo $static;
    }
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    if ($outDir === null) {
        continue;
    }
    $r = skWriteSkinny($outDir, SLUG, $depth, $static, DESCRIPTION . " ($depth)", TAGS, $write);
    fwrite(STDERR, "  wrote {$r['file']}\n");
    if ($anim) {
        $enc = array_map(static fn (array $img): string => lkEncode(cellsFor($img, $depth), $depth, [], 'nearest'), $frameImgs);
        $a = lkAnim($enc, $static);
        $n = count($enc) + 1;
        $r = lkWriteLogo($outDir, SLUG . '-anim', $depth, $a, 'Animated ' . DESCRIPTION . " The CPU graph sweeps in left to right while the key plate lands and the teal-to-violet colour plate slides in from far off-register and settles as the extrusion; $n frames, play with tools/logo-play.php <file> 160 (~1.3 s) ($depth)", [...TAGS, 'animated', 'print-run'], $write);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
}
