<?php

declare(strict_types=1);

/**
 * generate-chocolate-bar-emboss — "CANDY TOP" embossed in relief on a
 * moulded chocolate bar, lit from the upper-left, with break-off grooves
 * and a crinkled gold-foil wrapper torn back over the right end.
 *
 * Technique (no pixel-art font): a 2-col-stroke glyph mask is run through a
 * generic emboss shader — every cell gets a top-half and bottom-half relief
 * value from its four neighbours (lit on the up/left edges, shaded on the
 * down/right edges). Equal halves → a plain bg cell; unequal → '▄' with the
 * two tones, which gives 1-row horizontal strokes a lit top AND a shaded
 * underside. Neighbouring slab cells get a cast shadow; '◢◣◤◥' round the
 * chamfered corners and tear the foil edge; '░▒▓' crinkle the foil and
 * grain the cocoa.
 *
 * Everything design-specific is data at the top (font, palette roles,
 * light weights, groove positions, foil width, seeds). The shader
 * (emboss()), compositor (cells()) and the 16-colour role pins are generic
 * enough to re-skin: swap ROLES for e.g. white chocolate or a mint slab.
 *
 * Usage:
 *   php generate-chocolate-bar-emboss.php                 preview tc/256/16 to stdout + verify
 *   php generate-chocolate-bar-emboss.php --write         write .ansi files + logos.jsonl lines
 *   php generate-chocolate-bar-emboss.php --out=<dir>     write the files into <dir> instead (no jsonl)
 *   play the animation: php logo-play.php ../logo-chocolate-bar-emboss-anim-tc-WxH.ansi 120
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'chocolate-bar-emboss';
const TEXT = 'CANDY TOP';

/**
 * Slab face, 6 rows. '#' = moulded stroke; corner wedges (the corner is
 * chocolate, the rest of the cell is slab): a=◢ b=◣ c=◥ d=◤.
 */
const FONT = [
    'C' => ['a#####', '##....', '##....', '##....', '##....', 'c#####'],
    'A' => ['a####b', '##..##', '##..##', '######', '##..##', '##..##'],
    'N' => ['##b..##', '###b.##', '##c#b##', '##.c###', '##..c##', '##...##'],
    'D' => ['#####b', '##..##', '##..##', '##..##', '##..##', '#####d'],
    'Y' => ['##..##', '##..##', 'c#..#d', '.c##d.', '..##..', '..##..'],
    'T' => ['######', '..##..', '..##..', '..##..', '..##..', '..##..'],
    'O' => ['a####b', '##..##', '##..##', '##..##', '##..##', 'c####d'],
    'P' => ['#####b', '##..##', '##..##', '#####d', '##....', '##....'],
];
const WEDGES = ['a' => '◢', 'b' => '◣', 'c' => '◥', 'd' => '◤'];

const LETTER_GAP = 2;
const WORD_GAP = 5;
const PAD_LEFT = 2;          // slab between left rim and C
const PAD_RIGHT = 1;         // slab between P and the foil tear
const FOIL = 7;              // foil-wrapped columns at the right end
/** Grooves (break lines) sit in the gap AFTER these letter indices (0=C). */
const GROOVES_AFTER = [4];
const SHADOW_ROW = true;     // soft drop shadow row under the bar
const SEED = 7;

/**
 * Palette roles: role => [tc hex, 16-colour fg code, xterm-256 index]. tc
 * shades these continuously; the 16 and 256 builds snap every cell to its
 * role and pin the code, so the downgrade is designer-tuned (nearest-match
 * turned the dark browns grey / olive). Hexes must be unique (pin keys).
 */
const ROLES = [
    //              tc hex      16   256
    'slab'      => ['#3E2215', 90, 235],
    'grain'     => ['#553019', 37, 52],
    'rimHi'     => ['#7A4A2C', 37, 94],
    'rimLo'     => ['#1E0F08', 30, 233],
    'groove'    => ['#1A0D07', 30, 232],
    'grooveHi'  => ['#5E3822', 90, 237],
    'cast'      => ['#24120A', 30, 233],
    'face'      => ['#9A6440', 31, 137],
    'hiMid'     => ['#C2875A', 91, 173],
    'hi'        => ['#E3A874', 91, 180],
    'loMid'     => ['#73452B', 31, 95],
    'lo'        => ['#4A2817', 30, 239],
    'foil'      => ['#C9971F', 33, 178],
    'foilDark'  => ['#7E560A', 33, 136],
    'foilLight' => ['#FFE27A', 93, 221],
    'foilMid'   => ['#E0B23A', 93, 179],
    'glint'     => ['#FFF8D0', 97, 230],
    'barShadow' => ['#2A1A12', 90, 235],
    'sheen'     => ['#FFE9C8', 97, 224],
];

/** Relief weights: how much each missing neighbour lights (+) / shades (−). */
const LIGHT = ['left' => 0.55, 'up' => 0.45, 'right' => -0.55, 'down' => -0.45];
/** Fraction of slab cells that get a cocoa-grain speckle. */
const GRAIN = 0.08;
/** Foil glints (x offset from bar right edge, y). */
const GLINTS = [[-5, 1], [-2, 4], [-4, 6]];

const DESCRIPTION = 'CANDY TOP embossed in relief on a moulded dark-chocolate bar, lit from the upper-left: half-cell bevels give every stroke a bright top/left edge and a shaded bottom/right edge, letters cast shadows onto the cocoa-grained slab, break-off grooves split it into squares, and a crinkled gold-foil wrapper with a torn ◢◥ edge covers the right end.';
const TAGS = ['chocolate-bar-emboss', 'embossed-relief', 'bevel-lighting', 'gold-foil-wrapper', 'shaded-texture', 'segmented-slab', 'warm-brown-gold'];

const ANIM_FRAMES = 18;      // play: php logo-play.php <file> 120 (≈2.3 s)

// ================================================================ COLOUR

$GLOBALS['SNAP'] = false;

function role(string $r): array
{
    return lkHex(ROLES[$r][0]);
}

/** Pins built from the role table: column 1 = 16-colour, 2 = 256. */
function pins(int $col): array
{
    $p = [];
    foreach (ROLES as $row) {
        $p[$row[0]] = $row[$col];
    }
    return $p;
}

/** Role-snapped tc ANSI → 256 ANSI via the pinned role indices. */
function encode256(array $cells): string
{
    $map = [];
    foreach (pins(2) as $hex => $code) {
        $map[implode(';', lkHex($hex))] = $code;
    }
    return preg_replace_callback(
        '/([34])8;2;(\d+;\d+;\d+)/',
        static fn (array $m): string => $m[1] . '8;5;' . ($map[$m[2]] ?? throw new RuntimeException("unpinned colour {$m[2]}")),
        lkEncode($cells, 'tc'),
    );
}

/** A role, optionally brightened (k>1) / darkened (k<1) — role-snapped in 16 mode. */
function tone(string $r, float $k = 1.0): array
{
    return $GLOBALS['SNAP'] ? role($r) : lkScale(role($r), $k);
}

/** Relief value v∈[-1,1] → chocolate colour (face ↔ hi / lo). */
function relief(float $v, float $rowK): array
{
    if ($GLOBALS['SNAP']) {
        return role(match (true) {
            $v > 0.6 => 'hi',
            $v > 0.2 => 'hiMid',
            $v < -0.6 => 'lo',
            $v < -0.2 => 'loMid',
            default => 'face',
        });
    }
    $c = $v >= 0 ? lkMix(role('face'), role('hi'), min(1.0, $v)) : lkMix(role('face'), role('lo'), min(1.0, -$v));
    return lkScale($c, $rowK);
}

// ================================================================ GEOMETRY

/** @return array{mask: list<string>, ranges: array, x0: int, w: int, h: int, barW: int} */
function geometry(): array
{
    [$mask, $ranges] = lkLayout(FONT, TEXT, LETTER_GAP, WORD_GAP, 0);
    $x0 = 1 + PAD_LEFT;
    $barW = $x0 + strlen($mask[0]) + PAD_RIGHT + FOIL;
    $h = count($mask) + 2 + (SHADOW_ROW ? 1 : 0);
    return ['mask' => $mask, 'ranges' => $ranges, 'x0' => $x0, 'w' => $barW + (SHADOW_ROW ? 1 : 0), 'h' => $h, 'barW' => $barW];
}

/** Generic emboss shader: [vTop, vBottom] for a mask cell. */
function emboss(callable $on, int $x, int $y): array
{
    $h = 0.0;
    if (!$on($x - 1, $y)) {
        $h += LIGHT['left'];
    }
    if (!$on($x + 1, $y)) {
        $h += LIGHT['right'];
    }
    $top = $h + (!$on($x, $y - 1) ? LIGHT['up'] : 0.0);
    $bot = $h + (!$on($x, $y + 1) ? LIGHT['down'] : 0.0);
    return [$top, $bot];
}

// ================================================================ COMPOSE

/** @return list<list<array>> cell grid; $sheen = diagonal position or null */
function cells(?float $sheen = null): array
{
    $g = geometry();
    $mask = $g['mask'];
    $mh = count($mask);
    $barW = $g['barW'];
    $barH = $mh + 2;
    $x0 = $g['x0'];
    $cells = lkBlankCells($g['w'], $g['h']);

    // mask lookup in bar coordinates (letters occupy rows 1..mh)
    $ch = static function (int $x, int $y) use ($mask, $x0): string {
        $c = $mask[$y - 1][$x - $x0] ?? '.';
        return ($x - $x0) < 0 ? '.' : $c;
    };
    $solid = static fn (int $x, int $y): bool => $ch($x, $y) === '#';
    $any = static fn (int $x, int $y): bool => $ch($x, $y) !== '.';

    // groove columns: middle of the chosen gaps
    $grooves = [];
    foreach (GROOVES_AFTER as $i) {
        $end = $g['ranges'][$i][1] + $x0;
        $next = $g['ranges'][$i + 1][0] + $x0;
        $grooves[intdiv($end + $next - 1, 2)] = true;
    }

    $foilEdge = static fn (int $y): int => $barW - FOIL + (lkNoise(0, $y, SEED) < 0.5 ? 0 : 1);

    for ($y = 0; $y < $barH; $y++) {
        $rowK = 1.06 - 0.16 * max(0, $y - 1) / max(1, $mh - 1);
        for ($x = 0; $x < $barW; $x++) {
            // ---------------------------------------------------- slab
            $slab = tone('slab', $rowK);
            if (isset($grooves[$x]) && $y > 0 && $y < $barH - 1) {
                $slab = tone('groove');
            } elseif (isset($grooves[$x - 1]) && $y > 0 && $y < $barH - 1) {
                $slab = tone('grooveHi', $rowK);
            }
            $shadowed = $y > 0 && $y < $barH && !$any($x, $y)
                && ($any($x - 1, $y) || $any($x, $y - 1) || $any($x - 1, $y - 1));
            if ($shadowed && !isset($grooves[$x]) && !isset($grooves[$x - 1])) {
                $slab = tone('cast');
            }
            $cell = [' ', null, $slab];
            if (!$shadowed && !isset($grooves[$x]) && $y > 0 && $y < $barH - 1 && lkNoise($x, $y, SEED) < GRAIN) {
                $cell = ['░', tone('grain'), $slab];
            }

            // ---------------------------------------------------- rims
            if ($y === 0) {
                $cell = isset($grooves[$x]) ? ['▄', tone('groove'), tone('rimHi', 0.8)] : ['▄', $slab, tone('rimHi', 1.1)];
            } elseif ($y === $barH - 1) {
                $cell = ['▄', tone('rimLo'), isset($grooves[$x]) ? tone('groove') : $cell[2]];
            }
            if ($x === 0) {
                $cell = $y === 0 ? ['◢', tone('rimHi', 1.1), null]
                    : ($y === $barH - 1 ? ['◥', tone('rimHi', 0.8), null] : ['▐', $cell[2], tone('rimHi', 1.0 - 0.03 * $y)]);
            }

            // ---------------------------------------------------- letters
            $c = $ch($x, $y);
            if ($c === '#') {
                [$vt, $vb] = emboss($any, $x, $y);
                $top = relief($vt, $rowK);
                $bot = relief($vb, $rowK);
                $cell = $top === $bot ? [' ', null, $top] : ['▄', $bot, $top];
            } elseif (isset(WEDGES[$c])) {
                [$vt, $vb] = emboss($any, $x, $y);
                $cell = [WEDGES[$c], relief(($vt + $vb) / 2 + 0.25 * (in_array($c, ['a', 'b'], true) ? 1 : -1), $rowK), $cell[2]];
            }

            // ---------------------------------------------------- foil
            $edge = $foilEdge($y);
            if ($x === $edge) {
                $cell = [$y % 2 ? '◥' : '◢', tone('foil', 1.08 - 0.05 * $y), $cell[2]];
            } elseif ($x > $edge) {
                $cell = foilCell($x, $y, $barW, $barH);
            }

            // ---------------------------------------------------- sheen
            if ($sheen !== null && !$GLOBALS['SNAP']) {
                $d = abs(($x + 2 * $y) - $sheen);
                if ($d < 3) {
                    $a = 0.45 * (1 - $d / 3);
                    $cell[1] = $cell[1] === null ? null : lkMix($cell[1], role('sheen'), $a);
                    $cell[2] = $cell[2] === null ? null : lkMix($cell[2], role('sheen'), $a);
                }
            }
            $cells[$y][$x] = $cell;
        }
    }

    if (SHADOW_ROW) {
        for ($x = 1; $x <= $barW; $x++) {
            $cells[$barH][$x] = ['▀', tone('barShadow'), null];
        }
        for ($y = 1; $y < $barH; $y++) {
            $cells[$y][$barW] = ['▌', tone('barShadow'), null];
        }
    }
    return $cells;
}

/** Crinkled foil: crease diagonals + noise-picked shade glyphs + glints. */
function foilCell(int $x, int $y, int $barW, int $barH): array
{
    $k = 1.12 - 0.06 * $y;
    $crease = (($x * 2 + $y * 3) % 7) === 0 || (($x * 3 - $y * 2 + 70) % 9) === 0;
    $bg = $crease ? tone('foilDark', $k + 0.1) : tone('foil', $k);
    $n = lkNoise($x, $y, SEED + 11);
    $cell = match (true) {
        $n < 0.22 => ['▓', tone('foilMid', $k), $bg],
        $n < 0.42 => ['▒', tone('foilLight', min(1.0, $k)), $bg],
        $n < 0.58 => ['░', tone('foilDark', $k), $bg],
        default => [' ', null, $bg],
    };
    foreach (GLINTS as [$gx, $gy]) {
        if ($x === $barW + $gx && $y === $gy) {
            $cell = ['✦', tone('glint'), $bg];
        }
    }
    if ($x === $barW - 1 && $y === 0) {
        $cell = ['◣', tone('foil', $k), null];
    } elseif ($x === $barW - 1 && $y === $barH - 1) {
        $cell = ['◤', tone('foilDark', $k + 0.1), null];
    }
    return $cell;
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$outDir = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--out=')) {
        $outDir = substr($a, 6);
    }
}
$dir = $outDir ?? dirname(__DIR__);
$pins = pins(1);

foreach (['tc', '256', '16'] as $depth) {
    $GLOBALS['SNAP'] = $depth !== 'tc';
    $static = $depth === '256' ? encode256(cells()) : lkEncode(cells(), $depth, $pins);
    [$w, $h] = lkVerify($static, $depth);
    lkCheckDepth($static, $depth, $depth);
    if (!$write && $outDir === null) {
        echo $static, "\n";
    }
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    if ($write || $outDir !== null) {
        $r = lkWriteLogo($dir, SLUG, $depth, $static, DESCRIPTION . " ($depth)", TAGS, $outDir === null);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
    if ($depth === 'tc' && ($write || $outDir !== null)) {
        $frames = [];
        for ($f = 0; $f < ANIM_FRAMES; $f++) {
            $frames[] = lkEncode(cells(-6 + ($w + 2 * $h + 12) * $f / (ANIM_FRAMES - 1)), $depth, $pins);
        }
        $anim = lkAnim($frames, $static);
        $r = lkWriteLogo($dir, SLUG . '-anim', $depth, $anim,
            'Animated: ' . DESCRIPTION . ' A warm gloss sheen sweeps diagonally across the bar and foil, ending on the static frame. Play with tools/logo-play.php <file> 120 (~2.3 s). (tc)',
            [...TAGS, 'animated', 'gloss-sheen-sweep'], $outDir === null);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
}
