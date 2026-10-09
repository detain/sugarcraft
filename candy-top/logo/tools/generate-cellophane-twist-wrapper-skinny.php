<?php

declare(strict_types=1);

/**
 * generate-cellophane-twist-wrapper-skinny — 40-col stacked variant of
 * generate-cellophane-twist-wrapper.php (same palette, bows, gloss and swash;
 * 8x8 px font, CANDY over TOP, swashes flank TOP). Original header follows.
 *
 * generate-cellophane-twist-wrapper — "CANDY TOP" as one wrapped hard candy.
 *
 * The letters are cream cut-outs pressed into a glossy cherry pill-shaped
 * candy body: cylinder shading on the background colour, an embossed drop
 * shadow, and a diagonal specular streak. Each end pinches into a twisted
 * cellophane bow. The bow is a fan mask that radiates crinkle folds (░▒▓
 * density) in iridescent cyan → lilac → pink, with a two-tone twisted knot
 * (▚▞) and white glints.
 *
 * Everything is drawn on a 2x2-per-cell pixel canvas and turned into cells by
 * quadrant-raster.php, so the letters get rounded quadrant corners. Full
 * cellophane cells are then swapped for shade glyphs.
 *
 * Usage:
 *   php generate-cellophane-twist-wrapper-skinny.php           preview tc/256/16 (+ verify)
 *   php generate-cellophane-twist-wrapper-skinny.php --write   write .ansi files + logos.jsonl
 *   php generate-cellophane-twist-wrapper-skinny.php --anim    also preview the animation
 *
 * Swap PALETTE / FONT / layout constants to reskin (e.g. a lime or grape candy).
 */

require_once __DIR__ . '/skinny-kit.php';    // logo-kit + quadrant-raster + skinny writers

// ================================================================ DESIGN DATA

const SLUG = 'cellophane-twist-wrapper-skinny';
const TEXT = 'CANDY TOP';
/** Stacked lines; each centred in the body. */
const LINES = ['CANDY', 'TOP'];
const LINE_ROWS = [2, 6];      // top cell row of each line (+0.5 px nudge below)
const LINE_DY = [0, 1];        // extra px drop per line

/** Canvas in cells. Pixels are 2x2 per cell. */
const COLS = 40;
const ROWS = 12;
const TWIST_COLS = 6;          // each cellophane bow
const BODY_ROW0 = 1;           // body cell rows 1..10
const BODY_ROW1 = 10;
const GLYPH_H = 8;             // px

/**
 * 8 px x 8 px glyphs (4 cols x 4 rows): 2 px strokes, bowls and corners cut
 * back so the quadrants draw them round. The narrow cousin of the full logo's
 * 12x10 face.
 */
const FONT = [
    'C' => ['..######', '.#######', '##......', '##......', '##......', '##......', '.#######', '..######'],
    'A' => ['..####..', '.######.', '##....##', '##....##', '########', '########', '##....##', '##....##'],
    'N' => ['##....##', '###...##', '####..##', '##.##.##', '##..####', '##...###', '##....##', '##....##'],
    'D' => ['######..', '#######.', '##...###', '##....##', '##....##', '##...###', '#######.', '######..'],
    'Y' => ['##....##', '##....##', '##....##', '.##..##.', '..####..', '...##...', '...##...', '...##...'],
    'T' => ['########', '########', '...##...', '...##...', '...##...', '...##...', '...##...', '...##...'],
    'O' => ['..####..', '.######.', '##....##', '##....##', '##....##', '##....##', '.######.', '..####..'],
    'P' => ['######..', '#######.', '##....##', '##....##', '#######.', '######..', '##......', '##......'],
];

const PALETTE = [
    // body cylinder shading, top → bottom (t = 0..1 over the body's pixel rows)
    'body' => ['#FFB3C9', '#FF6F9C', '#E8245E', '#B8154A', '#8E0E3A'],
    // per-letter candy colours [top, bottom], in TEXT order (spaces skipped)
    'letters' => [
        ['#FFF8B8', '#FFC93C'],   // C lemon
        ['#D8FFE8', '#36DB86'],   // A mint
        ['#DAF4FF', '#3AAEFF'],   // N sky
        ['#FFE6C8', '#FF9436'],   // D tangerine
        ['#F6FFC8', '#A8E83A'],   // Y lime
        ['#D4FFF8', '#26D9C2'],   // T aqua
        ['#FFF1BC', '#FFB83A'],   // O butterscotch
        ['#EEE8FF', '#A68AFF'],   // P lilac
    ],
    'shadow' => '#4E0620',
    'white' => '#FFFFFF',
    // underline swash ribbon, left → right
    'swash' => ['#FFE45C', '#7DFFB0', '#7FD8FF', '#D9A8FF', '#FFE45C'],
    // thin-film cellophane cycle
    'film' => ['#6FE6FF', '#B49BFF', '#FF86CF', '#6FE6FF'],
    'knot' => ['#7F6FD1', '#C46BC8'],
];

/**
 * Flat 16-colour roles. With $flat set, the colour functions return these
 * anchors instead of gradients, and PINS16 maps each anchor to its ANSI code.
 */
const FLAT = [
    'body' => '#C81E50', 'bodyTop' => '#FF6F9C', 'gloss' => '#FF8FB0',
    'shadow' => '#3A0614', 'white' => '#FFFFFF', 'swash' => '#FFE45D',
    'L0' => '#FFE45C', 'L1' => '#7DFFB0', 'L2' => '#7FD8FF', 'L3' => '#FFFFF0',
    'L4' => '#FFE45B', 'L5' => '#7DFFB1', 'L6' => '#7FD8FE', 'L7' => '#FFFFF1',
    'film0' => '#9FF3FF', 'film1' => '#C9B6FF', 'film2' => '#FFB6E1',
    'knot0' => '#7F6FD1', 'knot1' => '#C46BC8',
];
const PINS16 = [
    '#C81E50' => 31, '#FF6F9C' => 91, '#FF8FB0' => 91, '#3A0614' => 30, '#FFFFFF' => 97, '#FFE45D' => 93,
    '#FFE45C' => 93, '#7DFFB0' => 92, '#7FD8FF' => 96, '#FFFFF0' => 97,
    '#FFE45B' => 93, '#7DFFB1' => 92, '#7FD8FE' => 96, '#FFFFF1' => 97,
    '#9FF3FF' => 96, '#C9B6FF' => 94, '#FFB6E1' => 95,
    '#7F6FD1' => 34, '#C46BC8' => 35,
];

const GLOSS_REST = 66.0;       // px x of the specular streak's top end at rest
const GLOSS_SLANT = -0.1;      // px right per px down
const SEED = 7;
/** Sparkles on the body's top row (cell col, glyph), per twinkle phase. */
const BODY_SPARKLES = [[[9, '✦'], [30, '✧']], [[9, '✧'], [30, '✦']]];

const DESCRIPTION = 'Skinny wrapped hard candy: CANDY stacked over TOP in rounded 4x4-cell quadrant-block letters, each a different bevelled candy colour (lemon, mint, sky, tangerine, lime, aqua, butterscotch, lilac) with a white top highlight edge and drop shadow, TOP flanked by tapering rainbow ribbon swashes, pressed into a glossy cherry candy body (cylinder-shaded background, diagonal specular streak, sparkles), pinched at both ends into twisted iridescent cellophane bows of ░▒▓ crinkle folds with glints.';
const TAGS = ['skinny', 'stacked-words', 'candy-wrapper', 'cellophane-twist', 'glossy-specular', 'per-letter-candy-colours', 'bevel-highlight', 'ribbon-swash', 'iridescent', 'rounded-quadrant-font'];

// ================================================================ BUILD

$GLOBALS['flat'] = false;

function hx(string $role): array
{
    return lkHex(FLAT[$role]);
}

function hexes(array $l): array
{
    return array_map('lkHex', $l);
}

/** Per-pixel letter index (-1 = none) and per-letter top px row, sized to the canvas. */
function letterMap(): array
{
    static $grid = null;
    if ($grid !== null) {
        return $grid;
    }
    $grid = array_fill(0, ROWS * 2, array_fill(0, COLS * 2, -1));
    $base = 0;
    foreach (LINES as $li => $word) {
        [$mask, $ranges] = lkLayout(FONT, $word, letterGap: 2, wordGap: 6);
        $x0 = intdiv(COLS * 2 - strlen($mask[0]), 2);
        $y0 = LINE_ROWS[$li] * 2 + LINE_DY[$li];
        foreach ($mask as $y => $line) {
            for ($x = 0; $x < strlen($line); $x++) {
                if ($line[$x] !== '#') {
                    continue;
                }
                foreach ($ranges as $i => [$a, $b]) {
                    if ($x >= $a && $x < $b) {
                        $grid[$y0 + $y][$x0 + $x] = $base + $i;
                    }
                }
            }
        }
        $base += count($ranges);
    }
    return $grid;
}

/** Top px row of letter $i (for its vertical gradient). */
function letterTop(int $i): int
{
    return LINE_ROWS[$i < 5 ? 0 : 1] * 2 + LINE_DY[$i < 5 ? 0 : 1];
}

function inBody(float $cx, float $cy): bool
{
    $x0 = TWIST_COLS * 2;
    $x1 = (COLS - TWIST_COLS) * 2;
    $y0 = BODY_ROW0 * 2;
    $y1 = (BODY_ROW1 + 1) * 2;
    if ($cx < $x0 || $cx > $x1 || $cy < $y0 || $cy > $y1) {
        return false;
    }
    // elliptical corners: rx 5 px, ry 4 px
    $rx = 5.0;
    $ry = 4.0;
    $qx = max(0.0, max($x0 + $rx - $cx, $cx - ($x1 - $rx)));
    $qy = max(0.0, max($y0 + $ry - $cy, $cy - ($y1 - $ry)));
    return ($qx / $rx) ** 2 + ($qy / $ry) ** 2 <= 1.0;
}

/**
 * Flanking swashes: a wavy ribbon either side of TOP ("~ TOP ~") that swells
 * near the word and tapers to a fine point toward the candy's ends.
 * @return ?array{0:float,1:bool} [u 0..1 (0 = outer tip), isTopEdge] or null
 */
function swashAt(float $cx, float $cy): ?array
{
    $L = letterMap();
    $y0 = LINE_ROWS[1] * 2 + LINE_DY[1];
    $mid = $y0 + GLYPH_H / 2 - 0.5;
    // TOP's ink extent on this canvas
    $xl = PHP_INT_MAX;
    $xr = -1;
    foreach ($L[$y0] as $x => $id) {
        if ($id >= 5) {
            $xl = min($xl, $x);
            $xr = max($xr, $x);
        }
    }
    $outerL = TWIST_COLS * 2 + 2.5;
    $outerR = (COLS - TWIST_COLS) * 2 - 2.5;
    if ($cx > $outerL && $cx < $xl - 2.5) {
        $u = ($cx - $outerL) / ($xl - 2.5 - $outerL);
    } elseif ($cx > $xr + 3.5 && $cx < $outerR) {
        $u = ($outerR - $cx) / ($outerR - $xr - 3.5);
    } else {
        return null;
    }
    $yc = $mid + 0.6 * (1 - $u) ** 2;          // dips slightly toward the tips
    $half = 0.3 + 0.7 * $u ** 0.8;
    if (abs($cy - $yc) <= $half) {
        return [$u, $cy < $yc];
    }
    return null;
}

/** Bevelled candy colour of letter pixel (x, y) in letter $i. */
function letterColour(int $i, int $x, int $y, float $gl): array
{
    $L = letterMap();
    $flat = $GLOBALS['flat'];
    if ($flat) {
        return $gl > 0 ? hx('white') : hx("L$i");
    }
    [$top, $bot] = hexes(PALETTE['letters'][$i]);
    $t = ($y - letterTop($i)) / (GLYPH_H - 1);
    $c = lkMix($top, $bot, $t ** 0.8);
    // bevel: lit top edge, shaded bottom edge (side edges left alone; a 3 px
    // stroke shaded on both sides rasterises as a hollow outline)
    if (($L[$y - 1][$x] ?? -1) !== $i) {
        $c = lkMix($c, lkHex(PALETTE['white']), 0.6);
    } elseif (($L[$y + 1][$x] ?? -1) !== $i) {
        $c = lkShade($c, -0.18);
    }
    if ($gl > 0) {
        $c = lkMix($c, lkHex(PALETTE['white']), 0.6 * $gl);
    }
    return $c;
}

/**
 * Cellophane bow geometry relative to the knot. $dx > 0 = away from the body.
 * @return array{0:string,1:float} ['', 0] outside | ['knot', 0] | ['film', density]
 */
function twistAt(float $dx, float $dy, int $side): array
{
    if ($dx <= 0) {
        return ['', 0.0];
    }
    if ($dx < 2.6 && abs($dy) < 2.6) {
        return ['knot', 0.0];
    }
    if ($dx < 2.6) {
        return ['', 0.0];
    }
    $t = min(1.0, ($dx - 2.6) / (TWIST_COLS * 2 - 3.6));
    $half = 2.0 + (ROWS - 1.4) * $t ** 1.25;
    $half *= 1 - 0.06 * sin($dx * 1.9 + $side);               // crinkled top/bottom edge
    $tip = TWIST_COLS * 2 - 0.4 - 1.4 * abs(sin($dy * 0.95 + $side * 0.6));    // scalloped tip edge
    if (abs($dy) > $half || $dx > $tip) {
        return ['', 0.0];
    }
    $theta = atan2($dy, $dx);
    $fold = 0.5 + 0.5 * cos($theta * 7.0 + 0.9 * sin($dx * 0.6 + $side));
    $d = 0.35 + 0.65 * $fold ** 1.5 + 0.3 * (1 - $t) - 0.15 * lkNoise((int) $dx, (int) ($dy * 2), SEED + $side);
    return ['film', $d];
}

function filmColour(float $dx, float $dy, int $side): array
{
    $flat = $GLOBALS['flat'];
    $u = fmod($dx / 15 + atan2($dy, $dx) * 0.22 + 0.15 * $side + 4.0, 1.0);
    if ($flat) {
        return hx('film' . min(2, (int) floor($u * 3)));
    }
    return lkGradient(hexes(PALETTE['film']), $u);
}

/**
 * Pixel canvas plus the per-cell cellophane info for the post-pass.
 * @return array{0:list<list<?array>>,1:array<string,array>} [px, film]
 */
function canvas(float $gloss): array
{
    $flat = $GLOBALS['flat'];
    $W = COLS * 2;
    $H = ROWS * 2;
    $L = letterMap();
    $px = array_fill(0, $H, array_fill(0, $W, null));
    $film = [];   // "cx,cy" cell → [density sum, count, colour]

    $knotY = ((BODY_ROW0 * 2) + (BODY_ROW1 + 1) * 2) / 2;   // body centre (px)
    $kl = TWIST_COLS * 2;
    $kr = (COLS - TWIST_COLS) * 2;
    $white = lkHex(PALETTE['white']);

    for ($y = 0; $y < $H; $y++) {
        $cy = $y + 0.5;
        for ($x = 0; $x < $W; $x++) {
            $cx = $x + 0.5;
            if (inBody($cx, $cy)) {
                $t = ($cy - BODY_ROW0 * 2) / ((BODY_ROW1 - BODY_ROW0 + 1) * 2);
                $g = $cx - $gloss - ($cy - BODY_ROW0 * 2) * GLOSS_SLANT;     // slants down-right
                $gl = abs($g) < 1.6 ? 1.0 : (($g < -3.2 && $g > -4.6) ? 0.55 : 0.0);
                $sw = swashAt($cx, $cy);
                $swShadow = false;
                if ($L[$y][$x] >= 0) {
                    $c = letterColour($L[$y][$x], $x, $y, $gl);
                } elseif ($sw !== null) {
                    [$u, $topEdge] = $sw;
                    if ($flat) {
                        $c = hx('swash');
                    } else {
                        $c = lkGradient(hexes(PALETTE['swash']), $cx < COLS ? $u * 0.5 : 1 - $u * 0.5);
                        $c = $topEdge ? lkMix($c, $white, 0.5) : lkShade($c, -0.1);
                        if ($gl > 0) {
                            $c = lkMix($c, $white, 0.5 * $gl);
                        }
                    }
                } elseif (($L[$y - 1][$x - 1] ?? -1) >= 0 || $swShadow) {
                    $c = $flat ? hx('shadow') : lkHex(PALETTE['shadow']);
                    if ($gl > 0 && !$flat) {
                        $c = lkMix($c, lkHex(PALETTE['body'][1]), 0.5 * $gl);
                    }
                } elseif ($flat) {
                    $c = $gl > 0 ? hx('gloss') : ($y <= BODY_ROW0 * 2 ? hx('bodyTop') : hx('body'));
                } else {
                    $c = lkGradient(hexes(PALETTE['body']), min(1.0, $t * 1.15));
                    if ($gl > 0) {
                        $c = lkMix($c, $white, 0.4 * $gl);
                    }
                }
                $px[$y][$x] = $c;
                continue;
            }
            foreach ([[-1, $kl - $cx], [1, $cx - $kr]] as [$side, $dx]) {
                $dy = $cy - $knotY;
                [$kind, $d] = twistAt($dx, $dy, $side);
                if ($kind === 'knot') {
                    $tone = ((int) floor(($x * $side + $y) / 2)) & 1;
                    $px[$y][$x] = $flat ? hx('knot' . $tone) : lkHex(PALETTE['knot'][$tone]);
                } elseif ($kind === 'film') {
                    $cell = intdiv($x, 2) . ',' . intdiv($y, 2);
                    // one colour per cell so partial cells rasterise to one quadrant glyph
                    $ccx = intdiv($x, 2) * 2 + 1.0;
                    $ccy = intdiv($y, 2) * 2 + 1.0;
                    $cdx = $side < 0 ? $kl - $ccx : $ccx - $kr;
                    $film[$cell] ??= [0.0, 0, filmColour($cdx, $ccy - $knotY, $side)];
                    $film[$cell][0] += $d;
                    $film[$cell][1]++;
                    $px[$y][$x] = $film[$cell][2];
                }
            }
        }
    }
    return [$px, $film];
}

/** @return list<list<array>> cells */
function cells(float $gloss = GLOSS_REST, int $twinkle = 0): array
{
    $flat = $GLOBALS['flat'];
    [$px, $film] = canvas($gloss);
    $cells = qrRaster($px);

    // full cellophane cells → crinkle shade by fold density
    foreach ($film as $key => [$sum, $n, $c]) {
        [$x, $y] = array_map('intval', explode(',', $key));
        if ($n < 4) {
            continue;
        }
        $d = $sum / $n;
        [$g, $k] = match (true) {
            $d > 0.88 => ['▓', 0.30],
            $d > 0.52 => ['▒', 0.05],
            default => ['░', 0.25],
        };
        $cells[$y][$x] = [$g, $flat ? $c : lkShade($c, $k), null];
    }

    // glints on the bows (positions twinkle in the animation)
    $white = $flat ? hx('white') : lkHex(PALETTE['white']);
    $mid = intdiv(ROWS, 2);
    $spots = [
        [[2, 2], [5, $mid + 2], [COLS - 3, $mid + 2], [COLS - 6, 2]],
        [[4, 2], [2, $mid + 2], [COLS - 5, $mid + 2], [COLS - 3, 2]],
    ][$twinkle & 1];
    foreach ($spots as [$x, $y]) {
        if ($cells[$y][$x][0] !== ' ') {
            $cells[$y][$x] = ['✦', $white, null];
        }
    }
    // sparkles on the candy's top row, and one atop the gloss streak
    $sparkles = BODY_SPARKLES[$twinkle & 1];
    $gx = (int) floor(($gloss + 1.5) / 2);
    if ($gx >= TWIST_COLS + 2 && $gx < COLS - TWIST_COLS - 2) {
        $sparkles[] = [$gx, '✦'];
    }
    foreach ($sparkles as [$x, $glyph]) {
        $c = $cells[BODY_ROW0][$x];
        $cells[BODY_ROW0][$x] = [$glyph, $white, $c[0] === '█' ? $c[1] : ($c[2] ?? $c[1])];
    }
    return $cells;
}

function encodeAt(string $depth, float $gloss = GLOSS_REST, int $twinkle = 0): string
{
    $GLOBALS['flat'] = $depth === '16';
    $out = lkEncode(cells($gloss, $twinkle), $depth, PINS16);
    $GLOBALS['flat'] = false;
    return $out;
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$showAnim = in_array('--anim', $argv, true);
$dir = dirname(__DIR__);
foreach (['tc', '256', '16'] as $depth) {
    $static = encodeAt($depth);
    [$w, $h] = lkVerify($static, $depth);
    lkCheckDepth($static, $depth, $depth);
    echo $static;
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");

    // animation: the specular streak slides in from the left and eases onto its
    // rest position while the bow glints twinkle. 14 frames + static @ 100 ms = 1.5 s.
    $frames = [];
    $n = 14;
    for ($f = 0; $f < $n; $f++) {
        $e = 1 - (1 - $f / $n) ** 2.2;                    // ease-out
        $frames[] = encodeAt($depth, -16 + (GLOSS_REST + 16) * $e, $f >> 1);
    }
    $anim = lkAnim($frames, $static);
    if ($showAnim) {
        echo $anim, "\n";
    }
    if ($write) {
        $r = skWriteSkinny($dir, SLUG, $depth, $static, DESCRIPTION . " ($depth)", TAGS);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        $r = skWriteSkinny($dir, SLUG . '-anim', $depth, $anim,
            'Animated ' . DESCRIPTION . " The specular streak sweeps across the candy and settles while the cellophane glints twinkle; ~1.4 s at 100 ms/frame via tools/logo-play.php, ends on the static frame ($depth)",
            [...TAGS, 'animated', 'gloss-sweep']);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
}
