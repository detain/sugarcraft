<?php

declare(strict_types=1);

/**
 * generate-gummy-worm-script — "candy top" as joined-up cursive written
 * by two sour gummy worms, rasterised to Unicode sextant mosaics (2×3
 * sub-pixels per cell) with tools/sextant-canvas.php.
 *
 *  - Lettering: centre-line paths (arcs + points, Catmull-Rom smoothed) in
 *    sub-pixel units — LETTERS below is pure data; words are chained into
 *    one continuous stroke, so the joins between letters come for free.
 *  - Colour runs ALONG each worm (arc-length t), not across the screen:
 *    worm 1 "candy" cherry→tangerine→lemon, worm 2 "top" lime→teal→grape.
 *  - Gel shading: every sub-pixel knows its offset across the stroke, so a
 *    light vector gives an upper-left gloss band and a lower-right depth.
 *  - Sugar coating: seeded crystal specks in empty cells touching the worm.
 *  - Net graph (v2): a dim mirrored braille area graph — candy-top's net
 *    panel, download (indigo) growing up, upload (plum) hanging down — fills
 *    the empty cells behind the worms (tools/braille-netgraph.php).
 *  - Animation: the worms crawl in along their path (seq reveal), bright head,
 *    while the net graph scrolls in from the right like the live panel.
 *
 * Usage:
 *   php generate-gummy-worm-script.php            preview tc,256,16 (+ verify)
 *   php generate-gummy-worm-script.php --write    write .ansi + logos.jsonl
 *   php generate-gummy-worm-script.php --png=DIR  also write PNG previews to DIR
 *   php generate-gummy-worm-script.php --v1       the v1 look (no net graph);
 *       preview/--png only — v1 files live in ../archive/gummy-worm-script/v1/
 */

require __DIR__ . '/logo-kit.php';
require __DIR__ . '/sextant-canvas.php';
require __DIR__ . '/braille-netgraph.php';

// ================================================================ DESIGN DATA

const SLUG = 'gummy-worm-script';
const CANVAS = [80, 9];          // cells; cropped to ink + MARGIN columns afterwards
const MARGIN = 1;
const SX = 1.32;                 // horizontal stretch of the letter data (sub-px)
const X0 = 3.0;                  // left offset (sub-px, after stretch)
const BRUSH = [1.8, 1.3];      // brush radii rx, ry in sub-pixels (round on screen)
const SEED = 1207;

// vertical metrics (sub-pixel rows of a 24-row canvas)
const ASC = 1.4;
const XH = 9.4;
const MID = 15.0;
const BASE = 20.6;
const DESC = 25.4;

/** Worm palettes, sampled by arc length (head → tail). */
const WORMS = [
    'candy' => ['#FF2D55', '#FF5A3C', '#FF8A1F', '#FFC23A', '#FFE14D'],
    'top'   => ['#7CF25A', '#2FD4C8', '#4F9BFF', '#9B5CFF'],
];
const GLOSS = '#FFFFFF';
const DEPTH = '#2A0B2E';
const SUGAR = ['#FFFFFF', '#EAF4FF', '#FFF3E0'];
const LIGHT = [-0.55, -0.83];     // light direction in brush-normalised space (up-left)
const SUGAR_DENSITY = 0.17;
const SPARKLES = 3;

/**
 * Background net graph — candy-top's net panel: download area grows up from
 * the axis, upload hangs down (default theme download_* / upload_* stops).
 * Dimmed and kept one cell clear of the worms so the lettering stays the star.
 */
const NET_DOWN = ['#291f75', '#4f43a3', '#b0a9de'];   // download_start/mid/end
const NET_UP = ['#620665', '#7d4180', '#dcafde'];     // upload_start/mid/end
const NET_DIM = 0.82;          // brightness factor on the theme gradient
const NET_AXIS = 24;           // axis dot row (of 36)
const NET_GUTTER = 0;          // cells kept clear around worm ink
const NET_SEED = 4242;
/** 256-colour graph ramp: muted indigos/plums + dark greys (no navy/maroon). */
const NET_256 = [236, 237, 238, 239, 53, 54, 60, 61, 62, 96, 97, 103, 104, 139, 140, 146, 147, 182, 183];

/**
 * Letter centre-lines in sub-pixel units relative to the letter origin.
 * Each letter: 'adv' (advance), 'main' (list of path parts — ['arc',cx,cy,rx,ry,a0,a1]
 * or ['pts',[[x,y],…]]) chained into the word's single stroke, and optional
 * 'extra' strokes drawn separately (t's crossbar) with a fixed t.
 */
function letters(): array
{
    return [
        'c' => ['adv' => 11.5, 'main' => [
            ['arc', 5, MID, 5, 5.6, -45, -290],
        ]],
        'a' => ['adv' => 13.5, 'main' => [
            ['pts', [[0.3, MID + 1.5], [1.4, XH + 1.6], [5, XH], [8.6, XH + 1.2]]],
            ['arc', 5, MID, 5, 5.6, -40, -360],
            ['pts', [[10, XH], [10, BASE - 1.6], [12.5, BASE]]],
        ]],
        'n' => ['adv' => 13.5, 'main' => [
            ['pts', [[1, XH + 0.4], [1, BASE], [1.2, MID]]],
            ['arc', 5.6, MID, 4.4, 5.2, -175, 0],
            ['pts', [[10, BASE - 1.6], [12.5, BASE]]],
        ]],
        'd' => ['adv' => 13.5, 'main' => [
            ['pts', [[0.3, MID + 1.5], [1.4, XH + 1.6], [5, XH], [8.6, XH + 1.2]]],
            ['arc', 5, MID, 5, 5.6, -40, -360],
            ['pts', [[10, ASC], [10, BASE - 1.6], [12.5, BASE]]],
        ]],
        'y' => ['adv' => 15, 'main' => [
            ['pts', [[1, XH + 0.4], [1, MID + 1.5]]],
            ['arc', 5.5, MID + 1.5, 4.5, 4.1, 180, 0],
            ['pts', [[10, XH], [10, DESC - 2.2]]],
            ['arc', 6.4, DESC - 2.2, 3.6, 2.2, 0, 160],
        ]],
        ' ' => ['adv' => 3, 'main' => []],
        't' => ['adv' => 9.5, 'main' => [
            ['pts', [[3.2, ASC + 1], [3.2, BASE - 1.8]]],
            ['arc', 6.2, BASE - 1.8, 3, 1.8, 180, 90],
            ['pts', [[9, BASE - 1.2], [11, XH + 1.5]]],
        ], 'extra' => [
            [[-0.2, XH - 0.6], [7.2, XH - 1.2]],
        ]],
        'o' => ['adv' => 13, 'main' => [
            ['arc', 5, MID, 5, 5.6, -80, -440],
            ['pts', [[8.8, XH - 0.2], [12, XH + 0.6]]],
        ]],
        'p' => ['adv' => 12, 'main' => [
            ['pts', [[1, XH + 0.4], [1, DESC], [1.1, MID - 1.5]]],
            ['arc', 5.6, MID, 4.6, 5.4, -150, 165],
        ]],
    ];
}

/** Words → worms; each worm is one continuous stroke through its letters. */
const WORDS = [['candy', 'candy'], ['top', 'top']];
const WORD_GAP = 4.0;

// ================================================================ BUILD

function xform(array $p, float $o): array
{
    return [X0 + ($o + $p[0]) * SX, $p[1]];
}

/** @return array{0:array,1:int} canvas + total seq */
function canvas(): array
{
    $L = letters();
    $c = scNew(CANVAS[0], CANVAS[1]);
    $seq = 0;
    $o = 0.0;
    foreach (WORDS as $wi => [$word, $palette]) {
        $key = [];
        $extras = [];
        $starts = [];
        foreach (str_split($word) as $ch) {
            foreach ($L[$ch]['main'] as $part) {
                $pts = $part[0] === 'arc'
                    ? scArc($part[1], $part[2], $part[3], $part[4], $part[5], $part[6], (int) max(8, abs($part[6] - $part[5]) / 10))
                    : $part[1];
                foreach ($pts as $p) {
                    $key[] = xform($p, $o);
                }
            }
            foreach ($L[$ch]['extra'] ?? [] as $ex) {
                $extras[] = array_map(static fn ($p) => xform($p, $o), $ex);
                $starts[] = count($key);
            }
            $o += $L[$ch]['adv'];
        }
        $o += WORD_GAP;
        $path = scCatmull($key, 3);
        // crossbars are drawn when the worm head passes them, with that t
        $splitAt = $extras ? (int) round($starts[0] * 3 * 0.9) : count($path);
        $total = count($path) - 1;
        scStroke($c, array_slice($path, 0, $splitAt + 1), BRUSH[0], BRUSH[1], $wi, 0.0, $splitAt / $total, $seq);
        foreach ($extras as $ex) {
            scStroke($c, $ex, BRUSH[0], BRUSH[1], $wi, $splitAt / $total * 0.6, $splitAt / $total, $seq);
        }
        if ($splitAt < $total) {
            scStroke($c, array_slice($path, $splitAt), BRUSH[0], BRUSH[1], $wi, $splitAt / $total, 1.0, $seq);
        }
    }
    return [$c, $seq];
}

/** Worm cell colour: arc-length palette + gel gloss/depth + sugar-grain noise. */
function wormColour(array $cell, int $x, int $y, float $head = 0.0): array
{
    $pal = array_values(WORMS)[$cell['layer']];
    $base = lkGradient($pal, $cell['t']);
    [$nx, $ny] = $cell['shade'];
    $s = $nx * LIGHT[0] + $ny * LIGHT[1];
    if ($s > 0.2) {
        $base = lkMix($base, lkHex(GLOSS), min(0.72, ($s - 0.2) * 1.6));
    } elseif ($s < -0.18) {
        $base = lkMix($base, lkHex(DEPTH), min(0.5, (-$s - 0.18) * 1.1));
    }
    // thin cells (few sub-pixels) are the translucent edge of the gel
    if ($cell['n'] <= 2) {
        $base = lkMix($base, lkHex(DEPTH), 0.18);
    }
    $grain = (lkNoise($x, $y, SEED) - 0.5) * 0.12;
    $base = $grain > 0 ? lkMix($base, [255, 255, 255], $grain) : lkMix($base, [0, 0, 0], -$grain);
    if ($head > 0) {
        $base = lkMix($base, [255, 255, 255], $head);
    }
    return $base;
}

/**
 * Cell grid (logo-kit format). $maxSeq < total reveals the worms partially
 * (animation); $sugar toggles the crystal coating.
 */
function cells(?int $maxSeq = null, bool $sugar = true, string $depth = 'tc', int $netShift = 0, float $netReveal = 1.0): array
{
    static $cache = null;
    $cache ??= canvas();
    [$c, $total] = $cache;
    $sc = scCells($c, $maxSeq);
    $full = scCells($c);
    // crop to the full logo's ink so every frame has identical geometry
    $xs = [];
    foreach ($full as $row) {
        foreach ($row as $x => $cell) {
            if ($cell !== null) {
                $xs[] = $x;
            }
        }
    }
    $x0 = min($xs) - MARGIN;
    $x1 = max($xs) + MARGIN;
    $grid = [];
    foreach ($sc as $y => $row) {
        for ($x = $x0; $x <= $x1; $x++) {
            $cell = $row[$x] ?? null;
            if ($cell === null) {
                $grid[$y][] = [' ', null, null];
                continue;
            }
            $head = 0.0;
            if ($maxSeq !== null && $cell['seq'] > $maxSeq - 40) {
                $head = 0.55 * (1 - ($maxSeq - $cell['seq']) / 40);
            }
            $col = $depth === '16' ? worm16($cell, $head) : wormColour($cell, $x, $y, $head);
            $grid[$y][] = [scGlyph($cell['bits']), $col, null];
        }
    }
    if (NET_ON) {
        netGraph($grid, $sc, $x0, $depth, $netShift, $netReveal);
    }
    if ($sugar) {
        sugarCoat($grid, $sc, $x0);
    }
    if ($depth !== 'tc') {
        $ansi16 = lkAnsi16();
        foreach ($grid as &$row) {
            foreach ($row as &$g) {
                if ($g[1] === null) {
                    continue;
                }
                if ($depth === '256') {
                    // graph dots: plain nearest (the hue/saturation bias that keeps the
                    // worms juicy turns the dim purples into loud navy/maroon)
                    $g[1] = isBraille($g[0]) ? near256($g[1], NET_256) : gw256($g[1]);
                } elseif ($g[0] === '·' || $g[0] === '⋅' || $g[0] === '✧') {
                    $g[1] = $ansi16[$g[0] === '⋅' ? 37 : 97];
                }
            }
        }
        unset($row, $g);
    }
    return $grid;
}

/**
 * 16-colour worm cell chosen by ROLE, not nearest colour: band of the worm
 * by t, bright body, normal-intensity depth side, white gloss crest. Nearest
 * colour turned the orange half of "candy" into a hard red/yellow seam and
 * the gel depth into magenta blotches.
 */
function worm16(array $cell, float $head): array
{
    $bands = [
        0 => [[0.42, 31], [1.01, 33]],
        1 => [[0.30, 32], [0.62, 36], [0.82, 34], [1.01, 35]],
    ];
    $code = 31;
    foreach ($bands[$cell['layer']] as [$lim, $c]) {
        if ($cell['t'] < $lim) {
            $code = $c;
            break;
        }
    }
    [$nx, $ny] = $cell['shade'];
    $s = $nx * LIGHT[0] + $ny * LIGHT[1];
    $code = match (true) {
        $s > 0.5 || $head > 0.3 => 97,
        $s < -0.3 => $code,
        default => $code + 60,
    };
    return lkAnsi16()[$code];
}

/**
 * xterm-256 pick with a hue penalty: plain nearest turned the saturated
 * cherry into salmon. Returns the palette rgb itself so lkTo256 maps it 1:1.
 */
function gw256(array $c, bool $boost = true): array
{
    static $memo = [];
    $k = implode(',', $c) . ($boost ? '+' : '');
    if (isset($memo[$k])) {
        return $memo[$k];
    }
    $hue = static function (array $c): array {
        [$r, $g, $b] = $c;
        $max = max($c);
        $min = min($c);
        $d = $max - $min;
        if ($d < 1) {
            return [0.0, 0.0];
        }
        $h = match (true) {
            $max === $r => fmod(($g - $b) / $d + 6, 6),
            $max === $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };
        return [$h * 60, $d / max(1, $max)];
    };
    // the cube's coarse steps wash mid-saturation colours out: aim at a
    // slightly more saturated target so cherry stays cherry, not salmon
    $grey = array_sum($c) / 3;
    $c = array_map(static fn ($v) => (int) max(0, min(255, round($grey + ($v - $grey) * ($boost ? 1.3 : 1.0)))), $c);
    [$h0, $s0] = $hue($c);
    $best = null;
    $bd = INF;
    foreach (lkXterm256() as $p) {
        [$h1, $s1] = $hue($p);
        $dh = abs($h0 - $h1);
        $dh = min($dh, 360 - $dh);
        $d = lkDist($c, $p) + 40 * $s0 * ($dh * $dh) + 40000 * max(0, $s0 - $s1 - 0.08);
        if ($d < $bd) {
            $bd = $d;
            $best = $p;
        }
    }
    return $memo[$k] = $best;
}

/** Seeded sugar crystals in empty cells touching the worms, plus a few sparkles. */
function sugarCoat(array &$grid, array $sc, int $x0): void
{
    $h = count($grid);
    $w = count($grid[0]);
    $cands = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ($grid[$y][$x][0] !== ' ') {
                continue;
            }
            $near = 0;
            $nb = null;
            foreach ([[-1, 0], [1, 0], [0, -1], [0, 1], [-1, -1], [1, -1], [-1, 1], [1, 1]] as [$dx, $dy]) {
                $cell = $sc[$y + $dy][$x0 + $x + $dx] ?? null;
                if ($cell !== null) {
                    $near++;
                    $nb ??= $grid[$y + $dy][$x + $dx][1];
                }
            }
            if ($near === 0) {
                continue;
            }
            $cands[] = [$x, $y, $nb, lkNoise($x, $y, SEED + 7)];
        }
    }
    $sugar = array_map('lkHex', SUGAR);
    foreach ($cands as [$x, $y, $nb, $r]) {
        if ($r < SUGAR_DENSITY) {
            $col = lkMix($sugar[(int) floor($r * 100) % count($sugar)], $nb, 0.2);
            $grid[$y][$x] = [$r < SUGAR_DENSITY * 0.45 ? '⋅' : '·', lkScale($col, 0.92), null];
        }
    }
    // sparkles: highest-noise candidates on the upper rows, kept apart
    usort($cands, static fn ($a, $b) => $b[3] <=> $a[3]);
    $placed = [];
    foreach ($cands as [$x, $y]) {
        if (count($placed) >= SPARKLES || $y > 3) {
            continue;
        }
        foreach ($placed as [$px]) {
            if (abs($px - $x) < 14) {
                continue 2;
            }
        }
        $grid[$y][$x] = ['✧', [255, 255, 255], null];
        $placed[] = [$x, $y];
    }
}

/** Nearest xterm colour from a restricted index set; returns its rgb. */
function near256(array $c, array $set): array
{
    $pal = lkXterm256();
    $best = $pal[$set[0]];
    $bd = INF;
    foreach ($set as $i) {
        $d = lkDist($c, $pal[$i]);
        if ($d < $bd) {
            $bd = $d;
            $best = $pal[$i];
        }
    }
    return $best;
}

function isBraille(string $g): bool
{
    $o = mb_ord($g, 'UTF-8');
    return $o >= 0x2800 && $o <= 0x28FF;
}

/**
 * Fill empty cells (outside a NET_GUTTER halo around the worms) with the
 * mirrored braille net graph. $shift scrolls the history (animation: data
 * streams in from the right like the live panel); $reveal 0..1 scales the
 * amplitudes so the graph can swell up during the intro.
 */
function netGraph(array &$grid, array $sc, int $x0, string $depth, int $shift, float $reveal): void
{
    static $data = null;
    $h = count($grid);
    $w = count($grid[0]);
    $n = $w * 2 + 400;
    $data ??= [
        bnTraffic($n, NET_SEED, 0.6, 0.65, 2),
        bnTraffic($n, NET_SEED + 17, 0.55, 0.45, 2),
    ];
    $off = 200 - $shift;
    $up = array_map(static fn ($v) => $v * $reveal, array_slice($data[0], $off, $w * 2));
    $down = array_map(static fn ($v) => $v * $reveal, array_slice($data[1], $off, $w * 2));
    $g = bnGraph($w, $h, NET_AXIS, $up, $down);
    $ansi16 = lkAnsi16();
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ($g[$y][$x] === null || $grid[$y][$x][0] !== ' ') {
                continue;
            }
            for ($dy = -NET_GUTTER; $dy <= NET_GUTTER; $dy++) {
                for ($dx = -NET_GUTTER; $dx <= NET_GUTTER; $dx++) {
                    if (($sc[$y + $dy][$x0 + $x + $dx] ?? null) !== null) {
                        continue 3;
                    }
                }
            }
            $cell = $g[$y][$x];
            $isDown = $cell['series'] === 'up';      // "up" area = download (btop: on top)
            if ($depth === '16') {
                // fade like the tc gradient: grey at the axis, hue above, bright crest
                $code = $cell['t'] < 0.35 ? 90 : ($isDown ? 34 : 35) + ($cell['t'] > 0.8 ? 60 : 0);
                $col = $ansi16[$code];
            } else {
                $col = lkScale(lkGradient($isDown ? NET_DOWN : NET_UP, 0.2 + 0.8 * $cell['t']), NET_DIM);
            }
            $grid[$y][$x] = [$cell['glyph'], $col, null];
        }
    }
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$png = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--png=')) {
        $png = substr($a, 6);
    }
}
$dir = dirname(__DIR__);
define('NET_ON', !in_array('--v1', $argv, true));
if (!NET_ON && $write) {
    exit("--v1 is preview-only; the v1 files are archived in archive/gummy-worm-script/v1/\n");
}
const DESCRIPTION = '"candy top" in joined-up lowercase cursive drawn by two glossy sour gummy worms, rasterised to Unicode sextant mosaics (2x3 sub-pixels/cell, needs a font with U+1FB00 Legacy Computing). Colour runs along each worm: cherry->tangerine->lemon for "candy", lime->teal->grape for "top"; upper-left gloss band, lower-right gel depth, sugar-crystal specks and sparkles. Behind them a dim mirrored braille area graph like candy-top\'s net panel: download in indigo shades growing up, upload in plum shades hanging down.';
const TAGS = ['gummy-worm', 'cursive-script', 'sextant-mosaic', 'arc-length-gradient', 'gel-gloss', 'sugar-crystals', 'lowercase', 'braille-net-graph', 'mirrored-area-graph', 'purple-shades'];
// 16-colour: hand map by worm band so the sour two-tone survives
const PINS16 = [];
const ANIM_FRAMES = 18;

[, $total] = canvas();
$static = [];
foreach (['tc', '256', '16'] as $depth) {
    $static[$depth] = lkEncode(cells(null, true, $depth), $depth, PINS16, 'nearest');
    [$w, $h] = lkVerify($static[$depth], $depth);
    echo $static[$depth];
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    if ($write) {
        $r = lkWriteLogo($dir, SLUG, $depth, $static[$depth], DESCRIPTION . " ($depth)", TAGS);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
    $frames = [];
    for ($f = 1; $f < ANIM_FRAMES; $f++) {
        // graph history streams in from the right (2 dots/frame), swelling up
        $frames[] = lkEncode(cells((int) round($total * $f / ANIM_FRAMES), false, $depth,
            2 * (ANIM_FRAMES - $f), min(1.0, 0.35 + $f / ANIM_FRAMES)), $depth, PINS16, 'nearest');
    }
    $anim = lkAnim($frames, $static[$depth]);
    if ($write) {
        $r = lkWriteLogo($dir, SLUG . '-anim', $depth, $anim,
            'Animated gummy-worm-script: the two gummy worms crawl in along their cursive path with a bright head while the purple net graph scrolls in from the right behind them, then the sugar coating lands; '
            . ANIM_FRAMES . ' frames, ~1.7s via tools/logo-play.php at 100ms, ends on the static logo with the cursor restored. (' . $depth . ')',
            [...TAGS, 'animated', 'crawl-in', 'scrolling-graph']);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
    if ($png !== null) {
        @mkdir($png, 0777, true);
        $f = "$png/$depth.ansi";
        file_put_contents($f, $static[$depth]);
        passthru('php ' . escapeshellarg(__DIR__ . '/sextant-preview.php') . ' ' . escapeshellarg($f) . ' ' . escapeshellarg("$png/$depth.png"));
    }
}
