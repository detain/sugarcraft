<?php

declare(strict_types=1);

/**
 * generate-neon-tube-sign (v4): "candy top" bent in rounded lowercase neon
 * tube (white-hot cores, one candy colour per letter) floating over btop-style
 * neon graphs on clean black: a braille "download / cpu" area graph rising from
 * the bottom with btop's height gradient, and an "upload" area graph hanging
 * from the top (candy-top's mirrored net panel, split to the edges).
 *
 * Pipeline
 *   letters: centre-line polylines (visual units, cell = 1×2 units) →
 *     per-sample distance fields (precomputed once) → shade(): additive glow
 *     (tight + haze) + tube body with white-hot core → supersampled sub-pixel
 *     image (2×3 per cell) → sextant-image-raster cells.
 *   graphs: per-dot braille layer (2×4 dots per cell) from seeded traffic
 *     (braille-netgraph.php bnTraffic), coloured by dot height along a btop-like
 *     gradient, kept out of a moat round every tube, faded at the left/right
 *     edges; a graph cell takes the letter glow as its background.
 *   depths: tc · 256 (quantise sub-pixels before fitting, graph fg nearest) ·
 *     16 (flat tubes on black + hue-bucketed graph dots).
 *   anim: graphs stream in from the right and scroll (btop style) while the
 *     letters stutter alight; ends exactly on the static frame.
 *
 * Reuse: letterPaths()/HUES (any centre-line font), GRAPHS (series seeds,
 * heights, gradients) and the glow constants are data.
 *
 *   php generate-neon-tube-sign.php [--write] [--no-anim] [--png=<dir>]
 *   php logo-play.php ../logo-neon-tube-sign-anim-tc-78x11.ansi 60
 */

require_once __DIR__ . '/logo-kit.php';
require_once __DIR__ . '/sextant-image-raster.php';
require_once __DIR__ . '/braille-netgraph.php';

// ================================================================ DESIGN DATA

const SLUG = 'neon-tube-sign';
const COLS = 78;
const ROWS = 11;
const SS = 3;                 // supersamples per sub-pixel axis

const XH = 7.6;               // x-height (units)
const ASC = 11.6;             // ascender height
const DESC = 3.6;             // descender depth
const BASE = 15.0;            // baseline y (units, y down; 22 units total)
const GAP = 1.9;              // letter gap
const SPACE = 6.0;            // word space
const TUBE_R = 0.72;          // letter tube radius (units)

/**
 * Centre-line letters in local units: x from 0, y relative to baseline (up = negative).
 * Each letter: [width, list of strokes]; stroke = ['l', x1,y1,x2,y2] |
 * ['a', cx,cy,rx,ry,deg0,deg1] (angle CCW, 0 = right).
 */
function letterPaths(): array
{
    $h = XH;
    $rx = 2.75;
    $ry = $h / 2;
    return [
        'c' => [2 * $rx, [['a', $rx, -$ry, $rx, $ry, 42, 318]]],
        'a' => [2 * $rx, [['a', $rx, -$ry, $rx, $ry, 0, 360], ['l', 2 * $rx, -$h, 2 * $rx, 0]]],
        'n' => [5.4, [['l', 0, -$h, 0, 0], ['a', 2.7, -$h + 2.9, 2.7, 2.9, 180, 0], ['l', 5.4, -$h + 2.9, 5.4, 0]]],
        'd' => [2 * $rx, [['a', $rx, -$ry, $rx, $ry, 0, 360], ['l', 2 * $rx, -ASC, 2 * $rx, 0]]],
        'y' => [5.4, [
            ['l', 0, -$h, 0, -2.9], ['a', 2.7, -2.9, 2.7, 2.9, 180, 360],
            ['l', 5.4, -$h, 5.4, DESC - 1.6], ['a', 3.2, DESC - 1.6, 2.2, 1.6, 0, -150],
        ]],
        't' => [4.2, [['l', 1.4, -ASC + 0.6, 1.4, -1.6], ['a', 3.0, -1.6, 1.6, 1.6, 180, 290], ['l', -0.4, -$h, 4.2, -$h]]],
        'o' => [2 * $rx + 0.3, [['a', $rx + 0.15, -$ry, $rx + 0.15, $ry, 0, 360]]],
        'p' => [2 * $rx + 0.4, [['l', 0, -$h, 0, DESC], ['a', $rx + 0.4, -$ry, $rx, $ry, 0, 360]]],
    ];
}

const TEXT = 'candy top';
/** One candy-neon hue per letter (pink → violet). */
const HUES = ['#FF3EA5', '#FF8A2A', '#FFE03A', '#7CFF4A', '#2EF2C8', '#38C6FF', '#6F7DFF', '#C24DFF'];

// ================================================================ GEOMETRY

/** Polyline (absolute units) for a stroke. */
function strokePoints(array $s, float $ox): array
{
    if ($s[0] === 'l') {
        return [[$ox + $s[1], BASE + $s[2]], [$ox + $s[3], BASE + $s[4]]];
    }
    [, $cx, $cy, $rx, $ry, $a0, $a1] = $s;
    $n = max(4, (int) ceil(abs($a1 - $a0) / 6));
    $pts = [];
    for ($i = 0; $i <= $n; $i++) {
        $a = deg2rad($a0 + ($a1 - $a0) * $i / $n);
        $pts[] = [$ox + $cx + $rx * cos($a), BASE + $cy - $ry * sin($a)];
    }
    return $pts;
}

/** @return list<array{segs: list<array>, bbox: array}> one entry per letter */
function letters(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $paths = letterPaths();
    $total = 0.0;
    $chars = str_split(TEXT);
    foreach ($chars as $i => $ch) {
        $total += $ch === ' ' ? SPACE : $paths[$ch][0] + ($i < count($chars) - 1 && $chars[$i + 1] !== ' ' ? GAP : 0);
    }
    $x = (COLS - $total) / 2;
    $out = [];
    foreach ($chars as $i => $ch) {
        if ($ch === ' ') {
            $x += SPACE;
            continue;
        }
        $segs = [];
        $bb = [INF, INF, -INF, -INF];
        foreach ($paths[$ch][1] as $s) {
            $pts = strokePoints($s, $x);
            for ($k = 0; $k < count($pts) - 1; $k++) {
                $segs[] = [$pts[$k][0], $pts[$k][1], $pts[$k + 1][0], $pts[$k + 1][1]];
            }
            foreach ($pts as [$px, $py]) {
                $bb = [min($bb[0], $px), min($bb[1], $py), max($bb[2], $px), max($bb[3], $py)];
            }
        }
        $out[] = ['segs' => $segs, 'bbox' => $bb];
        $x += $paths[$ch][0] + ((($chars[$i + 1] ?? ' ') !== ' ') ? GAP : 0);
    }
    return $memo = $out;
}

function segDist(float $px, float $py, array $s): float
{
    [$x1, $y1, $x2, $y2] = $s;
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    $l2 = $dx * $dx + $dy * $dy;
    $t = $l2 > 0 ? max(0.0, min(1.0, (($px - $x1) * $dx + ($py - $y1) * $dy) / $l2)) : 0.0;
    return hypot($px - $x1 - $t * $dx, $py - $y1 - $t * $dy);
}


const BG = '#000000';
const STARS = [];             // [x, y, size, colour] — v4 keeps the black clean for the graphs
const GLOW_TIGHT = [0.62, 0.55];   // [sigma, gain] outside the tube
const GLOW_HAZE = [3.0, 0.075];
const UNLIT = '#2A2432';

/**
 * btop-style graphs. from: 'bottom' grows up from the bottom edge, 'top' hangs
 * down from the top edge. max = peak height as a fraction of the 44 dot rows.
 * tspan = height (fraction of rows) the colour gradient spans; grad: low → high colour stops (btop *_start → *_mid → *_end).
 */
const GRAPHS = [
    'down' => ['from' => 'bottom', 'seed' => 7, 'base' => 0.55, 'burst' => 0.75, 'max' => 0.62, 'tspan' => 0.34,
        'grad' => ['#0E4A38', '#16B884', '#6CFFB4', '#FFE45C', '#FF5C9A']],
    'up' => ['from' => 'top', 'seed' => 23, 'base' => 0.45, 'burst' => 0.7, 'max' => 0.40, 'tspan' => 0.24,
        'grad' => ['#1C1046', '#4F36C9', '#8F6BFF', '#FF8AE6']],
];
const MOAT = 1.15;            // no graph dots within this distance (units) of a tube centre-line
const EDGE_FADE = 10.0;       // graph fade-in width (cols) at the left/right edges
const GRAPH_GAIN = 0.92;

// ================================================================ FIELDS

function minLetterDist(float $x, float $y): array
{
    $m = INF;
    $ds = [];
    foreach (letters() as $li => $l) {
        [$a, $b, $c, $d] = $l['bbox'];
        if ($x < $a - 7 || $x > $c + 7 || $y < $b - 7 || $y > $d + 7) {
            continue;
        }
        $k = INF;
        foreach ($l['segs'] as $s) {
            $k = min($k, segDist($x, $y, $s));
        }
        $ds[$li] = $k;
        $m = min($m, $k);
    }
    return [$m, $ds];
}

/**
 * Precompute per-sample letter distances + per-dot moat mask once.
 * @return array{w:int,h:int,s:array,free:array}
 */
function precompute(): array
{
    $pw = COLS * 2;
    $ph = ROWS * 3;
    $samples = [];
    for ($py = 0; $py < $ph; $py++) {
        for ($px = 0; $px < $pw; $px++) {
            for ($j = 0; $j < SS; $j++) {
                for ($i = 0; $i < SS; $i++) {
                    $x = ($px + ($i + 0.5) / SS) * 0.5;
                    $y = ($py + ($j + 0.5) / SS) * (2 / 3);
                    $samples[$py][$px][] = [$x, $y, minLetterDist($x, $y)[1]];
                }
            }
        }
    }
    $free = [];
    for ($dy = 0; $dy < ROWS * 4; $dy++) {
        for ($dx = 0; $dx < COLS * 2; $dx++) {
            $x = ($dx + 0.5) * 0.5;
            $y = ($dy + 0.5) * 0.5;
            $ok = minLetterDist($x, $y)[0] > MOAT;
            foreach (letters() as $l) {           // letters occlude the graph: no dots in their counters
                [$a, $b, $c, $d] = $l['bbox'];
                if ($x > $a - 0.3 && $x < $c + 0.3 && $y > $b - 0.3 && $y < $d + 0.3) {
                    $ok = false;
                }
            }
            $free[$dy][$dx] = $ok;
        }
    }
    // cells holding any tube body stay sextant (graphs never overwrite a letter)
    $tube = [];
    foreach ($samples as $py => $row) {
        foreach ($row as $px => $smps) {
            foreach ($smps as [, , $ds]) {
                if ($ds && min($ds) < TUBE_R + 0.1) {
                    $tube[intdiv($py, 3)][intdiv($px, 2)] = true;
                }
            }
        }
    }
    return ['w' => $pw, 'h' => $ph, 's' => $samples, 'free' => $free, 'tube' => $tube];
}

// ================================================================ LETTER SHADING

/**
 * @param array $st 'L' => per-letter brightness, 'stars' => per-star twinkle,
 *        'flat' => true for the 16-colour tubes-only render
 */
function shade(array $smp, array $st): array
{
    [$x, $y, $ds] = $smp;
    static $hues = null, $bg = null, $unlit = null;
    if ($hues === null) {
        $hues = array_map('lkHex', HUES);
        $bg = lkHex(BG);
        $unlit = lkHex(UNLIT);
    }
    $flat = $st['flat'] ?? false;
    $c = [(float) $bg[0], (float) $bg[1], (float) $bg[2]];

    $glow = [0.0, 0.0, 0.0];
    [$s1, $g1] = GLOW_TIGHT;
    [$s2, $g2] = GLOW_HAZE;
    $minLetter = INF;
    $minLi = -1;
    foreach ($ds as $li => $d) {
        $b = $st['L'][$li] ?? 1.0;
        if ($d < $minLetter) {
            $minLetter = $d;
            $minLi = $li;
        }
        if ($b <= 0) {
            continue;
        }
        $e = max(0.0, $d - TUBE_R);
        $k = $b * ($g1 * exp(-$e * $e / ($s1 * $s1)) + $g2 * exp(-$e * $e / ($s2 * $s2)));
        for ($ch = 0; $ch < 3; $ch++) {
            $glow[$ch] += $hues[$li][$ch] * $k;
        }
    }
    for ($ch = 0; $ch < 3 && !$flat; $ch++) {
        $l = 1 - exp(-$glow[$ch] / 255 * 1.25);
        $c[$ch] = $c[$ch] + (255 - $c[$ch]) * $l * 0.85;
    }

    if ($minLi >= 0 && $minLetter < TUBE_R) {
        $b = $st['L'][$minLi] ?? 1.0;
        $col = $hues[$minLi];
        $t = $minLetter / TUBE_R;
        $lit = lkMix($col, [255, 255, 255], $flat ? 0.0 : 0.6 * (1 - $t * $t) ** 2);
        $lit = lkMix($lit, lkScale($col, 0.8), max(0.0, $t - 0.75) * 2);
        $glass = lkMix($unlit, lkScale($unlit, 1.5), 1 - $t);
        $out = lkMix($glass, $lit, $b);
        $aa = min(1.0, (TUBE_R - $minLetter) / 0.18);
        for ($ch = 0; $ch < 3; $ch++) {
            $c[$ch] += ($out[$ch] - $c[$ch]) * $aa;
        }
    }

    $sb = $st['stars'] ?? [];
    foreach ($flat ? [] : STARS as $si => [$sx, $sy, $ssz, $scol]) {
        $dx = abs($x - $sx);
        $dy = abs($y - $sy) * 0.8;
        $i = ($sb[$si] ?? 1.0) * 0.7 * (exp(-($dx * $dx + $dy * $dy) / 0.1)
            + exp(-$dy * $dy / 0.05) * max(0, 1 - $dx / $ssz)
            + exp(-$dx * $dx / 0.08) * max(0, 1 - $dy / ($ssz * 0.8)));
        if ($i > 0.01) {
            $sc = lkHex($scol);
            for ($ch = 0; $ch < 3; $ch++) {
                $c[$ch] += ($sc[$ch] - $c[$ch]) * min(1.0, $i);
            }
        }
    }
    return $c;
}

/** Sub-pixel image for a state (memoised: graphs don't touch it). */
function image(array $F, array $st): array
{
    static $memo = [];
    $key = json_encode($st);
    if (isset($memo[$key])) {
        return $memo[$key];
    }
    $img = [];
    $n = SS * SS;
    for ($py = 0; $py < $F['h']; $py++) {
        for ($px = 0; $px < $F['w']; $px++) {
            $acc = [0.0, 0.0, 0.0];
            foreach ($F['s'][$py][$px] as $smp) {
                $c = shade($smp, $st);
                $acc[0] += $c[0];
                $acc[1] += $c[1];
                $acc[2] += $c[2];
            }
            $img[$py][$px] = [
                (int) max(0, min(255, round($acc[0] / $n))),
                (int) max(0, min(255, round($acc[1] / $n))),
                (int) max(0, min(255, round($acc[2] / $n))),
            ];
        }
    }
    return $memo[$key] = $img;
}

// ================================================================ GRAPHS

/** Long seeded series per graph (enough for the animation's scroll). */
function series(): array
{
    static $memo = null;
    if ($memo === null) {
        foreach (GRAPHS as $name => $g) {
            $memo[$name] = bnTraffic(COLS * 2 + 400, $g['seed'], $g['base'], $g['burst'], 2);
        }
    }
    return $memo;
}

/**
 * Lit braille dots: [dy][dx] => rgb.
 * @param int $shift   series offset (scroll); 0 = static frame
 * @param int $arrived dot columns filled from the right (btop graphs start empty)
 */
function graphDots(array $F, int $shift, int $arrived = PHP_INT_MAX): array
{
    $hd = ROWS * 4;
    $wd = COLS * 2;
    $dots = [];
    foreach (GRAPHS as $name => $g) {
        $data = series()[$name];
        $stops = $g['grad'];
        for ($x = 0; $x < $wd; $x++) {
            if ($x < $wd - $arrived) {
                continue;
            }
            $h = (int) round($data[$x + $shift] * $g['max'] * $hd);
            $fade = min(1.0, min($x / 2 + 0.5, COLS - $x / 2 - 0.5) / EDGE_FADE);
            $fade = $fade * $fade * (3 - 2 * $fade);
            for ($k = 0; $k < $h; $k++) {
                $dy = $g['from'] === 'bottom' ? $hd - 1 - $k : $k;
                if (!$F['free'][$dy][$x]) {
                    continue;
                }
                $t = ($k + 1) / ($g['tspan'] * $hd);   // gradient spans the visible band
                $c = lkScale(lkGradient($stops, min(1.0, $t)), GRAPH_GAIN * (0.12 + 0.88 * $fade));
                $dots[$dy][$x] = $c;
            }
        }
    }
    return $dots;
}

/**
 * Overlay braille graph cells onto sextant cells. A cell becomes braille when
 * it has lit dots and no tube body; its bg is the letter glow beneath it.
 * @param callable(array): array $fgMap colour mapper for the depth
 */
function overlay(array $cells, array $dots, callable $fgMap, array $tube = []): array
{
    for ($cy = 0; $cy < ROWS; $cy++) {
        for ($cx = 0; $cx < COLS; $cx++) {
            $bits = 0;
            $acc = [0, 0, 0];
            $n = 0;
            for ($dy = 0; $dy < 4; $dy++) {
                for ($dx = 0; $dx < 2; $dx++) {
                    $c = $dots[$cy * 4 + $dy][$cx * 2 + $dx] ?? null;
                    if ($c !== null) {
                        $bits |= BN_BITS[$dy][$dx];
                        $acc[0] += $c[0];
                        $acc[1] += $c[1];
                        $acc[2] += $c[2];
                        $n++;
                    }
                }
            }
            if ($n === 0 || isset($tube[$cy][$cx])) {
                continue;
            }
            [$g, $fg, $bg] = $cells[$cy][$cx];
            // what the sextant cell showed: mean of its two colours ≈ the glow behind
            $under = $bg ?? $fg ?? [0, 0, 0];
            if ($g !== ' ' && $fg !== null && $bg !== null) {
                $under = max($fg) < max($bg) ? $fg : $bg;    // darker side = background glow
            }
            $mean = [intdiv($acc[0], $n), intdiv($acc[1], $n), intdiv($acc[2], $n)];
            $cells[$cy][$cx] = [mb_chr(0x2800 + $bits, 'UTF-8'), $fgMap($mean), $under];
        }
    }
    return $cells;
}

// ================================================================ DEPTHS

/** 16-colour picker: darks → black, else hue bucket (bright where light). */
function map16(array $c): array
{
    $pal = lkAnsi16();
    if (max($c) < 90) {
        return $pal[30];
    }
    // orange would share yellow's bucket (a and n identical): send it to bright red
    if ($c[0] > 100 && $c[1] > 0.3 * $c[0] && $c[1] < 0.72 * $c[0] && $c[2] < 0.4 * $c[0]) {
        return $pal[91];
    }
    return $pal[lkTo16($c)];
}

/** Graph dots at 16 colours: hue bucket, dim → bright black, near-black → black. */
function graph16(array $c): array
{
    $pal = lkAnsi16();
    if (max($c) < 80) {
        return $pal[30];           // faded edges and the dim graph floor go to black
    }
    if (max($c) < 120) {
        $code = lkTo16($c);
        return $pal[$code >= 90 ? $code - 60 : $code];
    }
    return $pal[lkTo16($c)];
}

function render(array $F, array $st, string $depth, int $shift = 0, int $arrived = PHP_INT_MAX): string
{
    $dots = graphDots($F, $shift, $arrived);
    $xt = lkXterm256();
    $cells = match ($depth) {
        'tc' => overlay(siCells(image($F, $st)), $dots, static fn (array $c): array => $c, $F['tube']),
        '256' => overlay(siCells(siQuantise(image($F, $st), '256'), true), $dots,
            static fn (array $c): array => $xt[lkTo256($c)], $F['tube']),
        '16' => overlay(
            siCells(siQuantise(image($F, $st + ['flat' => true]), '16', 0.0, static fn (array $c): array => map16($c)), true),
            $dots, static fn (array $c): array => graph16($c), $F['tube']),
    };
    return lkEncode($cells, $depth, [], 'nearest');
}

// ================================================================ ANIMATION

const ANIM_FRAMES = 56;
const SCROLL = 2;             // dot columns the graphs scroll per frame

/**
 * ≈3.4 s @ 60 ms: graphs stream in from the right and keep scrolling (btop),
 * the letters stutter alight one by one, then hold.
 * @return list<array{0: array, 1: int, 2: int}> [state, shift, arrived]
 */
function animFrames(): array
{
    $stutter = [0.0, 1.0, 0.2, 0.0, 0.9, 0.5, 1.0];
    $n = count(letters());
    $out = [];
    for ($f = 0; $f < ANIM_FRAMES; $f++) {
        $L = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $f - (8 + $i * 3);
            $L[$i] = $t < 0 ? 0.0 : ($stutter[$t] ?? 1.0);
        }
        $out[] = [['L' => $L], (ANIM_FRAMES - $f) * SCROLL, ($f + 1) * 5];
    }
    return $out;
}

// ================================================================ OUTPUT

function appendJsonl(string $dir, string $file, string $slug, string $depth, int $w, int $h, string $desc, array $tags): void
{
    $path = "$dir/logos.jsonl";
    $fp = fopen($path, 'c+');
    flock($fp, LOCK_EX);
    $kept = [];
    foreach (explode("\n", stream_get_contents($fp)) as $l) {
        if ($l !== '' && (json_decode($l, true)['filename'] ?? null) !== $file) {
            $kept[] = $l;
        }
    }
    $kept[] = json_encode([
        'filename' => $file, 'slug' => $slug, 'colors' => $depth, 'width' => $w, 'height' => $h,
        'description' => $desc, 'tags' => $tags,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, implode("\n", $kept) . "\n");
    flock($fp, LOCK_UN);
    fclose($fp);
}

function writeLogo(string $dir, string $slug, string $depth, string $ansi, string $desc, array $tags, bool $write): void
{
    [$w, $h] = lkVerify(lkLastFrame($ansi), "$slug/$depth");
    lkCheckDepth($ansi, $depth, "$slug/$depth");
    $file = "logo-$slug-$depth-{$w}x{$h}.ansi";
    fwrite(STDERR, "[$slug/$depth] {$w}x{$h} ok" . ($write ? " → $file" : '') . "\n");
    if ($write) {
        file_put_contents("$dir/$file", $ansi);
        appendJsonl($dir, $file, $slug, $depth, $w, $h, $desc, $tags);
    }
}

const DESCRIPTION = 'candy top bent in rounded lowercase neon tube (white-hot cores, one candy colour per letter, pink→violet) '
    . 'floating over btop-style neon braille graphs on clean black: a download/cpu area graph rising from the bottom with a '
    . 'green→mint→yellow→pink height gradient and an upload graph hanging from the top in violet→pink (candy-top\'s mirrored '
    . 'net panel split to the edges), a dark moat keeping every letter legible, graphs fading out at the side edges, '
    . 'sextant letters + braille graphs.';
const TAGS = ['neon-tube-sign', 'neon-script', 'rounded-lowercase', 'braille-graphs', 'btop-net-graph', 'rainbow-neon',
    'sextant-smooth', 'black-background'];

$write = in_array('--write', $argv, true);
$noAnim = in_array('--no-anim', $argv, true);
$png = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--png=')) {
        $png = substr($a, 6);
    }
}
$dir = dirname(__DIR__);

$F = precompute();
if ($png !== null) {
    siImagePng(image($F, []), "$png/subpixels.png", 6);
}
$out = [];
foreach (['tc', '256', '16'] as $depth) {
    $out[$depth] = render($F, [], $depth);
    echo $out[$depth], "\n";
}
writeLogo($dir, SLUG, 'tc', $out['tc'], DESCRIPTION, TAGS, $write);
writeLogo($dir, SLUG, '256', $out['256'],
    'neon-tube-sign v4 at xterm-256: letter image quantised to the xterm palette before sextant fitting; braille graph dots '
    . 'by nearest colour over the quantised glow; rainbow neon letters over btop-style up/down graphs on black.',
    [...TAGS, 'xterm-256'], $write);
writeLogo($dir, SLUG, '16', $out['16'],
    'neon-tube-sign v4 at 16 colours: flat pure-hue tube letters on black over hue-bucketed braille graphs (dim dots in the '
    . 'normal colours, peaks in the bright ones).',
    ['neon-tube-sign', 'neon-script', 'rounded-lowercase', 'braille-graphs', 'btop-net-graph', 'role-mapped-16'], $write);

if (!$noAnim) {
    $frames = [];
    foreach (animFrames() as [$st, $shift, $arrived]) {
        $frames[] = render($F, $st, 'tc', $shift, $arrived);
    }
    $anim = lkAnim($frames, $out['tc']);
    writeLogo($dir, SLUG . '-anim', 'tc', $anim,
        'Animated neon-tube-sign v4: the btop-style braille graphs stream in from the right and scroll while the rainbow '
        . 'lowercase neon letters stutter alight one by one, then the static sign holds. ' . (count($frames) + 1)
        . ' frames; play with tools/logo-play.php <file> 60 (about 3.4 s).',
        [...TAGS, 'animated', 'flicker-on', 'graph-scroll'], $write);
}
