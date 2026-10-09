<?php

declare(strict_types=1);

/**
 * generate-spun-sugar-dither-skinny — 40×11 stacked (CANDY over TOP) copy of
 * generate-spun-sugar-dither.php (v4). Writes via skWriteSkinny().
 *
 * Original notes: CANDY TOP spun from rainbow cotton candy,
 * glittering in a dark nebula night that fades to black at the edges; the O of
 * TOP is a segmented radial CPU gauge (see GAUGE).
 *
 * Pipeline: paint the whole scene as a full-colour sub-pixel image at sextant
 * resolution (2W × 3H), then fit every cell to the best sextant + 2 colours
 * (sextant-image-raster.php). Edges and fades are therefore sub-cell smooth.
 * The shade-glyph identity of this design (░▒▓) survives as an OVERLAY pass:
 *  - floss fringe: sparse ░ wisps in the glow just outside the letters
 *  - spun strands: ▒/░ fibre cells along the twist highlights inside letters
 * plus glyph sparkles (✦ ✧ ⋆ · ˚ stars, glints on the floss, a shooting star).
 *
 * Image layers (back → front):
 *  1. nebula sky: smooth value-noise blend of plum / indigo / teal on black,
 *     multiplied by a vignette that fades to black at the sides and top
 *  2. candy glow: blurred letter coverage tinted by the floss hue
 *  3. cumulus clouds: two layers of lit puff spheres, multi-tint, side-faded
 *  4. letters: ttf-coverage.php (Lato Black, anti-aliased), coloured by a
 *     rainbow-pastel gradient across the text, a diagonal twist (bicolour
 *     floss stripes + white highlights), and puffy depth shading from
 *     repeated erosion (dark rim → bright core)
 *
 * Reuse: swap FONT_FILE/LINES, FLOSS / NEBULA / CLOUD stops, VIGNETTE,
 * TWIST, STAR_* / GLINTS / METEOR; overlay rules live in overlays().
 *
 * Usage:
 *   php generate-spun-sugar-dither.php            preview tc/256/16 (+ verify)
 *   php generate-spun-sugar-dither.php --write    write .ansi files + logos.jsonl
 *   php generate-spun-sugar-dither.php --seed=N   try another noise seed
 */

require_once __DIR__ . '/logo-kit.php';
require_once __DIR__ . '/sextant-image-raster.php';
require_once __DIR__ . '/ttf-coverage.php';
require_once __DIR__ . '/skinny-raster-kit.php';   // skWriteSkinny()

// ================================================================ DESIGN DATA

const SLUG = 'spun-sugar-dither-skinny';
const SEED = 7;
const W = 40;
const H = 11;
const PW = W * 2;              // sub-pixels (sextant 2×3)
const PH = H * 3;

const FONT_FILE = '/usr/share/fonts/truetype/lato/Lato-Black.ttf';
/**
 * Stacked lines: [text, ink box in sub-pixels [x0,y0,x1,y1], rainbow u range,
 * tracking (font px at size 200)]. CANDY over a taller TOP so the O gauge has
 * room for its readout.
 */
const LINES = [
    ['CANDY', [4, 3, 76, 15], [0.0, 0.62], 6.0],
    ['TOP', [6, 16, 74, 31], [0.55, 1.0], 10.0],
];

/** Rainbow-pastel floss across the text (u = 0..1). */
const FLOSS = ['#FF4FA3', '#FF7F9E', '#FFA37A', '#FFC86E', '#C9F08A', '#7FF0C8', '#6FD3FF', '#8E9BFF', '#C38CFF', '#F07BE6'];
/** Twist: diagonal stripes (period in sub-pixels), hue shift of the 2nd strand, highlight. */
const TWIST = ['period' => 9.0, 'slope' => 1.3, 'shift' => 0.16, 'white' => 0.30];
const RIM_DARK = -0.10;        // depth shading at the very edge
const CORE_LIGHT = 0.28;       // and at the deepest core

/** Nebula sky stops (blended by smooth noise), all dark; × vignette. */
const NEBULA = ['#030207', '#1C0829', '#0E0D31', '#071E2C', '#270A26'];
const NEBULA_SCALE = 14.0;     // sub-pixels per noise cell
/** Vignette ramps: side distance (cols), top distance (rows), bottom floor. */
const VIGNETTE = ['side' => 8.0, 'top' => 2.2, 'bottomFloor' => 0.55];

const GLOW = ['radius' => 3, 'passes' => 3, 'mix' => 0.55];

/**
 * The O of TOP is a CPU/power gauge: an elliptical ring fitted to the O's ink
 * box, split into SEGMENTS across a 270° sweep (gap at the bottom), filled
 * clockwise to LEVEL with a mint→lemon→peach→pink heat ramp; unfilled
 * segments stay as a dim track, the leading segment glows, and a percentage
 * readout sits in the middle. Angles: degrees clockwise from 12 o'clock.
 */
const GAUGE = [
    'letter' => 6, 'in' => 0.52, 'start' => -150.0, 'sweep' => 300.0, 'segments' => 8, 'gap' => 4.0,
    'level' => 0.78, 'track' => 0.58, 'face' => '#120A1E', 'grow' => 1.5,
    'heat' => ['#6FF2D0', '#B8F58A', '#FFE27A', '#FFB27A', '#FF7FA8', '#FF4F9A'],
    'heat16' => [96, 92, 93, 93, 95, 91],
];
const READOUT = '#FFF4FB';

/** Clouds: base height (rows), puff spacing (cols), radius range (rows). */
const CLOUD_BASE = 0.9;
const PUFF_SPACING = 4.0;
const PUFF_R = [0.8, 1.4];
const CLOUD = ['#130819', '#331846', '#66356F', '#A65590', '#D98DB5', '#EEC3DA'];
const PUFF_TINTS = ['#FFB38A', '#C9A0FF', '#FF8FC8', '#9ED8FF', '#FFE08A'];

/** Stars. */
const STAR_P = 0.14;
const STAR_GLYPHS = ['·', '˚', '⋆', '✧', '✦'];
const STAR_TINTS = ['#FFFFFF', '#FFE2F2', '#FFF1B8', '#CFE6FF', '#EBD6FF', '#B8FFE6', '#FFC8A8'];
/** Glints on the floss: count, glyph. */
const GLINTS = 5;
const METEOR = ['row' => 0, 'head' => 22, 'len' => 7];

/** Floss fringe ░ probability by glow strength, and strand cells. */
const FRINGE_P = 0.15;
/** Glitter: sparkle glyphs scattered in the glow around the letters. */
const GLITTER_P = 0.08;
const STRAND_P = 0.55;

const DESCRIPTION = 'Skinny 40x11 spun-sugar-dither: CANDY stacked over a taller TOP in anti-aliased Lato Black at sextant '
    . 'sub-cell resolution, rainbow cotton-candy floss with twisted stripes and ▒░ fibre strands; the O of TOP is a segmented '
    . 'radial CPU gauge (mint→lemon→peach→pink, 78% readout); glitter and glints over a dark nebula vignetting to black, '
    . 'with stars, a shooting star and dusky clouds.';
const TAGS = ['skinny', 'spun-sugar-dither', 'stacked-lines', 'rainbow-floss', 'sextant-subcell', 'ttf-letters',
    'radial-gauge-o', 'cpu-meter', 'nebula-sky', 'vignette-to-black', 'twinkling-stars', 'glitter-glints'];

const ANIM_FRAMES = 20;        // logo-play.php <file> 120 → ~2.5 s

// ================================================================ HELPERS

function smooth(float $t, float $a, float $b): float
{
    $t = max(0.0, min(1.0, ($t - $a) / ($b - $a)));
    return $t * $t * (3 - 2 * $t);
}

/** Smooth 2-D value noise in [0,1). */
function vnoise(float $x, float $y, int $seed): float
{
    $ix = (int) floor($x);
    $iy = (int) floor($y);
    $fx = $x - $ix;
    $fy = $y - $iy;
    $fx = $fx * $fx * (3 - 2 * $fx);
    $fy = $fy * $fy * (3 - 2 * $fy);
    $a = lkNoise($ix, $iy, $seed);
    $b = lkNoise($ix + 1, $iy, $seed);
    $c = lkNoise($ix, $iy + 1, $seed);
    $d = lkNoise($ix + 1, $iy + 1, $seed);
    return ($a + ($b - $a) * $fx) * (1 - $fy) + ($c + ($d - $c) * $fx) * $fy;
}

/** Separable box blur, repeated (≈ gaussian). */
function blur(array $m, int $r, int $passes): array
{
    $h = count($m);
    $w = count($m[0]);
    for ($p = 0; $p < $passes; $p++) {
        $t = $m;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $s = 0.0;
                for ($k = -$r; $k <= $r; $k++) {
                    $s += $m[$y][max(0, min($w - 1, $x + $k))];
                }
                $t[$y][$x] = $s / (2 * $r + 1);
            }
        }
        $rv = max(1, intdiv($r * 3, 4));        // sub-pixels are taller than wide
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $s = 0.0;
                for ($k = -$rv; $k <= $rv; $k++) {
                    $s += $t[max(0, min($h - 1, $y + $k))][$x];
                }
                $m[$y][$x] = $s / (2 * $rv + 1);
            }
        }
    }
    return $m;
}

/** Vignette factor at sub-pixel (px, py): 0 at the sides/top → 1 inside. */
function vignette(float $px, float $py): float
{
    $col = $px / 2;
    $row = $py / 3;
    $side = smooth(min($col, W - $col), 0.0, VIGNETTE['side']);
    $top = smooth($row, 0.0, VIGNETTE['top']);
    $bot = VIGNETTE['bottomFloor'] + (1 - VIGNETTE['bottomFloor']) * smooth(H - $row, 0.0, 2.0);
    return $side * $top * $bot;
}

// ================================================================ LETTERS

/**
 * Letter fields at sub-pixel resolution (memoised): cov, letter index, u
 * (0..1 across the text), depth (0 rim → 1 core), glow (blurred cov).
 */
function letters(): array
{
    static $m = null;
    if ($m !== null) {
        return $m;
    }
    $cov = array_fill(0, PH, array_fill(0, PW, 0.0));
    $letter = array_fill(0, PH, array_fill(0, PW, -1));
    $u = array_fill(0, PH, array_fill(0, PW, 0.0));
    $offset = 0;
    foreach (LINES as [$text, $box, [$u0, $u1], $tracking]) {
        $r = tcRender(FONT_FILE, $text, 2, 3, PW, PH, $box, ['tracking' => $tracking, 'ss' => 5, 'maxCondense' => 0.7]);
        [$bx0, , $bx1] = $r['box'];
        for ($y = 0; $y < PH; $y++) {
            for ($x = 0; $x < PW; $x++) {
                if ($y >= $box[1] - 2 && $y < $box[3] + 2) {
                    $u[$y][$x] = $u0 + ($u1 - $u0) * max(0.0, min(1.0, ($x - $bx0) / max(1.0, $bx1 - $bx0)));
                }
                if ($r['cov'][$y][$x] > $cov[$y][$x]) {
                    $cov[$y][$x] = $r['cov'][$y][$x];
                    $letter[$y][$x] = $r['letter'][$y][$x] >= 0 ? $r['letter'][$y][$x] + $offset : -1;
                }
            }
        }
        $offset += strlen(str_replace(' ', '', $text));
    }
    // rows between/outside the lines: u from the nearer line
    for ($y = 0; $y < PH; $y++) {
        $line = $y < (LINES[0][1][3] + LINES[1][1][1]) / 2 ? LINES[0] : LINES[1];
        if ($y < $line[1][1] - 2 || $y >= $line[1][3] + 2) {
            for ($x = 0; $x < PW; $x++) {
                $u[$y][$x] = $line[2][0] + ($line[2][1] - $line[2][0]) * $x / PW;
            }
        }
    }
    // fit the gauge to the O's ink box, then replace the O with the ring
    $x0 = $y0 = PHP_INT_MAX;
    $x1 = $y1 = -1;
    for ($y = 0; $y < PH; $y++) {
        for ($x = 0; $x < PW; $x++) {
            if ($letter[$y][$x] === GAUGE['letter'] && $cov[$y][$x] > 0.5) {
                [$x0, $x1, $y0, $y1] = [min($x0, $x), max($x1, $x + 1), min($y0, $y), max($y1, $y + 1)];
            }
        }
    }
    $geo = ['cx' => ($x0 + $x1) / 2, 'cy' => ($y0 + $y1) / 2, 'rx' => ($x1 - $x0) / 2 + GAUGE['grow'], 'ry' => ($y1 - $y0) / 2];
    for ($y = 0; $y < PH; $y++) {
        for ($x = 0; $x < PW; $x++) {
            $ring = gaugeCoverage($geo, $x, $y);
            if ($letter[$y][$x] === GAUGE['letter'] || $ring > 0) {
                $cov[$y][$x] = $ring;
                $letter[$y][$x] = $ring > 0 ? GAUGE['letter'] : -1;
            }
        }
    }
    $depth = array_fill(0, PH, array_fill(0, PW, 0.0));
    $layer = $cov;
    for ($i = 1; $i <= 3; $i++) {
        $layer = tcDilate($layer, -1.0, 4 / 3);
        for ($y = 0; $y < PH; $y++) {
            for ($x = 0; $x < PW; $x++) {
                $depth[$y][$x] += $layer[$y][$x] / 3;
            }
        }
    }
    $depth = blur($depth, 1, 1);
    return $m = ['cov' => $cov, 'letter' => $letter, 'u' => $u, 'depth' => $depth, 'gauge' => $geo,
        'glow' => blur($cov, GLOW['radius'], GLOW['passes'])];
}

/** Floss colour at sub-pixel (x, y) with depth d. */
function flossColour(float $x, float $y, float $u, float $d, int $seed): array
{
    $phase = ($x + $y * TWIST['slope']) / TWIST['period'] * 2 * M_PI + vnoise($x / 6, $y / 6, $seed + 3) * 1.5;
    $s = 0.5 + 0.5 * sin($phase);                       // which strand of the twist
    $c = lkMix(lkGradient(FLOSS, $u), lkGradient(FLOSS, fmod($u + TWIST['shift'], 1.0)), smooth($s, 0.35, 0.65));
    $hi = smooth(sin($phase * 0.5 + 0.8), 0.75, 1.0);   // white highlight on every other twist
    $c = lkShade($c, RIM_DARK + (CORE_LIGHT - RIM_DARK) * $d);
    return lkMix($c, [255, 248, 252], TWIST['white'] * $hi * (0.4 + 0.6 * $d));
}

/** Glow tint (floss hue at that column). */
function glowColour(float $u): array
{
    return lkScale(lkGradient(FLOSS, max(0.0, min(1.0, $u))), 0.62);
}

// ================================================================ GAUGE

/** Normalised ellipse radius and clockwise-from-top angle (deg) of a sub-pixel point. */
function gaugePolar(array $geo, float $x, float $y): array
{
    $ex = ($x - $geo['cx']) / $geo['rx'];
    $ey = ($y - $geo['cy']) / $geo['ry'];
    return [sqrt($ex * $ex + $ey * $ey), rad2deg(atan2($ex, -$ey))];
}

/** Ring coverage of sub-pixel (px, py), 4×4 supersampled. */
function gaugeCoverage(array $geo, int $px, int $py): float
{
    $hit = 0;
    for ($j = 0; $j < 4; $j++) {
        for ($i = 0; $i < 4; $i++) {
            [$rho] = gaugePolar($geo, $px + ($i + 0.5) / 4, $py + ($j + 0.5) / 4);
            if ($rho <= 1.0 && $rho >= GAUGE['in']) {
                $hit++;
            }
        }
    }
    return $hit / 16;
}

/**
 * What the gauge shows at a sub-pixel: ['kind' => fill|track|notch|face|none,
 * 'pos' => 0..1 along the sweep, 'rho' => radius].
 */
function gaugePart(array $geo, float $x, float $y, float $level): array
{
    [$rho, $a] = gaugePolar($geo, $x, $y);
    if ($rho < GAUGE['in']) {
        return ['kind' => 'face', 'pos' => 0.0, 'rho' => $rho];
    }
    $rel = fmod($a - GAUGE['start'] + 720.0, 360.0);
    if ($rel > GAUGE['sweep']) {
        return ['kind' => 'notch', 'pos' => 0.0, 'rho' => $rho];
    }
    $seg = GAUGE['sweep'] / GAUGE['segments'];
    $inSeg = fmod($rel, $seg);
    if ($inSeg < GAUGE['gap'] / 2 || $inSeg > $seg - GAUGE['gap'] / 2) {
        return ['kind' => 'notch', 'pos' => $rel / GAUGE['sweep'], 'rho' => $rho];
    }
    $idx = (int) floor($rel / $seg);
    $pos = ($idx + 0.5) / GAUGE['segments'];
    return ['kind' => $pos <= $level ? 'fill' : 'track', 'pos' => $pos, 'rho' => $rho, 'idx' => $idx,
        'lead' => $pos <= $level && $pos + 1 / GAUGE['segments'] > $level];
}

/** Gauge colour (truecolour) for a part; null = let the scene show (face/notch use $under). */
function gaugeColour(array $part, array $under): array
{
    $heat = lkGradient(GAUGE['heat'], $part['pos']);
    // bevel: rings lit on the outer rim, darker toward the inner edge
    $bevel = ($part['rho'] - GAUGE['in']) / (1 - GAUGE['in']);
    return match ($part['kind']) {
        'fill' => lkMix(lkShade($heat, -0.12 + 0.30 * $bevel), [255, 250, 252], $part['lead'] ? 0.35 : 0.0),
        'track' => lkMix(lkMix($heat, [150, 130, 170], 0.55), [0, 0, 0], 1 - GAUGE['track']),
        'notch' => lkMix(lkHex(GAUGE['face']), $under, 0.3),
        default => $under,
    };
}

function gaugeColour16(array $part): array
{
    $pal = lkAnsi16();
    return match ($part['kind']) {
        'fill' => $pal[$part['lead'] ? 97 : GAUGE['heat16'][min(5, (int) floor($part['pos'] * 6))]],
        'track' => $pal[90],
        default => $pal[30],
    };
}

// ================================================================ SCENE

function puffs(int $seed): array
{
    static $memo = [];
    if (isset($memo[$seed])) {
        return $memo[$seed];
    }
    $out = [];
    foreach ([0, 1] as $layer) {
        for ($i = -1; $i <= (int) ceil(W / PUFF_SPACING) + 1; $i++) {
            $n = lkNoise($i, $layer, $seed + 40);
            $cx = ($i + 0.5 * $layer + (lkNoise($i, $layer + 2, $seed + 40) - 0.5) * 0.6) * PUFF_SPACING;
            $r = PUFF_R[0] + $n * (PUFF_R[1] - PUFF_R[0]) - 0.3 * $layer;
            $tint = lkHex(PUFF_TINTS[(int) floor(lkNoise($i, $layer + 5, $seed + 40) * count(PUFF_TINTS))]);
            $out[] = [$cx, CLOUD_BASE * (0.35 - 0.3 * $layer), $r, $layer, $tint];
        }
    }
    return $memo[$seed] = $out;
}

/** Cloud colour at sub-pixel (x, y) or null. Lit-sphere puffs, multi-tint. */
function cloudAt(float $x, float $y, int $seed, bool $want16 = false): ?array
{
    $col = $x / 2;
    $hb = H - $y / 3;                                   // rows above bottom
    $best = null;
    foreach (puffs($seed) as [$cx, $cy, $r, $layer, $tint]) {
        $dx = ($col - $cx) / 2;
        $dy = $hb - $cy;
        $q = ($dx * $dx + $dy * $dy) / ($r * $r);
        if ($q >= 1) {
            continue;
        }
        $key = $layer * 10 + (1 - $q);
        if ($best === null || $key > $best[0]) {
            $best = [$key, $dx / $r, $dy / $r, $q, $layer, $tint];
        }
    }
    if ($best === null) {
        return null;
    }
    [, $nx, $ny, $q, $layer, $tint] = $best;
    $light = 0.52 + 0.38 * $ny - 0.14 * $nx - 0.22 * smooth($q, 0.75, 1.0) - 0.16 * (1 - $layer);
    if ($want16) {
        return lkAnsi16()[$light > 0.74 ? 95 : ($light > 0.36 ? 35 : 34)];
    }
    $c = lkGradient(CLOUD, max(0.0, min(1.0, $light)));
    return lkMix($c, lkScale($tint, max(0.2, $light)), 0.28);
}

function nebula(float $x, float $y, int $seed): array
{
    $n1 = vnoise($x / NEBULA_SCALE, $y / NEBULA_SCALE, $seed + 70);
    $n2 = vnoise($x / (NEBULA_SCALE * 0.45), $y / (NEBULA_SCALE * 0.45), $seed + 71);
    return lkGradient(NEBULA, max(0.0, min(1.0, 0.65 * $n1 + 0.35 * $n2)));
}

/**
 * Full-colour sub-pixel image.
 * $reveal(px, py, cov) → visible coverage (animation); $glowK scales glow.
 */
function image(int $seed, ?callable $reveal = null, float $glowK = 1.0, bool $mode16 = false, float $level = GAUGE['level']): array
{
    $L = letters();
    if ($mode16) {
        return image16($seed, $reveal, $level);
    }
    $img = [];
    for ($py = 0; $py < PH; $py++) {
        for ($px = 0; $px < PW; $px++) {
            $x = $px + 0.5;
            $y = $py + 0.5;
            $vig = vignette($x, $y);
            $c = lkScale(nebula($x, $y, $seed), $vig);
            $g = $L['glow'][$py][$px] * $glowK;
            $c = lkMix($c, glowColour($L['u'][$py][$px]), GLOW['mix'] * $g * (0.35 + 0.65 * $vig));
            $cl = cloudAt($x, $y, $seed);
            if ($cl !== null) {
                $c = lkScale($cl, 0.25 + 0.75 * smooth(min($x / 2, W - $x / 2), 0.0, VIGNETTE['side'] * 0.8));
            }
            $cov = $L['cov'][$py][$px];
            if ($reveal !== null) {
                $cov = $reveal($px, $py, $cov);
            }
            $geo = $L['gauge'];
            [$rho] = gaugePolar($geo, $x, $y);
            if ($rho < GAUGE['in'] && $reveal === null || $rho < GAUGE['in'] && $reveal($px, $py, 1.0) > 0.5) {
                $c = lkMix($c, lkHex(GAUGE['face']), 0.7 * (1 - $rho / GAUGE['in']) + 0.2);    // dark dial face
            }
            if ($cov > 0) {
                $ink = $L['letter'][$py][$px] === GAUGE['letter']
                    ? gaugeColour(gaugePart($geo, $x, $y, $level), $c)
                    : flossColour($x, $y, $L['u'][$py][$px], $L['depth'][$py][$px], $seed);
                $c = lkMix($c, $ink, $cov);
            }
            $img[$py][$px] = $c;
        }
    }
    return $img;
}

/**
 * 16-colour scene painted straight in the ANSI palette: black sky (the
 * vignette is the terminal's own black), palette-banded clouds, one bright
 * hue per letter, hard 50 % coverage edges (sextants keep them smooth).
 */
function image16(int $seed, ?callable $reveal, float $level = GAUGE['level']): array
{
    $L = letters();
    $pal = lkAnsi16();
    $img = [];
    for ($py = 0; $py < PH; $py++) {
        for ($px = 0; $px < PW; $px++) {
            $c = cloudAt($px + 0.5, $py + 0.5, $seed, true) ?? $pal[30];
            $cov = $L['cov'][$py][$px];
            if ($reveal !== null) {
                $cov = $reveal($px, $py, $cov);
            }
            if ($cov >= 0.5) {
                $c = $L['letter'][$py][$px] === GAUGE['letter']
                    ? gaugeColour16(gaugePart($L['gauge'], $px + 0.5, $py + 0.5, $level))
                    : $pal[LETTER16[max(0, $L['letter'][$py][$px]) % count(LETTER16)]];
            }
            $img[$py][$px] = $c;
        }
    }
    return $img;
}

// ================================================================ OVERLAYS

/** Per-cell letter coverage / glow averages + whether a cloud touches it. */
function cellInfo(int $seed): array
{
    static $memo = [];
    if (isset($memo[$seed])) {
        return $memo[$seed];
    }
    $L = letters();
    $info = [];
    for ($cy = 0; $cy < H; $cy++) {
        for ($cx = 0; $cx < W; $cx++) {
            $cov = $glow = 0.0;
            $cloud = false;
            $gauge = false;
            $min = 1.0;
            for ($k = 0; $k < 6; $k++) {
                $px = $cx * 2 + $k % 2;
                $py = $cy * 3 + intdiv($k, 2);
                $cov += $L['cov'][$py][$px] / 6;
                $min = min($min, $L['cov'][$py][$px]);
                $glow += $L['glow'][$py][$px] / 6;
                $cloud = $cloud || cloudAt($px + 0.5, $py + 0.5, $seed) !== null;
                $gauge = $gauge || gaugePolar($L['gauge'], $px + 0.5, $py + 0.5)[0] <= 1.05;
            }
            $info[$cy][$cx] = ['cov' => $cov, 'min' => $min, 'glow' => $glow, 'cloud' => $cloud, 'gauge' => $gauge,
                'u' => $L['u'][$cy * 3 + 1][$cx * 2 + 1]];
        }
    }
    return $memo[$seed] = $info;
}

/**
 * Glyph overlays on the fitted cells: strands, fringe, stars, glints,
 * meteor. $t = animation time (null = static), $ink = letter visibility 0..1.
 * $q maps an rgb to the output depth's palette (identity for tc).
 */
function overlays(array $cells, int $seed, ?float $t, float $ink, callable $q, string $depth = 'tc', float $level = GAUGE['level']): array
{
    $info = cellInfo($seed);
    $L = letters();
    $glints = [];
    // glints: the brightest solid cells near the letter tops, spread out
    $cands = [];
    for ($cy = 0; $cy < H; $cy++) {
        for ($cx = 0; $cx < W; $cx++) {
            if ($info[$cy][$cx]['min'] > 0.9 && !$info[$cy][$cx]['gauge']) {
                $cands[] = [$cx, $cy, lkNoise($cx, $cy, $seed + 90) - 0.08 * $cy];
            }
        }
    }
    usort($cands, static fn ($a, $b) => $b[2] <=> $a[2]);
    foreach ($cands as [$cx, $cy]) {
        foreach ($glints as [$gx]) {
            if (abs($gx - $cx) < 6) {
                continue 2;
            }
        }
        $glints[] = [$cx, $cy];
        if (count($glints) >= GLINTS) {
            break;
        }
    }
    $glintSet = [];
    foreach ($glints as $i => [$gx, $gy]) {
        $glintSet["$gx,$gy"] = $i;
    }

    for ($cy = 0; $cy < H; $cy++) {
        for ($cx = 0; $cx < W; $cx++) {
            $in = $info[$cy][$cx];
            [$g, $fg, $bg] = $cells[$cy][$cx];
            $base = $bg ?? $fg ?? [0, 0, 0];
            $n = lkNoise($cx, $cy, $seed + 1);
            // glints on the floss
            if (isset($glintSet["$cx,$cy"]) && $ink >= 1.0) {
                $i = $glintSet["$cx,$cy"];
                $on = $t === null ? true : sin($t * 9 + $i * 1.7) > -0.2;
                if ($on) {
                    $cells[$cy][$cx] = [$i % 3 === 0 ? '✧' : '✦', $q([255, 255, 255]), $q($base), 'glint'];
                    continue;
                }
            }
            if ($in['gauge']) {
                continue;   // the gauge keeps its clean segments; readout drawn below
            }
            // spun strands: solid letter cells on a twist highlight → ▒/░ fibre
            if ($depth !== '16' && $in['min'] > 0.9 && $ink >= 0.6 && $g === ' ' && $n < STRAND_P) {
                $px = $cx * 2 + 1;
                $py = $cy * 3 + 1;
                $phase = ($px + $py * TWIST['slope']) / TWIST['period'] * 2 * M_PI + vnoise($px / 6, $py / 6, $seed + 3) * 1.5;
                $s = sin($phase);
                if (abs($s) < 0.35) {
                    $alt = flossColour($px, $py, fmod($in['u'] + TWIST['shift'] * 2, 1.0), $L['depth'][$py][$px], $seed);
                    $cells[$cy][$cx] = [$s > 0 ? '▒' : '░', $q(lkMix($alt, [255, 255, 255], 0.25)), $q($base), 'strand'];
                    continue;
                }
            }
            if ($in['cov'] > 0.04 || $in['cloud']) {
                continue;
            }
            // glitter in the glow: small bright sparkles hugging the letters
            if ($in['glow'] > 0.10 && lkNoise($cx, $cy, $seed + 95) < GLITTER_P * $ink) {
                $k = lkNoise($cx, $cy, $seed + 96);
                $tw = $t === null ? 1.0 : 0.5 + 0.5 * sin($t * 14 + $k * 9);
                $glyph = $tw < 0.3 ? '·' : ($k < 0.5 ? '✦' : ($k < 0.8 ? '✧' : '⋆'));
                $tint = lkMix([255, 255, 255], lkGradient(FLOSS, max(0.0, min(1.0, $in['u']))), 0.35);
                $cells[$cy][$cx] = [$glyph, $q(lkMix($base, $tint, 0.55 + 0.45 * $tw)), $q($base), 'glitter'];
                continue;
            }
            // floss fringe: ░ wisps in the glow
            if ($in['glow'] > 0.10 && $n < FRINGE_P * min(1.0, $in['glow'] * 3) * $ink) {
                $fc = lkMix(lkGradient(FLOSS, max(0.0, min(1.0, $in["u"]))), $base, 0.55);
                $cells[$cy][$cx] = ['░', $q($fc), $q($base), 'fringe'];
                continue;
            }
            // stars, brighter toward the middle so the edges stay dark
            if ($in['glow'] < 0.12 && lkNoise($cx, $cy, $seed + 5) < STAR_P) {
                $r = lkNoise($cx, $cy, $seed + 6);
                $size = $r < 0.40 ? 0 : ($r < 0.58 ? 1 : ($r < 0.76 ? 2 : ($r < 0.90 ? 3 : 4)));
                if ($t !== null) {
                    $size = max(0, min(4, $size + (int) round(sin(lkNoise($cx, $cy, $seed + 8) * 6.283 + $t * 12) * 1.3)));
                }
                $vig = vignette($cx * 2 + 1, $cy * 3 + 1.5);
                $tint = lkHex(STAR_TINTS[(int) floor(lkNoise($cx, $cy, $seed + 7) * count(STAR_TINTS))]);
                $fc = lkMix($base, $tint, (0.35 + 0.13 * $size) * (0.45 + 0.55 * $vig));
                $cells[$cy][$cx] = [STAR_GLYPHS[$size], $q($fc), $q($base), 'star'];
            }
        }
    }
    // shooting star
    $head = null;
    if ($t === null) {
        $head = METEOR['head'];
    } elseif ($t > 0.12 && $t < 0.8) {
        $head = (int) round(W + 2 - ($t - 0.12) / 0.68 * (W + 2 - METEOR['head']));
    }
    if ($head !== null) {
        $y = METEOR['row'];
        for ($i = 0; $i <= METEOR['len']; $i++) {
            $x = $head + $i;
            if (!isset($cells[$y][$x]) || $info[$y][$x]['cov'] > 0.04) {
                continue;
            }
            $base = $cells[$y][$x][2] ?? [0, 0, 0];
            $k = 1 - $i / (METEOR['len'] + 1);
            $glyph = $i === 0 ? '✦' : ($i < 4 ? '━' : ($i < 7 ? '─' : '·'));
            $col = lkMix(lkHex('#FFD9F0'), lkHex('#9FD8FF'), $i / METEOR['len']);
            $cells[$y][$x] = [$glyph, $q(lkMix($base, $col, 0.3 + 0.7 * $k)), $q($base), 'meteor'];
        }
    }
    // gauge readout: percentage centred in the dial face
    if ($ink > 0.3) {
        $geo = $L['gauge'];
        $text = sprintf('%d%%', (int) round($level * 100));
        $cy = (int) floor($geo['cy'] / 3);
        $cx0 = (int) round($geo['cx'] / 2 - mb_strlen($text) / 2);
        foreach (mb_str_split($text) as $i => $ch) {
            $base = $cells[$cy][$cx0 + $i][2] ?? $cells[$cy][$cx0 + $i][1] ?? [0, 0, 0];
            $col = $i === mb_strlen($text) - 1 ? lkGradient(GAUGE['heat'], 0.85) : lkHex(READOUT);
            $cells[$cy][$cx0 + $i] = [$ch, $q($col), $q($base), 'readout'];
        }
    }
    return $cells;
}

// ================================================================ DEPTHS

/** Per-letter 16-colour floss (one hue per letter keeps it legible), and its strand shade. */
const LETTER16 = [95, 91, 93, 92, 96, 94, 95, 93];

/** 16-colour picker: near-black stays black; otherwise hue-preserving. */
function map16(array $c): array
{
    $pal = lkAnsi16();
    $max = max($c);
    if ($max < 70) {
        return $pal[30];
    }
    return $pal[lkTo16($c)];
}

/**
 * 256-colour quantiser: very dark → black (nearest-256 turned the dim glow
 * into a grey box), saturated colours keep their hue (cube entries within
 * 30°), the rest redmean-nearest.
 */
function q256(array $c): array
{
    static $memo = [];
    $k = implode(',', $c);
    if (isset($memo[$k])) {
        return $memo[$k];
    }
    $pal = lkXterm256();
    if (max($c) < 34) {
        return $memo[$k] = $pal[16];
    }
    if (max($c) < 110) {
        // dim haze: push toward black rather than into saturated navy/plum cube steps
        return $memo[$k] = $pal[lkTo256(lkScale($c, 0.8))];
    }
    [$h, $s] = hueSat($c);
    $best = null;
    $bd = INF;
    foreach ($pal as $p) {
        if ($s > 0.25) {
            [$ph, $ps] = hueSat($p);
            $dh = abs($ph - $h);
            if ($ps < 0.15 || min($dh, 360 - $dh) > 30) {
                continue;
            }
        }
        $d = lkDist($c, $p);
        if ($d < $bd) {
            $bd = $d;
            $best = $p;
        }
    }
    return $memo[$k] = $best ?? $pal[lkTo256($c)];
}

/** @return array{float,float} hue degrees, saturation 0..1 */
function hueSat(array $c): array
{
    [$r, $g, $b] = array_map(static fn (int $v): float => $v / 255, $c);
    $max = max($r, $g, $b);
    $d = $max - min($r, $g, $b);
    if ($d == 0.0) {
        return [0.0, 0.0];
    }
    $h = match (true) {
        $max == $r => 60 * fmod(($g - $b) / $d, 6),
        $max == $g => 60 * (($b - $r) / $d + 2),
        default => 60 * (($r - $g) / $d + 4),
    };
    return [$h < 0 ? $h + 360 : $h, $d / $max];
}

function pins16(): array
{
    $pins = [];
    foreach (lkAnsi16() as $code => $rgb) {
        $pins[implode(',', $rgb)] = $code;
    }
    return $pins;
}

function render(int $seed, string $depth, ?float $t = null, ?callable $reveal = null, float $glowK = 1.0, float $ink = 1.0, float $level = GAUGE['level']): string
{
    $img = image($seed, $reveal, $glowK, $depth === '16', $level);
    $q = match ($depth) {
        'tc' => static fn (array $c): array => $c,
        '256' => static fn (array $c): array => q256($c),
        '16' => static fn (array $c): array => map16($c),
    };
    if ($depth === '256') {
        $img = array_map(static fn (array $row): array => array_map(static fn (array $c): array => q256($c), $row), $img);
    }
    $cells = siCells($img, $depth !== 'tc');
    $cells = overlays($cells, $seed, $t, $ink, $q, $depth, $level);
    return lkEncode($cells, $depth, $depth === '16' ? pins16() : [], 'nearest');
}

function animFrames(int $seed, string $depth): array
{
    $frames = [];
    for ($f = 0; $f < ANIM_FRAMES; $f++) {
        $t = $f / ANIM_FRAMES;
        $reveal = static function (int $px, int $py, float $cov) use ($t, $seed): float {
            if ($cov <= 0) {
                return 0.0;
            }
            $arrive = vnoise($px / 5, $py / 4, $seed + 21) * 0.5;
            return $cov * smooth($t, $arrive, $arrive + 0.35);
        };
        $ink = smooth($t, 0.1, 0.85);
        $level = GAUGE['level'] * smooth($t, 0.35, 0.95);   // the gauge sweeps up as the floss settles
        $frames[] = render($seed, $depth, $t, $reveal, smooth($t, 0.0, 0.8), $ink, $level);
    }
    return $frames;
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$seed = SEED;
foreach ($argv as $a) {
    if (str_starts_with($a, '--seed=')) {
        $seed = (int) substr($a, 7);
    }
}
$dir = dirname(__DIR__);
$depths = ['tc', '256', '16'];
foreach ($argv as $a) {
    if (str_starts_with($a, '--depth=')) {
        $depths = [substr($a, 8)];
    }
}
foreach ($depths as $depth) {
    if (!in_array($depth, ['tc', '256', '16'], true)) {
        continue;   // --depth=none: library mode (require + call animFrames()/render())
    }
    $static = render($seed, $depth);
    [$w, $h] = lkVerify($static, $depth);
    lkCheckDepth($static, $depth, $depth);
    echo $static;
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    if ($write) {
        $r = skWriteSkinny($dir, SLUG, $depth, $static, DESCRIPTION . " ($depth)", TAGS);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        $anim = lkAnim(animFrames($seed, $depth), $static);
        $r = skWriteSkinny($dir, SLUG . '-anim', $depth, $anim,
            'Animated: rainbow cotton-candy floss condenses into CANDY TOP while the O gauge sweeps 0→78% with a counting '
            . 'readout, the candy glow swells, stars twinkle, glints flash and a shooting star crosses the nebula; ends on the static frame; ' . ANIM_FRAMES
            . " frames, play with tools/logo-play.php <file> 120 (~2.5 s) ($depth)",
            [...TAGS, 'animated', 'condense-from-noise', 'gauge-sweep', 'twinkle-anim']);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
}
