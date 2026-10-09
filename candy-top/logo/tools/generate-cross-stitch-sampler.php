<?php

declare(strict_types=1);

/**
 * generate-cross-stitch-sampler — CANDY-TOP worked as an embroidered
 * cross-stitch sampler on cream linen aida.
 *
 * Every cell is one aida square. A full stitch is `╳` in the thread's
 * highlight over the thread's body colour (bg), so letters read as solid
 * floss with a visible X weave; half stitches `╱` `╲` (fractional stitches,
 * as real samplers use to round corners) sit on bare linen; back-stitch `─`
 * outlines; unstitched holes are `·` on a noisy linen ground, and stitches
 * cast a soft shadow onto the fabric down-right.
 *
 * Composition: vine-and-berry border band, a tulip motif left, the variegated
 * floss wordmark, and a stitched btop-style CPU graph (the "top" is the
 * resource monitor) on the right.
 *
 * Reusable: the font, ornaments (legend-coded masks), palette and layout are
 * data at the top; stitch rendering, 16-colour role mapping and the stitch-in
 * animation are generic functions over a "stitch canvas" of
 * [kind, rgb] entries (kind ∈ full | fwd | back | line).
 *
 *   php generate-cross-stitch-sampler.php           preview tc/256/16 (+verify)
 *   php generate-cross-stitch-sampler.php --write   write .ansi + logos.jsonl
 *   php generate-cross-stitch-sampler.php --anim    preview the animation (tc)
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'cross-stitch-sampler';

/** 5×7 stitch font. '#' full stitch, '/' '\' half stitches (rounded corners). */
const FONT = [
    'C' => ['/###\\', '#...#', '#....', '#....', '#....', '#...#', '\\###/'],
    'A' => ['/###\\', '#...#', '#...#', '#####', '#...#', '#...#', '#...#'],
    'N' => ['##..#', '##..#', '#\\#.#', '#.#\\#', '#..##', '#..##', '#...#'],
    'D' => ['###\\.', '#...#', '#...#', '#...#', '#...#', '#...#', '###/.'],
    'Y' => ['#...#', '#...#', '\\#.#/', '.\\#/.', '..#..', '..#..', '..#..'],
    '-' => ['...', '...', '...', '###', '...', '...', '...'],
    'T' => ['#####', '..#..', '..#..', '..#..', '..#..', '..#..', '..#..'],
    'O' => ['/###\\', '#...#', '#...#', '#...#', '#...#', '#...#', '\\###/'],
    'P' => ['####\\', '#...#', '#...#', '####/', '#....', '#....', '#....'],
];

/** Wordmark: [glyph, thread hex, gap after]. One floss per letter (legibility). */
const WORD = [
    ['C', '#D7263D', 1], ['A', '#F46036', 1], ['N', '#F2B134', 1], ['D', '#4F9D4A', 1], ['Y', '#2E86AB', 2],
    ['-', '#9B4DCA', 2],
    ['T', '#D81B7A', 1], ['O', '#6A4C93', 1], ['P', '#1B998B', 0],
];

/** Ornament legend: char → [kind, hex]. kind full|fwd(╱)|back(╲)|line(─). */
const LEGEND = [
    'R' => ['full', '#C8213A'], 'K' => ['full', '#F27A93'], 'r' => ['fwd', '#C8213A'], 'q' => ['back', '#C8213A'],
    'G' => ['full', '#2F6B35'], 'L' => ['full', '#62A84B'], 'l' => ['fwd', '#62A84B'], 'j' => ['back', '#62A84B'],
    'W' => ['full', '#8A5A33'],
    'a' => ['full', '#D7263D'], '(' => ['fwd', '#D7263D'], ')' => ['back', '#D7263D'],
    'b' => ['full', '#F2B134'], 'c' => ['full', '#1B998B'], 'd' => ['full', '#3D3F8F'],
    '{' => ['back', '#3D3F8F'], '}' => ['fwd', '#3D3F8F'],
    'k' => ['full', '#9AA3AD'],
    '=' => ['line', '#7A6A58'],
];

/** Tulip with curling leaves (7×7). */
const TULIP = [
    '.R.K.R.',
    '.RRKRR.',
    '.RRRRR.',
    '.qRRRr.',
    'l..G..j',
    'LLjGlLL',
    '.LLGLL.',
];

/**
 * btop-style CPU history graph (9×7): back-stitched axes and a bar per
 * column, each stitch coloured by its height along btop's default
 * cpu_start → cpu_mid → cpu_end gradient (green → amber → red), deepened so
 * the floss holds its colour on cream linen.
 */
const GRAPH_BARS = [2, 4, 3, 5, 4, 6, 5, 3];
const GRAPH_GRADIENT = ['#2FA35A', '#8CC63F', '#F2B134', '#E8603C', '#D9363E'];   // extra stops: a straight green→amber mix goes olive on cream
const AXIS = '#7A6A58';   // same brown-grey back-stitch thread as the v1 speed lines

const LINEN = '#EFE4CC';
const HOLE = '#CDBE9E';
const VINE = '#5E9A4F';
const BERRY = '#D7263D';
const BLOOM = '#F2B134';

/** Layout: border(1) hole(1) tulip(7) gap(2) word gap(2) top(9) hole(1) border(1). */
const PAD_L = 2;
const GAP = 2;

const DESCRIPTION = 'Cross-stitch sampler: CANDY-TOP embroidered in variegated floss (one thread per letter, ╳ weave, ╱╲ fractional stitches rounding the corners) on cream linen aida with a hole grid and stitch shadows, framed by a vine-and-berry border, a tulip motif and a stitched btop-style CPU history bar graph (green→amber→red cpu gradient, back-stitched axes)';
const TAGS = ['cross-stitch', 'embroidery', 'folk-textile', 'aida-linen-grid', 'variegated-floss', 'sampler-border', 'tulip-motif', 'cpu-bar-graph', 'light-background'];

const ANIM_FRAMES = 22;

// ================================================================ STITCH CANVAS

/** @return array{0:array<int,array<int,array{0:string,1:array}>>,1:int,2:int} [stitches[y][x], w, h] */
function canvas(): array
{
    $wordW = 0;
    foreach (WORD as [$g, , $gap]) {
        $wordW += strlen(FONT[$g][0]) + $gap;
    }
    $w = PAD_L + strlen(TULIP[0]) + GAP + $wordW + GAP + count(GRAPH_BARS) + 1 + 1 + 1;
    $h = 9;
    $s = [];
    // [kind, rgb, key]: key = the thread's un-variegated colour (16-colour hue)
    $put = static function (int $x, int $y, string $kind, array $rgb, ?array $key = null) use (&$s): void {
        $s[$y][$x] = [$kind, $rgb, $key ?? $rgb];
    };
    $ornament = static function (array $mask, int $ox, int $oy) use ($put): void {
        foreach ($mask as $y => $row) {
            foreach (str_split($row) as $x => $ch) {
                if (isset(LEGEND[$ch])) {
                    [$kind, $hex] = LEGEND[$ch];
                    $put($ox + $x, $oy + $y, $kind, lkHex($hex));
                }
            }
        }
    };

    // border band: vine zig-zag of half stitches with berries, corner blooms
    for ($x = 0; $x < $w; $x++) {
        foreach ([0, $h - 1] as $y) {
            $phase = ($x + ($y ? 3 : 0)) % 8;
            if ($x === 0 || $x === $w - 1) {
                $put($x, $y, 'full', lkHex(BLOOM));
            } elseif ($phase === 0) {
                $put($x, $y, 'full', lkHex(BERRY));
            } else {
                $put($x, $y, $phase % 2 ? 'fwd' : 'back', lkHex(VINE));
            }
        }
    }
    for ($y = 1; $y < $h - 1; $y++) {
        foreach ([0, $w - 1] as $x) {
            if ($y === 4) {
                $put($x, $y, 'full', lkHex(BERRY));
            } elseif ($y % 2 === 0) {
                $put($x, $y, 'full', lkHex(VINE));
            } else {
                $put($x, $y, $y < 4 ? 'back' : 'fwd', lkHex(VINE));
            }
        }
    }

    $x0 = PAD_L;
    $ornament(TULIP, $x0, 1);
    $x = $x0 + strlen(TULIP[0]) + GAP;
    foreach (WORD as $i => [$g, $hex, $gap]) {
        $mask = FONT[$g];
        $gw = strlen($mask[0]);
        $base = lkHex($hex);
        $next = lkHex(WORD[min($i + 1, count(WORD) - 1)][1]);
        foreach ($mask as $y => $row) {
            foreach (str_split($row) as $dx => $ch) {
                if ($ch === '.') {
                    continue;
                }
                // variegated floss: drift toward the next letter's hue, lit top → dark base
                $c = lkMix($base, $next, 0.30 * $dx / max(1, $gw - 1));
                $c = lkShade($c, 0.10 - 0.28 * $y / 6);
                $put($x + $dx, 1 + $y, match ($ch) {'#' => 'full', '/' => 'fwd', default => 'back'}, $c, $base);
            }
        }
        $x += $gw + $gap;
    }
    $x += GAP;
    // stitched CPU graph: axes then gradient bars (alternate bars a shade darker)
    $axis = lkHex(AXIS);
    for ($y = 1; $y <= 6; $y++) {
        $put($x, $y, 'vline', $axis);
    }
    $put($x, 7, 'corner', $axis);
    foreach (GRAPH_BARS as $i => $hgt) {
        $put($x + 1 + $i, 7, 'line', $axis);
        for ($k = 0; $k < $hgt; $k++) {
            $c = lkGradient(GRAPH_GRADIENT, $k / 5);
            $put($x + 1 + $i, 6 - $k, 'full', lkShade($c, $k === $hgt - 1 ? 0.12 : -0.08 * ($i % 2)), $c);
        }
    }
    return [$s, $w, $h];
}

// ================================================================ RENDER

/**
 * Stitch canvas → cell grid.
 * $reveal: column frontier for the stitch-in animation (null = all sewn);
 * columns < reveal-1 are finished, the frontier column shows only the first
 * leg (╱) of each cross, columns beyond are bare aida.
 */
function cells(?float $reveal = null, bool $flat = false): array
{
    [$s, $w, $h] = canvas();
    $linen = lkHex(LINEN);
    $hole = lkHex(HOLE);
    $state = static function (int $x) use ($reveal): int {
        if ($reveal === null || $x < $reveal - 1) {
            return 2;
        }
        return $x < $reveal ? 1 : 0;
    };
    $isStitch = static fn (int $x, int $y): bool => isset($s[$y][$x]) && $state($x) === 2 && $s[$y][$x][0] === 'full';
    $cells = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $n = $flat ? 0.5 : lkNoise($x, $y, 7);
            $bg = lkShade($linen, -0.035 * $n);
            if ($isStitch($x - 1, $y - 1)) {
                $bg = lkShade($bg, -0.07);   // full stitches cast a soft shadow down-right
            }
            $cell = ['·', lkShade($hole, $isStitch($x - 1, $y - 1) ? -0.15 : 0.0), $bg];
            if (isset($s[$y][$x]) && ($st = $state($x)) > 0) {
                [$kind, $c] = $s[$y][$x];
                $sheen = $flat ? 0.0 : (lkNoise($x, $y, 3) - 0.5) * 0.12;
                if ($st === 1) {
                    $cell = in_array($kind, ['line', 'vline', 'corner'], true) ? $cell : ['╱', lkShade($c, -0.1), $bg];
                } else {
                    $cell = match ($kind) {
                        'full' => ['╳', lkShade($c, 0.38 + $sheen), lkShade($c, -0.12 + $sheen)],
                        'fwd' => ['╱', lkShade($c, -0.08), $bg],
                        'back' => ['╲', lkShade($c, -0.08), $bg],
                        'line' => ['─', $c, $bg],
                        'vline' => ['│', $c, $bg],
                        'corner' => ['└', $c, $bg],
                    };
                }
            }
            $cells[$y][$x] = $cell;
        }
    }
    return $cells;
}

/**
 * 16-colour cells by role: linen → bright white bg, holes → white/grey,
 * full stitch → base-hue bg with bright fg, half/back stitches → hue fg.
 */
function cells16(?float $reveal = null): array
{
    $a16 = lkAnsi16();
    $tc = cells($reveal, true);
    [$s] = canvas();
    $linen = lkHex(LINEN);
    $out = [];
    foreach ($tc as $y => $row) {
        foreach ($row as $x => [$g, $fg, $bg]) {
            $base = isset($s[$y][$x]) ? $s[$y][$x][2] : null;
            $hue = $base !== null ? lkTo16($base) : 37;
            $dark = $hue >= 90 ? $hue - 60 : $hue;
            $bright = $dark + 60;
            $shadow = false;   // flat linen reads cleaner than 2-tone shadow at 16 colours
            $linenBg = $a16[$shadow ? 37 : 97];
            $out[$y][$x] = match ($g) {
                '╳' => ['╳', $a16[$bright === 90 ? 97 : $bright], $a16[$dark === 30 ? 90 : $dark]],
                '╱', '╲' => [$g, $a16[$dark === 33 ? 33 : $dark], $linenBg],
                '─', '│', '└' => [$g, $a16[90], $linenBg],
                default => ['·', $a16[$shadow ? 90 : 37], $linenBg],
            };
        }
    }
    return $out;
}

/**
 * Hue-preserving 256 snap: saturated threads may not fall onto the grey ramp
 * (redmean-nearest turned dark-shaded floss grey) and pay a hue penalty;
 * fabric roles are pinned so the linen stays one even cream.
 */
function csTo256(array $c): array
{
    static $memo = [];
    $linen = lkShade(lkHex(LINEN), -0.0175);
    $pins = [
        implode(',', $linen) => [255, 255, 215],                 // 230
        implode(',', lkShade($linen, -0.07)) => [255, 255, 215],  // 230: no 2-tone shadow at 256
        implode(',', lkHex(HOLE)) => [215, 175, 135],            // 180
        implode(',', lkShade(lkHex(HOLE), -0.15)) => [175, 135, 95],
    ];
    $k = implode(',', $c);
    if (isset($pins[$k])) {
        return $pins[$k];
    }
    if (isset($memo[$k])) {
        return $memo[$k];
    }
    $hsv = static function (array $c): array {
        [$r, $g, $b] = array_map(static fn (int $v): float => $v / 255, $c);
        $max = max($r, $g, $b);
        $d = $max - min($r, $g, $b);
        $h = $d == 0.0 ? 0.0 : match (true) {
            $max == $r => 60 * fmod(($g - $b) / $d + 6, 6),
            $max == $g => 60 * (($b - $r) / $d + 2),
            default => 60 * (($r - $g) / $d + 4),
        };
        return [$h, $max == 0.0 ? 0.0 : $d / $max];
    };
    [$h, $sat] = $hsv($c);
    $best = null;
    $bd = INF;
    foreach (lkXterm256() as $n => $p) {
        [$ph, $ps] = $hsv($p);
        if ($sat > 0.25 && $ps < 0.15) {
            continue;
        }
        $dh = abs($h - $ph);
        $dh = min($dh, 360 - $dh);
        $d = lkDist($c, $p) + ($sat > 0.25 ? 120 * $dh * $dh : 0);
        if ($d < $bd) {
            $bd = $d;
            $best = $p;
        }
    }
    return $memo[$k] = $best;
}

function cells256(?float $reveal = null): array
{
    $cells = cells($reveal, true);
    foreach ($cells as &$row) {
        foreach ($row as &$cell) {
            $cell[1] = $cell[1] === null ? null : csTo256($cell[1]);
            $cell[2] = $cell[2] === null ? null : csTo256($cell[2]);
        }
    }
    return $cells;
}

function encodeAt(string $depth, ?float $reveal = null): string
{
    // 256: flat (no fabric noise / thread sheen) — nearest-256 turns ±4% noise into blotches
    return match ($depth) {
        '16' => lkEncode(cells16($reveal), '16', [], 'nearest'),
        '256' => lkEncode(cells256($reveal), '256'),
        default => lkEncode(cells($reveal), 'tc'),
    };
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$dir = dirname(__DIR__);
$w = count(cells()[0]);
if (in_array('--anim', $argv, true)) {
    $frames = [];
    for ($f = 0; $f < ANIM_FRAMES; $f++) {
        $frames[] = encodeAt('tc', -1 + ($w + 2) * $f / (ANIM_FRAMES - 1));
    }
    echo lkAnim($frames, encodeAt('tc')), "\n";
    exit;
}
foreach (['tc', '256', '16'] as $depth) {
    $static = encodeAt($depth);
    [$vw, $vh] = lkVerify($static, $depth);
    lkCheckDepth($static, $depth);
    echo $static;
    fwrite(STDERR, "[$depth] {$vw}x{$vh} ok\n");
    if ($write) {
        $r = lkWriteLogo($dir, SLUG, $depth, $static, DESCRIPTION . " ($depth)", TAGS);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        $frames = [];
        for ($f = 0; $f < ANIM_FRAMES; $f++) {
            $frames[] = encodeAt($depth, -1 + ($w + 2) * $f / (ANIM_FRAMES - 1));
        }
        $anim = lkAnim($frames, $static);
        $r = lkWriteLogo($dir, SLUG . '-anim', $depth, $anim, 'Animated ' . DESCRIPTION . ". Needle stitch-in: a frontier sweeps left to right laying the first ╱ leg then completing each ╳ cross; ends on the static frame; play with tools/logo-play.php <file> 150 (~3.4s) ($depth)", [...TAGS, 'animated', 'stitch-in']);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
}
