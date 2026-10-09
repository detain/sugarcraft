<?php

declare(strict_types=1);

/**
 * generate-sprinkle-donut-glaze — "CANDY TOP" poured in glossy strawberry
 * icing with rainbow sprinkles and drips.
 *
 * Technique (reusable): letterforms are BACKGROUND-colour cells (spaces with
 * 48;2), not block glyphs. Convex stroke corners are auto-rounded with
 * quadrant glyphs (▟▙▜▛) in the icing fg colour on a transparent bg. Sprinkles
 * are fg glyphs painted on top of icing cells, seeded and non-touching. Drips
 * are extra font rows below the baseline ('#' = icing cell, 't' = ▀ drip tip).
 * A diagonal specular streak lightens each letter's bg.
 *
 * Usage:
 *   php generate-sprinkle-donut-glaze.php            preview tc/256/16 (+verify)
 *   php generate-sprinkle-donut-glaze.php --write    write .ansi + logos.jsonl
 *   php generate-sprinkle-donut-glaze.php --anim     also print the anim (tc)
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'sprinkle-donut-glaze';
const TEXT = 'CANDY TOP';

/**
 * Cell font. Rows 0-5 = letter body ('#' icing), rows 6-7 = drips
 * ('#' icing run-off cell, 't' = ▀ drip tip). '.' = empty.
 */
const FONT = [
    'C' => ['.######', '##.....', '##.....', '##.....', '##.....', '.######', '..#....', '..t....'],
    'A' => ['.#####.', '##...##', '##...##', '#######', '##...##', '##...##', '......t', '.......'],
    'N' => ['##...##', '###..##', '####.##', '##.####', '##..###', '##...##', '.#.....', '.t.....'],
    'D' => ['######.', '##...##', '##...##', '##...##', '##...##', '######.', '...t...', '.......'],
    'Y' => ['##..##', '##..##', '.####.', '..##..', '..##..', '..##..', '...#..', '...t..'],
    'T' => ['######', '..##..', '..##..', '..##..', '..##..', '..##..', '..t...', '......'],
    'O' => ['.#####.', '##...##', '##...##', '##...##', '##...##', '.#####.', '.....#.', '.....t.'],
    'P' => ['######.', '##...##', '##...##', '######.', '##.....', '##.....', '.t.....', '.......'],
];
const BODY_ROWS = 6;

/** Icing: vertical ramp top→bottom, blended left→right strawberry→raspberry. */
const GLAZE_LEFT = ['#FFA3CB', '#FF7AB4', '#F7559A', '#EE4A91', '#D93A80', '#B92A69'];
const GLAZE_RIGHT = ['#F7A0E0', '#F078CB', '#E052B4', '#D447A8', '#BD3896', '#9C2A7C'];
const DRIP = '#A82664';
const SHINE = '#FFE3F0';
const SHINE_MIX = 0.55;      // centre of the streak
const SHINE_EDGE = 0.18;     // neighbour cells
const SHINE_SLOPE = 0.55;    // streak x offset per row (top-left → bottom-right)
const SHINE_START = 1.0;     // streak x at row 0 (letter-local)

const SPRINKLE_GLYPHS = ['╱', '╲', '─', '│', '╱', '╲', '•'];
const SPRINKLE_COLORS = ['#FFFFFF', '#FFE14D', '#4DD7FF', '#7CFF6B', '#6B4BFF', '#FF8A3D', '#3B2214'];
/** 16-colour only: body rows from this index down take the DRIP colour (→ 45 dark magenta), giving a two-tone shade. */
const SHADE16_FROM_ROW = 4;
const SPRINKLE_DENSITY = 0.30;
const SEED = 1313;

/** 16-colour pins: icing on magenta, sprinkles on distinct non-magenta codes. */
const PINS16 = [
    '#FFFFFF' => 97, '#FFE14D' => 93, '#4DD7FF' => 96, '#7CFF6B' => 92,
    '#6B4BFF' => 34, '#FF8A3D' => 33, '#3B2214' => 30, '#FFE3F0' => 97, '#A82664' => 35,
];

const DESCRIPTION = 'Sprinkle donut glaze: CANDY TOP poured in glossy strawberry-to-raspberry icing (letters are truecolor background cells), quadrant-rounded corners, a diagonal specular gloss streak, seeded rainbow sprinkles and icing drips running off the baseline.';
const TAGS = ['sprinkle-donut-glaze', 'bg-color-letterforms', 'icing-drips', 'rainbow-sprinkles', 'specular-gloss', 'seeded-random', 'rounded-quadrant-corners'];

const ANIM_SPRINKLE_FRAMES = 7;   // sprinkles rain on over these frames
const ANIM_SHINE_FRAMES = 6;      // then a bright gloss band sweeps across

// ================================================================ BUILD

/** @return array{0: list<string>, 1: list<array{int,int}>} */
function layout(): array
{
    return lkLayout(FONT, TEXT, letterGap: 1, wordGap: 3, margin: 0);
}

function letterAt(array $ranges, int $x): ?array
{
    foreach ($ranges as $r) {
        if ($x >= $r[0] && $x < $r[1]) {
            return $r;
        }
    }
    return null;
}

/** Icing colour for a body cell, with the static specular streak. */
function glaze(int $x, int $y, int $w, array $range, ?float $sweep, bool $flat16 = false): array
{
    if ($flat16 && $y >= SHADE16_FROM_ROW) {
        return lkHex(DRIP);
    }
    $t = $x / max(1, $w - 1);
    $row = min($y, BODY_ROWS - 1);
    $c = lkMix(lkHex(GLAZE_LEFT[$row]), lkHex(GLAZE_RIGHT[$row]), $t);
    $lx = $x - $range[0];
    $d = abs($lx - (SHINE_START + $y * SHINE_SLOPE));
    $k = $d < 0.75 ? SHINE_MIX : ($d < 1.75 ? SHINE_EDGE : 0.0);
    if ($sweep !== null) {
        $sd = abs($x + $y * 0.8 - $sweep);
        $k = max($k, $sd < 1.0 ? 0.75 : ($sd < 2.5 ? 0.35 : 0.0));
    }
    return $k > 0 ? lkMix($c, lkHex(SHINE), $k) : $c;
}

/** Deterministic sprinkle map: [y][x] => [glyph, rgb, order]. Non-touching. */
function sprinkles(array $mask): array
{
    mt_srand(SEED);
    $h = BODY_ROWS;
    $w = strlen($mask[0]);
    $cands = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ($mask[$y][$x] === '#') {
                $cands[] = [$x, $y];
            }
        }
    }
    shuffle($cands);
    $out = [];
    $taken = [];
    $want = (int) round(count($cands) * SPRINKLE_DENSITY);
    $order = 0;
    foreach ($cands as [$x, $y]) {
        if ($order >= $want) {
            break;
        }
        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                if (isset($taken[$y + $dy][$x + $dx])) {
                    continue 3;
                }
            }
        }
        $taken[$y][$x] = true;
        $g = SPRINKLE_GLYPHS[mt_rand(0, count(SPRINKLE_GLYPHS) - 1)];
        $c = SPRINKLE_COLORS[mt_rand(0, count(SPRINKLE_COLORS) - 1)];
        $out[$y][$x] = [$g, lkHex($c), $order++];
    }
    return $out;
}

/**
 * @param ?int $sprinkleLimit show only sprinkles with order < limit (anim)
 * @param ?float $sweep x position of the moving gloss band (anim)
 */
function cells(?int $sprinkleLimit = null, ?float $sweep = null, bool $flat16 = false): array
{
    [$mask, $ranges] = layout();
    $h = count($mask);
    $w = strlen($mask[0]);
    $on = static fn (int $x, int $y): bool => $y >= 0 && $y < BODY_ROWS && $x >= 0 && $x < $w && $mask[$y][$x] === '#';
    $spr = sprinkles($mask);
    $cells = lkBlankCells($w, $h);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $m = $mask[$y][$x];
            if ($m === '.') {
                continue;
            }
            $range = letterAt($ranges, $x) ?? [$x, $x + 1];
            if ($y >= BODY_ROWS) {
                if ($m === '#') {
                    lkPut($cells, $x, $y, ' ', null, lkHex(DRIP));
                } elseif ($m === 't') {
                    lkPut($cells, $x, $y, '▀', lkHex(DRIP), null);
                }
                continue;
            }
            $c = glaze($x, $y, $w, $range, $sweep, $flat16);
            $up = $on($x, $y - 1);
            $dn = $on($x, $y + 1) || ($y === BODY_ROWS - 1 && $mask[$y + 1][$x] !== '.');
            $lf = $on($x - 1, $y);
            $rt = $on($x + 1, $y);
            $corner = match (true) {
                !$up && !$lf && $rt && $dn => '▟',
                !$up && !$rt && $lf && $dn => '▙',
                !$dn && !$lf && $rt && $up => '▜',
                !$dn && !$rt && $lf && $up => '▛',
                default => null,
            };
            if ($corner !== null) {
                lkPut($cells, $x, $y, $corner, $c, null);
                continue;
            }
            $s = $spr[$y][$x] ?? null;
            if ($s !== null && abs(($x - $range[0]) - (SHINE_START + $y * SHINE_SLOPE)) < 0.75) {
                $s = null;   // keep the gloss streak clean
            }
            if ($s !== null && ($sprinkleLimit === null || $s[2] < $sprinkleLimit)) {
                lkPut($cells, $x, $y, $s[0], $s[1], $c);
            } else {
                lkPut($cells, $x, $y, ' ', null, $c);
            }
        }
    }
    return $cells;
}

// ================================================================ CLI

$write = in_array('--write', $argv, true);
$showAnim = in_array('--anim', $argv, true);
$dir = dirname(__DIR__);
[$mask] = layout();
$total = array_sum(array_map('count', sprinkles($mask)));
foreach (['tc', '256', '16'] as $depth) {
    $static = lkEncode(cells(null, null, $depth === '16'), $depth, PINS16);
    [$w, $h] = lkVerify($static, $depth);
    lkCheckDepth($static, $depth, $depth);
    echo $static, "\n";
    fwrite(STDERR, "[$depth] {$w}x{$h} ok ($total sprinkles)\n");
    if ($write) {
        $r = lkWriteLogo($dir, SLUG, $depth, $static, DESCRIPTION . " ($depth)", TAGS);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
    $frames = [];
    for ($f = 0; $f < ANIM_SPRINKLE_FRAMES; $f++) {
        $frames[] = lkEncode(cells((int) round($total * $f / ANIM_SPRINKLE_FRAMES), null, $depth === '16'), $depth, PINS16);
    }
    for ($f = 0; $f < ANIM_SHINE_FRAMES; $f++) {
        $frames[] = lkEncode(cells(null, -4 + ($w + 10) * $f / (ANIM_SHINE_FRAMES - 1), $depth === '16'), $depth, PINS16);
    }
    $anim = lkAnim($frames, $static);
    if ($showAnim && $depth === 'tc') {
        echo $anim, "\n";
    }
    if ($write) {
        $r = lkWriteLogo($dir, SLUG . '-anim', $depth, $anim,
            'Animated ' . DESCRIPTION . ' Sprinkles rain onto plain icing, then a gloss band sweeps across; ' . (count($frames) + 1) . " frames, play with tools/logo-play.php <file> 150 (~2 s) ($depth)",
            [...TAGS, 'animated', 'sprinkle-rain', 'gloss-sweep']);
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
}
