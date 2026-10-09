<?php

declare(strict_types=1);

/**
 * generate-peppermint-barberpole-v2 — CANDY as peppermint sticks, TOP as tall
 * blueberry-gel letters, over a live system-monitor of background graphs.
 *
 * Layers (back → front), all on one cell grid:
 *   1. braille area chart (dotted fill + bright crest) across the whole logo
 *   2. braille line graph (second series) in the upper band
 *   3. eighth-block bar chart / histogram along the floor under CANDY
 *   4. CANDY — v1's stick font: auto-tiled rounded box-drawing outline
 *      (mint) + diagonal red/cream barber-pole stripes with ◤ wedge edges
 *   5. TOP — condensed rounded geometric face drawn as signed-distance shapes,
 *      rasterised at 2x2 per cell with quadrant blocks; cyan→blue→violet→
 *      magenta gel gradient, dark rim, specular streak; the O is a DONUT
 *      PIE CHART with four coloured sectors.
 * Graphs only show in cells the lettering leaves empty, so the words read.
 *
 * Every cell may carry a 4th element: its ROLE, used by the 16-colour map
 * (role → fixed code) so dim graph colours don't collapse into the letters.
 *
 * Usage:
 *   php generate-peppermint-barberpole-v2.php            preview + verify
 *   php generate-peppermint-barberpole-v2.php --write    write .ansi + logos.jsonl
 *   php generate-peppermint-barberpole-v2.php --frame=N  preview one anim frame
 */

require __DIR__ . '/logo-kit.php';
require __DIR__ . '/braille-canvas.php';
require __DIR__ . '/quadrant-raster.php';

// ================================================================ DESIGN DATA

const SLUG = 'peppermint-barberpole';
const TEXT = 'CANDY';                 // the peppermint word (stick font)
const W = 78;
const H = 11;
const CANDY_AT = [0, 2];              // cell offset of the CANDY grid (rows 0-1: line graph band)
const TOP_AT = 50;                    // first column of the TOP box
const TOP_W = 28;                     // TOP box width in cols (height = H)

/**
 * 7-row stick-candy font: rows 0 and 6 are the outline rows, rows 1-5 the body.
 *   '#' body · 'a' ◣ 'b' ◥ 'c' ◤ 'd' ◢ half-body wedges
 *   '.' auto outline · ' ' forced blank · overrides '\' ╲ '/' ╱ '-' ─ '|' │
 */
const FONT = [
    'C' => ['.......', '#######', '##.....', '##.....', '##.....', '#######', '.......'],
    'A' => ['.......', '#######', '##...##', '##...##', '#######', '##...##', '.......'],
    'N' => ['...-.....', '###a\ .##', '##b#a\|##', '##\b#a\##', '##|\b#a##', '##| \b###', '.....-...'],
    'D' => ['.......', '######.', '##...##', '##...##', '##...##', '######.', '.......'],
    'Y' => ['.......', '##...##', '##...##', '#######', '..###..', '..###..', '.......'],
];
const WEDGES = ['a' => '◣', 'b' => '◥', 'c' => '◤', 'd' => '◢'];
const WEDGE_EDGES = ['a' => 'DL', 'b' => 'UR', 'c' => 'UL', 'd' => 'DR'];
const OVERRIDES = ['\\' => ['╲', ''], '/' => ['╱', ''], '-' => ['─', 'LR'], '|' => ['│', 'UD']];
const LETTER_GAP = 2;
const WORD_GAP = 4;

const PAL = [
    'redL' => '#FF2E4D', 'redR' => '#C80F3C',
    'creamT' => '#FFF6EC', 'creamB' => '#F4E2D4',
    'mintT' => '#5CF2C4', 'mintB' => '#14A37F',
    // TOP gel, top → bottom, and its rim / gloss
    'gel' => ['#5FF4FF', '#3FA0FF', '#6A5CFF', '#B04DFF', '#FF4FD0'],
    'rim' => '#1B1240',
    // O = donut pie chart sectors [fromDeg, toDeg, colour] (0 = 12 o'clock, clockwise)
    'pie' => [[3, 150, '#46E8FF'], [156, 236, '#B8FF5C'], [242, 357, '#FF4FD0']],
    // background graphs (deliberately dim)
    'areaFill' => '#232C5E', 'areaCrest' => '#4A5FC0',
    'line' => '#8A6428', 'lineHot' => '#C7903A',
    'bars' => ['#2E8A52', '#C08A2E', '#B04040'],
    'grid' => '#2A2440',
    'sparkle' => '#FFC8DA',
];
const BAND = 4;
const GLOSS = 0.30;
const BASE_DARK = ['red' => 0.18, 'cream' => 0.04];
const SPARKLES = [[48, 1, '✦']];

/** role → 16-colour fg code (bg = +10). Colours without a role use lkTo16. */
const ROLE16 = [
    'red' => 91, 'redDark' => 31, 'cream' => 97, 'mint' => 92, 'mintDark' => 32,
    'area' => 90, 'crest' => 34, 'line' => 33, 'bar0' => 32, 'bar1' => 33, 'bar2' => 31,
    'grid' => 90, 'sparkle' => 95, 'rim' => 34,
    'gel0' => 96, 'gel1' => 94, 'gel2' => 34, 'gel3' => 95, 'gel4' => 35,
    'pie0' => 96, 'pie1' => 92, 'pie2' => 95,
];

const DESCRIPTION = 'CANDY as peppermint sticks (diagonal red/cream barber-pole stripes with ◤ wedge edges, auto-tiled mint box-drawing outline) beside a tall condensed TOP in cyan→violet→magenta gel drawn with quadrant blocks, whose O is a four-sector donut pie chart; behind it a dim live system monitor: braille area chart, braille line graph and an eighth-block histogram floor.';
const TAGS = ['peppermint-barberpole', 'candy-cane', 'diagonal-wedge-stripes', 'box-drawing-outline', 'quadrant-blocks', 'gel-gradient', 'pie-chart-letter', 'braille-graphs', 'bar-chart', 'system-monitor-background', 'two-typefaces'];

const ANIM_FRAMES = 22;
const ANIM_DELAY_MS = 85;            // 22 + final ≈ 2.0 s

// ================================================================ GEOMETRY

/**
 * @return array{0: list<list<string>>, 1: int, 2: int, 3: list<array{int,int}>}
 *         [code grid, w, h, letter boxes [x0,x1) incl. outline cols];
 *         codes: '#', wedge letters, override chars, ' ' blank, '' auto
 */
function codeGrid(): array
{
    [$mask, $ranges] = lkLayout(FONT, TEXT, LETTER_GAP, WORD_GAP, 0);
    $w = strlen($mask[0]) + 3;          // outline col each side + shadow col
    $h = count($mask) + 1;              // + shadow row
    $g = array_fill(0, $h, array_fill(0, $w, ''));
    foreach ($mask as $y => $row) {
        for ($x = 0; $x < strlen($row); $x++) {
            $c = $row[$x];
            $g[$y][$x + 1] = $c === '.' ? '' : $c;
        }
    }
    $boxes = array_map(static fn (array $r): array => [$r[0], $r[1] + 2], $ranges);
    return [$g, $w, $h, $boxes];
}

function isBody(string $c): bool
{
    return $c === '#' || isset(WEDGES[$c]);
}

/**
 * Does the body cell at (x+dx, y+dy) touch the cell at (x, y)? Wedges only
 * touch across the edges they fill completely.
 */
function touches(array $g, int $x, int $y, int $dx, int $dy): bool
{
    $c = $g[$y + $dy][$x + $dx] ?? '';
    if (!isBody($c)) {
        return false;
    }
    if ($c === '#') {
        return true;
    }
    // direction from the wedge back toward (x,y)
    $need = ($dy > 0 ? 'U' : ($dy < 0 ? 'D' : '')) . ($dx > 0 ? 'L' : ($dx < 0 ? 'R' : ''));
    foreach (str_split($need) as $e) {
        if (!str_contains(WEDGE_EDGES[$c], $e)) {
            return false;
        }
    }
    return true;
}

/** Body cells touching (x,y), as "x,y" keys. */
function bodyNbrs(array $g, int $x, int $y): array
{
    $out = [];
    for ($dy = -1; $dy <= 1; $dy++) {
        for ($dx = -1; $dx <= 1; $dx++) {
            if (($dx || $dy) && touches($g, $x, $y, $dx, $dy)) {
                $out[($x + $dx) . ',' . ($y + $dy)] = true;
            }
        }
    }
    return $out;
}

/** Auto cells touching the body. */
function outlineRing(array $g, int $w, int $h): array
{
    $ring = array_fill(0, $h, array_fill(0, $w, false));
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $ring[$y][$x] = $g[$y][$x] === '' && bodyNbrs($g, $x, $y) !== [];
        }
    }
    return $ring;
}

const TILES = [
    '' => '·', 'U' => '╵', 'D' => '╷', 'L' => '╴', 'R' => '╶',
    'UD' => '│', 'LR' => '─', 'DR' => '╭', 'DL' => '╮', 'UR' => '╰', 'UL' => '╯',
    'UDR' => '├', 'UDL' => '┤', 'DLR' => '┬', 'ULR' => '┴', 'UDLR' => '┼',
];
const OPPOSITE = ['U' => 'D', 'D' => 'U', 'L' => 'R', 'R' => 'L'];

/**
 * Outline glyph per cell ("x,y" => glyph). Two auto cells link when they
 * touch a common body cell (so neighbouring letters' contours never fuse);
 * an auto cell links to an override when the override has a stub facing it.
 */
function autotile(array $g, array $ring, int $w, int $h): array
{
    $out = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $code = $g[$y][$x];
            if (isset(OVERRIDES[$code])) {
                $out["$x,$y"] = OVERRIDES[$code][0];
                continue;
            }
            if (!$ring[$y][$x]) {
                continue;
            }
            $mine = bodyNbrs($g, $x, $y);
            $k = '';
            foreach (['U' => [0, -1], 'D' => [0, 1], 'L' => [-1, 0], 'R' => [1, 0]] as $d => [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                $nc = $g[$ny][$nx] ?? '';
                if (isset(OVERRIDES[$nc])) {
                    if (str_contains(OVERRIDES[$nc][1], OPPOSITE[$d])) {
                        $k .= $d;
                    }
                } elseif (($ring[$ny][$nx] ?? false) && array_intersect_key($mine, bodyNbrs($g, $nx, $ny)) !== []) {
                    $k .= $d;
                }
            }
            $out["$x,$y"] = TILES[$k];
        }
    }
    return $out;
}

// ================================================================ CANDY (stick font)

function stripeColour(bool $red, int $x, int $y, int $w, int $top, int $bot): array
{
    $ty = ($y - $top) / max(1, $bot - $top);
    return $red
        ? lkScale(lkGradient([PAL['redL'], PAL['redR']], $x / ($w - 1)), 1 - 0.18 * $ty)
        : lkGradient([PAL['creamT'], PAL['creamB']], $ty);
}

/**
 * CANDY layer: [glyph, fg, bg, role] cells, null where empty.
 * @return list<list<?array>>
 */
function candyCells(int $phase): array
{
    [$g, $w, $h] = codeGrid();
    $ring = outlineRing($g, $w, $h);
    $tiles = autotile($g, $ring, $w, $h);
    $out = array_fill(0, $h, array_fill(0, $w, null));
    $top = 1;
    $bot = $h - 3;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $code = $g[$y][$x];
            if (isBody($code)) {
                $tweak = static function (array $c, float $dark) use ($g, $x, $y): array {
                    if (!isBody($g[$y - 1][$x] ?? '')) {
                        return lkMix($c, [255, 255, 255], GLOSS);
                    }
                    return isBody($g[$y + 1][$x] ?? '') ? $c : lkScale($c, 1 - $dark);
                };
                $red = $tweak(stripeColour(true, $x, $y, $w, $top, $bot), BASE_DARK['red']);
                $cream = $tweak(stripeColour(false, $x, $y, $w, $top, $bot), BASE_DARK['cream']);
                $s = (($x + $y + $phase) % BAND + BAND) % BAND;
                if ($code !== '#') {
                    $out[$y][$x] = [WEDGES[$code], $s <= 1 ? $red : $cream, null, $s <= 1 ? 'red' : 'cream'];
                    continue;
                }
                $out[$y][$x] = match ($s) {
                    0 => [' ', null, $red, 'red'],
                    1 => ['◤', $red, $cream, 'red/cream'],
                    2 => [' ', null, $cream, 'cream'],
                    3 => ['◤', $cream, $red, 'cream/red'],
                };
            } elseif (isset($tiles["$x,$y"])) {
                $t = $y / ($h - 2);
                $out[$y][$x] = [$tiles["$x,$y"], lkGradient([PAL['mintT'], PAL['mintB']], $t), null, $t < 0.5 ? 'mint' : 'mintDark'];
            }
        }
    }
    return $out;
}

// ================================================================ TOP (gel + pie)

function sdRoundRect(float $px, float $py, float $x0, float $y0, float $x1, float $y1, float $r): float
{
    $cx = ($x0 + $x1) / 2;
    $cy = ($y0 + $y1) / 2;
    $qx = abs($px - $cx) - (($x1 - $x0) / 2 - $r);
    $qy = abs($py - $cy) - (($y1 - $y0) / 2 - $r);
    return hypot(max($qx, 0), max($qy, 0)) + min(max($qx, $qy), 0) - $r;
}

function sdEllipse(float $px, float $py, float $cx, float $cy, float $rx, float $ry): float
{
    // first-order distance: implicit value over its gradient length
    $u = ($px - $cx) / $rx;
    $v = ($py - $cy) / $ry;
    $k = hypot($u, $v);
    if ($k < 1e-6) {
        return -min($rx, $ry);
    }
    $grad = hypot($u / $rx, $v / $ry) / $k;
    return ($k - 1) / $grad;
}

/** TOP letter geometry in units (x = cols, y = rows*2), box TOP_W x 2H. */
const O_CENTRE = [14.0, 10.0];
function topSdf(float $x, float $y): array
{
    [$ox, $oy] = O_CENTRE;
    $t = min(sdRoundRect($x, $y, 0, 0.6, 8, 3.6, 1.4), sdRoundRect($x, $y, 2.6, 0.6, 5.4, 19.4, 1.4));
    $o = max(sdEllipse($x, $y, $ox, $oy, 4.7, 9.4), -sdEllipse($x, $y, $ox, $oy, 2.0, 6.4));
    $bowl = max(sdRoundRect($x, $y, 20, 0.6, 28, 11.8, 4.0), -sdRoundRect($x, $y, 22.8, 3.6, 25.2, 8.8, 1.2));
    $p = min(sdRoundRect($x, $y, 20, 0.6, 22.8, 19.4, 1.4), $bowl);
    return [min($t, $p), $o];
}

/** Clockwise angle from 12 o'clock around the O, 0..360. */
function pieAngle(float $x, float $y): float
{
    [$ox, $oy] = O_CENTRE;
    $a = rad2deg(atan2($x - $ox, -($y - $oy) * 4.6 / 9.4));
    return $a < 0 ? $a + 360 : $a;
}

/**
 * TOP layer, rasterised with quadrant blocks.
 * @param float $pie 0..1 how much of the donut pie has been drawn
 * @return list<list<?array>>
 */
function topCells(float $pie = 1.0): array
{
    $px = [];
    $pw = TOP_W * 2;
    $ph = H * 2;
    for ($j = 0; $j < $ph; $j++) {
        for ($i = 0; $i < $pw; $i++) {
            $x = ($i + 0.5) / 2;
            $y = ($j + 0.5) * 20 / $ph;          // geometry is authored for a 20-unit box
            [$dl, $do] = topSdf($x, $y);
            $px[$j][$i] = null;
            $d = min($dl, $do);
            if ($d >= 0) {
                continue;
            }
            if ($do < $dl) {
                $a = pieAngle($x, $y);
                $c = null;
                foreach (PAL['pie'] as [$a0, $a1, $hex]) {
                    if ($a >= $a0 && $a <= $a1 && $a <= 360 * $pie) {
                        $c = lkHex($hex);
                    }
                }
                if ($c === null) {
                    continue;
                }
                $c = lkScale($c, 1.0 - 0.10 * $y / 20);
            } else {
                $c = lkGradient(PAL['gel'], $y / 20);
            }
            // normal from the distance field → gel lighting
            $e = 0.3;
            $nx = min(...topSdf($x + $e, $y)) - min(...topSdf($x - $e, $y));
            $ny = min(...topSdf($x, $y + $e)) - min(...topSdf($x, $y - $e));
            $len = hypot($nx, $ny) ?: 1.0;
            $lit = -($nx * -0.55 + $ny * -0.83) / $len;          // >0 faces up-left light
            if ($d > -0.55) {
                $c = lkMix($c, lkHex(PAL['rim']), 0.55);           // dark rim
            } elseif ($d > -1.5 && $lit < -0.35 && $do >= $dl) {
                $c = lkMix($c, [255, 255, 255], 0.45);            // specular inner edge
            } elseif ($d > -1.5 && $lit > 0.35 && $do >= $dl) {
                $c = lkScale($c, 0.72);                            // shaded inner edge
            }
            $px[$j][$i] = $c;
        }
    }
    $cells = qrRaster($px);
    $out = [];
    foreach ($cells as $y => $row) {
        foreach ($row as $x => $cell) {
            $out[$y][$x] = ($cell[0] === ' ' && $cell[2] === null) ? null : $cell;
        }
    }
    return $out;
}

// ================================================================ BACKGROUND GRAPHS

function seriesArea(float $x): float
{
    return 0.45 + 0.22 * sin($x * 0.16) + 0.14 * sin($x * 0.41 + 1.3) + 0.08 * sin($x * 1.07 + 0.4);
}

function seriesLine(float $x): float
{
    return 0.5 + 0.28 * sin($x * 0.23 + 2.1) + 0.12 * sin($x * 0.71 + 0.2) + 0.06 * sin($x * 1.9);
}

function seriesBar(int $i, float $t): float
{
    return 0.5 + 0.3 * sin($i * 0.55 + $t * 0.6) + 0.18 * sin($i * 1.31 + 2 + $t * 0.9);
}

/**
 * Braille area chart + line graph + dotted grid, then the histogram floor.
 * @return list<list<?array>>
 */
function graphCells(float $t): array
{
    $c = bcNew(W, H);
    $seq = 0;
    $dw = W * 2;
    $dh = H * 4;
    $prev = null;
    for ($X = 0; $X < $dw; $X++) {
        $v = max(0.05, min(1.0, seriesArea($X / 2 + $t)));
        $crest = (int) round($dh - 1 - $v * ($dh - 12));
        // crest: connect vertically to the previous column so it reads as a line
        $from = $prev === null ? $crest : min($crest, $prev);
        $to = $prev === null ? $crest : max($crest, $prev);
        for ($Y = $from; $Y <= $to; $Y++) {
            bcSet($c, $X, $Y, 2, $seq++);
        }
        $prev = $crest;
        for ($Y = $crest + 2; $Y < $dh; $Y++) {
            if (($X + $Y) % 2 === 0 && $Y % 2 === 0) {
                bcSet($c, $X, $Y, 1, $seq++);
            }
        }
    }
    $pts = [];
    for ($X = 0; $X < $dw; $X++) {
        $pts[] = [$X, 0.5 + (1 - seriesLine($X / 2 + 1.7 * $t)) * 6.5];
    }
    $c2 = $c;
    bcPolyline($c, $pts, 3, $seq, 1);
    foreach ($c['dots'] as $Y => $row) {          // the line draws over the area
        foreach ($row as $X => $d) {
            if ($d[0] === 3) {
                $c['dots'][$Y][$X] = $d;
            }
        }
    }
    unset($c2);
    for ($Y = 4; $Y < $dh; $Y += 12) {             // faint grid
        for ($X = 0; $X < $dw; $X += 4) {
            bcSet($c, $X, $Y, 4, $seq++);
        }
    }
    $out = array_fill(0, H, array_fill(0, W, null));
    foreach (bcCells($c) as $y => $row) {
        foreach ($row as $x => [$ch, $n, $layers]) {
            if ($n === 0) {
                continue;
            }
            $out[$y][$x] = match (true) {
                isset($layers[3]) => [$ch, lkHex($n >= 3 ? PAL['lineHot'] : PAL['line']), null, 'line'],
                isset($layers[2]) => [$ch, lkHex(PAL['areaCrest']), null, 'crest'],
                isset($layers[1]) => [$ch, lkMix(lkHex(PAL['areaFill']), lkHex(PAL['areaCrest']), 0.25 * $y / H), null, 'area'],
                default => [$ch, lkHex(PAL['grid']), null, 'grid'],
            };
        }
    }
    // histogram floor: 2-col bars, 1-col gap, two rows of eighth blocks
    $eighths = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
    for ($x = 0; $x < TOP_AT - 2; $x++) {
        if ($x % 3 === 2) {
            continue;
        }
        $v = max(0.06, min(1.0, seriesBar(intdiv($x, 3), $t)));
        $n = (int) round($v * 16);
        $lvl = $v < 0.45 ? 0 : ($v < 0.75 ? 1 : 2);
        $col = lkHex(PAL['bars'][$lvl]);
        foreach ([H - 1 => min(8, $n), H - 2 => $n - 8] as $y => $k) {
            if ($k > 0) {
                $out[$y][$x] = [$eighths[$k - 1], $col, null, "bar$lvl"];
            }
        }
    }
    return $out;
}

// ================================================================ COMPOSE

/** @return list<list<array>> cells [glyph, fg, bg, role?] */
function compose(int $phase = 0, float $t = 0.0, float $pie = 1.0): array
{
    $cells = array_fill(0, H, array_fill(0, W, [' ', null, null]));
    $over = static function (array $layer, int $ox, int $oy) use (&$cells): void {
        foreach ($layer as $y => $row) {
            foreach ($row as $x => $cell) {
                if ($cell !== null && isset($cells[$y + $oy][$x + $ox])) {
                    $cells[$y + $oy][$x + $ox] = $cell;
                }
            }
        }
    };
    $over(graphCells($t), 0, 0);
    [$cx, $cy] = CANDY_AT;
    $over(candyCells($phase), $cx, $cy);
    $over(topCells($pie), TOP_AT, 0);
    foreach (SPARKLES as [$sx, $sy, $glyph]) {
        if (($cells[$sy][$sx][0] ?? null) === ' ' && $cells[$sy][$sx][2] === null) {
            $cells[$sy][$sx] = [$glyph, lkHex(PAL['sparkle']), null, 'sparkle'];
        }
    }
    return $cells;
}

/** 16-colour code for a colour: by role when known, else nearest TOP anchor, else hue. */
function anchor16(array $c): int
{
    static $anchors = null;
    if ($anchors === null) {
        $anchors = [[lkHex(PAL['rim']), ROLE16['rim']]];
        foreach (PAL['gel'] as $i => $hex) {
            $anchors[] = [lkHex($hex), ROLE16["gel$i"]];
        }
        foreach (PAL['pie'] as $i => [, , $hex]) {
            $anchors[] = [lkHex($hex), ROLE16["pie$i"]];
        }
    }
    if ($c[0] + $c[1] + $c[2] > 690) {
        return 97;                                     // speculars
    }
    $best = 37;
    $bd = INF;
    foreach ($anchors as [$a, $code]) {
        $d = lkDist($c, $a) / max(0.35, (array_sum($c) / max(1, array_sum($a))));
        if ($d < $bd) {
            $bd = $d;
            $best = $code;
        }
    }
    return $best;
}

function encode(array $cells, string $depth): string
{
    $pins = [];
    if ($depth === '16') {
        foreach ($cells as $row) {
            foreach ($row as $cell) {
                [, $fg, $bg] = $cell;
                $role = $cell[3] ?? null;
                $roles = $role === null ? [null, null] : (str_contains($role, '/') ? explode('/', $role) : [$role, $role]);
                foreach ([[$fg, $roles[0]], [$bg, $roles[1]]] as [$c, $r]) {
                    if ($c !== null) {
                        $pins[implode(',', $c)] = $r !== null ? ROLE16[$r] : anchor16($c);
                    }
                }
            }
        }
    }
    $plain = array_map(static fn (array $row): array => array_map(static fn (array $c): array => [$c[0], $c[1], $c[2]], $row), $cells);
    return lkEncode($plain, $depth, $pins);
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$dir = dirname(__DIR__);
$frameOpt = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--frame=')) {
        $frameOpt = (int) substr($a, 8);
    }
}
/** Frame f of N: stripes + graphs scroll into their static pose; the pie sweeps in. */
$frameCells = static function (int $f): array {
    $k = $f - ANIM_FRAMES;                               // → 0 at the static frame
    return compose($k, 0.55 * $k, min(1.0, ($f + 1) / 12));
};
if ($frameOpt !== null) {
    echo encode($frameCells($frameOpt), 'tc');
    exit;
}
foreach (['tc', '256', '16'] as $depth) {
    $static = encode(compose(), $depth);
    [$w, $h] = lkVerify($static, $depth);
    echo $static;
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    if ($write) {
        $r = lkWriteLogo($dir, SLUG, $depth, $static, DESCRIPTION . " ($depth)", TAGS);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        $frames = [];
        for ($f = 0; $f < ANIM_FRAMES; $f++) {
            $frames[] = encode($frameCells($f), $depth);
        }
        $anim = lkAnim($frames, $static);
        $r = lkWriteLogo($dir, SLUG . '-anim', $depth, $anim, 'Animated ' . DESCRIPTION . ' The barber-pole stripes and every graph scroll like a live monitor while the TOP pie chart sweeps in; ' . (ANIM_FRAMES + 1) . ' frames, play with tools/logo-play.php <file> ' . ANIM_DELAY_MS . " (~2s) ($depth)", [...TAGS, 'animated', 'live-scroll', 'pie-sweep']);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
}
