<?php

declare(strict_types=1);

/**
 * generate-gumdrop-circuit-board-skinny — the ≤40-col rendition of
 * gumdrop-circuit-board: CANDY stacked over TOP as heavy box-drawing copper
 * traces (45° chamfers ╱╲) on a green solder-mask PCB, every trace end a
 * glowing candy-coloured gumdrop LED via, drop traces into gold
 * edge-connector fingers, silkscreen + mounting holes + ground-plane dots.
 *
 * Differences from the full generator: a condensed 5x4 trace font (was 6x5),
 * multi-line layout (LINES = [text, x0, y0] in board cells), silkscreen and
 * drop traces repositioned around the stacked words, writer = skWriteSkinny
 * (≤40 cols, 5-15 rows). Colour depth handling is the same role-pin scheme:
 * 256 swaps the solder mask to the cube's #005f00 (redmean-nearest greys it
 * out), 16 pins board + LED glow to black, copper to dark yellow.
 *
 * Usage:
 *   php generate-gumdrop-circuit-board-skinny.php            preview + verify
 *   php generate-gumdrop-circuit-board-skinny.php --write    write .ansi + logos.jsonl
 *   php logo-play.php ../logo-gumdrop-circuit-board-skinny-anim-tc-39x11.ansi 150
 */

require_once __DIR__ . '/skinny-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'gumdrop-circuit-board-skinny';
const BOARD_W = 39;
const BOARD_H = 11;
const LETTER_GAP = 2;

/** Condensed trace font, 5 cols x 4 rows. ● = via (becomes an LED). */
const FONT = [
    'C' => ['╱━━━●', '┃    ', '┃    ', '╲━━━●'],
    'A' => ['╱━━━╲', '┣━━━┫', '┃   ┃', '●   ●'],
    'N' => ['●╲  ●', '┃ ╲ ┃', '┃  ╲┃', '●   ●'],
    'D' => ['┏━━━╲', '┃   ┃', '┃   ┃', '┗━━━╱'],
    'Y' => ['●   ●', '╲━┳━╱', '  ┃  ', '  ●  '],
    'T' => ['●━┳━●', '  ┃  ', '  ┃  ', '  ●  '],
    'O' => ['╱━━━╲', '┃   ┃', '┃   ┃', '╲━━━╱'],
    'P' => ['┏━━━╲', '┣━━━╱', '┃    ', '●    '],
];
const VIA_GLYPHS = ['●'];

/** Words on the board: [text, x0, y0] (board cells). */
const LINES = [
    ['CANDY', 3, 1],
    ['TOP', 10, 6],
];

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

const ROLE16 = [
    'board' => 30, 'copper' => 33, 'copperHi' => 93, 'copperOff' => 90, 'drop' => 33, 'dropOff' => 90,
    'dot' => 32, 'silk' => 97, 'hole' => 37, 'finger' => 93, 'fingerAlt' => 33,
    'ledOff' => 90, 'flash' => 97,
    'led' => [91, 31, 93, 92, 94, 95],
];
const BOARD256 = [0, 95, 0];
const DOT256 = [0, 135, 0];

/** Silkscreen: [x, y, text]; negative x = right-aligned to that edge offset. */
const SILK = [
    [1, 0, '◯'], [-2, 0, '◯'],
    [4, 0, '+5V'], [-5, 0, 'GND ⊥'],
    [1, 7, 'U1'], [-2, 7, 'REV'], [-2, 8, '1.0'],
];
/** Columns whose drop traces run from below a word down to the fingers. */
const DROPS = [[7, 5], [33, 5]];          // [x, firstRow]
const FINGER_SPAN = [4, 35];
const FINGER_KEY = 25;

const DESCRIPTION = 'Skinny gumdrop-circuit-board: CANDY stacked over TOP as condensed heavy box-drawing copper traces with 45° chamfers on a green solder-mask PCB; trace ends are glowing candy-coloured gumdrop LED vias, drop traces run into gold edge-connector fingers, silkscreen labels, mounting holes and ground-plane dots.';
const TAGS = ['skinny', 'circuit-board', 'box-drawing-traces', 'gumdrop-leds', 'copper-gradient', 'edge-connector', 'stacked-words'];
const ANIM_FRAMES = 12;

// ================================================================ BUILD

$PINS = [];
$DEPTH = 'tc';

function pin(array $c, string $role, int $i = 0): array
{
    global $PINS;
    $code = ROLE16[$role];
    $PINS[implode(',', $c)] = is_array($code) ? $code[$i % count($code)] : $code;
    return $c;
}

/**
 * Lay out every word → list of [x, y, glyph] trace cells plus the letter
 * bounding boxes (to keep the dot texture out of letter interiors).
 * @return array{0: list<array{int,int,string}>, 1: list<array{int,int,int,int}>}
 */
function traceCells(): array
{
    $cells = [];
    $boxes = [];
    foreach (LINES as [$text, $x0, $y0]) {
        $x = $x0;
        foreach (str_split($text) as $ch) {
            $g = FONT[$ch];
            $lw = mb_strlen($g[0]);
            $boxes[] = [$x, $y0, $x + $lw, $y0 + count($g)];
            foreach ($g as $dy => $row) {
                foreach (mb_str_split($row) as $dx => $glyph) {
                    if ($glyph !== ' ') {
                        $cells[] = [$x + $dx, $y0 + $dy, $glyph];
                    }
                }
            }
            $x += $lw + LETTER_GAP;
        }
    }
    usort($cells, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    return [$cells, $boxes];
}

function boardBg(int $x, int $y): array
{
    if ($GLOBALS['DEPTH'] === '256') {
        return BOARD256;
    }
    $c = lkGradient([lkHex(PALETTE['boardTop']), lkHex(PALETTE['boardBot'])], $y / (BOARD_H - 1));
    $band = abs((($x * 0.5 + $y * 1.0) - BOARD_W * 0.30) / 4.0);
    return pin($band < 1.0 ? lkShade($c, PALETTE['sheen'] * (1 - $band)) : $c, 'board');
}

/** Whole card; $front = power-front column (null = fully powered static frame). */
function board(?float $front = null): array
{
    [$trace, $boxes] = traceCells();
    $fingerRow = BOARD_H - 1;
    $inBox = static function (int $x, int $y) use ($boxes): bool {
        foreach ($boxes as [$a, $b, $c, $d]) {
            if ($x >= $a && $x < $c && $y >= $b && $y < $d) {
                return true;
            }
        }
        return false;
    };
    $cells = [];
    for ($y = 0; $y < BOARD_H; $y++) {
        for ($x = 0; $x < BOARD_W; $x++) {
            $bg = boardBg($x, $y);
            $glyph = ' ';
            $fg = null;
            if ($y === $fingerRow) {
                $in = $x >= FINGER_SPAN[0] && $x < FINGER_SPAN[1] && $x !== FINGER_KEY && $x !== FINGER_KEY + 1;
                $bg = $in ? lkHex(PALETTE['fingerBg']) : null;
                if ($in) {
                    $glyph = '▌';
                    $fg = $x % 2 ? pin(lkHex(PALETTE['fingerAlt']), 'fingerAlt') : pin(lkHex(PALETTE['finger']), 'finger');
                }
            } elseif ($x % 2 === 0 && $y % 2 === 1 && !$inBox($x, $y)) {
                $glyph = '·';
                $fg = pin($GLOBALS['DEPTH'] === '256' ? DOT256 : lkHex(PALETTE['dot']), 'dot');
            }
            $cells[$y][$x] = [$glyph, $fg, $bg];
        }
    }
    foreach (SILK as [$sx, $sy, $text]) {
        $x0 = $sx < 0 ? BOARD_W + $sx - mb_strlen($text) + 1 : $sx;
        $role = $text === '◯' ? 'hole' : 'silk';
        foreach (mb_str_split($text) as $i => $g) {
            $cells[$sy][$x0 + $i][0] = $g;
            $cells[$sy][$x0 + $i][1] = pin(lkHex(PALETTE[$role]), $role);
        }
    }
    $powered = static fn (int $x): bool => $front === null || $x < $front;
    foreach (DROPS as [$x, $y0]) {
        for ($y = $y0; $y < $fingerRow; $y++) {
            $fg = $powered($x) ? pin(lkHex(PALETTE['drop']), 'drop') : pin(lkHex(PALETTE['dropOff']), 'dropOff');
            $cells[$y][$x] = ['│', $fg, $cells[$y][$x][2]];
        }
    }
    $via = 0;
    foreach ($trace as [$x, $y, $g]) {
        if (in_array($g, VIA_GLYPHS, true)) {
            $i = $via++;
            if (!$powered($x)) {
                $cells[$y][$x] = ['●', pin(lkHex(PALETTE['ledOff']), 'ledOff'), $cells[$y][$x][2]];
                continue;
            }
            $led = lkHex(PALETTE['leds'][$i % count(PALETTE['leds'])]);
            $fg = ($front !== null && $x >= $front - 3) ? pin(lkHex(PALETTE['flash']), 'flash') : pin($led, 'led', $i);
            $cells[$y][$x] = ['●', $fg, pin(lkMix($cells[$y][$x][2], $led, PALETTE['glow']), 'board')];
            continue;
        }
        if ($powered($x)) {
            $t = $x / (BOARD_W - 1);
            $c = lkShade(lkGradient(array_map('lkHex', PALETTE['copper']), $t), 0.12 - 0.05 * (($y - 1) % 5));
            $fg = pin($c, $t > 0.35 && $t < 0.65 ? 'copperHi' : 'copper');
        } else {
            $fg = pin(lkHex(PALETTE['copperOff']), 'copperOff');
        }
        $cells[$y][$x] = [$g, $fg, $cells[$y][$x][2]];
    }
    return $cells;
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$dir = dirname(__DIR__);
foreach (['tc', '256', '16'] as $depth) {
    $DEPTH = $depth;
    $static = board();
    $frames = [];
    for ($f = 0; $f < ANIM_FRAMES; $f++) {
        $frames[] = board(-2 + (BOARD_W + 6) * $f / (ANIM_FRAMES - 1));
    }
    $enc = lkEncode($static, $depth, $GLOBALS['PINS']);
    [$ww, $hh] = lkVerify($enc, $depth);
    lkCheckDepth($enc, $depth, $depth);
    echo $enc;
    fwrite(STDERR, "[$depth] {$ww}x{$hh} ok\n");
    if ($write) {
        $r = skWriteSkinny($dir, SLUG, $depth, $enc, DESCRIPTION . " ($depth)", TAGS);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        $anim = lkAnim(array_map(static fn (array $c): string => lkEncode($c, $depth, $GLOBALS['PINS']), $frames), $enc);
        $r = skWriteSkinny($dir, SLUG . '-anim', $depth, $anim, 'Animated power-on: ' . DESCRIPTION . " Current sweeps left to right lighting copper and flashing each LED on; ends on the static frame. Play with tools/logo-play.php <file> 150 (~1.9 s). ($depth)", [...TAGS, 'animated', 'power-on-sweep']);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
}
