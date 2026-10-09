<?php

declare(strict_types=1);

/**
 * skinny-stroke-font — vector single-stroke (centre-line) font → distance
 * field → ink mask at braille / sextant / quadrant / half-block resolution,
 * with multi-row layout (stack CANDY over TOP), per-glyph condensing,
 * negative gaps (overlapping letters) and italic slant. Library + CLI.
 *
 * Why: a stroke font scales to ANY glyph box without re-drawing pixel masks,
 * so a 7-cell-wide letter can become a 4- or 5-cell one and still keep round
 * bowls and even stroke weight — the main problem when squeezing a 75-col
 * logo into 40 cols. The distance field also gives every generator glow
 * halos (distance > radius), tube/cylinder shading (distance / radius), a
 * per-px nearest-letter id (per-letter colours) and a stroke draw ORDER
 * (beam / pen reveal animations) for free.
 *
 * Coordinates: everything is in SUB-PIXELS of the chosen resolution
 * (braille 2×4, sextant 2×3, quadrant 2×2, half 1×2 per cell). Distances
 * are measured in sub-pixel WIDTHS with y scaled by the sub-pixel aspect
 * (terminal cells are ~1:2), so a stroke radius gives a round pen on screen.
 *
 * ---------------------------------------------------------------- CLI
 *   php skinny-stroke-font.php [options]
 *     --rows="CANDY/TOP"     text rows, '/' separates stacked rows ("CANDY TOP" = one row)
 *     --w=38 --h=11          canvas size in CELLS
 *     --res=braille          braille|sextant|quadrant|half
 *     --glyph=6x5            glyph box in CELLS (w x h), stroke included
 *     --gap=1                cells between letters (may be negative / fractional: overlap)
 *     --word-gap=3           cells for a space
 *     --row-gap=0.5          cells between stacked rows
 *     --radius=1.8           stroke radius in sub-pixel widths
 *     --slant=0              italic shear (x shift per glyph height, in glyph widths, e.g. 0.15)
 *     --top=0                first row's top, in cells (fractional ok)
 *     --colour               colour each letter (ANSI tc) instead of mono
 *     -h | --help
 *   Prints the mask as glyphs so you can judge legibility before writing a
 *   generator, plus a fit report (row widths vs canvas). Example:
 *     php skinny-stroke-font.php --rows="CANDY/TOP" --w=38 --h=11 --glyph=6x5 --colour
 *     php skinny-stroke-font.php --rows="CANDY TOP" --w=38 --h=6 --glyph=4x5 --gap=-0.2 --res=sextant
 *
 * ---------------------------------------------------------------- library
 *   require __DIR__ . '/skinny-stroke-font.php';     (no CLI side effects when required)
 *   SSF_FONT                         default font: A C D N O P T Y (+ space); centre lines
 *                                    in a 10×20 box; ['arc', cx,cy,rx,ry,a0,a1] entries
 *                                    (deg, 0 = right, 90 = up) expand in place
 *   ssfRes($res)                     → [subX, subY, aspect]
 *   ssfLayout($rows, $o)             → ['segs'=>[[x0,y0,x1,y1,letter,order,row],…],
 *                                       'boxes'=>[letter=>[x,y,w,h,ch,row]], 'rows'=>[[x0,x1,y0,y1],…]]
 *       $rows: list of strings (stacked top→bottom)
 *       $o: w,h (cells), res, glyphW, glyphH (cells; or 'glyphW'=>['C'=>5,'*'=>6]),
 *           gap, wordGap, rowGap, radius, slant, top, align ('center'|'left'), font
 *   ssfField($layout, $o)            → ['dist'=>[y][x], 'letter'=>[y][x], 'order'=>[y][x],
 *                                       'maxOrder'=>int, 'pxW'=>, 'pxH'=>]
 *   ssfInk($field, $radius, ?$maxOrder) → [y][x] bool  (maxOrder: reveal up to a draw order)
 *   ssfCells($field, $o, ?$colourFn) → cell grid [glyph, fg, bg] (mono or coloured
 *       by $colourFn(letter, dist) → rgb); handy as a starting point / preview
 *   ssfGlyph($res, $bits)            → glyph for a sub-cell bit mask at that resolution
 *       bit order: row-major from top-left (bit 0 = TL, bit 1 = TR, bit 2 = next row L …)
 */

require_once __DIR__ . '/logo-kit.php';
require_once __DIR__ . '/sextant-canvas.php';     // scGlyph

const SSF_FONT = [
    'A' => [[[0, 20], [5, 0], [10, 20]], [[1.9, 13], [8.1, 13]]],
    'C' => [[['arc', 5, 10, 5, 10, 42, 318]]],
    'D' => [[[0, 0], [0, 20]], [[0, 0], [3, 0], ['arc', 3, 10, 7, 10, 90, -90], [0, 20]]],
    'N' => [[[0, 20], [0, 0], [10, 20], [10, 0]]],
    'O' => [[['arc', 5, 10, 5, 10, 90, 450]]],
    'P' => [[[0, 20], [0, 0]], [[0, 0], [5, 0], ['arc', 5, 5.25, 5, 5.25, 90, -90], [0, 10.5]]],
    'T' => [[[0, 0], [10, 0]], [[5, 0], [5, 20]]],
    'Y' => [[[0, 0], [5, 10], [10, 0]], [[5, 10], [5, 20]]],
    '-' => [[[2, 10], [8, 10]]],
];

/** @return array{0:int,1:int,2:float} [sub-px per cell x, y, sub-px height/width] */
function ssfRes(string $res): array
{
    return match ($res) {
        'braille' => [2, 4, 1.0],
        'sextant' => [2, 3, 4 / 3],
        'quadrant' => [2, 2, 2.0],
        'half' => [1, 2, 1.0],
        default => throw new InvalidArgumentException("res $res: braille|sextant|quadrant|half"),
    };
}

function ssfDefaults(array $o): array
{
    return $o + [
        'w' => 38, 'h' => 11, 'res' => 'braille', 'glyphW' => 6, 'glyphH' => 5, 'gap' => 1.0,
        'wordGap' => 3.0, 'rowGap' => 0.5, 'radius' => 1.8, 'slant' => 0.0, 'top' => 0.0,
        'align' => 'center', 'font' => SSF_FONT,
    ];
}

/** Lay rows of text out as stroke segments in sub-pixel space. */
function ssfLayout(array $rows, array $o): array
{
    $o = ssfDefaults($o);
    [$sx, $sy] = ssfRes($o['res']);
    $r = $o['radius'];
    $aspect = ssfRes($o['res'])[2];
    $gw = static fn (string $c): float => is_array($o['glyphW']) ? (float) ($o['glyphW'][$c] ?? $o['glyphW']['*']) : (float) $o['glyphW'];
    $segs = $boxes = $rowBoxes = [];
    $letter = 0;
    $order = 0;
    $y = $o['top'];
    foreach ($rows as $ri => $text) {
        $chars = str_split($text);
        $wCells = 0.0;
        foreach ($chars as $i => $c) {
            $wCells += $c === ' ' ? $o['wordGap'] : $gw($c);
            if ($i < count($chars) - 1 && $c !== ' ' && $chars[$i + 1] !== ' ') {
                $wCells += $o['gap'];
            }
        }
        $x = $o['align'] === 'center' ? ($o['w'] - $wCells) / 2 : 0.0;
        $rowBoxes[$ri] = [$x * $sx, ($x + $wCells) * $sx, $y * $sy, ($y + $o['glyphH']) * $sy];
        foreach ($chars as $i => $c) {
            if ($c === ' ') {
                $x += $o['wordGap'];
                continue;
            }
            $bw = $gw($c) * $sx;                       // box in sub-px
            $bh = $o['glyphH'] * $sy;
            $ry = $r / $aspect;                        // radius in sub-px rows
            $ox = $x * $sx + $r;
            $oy = $y * $sy + $ry;
            $kx = ($bw - 2 * $r) / 10;
            $ky = ($bh - 2 * $ry) / 20;
            $boxes[$letter] = [$x * $sx, $y * $sy, $bw, $bh, $c, $ri];
            foreach ($o['font'][$c] ?? [] as $stroke) {
                $pts = [];
                foreach ($stroke as $p) {
                    if ($p[0] === 'arc') {
                        [, $acx, $acy, $arx, $ary, $a0, $a1] = $p;
                        $n = (int) max(8, abs($a1 - $a0) / 9);
                        for ($k = 0; $k <= $n; $k++) {
                            $a = deg2rad($a0 + ($a1 - $a0) * $k / $n);
                            $pts[] = [$acx + $arx * cos($a), $acy - $ary * sin($a)];
                        }
                    } else {
                        $pts[] = $p;
                    }
                }
                $pts = array_map(static fn (array $p): array => [
                    $ox + $p[0] * $kx + $o['slant'] * (20 - $p[1]) / 20 * ($bw - 2 * $r),
                    $oy + $p[1] * $ky,
                ], $pts);
                for ($k = 1; $k < count($pts); $k++) {
                    $segs[] = [$pts[$k - 1][0], $pts[$k - 1][1], $pts[$k][0], $pts[$k][1], $letter, $order++, $ri];
                }
            }
            $letter++;
            $x += $gw($c) + (($chars[$i + 1] ?? ' ') !== ' ' ? $o['gap'] : 0);
        }
        $y += $o['glyphH'] + $o['rowGap'];
    }
    return ['segs' => $segs, 'boxes' => $boxes, 'rows' => $rowBoxes];
}

/** Distance field (sub-px widths) at every sub-pixel centre. */
function ssfField(array $layout, array $o): array
{
    $o = ssfDefaults($o);
    [$sx, $sy, $aspect] = ssfRes($o['res']);
    $pxW = (int) round($o['w'] * $sx);
    $pxH = (int) round($o['h'] * $sy);
    $dist = $let = $ord = [];
    $maxOrder = 0;
    foreach ($layout['segs'] as $s) {
        $maxOrder = max($maxOrder, $s[5]);
    }
    $reach = 4 * $o['radius'] + 12;
    for ($y = 0; $y < $pxH; $y++) {
        for ($x = 0; $x < $pxW; $x++) {
            $px = $x + 0.5;
            $py = $y + 0.5;
            [$best, $bl, $bo] = [INF, -1, 0];
            foreach ($layout['segs'] as [$x0, $y0, $x1, $y1, $l, $or]) {
                if ($px < min($x0, $x1) - $reach || $px > max($x0, $x1) + $reach) {
                    continue;
                }
                $dx = $x1 - $x0;
                $dy = ($y1 - $y0) * $aspect;
                $qx = $px - $x0;
                $qy = ($py - $y0) * $aspect;
                $len2 = $dx * $dx + $dy * $dy;
                $t = $len2 > 0 ? max(0.0, min(1.0, ($qx * $dx + $qy * $dy) / $len2)) : 0.0;
                $d = hypot($qx - $t * $dx, $qy - $t * $dy);
                if ($d < $best) {
                    [$best, $bl, $bo] = [$d, $l, $or];
                }
            }
            $dist[$y][$x] = $best;
            $let[$y][$x] = $bl;
            $ord[$y][$x] = $bo;
        }
    }
    return ['dist' => $dist, 'letter' => $let, 'order' => $ord, 'maxOrder' => $maxOrder, 'pxW' => $pxW, 'pxH' => $pxH];
}

function ssfInk(array $field, float $radius, ?float $maxOrder = null): array
{
    $ink = [];
    foreach ($field['dist'] as $y => $row) {
        foreach ($row as $x => $d) {
            $ink[$y][$x] = $d < $radius && ($maxOrder === null || $field['order'][$y][$x] <= $maxOrder);
        }
    }
    return $ink;
}

/** Glyph for a row-major sub-cell bit mask at a resolution. */
function ssfGlyph(string $res, int $bits): string
{
    switch ($res) {
        case 'braille':
            // row-major bits (TL,TR,2L,2R,3L,3R,4L,4R) → braille dot numbering
            $map = [0x01, 0x08, 0x02, 0x10, 0x04, 0x20, 0x40, 0x80];
            $b = 0;
            foreach ($map as $i => $v) {
                if ($bits & (1 << $i)) {
                    $b |= $v;
                }
            }
            return $b ? mb_chr(0x2800 + $b) : ' ';
        case 'sextant':
            return scGlyph($bits);                 // sextant numbering is already row-major
        case 'quadrant':
            return [' ', '▘', '▝', '▀', '▖', '▌', '▞', '▛', '▗', '▚', '▐', '▜', '▄', '▙', '▟', '█'][$bits];
        case 'half':
            return [' ', '▀', '▄', '█'][$bits];
    }
    throw new InvalidArgumentException("res $res");
}

/**
 * Field → cell grid. Mono (light grey) unless $colourFn(letter, dist) → rgb.
 * Letter colour = majority of the cell's inked sub-pixels.
 */
function ssfCells(array $field, array $o, ?callable $colourFn = null, ?float $maxOrder = null): array
{
    $o = ssfDefaults($o);
    [$sx, $sy] = ssfRes($o['res']);
    $ink = ssfInk($field, $o['radius'], $maxOrder);
    $cells = [];
    for ($cy = 0; $cy < $o['h']; $cy++) {
        for ($cx = 0; $cx < $o['w']; $cx++) {
            $bits = 0;
            $votes = [];
            $dSum = 0.0;
            for ($j = 0; $j < $sy; $j++) {
                for ($i = 0; $i < $sx; $i++) {
                    $x = $cx * $sx + $i;
                    $y = $cy * $sy + $j;
                    if ($ink[$y][$x] ?? false) {
                        $bits |= 1 << ($j * $sx + $i);
                        $l = $field['letter'][$y][$x];
                        $votes[$l] = ($votes[$l] ?? 0) + 1;
                        $dSum += $field['dist'][$y][$x];
                    }
                }
            }
            if (!$bits) {
                $cells[$cy][$cx] = [' ', null, null];
                continue;
            }
            arsort($votes);
            $fg = $colourFn ? $colourFn(array_key_first($votes), $dSum / array_sum($votes)) : [220, 220, 220];
            $cells[$cy][$cx] = [ssfGlyph($o['res'], $bits), $fg, null];
        }
    }
    return $cells;
}

// ================================================================ CLI

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $a = ['rows' => 'CANDY/TOP', 'w' => '38', 'h' => '11', 'res' => 'braille', 'glyph' => '6x5', 'gap' => '1',
        'word-gap' => '3', 'row-gap' => '0.5', 'radius' => '1.8', 'slant' => '0', 'top' => '0', 'colour' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '-h' || $arg === '--help') {
            preg_match('#/\*\*(.*?)\*/#s', file_get_contents(__FILE__), $m);
            echo preg_replace('/^ \* ?/m', '', $m[1]), "\n";
            exit(0);
        }
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
            $a[$m[1]] = $m[2] ?? true;
        }
    }
    [$gw, $gh] = array_map('floatval', explode('x', $a['glyph']));
    $o = ['w' => (int) $a['w'], 'h' => (int) $a['h'], 'res' => $a['res'], 'glyphW' => $gw, 'glyphH' => $gh,
        'gap' => (float) $a['gap'], 'wordGap' => (float) $a['word-gap'], 'rowGap' => (float) $a['row-gap'],
        'radius' => (float) $a['radius'], 'slant' => (float) $a['slant'], 'top' => (float) $a['top']];
    $layout = ssfLayout(explode('/', $a['rows']), $o);
    $field = ssfField($layout, $o);
    $hues = ['#FF3E9E', '#FF8A1F', '#FFE03A', '#6DFF4A', '#22EEFF', '#4F8DFF', '#B26BFF', '#FF4FE6'];
    $fn = $a['colour'] ? static fn (int $l, float $d): array => lkHex($hues[$l % count($hues)]) : null;
    $cells = ssfCells($field, $o, $fn);
    $out = lkEncode($cells, 'tc');
    echo $a['colour'] ? $out : lkStrip($out);
    [$sx, $sy] = ssfRes($o['res']);
    foreach ($layout['rows'] as $i => [$x0, $x1, $y0, $y1]) {
        printf("row %d: cells %.1f..%.1f (%.1f wide) rows %.1f..%.1f%s\n", $i, $x0 / $sx, $x1 / $sx, ($x1 - $x0) / $sx, $y0 / $sy, $y1 / $sy,
            ($x0 < 0 || $x1 / $sx > $o['w'] || $y1 / $sy > $o['h']) ? '  ** OVERFLOWS CANVAS **' : '');
    }
}
