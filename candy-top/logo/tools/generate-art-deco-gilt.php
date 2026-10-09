<?php

declare(strict_types=1);

/**
 * generate-art-deco-gilt (v4) — "CANDY TOP" as a btop-style monitor marquee.
 *
 * The letters keep v3's two treatments, smooth TTF forms at sextant (2×3)
 * resolution:
 *  - CANDY: URW Gothic Demi (geometric Deco face), each letter a different
 *    glossy candy colour with a thin gilt rim.
 *  - TOP: Nimbus Sans Narrow Bold, tall streamline metal, gold→coral→pink→
 *    violet sweep, three speed lines trailing off the P.
 * Everything else is resource-monitor art in btop's own vocabulary (colours
 * from candy-top's default theme, src/Theme/ThemeConfig.php):
 *  - the whole logo sits in a btop panel box: rounded corners, ┐¹cpu┌ ²mem
 *    ³net ⁴proc title tabs, ┐- 2000ms +┌, and ▼/▲ MiB/s footers;
 *  - between the words a mini cpu box: bright braille cpu graph plus three
 *    ■ meters (cpu / used / download gradients);
 *  - background: dim braille graphs on black, upload hanging from the top,
 *    cpu rising from the bottom, coloured by height with the theme
 *    gradients and fading to black toward the edges. A black halo keeps
 *    them off the letters.
 * Animation: the graphs scroll left as in btop and the meters fill, ending on
 * the static frame.
 *
 * Usage:
 *   php generate-art-deco-gilt.php            preview tc/256/16 (+ verify)
 *   php generate-art-deco-gilt.php --write    write .ansi files + logos.jsonl
 *
 * Reuse: fonts/boxes/palettes, theme gradients, frame tab texts and graph
 * seeds are data; letterImage() is a pure sub-pixel function; brailleArea()
 * is a generic area-graph cell renderer; compose() overlays them per cell.
 * Previous versions: archive/art-deco-gilt/v1 … v3.
 */

require_once __DIR__ . '/logo-kit.php';
require_once __DIR__ . '/ttf-coverage.php';
require_once __DIR__ . '/sextant-image-raster.php';
require_once __DIR__ . '/braille-netgraph.php';   // bnTraffic()

// ================================================================ DESIGN DATA

const SLUG = 'art-deco-gilt';
const COLS = 76;
const ROWS = 9;
const PW = COLS * 2;
const PH = ROWS * 3;

const FONT_CANDY = '/usr/share/fonts/opentype/urw-base35/URWGothic-Demi.otf';
const FONT_TOP = '/usr/share/fonts/opentype/urw-base35/NimbusSansNarrow-Bold.otf';

/** Ink boxes in sub-pixels [x0, y0, x1, y1] (interior = sub rows 3..23). */
const BOX_CANDY = [6, 4, 77, 23];
const BOX_TOP = [106, 3, 140, 24];

const CANDY_HUES = ['#FF3D9A', '#FF8C1A', '#FFE135', '#3DF28A', '#2EC8FF'];
const CANDY_RIM = '#E0A030';
const CANDY_RIM_DARK = '#7A4510';
const TOP_SWEEP = ['#FFD54A', '#FFC23A', '#FF6B6B', '#FF4FA0', '#B36BFF'];
const SPEED_LINES = [0.45, 0.62, 0.79];

/** btop default theme (candy-top ThemeConfig::DEFAULT_THEME) gradients. */
const G_CPU = ['#77ca9b', '#cbc06c', '#dc4c4c'];
const G_USED = ['#592b26', '#d9626d', '#ff4769'];
const G_DOWN = ['#291f75', '#4f43a3', '#b0a9de'];
const G_UP = ['#620665', '#7d4180', '#dcafde'];
const G_FREE = ['#384f21', '#b5e685', '#dcff85'];
const HI_FG = '#b54040';
const TITLE = '#eeeeee';
const MAIN_FG = '#cccccc';
const METER_BG = '#404040';
const BOX_LINE = '#556d59';          // cpu_box
const MINI_BOX = '#77ca9b';

/** Mini cpu box between the words: cell columns/rows (inclusive). */
const MINI = [40, 1, 51, 7];
/** Mini-box meters: [gradient, final fill 0..1]. */
const METERS = [[G_CPU, 0.70], [G_USED, 0.45], [G_FREE, 0.85]];

/** Background graphs: [first row, last row, from-top?, gradient, seed, brightness]. */
const BG_GRAPHS = [
    [1, 3, true, G_UP, 11, 0.85],
    [4, 7, false, G_CPU, 7, 0.70],
];
const EDGE_FADE = 7.0;              // cells over which graphs fade to black at the sides
const HALO = 1.0;                    // sub-pixels of black kept around letters

const TOP_LEFT = [['╭─┐', 'line'], ['¹', 'hi'], ['cpu', 'title'], ['┌─┐', 'line'], ['²', 'hi'], ['mem', 'title'], ['┌─┐', 'line'], ['³', 'hi'], ['net', 'title'], ['┌─┐', 'line'], ['⁴', 'hi'], ['proc', 'title'], ['┌', 'line']];
const TOP_RIGHT = [['┐', 'line'], ['-', 'hi'], [' 2000ms ', 'title'], ['+', 'hi'], ['┌─╮', 'line']];
const BOT_LEFT = [['╰─┘', 'line'], ['▼', 'down'], [' 48.2 MiB/s', 'fg'], ['└', 'line']];
const BOT_RIGHT = [['┘', 'line'], ['▲', 'up'], [' 3.1 MiB/s', 'fg'], ['└─╯', 'line']];

const ANIM_FRAMES = 18;

const DESCRIPTION = 'CANDY TOP as a btop-style resource-monitor marquee on black: smooth TTF letters at sextant resolution, CANDY in geometric Deco URW Gothic with each letter a different glossy candy colour and a gilt rim, TOP in tall narrow streamline metal with an iridescent gold-coral-pink-violet sweep and speed lines; framed by a btop panel box (¹cpu ²mem ³net ⁴proc tabs, - 2000ms +, ▼/▲ MiB/s); a mini cpu box between the words with a braille cpu graph and three ■ gradient meters; dim braille upload/cpu area graphs in btop theme gradients behind, fading to black at the edges.';
const TAGS = ['art-deco', 'btop-style', 'resource-monitor', 'braille-graphs', 'gradient-meters', 'black-background', 'ttf-sextant-letters', 'glossy-candy-letters', 'streamline-metal', 'two-word-treatments'];

// ================================================================ LETTERS

function geo(): array
{
    static $g = null;
    if ($g !== null) {
        return $g;
    }
    $candy = tcRender(FONT_CANDY, 'CANDY', 2, 3, PW, PH, BOX_CANDY, ['maxCondense' => 0.72, 'tracking' => 34.0, 'ss' => 4]);
    $top = tcRender(FONT_TOP, 'TOP', 2, 3, PW, PH, BOX_TOP, ['maxCondense' => 0.6, 'tracking' => 14.0, 'ss' => 4]);
    $rim = tcDilate($candy['cov'], 1.6, 1.5);
    // occupancy: letters (+rim, speed lines) grown by HALO → cells that render as sextants
    $ink = [];
    for ($y = 0; $y < PH; $y++) {
        for ($x = 0; $x < PW; $x++) {
            $ink[$y][$x] = max($rim[$y][$x], $top['cov'][$y][$x] >= 0.3 ? 1.0 : 0.0);
        }
    }
    $g = ['candy' => $candy, 'top' => $top, 'rim' => $rim];
    $g['speed'] = speedRowsFor($top);
    foreach ($g['speed'] as $i => $ly) {
        $x0 = speedStartFor($top, $ly);
        for ($x = $x0 + 2; $x < min(PW - 3, $x0 + (int) ((3.0 + 1.5 * $i) * 2)); $x++) {
            $ink[$ly][$x] = 1.0;
        }
    }
    $g['occ'] = tcDilate($ink, HALO, 1.5);
    return $g;
}

function speedRowsFor(array $top): array
{
    $b = $top['box'];
    return array_map(static fn (float $v): int => (int) round($b[1] + $v * ($b[3] - $b[1])), SPEED_LINES);
}

function speedStartFor(array $top, int $y): int
{
    $last = (int) BOX_TOP[0];
    foreach ($top['cov'][$y] as $x => $c) {
        if ($c > 0.3) {
            $last = $x;
        }
    }
    return $last;
}

/** Letters-only sub-pixel colour (black elsewhere). */
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
        if ($v > 0.12 && $v < 0.26) {
            $c = lkMix($c, [255, 255, 255], 0.25);
        }
        $c = lkShade($c, -0.30 * max(0.0, $v - 0.45) / 0.55);
        return lkMix($base, $c, min(1.0, $cc * 1.15));
    }
    $tc = $g['top']['cov'][$y][$x];
    if ($tc > 0.02) {
        $u = $g['top']['u'][$y][$x];
        $v = $g['top']['v'][$y][$x];
        $c = lkGradient(TOP_SWEEP, max(0.0, min(1.0, $u)));
        $c = match (true) {
            $v < 0.08 => lkMix($c, [255, 255, 255], 0.35),
            $v < 0.45 => lkMix($c, [255, 255, 255], 0.15 * (0.45 - $v) / 0.37),
            $v < 0.55 => lkShade($c, -0.12),
            default => lkShade($c, -0.15 * ($v - 0.55) / 0.45),
        };
        return lkMix([0, 0, 0], $c, min(1.0, $tc * 1.15));
    }
    foreach ($g['speed'] as $i => $ly) {
        if ($y !== $ly) {
            continue;
        }
        $x0 = speedStartFor($g['top'], $ly);
        $len = (3.0 + 1.5 * $i) * 2;
        if ($x > $x0 + 1 && $x - $x0 < $len && $x < PW - 3) {
            return lkScale(lkGradient(TOP_SWEEP, 0.7 + 0.1 * $i), 1 - ($x - $x0) / $len);
        }
    }
    return [0, 0, 0];
}

function letterImage(): array
{
    $img = [];
    for ($y = 0; $y < PH; $y++) {
        for ($x = 0; $x < PW; $x++) {
            $img[$y][$x] = letterPx($x, $y);
        }
    }
    return $img;
}

/** Cell is reserved for letters if any of its 2×3 sub-pixels is in the halo. */
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

// ================================================================ GRAPHS

const BRAILLE_BITS = [[0x01, 0x08], [0x02, 0x10], [0x04, 0x20], [0x40, 0x80]];

/**
 * One braille area-graph cell. $levels = two dot-column heights (in dots)
 * measured from the graph's anchor edge; $offset = dots between the anchor and
 * this cell's near edge. Returns [glyph, t] (t = 0..1 height of the cell's
 * farthest lit dot for gradient colouring) or null when empty.
 */
function brailleArea(array $levels, int $offset, bool $fromTop, int $totalDots): ?array
{
    $bits = 0;
    $far = -1;
    for ($k = 0; $k < 4; $k++) {
        $lvl = $fromTop ? $offset + $k : $offset + (3 - $k);
        foreach ([0, 1] as $c) {
            if ($lvl < $levels[$c]) {
                $bits |= BRAILLE_BITS[$k][$c];
                $far = max($far, $lvl);
            }
        }
    }
    return $bits === 0 ? null : [mb_chr(0x2800 + $bits), ($far + 1) / $totalDots];
}

/** Seeded series long enough for every animation frame. */
function series(int $seed, int $n, float $base = 0.45, float $burst = 0.55, int $smooth = 2): array
{
    static $memo = [];
    return $memo["$seed/$n/$base"] ??= bnTraffic($n, $seed, $base, $burst, $smooth);
}

/** Edge fade 0..1 for a cell column (graphs sink to black at the sides). */
function edgeFade(int $cx): float
{
    $d = min($cx, COLS - 1 - $cx);
    return max(0.0, min(1.0, ($d - 1) / EDGE_FADE)) ** 1.3;
}

// ================================================================ COMPOSE

/** A styled run list → [col => [glyph, rgb]]. */
function runs(array $parts, int $x0, ?float $fill = null): array
{
    $role = [
        'line' => lkHex(BOX_LINE), 'hi' => lkHex(HI_FG), 'title' => lkHex(TITLE),
        'fg' => lkHex(MAIN_FG), 'down' => lkHex(G_DOWN[2]), 'up' => lkHex(G_UP[2]),
    ];
    $out = [];
    $x = $x0;
    foreach ($parts as [$text, $r]) {
        foreach (mb_str_split($text) as $g) {
            $out[$x++] = [$g, $role[$r]];
        }
    }
    return $out;
}

function runWidth(array $parts): int
{
    return array_sum(array_map(static fn (array $p): int => mb_strlen($p[0]), $parts));
}

/**
 * Build the full cell grid for a depth at animation step $step (0 = first
 * frame … ANIM_FRAMES-1 = the static logo).
 * @return array{0: list<list<array>>, 1: array<string,int>} [cells, 16-colour pins]
 */
function compose(string $depth, int $step): array
{
    static $letterCells = [];
    if (!isset($letterCells[$depth])) {
        $img = letterImage();
        if ($depth !== 'tc') {
            $a16 = lkAnsi16();
            foreach ($img as $y => $row) {
                foreach ($row as $x => $c) {
                    $img[$y][$x] = $depth === '256' ? adTo256($c) : $a16[adCode16($c)];
                }
            }
        }
        $letterCells[$depth] = siCells($img, $depth !== 'tc');
    }
    $cells = $letterCells[$depth];
    $pins = [];
    $put = static function (int $x, int $y, string $g, array $rgb, int $code16) use (&$cells, &$pins): void {
        $cells[$y][$x] = [$g, $rgb, [0, 0, 0]];
        $pins[implode(',', $rgb)] = $code16;
    };
    $shift = (ANIM_FRAMES - 1 - $step) * 2;           // dot columns not yet scrolled in
    $n = COLS * 2 + ANIM_FRAMES * 2 + 4;

    // background graphs on every free interior cell
    for ($cy = 1; $cy < ROWS - 1; $cy++) {
        for ($cx = 1; $cx < COLS - 1; $cx++) {
            $inMini = $cx >= MINI[0] && $cx <= MINI[2] && $cy >= MINI[1] && $cy <= MINI[3];
            if ($inMini || occupied($cx, $cy)) {
                continue;
            }
            $cells[$cy][$cx] = [" ", null, [0, 0, 0]];
            foreach (BG_GRAPHS as [$r0, $r1, $fromTop, $grad, $seed, $bright]) {
                if ($cy < $r0 || $cy > $r1) {
                    continue;
                }
                $s = series($seed, $n);
                $total = ($r1 - $r0 + 1) * 4;
                $lv = [];
                foreach ([0, 1] as $c) {
                    $i = $cx * 2 + $c + (ANIM_FRAMES * 2) - $shift;
                    $lv[] = (int) round(($s[$i] ?? 0) * $total);
                }
                $off = $fromTop ? ($cy - $r0) * 4 : ($r1 - $cy) * 4;
                $hit = brailleArea($lv, $off, $fromTop, $total);
                if ($hit !== null) {
                    [$glyph, $t] = $hit;
                    $full = lkGradient($grad, $t);
                    $rgb = lkScale($full, $bright * edgeFade($cx));
                    $put($cx, $cy, $glyph, $rgb, max($rgb) < 40 ? 30 : graphCode16($full, $fromTop));
                }
            }
        }
    }

    // mini cpu box
    [$bx0, $by0, $bx1, $by1] = MINI;
    $line = lkHex(MINI_BOX);
    for ($x = $bx0; $x <= $bx1; $x++) {
        $put($x, $by0, '─', $line, 32);
        $put($x, $by1, '─', $line, 32);
    }
    for ($y = $by0; $y <= $by1; $y++) {
        $put($bx0, $y, '│', $line, 32);
        $put($bx1, $y, '│', $line, 32);
    }
    $put($bx0, $by0, '╭', $line, 32);
    $put($bx1, $by0, '╮', $line, 32);
    $put($bx0, $by1, '╰', $line, 32);
    $put($bx1, $by1, '╯', $line, 32);
    foreach (runs([['┐', 'line'], ['¹', 'hi'], ['cpu', 'title'], ['┌', 'line']], $bx0 + 2) as $x => [$g, $rgb]) {
        $put($x, $by0, $g, $g === '┐' || $g === '┌' ? $line : $rgb, $rgb === lkHex(HI_FG) ? 91 : ($g === '┐' || $g === '┌' ? 32 : 97));
    }
    $iw = $bx1 - $bx0 - 1;
    // bright braille cpu graph, 2 rows
    $s = series(23, $n, 0.22, 0.8, 1);
    for ($cy = $by0 + 1; $cy <= $by0 + 2; $cy++) {
        for ($i = 0; $i < $iw; $i++) {
            $cx = $bx0 + 1 + $i;
            $lv = [];
            foreach ([0, 1] as $c) {
                $lv[] = (int) round(($s[$i * 2 + $c + ANIM_FRAMES * 2 - $shift] ?? 0) * 8);
            }
            $hit = brailleArea($lv, ($by0 + 2 - $cy) * 4, false, 8);
            if ($hit === null) {
                $cells[$cy][$cx] = [" ", null, [0, 0, 0]];
                continue;
            }
            $rgb = lkGradient(G_CPU, $hit[1]);
            $put($cx, $cy, $hit[0], $rgb, graphCode16($rgb, false) + 60);
        }
    }
    // ■ meters, filling over the animation
    $grow = min(1.0, ($step + 1) / (ANIM_FRAMES * 0.7));
    foreach (METERS as $m => [$grad, $fill]) {
        $cy = $by0 + 3 + $m;
        $lit = (int) round($fill * $grow * $iw);
        for ($i = 0; $i < $iw; $i++) {
            $cx = $bx0 + 1 + $i;
            if ($i < $lit) {
                $rgb = lkGradient([$grad[1], $grad[2]], $i / max(1, $iw - 1));
                $put($cx, $cy, '■', $rgb, adCode16($rgb) < 90 ? adCode16($rgb) + 60 : adCode16($rgb));
            } else {
                $put($cx, $cy, '■', lkHex(METER_BG), 90);
            }
        }
    }

    // outer btop panel frame
    $codes = static fn (array $rgb): int => match (true) {
        $rgb === lkHex(BOX_LINE) => 32,
        $rgb === lkHex(HI_FG) => 91,
        $rgb === lkHex(G_DOWN[2]) => 94,
        $rgb === lkHex(G_UP[2]) => 95,
        $rgb === lkHex(MAIN_FG) => 37,
        default => 97,
    };
    for ($x = 0; $x < COLS; $x++) {
        $put($x, 0, '─', lkHex(BOX_LINE), 32);
        $put($x, ROWS - 1, '─', lkHex(BOX_LINE), 32);
    }
    for ($y = 1; $y < ROWS - 1; $y++) {
        $put(0, $y, '│', lkHex(BOX_LINE), 32);
        $put(COLS - 1, $y, '│', lkHex(BOX_LINE), 32);
    }
    foreach ([[TOP_LEFT, 0, 0], [TOP_RIGHT, COLS - runWidth(TOP_RIGHT), 0], [BOT_LEFT, 0, ROWS - 1], [BOT_RIGHT, COLS - runWidth(BOT_RIGHT), ROWS - 1]] as [$parts, $x0, $y]) {
        foreach (runs($parts, $x0) as $x => [$g, $rgb]) {
            $put($x, $y, $g, $rgb, $codes($rgb));
        }
    }
    return [$cells, $pins];
}

/** Dim graph colour → 16-colour code by its (undimmed) theme hue. */
function graphCode16(array $full, bool $upload): int
{
    if ($upload) {
        return 35;
    }
    $c = adCode16($full);
    $c = $c >= 90 ? $c - 60 : $c;
    return in_array($c, [30, 37], true) ? 32 : $c;     // low-sat graph tones stay green, never white specks
}

// ================================================================ DEPTH

/** @return array{0: float, 1: float} [hue°, saturation] */
function adHueSat(array $c): array
{
    [$r, $g, $b] = array_map(static fn (int $v): float => $v / 255, $c);
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $d = $max - $min;
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

/**
 * Hue-constrained nearest xterm-256: bright saturated colours only match cube
 * entries within 15° of their hue (plain nearest turned golds olive/pink).
 */
function adTo256(array $c): array
{
    static $memo = [];
    $key = implode(',', $c);
    if (isset($memo[$key])) {
        return $memo[$key];
    }
    [$h, $s] = adHueSat($c);
    $best = null;
    $bd = INF;
    foreach (lkXterm256() as $i => $p) {
        if ($i < 16) {
            continue;
        }
        if ($s > 0.2 && max($c) > 150) {
            [$ph, $ps] = adHueSat($p);
            $dh = abs($ph - $h);
            $dh = min($dh, 360 - $dh);
            if ($ps < 0.15 || $dh > 15) {
                continue;
            }
        }
        $d = lkDist($c, $p);
        if ($d < $bd) {
            $bd = $d;
            $best = $p;
        }
    }
    return $memo[$key] = $best ?? lkXterm256()[lkTo256($c)];
}

/** Hue bands → 16-colour base: orange (A, coral O) → red, gold (T) → yellow, pinks → magenta. */
const BANDS16 = [[34, 31], [75, 33], [165, 32], [205, 36], [255, 34], [300, 35], [345, 35], [360, 31]];

function adCode16(array $c): int
{
    [$h, $s] = adHueSat($c);
    $max = max($c) / 255;
    if ($max < 0.30) {
        return 30;                      // black bg + faint rays
    }
    if ($s < 0.30) {
        return $max > 0.75 ? 97 : ($max > 0.5 ? 37 : 90);
    }
    if ($h >= 15 && $h < 50 && $max < 0.8) {
        return 33;                      // gilt rims / bronze → dark yellow, never red
    }
    foreach (BANDS16 as [$lim, $code]) {
        if ($h < $lim) {
            return $max > 0.62 ? $code + 60 : $code;
        }
    }
    return 35;
}


// ================================================================ CLI

function encodeStep(string $depth, int $step): string
{
    [$cells, $pins] = compose($depth, $step);
    $pins['0,0,0'] = 30;
    return lkEncode($cells, $depth, $depth === '16' ? $pins : [], 'nearest');
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
        $r = lkWriteLogo($dir, SLUG, $depth, $static, DESCRIPTION . " ($depth)", TAGS);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        $fs = [];
        for ($f = 0; $f < ANIM_FRAMES - 1; $f++) {
            $fs[] = encodeStep($depth, $f);
        }
        $anim = lkAnim($fs, $static);
        $r = lkWriteLogo($dir, SLUG . '-anim', $depth, $anim, 'Animated ' . DESCRIPTION . ' The braille graphs scroll left like btop and the ■ meters fill (' . ANIM_FRAMES . " frames), ending on the static logo; play with tools/logo-play.php <file> 80 (~1.5 s). ($depth)", [...TAGS, 'scrolling-graphs', 'animated']);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
}
