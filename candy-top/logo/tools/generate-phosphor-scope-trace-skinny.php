<?php

declare(strict_types=1);

/**
 * generate-phosphor-scope-trace-skinny — 40-col SKINNY variant of
 * generate-phosphor-scope-trace.php: the same candy oscilloscope (fat neon
 * phosphor tubes with white-hot braille filaments and quadrant edges, one hue
 * per letter, coloured bloom on a radial violet screen, multicolour braille
 * traces, dot graticule, rainbow heavy bezel), with CANDY STACKED over TOP
 * in narrower 6-cell glyph boxes. The heartbeat now runs either side of TOP
 * and spikes up into the free corners; the logic pulse runs in the row between the words.
 *
 * Built on the reusable skinny tools:
 *   skinny-stroke-font.php  ssfLayout/ssfField — stroke font → distance field
 *                           (any glyph box / rows / gaps) + per-px letter + draw order
 *   skinny-depth.php        sdEncode — role-aware hue-preserving tc/256/16
 *   skinny-b-kit.php        skbWriteSet — verify ≤40 cols + write + jsonl (tag skinny)
 *
 * Usage:
 *   php generate-phosphor-scope-trace-skinny.php                 preview tc/256/16 + verify
 *   php generate-phosphor-scope-trace-skinny.php --write --jsonl write .ansi (+ anim) + logos.jsonl
 *   php generate-phosphor-scope-trace-skinny.php --png=DIR       also PNG previews into DIR
 *   php logo-play.php ../logo-phosphor-scope-trace-skinny-anim-tc-40x13.ansi 110
 */

require_once __DIR__ . '/logo-kit.php';
require_once __DIR__ . '/skinny-stroke-font.php';
require_once __DIR__ . '/skinny-depth.php';
require_once __DIR__ . '/skinny-b-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'phosphor-scope-trace-skinny';

const IN_W = 38;                 // inner cells; bezel adds 2 → 40 × 13
const IN_H = 11;
const PX_W = IN_W * 2;           // braille px: 76 × 44
const PX_H = IN_H * 4;

/** ssf layout options (cells unless noted). */
const LAYOUT = [
    'w' => IN_W, 'h' => IN_H, 'res' => 'braille', 'glyphW' => 6, 'glyphH' => 5,
    'gap' => 1, 'rowGap' => 1, 'top' => 0, 'radius' => 1.85,
];
const ROWS = ['CANDY', 'TOP'];
const STROKE_R = 1.85;           // px
const CORE_R = 0.6;

const LETTER_HUES = ['#FF3E9E', '#FF8A1F', '#FFE03A', '#6DFF4A', '#22EEFF', '#4F8DFF', '#B26BFF', '#FF4FE6'];
const SCREEN_CENTRE = '#2A1268';
const SCREEN_EDGE = '#0C0624';
const GLOW = 0.36;
const GLOW_FALLOFF = 2.4;
const GRATICULE = '#5A44A8';

/** Traces: [name, colour, label]. Shapes in traceY(). */
const TRACES = [
    ['sineA', '#FFF26B', 'CH1'],
    ['sineB', '#3DF5FF', 'CH2'],
    ['pulse', '#7DFF6B', 'CH3'],
    ['heart', '#FF5FB0', 'CH4'],
];

const BEZEL_RAINBOW = ['#FF3E9E', '#FF8A1F', '#FFE03A', '#6DFF4A', '#22EEFF', '#4F8DFF', '#B26BFF', '#FF4FE6'];
const LABEL_BG = [36, 16, 70];
const TOP_LABEL = ' ▲RUN 2ms/div ';

/** Role pins for skinny-depth: screen collapses to flat dark in 256/16, strong bloom keeps a cube hue. */
function roles(): array
{
    $screen256 = static fn (array $c, array $cell): string => '48;5;' . (($cell[5] ?? 0) === 1 ? 232 : 233);
    return [
        'screen' => ['256' => ['bg' => $screen256], '16' => ['bg' => 40]],
        'glow' => ['256' => ['bg' => static fn (array $c): string => '48;5;' . sdTo256Hue($c, true)], '16' => ['bg' => 40]],
        'grid' => ['256' => ['bg' => $screen256, 'fg' => 60], '16' => ['bg' => 40, 'fg' => 35]],
        'gridglow' => ['256' => ['bg' => static fn (array $c): string => '48;5;' . sdTo256Hue($c, true), 'fg' => 60], '16' => ['bg' => 40, 'fg' => 35]],
    ];
}

const DESCRIPTION = 'Skinny 40-col candy oscilloscope: CANDY stacked over TOP as fat neon phosphor tubes (bg-colour bodies with white-hot braille filaments, quadrant-block edges), one vivid hue per letter, coloured bloom on a radial violet screen, four multicolour braille traces (braided sines, logic pulse running between the two words, heartbeat spiking up either side of TOP), dot graticule, rainbow heavy box-drawing bezel with a colour-keyed channel legend.';
const TAGS = ['phosphor-scope-trace', 'skinny', 'stacked-words', 'neon-tube-letters', 'braille-traces', 'quadrant-edges', 'oscilloscope-crt', 'rainbow-bezel', 'bloom-glow', 'multicolour'];
const ANIM_FRAMES = 24;

const QUAD = [' ', '▘', '▝', '▀', '▖', '▌', '▞', '▛', '▗', '▚', '▐', '▜', '▄', '▙', '▟', '█'];
const BRAILLE_BIT = [[0x01, 0x08], [0x02, 0x10], [0x04, 0x20], [0x40, 0x80]];

// ================================================================ TRACES

function traceY(string $name, int $x): float
{
    return match ($name) {
        'sineA' => 22 + 19 * sin($x / 8.0 + 0.6),
        'sineB' => 22 + 14 * sin($x / 8.0 + 2.9),
        'pulse' => (intdiv($x + 2, 7) % 3 === 0) ? 20.6 : 22.4,   // logic pulse in the row between CANDY and TOP
        'heart' => heartY($x),
    };
}

function heartY(int $x): float
{
    $y = 41.5 + 0.8 * sin($x / 4.0);
    foreach ([[8, 15.0], [68, 15.0]] as [$c, $h]) {
        $d = $x - $c;
        if ($d >= -4 && $d < -2) {
            $y = 43.0;
        } elseif ($d >= -2 && $d <= 2) {
            $y = 41.5 - $h * (1 - abs($d) / 2.6);
        } elseif ($d > 2 && $d <= 4) {
            $y = 43.0;
        }
    }
    return max(0.0, min(PX_H - 1.0, $y));
}

/** [y][x] = trace index; steep jumps filled; sweep limited to $upToX. */
function traces(int $upToX = PX_W): array
{
    $dots = [];
    foreach (TRACES as $ti => [$name]) {
        $prev = null;
        for ($x = 0; $x < min(PX_W, $upToX); $x++) {
            $y = (int) round(traceY($name, $x));
            $from = $prev ?? $y;
            for ($yy = min($from, $y); $yy <= max($from, $y); $yy++) {
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
    $dy = ($cy - IN_H / 2 + 0.5) / (IN_H / 2) * 0.7;
    $t = min(1.0, sqrt($dx * $dx + $dy * $dy));
    $c = lkMix(lkHex(SCREEN_CENTRE), lkHex(SCREEN_EDGE), $t ** 1.4);
    return $cy % 2 === 1 ? lkScale($c, 0.9) : $c;
}

/** Lit candy top → saturated base within the letter's own box; tube shading by distance. */
function tubeColour(array $boxes, int $l, float $py, float $d): array
{
    [, $by, , $bh] = $boxes[$l];
    $base = lkHex(LETTER_HUES[$l]);
    $v = max(0.0, min(1.0, ($py - $by) / $bh));
    $c = $v < 0.5 ? lkMix($base, [255, 255, 255], 0.28 * (0.5 - $v) * 2) : lkScale($base, 1 - 0.22 * ($v - 0.5) * 2);
    return lkScale($c, 1 - 0.18 * min(1.0, $d / STROKE_R));
}

/** $p = [power, letters, traces] in 0..1 (animation), null = static. */
function cells(array $L, array $F, ?array $p = null): array
{
    [$dist, $let, $ord, $maxOrder] = [$F['dist'], $F['letter'], $F['order'], $F['maxOrder']];
    [$power, $letP, $traceP] = $p ?? [1.0, 1.0, 1.0];
    $reveal = $letP * ($maxOrder + 6);
    $tr = traces((int) round($traceP * PX_W));
    $white = [255, 255, 255];
    $cells = lkBlankCells(IN_W + 2, IN_H + 2);
    $shown = static fn (int $x, int $y): bool => $dist[$y][$x] < STROKE_R && $ord[$y][$x] <= $reveal;

    for ($cy = 0; $cy < IN_H; $cy++) {
        for ($cx = 0; $cx < IN_W; $cx++) {
            [$ink, $core, $head, $dMin, $lNear, $dSum, $votes] = [0, 0, false, INF, 0, 0.0, []];
            for ($dy = 0; $dy < 4; $dy++) {
                for ($dx = 0; $dx < 2; $dx++) {
                    [$x, $y] = [$cx * 2 + $dx, $cy * 4 + $dy];
                    $d = $ord[$y][$x] <= $reveal ? $dist[$y][$x] : INF;
                    if ($d < $dMin) {
                        [$dMin, $lNear] = [$d, $let[$y][$x]];
                    }
                    if ($d < STROKE_R) {
                        $ink++;
                        $dSum += $d;
                        $votes[$let[$y][$x]] = ($votes[$let[$y][$x]] ?? 0) + 1;
                        if ($d < CORE_R) {
                            $core |= BRAILLE_BIT[$dy][$dx];
                        }
                        if ($p !== null && $ord[$y][$x] > $reveal - 7) {
                            $head = true;
                        }
                    }
                }
            }
            $quad = 0;
            foreach ([[0, 0, 1], [1, 0, 2], [0, 2, 4], [1, 2, 8]] as [$qx, $qy, $bit]) {
                if ($shown($cx * 2 + $qx, $cy * 4 + $qy) || $shown($cx * 2 + $qx, $cy * 4 + $qy + 1)) {
                    $quad |= $bit;
                }
            }
            $screen = lkScale(screenBg($cx, $cy), 0.25 + 0.75 * $power);
            $k = ($dMin === INF || $lNear < 0) ? 0.0 : GLOW * exp(-max(0.0, $dMin - STROKE_R) / GLOW_FALLOFF);
            $bg = $k > 0 ? lkMix($screen, lkHex(LETTER_HUES[$lNear]), $k) : $screen;
            $role = $k > 0.3 ? 'glow' : 'screen';      // only the tube-adjacent bloom keeps a cube hue in 256
            $par = $cy % 2;
            [$mx, $my] = [$cx + 1, $cy + 1];

            if ($ink > 0 && $quad === 15) {
                arsort($votes);
                $l = array_key_first($votes);
                $body = tubeColour($L['boxes'], $l, $cy * 4 + 2, $dSum / $ink);
                if ($head) {
                    $body = lkMix($body, $white, 0.55);
                }
                $coreC = $head ? $white : lkMix(lkHex(LETTER_HUES[$l]), $white, 0.78);
                $cells[$my][$mx] = $core ? [mb_chr(0x2800 + $core), $coreC, $body] : [' ', null, $body];
                continue;
            }
            if ($quad !== 0) {
                arsort($votes);
                $l = $votes ? array_key_first($votes) : $lNear;
                $fg = tubeColour($L['boxes'], $l, $cy * 4 + 2, STROKE_R * 0.7);
                $cells[$my][$mx] = [QUAD[$quad], $head ? lkMix($fg, $white, 0.55) : $fg, $bg, $role, null, $par];
                continue;
            }
            [$bits, $tCount] = [0, []];
            for ($dy = 0; $dy < 4; $dy++) {
                for ($dx = 0; $dx < 2; $dx++) {
                    $t = $tr[$cy * 4 + $dy][$cx * 2 + $dx] ?? null;
                    if ($t !== null) {
                        $bits |= BRAILLE_BIT[$dy][$dx];
                        $tCount[$t] = ($tCount[$t] ?? 0) + 1;
                    }
                }
            }
            if ($bits) {
                arsort($tCount);
                $tc = lkHex(TRACES[array_key_first($tCount)][1]);
                if (count($tCount) > 1) {
                    $tc = lkMix($tc, $white, 0.5);
                }
                $cells[$my][$mx] = [mb_chr(0x2800 + $bits), lkScale($tc, 0.4 + 0.6 * $power), $bg, $role, null, $par];
                continue;
            }
            $gx = $cx % 6 === 1;
            $gy = $cy % 2 === 0;
            if ($gx || ($gy && $cx % 2 === 0)) {
                $dot = $gx && $gy ? 0x12 : ($gx ? 0x02 : 0x01);
                $cells[$my][$mx] = [mb_chr(0x2800 + $dot), lkMix($bg, lkHex(GRATICULE), 0.75 * $power), $bg, $role === 'glow' ? 'gridglow' : 'grid', null, $par];
                continue;
            }
            $cells[$my][$mx] = [' ', null, $bg, $role, null, $par];
        }
    }
    bezel($cells, IN_W + 2, IN_H + 2, $power);
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
    [$cells[0][0], $cells[0][$W - 1]] = [['┏', $rb(0), null], ['┓', $rb($W - 1), null]];
    [$cells[$H - 1][0], $cells[$H - 1][$W - 1]] = [['┗', $rb(0), null], ['┛', $rb($W - 1), null]];

    foreach (['#FF3E9E', '#FFE03A', '#22EEFF'] as $i => $led) {
        $cells[0][2 + $i * 2] = ['⣿', $power >= 0.999 || $i === 0 ? lkHex($led) : lkScale(lkHex($led), 0.35), null];
    }
    $lx = $W - 3 - mb_strlen(TOP_LABEL) - 1;
    put($cells, 0, $lx, '┫', $rb($lx), null);
    put($cells, 0, $lx + 1, TOP_LABEL, [255, 255, 255], LABEL_BG);
    put($cells, 0, $lx + 1 + mb_strlen(TOP_LABEL), '┣', $rb($lx + 1 + mb_strlen(TOP_LABEL)), null);

    // bottom legend, centred: ┫ ━CH1 ━CH2 ━CH3 ━CH4 ┣
    $legendW = 2 + count(TRACES) * 5 + 1;
    $x = intdiv($W - $legendW, 2);
    put($cells, $H - 1, $x, '┫', $rb($x), null);
    $x++;
    foreach (TRACES as [, $col, $name]) {
        put($cells, $H - 1, $x, ' ━', lkHex($col), LABEL_BG);
        put($cells, $H - 1, $x + 2, $name, [255, 255, 255], LABEL_BG);
        $x += 5;
    }
    put($cells, $H - 1, $x, ' ', null, LABEL_BG);
    put($cells, $H - 1, $x + 1, '┣', $rb($x + 1), null);
}

function put(array &$cells, int $y, int $x, string $text, ?array $fg, ?array $bg): void
{
    foreach (mb_str_split($text) as $i => $ch) {
        $cells[$y][$x + $i] = [$ch, $fg, $bg];
    }
}

// ================================================================ CLI

$args = skbArgs($argv);
if ($args['help']) {
    skbHelp(__FILE__);
    exit(0);
}
$layout = ssfLayout(ROWS, LAYOUT);
$field = ssfField($layout, LAYOUT);
$static = cells($layout, $field);
$frames = [];
for ($f = 0; $f < ANIM_FRAMES; $f++) {
    $t = $f / (ANIM_FRAMES - 1);
    $frames[] = cells($layout, $field, [min(1.0, $t / 0.15), max(0.0, min(1.0, ($t - 0.08) / 0.6)), max(0.0, min(1.0, ($t - 0.5) / 0.45))]);
}

$byDepth = $animByDepth = [];
foreach (['tc', '256', '16'] as $depth) {
    $byDepth[$depth] = sdEncode($static, $depth, roles());
    $animByDepth[$depth] = lkAnim(array_map(static fn (array $fr): string => sdEncode($fr, $depth, roles()), $frames), $byDepth[$depth]);
    [$w, $h] = skbVerify($byDepth[$depth], $depth);
    lkCheckDepth($animByDepth[$depth], $depth, "anim/$depth");
    echo $byDepth[$depth];
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
}
if ($args['write']) {
    $out = $args['out'];
    skbWriteSet($out, SLUG, $byDepth, DESCRIPTION, TAGS, (bool) $args['jsonl']);
    skbWriteSet($out, SLUG . '-anim', $animByDepth, 'Animated ' . DESCRIPTION . ' Screen powers on, the beam traces each letter in stroke order with a white-hot head, then the traces sweep in; ends on the static frame. Play with tools/logo-play.php at 110 ms (~2.7 s).', [...TAGS, 'animated', 'beam-trace-reveal'], (bool) $args['jsonl']);
}
if ($args['png']) {
    @mkdir($args['png'], 0777, true);
    foreach ($byDepth as $depth => $ansi) {
        $tmp = "{$args['png']}/" . SLUG . "-$depth.ansi";
        file_put_contents($tmp, $ansi);
        skbPreview($tmp, "{$args['png']}/" . SLUG . "-$depth.png", 'block-braille');
    }
    fwrite(STDERR, "previews in {$args['png']}\n");
}
