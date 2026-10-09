<?php

declare(strict_types=1);

/**
 * licorice-allsorts — "CANDY TOP" as a box of Liquorice Allsorts.
 *
 * Each letter is a layered sweet: 5 horizontal bands (fondant / liquorice /
 * fondant / liquorice / fondant), each band bevelled (highlight top pixel,
 * shade bottom pixel), the right edge darkened as a cube side-face, plus a
 * soft drop shadow. Y is the blue nonpareil "bobble"; O is the round wheel.
 * Rendered on a half-block grid (2 pixel rows per terminal row).
 *
 * Usage: php licorice-allsorts.php [outdir]   (default: parent dir)
 *        php licorice-allsorts.php --preview  (print tc only)
 * Swap $PALETTE / $GLYPHS / $WORD / $FONDANT to re-skin.
 */

require __DIR__ . '/licorice-allsorts-lib.php';

$SLUG = 'licorice-allsorts';

// ---------------------------------------------------------------- design data
$PALETTE = [
    // [highlight, base, shade]
    'liq'    => ['#7A6070', '#33242B', '#1C1318'],
    'pink'   => ['#FFD0E3', '#F7A1C4', '#C9709A'],
    'yellow' => ['#FFF1A0', '#FFD93B', '#D9A800'],
    'cream'  => ['#FFFFFF', '#FFF4E0', '#D8C7A8'],
    'orange' => ['#FFC48A', '#FF9A3C', '#D06A10'],
    'blue'   => ['#A8DDF7', '#4FB3E8', '#2E7FB8'],
    'shadow' => ['#2B1B24', '#2B1B24', '#2B1B24'],
];
// 16-colour mapping per family [highlight, base, shade] (fg codes; bg = +10).
// Nearest-colour would turn every pastel into white, so the families are pinned.
$ANSI16 = [
    'liq' => [37, 90, 90], 'pink' => [95, 95, 35], 'yellow' => [93, 93, 33], 'cream' => [97, 97, 37],
    'orange' => [93, 33, 31], 'blue' => [96, 94, 34], 'shadow' => [30, 30, 30], 'speck' => [34, 97, 97],
];
$PIN16 = [];   // filled by build_grid(): 'r,g,b' => code
$SPECKLE = ['#1E5F8E', '#E8F6FF'];   // nonpareils on the bobble
$SIDE_DARKEN = -0.18;                 // cube side-face
// Vertical layer cake, top to bottom: [pixel rows, 'fond' | 'liq']. Sums to $HEIGHT_PX.
$BANDS = [[4, 'fond'], [2, 'liq'], [3, 'fond'], [2, 'liq'], [4, 'fond']];

// Letter, fondant colour, extra flags.
$WORD = [
    ['C', 'pink'], ['A', 'yellow'], ['N', 'cream'], ['D', 'orange'], ['Y', 'blue', 'speckle'],
    [' ', null],
    ['T', 'pink'], ['O', 'wheel'], ['P', 'yellow'],
];
$GAP = 1;
$SPACE_W = 2;   // extra columns for the word space (plus the 2 normal gaps)

// '#' = body pixel. 15 rows each.
$GLYPHS = [
    'C' => [
        '.######', '#######', '#######', '###....', '###....', '###....', '###....', '###....',
        '###....', '###....', '###....', '###....', '#######', '#######', '.######',
    ],
    'A' => [
        '.#####.', '#######', '#######', '##...##', '##...##', '##...##', '#######', '#######',
        '#######', '##...##', '##...##', '##...##', '##...##', '##...##', '##...##',
    ],
    'N' => [
        '##...##', '###..##', '###..##', '####.##', '####.##', '##.#.##', '##.#.##', '##.#.##',
        '##.#.##', '##.#.##', '##.####', '##.####', '##..###', '##..###', '##...##',
    ],
    'D' => [
        '#####..', '######.', '#######', '##..###', '##...##', '##...##', '##...##', '##...##',
        '##...##', '##...##', '##...##', '##..###', '#######', '######.', '#####..',
    ],
    'Y' => [
        '##...##', '##...##', '##...##', '##...##', '##...##', '###.###', '#######', '.#####.',
        '..###..', '..###..', '..###..', '..###..', '..###..', '..###..', '..###..',
    ],
    'T' => [
        '#######', '#######', '#######', '..###..', '..###..', '..###..', '..###..', '..###..',
        '..###..', '..###..', '..###..', '..###..', '..###..', '..###..', '..###..',
    ],
    'P' => [
        '######.', '#######', '#######', '##...##', '##...##', '##...##', '#######', '#######',
        '######.', '##.....', '##.....', '##.....', '##.....', '##.....', '##.....',
    ],
];
// The wheel O: concentric rings, outside-in, as [maxRadius, colourKey].
$WHEEL_W = 9;
$WHEEL_RINGS = [[1.00, 'pink'], [0.74, 'liq'], [0.50, 'cream'], [0.30, 'liq']];
$HEIGHT_PX = 15;

// ---------------------------------------------------------------- rasterise
/**
 * Build the pixel grid. $sheen (column, or null) adds the animated gloss pass.
 * @return list<list<?array{int,int,int}>>
 */
function build_grid(?float $sheen = null): array
{
    global $PALETTE, $ANSI16, $PIN16, $SPECKLE, $SIDE_DARKEN, $BANDS, $WORD, $GAP, $SPACE_W, $GLYPHS,
        $WHEEL_W, $WHEEL_RINGS, $HEIGHT_PX;

    $rgbPal = array_map(static fn ($t) => array_map('hex_rgb', $t), $PALETTE);
    // per pixel row: [layer, bevel pos 0=highlight 1=base 2=shade]
    $rowLayer = [];
    foreach ($BANDS as [$n, $layer]) {
        for ($k = 0; $k < $n; $k++) {
            $rowLayer[] = [$layer, $k === 0 ? 0 : ($k === $n - 1 ? 2 : 1)];
        }
    }
    $speck = array_map('hex_rgb', $SPECKLE);

    // Body map: key per pixel ('fondantKey' or 'liq') + metadata, before shading.
    $cells = [];   // [y][x] => [key, letterIndex, bandPos, speckle?]
    $x0 = 0;
    $width = 0;
    foreach ($WORD as $i => $spec) {
        [$ch, $fond] = $spec;
        $flags = array_slice($spec, 2);
        if ($ch === ' ') {
            $x0 += $SPACE_W;
            continue;
        }
        if ($ch === 'O' && $fond === 'wheel') {
            $cx = ($WHEEL_W - 1) / 2; $cy = ($HEIGHT_PX - 1) / 2;
            for ($y = 0; $y < $HEIGHT_PX; $y++) {
                for ($x = 0; $x < $WHEEL_W; $x++) {
                    $dx = ($x - $cx) / ($cx + 0.55); $dy = ($y - $cy) / ($cy + 0.55);
                    $r = sqrt($dx * $dx + $dy * $dy);
                    $key = null;
                    foreach ($WHEEL_RINGS as [$max, $k]) {
                        if ($r <= $max) { $key = $k; }
                    }
                    if ($key !== null) {
                        // bevel by vertical position on the disc: lit top-left
                        $pos = ($dy < -0.55 || ($dx < -0.6 && $dy < 0)) ? 0 : (($dy > 0.55 || $dx > 0.6) ? 2 : 1);
                        $cells[$y][$x0 + $x] = [$key, $i, $pos, false];
                    }
                }
            }
            $x0 += $WHEEL_W + $GAP;
            continue;
        }
        $mask = $GLYPHS[$ch];
        $gw = strlen($mask[0]);
        foreach ($mask as $y => $row) {
            for ($x = 0; $x < $gw; $x++) {
                if ($row[$x] !== '#') { continue; }
                [$layer, $pos] = $rowLayer[$y];
                $key = $layer === 'liq' ? 'liq' : $fond;
                $cells[$y][$x0 + $x] = [$key, $i, $pos, in_array('speckle', $flags, true) && $key !== 'liq'];
            }
        }
        $x0 += $gw + $GAP;
    }
    $width = $x0 - $GAP + 1;          // +1 for the shadow column
    $height = $HEIGHT_PX + 1;         // +1 for the shadow row

    $grid = array_fill(0, $height, array_fill(0, $width, null));
    // shadow first (offset +1,+1), then bodies over it
    foreach ($cells as $y => $row) {
        foreach ($row as $x => $_) {
            $grid[$y + 1][$x + 1] = $rgbPal['shadow'][1];
            $PIN16[implode(',', $rgbPal['shadow'][1])] = $ANSI16['shadow'][1];
        }
    }
    foreach ($cells as $y => $row) {
        foreach ($row as $x => [$key, $li, $pos, $sp]) {
            $c = $rgbPal[$key][$pos];
            $code = $ANSI16[$key][$pos];
            if ($sp) {
                $hsh = ($x * 7 + $y * 13 + $x * $y) % 9;
                if ($hsh === 0 || $hsh === 6) { $c = $speck[0]; $code = $ANSI16['speck'][0]; } elseif ($hsh === 4) { $c = $speck[1]; $code = $ANSI16['speck'][1]; }
            }
            $right = $cells[$y][$x + 1] ?? null;
            $left = $cells[$y][$x - 1] ?? null;
            // liquorice is near-black: give its run ends a glossy rim so the letter
            // silhouette survives on dark terminals
            if ($key === 'liq' && ($left === null || $left[1] !== $li || $right === null || $right[1] !== $li)) {
                $c = shade_rgb($rgbPal['liq'][0], $pos === 2 ? -0.25 : 0.0);
                $code = $ANSI16['liq'][$pos === 2 ? 1 : 0];
            }
            // cube side-face: rightmost pixel of a run within the same letter
            if ($right === null || $right[1] !== $li) {
                $c = shade_rgb($c, $SIDE_DARKEN);
                $code = $key === 'liq' ? $code : $ANSI16[$key][2];
            }
            if ($sheen !== null) {
                // diagonal specular band
                $d = abs(($x + $y * 0.5) - $sheen);
                if ($d < 2.5) { $c = shade_rgb($c, 0.55 * (1 - $d / 2.5)); }
            }
            $grid[$y][$x] = $c;
            if ($sheen === null) {
                $PIN16[implode(',', $c)] = $code;
            }
        }
    }
    return $grid;
}

// ---------------------------------------------------------------- build
$args = array_slice($argv, 1);
if (in_array('--preview', $args, true)) {
    echo encode_halfblocks(build_grid());
    exit(0);
}
$outDir = rtrim($args[0] ?? dirname(__DIR__), '/');

$tc = encode_halfblocks(build_grid());
[$W, $H] = verify_ansi($tc);


$files = [
    'tc'  => $tc,
    '256' => downgrade_ansi($tc, 256),
    '16'  => downgrade_ansi($tc, 16, $PIN16),
];
$written = [];
foreach ($files as $depth => $ansi) {
    [$w, $h] = verify_ansi($ansi);
    $name = "logo-{$SLUG}-{$depth}-{$w}x{$h}.ansi";
    file_put_contents("$outDir/$name", $ansi);
    $written[] = [$name, $depth, $w, $h];
    fwrite(STDOUT, "wrote $name\n");
}

// Animated variant: a gloss sheen sweeps across, ends on the static frame.
$frames = [];
$sweep = range(-6, $W + 10, 4);
foreach ($sweep as $s) {
    $frames[] = encode_halfblocks(build_grid((float) $s));
}
$frames[] = $tc;
foreach ($frames as $f) { verify_ansi($f); }
$up = "\e[{$H}A\r";
$anim = "\e[?25l" . implode($up, $frames);
$anim = rtrim($anim, "\n") . "\e[?25h\n";
$animName = "logo-{$SLUG}-anim-tc-{$W}x{$H}.ansi";
file_put_contents("$outDir/$animName", $anim);
fwrite(STDOUT, "wrote $animName (" . count($frames) . " frames)\n");
