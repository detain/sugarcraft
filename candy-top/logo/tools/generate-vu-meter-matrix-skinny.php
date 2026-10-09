<?php

declare(strict_types=1);

/**
 * generate-vu-meter-matrix-skinny — the ≤40-col rendition of
 * generate-vu-meter-matrix.php: same LED-segment (`▆`) letters, heat vs
 * bubbly colour sets, braille cpu area graph, peak ticks, bubbles and
 * %-axis, but with CANDY stacked over TOP in a condensed 5×5 segment font
 * so each word keeps fat 2-cell stems instead of turning to mush.
 *
 * Layout (39×12): row 0 peaks/bubbles over CANDY · rows 1-5 CANDY ·
 * row 6 peaks/bubbles over TOP · rows 7-11 TOP. The graph spans rows 1-11
 * (avg ~43% → it mostly fills the TOP band, its spikes reach into CANDY and
 * it rolls out on both sides of the short TOP word), tapering past both ends.
 *
 * Usage:
 *   php generate-vu-meter-matrix-skinny.php            preview tc, 256, 16 (+ verify)
 *   php generate-vu-meter-matrix-skinny.php --write    write .ansi (+anim) + logos.jsonl via skWriteSkinny
 *   php generate-vu-meter-matrix-skinny.php --anim     also play the tc animation
 */

require_once __DIR__ . '/skinny-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'vu-meter-matrix-skinny';
/** Stacked words, top → bottom. */
const WORDS = ['CANDY', 'TOP'];

/** 5×5 condensed LED font, 2-cell stems where the width allows. */
const FONT = [
    'C' => ['.####', '##...', '##...', '##...', '.####'],
    'A' => ['.###.', '##.##', '#####', '##.##', '##.##'],
    'N' => ['##..#', '###.#', '#####', '#.###', '#..##'],
    'D' => ['####.', '##.##', '##.##', '##.##', '####.'],
    'Y' => ['##.##', '##.##', '.###.', '.##..', '.##..'],
    'T' => ['######', '..##..', '..##..', '..##..', '..##..'],   // 6 wide: centred 2-cell stem
    'O' => ['.###.', '##.##', '##.##', '##.##', '.###.'],
    'P' => ['####.', '##.##', '####.', '##...', '##...'],
];

/** Colour set per word → letter: C·TO heat (top → bottom), ANDY·P bubbly (left → right). */
const LETTER_SET = [
    ['heat', 'bubble', 'bubble', 'bubble', 'bubble'],
    ['heat', 'heat', 'bubble'],
];

const HEAT = ['#FF2D55', '#FF5A36', '#FF9A1F', '#F7DA1A', '#B6EC2C', '#5BEA4F', '#00E676'];
const BUBBLE = ['#FF4FD0', '#F06BFF', '#C07DFF', '#8E9BFF', '#5FCBFF', '#4FEBE0', '#6BFFB8'];
const BUBBLE_GLOSS = 0.16;
const BUBBLE16 = [95, 95, 95, 94, 96, 96, 96];
const HEAT16 = [91, 91, 33, 93, 92, 92, 32];   // per HEAT stop

/** Graph: surface (bright edge) and interior colours, sampled per graph row. */
const GRAPH_TOP = ['#9AF8FF', '#86E2FF', '#8CB8FF', '#A09CFF', '#B488FF', '#C07CF4', '#BC72E6', '#AE66D8'];
const GRAPH_IN = ['#0087AF', '#0087AF', '#005FAF', '#5F5FAF', '#5F00AF', '#5F00AF', '#5F0087', '#5F0087'];
const GRAPH_MEAN = 0.43;
const GRAPH_SEED = 11;
/** Interior graph cells inside a word's box are recessed: checker dots, darker hue. */
const RECESS_BITS = 0x95;
const RECESS = '#5F0087';
const GRAPH_EXT = 3;
const GRAPH_TAPER = 4.0;
const GRAPH_TAPER_MIN = 0.15;

const AXIS = '#6A6F8C';
const PEAK = '#FFFFFF';
const BUBBLES = ['°', '∘', '○', '○', '°', '∘'];
const BUBBLE_COLS = ['#FF9BE0', '#C9A6FF', '#9EE7FF', '#FFC6F0'];

const SEG = '▆';
const AXIS_W = 4;
const ANIM_FRAMES_PER_COL = 0.7;
const ANIM_RISE = 6.0;
const ANIM_WORD_DELAY = 3.0;     // TOP starts a little after CANDY

const DESCRIPTION = 'Skinny vu-meter-matrix: CANDY stacked over TOP as LED meter segments (condensed 5x5 font) over a braille cpu-graph area chart that tapers past both ends; C and TO in btop cpu heat colours top to bottom, ANDY and P in a bubbly pink-violet-sky-mint fade left to right, with peak-hold ticks, bubbles and a %-axis gutter.';
const TAGS = ['skinny', 'vu-meter', 'led-segments', 'stacked-words', 'heat-gradient', 'bubblegum-fade', 'braille-area-graph', 'system-monitor'];

// ================================================================ BUILD

/**
 * Lay out every word, centred in the widest word's span.
 * @return list<array{mask:list<string>,ranges:array<int,array{int,int}>,x0:int}>
 */
function vuWords(): array
{
    $words = [];
    foreach (WORDS as $wd) {
        [$mask, $ranges] = lkLayout(FONT, $wd, letterGap: 1, wordGap: 3, margin: 0);
        $words[] = ['mask' => $mask, 'ranges' => $ranges];
    }
    $mw = max(array_map(static fn (array $w): int => strlen($w['mask'][0]), $words));
    foreach ($words as &$w) {
        $w['x0'] = intdiv($mw - strlen($w['mask'][0]), 2);
    }
    return $words;
}

/** Letter index owning column x, or null for gaps. */
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
        return $c;
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

/** Back-out eased rise progress at frame $t for a column whose start is $start frames. */
function vuRise(?float $t, float $start): array
{
    if ($t === null) {
        return [1.0, 1.0];
    }
    $p = max(0.0, min(1.0, ($t - $start) / ANIM_RISE));
    $c1 = 1.9;
    return [$p, 1 + ($c1 + 1) * ($p - 1) ** 3 + $c1 * ($p - 1) ** 2];
}

/** Sample a per-row colour list for graph row $gy of $gRows. */
function vuGraphRowColour(array $list, int $gy, int $gRows): array
{
    return lkHex($list[(int) round($gy * (count($list) - 1) / max(1, $gRows - 1))]);
}

function vuCells(string $depth, ?float $t = null): array
{
    $words = vuWords();
    $fh = count(FONT['C']);
    $mw = max(array_map(static fn (array $w): int => strlen($w['mask'][0]), $words));
    $gw = $mw + 2 * GRAPH_EXT;
    $mx0 = AXIS_W + GRAPH_EXT;
    $w = AXIS_W + $gw;
    $h = count($words) * ($fh + 1);                // per word: peak row + letters
    $gRows = $h - 1;                                // graph: everything below the first peak row
    $gDots = $gRows * 4;
    $cells = lkBlankCells($w, $h);
    $axis = lkHex(AXIS);
    $graph = vuGraph($gw * 2, $gDots);
    $gloss = $depth === 'tc';
    // word boxes (cell coords) where the graph interior is recessed for legibility
    $boxes = [];
    foreach ($words as $wi => $word) {
        $boxes[] = [$mx0 + $word['x0'] - 1, $wi * ($fh + 1) + 1, $mx0 + $word['x0'] + strlen($word['mask'][0]), ($wi + 1) * ($fh + 1)];
    }
    $inBox = static function (int $x, int $y) use ($boxes): bool {
        foreach ($boxes as [$x0, $y0, $x1, $y1]) {
            if ($x >= $x0 && $x <= $x1 && $y >= $y0 && $y <= $y1) {
                return true;
            }
        }
        return false;
    };

    for ($y = 0; $y < $gRows; $y++) {
        $label = match ($y) {
            0 => '100┤',
            intdiv($gRows, 2) => ' 50┤',
            $gRows - 1 => '  0└',
            default => str_repeat(' ', AXIS_W - 1) . '│',
        };
        lkText($cells, 0, $y + 1, $label, $axis);
    }

    for ($gx = 0; $gx < $gw; $gx++) {
        [$p] = vuRise($t, ($gx - GRAPH_EXT) * ANIM_FRAMES_PER_COL);
        $k = vuEnvelope($gx, $gw) * min(1.0, $p * 1.15);
        $gl = (int) round($graph[2 * $gx] * $k);
        $gr = (int) round($graph[2 * $gx + 1] * $k);
        for ($gy = 0; $gy < $gRows; $gy++) {
            $base = ($gRows - 1 - $gy) * 4;
            $l = max(0, $gl - $base);
            $r = max(0, $gr - $base);
            if ($l === 0 && $r === 0) {
                continue;
            }
            $surface = $l < 4 || $r < 4 || max($gl, $gr) - $base <= 4;
            $c = vuGraphRowColour($surface ? GRAPH_TOP : GRAPH_IN, $gy, $gRows);
            $glyph = vuBrailleFill($l, $r);
            if (!$surface && $inBox(AXIS_W + $gx, $gy + 1)) {
                $glyph = mb_chr(0x2800 + ((mb_ord($glyph) - 0x2800) & RECESS_BITS));
                $c = lkHex(RECESS);
            }
            lkPut($cells, AXIS_W + $gx, $gy + 1, $glyph, $c);
        }
    }

    foreach ($words as $wi => $word) {
        $mask = $word['mask'];
        $ranges = $word['ranges'];
        $y0 = $wi * ($fh + 1);                      // this word's peak row
        $wordW = strlen($mask[0]);
        // bubbly runs: consecutive bubble letters share one left→right fade
        $runs = [];
        $prev = null;
        foreach ($ranges as $i => [$a, $b]) {
            $set = LETTER_SET[$wi][$i] ?? 'heat';
            if ($set === 'bubble' && $prev !== null && $prev + 1 === $i && $runs !== []) {
                $runs[array_key_last($runs)][1] = $b - 1;
            } elseif ($set === 'bubble') {
                $runs[] = [$a, $b - 1];
            }
            $prev = $set === 'bubble' ? $i : null;
        }
        for ($x = 0; $x < $wordW; $x++) {
            $cx = $mx0 + $word['x0'] + $x;
            [$p, $e] = vuRise($t, ($word['x0'] + $x) * ANIM_FRAMES_PER_COL + $wi * ANIM_WORD_DELAY);
            $level = $fh * $e;
            $settled = $p >= 1.0;
            $top = null;
            for ($y = 0; $y < $fh; $y++) {
                if ($mask[$y][$x] === '#') {
                    $top ??= $y;
                }
            }
            $li = vuLetterAt($ranges, $x);
            $set = $li === null ? 'heat' : (LETTER_SET[$wi][$li] ?? 'heat');
            $xFrac = 0.0;
            foreach ($runs as [$ra, $rb]) {
                if ($x >= $ra && $x <= $rb) {
                    $xFrac = ($x - $ra) / max(1, $rb - $ra);
                }
            }
            for ($y = 0; $y < $fh; $y++) {
                $fromBottom = $fh - 1 - $y;
                if ($mask[$y][$x] === '#' && $fromBottom < $level) {
                    $c = vuLetterColour($set, $y, $fh, $xFrac, $gloss);
                    if ($t !== null && $level < $fh && $fromBottom + 1 >= $level) {
                        $c = lkShade($c, 0.35);
                    }
                    lkPut($cells, $cx, $y0 + 1 + $y, SEG, $c);
                }
            }
            if ($t !== null && $top !== null && !$settled && $level > $fh - $top + 0.35) {
                lkPut($cells, $cx, $y0 + $top, SEG, vuLetterColour($set, 0, $fh, $xFrac, $gloss));
            }
            if ($top === 0 && ($t === null || $settled)) {
                if ($set === 'heat') {
                    lkPut($cells, $cx, $y0, '▁', lkHex(PEAK));
                } elseif (lkNoise($cx, 9 + $wi, GRAPH_SEED) > 0.35) {
                    $k = (int) floor(lkNoise($cx, 7, GRAPH_SEED) * count(BUBBLES));
                    lkPut($cells, $cx, $y0, BUBBLES[$k], lkHex(BUBBLE_COLS[$cx % count(BUBBLE_COLS)]));
                }
            }
        }
    }
    return $cells;
}

/** 16-colour pins built from the data (bubble fade sampled per x bucket). */
function vuPins16(): array
{
    $pins = [AXIS => 90, PEAK => 97];
    $fh = count(FONT['C']);
    for ($y = 0; $y < $fh; $y++) {
        $pins[implode(',', vuLetterColour('heat', $y, $fh, 0.0))] = HEAT16[(int) round($y * (count(HEAT) - 1) / ($fh - 1))];
        for ($k = 0; $k <= 200; $k++) {
            $f = $k / 200;
            $c = vuLetterColour('bubble', $y, $fh, $f, false);
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
    $pins[RECESS] = 34;
    foreach (BUBBLE_COLS as $hex) {
        $pins[$hex] = 95;
    }
    return $pins;
}

function vuFrameCount(): int
{
    $mw = max(array_map(static fn (array $w): int => strlen($w['mask'][0]), vuWords()));
    return (int) ceil(($mw + GRAPH_EXT) * ANIM_FRAMES_PER_COL + ANIM_WORD_DELAY + ANIM_RISE) + 1;
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
        $r = skWriteSkinny($dir, SLUG, $depth, $static, DESCRIPTION . " ($depth)", TAGS);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        $r = skWriteSkinny($dir, 'vu-meter-matrix-skinny-anim', $depth, $anim,
            'Animated ' . DESCRIPTION . ' Boot-up: graph and letter columns rise left to right (CANDY then TOP) with overshoot, peaks and bubbles drop in; ' . count($frames) . " frames, play with tools/logo-play.php <file> 75 (~2.5s) ($depth)",
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
