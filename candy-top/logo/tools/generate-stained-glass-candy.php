<?php

declare(strict_types=1);

/**
 * generate-stained-glass-candy — "CANDY TOP" as a leaded stained-glass window.
 *
 * Each letter is a glass mask cut into panes by a seeded Voronoi split; the
 * pane borders and the letter outlines become dark lead "came". Panes take
 * jewel-toned hard-candy hues (graph-coloured so neighbours never match),
 * lit by sunlight from the upper left, with a soft bevel per pane and a
 * hammered-glass hash texture. Below the window, a blurred pool of coloured
 * light spills onto the sill. Optional animation: a warm glint sweeps across.
 *
 * Reusable bits (swap as data): FONT (glass masks), PALETTE, LEAD colours,
 * PANE_SPACING / SEED (Voronoi density + layout), light + spill constants.
 * Generic helpers: sgVoronoi() (mask → region map + lead), sgColourPanes()
 * (adjacency graph colouring), sgSpill() (column-average blurred spill).
 *
 * Usage:
 *   php generate-stained-glass-candy.php            preview tc, 256, 16 (+ verify)
 *   php generate-stained-glass-candy.php --write    write .ansi files + logos.jsonl
 *   php generate-stained-glass-candy.php --regions  dump the pane map (debug)
 * Play the animation: php logo-play.php ../logo-stained-glass-candy-anim-tc-76x8.ansi 100
 */

require __DIR__ . '/logo-kit.php';

// ================================================================ DESIGN DATA

const SLUG = 'stained-glass-candy';
const TEXT = 'CANDY TOP';

/** Glass masks, 8×12 px ('#' = glass). Gaps become lead / transparency. */
const FONT = [
    'C' => ['..######', '.#######', '########', '###.....', '###.....', '###.....',
            '###.....', '###.....', '###.....', '########', '.#######', '..######'],
    'A' => ['..####..', '.######.', '########', '###..###', '###..###', '########',
            '########', '###..###', '###..###', '###..###', '###..###', '###..###'],
    'N' => ['###...##', '####..##', '#####.##', '##.##.##', '##.##.##', '##.##.##',
            '##.##.##', '##.##.##', '##.##.##', '##.#####', '##..####', '##...###'],
    'D' => ['######..', '#######.', '########', '###..###', '###..###', '###..###',
            '###..###', '###..###', '###..###', '########', '#######.', '######..'],
    'Y' => ['###..###', '###..###', '###..###', '###..###', '########', '.######.',
            '..####..', '..####..', '..####..', '..####..', '..####..', '..####..'],
    'T' => ['########', '########', '########', '..####..', '..####..', '..####..',
            '..####..', '..####..', '..####..', '..####..', '..####..', '..####..'],
    'O' => ['..####..', '.######.', '########', '###..###', '###..###', '###..###',
            '###..###', '###..###', '###..###', '########', '.######.', '..####..'],
    'P' => ['#######.', '########', '########', '###..###', '###..###', '########',
            '#######.', '###.....', '###.....', '###.....', '###.....', '###.....'],
];

/** Hard-candy glass hues. */
const PALETTE = [
    'cherry' => '#D7263D', 'tangerine' => '#F46036', 'lemon' => '#F7C548',
    'lime' => '#7BC950', 'mint' => '#1FB5A8', 'blueberry' => '#2E86DE',
    'grape' => '#8E44AD', 'bubblegum' => '#FF6FB5',
];

/** Base hue per letter (index into PALETTE, in TEXT order without spaces). */
const LETTER_HUES = [0, 1, 2, 3, 4, 5, 6, 7];

/**
 * Per-pane variants of the letter hue, so a letter stays one candy colour
 * family while its panes visibly differ: [shade (+ tint / − shade), share
 * of the NEXT palette hue mixed in].
 */
const PANE_VARIANTS = [[0.0, 0.0], [0.22, 0.0], [-0.22, 0.0], [0.05, 0.38], [-0.08, -0.38]];

const LEAD = '#1C1714';          // came
const LEAD_LIT = '#3B302A';      // internal pane came (a touch lighter than the outline)
const GLINT = '#FFF6E0';         // animation sun glint
const GLINT_LEAD = '#C9A227';    // lead catching the glint
const PANE_SPACING = 5.2;        // min distance between Voronoi seeds (px)
const SEED = 1957;
const TEXTURE = 0.07;            // ± hammered-glass brightness jitter (tc/256)
const SPILL = [0.50, 0.21];      // spill brightness for the two sill pixel rows
const SPILL_BLUR = 2;            // box-blur radius (px)

/** 16-colour pins: lead → black, lit lead → dark grey. */
const PINS16 = [LEAD => 30, LEAD_LIT => 30, GLINT_LEAD => 93];

const DESCRIPTION = 'CANDY TOP as a leaded stained-glass window: chunky pixel letters cut into Voronoi panes of jewel-toned hard-candy glass (cherry, tangerine, lemon, lime, blueberry, grape, bubblegum) held by dark lead came, lit from the upper left with per-pane bevels and hammered-glass texture, and a blurred pool of coloured light spilling onto the sill below.';
const TAGS = ['stained-glass', 'leaded-panes', 'voronoi-panes', 'jewel-tones', 'half-block-pixels', 'light-spill', 'hammered-texture', 'sunlit'];

const ANIM_FRAMES = 24;          // 24 × 100 ms ≈ 2.4 s

// ================================================================ GENERIC HELPERS

/**
 * Voronoi pane split of a mask. Seeds are glass pixels taken in hashed order
 * and kept if ≥ $spacing from every earlier seed of the same letter, so panes
 * never straddle letters. A glass pixel whose right/down glass neighbour lies
 * in another pane becomes lead (1-px came).
 *
 * @param list<string> $mask '#' = glass
 * @param list<array{int,int}> $ranges letter column ranges [x0, x1)
 * @return array{0: array<int, array<int, int>>, 1: array<int, array<int, bool>>}
 *         [region id per glass pixel (y → x → id), lead flags]
 */
function sgVoronoi(array $mask, array $ranges, float $spacing, int $seed): array
{
    $h = count($mask);
    $region = [];
    $lead = [];
    $nextId = 0;
    foreach ($ranges as $li => [$x0, $x1]) {
        $pix = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                if ($mask[$y][$x] === '#') {
                    $pix[] = [$x, $y, lkNoise($x, $y, $seed + $li)];
                }
            }
        }
        usort($pix, static fn ($a, $b) => $a[2] <=> $b[2]);
        $seeds = [];
        foreach ($pix as [$x, $y]) {
            $ok = true;
            foreach ($seeds as [$sx, $sy]) {
                if (hypot($x - $sx, $y - $sy) < $spacing) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $seeds[] = [$x, $y, $nextId++];
            }
        }
        foreach ($pix as [$x, $y]) {
            $best = INF;
            foreach ($seeds as [$sx, $sy, $id]) {
                // Slight anisotropy so cuts run across strokes, not along them.
                $d = hypot(($x - $sx) * 1.15, $y - $sy);
                if ($d < $best) {
                    $best = $d;
                    $region[$y][$x] = $id;
                }
            }
        }
    }
    foreach ($region as $y => $row) {
        foreach ($row as $x => $id) {
            foreach ([[1, 0], [0, 1]] as [$dx, $dy]) {
                $n = $region[$y + $dy][$x + $dx] ?? null;
                if ($n !== null && $n !== $id) {
                    $lead[$y][$x] = true;
                }
            }
        }
    }
    // Panes reduced to a sliver (< 3 px of glass) are absorbed into lead.
    $count = [];
    foreach ($region as $y => $row) {
        foreach ($row as $x => $id) {
            if (empty($lead[$y][$x])) {
                $count[$id] = ($count[$id] ?? 0) + 1;
            }
        }
    }
    foreach ($region as $y => $row) {
        foreach ($row as $x => $id) {
            if (($count[$id] ?? 0) < 3) {
                $lead[$y][$x] = true;
            }
        }
    }
    return [$region, $lead];
}

/**
 * Assign palette indices to panes so panes touching across a lead line
 * (within 2 px) differ; ties broken by seeded hash for variety.
 * @return array<int, int> region id → palette index
 */
function sgColourPanes(array $region, array $lead, int $nColours, int $seed): array
{
    $adj = [];
    foreach ($region as $y => $row) {
        foreach ($row as $x => $id) {
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $n = $region[$y + $dy][$x + $dx] ?? null;
                    if ($n !== null && $n !== $id) {
                        $adj[$id][$n] = true;
                    }
                }
            }
        }
    }
    $ids = array_unique(array_merge(...array_map('array_values', $region)));
    sort($ids);
    $colour = [];
    $used = array_fill(0, $nColours, 0);
    foreach ($ids as $id) {
        $banned = [];
        foreach (array_keys($adj[$id] ?? []) as $n) {
            if (isset($colour[$n])) {
                $banned[$colour[$n]] = true;
            }
        }
        $best = null;
        $bestScore = INF;
        for ($c = 0; $c < $nColours; $c++) {
            if (isset($banned[$c])) {
                continue;
            }
            $score = $used[$c] + lkNoise($id, $c, $seed);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $c;
            }
        }
        $colour[$id] = $best ?? ($id % $nColours);
        $used[$colour[$id]]++;
    }
    return $colour;
}

/**
 * Light spill under the window: per-column average of the glass colours
 * above, box-blurred horizontally; null where no glass is near.
 * @param list<list<?array>> $glass pixel rows holding only glass colours
 * @return list<?array> per-column spill colour at full strength
 */
function sgSpill(array $glass, int $radius): array
{
    $w = count($glass[0]);
    $avg = [];
    for ($x = 0; $x < $w; $x++) {
        $sum = [0, 0, 0];
        $n = 0;
        foreach ($glass as $row) {
            if ($row[$x] !== null) {
                $sum = [$sum[0] + $row[$x][0], $sum[1] + $row[$x][1], $sum[2] + $row[$x][2]];
                $n++;
            }
        }
        $avg[$x] = $n ? [$sum, $n] : null;
    }
    $out = [];
    for ($x = 0; $x < $w; $x++) {
        $sum = [0, 0, 0];
        $n = 0;
        for ($k = -$radius; $k <= $radius; $k++) {
            if (($a = $avg[$x + $k] ?? null) !== null) {
                $sum = [$sum[0] + $a[0][0], $sum[1] + $a[0][1], $sum[2] + $a[0][2]];
                $n += $a[1];
            }
        }
        $out[$x] = $n ? array_map(static fn ($v) => (int) round($v / $n), $sum) : null;
    }
    return $out;
}

/** Pane colour: letter hue, shaded and/or mixed toward the neighbouring hue. */
function sgPaneColour(array $pal, int $hue, array $variant): array
{
    [$shade, $mix] = $variant;
    $n = count($pal);
    $c = $pal[$hue];
    if ($mix !== 0.0) {
        $c = lkMix($c, $pal[($hue + ($mix > 0 ? 1 : -1) + $n) % $n], abs($mix));
    }
    return lkShade($c, $shade);
}

// ================================================================ BUILD

/** @return array{mask: list<string>, region: array, lead: array, colour: array} */
function design(): array
{
    static $d = null;
    if ($d !== null) {
        return $d;
    }
    [$mask, $ranges] = lkLayout(FONT, TEXT, letterGap: 1, wordGap: 4, margin: 1);
    $blank = str_repeat('.', strlen($mask[0]));
    $mask = [$blank, ...$mask, $blank];             // room for the top/bottom came
    $ranges = array_map(static fn ($r) => $r, $ranges);
    [$region, $lead] = sgVoronoi($mask, $ranges, PANE_SPACING, SEED);
    $colour = sgColourPanes($region, $lead, count(PANE_VARIANTS), SEED);
    $letterOf = [];
    foreach ($ranges as $li => [$x0, $x1]) {
        foreach ($region as $y => $row) {
            foreach ($row as $x => $id) {
                if ($x >= $x0 && $x < $x1) {
                    $letterOf[$id] = $li;
                }
            }
        }
    }
    return $d = compact('mask', 'region', 'lead', 'colour', 'letterOf');
}

/**
 * Pixel grid. $glint = band position (px along x + 0.8y), null = static.
 * $depth '16' drops the texture; 256/16 push the spill into one dim-hue row.
 */
function pixels(string $depth, ?float $glint = null): array
{
    ['mask' => $mask, 'region' => $region, 'lead' => $lead, 'colour' => $colour, 'letterOf' => $letterOf] = design();
    $pal = array_map('lkHex', array_values(PALETTE));
    $h = count($mask);
    $w = strlen($mask[0]);
    $isGlass = static fn (int $x, int $y): bool => isset($region[$y][$x]) && empty($lead[$y][$x]);
    $isCame = static fn (int $x, int $y): bool => !$isGlass($x, $y) && (isset($region[$y][$x]) || (function () use ($x, $y, $region): bool {
        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                if (isset($region[$y + $dy][$x + $dx])) {
                    return true;
                }
            }
        }
        return false;
    })());
    $band = static function (int $x, int $y) use ($glint): float {
        if ($glint === null) {
            return 0.0;
        }
        $d = abs($x + 0.8 * $y - $glint);
        return $d < 3.0 ? 1 - $d / 3.0 : 0.0;
    };

    $px = array_fill(0, $h + 2, array_fill(0, $w, null));
    $glassOnly = array_fill(0, $h, array_fill(0, $w, null));
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ($isGlass($x, $y)) {
                $id = $region[$y][$x];
                $c = sgPaneColour($pal, LETTER_HUES[$letterOf[$id]], PANE_VARIANTS[$colour[$id]]);
                // Sun from the upper left.
                $sun = 1 - (0.35 * $x / $w + 0.65 * $y / $h);
                $c = lkScale($c, 0.74 + 0.40 * $sun);
                // Per-pane bevel: lit where the came is above/left, shaded below/right.
                $litEdge = !$isGlass($x - 1, $y) || !$isGlass($x, $y - 1);
                $darkEdge = !$isGlass($x + 1, $y) || !$isGlass($x, $y + 1);
                if ($litEdge && !$darkEdge) {
                    $c = lkShade($c, 0.22);
                } elseif ($darkEdge && !$litEdge) {
                    $c = lkShade($c, -0.14);
                }
                $glassOnly[$y][$x] = $c;
                if ($depth !== '16') {
                    $c = lkScale($c, 1 + TEXTURE * (2 * lkNoise($x, $y, SEED + 99) - 1));
                }
                if (($b = $band($x, $y)) > 0) {
                    $c = lkMix($c, lkHex(GLINT), 0.6 * $b);
                }
                $px[$y][$x] = $c;
            } elseif ($isCame($x, $y)) {
                $c = lkHex(isset($region[$y][$x]) ? LEAD_LIT : LEAD);
                if ($band($x, $y) > 0.4) {
                    $c = lkHex(GLINT_LEAD);
                }
                $px[$y][$x] = $c;
            }
        }
    }
    // Light pool on the sill (two pixel rows under the window).
    $spill = sgSpill($glassOnly, SPILL_BLUR);
    foreach ($spill as $x => $c) {
        if ($c === null) {
            continue;
        }
        if ($depth !== 'tc') {
            // Palettes lack dark saturated tones, so a dim spill quantises to grey
            // mush: use one pixel row normalised into the dim-but-hued range.
            $m = max($c) ?: 1;
            $px[$h][$x] = lkScale($c, ($depth === '16' ? 125 : 118) / $m);
            continue;
        }
        $px[$h][$x] = lkScale($c, SPILL[0]);
        $px[$h + 1][$x] = lkScale($c, SPILL[1]);
    }
    return $px;
}

function cells(string $depth, ?float $glint = null): array
{
    return lkHalfBlock(pixels($depth, $glint));
}

// ================================================================ CLI

if (in_array('--regions', $argv, true)) {
    ['mask' => $mask, 'region' => $region, 'lead' => $lead] = design();
    foreach ($mask as $y => $row) {
        $s = '';
        for ($x = 0; $x < strlen($row); $x++) {
            $s .= isset($region[$y][$x]) ? (!empty($lead[$y][$x]) ? '+' : chr(65 + $region[$y][$x] % 58)) : '.';
        }
        echo $s, "\n";
    }
    exit;
}

$write = in_array('--write', $argv, true);
$dir = dirname(__DIR__);
foreach (['tc', '256', '16'] as $depth) {
    $static = lkEncode(cells($depth), $depth, PINS16);
    [$w, $h] = lkVerify($static, $depth);
    echo $static;
    fwrite(STDERR, "[$depth] {$w}x{$h} ok\n");
    $width = count(cells($depth)[0]);
    $frames = [];
    for ($f = 0; $f < ANIM_FRAMES; $f++) {
        $frames[] = lkEncode(cells($depth, -14 + ($width + 28) * $f / (ANIM_FRAMES - 1)), $depth, PINS16);
    }
    $anim = lkAnim($frames, $static);
    if ($write) {
        $label = ['tc' => '24-bit colour', '256' => 'xterm-256 downgrade', '16' => '16-colour downgrade (texture dropped, single-row spill)'][$depth];
        $r = lkWriteLogo($dir, SLUG, $depth, $static, DESCRIPTION . " $label.", TAGS);
        fwrite(STDERR, "  wrote {$r['file']}\n");
        $r = lkWriteLogo(
            $dir,
            SLUG . '-anim',
            $depth,
            $anim,
            'Animated stained-glass-candy: a warm sun glint sweeps diagonally across the leaded candy-glass panes (the came flashes gold), ' . ANIM_FRAMES . ' frames, ~2.5 s with tools/logo-play.php at 100 ms; ends on the static logo with the cursor restored. ' . $label . '.',
            [...TAGS, 'animated', 'glint-sweep'],
        );
        fwrite(STDERR, "  wrote {$r['file']}\n");
    }
}
