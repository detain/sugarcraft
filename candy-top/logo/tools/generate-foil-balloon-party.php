<?php

declare(strict_types=1);

/**
 * generate-foil-balloon-party — CANDY TOP spelled in inflated mylar FOIL
 * LETTER BALLOONS, the kind tied up for a birthday party, bobbing at slightly
 * different heights and tilts over a night-time party backdrop of soft bokeh
 * lights, falling confetti and twinkles, each balloon trailing a curly
 * ribbon from its tie.
 *
 *  - Letterforms: stroke skeletons inflated into round tubes
 *    (tools/inflated-sdf-letters.php): smooth-min joins swell like real
 *    balloon seams; a height field gives normals for metallic shading
 *    (dark core, sky band, hard white specular, coloured rim bounce,
 *    crimped edge seam, hue shift at grazing angles).
 *  - Raster: full-colour sub-pixel image → sextant cells
 *    (tools/sextant-image-raster.php), quantised BEFORE fitting at 256/16.
 *  - Animation: balloons float up from below one after another with a little
 *    overshoot bob while confetti drifts down and the CPU graph scrolls in from the right; ends on the static frame.
 *
 *   php generate-foil-balloon-party.php             preview tc/256/16 + verify
 *   php generate-foil-balloon-party.php --write     write .ansi + logos.jsonl
 *   php generate-foil-balloon-party.php --out=DIR   write .ansi into DIR (no jsonl)
 *   php generate-foil-balloon-party.php --png=DIR   also dump sub-pixel PNG
 *   --no-anim  skip the animation
 */

require __DIR__ . '/logo-kit.php';
require __DIR__ . '/sextant-canvas.php';
require __DIR__ . '/sextant-image-raster.php';
require __DIR__ . '/inflated-sdf-letters.php';
require __DIR__ . '/braille-netgraph.php';

// ================================================================ DESIGN DATA

const SLUG = 'foil-balloon-party';
const W = 76;                 // cells
const H = 10;
const YS = 4 / 3;             // sub-pixel row height in sub-pixel-column units
const R = 3.55;               // balloon tube radius (phys units)
const KBLEND = 1.4;           // smooth-union radius at joins
const LIGHT = [-0.55, -0.75, 0.62];
const SEED = 4242;
/** isShadeFoil options: specular tightness/strength, ambient, seam, iridescence. */
const FOIL = ['shine' => 10, 'spec' => 1.5, 'amb' => 0.3, 'seam' => 0.88, 'seamDark' => 0.6, 'iri' => 0.7, 'bounce' => 0.9];

// skeleton metrics: cap 0..26, centre-lines at T..B
const T = 3.6;
const B = 22.4;

/** Letter skeletons (phys units), advance width, knot (ribbon tie) point. */
function glyphs(): array
{
    return [
        'C' => ['w' => 16.5, 'knot' => [9.0, 26.0], 's' => [
            ['arc', 9.6, 13, 5.9, 9.4, -38, -322],
        ]],
        'A' => ['w' => 18, 'knot' => [14.4, 26.0], 's' => [
            ['line', [[3.6, B], [9, T + 0.4], [14.4, B]]],
            ['line', [[6.3, 15.4], [11.7, 15.4]]],
        ]],
        'N' => ['w' => 17.2, 'knot' => [3.6, 26.0], 's' => [
            ['line', [[3.6, B], [3.6, T], [13.6, B], [13.6, T]]],
        ]],
        'D' => ['w' => 17.4, 'knot' => [6.0, 26.0], 's' => [
            ['line', [[3.6, B], [3.6, T], [7.2, T]]],
            ['arc', 7.2, 13, 6.4, 9.4, -90, 90],
            ['line', [[7.2, B], [3.6, B]]],
        ]],
        'Y' => ['w' => 18, 'knot' => [9.0, 26.0], 's' => [
            ['line', [[3.6, T], [9, 12.8], [14.4, T]]],
            ['line', [[9, 12.8], [9, B]]],
        ]],
        'T' => ['w' => 17.4, 'knot' => [8.7, 26.0], 's' => [
            ['line', [[3.4, T], [14.0, T]]],
            ['line', [[8.7, T], [8.7, B]]],
        ]],
        'O' => ['w' => 19, 'knot' => [9.5, 26.0], 's' => [
            ['arc', 9.5, 13, 5.9, 9.4, 0, 360],
        ]],
        'P' => ['w' => 16.4, 'knot' => [3.6, 26.0], 's' => [
            ['line', [[3.6, B], [3.6, T], [7.2, T]]],
            ['arc', 7.2, 9.1, 5.4, 5.5, -90, 90],
            ['line', [[7.2, 14.6], [3.6, 14.6]]],
        ]],
    ];
}

const TEXT = 'CANDY TOP';
const KERN = -1.6;            // balloons nudge into each other
const WORD_GAP = 6.5;
const TOP_Y = 1.7;            // cap top (phys)

/** Per letter: foil colour, iridescent alt, bob (phys y), tilt (deg). */
const BALLOONS = [
    ['#FF2E8A', '#FF8A3D', 0.0, -5.0],   // C hot pink
    ['#FFB21E', '#FFE45C', 0.8, 3.5],    // A gold
    ['#13D6C4', '#5CFF9E', -0.4, -2.5],  // N turquoise
    ['#8A55FF', '#FF5CD6', 0.6, 4.0],    // D violet
    ['#FF5A1F', '#FFD03A', -0.5, -4.0],  // Y tangerine
    ['#2E8DFF', '#3DF0FF', 0.5, 4.5],    // T sky blue
    ['#FF3363', '#FF9AD5', -0.4, -3.0],  // O cherry
    ['#7BE234', '#E8FF4A', 0.7, 5.0],    // P lime
];

/**
 * 16-colour build: each balloon gets a [lit, shade] ANSI pair (fg codes)
 * instead of hue-bucketing its shading — nearest/hue turned C and D the same
 * magenta and spread one balloon over 3-4 codes. Neighbours never share.
 */
const PAIRS16 = [[95, 35], [93, 33], [96, 36], [94, 34], [93, 91], [94, 34], [91, 31], [92, 32]];

const BG_TOP = '#0E0624';
const BG_BOT = '#2A0A4A';
/** Soft bokeh party lights: [x, y, r, hex, alpha] (phys). */
const BOKEH = [
    [14, 8, 7, '#FF3FA4', 0.22], [34, 28, 8, '#2E8DFF', 0.20], [60, 5, 6, '#FFC23A', 0.18],
    [86, 27, 9, '#B45CFF', 0.22], [110, 6, 7, '#13D6C4', 0.19], [134, 26, 8, '#FF5A1F', 0.20],
    [146, 10, 5, '#FF2E8A', 0.18],
];
/** Edge fade to black (phys units): sides, top, bottom. */
const FADE = ['side' => 26.0, 'top' => 12.0, 'bottom' => [24.0, 40.0]];

/**
 * btop-style CPU graph along the bottom: braille area graph (2x4 dots per
 * cell) on the last GRAPH_ROWS rows; the balloon ribbons trail down into it.
 * Each column takes the colour of the balloon above it, dim at the base and
 * bright at the peak — btop's *_start → *_end height gradient.
 */
const GRAPH_ROWS = 2;
const GRAPH_SEED = 77;
const GRAPH_SCROLL = 2;       // dot columns per animation frame (scrolls left like btop)
const LABEL_LEFT = 'cpu';
const LABEL_FG = '#A99BD0';
const CONFETTI_N = 30;
const CONFETTI = ['#FF2E8A', '#FFD23A', '#13D6C4', '#8A55FF', '#FFFFFF', '#7BE234', '#2E8DFF', '#FF7A2E'];
const TWINKLES = [[3, 22], [20, 2.0], [71, 30], [100, 1.6], [124, 30.5], [150, 18]];

const DESCRIPTION = 'Party foil balloons: CANDY TOP as inflated mylar letter balloons (stroke skeletons blown up into round tubes with metallic shading, white specular glints, iridescent rims and crimped seams), each a different bright colour, bobbing and tilted, with curly ribbons hanging from their ties over a violet night-party glow that fades to black at the edges, with bokeh lights, falling confetti and twinkles; the ribbons trail down into a btop-style braille CPU area graph along the bottom, each column tinted by the balloon above it and labelled cpu / load %; sextant-mosaic + braille raster';
const TAGS = ['foil-balloons', 'inflated-letters', 'metallic-shading', 'party', 'confetti', 'bokeh', 'curly-ribbons', 'sextant-mosaic', 'multicolour', 'braille-cpu-graph', 'btop-graph', 'black-edge-fade'];

// ================================================================ BUILD

/** Placed letters: skeleton (local), origin, rotation, colours. */
function placed(?array $rise = null): array
{
    $g = glyphs();
    $chars = str_split(TEXT);
    $total = 0.0;
    foreach ($chars as $i => $ch) {
        $total += $ch === ' ' ? WORD_GAP : $g[$ch]['w'] + ($i ? KERN : 0);
    }
    $x = (2 * W - $total) / 2;
    $out = [];
    $li = 0;
    foreach ($chars as $i => $ch) {
        if ($ch === ' ') {
            $x += WORD_GAP;
            continue;
        }
        $x += $i ? KERN : 0;
        [$col, $alt, $bob, $tilt] = BALLOONS[$li];
        $dy = $rise[$li] ?? 0.0;
        $out[] = [
            'ch' => $ch, 'sk' => isSkeleton($g[$ch]['s']), 'x' => $x, 'y' => TOP_Y + $bob + $dy,
            'w' => $g[$ch]['w'], 'a' => deg2rad($tilt), 'col' => lkHex($col), 'alt' => lkHex($alt),
            'knot' => $g[$ch]['knot'], 'i' => $li,
        ];
        $x += $g[$ch]['w'];
        $li++;
    }
    return $out;
}

/** World → letter-local (undo tilt about the letter centre). */
function toLocal(array $L, float $x, float $y): array
{
    $cx = $L['x'] + $L['w'] / 2;
    $cy = $L['y'] + 13;
    $dx = $x - $cx;
    $dy = $y - $cy;
    $c = cos(-$L['a']);
    $s = sin(-$L['a']);
    return [$dx * $c - $dy * $s + $L['w'] / 2, $dx * $s + $dy * $c + 13];
}

function toWorld(array $L, float $lx, float $ly): array
{
    $dx = $lx - $L['w'] / 2;
    $dy = $ly - 13;
    $c = cos($L['a']);
    $s = sin($L['a']);
    return [$L['x'] + $L['w'] / 2 + $dx * $c - $dy * $s, $L['y'] + 13 + $dx * $s + $dy * $c];
}

function smooth(float $e0, float $e1, float $v): float
{
    $t = max(0.0, min(1.0, ($v - $e0) / ($e1 - $e0)));
    return $t * $t * (3 - 2 * $t);
}

/** 0 at the black edges → 1 in the lit middle. */
function fade(float $x, float $y): float
{
    $wp = 2 * W;
    return smooth(0, FADE['side'], $x) * smooth(0, FADE['side'], $wp - $x)
        * smooth(-2, FADE['top'], $y) * (1 - smooth(FADE['bottom'][0], FADE['bottom'][1], $y));
}

function background(float $x, float $y, bool $bokeh = true): array
{
    $c = lkMix(lkHex(BG_TOP), lkHex(BG_BOT), $y / (3 * H * YS));
    foreach ($bokeh ? BOKEH : [] as [$bx, $by, $br, $hex, $al]) {
        $d = sqrt(($x - $bx) ** 2 + ($y - $by) ** 2) / $br;
        if ($d < 1.0) {
            // bokeh disc: flat with a slightly brighter rim
            $a = $al * ($d > 0.82 ? 1.5 : 1.0);
            $c = lkMix($c, lkHex($hex), $a);
        }
    }
    return lkScale($c, fade($x, $y));
}

/** Graph values per dot column (0..1), scrolled by $off dot columns. */
function traffic(int $off = 0): array
{
    static $all = null;
    if ($all === null) {
        // stretch to the full 0..1 range so the graph has real peaks and dips
        $raw = bnTraffic(2 * W + 400, GRAPH_SEED, 0.42, 0.7, 1);
        $lo = min($raw);
        $hi = max($raw);
        $all = array_map(static fn (float $v): float => 0.1 + 0.9 * (($v - $lo) / ($hi - $lo)) ** 0.85, $raw);
    }
    return array_slice($all, 200 + $off, 2 * W);
}

/** Phys y of the graph surface at x (ribbons stop there). */
function surfaceY(array $vals, float $x): float
{
    $dots = GRAPH_ROWS * 4;
    $v = $vals[max(0, min(count($vals) - 1, (int) floor($x)))];
    return H * 4 - round($v * $dots) * (4 / 4);
}

/** Seeded confetti: rotated little rectangles [cx, cy, hw, hh, ang, rgb]. */
function confetti(float $fall = 0.0): array
{
    $out = [];
    $hgt = 3 * H * YS;
    for ($i = 0; $i < CONFETTI_N; $i++) {
        $cx = lkNoise($i, 1, SEED) * 2 * W;
        $cy = fmod(lkNoise($i, 2, SEED) * $hgt + $fall * (0.6 + lkNoise($i, 5, SEED)), $hgt);
        $out[] = [$cx, $cy, 0.95 + 0.5 * lkNoise($i, 3, SEED), 0.55, lkNoise($i, 4, SEED) * M_PI,
            lkHex(CONFETTI[$i % count(CONFETTI)])];
    }
    return $out;
}

/** Ribbon polyline (world) from a balloon's knot to the bottom edge. */
function ribbon(array $L): array
{
    [$kx, $ky] = toWorld($L, $L['knot'][0], $L['knot'][1]);
    $pts = [];
    $bottom = 3 * H * YS + 1;
    $ph = $L['i'] * 1.7;
    for ($t = 0; ; $t += 0.25) {
        $y = $ky + $t;
        $amp = min(1.0, $t / 2.0) * 1.5;
        $pts[] = [$kx + $amp * sin($t * 0.95 + $ph), $y];
        if ($y > $bottom) {
            break;
        }
    }
    return $pts;
}

/** Colour function over sub-pixel coords (x in cols*2, y in rows*3). */
function scene(array $letters, array $conf, array $ribbons, bool $m16, array $vals, bool $m256 = false): callable
{
    return static function (float $sx, float $sy) use ($letters, $conf, $ribbons, $m16, $vals, $m256): array {
        $x = $sx;
        $y = $sy * YS;
        // front-most letter wins (later letters are in front)
        for ($i = count($letters) - 1; $i >= 0; $i--) {
            $L = $letters[$i];
            if ($x < $L['x'] - 6 || $x > $L['x'] + $L['w'] + 6) {
                continue;
            }
            [$lx, $ly] = toLocal($L, $x, $y);
            $d = isDist($L['sk'], $lx, $ly, KBLEND);
            if ($d < R) {
                $n = isNormal($L['sk'], $lx, $ly, R, KBLEND);
                $c = isShadeFoil($L['col'], $n, $d / R, LIGHT, $L['alt'], FOIL);
                return $m16 ? pick16($c, $L, $d / R) : $c;
            }
            // balloon tie: little pinched triangle under the knot
            $kx = $L['knot'][0];
            $ky = $L['knot'][1];
            if ($ly >= $ky - 1.6 && $ly <= $ky + 0.6 && abs($lx - $kx) <= 0.35 + 0.55 * ($ly - $ky + 1.6)) {
                return $m16 ? lkAnsi16()[PAIRS16[$L['i']][1]] : lkScale($L['col'], 0.8);
            }
        }
        // ribbons (they end where they meet the graph surface)
        foreach ($ribbons as $ri => $pts) {
            if ($y < $pts[0][1] - 1 || $y > surfaceY($vals, $x) + 0.3) {
                continue;
            }
            $best = INF;
            $slope = 0.0;
            for ($k = 0; $k + 1 < count($pts); $k++) {
                if (abs($pts[$k][1] - $y) > 1.0) {
                    continue;
                }
                $dd = isSegDist([$pts[$k][0], $pts[$k][1], $pts[$k + 1][0], $pts[$k + 1][1]], $x, $y);
                if ($dd < $best) {
                    $best = $dd;
                    $slope = $pts[$k + 1][0] - $pts[$k][0];
                }
            }
            if ($best < 0.62) {
                if ($m16) {
                    return lkAnsi16()[PAIRS16[$ri][$slope > 0 ? 0 : 1]];
                }
                $base = lkShade($letters[$ri]['col'], 0.25);
                return $slope > 0 ? lkShade($base, 0.35) : lkScale($base, 0.78);
            }
        }
        // shadow cast by the balloons onto the backdrop
        // 16: bokeh rims read as stray arcs → plain black; 256: xterm has no
        // deep violets, so the backdrop goes to the grey ramp (smooth fade, no
        // bokeh — on greys the discs read as dirty smudges)
        $bg = $m16 ? [0, 0, 0] : ($m256 ? bg256(background($x, $y, false)) : background($x, $y));
        foreach ($conf as [$cx, $cy, $hw, $hh, $ang, $rgb]) {
            $dx = $x - $cx;
            $dy = $y - $cy;
            $u = $dx * cos($ang) + $dy * sin($ang);
            $v = -$dx * sin($ang) + $dy * cos($ang);
            if (abs($u) <= $hw && abs($v) <= $hh * 1.6) {
                return lkMix($rgb, $bg, 0.12);
            }
        }
        foreach (TWINKLES as [$tx, $ty]) {
            $ax = abs($x - $tx);
            $ay = abs($y - $ty);
            if (($ax < 0.35 && $ay < 1.9) || ($ay < 0.45 && $ax < 1.5)) {
                return [255, 246, 214];
            }
        }
        foreach ($letters as $L) {
            [$lx, $ly] = toLocal($L, $x - 1.4, $y - 1.8);
            if ($lx > -6 && $lx < $L['w'] + 6 && isDist($L['sk'], $lx, $ly, KBLEND) < R * 0.95) {
                return lkScale($bg, 0.5);
            }
        }
        return $bg;
    };
}

/** Shaded foil colour → the balloon's lit/shade ANSI pair (or white glint). */
function pick16(array $c, array $L, float $edge): array
{
    $a16 = lkAnsi16();
    if (min($c) > 205) {
        return $a16[97];
    }
    $lum = 0.3 * $c[0] + 0.59 * $c[1] + 0.11 * $c[2];
    $base = 0.3 * $L['col'][0] + 0.59 * $L['col'][1] + 0.11 * $L['col'][2];
    [$lit, $shade] = PAIRS16[$L['i']];
    return $a16[($edge > 0.88 || $lum < 0.62 * $base) ? $shade : $lit];
}

/** $m16: point-sampled, letters pre-mapped to their ANSI pairs. */
/** $mode tc|256|16 — 16 is point-sampled with letters pre-mapped to ANSI pairs. */
function image(?array $rise = null, float $fall = 0.0, string $mode = 'tc', int $off = 0): array
{
    $letters = placed($rise);
    $ribbons = array_map('ribbon', $letters);
    $m16 = $mode === '16';
    return siSupersample(2 * W, 3 * H, scene($letters, confetti($fall), $ribbons, $m16, traffic($off), $mode === '256'), $m16 ? 1 : 3);
}

/** 16-colour picker: deep backdrop → black, the rest by hue. */
function map16(array $c): array
{
    if (in_array($c, lkAnsi16(), true)) {
        return $c;                       // already picked by pick16()
    }
    $lum = 0.3 * $c[0] + 0.59 * $c[1] + 0.11 * $c[2];
    $a16 = lkAnsi16();
    if ($lum < 52) {
        return $a16[30];
    }
    if (max($c) - min($c) < 40) {
        return $lum > 200 ? $a16[97] : $a16[90];
    }
    return $a16[lkTo16($c)];
}

/**
 * 256: xterm has no deep purples — nearest turned the night backdrop into a
 * grey top + loud navy band. Dark backdrop pixels go to the grey ramp
 * instead (one smooth dark gradient); everything else nearest.
 */
/** Backdrop colour → black / dark grey ramp (232-237), keeping the fade. */
function bg256(array $c): array
{
    $v = 0.45 * $c[0] + 0.25 * $c[1] + 0.3 * $c[2];
    return $v < 7 ? [0, 0, 0] : lkXterm256()[232 + max(0, min(5, (int) round($v / 10 - 0.8)))];
}

function px256(array $c): array
{
    return $c === [0, 0, 0] ? $c : lkXterm256()[lkTo256($c)];
}

function quantise256(array $img): array
{
    foreach ($img as $y => $row) {
        foreach ($row as $x => $c) {
            if ($c !== null) {
                $img[$y][$x] = px256($c);
            }
        }
    }
    return $img;
}

/**
 * Lay the braille CPU graph over the bottom rows, plus the btop-style panel
 * label. Columns are coloured by the balloon nearest above them.
 */
function graphOverlay(array $cells, string $depth, int $off): array
{
    $vals = traffic($off);
    $g = bnGraph(W, GRAPH_ROWS, GRAPH_ROWS * 4, $vals, []);
    $letters = placed();
    $a16 = lkAnsi16();
    $top = H - GRAPH_ROWS;
    for ($cy = 0; $cy < GRAPH_ROWS; $cy++) {
        for ($cx = 0; $cx < W; $cx++) {
            $gc = $g[$cy][$cx];
            if ($gc === null) {
                continue;
            }
            $x = 2 * $cx + 1;
            // nearest balloon centre → colour; blend toward the next one
            $best = 0;
            $bd = INF;
            foreach ($letters as $i => $L) {
                $d = abs($L['x'] + $L['w'] / 2 - $x);
                if ($d < $bd) {
                    $bd = $d;
                    $best = $i;
                }
            }
            $L = $letters[$best];
            $t = $gc['t'];
            $f = 0.3 + 0.7 * smooth(0, FADE['side'], $x) * smooth(0, FADE['side'], 2 * W - $x);   // sides dim toward black
            if ($depth === '16') {
                $fg = $a16[PAIRS16[$best][$t > 0.6 ? 0 : 1]];
                $bg = [0, 0, 0];
            } else {
                $fg = lkScale(lkMix(lkScale($L['col'], 0.7), lkShade($L['col'], 0.45), $t), $f);
                $bg = $depth === '256' ? bg256(background($x, ($top + $cy + 0.5) * 4, false)) : background($x, ($top + $cy + 0.5) * 4);
            }
            $cells[$top + $cy][$cx] = [$gc['glyph'], $fg, $bg];
        }
    }
    // panel label on the black left edge, current load on the right
    $pct = (int) round(end($vals) * 100) . '%';
    $lab = static function (array &$cells, int $x, string $txt): void {
        foreach (str_split($txt) as $i => $ch) {
            $old = $cells[H - GRAPH_ROWS - 1][$x + $i];
            $cells[H - GRAPH_ROWS - 1][$x + $i] = [$ch, lkHex(LABEL_FG), $old[2] ?? [0, 0, 0]];
        }
    };
    $lab($cells, 1, LABEL_LEFT);
    $lab($cells, W - 1 - strlen($pct), $pct);
    return $cells;
}

function cellsAt(array $img, string $depth): array
{
    if ($depth === 'tc') {
        return siCells($img);
    }
    if ($depth === '256') {
        return siCells(quantise256($img), true);
    }
    return siCells(siQuantise($img, $depth, 0.0, $depth === '16' ? static fn (array $c): array => map16($c) : null), true);
}

function encodeAt(array $img, string $depth, int $off = 0): string
{
    return lkEncode(graphOverlay(cellsAt($img, $depth), $depth, $off), $depth, [], 'nearest');
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$noAnim = in_array('--no-anim', $argv, true);
$outDir = null;
$png = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--out=')) {
        $outDir = substr($a, 6);
    }
    if (str_starts_with($a, '--png=')) {
        $png = substr($a, 6);
    }
}
$dir = $outDir ?? dirname(__DIR__);

$imgs = ['tc' => image(), '256' => image(null, 0.0, '256'), '16' => image(null, 0.0, '16')];
if ($png !== null) {
    siImagePng($imgs['tc'], "$png/foil-subpx.png");
}

// animation params: balloons float up in turn (ease-out + small overshoot
// bob), confetti drifts down
$animParams = [];
$animCache = [];
if (!$noAnim) {
    $nF = 16;
    for ($f = 0; $f < $nF; $f++) {
        $rise = [];
        for ($i = 0; $i < 8; $i++) {
            $t = max(0.0, min(1.0, ($f - $i * 0.9) / 6.0));
            $e = 1 - (1 - $t) ** 3;
            $rise[$i] = (1 - $e) * 34 - sin($t * M_PI) * ($t > 0.5 ? 1.6 : 0.5);
        }
        $animParams[] = [$rise, -$nF * 0.9 + $f * 0.9, -($nF - $f) * GRAPH_SCROLL];
    }
}

foreach (['tc', '256', '16'] as $depth) {
    $m16 = $depth === '16';
    $ansi = encodeAt($imgs[$depth], $depth);
    [$w, $h] = lkVerify($ansi, $depth);
    lkCheckDepth($ansi, $depth, $depth);
    echo $ansi;
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    if ($write || $outDir !== null) {
        $r = lkWriteLogo($dir, SLUG, $depth, $ansi, DESCRIPTION . " ($depth)", TAGS, $outDir === null);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        if ($animParams) {
            $frames = [];
            foreach ($animParams as $k => $p) {
                $frames[] = encodeAt(image($p[0], $p[1], $depth, $p[2]), $depth, $p[2]);
            }
            $anim = lkAnim($frames, $ansi);
            $r = lkWriteLogo($dir, SLUG . '-anim', $depth, $anim,
                'Animated ' . DESCRIPTION . '. The balloons float up one after another with a little overshoot bob while confetti drifts down; ends on the static frame. Play with tools/logo-play.php <file> 120 (~2.0s)' . " ($depth)",
                [...TAGS, 'animated', 'float-up'], $outDir === null);
            fwrite(STDERR, "  wrote {$r['file']}\n");
        }
    }
}
