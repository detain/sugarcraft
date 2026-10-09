<?php

declare(strict_types=1);

/**
 * generate-vu-meter-matrix — "CANDY TOP" lit on an LED VU-meter / equalizer
 * panel, the instrument a system monitor would draw.
 *
 * Letter cells are `▆` segments (the empty top quarter of the glyph is the
 * dark seam between LED rows). Each letter picks a colour set: C·TO use
 * btop's cpu heat ramp (green base → candy red top), ANDY·P a bubbly
 * bubblegum → lilac → sky → mint fade that also drifts sideways. Behind
 * them a big braille cpu-graph area chart (avg ~44% height, spikes and
 * dips; bright cyan→violet surface, deeper interior) fills the panel.
 * Heat letters get white peak-hold ticks, bubbly ones floating bubbles,
 * and a 100/50/0 %-axis gutter sits left.
 *
 * The animation "boots" the meter: graph and letter columns rise
 * left→right with a back-out overshoot, then peaks and bubbles drop in.
 *
 * Usage:
 *   php generate-vu-meter-matrix.php            preview tc, 256, 16 (+ verify)
 *   php generate-vu-meter-matrix.php --write    write .ansi files + logos.jsonl
 *   php generate-vu-meter-matrix.php --anim     also play the tc animation
 *
 * Reuse: swap FONT / LETTER_SET / HEAT / BUBBLE / GRAPH_* / GRAPH_SEED; the build is generic over
 * any mask height (the axis labels and heat ramp are sampled per row).
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'vu-meter-matrix';
const TEXT = 'CANDY TOP';

/** 6×7 LED font, 2-cell stems so letters read as solid meter bars. */
const FONT = [
    'C' => ['.#####', '##....', '##....', '##....', '##....', '##....', '.#####'],
    'A' => ['.####.', '##..##', '##..##', '######', '##..##', '##..##', '##..##'],
    'N' => ['##..##', '###.##', '######', '##.###', '##..##', '##..##', '##..##'],
    'D' => ['#####.', '##..##', '##..##', '##..##', '##..##', '##..##', '#####.'],
    'Y' => ['##..##', '##..##', '##..##', '.####.', '..##..', '..##..', '..##..'],
    'T' => ['######', '..##..', '..##..', '..##..', '..##..', '..##..', '..##..'],
    'O' => ['.####.', '##..##', '##..##', '##..##', '##..##', '##..##', '.####.'],
    'P' => ['#####.', '##..##', '##..##', '#####.', '##....', '##....', '##....'],
];

/**
 * Colour set per letter index of TEXT (spaces count): 'heat' = btop cpu
 * ramp (runs top → bottom), 'bubble' = playful bubblegum ramp (runs left →
 * right across each bubbly run). C·TO heat, ANDY·P bubbly.
 */
const LETTER_SET = [0 => 'heat', 1 => 'bubble', 2 => 'bubble', 3 => 'bubble', 4 => 'bubble',
    6 => 'heat', 7 => 'heat', 8 => 'bubble'];

/** heat: top row → bottom row. */
const HEAT = ['#FF2D55', '#FF5A36', '#FF9A1F', '#F7DA1A', '#B6EC2C', '#5BEA4F', '#00E676'];
/** bubble: left → right across a run (ANDY, then P on its own). */
const BUBBLE = ['#FF4FD0', '#F06BFF', '#C07DFF', '#8E9BFF', '#5FCBFF', '#4FEBE0', '#6BFFB8'];
const BUBBLE_GLOSS = 0.16;       // bubbly letters: lighter top row → slightly deeper base
const BUBBLE16 = [95, 95, 95, 94, 96, 96, 96];   // per BUBBLE stop

/** Background cpu graph (braille area chart behind the letters). */
const GRAPH_TOP = ['#9AF8FF', '#86E2FF', '#8CB8FF', '#A09CFF', '#B488FF', '#C07CF4', '#BC72E6', '#AE66D8'];
/** Interior (below-surface) dot colours per row; xterm-cube exact so 256 stays hued. */
const GRAPH_IN = ['#0087AF', '#0087AF', '#005FAF', '#5F5FAF', '#5F00AF', '#5F00AF', '#5F0087', '#5F0087'];
const GRAPH_MEAN = 0.43;         // average fill (fraction of graph height)
const GRAPH_SEED = 11;
const GRAPH_EXT = 6;             // graph columns past the letters on each side
const GRAPH_TAPER = 7.0;         // columns over which the ends ease down
const GRAPH_TAPER_MIN = 0.12;    // height factor at the very ends

const AXIS = '#6A6F8C';
const PEAK = '#FFFFFF';
const BUBBLES = ['°', '∘', '○', '○', '°', '∘'];
const BUBBLE_COLS = ['#FF9BE0', '#C9A6FF', '#9EE7FF', '#FFC6F0'];

const SEG = '▆';
const HEAT16 = [91, 91, 33, 93, 92, 92, 32];   // per HEAT row

const AXIS_W = 4;                // "100┤"
const ANIM_FRAMES_PER_COL = 0.45;
const ANIM_RISE = 7.0;

const DESCRIPTION = 'CANDY TOP lit as LED meter segments over a big braille cpu-graph area chart (peaks and valleys, bright cyan-to-violet edge, tapering past both ends): C and TO in btop cpu heat colours top to bottom, ANDY and P in a bubbly pink-violet-sky-mint fade left to right, with peak-hold ticks, floating bubbles and a %-axis gutter.';
const TAGS = ['vu-meter', 'led-segments', 'heat-gradient', 'bubblegum-fade', 'braille-area-graph', 'system-monitor', 'peak-hold', 'bubbles'];

// ================================================================ BUILD

/** @return array{0:list<string>,1:array<int,array{int,int}>} */
function vuMask(): array
{
    return lkLayout(FONT, TEXT, letterGap: 1, wordGap: 3, margin: 0);
}

/** Letter index owning matrix column x, or null for gaps. */
function vuLetterAt(array $ranges, int $x): ?int
{
    foreach ($ranges as $i => [$a, $b]) {
        if ($x >= $a && $x < $b) {
            return $i;
        }
    }
    return null;
}

/** Graph heights in dots (0..$dots) per dot column: spiky pseudo cpu trace. */
function vuGraph(int $n, int $dots): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $s = GRAPH_SEED;
        $v = GRAPH_MEAN
            + 0.22 * sin($i * 0.12 + $s)
            + 0.15 * sin($i * 0.31 + $s * 2.3)
            + 0.09 * sin($i * 0.83 + $s * 0.7)
            + 0.20 * (lkNoise(intdiv($i, 3), 1, $s) - 0.5)
            + (lkNoise(intdiv($i, 4), 2, $s) > 0.84 ? 0.30 : 0.0)       // load spikes
            - (lkNoise(intdiv($i, 4), 3, $s) > 0.88 ? 0.22 : 0.0);      // idle dips
        $out[] = max(2, min($dots - 1, (int) round($v * $dots)));
    }
    return $out;
}

/** Braille cell for two dot columns given fill heights relative to the cell's bottom (0..4). */
function vuBrailleFill(int $l, int $r): string
{
    $left = [0x40, 0x04, 0x02, 0x01];       // bottom → top
    $right = [0x80, 0x20, 0x10, 0x08];
    $bits = 0;
    for ($k = 0; $k < min(4, $l); $k++) {
        $bits |= $left[$k];
    }
    for ($k = 0; $k < min(4, $r); $k++) {
        $bits |= $right[$k];
    }
    return mb_chr(0x2800 + $bits);
}

/** Letter colour: heat ramps by row, bubble ramps by x across its run. */
function vuLetterColour(string $set, int $y, int $mh, float $xFrac, bool $gloss = true): array
{
    if ($set === 'heat') {
        return lkHex(HEAT[(int) round($y * (count(HEAT) - 1) / ($mh - 1))]);
    }
    $c = lkGradient(BUBBLE, $xFrac);
    if (!$gloss) {
        return $c;                                  // 256/16: per-row gloss only adds banding
    }
    return lkShade($c, BUBBLE_GLOSS * (1 - 2 * $y / ($mh - 1)) * ($y < $mh / 2 ? 1 : 0.6));
}

/** Graph height envelope: eases the ends down so the chart trails off. */
function vuEnvelope(int $gx, int $gw): float
{
    $d = min($gx + 0.5, $gw - $gx - 0.5) / GRAPH_TAPER;
    if ($d >= 1) {
        return 1.0;
    }
    $sm = $d * $d * (3 - 2 * $d);
    return GRAPH_TAPER_MIN + (1 - GRAPH_TAPER_MIN) * $sm;
}

/** Back-out eased rise progress for matrix column $mx at frame $t. */
function vuRise(?float $t, float $mx): array
{
    if ($t === null) {
        return [1.0, 1.0];
    }
    $p = max(0.0, min(1.0, ($t - $mx * ANIM_FRAMES_PER_COL) / ANIM_RISE));
    $c1 = 1.9;
    return [$p, 1 + ($c1 + 1) * ($p - 1) ** 3 + $c1 * ($p - 1) ** 2];
}

/**
 * Build the cell grid. $t = null → static; otherwise animation time in
 * frames (columns + graph rise staggered; overshoot shows a transient segment).
 */
function vuCells(string $depth, ?float $t = null): array
{
    [$mask, $ranges] = vuMask();
    $mh = count($mask);
    $mw = strlen($mask[0]);
    $gw = $mw + 2 * GRAPH_EXT;                     // graph columns
    $mx0 = AXIS_W + GRAPH_EXT;                     // first matrix column
    $w = AXIS_W + $gw;
    $h = $mh + 2;                                   // peak row + matrix + base row
    $gRows = $mh + 1;                               // graph spans matrix + base row
    $gDots = $gRows * 4;
    $cells = lkBlankCells($w, $h);
    $axis = lkHex(AXIS);
    $graph = vuGraph($gw * 2, $gDots);

    // axis gutter
    for ($y = 0; $y < $gRows; $y++) {
        $label = match ($y) {
            0 => '100┤',
            intdiv($gRows, 2) => ' 50┤',
            $gRows - 1 => '  0└',
            default => str_repeat(' ', AXIS_W - 1) . '│',
        };
        lkText($cells, 0, $y + 1, $label, $axis);
    }

    // background graph (letters overwrite it)
    for ($gx = 0; $gx < $gw; $gx++) {
        [$p] = vuRise($t, $gx - GRAPH_EXT);
        $k = vuEnvelope($gx, $gw) * min(1.0, $p * 1.15);
        $gl = (int) round($graph[2 * $gx] * $k);
        $gr = (int) round($graph[2 * $gx + 1] * $k);
        for ($gy = 0; $gy < $gRows; $gy++) {
            $base = ($gRows - 1 - $gy) * 4;          // dots below this cell
            $l = max(0, $gl - $base);
            $r = max(0, $gr - $base);
            if ($l === 0 && $r === 0) {
                continue;
            }
            $surface = $l < 4 || $r < 4 || max($gl, $gr) - $base <= 4;
            $c = lkHex($surface ? GRAPH_TOP[$gy] : GRAPH_IN[$gy]);
            lkPut($cells, AXIS_W + $gx, $gy + 1, vuBrailleFill($l, $r), $c);
        }
    }

    // bubbly runs: consecutive bubble letters within a word share one fade
    $runs = [];
    $prev = null;
    foreach ($ranges as $i => [$a, $b]) {
        $set = LETTER_SET[$i] ?? 'heat';
        if ($set === 'bubble' && $prev !== null && $prev + 1 === $i && $runs !== []) {
            $runs[array_key_last($runs)][1] = $b - 1;
        } elseif ($set === 'bubble') {
            $runs[] = [$a, $b - 1];
        }
        $prev = $set === 'bubble' ? $i : null;
    }

    for ($x = 0; $x < $mw; $x++) {
        [$p, $e] = vuRise($t, $x);
        $level = $mh * $e;
        $settled = $p >= 1.0;
        $top = null;
        for ($y = 0; $y < $mh; $y++) {
            if ($mask[$y][$x] === '#') {
                $top ??= $y;
            }
        }
        $li = vuLetterAt($ranges, $x);
        $set = $li === null ? 'heat' : (LETTER_SET[$li] ?? 'heat');
        $xFrac = 0.0;
        foreach ($runs as [$ra, $rb]) {
            if ($x >= $ra && $x <= $rb) {
                $xFrac = ($x - $ra) / max(1, $rb - $ra);
            }
        }
        for ($y = 0; $y < $mh; $y++) {
            $fromBottom = $mh - 1 - $y;
            if ($mask[$y][$x] === '#' && $fromBottom < $level) {
                $c = vuLetterColour($set, $y, $mh, $xFrac, $depth === 'tc');
                if ($t !== null && $level < $mh && $fromBottom + 1 >= $level) {
                    $c = lkShade($c, 0.35);               // rising head glint
                }
                lkPut($cells, $mx0 + $x, $y + 1, SEG, $c);
            }
        }
        if ($t !== null && $top !== null && !$settled && $level > $mh - $top + 0.35) {
            lkPut($cells, $mx0 + $x, $top, SEG, vuLetterColour($set, 0, $mh, $xFrac, $depth === 'tc'));
        }
        // peak row: heat letters get peak-hold ticks, bubbly letters bubbles
        if ($top === 0 && ($t === null || $settled)) {
            if ($set === 'heat') {
                lkPut($cells, $mx0 + $x, 0, '▁', lkHex(PEAK));
            } elseif (lkNoise($x, 9, GRAPH_SEED) > 0.35) {
                $k = (int) floor(lkNoise($x, 7, GRAPH_SEED) * count(BUBBLES));
                lkPut($cells, $mx0 + $x, 0, BUBBLES[$k], lkHex(BUBBLE_COLS[$x % count(BUBBLE_COLS)]));
            }
        }
    }
    return $cells;
}

/** 16-colour pins built from the data (bubble fade sampled per x bucket). */
function vuPins16(): array
{
    $pins = [];
    foreach (HEAT as $i => $hex) {
        $pins[$hex] = HEAT16[$i];
    }
    $pins[AXIS] = 90;
    $pins[PEAK] = 97;
    $mh = count(FONT['C']);
    for ($y = 0; $y < $mh; $y++) {
        for ($k = 0; $k <= 200; $k++) {
            $f = $k / 200;
            $c = vuLetterColour('bubble', $y, $mh, $f, false);
            $pins[implode(',', $c)] = BUBBLE16[(int) round($f * (count(BUBBLE16) - 1))];
            $pins[implode(',', lkShade($c, 0.35))] = 97;
        }
    }
    foreach (GRAPH_TOP as $i => $hex) {
        $pins[$hex] = $i < 2 ? 96 : 94;
    }
    foreach (GRAPH_IN as $hex) {
        $pins[$hex] = 34;
    }
    foreach (BUBBLE_COLS as $hex) {
        $pins[$hex] = 95;
    }
    return $pins;
}

function vuFrameCount(): int
{
    $mw = strlen(vuMask()[0][0]);
    return (int) ceil(($mw + GRAPH_EXT) * ANIM_FRAMES_PER_COL + ANIM_RISE) + 1;
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$play = in_array('--anim', $argv, true);
$dir = dirname(__DIR__);
foreach (['tc', '256', '16'] as $depth) {
    $pins = $depth === '16' ? vuPins16() : [];
    $static = lkEncode(vuCells($depth), $depth, $pins);
    [$w, $h] = lkVerify($static, $depth);
    lkCheckDepth($static, $depth, $depth);
    echo $static, "\n";
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    $frames = [];
    for ($f = 0, $n = vuFrameCount(); $f < $n; $f++) {
        $frames[] = lkEncode(vuCells($depth, (float) $f), $depth, $pins);
    }
    $anim = lkAnim($frames, $static);
    if ($write) {
        $r = lkWriteLogo($dir, SLUG, $depth, $static, DESCRIPTION . " ($depth)", TAGS);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        $r = lkWriteLogo($dir, SLUG . '-anim', $depth, $anim,
            'Animated ' . DESCRIPTION . ' Boot-up: graph and letter columns rise left to right with overshoot, peaks and bubbles drop in; ' . count($frames) . " frames, play with tools/logo-play.php <file> 75 (~2.4s) ($depth)",
            [...TAGS, 'animated', 'boot-up-rise']);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
    if ($play && $depth === 'tc') {
        $tmp = tempnam(sys_get_temp_dir(), 'vu');
        file_put_contents($tmp, $anim);
        passthru('php ' . escapeshellarg(__DIR__ . '/logo-play.php') . ' ' . escapeshellarg($tmp) . ' 75');
        unlink($tmp);
        echo "\n";
    }
}
