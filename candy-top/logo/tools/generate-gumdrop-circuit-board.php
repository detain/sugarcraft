<?php

declare(strict_types=1);

/**
 * generate-gumdrop-circuit-board — "CANDY TOP" etched as heavy box-drawing
 * copper traces on a green solder-mask PCB, every trace end a via that glows
 * as a candy-coloured gumdrop LED, thin drop traces wired into gold
 * edge-connector fingers (the logo is an expansion card).
 *
 * Reusable pieces (swap the data, keep the functions):
 *   FONT        box-drawing glyph strings per letter (any width, same height);
 *               VIA_GLYPHS mark which cells are vias (become LEDs).
 *   PALETTE     board / copper / LED / silkscreen / finger colours.
 *   board()     rasterises everything for a given "power front" column
 *               (null = fully powered static frame) → cell grid.
 *   Colour roles register 16-colour pins as they are painted ($PINS), so the
 *   16-colour version keeps copper = dark yellow, LEDs distinct, etc.
 *
 * Usage:
 *   php generate-gumdrop-circuit-board.php            preview tc/256/16 + verify
 *   php generate-gumdrop-circuit-board.php --write    write .ansi + logos.jsonl
 *   php logo-play.php ../logo-gumdrop-circuit-board-anim-tc-72x9.ansi 150
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'gumdrop-circuit-board';
const TEXT = 'CANDY TOP';
const LETTER_GAP = 2;
const WORD_GAP = 4;
const MARGIN_X = 3;          // board cells left/right of the lettering
const LETTER_TOP = 1;        // board row where letters start

/** Trace font: heavy box drawing, 45° chamfers (╱╲), ● = via. */
const FONT = [
    'C' => ['╱━━━━●', '┃     ', '┃     ', '┃     ', '╲━━━━●'],
    'A' => ['╱━━━━╲', '┃    ┃', '┣━━━━┫', '┃    ┃', '●    ●'],
    'N' => ['●╲   ●', '┃ ╲  ┃', '┃  ╲ ┃', '┃   ╲┃', '●    ●'],
    'D' => ['┏━━━━╲', '┃    ┃', '┃    ┃', '┃    ┃', '┗━━━━╱'],
    'Y' => ['●     ●', '┃     ┃', '╲━━┳━━╱', '   ┃   ', '   ●   '],
    'T' => ['●━━┳━━●', '   ┃   ', '   ┃   ', '   ┃   ', '   ●   '],
    'O' => ['╱━━━━╲', '┃    ┃', '┃    ┃', '┃    ┃', '╲━━━━╱'],
    'P' => ['┏━━━━╲', '┃    ┃', '┣━━━━╱', '┃     ', '●     '],
];
const VIA_GLYPHS = ['●'];

const PALETTE = [
    'boardTop' => '#0E3B22', 'boardBot' => '#06200F', 'sheen' => 0.06,
    'dot' => '#1F5A36',
    'copper' => ['#C8743A', '#E89B5A', '#F5C542', '#E89B5A', '#C8743A'],
    'copperOff' => '#5A3A22', 'drop' => '#9A5A2C', 'dropOff' => '#3E2A1A',
    'leds' => ['#FF3B5C', '#FF8C2A', '#FFE14D', '#7CFF4F', '#3DA5FF', '#B04DFF'],
    'ledOff' => '#2A2A2A', 'flash' => '#FFFFFF', 'glow' => 0.30,
    'silk' => '#D8E8D0', 'hole' => '#9FB8A8',
    'finger' => '#FFD15C', 'fingerAlt' => '#B8902F', 'fingerBg' => '#06200F',
];

/** 16-colour role pins (fg codes). */
const ROLE16 = [
    'copper' => 33, 'copperHi' => 93, 'copperOff' => 90, 'drop' => 33, 'dropOff' => 90,
    'board' => 30, 'dot' => 32, 'silk' => 97, 'hole' => 37, 'finger' => 93, 'fingerAlt' => 33,
    'ledOff' => 90, 'flash' => 97,
    'led' => [91, 31, 93, 92, 94, 95],
];

/** Silkscreen labels: [x, y, text]; negative x = from the right edge. */
const SILK = [
    [2, 0, '◯'], [-3, 0, '◯'],
    [5, 0, '+5V'], [-10, 0, 'GND ⊥'],
    [2, 7, 'U1 CANDY-TOP'], [-2, 7, 'REV 1.0 ▪▪▪'],
];
const FINGER_SPAN = [16, 56];   // [x0, x1) columns of gold fingers
const FINGER_KEY = 41;          // 2-col notch (keyed edge, like PCIe)

const DESCRIPTION = 'CANDY TOP etched as heavy box-drawing copper traces with 45° chamfers on a green solder-mask PCB; every trace end is a glowing candy-coloured gumdrop LED via, thin drop traces wire the letters into gold edge-connector fingers, silkscreen labels and a ground-plane dot texture.';
const TAGS = ['circuit-board', 'box-drawing-traces', 'gumdrop-leds', 'copper-gradient', 'edge-connector', 'silkscreen', 'via-glow'];
const ANIM_FRAMES = 14;

// ================================================================ BUILD

$PINS = [];
$DEPTH = 'tc';

/**
 * Per-depth board overrides: xterm-256 has no dark green below #005f00 and
 * redmean-nearest turns the solder mask grey, so 256 uses the cube's own
 * greens; 16 pins the board (and LED glow) to black so the green dots read.
 */
const BOARD256 = [0, 95, 0];
const DOT256 = [0, 135, 0];

function pin(array $c, string $role, int $i = 0): array
{
    global $PINS;
    $code = ROLE16[$role];
    $PINS[implode(',', $c)] = is_array($code) ? $code[$i % count($code)] : $code;
    return $c;
}

/** Lay out the trace font → [rows of glyph lists, width]. */
function traceLayout(): array
{
    $h = count(FONT['C']);
    $rows = array_fill(0, $h, []);
    $chars = str_split(TEXT);
    foreach ($chars as $i => $ch) {
        if ($ch === ' ') {
            for ($y = 0; $y < $h; $y++) {
                array_push($rows[$y], ...array_fill(0, WORD_GAP, ' '));
            }
            continue;
        }
        for ($y = 0; $y < $h; $y++) {
            array_push($rows[$y], ...mb_str_split(FONT[$ch][$y]));
        }
        if (($chars[$i + 1] ?? ' ') !== ' ') {
            for ($y = 0; $y < $h; $y++) {
                array_push($rows[$y], ...array_fill(0, LETTER_GAP, ' '));
            }
        }
    }
    return [$rows, count($rows[0])];
}

/** Letter column ranges [x0, x1) in trace coordinates (for keeping texture out of letters). */
function letterRanges(): array
{
    $ranges = [];
    $x = 0;
    $chars = str_split(TEXT);
    foreach ($chars as $i => $ch) {
        if ($ch === ' ') {
            $x += WORD_GAP;
            continue;
        }
        $lw = mb_strlen(FONT[$ch][0]);
        $ranges[] = [$x, $x + $lw];
        $x += $lw + ((($chars[$i + 1] ?? ' ') !== ' ') ? LETTER_GAP : 0);
    }
    return $ranges;
}

function inLetter(int $x, int $y, int $h): bool
{
    if ($y < LETTER_TOP || $y >= LETTER_TOP + $h) {
        return false;
    }
    foreach (letterRanges() as [$a, $b]) {
        if ($x - MARGIN_X >= $a && $x - MARGIN_X < $b) {
            return true;
        }
    }
    return false;
}

function boardBg(int $x, int $y, int $w, int $h): array
{
    global $DEPTH;
    if ($DEPTH === '256') {
        return BOARD256;
    }
    $c = lkGradient([lkHex(PALETTE['boardTop']), lkHex(PALETTE['boardBot'])], $y / max(1, $h - 1));
    $band = abs((($x * 0.5 + $y * 1.0) - $w * 0.30) / 4.0);   // diagonal sheen band
    return pin($band < 1.0 ? lkShade($c, PALETTE['sheen'] * (1 - $band)) : $c, 'board');
}

/**
 * Whole card as a cell grid. $front = power front column (cells left of it
 * are powered); null = fully powered (the static logo).
 */
function board(?float $front = null): array
{
    [$trace, $tw] = traceLayout();
    $w = $tw + 2 * MARGIN_X;
    $h = LETTER_TOP + count($trace) + 3;           // letters + drop row + silk row + fingers
    $fingerRow = $h - 1;
    $cells = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $bg = boardBg($x, $y, $w, $h);
            $glyph = ' ';
            $fg = null;
            if ($y === $fingerRow) {
                $in = $x >= FINGER_SPAN[0] && $x < FINGER_SPAN[1] && $x !== FINGER_KEY && $x !== FINGER_KEY + 1;
                $bg = $in ? lkHex(PALETTE['fingerBg']) : null;
                if ($in) {
                    $glyph = '▌';
                    $fg = $x % 2 ? pin(lkHex(PALETTE['fingerAlt']), 'fingerAlt') : pin(lkHex(PALETTE['finger']), 'finger');
                }
            } elseif ($x % 2 === 0 && $y % 2 === 1 && !inLetter($x, $y, count($trace))) {
                $glyph = '·';
                $fg = pin($GLOBALS['DEPTH'] === '256' ? DOT256 : lkHex(PALETTE['dot']), 'dot');
            }
            $cells[$y][$x] = [$glyph, $fg, $bg];
        }
    }
    // silkscreen
    foreach (SILK as [$sx, $sy, $text]) {
        $x0 = $sx < 0 ? $w + $sx - mb_strlen($text) + 1 : $sx;
        $role = $text === '◯' ? 'hole' : 'silk';
        foreach (mb_str_split($text) as $i => $g) {
            $cells[$sy][$x0 + $i][0] = $g;
            $cells[$sy][$x0 + $i][1] = pin(lkHex(PALETTE[$role]), $role);
        }
    }
    $powered = static fn (int $x): bool => $front === null || $x < $front;
    // traces + vias
    $via = 0;
    $bottomVias = [];
    foreach ($trace as $ty => $row) {
        $y = LETTER_TOP + $ty;
        foreach ($row as $tx => $g) {
            if ($g === ' ') {
                continue;
            }
            $x = $tx + MARGIN_X;
            if (in_array($g, VIA_GLYPHS, true)) {
                $i = $via++;
                if ($ty === count($trace) - 1) {
                    $bottomVias[] = $x;
                }
                if (!$powered($x)) {
                    $cells[$y][$x] = ['●', pin(lkHex(PALETTE['ledOff']), 'ledOff'), $cells[$y][$x][2]];
                    continue;
                }
                $led = lkHex(PALETTE['leds'][$i % count(PALETTE['leds'])]);
                $flash = $front !== null && $x >= $front - 3;
                $fg = $flash ? pin(lkHex(PALETTE['flash']), 'flash') : pin($led, 'led', $i);
                $cells[$y][$x] = ['●', $fg, pin(lkMix($cells[$y][$x][2], $led, PALETTE['glow']), 'board')];
                continue;
            }
            if ($powered($x)) {
                $t = $x / ($w - 1);
                $c = lkGradient(array_map('lkHex', PALETTE['copper']), $t);
                $c = lkShade($c, 0.12 - 0.07 * $ty);           // lit upper strokes
                $fg = pin($c, $t > 0.35 && $t < 0.65 ? 'copperHi' : 'copper');
            } else {
                $fg = pin(lkHex(PALETTE['copperOff']), 'copperOff');
            }
            $cells[$y][$x] = [$g, $fg, $cells[$y][$x][2]];
        }
    }
    // drop traces: bottom vias above the finger span run down into the fingers
    foreach ($bottomVias as $x) {
        if ($x < FINGER_SPAN[0] || $x >= FINGER_SPAN[1] || abs($x - FINGER_KEY - 0.5) < 1) {
            continue;
        }
        for ($y = LETTER_TOP + count($trace); $y < $fingerRow; $y++) {
            $fg = $powered($x) ? pin(lkHex(PALETTE['drop']), 'drop') : pin(lkHex(PALETTE['dropOff']), 'dropOff');
            $cells[$y][$x] = ['│', $fg, $cells[$y][$x][2]];
        }
    }
    return $cells;
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$dir = dirname(__DIR__);
foreach (['tc', '256', '16'] as $depth) {
    $DEPTH = $depth;
    $static = board();
    $w = count($static[0]);
    $frames = [];
    for ($f = 0; $f < ANIM_FRAMES; $f++) {
        $frames[] = board(-2 + ($w + 6) * $f / (ANIM_FRAMES - 1));
    }
    $enc = lkEncode($static, $depth, $GLOBALS['PINS']);
    [$ww, $hh] = lkVerify($enc, $depth);
    lkCheckDepth($enc, $depth, $depth);
    echo $enc;
    fwrite(STDERR, "[$depth] {$ww}x{$hh} ok\n");
    $anim = lkAnim(array_map(static fn (array $c): string => lkEncode($c, $depth, $GLOBALS['PINS']), $frames), $enc);
    if ($write) {
        $r = lkWriteLogo($dir, SLUG, $depth, $enc, DESCRIPTION . " ($depth)", TAGS);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        $r = lkWriteLogo($dir, SLUG . '-anim', $depth, $anim, 'Animated power-on: ' . DESCRIPTION . " Current runs left to right lighting the copper and flashing each gumdrop LED on; ends on the static frame. Play with tools/logo-play.php <file> 150 (~2.2 s). ($depth)", [...TAGS, 'animated', 'power-on-sweep']);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
}
