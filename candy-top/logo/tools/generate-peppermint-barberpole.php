<?php

declare(strict_types=1);

/**
 * generate-peppermint-barberpole — CANDY TOP as outlined hard-candy sticks.
 *
 * Technique (reusable for any mask font):
 *   1. A glyph-mask font ('#' = candy body) is laid out with lkLayout.
 *   2. outlineRing() finds every empty cell 8-adjacent to the body; autotile()
 *      links two orthogonally adjacent ring cells only when they share a body
 *      cell in their 8-neighbourhoods, so contours of neighbouring letters
 *      never fuse, then picks a rounded box-drawing glyph from the links.
 *   3. Body cells get diagonal barber-pole bands: s = x + y + phase, period 4;
 *      s≡1 / s≡3 cells are ◤ wedges (fg = band above-left, bg = band below-
 *      right) so the stripe edge runs smoothly along the cell diagonal.
 *   4. Drop shadow = silhouette (body ∪ ring) shifted +1,+1 into empty cells.
 *   Animation: phase walks the stripes (barber pole), then a gloss sweep.
 *
 * Usage:
 *   php generate-peppermint-barberpole.php            preview + verify
 *   php generate-peppermint-barberpole.php --write    write .ansi + logos.jsonl
 *   php generate-peppermint-barberpole.php --outline  debug: outline only
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'peppermint-barberpole';
const TEXT = 'CANDY TOP';

/**
 * 7-row stick-candy font: rows 0 and 6 are the outline rows, rows 1-5 the body
 * (verticals 2 cols thick, horizontals 1 row). Single-byte codes:
 *   '#' body · 'a' ◣ 'b' ◥ 'c' ◤ 'd' ◢ half-body wedges (filled half striped)
 *   '.' auto (outline if it touches the body) · ' ' forced blank
 *   outline overrides: '\' ╲  '/' ╱  '-' ─  '|' │
 */
const FONT = [
    'C' => ['.......', '#######', '##.....', '##.....', '##.....', '#######', '.......'],
    'A' => ['.......', '#######', '##...##', '##...##', '#######', '##...##', '.......'],
    'N' => ['...-.....', '###a\ .##', '##b#a\|##', '##\b#a\##', '##|\b#a##', '##| \b###', '.....-...'],
    'D' => ['.......', '######.', '##...##', '##...##', '##...##', '######.', '.......'],
    'Y' => ['.......', '##...##', '##...##', '#######', '..###..', '..###..', '.......'],
    'T' => ['.......', '#######', '..###..', '..###..', '..###..', '..###..', '.......'],
    'O' => ['.......', '#######', '##...##', '##...##', '##...##', '#######', '.......'],
    'P' => ['.......', '#######', '##...##', '#######', '##.....', '##.....', '.......'],
];
const WEDGES = ['a' => '◣', 'b' => '◥', 'c' => '◤', 'd' => '◢'];
/** Edges of the cell each wedge fills completely (U/D/L/R). */
const WEDGE_EDGES = ['a' => 'DL', 'b' => 'UR', 'c' => 'UL', 'd' => 'DR'];
/** Outline overrides: glyph + the orthogonal stubs auto cells may link to. */
const OVERRIDES = ['\\' => ['╲', ''], '/' => ['╱', ''], '-' => ['─', 'LR'], '|' => ['│', 'UD']];
const LETTER_GAP = 2;     // 2 so each letter keeps its own outline column
const WORD_GAP = 4;

const PAL = [
    'redL' => '#FF2E4D', 'redR' => '#C80F3C',      // red stripe, left → right
    'creamT' => '#FFF6EC', 'creamB' => '#F4E2D4',  // cream stripe, top → bottom
    'mintT' => '#5CF2C4', 'mintB' => '#14A37F',    // outline, top → bottom
    'shadowNear' => '#5A2A3E', 'shadowFar' => '#2A1520',
    'sparkle' => '#FFC8DA',
];
const BAND = 4;           // stripe period in cells (2 red, 2 cream incl. wedges)
const GLOSS = 0.30;       // top body row tint toward white
const BASE_DARK = ['red' => 0.18, 'cream' => 0.04];   // bottom body row darkening
/** Sparkles [x, y, glyph] in absolute cell coords; only placed on empty cells. */
const SPARKLES = [[49, 5, '✦'], [48, 2, '·'], [50, 3, '⋅'], [46, 6, '·'], [0, 7, '·']];

/** 16-colour: keep red/cream/mint readable instead of washing out. */
const PINS16 = [];
function to16(array $c): int
{
    [$r, $g, $b] = $c;
    if ($r > 200 && $g > 190 && $b > 170) {
        return 97;                                   // cream
    }
    if ($r > 150 && $g < 120) {
        return $r > 215 ? 91 : 31;                   // red stripe
    }
    if ($g > $r + 40) {
        return $g > 190 ? 92 : 32;                   // mint outline
    }
    if ($r < 100 && $g < 60) {
        return 90;                                   // chocolate shadow
    }
    return lkTo16($c);
}

const DESCRIPTION = 'CANDY TOP as outlined hard-candy sticks: a 7x5 stick font whose bodies carry diagonal red/cream peppermint barber-pole stripes with smooth ◤ wedge edges, a mint-green rounded box-drawing outline auto-tiled from the mask, sugar-gloss top row and a chocolate ▒░ drop shadow.';
const TAGS = ['peppermint-barberpole', 'outlined-letters', 'box-drawing-outline', 'diagonal-wedge-stripes', 'candy-cane', 'mint-outline', 'drop-shadow'];

const ANIM_STRIPE_FRAMES = 16;   // phase 0..15 → stripes travel 4 periods
const ANIM_GLOSS_FRAMES = 10;
const ANIM_DELAY_MS = 75;        // 26 frames + final ≈ 2.0 s

// ================================================================ GEOMETRY

/**
 * @return array{0: list<list<string>>, 1: int, 2: int, 3: list<array{int,int}>}
 *         [code grid, w, h, letter boxes [x0,x1) incl. outline cols];
 *         codes: '#', wedge letters, override chars, ' ' blank, '' auto
 */
function codeGrid(): array
{
    [$mask, $ranges] = lkLayout(FONT, TEXT, LETTER_GAP, WORD_GAP, 0);
    $w = strlen($mask[0]) + 3;          // outline col each side + shadow col
    $h = count($mask) + 1;              // + shadow row
    $g = array_fill(0, $h, array_fill(0, $w, ''));
    foreach ($mask as $y => $row) {
        for ($x = 0; $x < strlen($row); $x++) {
            $c = $row[$x];
            $g[$y][$x + 1] = $c === '.' ? '' : $c;
        }
    }
    $boxes = array_map(static fn (array $r): array => [$r[0], $r[1] + 2], $ranges);
    return [$g, $w, $h, $boxes];
}

function isBody(string $c): bool
{
    return $c === '#' || isset(WEDGES[$c]);
}

/**
 * Does the body cell at (x+dx, y+dy) touch the cell at (x, y)? Wedges only
 * touch across the edges they fill completely.
 */
function touches(array $g, int $x, int $y, int $dx, int $dy): bool
{
    $c = $g[$y + $dy][$x + $dx] ?? '';
    if (!isBody($c)) {
        return false;
    }
    if ($c === '#') {
        return true;
    }
    // direction from the wedge back toward (x,y)
    $need = ($dy > 0 ? 'U' : ($dy < 0 ? 'D' : '')) . ($dx > 0 ? 'L' : ($dx < 0 ? 'R' : ''));
    foreach (str_split($need) as $e) {
        if (!str_contains(WEDGE_EDGES[$c], $e)) {
            return false;
        }
    }
    return true;
}

/** Body cells touching (x,y), as "x,y" keys. */
function bodyNbrs(array $g, int $x, int $y): array
{
    $out = [];
    for ($dy = -1; $dy <= 1; $dy++) {
        for ($dx = -1; $dx <= 1; $dx++) {
            if (($dx || $dy) && touches($g, $x, $y, $dx, $dy)) {
                $out[($x + $dx) . ',' . ($y + $dy)] = true;
            }
        }
    }
    return $out;
}

/** Auto cells touching the body. */
function outlineRing(array $g, int $w, int $h): array
{
    $ring = array_fill(0, $h, array_fill(0, $w, false));
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $ring[$y][$x] = $g[$y][$x] === '' && bodyNbrs($g, $x, $y) !== [];
        }
    }
    return $ring;
}

const TILES = [
    '' => '·', 'U' => '╵', 'D' => '╷', 'L' => '╴', 'R' => '╶',
    'UD' => '│', 'LR' => '─', 'DR' => '╭', 'DL' => '╮', 'UR' => '╰', 'UL' => '╯',
    'UDR' => '├', 'UDL' => '┤', 'DLR' => '┬', 'ULR' => '┴', 'UDLR' => '┼',
];
const OPPOSITE = ['U' => 'D', 'D' => 'U', 'L' => 'R', 'R' => 'L'];

/**
 * Outline glyph per cell ("x,y" => glyph). Two auto cells link when they
 * touch a common body cell (so neighbouring letters' contours never fuse);
 * an auto cell links to an override when the override has a stub facing it.
 */
function autotile(array $g, array $ring, int $w, int $h): array
{
    $out = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $code = $g[$y][$x];
            if (isset(OVERRIDES[$code])) {
                $out["$x,$y"] = OVERRIDES[$code][0];
                continue;
            }
            if (!$ring[$y][$x]) {
                continue;
            }
            $mine = bodyNbrs($g, $x, $y);
            $k = '';
            foreach (['U' => [0, -1], 'D' => [0, 1], 'L' => [-1, 0], 'R' => [1, 0]] as $d => [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                $nc = $g[$ny][$nx] ?? '';
                if (isset(OVERRIDES[$nc])) {
                    if (str_contains(OVERRIDES[$nc][1], OPPOSITE[$d])) {
                        $k .= $d;
                    }
                } elseif (($ring[$ny][$nx] ?? false) && array_intersect_key($mine, bodyNbrs($g, $nx, $ny)) !== []) {
                    $k .= $d;
                }
            }
            $out["$x,$y"] = TILES[$k];
        }
    }
    return $out;
}

// ================================================================ PAINT

function stripeColour(bool $red, int $x, int $y, int $w, int $top, int $bot): array
{
    $ty = ($y - $top) / max(1, $bot - $top);
    return $red
        ? lkScale(lkGradient([PAL['redL'], PAL['redR']], $x / ($w - 1)), 1 - 0.18 * $ty)
        : lkGradient([PAL['creamT'], PAL['creamB']], $ty);
}

/**
 * @param int $phase stripe offset (barber-pole animation)
 * @param ?float $sweep gloss sweep diagonal position (x + 2y), null = none
 * @return list<list<array>> cells
 */
function cells(int $phase = 0, ?float $sweep = null, bool $outlineOnly = false): array
{
    [$g, $w, $h, $boxes] = codeGrid();
    $ring = outlineRing($g, $w, $h);
    $tiles = autotile($g, $ring, $w, $h);
    $cells = lkBlankCells($w, $h);
    $top = 1;
    $bot = $h - 3;
    $inBox = static function (int $x, int $y) use ($boxes, $h): bool {
        foreach ($boxes as [$a, $b]) {
            if ($x >= $a && $x < $b && $y < $h - 1) {
                return true;
            }
        }
        return false;
    };
    $solid = static fn (int $x, int $y): bool => isBody($g[$y][$x] ?? '') || isset($tiles["$x,$y"]);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $code = $g[$y][$x];
            if (isBody($code)) {
                if ($outlineOnly) {
                    $cells[$y][$x] = [$code === '#' ? ' ' : WEDGES[$code], [90, 90, 90], null];
                    continue;
                }
                $tweak = static function (array $c, float $dark) use ($g, $x, $y, $sweep): array {
                    if (!isBody($g[$y - 1][$x] ?? '')) {
                        $c = lkMix($c, [255, 255, 255], GLOSS);
                    } elseif (!isBody($g[$y + 1][$x] ?? '')) {
                        $c = lkScale($c, 1 - $dark);
                    }
                    if ($sweep !== null) {
                        $d = abs(($x + 2 * $y) - $sweep);
                        if ($d < 3) {
                            $c = lkMix($c, [255, 255, 255], 0.6 * (1 - $d / 3));
                        }
                    }
                    return $c;
                };
                $red = $tweak(stripeColour(true, $x, $y, $w, $top, $bot), BASE_DARK['red']);
                $cream = $tweak(stripeColour(false, $x, $y, $w, $top, $bot), BASE_DARK['cream']);
                $s = (($x + $y + $phase) % BAND + BAND) % BAND;
                if ($code !== '#') {
                    $cells[$y][$x] = [WEDGES[$code], $s <= 1 ? $red : $cream, null];
                    continue;
                }
                $cells[$y][$x] = match ($s) {
                    0 => [' ', null, $red],
                    1 => ['◤', $red, $cream],
                    2 => [' ', null, $cream],
                    3 => ['◤', $cream, $red],
                };
            } elseif (isset($tiles["$x,$y"])) {
                $mint = lkGradient([PAL['mintT'], PAL['mintB']], $y / ($h - 2));
                $cells[$y][$x] = [$tiles["$x,$y"], $mint, null];
            } elseif (!$outlineOnly && !$inBox($x, $y) && $solid($x - 1, $y - 1)) {
                $deep = $solid($x - 1, $y) || $solid($x, $y - 1);
                $cells[$y][$x] = $deep ? ['▒', lkHex(PAL['shadowNear']), null] : ['░', lkHex(PAL['shadowFar']), null];
            }
        }
    }
    foreach (SPARKLES as [$sx, $sy, $glyph]) {
        if (($cells[$sy][$sx][0] ?? null) === ' ' && $cells[$sy][$sx][2] === null) {
            $cells[$sy][$sx] = [$glyph, lkHex(PAL['sparkle']), null];
        }
    }
    return $cells;
}

/** lkEncode, but with this design's 16-colour mapping. */
function encode(array $cells, string $depth): string
{
    if ($depth !== '16') {
        return lkEncode($cells, $depth, PINS16);
    }
    $pins = [];
    foreach ($cells as $row) {
        foreach ($row as [, $fg, $bg]) {
            foreach ([$fg, $bg] as $c) {
                if ($c !== null) {
                    $pins[implode(',', $c)] = to16($c);
                }
            }
        }
    }
    return lkEncode($cells, '16', $pins);
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
if (in_array('--outline', $argv, true)) {
    echo encode(cells(0, null, true), 'tc');
    exit;
}
$dir = dirname(__DIR__);
$width = count(cells()[0]);
foreach (['tc', '256', '16'] as $depth) {
    $static = encode(cells(), $depth);
    [$w, $h] = lkVerify($static, $depth);
    echo $static;
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    $frames = [];
    for ($f = 1; $f <= ANIM_STRIPE_FRAMES; $f++) {
        $frames[] = encode(cells($f), $depth);
    }
    $span = $width + 2 * 9;
    for ($f = 0; $f < ANIM_GLOSS_FRAMES; $f++) {
        $frames[] = encode(cells(0, -6 + ($span + 12) * $f / (ANIM_GLOSS_FRAMES - 1)), $depth);
    }
    $anim = lkAnim($frames, $static);
    if ($write) {
        $r = lkWriteLogo($dir, SLUG, $depth, $static, DESCRIPTION . " ($depth)", TAGS);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        $r = lkWriteLogo($dir, SLUG . '-anim', $depth, $anim, 'Animated ' . DESCRIPTION . ' Barber-pole stripes travel through the letters, then a gloss sweep; ' . (count($frames) + 1) . ' frames, play with tools/logo-play.php <file> ' . ANIM_DELAY_MS . " (~2s) ($depth)", [...TAGS, 'animated', 'barber-pole-scroll', 'gloss-sweep']);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
}
