<?php

declare(strict_types=1);

/**
 * generate-bauhaus-primaries — CANDY TOP as a 1923 Bauhaus exhibition poster.
 *
 * Letters are CONSTRUCTED from flat geometric primitives (annulus sectors,
 * triangles, bars, parallelograms) in red / yellow / blue / black on a cream
 * paper card, over pale tint shapes (circle, square, triangle) that the
 * letters OVERPRINT risograph-style: ink on ink multiplies, so a red arm
 * crossing a blue arm turns plum, yellow over the blue tint turns green.
 * Rendered as quadrant blocks from vector shapes (vector-quadrant-raster.php).
 *
 *   php generate-bauhaus-primaries.php            preview tc/256/16 + verify
 *   php generate-bauhaus-primaries.php --write    write .ansi + logos.jsonl
 *   php generate-bauhaus-primaries.php --out=DIR  write files into DIR (no jsonl)
 */

require __DIR__ . '/logo-kit.php';
require __DIR__ . '/quadrant-raster.php';
require __DIR__ . '/vector-quadrant-raster.php';

// ================================================================ DESIGN DATA

const SLUG = 'bauhaus-primaries';
const W = 78;                 // columns
const H = 10;                 // rows  (canvas = W x 2H square units)

const INK = [
    'paper' => '#EFE6D2',
    'red' => '#D7262E',
    'yellow' => '#F2B81F',
    'blue' => '#1F4E9C',
    'black' => '#1C1A1A',
    'tRed' => '#EDA796',      // pale tints for the background geometry
    'tYellow' => '#F7D97C',
    'tBlue' => '#A9C0DF',
];

const LETTER_TOP = 3.0;       // y of cap line (units); cap height 14

/**
 * Letters in local units (x from 0, y 0..14). Each piece: [shape, ink].
 * Stems 2 units wide (= 2 cols), bars 2 units tall (= 1 row).
 */
function letters(): array
{
    return [
        'C' => [8, [
            [['ring', 4, 7, 4, 7, 2, 5, 40, 320], 'red'],
        ]],
        'A' => [9, [
            [['diff',
                ['poly', [[4.5, 0], [0, 14], [9, 14]]],
                ['poly', [[4.5, 5.2], [3.0, 10], [6.0, 10]]],
                ['poly', [[4.5, 12], [2.4, 14.1], [6.6, 14.1]]],
            ], 'blue'],
            [['ell', 4.5, 8.4, 0.9, 0.9], 'yellow'],
        ]],
        'N' => [7, [
            [['poly', [[0, 0], [2.4, 0], [7, 14], [4.6, 14]]], 'yellow'],
            [['rect', 0, 0, 2, 14], 'black'],
            [['rect', 5, 0, 7, 14], 'black'],
        ]],
        'D' => [8, [
            [['ring', 2, 7, 6, 7, 4, 5, 270, 90], 'blue'],
            [['rect', 0, 0, 2, 14], 'blue'],
        ]],
        'Y' => [8, [
            [['poly', [[0, 0], [2.4, 0], [5, 8], [3, 8]]], 'red'],
            [['poly', [[5.6, 0], [8, 0], [5, 8], [3, 8]]], 'blue'],
            [['rect', 3, 7, 5, 14], 'black'],
        ]],
        'T' => [8, [
            [['rect', 3, 0, 5, 14], 'black'],
            [['rect', 0, 0, 8, 2], 'red'],
        ]],
        'O' => [9, [
            [['ring', 4.5, 7, 4.5, 7, 2.5, 5], 'blue'],
            [['ell', 5.4, 6.0, 0.9, 0.9], 'red'],
        ]],
        'P' => [7, [
            [['ring', 2, 4.5, 5, 4.5, 3, 2.5, 270, 90], 'red'],
            [['rect', 0, 0, 2, 14], 'black'],
        ]],
    ];
}

/** x of each letter (units/cols). */
const PLACE = ['C' => 2, 'A' => 11, 'N' => 21, 'D' => 30, 'Y' => 39, 'T' => 50, 'O' => 59, 'P' => 69];
const ORDER = ['C', 'A', 'N', 'D', 'Y', 'T', 'O', 'P'];

/** Background poster geometry, painted under the letters. */
function backdrop(float $t = 1.0): array
{
    $r = 11.5 * min(1.0, $t);
    $all = [
        // big tint disc behind TOP, bleeding off top and bottom edges
        [['ell', 62, 10, $r, $r], 'tYellow', 'normal'],
        // tilted tint square behind N/D
        [['poly', bpRot([[26, 1], [38, 1], [38, 15], [26, 15]], 32, 8, -12)], 'tRed', 'overprint'],
        // tint triangle in the lower-left corner behind C/A
        [['poly', [[-1, 17], [-1, 3], [15, 17]]], 'tBlue', 'overprint'],
        // constructivist rules: thin top rule, baseline bar
        [['rect', 2, 1, 46, 2], 'black', 'overprint'],
        [['rect', 2, 17, 76, 18], 'black', 'normal'],
        // small accents
        [['rect', 47, 0, 49, 2], 'red', 'normal'],
        [['ell', 78, 0, 3.2, 3.2], 'black', 'overprint'],
        // primitive motif in the caption row: triangle, circle, square
        [['poly', [[3, 20], [4.2, 18], [5.4, 20]]], 'blue', 'normal'],
        [['ell', 7.3, 19, 1.1, 1.1], 'yellow', 'normal'],
        [['rect', 9.5, 18, 11.5, 20], 'red', 'normal'],
    ];
    // assemble-in order for the animation: disc first, then each element
    // appears once $t passes its threshold
    $n = count($all);
    return array_values(array_filter($all, static fn (int $i): bool => $i === 0 || $t >= 0.2 + 0.8 * ($i - 1) / $n, ARRAY_FILTER_USE_KEY));
}

const CAPTION = 'b a u h a u s   s y s t e m   m o n i t o r';
const CAPTION_RIGHT = 3;      // cols kept free right of the caption

// Per-role depth maps. Colours within SNAP_DIST of a role (paper grain,
// light overprints) snap to that role first; everything else goes nearest.
// nearest-256 turned the cream paper grey and the blue teal, hence the pins.
const PINS256 = [
    'paper' => 230, 'red' => 160, 'yellow' => 214, 'blue' => 25,
    'black' => 234, 'tRed' => 216, 'tYellow' => 222, 'tBlue' => 153,
];
const PINS16 = [   // fg code; tints go to neutral/bright so they read as tints
    'paper' => 97, 'red' => 31, 'yellow' => 93, 'blue' => 34,
    'black' => 30, 'tRed' => 37, 'tYellow' => 93, 'tBlue' => 94,
];
const SNAP_DIST = ['256' => 50.0, '16' => 22.0];   // 256: overprint mixes too (nearest made the blue O teal)

const DESCRIPTION = 'Bauhaus exhibition-poster CANDY TOP: letters constructed from flat geometric primitives (annulus sectors, triangles, bars) in red/yellow/blue/black on a cream paper card, overprinting pale tint circle/square/triangle shapes risograph-style, with constructivist rules, a black corner quarter-disc, paper grain and a triangle-circle-square motif beside a letterspaced lowercase caption; quadrant-block vector raster';
const TAGS = ['bauhaus', 'constructivist', 'geometric-letters', 'primary-colors', 'risograph-overprint', 'poster-card', 'flat-color', 'quadrant-blocks', 'vector-raster'];

// ================================================================ BUILD

/** Rotate polygon points by $deg around ($cx, $cy). */
function bpRot(array $pts, float $cx, float $cy, float $deg): array
{
    $a = deg2rad($deg);
    return array_map(static fn (array $p): array => [
        $cx + ($p[0] - $cx) * cos($a) - ($p[1] - $cy) * sin($a),
        $cy + ($p[0] - $cx) * sin($a) + ($p[1] - $cy) * cos($a),
    ], $pts);
}

/**
 * Layer list. $shown: letter => [visible bool, y drop offset] (animation).
 * @param array{0:float,1:float,2:float} $bg backdrop progress
 */
function layers(?array $shown = null, float $bgT = 1.0, bool $withBackdrop = true): array
{
    $ink = static fn (string $k): array => lkHex(INK[$k]);
    $L = [];
    if ($withBackdrop) {
        foreach (backdrop($bgT) as [$s, $k, $m]) {
            $L[] = ['shape' => $s, 'ink' => $ink($k), 'mode' => $m];
        }
    }
    $font = letters();
    foreach (ORDER as $ch) {
        $dy = 0.0;
        if ($shown !== null) {
            if (!isset($shown[$ch])) {
                continue;
            }
            $dy = $shown[$ch];
        }
        foreach ($font[$ch][1] as [$s, $k]) {
            $L[] = ['shape' => ['move', PLACE[$ch], LETTER_TOP + $dy, $s], 'ink' => $ink($k), 'mode' => 'overprint', 'keep' => 0.5];
        }
    }
    return $L;
}

/** Paper grain: nudge paper-coloured cell sides by a hashed ±amount. */
function grain(array $cells, int $amp = 3): array
{
    $paper = lkHex(INK['paper']);
    foreach ($cells as $y => $row) {
        foreach ($row as $x => [$g, $fg, $bg]) {
            $d = (int) round((lkNoise($x, $y, 1923) - 0.5) * 2 * $amp);
            $n = [max(0, min(255, $paper[0] + $d)), max(0, min(255, $paper[1] + $d)), max(0, min(255, $paper[2] + $d - 1))];
            if ($bg === $paper) {
                $cells[$y][$x][2] = $n;
            }
            if ($fg === $paper) {
                $cells[$y][$x][1] = $n;
            }
        }
    }
    return $cells;
}

function caption(array &$cells): void
{
    $x = W - CAPTION_RIGHT - mb_strlen(CAPTION);
    foreach (mb_str_split(CAPTION) as $i => $ch) {
        if ($ch !== ' ') {
            lkPut($cells, $x + $i, H - 1, $ch, lkHex(INK['black']));
        }
    }
}

function cells(?array $shown = null, float $bgT = 1.0, bool $cap = true): array
{
    $cells = qrRaster(vqPixels(layers($shown, $bgT), W, H, lkHex(INK['paper'])));
    // solid cells → bg-only spaces, so caption text keeps the paper behind it
    foreach ($cells as $y => $row) {
        foreach ($row as $x => $c) {
            if ($c[0] === '█') {
                $cells[$y][$x] = [' ', null, $c[1]];
            }
        }
    }
    $cells = grain($cells);
    if ($cap) {
        caption($cells);
    }
    return $cells;
}

/** Snap role-near colours to their pinned 256 entry / exact role hex. */
function snapCells(array $cells, string $depth): array
{
    if ($depth === 'tc') {
        return $cells;
    }
    $roles = array_map('lkHex', INK);
    $x256 = lkXterm256();
    $snap = static function (?array $c) use ($roles, $depth, $x256): ?array {
        if ($c === null) {
            return null;
        }
        $best = null;
        $bd = SNAP_DIST[$depth];
        foreach ($roles as $k => $r) {
            $d = sqrt(($c[0] - $r[0]) ** 2 + ($c[1] - $r[1]) ** 2 + ($c[2] - $r[2]) ** 2);
            if ($d < $bd) {
                $bd = $d;
                $best = $k;
            }
        }
        if ($best === null && $depth === '16') {
            // overprint mixes: in 16 colours, fall back to the nearest role so
            // a letter keeps one colour across the tints it crosses
            $bd = INF;
            foreach ($roles as $k => $r) {
                $d = lkDist($c, $r);
                if ($d < $bd) {
                    $bd = $d;
                    $best = $k;
                }
            }
        }
        if ($best === null) {
            return $c;
        }
        return $depth === '256' ? $x256[PINS256[$best]] : $roles[$best];
    };
    foreach ($cells as $y => $row) {
        foreach ($row as $x => $cell) {
            $cells[$y][$x] = [$cell[0], $snap($cell[1]), $snap($cell[2])];
        }
    }
    return $cells;
}

/** Encode at a depth with the role snapping + 16-colour pins. */
function encode(array $cells, string $depth): string
{
    $pins = [];
    foreach (PINS16 as $k => $code) {
        $pins[INK[$k]] = $code;
    }
    return lkEncode(snapCells($cells, $depth), $depth, $pins);
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$outDir = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--out=')) {
        $outDir = substr($a, 6);
    }
}
$noAnim = in_array('--no-anim', $argv, true);
$dir = $outDir ?? dirname(__DIR__);

$static = cells();
// animation: backdrop disc grows, then letters drop in one by one
$frames = [];
if (!$noAnim) {
    $frames[] = [[], 0.0, false];
    foreach ([0.2, 0.45, 0.7, 0.9] as $t) {
        $frames[] = [[], $t, false];
    }
    $shown = [];
    foreach (ORDER as $ch) {
        $frames[] = [$shown + [$ch => -2.0], 1.0, false];
        $shown[$ch] = 0.0;
    }
    $frames[] = [$shown, 1.0, false];
    $frameCells = array_map(static fn (array $f): array => cells($f[0], $f[1], $f[2]), $frames);
}

foreach (['tc', '256', '16'] as $depth) {
    $ansi = encode($static, $depth);
    [$w, $h] = lkVerify($ansi, $depth);
    lkCheckDepth($ansi, $depth, $depth);
    echo $ansi;
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    if ($write || $outDir !== null) {
        $r = lkWriteLogo($dir, SLUG, $depth, $ansi, DESCRIPTION . " ($depth)", TAGS, $outDir === null);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        if (!$noAnim) {
            $enc = array_map(static fn (array $c): string => encode($c, $depth), $frameCells);
            $anim = lkAnim($enc, $ansi);
            $r = lkWriteLogo($dir, SLUG . '-anim', $depth, $anim,
                'Animated ' . DESCRIPTION . '. The tint disc grows, then each constructed letter drops into place; ends on the static poster. Play with tools/logo-play.php <file> 150 (~2.3s)' . " ($depth)",
                [...TAGS, 'animated', 'assemble-in'], $outDir === null);
            fwrite(STDERR, "  wrote {$r['file']}\n");
        }
    }
}
