<?php

declare(strict_types=1);

/**
 * rock-candy-prism-skinny — the rock-candy-prism crystal letters condensed to
 * ≤40 cols: CANDY stacked over TOP, narrower 6x10 chamfered masks (N is 7
 * wide for its diagonal), each word followed by its own dim sugar-dust
 * reflection pixel row, sparkles tucked into the margins beside TOP.
 *
 * Usage:
 *   php generate-rock-candy-prism-skinny.php                preview tc/256/16 to stdout
 *   php generate-rock-candy-prism-skinny.php --write        write the three .ansi files
 *   php generate-rock-candy-prism-skinny.php --write --jsonl  + append logos.jsonl lines
 *
 * Copied from rock-candy-prism.php; facet shading / half-block raster reuse
 * rock-candy-prism-lib.php, colour downgrade + verified write use skinny-kit
 * (spEncode, skWriteSkinny).
 */

require_once __DIR__ . '/rock-candy-prism-lib.php';
require_once __DIR__ . '/skinny-kit.php';

// ================================================================ DESIGN DATA

const RCPS_SLUG = 'rock-candy-prism-skinny';

/** 6x10 chamfered crystal masks ('#' = crystal); N is 7 wide. */
const RCPS_GLYPHS = [
    'C' => ['..####', '.#####', '##....', '##....', '##....', '##....', '##....', '##....', '.#####', '..####'],
    'A' => ['.####.', '######', '##..##', '##..##', '######', '######', '##..##', '##..##', '##..##', '##..##'],
    'N' => ['###..##', '####.##', '####.##', '####.##', '##.####', '##.####', '##..###', '##..###', '##..###', '##...##'],
    'D' => ['#####.', '######', '##..##', '##..##', '##..##', '##..##', '##..##', '##..##', '######', '#####.'],
    'Y' => ['##..##', '##..##', '##..##', '######', '.####.', '..##..', '..##..', '..##..', '..##..', '..##..'],
    'T' => ['######', '######', '..##..', '..##..', '..##..', '..##..', '..##..', '..##..', '..##..', '..##..'],
    'O' => ['.####.', '######', '##..##', '##..##', '##..##', '##..##', '##..##', '##..##', '######', '.####.'],
    'P' => ['#####.', '######', '##..##', '##..##', '######', '#####.', '##....', '##....', '##....', '##....'],
];

/** Stacked words; each word is followed by one reflection pixel row. */
const RCPS_WORDS = ['CANDY', 'TOP'];
const RCPS_GAP = 1;      // px between letters
const RCPS_MARGIN = 1;   // blank cols left/right

/** Same prism bands as the original, one per letter, reading order. */
const RCPS_HUES = ['#FF6EC7', '#FF8FA3', '#FFB27A', '#FFE66E', '#9CFFB0', '#5FE3E0', '#7FA8FF', '#B48CFF'];

const RCPS_DUST = 0.42;

/** 16-colour: hues in this range (peach N) are pinned to normal yellow 33. */
const RCPS_PEACH_HUE = [12.0, 40.0];  // reflection brightness

/** Sparkles in the empty margins beside TOP: [row, col, glyph, hex]. */
const RCPS_SPARKLES = [
    [6, 2, '✦', '#FFFFFF'], [8, 5, '·', '#FFB6E1'], [9, 1, '✧', '#FFE9F7'],
    [6, 32, '·', '#C9FFF8'], [7, 34, '✦', '#FFFFFF'], [9, 30, '✧', '#F1E6FF'],
];

function rcpsLevel(array $hue, int $level): array
{
    return match ($level) {
        0 => rcpScale($hue, 0.48),
        1 => $hue,
        2 => rcpMix($hue, [255, 255, 255], 0.46),
        default => rcpMix($hue, [255, 255, 255], 0.74),
    };
}

// ================================================================ BUILD

/**
 * Lay out the stacked words into one pixel mask.
 * @return array{0: list<string>, 1: list<array{x0:int,x1:int,y0:int,y1:int,hue:int}>, 2: list<int>}
 *         [mask rows, letters, reflection row indices]
 */
function rcpsLayout(): array
{
    $words = [];
    $maxW = 0;
    foreach (RCPS_WORDS as $word) {
        $rows = array_fill(0, 10, '');
        $spans = [];
        foreach (str_split($word) as $i => $ch) {
            if ($i > 0) {
                foreach ($rows as $k => $_) {
                    $rows[$k] .= str_repeat('.', RCPS_GAP);
                }
            }
            $x0 = strlen($rows[0]);
            foreach (RCPS_GLYPHS[$ch] as $k => $line) {
                $rows[$k] .= $line;
            }
            $spans[] = [$x0, strlen($rows[0])];
        }
        $words[] = [$rows, $spans];
        $maxW = max($maxW, strlen($rows[0]));
    }
    $w = $maxW + 2 * RCPS_MARGIN;
    $mask = [];
    $letters = [];
    $dust = [];
    $hue = 0;
    foreach ($words as [$rows, $spans]) {
        $off = intdiv($w - strlen($rows[0]), 2);
        $y0 = count($mask);
        foreach ($rows as $r) {
            $mask[] = str_pad(str_repeat('.', $off) . $r, $w, '.');
        }
        foreach ($spans as [$a, $b]) {
            $letters[] = ['x0' => $a + $off, 'x1' => $b + $off, 'y0' => $y0, 'y1' => $y0 + 10, 'hue' => $hue++];
        }
        $dust[] = count($mask);
        $mask[] = str_repeat('.', $w);
    }
    return [$mask, $letters, $dust];
}

/** Prism hue: interpolate between the centres of the letters in the same word. */
function rcpsHue(int $x, int $y, array $letters): array
{
    $hues = array_map('rcpHex', RCPS_HUES);
    $row = array_values(array_filter($letters, static fn (array $l): bool => $y >= $l['y0'] && $y <= $l['y1']));
    $c = array_map(static fn (array $l): float => ($l['x0'] + $l['x1'] - 1) / 2, $row);
    if ($x <= $c[0]) {
        return $hues[$row[0]['hue']];
    }
    for ($i = 0; $i < count($c) - 1; $i++) {
        if ($x <= $c[$i + 1]) {
            return rcpMix($hues[$row[$i]['hue']], $hues[$row[$i + 1]['hue']], ($x - $c[$i]) / ($c[$i + 1] - $c[$i]));
        }
    }
    return $hues[end($row)['hue']];
}

/** Facet-shaded pixel image + dim reflections under each word. */
function rcpsPixels(): array
{
    [$mask, $letters, $dust] = rcpsLayout();
    $h = count($mask);
    $w = strlen($mask[0]);
    $on = static fn (int $x, int $y): bool => $y >= 0 && $y < $h && $x >= 0 && $x < $w && $mask[$y][$x] === '#';
    $px = array_fill(0, $h, array_fill(0, $w, null));
    foreach ($letters as $l) {
        for ($y = $l['y0']; $y < $l['y1']; $y++) {
            for ($x = $l['x0']; $x < $l['x1']; $x++) {
                if (!$on($x, $y)) {
                    continue;
                }
                $lx = ($x - $l['x0'] + 0.5) / ($l['x1'] - $l['x0']);
                $ly = ($y - $l['y0'] + 0.5) / 10;
                $level = ($lx + $ly) < 1.0 ? 2 : 1;
                $level += (!$on($x, $y - 1) || !$on($x - 1, $y) ? 1 : 0) - (!$on($x, $y + 1) || !$on($x + 1, $y) ? 1 : 0);
                $px[$y][$x] = rcpsLevel(rcpsHue($x, $y, $letters), max(0, min(3, $level)));
            }
        }
    }
    $mid = ($w - 1) / 2;
    foreach ($dust as $y) {
        for ($x = 0; $x < $w; $x++) {
            if ($on($x, $y - 1)) {
                $fade = 1 - 0.5 * abs($x - $mid) / $mid;
                $px[$y][$x] = rcpScale(rcpsHue($x, $y - 1, $letters), RCPS_DUST * $fade + 0.08);
            }
        }
    }
    return $px;
}

function rcpsCells(): array
{
    $cells = rcpHalfBlock(rcpsPixels());
    foreach (RCPS_SPARKLES as [$r, $c, $g, $hex]) {
        if ($cells[$r][$c][0] !== ' ') {
            throw new RuntimeException("sparkle at $r,$c would cover crystal");
        }
        $cells[$r][$c] = [$g, rcpHex($hex), null];
    }
    return $cells;
}

/**
 * 16-colour encode: skinny-palette hue bands, except the peach band (N) drops
 * to normal yellow 33 so it doesn't merge with rose A (91) or lemon D (93).
 */
function rcps16(array $cells): string
{
    $pins = [];
    foreach ($cells as $row) {
        foreach ($row as [, $fg, $bg]) {
            foreach ([$fg, $bg] as $c) {
                if ($c === null) {
                    continue;
                }
                $code = spCode16($c);
                [$hue, $sat] = spHueSat($c);
                if ($hue >= RCPS_PEACH_HUE[0] && $hue < RCPS_PEACH_HUE[1] && $sat >= 0.28 && in_array($code, [91, 93], true)) {
                    $code = 33;
                }
                $pins[implode(',', $c)] = $code;
            }
        }
    }
    return lkEncode($cells, '16', $pins, 'nearest');
}

// ================================================================ CLI

$o = skOpts($argv);
$dir = dirname(__DIR__);
$cells = rcpsCells();
$tags = ['skinny', 'rock-candy', 'faceted-crystal', 'prismatic-gradient', 'half-block-pixel', 'bevel-shading', 'stacked-words', 'sparkle', 'pastel'];
$desc = [
    'tc' => 'Skinny rock-candy-prism: CANDY stacked over TOP in narrow 6x10 chamfered half-block crystal letters with 4-level facet/bevel shading, pastel prism gradient (bubblegum→lemon→mint, aqua→lilac), dim sugar-dust reflection under each word and sparkles beside TOP. 24-bit colour.',
    '256' => 'Skinny rock-candy-prism in xterm-256 (hue-faithful skinny-palette mapping): stacked CANDY/TOP faceted pastel crystal letters with reflections and sparkles.',
    '16' => 'Skinny rock-candy-prism in 16 ANSI colours: stacked CANDY/TOP crystal letters, hue-banded facets (white highlights, bright faces, dim shadows), reflections and sparkles.',
];
foreach (['tc', '256', '16'] as $depth) {
    $ansi = $depth === '16' ? rcps16($cells) : spEncode($cells, $depth);
    echo $ansi;
    if (!empty($o['write'])) {
        $r = skWriteSkinny($dir, RCPS_SLUG, $depth, $ansi, $desc[$depth], $tags, !empty($o['jsonl']));
        fwrite(STDERR, "wrote {$r['file']} ({$r['width']}x{$r['height']}, jsonl {$r['jsonl']})\n");
    } else {
        [$w, $h] = rcpVerify($ansi, $depth);
        fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    }
}
