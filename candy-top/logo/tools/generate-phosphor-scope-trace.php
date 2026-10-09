<?php

declare(strict_types=1);

/**
 * generate-phosphor-scope-trace (v2) — "CANDY TOP" as fat neon phosphor tubes
 * on a glowing candy-coloured oscilloscope screen.
 *
 * Technique (mixed glyphs on one sub-pixel grid):
 *   - Everything is evaluated on a px grid of 2×4 px per cell (= braille
 *     resolution; px are square because a cell is ~1:2).
 *   - Letters: a single-stroke vector font turned into a distance field;
 *     px within STROKE_R of a centreline are ink. Cells fully inked are
 *     drawn as a BACKGROUND-colour body with the stroke's white-hot core as
 *     braille dots on top (neon tube with a bright filament); cells on a
 *     letter edge become quadrant blocks (▘▝▖▗▌▐▚▞▛▜▙▟) for crisp outlines.
 *   - Each letter its own candy hue (legibility), lit top → saturated base.
 *   - Glow: every off-letter cell gets a bg bloom of the nearest letter's hue
 *     (exp falloff over the distance field) on a radial violet screen.
 *   - Four braille traces in contrasting colours weave behind the letters:
 *     two braided sines, a logic-analyser pulse train (top) and a heartbeat
 *     whose spike leaps through the word gap (bottom); a dot graticule fills
 *     the rest. Rainbow heavy box-drawing bezel with a colour-keyed channel
 *     legend.
 *   - Animation: screen powers on, the beam traces the letters in stroke
 *     order (white head), the traces sweep in left→right, then the static frame.
 *
 * Usage:
 *   php generate-phosphor-scope-trace.php           preview tc/256/16 + verify
 *   php generate-phosphor-scope-trace.php --write   write .ansi + logos.jsonl
 *   php logo-play.php ../logo-phosphor-scope-trace-anim-tc-78x10.ansi 110
 *
 * Reusable bits: psGlyphSegments()/psField() (any stroke font → distance
 * field with per-px nearest letter + draw order), psTo256() (hue-preserving
 * 256 mapping that never greys out saturated glows), psEncode() (role-aware
 * tc/256/16 encoder: cells carry glow strength / grid role).
 */

require __DIR__ . '/logo-kit.php';
require __DIR__ . '/quadrant-raster.php';   // QR_GLYPHS (bit 1=TL 2=TR 4=BL 8=BR)

// ================================================================ DESIGN DATA

const SLUG = 'phosphor-scope-trace';
const TEXT = 'CANDY TOP';

const IN_W = 76;                 // inner cells (bezel adds 2) → 78 × 10
const IN_H = 8;
const PX_W = IN_W * 2;           // 152 px
const PX_H = IN_H * 4;           // 32 px

const STROKE_R = 2.05;           // tube radius in px
const CORE_R = 0.62;             // white-hot filament radius
const GLYPH_W = 7;               // cells per glyph box (14 px incl. stroke)
const GAP = 1;                   // cells between letters
const WORD_GAP = 3;              // cells for the space
const TEXT_X = 5;                // first glyph cell
const TEXT_Y = 4;                // px row of glyph box top (letters fill inner rows 1-6)

/**
 * Stroke font, centrelines in a 10 × 20 px box (the tube radius is added
 * around it). Polylines; ['arc', cx, cy, rx, ry, a0, a1] expands in place.
 */
const FONT = [
    'C' => [[['arc', 5, 10, 5, 10, 42, 318]]],
    'A' => [[[0, 20], [5, 0], [10, 20]], [[1.9, 13], [8.1, 13]]],
    'N' => [[[0, 20], [0, 0], [10, 20], [10, 0]]],
    'D' => [[[0, 0], [0, 20]], [[0, 0], [3, 0], ['arc', 3, 10, 7, 10, 90, -90], [0, 20]]],
    'Y' => [[[0, 0], [5, 10], [10, 0]], [[5, 10], [5, 20]]],
    'T' => [[[0, 0], [10, 0]], [[5, 0], [5, 20]]],
    'O' => [[['arc', 5, 10, 5, 10, 90, 450]]],
    'P' => [[[0, 20], [0, 0]], [[0, 0], [5, 0], ['arc', 5, 5.25, 5, 5.25, 90, -90], [0, 10.5]]],
];

/** One candy hue per letter (C A N D Y T O P). */
const LETTER_HUES = ['#FF3E9E', '#FF8A1F', '#FFE03A', '#6DFF4A', '#22EEFF', '#4F8DFF', '#B26BFF', '#FF4FE6'];

const SCREEN_CENTRE = '#2A1268';
const SCREEN_EDGE = '#0C0624';
const GLOW = 0.36;               // peak bloom mix at the tube edge
const GLOW_FALLOFF = 2.4;        // px
const GRATICULE = '#5A44A8';

/** Traces: [name, colour, label]. Shapes in traceY(). */
const TRACES = [
    ['sineA', '#FFF26B', 'CH1'],
    ['sineB', '#3DF5FF', 'CH2'],
    ['pulse', '#7DFF6B', 'CH3'],
    ['heart', '#FF5FB0', 'CH4'],
];

const BEZEL_RAINBOW = ['#FF3E9E', '#FF8A1F', '#FFE03A', '#6DFF4A', '#22EEFF', '#4F8DFF', '#B26BFF', '#FF4FE6'];
const BEZEL_TEXT = '#FFFFFF';
const TOP_LABEL = ' ▲ RUN  2ms/div ';

const DESCRIPTION = 'Candy oscilloscope: CANDY TOP as fat neon phosphor tubes (bg-colour bodies with white-hot braille filaments, quadrant-block edges), one vivid hue per letter, coloured bloom on a radial violet screen, four multicolour braille traces (braided sines, logic pulse, heartbeat leaping the word gap), dot graticule, rainbow heavy box-drawing bezel with a colour-keyed channel legend.';
const TAGS = ['phosphor-scope-trace', 'neon-tube-letters', 'braille-traces', 'quadrant-edges', 'oscilloscope-crt', 'rainbow-bezel', 'bloom-glow', 'multicolour'];

const ANIM_FRAMES = 24;          // + static; logo-play at 110 ms → ~2.75 s

// ================================================================ GEOMETRY

/**
 * Expand a stroke font into segments in px space.
 * @return list<array{0:float,1:float,2:float,3:float,4:int,5:int}> [x0,y0,x1,y1,letter,order]
 */
function psGlyphSegments(): array
{
    $segs = [];
    $x = TEXT_X * 2 + STROKE_R;
    $letter = 0;
    $order = 0;
    foreach (str_split(TEXT) as $ch) {
        if ($ch === ' ') {
            $x += WORD_GAP * 2;
            continue;
        }
        foreach (FONT[$ch] as $stroke) {
            $pts = [];
            foreach ($stroke as $p) {
                if ($p[0] === 'arc') {
                    [, $cx, $cy, $rx, $ry, $a0, $a1] = $p;
                    $n = (int) max(8, abs($a1 - $a0) / 9);
                    for ($i = 0; $i <= $n; $i++) {
                        $a = deg2rad($a0 + ($a1 - $a0) * $i / $n);
                        $pts[] = [$cx + $rx * cos($a), $cy - $ry * sin($a)];
                    }
                } else {
                    $pts[] = $p;
                }
            }
            for ($i = 1; $i < count($pts); $i++) {
                $segs[] = [
                    $x + $pts[$i - 1][0], TEXT_Y + STROKE_R + $pts[$i - 1][1] * (24 - 2 * STROKE_R) / 20,
                    $x + $pts[$i][0], TEXT_Y + STROKE_R + $pts[$i][1] * (24 - 2 * STROKE_R) / 20,
                    $letter, $order++,
                ];
            }
        }
        $letter++;
        $x += (GLYPH_W + GAP) * 2;
    }
    return $segs;
}

/**
 * Distance field at px centres.
 * @return array{0: array, 1: array, 2: array, 3: int} [dist[y][x], letter[y][x], order[y][x], maxOrder]
 */
function psField(array $segs): array
{
    $dist = $let = $ord = [];
    $maxOrder = 0;
    for ($y = 0; $y < PX_H; $y++) {
        for ($x = 0; $x < PX_W; $x++) {
            $px = $x + 0.5;
            $py = $y + 0.5;
            $best = INF;
            $bl = 0;
            $bo = 0;
            foreach ($segs as [$x0, $y0, $x1, $y1, $l, $o]) {
                if ($px < min($x0, $x1) - 12 || $px > max($x0, $x1) + 12) {
                    continue;
                }
                $dx = $x1 - $x0;
                $dy = $y1 - $y0;
                $len2 = $dx * $dx + $dy * $dy;
                $t = $len2 > 0 ? max(0.0, min(1.0, (($px - $x0) * $dx + ($py - $y0) * $dy) / $len2)) : 0.0;
                $d = hypot($px - $x0 - $t * $dx, $py - $y0 - $t * $dy);
                if ($d < $best) {
                    [$best, $bl, $bo] = [$d, $l, $o];
                }
            }
            $dist[$y][$x] = $best;
            $let[$y][$x] = $bl;
            $ord[$y][$x] = $bo;
        }
    }
    foreach ($segs as $s) {
        $maxOrder = max($maxOrder, $s[5]);
    }
    return [$dist, $let, $ord, $maxOrder];
}

/** Trace y (px) at px column x; null = no dot. */
function traceY(string $name, int $x): ?float
{
    return match ($name) {
        'sineA' => 15.5 + 12.5 * sin($x / 11.0 + 0.3),
        'sineB' => 15.5 + 9.0 * sin($x / 11.0 + 2.6),
        'pulse' => (intdiv($x + 3, 9) % 3 === 0) ? 0.0 : 2.6,   // logic pulse train in inner row 0
        'heart' => heartY($x),
        default => null,
    };
}

function heartY(int $x): float
{
    $y = 29.5 + 0.8 * sin($x / 5.0);
    foreach ([[18, 3.0], [91, 27.0], [146, 3.0]] as [$c, $h]) {
        $d = $x - $c;
        if ($d >= -4 && $d < -2) {
            $y = 31.0;
        } elseif ($d >= -2 && $d <= 2) {
            $y = 29.5 - $h * (1 - abs($d) / 2.6);
        } elseif ($d > 2 && $d <= 4) {
            $y = 31.0;
        }
    }
    return max(0.0, min(31.0, $y));
}

/**
 * Trace dots: [y][x] = trace index. Vertical jumps are filled so steep
 * edges stay continuous. $upToX limits the sweep (animation).
 */
function psTraces(int $upToX = PX_W): array
{
    $dots = [];
    foreach (TRACES as $ti => [$name]) {
        $prev = null;
        for ($x = 0; $x < min(PX_W, $upToX); $x++) {
            $y = (int) round(traceY($name, $x));
            $from = $prev ?? $y;
            for ($yy = min($from, $y); $yy <= max($from, $y); $yy++) {
                // fill the jump half in the previous column, half in this one
                $col = ($prev !== null && abs($yy - $from) < abs($yy - $y)) ? $x - 1 : $x;
                $dots[$yy][$col] ??= $ti;
            }
            $prev = $y;
        }
    }
    return $dots;
}

// ================================================================ RENDER

function screenBg(int $cx, int $cy): array
{
    $dx = ($cx - IN_W / 2) / (IN_W / 2);
    $dy = ($cy - IN_H / 2 + 0.5) / (IN_H / 2) * 0.55;
    $t = min(1.0, sqrt($dx * $dx + $dy * $dy));
    $c = lkMix(lkHex(SCREEN_CENTRE), lkHex(SCREEN_EDGE), $t ** 1.4);
    return $cy % 2 === 1 ? lkScale($c, 0.9) : $c;            // CRT scanline texture
}

/**
 * Letter hue for (letter, px y) — lit candy top, saturated base, and tube
 * shading by distance from the centreline.
 */
function tubeColour(int $l, float $py, float $d): array
{
    $base = lkHex(LETTER_HUES[$l]);
    $v = ($py - TEXT_Y) / 24;
    $c = $v < 0.5 ? lkMix($base, [255, 255, 255], 0.28 * (0.5 - $v) * 2) : lkScale($base, 1 - 0.22 * ($v - 0.5) * 2);
    return lkScale($c, 1 - 0.18 * min(1.0, $d / STROKE_R));
}

/**
 * Build the cell grid. $p: animation progress [power 0..1, letters 0..1,
 * traces 0..1]; null = static.
 */
function cells(array $field, ?array $p = null): array
{
    [$dist, $let, $ord, $maxOrder] = $field;
    [$power, $letP, $traceP] = $p ?? [1.0, 1.0, 1.0];
    $reveal = $letP * ($maxOrder + 6);
    $headWin = 7;
    $traces = psTraces((int) round($traceP * PX_W));

    $W = IN_W + 2;
    $H = IN_H + 2;
    $cells = lkBlankCells($W, $H);
    $white = [255, 255, 255];

    for ($cy = 0; $cy < IN_H; $cy++) {
        for ($cx = 0; $cx < IN_W; $cx++) {
            $ink = 0;            // count of inked px (of 8)
            $quad = 0;
            $core = 0;
            $head = false;
            $dMin = INF;
            $lNear = 0;
            $dSum = 0.0;
            $lInk = [];
            for ($dy = 0; $dy < 4; $dy++) {
                for ($dx = 0; $dx < 2; $dx++) {
                    $x = $cx * 2 + $dx;
                    $y = $cy * 4 + $dy;
                    $d = $dist[$y][$x];
                    $shown = $ord[$y][$x] <= $reveal;
                    if (!$shown) {
                        $d = INF;
                    }
                    if ($d < $dMin) {
                        [$dMin, $lNear] = [$d, $let[$y][$x]];
                    }
                    if ($d < STROKE_R) {
                        $ink++;
                        $dSum += $d;
                        $lInk[$let[$y][$x]] = ($lInk[$let[$y][$x]] ?? 0) + 1;
                        $quad |= [[1, 2], [1, 2], [4, 8], [4, 8]][$dy][$dx];
                        if ($d < CORE_R) {
                            $core |= [[0x01, 0x08], [0x02, 0x10], [0x04, 0x20], [0x40, 0x80]][$dy][$dx];
                        }
                        if ($p !== null && $ord[$y][$x] > $reveal - $headWin) {
                            $head = true;
                        }
                    }
                }
            }
            // quadrant needs both px of the half-column inked to count, else edges look ragged
            $quad = 0;
            foreach ([[0, 0, 1], [1, 0, 2], [0, 2, 4], [1, 2, 8]] as [$qx, $qy, $bit]) {
                $n = 0;
                for ($k = 0; $k < 2; $k++) {
                    $d = $dist[$cy * 4 + $qy + $k][$cx * 2 + $qx];
                    if ($d < STROKE_R && $ord[$cy * 4 + $qy + $k][$cx * 2 + $qx] <= $reveal) {
                        $n++;
                    }
                }
                if ($n >= 1) {
                    $quad |= $bit;
                }
            }

            $screen = lkScale(screenBg($cx, $cy), 0.25 + 0.75 * $power);
            $glowK = $dMin === INF ? 0.0 : GLOW * exp(-max(0.0, $dMin - STROKE_R) / GLOW_FALLOFF);
            $glowBg = lkMix($screen, lkHex(LETTER_HUES[$lNear]), $glowK);
            $mx = $cx + 1;
            $my = $cy + 1;

            if ($ink > 0 && $quad === 15) {
                // full tube body: bg = letter colour, filament = braille core
                arsort($lInk);
                $l = array_key_first($lInk);
                $body = tubeColour($l, $cy * 4 + 2, $dSum / $ink);
                if ($head) {
                    $body = lkMix($body, $white, 0.55);
                }
                $coreC = $head ? $white : lkMix(lkHex(LETTER_HUES[$l]), $white, 0.78);
                $cells[$my][$mx] = $core ? [mb_chr(0x2800 + $core), $coreC, $body] : [' ', null, $body];
                continue;
            }
            if ($quad !== 0) {
                arsort($lInk);
                $l = $lInk ? array_key_first($lInk) : $lNear;
                $fg = tubeColour($l, $cy * 4 + 2, STROKE_R * 0.7);
                if ($head) {
                    $fg = lkMix($fg, $white, 0.55);
                }
                $cells[$my][$mx] = [QR_GLYPHS[$quad], $fg, $glowBg, $glowK, null, $cy % 2];
                continue;
            }
            // background: traces win over graticule
            $bits = 0;
            $tCount = [];
            for ($dy = 0; $dy < 4; $dy++) {
                for ($dx = 0; $dx < 2; $dx++) {
                    $t = $traces[$cy * 4 + $dy][$cx * 2 + $dx] ?? null;
                    if ($t !== null) {
                        $bits |= [[0x01, 0x08], [0x02, 0x10], [0x04, 0x20], [0x40, 0x80]][$dy][$dx];
                        $tCount[$t] = ($tCount[$t] ?? 0) + 1;
                    }
                }
            }
            if ($bits) {
                arsort($tCount);
                $tc = lkHex(TRACES[array_key_first($tCount)][1]);
                if (count($tCount) > 1) {
                    $tc = lkMix($tc, $white, 0.5);                 // traces cross → flare
                }
                $cells[$my][$mx] = [mb_chr(0x2800 + $bits), lkScale($tc, 0.4 + 0.6 * $power), $glowBg, $glowK, null, $cy % 2];
                continue;
            }
            $gx = $cx % 6 === 3;
            $gy = $cy % 2 === 0;
            if ($gx || ($gy && $cx % 2 === 0)) {
                $dot = $gx && $gy ? 0x12 : ($gx ? 0x02 : 0x01);
                $cells[$my][$mx] = [mb_chr(0x2800 + $dot), lkMix($glowBg, lkHex(GRATICULE), 0.75 * $power), $glowBg, $glowK, 'grid', $cy % 2];
                continue;
            }
            $cells[$my][$mx] = [' ', null, $glowBg, $glowK, null, $cy % 2];
        }
    }
    bezel($cells, $W, $H, $power);
    return $cells;
}

function bezel(array &$cells, int $W, int $H, float $power): void
{
    $rb = static fn (int $x): array => lkGradient(BEZEL_RAINBOW, $x / ($W - 1));
    for ($x = 0; $x < $W; $x++) {
        $cells[0][$x] = ['━', $rb($x), null];
        $cells[$H - 1][$x] = ['━', $rb($x), null];
    }
    for ($y = 1; $y < $H - 1; $y++) {
        $cells[$y][0] = ['┃', $rb(0), null];
        $cells[$y][$W - 1] = ['┃', $rb($W - 1), null];
    }
    $cells[0][0] = ['┏', $rb(0), null];
    $cells[0][$W - 1] = ['┓', $rb($W - 1), null];
    $cells[$H - 1][0] = ['┗', $rb(0), null];
    $cells[$H - 1][$W - 1] = ['┛', $rb($W - 1), null];

    // top: power LEDs + run/timebase label
    $x = 2;
    foreach (['#FF3E9E', '#FFE03A', '#22EEFF'] as $i => $led) {
        $cells[0][$x + $i * 2] = ['⣿', $power >= 0.999 || $i === 0 ? lkHex($led) : lkScale(lkHex($led), 0.35), null];
    }
    $label = TOP_LABEL;
    $lx = $W - 3 - mb_strlen($label) - 2;
    putText($cells, 0, $lx, '┫', $rb($lx), null);
    putText($cells, 0, $lx + 1, $label, lkHex(BEZEL_TEXT), [36, 16, 70]);
    putText($cells, 0, $lx + 1 + mb_strlen($label), '┣', $rb($lx + 1 + mb_strlen($label)), null);

    // bottom: channel legend, each keyed with its trace colour
    $x = 3;
    putText($cells, $H - 1, $x, '┫', $rb($x), null);
    $x++;
    foreach (TRACES as [, $col, $name]) {
        putText($cells, $H - 1, $x, ' ━━', lkHex($col), [36, 16, 70]);
        putText($cells, $H - 1, $x + 3, " $name", lkHex(BEZEL_TEXT), [36, 16, 70]);
        $x += 7;
    }
    putText($cells, $H - 1, $x, ' ', null, [36, 16, 70]);
    putText($cells, $H - 1, $x + 1, '┣', $rb($x + 1), null);
    $right = ' candy·top ';
    $rx = $W - 3 - mb_strlen($right) - 1;
    putText($cells, $H - 1, $rx, '┫', $rb($rx), null);
    putText($cells, $H - 1, $rx + 1, $right, lkHex('#FFD1F0'), [36, 16, 70]);
    putText($cells, $H - 1, $rx + 1 + mb_strlen($right), '┣', $rb($rx + 1 + mb_strlen($right)), null);
}

function putText(array &$cells, int $y, int $x, string $text, ?array $fg, ?array $bg): void
{
    foreach (mb_str_split($text) as $i => $ch) {
        $cells[$y][$x + $i] = [$ch, $fg, $bg];
    }
}

// ================================================================ DEPTH

/** Hue-preserving xterm-256: saturated colours only match the 6×6×6 cube (never the grey ramp). */
function psTo256(array $c, bool $forceHue = false): int
{
    static $memo = [];
    $k = implode(',', $c) . ($forceHue ? 'h' : '');
    if (isset($memo[$k])) {
        return $memo[$k];
    }
    $max = max($c);
    $sat = $max > 0 ? ($max - min($c)) / $max : 0;
    if ($sat < 0.25 && !$forceHue) {
        return $memo[$k] = lkTo256($c);
    }
    $pal = lkXterm256();
    $best = 16;
    $bd = INF;
    for ($i = 16; $i < 232; $i++) {
        $q = $i - 16;
        if (intdiv($q, 36) === intdiv($q % 36, 6) && intdiv($q % 36, 6) === $q % 6) {
            continue;            // skip the cube's own greys
        }
        $d = lkDist($c, $pal[$i]);
        if ($d < $bd) {
            [$bd, $best] = [$d, $i];
        }
    }
    return $memo[$k] = $best;
}

/** 16-colour by role-ish rules: dark → black, near-white → bright white, else hue band. */
function psTo16(array $c): int
{
    [$r, $g, $b] = $c;
    $max = max($c);
    $min = min($c);
    if ($max < 125) {
        return 30;
    }
    if ($min > 185 || ($max - $min) < 45) {
        return 97;
    }
    $h = rad2deg(atan2(sqrt(3) * ($g - $b), 2 * $r - $g - $b));
    $h = $h < 0 ? $h + 360 : $h;
    return match (true) {
        $h >= 345 || $h < 15 => 91,
        $h < 42 => 33,           // orange
        $h < 75 => 93,           // yellow
        $h < 160 => 92,          // lime
        $h < 205 => 96,          // aqua
        $h < 245 => 94,          // blue
        $h < 285 => 35,          // violet
        $h < 345 => 95,          // pink / magenta
    };
}

/**
 * Role-aware encoder. Cells may carry [3] = glow strength (marks a screen /
 * bloom background) and [4] = 'grid'. In 256 the faint screen collapses to a
 * clean dark ramp and only strong bloom keeps a cube tint (nearest-256 made
 * the glow blotchy); in 16 the whole screen is black and the graticule dim.
 */
function psEncode(array $cells, string $depth): string
{
    $map = static function (?array $c, bool $bg, array $cell) use ($depth): string {
        if ($c === null) {
            return $bg ? '49' : '39';
        }
        $glow = $cell[3] ?? null;
        $grid = ($cell[4] ?? null) === 'grid';
        if ($depth === 'tc') {
            return ($bg ? '48' : '38') . ";2;{$c[0]};{$c[1]};{$c[2]}";
        }
        if ($depth === '256') {
            if ($glow !== null && ($bg || $grid)) {
                if ($bg && $glow > 0.22) {
                    return '48;5;' . psTo256($c, true);
                }
                $odd = ($cell[5] ?? 0) === 1;
                return ($bg ? '48' : '38') . ';5;' . (($grid && !$bg) ? 60 : ($odd ? 232 : 233));
            }
            return ($bg ? '48' : '38') . ';5;' . psTo256($c);
        }
        if ($glow !== null && $bg) {
            return '40';
        }
        if ($grid) {
            return '35';
        }
        return (string) (psTo16($c) + ($bg ? 10 : 0));
    };
    $out = '';
    foreach ($cells as $row) {
        [$curFg, $curBg, $line] = ['39', '49', ''];
        foreach ($row as $cell) {
            [$ch, $fg, $bg] = $cell;
            $codes = [];
            $b = $map($bg, true, $cell);
            if ($b !== $curBg) {
                $codes[] = $curBg = $b;
            }
            if ($ch !== ' ') {
                $f = $map($fg, false, $cell);
                if ($f !== $curFg) {
                    $codes[] = $curFg = $f;
                }
            }
            $line .= ($codes ? "\e[" . implode(';', $codes) . 'm' : '') . $ch;
        }
        $out .= $line . "\e[0m\n";
    }
    return $out;
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$dir = dirname(__DIR__);
$field = psField(psGlyphSegments());
$static = cells($field);

$frames = [];
for ($f = 0; $f < ANIM_FRAMES; $f++) {
    $t = $f / (ANIM_FRAMES - 1);
    $power = min(1.0, $t / 0.15);
    $letP = max(0.0, min(1.0, ($t - 0.08) / 0.6));
    $trP = max(0.0, min(1.0, ($t - 0.5) / 0.45));
    $frames[] = cells($field, [$power, $letP, $trP]);
}

foreach (['tc', '256', '16'] as $depth) {
    $enc = psEncode($static, $depth);
    [$w, $h] = lkVerify($enc, $depth);
    lkCheckDepth($enc, $depth, $depth);
    echo $enc;
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    if ($write) {
        $r = lkWriteLogo($dir, SLUG, $depth, $enc, DESCRIPTION . " ($depth)", TAGS);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        $anim = lkAnim(array_map(static fn (array $fr): string => psEncode($fr, $depth), $frames), $enc);
        $r = lkWriteLogo($dir, SLUG . '-anim', $depth, $anim, 'Animated ' . DESCRIPTION . " Screen powers on, the beam traces each letter in stroke order with a white-hot head, then the four traces sweep in; ends on the static frame. Play with tools/logo-play.php at 110 ms (~2.75 s). ($depth)", [...TAGS, 'animated', 'beam-trace-reveal']);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
}
